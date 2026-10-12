<?php
function smsAvailableTemplates(PDO $pdo, int $tenant): array {
    $rows=[];
    try {
        $st=$pdo->prepare('SELECT * FROM sms_templates WHERE tenant_id=? OR is_global=1 ORDER BY tenant_id=? DESC,template_name');
        $st->execute([$tenant,$tenant]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (!in_array($e->errorInfo[1] ?? 0,[1054,1146],true)) throw $e;
        try { $st=$pdo->prepare('SELECT * FROM sms_templates WHERE tenant_id=? ORDER BY template_name'); $st->execute([$tenant]); $rows=$st->fetchAll(PDO::FETCH_ASSOC); }
        catch (PDOException $missing) { if (($missing->errorInfo[1] ?? 0)!==1146) throw $missing; }
    }
    $unique=[];
    foreach($rows as $row) $unique[$row['template_key']] ??= $row;
    $unique['login_credentials'] ??= ['template_key'=>'login_credentials','template_name'=>'Login Credentials',
        'template_content'=>"Hello {name}, your internet login details are:\nUsername: {username}\nPassword: {password}",'is_global'=>0];
    return array_values($unique);
}

function smsTenantTemplateContext(PDO $pdo, int $tenant): array {
    $context = ['support' => '', 'paybill' => ''];
    if ($tenant < 1) return $context;
    $st = $pdo->prepare('SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id=?');
    $st->execute([$tenant]);
    $settings = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['hs_support_phone', 'support_phone'] as $key) {
        if (trim((string)($settings[$key] ?? '')) !== '') { $context['support'] = trim($settings[$key]); break; }
    }
    $context['paybill'] = trim((string)($settings['hs_paybill'] ?? $settings['paybill'] ?? ''));
    if ($context['support'] === '') {
        $st = $pdo->prepare('SELECT * FROM tenants WHERE id=?');
        $st->execute([$tenant]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['support_phone', 'contact_phone', 'phone'] as $key) {
            if (trim((string)($row[$key] ?? '')) !== '') { $context['support'] = trim($row[$key]); break; }
        }
    }
    if ($context['paybill'] === '') {
        require_once __DIR__ . '/credential_helper.php';
        $st = $pdo->prepare("SELECT gateway_type, credentials FROM payment_gateways WHERE tenant_id=? AND is_active=1 AND gateway_type IN ('mpesa_api','paybill_no_api','bank_account') ORDER BY is_default DESC,id ASC");
        $st->execute([$tenant]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $credentials = decrypt_gateway_credentials((string)$row['credentials']);
            if (($credentials['shortcode_type'] ?? 'paybill') === 'till') continue;
            $number = $row['gateway_type'] === 'mpesa_api'
                ? ($credentials['shortcode'] ?? $credentials['paybill'] ?? '')
                : ($credentials['paybill_number'] ?? '');
            if (trim((string)$number) !== '') { $context['paybill'] = trim((string)$number); break; }
        }
    }
    return $context;
}

function smsRenderCustomerTemplate(string $message, array $client, array $tenantContext = []): string {
    $first=function(array $keys) use($client) { foreach($keys as $key) if(isset($client[$key]) && trim((string)$client[$key])!=='') return (string)$client[$key]; return ''; };
    // Never use auth_password/password: these can contain password hashes.
    $map=['support'=>trim((string)($tenantContext['support'] ?? '')), 'paybill'=>trim((string)($tenantContext['paybill'] ?? '')),
        'name'=>$first(['full_name','name']),'username'=>$first(['mikrotik_username','username']),
        'password'=>$first(['mikrotik_password']),'phone'=>$first(['phone']),'account_number'=>$first(['account_number']),
        'package_name'=>$first(['package_name','subscription_plan']),
        'amount'=>isset($client['package_price'])?number_format((float)$client['package_price'],2,'.',''):'',
        'expiry_date'=>!empty($client['expiry_date']) && strtotime($client['expiry_date'])!==false?date('d M Y H:i',strtotime($client['expiry_date'])):''];
    $aliases=['customer_name'=>'name','full_name'=>'name','client_name'=>'name','mikrotik_username'=>'username','login_username'=>'username',
        'mikrotik_password'=>'password','login_password'=>'password','phone_number'=>'phone','account'=>'account_number','expiry'=>'expiry_date','package'=>'package_name','package_price'=>'amount',
        'support_phone'=>'support','support_contact'=>'support','paybill_number'=>'paybill'];
    return preg_replace_callback('/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}|\{\s*([a-z_][a-z0-9_]*)\s*\}|\[\s*([a-z_][a-z0-9_]*)\s*\]/i',function($match)use($map,$aliases){
        $key=strtolower($match[1] ?: ($match[2] ?: ($match[3] ?? ''))); $key=$aliases[$key] ?? $key;
        if(!array_key_exists($key,$map)) throw new InvalidArgumentException('Unknown SMS variable: '.$key.'. Edit the template before sending.');
        if ($map[$key] === '' && in_array($key, ['support','paybill'], true)) throw new InvalidArgumentException('Tenant '.$key.' is not configured. Set '.($key === 'support' ? 'the support phone in Hotspot settings or your account contact phone' : 'the paybill in Hotspot settings or an active payment gateway').' before sending this template.');
        if($map[$key]==='') throw new InvalidArgumentException('Customer is missing '.$key.'. Update their details before sending this template.');
        return $map[$key];
    },$message);
}
