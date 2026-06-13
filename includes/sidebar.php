<?php
if (isLoggedIn()) {
    require_once __DIR__ . '/app_url.php';
}
?>
<?php if (isLoggedIn()): ?>
<!-- Toggle button positioned in navbar area via CSS -->
<button id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="true" title="Toggle sidebar">
    <i class="fas fa-bars" aria-hidden="true"></i>
</button>

<aside class="sidebar" id="appSidebar">
    <?php
    // Determine current page for active link highlighting
    $current_page = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
    
    function isActivePage($pages) {
        global $current_page;
        if (!is_array($pages)) $pages = [$pages];
        return in_array($current_page, $pages) ? 'active' : '';
    }
    ?>

    <ul class="sidebar-menu">
        <li>
            <a href="dashboard.php" class="<?php echo isActivePage('dashboard.php'); ?>">
                <i class="fas fa-th-large"></i> <span>Dashboard</span>
            </a>
        </li>

        <li class="sidebar-section-label"><span>Customers</span></li>
        <li>
            <a href="clients.php" class="<?php echo isActivePage(['clients.php', 'user_detail.php']); ?>">
                <i class="fas fa-user-friends"></i> <span>Customers</span>
            </a>
        </li>
        <li>
            <a href="online_customers.php" class="<?php echo isActivePage('online_customers.php'); ?>">
                <i class="fas fa-circle" style="font-size:8px;vertical-align:middle;"></i> <span>Active Sessions</span>
            </a>
        </li>
        <li>
            <a href="vouchers.php" class="<?php echo isActivePage('vouchers.php'); ?>">
                <i class="fas fa-ticket-alt"></i> <span>Vouchers</span>
            </a>
        </li>

        <li class="sidebar-section-label"><span>Network</span></li>
        <li>
            <a href="mikrotik.php" class="<?php echo isActivePage('mikrotik.php'); ?>">
                <i class="fas fa-network-wired"></i> <span>Routers</span>
            </a>
        </li>
        <li>
            <a href="hotspot_diagnostics.php" class="<?php echo isActivePage('hotspot_diagnostics.php'); ?>">
                <i class="fas fa-stethoscope"></i> <span>Hotspot Diagnostics</span>
            </a>
        </li>
        <li>
            <a href="packages.php" class="<?php echo isActivePage('packages.php'); ?>">
                <i class="fas fa-cube"></i> <span>Packages</span>
            </a>
        </li>

        <li class="sidebar-section-label"><span>Finance</span></li>
        <li>
            <a href="payments.php" class="<?php echo isActivePage('payments.php'); ?>">
                <i class="fas fa-credit-card"></i> <span>Payments</span>
            </a>
        </li>
        <li>
            <a href="billing.php" class="<?php echo isActivePage('billing.php'); ?>">
                <i class="fas fa-file-invoice-dollar"></i> <span>Billing</span>
            </a>
        </li>
        <li>
            <a href="reports.php" class="<?php echo isActivePage('reports.php'); ?>">
                <i class="fas fa-chart-line"></i> <span>Reports</span>
            </a>
        </li>

        <li class="sidebar-section-label"><span>Communication</span></li>
        <li>
            <a href="sms.php" class="<?php echo isActivePage('sms.php'); ?>">
                <i class="fas fa-comment-dots"></i> <span>SMS</span>
            </a>
        </li>
        <li>
            <a href="emails.php" class="<?php echo isActivePage('emails.php'); ?>">
                <i class="fas fa-envelope-open"></i> <span>Emails</span>
            </a>
        </li>

        <li class="sidebar-section-label"><span>Settings</span></li>
        <li>
            <a href="settings.php" class="<?php echo isActivePage('settings.php'); ?>">
                <i class="fas fa-sliders-h"></i> <span>Settings</span>
            </a>
        </li>
        <!-- Preview Customer Portal — opens a demo session in a new tab -->
        <li class="sidebar-preview-item">
            <a href="#" onclick="openCustomerPortalPreview(event)" title="Preview the customer portal as a demo user">
                <i class="fas fa-eye"></i> <span>Preview Portal</span>
            </a>
        </li>
        <li class="sidebar-profile-item">
            <a href="profile.php" class="<?php echo isActivePage('profile.php'); ?>">
                <i class="fas fa-user-circle"></i> <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Profile'); ?></span>
            </a>
        </li>
        <li class="sidebar-logout-item">
            <a href="logout.php" style="color:rgba(255,100,100,.85);">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </a>
        </li>
    </ul>

    <?php
    // Tenant subscription status widget
    if (isLoggedIn()) {
        try {
            $uid = $_SESSION['user_id'] ?? null;
            if ($uid) {
                $tSub = $pdo->prepare("
                    SELECT t.status, t.trial_ends_at, t.subscription_ends_at,
                           p.name AS plan_name
                    FROM users u
                    JOIN tenants t ON t.id = u.tenant_id
                    LEFT JOIN platform_subscription_plans p ON p.id = t.subscription_plan_id
                    WHERE u.id = ?
                ");
                $tSub->execute([$uid]);
                $tInfo = $tSub->fetch(PDO::FETCH_ASSOC);

                // Overdue invoice check
                $tenantIdSub = $_SESSION['tenant_id'] ?? null;
                $hasOverdue  = false;
                if ($tenantIdSub) {
                    $oInv = $pdo->prepare("SELECT 1 FROM platform_invoices WHERE tenant_id = ? AND status = 'overdue' LIMIT 1");
                    $oInv->execute([$tenantIdSub]);
                    $hasOverdue = (bool)$oInv->fetchColumn();
                }

                if ($tInfo):
                    $expiryDate = $tInfo['trial_ends_at'] ?? $tInfo['subscription_ends_at'] ?? null;
                    $daysLeft   = 0;
                    $pct        = 100;
                    $barClass   = '';
                    if ($expiryDate) {
                        $diff = (new DateTime())->diff(new DateTime($expiryDate));
                        $daysLeft = max(0, (int)$diff->days * ($diff->invert ? -1 : 1));
                        $total = ($tInfo['status'] === 'trial') ? 14 : 30;
                        $pct   = max(0, min(100, round(($daysLeft / $total) * 100)));
                        $barClass = $pct < 20 ? 'danger' : ($pct < 40 ? 'warning' : '');
                    }
            ?>
            <div class="sidebar-tenant-footer">
                <div class="plan-badge">
                    <i class="fas fa-layer-group"></i>
                    <?= htmlspecialchars($tInfo['plan_name'] ?? 'Starter') ?>
                    <?php if ($tInfo['status'] === 'trial'): ?>
                    &nbsp;· Trial
                    <?php endif; ?>
                </div>
                <?php if ($expiryDate && $daysLeft >= 0): ?>
                <div class="trial-bar-wrap">
                    <div class="trial-bar <?= $barClass ?>" style="width:<?= $pct ?>%"></div>
                </div>
                <div class="trial-label"><?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> remaining</div>
                <?php if ($tInfo['status'] === 'trial' && $daysLeft <= 3): ?>
                <a href="billing.php" class="billing-alert" style="background:rgba(124,58,237,.18);border-color:rgba(124,58,237,.4);color:#c4b5fd;">
                    <i class="fas fa-arrow-circle-up"></i> <?= $daysLeft === 0 ? 'Trial ended — Upgrade now' : 'Trial ending soon — Upgrade' ?>
                </a>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($hasOverdue): ?>
                <a href="billing.php" class="billing-alert">
                    <i class="fas fa-exclamation-circle"></i> Invoice overdue — Pay now
                </a>
                <?php endif; ?>
            </div>
            <?php
                endif;
            }
        } catch (Throwable $e) { /* Non-fatal */ }
    }
    ?>
</aside>

<!-- Mobile sidebar overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<style>
    /* Toggle button - fixed in top-left navbar area */
    #sidebarToggle {
        position: fixed;
        top: 12px;
        left: 12px;
        z-index: 1050;
        background: rgba(255,255,255,.12);
        border: 1px solid rgba(255,255,255,.18);
        border-radius: 6px;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background 0.2s ease, transform 0.15s ease;
        box-shadow: 0 2px 8px rgba(0,0,0,.4);
        font-size: 16px;
        color: #fff;
        height: 40px;
        width: 40px;
    }

    #sidebarToggle:hover {
        background: rgba(255,255,255,.22);
        transform: scale(1.05);
    }

    #sidebarToggle:active {
        transform: scale(0.95);
    }

    /* Sidebar container - full vertical height */
    .sidebar {
        position: fixed;
        left: 0;
        top: var(--navbar-height);
        bottom: 0;
        width: var(--sidebar-width);
        background: linear-gradient(180deg, #0d1117 0%, #131920 55%, #182030 100%);
        box-shadow: 4px 0 24px rgba(0,0,0,.5);
        z-index: 999;
        transition: transform 0.3s ease, width 0.3s ease, opacity 0.3s ease;
        transform: translateX(0);
        opacity: 1;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .sidebar.collapsed {
        width: var(--sidebar-collapsed-width);
    }

    .sidebar.hidden {
        transform: translateX(-100%);
        opacity: 0;
        pointer-events: none;
    }

    /* Sidebar menu - fills entire vertical space with proper distribution */
    .sidebar-menu {
        list-style: none;
        padding: 8px 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        flex: 1;
        justify-content: flex-start;
        overflow-y: auto;
        overflow-x: hidden;
    }

    .sidebar-menu li {
        margin: 0;
        flex: 0 0 auto;
    }

    .sidebar-menu a {
        display: flex;
        align-items: center;
        padding: 14px 16px;
        color: rgba(255, 255, 255, 0.85);
        text-decoration: none;
        transition: all 0.2s ease;
        border-left: 4px solid transparent;
        gap: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-size: 14px;
    }

    .sidebar-menu a:hover {
        background: rgba(255, 255, 255, 0.1);
        border-left-color: var(--primary-light);
        color: white;
    }

    .sidebar-menu a.active {
        background: rgba(255, 255, 255, 0.15);
        color: white;
        border-left-color: var(--primary-light);
        font-weight: 600;
    }

    .sidebar-menu a i {
        width: 20px;
        text-align: center;
        flex: 0 0 auto;
        font-size: 16px;
    }

    .sidebar-menu a span {
        display: inline-block;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        flex: 1;
    }

    .sidebar.collapsed .sidebar-menu a span {
        display: none;
    }

    .sidebar.collapsed .sidebar-menu a {
        justify-content: center;
        padding: 14px 0;
    }

    /* Scrollbar styling */
    .sidebar-menu::-webkit-scrollbar {
        width: 6px;
    }
    .sidebar-menu::-webkit-scrollbar-track {
        background: transparent;
    }
    .sidebar-menu::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,.18);
        border-radius: 3px;
    }
    .sidebar-menu::-webkit-scrollbar-thumb:hover {
        background: rgba(255,255,255,.32);
    }

    /* Content wrapper adjustments */
    .main-content-wrapper {
        margin-left: var(--sidebar-width);
        width: calc(100% - var(--sidebar-width));
        padding: 30px 0;
        min-height: calc(100vh - 60px);
        background: #141414;
        display: flex;
        justify-content: center;
        transition: margin-left 0.3s ease, width 0.3s ease;
    }

    .main-content-wrapper > div {
        width: 100%;
        max-width: 1350px;
        padding: 0 40px;
    }

    .main-content-wrapper.sidebar-collapsed {
        margin-left: var(--sidebar-collapsed-width);
        width: calc(100% - var(--sidebar-collapsed-width));
    }

    .main-content-wrapper.sidebar-collapsed > div {
        padding: 0 40px;
    }

    .main-content-wrapper.sidebar-hidden {
        margin-left: 0;
        width: 100%;
    }

    /* Mobile overlay backdrop */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.55);
        z-index: 998;
        opacity: 0;
        transition: opacity .3s;
    }
    .sidebar-overlay.show {
        display: block;
        opacity: 1;
    }

    /* Section labels */
    .sidebar-section-label {
        padding: 12px 16px 4px;
        flex: 0 0 auto;
    }
    .sidebar-section-label span {
        font-size: 9.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .1em;
        color: rgba(255,255,255,.22);
        display: block;
        border-top: 1px solid rgba(255,255,255,.06);
        padding-top: 10px;
    }
    .sidebar.collapsed .sidebar-section-label { display: none; }

    /* Sidebar profile item — subtle separator above it */
    .sidebar-profile-item {
        margin-top: auto;
        border-top: 1px solid rgba(255,255,255,.07);
        padding-top: 4px;
    }
    .sidebar-profile-item a {
        opacity: .85;
    }
    .sidebar-profile-item a:hover { opacity: 1; }

    /* Preview Portal item — teal/cyan accent */
    .sidebar-preview-item {
        border-top: 1px solid rgba(255,255,255,.07);
        padding-top: 4px;
    }
    .sidebar-preview-item a {
        color: rgba(99,235,200,.85) !important;
        opacity: .85;
    }
    .sidebar-preview-item a:hover {
        background: rgba(99,235,200,.1) !important;
        color: rgba(99,235,200,1) !important;
        border-left-color: rgba(99,235,200,.5) !important;
        opacity: 1;
    }

    /* Logout item — red tint, below profile */
    .sidebar-logout-item a {
        opacity: .8;
    }
    .sidebar-logout-item a:hover {
        background: rgba(255,80,80,.1) !important;
        color: rgba(255,120,120,1) !important;
        border-left-color: rgba(255,80,80,.5) !important;
        opacity: 1;
    }

    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
            z-index: 1000;
        }
        .sidebar.show {
            transform: translateX(0);
        }
        .main-content-wrapper {
            margin-left: 0 !important;
            width: 100% !important;
        }
        .main-content-wrapper > div {
            padding: 0 12px;
        }
    }
</style>

<script>
(function(){
    const SIDEBAR_KEY = 'sidebar-state';
    const sidebar = document.getElementById('appSidebar');
    const toggle = document.getElementById('sidebarToggle');

    let currentState = 0;

    function applyState(state, save = true) {
        if (!sidebar) return;
        currentState = Number(state) || 0;

        sidebar.classList.remove('collapsed', 'hidden');
        if (currentState === 1) sidebar.classList.add('collapsed');
        if (currentState === 2) sidebar.classList.add('hidden');

        // Update main content wrapper
        const mainContent = document.querySelector('.main-content-wrapper');
        if (mainContent) {
            mainContent.classList.remove('sidebar-collapsed', 'sidebar-hidden');
            if (currentState === 1) mainContent.classList.add('sidebar-collapsed');
            if (currentState === 2) mainContent.classList.add('sidebar-hidden');
        }

        // Sync footer padding with sidebar state
        const footer = document.querySelector('.main-layout > footer');
        if (footer) {
            const pl = currentState === 1
                ? 'var(--sidebar-collapsed-width, 72px)'
                : currentState === 2 ? '0'
                : 'var(--sidebar-width, 250px)';
            footer.style.paddingLeft = pl;
        }

        if (toggle) {
            toggle.setAttribute('aria-expanded', currentState === 0 ? 'true' : 'false');
        }

        if (save) localStorage.setItem(SIDEBAR_KEY, String(currentState));
    }

    document.addEventListener('DOMContentLoaded', function(){
        if (!sidebar || !toggle) return;

        // Load saved state
        const stored = localStorage.getItem(SIDEBAR_KEY);
        const state = stored === null ? 0 : (stored === '2' ? 2 : (stored === '1' ? 1 : 0));
        applyState(state, false);

        // Single-click: toggle between expanded and collapsed
        let clickTimer = null;
        toggle.addEventListener('click', function(e){
            e.preventDefault();

            // Mobile handling: Toggle visibility
            if (window.innerWidth <= 768) {
                const isOpen = sidebar.classList.toggle('show');
                const overlay = document.getElementById('sidebarOverlay');
                if (overlay) overlay.classList.toggle('show', isOpen);
                return;
            }

            if (clickTimer) return;
            
            clickTimer = setTimeout(function(){
                clickTimer = null;
                if (currentState === 2) {
                    applyState(0, true);
                } else {
                    applyState(currentState === 1 ? 0 : 1, true);
                }
            }, 220);
        });

        // Double-click: toggle hidden
        toggle.addEventListener('dblclick', function(e){
            e.preventDefault();
            if (clickTimer) { clearTimeout(clickTimer); clickTimer = null; }
            const newState = currentState === 2 ? 0 : 2;
            applyState(newState, true);
        });
        // Tap overlay to close sidebar on mobile
        const overlay = document.getElementById('sidebarOverlay');
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('show');
                overlay.classList.remove('show');
            });
        }
    });
})();

// ── Preview Customer Portal ──────────────────────────────────────────────────
async function openCustomerPortalPreview(e) {
    e.preventDefault();
    const link = e.currentTarget;
    const origHtml = link.innerHTML;
    link.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Opening…</span>';
    link.style.pointerEvents = 'none';

    try {
        const res  = await fetch('<?php echo appBase(); ?>/api/admin/demo_customer_login.php', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            window.open(data.login_url, '_blank', 'noopener');
        } else {
            alert('Could not open preview: ' + (data.message || 'Unknown error'));
        }
    } catch (err) {
        alert('Connection error. Please try again.');
    } finally {
        link.innerHTML = origHtml;
        link.style.pointerEvents = '';
    }
}
</script>

<?php endif; ?>