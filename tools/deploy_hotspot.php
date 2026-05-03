<?php
/**
 * MikroTik Hotspot File Deployer
 *
 * Connects to a tenant's MikroTik router using credentials from the DB,
 * uploads the hotspot HTML/CSS files via FTP, and fixes the html-directory
 * profile setting via the RouterOS API.
 *
 * CLI:  php tools/deploy_hotspot.php <subdomain>
 *       php tools/deploy_hotspot.php demo
 *
 * Web:  https://yourserver/tools/deploy_hotspot.php?subdomain=demo&key=SECRET
 *
 * Set DEPLOY_KEY below to a strong random string for web access security.
 */

define('DEPLOY_KEY', 'fnt-deploy-2026');   // change this

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

// ── Files to upload (local → FTP path on router) ──────────────────────────────
// FTP root on MikroTik = flash storage root, so paths are WITHOUT the flash/ prefix.
// RouterOS shows them as flash/hotspot/... in /file/print — that's just the display prefix.
$baseDir = dirname(__DIR__);
$filesToUpload = [
    $baseDir . '/customer/login.html'    => 'hotspot/login.html',
    $baseDir . '/customer/register.html' => 'hotspot/register.html',
    $baseDir . '/css/auth.css'           => 'hotspot/css/auth.css',
];

// ── Helpers ───────────────────────────────────────────────────────────────────
function out(string $msg): void { echo $msg . "\n"; }
function ok(string $msg):  void { out("  ✓ " . $msg); }
function err(string $msg): void { out("  ✗ " . $msg); }
function hdr(string $msg): void { out("\n── " . $msg . " " . str_repeat('─', max(0, 50 - strlen($msg)))); }

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
$tenantId = (int)$tenant['id'];
ok("Tenant: {$tenant['company_name']} (id=$tenantId)");

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
hdr('Uploading files via FTP');

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
@ftp_mkdir($ftp, 'hotspot');
@ftp_mkdir($ftp, 'hotspot/css');
ok("Directories ready");

$uploadOk = 0;
foreach ($filesToUpload as $localPath => $remotePath) {
    if (!file_exists($localPath)) {
        err("Local file not found: $localPath");
        continue;
    }
    if (@ftp_put($ftp, $remotePath, $localPath, FTP_BINARY)) {
        ok("Uploaded → $remotePath (" . number_format(filesize($localPath)) . " bytes)");
        $uploadOk++;
    } else {
        err("Upload failed → $remotePath");
    }
}

ftp_close($ftp);

if ($uploadOk === 0) {
    die(err("No files uploaded — aborting profile fix") . "\n");
}
out("  $uploadOk/" . count($filesToUpload) . " files uploaded");

// ── 5. Fix hotspot profile via RouterOS API ───────────────────────────────────
hdr('Fixing hotspot profile via RouterOS API');

$api = new RouterOSAPI();
$api->port    = $apiPort;
$api->timeout = 10;
$api->attempts = 2;
$api->delay   = 1;

if (!$api->connect($routerIp, $routerUser, $routerPass)) {
    err("RouterOS API connection failed — files uploaded but profile not fixed");
    err("Fix manually: set html-directory=flash/hotspot on any profile showing flash/flash/hotspot");
    exit(1);
}

ok("RouterOS API connected");

// Get all hotspot profiles
$profiles = $api->comm('/ip/hotspot/profile/print');
$fixed = 0;

foreach ($profiles as $p) {
    if (!isset($p['!re'])) continue;

    $id      = $p['.id']             ?? null;
    $name    = $p['name']            ?? '?';
    $htmlDir = $p['html-directory']  ?? '';
    $override= $p['html-directory-override'] ?? '';

    out("  Profile '$name': html-directory='$htmlDir' override='$override'");

    // Fix any profile with double-flash or wrong html-directory
    $needsFix = (str_contains($htmlDir, 'flash/flash') || $htmlDir === '' || $htmlDir === 'hotspot');

    if ($id && $needsFix) {
        $result = $api->comm('/ip/hotspot/profile/set', [
            '.id'            => $id,
            'html-directory' => 'flash/hotspot',
        ]);
        ok("  Fixed '$name': html-directory set to flash/hotspot");
        $fixed++;
    }

    // Also clear a bad override if it has double-flash
    if ($id && str_contains($override, 'flash/flash')) {
        $api->comm('/ip/hotspot/profile/set', [
            '.id'                      => $id,
            'html-directory-override'  => '',
        ]);
        ok("  Cleared bad html-directory-override on '$name'");
    }
}

if ($fixed === 0) {
    out("  (all profiles already have correct html-directory — no changes needed)");
}

// Print final state
hdr('Final profile state');
$profiles2 = $api->comm('/ip/hotspot/profile/print');
foreach ($profiles2 as $p) {
    if (!isset($p['!re'])) continue;
    out("  [{$p['name']}] html-directory={$p['html-directory']}");
}

// Verify files are visible on router filesystem
hdr('Verifying files on router');
$files = $api->comm('/file/print');
$found = [];
foreach ($files as $f) {
    if (!isset($f['!re'])) continue;
    $fname = $f['name'] ?? '';
    if (str_contains($fname, 'hotspot/login.html'))    { $found[] = $fname; ok("Found: $fname"); }
    if (str_contains($fname, 'hotspot/register.html')) { $found[] = $fname; ok("Found: $fname"); }
    if (str_contains($fname, 'hotspot/css/auth.css'))  { $found[] = $fname; ok("Found: $fname"); }
}
if (empty($found)) {
    err("Files not visible yet — may take a few seconds to appear in /file/print");
}

$api->disconnect();

hdr('Done');
out("  Files deployed and profile fixed.");
out("  Connect a device to the '$subdomain' hotspot WiFi to test.");
out("  The captive portal should show the new dark login page with branding.");
out("");
