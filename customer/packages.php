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
        
    <div id="emptyState" style="text-align: center; padding: 40px; display: none; background: white; border-radius: 16px;">
        <i class="fas fa-inbox" style="font-size: 48px; color: var(--gray-300); margin-bottom: 16px;"></i>
        <p style="color: var(--gray-500);">No packages available in this category.</p>
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
.packages-container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    text-align: center;
    margin-bottom: 30px;
}
.page-header h1 { font-size: 28px; font-weight: 700; color: var(--gray-900); }

.current-package-notice {
    background: #EFF6FF;
    border: 1px solid #BFDBFE;
    color: #1E40AF;
    padding: 16px;
    border-radius: 10px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}

/* Toggle */
.toggle-container {
    display: flex;
    justify-content: center;
    margin-bottom: 24px;
}
.toggle-wrapper {
    background: var(--gray-200);
    padding: 4px;
    border-radius: 50px;
    display: flex;
}
.toggle-btn {
    padding: 8px 24px;
    border-radius: 40px;
    border: none;
    background: transparent;
    color: var(--gray-600);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.toggle-btn.active {
    background: white;
    color: var(--primary);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* List */
.packages-list {
    background: white;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}
.package-item {
    display: flex;
    align-items: center;
    padding: 24px;
    border-bottom: 1px solid var(--gray-100);
    gap: 20px;
}
.package-item:last-child { border-bottom: none; }

.pkg-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: var(--gray-100);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}
.pkg-icon.free { background: #EFF6FF; color: #3B82F6; }

.pkg-details {
    flex: 1;
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
    align-items: center;
    gap: 16px;
}
.pkg-name { font-weight: 700; margin-bottom: 4px; }
.pkg-desc { font-size: 13px; color: var(--gray-500); }
.pkg-stat { font-size: 14px; color: var(--gray-700); }
.pkg-stat span { display: block; font-size: 11px; text-transform: uppercase; color: var(--gray-400); font-weight: 600; }
.pkg-price { font-weight: 700; font-size: 16px; color: var(--primary); text-align: right; }
.pkg-price.free { color: #3B82F6; }

.btn-select {
    padding: 8px 20px;
    border-radius: 8px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    background: var(--primary);
    color: white;
}
.btn-select.free { background: #3B82F6; }
.btn-select:disabled { background: var(--gray-200); color: var(--gray-500); cursor: not-allowed; }

/* Modal */
.modal {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center;
}
.modal.show { display: flex; }
.modal-content { background: white; border-radius: 16px; width: 90%; max-width: 500px; }
.modal-header { padding: 20px; border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; }
.modal-body { padding: 24px; } 
.selected-package-info { background: var(--gray-50); padding: 16px; border-radius: 8px; text-align: center; margin: 16px 0; }
.selected-price { font-size: 20px; font-weight: 700; color: var(--primary); }
.modal-footer { padding: 20px; border-top: 1px solid var(--gray-200); display: flex; justify-content: flex-end; gap: 12px; }
.btn-secondary { padding: 10px 20px; background: white; border: 1px solid var(--gray-300); border-radius: 8px; cursor: pointer; }
.btn-primary { padding: 10px 20px; background: var(--primary); color: white; border: none; border-radius: 8px; cursor: pointer; }

@media (max-width: 768px) {
    .pkg-details { grid-template-columns: 1fr; gap: 8px; margin-top: 12px; }
    .package-item { flex-direction: column; align-items: flex-start; }
    .pkg-stat { display: flex; justify-content: space-between; border-bottom: 1px dashed var(--gray-100); padding-bottom: 4px; }
}
</style>

<script>
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
            const res = await fetch('/fortunett_technologies_/api/customer/activate_free_package.php', { method: 'POST', body: formData });
            const data = await res.json();
            
            if (data.success) {
                window.location.href = 'dashboard.php?msg=activated';
            } else {
                alert(data.message);
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        } catch (e) { alert('Error activating'); btn.disabled = false; btn.innerHTML = originalText; }
    } else {
        // Paid
        window.location.href = `payment.php?package_id=${selectedPkg.id}`;
    }
}
</script>

<?php include 'includes/footer.php'; ?>
