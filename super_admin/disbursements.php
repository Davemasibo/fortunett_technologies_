<?php
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
superAdminGuard();
require_once __DIR__ . '/../includes/disbursements.php';
$_SESSION['disbursement_csrf'] ??= bin2hex(random_bytes(32));
$tenantId = (int)($_GET['tenant_id'] ?? 0);
$st = $pdo->prepare('SELECT company_name FROM tenants WHERE id = ?');
$st->execute([$tenantId]);
$name = $st->fetchColumn();
if (!$name) { http_response_code(404); exit('Tenant not found.'); }
$today = (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d');
$nowLocal = (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('Y-m-d\TH:i');
$form = array_merge(['cutoff_date'=>$nowLocal, 'disbursed_at'=>$nowLocal, 'cash_amount'=>'',
    'fees_amount'=>'0', 'platform_cost'=>'0', 'reference'=>'', 'notes'=>''], $_POST);
$error = ''; $success = ''; $schemaReady = false; $rows = []; $history = []; $gross = 0;
$cutoff = disbursementDate($nowLocal, 'Collections through');
try {
    ensureDisbursementSchema($pdo);
    $schemaReady = true;
    $cutoff = disbursementDate((string)$form['cutoff_date'], 'Collections through');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['disbursement_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Your session expired. Reload the page and try again.');
        }
        if (($_POST['action'] ?? '') === 'record') {
            if (empty($_POST['confirmed'])) throw new InvalidArgumentException('Confirm that the tenant has already received this money.');
            $snapshot = disbursementDate((string)($_POST['preview_cutoff'] ?? ''), 'Preview date');
            if ($snapshot !== $cutoff) {
                throw new InvalidArgumentException('Update the collections preview for your selected date and time before saving.');
            }
            $input = $_POST;
            $input['cutoff'] = $snapshot;
            $id = recordDisbursement($pdo, $tenantId, (int)$_SESSION['user_id'], $input);
            $_SESSION['disbursement_success'] = ['tenant_id'=>$tenantId, 'id'=>$id];
            header('Location: disbursements.php?tenant_id=' . $tenantId);
            exit;
        }
    }
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('Manual disbursement: ' . $e->getMessage());
    $error = 'Unable to save the disbursement. Your transfer has not been recorded. Please retry or contact support.';
}
if ($schemaReady) {
    try {
        $rows = disbursementPayments($pdo, $tenantId, $cutoff);
        $gross = array_sum(array_map(fn($r) => disbursementMoney((string)$r['amount']), $rows)) / 100;
        $st = $pdo->prepare('SELECT * FROM tenant_disbursements WHERE tenant_id = ? ORDER BY disbursed_at DESC, id DESC LIMIT 100');
        $st->execute([$tenantId]);
        $history = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Disbursement preview: ' . $e->getMessage());
        $error = 'Collections could not be loaded. Please retry before recording a transfer.';
        $schemaReady = false;
    }
}
if (($_SESSION['disbursement_success']['tenant_id'] ?? 0) === $tenantId) {
    $success = 'Disbursement #' . (int)$_SESSION['disbursement_success']['id'] . ' recorded. The tenant can now see the transfer and their updated balance.';
    unset($_SESSION['disbursement_success']);
}
function dh($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
require __DIR__ . '/includes/disbursements_view.php';
