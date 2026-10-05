<?php
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require_once __DIR__.'/../includes/email_helper.php';
$config=fortunettPlatformEmailConfig($pdo);
$sender=$config['from_email'] ?? '';
echo 'SMTP host: '.($config['smtp_host'] ?? 'NOT CONFIGURED')."\n";
echo 'Port: '.($config['smtp_port'] ?? 587)."\n";
echo 'From: '.$sender."\n";
echo 'Credentials present: '.(!empty($config['smtp_username']) && !empty($config['smtp_password']) ? 'yes' : 'no')."\n";
echo "Transport: authenticated SMTP only; no PHP mail fallback.\n";
if (!filter_var($sender,FILTER_VALIDATE_EMAIL)) exit("Set a valid sender address.\n");
$domain=substr(strrchr($sender,'@'),1);
foreach ([$domain,'_dmarc.'.$domain] as $name) {
    $records=@dns_get_record($name,DNS_TXT);
    echo $name." TXT:\n";
    foreach ($records ?: [] as $record) {
        $txt=$record['txt'] ?? implode('',$record['entries'] ?? []);
        if (str_starts_with($txt,'v=spf1') || str_starts_with($txt,'v=DMARC1')) echo '  '.$txt."\n";
    }
    if (!$records) echo "  No TXT record returned.\n";
}
if (in_array(strtolower($domain),['gmail.com','googlemail.com'],true)) {
    echo "Gmail owns this sender domain and its DNS authentication. Changing FortuNett-domain DNS will not fix authentication for this Gmail From address.\n";
} else {
    $selector=$argv[1] ?? get_env_var('MAIL_DKIM_SELECTOR');
    if ($selector && preg_match('/^[a-z0-9_-]+$/i',$selector)) {
        $name=$selector.'._domainkey.'.$domain;
        echo $name.' DKIM record present: '.(@dns_get_record($name,DNS_TXT|DNS_CNAME) ? 'yes' : 'not found')."\n";
    } else echo "Pass the provider's DKIM selector as the first argument to check its public DNS record.\n";
}
echo "This command sends no mail and does not test inbox placement. Use Gmail > Show original for SPF, DKIM and DMARC results; provider logs for delivery and rejection details.\n";
