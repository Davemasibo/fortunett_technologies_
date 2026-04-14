<?php
require_once __DIR__ . '/includes/auth.php';
$customer = requireCustomerLogin();

// Get all active packages for this tenant
$stmt = $pdo->prepare("SELECT * FROM packages WHERE status = 'active' AND tenant_id = ? ORDER BY price ASC");
$stmt->execute([$customer['tenant_id']]);
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<div class="packages-container">
    <div class="page-header">
        <h1><i class="fas fa-box"></i> Available Packages</h1>
        <p>Choose the perfect internet plan for your needs</p>
    </div>
    
    <?php if ($customer['package_id']): ?>
    <div class="current-package-notice">
        <i class="fas fa-info-circle"></i>
        <span>You are currently on the <strong><?php 
            $currentPkg = array_filter($packages, fn($p) => $p['id'] == $customer['package_id']);
            echo $currentPkg ? reset($currentPkg)['name'] : 'Unknown';
        ?></strong> package</span>
    </div>
    <?php endif; ?>

    <!-- Toggle Switch -->
    <div class="toggle-container">
        <div class="toggle-wrapper">
            <button class="toggle-btn" id="btnFree" onclick="setMode('free')">Free Trial</button>
            <button class="toggle-btn active" id="btnPaid" onclick="setMode('paid')">Premium Packages</button>
        </div>
    </div>
    
    <div class="packages-list" id="packagesList">
        <!-- JS will populate -->
    </div>
        
    <div id="emptyState" style="text-align:center;padding:48px 24px;display:none;background:#222221;border-radius:14px;border:1px solid rgba(255,255,255,.07);">
        <i class="fas fa-inbox" style="font-size:44px;color:rgba(255,255,255,.2);margin-bottom:14px;display:block;"></i>
        <p style="color:rgba(255,255,255,.35);font-size:14px;">No packages available in this category.</p>
    </div>
</div>

<!-- Package Selection Modal -->
<div id="packageModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Confirm Package Selection</h2>
            <button class="modal-close" onclick="closePackageModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <p>You are about to switch to:</p>
            <div class="selected-package-info">
                <h3 id="selectedPackageName"></h3>
                <div class="selected-price">
                    <span id="selectedPackagePrice"></span>
                </div>
            </div>
            <p class="modal-note" id="paymentNote">
                <i class="fas fa-info-circle"></i>
                The change will take effect immediately. You will need to make a payment to activate the new package.
            </p>
            <p class="modal-note" id="freeNote" style="display: none;">
                <i class="fas fa-check-circle"></i>
                This is a free trial package. You will get connected instantly without any payment.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="closePackageModal()">Cancel</button>
            <button class="btn-primary" id="confirmButton" onclick="confirmPackageChange()">
                <i class="fas fa-check"></i> Confirm & Pay
            </button>
        </div>
    </div>
</div>

<style>
/* ── Packages page — dark neumorphic ─────────── */
.packages-container { max-width: 1000px; margin: 0 auto; }

.page-header { text-align: center; margin-bottom: 28px; }
.page-header h1 { font-size: 26px; font-weight: 700; color: #e2e2e0; display:flex; align-items:center; justify-content:center; gap:10px; }
.page-header p  { font-size:14px; color:rgba(255,255,255,.4); margin-top:4px; }

.current-package-notice {
    background: rgba(59,130,246,.1);
    border: 1px solid rgba(59,130,246,.25);
    color: #93c5fd;
    padding: 13px 18px;
    border-radius: 10px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
}

/* Toggle */
.toggle-container { display:flex; justify-content:center; margin-bottom:24px; }
.toggle-wrapper {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.08);
    padding: 4px;
    border-radius: 50px;
    display: flex;
    gap: 2px;
}
.toggle-btn {
    padding: 8px 26px;
    border-radius: 40px;
    border: none;
    background: transparent;
    color: rgba(255,255,255,.45);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s;
    font-family: inherit;
}
.toggle-btn.active {
    background: #222221;
    color: #e2e2e0;
    box-shadow: 4px 4px 10px rgba(0,0,0,.4), -2px -2px 6px rgba(255,255,255,.04);
}

/* Package list */
.packages-list {
    background: #222221;
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 8px 8px 20px rgba(0,0,0,.4), -4px -4px 10px rgba(255,255,255,.03);
}
.package-item {
    display: flex;
    align-items: center;
    padding: 20px 22px;
    border-bottom: 1px solid rgba(255,255,255,.05);
    gap: 18px;
    transition: background .15s;
}
.package-item:last-child { border-bottom: none; }
.package-item:hover { background: rgba(255,255,255,.025); }

.pkg-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    background: rgba(59,130,246,.15);
    color: #93c5fd;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.pkg-icon.free { background: rgba(16,185,129,.15); color: #6ee7b7; }

.pkg-details {
    flex: 1;
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
    align-items: center;
    gap: 12px;
    min-width: 0;
}
.pkg-name { font-weight: 700; color: #e2e2e0; font-size: 14px; margin-bottom: 2px; }
.pkg-desc { font-size: 12px; color: rgba(255,255,255,.35); }
.pkg-stat { font-size: 13px; color: #d4d4d2; }
.pkg-stat span { display: block; font-size: 10px; text-transform: uppercase; color: rgba(255,255,255,.3); font-weight: 600; letter-spacing:.04em; margin-bottom:2px; }
.pkg-price { font-weight: 700; font-size: 15px; color: #93c5fd; text-align: right; white-space:nowrap; }
.pkg-price.free { color: #6ee7b7; }

.btn-select {
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    border: none;
    cursor: pointer;
    background: linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary,#2C5282) 100%);
    color: #fff;
    white-space: nowrap;
    flex-shrink: 0;
    transition: opacity .2s, transform .15s;
}
.btn-select:hover { opacity: .85; transform: translateY(-1px); }
.btn-select.free { background: linear-gradient(135deg, #059669, #10b981); }
.btn-select:disabled { background: rgba(255,255,255,.08); color: rgba(255,255,255,.3); cursor: not-allowed; transform: none; }

/* Confirm modal */
.modal {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.65); z-index: 2000;
    align-items: center; justify-content: center;
    padding: 20px;
}
.modal.show { display: flex; }
.modal-content {
    background: #222221;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 16px;
    width: 100%;
    max-width: 460px;
    box-shadow: 0 24px 64px rgba(0,0,0,.6);
    animation: fadeSlideUp .25s cubic-bezier(.22,1,.36,1);
}
.modal-header {
    padding: 18px 22px;
    border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h2 { font-size: 16px; font-weight: 700; color: #e2e2e0; margin: 0; }
.modal-close {
    width: 28px; height: 28px;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 6px;
    color: rgba(255,255,255,.5);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    font-size: 13px;
}
.modal-close:hover { background: rgba(255,255,255,.13); color: #fff; }
.modal-body  { padding: 22px; }
.modal-body p { color: rgba(255,255,255,.55); font-size: 13px; margin-bottom: 14px; }
.selected-package-info {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.08);
    padding: 16px;
    border-radius: 10px;
    text-align: center;
    margin-bottom: 16px;
}
.selected-package-info h3 { color: #e2e2e0; font-size: 17px; margin-bottom: 6px; }
.selected-price { font-size: 22px; font-weight: 800; color: #93c5fd; }
.modal-note {
    font-size: 12px;
    color: rgba(255,255,255,.4);
    padding: 10px 14px;
    background: rgba(255,255,255,.04);
    border-radius: 8px;
    display: flex; align-items: flex-start; gap: 8px;
}
.modal-note i { margin-top: 1px; flex-shrink: 0; }
.modal-footer {
    padding: 16px 22px;
    border-top: 1px solid rgba(255,255,255,.07);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.btn-secondary {
    padding: 9px 20px;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 8px;
    color: rgba(255,255,255,.7);
    font-size: 14px;
    cursor: pointer;
    font-family: inherit;
}
.btn-secondary:hover { background: rgba(255,255,255,.12); color: #fff; }
.btn-primary {
    padding: 9px 20px;
    background: linear-gradient(135deg, var(--primary-dark,#1e3a5f) 0%, var(--primary,#2C5282) 100%);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    display: inline-flex; align-items: center; gap: 6px;
    transition: opacity .2s;
}
.btn-primary:hover { opacity: .85; }

/* Mobile */
@media (max-width: 720px) {
    .pkg-details { grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
    .package-item { flex-wrap: wrap; }
    .pkg-price { text-align: left; }
    .btn-select { width: 100%; justify-content: center; margin-top: 4px; padding: 10px; }
}
@media (max-width: 480px) {
    .pkg-details { grid-template-columns: 1fr; }
    .toggle-btn { padding: 8px 16px; font-size: 12px; }
}
</style>

<script>
const _base = (location.hostname === 'localhost' || /^\d+\.\d+\./.test(location.hostname))
    ? '/fortunett_technologies_' : '';

const packages = <?php echo json_encode($packages); ?>;
const currentPkgId = <?php echo $customer['package_id'] ?? 'null'; ?>;
let selectedPkg = null;
let currentMode = 'paid';

document.addEventListener('DOMContentLoaded', () => {
    setMode('paid');
});

function setMode(mode) {
    currentMode = mode;
    document.getElementById('btnFree').className = `toggle-btn ${mode === 'free' ? 'active' : ''}`;
    document.getElementById('btnPaid').className = `toggle-btn ${mode === 'paid' ? 'active' : ''}`;
    render();
}

function render() {
    const list = document.getElementById('packagesList');
    const empty = document.getElementById('emptyState');
    list.innerHTML = '';
    
    const filtered = packages.filter(p => {
        const price = parseFloat(p.price);
        return currentMode === 'free' ? price === 0 : price > 0;
    });

    if (filtered.length === 0) {
        list.style.display = 'none';
        empty.style.display = 'block';
        return;
    }
    
    list.style.display = 'block';
    empty.style.display = 'none';
    
    filtered.forEach(pkg => {
        const isFree = parseFloat(pkg.price) === 0;
        const isCurrent = pkg.id == currentPkgId;
        
        const validity = (pkg.validity_value && pkg.validity_unit) 
            ? `${pkg.validity_value} ${pkg.validity_unit}`
            : (pkg.duration || '30 days');
        
        const item = document.createElement('div');
        item.className = 'package-item';
        item.innerHTML = `
            <div class="pkg-icon ${isFree ? 'free' : ''}">
                <i class="fas fa-${pkg.connection_type === 'hotspot' ? 'wifi' : 'network-wired'}"></i>
            </div>
            <div class="pkg-details">
                <div>
                    <div class="pkg-name">${pkg.name} ${isCurrent ? '<span style="font-size:10px; background:#2C5282; color:white; padding:2px 6px; border-radius:10px;">CURRENT</span>' : ''}</div>
                    <div class="pkg-desc">${pkg.description || ''}</div>
                </div>
                <div class="pkg-stat"><span>Speed</span>${pkg.download_speed}/${pkg.upload_speed} Mbps</div>
                <div class="pkg-stat"><span>Validity</span>${validity}</div>
                <div class="pkg-stat"><span>Devices</span>Max ${pkg.device_limit || 1}</div>
                <div class="pkg-price ${isFree ? 'free' : ''}">
                    ${isFree ? 'Free Trial' : 'KES ' + parseFloat(pkg.price).toLocaleString()}
                </div>
            </div>
            <button class="btn-select ${isFree ? 'free' : ''}" 
                onclick='openModal(${JSON.stringify(pkg).replace(/'/g, "&#39;")})'
                ${isCurrent ? 'disabled' : ''}>
                ${isFree ? 'Get Free' : 'Select'}
            </button>
        `;
        list.appendChild(item);
    });
}

function openModal(pkg) {
    selectedPkg = pkg;
    const isFree = parseFloat(pkg.price) === 0;
    
    document.getElementById('selectedPackageName').textContent = pkg.name;
    document.getElementById('selectedPackagePrice').textContent = isFree ? 'Free' : `KES ${parseFloat(pkg.price).toLocaleString()}`;
    
    const btn = document.getElementById('confirmButton');
    if (isFree) {
        document.getElementById('paymentNote').style.display = 'none';
        document.getElementById('freeNote').style.display = 'block';
        btn.innerHTML = '<i class="fas fa-bolt"></i> Get Connected';
        btn.style.background = '#3B82F6';
    } else {
        document.getElementById('paymentNote').style.display = 'block';
        document.getElementById('freeNote').style.display = 'none';
        btn.innerHTML = '<i class="fas fa-check"></i> Confirm & Pay';
        btn.style.background = '#2C5282';
    }
    
    document.getElementById('packageModal').classList.add('show');
}

function closePackageModal() {
    document.getElementById('packageModal').classList.remove('show');
    selectedPkg = null;
}

async function confirmPackageChange() {
    if (!selectedPkg) return;
    
    if (parseFloat(selectedPkg.price) <= 0) {
        const btn = document.getElementById('confirmButton');
        const originalText = btn.innerHTML;
        btn.innerHTML = 'Activating...';
        btn.disabled = true;
        
        // Free activation
        try {
            const formData = new FormData();
            formData.append('package_id', selectedPkg.id);
            const res = await fetch(_base + '/api/customer/activate_free_package.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.success) {
                window.location.href = 'dashboard.php?msg=activated';
            } else {
                showCustToast(data.message || 'Activation failed.', 'error');
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        } catch (e) { showCustToast('Connection error. Please try again.', 'error'); btn.disabled = false; btn.innerHTML = originalText; }
    } else {
        // Paid
        window.location.href = `payment.php?package_id=${selectedPkg.id}`;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
