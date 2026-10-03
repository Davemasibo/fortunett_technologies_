<?php
/** Trial fees start with collections; the fixed fee is waived throughout trial. */
function platformTrialCharges(string $status, float $collections, int $activeUsers, int $payingUsers, float $baseFee): array
{
    if ($status !== 'trial') return ['eligible' => true, 'users' => $activeUsers, 'base_fee' => $baseFee];
    return ['eligible' => $collections > 0, 'users' => $payingUsers, 'base_fee' => 0.0];
}

/** Correct unpaid trial fees without changing settled or partly paid invoices. */
function repairUnpaidTrialInvoices(PDO $pdo, int $tenantId): void
{
    $st = $pdo->prepare("UPDATE platform_invoices i JOIN tenants t ON t.id=i.tenant_id
        SET i.base_fee=0, i.pppoe_user_count=(SELECT COUNT(DISTINCT c.id)
            FROM payments pay JOIN clients c ON c.id=pay.client_id AND c.tenant_id=pay.tenant_id
            WHERE pay.tenant_id=i.tenant_id AND pay.status='completed' AND pay.amount>0
              AND c.connection_type='pppoe' AND pay.payment_date>=i.billing_period
              AND pay.payment_date<DATE_ADD(i.billing_period, INTERVAL 1 MONTH))
        WHERE t.id=? AND t.status='trial' AND i.status IN ('pending','overdue') AND COALESCE(i.amount_paid,0)=0");
    $st->execute([$tenantId]);
    $st = $pdo->prepare("UPDATE platform_invoices i JOIN tenants t ON t.id=i.tenant_id
        SET i.status='paid'
        WHERE t.id=? AND t.status='trial' AND i.status IN ('pending','overdue')
          AND COALESCE(i.amount_paid,0)=0 AND i.total_due=0");
    $st->execute([$tenantId]);
}
