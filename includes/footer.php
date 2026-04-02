<?php if (isLoggedIn()): ?>
    <footer class="text-center py-4 text-muted small">
        <div class="container">
            <p class="mb-1">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($tSettings['company_name'] ?? $tenant['company_name'] ?? 'FN Tech'); ?>. All rights reserved.</p>
            <?php if (!empty($tSettings['support_number']) || !empty($tSettings['support_email'])): ?>
                <p class="mb-0">
                    <i class="fas fa-headset me-1"></i> Support: 
                    <?php if (!empty($tSettings['support_number'])): ?>
                        <span class="me-3"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($tSettings['support_number']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($tSettings['support_email'])): ?>
                        <span><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($tSettings['support_email']); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </footer>
</div> <!-- End main-layout -->
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Scroll-to-top button -->
<button id="scrollTopBtn" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="Back to top">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- Toast notification container -->
<div id="toast-container"></div>

<script>
/* ── Scroll-to-top visibility ────────────────── */
window.addEventListener('scroll', function(){
    const btn = document.getElementById('scrollTopBtn');
    if (btn) btn.classList.toggle('visible', window.scrollY > 320);
}, { passive: true });

/* ── Global toast helper  ────────────────────────
   Usage:  showToast('Saved!', 'success')
           showToast('Error occurred', 'error')
           showToast('Processing…', 'info')
   ─────────────────────────────────────────────── */
window.showToast = function(msg, type = 'info', duration = 3500) {
    const icons = { success: 'fa-check-circle', error: 'fa-times-circle', info: 'fa-info-circle', warning: 'fa-exclamation-triangle' };
    const container = document.getElementById('toast-container');
    if (!container) return;
    const el = document.createElement('div');
    el.className = 'toast-msg ' + type;
    el.innerHTML = '<i class="fas ' + (icons[type] || 'fa-info-circle') + '"></i><span>' + msg + '</span>';
    el.onclick = () => el.remove();
    container.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateX(30px)'; el.style.transition = 'all .3s'; setTimeout(() => el.remove(), 300); }, duration);
};

/* ── Auto-show flash messages as toasts ──────── */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-toast]').forEach(function(el) {
        const msg  = el.dataset.toast;
        const type = el.dataset.toastType || 'info';
        if (msg) showToast(msg, type);
        el.remove();
    });
});
</script>
</body>
</html>