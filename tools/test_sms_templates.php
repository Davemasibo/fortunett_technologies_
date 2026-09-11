<?php
require_once __DIR__ . '/../classes/SMSHelper.php';
function templateCheck(bool $ok,string $label): void { if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n"; }
$client=['full_name'=>'Alex','mikrotik_username'=>'alex123','mikrotik_password'=>'wifi456','phone'=>'254712345678','expiry_date'=>'2026-09-11 14:30:00','package_price'=>10];
templateCheck(smsRenderCustomerTemplate('Hello {name}: {username} / {password}',$client)==='Hello Alex: alex123 / wifi456','Credentials fill from saved router details');
templateCheck(smsRenderCustomerTemplate('{{ customer_name }} [login_username] {MIKROTIK_PASSWORD}',$client)==='Alex alex123 wifi456','Legacy aliases, double braces, brackets and case are supported');
templateCheck(str_contains(smsRenderCustomerTemplate('{expiry_date}',$client),'14:30'),'Short-package expiry includes time');
foreach(['{unknown}','{account_number}'] as $input){$failed=false;try{smsRenderCustomerTemplate($input,$client);}catch(InvalidArgumentException $e){$failed=true;}templateCheck($failed,'Unresolved or missing field blocks sending');}
$bad=$client;unset($bad['mikrotik_password']);$bad['auth_password']='hashed-secret';$failed=false;
try{smsRenderCustomerTemplate('{password}',$bad);}catch(InvalidArgumentException $e){$failed=true;}
templateCheck($failed,'Missing router password never falls back to an authentication hash');
class TemplatePDO extends PDO {
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new TemplateStatement(); }
}
class TemplateStatement extends PDOStatement {
    public function execute(?array $params=null): bool{return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return [];}
}
$templates=smsAvailableTemplates(new TemplatePDO(),9);
templateCheck($templates[0]['template_key']==='login_credentials','Credentials template exists even with no saved templates');
class TemplateSender extends SMSHelper { public function __construct(){} }
$result=(new TemplateSender())->send('254712345678','Hello {unknown}',null,false);
templateCheck(!$result['success'],'Provider boundary blocks raw placeholders without contacting provider');
