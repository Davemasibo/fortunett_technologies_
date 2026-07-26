<?php
/**
 * Rewrites any stored M-Pesa URL that still points at the retired /api/mpesa/
 * folder so it points at /api/payment/ instead.
 *
 * WHY: the endpoints moved to /api/payment/ because Safaricom rejects callback
 * URLs containing the word "mpesa". The code and config were updated, but URLs
 * already saved in the database were not — and MpesaAPI::getCallbackUrl()
 * prefers the stored value over the MPESA_CALLBACK_URL constant. A stale row
 * therefore still sends Safaricom the old path.
 *
 * RUN THIS ON EVERY ENVIRONMENT *BEFORE* DEPLOYING THE DELETION OF /api/mpesa/,
 * otherwise callbacks 404 and payments are taken but never confirmed.
 *
 * CLI:      php tools/fix_mpesa_callback_urls.php [--dry-run]
 * Browser:  /tools/fix_mpesa_callback_urls.php    (localhost only)
 */

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('Access denied. Run from localhost or the CLI.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/credential_helper.php';

$dry = $cli ? in_array('--dry-run', $argv, true) : isset($_GET['dry-run']);
if ($dry) echo "DRY RUN — no writes will be made\n\n";

const OLD_PATH = '/api/mpesa/';
const NEW_PATH = '/api/payment/';

$changed = 0;

// ── platform_mpesa_config ─────────────────────────────────────────────────────
echo "── platform_mpesa_config ──────────────────────────────────\n";
try {
    $rows = $pdo->query("SELECT * FROM platform_mpesa_config")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $id      = $row['id'] ?? null;
        $updates = [];
        foreach ($row as $col => $val) {
            if (is_string($val) && stripos($val, OLD_PATH) !== false) {
                $updates[$col] = str_ireplace(OLD_PATH, NEW_PATH, $val);
            }
        }
        if (!$updates) { continue; }

        foreach ($updates as $col => $new) {
            echo "  id={$id} {$col}\n    old: {$row[$col]}\n    new: {$new}\n";
        }
        if (!$dry && $id !== null) {
            $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($updates)));
            $st  = $pdo->prepare("UPDATE platform_mpesa_config SET $set WHERE id = ?");
            $st->execute([...array_values($updates), $id]);
        }
        $changed += count($updates);
    }
    if (!$rows) echo "  (no rows)\n";
} catch (Throwable $e) {
    echo "  skipped: " . $e->getMessage() . "\n";
}

// ── payment_gateways.credentials (encrypted JSON) ─────────────────────────────
echo "\n── payment_gateways.credentials ───────────────────────────\n";
try {
    $rows = $pdo->query("SELECT id, tenant_id, credentials FROM payment_gateways")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $creds = [];
        try { $creds = decrypt_gateway_credentials($row['credentials']) ?: []; } catch (Throwable $e) { continue; }

        $touched = false;
        foreach ($creds as $k => $v) {
            if (is_string($v) && stripos($v, OLD_PATH) !== false) {
                echo "  gw#{$row['id']} tenant={$row['tenant_id']} {$k}\n    old: {$v}\n    new: " . str_ireplace(OLD_PATH, NEW_PATH, $v) . "\n";
                $creds[$k] = str_ireplace(OLD_PATH, NEW_PATH, $v);
                $touched = true;
                $changed++;
            }
        }
        if ($touched && !$dry) {
            $pdo->prepare("UPDATE payment_gateways SET credentials = ? WHERE id = ?")
                ->execute([encrypt_gateway_credentials($creds), $row['id']]);
        }
    }
    if (!$rows) echo "  (no rows)\n";
} catch (Throwable $e) {
    echo "  skipped: " . $e->getMessage() . "\n";
}

// ── platform_settings ─────────────────────────────────────────────────────────
echo "\n── platform_settings ──────────────────────────────────────\n";
try {
    $st = $pdo->prepare("SELECT setting_key, setting_value FROM platform_settings WHERE setting_value LIKE ?");
    $st->execute(['%' . OLD_PATH . '%']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $new = str_ireplace(OLD_PATH, NEW_PATH, $row['setting_value']);
        echo "  {$row['setting_key']}\n    old: {$row['setting_value']}\n    new: {$new}\n";
        if (!$dry) {
            $pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = ?")
                ->execute([$new, $row['setting_key']]);
        }
        $changed++;
    }
} catch (Throwable $e) {
    echo "  skipped: " . $e->getMessage() . "\n";
}

echo "\n" . ($changed
    ? ($dry ? "$changed URL(s) WOULD be rewritten. Re-run without --dry-run to apply.\n"
            : "$changed URL(s) rewritten. /api/mpesa/ is now safe to delete.\n")
    : "Nothing to change — no stored URL references /api/mpesa/.\n");

if (!$dry && $changed) {
    echo "\nNOTE: tenants using their own paybill must re-register their C2B URLs\n";
    echo "      (Payments -> Register C2B) so Safaricom picks up the new paths.\n";
}
