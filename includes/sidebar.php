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
                <i class="fas fa-home"></i> <span>Dashboard</span>
            </a>
        </li>
        <li>
            <a href="clients.php" class="<?php echo isActivePage(['clients.php', 'user_detail.php']); ?>">
                <i class="fas fa-users"></i> <span>Users</span>
            </a>
        </li>
        <li>
            <a href="active_clients.php" class="<?php echo isActivePage('active_clients.php'); ?>">
                <i class="fas fa-signal"></i> <span>Active Users</span>
            </a>
        </li>
        <li>
            <a href="packages.php" class="<?php echo isActivePage('packages.php'); ?>">
                <i class="fas fa-box"></i> <span>Packages</span>
            </a>
        </li>
        <li>
            <a href="payments.php" class="<?php echo isActivePage('payments.php'); ?>">
                <i class="fas fa-money-bill-wave"></i> <span>Payments</span>
            </a>
        </li>
        <li>
            <a href="sms.php" class="<?php echo isActivePage('sms.php'); ?>">
                <i class="fas fa-sms"></i> <span>SMS</span>
            </a>
        </li>
        <li>
            <a href="emails.php" class="<?php echo isActivePage('emails.php'); ?>">
                <i class="fas fa-envelope"></i> <span>Emails</span>
            </a>
        </li>
        <li>
            <a href="subscription.php" class="<?php echo isActivePage('subscription.php'); ?>">
                <i class="fas fa-crown"></i> <span>Billing and Invoice</span>
            </a>
        </li>
        <li>
            <a href="mikrotik.php" class="<?php echo isActivePage('mikrotik.php'); ?>">
                <i class="fas fa-server"></i> <span>MikroTik</span>
            </a>
        </li>
        <li>
            <a href="reports.php" class="<?php echo isActivePage('reports.php'); ?>">
                <i class="fas fa-chart-bar"></i> <span>Reports</span>
            </a>
        </li>
        <li>
            <a href="settings.php" class="<?php echo isActivePage('settings.php'); ?>">
                <i class="fas fa-cog"></i> <span>Settings</span>
            </a>
        </li>
    </ul>
</aside>

<style>
    :root {
        --sidebar-width: 250px;
        --sidebar-collapsed-width: 72px;
        --navbar-height: 60px;
        --primary-color: #667eea;
        --secondary-color: #764ba2;
    }

    /* Toggle button - fixed in top-left navbar area */
    #sidebarToggle {
        position: fixed;
        top: 12px;
        left: 12px;
        z-index: 1001;
        background: #fff;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 6px;
        padding: 8px 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background 0.2s ease, transform 0.15s ease;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        font-size: 16px;
        color: #333;
        height: 40px;
        width: 40px;
    }

    #sidebarToggle:hover {
        background: #f0f0f0;
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
        background: white;
        box-shadow: 2px 0 5px rgba(0,0,0,0.1);
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
        justify-content: space-evenly;
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
        color: #333;
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
        background: #f5f5f5;
        border-left-color: var(--primary-color);
        color: var(--primary-color);
    }

    .sidebar-menu a.active {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: white;
        border-left-color: #fff;
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
        background: #ddd;
        border-radius: 3px;
    }
    .sidebar-menu::-webkit-scrollbar-thumb:hover {
        background: #999;
    }

    /* Content wrapper adjustments */
    .main-content-wrapper {
        margin-left: var(--sidebar-width);
        width: calc(100% - var(--sidebar-width));
        padding: 30px 0;
        min-height: calc(100vh - 60px);
        background: #f8f9fa;
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

    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
        }
        .sidebar.show {
            transform: translateX(0);
        }
        .main-content-wrapper {
            margin-left: 0;
            width: 100%;
        }
        .main-content-wrapper > div {
            padding: 0 16px;
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
    });
})();
</script>

<?php endif; ?>