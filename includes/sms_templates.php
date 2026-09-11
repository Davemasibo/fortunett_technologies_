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

function smsRenderCustomerTemplate(string $message, array $client): string {
    $first=function(array $keys) use($client) { foreach($keys as $key) if(isset($client[$key]) && trim((string)$client[$key])!=='') return (string)$client[$key]; return ''; };
    // Never use auth_password/password: these can contain password hashes.
    $map=['name'=>$first(['full_name','name']),'username'=>$first(['mikrotik_username','username']),
        'password'=>$first(['mikrotik_password']),'phone'=>$first(['phone']),'account_number'=>$first(['account_number']),
        'package_name'=>$first(['package_name','subscription_plan']),
        'amount'=>isset($client['package_price'])?number_format((float)$client['package_price'],2,'.',''):'',
        'expiry_date'=>!empty($client['expiry_date']) && strtotime($client['expiry_date'])!==false?date('d M Y H:i',strtotime($client['expiry_date'])):''];
    $aliases=['customer_name'=>'name','full_name'=>'name','client_name'=>'name','mikrotik_username'=>'username','login_username'=>'username',
        'mikrotik_password'=>'password','login_password'=>'password','phone_number'=>'phone','account'=>'account_number','expiry'=>'expiry_date','package'=>'package_name','package_price'=>'amount'];
    return preg_replace_callback('/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}|\{\s*([a-z_][a-z0-9_]*)\s*\}|\[\s*([a-z_][a-z0-9_]*)\s*\]/i',function($match)use($map,$aliases){
        $key=strtolower($match[1] ?: ($match[2] ?: ($match[3] ?? ''))); $key=$aliases[$key] ?? $key;
        if(!array_key_exists($key,$map)) throw new InvalidArgumentException('Unknown SMS variable: '.$key.'. Edit the template before sending.');
        if($map[$key]==='') throw new InvalidArgumentException('Customer is missing '.$key.'. Update their details before sending this template.');
        return $map[$key];
    },$message);
}
