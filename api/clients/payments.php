<?php
/**
 * GET /api/clients/payments.php?client_id=X
 * Returns all payments for a single client (combined payments + mpesa_transactions)
 */
header('Content-Type: application/json');
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$_SESSION['user_id']]);
$tenant_id = $st->fetchColumn();
if (!$tenant_id) { echo json_encode(['success'=>false,'message'=>'No tenant']); exit; }

$client_id = (int)($_GET['client_id'] ?? 0);
if (!$client_id) { echo json_encode(['success'=>false,'message'=>'client_id required']); exit; }

// Confirm client belongs to this tenant
$chk = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND tenant_id = ?");
$chk->execute([$client_id, $tenant_id]);
if (!$chk->fetchColumn()) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

try {
    // Detect whether the payments table has a notes column (may not exist on older schemas)
    $hasNotes = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM payments LIKE 'notes'");
        $hasNotes = (bool)$colCheck->fetch();
    } catch (Throwable $_e) {}

    $notesCol = $hasNotes ? 'p.notes' : "'' AS notes";

    // Fetch from payments table (includes manual records)
    $st = $pdo->prepare("
        SELECT
            p.id,
            p.amount,
            p.payment_method  AS method,
            p.transaction_id  AS reference,
            p.status,
            p.payment_date    AS paid_at,
            {$notesCol},
            c.phone           AS phone
        FROM payments p
        LEFT JOIN clients c ON c.id = p.client_id
        WHERE p.client_id = ? AND p.tenant_id = ?
        ORDER BY p.payment_date DESC
        LIMIT 100
    ");
    $st->execute([$client_id, $tenant_id]);
    $payments = $st->fetchAll(PDO::FETCH_ASSOC);

    // Merge in M-Pesa transaction details (phone, confirmation code from callback)
    $mtSt = $pdo->prepare("
        SELECT phone_number, checkout_request_id, transaction_id, result_code, amount, created_at
        FROM mpesa_transactions
        WHERE client_id = ? AND (tenant_id = ? OR tenant_id IS NULL)
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $mtSt->execute([$client_id, $tenant_id]);
    $mpesaTx = $mtSt->fetchAll(PDO::FETCH_ASSOC);

    // Index mpesa rows by checkout_request_id for quick lookup
    $mpesaMap = [];
    foreach ($mpesaTx as $tx) {
        if ($tx['checkout_request_id']) {
            $mpesaMap[$tx['checkout_request_id']] = $tx;
        }
    }

    // Enrich payments with mpesa confirmation data
    foreach ($payments as &$p) {
        $ref = $p['reference'] ?? '';
        if ($ref && isset($mpesaMap[$ref])) {
            $mt = $mpesaMap[$ref];
            if (empty($p['phone'])) $p['phone'] = $mt['phone_number'];
            $p['mpesa_code']  = $mt['transaction_id'] ?? '';
            $p['result_code'] = $mt['result_code'] ?? '';
        } else {
            $p['mpesa_code']  = '';
            $p['result_code'] = '';
        }
        // Only confirmed if result_code is explicitly '0' (M-Pesa success) or integer 0
        // Manual unverified entries have result_code='MANUAL', pending have null
        $rc = (string)($p['result_code'] ?? '');
        $p['confirmed'] = ($rc === '0');
    }
    unset($p);

    echo json_encode(['success'=>true, 'payments'=>$payments]);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
