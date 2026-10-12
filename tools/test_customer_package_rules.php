<?php
require_once __DIR__.'/../includes/customer_packages.php';
function packageRuleCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$client=['tenant_id'=>2,'connection_type'=>'pppoe'];
$package=['tenant_id'=>2,'connection_type'=>'pppoe','type'=>'hotspot','status'=>'active'];
assertCustomerPackageCompatible($client,$package);
packageRuleCheck(customerPackageType($package)==='pppoe','Connection type takes precedence over stale legacy package type');
foreach ([['connection_type'=>'hotspot'],['tenant_id'=>3],['status'=>'inactive']] as $change) {
    $blocked=false;try{assertCustomerPackageCompatible($client,array_replace($package,$change));}catch(InvalidArgumentException $e){$blocked=true;}
    packageRuleCheck($blocked,'Invalid package selection is rejected');
}
assertCustomerPackageCompatible(['tenant_id'=>2,'connection_type'=>'hotspot'],['tenant_id'=>2,'type'=>'hotspot','status'=>'active']);
packageRuleCheck(true,'Legacy hotspot packages remain selectable by hotspot customers');
