<?php
require_once __DIR__ . '/includes/db_master.php';
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id   = $_SESSION['user_id'] ?? 0;
$st        = $pdo->prepare("SELECT tenant_id FROM users WHERE id = ?");
$st->execute([$user_id]);
$tenant_id = $st->fetchColumn();

// Get tenant branding for the report header
$brandName  = 'ISP Services';
$brandColor = '#3B6EA5';
try {
    $bSt = $pdo->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?");
    $bSt->execute([$tenant_id]);
    $tSettings  = $bSt->fetchAll(PDO::FETCH_KEY_PAIR);
    $brandName  = $tSettings['company_name']  ?? $tSettings['brand_name'] ?? 'ISP Services';
    $brandColor = $tSettings['brand_color']   ?? '#3B6EA5';
} catch (Exception $e) {}

// Get all clients for directory table
try {
    $st = $pdo->prepare("
        SELECT c.id, c.full_name, c.email, c.phone, c.mikrotik_username, c.account_number,
               c.status, c.expiry_date,
               COALESCE(p.name, c.subscription_plan, '—') AS package_name
        FROM clients c
        LEFT JOIN packages p ON p.id = c.package_id
        WHERE c.tenant_id = ?
        ORDER BY c.full_name ASC
    ");
    $st->execute([$tenant_id]);
    $clients = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    /* ── Dark tokens ── */
    :root { --neu-bg:#141414; --neu-surf:#1c1c1b; --neu-s2:#222221; --neu-border:rgba(255,255,255,.06); --neu-card:8px 8px 20px rgba(0,0,0,.45),-4px -4px 10px rgba(255,255,255,.03); }

    .main-content-wrapper { background: var(--neu-bg) !important; }
    .reports-container { padding: 24px 32px; max-width: 1400px; margin: 0 auto; }

    /* Page header text */
    .reports-container h1, .reports-container [style*="color:#111827"] { color: #e2e2e0 !important; }
    .reports-container [style*="color:#6B7280"] { color: rgba(255,255,255,.45) !important; }

    /* Quick-generate panel + directory panel inline overrides */
    .reports-container [style*="background:white"],
    .reports-container [style*="background: white"] {
        background: var(--neu-s2) !important;
        border-color: var(--neu-border) !important;
        box-shadow: var(--neu-card) !important;
    }
    .reports-container [style*="border:1px solid #E5E7EB"] { border-color: var(--neu-border) !important; }
    .reports-container select[style], .reports-container input[style] {
        background: var(--neu-surf) !important;
        border-color: var(--neu-border) !important;
        color: #e2e2e0 !important;
    }
    .reports-container label[style*="color:#374151"] { color: rgba(255,255,255,.6) !important; }
    .reports-container [style*="color:#374151"]       { color: rgba(255,255,255,.65) !important; }
    .reports-container [style*="border-bottom:1px solid #E5E7EB"] { border-bottom-color: var(--neu-border) !important; }
    .reports-container [style*="font-size:15px"][style*="color:#111827"] { color: #e2e2e0 !important; }
    .reports-container [style*="font-size:13px"][style*="color:#6B7280"] { color: rgba(255,255,255,.4) !important; }

    /* Table */
    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .clients-table { min-width: 780px; width: 100%; border-collapse: collapse; }
    .clients-table thead { background: rgba(255,255,255,.04); border-bottom: 1px solid var(--neu-border); }
    .clients-table th { padding: 11px 14px; text-align: left; font-size: 11px; font-weight: 600; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .05em; }
    .clients-table td { padding: 13px 14px; border-bottom: 1px solid rgba(255,255,255,.04); font-size: 13px; color: #d4d4d2; }
    .clients-table tbody tr:hover { background: rgba(255,255,255,.03); }

    .pill-status { display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600; }
    .pill-status.active    { background:rgba(52,211,153,.15);  color:#6ee7b7; border:1px solid rgba(52,211,153,.25); }
    .pill-status.expired   { background:rgba(248,113,113,.15); color:#fca5a5; border:1px solid rgba(248,113,113,.25); }
    .pill-status.inactive  { background:rgba(156,163,175,.12); color:rgba(255,255,255,.45); border:1px solid rgba(255,255,255,.1); }
    .pill-status.suspended { background:rgba(251,191,36,.15);  color:#fcd34d; border:1px solid rgba(251,191,36,.25); }

    .rpt-btn { padding:5px 11px; border:1px solid var(--primary-color,#3B6EA5); background:rgba(59,110,165,.1); color:var(--primary-color,#3B6EA5); border-radius:6px; font-size:12px; font-weight:500; cursor:pointer; white-space:nowrap; transition:all .15s; }
    .rpt-btn:hover { background:var(--primary-color,#3B6EA5); color:white; }

    /* Modal overrides */
    #reportModal [style*="background:white"], #reportModal [style*="background: white"],
    #reportModal [style*="background:#F9FAFB"] {
        background: var(--neu-s2) !important;
        border-color: var(--neu-border) !important;
        color: #d4d4d2 !important;
    }
    #reportModal [style*="border-bottom:1px solid #E5E7EB"] { border-bottom-color: var(--neu-border) !important; }
    #reportModal [style*="color:#111827"] { color: #e2e2e0 !important; }
    #reportModal [style*="color:#374151"] { color: rgba(255,255,255,.65) !important; }
    #reportModal button[style*="background:white"], #reportModal button[style*="background: white"] {
        background: var(--neu-surf) !important; border-color: var(--neu-border) !important; color: rgba(255,255,255,.7) !important;
    }

    @media (max-width: 640px) { .reports-container { padding: 16px; } }

    @media print {
        body > *:not(#printFrame) { display: none !important; }
        #printFrame { display: block !important; position: fixed; inset: 0; width: 100%; height: 100%; border: none; }
    }
</style>

<div class="main-content-wrapper">
<div class="reports-container">

    <!-- Header -->
    <div style="margin-bottom:24px;">
        <h1 style="font-size:28px;font-weight:600;color:#111827;margin:0 0 4px;">Reports & Analytics</h1>
        <p style="font-size:14px;color:#6B7280;margin:0;">Generate detailed customer reports, preview them, then share via email or SMS.</p>
    </div>

    <!-- Quick generate panel -->
    <div style="background:white;border-radius:10px;border:1px solid #E5E7EB;padding:24px;margin-bottom:24px;">
        <div style="font-size:15px;font-weight:700;color:#111827;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-file-pdf" style="color:var(--primary-color,#3B6EA5);"></i> Generate Customer Report
        </div>
        <div style="display:grid;grid-template-columns:2fr 1fr auto;gap:14px;align-items:end;">
            <div>
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Select Customer</label>
                <select id="qCustomer" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:white;">
                    <option value="">— Select a customer —</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?php echo $c['id']; ?>"
                            data-name="<?php echo htmlspecialchars($c['full_name']); ?>"
                            data-email="<?php echo htmlspecialchars($c['email'] ?? ''); ?>">
                        <?php echo htmlspecialchars($c['full_name']); ?>
                        <?php if ($c['account_number']): ?> (<?php echo htmlspecialchars($c['account_number']); ?>)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:500;color:#374151;margin-bottom:5px;">Period</label>
                <select id="qPeriod" style="width:100%;padding:9px 11px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:white;">
                    <option value="6">Last 6 Months</option>
                    <option value="3">Last 3 Months</option>
                    <option value="12">Last 12 Months</option>
                    <option value="1">Current Month</option>
                </select>
            </div>
            <button onclick="generateReport()" style="padding:9px 20px;background:linear-gradient(135deg,var(--primary-dark,#2C5282) 0%,var(--primary-color,#3B6EA5) 100%);color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:7px;">
                <i class="fas fa-eye"></i> Preview Report
            </button>
        </div>
    </div>

    <!-- Customer Directory -->
    <div style="background:white;border-radius:10px;border:1px solid #E5E7EB;overflow:hidden;">
        <div style="padding:14px 20px;border-bottom:1px solid #E5E7EB;display:flex;justify-content:space-between;align-items:center;">
            <div style="font-size:15px;font-weight:700;color:#111827;">Customer Directory</div>
            <div style="font-size:13px;color:#6B7280;"><?php echo count($clients); ?> customers</div>
        </div>
        <div class="table-scroll">
        <table class="clients-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Account #</th>
                    <th>Package</th>
                    <th>Status</th>
                    <th>Expiry</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clients as $c):
                $st2 = strtolower($c['status'] ?? 'inactive');
                $exp = $c['expiry_date'] ? strtotime($c['expiry_date']) < time() : false;
                $dispSt = $exp ? 'expired' : $st2;
            ?>
            <tr>
                <td>
                    <div style="font-weight:600;color:#111827;"><?php echo htmlspecialchars($c['full_name']); ?></div>
                    <div style="font-size:11px;color:#9CA3AF;"><?php echo htmlspecialchars($c['mikrotik_username'] ?? ''); ?></div>
                </td>
                <td style="font-family:monospace;font-size:12px;font-weight:600;color:var(--primary-color,#3B6EA5);">
                    <?php echo htmlspecialchars($c['account_number'] ?? ('#'.$c['id'])); ?>
                </td>
                <td><?php echo htmlspecialchars($c['package_name']); ?></td>
                <td><span class="pill-status <?php echo $dispSt; ?>"><?php echo ucfirst($dispSt); ?></span></td>
                <td style="font-size:12px;<?php echo $exp ? 'color:#EF4444;' : ''; ?>">
                    <?php echo $c['expiry_date'] ? date('d M Y', strtotime($c['expiry_date'])) : '—'; ?>
                </td>
                <td><?php echo htmlspecialchars($c['phone'] ?? '—'); ?></td>
                <td style="font-size:12px;"><?php echo htmlspecialchars($c['email'] ?? '—'); ?></td>
                <td onclick="event.stopPropagation()">
                    <button class="rpt-btn" onclick="generateReportFor(<?php echo $c['id']; ?>,'<?php echo htmlspecialchars(addslashes($c['full_name'])); ?>')">
                        <i class="fas fa-file-pdf"></i> Report
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

</div>
</div>

<!-- ═══════════ REPORT PREVIEW MODAL ═══════════ -->
<div id="reportModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1100;align-items:flex-start;justify-content:center;padding:20px;box-sizing:border-box;overflow-y:auto;">
<div style="background:white;width:100%;max-width:860px;border-radius:14px;box-shadow:0 24px 80px rgba(0,0,0,.3);margin:auto;display:flex;flex-direction:column;overflow:hidden;">
    <!-- Modal header -->
    <div style="padding:16px 20px;border-bottom:1px solid #E5E7EB;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;background:white;position:sticky;top:0;z-index:2;">
        <div>
            <div style="font-size:15px;font-weight:700;color:#111827;" id="rptModalTitle">Customer Report</div>
            <div style="font-size:12px;color:#9CA3AF;margin-top:2px;" id="rptModalSub">Loading…</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
            <button onclick="printReport()" id="btnPrintRpt" style="padding:7px 14px;background:linear-gradient(135deg,var(--primary-dark,#2C5282) 0%,var(--primary-color,#3B6EA5) 100%);color:white;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;">
                <i class="fas fa-print"></i> Print / Save PDF
            </button>
            <button onclick="emailReport()" id="btnEmailRpt" style="padding:7px 14px;background:white;border:1px solid #D1D5DB;border-radius:7px;font-size:12px;font-weight:500;cursor:pointer;color:#374151;display:flex;align-items:center;gap:5px;">
                <i class="fas fa-envelope" style="color:#3B82F6;"></i> Send Email
            </button>
            <button onclick="smsReport()" id="btnSmsRpt" style="padding:7px 14px;background:white;border:1px solid #D1D5DB;border-radius:7px;font-size:12px;font-weight:500;cursor:pointer;color:#374151;display:flex;align-items:center;gap:5px;">
                <i class="fas fa-comment" style="color:#10B981;"></i> SMS Summary
            </button>
            <button onclick="closeReportModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9CA3AF;line-height:1;padding:4px 6px;">&times;</button>
        </div>
    </div>
    <!-- Modal body — report renders here -->
    <div id="reportBody" style="padding:28px 32px;background:#F9FAFB;flex:1;min-height:400px;">
        <div style="text-align:center;padding:60px 0;color:#9CA3AF;" id="reportLoadingMsg">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <div style="margin-top:12px;font-size:14px;">Loading report data…</div>
        </div>
    </div>
</div>
</div>

<!-- Hidden iframe for printing -->
<iframe id="printFrame" style="display:none;width:0;height:0;border:0;"></iframe>

<script>
/* ─────────────────────────────────────────────────
   BRAND / CONFIG injected from PHP
   ───────────────────────────────────────────────── */
const BRAND_NAME  = <?php echo json_encode($brandName); ?>;
const BRAND_COLOR = <?php echo json_encode($brandColor); ?>;

let currentRptClientId   = null;
let currentRptClientData = null; // { client, analytics, payments }

/* ─────────────────────────────────────────────────
   Entry points
   ───────────────────────────────────────────────── */
function generateReport() {
    const sel = document.getElementById('qCustomer');
    const id  = parseInt(sel.value);
    if (!id) { showToast('Please select a customer first.', 'warning'); return; }
    const name = sel.options[sel.selectedIndex].dataset.name || 'Customer';
    generateReportFor(id, name);
}

function generateReportFor(clientId, clientName) {
    // Sync the quick-generate dropdown if present
    const sel = document.getElementById('qCustomer');
    if (sel) sel.value = clientId;
    currentRptClientId = clientId;
    document.getElementById('rptModalTitle').textContent = clientName + ' — Report';
    document.getElementById('rptModalSub').textContent   = 'Generating…';
    document.getElementById('reportBody').innerHTML =
        '<div style="text-align:center;padding:60px 0;color:#9CA3AF;"><i class="fas fa-spinner fa-spin fa-2x"></i><div style="margin-top:12px;font-size:14px;">Loading report data…</div></div>';
    document.getElementById('btnPrintRpt').disabled  = true;
    document.getElementById('btnEmailRpt').disabled  = true;
    document.getElementById('btnSmsRpt').disabled    = true;
    document.getElementById('reportModal').style.display = 'flex';

    // Fetch analytics + payments in parallel
    Promise.all([
        fetch('api/clients/reports.php?client_id='  + clientId).then(r => r.json()),
        fetch('api/clients/payments.php?client_id=' + clientId).then(r => r.json())
    ])
    .then(([analytics, paymentsData]) => {
        if (!analytics.success) throw new Error(analytics.message || 'Failed to load analytics');
        currentRptClientData = {
            analytics: analytics,
            payments:  paymentsData.payments || []
        };
        const html = buildReportHTML(analytics, paymentsData.payments || [], clientName);
        document.getElementById('reportBody').innerHTML = html;
        document.getElementById('rptModalSub').textContent = 'Generated ' + new Date().toLocaleString();
        document.getElementById('btnPrintRpt').disabled = false;
        document.getElementById('btnEmailRpt').disabled = false;
        document.getElementById('btnSmsRpt').disabled   = false;
    })
    .catch(err => {
        document.getElementById('reportBody').innerHTML =
            '<div style="text-align:center;padding:40px;color:#EF4444;"><i class="fas fa-exclamation-circle fa-2x"></i><div style="margin-top:12px;">' + (err.message||'Error loading report') + '</div></div>';
        document.getElementById('rptModalSub').textContent = 'Error';
    });
}

/* ─────────────────────────────────────────────────
   Build report HTML
   ───────────────────────────────────────────────── */
function buildReportHTML(a, payments, clientName) {
    const now        = new Date().toLocaleDateString('en-KE', { day:'numeric', month:'long', year:'numeric' });
    const acctNum    = a.account_number  || ('—');
    const expiryStr  = a.expiry_date     ? new Date(a.expiry_date).toLocaleDateString('en-KE',{day:'numeric',month:'long',year:'numeric'}) : '—';
    const daysLeft   = a.days_to_expiry;
    const expiryNote = daysLeft === null ? '' : daysLeft < 0 ? (' <span style="color:#EF4444;font-size:11px;">(' + Math.abs(daysLeft) + 'd overdue)</span>') : (' <span style="color:#059669;font-size:11px;">(' + daysLeft + 'd remaining)</span>');

    const crColor = { Low:'#059669', Medium:'#D97706', High:'#DC2626' };
    const crBg    = { Low:'#D1FAE5', Medium:'#FEF3C7', High:'#FEE2E2' };
    const cr      = a.churn_risk || 'Low';
    const rlColor = a.payment_reliability >= 80 ? '#059669' : a.payment_reliability >= 50 ? '#D97706' : '#DC2626';

    // Payments table rows
    const payRows = payments.length ? payments.slice(0,20).map(p => {
        const conf = p.confirmed
            ? '<span style="color:#059669;font-weight:600;font-size:11px;">✓ Confirmed</span>'
            : '<span style="color:#D97706;font-size:11px;">Pending</span>';
        const date = p.paid_at ? new Date(p.paid_at).toLocaleDateString('en-KE') : '—';
        return `<tr>
            <td style="padding:8px 10px;border-bottom:1px solid #F3F4F6;font-size:12px;">${date}</td>
            <td style="padding:8px 10px;border-bottom:1px solid #F3F4F6;font-size:12px;">${p.method||'—'}</td>
            <td style="padding:8px 10px;border-bottom:1px solid #F3F4F6;font-size:12px;font-weight:600;">KES ${parseFloat(p.amount||0).toLocaleString()}</td>
            <td style="padding:8px 10px;border-bottom:1px solid #F3F4F6;font-size:12px;font-family:monospace;">${p.mpesa_code||p.reference||'—'}</td>
            <td style="padding:8px 10px;border-bottom:1px solid #F3F4F6;">${conf}</td>
        </tr>`;
    }).join('') : '<tr><td colspan="5" style="padding:16px;text-align:center;color:#9CA3AF;font-size:13px;">No payments on record.</td></tr>';

    // Monthly data mini-table
    const monthLabels = a.monthly_labels || [];
    const monthData   = a.monthly_data   || [];
    const maxVal      = Math.max(...monthData, 1);
    const monthBars   = monthLabels.map((lbl, i) => {
        const pct = Math.round((monthData[i] / maxVal) * 100);
        return `<div style="display:flex;flex-direction:column;align-items:center;gap:3px;flex:1;">
            <div style="font-size:11px;font-weight:600;color:#374151;">${monthData[i]>0?'KES '+parseFloat(monthData[i]).toLocaleString():''}</div>
            <div style="width:100%;background:#E5E7EB;border-radius:4px;height:60px;display:flex;align-items:flex-end;">
                <div style="width:100%;background:${BRAND_COLOR};border-radius:4px 4px 0 0;height:${pct}%;min-height:${monthData[i]>0?'4px':'0'};"></div>
            </div>
            <div style="font-size:10px;color:#6B7280;white-space:nowrap;">${lbl}</div>
        </div>`;
    }).join('');

    return `
    <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:800px;margin:0 auto;">

        <!-- Report Header -->
        <div style="background:${BRAND_COLOR};color:white;border-radius:10px;padding:20px 24px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;">
            <div>
                <div style="font-size:20px;font-weight:700;">${escHtmlJs(BRAND_NAME)}</div>
                <div style="font-size:12px;opacity:.8;margin-top:2px;">Customer Account Report</div>
            </div>
            <div style="text-align:right;font-size:12px;opacity:.85;">
                <div>Generated: ${now}</div>
                <div style="margin-top:3px;">Account: <strong>${escHtmlJs(acctNum)}</strong></div>
            </div>
        </div>

        <!-- Customer Info -->
        <div style="background:white;border:1px solid #E5E7EB;border-radius:10px;padding:18px 20px;margin-bottom:16px;">
            <div style="font-size:11px;font-weight:700;color:#9CA3AF;text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px;">Customer Information</div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                ${rptField('Full Name', escHtmlJs(clientName))}
                ${rptField('Account Number', escHtmlJs(acctNum))}
                ${rptField('Status', `<span style="padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:${cr==='High'?'#FEE2E2':cr==='Medium'?'#FEF3C7':'#D1FAE5'};color:${crColor[cr]||'#065F46'};">${escHtmlJs((a.status||'—').toUpperCase())}</span>`)}
                ${rptField('Package', escHtmlJs(a.package_name||a.subscription_plan||'—'))}
                ${rptField('Expiry Date', expiryStr + expiryNote)}
                ${rptField('Account Age', (a.account_age_days||0) + ' days')}
            </div>
        </div>

        <!-- Analytics Summary -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
            ${rptStat('Lifetime Value', 'KES ' + (a.lifetime_value||0).toLocaleString(), (a.total_payments||0) + ' payments total')}
            ${rptStat('Avg Monthly Spend', 'KES ' + (a.avg_monthly||0).toLocaleString(), 'Over last 6 months')}
            ${rptStat('Payment Reliability', (a.payment_reliability||0) + '%', 'Months with payment / 6', rlColor)}
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
            ${rptStat('Value Rank', '#' + (a.value_rank||'—') + ' of ' + (a.total_clients||1), 'By total spend')}
            ${rptStat('Churn Risk', cr, a.days_since_payment !== null ? a.days_since_payment + 'd since last payment' : 'No payments', crColor[cr]||'#374151')}
            ${rptStat('Days to Expiry', daysLeft !== null ? (daysLeft < 0 ? Math.abs(daysLeft)+'d overdue' : daysLeft+'d') : '—', 'Subscription status')}
        </div>

        <!-- Monthly bar chart -->
        <div style="background:white;border:1px solid #E5E7EB;border-radius:10px;padding:16px 20px;margin-bottom:16px;">
            <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:14px;">Monthly Payment History</div>
            <div style="display:flex;gap:8px;align-items:flex-end;height:100px;">${monthBars}</div>
        </div>

        <!-- Payment History Table -->
        <div style="background:white;border:1px solid #E5E7EB;border-radius:10px;overflow:hidden;margin-bottom:16px;">
            <div style="padding:12px 16px;border-bottom:1px solid #E5E7EB;font-size:12px;font-weight:700;color:#374151;">
                Recent Payments (Last 20)
            </div>
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#F9FAFB;">
                        <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:600;color:#9CA3AF;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Date</th>
                        <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:600;color:#9CA3AF;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Method</th>
                        <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:600;color:#9CA3AF;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Amount</th>
                        <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:600;color:#9CA3AF;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Ref / Code</th>
                        <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:600;color:#9CA3AF;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Status</th>
                    </tr>
                </thead>
                <tbody>${payRows}</tbody>
            </table>
        </div>

        <!-- Footer -->
        <div style="text-align:center;font-size:11px;color:#9CA3AF;padding:12px 0 4px;">
            This report was generated automatically on ${now} · ${escHtmlJs(BRAND_NAME)}
        </div>
    </div>`;
}

function rptField(label, value) {
    return `<div style="background:#F9FAFB;border-radius:7px;padding:9px 12px;">
        <div style="font-size:10px;font-weight:600;color:#9CA3AF;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;">${label}</div>
        <div style="font-size:13px;font-weight:600;color:#111827;">${value}</div>
    </div>`;
}

function rptStat(label, value, sub, color) {
    return `<div style="background:white;border:1px solid #E5E7EB;border-radius:9px;padding:12px 14px;">
        <div style="font-size:10px;font-weight:600;color:#9CA3AF;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;">${label}</div>
        <div style="font-size:19px;font-weight:700;color:${color||'#111827'};">${value}</div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">${sub}</div>
    </div>`;
}

function escHtmlJs(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ─────────────────────────────────────────────────
   Print / Save PDF
   ───────────────────────────────────────────────── */
function printReport() {
    const body   = document.getElementById('reportBody');
    const title  = document.getElementById('rptModalTitle').textContent;
    const w      = window.open('', '_blank', 'width=900,height=700');
    if (!w) { showToast('Please allow pop-ups to print.', 'warning'); return; }

    w.document.write(`<!DOCTYPE html><html><head>
    <meta charset="UTF-8">
    <title>${escHtmlJs(title)}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; background: white; }
        @page { margin: 15mm; }
        @media print { body { padding: 0; } }
    </style>
    </head><body>
    ${body.innerHTML}
    <script>window.onload = function(){ window.print(); }<\/script>
    </body></html>`);
    w.document.close();
}

/* ─────────────────────────────────────────────────
   Send Email
   ───────────────────────────────────────────────── */
function emailReport() {
    if (!currentRptClientId || !currentRptClientData) return;
    const opt = document.querySelector(`#qCustomer option[value="${currentRptClientId}"]`);
    const email = opt ? opt.dataset.email : '';
    if (!email) {
        showToast('This customer has no email address on file.', 'warning');
        return;
    }
    const btn = document.getElementById('btnEmailRpt');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
    btn.disabled = true;

    // Build full report HTML for email
    const reportHtml = buildFullEmailHTML(
        document.getElementById('reportBody').innerHTML,
        document.getElementById('rptModalTitle').textContent
    );
    const encoded = btoa(unescape(encodeURIComponent(reportHtml)));

    const fd = new FormData();
    fd.append('client_id', currentRptClientId);
    fd.append('report_html', encoded);
    fd.append('subject', 'Your Account Report — ' + new Date().toLocaleDateString('en-KE', {month:'long', year:'numeric'}));

    fetch('api/clients/email_report.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) showToast('Report sent to ' + email, 'success');
            else showToast('Email failed: ' + d.message, 'error');
        })
        .catch(() => showToast('Network error sending email.', 'error'))
        .finally(() => { btn.innerHTML = orig; btn.disabled = false; });
}

function buildFullEmailHTML(bodyInner, title) {
    return `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${escHtmlJs(title)}</title>
    <style>body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#F3F4F6;padding:20px;}</style>
    </head><body><div style="max-width:800px;margin:0 auto;background:white;border-radius:10px;padding:28px;border:1px solid #E5E7EB;">
    ${bodyInner}
    </div></body></html>`;
}

/* ─────────────────────────────────────────────────
   Send SMS Summary
   ───────────────────────────────────────────────── */
function smsReport() {
    if (!currentRptClientId || !currentRptClientData) return;
    const a = currentRptClientData.analytics;
    const name    = document.getElementById('rptModalTitle').textContent.replace(' — Report', '');
    const acct    = a.account_number || ('—');
    const ltv     = 'KES ' + parseFloat(a.lifetime_value||0).toLocaleString();
    const expiry  = a.expiry_date ? new Date(a.expiry_date).toLocaleDateString('en-KE') : 'N/A';
    const reliab  = (a.payment_reliability||0) + '%';
    const summary =
        `Dear ${name}, here is your account summary:\n` +
        `Account: ${acct}\n` +
        `Status: ${(a.status||'—').toUpperCase()}\n` +
        `Expiry: ${expiry}\n` +
        `Lifetime Value: ${ltv}\n` +
        `Payment Reliability: ${reliab}\n` +
        `— ${BRAND_NAME}`;

    // Use existing SMS modal
    if (typeof openSMSModal !== 'undefined') {
        // If we're on clients page, use that modal
        openSMSModal({ id: currentRptClientId, full_name: name });
        const msgEl = document.getElementById('smsMessage');
        if (msgEl) { msgEl.value = summary; if (document.getElementById('smsCharCount')) document.getElementById('smsCharCount').textContent = summary.length + ' characters'; }
    } else {
        // Send directly using inline SMS form
        showSmsForm(summary);
    }
}

function showSmsForm(prefillText) {
    const modal = document.getElementById('smsSummaryModal');
    if (modal) {
        document.getElementById('smsSummaryText').value = prefillText;
        modal.style.display = 'flex';
    }
}

/* ─────────────────────────────────────────────────
   Modal controls
   ───────────────────────────────────────────────── */
function closeReportModal() {
    document.getElementById('reportModal').style.display = 'none';
    currentRptClientId   = null;
    currentRptClientData = null;
}

document.getElementById('reportModal').addEventListener('click', function(e) {
    if (e.target === this) closeReportModal();
});

</script>

<?php include 'includes/footer.php'; ?>
