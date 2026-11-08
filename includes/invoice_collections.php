<?php
require_once __DIR__.'/disbursements.php';

/** All issued unpaid invoices, including those not overdue yet, take priority. */
function collectionInvoiceBalances(PDO $pdo, int $tenantId, bool $lock=false): array {
    $st=$pdo->prepare("SELECT * FROM platform_invoices WHERE tenant_id=? AND status IN ('pending','overdue')
        AND total_due > COALESCE(amount_paid,0) ORDER BY billing_period,id".($lock?' FOR UPDATE':''));
    $st->execute([$tenantId]);return $st->fetchAll(PDO::FETCH_ASSOC);
}

function collectionInvoiceOutstanding(array $rows): int {
    return array_sum(array_map(fn($r)=>max(0,disbursementMoney((string)$r['total_due'])-disbursementMoney((string)($r['amount_paid']??'0'))),$rows));
}

function collectionInvoiceRevision(array $rows): string { return hash('sha256',json_encode($rows)); }

function assertCollectionInvoicesSettled(PDO $pdo,int $tenantId,bool $lock=false): void {
    $due=collectionInvoiceOutstanding(collectionInvoiceBalances($pdo,$tenantId,$lock));
    if($due>0) throw new InvalidArgumentException('Settle outstanding invoices of KES '.number_format($due/100,2).' from collections before making a payout.');
}

/** One review and one atomic commit: insufficient funds never leave half a batch settled. */
function settleCollectionInvoices(PDO $pdo,int $tenantId,int $actor,array $input): int {
    $cutoff=disbursementDate((string)($input['cutoff']??''),'Collections through');
    $paidAt=(new DateTimeImmutable('now',new DateTimeZone('Africa/Nairobi')))->format('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT id FROM tenants WHERE id=? FOR UPDATE');$st->execute([$tenantId]);
        if(!$st->fetchColumn())throw new InvalidArgumentException('Tenant not found.');
        $invoices=collectionInvoiceBalances($pdo,$tenantId,true);
        if(!$invoices || !hash_equals(collectionInvoiceRevision($invoices),(string)($input['invoice_revision']??''))) throw new InvalidArgumentException('Invoices changed. Reload and review the deductions.');
        $total=collectionInvoiceOutstanding($invoices);
        // Lock queues before receipts, matching the payout and invoice-offset paths.
        $st=$pdo->prepare('SELECT id FROM isp_payout_queue WHERE tenant_id=? FOR UPDATE');$st->execute([$tenantId]);$st->fetchAll();
        $rows=disbursementPayments($pdo,$tenantId,$cutoff,true);
        $available=array_sum(array_map(fn($r)=>disbursementMoney((string)$r['amount']),$rows));
        if($available!==disbursementMoney((string)($input['expected_gross']??'')) || count($rows)!==(int)($input['expected_count']??0)) throw new InvalidArgumentException('Collections changed. Reload before deducting invoices.');
        if($total>$available)throw new InvalidArgumentException('Collections are insufficient to settle all outstanding invoices. Payouts remain blocked.');
        foreach($invoices as $invoice) {
            $rows=disbursementPayments($pdo,$tenantId,$cutoff,true);
            $available=array_sum(array_map(fn($r)=>disbursementMoney((string)$r['amount']),$rows));
            $due=disbursementMoney((string)$invoice['total_due'])-disbursementMoney((string)($invoice['amount_paid']??'0'));
            recordDisbursement($pdo,$tenantId,$actor,[
                'cutoff'=>$cutoff,'disbursed_at'=>$paidAt,'cash_amount'=>'0','fees_amount'=>number_format($due/100,2,'.',''),
                'platform_cost'=>'0','notes'=>'Invoice settled before payout','expected_gross'=>number_format($available/100,2,'.',''),
                'expected_count'=>count($rows),
            ],(int)$invoice['id']);
        }
        $pdo->commit();return count($invoices);
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
