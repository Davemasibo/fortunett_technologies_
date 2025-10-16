<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

include 'includes/header.php';
include 'includes/sidebar.php';

// Load available packages (for the modal dropdown)
$packages = $pdo->query("SELECT id, name, type, price FROM packages ORDER BY created_at DESC")->fetchAll();
?>

<div class="main-content-wrapper">
    <div class="container-fluid py-4">
        <!-- ...existing header/card... -->
        <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-0">Users</h2>
                    <div class="text-muted small">All users including Hotspot and PPPoE</div>
                </div>
                <div class="d-flex gap-2">
                    <button id="importBtn" class="btn btn-outline-secondary">Import Users</button>
                    <button id="createBtn" class="btn btn-warning" onclick="openCreateModal()"><i class="fas fa-plus me-1"></i>Create User</button>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="mb-3">
            <div class="btn-group" role="group">
                <button class="tab-btn btn btn-link text-decoration-none active" data-type="all">All <span id="countAll" class="badge bg-light text-dark ms-2">0</span></button>
                <button class="tab-btn btn btn-link text-decoration-none" data-type="hotspot">Hotspot <span id="countHotspot" class="badge bg-light text-dark ms-2">0</span></button>
                <button class="tab-btn btn btn-link text-decoration-none" data-type="pppoe">PPPoE <span id="countPppoe" class="badge bg-light text-dark ms-2">0</span></button>
                <button class="tab-btn btn btn-link text-decoration-none" data-type="paused">Paused <span id="countPaused" class="badge bg-light text-dark ms-2">0</span></button>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="form-check">
                    <input id="selectAll" class="form-check-input" type="checkbox">
                </div>
                <div class="ms-auto d-flex gap-2">
                    <input id="searchInput" class="form-control form-control-sm" placeholder="Search" style="min-width:260px;">
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th></th>
                            <th>Username</th>
                            <th>IP/MAC</th>
                            <th>Session Start</th>
                            <th>Session End</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersTbody">
                        <!-- rows populated by JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap Modal (Create / Edit) -->
<div class="modal fade" id="clientModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="userForm" class="needs-validation" novalidate onsubmit="event.preventDefault(); saveUser();">
        <div class="modal-header">
          <h5 class="modal-title" id="modalTitle">Create User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="clientId">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Username</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><span style="color:#dc3545">*</span> Phone</label>
                    <input type="text" id="phone" name="phone" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><span style="color:#dc3545">*</span> Password</label>
                    <input type="text" id="password" name="password" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Package</label>
                    <select id="package_id" class="form-select">
                        <option value="">-- None --</option>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?php echo (int)$pkg['id']; ?>" data-type="<?php echo htmlspecialchars($pkg['type'] ?? ''); ?>">
                                <?php echo htmlspecialchars($pkg['name'] . ' — KES ' . number_format($pkg['price'],2)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Packages come from Packages tab and are what clients can purchase.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Type</label>
                    <select id="type" name="type" class="form-select">
                        <option value="hotspot">Hotspot</option>
                        <option value="pppoe">PPPoE</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Address / Notes</label>
                    <input type="text" id="address" name="address" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="active">Active</option>
                        <option value="paused">Paused</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><span style="color:#dc3545">*</span> Expiry Date (leave empty for no expiry)</label>
                    <input type="datetime-local" id="expiry" name="expiry_date" class="form-control">
                </div>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const apiBase = 'api/clients.php';
let currentType = 'all';
let usersCache = [];
let clientModalInstance;

// packages data for client-side filtering (built from server-side PHP values)
const packages = <?php echo json_encode($packages, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;

document.addEventListener('DOMContentLoaded', () => {
    // Initialize modal after Bootstrap bundle is loaded
    if (window.bootstrap && document.getElementById('clientModal')) {
        clientModalInstance = new bootstrap.Modal(document.getElementById('clientModal'), { keyboard: true, backdrop: true });
    }
    document.querySelectorAll('.tab-btn').forEach(b => b.addEventListener('click', (e) => {
        document.querySelectorAll('.tab-btn').forEach(x=>x.classList.remove('active'));
        e.currentTarget.classList.add('active');
        currentType = e.currentTarget.dataset.type;
        loadUsers();
    }));
    document.getElementById('searchInput').addEventListener('input', debounce(loadUsers, 300));

    // when user changes type in modal, filter packages dropdown
    document.getElementById('type').addEventListener('change', filterPackageOptions);

    loadUsers();
});

function filterPackageOptions(){
    const sel = document.getElementById('package_id');
    const t = document.getElementById('type').value;
    // reset options and add those matching type + the None option
    sel.innerHTML = '<option value="">-- None --</option>';
    packages.forEach(p=>{
        if (!t || !p.type || p.type === t) {
            const o = document.createElement('option');
            o.value = p.id;
            o.text = p.name + ' — KES ' + parseFloat(p.price).toFixed(2);
            o.dataset.type = p.type;
            sel.appendChild(o);
        }
    });
}

function loadUsers() {
    const typeParam = (currentType === 'all' || currentType === 'paused') ? '' : '?type=' + currentType;
    fetch(apiBase + typeParam).then(r=>r.json()).then(data=>{
        usersCache = Array.isArray(data) ? data : (data ? [data] : []);
        const q = document.getElementById('searchInput').value.toLowerCase();
        let filtered = usersCache.filter(u=>{
            if(!q) return true;
            return (u.name||'').toLowerCase().includes(q) || (u.address||'').toLowerCase().includes(q) || (u.phone||'').toLowerCase().includes(q);
        });
        if(currentType === 'paused'){
            filtered = usersCache.filter(u=>u.status==='paused').filter(u=>{
                if(!q) return true;
                return (u.name||'').toLowerCase().includes(q) || (u.address||'').toLowerCase().includes(q) || (u.phone||'').toLowerCase().includes(q);
            });
        }
        renderTable(filtered);
        // counts (from full all)
        fetch(apiBase).then(r=>r.json()).then(all=>{
            const arr = Array.isArray(all) ? all : (all? [all] : []);
            document.getElementById('countAll').innerText = arr.length;
            document.getElementById('countHotspot').innerText = arr.filter(x=>x.type==='hotspot').length;
            document.getElementById('countPppoe').innerText = arr.filter(x=>x.type==='pppoe').length;
            document.getElementById('countPaused').innerText = arr.filter(x=>x.status==='paused').length;
        });
    }).catch(err=>console.error(err));
}

function renderTable(users){
    const tbody = document.getElementById('usersTbody');
    tbody.innerHTML = '';
    users.forEach(u=>{
        const tr = document.createElement('tr');
        const ip = escapeHtml(u.ip || '—');
        const mac = escapeHtml(u.mac || '—');
        const start = u.session_start ? formatRelative(u.session_start) : '—';
        const end = u.expiry_date ? formatRelative(u.expiry_date, true) : (u.session_end ? formatRelative(u.session_end, true) : '—');
        tr.innerHTML = `
            <td><input type="checkbox" data-id="${u.id}"></td>
            <td class="fw-semibold"><a href="user.php?id=${u.id}" class="text-decoration-none">${escapeHtml(u.name||'—')}</a><div class="text-muted small">(Acc. E${27000})</div></td>
            <td><div class="small">IP: ${ip}</div><div class="small text-muted">MAC: ${mac}</div></td>
            <td>${start}</td>
            <td>${end}</td>
            <td>
                <button class="btn btn-sm btn-outline-danger" onclick="disconnectUser(${u.id})">Disconnect</button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function openCreateModal(){
    if (!clientModalInstance && window.bootstrap) {
        clientModalInstance = new bootstrap.Modal(document.getElementById('clientModal'), { keyboard: true, backdrop: true });
    }
    document.getElementById('modalTitle').innerText = 'Create User';
    document.getElementById('clientId').value = '';
    document.getElementById('name').value = '';
    document.getElementById('phone').value = '';
    document.getElementById('address').value = '';
    document.getElementById('type').value = 'hotspot';
    document.getElementById('status').value = 'active';
    document.getElementById('expiry').value = '';
    filterPackageOptions();
    if (clientModalInstance) clientModalInstance.show();
}

function openEdit(id){
    const u = usersCache.find(x=>x.id==id);
    if(!u) return alert('User not found');
    document.getElementById('modalTitle').innerText = 'Edit User';
    document.getElementById('clientId').value = u.id;
    document.getElementById('name').value = u.name || '';
    document.getElementById('phone').value = u.phone || '';
    document.getElementById('address').value = u.address || '';
    document.getElementById('type').value = u.type || 'hotspot';
    document.getElementById('status').value = u.status || 'active';
    if(u.expiry_date){
        const d = new Date(u.expiry_date);
        const local = new Date(d.getTime() - d.getTimezoneOffset()*60000).toISOString().slice(0,16);
        document.getElementById('expiry').value = local;
    } else {
        document.getElementById('expiry').value = '';
    }
    filterPackageOptions();
    // set package selection
    document.getElementById('package_id').value = u.package_id ? u.package_id : '';
    if (!clientModalInstance && window.bootstrap) {
        clientModalInstance = new bootstrap.Modal(document.getElementById('clientModal'), { keyboard: true, backdrop: true });
    }
    if (clientModalInstance) clientModalInstance.show();
}

function closeModal(){ if (clientModalInstance) clientModalInstance.hide(); }

function saveUser(){
    const id = document.getElementById('clientId').value;
    const payload = {
        id: id || undefined,
        name: document.getElementById('name').value.trim(),
        phone: document.getElementById('phone').value.trim(),
            password: document.getElementById('password') ? document.getElementById('password').value : undefined,
        address: document.getElementById('address').value.trim(),
        type: document.getElementById('type').value,
        status: document.getElementById('status').value,
        expiry_date: document.getElementById('expiry').value ? new Date(document.getElementById('expiry').value).toISOString() : null,
        package_id: document.getElementById('package_id') ? (document.getElementById('package_id').value ? Number(document.getElementById('package_id').value) : null) : null
    };
    if(!payload.name){ return alert('Username required'); }
    if(!document.getElementById('password').value){ return alert('Password required'); }

    const method = id ? 'PUT' : 'POST';
    fetch(apiBase, {
        method,
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
    }).then(async r=>{
        const data = await r.json().catch(()=>({success:false, message:'Invalid JSON response'}));
        if (!r.ok) {
            // server returned non-200 (e.g. 400) - show message
            alert(data.message || ('Server error: ' + r.status));
            console.error('Server response', data);
            return;
        }
        if (data.success === false) {
            alert(data.message || 'Operation failed');
            console.error('Server error details:', data.error || data);
            return;
        }
        // success
        closeModal();
        loadUsers();
    }).catch(err=>{
        console.error(err);
        alert('Network or server error. Check console for details.');
    });
}

function deleteUser(id){
    if(!confirm('Delete user?')) return;
    fetch(apiBase, {
        method: 'DELETE',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id})
    }).then(r=>r.json()).then(res=>{
        loadUsers();
    }).catch(err=>{ console.error(err); alert('Delete failed'); });
}

function togglePause(id){
    const u = usersCache.find(x=>x.id==id);
    if(!u) return;
    const newStatus = u.status === 'paused' ? 'active' : 'paused';
    const payload = { id, status: newStatus, name: u.name, phone: u.phone, address: u.address, type: u.type, expiry_date: u.expiry_date, package_id: u.package_id };
    fetch(apiBase, {
        method: 'PUT',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
    }).then(r=>r.json()).then(()=>{
        // refresh both current tab and counts so Paused tab reflects change
        loadUsers();
    }).catch(e=>console.error(e));
}

function formatRelative(dateLike, future=false){
    const d = new Date(dateLike);
    if (isNaN(d.getTime())) return '—';
    const now = new Date();
    const diffMs = d.getTime() - now.getTime();
    const abs = Math.abs(diffMs);
    const mins = Math.round(abs/60000);
    const hours = Math.round(abs/3600000);
    const days = Math.round(abs/86400000);
    let txt = '';
    if (mins < 60) txt = `${mins} minutes ${diffMs < 0 ? 'ago' : 'from now'}`;
    else if (hours < 48) txt = `${hours} hours ${diffMs < 0 ? 'ago' : 'from now'}`;
    else txt = `${days} days ${diffMs < 0 ? 'ago' : 'from now'}`;
    return txt;
}

function disconnectUser(id){
    // Placeholder for real Mikrotik disconnect API; currently just a confirmation
    if(!confirm('Disconnect this user?')) return;
    alert('Disconnect command queued');
}

// helpers
function escapeHtml(s){ return (s||'').toString().replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function debounce(fn, t){ let time; return function(){ clearTimeout(time); time = setTimeout(()=>fn.apply(this,arguments), t); }; }
</script>

<?php include 'includes/footer.php'; ?>