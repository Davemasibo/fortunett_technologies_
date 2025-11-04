<?php if (isLoggedIn()): ?>
<!-- Toggle button: appears to left of top nav (fixed) -->
<button id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="true" title="Toggle sidebar">
    <i class="fas fa-bars" aria-hidden="true"></i>
</button>

<aside class="sidebar" id="appSidebar">
    <style>
        /* Theme vars */
        :root {
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 72px;
            --transition-speed: 250ms;
            --primary-color: #4f46e5;
            --secondary-color: #06b6d4;
            --navbar-height: 0px; /* will be set by JS to place sidebar below header */
        }

        /* Base sidebar */
        .sidebar {
            position: fixed;
            top: var(--navbar-height); /* ensure sidebar starts below navbar */
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            height: calc(100vh - var(--navbar-height)); /* sidebar fills remaining viewport */
            background: #fff;
            border-right: 1px solid rgba(0,0,0,0.06);
            transition: width var(--transition-speed) ease, top 120ms ease, height 120ms ease, opacity 120ms ease;
            z-index: 995;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Menu as a vertical flex column that fills the sidebar height and distributes all items evenly */
        .sidebar-menu {
            list-style: none;
            padding: 8px 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-evenly; /* centered and evenly spaced vertically */
            align-items: stretch;
            height: 100%;
            overflow: auto;
        }

        .sidebar-menu li {
            margin: 0;
        }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 15px 16px;
            color: #333;
            text-decoration: none;
            transition: all .2s;
            border-left: 4px solid transparent;
            gap: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-menu a:hover {
            background: #f0f0f0;
            border-left-color: var(--primary-color);
        }
        .sidebar-menu a.active {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border-left-color: var(--secondary-color);
            font-weight: 600;
        }
        .sidebar-menu a i {
            width: 24px;
            margin-right: 8px;
            text-align: center;
            flex: 0 0 auto;
            font-size: 16px;
        }
        .sidebar-menu a span {
            display: inline-block;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: opacity var(--transition-speed) ease, transform var(--transition-speed) ease;
        }

        /* Collapsed state (icon-only) */
        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
            opacity: 1;
            pointer-events: auto;
        }

        /* Hidden state (completely out of the layout) */
        .sidebar.hidden {
            width: 0 !important;
            opacity: 0;
            pointer-events: none;
            /* display will be set to none via JS after transition to avoid reserving layout space */
        }
        .sidebar.hidden .sidebar-menu a span {
            opacity: 0;
            visibility: hidden;
        }

        /* Toggle button (position is refined via JS to appear below the navbar or left of logo) */
        #sidebarToggle {
            position: fixed;
            top: 10px; /* will be overridden by JS with navbar height + offset or logo position */
            left: calc(var(--sidebar-width) + 12px);
            z-index: 1001; /* higher than sidebar so it stays visible on the navbar */
            background: #fff;
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 6px;
            padding: 8px 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: left var(--transition-speed) ease, background .15s, transform .15s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            font-size: 14px;
            height: 36px;
            line-height: 36px;
            padding: 6px 8px;
            border-radius: 6px;
        }

        /* When sidebar is hidden, position toggle at screen edge */
        #sidebarToggle.at-edge {
            left: 12px !important;
        }

        /* Small screens: keep toggle accessible but collapse sidebar by default via JS if desired */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(0); /* keep visible by default but may be toggled by site */
            }
            #sidebarToggle {
                left: calc(var(--sidebar-collapsed-width) + 8px);
            }
        }

        /* Responsiveness tweaks */
        @media (max-width: 991.98px) {
            .sidebar-menu a {
                padding: 12px 14px;
            }
        }

        /* help the page animate when margins/width change */
        body, #main, .main, .main-content, .content, #content, .app-content, .page-wrapper, .container-fluid {
            transition: margin-left var(--transition-speed) ease, width var(--transition-speed) ease;
            box-sizing: border-box;
        }
    </style>

    <?php
    // determine current file once and helper to mark active links (accepts string or array)
    $current = basename($_SERVER['PHP_SELF'] ?? '');
    function isActive($current, $files) {
        if (!is_array($files)) $files = [$files];
        return in_array($current, $files) ? 'active' : '';
    }
    ?>

    <ul class="sidebar-menu">
        <!-- All items now live in the single list so they will all be vertically distributed -->
        <li>
            <a href="dashboard.php" class="<?php echo isActive($current, 'dashboard.php'); ?>">
                <i class="fas fa-home"></i> <span>Dashboard</span>
            </a>
        </li>
        <li>
            <!-- Clients should be active only for clients-related pages (add related names as needed) -->
            <a href="clients.php" class="<?php echo isActive($current, ['clients.php']); ?>">
                <i class="fas fa-users"></i> <span>Users</span>
            </a>
        </li>
        <li>
            <!-- Active Users must be active only when viewing active_clients.php -->
            <a href="active_clients.php" class="<?php echo isActive($current, 'active_clients.php'); ?>">
                <i class="fas fa-users"></i> <span>Active Users</span>
            </a>
        </li>
        <li>
            <a href="packages.php" class="<?php echo isActive($current, 'packages.php'); ?>">
                <i class="fas fa-box"></i> <span>Packages</span>
            </a>
        </li>
        <li>
            <a href="payments.php" class="<?php echo isActive($current, 'payments.php'); ?>">
                <i class="fas fa-money-bill-wave"></i> <span>Payments</span>
            </a>
        </li>
        <li>
            <a href="sms.php" class="<?php echo isActive($current, 'sms.php'); ?>">
                <i class="fas fa-sms"></i> <span>SMS</span>
            </a>
        </li>
        <li>
            <a href="emails.php" class="<?php echo isActive($current, 'emails.php'); ?>">
                <i class="fas fa-envelope"></i> <span>Emails</span>
            </a>
        </li>
        <li>
            <a href="subscription.php" class="<?php echo isActive($current, 'subscription.php'); ?>">
                <i class="fas fa-crown"></i> <span>Billing and Invoice </span>
            </a>
        </li>
        <li>
            <a href="mikrotik.php" class="<?php echo isActive($current, 'mikrotik.php'); ?>">
                <i class="fas fa-server"></i> <span>MikroTik</span>
            </a>
        </li>
        <li>
            <a href="reports.php" class="<?php echo isActive($current, 'reports.php'); ?>">
                <i class="fas fa-chart-bar"></i> <span>Reports</span>
            </a>
        </li>
        <li>
            <a href="settings.php" class="<?php echo isActive($current, 'settings.php'); ?>">
                <i class="fas fa-cog"></i> <span>Settings</span>
            </a>
        </li>
    </ul>
</aside>

<!-- JavaScript to handle collapse/expand/hidden, positioning beneath navbar, and persistence -->
<script>
(function(){
    const SIDEBAR_KEY = 'sidebar-state'; // 0=expanded,1=collapsed,2=hidden
    const sidebar = document.getElementById('appSidebar');
    const toggle = document.getElementById('sidebarToggle');

    const contentSelectors = [
        'body', '#main', '.main', '.main-content', '.content', '#content', '.app-content', '.page-wrapper', '.container-fluid'
    ];
    const navbarSelectors = ['.navbar', '.topnav', 'header', '.main-header', '.topbar', '#topnav', '.navbar-fixed-top'];
    const logoSelectors = ['.navbar .navbar-brand', '.navbar .brand', '.navbar .logo', '.navbar-brand', 'header .brand', 'header .logo', '.navbar-brand img', '.navbar .brand img', 'header .logo img'];

    function getCssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }
    function pxValue(value) {
        return parseInt(String(value).replace('px','').trim()) || 0;
    }

    function findNavbarElement() {
        for (const sel of navbarSelectors) {
            const el = document.querySelector(sel);
            if (el) {
                const style = getComputedStyle(el);
                if (style.display !== 'none' && el.offsetHeight > 0) return el;
            }
        }
        return null;
    }

    // Keep navbar fixed (non-destructive) and return it
    function ensureNavbarFixed() {
        const nav = findNavbarElement();
        if (nav) {
            const style = getComputedStyle(nav);
            if (style.position !== 'fixed') {
                nav.style.position = 'fixed';
                nav.style.top = '0';
                nav.style.left = '0';
                nav.style.right = '0';
                nav.style.zIndex = 998; // below toggle but above sidebar
            }
        }
        return nav;
    }

    function findLogoRect() {
        for (const sel of logoSelectors) {
            const el = document.querySelector(sel);
            if (el && el.getBoundingClientRect) {
                const r = el.getBoundingClientRect();
                if (r.width > 0 && r.height > 0) return r;
            }
        }
        return null;
    }

    // Position the toggle at the left of the navbar (primary) or left of the logo (if available and not off-screen)
    function positionToggle(state) {
        if (!toggle) return;
        // ensure navbar exists and is fixed
        const nav = ensureNavbarFixed();
        const navRect = nav ? nav.getBoundingClientRect() : { left: 0, top: 0, height: 40 };

        // place toggle at the upper-left of the page (inside navbar left padding),
        // vertically centered in the navbar. Do NOT shift it to the right when sidebar is visible.
        const left = Math.round(window.scrollX + navRect.left + 8);
        const top  = Math.round(window.scrollY + navRect.top + (navRect.height - (toggle.offsetHeight || 36)) / 2);

        toggle.style.left = left + 'px';
        toggle.style.top = top + 'px';

        // keep ARIA/visual class for hidden state if needed
        if (state === 2) toggle.classList.add('at-edge');
        else toggle.classList.remove('at-edge');
    }

    // update page content left margin and width so it occupies remaining space
    function updateContentMargins(state) {
        // compute actual sidebar width (0 when hidden)
        let sidebarWidth = 0;
        try {
            if (sidebar && getComputedStyle(sidebar).display !== 'none' && !sidebar.classList.contains('hidden')) {
                // getBoundingClientRect is most accurate (accounts for CSS and transforms)
                sidebarWidth = Math.round(sidebar.getBoundingClientRect().width) || sidebar.offsetWidth || pxValue(getCssVar('--sidebar-width'));
            } else {
                sidebarWidth = 0;
            }
        } catch (e) {
            sidebarWidth = pxValue(getCssVar('--sidebar-width'));
            if (state === 2) sidebarWidth = 0;
        }

        // Apply exact left margin equal to sidebar width (no extra padding/gap)
        const newMarginPx = sidebarWidth + 'px';

        // content width should exactly fill the remaining space
        const newWidth = (sidebarWidth === 0) ? '100%' : ('calc(100% - ' + newMarginPx + ')');

        // enforce on common content selectors and body to avoid unexpected gaps
        contentSelectors.forEach(sel => {
            document.querySelectorAll(sel).forEach(el => {
                try {
                    if (getComputedStyle(el).position !== 'fixed') {
                        el.style.marginLeft = newMarginPx;
                        el.style.paddingLeft = '0px';
                        el.style.width = newWidth;
                        el.style.maxWidth = 'none';
                    }
                } catch (e) { /* ignore */ }
            });
        });

        // ensure body has no left margin/padding left behind
        try {
            document.body.style.marginLeft = '0px';
            document.body.style.paddingLeft = '0px';
        } catch (e) {}

        if (toggle) toggle.setAttribute('aria-expanded', state === 0 ? 'true' : 'false');
    }

    // display handling: remove element from flow after transition so it doesn't reserve space
    const HIDE_TRANSITION_MS = 280;
    let hideTimeout = null;
    function scheduleSidebarDisplay(state) {
        if (hideTimeout) { clearTimeout(hideTimeout); hideTimeout = null; }

        if (state === 2) {
            // let transition run then remove from flow
            sidebar.style.display = '';
            hideTimeout = setTimeout(() => {
                if (sidebar.classList.contains('hidden')) {
                    sidebar.style.display = 'none';
                }
                // after fully hidden ensure content occupies full width
                updateContentMargins(2);
            }, HIDE_TRANSITION_MS);
        } else {
            // restore immediately and allow layout to recalc before updating margins
            sidebar.style.display = '';
            // small timeout to allow browser to apply display then measure
            setTimeout(() => {
                updateContentMargins(state);
                positionToggle(state);
            }, 20);
        }
    }

    // state management
    let currentState = 0;
    function applyState(state, save=true) {
        if (!sidebar) return;
        currentState = Number(state) || 0;

        if (currentState !== 2) {
            sidebar.style.display = '';
        }

        sidebar.classList.remove('collapsed','hidden');
        if (currentState === 1) sidebar.classList.add('collapsed');
        if (currentState === 2) sidebar.classList.add('hidden');

        scheduleSidebarDisplay(currentState);

        // ensure content and toggle are updated after layout settles
        setTimeout(() => {
            updateContentMargins(currentState);
            positionToggle(currentState);
        }, 40);

        if (save) localStorage.setItem(SIDEBAR_KEY, String(currentState));
    }

    // init
    document.addEventListener('DOMContentLoaded', function(){
        if (!sidebar || !toggle) return;

        ensureNavbarFixed();
        // apply navbar height var (so sidebar top is correct)
        const nav = findNavbarElement();
        const navH = nav ? Math.round(nav.getBoundingClientRect().height) : 0;
        document.documentElement.style.setProperty('--navbar-height', navH + 'px');
        if (sidebar) {
            sidebar.style.top = navH + 'px';
            sidebar.style.height = (window.innerHeight - navH) + 'px';
        }

        const stored = localStorage.getItem(SIDEBAR_KEY);
        const state = stored === null ? 0 : (stored === '2' ? 2 : (stored === '1' ? 1 : 0));
        applyState(state, false);

        positionToggle(state);

        // Single-click toggles between expanded <-> collapsed
        let clickTimer = null;
        toggle.addEventListener('click', function(e){
            if (clickTimer != null) return;
            clickTimer = setTimeout(function(){
                clickTimer = null;
                if (currentState === 2) {
                    applyState(0, true);
                } else {
                    applyState(currentState === 1 ? 0 : 1, true);
                }
            }, 220);
        });

        // Double-click toggles hidden <-> expanded
        toggle.addEventListener('dblclick', function(e){
            if (clickTimer) { clearTimeout(clickTimer); clickTimer = null; }
            const newState = currentState === 2 ? 0 : 2;
            applyState(newState, true);
        });

        // Recompute on resize / orientation change
        window.addEventListener('resize', function(){
            const nav = findNavbarElement();
            const navH = nav ? Math.round(nav.getBoundingClientRect().height) : 0;
            document.documentElement.style.setProperty('--navbar-height', navH + 'px');
            if (sidebar) {
                sidebar.style.top = navH + 'px';
                sidebar.style.height = (window.innerHeight - navH) + 'px';
            }
            positionToggle(currentState);
            // small timeout to stabilize layout before recalculating margins
            setTimeout(() => updateContentMargins(currentState), 30);
        });
    });
})();
</script>

<?php endif; ?>