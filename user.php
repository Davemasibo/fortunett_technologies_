<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: users.php'); exit; }

// fetch user
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { header('Location: users.php'); exit; }
// normalize fields
if ((!isset($user['name']) || !$user['name']) && isset($user['full_name'])) { $user['name'] = $user['full_name']; }
if (isset($user['expiry_date']) && $user['expiry_date']) { $user['expiry_date_iso'] = date('c', strtotime($user['expiry_date'])); }

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content-wrapper">
    <div style="padding: 30px;">
        <div style="background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <h2 style="margin:0; color:#333; font-weight:600;"><?php echo htmlspecialchars($user['name'] ?? 'User'); ?></h2>
                        <span style="background:#e9f8ee;color:#1e8e3e;border:1px solid #c9efda; padding:4px 10px; border-radius:999px; font-size:12px;">Currently Online</span>
                    </div>
                    <div style="color:#666;font-size:13px;margin-top:6px;">Package: <?php echo htmlspecialchars($user['package_name'] ?? '—'); ?> • Expires: <?php echo isset($user['expiry_date']) && $user['expiry_date'] ? date('F j, Y g:i A', strtotime($user['expiry_date'])) : 'No expiry'; ?></div>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="button" class="btn btn-outline-warning" onclick="togglePauseUser()">Pause Subscription</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="openExpiry()">Change Expiry</button>
                    <a href="sms.php?client_id=<?php echo (int)$user['id']; ?>" class="btn btn-outline-primary">Send voucher</a>
                    <div class="dropdown">
                        <button class="btn btn-outline-dark dropdown-toggle" type="button" data-bs-toggle="dropdown">Actions</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="openEdit()">Edit</a></li>
                            <li><a class="dropdown-item" href="sms.php?client_id=<?php echo (int)$user['id']; ?>&template=credentials">Send SMS</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteUser(<?php echo (int)$user['id']; ?>)">Delete user</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div style="background:white;padding:12px;border-radius:8px;margin-bottom:16px;display:flex;gap:18px;align-items:center;">
            <button class="tab-btn btn btn-link active" data-bs-target="#tab-general" data-tab>General Information</button>
            <button class="tab-btn btn btn-link" data-bs-target="#tab-reports" data-tab>Reports</button>
            <button class="tab-btn btn btn-link" data-bs-target="#tab-payments" data-tab>Payments</button>
            <button class="tab-btn btn btn-link" data-bs-target="#tab-sessions" data-tab>Sessions</button>
        </div>

        <div id="tab-general" class="tab-pane-custom active">
            <div style="background:white; padding:0; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05);">
                <div style="padding:14px 18px; border-bottom:1px solid #eee; font-weight:600;">Account Information</div>
                <div style="padding:14px;">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">Account Number</div>
                                    <div style="font-weight:600;">E<?php echo 27000 + (int)$user['id']; ?></div>
                                </div>
                                <button class="btn btn-sm btn-outline-light" onclick="copyText('E<?php echo 27000 + (int)$user['id']; ?>')">Copy</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">Full Name</div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($user['name'] ?? '—'); ?></div>
                                </div>
                                <button class="btn btn-sm btn-outline-light" onclick="copyText('<?php echo htmlspecialchars($user['name'] ?? '', ENT_QUOTES); ?>')">Copy</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">Username</div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($user['name'] ?? '—'); ?></div>
                                </div>
                                <button class="btn btn-sm btn-outline-light" onclick="copyText('<?php echo htmlspecialchars($user['name'] ?? '', ENT_QUOTES); ?>')">Copy</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">Password</div>
                                    <div style="font-weight:600;"><span id="pwdHidden">••••••••</span><span id="pwdValue" style="display:none;"><?php echo htmlspecialchars($user['password'] ?? ''); ?></span></div>
                                </div>
                                <div>
                                    <button class="btn btn-sm btn-link me-2 p-0" onclick="togglePwd()" type="button" title="Show/Hide"><i class="fas fa-eye" id="pwdEye"></i></button>
                                    <button class="btn btn-sm btn-outline-light" onclick="copyText(document.getElementById('pwdValue').textContent)" type="button">Copy</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">Package</div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($user['package_name'] ?? '—'); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">Status</div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($user['status'] ?? '—'); ?> <span style="color:#1e8e3e;">• Online</span></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">User Type</div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars(ucfirst($user['type'] ?? '')); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-size:11px;color:#888; text-transform:uppercase;">Phone Number</div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($user['phone'] ?? '—'); ?></div>
                                </div>
                                <button class="btn btn-sm btn-outline-light" onclick="copyText('<?php echo htmlspecialchars($user['phone'] ?? '', ENT_QUOTES); ?>')">Copy</button>
                            </div>
                        </div>
                        <div class="col-12">
                            <div style="border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px;">
                                <div style="font-size:11px;color:#888; text-transform:uppercase;">Time Remaining</div>
                                <div style="font-weight:600;" id="timeRemaining">—</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="tab-reports" class="tab-pane-custom" style="display:none;">
            <div style="background:white; padding:20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05); color:#666;">No reports implemented yet.</div>
        </div>
        <div id="tab-payments" class="tab-pane-custom" style="display:none;">
            <div style="background:white; padding:20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05); color:#666;">No payments displayed yet.</div>
        </div>
        <div id="tab-sessions" class="tab-pane-custom" style="display:none;">
            <div style="background:white; padding:20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05); color:#666;">No sessions displayed yet.</div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="editForm" class="needs-validation" novalidate onsubmit="event.preventDefault(); saveEdit();">
        <div class="modal-header">
          <h5 class="modal-title">Edit User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="userId" value="<?php echo (int)$user['id']; ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Username</label>
                    <input type="text" id="name" class="form-control" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" id="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password</label>
                    <input type="text" id="password" class="form-control" value="<?php echo htmlspecialchars($user['password'] ?? ''); ?>" placeholder="Leave blank to keep">
                </div>
                <div class="col-12">
                    <label class="form-label">Address / Notes</label>
                    <input type="text" id="address" class="form-control" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Type</label>
                    <select id="type" class="form-select">
                        <option value="hotspot" <?php echo ($user['type'] ?? '') === 'hotspot' ? 'selected' : ''; ?>>Hotspot</option>
                        <option value="pppoe" <?php echo ($user['type'] ?? '') === 'pppoe' ? 'selected' : ''; ?>>PPPoE</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select id="status" class="form-select">
                        <option value="active" <?php echo ($user['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="paused" <?php echo ($user['status'] ?? '') === 'paused' ? 'selected' : ''; ?>>Paused</option>
                        <option value="suspended" <?php echo ($user['status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    </select>
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

<!-- Change Expiry Modal -->
<div class="modal fade" id="expiryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form onsubmit="event.preventDefault(); saveExpiry();">
        <div class="modal-header">
          <h5 class="modal-title">Change Expiry</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <label class="form-label">Expiry Date</label>
            <input type="datetime-local" id="expiryPicker" class="form-control" value="<?php echo isset($user['expiry_date']) && $user['expiry_date'] ? date('Y-m-d\TH:i', strtotime($user['expiry_date'])) : ''; ?>">
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
let editModal;
let expiryModal;
document.addEventListener('DOMContentLoaded', () => {
  if (window.bootstrap) {
    editModal = new bootstrap.Modal(document.getElementById('editModal'));
    expiryModal = new bootstrap.Modal(document.getElementById('expiryModal'));
  }
  // simple tabs
  document.querySelectorAll('[data-tab]').forEach(btn=>{
    btn.addEventListener('click', (e)=>{
      document.querySelectorAll('[data-tab]').forEach(x=>x.classList.remove('active'));
      e.currentTarget.classList.add('active');
      const target = e.currentTarget.getAttribute('data-bs-target');
      document.querySelectorAll('.tab-pane-custom').forEach(p=>p.style.display='none');
      const pane = document.querySelector(target);
      if (pane) pane.style.display = 'block';
    });
  });
});
function copyText(t){
  if (!navigator.clipboard) { return; }
  navigator.clipboard.writeText(t).catch(()=>{});
}
function togglePauseUser(){
  alert('Pause/Resume action can be wired to status update.');
}
function openEdit(){ if(editModal) editModal.show(); }
function openExpiry(){ if(expiryModal) expiryModal.show(); }
function saveEdit(){
  const payload = {
    id: Number(document.getElementById('userId').value),
    name: document.getElementById('name').value.trim(),
    phone: document.getElementById('phone').value.trim(),
    password: document.getElementById('password') ? document.getElementById('password').value : undefined,
    address: document.getElementById('address').value.trim(),
    type: document.getElementById('type').value,
    status: document.getElementById('status').value
  };
  fetch(apiBase, { method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) })
    .then(r=>r.json()).then(res=>{ if(res.success!==false){ location.reload(); } else { alert(res.message||'Update failed'); } })
    .catch(e=>{ console.error(e); alert('Update failed'); });
}
// compute time remaining
(function(){
  const el = document.getElementById('timeRemaining');
  const expiryIso = <?php echo isset($user['expiry_date']) && $user['expiry_date'] ? json_encode(date('c', strtotime($user['expiry_date']))) : 'null'; ?>;
  if(!el || !expiryIso) return;
  const d = new Date(expiryIso);
  if (isNaN(d.getTime())) return;
  const now = new Date();
  const diffMs = d.getTime() - now.getTime();
  const days = Math.max(0, (diffMs / 86400000));
  el.textContent = days.toFixed(6) + ' days';
})();
function togglePwd(){
  const h = document.getElementById('pwdHidden');
  const v = document.getElementById('pwdValue');
  if(!h || !v) return;
  const isHidden = v.style.display === 'none';
  v.style.display = isHidden ? 'inline' : 'none';
  h.style.display = isHidden ? 'none' : 'inline';
  const eye = document.getElementById('pwdEye');
  if (eye) eye.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
}
function saveExpiry(){
  const val = document.getElementById('expiryPicker').value;
  const payload = {
    id: Number(document.getElementById('userId') ? document.getElementById('userId').value : <?php echo (int)$user['id']; ?>),
    expiry_date: val ? new Date(val).toISOString() : null
  };
  fetch(apiBase, { method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) })
    .then(r=>r.json()).then(res=>{ if(res.success!==false){ location.reload(); } else { alert(res.message||'Update failed'); } })
    .catch(e=>{ console.error(e); alert('Update failed'); });
}
</script>

<?php include 'includes/footer.php'; ?>


