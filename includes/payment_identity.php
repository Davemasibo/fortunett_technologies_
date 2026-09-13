<?php
/** Resolve a confirmed payment using fresh gateway data, never a stale poll snapshot. */
function paymentIsManualTransaction(array $tx): bool {
    return str_starts_with(strtoupper((string)($tx['merchant_request_id'] ?? '')), 'MANUAL-')
        || str_starts_with(strtolower((string)($tx['result_desc'] ?? '')), 'manual:');
}

function resolvePaymentIdentity(PDO $pdo, int $tenant, int $client, string $receipt, string $key): array {
    $s=$pdo->prepare('SELECT * FROM mpesa_transactions WHERE tenant_id=? AND client_id=? AND (checkout_request_id IN (?,?) OR mpesa_receipt_number IN (?,?)) ORDER BY id DESC LIMIT 2');
    $s->execute([$tenant,$client,$key,$receipt,$key,$receipt]);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    $manual=(bool)array_filter($rows,'paymentIsManualTransaction');
    $rows=array_values(array_filter($rows,fn($r)=>!paymentIsManualTransaction($r)));
    if(count($rows)>1 && $rows[0]['checkout_request_id']!==$rows[1]['checkout_request_id']) throw new RuntimeException('Ambiguous payment identity; review required');
    $tx=$rows[0]??null;
    if(!$tx) return ['receipt'=>$receipt,'checkout'=>null,'key'=>$key,'manual'=>$manual];
    $checkout=(string)$tx['checkout_request_id'];
    $confirmedReceipt=trim((string)($tx['mpesa_receipt_number']??''));
    // A real receipt supplied by a callback outranks a checkout placeholder.
    if($confirmedReceipt!=='' && $confirmedReceipt!==$checkout) {
        if($receipt!==$checkout && $receipt!==$confirmedReceipt) throw new RuntimeException('Conflicting payment receipts');
        $receipt=$confirmedReceipt;
    }
    return ['receipt'=>$receipt,'checkout'=>$checkout,'key'=>$checkout];
}
