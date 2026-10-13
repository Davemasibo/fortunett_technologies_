from pathlib import Path
p=Path('api/mikrotik/hotspot_status.php')
s=p.read_text(encoding='utf-8')
s=s.replace("require_once '../../classes/MikrotikAPI.php';", "require_once '../../classes/MikrotikAPI.php';\nrequire_once __DIR__ . '/../../includes/hotspot_diagnostic_snapshot.php';\nrequire_once __DIR__ . '/../../includes/router_expiry.php';")
s=s.replace("if (isset($s['!re']) && ($s['disabled'] ?? 'true') !== 'true') {", "if (isset($s['!re']) && ($s['disabled'] ?? 'true') !== 'true' && ($s['invalid'] ?? 'false') !== 'true') {")
s=s.replace("$result['hotspot_server'] = $running;", "$result['hotspot_server'] = $running ?: false;")
pos=s.index('    $api->disconnect();')
s=s[:pos]+'''    // Use exact active-profile paths and current tenant DNS destinations.
    $snapshot = [];
    foreach (['servers'=>'/ip/hotspot/print', 'profiles'=>'/ip/hotspot/profile/print',
        'addresses'=>'/ip/address/print', 'dhcp'=>'/ip/dhcp-server/print',
        'networks'=>'/ip/dhcp-server/network/print', 'dns'=>'/ip/dns/print',
        'files'=>'/file/print', 'garden'=>'/ip/hotspot/walled-garden/print',
        'garden_ip'=>'/ip/hotspot/walled-garden/ip/print'] as $key=>$command) {
        try { $snapshot[$key] = array_values(array_filter(routerCheckedCommand($api, $command), fn($r)=>isset($r['!re']))); }
        catch (Throwable $e) { $result['read_errors'][$key] = 'Router read failed'; }
    }
    $ts = $pdo->prepare('SELECT subdomain FROM tenants WHERE id=?');
    $ts->execute([$tenantId]);
    $domain = 'fortunetttech.site';
    try { $domain = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain'")->fetchColumn() ?: $domain; } catch (Throwable $e) {}
    $host = $ts->fetchColumn() . '.' . $domain;
    $ips = array_values(array_unique(gethostbynamel($host) ?: []));
    $result = array_merge($result, hotspotDiagnosticSnapshot($snapshot, $host, $ips));
    $result['checked_at'] = date(DATE_ATOM);
    $result['portal_host'] = $host;
    $result['portal_ips'] = $ips;
''' +s[pos:]
p.write_text(s,encoding='utf-8')
p=Path('api/diagnostics/automation_chain.php');s=p.read_text(encoding='utf-8')
s=s.replace("$groups = [", "$hotspotOnly = ($_POST['service'] ?? '') === 'hotspot';\n$clientScope = $hotspotOnly ? \" AND COALESCE(NULLIF(c.connection_type,''),'hotspot') = 'hotspot'\" : '';\n\n$groups = [",1)
s=s.replace("$r['status'] === 'active'", "in_array($r['status'], ['active', 'online'], true)")
a=s.index('    $ev = $pdo->prepare(');b=s.index('\n} catch (Throwable $e) {',a)
s=s[:a]+'''    $ev = $pdo->prepare("SELECT COUNT(DISTINCT c.id) AS total,
        COUNT(DISTINCT CASE WHEN c.expiry_date > NOW() AND c.status='active' THEN c.id END) AS active_now,
        COUNT(DISTINCT CASE WHEN c.expiry_date > NOW() AND c.status<>'active' THEN c.id END) AS needs_review,
        COUNT(DISTINCT CASE WHEN c.expiry_date IS NULL OR c.expiry_date <= NOW() THEN c.id END) AS expired
        FROM payments p JOIN clients c ON c.id=p.client_id AND c.tenant_id=p.tenant_id
        WHERE p.tenant_id=? AND p.status='completed' AND p.payment_date>=NOW()-INTERVAL 7 DAY $clientScope");
    $ev->execute([$tenantId]);
    $row = $ev->fetch(PDO::FETCH_ASSOC);
    $total = (int)$row['total']; $review = (int)$row['needs_review'];
    $add('evidence', 'Paid customer access records', $review ? 'fail' : ($total ? 'ok' : 'warn'),
        $total ? $row['active_now'] . ' customer(s) have active, unexpired access; ' . $row['expired']
            . ' have expired or unrecorded access; ' . $review . ' have a future expiry but are not active. '
            . 'These are account records, not proof of router login. Expired access and part payments do not prove activation failed.'
            : 'No completed ' . ($hotspotOnly ? 'hotspot ' : '') . 'payments in the last 7 days. End-to-end purchase remains unverified.',
        $review ? 'Review these customers in Clients, then check their router provisioning.' : null);
''' + s[b:]
s=s.replace("AND (result_code IS NULL OR result_code = '')", "AND status = 'pending'\n          AND (result_code IS NULL OR CAST(result_code AS CHAR) = '')")
s=s.replace("'Full automation verified'", "'Recorded checks passed'")
s=s.replace("'Money arrives, is recorded without faults, and turns into router access without anyone '\n                           . 'intervening.'", "'No recorded blocker was found. Verify a real hotspot purchase, router login and browsing on a phone.'")
s=s.replace("'Automation working, '", "'Review '")
s=s.replace("'Nothing is blocking activation, but the items below will cause trouble under load or over time.'", "'Review the warnings below. A configuration check cannot prove a customer purchase and Internet access.'")
s=s.replace("'groups'          => $groups,", "'groups'          => $groups,\n    'scope' => $hotspotOnly ? 'hotspot' : 'all',\n    'checked_at' => date(DATE_ATOM),")
p.write_text(s,encoding='utf-8')
