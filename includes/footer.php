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
<!-- Dark neumorphism: injected after all page inline styles so !important wins -->
<style id="dark-neu-theme">
:root{--neu-bg:#141414;--neu-surf:#1c1c1b;--neu-s2:#222221;--neu-border:rgba(255,255,255,.06);
--neu-card:14px 14px 28px rgba(0,0,0,.5),-7px -7px 18px rgba(255,255,255,.035),0 0 0 1px rgba(255,255,255,.06);
--neu-inset:inset 3px 3px 8px rgba(0,0,0,.55),inset -2px -2px 5px rgba(255,255,255,.05);
--neu-text:#e2e2e0;--neu-muted:#9a9a95;--neu-input:#1a1a19;}

/* ── Page & layout ─────────────────────────────── */
body,html{background:var(--neu-bg) !important;color:var(--neu-text) !important;}
.main-layout,#main-content,.content-area,.page-wrapper{background:var(--neu-bg) !important;}
.main-content-wrapper{background:var(--neu-bg) !important;color:var(--neu-text) !important;}
.main-layout > footer{background:var(--neu-surf) !important;border-top:1px solid var(--neu-border) !important;color:var(--neu-muted) !important;}
.text-muted{color:var(--neu-muted) !important;}
h1,h2,h3,h4,h5,h6,.h1,.h2,.h3,.h4,.h5,.h6{color:var(--neu-text) !important;}
.text-dark{color:var(--neu-text) !important;}.text-secondary{color:var(--neu-muted) !important;}
p{color:var(--neu-muted);}
hr{border-color:var(--neu-border) !important;}

/* ── Navbar/topbar ─────────────────────────────── */
.navbar,nav.navbar{background:var(--neu-surf) !important;border-bottom:1px solid var(--neu-border) !important;box-shadow:0 2px 16px rgba(0,0,0,.4) !important;}
.navbar-brand,.navbar .nav-link,.navbar-text{color:var(--neu-text) !important;}

/* ── Cards ─────────────────────────────────────── */
.card,.metric-card,.stat-card-premium,.dashboard-card,.stat-card,.info-card,.chart-card,.activity-card,.payment-card,.table-card{background:var(--neu-s2) !important;border:1px solid var(--neu-border) !important;border-radius:16px !important;box-shadow:var(--neu-card) !important;color:var(--neu-text) !important;}
.card-header,.card-footer{background:rgba(255,255,255,.03) !important;border-color:var(--neu-border) !important;color:var(--neu-text) !important;}
.card-title,.card-header h5,.card-header h6{color:#fff !important;}
.metric-card:hover,.stat-card-premium:hover{transform:translateY(-3px) !important;box-shadow:18px 18px 36px rgba(0,0,0,.6),-8px -8px 22px rgba(255,255,255,.04),0 0 0 1px var(--neu-border) !important;}
.metric-icon,.stat-icon{background:rgba(255,255,255,.06) !important;border-radius:12px !important;}
.metric-value,.stat-value,.metric-card h3,.metric-card .display-6{color:#fff !important;}
.metric-label,.stat-label,.metric-card p,.metric-card .text-muted{color:var(--neu-muted) !important;}

/* ── Inline page backgrounds ───────────────────── */
/* These override the per-page <style> blocks with hardcoded colors */
[style*="background:#F3F4F6"],[style*="background: #F3F4F6"],
[style*="background:#fff"],[style*="background: #fff"],
[style*="background:#ffffff"],[style*="background: #ffffff"],
[style*="background:#f8f9fa"],[style*="background: #f8f9fa"],
[style*="background:#f1f5f9"],[style*="background: #f1f5f9"],
[style*="background-color:#fff"],[style*="background-color: #fff"],
[style*="background-color:white"],[style*="background-color: white"]{
  /* Can't override inline style attribute with external CSS — handled via JS below */
}

/* ── Tables ─────────────────────────────────────── */
.table,table{color:var(--neu-text) !important;}
.table thead th,table thead th{background:rgba(255,255,255,.05) !important;border-color:var(--neu-border) !important;color:var(--neu-muted) !important;font-size:11px;text-transform:uppercase;letter-spacing:.06em;}
.table tbody tr,table tbody tr{border-color:var(--neu-border) !important;}
.table tbody tr:hover,table tbody tr:hover{background:rgba(255,255,255,.04) !important;}
.table tbody td,table tbody td{border-color:var(--neu-border) !important;color:var(--neu-text) !important;vertical-align:middle;}
.table-striped>tbody>tr:nth-child(odd){background:rgba(255,255,255,.02) !important;}
.table-hover tbody tr:hover{background:rgba(255,255,255,.04) !important;}
.dataTables_wrapper .dataTables_length,.dataTables_wrapper .dataTables_filter,.dataTables_wrapper .dataTables_info,.dataTables_wrapper .dataTables_paginate{color:var(--neu-muted) !important;}
.dataTables_wrapper .dataTables_paginate .paginate_button{color:var(--neu-muted) !important;border-radius:6px !important;}
.dataTables_wrapper .dataTables_paginate .paginate_button.current,.dataTables_wrapper .dataTables_paginate .paginate_button:hover{background:var(--primary-color,#3B6EA5) !important;color:#fff !important;border-color:transparent !important;}

/* ── Forms ──────────────────────────────────────── */
.form-control,.form-select,input[type="text"],input[type="email"],input[type="password"],input[type="number"],input[type="tel"],input[type="url"],input[type="date"],input[type="time"],input[type="search"],textarea,select{background:var(--neu-input) !important;border:1px solid rgba(255,255,255,.08) !important;border-radius:9px !important;color:var(--neu-text) !important;box-shadow:var(--neu-inset) !important;}
.form-control:focus,.form-select:focus,input:focus,textarea:focus,select:focus{background:var(--neu-input) !important;border-color:var(--primary-color,#3B6EA5) !important;color:#fff !important;box-shadow:var(--neu-inset),0 0 0 3px rgba(59,110,165,.25) !important;outline:none !important;}
.form-control::placeholder,input::placeholder,textarea::placeholder{color:rgba(255,255,255,.3) !important;}
.form-label,label{color:var(--neu-muted) !important;}
.input-group-text{background:rgba(255,255,255,.06) !important;border:1px solid rgba(255,255,255,.08) !important;color:var(--neu-muted) !important;}
.form-check-input{background-color:var(--neu-input) !important;border-color:rgba(255,255,255,.15) !important;}
.form-check-input:checked{background-color:var(--primary-color,#3B6EA5) !important;border-color:var(--primary-color,#3B6EA5) !important;}
.form-check-label{color:var(--neu-text) !important;}
.form-text{color:var(--neu-muted) !important;}

/* ── Buttons ─────────────────────────────────────── */
.btn-secondary,.btn-outline-secondary{background:rgba(255,255,255,.08) !important;border:1px solid rgba(255,255,255,.12) !important;color:var(--neu-text) !important;}
.btn-secondary:hover,.btn-outline-secondary:hover{background:rgba(255,255,255,.14) !important;color:#fff !important;}
.btn-light,.btn-outline-light{background:rgba(255,255,255,.08) !important;border-color:rgba(255,255,255,.12) !important;color:var(--neu-text) !important;}
.btn-light:hover{background:rgba(255,255,255,.15) !important;color:#fff !important;}
.btn-outline-primary{border-color:var(--primary-color,#3B6EA5) !important;color:var(--primary-color,#3B6EA5) !important;}
.btn-outline-primary:hover{background:var(--primary-color,#3B6EA5) !important;color:#fff !important;}

/* ── Modals ──────────────────────────────────────── */
.modal-content{background:var(--neu-s2) !important;border:1px solid var(--neu-border) !important;border-radius:18px !important;box-shadow:var(--neu-card) !important;color:var(--neu-text) !important;}
.modal-header{border-bottom:1px solid var(--neu-border) !important;background:rgba(255,255,255,.03) !important;}
.modal-footer{border-top:1px solid var(--neu-border) !important;background:rgba(255,255,255,.03) !important;}
.modal-title{color:#fff !important;}
.btn-close{filter:invert(1) grayscale(1) !important;}

/* ── Dropdowns ───────────────────────────────────── */
.dropdown-menu{background:var(--neu-surf) !important;border:1px solid var(--neu-border) !important;border-radius:12px !important;box-shadow:var(--neu-card) !important;}
.dropdown-item{color:var(--neu-text) !important;border-radius:8px;margin:2px 6px;width:calc(100% - 12px);}
.dropdown-item:hover,.dropdown-item:focus{background:rgba(255,255,255,.08) !important;color:#fff !important;}
.dropdown-divider{border-color:var(--neu-border) !important;}

/* ── Alerts ──────────────────────────────────────── */
.alert-info{background:rgba(59,130,246,.12) !important;border-color:rgba(59,130,246,.25) !important;color:#93c5fd !important;}
.alert-success{background:rgba(16,185,129,.12) !important;border-color:rgba(16,185,129,.25) !important;color:#6ee7b7 !important;}
.alert-warning{background:rgba(245,158,11,.12) !important;border-color:rgba(245,158,11,.25) !important;color:#fcd34d !important;}
.alert-danger{background:rgba(239,68,68,.12) !important;border-color:rgba(239,68,68,.25) !important;color:#fca5a5 !important;}

/* ── Nav tabs ────────────────────────────────────── */
.nav-tabs{border-bottom:1px solid var(--neu-border) !important;}
.nav-tabs .nav-link{color:var(--neu-muted) !important;border:none !important;border-bottom:2px solid transparent !important;}
.nav-tabs .nav-link:hover{color:var(--neu-text) !important;background:rgba(255,255,255,.04) !important;}
.nav-tabs .nav-link.active{background:transparent !important;color:var(--primary-color,#3B6EA5) !important;border-bottom-color:var(--primary-color,#3B6EA5) !important;}
.nav-pills .nav-link{color:var(--neu-muted) !important;border-radius:8px !important;}
.nav-pills .nav-link.active{background:var(--primary-color,#3B6EA5) !important;color:#fff !important;}

/* ── Badges ──────────────────────────────────────── */
.badge.bg-light{background:rgba(255,255,255,.1) !important;color:var(--neu-text) !important;}
.badge.bg-secondary{background:rgba(255,255,255,.12) !important;color:var(--neu-text) !important;}

/* ── Status badges ───────────────────────────────── */
.status-badge.active{background:rgba(16,185,129,.15) !important;color:#6ee7b7 !important;border:1px solid rgba(16,185,129,.3) !important;}
.status-badge.inactive{background:rgba(107,114,128,.15) !important;color:#9ca3af !important;border:1px solid rgba(107,114,128,.3) !important;}
.status-badge.suspended{background:rgba(239,68,68,.15) !important;color:#fca5a5 !important;border:1px solid rgba(239,68,68,.3) !important;}
.status-badge.expired{background:rgba(245,158,11,.15) !important;color:#fcd34d !important;border:1px solid rgba(245,158,11,.3) !important;}

/* ── List groups ─────────────────────────────────── */
.list-group-item{background:var(--neu-s2) !important;border-color:var(--neu-border) !important;color:var(--neu-text) !important;}
.list-group-item:hover{background:rgba(255,255,255,.05) !important;}
.list-group-item.active{background:var(--primary-color,#3B6EA5) !important;border-color:var(--primary-color,#3B6EA5) !important;}

/* ── Progress ────────────────────────────────────── */
.progress{background:rgba(255,255,255,.08) !important;border-radius:10px !important;}

/* ── Pagination ──────────────────────────────────── */
.page-link{background:var(--neu-s2) !important;border-color:var(--neu-border) !important;color:var(--neu-muted) !important;}
.page-link:hover{background:rgba(255,255,255,.08) !important;color:var(--neu-text) !important;}
.page-item.active .page-link{background:var(--primary-color,#3B6EA5) !important;border-color:var(--primary-color,#3B6EA5) !important;color:#fff !important;}
.page-item.disabled .page-link{background:rgba(255,255,255,.03) !important;color:rgba(255,255,255,.2) !important;}

/* ── Settings page ───────────────────────────────── */
.set-card{background:var(--neu-s2) !important;border:1px solid var(--neu-border) !important;box-shadow:var(--neu-card) !important;color:var(--neu-text) !important;}
.set-section-title{color:var(--neu-muted) !important;border-bottom-color:var(--neu-border) !important;}
.set-input,.set-select{background:var(--neu-input) !important;border-color:rgba(255,255,255,.08) !important;color:var(--neu-text) !important;}
.gw-card{background:var(--neu-surf) !important;border:1px solid var(--neu-border) !important;box-shadow:var(--neu-inset) !important;}

/* ── Dashboard specific ──────────────────────────── */
.dashboard-header,.dashboard-title{color:var(--neu-text) !important;}
.dashboard-subtitle,.breadcrumb{color:var(--neu-muted) !important;}
.breadcrumb a{color:var(--primary-color,#3B6EA5) !important;}
.section-title{color:var(--neu-text) !important;}
.dashboard-container{background:transparent !important;}

/* ── Client/billing pages ────────────────────────── */
.client-card,.billing-card,.package-row,.client-row{background:var(--neu-s2) !important;border-color:var(--neu-border) !important;color:var(--neu-text) !important;}
.client-name,.billing-amount{color:#fff !important;}

/* ── Scrollbar ───────────────────────────────────── */
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:var(--neu-bg);}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:3px;}
::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.22);}
</style>

<script>
/* Dark theme: fix inline style="background:..." attributes on page elements */
(function(){
  var lightBgs = ['#f3f4f6','#ffffff','#fff','#f8f9fa','#f1f5f9','#f9fafb','#f0f4f8','#fafafa','white'];
  var lightClrs = ['#111827','#1e293b','#374151','#1f2937','#0f172a','#000'];
  document.querySelectorAll('[style]').forEach(function(el){
    var s = el.getAttribute('style') || '';
    var low = s.toLowerCase();
    var changed = false;
    lightBgs.forEach(function(c){
      if(low.includes('background:'+c) || low.includes('background: '+c) || low.includes('background-color:'+c) || low.includes('background-color: '+c)){
        s = s.replace(new RegExp('background(?:-color)?\\s*:\\s*'+c.replace('#','#?'),'gi'),'background:#141414');
        changed = true;
      }
    });
    lightClrs.forEach(function(c){
      if(low.includes('color:'+c) || low.includes('color: '+c)){
        s = s.replace(new RegExp('(?<!background-)color\\s*:\\s*'+c.replace('#','#?'),'gi'),'color:#e2e2e0');
        changed = true;
      }
    });
    if(changed) el.setAttribute('style',s);
  });
})();
</script>

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