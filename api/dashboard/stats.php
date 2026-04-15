<?php
/**
 * Dashboard live stats API
 * Returns metrics and chart data as JSON
 */
ob_start();
ini_set('display_errors', 0);
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
ob_clean();
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$user_id = $_SESSION['user_id'];
$st = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$user_id]);
$tenant_id = $st->fetchColumn();
if (!$tenant_id) { echo json_encode(['success'=>false,'message'=>'No tenant']); exit; }

$data = [];

try {
    // ── Revenue metrics ───────────────────────────────────────────
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date) = CURDATE() AND status = 'completed' AND tenant_id = ?");
    $st->execute([$tenant_id]);
    $data['daily_revenue'] = (float)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE()) AND status='completed' AND tenant_id=?");
    $st->execute([$tenant_id]);
    $data['monthly_revenue'] = (float)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE YEAR(payment_date)=YEAR(CURDATE()) AND status='completed' AND tenant_id=?");
    $st->execute([$tenant_id]);
    $data['yearly_revenue'] = (float)$st->fetchColumn();

    // ── Customer metrics ──────────────────────────────────────────
    // subscribed_users = DB active subscriptions (billing view)
    $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE status='active' AND tenant_id=?");
    $st->execute([$tenant_id]);
    $data['subscribed_users'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE (expiry_date < NOW() OR status='inactive') AND tenant_id=?");
    $st->execute([$tenant_id]);
    $data['expired_accounts'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) AND tenant_id=?");
    $st->execute([$tenant_id]);
    $data['new_registrations'] = (int)$st->fetchColumn();

    // ── Payments chart: last 7 days daily totals ──────────────────
    $pLabels = []; $pData = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $pLabels[] = date('D d', strtotime($d));
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date)=? AND status='completed' AND tenant_id=?");
        $st->execute([$d, $tenant_id]);
        $pData[] = (float)$st->fetchColumn();
    }
    $data['payments_labels'] = $pLabels;
    $data['payments_data']   = $pData;

    // ── Monthly revenue: last 6 months ────────────────────────────
    $mLabels = []; $mData = [];
    for ($i = 5; $i >= 0; $i--) {
        $y = date('Y', strtotime("-$i months"));
        $m = date('m', strtotime("-$i months"));
        $mLabels[] = date('M Y', strtotime("-$i months"));
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE YEAR(payment_date)=? AND MONTH(payment_date)=? AND status='completed' AND tenant_id=?");
        $st->execute([$y, $m, $tenant_id]);
        $mData[] = (float)$st->fetchColumn();
    }
    $data['monthly_labels'] = $mLabels;
    $data['monthly_data']   = $mData;

    // ── Registrations: last 7 days ────────────────────────────────
    $rLabels = []; $rData = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $rLabels[] = date('D', strtotime($d));
        $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE DATE(created_at)=? AND tenant_id=?");
        $st->execute([$d, $tenant_id]);
        $rData[] = (int)$st->fetchColumn();
    }
    $data['reg_labels'] = $rLabels;
    $data['reg_data']   = $rData;

    // ── Package utilization ───────────────────────────────────────
    $st = $pdo->prepare("SELECT p.name, COUNT(c.id) as cnt
        FROM clients c
        JOIN packages p ON p.id = c.package_id
        WHERE c.tenant_id = ?
        GROUP BY p.id, p.name ORDER BY cnt DESC LIMIT 6");
    $st->execute([$tenant_id]);
    $pkgs = $st->fetchAll(PDO::FETCH_ASSOC);
    $data['pkg_labels'] = array_column($pkgs, 'name');
    $data['pkg_data']   = array_map('intval', array_column($pkgs, 'cnt'));

    // ── Router status (live from MikroTik) ────────────────────────
    // active_users = sum of LIVE sessions across all reachable routers,
    // NOT the DB subscription count (use subscribed_users for that).
    require_once '../../classes/MikrotikAPI.php';
    $rSt = $pdo->prepare("SELECT id, name, ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status = 'active' AND tenant_id = ?");
    $rSt->execute([$tenant_id]);
    $routerRows = $rSt->fetchAll(PDO::FETCH_ASSOC);

    $routerStatus    = [];
    $totalLiveUsers  = 0;
    $routersOnline   = 0;
    $anyRouterOnline = false;

    foreach ($routerRows as $router) {
        $port = (int)($router['api_port'] ?: 8728);
        $rs = [
            'id'             => $router['id'],
            'name'           => $router['name'],
            'ip'             => $router['ip_address'],
            'online'         => false,
            'active_clients' => 0,
            'pppoe_clients'  => 0,
            'hotspot_clients'=> 0,
        ];

        // Quick TCP reachability check (2-second timeout) before full API call
        $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
        $sock = @fsockopen($connectIp, $port, $tcpErrno, $tcpErrstr, 2);
        if ($sock) {
            fclose($sock);
            try {
                $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
                $mk->connect();

                // PPPoE active sessions
                $pppoeSessions   = $mk->getActiveSessions();
                $pppoeCount      = count($pppoeSessions);

                // Hotspot active sessions
                $hotspotMap      = $mk->getActiveHotspotSessionsMap();
                $hotspotCount    = count($hotspotMap);

                $rs['online']          = true;
                $rs['pppoe_clients']   = $pppoeCount;
                $rs['hotspot_clients'] = $hotspotCount;
                $rs['active_clients']  = $pppoeCount + $hotspotCount;

                $totalLiveUsers += $rs['active_clients'];
                $anyRouterOnline = true;
                $routersOnline++;

                $mk->disconnect();
            } catch (Exception $mkEx) {
                $rs['online'] = true; // TCP reachable but API issue — mark online, count stays 0
                $anyRouterOnline = true;
                $routersOnline++;
            }
        }
        $routerStatus[] = $rs;
    }

    // active_users = live connections from router(s).
    // If no router is reachable at all, fall back to subscribed_users so the card isn't empty.
    $data['active_users']    = $anyRouterOnline ? $totalLiveUsers : $data['subscribed_users'];
    $data['router_online']   = $anyRouterOnline;
    $data['routers_online']  = $routersOnline;
    $data['routers_total']   = count($routerRows);
    $data['router_status']   = $routerStatus;

    // ── SMS stats (last 7 days) ───────────────────────────────────
    $smsLabels = []; $smsData = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $smsLabels[] = date('D', strtotime($d));
        $st = $pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE DATE(sent_at) = ? AND tenant_id = ?");
        try { $st->execute([$d, $tenant_id]); $smsData[] = (int)$st->fetchColumn(); }
        catch (Exception $e) { $smsData[] = 0; }
    }
    $data['sms_labels'] = $smsLabels;
    $data['sms_data']   = $smsData;

    $data['success'] = true;

} catch (Exception $e) {
    $data['success'] = false;
    $data['message'] = $e->getMessage();
}

echo json_encode($data);
