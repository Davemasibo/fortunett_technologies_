/*
 * Super-admin shell behaviour — see css/shell.css for the matching styles.
 *
 * Deliberately markup-free: the seven super-admin pages each hand-roll their own
 * copy of the sidebar, so anything that required editing that markup would have
 * to be done seven times and would drift. This adds the body class, the toggle
 * button and the backdrop at runtime, which means enabling it on a page is a
 * two-line change in <head> and nothing else.
 *
 * Loaded with `defer`, so the DOM is parsed by the time this runs.
 */
(function () {
    'use strict';

    var MOBILE_QUERY = '(max-width: 900px)';
    var STORE_KEY    = 'sa.sidebar.collapsed';

    var body    = document.body;
    var sidebar = document.querySelector('.sidebar');
    var topbar  = document.querySelector('.topbar');

    // Login and other chrome-less pages have neither; leave them alone.
    if (!sidebar || !topbar) return;

    body.classList.add('sa-shell');

    function isMobile() {
        return window.matchMedia(MOBILE_QUERY).matches;
    }

    /* The collapsed rail hides the link text, so carry it into a data attribute
       for the CSS hover label — otherwise the icons are unlabelled guesswork. */
    Array.prototype.forEach.call(sidebar.querySelectorAll('.sidebar-menu a'), function (link) {
        var span = link.querySelector('span');
        if (span && !link.hasAttribute('data-label')) {
            link.setAttribute('data-label', span.textContent.trim());
        }
    });

    var backdrop = document.createElement('div');
    backdrop.className = 'sa-nav-backdrop';
    body.appendChild(backdrop);

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'sa-menu-toggle';
    toggle.setAttribute('aria-label', 'Toggle navigation');
    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-controls', sidebar.id || 'sa-navigation');
    sidebar.id = sidebar.id || 'sa-navigation';
    toggle.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>';
    topbar.insertBefore(toggle, topbar.firstChild);

    /* Only the desktop collapse is remembered. Restoring an open drawer would
       land you on a page with the nav sitting over the content — the exact
       problem this is here to fix. */
    try {
        if (localStorage.getItem(STORE_KEY) === '1') {
            body.classList.add('sa-collapsed');
            toggle.setAttribute('aria-expanded', 'false');
        }
    } catch (e) { /* private mode / blocked storage — default to expanded */ }

    function syncNavigation() {
        var open = isMobile() ? body.classList.contains('sa-nav-open') : !body.classList.contains('sa-collapsed');
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', isMobile() ? (open ? 'Close navigation' : 'Open navigation') : (open ? 'Collapse navigation' : 'Expand navigation'));
        sidebar.inert = isMobile() && !open;
        body.style.overflow = isMobile() && open ? 'hidden' : '';
    }
    syncNavigation();

    // Scroll wide tables inside their own container, not the whole page.
    document.querySelectorAll('.content table').forEach(function (table) {
        if (table.closest('.table-wrap, [style*="overflow"]')) return;
        var wrap = document.createElement('div'); wrap.className = 'table-wrap';
        table.parentNode.insertBefore(wrap, table); wrap.appendChild(table);
    });

    toggle.addEventListener('click', function () {
        if (isMobile()) {
            var open = body.classList.toggle('sa-nav-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            syncNavigation();
            if (open) sidebar.querySelector('a').focus();
            return;
        }
        var collapsed = body.classList.toggle('sa-collapsed');
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        try { localStorage.setItem(STORE_KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
        syncNavigation();
    });

    function closeDrawer() {
        if (body.classList.contains('sa-nav-open')) {
            body.classList.remove('sa-nav-open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
        }
        syncNavigation();
    }

    backdrop.addEventListener('click', closeDrawer);
    sidebar.querySelector('.sa-drawer-close')?.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') closeDrawer();
        if (e.key === 'Tab' && isMobile() && body.classList.contains('sa-nav-open')) {
            var links = sidebar.querySelectorAll('a, button');
            var first = links[0], last = links[links.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });

    /* Tapping a link navigates away, but on a slow load the drawer would sit
       open over the outgoing page. */
    sidebar.addEventListener('click', function (e) {
        if (isMobile() && e.target.closest('.sidebar-menu a')) closeDrawer();
    });

    /* Crossing the breakpoint with the drawer open otherwise leaves a backdrop
       stuck over a desktop layout with no way to dismiss it. */
    window.addEventListener('resize', function () {
        if (!isMobile()) closeDrawer();
        syncNavigation();
    });
})();
