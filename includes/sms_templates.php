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
        'welcome'=>['Welcome Message','Welcome to {network_name}, {name}! {payment_instructions} {account_line} Log in to choose a package: {portal_url}. {support_line}'],
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
    // Use the actual platform till only through checkout, where the payment is
    // attributed to this tenant and customer. Never invent a till/account.
    try {
        $platform=$pdo->query('SELECT shortcode,shortcode_type,environment FROM platform_mpesa_config LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($platform && in_array(strtolower($platform['environment'] ?? ''), ['live','production'], true)
            && ($platform['shortcode_type'] ?? '')==='till' && !empty($platform['shortcode'])) {
            require_once __DIR__.'/credential_helper.php';
            $own=$pdo->prepare("SELECT credentials FROM payment_gateways WHERE tenant_id=? AND gateway_type='mpesa_api' AND is_active=1");
            $own->execute([$tenant]);
            $hasOwnApi=false;
            foreach ($own->fetchAll(PDO::FETCH_ASSOC) as $gateway) {
                $creds=decrypt_gateway_credentials((string)$gateway['credentials']);
                if (!empty($creds['consumer_key']) && !empty($creds['consumer_secret']) && !empty($creds['passkey']) && !empty($creds['shortcode'])) $hasOwnApi=true;
            }
            if (!$hasOwnApi) $context['platform_till']=(string)$platform['shortcode'];
        }
    } catch (PDOException $e) { if (($e->errorInfo[1] ?? 0)!==1146) throw $e; }
    return $context;
}

function smsPaymentInstructions(array $context, array $client): string {
    $number=trim((string)($context['payment_number'] ?? ''));
    $account=trim((string)($client['account_number'] ?? ''));
    $price=$client['package_price'] ?? $client['pkg_price'] ?? null;
    $amount=$price!==null && (float)$price>0 ? ' KSh '.number_format((float)$price,2,'.','') : '';
    if (($context['payment_kind'] ?? '')==='paybill' && $number!=='' && $account!=='') return 'Please pay for internet through M-Pesa Paybill '.$number.'. Account Number '.$account.'.'.($amount!=='' ? ' Amount:'. $amount.'.' : '') . (!empty($context['platform_till']) ? ' For immediate activation, pay'.$amount.' through your customer portal using platform M-Pesa Till '.$context['platform_till'].'.' : '');
    if (($context['payment_kind'] ?? '')==='till' && $number!=='') return 'Please pay for internet through M-Pesa Buy Goods, Till '.$number.'.'.($amount!=='' ? ' Amount:'.$amount.'.' : '');
    if (!empty($context['platform_till'])) return 'For immediate activation, pay'.$amount.' through your customer portal using M-Pesa Till '.$context['platform_till'].'.';
    return 'Log in to your customer portal to choose a package and pay using the available payment options.';
}

function smsNetworkName(string $company): string {
    $company=trim($company);
    if (preg_match('/network solutions$/i',$company)) return $company;
    return $company.(preg_match('/network$/i',$company) ? ' Solutions' : ' Network Solutions');
}

/** Also sanitizes already-rendered queued messages before provider delivery. */
function smsWithoutPppoeCredentials(string $message, array $client): string {
    if (strtolower(trim((string)($client['connection_type'] ?? ''))) !== 'pppoe') return $message;
    $message=preg_replace('/\b(?:Username|Password)\s*:\s*(?:\{\{?\s*[a-z_]+\s*\}\}?|\[\s*[a-z_]+\s*\]|[^\s\r\n]+)[ \t]*/i','',$message);
    $message=preg_replace('/\bLogin\s*:\s*[^\r\n]+/i','',$message);
    $message=preg_replace('/(?:\{\{\s*|\{\s*|\[\s*)(?:username|password|mikrotik_username|mikrotik_password|login_username|login_password)\s*(?:\}\}|\}|\])/i','',$message);
    $message=str_replace('your internet login details are:', 'your internet account details are:', $message);
    return trim(preg_replace('/\n[ \t]*\n+/',"\n",$message));
}

function smsRenderCustomerTemplate(string $message, array $client, array $tenantContext = []): string {
    $message=smsWithoutPppoeCredentials($message,$client);
    if (str_contains($message,'{account_line}') && empty($client['account_number'])) throw new InvalidArgumentException('Customer is missing account_number. Update their general information before sending this template.');
    $first=function(array $keys) use($client) { foreach($keys as $key) if(isset($client[$key]) && trim((string)$client[$key])!=='') return (string)$client[$key]; return ''; };
    // Never use auth_password/password: these can contain password hashes.
    $map=['portal_url'=>(string)($tenantContext['portal_url'] ?? ''),'company'=>(string)($tenantContext['company'] ?? ''),
        'network_name'=>smsNetworkName((string)($tenantContext['company'] ?? 'your internet provider')),
        'account_line'=>($tenantContext['payment_kind'] ?? '')==='paybill' && !empty($tenantContext['payment_number']) && $first(['account_number'])!=='' ? '' : 'Your customer Account Number is '.$first(['account_number']).'.',
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
        if($map[$key]==='' && !in_array($key,['support_line','account_line'],true)) throw new InvalidArgumentException('Customer is missing '.$key.'. Update their details before sending this template.');
        return $map[$key];
    },$message);
}
