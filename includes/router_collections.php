<?php
/** Immutable checkout attribution; payment rows are grouped before summing. */
function routerCollectionRows(PDO $pdo, int $tenant, array $range): array {
    $bucket=analyticsBucketExpr($range,'p.payment_date');
    $st=$pdo->prepare("SELECT assigned.router_id, assigned.bucket, COUNT(*) AS sales, SUM(assigned.amount) AS revenue FROM (
        SELECT p.id,p.amount,$bucket AS bucket,
          IF(COUNT(DISTINCT l.router_id)=1,MIN(l.router_id),NULL) AS router_id
        FROM payments p
        LEFT JOIN mpesa_transactions mt ON mt.tenant_id=p.tenant_id AND mt.client_id=p.client_id
          AND (mt.checkout_request_id=p.transaction_id OR mt.mpesa_receipt_number=p.transaction_id)
        LEFT JOIN hotspot_purchase_locations l ON l.tenant_id=p.tenant_id AND l.client_id=p.client_id
          AND l.checkout_id=COALESCE(mt.checkout_request_id,p.transaction_id)
        WHERE p.tenant_id=? AND p.status='completed' AND p.payment_date>=? AND p.payment_date<?
        GROUP BY p.id,p.amount,$bucket
    ) assigned GROUP BY assigned.router_id,assigned.bucket");
    $st->execute([$tenant,$range['start'],$range['end_exclusive']]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
