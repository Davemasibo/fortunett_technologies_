<?php
/**
 * Is this reference code free for this tenant?
 *
 * Called as the operator types, so the duplicate is caught before they finish
 * filling the form rather than as a rejection after they press Record. The
 * write paths enforce the same rule regardless — this endpoint is a courtesy,
 * never the guard.
 */
require_once __DIR__ . '/_auth.php';

$ref     = strtoupper(trim($_REQUEST['reference'] ?? $_REQUEST['reference_code'] ?? ''));
$exclude = isset($_REQUEST['payment_id']) ? (int)$_REQUEST['payment_id'] : null;

if ($ref === '') {
    echo json_encode(['success' => true, 'available' => true, 'message' => '']);
    exit;
}

$conflict = paymentReferenceConflict($pdo, $tenant_id, $ref, $exclude ?: null);

echo json_encode([
    'success'   => true,
    'available' => !$conflict,
    'reference' => $ref,
    'message'   => $conflict ? 'Reference ' . $ref . ' is ' . paymentConflictSummary($conflict) : '',
]);
