<?php
require_once __DIR__ . '/includes/auth.php';
$customer = requireCustomerLogin();

$stmt = $pdo->prepare("
    SELECT * FROM customer_sessions
    WHERE client_id = ?
    ORDER BY last_activity DESC
");
$stmt->execute([$customer['id']]);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<style>
/* ── Page ──────────────────────────────────────────── */
.dev-page { padding: 28px 32px; max-width: 1000px; }
.dev-page-title { font-size: 26px; font-weight: 700; color: #e2e2e0; margin: 0 0 4px; }
.dev-page-sub   { font-size: 14px; color: rgba(255,255,255,.4); margin: 0 0 28px; }

/* ── Card ──────────────────────────────────────────── */
.dev-card {
    background: #222221;
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 14px;
    box-shadow: 8px 8px 20px rgba(0,0,0,.4), -4px -4px 10px rgba(255,255,255,.03);
    overflow: hidden;
}
.dev-card-head {
    padding: 16px 22px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.dev-card-head-left {
    font-size: 15px;
    font-weight: 600;
    color: #e2e2e0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.dev-card-head-left i { color: rgba(255,255,255,.4); font-size: 14px; }
.dev-count {
    font-size: 12px;
    color: rgba(255,255,255,.35);
    background: rgba(255,255,255,.06);
    padding: 3px 10px;
    border-radius: 20px;
    font-weight: 500;
}

/* ── Table ─────────────────────────────────────────── */
.dev-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.dev-table  { width: 100%; border-collapse: collapse; min-width: 600px; }
.dev-table thead { background: rgba(255,255,255,.03); border-bottom: 1px solid rgba(255,255,255,.07); }
.dev-table th {
    padding: 11px 18px;
    text-align: left;
    font-size: 10px;
    font-weight: 700;
    color: rgba(255,255,255,.35);
    text-transform: uppercase;
    letter-spacing: .06em;
    white-space: nowrap;
}
.dev-table td {
    padding: 14px 18px;
    border-bottom: 1px solid rgba(255,255,255,.04);
    font-size: 13px;
    color: #d4d4d2;
    vertical-align: middle;
}
.dev-table tbody tr:last-child td { border-bottom: none; }
.dev-table tbody tr:hover { background: rgba(255,255,255,.025); }
.dev-table tbody tr.is-current { background: rgba(59,130,246,.06); }

/* ── Device cell ───────────────────────────────────── */
.dev-cell { display: flex; align-items: center; gap: 12px; }
.dev-icon {
    width: 36px; height: 36px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}
.dev-icon.current { background: rgba(59,130,246,.15); color: #93c5fd; }
.dev-icon.other   { background: rgba(255,255,255,.07); color: rgba(255,255,255,.4); }
.dev-ip    { font-size: 13px; font-weight: 500; color: #e2e2e0; }
.dev-badge {
    display: inline-block;
    margin-top: 2px;
    font-size: 10px;
    font-weight: 700;
    background: rgba(59,130,246,.15);
    color: #93c5fd;
    border: 1px solid rgba(59,130,246,.25);
    padding: 1px 8px;
    border-radius: 10px;
    letter-spacing: .03em;
}

/* ── MAC address ───────────────────────────────────── */
.mac {
    font-family: 'JetBrains Mono', 'Fira Mono', monospace;
    font-size: 12px;
    color: rgba(255,255,255,.45);
    background: rgba(255,255,255,.05);
    padding: 2px 8px;
    border-radius: 5px;
}

/* ── Status badges ─────────────────────────────────── */
.sb { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.sb.active  { background: rgba(16,185,129,.12); color: #6ee7b7; border: 1px solid rgba(16,185,129,.25); }
.sb.expired { background: rgba(239,68,68,.1);   color: #fca5a5; border: 1px solid rgba(239,68,68,.2); }
.sb-dot     { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

/* ── Empty state ───────────────────────────────────── */
.dev-empty {
    text-align: center;
    padding: 64px 24px;
    color: rgba(255,255,255,.25);
}
.dev-empty i { font-size: 44px; display: block; margin-bottom: 14px; }
.dev-empty h3 { font-size: 18px; font-weight: 600; color: rgba(255,255,255,.4); margin-bottom: 6px; }
.dev-empty p  { font-size: 13px; }

/* ── Info strip ────────────────────────────────────── */
.dev-info-strip {
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: rgba(59,130,246,.08);
    border: 1px solid rgba(59,130,246,.18);
    border-radius: 10px;
    font-size: 13px;
    color: rgba(255,255,255,.55);
}
.dev-info-strip i { color: #93c5fd; flex-shrink: 0; }
</style>

<div class="dev-page">
    <h1 class="dev-page-title"><i class="fas fa-laptop" style="color:rgba(255,255,255,.4);margin-right:10px;"></i>Connected Devices</h1>
    <p class="dev-page-sub">Sessions and devices linked to your account</p>

    <div class="dev-info-strip">
        <i class="fas fa-info-circle"></i>
        <span>Each row represents a portal session. Device limits are enforced by your current package.</span>
    </div>

    <div class="dev-card">
        <div class="dev-card-head">
            <div class="dev-card-head-left">
                <i class="fas fa-shield-alt"></i>
                Active Sessions
            </div>
            <span class="dev-count"><?php echo count($sessions); ?> session<?php echo count($sessions) !== 1 ? 's' : ''; ?></span>
        </div>

        <?php if (count($sessions) > 0): ?>
        <div class="dev-scroll">
            <table class="dev-table">
                <thead>
                    <tr>
                        <th>Device / IP</th>
                        <th>MAC Address</th>
                        <th>Status</th>
                        <th>Last Seen</th>
                        <th>Expires</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sessions as $s):
                    $isCurrent = ($s['session_token'] === ($_COOKIE['customer_session'] ?? ''));
                    $isActive  = strtotime($s['expires_at']) > time();
                    $lastAct   = !empty($s['last_activity']) ? date('M d, H:i', strtotime($s['last_activity'])) : '—';
                    $expiresAt = !empty($s['expires_at'])    ? date('M d, H:i', strtotime($s['expires_at']))    : '—';
                ?>
                <tr class="<?php echo $isCurrent ? 'is-current' : ''; ?>">
                    <td>
                        <div class="dev-cell">
                            <div class="dev-icon <?php echo $isCurrent ? 'current' : 'other'; ?>">
                                <i class="fas fa-<?php echo $isCurrent ? 'mobile-alt' : 'desktop'; ?>"></i>
                            </div>
                            <div>
                                <div class="dev-ip"><?php echo htmlspecialchars($s['ip_address'] ?? 'Unknown IP'); ?></div>
                                <?php if ($isCurrent): ?><div class="dev-badge">This Device</div><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if (!empty($s['mac_address'])): ?>
                            <span class="mac"><?php echo htmlspecialchars($s['mac_address']); ?></span>
                        <?php else: ?>
                            <span style="color:rgba(255,255,255,.2);">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="sb <?php echo $isActive ? 'active' : 'expired'; ?>">
                            <span class="sb-dot"></span>
                            <?php echo $isActive ? 'Active' : 'Expired'; ?>
                        </span>
                    </td>
                    <td style="color:rgba(255,255,255,.45);"><?php echo $lastAct; ?></td>
                    <td style="color:rgba(255,255,255,.45);"><?php echo $expiresAt; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="dev-empty">
            <i class="fas fa-laptop-medical"></i>
            <h3>No Active Sessions</h3>
            <p>No devices are currently linked to this account.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
