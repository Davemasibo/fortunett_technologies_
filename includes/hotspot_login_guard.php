<?php
/** Persistent limits protect short PINs across PHP workers. No raw identity is stored. */
function hotspotLoginGuard(PDO $pdo, int $tenant, string $identity, string $ip): bool {
    $pdo->exec('CREATE TABLE IF NOT EXISTS hotspot_login_attempts (
        attempt_key CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0,
        window_start DATETIME NOT NULL, INDEX(window_start)
    ) ENGINE=InnoDB');
    // Fixed windows are updated atomically, including concurrent requests.
    foreach ([['account:' . strtolower($identity), 8], ['ip:' . $ip, 80]] as [$subject, $limit]) {
        $key = hash('sha256', $tenant . ':' . $subject);
        $pdo->prepare('INSERT INTO hotspot_login_attempts (attempt_key,attempts,window_start) VALUES (?,1,NOW())
            ON DUPLICATE KEY UPDATE attempts=IF(window_start <= NOW()-INTERVAL 5 MINUTE,1,attempts+1),
            window_start=IF(window_start <= NOW()-INTERVAL 5 MINUTE,NOW(),window_start)')->execute([$key]);
        $st = $pdo->prepare('SELECT attempts FROM hotspot_login_attempts WHERE attempt_key=?');
        $st->execute([$key]);
        if ((int)$st->fetchColumn() > $limit) return false;
    }
    return true;
}
