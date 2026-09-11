<?php
require_once __DIR__ . '/validity.php';

/** Rebuild chronological purchases; never use the potentially inflated current expiry. */
function hotspotPurchasedDeadline(array $events): array {
    if (!$events) return ['repairable'=>false,'reason'=>'No confirmed purchase history; review required'];
    $seen=[]; $ordered=[];
    foreach ($events as $event) {
        $identity=(string)($event['identity'] ?? '');
        if (!$identity || isset($seen[$identity])) return ['repairable'=>false,'reason'=>'Missing or duplicate purchase identity'];
        $seen[$identity]=true;
        if (empty($event['confirmed_at']) || empty($event['validity_value']) || empty($event['validity_unit'])) return ['repairable'=>false,'reason'=>'Original purchase duration or activation time is missing; current package settings cannot reconstruct it'];
        $time=strtotime($event['confirmed_at']);
        if ($time===false) return ['repairable'=>false,'reason'=>'Invalid historical activation timestamp'];
        $event['time']=$time; $ordered[]=$event;
    }
    usort($ordered,fn($a,$b)=>$a['time']<=>$b['time']);
    $deadline=0;
    try {
        foreach ($ordered as $event) $deadline=strtotime(packageExpiryFrom($event['validity_value'],$event['validity_unit'],max($deadline,$event['time'])));
    } catch (Throwable $e) { return ['repairable'=>false,'reason'=>$e->getMessage()]; }
    return ['repairable'=>true,'expiry'=>date('Y-m-d H:i:s',$deadline),'purchases'=>count($ordered)];
}

function hotspotPurchaseEvidence(PDO $pdo,int $tenant,int $client,array $historicalTerms=[]): array {
    $st=$pdo->prepare("SELECT id,transaction_id,payment_date FROM payments WHERE tenant_id=? AND client_id=? AND status='completed' ORDER BY payment_date,id");
    $st->execute([$tenant,$client]);$payments=$st->fetchAll(PDO::FETCH_ASSOC);$events=[];
    foreach ($payments as $payment) {
        $event=['identity'=>(string)$payment['transaction_id'],'payment_id'=>(int)$payment['id']];
        $st=$pdo->prepare("SELECT checkout_request_id FROM mpesa_transactions WHERE tenant_id=? AND client_id=? AND status='completed' AND result_code=0 AND (checkout_request_id=? OR mpesa_receipt_number=?)");
        $st->execute([$tenant,$client,$payment['transaction_id'],$payment['transaction_id']]);$matches=$st->fetchAll(PDO::FETCH_COLUMN);
        if (count($matches)===1) {
            try {
                $st=$pdo->prepare('SELECT t.validity_value,t.validity_unit,MIN(a.created_at) AS confirmed_at FROM payment_purchase_terms t JOIN payment_activations a ON a.tenant_id=t.tenant_id AND a.client_id=t.client_id AND a.activation_key=t.checkout_id WHERE t.tenant_id=? AND t.client_id=? AND t.checkout_id=? GROUP BY t.validity_value,t.validity_unit');
                $st->execute([$tenant,$client,$matches[0]]);$event=array_merge($event,$st->fetch(PDO::FETCH_ASSOC) ?: []);
            } catch (PDOException $e) { if (($e->errorInfo[1] ?? 0)!==1146) throw $e; }
        }
        // Optional reviewed historical terms are keyed by tenant and immutable payment ID.
        $override=$historicalTerms[$tenant . ':' . $payment['id']] ?? null;
        if ($override && !empty($override['source'])) {
            foreach (['validity_value','validity_unit','confirmed_at'] as $field) if (empty($event[$field]) && isset($override[$field])) $event[$field]=$override[$field];
        }
        $events[]=$event;
    }
    // A confirmed STK missing from the ledger prevents an incomplete reconstruction.
    $st=$pdo->prepare("SELECT COUNT(*) FROM mpesa_transactions m WHERE m.tenant_id=? AND m.client_id=? AND m.status='completed' AND m.result_code=0 AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.tenant_id=m.tenant_id AND p.client_id=m.client_id AND p.status='completed' AND p.transaction_id IN (m.checkout_request_id,m.mpesa_receipt_number))");
    $st->execute([$tenant,$client]);
    if ((int)$st->fetchColumn()>0) return ['repairable'=>false,'reason'=>'Confirmed STK payment missing from completed payment ledger; financial reconciliation required'];
    return hotspotPurchasedDeadline($events);
}
