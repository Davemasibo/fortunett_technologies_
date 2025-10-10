<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <div style="background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <h2 style="margin:0">Users</h2>
                    <div style="color:#666;font-size:14px">All users including Hotspot and PPPoE</div>
                </div>
                <div style="display:flex;gap:10px">
                    <button id="importBtn" class="btn btn-outline-secondary">Import Users</button>
                    <button id="createBtn" class="btn btn-warning" onclick="openCreateModal()">Create User</button>
                </div>
            </div>
        </div>
        <!-- Tabs -->
        <div style="background:white;padding:12px;border-radius:8px;margin-bottom:16px;display:flex;gap:12px;align-items:center;">
            <button class="tab-btn btn btn-link active" data-type="all">All <span id="countAll" class="badge bg-light text-dark ms-2">0</span></button>
            <button class="tab-btn btn btn-link" data-type="hotspot">Hotspot <span id="countHotspot" class="badge bg-light text-dark ms-2">0</span></button>
            <button class="tab-btn btn btn-link" data-type="pppoe">PPPoE <span id="countPppoe" class="badge bg-light text-dark ms-2">0</span></button>
        </div>

        <!-- Search bar -->
        <div style="margin-bottom:12px;display:flex;justify-content:flex-end;">
            <input id="searchInput" placeholder="Search" style="padding:8px 12px;border-radius:6px;border:1px solid #ddd;width:260px;">
        </div>

        <!-- Users table -->
        <div style="background:white;border-radius:10px;padding:0;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.05);">
            <table style="width:100%;border-collapse:collapse;">
                <thead style="background:#f8f9fa">
                    <tr>
                        <th style="padding:12px;text-align:left;">Username</th>
                        <th style="padding:12px;text-align:left;">Phone</th>
                        <th style="padding:12px;text-align:left;">Package</th>
                        <th style="padding:12px;text-align:left;">Expiry</th>
                        <th style="padding:12px;text-align:left;">Type</th>
                        <th style="padding:12px;text-align:left;">Status</th>
                        <th style="padding:12px;text-align:left;">Actions</th>
                    </tr>
                </thead>
                <tbody id="usersTbody">
                    <!-- rows populated by JS -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create / Edit Modal -->
<div id="userModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;z-index:9999">
    <div style="background:white;width:95%;max-width:720px;border-radius:10px;padding:18px;max-height:90vh;overflow:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h4 id="modalTitle">Create User</h4>
            <button onclick="closeModal()" style="background:none;border:none;font-size:22px;cursor:pointer;">&times;</button>
        </div>
        <form id="userForm">
            <input type="hidden" name="id" id="clientId">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <label>Username</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>
                <div>
                    <label>Full Name / Company</label>
                    <input type="text" id="company" name="company" class="form-control">
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" id="email" name="email" class="form-control">
                </div>
                <div>
                    <label>Phone</label>
                    <input type="text" id="phone" name="phone" class="form-control">
                </div>
                <div style="grid-column:1/3">
                    <label>Address</label>
                    <input type="text" id="address" name="address" class="form-control">
                </div>
                <div>
                    <label>Type</label>
                    <select id="type" name="type" class="form-control">
                        <option value="hotspot">Hotspot</option>
                        <option value="pppoe">PPPoE</option>
                    </select>
                </div>
                <div>
                    <label>Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="active">Active</option>
                        <option value="paused">Paused</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div style="grid-column:1/3">
                    <label>Expiry Date (leave empty for no expiry)</label>
                    <input type="datetime-local" id="expiry" name="expiry_date" class="form-control">
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px">
                <button type="button" onclick="saveUser()" class="btn btn-success">Save</button>
                <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
const apiBase = '/api/clients.php';
let currentType = 'all';
let usersCache = [];

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.addEventListener('click', (e) => {
        document.querySelectorAll('.tab-btn').forEach(x=>x.classList.remove('active'));
        e.currentTarget.classList.add('active');
        currentType = e.currentTarget.dataset.type;
        loadUsers();
    }));
    document.getElementById('searchInput').addEventListener('input', debounce(loadUsers, 300));
    loadUsers();
});

function loadUsers() {
    const typeParam = currentType === 'all' ? '' : '?type=' + currentType;
    fetch(apiBase + typeParam).then(r=>r.json()).then(data=>{
        usersCache = Array.isArray(data) ? data : (data ? [data] : []);
        const q = document.getElementById('searchInput').value.toLowerCase();
        const filtered = usersCache.filter(u=>{
            if(!q) return true;
            return (u.name||'').toLowerCase().includes(q) || (u.company||'').toLowerCase().includes(q) || (u.phone||'').toLowerCase().includes(q);
        });
        renderTable(filtered);
        // counts
        fetch(apiBase).then(r=>r.json()).then(all=>{
            document.getElementById('countAll').innerText = (Array.isArray(all)?all.length: (all?1:0));
            const hs = Array.isArray(all) ? all.filter(x=>x.type==='hotspot').length : 0;
            const pp = Array.isArray(all) ? all.filter(x=>x.type==='pppoe').length : 0;
            document.getElementById('countHotspot').innerText = hs;
            document.getElementById('countPppoe').innerText = pp;
        });
    }).catch(err=>console.error(err));
}

function renderTable(users){
    const tbody = document.getElementById('usersTbody');
    tbody.innerHTML = '';
    users.forEach(u=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="padding:10px">${escapeHtml(u.name||'—')}</td>
            <td style="padding:10px">${escapeHtml(u.phone||'—')}</td>
            <td style="padding:10px">${escapeHtml(u.package_name||'—')}</td>
            <td style="padding:10px">${u.expiry_date ? new Date(u.expiry_date).toLocaleString() : '<span style="color:#888">No expiry</span>'}</td>
            <td style="padding:10px">${escapeHtml((u.type||'').toUpperCase())}</td>
            <td style="padding:10px">${escapeHtml(u.status||'—')}</td>
            <td style="padding:10px">
                <button class="btn btn-sm btn-outline-primary" onclick="openEdit(${u.id})">Edit</button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${u.id})">Delete</button>
                <button class="btn btn-sm btn-outline-secondary" onclick="togglePause(${u.id})">${u.status==='paused'?'Resume':'Pause'}</button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function openCreateModal(){
    document.getElementById('modalTitle').innerText = 'Create User';
    document.getElementById('clientId').value = '';
    document.getElementById('name').value = '';
    document.getElementById('company').value = '';
    document.getElementById('email').value = '';
    document.getElementById('phone').value = '';
    document.getElementById('address').value = '';
    document.getElementById('type').value = 'hotspot';
    document.getElementById('status').value = 'active';
    document.getElementById('expiry').value = '';
    document.getElementById('userModal').style.display = 'flex';
}

function openEdit(id){
    const u = usersCache.find(x=>x.id==id);
    if(!u) return alert('User not found');
    document.getElementById('modalTitle').innerText = 'Edit User';
    document.getElementById('clientId').value = u.id;
    document.getElementById('name').value = u.name || '';
    document.getElementById('company').value = u.company || '';
    document.getElementById('email').value = u.email || '';
    document.getElementById('phone').value = u.phone || '';
    document.getElementById('address').value = u.address || '';
    document.getElementById('type').value = u.type || 'hotspot';
    document.getElementById('status').value = u.status || 'active';
    // convert ISO to datetime-local value
    if(u.expiry_date){
        const d = new Date(u.expiry_date);
        const local = new Date(d.getTime() - d.getTimezoneOffset()*60000).toISOString().slice(0,16);
        document.getElementById('expiry').value = local;
    } else {
        document.getElementById('expiry').value = '';
    }
    document.getElementById('userModal').style.display = 'flex';
}

function closeModal(){ document.getElementById('userModal').style.display = 'none'; }

function saveUser(){
    const id = document.getElementById('clientId').value;
    const payload = {
        id: id || undefined,
        name: document.getElementById('name').value.trim(),
        company: document.getElementById('company').value.trim(),
        email: document.getElementById('email').value.trim(),
        phone: document.getElementById('phone').value.trim(),
        address: document.getElementById('address').value.trim(),
        type: document.getElementById('type').value,
        status: document.getElementById('status').value,
        expiry_date: document.getElementById('expiry').value ? new Date(document.getElementById('expiry').value).toISOString() : null
    };
    if(!payload.name){ return alert('Username required'); }

    if(id){
        // PUT
        fetch(apiBase, {
            method: 'PUT',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        }).then(r=>r.json()).then(res=>{
            alert(res.message || 'Updated');
            closeModal();
            loadUsers();
        }).catch(err=>{ console.error(err); alert('Update failed'); });
    } else {
        // POST
        fetch(apiBase, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        }).then(r=>r.json()).then(res=>{
            if(res.success){ closeModal(); loadUsers(); } else { alert(res.message || 'Create failed'); }
        }).catch(err=>{ console.error(err); alert('Create failed'); });
    }
}

function deleteUser(id){
    if(!confirm('Delete user?')) return;
    fetch(apiBase, {
        method: 'DELETE',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({id})
    }).then(r=>r.json()).then(res=>{
        alert(res.message || 'Deleted');
        loadUsers();
    }).catch(err=>{ console.error(err); alert('Delete failed'); });
}

function togglePause(id){
    const u = usersCache.find(x=>x.id==id);
    if(!u) return;
    const newStatus = u.status === 'paused' ? 'active' : 'paused';
    fetch(apiBase, {
        method: 'PUT',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id, status: newStatus, name: u.name, email: u.email, phone: u.phone, company: u.company, address: u.address, type: u.type, expiry_date: u.expiry_date })
    }).then(r=>r.json()).then(res=>{
        loadUsers();
    }).catch(err=>console.error(err));
}

// helpers
function escapeHtml(s){ return (s||'').toString().replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function debounce(fn, t){ let time; return function(){ clearTimeout(time); time = setTimeout(()=>fn.apply(this,arguments), t); }; }
</script>

<?php include 'includes/footer.php'; ?>
