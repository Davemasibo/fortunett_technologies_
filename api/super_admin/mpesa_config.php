<?php
/**
 * GET  /api/super_admin/mpesa_config.php         — fetch current platform credentials
 * POST /api/super_admin/mpesa_config.php action=save — save/update credentials
 * POST /api/super_admin/mpesa_config.php action=test  — test access-token fetch
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../super_admin/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
superAdminGuard();

// Ensure platform_mpesa_config table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_mpesa_config (
        id            INT NOT NULL DEFAULT 1,
        consumer_key  VARCHAR(255) DEFAULT '',
        consumer_secret VARCHAR(255) DEFAULT '',
        passkey       VARCHAR(255) DEFAULT '',
        shortcode     VARCHAR(20)  DEFAULT '',
        shortcode_type ENUM('paybill','till') DEFAULT 'paybill',
        environment   ENUM('sandbox','live','production') DEFAULT 'sandbox',
        callback_url  VARCHAR(512) DEFAULT '',
        c2b_validation_url VARCHAR(512) DEFAULT '',
        c2b_confirmation_url VARCHAR(512) DEFAULT '',
        notes         TEXT DEFAULT NULL,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure a default row exists
    $pdo->exec("INSERT IGNORE INTO platform_mpesa_config (id) VALUES (1)");
} catch (Exception $e) {
    // Ignore if already exists with different schema
}

// Also ensure payments.collection_type column exists
try {
    $pdo->exec("ALTER TABLE payments ADD COLUMN collection_type ENUM('direct','platform') NOT NULL DEFAULT 'direct'");
} catch (Exception $e) {} // Already exists — fine

$action = $_POST['action'] ?? ($_GET['action'] ?? 'get');

// ── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'get') {
    $row = $pdo->query("SELECT * FROM platform_mpesa_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) $row = ['id'=>1,'consumer_key'=>'','consumer_secret'=>'','passkey'=>'','shortcode'=>'','shortcode_type'=>'paybill','environment'=>'sandbox','callback_url'=>'','notes'=>''];
    // Mask secret fields for display
    $masked = $row;
    if (!empty($masked['consumer_secret'])) $masked['consumer_secret'] = str_repeat('*', 8) . substr($masked['consumer_secret'], -4);
    if (!empty($masked['passkey']))         $masked['passkey']         = str_repeat('*', 8) . substr($masked['passkey'], -4);
    echo json_encode(['success'=>true,'config'=>$masked]);
    exit;
}

// ── SAVE ─────────────────────────────────────────────────────────────────────
if ($action === 'save') {
    $fields = ['consumer_key','consumer_secret','passkey','shortcode','shortcode_type','environment','callback_url','c2b_validation_url','c2b_confirmation_url','notes'];
    $updates = [];
    $params  = [];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $val = trim($_POST[$f]);
            // Don't overwrite masked values (all-stars from GET display)
            if (preg_match('/^\*+[^*]*$/', $val)) continue;
            // Don't clear sensitive fields if submitted as blank (preserve existing)
            if ($val === '' && in_array($f, ['consumer_secret', 'passkey'], true)) continue;
            $updates[] = "$f = ?";
            $params[]  = $val;
        }
    }
    if (empty($updates)) {
        echo json_encode(['success'=>false,'message'=>'Nothing to update']);
        exit;
    }
    $params[] = 1; // WHERE id=1
    $pdo->prepare("UPDATE platform_mpesa_config SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
    echo json_encode(['success'=>true,'message'=>'Platform M-Pesa credentials saved successfully.']);
    exit;
}

// ── TEST ─────────────────────────────────────────────────────────────────────
if ($action === 'test') {
    $row = $pdo->query("SELECT * FROM platform_mpesa_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['consumer_key'])) {
        echo json_encode(['success'=>false,'message'=>'No platform credentials configured yet. Save credentials first.']);
        exit;
    }

    $env = strtolower($row['environment'] ?? 'sandbox');
    $isProduction = in_array($env, ['production', 'live'], true);
    $baseUrl = $isProduction ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    $url     = $baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
    $auth    = base64_encode($row['consumer_key'] . ':' . $row['consumer_secret']);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $auth],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo json_encode(['success'=>false,'message'=>'Network error: ' . $curlErr]);
        exit;
    }

    $resp = json_decode($raw);
    if ($resp && !empty($resp->access_token)) {
        $shortcode = !empty($row['shortcode']) ? ' | Shortcode: ' . $row['shortcode'] : '';
        echo json_encode([
            'success' => true,
            'message' => '✅ Connected! Access token obtained from Safaricom ' . ($isProduction ? 'Production' : 'Sandbox') . '.' . $shortcode
        ]);
    } else {
        // Surface the actual Safaricom error
        $sfError = $resp->errorMessage ?? $resp->error_description ?? $resp->error ?? null;
        $detail  = $sfError ? $sfError : ('HTTP ' . $httpCode . ' — ' . substr($raw, 0, 200));
        echo json_encode([
            'success' => false,
            'message' => '❌ Safaricom rejected the credentials: ' . $detail .
                         ' | Environment: ' . ($isProduction ? 'Production' : 'Sandbox')
        ]);
    }
    exit;
}

echo json_encode(['success'=>false,'message'=>'Unknown action']);
