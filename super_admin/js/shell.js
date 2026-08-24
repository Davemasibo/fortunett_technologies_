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
    toggle.innerHTML = '<i class="fas fa-bars"></i>';
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

    toggle.addEventListener('click', function () {
        if (isMobile()) {
            var open = body.classList.toggle('sa-nav-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            return;
        }
        var collapsed = body.classList.toggle('sa-collapsed');
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        try { localStorage.setItem(STORE_KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
    });

    function closeDrawer() {
        if (body.classList.contains('sa-nav-open')) {
            body.classList.remove('sa-nav-open');
            toggle.setAttribute('aria-expanded', 'false');
        }
    }

    backdrop.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') closeDrawer();
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
    });
})();
