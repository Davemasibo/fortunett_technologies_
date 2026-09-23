<?php
require_once __DIR__ . '/dashboard_sync.php';

/** Recheck under the payment lock so a concurrent renewal cannot be expired. */
function runTenantExpiryCheck(PDO $pdo, int $tenant): array {
    if ($tenant <= 0) throw new InvalidArgumentException('Tenant required');
    dashboardSyncSchema($pdo);
    $st = $pdo->prepare("SELECT id FROM clients WHERE tenant_id=? AND status='active' AND expiry_date<NOW() ORDER BY id LIMIT 100");
    $st->execute([$tenant]);
    $updated = 0; $skipped = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $key = 'payment-client-' . $tenant . '-' . $id;
        $lock = $pdo->prepare('SELECT GET_LOCK(?,0)'); $lock->execute([$key]);
        if ((int)$lock->fetchColumn() !== 1) { $skipped++; continue; }
        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare("UPDATE clients SET status='inactive' WHERE id=? AND tenant_id=? AND status='active' AND expiry_date<NOW()");
            $update->execute([$id,$tenant]);
            if ($update->rowCount()) { dashboardQueueCustomer($pdo,$tenant,(int)$id); $updated++; }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]); }
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE tenant_id=? AND status='active' AND expiry_date<NOW()");
    $st->execute([$tenant]);
    return ['updated'=>$updated,'remaining'=>(int)$st->fetchColumn(),'busy'=>$skipped];
}
