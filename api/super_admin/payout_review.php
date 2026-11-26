<?php
require_once __DIR__.'/../../includes/db_master.php';
require_once __DIR__.'/../../includes/payouts.php';
require_once __DIR__.'/../../includes/tenant_payouts.php';
require_once __DIR__.'/../../super_admin/includes/auth.php';
superAdminGuard();
header('Content-Type: application/json');
header('Cache-Control: no-store');
if(session_status()===PHP_SESSION_NONE)session_start();
$ok=fn($message)=>print(json_encode(['success'=>true,'message'=>$message]));
try {
    ensurePayoutTables($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS isp_payout_reconciliations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        admin_user_id INT NOT NULL,
        decision ENUM('paid','failed') NOT NULL,
        receipt VARCHAR(64) DEFAULT NULL,
        evidence TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_batch (batch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);throw new RuntimeException('POST required.');}
    if(!hash_equals((string)($_SESSION['payout_review_csrf']??''),(string)($_POST['csrf']??''))){http_response_code(403);throw new RuntimeException('Reload the page and try again.');}
    $action=(string)($_POST['action']??'');
    if($action==='verify_bank') {
        $tenantId=(int)($_POST['tenant_id']??0);$bank=tenantBankPayoutDestination($pdo,$tenantId);
        if(empty($bank['valid']))throw new RuntimeException('The tenant bank gateway is incomplete.');
        $pdo->prepare('INSERT IGNORE INTO tenant_payout_settings(tenant_id) VALUES(?)')->execute([$tenantId]);
        $pdo->prepare('UPDATE tenant_payout_settings SET bank_verified_at=NOW() WHERE tenant_id=?')->execute([$tenantId]);
        $ok('Bank destination verified.');exit;
    }
    if($action!=='resolve_unknown')throw new RuntimeException('Unknown review action.');
    $batchId=(int)($_POST['batch_id']??0);$decision=(string)($_POST['decision']??'');
    $evidence=trim((string)($_POST['evidence']??''));$receipt=trim((string)($_POST['receipt']??''));
    if(!in_array($decision,['paid','failed'],true))throw new RuntimeException('Choose paid or not paid.');
    if(strlen($evidence)<8 || strlen($evidence)>1000)throw new RuntimeException('Enter a short reconciliation note referencing the M-Pesa statement or Safaricom support confirmation.');
    if($decision==='paid' && $receipt==='')throw new RuntimeException('Enter the M-Pesa transaction receipt before marking paid.');
    $pdo->beginTransaction();
    $st=$pdo->prepare("SELECT * FROM isp_payout_batches WHERE id=? AND (status='unknown' OR (status='accepted' AND created_at < DATE_SUB(NOW(),INTERVAL 15 MINUTE))) FOR UPDATE");$st->execute([$batchId]);$batch=$st->fetch(PDO::FETCH_ASSOC);
    if(!$batch)throw new RuntimeException('This payout is no longer awaiting reconciliation.');
    if($decision==='paid') {
        $pdo->prepare("UPDATE isp_payout_batches SET status='paid',transaction_id=?,result_desc='Manually reconciled as paid',completed_at=NOW() WHERE id=? AND (status='unknown' OR (status='accepted' AND created_at < DATE_SUB(NOW(),INTERVAL 15 MINUTE)))")->execute([$receipt,$batchId]);
        settlePayoutBatch($pdo,$batch,'M-Pesa payout reconciled as paid · '.$receipt);
    } else {
        $pdo->prepare("UPDATE isp_payout_batches SET status='failed',result_desc='Manually reconciled as not paid',completed_at=NOW() WHERE id=? AND (status='unknown' OR (status='accepted' AND created_at < DATE_SUB(NOW(),INTERVAL 15 MINUTE)))")->execute([$batchId]);
        $pdo->prepare("UPDATE isp_payout_queue SET status='pending',batch_id=NULL,last_error=? WHERE tenant_id=? AND batch_id=? AND status='processing'")->execute(['Reconciled not paid: '.substr($evidence,0,180),(int)$batch['tenant_id'],$batchId]);
    }
    $pdo->prepare('INSERT INTO isp_payout_reconciliations(batch_id,admin_user_id,decision,receipt,evidence) VALUES(?,?,?,?,?)')
        ->execute([$batchId,(int)$_SESSION['user_id'],$decision,$receipt!==''?$receipt:null,$evidence]);
    $pdo->commit();
    $ok($decision==='paid'?'Payout marked paid and its collections settled.':'Payout confirmed not paid; collections returned to the payout queue.');
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    if(http_response_code()<400)http_response_code(409);
    error_log('Payout review: '.$e->getMessage());
    echo json_encode(['success'=>false,'message'=>$e instanceof PDOException?'Could not save payout review.':$e->getMessage()]);
}
