    </div> <!-- End content-wrapper -->

    <footer class="text-center py-4 text-muted small mt-auto border-top">
        <div class="container">
            <p class="mb-1">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($tenant_branding['company_name'] ?? 'ISP System'); ?>. All rights reserved.</p>
            <?php if (!empty($tenant_branding['support_number']) || !empty($tenant_branding['support_email'])): ?>
                <p class="mb-0">
                    <i class="fas fa-headset me-1"></i> Support: 
                    <?php if (!empty($tenant_branding['support_number'])): ?>
                        <span class="me-3"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($tenant_branding['support_number']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($tenant_branding['support_email'])): ?>
                        <span><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($tenant_branding['support_email']); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </footer>
    </main>
    </div> <!-- End portal-wrapper -->

    <!-- Toast notification container -->
    <div id="customer-toast"></div>

    <!-- Scroll-to-top button -->
    <button id="c-scroll-top" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="Back to top" aria-label="Scroll to top">
        <i class="fas fa-arrow-up"></i>
    </button>

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
