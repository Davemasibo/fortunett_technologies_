<?php
require_once __DIR__ . '/../includes/connectivity_audit.php';
function evidenceCheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$row=['last_seen'=>null,'router_user'=>['uptime'=>'1h3m7s','profile'=>'pkg37'],'mikrotik_profile'=>'pkg37','findings'=>['NO_RECORDED_CONNECTION']];
$findings=connectivityEvidenceFindings($row);
evidenceCheck(in_array('ROUTER_USAGE_WITHOUT_LAST_SEEN_TIMESTAMP',$findings) && !in_array('NO_RECORDED_CONNECTION',$findings),'Router usage is distinguished from missing timestamps');
$row['router_user']['uptime']='0s';
evidenceCheck(in_array('NO_RECORDED_CONNECTION',connectivityEvidenceFindings($row)),'Zero uptime does not invent prior connectivity');
$row['router_user']['profile']='old-package';
evidenceCheck(in_array('ROUTER_PROFILE_DIFFERS_FROM_CURRENT_PACKAGE',connectivityEvidenceFindings($row)),'Current package and router profile mismatch is visible');
$row['entitled_now']=true; $row['connection_type']='hotspot'; $row['device_context_available']=false;
evidenceCheck(in_array('NO_RECENT_DEVICE_FOR_AUTOMATIC_LOGIN',connectivityEvidenceFindings($row)),'Missing device identity explains unavailable automatic login');
$row['payments']=['confirmed_count'=>0]; $row['findings']=['PAID_ACCESS_NO_ACTIVE_SESSION'];
$findings=connectivityEvidenceFindings($row);
evidenceCheck(in_array('ACTIVE_ACCESS_WITHOUT_COMPLETED_PAYMENT_RECORD',$findings) && !in_array('PAID_ACCESS_NO_ACTIVE_SESSION',$findings),'An active database status alone is not claimed as confirmed payment');
