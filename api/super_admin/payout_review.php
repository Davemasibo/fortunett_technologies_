<?php
require_once __DIR__.'/../../includes/db_master.php';
require_once __DIR__.'/../../includes/payouts.php';
require_once __DIR__.'/../../includes/tenant_payouts.php';
require_once __DIR__.'/../../super_admin/includes/auth.php';
superAdminGuard();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (session_status() === PHP_SESSION_NONE) session_start();

$respond = static function (bool $success, string $message, int $status = 200) use ($pdo) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    ensurePayoutTables($pdo);
    ensurePayoutReconciliationSchema($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $respond(false, 'POST required.', 405);
    }
    $csrf = (string)($_SESSION['payout_review_csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $respond(false, 'Your session expired. Reload this page and try again.', 403);
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'verify_bank') {
        $tenantId = filter_var($_POST['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$tenantId) $respond(false, 'Choose a valid tenant bank destination.', 422);
        $bank = tenantBankPayoutDestination($pdo, (int)$tenantId);
        if (empty($bank['valid'])) $respond(false, 'The tenant bank gateway is incomplete.', 422);

        $pdo->beginTransaction();
        $pdo->prepare('INSERT IGNORE INTO tenant_payout_settings(tenant_id) VALUES(?)')->execute([(int)$tenantId]);
        $pdo->prepare('UPDATE tenant_payout_settings SET bank_verified_at=NOW(),verified_by=? WHERE tenant_id=?')
            ->execute([(int)($_SESSION['user_id'] ?? 0), (int)$tenantId]);
        $pdo->commit();
        $respond(true, 'Bank destination verified.');
    }

    if ($action !== 'resolve_unknown') $respond(false, 'Unknown review action.', 422);
    $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $decision = (string)($_POST['decision'] ?? '');
    $evidence = trim((string)($_POST['evidence'] ?? ''));
    $receipt = trim((string)($_POST['receipt'] ?? ''));
    if (!$batchId) $respond(false, 'Choose a valid payout transfer.', 422);
    if (!in_array($decision, ['paid', 'failed'], true)) $respond(false, 'Choose paid or not paid.', 422);
    if (strlen($evidence) < 8 || strlen($evidence) > 1000) {
        $respond(false, 'Enter a reconciliation note referencing the M-Pesa statement or Safaricom confirmation.', 422);
    }
    if ($decision === 'paid' && $receipt === '') $respond(false, 'Enter the M-Pesa receipt before marking this payout paid.', 422);
    if (strlen($receipt) > 64) $respond(false, 'The M-Pesa receipt cannot exceed 64 characters.', 422);

    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT * FROM isp_payout_batches WHERE id=? AND (status='unknown' OR (status IN ('sending','accepted') AND created_at < DATE_SUB(NOW(),INTERVAL 15 MINUTE))) FOR UPDATE");
    $st->execute([(int)$batchId]);
    $batch = $st->fetch(PDO::FETCH_ASSOC);
    if (!$batch) $respond(false, 'This payout is no longer awaiting reconciliation. Reload and check its current status.', 409);

    if ($decision === 'paid') {
        $duplicate = $pdo->prepare("SELECT id FROM isp_payout_batches WHERE transaction_id=? AND id<>? AND status='paid' LIMIT 1");
        $duplicate->execute([$receipt, (int)$batchId]);
        if ($duplicate->fetchColumn()) $respond(false, 'That M-Pesa receipt is already recorded against another paid transfer.', 409);

        $update = $pdo->prepare("UPDATE isp_payout_batches SET status='paid',transaction_id=?,result_desc='Manually reconciled as paid',completed_at=NOW() WHERE id=? AND (status='unknown' OR (status IN ('sending','accepted') AND created_at < DATE_SUB(NOW(),INTERVAL 15 MINUTE)))");
        $update->execute([$receipt, (int)$batchId]);
        if ($update->rowCount() !== 1) $respond(false, 'This payout changed while it was being reconciled. Reload and check its status.', 409);
        if (settlePayoutBatch($pdo, $batch, 'M-Pesa payout reconciled as paid · '.$receipt) < 1) {
            $respond(false, 'The payout queue could not be settled. No reconciliation was saved.', 409);
        }
    } else {
        $update = $pdo->prepare("UPDATE isp_payout_batches SET status='failed',result_desc='Manually reconciled as not paid',completed_at=NOW() WHERE id=? AND (status='unknown' OR (status IN ('sending','accepted') AND created_at < DATE_SUB(NOW(),INTERVAL 15 MINUTE)))");
        $update->execute([(int)$batchId]);
        if ($update->rowCount() !== 1) $respond(false, 'This payout changed while it was being reconciled. Reload and check its status.', 409);
        $release = $pdo->prepare("UPDATE isp_payout_queue SET status='pending',batch_id=NULL,last_error=? WHERE tenant_id=? AND batch_id=? AND status='processing'");
        $release->execute(['Reconciled not paid: '.substr($evidence, 0, 180), (int)$batch['tenant_id'], (int)$batchId]);
        if ($release->rowCount() < 1) $respond(false, 'No payout queue entries could be released. No reconciliation was saved.', 409);
    }

    $pdo->prepare('INSERT INTO isp_payout_reconciliations(batch_id,admin_user_id,decision,receipt,evidence) VALUES(?,?,?,?,?)')
        ->execute([(int)$batchId, (int)$_SESSION['user_id'], $decision, $receipt !== '' ? $receipt : null, $evidence]);
    $pdo->commit();
    $respond(true, $decision === 'paid'
        ? 'Payout marked paid and its collections settled.'
        : 'Payout confirmed not paid; collections returned to the payout queue.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Payout review: '.$e->getMessage());
    $respond(false, $e instanceof PDOException ? 'Could not save the payout review. Try again and check the payout status.' : $e->getMessage(), 500);
}
