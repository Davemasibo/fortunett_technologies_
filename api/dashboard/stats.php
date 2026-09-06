<?php
/**
 * Dashboard live stats API
 * Returns metrics and chart data as JSON
 */
ob_start();
ini_set('display_errors', 0);
require_once '../../includes/db_master.php';
require_once '../../includes/auth.php';
require_once '../../includes/analytics_range.php';
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

// The period every chart on the page is drawn over. Previously there was none:
// each series was hard-coded to "last 7 days" or "last 6 months", and the five
// dropdowns above the charts had no name, no id and no listener, so changing
// one did nothing. An unrecognised value falls back to 7d rather than erroring
// -- a stale bookmark should show the default, not a broken dashboard.
$range = analyticsRange($_GET['range'] ?? '7d');
$data['range']       = $range['key'];
$data['range_label'] = $range['label'];
$data['range_bucket']= $range['bucket'];

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

    // ── Collections over the chosen period ────────────────────────
    // One grouped query per series instead of one query per bucket: the old
    // loops were seven round trips each, which over twelve months of five
    // charts would have been sixty.
    $pay = analyticsQuerySeries(
        $pdo, $range, 'payments', 'payment_date', 'COALESCE(SUM(amount),0)',
        (int)$tenant_id, "AND status = 'completed'"
    );
    $data['payments_labels'] = $pay['labels'];
    $data['payments_data']   = $pay['data'];

    // The revenue-trend chart is the same money, always bucketed by MONTH so it
    // stays a trend line rather than turning into a copy of the bar chart above
    // it. A day-granularity range still gets the surrounding six months of
    // context, which is the question this chart is actually asked.
    $monthSpec = in_array($range['key'], ['6m', '12m', 'ytd'], true) ? $range : analyticsRange('6m');
    $mon = analyticsQuerySeries(
        $pdo, $monthSpec, 'payments', 'payment_date', 'COALESCE(SUM(amount),0)',
        (int)$tenant_id, "AND status = 'completed'"
    );
    $data['monthly_labels'] = $mon['labels'];
    $data['monthly_data']   = $mon['data'];
    $data['monthly_label']  = $monthSpec['label'];

    // ── Registrations over the chosen period ──────────────────────
    $reg = analyticsQuerySeries(
        $pdo, $range, 'clients', 'created_at', 'COUNT(*)',
        (int)$tenant_id, '', [], true
    );
    $data['reg_labels'] = $reg['labels'];
    $data['reg_data']   = $reg['data'];

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
                        // getActiveSessionsMap() resolves byte stats via queue fallback
                        $pppoeSessions = $mk->getActiveSessionsMap();
                        $pppoeCount    = count($pppoeSessions);
                        foreach ($pppoeSessions as $ps) {
                            $rBytesIn  += (int)($ps['rx_byte'] ?? 0);
                            $rBytesOut += (int)($ps['tx_byte'] ?? 0);
                        }
                    } catch (Exception $e) { error_log("dashboard/stats PPPoE error router {$router['id']}: " . $e->getMessage()); }
                    try {
                        $hsSessions   = $mk->getActiveHotspotSessionsMap();
                        $hotspotCount = count($hsSessions);
                        foreach ($hsSessions as $hs) {
                            $rBytesIn  += (int)($hs['rx_byte'] ?? 0);
                            $rBytesOut += (int)($hs['tx_byte'] ?? 0);
                        }
                    } catch (Exception $e) { error_log("dashboard/stats hotspot error router {$router['id']}: " . $e->getMessage()); }

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

    // ── SMS over the chosen period ────────────────────────────────
    $sms = analyticsQuerySeries(
        $pdo, $range, 'sms_logs', 'sent_at', 'COUNT(*)', (int)$tenant_id, '', [], true
    );
    $data['sms_labels'] = $sms['labels'];
    $data['sms_data']   = $sms['data'];

    // ── Customer Retention (last 6 months) ──────────────────────────────────
    // Retention is a monthly question whatever the page range is, so it follows
    // $monthSpec -- the same months the revenue trend above is drawn over.
    $retLabels = []; $retActive = []; $retNew = []; $retChurned = [];
    foreach ($monthSpec['buckets'] as $b) {
        $ts = strtotime($b['key'] . '-01');
        $y  = (int)date('Y', $ts);  $m = (int)date('m', $ts);
        $mEnd = date('Y-m-t 23:59:59', $ts); $mStart = date('Y-m-01', $ts);
        $retLabels[] = $b['label'];
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

    // ── Revenue Forecast (the trend's months + 3 projected, linear regression) ──
    // Reuses the monthly series already fetched above rather than re-querying
    // it a month at a time.
    $fcLabels = $mon['labels'];
    $fcHistorical = $mon['data'];
    $histRevs = $mon['data'];
    $n = count($histRevs); $sumX = 0; $sumY = array_sum($histRevs); $sumXY = 0; $sumX2 = 0;
    for ($j = 0; $j < $n; $j++) { $sumX += $j; $sumXY += $j*$histRevs[$j]; $sumX2 += $j*$j; }
    $regD = $n*$sumX2 - $sumX*$sumX;
    $slope = $regD != 0 ? ($n*$sumXY - $sumX*$sumY)/$regD : 0;
    $intercept = $n > 0 ? ($sumY - $slope*$sumX)/$n : 0;
    // One null per historical month except the last, which carries the value so
    // the projected line starts joined to the history instead of floating.
    $fcProjected = $n > 0 ? array_fill(0, $n - 1, null) : [];
    $fcProjected[] = $n > 0 ? end($histRevs) : 0;
    for ($j = 1; $j <= 3; $j++) {
        $fcLabels[]     = date('M Y', strtotime("+$j months"));
        $fcHistorical[] = null;
        $fcProjected[]  = max(0, round($intercept + $slope*($n - 1 + $j)));
    }
    $data['forecast_labels']     = $fcLabels;      // history + 3 projected
    $data['forecast_historical'] = $fcHistorical;  // values, then 3 nulls
    $data['forecast_projected']  = $fcProjected;   // nulls, then 4 values

    // ── Active subscribers by type, at the end of each bucket ───────────────
    // A point-in-time count, so it cannot be a GROUP BY -- it stays one query
    // per bucket, which the range caps at 30 for a month view and 90 for the
    // longest daily one. The 90-day case is the reason the two counts are asked
    // in a single grouped query per bucket rather than one each.
    $duLabels = []; $duPPPoE = []; $duHotspot = [];
    $duSt = $pdo->prepare("
        SELECT COALESCE(NULLIF(connection_type,''),'hotspot') AS ct, COUNT(*) AS n
        FROM clients
        WHERE status='active' AND created_at <= ? AND (expiry_date IS NULL OR expiry_date >= ?)
          AND tenant_id = ?
        GROUP BY ct
    ");
    foreach ($range['buckets'] as $b) {
        $duLabels[] = $b['label'];
        $asOf = $range['bucket'] === 'day'
              ? $b['key'] . ' 23:59:59'
              : date('Y-m-t 23:59:59', strtotime($b['key'] . '-01'));
        $from = $range['bucket'] === 'day' ? $b['key'] : $b['key'] . '-01';

        $pp = 0; $hs = 0;
        try {
            $duSt->execute([$asOf, $from, $tenant_id]);
            foreach ($duSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (strtolower($r['ct']) === 'pppoe') $pp = (int)$r['n']; else $hs = (int)$r['n'];
            }
        } catch (Throwable $e) { /* leave the bucket at zero */ }
        $duPPPoE[] = $pp; $duHotspot[] = $hs;
    }
    $data['du_labels']  = $duLabels;
    $data['du_pppoe']   = $duPPPoE;
    $data['du_hotspot'] = $duHotspot;

    // ── Network Data (live session bytes, today's bar) ───────────────────────
    // Only the live session counters exist, so every bucket but the last is
    // genuinely unknown rather than zero. Labelled across the chosen range so
    // the axis matches its neighbours; the honest value lands on the last one.
    $netLabels = []; $netDownload = []; $netUpload = [];
    foreach ($range['buckets'] as $b) { $netLabels[] = $b['label']; $netDownload[] = 0; $netUpload[] = 0; }
    $lastIdx = count($netLabels) - 1;
    if ($lastIdx >= 0 && ($totalBytesIn > 0 || $totalBytesOut > 0)) {
        $netDownload[$lastIdx] = round($totalBytesIn  / 1073741824, 2);
        $netUpload[$lastIdx]   = round($totalBytesOut / 1073741824, 2);
    }
    $data['net_labels']   = $netLabels;
    $data['net_download'] = $netDownload;
    $data['net_upload']   = $netUpload;

    // ── System Alerts ────────────────────────────────────────────────────────
    $alerts = [];
    $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE expiry_date < NOW() AND status='active' AND tenant_id=?");
    $st->execute([$tenant_id]); $expiredNow = (int)$st->fetchColumn();
    if ($expiredNow > 0) $alerts[] = ['type'=>'warning','title'=>"$expiredNow expired account(s) still active",'message'=>'These accounts have passed their expiry date but are still marked active. Click to view and run expiry check.','time'=>'Now','link'=>'/clients.php?status=active'];
    $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) AND status='active' AND tenant_id=?");
    $st->execute([$tenant_id]); $soonExp = (int)$st->fetchColumn();
    if ($soonExp > 0) $alerts[] = ['type'=>'warning','title'=>"$soonExp account(s) expiring within 3 days",'message'=>'Consider sending renewal SMS reminders to keep these customers active. Click to view them.','time'=>'Next 3 days','link'=>'/clients.php?status=active'];
    $offlineRs = array_values(array_filter($routerStatus, function($r){ return !$r['online']; }));
    if (count($offlineRs) > 0) {
        $offNames = implode(', ', array_column($offlineRs, 'name'));
        $alerts[] = ['type'=>'warning','title'=>count($offlineRs).' router(s) unreachable','message'=>"Cannot reach: $offNames. Click to open Router Management.","time"=>'Now','link'=>'/mikrotik.php'];
    }
    if (empty($alerts)) $alerts[] = ['type'=>'info','title'=>'All systems normal','message'=>'No issues detected. Routers are online and accounts are up to date.','time'=>'Now','link'=>''];
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
