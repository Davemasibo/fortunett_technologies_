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
    // Isolated in its own try-catch so a MikroTik failure never breaks the stats response.
    // The dashboard also calls api/dashboard/router_status.php independently for this data.
    $routerStatus    = [];
    $totalLiveUsers  = 0;
    $routersOnline   = 0;
    $anyRouterOnline = false;

    try {
        require_once '../../classes/MikrotikAPI.php';
        $rSt = $pdo->prepare("SELECT id, name, ip_address, vpn_ip, username, password, api_port FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ?");
        $rSt->execute([$tenant_id]);
        $routerRows = $rSt->fetchAll(PDO::FETCH_ASSOC);

        $totalPPPoE    = 0;
        $totalHotspot  = 0;
        $totalBytesIn  = 0;
        $totalBytesOut = 0;

        foreach ($routerRows as $router) {
            $port = (int)($router['api_port'] ?: 8728);
            $rs   = [
                'id'              => $router['id'],
                'name'            => $router['name'],
                'ip'              => $router['ip_address'],
                'online'          => false,
                'active_clients'  => 0,
                'pppoe_clients'   => 0,
                'hotspot_clients' => 0,
            ];

            $connectIp = !empty($router['vpn_ip']) ? $router['vpn_ip'] : $router['ip_address'];
            $sock = @fsockopen($connectIp, $port, $tcpErrno, $tcpErrstr, 4);
            if ($sock) {
                fclose($sock);
                try {
                    $mk = new MikrotikAPI($connectIp, $router['username'], $router['password'], $port);
                    $mk->connect();

                    $pppoeCount   = 0;
                    $hotspotCount = 0;
                    $rBytesIn     = 0;
                    $rBytesOut    = 0;
                    try {
                        $ppoeSessions = $mk->getActiveSessions();
                        $pppoeCount   = count($ppoeSessions);
                        foreach ($ppoeSessions as $ps) {
                            $rBytesIn  += (int)($ps['bytes-in']  ?? $ps['bytes_in']  ?? 0);
                            $rBytesOut += (int)($ps['bytes-out'] ?? $ps['bytes_out'] ?? 0);
                        }
                    } catch (Exception $e) { error_log("dashboard/stats PPPoE error router {$router['id']}: " . $e->getMessage()); }
                    try { $hotspotCount = count($mk->getActiveHotspotSessionsMap()); } catch (Exception $e) { error_log("dashboard/stats hotspot error router {$router['id']}: " . $e->getMessage()); }

                    $rs['online']          = true;
                    $rs['pppoe_clients']   = $pppoeCount;
                    $rs['hotspot_clients'] = $hotspotCount;
                    $rs['active_clients']  = $pppoeCount + $hotspotCount;

                    $totalPPPoE    += $pppoeCount;
                    $totalHotspot  += $hotspotCount;
                    $totalLiveUsers += $rs['active_clients'];
                    $totalBytesIn  += $rBytesIn;
                    $totalBytesOut += $rBytesOut;
                    $anyRouterOnline = true;
                    $routersOnline++;

                    $mk->disconnect();
                } catch (Exception $mkEx) {
                    $rs['online'] = true;
                    $anyRouterOnline = true;
                    $routersOnline++;
                }
            }
            $routerStatus[] = $rs;
        }
    } catch (Exception $routerSectionEx) {
        // MikroTik section failed — return offline stubs so the JS can at least show "Offline"
        try {
            $rFallback = $pdo->prepare("SELECT id, name, ip_address FROM mikrotik_routers WHERE status IN ('active','online') AND tenant_id = ?");
            $rFallback->execute([$tenant_id]);
            foreach ($rFallback->fetchAll(PDO::FETCH_ASSOC) as $rf) {
                $routerStatus[] = ['id' => $rf['id'], 'name' => $rf['name'], 'ip' => $rf['ip_address'], 'online' => false, 'active_clients' => 0, 'pppoe_clients' => 0, 'hotspot_clients' => 0];
            }
        } catch (Exception $e2) { /* ignore */ }
    }

    // active_users = live MikroTik sessions (PPPoE + hotspot combined).
    // subscribed_users = DB active subscriptions (billing view, not connection state).
    $data['active_users']    = $totalLiveUsers;
    $data['online_users']    = $totalLiveUsers;
    $data['pppoe_online']    = $totalPPPoE   ?? 0;
    $data['hotspot_online']  = $totalHotspot ?? 0;
    $data['router_online']   = $anyRouterOnline;
    $data['routers_online']  = $routersOnline;
    $data['routers_total']   = count($routerStatus);
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

    // ── Customer Retention (last 6 months) ──────────────────────────────────
    $retLabels = []; $retActive = []; $retNew = []; $retChurned = [];
    for ($i = 5; $i >= 0; $i--) {
        $ts = strtotime("-$i months");
        $y  = (int)date('Y', $ts);  $m = (int)date('m', $ts);
        $mEnd = date('Y-m-t 23:59:59', $ts); $mStart = date('Y-m-01', $ts);
        $retLabels[] = date('M Y', $ts);
        $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE YEAR(created_at)=? AND MONTH(created_at)=? AND tenant_id=?");
        $st->execute([$y,$m,$tenant_id]); $retNew[] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE created_at<=? AND (expiry_date IS NULL OR expiry_date>=?) AND tenant_id=?");
        $st->execute([$mEnd,$mStart,$tenant_id]); $retActive[] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE expiry_date>=? AND expiry_date<=? AND tenant_id=?");
        $st->execute([$mStart,$mEnd,$tenant_id]); $retChurned[] = (int)$st->fetchColumn();
    }
    $data['retention_labels'] = $retLabels;
    $data['retention_active']  = $retActive;
    $data['retention_new']     = $retNew;
    $data['retention_churned'] = $retChurned;

    // ── Revenue Forecast (6 historical + 3 projected via linear regression) ──
    $fcLabels = []; $fcHistorical = []; $histRevs = [];
    for ($i = 5; $i >= 0; $i--) {
        $ts = strtotime("-$i months");
        $fcLabels[] = date('M Y', $ts);
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE YEAR(payment_date)=? AND MONTH(payment_date)=? AND status='completed' AND tenant_id=?");
        $st->execute([(int)date('Y',$ts),(int)date('m',$ts),$tenant_id]);
        $rev = (float)$st->fetchColumn();
        $fcHistorical[] = $rev;  $histRevs[] = $rev;
    }
    $n = count($histRevs); $sumX = 0; $sumY = array_sum($histRevs); $sumXY = 0; $sumX2 = 0;
    for ($j = 0; $j < $n; $j++) { $sumX += $j; $sumXY += $j*$histRevs[$j]; $sumX2 += $j*$j; }
    $regD = $n*$sumX2 - $sumX*$sumX;
    $slope = $regD != 0 ? ($n*$sumXY - $sumX*$sumY)/$regD : 0;
    $intercept = $n > 0 ? ($sumY - $slope*$sumX)/$n : 0;
    $fcProjected = array_fill(0, 5, null);
    $fcProjected[] = end($histRevs); // connect at last historical point
    for ($j = 1; $j <= 3; $j++) {
        $fcLabels[]     = date('M Y', strtotime("+$j months"));
        $fcHistorical[] = null;
        $fcProjected[]  = max(0, round($intercept + $slope*($n - 1 + $j)));
    }
    $data['forecast_labels']     = $fcLabels;      // 9 labels
    $data['forecast_historical'] = $fcHistorical;  // 6 values + 3 nulls
    $data['forecast_projected']  = $fcProjected;   // 5 nulls + 4 values

    // ── Active Users by Type per day (last 7 days) ──────────────────────────
    $duLabels = []; $duPPPoE = []; $duHotspot = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $duLabels[] = date('D', strtotime($d));
        $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE connection_type='pppoe' AND status='active' AND created_at<=? AND (expiry_date IS NULL OR expiry_date>=?) AND tenant_id=?");
        $st->execute([$d.' 23:59:59',$d,$tenant_id]); $duPPPoE[] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE connection_type='hotspot' AND status='active' AND created_at<=? AND (expiry_date IS NULL OR expiry_date>=?) AND tenant_id=?");
        $st->execute([$d.' 23:59:59',$d,$tenant_id]); $duHotspot[] = (int)$st->fetchColumn();
    }
    $data['du_labels']  = $duLabels;
    $data['du_pppoe']   = $duPPPoE;
    $data['du_hotspot'] = $duHotspot;

    // ── Network Data (live session bytes, today's bar) ───────────────────────
    $netLabels = []; $netDownload = []; $netUpload = [];
    for ($i = 6; $i >= 0; $i--) { $netLabels[] = date('D', strtotime("-$i days")); $netDownload[] = 0; $netUpload[] = 0; }
    if ($totalBytesIn > 0 || $totalBytesOut > 0) {
        $netDownload[6] = round($totalBytesIn  / 1073741824, 2);
        $netUpload[6]   = round($totalBytesOut / 1073741824, 2);
    }
    $data['net_labels']   = $netLabels;
    $data['net_download'] = $netDownload;
    $data['net_upload']   = $netUpload;

    // ── System Alerts ────────────────────────────────────────────────────────
    $alerts = [];
    $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE expiry_date < NOW() AND status='active' AND tenant_id=?");
    $st->execute([$tenant_id]); $expiredNow = (int)$st->fetchColumn();
    if ($expiredNow > 0) $alerts[] = ['type'=>'warning','title'=>"$expiredNow expired account(s) still active",'message'=>'These accounts have passed their expiry date but are still marked active. Run expiry check to disable them.','time'=>'Now'];
    $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) AND status='active' AND tenant_id=?");
    $st->execute([$tenant_id]); $soonExp = (int)$st->fetchColumn();
    if ($soonExp > 0) $alerts[] = ['type'=>'warning','title'=>"$soonExp account(s) expiring within 3 days",'message'=>'Consider sending renewal SMS reminders to keep these customers active.','time'=>'Next 3 days'];
    $offlineRs = array_values(array_filter($routerStatus, function($r){ return !$r['online']; }));
    if (count($offlineRs) > 0) {
        $offNames = implode(', ', array_column($offlineRs, 'name'));
        $alerts[] = ['type'=>'warning','title'=>count($offlineRs).' router(s) unreachable','message'=>"Cannot reach: $offNames. Check power and network connectivity.",'time'=>'Now'];
    }
    if (empty($alerts)) $alerts[] = ['type'=>'info','title'=>'All systems normal','message'=>'No issues detected. Routers are online and accounts are up to date.','time'=>'Now'];
    $data['alerts'] = $alerts;

    // ── Most Active Users (top 5 by payments, last 30 days) ─────────────────
    try {
        $st = $pdo->prepare("
            SELECT c.mikrotik_username, c.full_name, c.phone,
                   COALESCE(SUM(p.amount),0) AS total_paid
            FROM clients c
            LEFT JOIN payments p ON p.client_id=c.id AND p.status='completed'
                AND p.payment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND p.tenant_id=c.tenant_id
            WHERE c.tenant_id=?
            GROUP BY c.id ORDER BY total_paid DESC LIMIT 5
        ");
        $st->execute([$tenant_id]);
        $data['most_active'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_ma) { $data['most_active'] = []; }

    $data['success'] = true;

} catch (Exception $e) {
    $data['success'] = false;
    $data['message'] = $e->getMessage();
}

echo json_encode($data);
