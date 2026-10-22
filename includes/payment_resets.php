<?php
/** Keep receipt identities after an authorized reset so callbacks cannot restore them. */
function paymentWasReset(PDO $pdo,int $tenantId,string ...$references): bool {
    $references=array_values(array_unique(array_filter($references,fn($v)=>$v!=='')));
    if(!$references)return false;
    try {
        $st=$pdo->prepare('SELECT 1 FROM payment_reset_receipts WHERE tenant_id=? AND reference IN ('.implode(',',array_fill(0,count($references),'?')).') LIMIT 1');
        $st->execute(array_merge([$tenantId],$references));
        return (bool)$st->fetchColumn();
    } catch(PDOException $e) {
        if($e->getCode()==='42S02')return false;
        throw $e;
    }
}
