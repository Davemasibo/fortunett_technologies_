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
$row=['purchase_entitlement'=>['repairable'=>true,'expiry'=>'2026-09-11 12:30:00'],
    'expiry_date'=>'2026-09-11 12:30:00','entitled_now'=>true,
    'router_user'=>['disabled'=>'false','limit-uptime'=>'30m'], 'watchdog_installed'=>true,
    'deadline_schedule'=>['disabled'=>'false','start-date'=>'2026-09-11','start-time'=>'12:30:00'],
    'latest_allowed_router_deadline'=>'20260911123000'];
evidenceCheck(connectivityExpiryFindings($row)===[],'Matching purchased, database and router deadlines produce no expiry findings');
$row['expiry_date']='2026-09-11 13:00:00';
evidenceCheck(in_array('DATABASE_EXPIRY_EXCEEDS_PURCHASES',connectivityExpiryFindings($row)),'Extra database time is flagged even while the subscription is active');
$row['deadline_schedule']['start-time']='13:00:00';
evidenceCheck(in_array('ROUTER_DEADLINE_EXCEEDS_DATABASE_EXPIRY',connectivityExpiryFindings($row)),'Router deadline beyond purchased database expiry is flagged');
$row['deadline_schedule']['start-date']='sep/11/2026';
evidenceCheck(in_array('ROUTER_DEADLINE_EXCEEDS_DATABASE_EXPIRY',connectivityExpiryFindings($row)),'Legacy RouterOS dates receive the same deadline comparison');
$row['deadline_schedule']['start-date']='invalid';
evidenceCheck(in_array('ROUTER_DEADLINE_UNREADABLE',connectivityExpiryFindings($row)),'Invalid scheduler timestamp cannot pass an expiry audit');
$row['deadline_schedule']=null;$row['watchdog_installed']=false;$row['router_user']['limit-uptime']='0s';$row['entitled_now']=false;
$findings=connectivityExpiryFindings($row);
foreach(['PAID_DEADLINE_MISSING_OR_DISABLED','PAID_EXPIRY_WATCHDOG_MISSING','ENABLED_HOTSPOT_WITHOUT_UPTIME_LIMIT','NOT_ENTITLED_ROUTER_USER_ENABLED'] as $finding) evidenceCheck(in_array($finding,$findings),$finding.' is visible even without an active session');
$row['purchase_entitlement']=['repairable'=>false];
evidenceCheck(in_array('PURCHASE_HISTORY_REQUIRES_REVIEW',connectivityExpiryFindings($row)),'Missing historical purchase terms never receive a clean audit');
