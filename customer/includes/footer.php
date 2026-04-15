    </div> <!-- End content-wrapper -->

    <footer style="text-align:center;padding:18px 24px;color:rgba(255,255,255,.35);font-size:13px;margin-top:auto;border-top:1px solid rgba(255,255,255,.06);background:#1c1c1b;">
        <p style="margin:0 0 4px;">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($tenant_branding['company_name'] ?? 'ISP System'); ?>. All rights reserved.</p>
        <?php if (!empty($tenant_branding['support_number']) || !empty($tenant_branding['support_email'])): ?>
        <p style="margin:0;">
            <i class="fas fa-headset" style="margin-right:4px;"></i> Support:
            <?php if (!empty($tenant_branding['support_number'])): ?>
                <span style="margin-right:12px;"><i class="fas fa-phone" style="margin-right:3px;"></i><?php echo htmlspecialchars($tenant_branding['support_number']); ?></span>
            <?php endif; ?>
            <?php if (!empty($tenant_branding['support_email'])): ?>
                <span><i class="fas fa-envelope" style="margin-right:3px;"></i><?php echo htmlspecialchars($tenant_branding['support_email']); ?></span>
            <?php endif; ?>
        </p>
        <?php endif; ?>
    </footer>
    </main>
    </div> <!-- End portal-wrapper -->

    <!-- Toast notification container -->
    <div id="customer-toast"></div>

    <!-- Scroll-to-top button -->
    <button id="c-scroll-top" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="Back to top" aria-label="Scroll to top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- Dark neumorphism: after all page inline styles so !important wins -->
    <style id="cust-dark-theme">
    :root{--neu-bg:#141414;--neu-surf:#1c1c1b;--neu-s2:#222221;--neu-border:rgba(255,255,255,.06);
    --neu-card:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px rgba(255,255,255,.06);
    --neu-inset:inset 3px 3px 8px rgba(0,0,0,.55),inset -2px -2px 5px rgba(255,255,255,.05);
    --neu-text:#e2e2e0;--neu-muted:#9a9a95;--neu-input:#1a1a19;}
    body,html{background:var(--neu-bg) !important;color:var(--neu-text) !important;}
    .main-content{background:var(--neu-bg) !important;}
    .content-wrapper{background:transparent !important;}
    /* Status cards */
    .status-card{background:var(--neu-s2) !important;border-color:var(--neu-border) !important;box-shadow:var(--neu-card) !important;}
    .status-card:hover{box-shadow:18px 18px 36px rgba(0,0,0,.6),-8px -8px 22px rgba(255,255,255,.04),0 0 0 1px var(--neu-border) !important;}
    .status-label{color:var(--neu-muted) !important;}.status-value{color:#fff !important;}
    .status-card.active .status-icon{background:rgba(16,185,129,.15) !important;color:#6ee7b7 !important;}
    .status-card.inactive .status-icon{background:rgba(239,68,68,.15) !important;color:#fca5a5 !important;}
    .status-icon.balance{background:rgba(59,130,246,.15) !important;color:#93c5fd !important;}
    .status-icon.expiry{background:rgba(245,158,11,.15) !important;color:#fcd34d !important;}
    .status-icon.speed{background:rgba(139,92,246,.15) !important;color:#c4b5fd !important;}
    /* Section containers */
    .package-section,.actions-section,.history-section,.account-section,.devices-section,.payment-section{background:var(--neu-s2) !important;border-color:var(--neu-border) !important;box-shadow:var(--neu-card) !important;color:var(--neu-text) !important;}
    .section-header{border-bottom-color:var(--neu-border) !important;}
    .section-header h2{color:#fff !important;}
    /* Package */
    .package-card{border-color:var(--neu-border) !important;background:var(--neu-surf) !important;}
    .package-card.current{border-color:var(--primary,#3B6EA5) !important;}
    .package-header{border-bottom-color:var(--neu-border) !important;background:rgba(255,255,255,.03) !important;}
    .package-info h3{color:#fff !important;}.package-info p{color:var(--neu-muted) !important;}
    .price-period{color:rgba(255,255,255,.5) !important;}.feature-item{color:var(--neu-text) !important;}
    /* btn-change */
    .btn-change{background:rgba(255,255,255,.07) !important;border-color:var(--neu-border) !important;color:var(--neu-text) !important;}
    .btn-change:hover{background:rgba(255,255,255,.12) !important;color:#fff !important;}
    /* Action cards */
    .action-card{background:var(--neu-s2) !important;border-color:var(--neu-border) !important;box-shadow:var(--neu-card) !important;}
    .action-card:hover{border-color:var(--primary,#3B6EA5) !important;transform:translateY(-4px) !important;}
    .action-info h3{color:#fff !important;}.action-info p{color:var(--neu-muted) !important;}
    /* Packages grid */
    .pkg-card{background:var(--neu-s2) !important;border-color:var(--neu-border) !important;}
    .pkg-card:hover{border-color:var(--primary,#3B6EA5) !important;}
    .pkg-feature{color:var(--neu-text) !important;}.pkg-card-body{background:var(--neu-s2) !important;}
    /* Tables */
    table{color:var(--neu-text) !important;}
    table thead th{background:rgba(255,255,255,.05) !important;border-color:var(--neu-border) !important;color:var(--neu-muted) !important;}
    table tbody tr{border-color:var(--neu-border) !important;}
    table tbody tr:hover{background:rgba(255,255,255,.04) !important;}
    table tbody td{border-color:var(--neu-border) !important;color:var(--neu-text) !important;vertical-align:middle;}
    /* Forms */
    input[type="text"],input[type="email"],input[type="password"],input[type="number"],input[type="tel"],textarea,select{background:var(--neu-input) !important;border:1px solid rgba(255,255,255,.08) !important;border-radius:9px !important;color:var(--neu-text) !important;box-shadow:var(--neu-inset) !important;}
    input:focus,textarea:focus,select:focus{border-color:var(--primary,#3B6EA5) !important;color:#fff !important;box-shadow:var(--neu-inset),0 0 0 3px rgba(59,110,165,.25) !important;outline:none !important;}
    input::placeholder,textarea::placeholder{color:rgba(255,255,255,.3) !important;}
    label{color:var(--neu-muted) !important;}
    /* Modal boxes */
    .modal-box{background:var(--neu-s2) !important;border:1px solid var(--neu-border) !important;box-shadow:var(--neu-card) !important;color:var(--neu-text) !important;}
    .modal-box h3{color:#fff !important;}
    /* Text */
    h1,h2,h3,h4,h5,h6{color:var(--neu-text) !important;}
    .section-header h2{color:#fff !important;}
    /* Skeleton */
    .skeleton{background:linear-gradient(90deg,#1e1e1d 25%,#2a2a28 50%,#1e1e1d 75%) !important;background-size:600px 100% !important;}
    /* Footer */
    footer{background:var(--neu-surf) !important;border-top-color:var(--neu-border) !important;color:var(--neu-muted) !important;}
    /* Transactions/history items */
    .history-item,.transaction-item,.payment-item{border-color:var(--neu-border) !important;color:var(--neu-text) !important;}
    .history-item:hover,.transaction-item:hover{background:rgba(255,255,255,.04) !important;}
    /* Scrollbar */
    ::-webkit-scrollbar{width:6px;height:6px;}
    ::-webkit-scrollbar-track{background:var(--neu-bg);}
    ::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:3px;}
    ::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.22);}
    </style>

    <script>
    /* ── Scroll-to-top visibility ──────────── */
    (function() {
        const btn = document.getElementById('c-scroll-top');
        if (!btn) return;
        window.addEventListener('scroll', function() {
            btn.classList.toggle('visible', window.scrollY > 300);
        }, { passive: true });
    })();

    /* ── Customer toast helper ─────────────────
       Usage: showCustToast('Message', 'success')
              showCustToast('Error!', 'error')
       ─────────────────────────────────────── */
    window.showCustToast = function(msg, type, duration) {
        type = type || 'info';
        duration = duration || 3500;
        const icons = { success: 'fa-check-circle', error: 'fa-times-circle', info: 'fa-info-circle', warning: 'fa-exclamation-triangle' };
        const container = document.getElementById('customer-toast');
        if (!container) return;
        const el = document.createElement('div');
        el.className = 'c-toast ' + type;
        el.innerHTML = '<i class="fas ' + (icons[type] || 'fa-info-circle') + '"></i><span>' + msg + '</span>';
        el.onclick = function() { el.remove(); };
        container.appendChild(el);
        // Trigger animation
        requestAnimationFrame(function() { el.classList.add('show'); });
        setTimeout(function() {
            el.classList.remove('show');
            setTimeout(function() { el.remove(); }, 350);
        }, duration);
    };

    /* ── Auto-show [data-toast] elements ──── */
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-toast]').forEach(function(el) {
            const msg  = el.dataset.toast;
            const type = el.dataset.toastType || 'info';
            if (msg) showCustToast(msg, type);
            el.remove();
        });
    });
    </script>
</body>
</html>
