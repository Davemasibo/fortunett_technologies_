<?php
require_once __DIR__.'/disbursements.php';

function ensureFinancialCorrectionSchema(PDO $pdo): void {
    if (!ensureColumn($pdo, 'platform_invoices', 'manual_override', 'TINYINT NOT NULL DEFAULT 0')) throw new RuntimeException('Could not prepare invoice corrections.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS financial_corrections (
        id INT AUTO_INCREMENT PRIMARY KEY, record_type VARCHAR(20) NOT NULL,
        record_id INT NOT NULL, actor_id INT NOT NULL, reason VARCHAR(255) NOT NULL,
        before_values LONGTEXT NOT NULL, after_values LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY record_history(record_type,record_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function financialRevision(array $row): string { return hash('sha256', json_encode($row)); }

function auditFinancialCorrection(PDO $pdo, string $type, array $before, array $after, int $actor, string $reason): void {
    $pdo->prepare('INSERT INTO financial_corrections(record_type,record_id,actor_id,reason,before_values,after_values) VALUES(?,?,?,?,?,?)')
        ->execute([$type,$before['id'],$actor,$reason,json_encode($before),json_encode($after)]);
}

/** Called inside a transaction after locking the tenant. Keep other payouts intact. */
function replaceDisbursementAmounts(PDO $pdo, array $before, int $cash, int $fees, int $cost, string $notes): void {
    $tenant=(int)$before['tenant_id']; $id=(int)$before['id'];
    $queue=$pdo->prepare('SELECT * FROM isp_payout_queue WHERE tenant_id=? FOR UPDATE');
    $queue->execute([$tenant]); $queued=$queue->fetchAll(PDO::FETCH_ASSOC);
    $st=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? ORDER BY payment_date,id FOR UPDATE');
    $st->execute([$tenant]); $payments=$st->fetchAll(PDO::FETCH_ASSOC);
    $st=$pdo->prepare('SELECT payment_id,amount FROM tenant_disbursement_items WHERE disbursement_id=? FOR UPDATE');
    $st->execute([$id]); $old=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $item) $old[(int)$item['payment_id']]=disbursementMoney((string)$item['amount']);
    if(array_sum($old)!==disbursementMoney((string)$before['gross_amount'])) throw new InvalidArgumentException('Existing allocations do not match this payout. Reconcile the ledger first.');
    $remaining=$cash+$fees; $allocations=[];
    foreach($payments as $p) {
        $pid=(int)$p['id']; $previous=$old[$pid]??0;
        $eligible=$p['collection_type']==='platform' && $p['status']==='completed'
            && ($previous>0 || ($p['released_at']===null && $p['payment_date']<=date('Y-m-d H:i:s') && $p['created_at']<=date('Y-m-d H:i:s')));
        $available=$eligible ? disbursementMoney((string)$p['amount'])-disbursementMoney((string)$p['disbursed_amount'])+$previous : 0;
        $next=min(max(0,$available),$remaining); $remaining-=$next;
        if($previous || $next) $allocations[$pid]=['payment'=>$p,'old'=>$previous,'next'=>$next];
    }
    if($remaining>0) throw new InvalidArgumentException('Insufficient tenant collections for cash plus transaction cost. No changes saved.');
    foreach($queued as $q) {
        $a=$allocations[(int)$q['payment_id']]??null;
        if(!$a || $a['old']===$a['next']) continue;
        $ownPaid=$q['status']==='paid' && str_starts_with((string)$q['notes'],'Manual disbursement #');
        if(!in_array($q['status'],['pending','cancelled'],true) && !$ownPaid) throw new InvalidArgumentException('An affected collection has an automatic payout in progress or paid. Reconcile it first.');
    }
    $pdo->prepare('DELETE FROM tenant_disbursement_items WHERE disbursement_id=?')->execute([$id]);
    foreach($allocations as $pid=>$a) {
        $p=$a['payment']; $total=disbursementMoney((string)$p['disbursed_amount'])-$a['old']+$a['next'];
        $full=$total===disbursementMoney((string)$p['amount']);
        $note='Manual disbursement #'.$id.': '.$before['reference'].' (corrected)';
        if($a['next']>0) $pdo->prepare('INSERT INTO tenant_disbursement_items VALUES(?,?,?)')->execute([$id,$pid,$a['next']/100]);
        if($a['old']===$a['next']) continue;
        $pdo->prepare('UPDATE payments SET disbursed_amount=?,released_at=?,release_note=? WHERE id=? AND tenant_id=?')
            ->execute([$total/100,$full?($p['released_at']?:$before['disbursed_at']):null,$note,$pid,$tenant]);
        $pdo->prepare('UPDATE isp_payout_queue SET status=?,processed_at=?,notes=? WHERE payment_id=? AND tenant_id=?')
            ->execute([$full?'paid':'cancelled',$full?$before['disbursed_at']:null,$note.($full?'':' - remaining balance requires manual disbursement'),$pid,$tenant]);
    }
    $pdo->prepare('UPDATE tenant_disbursements SET cash_amount=?,fees_amount=?,gross_amount=?,platform_cost=?,notes=? WHERE id=? AND tenant_id=?')
        ->execute([$cash/100,$fees/100,($cash+$fees)/100,$cost/100,$notes,$id,$tenant]);
}

function correctFinancialRecord(PDO $pdo, string $type, int $id, int $actor, array $input): void {
    if(!in_array($type,['invoice','payout'],true) || $actor<=0) throw new InvalidArgumentException('Invalid correction.');
    $reason=trim($input['reason']??'');
    if($reason==='' || strlen($reason)>255) throw new InvalidArgumentException('Enter a correction reason (up to 255 characters).');
    $table=$type==='invoice'?'platform_invoices':'tenant_disbursements';
    $st=$pdo->prepare("SELECT tenant_id FROM $table WHERE id=?"); $st->execute([$id]); $tenant=(int)$st->fetchColumn();
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT id FROM tenants WHERE id=? FOR UPDATE');$st->execute([$tenant]);
        if(!$st->fetchColumn()) throw new InvalidArgumentException('Tenant not found.');
        $st=$pdo->prepare("SELECT * FROM $table WHERE id=? AND tenant_id=? FOR UPDATE");$st->execute([$id,$tenant]);$before=$st->fetch(PDO::FETCH_ASSOC);
        if(!$before || !hash_equals(financialRevision($before),(string)($input['revision']??''))) throw new InvalidArgumentException('This record changed. Reload before correcting it.');
        $notes=trim($input['notes']??'');
        if($type==='payout') {
            if(strlen($notes)>255) throw new InvalidArgumentException('Notes must be at most 255 characters.');
            // A reference alone does not identify an invoice offset: older manual payouts used the same prefix.
            $linked=$pdo->prepare("SELECT id FROM platform_invoices WHERE tenant_id=? AND payment_method='collections_offset' AND transaction_ref=?");
            $linked->execute([$tenant,$before['reference']]);
            if($linked->fetchColumn()) throw new InvalidArgumentException('Edit the linked invoice to change this collections deduction.');
            $cash=disbursementMoney((string)($input['cash_amount']??''));
            if($cash<=0) throw new InvalidArgumentException('Cash received must be greater than zero.');
            replaceDisbursementAmounts($pdo,$before,$cash,disbursementMoney((string)($input['fees_amount']??'0')),disbursementMoney((string)($input['platform_cost']??'0')),$notes);
        } else {
            $count=filter_var($input['pppoe_user_count']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1000000]]);
            if($count===false) throw new InvalidArgumentException('Enter a valid PPPoE user count.');
            $amounts=[];
            foreach(['pppoe_fee_per_user','hotspot_collections','base_fee','sms_amount'] as $field) $amounts[$field]=disbursementMoney((string)($input[$field]??''));
            $rate=trim((string)($input['hotspot_commission_rate']??''));
            if(!preg_match('/^(?:0(?:\.\d{1,4})?|1(?:\.0{1,4})?)$/D',$rate)) throw new InvalidArgumentException('Commission rate must be between 0 and 1, up to four decimal places.');
            $total=$count*$amounts['pppoe_fee_per_user']+(int)round($amounts['hotspot_collections']*(float)$rate)+$amounts['base_fee']+$amounts['sms_amount']+(int)round((float)($before['carryover_amount']??0)*100);
            if($total>99999999999) throw new InvalidArgumentException('Invoice total is too large.');
            if((float)($before['amount_paid']??0)>0 && $before['status']!=='paid' && $total!==disbursementMoney((string)$before['total_due'])) throw new InvalidArgumentException('This invoice is partly paid. A payment adjustment is required to change its total.');
            $due=trim($input['due_date']??''); $date=DateTimeImmutable::createFromFormat('!Y-m-d',$due);
            if(!$date || $date->format('Y-m-d')!==$due) throw new InvalidArgumentException('Choose a valid due date.');
            if($before['status']==='paid' && $before['payment_method']!=='collections_offset' && $total!==disbursementMoney((string)$before['total_due'])) throw new InvalidArgumentException('This invoice was paid separately. Its paid amount cannot be overwritten; a payment adjustment is required.');
            if($before['payment_method']==='collections_offset') {
                $st=$pdo->prepare('SELECT * FROM tenant_disbursements WHERE tenant_id=? AND reference=? FOR UPDATE');$st->execute([$tenant,$before['transaction_ref']]);$payout=$st->fetch(PDO::FETCH_ASSOC);
                if(!$payout || disbursementMoney((string)$payout['cash_amount'])!==0 || disbursementMoney((string)$payout['fees_amount'])>disbursementMoney((string)$before['total_due'])) throw new InvalidArgumentException('Linked invoice deduction needs reconciliation.');
                $separatelyPaid=disbursementMoney((string)$before['total_due'])-disbursementMoney((string)$payout['fees_amount']);
                if($total<$separatelyPaid) throw new InvalidArgumentException('Corrected total cannot be below the separately paid amount.');
                replaceDisbursementAmounts($pdo,$payout,0,$total-$separatelyPaid,0,$payout['notes']);
                $st=$pdo->prepare('SELECT * FROM tenant_disbursements WHERE id=?');$st->execute([$payout['id']]);
                auditFinancialCorrection($pdo,'payout',$payout,$st->fetch(PDO::FETCH_ASSOC),$actor,$reason);
            }
            $status=$before['status'];
            if(in_array($status,['pending','overdue'],true)) $status=$due<date('Y-m-d')?'overdue':'pending';
            $pdo->prepare('UPDATE platform_invoices SET pppoe_user_count=?,pppoe_fee_per_user=?,hotspot_collections=?,hotspot_commission_rate=?,base_fee=?,sms_amount=?,due_date=?,notes=?,status=?,manual_override=1,router_count=NULL,router_fee_per_router=NULL WHERE id=? AND tenant_id=?')
                ->execute([$count,$amounts['pppoe_fee_per_user']/100,$amounts['hotspot_collections']/100,$rate,$amounts['base_fee']/100,$amounts['sms_amount']/100,$due,$notes,$status,$id,$tenant]);
            if($before['payment_method']==='collections_offset') $pdo->prepare('UPDATE platform_invoices SET amount_paid=total_due WHERE id=?')->execute([$id]);
        }
        $st=$pdo->prepare("SELECT * FROM $table WHERE id=?");$st->execute([$id]);
        auditFinancialCorrection($pdo,$type,$before,$st->fetch(PDO::FETCH_ASSOC),$actor,$reason);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}
