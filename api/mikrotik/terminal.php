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

try {
    $mk = new MikrotikAPI(
        $router['ip_address'],
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
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
        'output'  => 'Error: ' . $e->getMessage(),
    ]);
}

// ── Command parser ────────────────────────────────────────────────────────────
// Accepts RouterOS-style input:
//   /ppp/active/print
//   /ppp/active/print where name=john
//   /ip/hotspot/user/set =.id=*1 disabled=yes
// Returns [cmdPath, params[]]
function parseCmd(string $input): array {
    $tokens = preg_split('/\s+/', trim($input), -1, PREG_SPLIT_NO_EMPTY);
    if (empty($tokens)) return ['', []];

    $cmd = $tokens[0];
    if ($cmd[0] !== '/') $cmd = '/' . $cmd;

    $params = [];
    $i = 1;
    $n = count($tokens);

    while ($i < $n) {
        $t = $tokens[$i];

        if ($t === 'where' || $t === 'find') {
            $i++;
            while ($i < $n) {
                $f = $tokens[$i];
                if (strpos($f, '=') !== false) {
                    [$k, $v] = explode('=', $f, 2);
                    $params[] = '?' . $k . '=' . $v;
                }
                $i++;
            }
            continue;
        }

        if (strpos($t, '=') !== false) {
            $params[] = ($t[0] === '=') ? $t : '=' . $t;
            $i++;
            continue;
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
