<?php
/**
 * MikroTik Hotspot File Deployer
 *
 * Connects to a tenant's MikroTik router using credentials from the DB,
 * injects the ISP server URL into HTML files, uploads via FTP, fixes the
 * html-directory profile setting, and configures the walled garden so that
 * unauthenticated devices can reach the ISP server APIs.
 *
 * CLI:  php tools/deploy_hotspot.php <subdomain>
 *       php tools/deploy_hotspot.php demo
 *
 * Web:  https://yourserver/tools/deploy_hotspot.php?subdomain=demo&key=SECRET
 */

define('DEPLOY_KEY', 'fnt-deploy-2026');

// ── Bootstrap ─────────────────────────────────────────────────────────────────
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = $_GET['key'] ?? '';
    if ($key !== DEPLOY_KEY) {
        http_response_code(403);
        die("403 Forbidden — wrong key\n");
    }
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../classes/RouterOSAPI.php';

$subdomain = $isCli ? ($argv[1] ?? null) : ($_GET['subdomain'] ?? null);

if (!$subdomain) {
    die("Usage: php tools/deploy_hotspot.php <subdomain>\n" .
        "   eg: php tools/deploy_hotspot.php demo\n");
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function out(string $msg): void { echo $msg . "\n"; }
function ok(string $msg):  void { out("  ✓ " . $msg); }
function err(string $msg): void { out("  ✗ " . $msg); }
function hdr(string $msg): void { out("\n── " . $msg . " " . str_repeat('─', max(0, 50 - strlen($msg)))); }

/**
 * For HTML files: replace the FORTUNETT_API_BASE placeholder with the real
 * server URL so that API calls work correctly when the file is served from
 * the router's IP address (192.168.x.x) instead of the ISP domain.
 */
function prepareContent(string $localPath, string $serverUrl): string {
    $content = file_get_contents($localPath);
    $content = str_replace("'FORTUNETT_API_BASE'", "'" . $serverUrl . "'", $content);
    return $content;
}

/** Upload a string as a file via FTP using a temp stream. */
function ftpPutString($ftp, string $remotePath, string $content): bool {
    $h = fopen('php://temp', 'r+');
    fwrite($h, $content);
    rewind($h);
    $ok = @ftp_fput($ftp, $remotePath, $h, FTP_BINARY);
    fclose($h);
    return $ok;
}

// ── 1. Resolve tenant ─────────────────────────────────────────────────────────
hdr('Resolving tenant');
try {
    $tSt = $pdo->prepare("SELECT id, company_name FROM tenants WHERE subdomain = ? LIMIT 1");
    $tSt->execute([$subdomain]);
    $tenant = $tSt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die(err('DB error: ' . $e->getMessage()) . "\n");
}

if (!$tenant) {
    die(err("No tenant found for subdomain '$subdomain'") . "\n");
}
$tenantId  = (int)$tenant['id'];
$serverUrl = 'https://' . $subdomain . '.fortunetttech.site';
ok("Tenant: {$tenant['company_name']} (id=$tenantId)");
ok("Server URL: $serverUrl");

// ── 2. Get router credentials ─────────────────────────────────────────────────
hdr('Finding router');
try {
    $rSt = $pdo->prepare(
        "SELECT id, ip_address, vpn_ip, username, password, api_port
         FROM mikrotik_routers
         WHERE tenant_id = ? AND status IN ('active','online')
         ORDER BY id ASC LIMIT 1"
    );
    $rSt->execute([$tenantId]);
    $router = $rSt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die(err('DB error: ' . $e->getMessage()) . "\n");
}

if (!$router) {
    die(err("No active router found for tenant $tenantId") . "\n");
}

$routerIp   = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
$routerUser = $router['username'];
$routerPass = $router['password'];
$apiPort    = (int)($router['api_port'] ?: 8728);

ok("Router: $routerIp (API port $apiPort, user: $routerUser)");

// ── 3. Test reachability ───────────────────────────────────────────────────────
hdr('Testing router reachability');
$sock = @fsockopen($routerIp, $apiPort, $errno, $errstr, 5);
if (!$sock) {
    die(err("Cannot reach $routerIp:$apiPort — $errstr ($errno)") . "\n");
}
fclose($sock);
ok("Router is reachable on port $apiPort");

// ── 4. Upload files via FTP ───────────────────────────────────────────────────
hdr('Uploading files via FTP (with server URL injected into HTML)');

$ftp = @ftp_connect($routerIp, 21, 10);
if (!$ftp) {
    die(err("FTP connect failed to $routerIp:21 — ensure FTP service is enabled on the router") . "\n");
}

if (!@ftp_login($ftp, $routerUser, $routerPass)) {
    ftp_close($ftp);
    die(err("FTP login failed (user=$routerUser) — check credentials") . "\n");
}

ftp_pasv($ftp, true);
ok("FTP connected as $routerUser");

// Create directories (errors silently ignored — dirs may already exist)
@ftp_mkdir($ftp, 'flash');
@ftp_mkdir($ftp, 'flash/hotspot');
@ftp_mkdir($ftp, 'flash/hotspot/css');
ok("Directories ready");

$baseDir = dirname(__DIR__);
$filesToUpload = [
    $baseDir . '/customer/login.html'    => 'flash/hotspot/login.html',
    $baseDir . '/customer/register.html' => 'flash/hotspot/register.html',
    $baseDir . '/css/auth.css'           => 'flash/hotspot/css/auth.css',
];

$uploadOk = 0;
foreach ($filesToUpload as $localPath => $remotePath) {
    if (!file_exists($localPath)) {
        err("Local file not found: $localPath");
        continue;
    }

    $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
    if ($ext === 'html') {
        // Inject server URL so API calls work from the router's IP
        $content = prepareContent($localPath, $serverUrl);
        if (ftpPutString($ftp, $remotePath, $content)) {
            ok("Uploaded → $remotePath (" . strlen($content) . " bytes, server URL injected)");
            $uploadOk++;
        } else {
            err("Upload failed → $remotePath");
        }
    } else {
        if (@ftp_put($ftp, $remotePath, $localPath, FTP_BINARY)) {
            ok("Uploaded → $remotePath (" . number_format(filesize($localPath)) . " bytes)");
            $uploadOk++;
        } else {
            err("Upload failed → $remotePath");
        }
    }
}

ftp_close($ftp);

if ($uploadOk === 0) {
    die(err("No files uploaded — aborting") . "\n");
}
out("  $uploadOk/" . count($filesToUpload) . " files uploaded");

// ── 5. RouterOS API: fix profile + configure walled garden ───────────────────
hdr('Connecting RouterOS API');

$api = new RouterOSAPI();
$api->port     = $apiPort;
$api->timeout  = 10;
$api->attempts = 2;
$api->delay    = 1;

if (!$api->connect($routerIp, $routerUser, $routerPass)) {
    err("RouterOS API connection failed — files uploaded but profile/walled-garden not configured");
    err("Fix manually:");
    err("  /ip hotspot profile set [find] html-directory=flash/hotspot");
    err("  /ip hotspot walled-garden add dst-host=$subdomain.fortunetttech.site");
    exit(1);
}

ok("RouterOS API connected");

// ── 5a. Fix hotspot profile html-directory ────────────────────────────────────
hdr('Fixing hotspot profiles');
$profiles = $api->comm('/ip/hotspot/profile/print');
$fixed = 0;

foreach ($profiles as $p) {
    if (!isset($p['!re'])) continue;

    $id      = $p['.id']                       ?? null;
    $name    = $p['name']                      ?? '?';
    $htmlDir = $p['html-directory']            ?? '';
    $override= $p['html-directory-override']   ?? '';

    out("  Profile '$name': html-directory='$htmlDir'");

    $needsFix = (str_contains($htmlDir, 'flash/flash') || $htmlDir === '' || $htmlDir === 'hotspot');
    if ($id && $needsFix) {
        $api->comm('/ip/hotspot/profile/set', ['.id' => $id, 'html-directory' => 'flash/hotspot']);
        ok("  Fixed '$name' → flash/hotspot");
        $fixed++;
    }

    if ($id && str_contains($override, 'flash/flash')) {
        $api->comm('/ip/hotspot/profile/set', ['.id' => $id, 'html-directory-override' => '']);
        ok("  Cleared bad override on '$name'");
    }
}

if ($fixed === 0) out("  (profiles already correct)");

// ── 5b. Configure walled garden ───────────────────────────────────────────────
hdr('Configuring walled garden');

// Resolve ISP server IP for IP-based walled garden (more reliable than hostname)
$serverIp = gethostbyname($subdomain . '.fortunetttech.site');
$hasIp    = ($serverIp !== $subdomain . '.fortunetttech.site');

// Remove stale Fortunett walled garden entries so re-deploy is idempotent
$wgIpList = $api->comm('/ip/hotspot/walled-garden/ip/print');
foreach ($wgIpList as $e) {
    if (!isset($e['!re'])) continue;
    if (str_starts_with($e['comment'] ?? '', 'Fortunett-')) {
        $api->comm('/ip/hotspot/walled-garden/ip/remove', ['.id' => $e['.id']]);
    }
}
$wgHostList = $api->comm('/ip/hotspot/walled-garden/print');
foreach ($wgHostList as $e) {
    if (!isset($e['!re'])) continue;
    if (str_starts_with($e['comment'] ?? '', 'Fortunett-')) {
        $api->comm('/ip/hotspot/walled-garden/remove', ['.id' => $e['.id']]);
    }
}

// Add IP-based entry (bypasses DNS — works even when DNS is unavailable)
if ($hasIp) {
    $api->comm('/ip/hotspot/walled-garden/ip/add', [
        'dst-address' => $serverIp . '/32',
        'action'      => 'accept',
        'comment'     => 'Fortunett-API',
    ]);
    ok("Walled garden IP: $serverIp/32 (ISP server)");
} else {
    err("Could not resolve $subdomain.fortunetttech.site — add walled garden IP manually");
}

// Add hostname-based entries (covers HTTPS cert validation and CDN resources)
$wgHosts = [
    $subdomain . '.fortunetttech.site' => 'Fortunett-Portal',
    'fonts.googleapis.com'             => 'Fortunett-Fonts',
    'fonts.gstatic.com'                => 'Fortunett-Fonts',
    'cdnjs.cloudflare.com'             => 'Fortunett-Icons',
];
foreach ($wgHosts as $host => $comment) {
    $api->comm('/ip/hotspot/walled-garden/add', ['dst-host' => $host, 'comment' => $comment]);
    ok("Walled garden host: $host");
}

// ── 5c. Verify files on filesystem ───────────────────────────────────────────
hdr('Verifying files on router');
$files = $api->comm('/file/print');
$found = [];
foreach ($files as $f) {
    if (!isset($f['!re'])) continue;
    $fname = $f['name'] ?? '';
    if ($fname === 'flash/hotspot/login.html')    { $found[] = $fname; ok("Found: $fname ({$f['size']} bytes)"); }
    if ($fname === 'flash/hotspot/register.html') { $found[] = $fname; ok("Found: $fname ({$f['size']} bytes)"); }
    if ($fname === 'flash/hotspot/css/auth.css')  { $found[] = $fname; ok("Found: $fname ({$f['size']} bytes)"); }
}
if (count($found) < 3) {
    err("Some files missing from flash/hotspot/ — check FTP upload");
}

$api->disconnect();

hdr('Done');
out("  ✓ HTML files deployed with '$serverUrl' injected as API base");
out("  ✓ Hotspot profile html-directory set to flash/hotspot");
out("  ✓ Walled garden configured — unauthenticated devices can now reach the ISP server");
out("  → Connect a device to the '$subdomain' hotspot WiFi and open any HTTP page to test");
out("");
