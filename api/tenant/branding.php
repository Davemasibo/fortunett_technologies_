<?php
/**
 * Tenant branding endpoint — used by static HTML pages to dynamically apply tenant colors
 * Returns: { company_name, brand_color, gradient }
 */
require_once __DIR__ . '/../../includes/db_master.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300'); // cache 5 minutes

$host      = $_SERVER['HTTP_HOST'];
$parts     = explode('.', $host);
$subdomain = $parts[0];

$branding = [
    'company_name' => 'FortuNett Technologies',
    'brand_color'  => '#0f3460',
    'gradient'     => 'linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%)',
    'tenant_id'    => 0,
];

if (!in_array($subdomain, ['localhost', 'www']) && !filter_var($host, FILTER_VALIDATE_IP)) {
    try {
        $st = $pdo->prepare(
            "SELECT t.id, t.company_name, ts.setting_key, ts.setting_value
             FROM tenants t
             LEFT JOIN tenant_settings ts ON ts.tenant_id = t.id
             WHERE t.subdomain = ?
             LIMIT 20"
        );
        $st->execute([$subdomain]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $branding['company_name'] = $rows[0]['company_name'];
            $branding['tenant_id']    = (int)$rows[0]['id'];
            foreach ($rows as $r) {
                if ($r['setting_key'] === 'brand_color' && !empty($r['setting_value'])) {
                    $c = $r['setting_value'];
                    $branding['brand_color'] = $c;
                    $branding['gradient']    = "linear-gradient(135deg, {$c} 0%, {$c}99 100%)";
                }
            }
        }
    } catch (Exception $e) {}
}

echo json_encode($branding);
