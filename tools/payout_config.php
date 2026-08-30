<?php
/**
 * Configure ISP payouts. No UI to hunt for — this is the whole control surface.
 *
 * Show what is configured and what is blocking:
 *   php tools/payout_config.php
 *   php tools/payout_config.php --tenant=7
 *
 * Platform B2C credentials (from the Daraja portal — the SecurityCredential is
 * the initiator password already encrypted against Safaricom's certificate):
 *   php tools/payout_config.php --initiator=fortunett_api --security-credential='<blob>' --b2c-shortcode=123456
 *   php tools/payout_config.php --base-url=https://fortunetttech.site
 *
 * A tenant's destination. Setting the phone clears any prior verification on
 * purpose — a changed number is a new number and needs confirming again:
 *   php tools/payout_config.php --tenant=7 --phone=0712345678 --name='Ecoland Networks'
 *   php tools/payout_config.php --tenant=7 --verify          # you confirmed it with them
 *   php tools/payout_config.php --tenant=7 --enable
 *   php tools/payout_config.php --tenant=7 --min=500 --max-daily=100000
 *
 * The global switch. Off by default; nothing sends money until this is on:
 *   php tools/payout_config.php --enable-payouts
 *   php tools/payout_config.php --disable-payouts
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/payouts.php';

ensurePayoutTables($pdo);

// Columns the B2C sender needs. Added here rather than in a migration file so a
// deployment that only pulls code still ends up consistent.
foreach ([
    "ALTER TABLE platform_mpesa_config ADD COLUMN initiator_name VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE platform_mpesa_config ADD COLUMN security_credential TEXT DEFAULT NULL",
    "ALTER TABLE platform_mpesa_config ADD COLUMN b2c_shortcode VARCHAR(20) DEFAULT NULL",
] as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) { /* already present */ }
}
try { $pdo->exec("INSERT IGNORE INTO platform_mpesa_config (id) VALUES (1)"); } catch (Throwable $e) {}

$opt = function (string $name) use ($argv) {
    foreach ($argv as $a) {
        if (strpos($a, "--$name=") === 0) return substr($a, strlen($name) + 3);
    }
    return null;
};
$flag = function (string $name) use ($argv) { return in_array("--$name", $argv, true); };

$tenantId = (int)($opt('tenant') ?? 0);
$changed  = [];

function setSetting(PDO $pdo, string $key, string $value): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, $value]);
}

// ── Platform-level ────────────────────────────────────────────────────────────
if ($flag('enable-payouts'))  { setSetting($pdo, 'payouts_enabled', '1'); $changed[] = 'platform payouts ENABLED'; }
if ($flag('disable-payouts')) { setSetting($pdo, 'payouts_enabled', '0'); $changed[] = 'platform payouts DISABLED'; }

if (($v = $opt('base-url')) !== null) {
    setSetting($pdo, 'public_base_url', rtrim($v, '/'));
    $changed[] = 'public base URL = ' . rtrim($v, '/');
}

foreach (['initiator' => 'initiator_name', 'security-credential' => 'security_credential', 'b2c-shortcode' => 'b2c_shortcode'] as $arg => $col) {
    if (($v = $opt($arg)) !== null) {
        $pdo->prepare("UPDATE platform_mpesa_config SET $col = ? WHERE id = 1")->execute([$v]);
        $changed[] = $col . ' set' . ($col === 'security_credential' ? ' (' . strlen($v) . ' chars)' : ' = ' . $v);
    }
}

// ── Tenant-level ──────────────────────────────────────────────────────────────
if ($tenantId) {
    $pdo->prepare("INSERT IGNORE INTO tenant_payout_settings (tenant_id) VALUES (?)")->execute([$tenantId]);

    if (($v = $opt('phone')) !== null) {
        // Normalise to 2547XXXXXXXX so the B2C call and the display agree.
        $digits = preg_replace('/\D/', '', $v);
        if (strlen($digits) === 10 && $digits[0] === '0') $digits = '254' . substr($digits, 1);
        if (strlen($digits) === 9)                        $digits = '254' . $digits;

        if (!preg_match('/^254[17]\d{8}$/', $digits)) {
            exit("Refusing '$v' — not a valid Kenyan mobile number.\n");
        }

        // Changing the destination invalidates the verification. Paying a new
        // number on an old confirmation is exactly the mistake this prevents.
        $pdo->prepare("UPDATE tenant_payout_settings SET payout_phone = ?, verified_at = NULL WHERE tenant_id = ?")
            ->execute([$digits, $tenantId]);
        $changed[] = "tenant $tenantId payout phone = $digits (verification cleared — re-verify)";
    }

    if (($v = $opt('name')) !== null) {
        $pdo->prepare("UPDATE tenant_payout_settings SET payout_name = ? WHERE tenant_id = ?")->execute([$v, $tenantId]);
        $changed[] = "tenant $tenantId payout name = $v";
    }
    if (($v = $opt('min')) !== null) {
        $pdo->prepare("UPDATE tenant_payout_settings SET min_payout = ? WHERE tenant_id = ?")->execute([(float)$v, $tenantId]);
        $changed[] = "tenant $tenantId minimum payout = " . number_format((float)$v, 2);
    }
    if (($v = $opt('max-daily')) !== null) {
        $pdo->prepare("UPDATE tenant_payout_settings SET max_daily = ? WHERE tenant_id = ?")->execute([(float)$v, $tenantId]);
        $changed[] = "tenant $tenantId daily cap = " . number_format((float)$v, 2);
    }
    if ($flag('verify')) {
        $cur = $pdo->prepare("SELECT payout_phone FROM tenant_payout_settings WHERE tenant_id = ?");
        $cur->execute([$tenantId]);
        $ph = $cur->fetchColumn();
        if (!$ph) exit("Tenant $tenantId has no payout phone to verify. Set --phone= first.\n");
        $pdo->prepare("UPDATE tenant_payout_settings SET verified_at = NOW() WHERE tenant_id = ?")->execute([$tenantId]);
        $changed[] = "tenant $tenantId payout number $ph VERIFIED";
    }
    if ($flag('unverify')) {
        $pdo->prepare("UPDATE tenant_payout_settings SET verified_at = NULL WHERE tenant_id = ?")->execute([$tenantId]);
        $changed[] = "tenant $tenantId verification cleared";
    }
    if ($flag('enable')) {
        $pdo->prepare("UPDATE tenant_payout_settings SET auto_payout = 1 WHERE tenant_id = ?")->execute([$tenantId]);
        $changed[] = "tenant $tenantId auto payout ON";
    }
    if ($flag('disable')) {
        $pdo->prepare("UPDATE tenant_payout_settings SET auto_payout = 0 WHERE tenant_id = ?")->execute([$tenantId]);
        $changed[] = "tenant $tenantId auto payout OFF";
    }
}

foreach ($changed as $c) echo "  changed: $c\n";
if ($changed) echo "\n";

// ── Status ────────────────────────────────────────────────────────────────────
echo "=== Platform ===\n";
$pre = disbursementPreflight($pdo);
printf("  ready to send : %s\n", $pre['ok'] ? 'YES' : 'NO');
foreach ($pre['reasons'] as $r) echo "    - $r\n";
printf("  base URL      : %s\n", payoutSetting($pdo, 'public_base_url', '(not set — B2C needs it for the result callback)'));

echo "\n=== Tenants ===\n";
$sql = "SELECT t.id, t.company_name, s.payout_phone, s.payout_name, s.auto_payout,
               s.min_payout, s.max_daily, s.verified_at,
               (SELECT COALESCE(SUM(net_amount),0) FROM isp_payout_queue q
                WHERE q.tenant_id = t.id AND q.status = 'pending') AS owed
        FROM tenants t
        LEFT JOIN tenant_payout_settings s ON s.tenant_id = t.id";
$params = [];
if ($tenantId) { $sql .= " WHERE t.id = ?"; $params[] = $tenantId; }
$sql .= " ORDER BY owed DESC, t.id";
$st = $pdo->prepare($sql);
$st->execute($params);

printf("  %-4s %-24s %-13s %-6s %-9s %12s  %s\n", 'ID', 'TENANT', 'PAYOUT PHONE', 'AUTO', 'VERIFIED', 'OWED KES', 'STATUS');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $gate = tenantPayoutGate($pdo, (int)$r['id']);
    printf("  %-4s %-24s %-13s %-6s %-9s %12s  %s\n",
        $r['id'], substr((string)$r['company_name'], 0, 24),
        $r['payout_phone'] ?: '-',
        ((int)$r['auto_payout'] === 1) ? 'on' : 'off',
        $r['verified_at'] ? 'yes' : 'NO',
        number_format((float)$r['owed'], 2),
        $gate['ok'] ? 'will be paid' : $gate['reason']);
}

echo "\nDry run the sender with:  php cron/disburse_payouts.php\n";
