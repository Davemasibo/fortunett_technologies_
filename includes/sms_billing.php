<?php
/** Platform-funded SMS only. Rates are captured before contacting the provider. */
function ensureSmsUsageSchema(PDO $pdo): void {
    require_once __DIR__ . '/schema_guard.php';
    ensureColumn($pdo, 'platform_subscription_plans', 'sms_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_sms_usage (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL,
        client_id INT NULL, plan_id INT NULL, provider VARCHAR(50) NOT NULL,
        invoice_id INT NULL,
        recipient VARCHAR(40) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'sending',
        sms_units INT UNSIGNED NOT NULL DEFAULT 0, rate DECIMAL(12,4) NOT NULL DEFAULT 0,
        amount DECIMAL(20,4) NOT NULL DEFAULT 0, provider_ids TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL,
        INDEX tenant_month (tenant_id,status,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensureColumn($pdo,'platform_sms_usage','invoice_id','INT NULL');
}

function smsMessageUnits(string $message): int {
    // GSM extension characters consume two septets; other text uses UTF-16 units.
    $basic = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    $extension = "\f^{}\\[~]|€";
    $chars = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) throw new InvalidArgumentException('SMS text must be valid UTF-8.');
    $septets = 0;
    foreach ($chars as $char) {
        if (str_contains($basic, $char)) $septets++;
        elseif (str_contains($extension, $char)) $septets += 2;
        else {
            $utf16 = iconv('UTF-8', 'UTF-16BE', $message);
            $units = strlen($utf16) / 2;
            return $units <= 70 ? 1 : (int)ceil($units / 67);
        }
    }
    return $septets <= 160 ? 1 : (int)ceil($septets / 153);
}

function beginPlatformSmsUsage(PDO $pdo, int $tenant, ?int $client, array $config, string $phone): int {
    ensureSmsUsageSchema($pdo);
    $st = $pdo->prepare('SELECT p.id,p.sms_rate FROM tenants t LEFT JOIN platform_subscription_plans p ON p.id=t.subscription_plan_id WHERE t.id=?');
    $st->execute([$tenant]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$plan) throw new RuntimeException('SMS billing tenant not found.');
    $pdo->prepare('INSERT INTO platform_sms_usage (tenant_id,client_id,plan_id,provider,recipient,rate) VALUES (?,?,?,?,?,?)')
        ->execute([$tenant,$client,$plan['id'], $config['provider'] ?? 'custom',$phone,$plan['sms_rate'] ?? 0]);
    return (int)$pdo->lastInsertId();
}

function finishPlatformSmsUsage(PDO $pdo, int $id, array $result, string $phone, string $message, bool $uwazii): void {
    $state = !empty($result['success']) ? 'accepted' : (!empty($result['uncertain']) ? 'unknown' : 'failed');
    $ids = [];
    if ($state === 'accepted' && $uwazii) {
        $json = json_decode($result['response'] ?? '', true);
        $ids = array_column($json['data'][$phone] ?? [], 'id_state');
    }
    $units = $state === 'accepted' ? ($ids ? count($ids) : smsMessageUnits($message)) : 0;
    $pdo->prepare("UPDATE platform_sms_usage SET status=?,sms_units=?,amount=rate*?,provider_ids=?,completed_at=NOW() WHERE id=? AND status='sending'")
        ->execute([$state,$units,$units,$ids ? json_encode($ids) : null,$id]);
}

function platformSmsMonthUsage(PDO $pdo, int $tenant, string $period, ?int $invoiceId = null): array {
    // Unbilled sends after a settled invoice carry forward to the next open invoice.
    $st = $pdo->prepare("SELECT id,sms_units,amount FROM platform_sms_usage
        WHERE tenant_id=? AND status='accepted' AND created_at<? AND (invoice_id IS NULL OR invoice_id=?) FOR UPDATE");
    $st->execute([$tenant,date('Y-m-01',strtotime($period.' +1 month')),$invoiceId ?? 0]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return ['messages'=>count($rows),'units'=>array_sum(array_column($rows,'sms_units')),
        'amount'=>round(array_sum(array_column($rows,'amount')),2),'ids'=>array_column($rows,'id')];
}

function ensureSmsInvoiceSchema(PDO $pdo): void {
    ensureSmsUsageSchema($pdo);
    ensureColumn($pdo,'platform_invoices','sms_message_count','INT UNSIGNED NOT NULL DEFAULT 0');
    ensureColumn($pdo,'platform_invoices','sms_units','INT UNSIGNED NOT NULL DEFAULT 0');
    ensureColumn($pdo,'platform_invoices','sms_amount','DECIMAL(15,2) NOT NULL DEFAULT 0');
    $st=$pdo->query("SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='platform_invoices' AND COLUMN_NAME='total_due'");
    $expression=(string)$st->fetchColumn();
    if ($expression !== '' && stripos($expression,'sms_amount') === false) {
        $pdo->exec('ALTER TABLE platform_invoices MODIFY total_due DECIMAL(15,2) GENERATED ALWAYS AS (pppoe_user_count * pppoe_fee_per_user + hotspot_collections * hotspot_commission_rate + base_fee + sms_amount) STORED');
    } elseif ($expression === '') {
        throw new RuntimeException('Invoice total must be a generated column before enabling SMS billing.');
    }
}
