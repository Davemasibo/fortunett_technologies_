<?php
/**
 * Preview the branded hotspot login page — exactly the bytes a router serves.
 *
 * Two audiences, because the page belongs to a tenant but the person who most
 * often wants to look at it is FortuNett staff:
 *
 *   /hotspot/preview_login.php              tenant admin — their own portal
 *   /hotspot/preview_login.php?tenant=5     super admin  — any tenant's portal
 *   /hotspot/preview_login.php              super admin  — pick from a list
 *
 * A super admin has tenant_id = NULL, so the original version resolved their
 * tenant to 0 and dead-ended on "No provisioning token found" with no hint
 * that a ?tenant= was what it wanted.
 */
require_once __DIR__ . '/../includes/db_master.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit('Login required'); }

$isSuper = !empty($_SESSION['is_super_admin']);

if ($isSuper) {
    $tenantId = (int)($_GET['tenant'] ?? 0);

    if (!$tenantId) {
        // No tenant named: list them rather than fail. The provisioning token
        // is what login_serve.php authenticates on, so a tenant without one
        // has no portal to preview and is shown as such.
        header('Content-Type: text/html; charset=utf-8');
        $rows = $pdo->query("SELECT id, company_name, subdomain, provisioning_token
                             FROM tenants ORDER BY company_name")->fetchAll(PDO::FETCH_ASSOC);
        echo '<!doctype html><meta charset="utf-8"><title>Preview hotspot portal</title>'
           . '<style>body{font:14px/1.6 system-ui,sans-serif;background:#111;color:#eee;padding:32px}'
           . 'a{color:#ff9500}li{margin:6px 0}code{color:#888}</style>'
           . '<h2>Preview a tenant\'s hotspot portal</h2><ul>';
        foreach ($rows as $r) {
            $label = htmlspecialchars(($r['company_name'] ?: 'Tenant ' . $r['id'])
                   . ' (' . ($r['subdomain'] ?: 'no subdomain') . ')');
            echo '<li>';
            if ($r['provisioning_token']) {
                echo '<a href="?tenant=' . (int)$r['id'] . '">' . $label . '</a>';
            } else {
                echo $label . ' <code>— no provisioning token, nothing to preview</code>';
            }
            echo '</li>';
        }
        echo '</ul>';
        exit;
    }
} else {
    $t = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $t->execute([$_SESSION['user_id']]);
    $tenantId = (int)$t->fetchColumn();
    if (!$tenantId) {
        http_response_code(403);
        exit('Your account is not attached to a tenant.');
    }
}

$s = $pdo->prepare("SELECT provisioning_token FROM tenants WHERE id = ?");
$s->execute([$tenantId]);
$token = $s->fetchColumn();

if (!$token) { exit('No provisioning token found for tenant ' . $tenantId . '. Contact support.'); }

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base  = $proto . '://' . $_SERVER['HTTP_HOST'];
$url   = $base . '/hotspot/login_serve.php?token=' . urlencode($token);

header('Location: ' . $url);
exit;
