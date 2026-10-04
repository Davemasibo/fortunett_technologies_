<?php
require_once __DIR__.'/includes/db_master.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/onboarding.php';
redirectIfNotLoggedIn();
$st=$pdo->prepare('SELECT tenant_id FROM users WHERE id=?');
$st->execute([$_SESSION['user_id']]);
$tenantId=(int)$st->fetchColumn();
if (!$tenantId) { http_response_code(403); exit('No tenant assigned'); }
$progress=tenantOnboardingProgress($pdo,$tenantId);
include __DIR__.'/includes/header.php';
include __DIR__.'/includes/sidebar.php';
?>
<div class="main-content-wrapper"><main style="max-width:1000px;margin:auto;padding:32px">
<h1>Set up your workspace</h1>
<p><?= htmlspecialchars($progress['tenant']['company_name']) ?>: <?= $progress['completed'] ?> of <?= $progress['total'] ?> steps complete. Progress updates from your saved settings.</p>
<p>Trial accounts have no base monthly fee. Collection-based invoices start after successful customer collections; trial PPPoE fees count customers who paid during the month.</p>
<ol>
<?php foreach ($progress['steps'] as $step): ?>
<li style="margin:24px 0;padding:16px;border:1px solid #888;border-radius:8px">
<strong><?= $step['done'] ? 'Complete: ' : 'Next: ' ?><?= htmlspecialchars($step['title']) ?></strong>
<p><?= htmlspecialchars($step['detail']) ?></p>
<a href="<?= htmlspecialchars($step['url']) ?>">Open <?= htmlspecialchars($step['title']) ?></a>
</li>
<?php endforeach; ?>
</ol>
<h2>Connect your first MikroTik</h2>
<ol><li>Connect your router to the internet and open WinBox. Upgrade to RouterOS 7 or newer if needed.</li>
<li>Open <a href="mikrotik.php?open_modal=1">Add MikroTik</a>, choose a unique device name and copy its connection command into WinBox ? New Terminal.</li>
<li>Keep the setup window open. It checks the management connection before offering customer services.</li>
<li>Select Hotspot or PPPoE and your customer LAN bridge. Copy the service command into the terminal, then click Verify configuration.</li>
<li>Continue with packages, payment settings and a test customer below. Check that a customer can connect, pay and access the internet.</li></ol>
<p>If an import fails, keep the terminal error and use Repair management connection. Each router uses its own script. If two routers serve the same physical customer network, plan one DHCP gateway or separate VLANs before enabling Hotspot on both.</p>
<h2>Your devices</h2>
<p>For two routers, repeat setup for the second device in the same account. Each device needs its own generated script and VPN address. Do not reuse the first device’s script.</p>
<ul><?php foreach ($progress['routers'] as $router): ?>
<li><?= htmlspecialchars($router['name']) ?> — <?= htmlspecialchars($router['status']) ?> <button type="button" onclick="verifyRouter(<?= (int)$router['id'] ?>,this)">Verify device</button> <span id="verify-<?= (int)$router['id'] ?>" role="status"></span></li>
<?php endforeach; ?></ul>
<p><a href="mikrotik.php?open_modal=1">Add another MikroTik</a> · <a href="dashboard.php">Open dashboard</a></p>
<p>The dashboard shows PPPoE and Hotspot sessions for each device and totals across connected devices. Session totals can include the same customer connected on both routers. Financial collections are counted once per payment across the account.</p>
</main></div>
<script>
async function verifyRouter(id,button) {
    button.disabled=true;
    const output=document.getElementById('verify-'+id);
    output.textContent='Checking…';
    try {
        const body=new FormData(); body.append('router_id',id); body.append('services','');
        const response=await fetch('api/routers/verify_provisioning.php',{method:'POST',body});
        const result=await response.json();
        output.textContent=result.api_ok ? 'API connected. '+(result.checks||[]).map(c=>c.label+': '+(c.ok?'OK':'Needs attention')).join('; ') : (result.error||'Connection failed');
    } catch(e) { output.textContent='Verification unavailable. Try again.'; }
    finally { button.disabled=false; }
}
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
