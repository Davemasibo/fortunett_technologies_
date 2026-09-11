<?php
/** Administrative creation is not proof that a paid hotspot purchase occurred. */
function hotspotRegistrationAccess(array $package, string $service): ?array {
    if ($service !== 'hotspot') return null;
    require_once __DIR__ . '/validity.php';
    if (!isset($package['price']) || (float)$package['price'] > 0) return ['status'=>'pending','expiry'=>null];
    return ['status'=>'active','expiry'=>packageExpiryFrom($package['validity_value'] ?? null,$package['validity_unit'] ?? null)];
}

function assertHotspotApiProfileOnly(array $client, array $body): void {
    if (($client['connection_type'] ?? '') !== 'hotspot') return;
    if (!empty($body['action']) || isset($body['extend_days'])) throw new InvalidArgumentException('Hotspot renewal requires a confirmed payment. Manage suspension and package changes through the customer dashboard.');
    foreach (['expiry_date','status','package_id','connection_type'] as $field) {
        if (array_key_exists($field,$body) && (string)$body[$field] !== (string)($client[$field] ?? '')) throw new InvalidArgumentException('This API updates hotspot contact details only. Access changes must use the payment or customer dashboard workflow.');
    }
}
