<?php
require_once __DIR__ . '/../classes/MikrotikAPI.php';
require_once __DIR__ . '/radius_client.php';

function enforceCustomerSessions(PDO $pdo, callable $log, array $services = ['pppoe', 'hotspot']): void
{
    $log("--- Enforcement sweep: live sessions vs account status ---");

    $routers = $pdo->query("
        SELECT id, tenant_id, name, ip_address, vpn_ip, username, password, api_port
        FROM mikrotik_routers
        WHERE status IN ('active','online')
        ORDER BY tenant_id, id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $sweptOff = 0;

    foreach ($routers as $r) {
        $tid       = (int)$r['tenant_id'];
        $connectIp = !empty($r['vpn_ip']) ? $r['vpn_ip'] : $r['ip_address'];
        $rName     = $r['name'] ?: $connectIp;

        // Everyone under this tenant who is entitled to be online right now.
        // Compared case-insensitively — RouterOS treats usernames that way.
        $okSt = $pdo->prepare("
            SELECT LOWER(mikrotik_username) AS u
            FROM clients
            WHERE tenant_id = ?
              AND status = 'active'
              AND (expiry_date IS NOT NULL AND expiry_date > NOW())
              AND mikrotik_username IS NOT NULL AND mikrotik_username <> ''
        ");
        $okSt->execute([$tid]);
        $entitled = array_flip($okSt->fetchAll(PDO::FETCH_COLUMN));

        $sweepApi = null;
        try {
            $sweepApi = new MikrotikAPI($connectIp, $r['username'], $r['password'], (int)($r['api_port'] ?? 8728));
            if (!$sweepApi->isReachable(4)) {
                $log("  [{$tid}] {$rName} — unreachable, skipped");
                continue;
            }
            $sweepApi->connect();
        } catch (Throwable $e) {
            $log("  [{$tid}] {$rName} — connect failed: " . $e->getMessage());
            continue;
        }

        foreach ($services as $svc) {
            try {
                $live = ($svc === 'pppoe')
                    ? $sweepApi->getActiveSessionsMap()
                    : $sweepApi->getActiveHotspotSessionsMap();
            } catch (Throwable $e) {
                continue;   // service not configured on this router
            }

            foreach (array_keys($live) as $sessionUser) {
                $sessionUser = strtolower($sessionUser);
                if ($sessionUser === '' || isset($entitled[$sessionUser])) {
                    continue;
                }

                // Only act on sessions we can tie to a client of THIS tenant. Anything
                // else — the ISP's own admin PPPoE link, a MAC-auth hotspot session, a
                // manually created account — is left alone. Cutting an unknown session
                // risks knocking the operator off their own network.
                $whoSt = $pdo->prepare("
                    SELECT id, full_name, status, expiry_date,
                           (status = 'active' AND (expiry_date IS NOT NULL AND expiry_date > NOW())) AS entitled
                    FROM clients
                    WHERE tenant_id = ? AND LOWER(mikrotik_username) = ? LIMIT 1
                ");
                $whoSt->execute([$tid, $sessionUser]);
                $who = $whoSt->fetch(PDO::FETCH_ASSOC);
                if (!$who || $who['entitled']) {
                    continue;
                }

                $why = $who['status'] !== 'active'
                    ? "status={$who['status']}"
                    : 'expired ' . ($who['expiry_date'] ?? '?');

                try {
                    if ($svc === 'pppoe') {
                        $sweepApi->disablePPPoEUser($sessionUser);
                        $sweepApi->kickPPPoESession($sessionUser);
                        radius_disable_client($pdo, $sessionUser);
                    } else {
                        $sweepApi->disableHotspotUser($sessionUser);
                        $sweepApi->kickHotspotSession($sessionUser);
                        radius_disable_client($pdo, $sessionUser);
                    }
                    $sweptOff++;
                    $log("  [{$tid}] {$rName} — CUT {$svc} '{$sessionUser}' (#{$who['id']} {$who['full_name']}, {$why})");
                } catch (Throwable $e) {
                    $log("  [{$tid}] {$rName} — could not cut '{$sessionUser}': " . $e->getMessage());
                }
            }
        }

        try { $sweepApi->disconnect(); } catch (Throwable $e) {}
    }

    $log("Enforcement sweep: {$sweptOff} session(s) cut.");

}
