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
    foreach (smsDefaultTemplates() as $key=>[$name,$content]) {
        if (!isset($unique[$key])) $unique[$key]=['template_key'=>$key,'template_name'=>$name,'template_content'=>$content,'is_global'=>1];
        elseif (!str_contains($unique[$key]['template_content'],'{portal_url}')) $unique[$key]['template_content']=$content;
    }
    foreach ($unique as &$template) {
        if (!preg_match('/\{\s*(?:portal_url|portal_link)\s*\}/i',$template['template_content'])) $template['template_content'].="\nCustomer portal: {portal_url}";
    }
    unset($template);
    return array_values($unique);
}

function smsDefaultTemplates(): array {
    return [
        'account_expired'=>['Account Expired','Dear {name}, your internet subscription expired on {expiry}. Renew to restore service. {payment_instructions} Packages and renewal: {portal_url}. {support_line}'],
        'account_expiring'=>['Account Expiring','Dear {name}, your internet subscription expires on {expiry}. Renew before then to stay connected. {payment_instructions} Packages and renewal: {portal_url}. {support_line}'],
        'payment_reminder'=>['Payment Reminder','Dear {name}, your internet renewal is due on {expiry}. {payment_instructions} View packages and renew: {portal_url}. {support_line}'],
        'payment_received'=>['Payment Received','Dear {name}, we received your payment of KES {amount}. Your internet is active until {expiry}. View your account and packages: {portal_url}. {support_line}'],
        'welcome'=>['Welcome Message','Dear {name}, welcome to {company}. Your account number is {account_number}. {payment_instructions} Log in to choose a package: {portal_url}. {support_line}'],
        'login_credentials'=>['Login Credentials',"Dear {name}, your internet login details are:\nUsername: {username}\nPassword: {password}\nView your account and packages: {portal_url}. {support_line}"],
    ];
}

function smsTenantTemplateContext(PDO $pdo, int $tenant): array {
    $context = ['support'=>'','paybill'=>'','payment_kind'=>'portal','payment_number'=>'','company'=>'your internet provider','portal_url'=>''];
    if ($tenant < 1) return $context;
    $st=$pdo->prepare('SELECT * FROM tenants WHERE id=?');$st->execute([$tenant]);$row=$st->fetch(PDO::FETCH_ASSOC) ?: [];
    $context['company']=trim((string)($row['company_name'] ?? '')) ?: $context['company'];
    $st=$pdo->prepare('SELECT setting_key,setting_value FROM tenant_settings WHERE tenant_id=?');$st->execute([$tenant]);$settings=$st->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['hs_support_phone','support_phone','support_number'] as $key) {
        if (trim((string)($settings[$key] ?? ''))!=='') { $context['support']=trim($settings[$key]);break; }
    }
    if ($context['support']==='') foreach (['support_phone','contact_phone','phone'] as $key) {
        if (trim((string)($row[$key] ?? ''))!=='') { $context['support']=trim($row[$key]);break; }
    }
    $domain='fortunetttech.site';
    try { $configured=$pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain' LIMIT 1")->fetchColumn();
        if (is_string($configured) && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i',$configured)) $domain=$configured;
    } catch (PDOException $e) { if (($e->errorInfo[1] ?? 0)!==1146) throw $e; }
    $subdomain=trim((string)($row['subdomain'] ?? ''));
    if ($subdomain==='' || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i',$subdomain)) throw new InvalidArgumentException('Tenant portal subdomain is not configured. Update the tenant account before sending.');
    $context['portal_url']='https://'.$subdomain.'.'.$domain.'/customer/login.php';
    $override=trim((string)($settings['hs_paybill'] ?? '')) ?: trim((string)($settings['paybill'] ?? ''));
    if ($override!=='') {
        $context['payment_number']=$override;$context['payment_kind']=($settings['hs_pay_type'] ?? 'paybill')==='till' ? 'till' : 'paybill';
    } else {
        require_once __DIR__.'/credential_helper.php';
        $st=$pdo->prepare('SELECT gateway_type,credentials FROM payment_gateways WHERE tenant_id=? AND is_active=1 ORDER BY is_default DESC,id ASC');$st->execute([$tenant]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $gateway) {
            $c=decrypt_gateway_credentials((string)$gateway['credentials']);
            if ($gateway['gateway_type']==='mpesa_api') {
                $context['payment_number']=trim((string)($c['shortcode'] ?? ''));
                $context['payment_kind']=($c['shortcode_type'] ?? 'paybill')==='till' ? 'till' : 'paybill';
            } elseif ($gateway['gateway_type']==='paybill_no_api') {
                $context['payment_number']=trim((string)($c['paybill_number'] ?? ''));$context['payment_kind']='paybill';
            } else { break; } // Other configured methods are offered through the portal.
            if ($context['payment_number']!=='') break;
        }
    }
    if ($context['payment_kind']==='paybill') $context['paybill']=$context['payment_number'];
    return $context;
}

function smsPaymentInstructions(array $context, array $client): string {
    $number=trim((string)($context['payment_number'] ?? ''));
    $account=trim((string)($client['account_number'] ?? ''));
    $price=$client['package_price'] ?? $client['pkg_price'] ?? null;
    $pay=$price!==null && (float)$price>0 ? 'Pay KES '.number_format((float)$price,2,'.','').' via ' : 'Pay via ';
    if (($context['payment_kind'] ?? '')==='paybill' && $number!=='' && $account!=='') return $pay.'M-Pesa Paybill '.$number.', Account '.$account.'.';
    if (($context['payment_kind'] ?? '')==='till' && $number!=='') return $pay.'M-Pesa Buy Goods, Till '.$number.'.';
    return 'Log in to your customer portal to choose a package and pay using the available payment options.';
}

function smsRenderCustomerTemplate(string $message, array $client, array $tenantContext = []): string {
    $first=function(array $keys) use($client) { foreach($keys as $key) if(isset($client[$key]) && trim((string)$client[$key])!=='') return (string)$client[$key]; return ''; };
    // Never use auth_password/password: these can contain password hashes.
    $map=['portal_url'=>(string)($tenantContext['portal_url'] ?? ''),'company'=>(string)($tenantContext['company'] ?? ''),
        'payment_instructions'=>smsPaymentInstructions($tenantContext,$client),
        'support_line'=>empty($tenantContext['support']) ? '' : 'Need help? Contact '.$tenantContext['support'].'.',
        'support'=>trim((string)($tenantContext['support'] ?? '')), 'paybill'=>trim((string)($tenantContext['paybill'] ?? '')),
        'name'=>$first(['full_name','name']),'username'=>$first(['mikrotik_username','username']),
        'password'=>$first(['mikrotik_password']),'phone'=>$first(['phone']),'account_number'=>$first(['account_number']),
        'package_name'=>$first(['package_name','subscription_plan']),
        'amount'=>isset($client['payment_amount']) || isset($client['package_price']) ? number_format((float)($client['payment_amount'] ?? $client['package_price']),2,'.',''):'',
        'expiry_date'=>!empty($client['expiry_date']) && strtotime($client['expiry_date'])!==false?date('d M Y H:i',strtotime($client['expiry_date'])):''];
    $aliases=['customer_name'=>'name','full_name'=>'name','client_name'=>'name','mikrotik_username'=>'username','login_username'=>'username',
        'mikrotik_password'=>'password','login_password'=>'password','phone_number'=>'phone','account'=>'account_number','expiry'=>'expiry_date','package'=>'package_name','package_price'=>'amount',
        'support_phone'=>'support','support_contact'=>'support','paybill_number'=>'paybill','portal_link'=>'portal_url','payment_details'=>'payment_instructions'];
    return preg_replace_callback('/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}|\{\s*([a-z_][a-z0-9_]*)\s*\}|\[\s*([a-z_][a-z0-9_]*)\s*\]/i',function($match)use($map,$aliases){
        $key=strtolower($match[1] ?: ($match[2] ?: ($match[3] ?? ''))); $key=$aliases[$key] ?? $key;
        if(!array_key_exists($key,$map)) throw new InvalidArgumentException('Unknown SMS variable: '.$key.'. Edit the template before sending.');
        if ($map[$key] === '' && in_array($key, ['support','paybill'], true)) throw new InvalidArgumentException('Tenant '.$key.' is not configured. Set '.($key === 'support' ? 'the support phone in Hotspot settings or your account contact phone' : 'the paybill in Hotspot settings or an active payment gateway').' before sending this template.');
        if($map[$key]==='' && $key!=='support_line') throw new InvalidArgumentException('Customer is missing '.$key.'. Update their details before sending this template.');
        return $map[$key];
    },$message);
}
