<?php
/** Progress comes from saved tenant data, so setup can resume on any device. */
function tenantOnboardingProgress(PDO $pdo, int $tenantId): array
{
    $st = $pdo->prepare('SELECT company_name, subdomain FROM tenants WHERE id=?');
    $st->execute([$tenantId]);
    $tenant = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) throw new RuntimeException('Tenant not found');
    $count = function (string $sql) use ($pdo, $tenantId): int {
        $st = $pdo->prepare($sql); $st->execute([$tenantId]); return (int)$st->fetchColumn();
    };
    $st = $pdo->prepare('SELECT id,name,status,last_seen,service_types FROM mikrotik_routers WHERE tenant_id=? ORDER BY id');
    $st->execute([$tenantId]);
    $routers = $st->fetchAll(PDO::FETCH_ASSOC);
    $connected = count(array_filter($routers, fn($r) => in_array($r['status'], ['active','online'], true) && !empty($r['last_seen'])));
    $steps = [
        ['title'=>'Verify your account', 'done'=>$count("SELECT COUNT(*) FROM users u JOIN tenants t ON t.admin_user_id=u.id WHERE u.tenant_id=? AND (u.email_verified=1 OR u.is_verified=1)")>0, 'url'=>'settings.php#general', 'detail'=>'Open the verification link sent at signup. Google verified accounts are ready.'],
        ['title'=>'Confirm business profile', 'done'=>!empty(trim($tenant['company_name'] ?? '')), 'url'=>'settings.php#general', 'detail'=>'Save your company name and contact details.'],
        ['title'=>'Connect every MikroTik', 'done'=>count($routers)>0 && $connected===count($routers), 'url'=>'mikrotik.php?open_modal=1', 'detail'=>'Add each device separately under this account. Give each a unique name, generate its own setup script, run it on that device, then Test Connection and Verify Provisioning.'],
        ['title'=>'Create service packages', 'done'=>$count('SELECT COUNT(*) FROM packages WHERE tenant_id=?')>0, 'url'=>'packages.php?open_modal=1', 'detail'=>'Create the PPPoE or Hotspot packages your customers will buy.'],
        ['title'=>'Configure collections', 'done'=>$count('SELECT COUNT(*) FROM payment_gateways WHERE tenant_id=? AND is_active=1')>0, 'url'=>'settings.php#payments', 'detail'=>'Configure and activate your payment gateway and register its callbacks.'],
        ['title'=>'Provision your first customer', 'done'=>$count("SELECT COUNT(*) FROM router_services WHERE tenant_id=? AND status='active'")>0, 'url'=>'clients.php?open_modal=1', 'detail'=>'Create a customer and choose a package. After payment, open the customer and use Provision to Router to select the correct device. Check that the customer can connect.'],
        ['title'=>'Confirm first collection', 'done'=>$count("SELECT COUNT(*) FROM payments WHERE tenant_id=? AND status='completed' AND amount>0")>0, 'url'=>'payments.php', 'detail'=>'Complete a real customer payment and confirm the receipt, service activation and collections in Billing.'],
    ];
    return ['tenant'=>$tenant, 'routers'=>$routers, 'steps'=>$steps, 'completed'=>count(array_filter($steps, fn($s)=>$s['done'])), 'total'=>count($steps)];
}
