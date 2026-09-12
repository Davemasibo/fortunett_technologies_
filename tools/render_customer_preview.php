<?php
/** CLI-only production page preview with synthetic customer and database reads. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
date_default_timezone_set('Africa/Nairobi');
session_start();
$page = $argv[1] ?? 'dashboard.php';
if (!in_array($page, ['dashboard.php','packages.php','payment.php','account.php','devices.php','login.php','register.php','renew.php'], true)) exit(1);
$GLOBALS['previewPackage'] = ['id'=>39,'name'=>'Daily Wi-Fi','price'=>40,'description'=>'Stay connected throughout your day', 'download_speed'=>10,'upload_speed'=>10,'validity_value'=>24,'validity_unit'=>'hours','data_limit'=>0,'device_limit'=>1,'connection_type'=>'hotspot','mikrotik_profile'=>'pkg39','hotspot_server'=>'all','status'=>'active'];
$GLOBALS['previewCustomer'] = ['id'=>1,'tenant_id'=>9,'package_id'=>39,'full_name'=>'Alex Mwangi','name'=>'Alex Mwangi','username'=>'preview','account_number'=>'DEMO001','phone'=>'0712345678','email'=>'alex@example.test','status'=>'active','expiry_date'=>date('Y-m-d H:i:s',time()+3600*18),'account_balance'=>0,'connection_type'=>'hotspot','created_at'=>'2026-09-01 10:00:00','address'=>'Nairobi'];
class CustomerPreviewPDO extends PDO {
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new CustomerPreviewStatement($query); }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false { return new CustomerPreviewStatement($query); }
}
class CustomerPreviewStatement extends PDOStatement {
    public function __construct(private string $sql) {}
    public function execute(?array $params=null): bool { return true; }
    public function fetchColumn(int $column=0): mixed { return false; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return $this->fetchAll($mode)[0] ?? false; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        $settings=['company_name'=>'Ghettoh Link','brand_color'=>'#00856a','hs_accent'=>'#00856a','hs_card_mode'=>getenv('PREVIEW_THEME') ?: 'light','hs_bg_color'=>'#e9f3ef','hs_bg_color2'=>'#b8d7ce','hs_radius'=>'18'];
        if (str_contains($this->sql,'FROM tenants')) return [['id'=>9,'company_name'=>'Ghettoh Link','setting_key'=>'brand_color','setting_value'=>'#00856a']];
        if (str_contains($this->sql,'tenant_settings')) return $settings;
        if (str_contains($this->sql,'FROM packages')) return [$GLOBALS['previewPackage']];
        return [];
    }
}
$pdo = new CustomerPreviewPDO();
$_SERVER = array_merge($_SERVER,['HTTP_HOST'=>'preview.example.test','REQUEST_METHOD'=>'GET','PHP_SELF'=>'/customer/'.$page,'REQUEST_URI'=>'/customer/'.$page]);
if (in_array($page,['dashboard.php','packages.php','payment.php','account.php','devices.php'],true)) $_SESSION['customer_data']=$GLOBALS['previewCustomer'];
function requireCustomerLogin() { return $GLOBALS['previewCustomer']; }
function formatCurrency($amount) { return 'KES '.number_format($amount,2); }
function formatDate($date) { return $date ? date('M d, Y',strtotime($date)) : 'N/A'; }
function formatDateTime($date) { return $date ? date('M d, Y h:i A',strtotime($date)) : 'N/A'; }
function isSubscriptionActive($expiry) { return strtotime($expiry)>time(); }
function getDaysUntilExpiry($expiry) { return max(0,floor((strtotime($expiry)-time())/86400)); }
function customerPreviewRender(string $file): void {
    global $pdo, $tenant_branding;
    $source=file_get_contents($file);
    $source=preg_replace("~require_once __DIR__ \\. '/(?:\\.\\./)*includes/(?:auth|db_master)\\.php';~",'', $source);
    $source=str_replace("include 'includes/header.php';", "customerPreviewRender(__DIR__ . '/includes/header.php');",$source);
    $source=str_replace("include 'includes/footer.php';", "customerPreviewRender(__DIR__ . '/includes/footer.php');",$source);
    $source=str_replace('__DIR__',var_export(dirname($file),true),$source);
    eval('?>'.$source);
}
customerPreviewRender(realpath(__DIR__.'/../customer/'.$page));
