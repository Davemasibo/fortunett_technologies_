<?php
require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/MikrotikAPI.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST only']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$stmt = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$tenant_id = $stmt->fetchColumn();
if (!$tenant_id) {
    echo json_encode(['status' => 'error', 'message' => 'Tenant not found']);
    exit;
}

$router_id = (int)($_POST['router_id'] ?? 0);
$raw_cmd   = trim($_POST['command'] ?? '');

if (!$router_id || $raw_cmd === '') {
    echo json_encode(['status' => 'error', 'message' => 'router_id and command are required']);
    exit;
}

// Enforce tenant ownership
$stmt = $pdo->prepare("SELECT * FROM mikrotik_routers WHERE id = ? AND tenant_id = ?");
$stmt->execute([$router_id, $tenant_id]);
$router = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$router) {
    echo json_encode(['status' => 'error', 'message' => 'Router not found or access denied']);
    exit;
}

// Block commands that permanently destroy router config
$blocked = ['/system/reset-configuration', '/system/format-storage'];
foreach ($blocked as $b) {
    if (stripos($raw_cmd, $b) !== false) {
        echo json_encode(['status' => 'error', 'message' => "Blocked for safety: $b"]);
        exit;
    }
}

[$cmd_path, $params] = parseCmd($raw_cmd);

if ($cmd_path === '') {
    echo json_encode(['status' => 'error', 'message' => 'Could not parse command']);
    exit;
}

// Use VPN IP if set (WireGuard tunnel), else fall back to stored IP — same logic as test_connection.php
$connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];

try {
    $mk = new MikrotikAPI(
        $connectIp,
        $router['username'],
        $router['password'],
        (int)($router['api_port'] ?? 8728)
    );
    $mk->connect();
    $response = $mk->comm($cmd_path, $params);
    $mk->disconnect();

    echo json_encode([
        'status'  => 'success',
        'output'  => fmtResponse($response),
        'command' => $raw_cmd,
    ]);

} catch (Exception $e) {
    $msg = $e->getMessage();

    // RouterOS closes the API connection when a hotspot command is issued
    // and the hotspot server is not running or is marked invalid on the router.
    // Give the admin an actionable message instead of a raw TCP error.
    $isHotspotCmd = stripos($cmd_path, '/ip/hotspot') !== false;
    if ($isHotspotCmd && stripos($msg, 'connection closed') !== false) {
        $msg = "Hotspot server is not running or is marked invalid on this router.\n"
             . "Run /ip/hotspot/print to check its status, then fix the invalid flag "
             . "or re-run the hotspot setup wizard on the router.";
    }

    echo json_encode([
        'status'  => 'error',
        'message' => $msg,
        'output'  => $msg,
    ]);
}

// ── Command parser ────────────────────────────────────────────────────────────
// Accepts RouterOS-style input where spaces separate path components AND params:
//   /tool fetch url="https://example.com" dst-path=flash/hotspot/login.html
//   /system script add name=fh source={/tool fetch url="..." dst-path=...}
//   /ip hotspot user print where name=john
//   /ppp/active/print
//   /ip/hotspot/user/set =.id=*1 disabled=yes
// Returns [cmdPath, params[]]
function parseCmd(string $input): array {
    // ── Step 1: tokenize respecting quoted strings and braced blocks ──────────
    $tokens = [];
    $cur    = '';
    $depth  = 0;  // brace depth
    $inQ    = null; // current quote char or null
    $len    = strlen($input);

    for ($i = 0; $i < $len; $i++) {
        $c = $input[$i];
        if ($inQ !== null) {
            $cur .= $c;
            if ($c === $inQ) $inQ = null;
        } elseif ($c === '"' || $c === "'") {
            $inQ = $c;
            $cur .= $c;
        } elseif ($c === '{') {
            $depth++;
            $cur .= $c;
        } elseif ($c === '}') {
            $depth--;
            $cur .= $c;
        } elseif (($c === ' ' || $c === "\t") && $depth === 0) {
            if ($cur !== '') { $tokens[] = $cur; $cur = ''; }
        } else {
            $cur .= $c;
        }
    }
    if ($cur !== '') $tokens[] = $cur;

    if (empty($tokens)) return ['', []];

    // ── Step 2: build command path ────────────────────────────────────────────
    // A token is a path component if it contains no '=' (and is not 'where'/'find').
    // Spaces are used as path separators in RouterOS CLI (e.g. "/tool fetch" = /tool/fetch).
    $pathParts = [];

    // First token: split on '/' to get initial path components
    foreach (array_filter(explode('/', $tokens[0]), fn($p) => $p !== '') as $p) {
        $pathParts[] = $p;
    }

    $i = 1;
    $n = count($tokens);
    while ($i < $n) {
        $t = $tokens[$i];
        if ($t === 'where' || $t === 'find') break;
        if (strpos($t, '=') !== false)       break; // key=value → start of params
        $pathParts[] = $t;
        $i++;
    }

    $cmd = '/' . implode('/', $pathParts);
    $cmd = preg_replace('#/+#', '/', $cmd);

    // ── Step 3: parse remaining tokens as key=value parameters ───────────────
    $params = [];
    while ($i < $n) {
        $t = $tokens[$i];

        if ($t === 'where' || $t === 'find') {
            $i++;
            while ($i < $n) {
                $f = $tokens[$i];
                if (strpos($f, '=') !== false) {
                    [$k, $v] = explode('=', $f, 2);
                    $k = ltrim($k, '=');
                    $params[] = '?' . $k . '=' . trim($v, '"\'');
                }
                $i++;
            }
            continue;
        }

        if (strpos($t, '=') !== false) {
            if ($t[0] === '=') {
                // Already API-prefixed (e.g. =.id=*1) — pass through unchanged
                $params[] = $t;
            } else {
                $eqPos = strpos($t, '=');
                $key   = substr($t, 0, $eqPos);
                $val   = substr($t, $eqPos + 1);

                // Strip surrounding quotes: url="https://..." → url=https://...
                if (strlen($val) >= 2
                    && ($val[0] === '"' || $val[0] === "'")
                    && $val[-1] === $val[0]
                ) {
                    $val = substr($val, 1, -1);
                }

                // Strip surrounding braces from script source: source={...} → source=...
                // The braces are RouterOS console syntax; RouterOS API takes raw script text.
                if (strlen($val) >= 2 && $val[0] === '{' && $val[-1] === '}') {
                    $val = substr($val, 1, -1);
                }

                $params[] = '=' . $key . '=' . $val;
            }
        }
        $i++;
    }

    return [$cmd, $params];
}

// ── Response formatter ────────────────────────────────────────────────────────
function fmtResponse(array $response): string {
    if (empty($response)) return '(no output)';

    // Count data rows
    $rows = array_filter($response, fn($item) => isset($item['!re']));

    if (empty($rows)) {
        // Check for trap
        foreach ($response as $item) {
            if (isset($item['!trap'])) {
                return 'error: ' . ($item['message'] ?? 'command failed');
            }
        }
        return '(done — no data returned)';
    }

    $lines  = [];
    $rowNum = 0;
    $isList = count($rows) > 1;

    foreach ($rows as $item) {
        $data = array_filter(
            $item,
            fn($k) => !in_array($k, ['!re', '!done', '!trap', '!fatal', '.tag'], true),
            ARRAY_FILTER_USE_KEY
        );

        if ($isList) {
            $id = $data['.id'] ?? '';
            unset($data['.id']);
            $parts = array_map(fn($k, $v) => "$k=$v", array_keys($data), array_values($data));
            $lines[] = sprintf('  %-3d  %s', $rowNum, implode('  ', $parts));
        } else {
            foreach ($data as $k => $v) {
                $lines[] = sprintf('  %-28s: %s', $k, $v);
            }
        }
        $rowNum++;
    }

    if ($isList) {
        $lines[] = '';
        $lines[] = "  -- $rowNum " . ($rowNum === 1 ? 'entry' : 'entries') . ' --';
    }

    return implode("\n", $lines);
}
