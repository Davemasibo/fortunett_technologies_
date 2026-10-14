<?php
/** Validate the creation choice before saving or contacting a router. */
function customerCreationRouter(PDO $pdo, int $tenant, mixed $selection): int {
    if (!is_scalar($selection) || !preg_match('/^\d*$/D', (string)$selection)) {
        throw new InvalidArgumentException('Select a valid MikroTik router.');
    }
    $stmt = $pdo->prepare('SELECT id FROM mikrotik_routers WHERE tenant_id=? ORDER BY id');
    $stmt->execute([$tenant]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $id = (int)$selection;
    if ($id && !in_array($id, $ids, true)) throw new InvalidArgumentException('The selected MikroTik does not belong to your account.');
    if (!$id && count($ids) > 1) throw new InvalidArgumentException('Choose the MikroTik that will serve this customer.');
    return $id ?: ($ids[0] ?? 0);
}
