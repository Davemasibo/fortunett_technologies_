<?php
require_once __DIR__.'/../includes/sms_templates.php';
function communicationCheck(bool $ok,string $label):void { if(!$ok)throw new RuntimeException($label); echo "PASS: $label\n"; }
class CommunicationPDO extends PDO {
    public array $settings=[];
    public array $gateway=['gateway_type'=>'mpesa_api','credentials'=>'{"shortcode":"123456","shortcode_type":"paybill"}'];
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false { return new CommunicationStatement($this,$query); }
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false { return new CommunicationStatement($this,$query); }
}
class CommunicationStatement extends PDOStatement {
    private int $tenant=0;
    public function __construct(private CommunicationPDO $db,private string $sql){}
    public function execute(?array $params=null):bool {$this->tenant=(int)($params[0] ?? 0);return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed { return ['company_name'=>'Example ISP','subdomain'=>'tenant'.$this->tenant,'contact_phone'=>'0700123456']; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array { return str_contains($this->sql,'tenant_settings') ? $this->db->settings : [$this->db->gateway]; }
    public function fetchColumn(int $column=0):mixed {return 'fortunetttech.site';}
}
$db=new CommunicationPDO();$context=smsTenantTemplateContext($db,1);
$client=['full_name'=>'Dave','account_number'=>'GH001','package_price'=>500,'payment_amount'=>1000,'expiry_date'=>'2026-10-13 15:00:00','mikrotik_username'=>'dave','mikrotik_password'=>'1234'];
$message=smsRenderCustomerTemplate(smsDefaultTemplates()['account_expired'][1],$client,$context);
communicationCheck(str_contains($message,'Pay KES 500.00 via M-Pesa Paybill 123456, Account GH001.'),'Renewal gives actual tenant paybill, package price and customer account');
communicationCheck(str_contains($message,'https://tenant1.fortunetttech.site/customer/login.php'),'Portal URL uses saved tenant subdomain');
$db->gateway['credentials']='{"shortcode":"654321","shortcode_type":"till"}';$till=smsTenantTemplateContext($db,2);
$message=smsRenderCustomerTemplate(smsDefaultTemplates()['account_expiring'][1],$client,$till);
communicationCheck(str_contains($message,'Buy Goods, Till 654321') && !str_contains($message,'Paybill') && !str_contains($message,'Account GH001'),'Till instructions do not incorrectly request a paybill account');
communicationCheck(str_contains($message,'https://tenant2.') && !str_contains($message,'https://tenant1.'),'Payment and portal context stays tenant scoped');
$db->settings=['hs_paybill'=>'777777','hs_pay_type'=>'paybill','hs_support_phone'=>'0799999999'];
$override=smsTenantTemplateContext($db,1);
communicationCheck(str_contains(smsPaymentInstructions($override,$client),'777777') && $override['support']==='0799999999','Hotspot payment/support overrides are respected');
communicationCheck(str_contains(smsPaymentInstructions($context,[]),'customer portal') && !str_contains(smsPaymentInstructions($context,[]),'Account'),'Missing customer account directs to portal rather than inventing a reference');
communicationCheck(str_contains(smsRenderCustomerTemplate(smsDefaultTemplates()['payment_received'][1],$client,$context),'KES 1000.00'),'Payment receipt uses actual payment amount rather than package price');
$context['support']='';
foreach(smsDefaultTemplates() as $key=>[$name,$template]) {
    $rendered=smsRenderCustomerTemplate($template,$client,$context);
    communicationCheck(str_contains($rendered,$context['portal_url']) && !preg_match('/\{[a-z_]+\}/i',$rendered),'Complete portal-linked '.$key.' template');
}
