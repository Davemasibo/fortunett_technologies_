<?php
/**
 * Super Admin — one-click platform repair.
 *
 * POST /api/super_admin/platform_repair.php
 *   apply=1                       actually write (omit for a dry run)
 *   base_url=https://...          optional, when it cannot be derived
 *   external_ip=1.2.3.4           optional, when it cannot be detected
 *
 * Every repair lives in includes/platform_repair.php, shared with
 * `php tools/platform_repair.php`. Nothing is decided here.
 *
 * Two classes of break are NOT reachable from this endpoint, by design:
 *
 *   crontab lines — need a shell the web user usually does not have, and are
 *                   the one repair that touches the machine rather than the
 *                   database. CLI tool only.
 *   credentials   — a Daraja secret, a TalkSasa token, an SMTP password.
 *                   Reported as `manual` with where to enter them, never
 *                   guessed at.
 */
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db_master.php';
require_once __DIR__ . '/../../super_admin/includes/auth.php';

if (!isSuperAdmin()) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Super admin session required']);
    exit;
}

// Writes are POST-only: a repair must never be triggerable by following a link
// or by a prefetch.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../../includes/platform_repair.php';

try {
    $result = platformRepairRun($pdo, [
        'apply'       => !empty($_POST['apply']),
        'base_url'    => trim((string)($_POST['base_url'] ?? '')),
        'external_ip' => trim((string)($_POST['external_ip'] ?? '')),
    ]);
} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

ob_clean();
echo json_encode([
    'success' => true,
    'applied' => !empty($_POST['apply']),
    'counts'  => $result['counts'],
    'steps'   => $result['steps'],
    'manual'  => $result['manual'],
]);
