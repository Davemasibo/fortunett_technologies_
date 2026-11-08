<?php
/** Collection principal remains owed to the tenant; only unbilled commission carries. */
function ensureInvoiceCarryoverSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_invoice_carryovers (
        source_invoice_id INT NOT NULL, target_invoice_id INT NOT NULL,
        collections_amount DECIMAL(15,2) NOT NULL, commission_rate DECIMAL(5,4) NOT NULL,
        amount DECIMAL(15,2) NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(source_invoice_id,target_invoice_id), KEY target_invoice(target_invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Caller holds the tenant lock. Rebuild only the current editable invoice's share. */
function platformCollectionCarryovers(PDO $pdo,int $tenantId,string $period,int $targetId=0): array {
    $st=$pdo->prepare("SELECT * FROM platform_invoices WHERE tenant_id=? AND billing_period<?
        AND (status='paid' OR (status IN ('pending','overdue') AND (amount_paid>0 OR manual_override=1)))
        ORDER BY billing_period,id FOR UPDATE");
    $st->execute([$tenantId,$period]);$sources=$st->fetchAll(PDO::FETCH_ASSOC);$items=[];
    $collections=$pdo->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p
        JOIN clients c ON c.id=p.client_id AND c.tenant_id=p.tenant_id
        WHERE p.tenant_id=? AND p.status='completed' AND p.amount>0 AND c.connection_type='hotspot'
        AND p.payment_date>=? AND p.payment_date<?");
    $carried=$pdo->prepare("SELECT COALESCE(SUM(c.collections_amount),0) AS collections,COALESCE(SUM(c.amount),0) AS amount
        FROM platform_invoice_carryovers c JOIN platform_invoices target ON target.id=c.target_invoice_id
        WHERE c.source_invoice_id=? AND target.tenant_id=? AND target.status<>'cancelled' AND c.target_invoice_id<>?");
    foreach($sources as $source) {
        $collections->execute([$tenantId,$source['billing_period'],date('Y-m-01',strtotime($source['billing_period'].' +1 month'))]);
        $actual=(int)round((float)$collections->fetchColumn()*100);
        $carried->execute([$source['id'],$tenantId,$targetId]);$previous=$carried->fetch(PDO::FETCH_ASSOC);
        $basis=max(0,$actual-(int)round((float)$source['hotspot_collections']*100)-(int)round((float)$previous['collections']*100));
        // Round the whole month's commission once; subtract amounts already invoiced.
        $amount=max(0,(int)round($actual*(float)$source['hotspot_commission_rate'])-(int)round((float)$source['hotspot_commission']*100)-(int)round((float)$previous['amount']*100));
        if($basis>0 && $amount>0) $items[]=['source_invoice_id'=>(int)$source['id'],'collections_amount'=>$basis/100,'commission_rate'=>$source['hotspot_commission_rate'],'amount'=>$amount/100];
    }
    return $items;
}

function storePlatformCarryovers(PDO $pdo,int $targetId,array $items): void {
    $pdo->prepare('DELETE FROM platform_invoice_carryovers WHERE target_invoice_id=?')->execute([$targetId]);
    $insert=$pdo->prepare('INSERT INTO platform_invoice_carryovers(source_invoice_id,target_invoice_id,collections_amount,commission_rate,amount) VALUES(?,?,?,?,?)');
    foreach($items as $item)$insert->execute([$item['source_invoice_id'],$targetId,$item['collections_amount'],$item['commission_rate'],$item['amount']]);
    $total=round(array_sum(array_column($items,'amount')),2);
    $pdo->prepare("UPDATE platform_invoices SET carryover_amount=?,status=CASE WHEN ? > 0 AND status='paid' AND amount_paid=0 THEN 'pending' ELSE status END WHERE id=?")
        ->execute([$total,$total,$targetId]);
}
