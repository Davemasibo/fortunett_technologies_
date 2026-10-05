<?php
require_once __DIR__.'/../includes/onboarding.php';
require_once __DIR__.'/../includes/trial_billing.php';
class OnboardingTestPDO extends PDO {
    public array $queries=[];
    public array $routers=[['id'=>7,'name'=>'Branch A','status'=>'active','last_seen'=>'2026-10-03 12:00:00','service_types'=>'hotspot'],['id'=>8,'name'=>'Branch B','status'=>'pending','last_seen'=>null,'service_types'=>'hotspot']];
    public bool $configured=false;
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {return new OnboardingTestStatement($this,$query);}
}
class OnboardingTestStatement extends PDOStatement {
    public function __construct(private OnboardingTestPDO $db,private string $sql) {}
    public function execute(?array $params=null): bool {$this->db->queries[]=[$this->sql,$params];return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed {return ['company_name'=>'Branch Network','subdomain'=>'branches'];}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {if (str_contains($this->sql,'router_onboarding_checks')) return $this->db->configured ? [['router_id'=>7,'services'=>'hotspot','verified_at'=>'2026-10-03'],['router_id'=>8,'services'=>'hotspot','verified_at'=>'2026-10-03']] : []; return $this->db->routers;}
    public function fetchColumn(int $column=0): mixed {return $this->db->configured ? 1 : 0;}
}
function checkOnboarding(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$db=new OnboardingTestPDO();
$progress=tenantOnboardingProgress($db,22);
checkOnboarding(count($progress['routers'])===2 && !$progress['steps'][2]['done'],'Second pending device prevents router setup from being complete');
foreach($db->queries as [$sql,$params]) checkOnboarding($params===[22],'Progress reads stay within authenticated tenant');
$db->configured=true;
$db->routers[1]['status']='active';$db->routers[1]['last_seen']='2026-10-03 12:01:00';
$progress=tenantOnboardingProgress($db,22);
checkOnboarding($progress['completed']===$progress['total'],'Verified devices, packages, provisioning and collection complete setup');
$db->queries=[];repairUnpaidTrialInvoices($db,22);
foreach($db->queries as [$sql,$params]) {
    checkOnboarding($params===[22] && str_contains($sql,"t.status='trial'") && str_contains($sql,'COALESCE(i.amount_paid,0)=0'),'Trial corrections preserve other tenants and invoices with payments');
}
