<?php
require_once __DIR__.'/includes/db_master.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/onboarding.php';
if (!isLoggedIn()) $_SESSION['resume_onboarding']=true;
redirectIfNotLoggedIn();
$st=$pdo->prepare('SELECT tenant_id FROM users WHERE id=?');
$st->execute([$_SESSION['user_id']]);
$tenantId=(int)$st->fetchColumn();
if (!$tenantId) { http_response_code(403); exit('No tenant assigned'); }
$progress=tenantOnboardingProgress($pdo,$tenantId);
include __DIR__.'/includes/header.php';
include __DIR__.'/includes/sidebar.php';
?>
<link rel="stylesheet" href="assets/css/setup-pages.css">
<div class="main-content-wrapper"><main class="workspace-page">
<div class="page-heading"><div><h1>Get your network ready</h1><p><?= htmlspecialchars($progress['tenant']['company_name']) ?></p></div><a class="setup-button" href="settings.php#general">Back to settings</a></div>
<section class="setup-card"><h2><?= $progress['completed'] ?> of <?= $progress['total'] ?> steps complete</h2>
<div class="progress-track" role="progressbar" aria-label="Account setup" aria-valuemin="0" aria-valuemax="<?= $progress['total'] ?>" aria-valuenow="<?= $progress['completed'] ?>"><div class="progress-fill" style="width:<?= round(100*$progress['completed']/$progress['total']) ?>%"></div></div>
<p>Progress reflects saved account data. Live device verification and customer tests are required before field deployment.</p>
<?php foreach ($progress['steps'] as $step): if (!$step['done']): ?><a class="setup-button primary" href="<?= htmlspecialchars($step['url']) ?>">Continue: <?= htmlspecialchars($step['title']) ?></a><?php break; endif; endforeach; ?></section>
<section class="setup-card"><h2>Connect your first MikroTik</h2><p>Have your router connected to the internet, a laptop, WinBox and the router login ready. Use an Ethernet cable while changing the customer network.</p>
<ol><li><strong>Prepare:</strong> If this router already serves customers, save and download a backup in WinBox &rarr; Files &rarr; Backup before applying scripts. Schedule changes when a brief customer interruption is acceptable.</li><li><strong>Open WinBox:</strong> Select your router in Neighbors and sign in. If its IP does not work, try its MAC address from the same local network.</li><li><strong>Connect:</strong> Add a uniquely named device below, copy its command and paste into WinBox &rarr; New Terminal. Keep the setup page open.</li><li><strong>Configure:</strong> Choose Hotspot for Wi-Fi/voucher users or PPPoE for broadband customers, and select the bridge used by those customers.</li><li><strong>Verify:</strong> Run configuration verification. Follow any failed check's instructions and retry.</li><li><strong>Test:</strong> Create a package and customer, then connect their device and check access, speed and expiry.</li></ol>
<a class="setup-button primary" href="mikrotik.php?open_modal=1">Connect MikroTik</a>
<details><summary>Stuck during setup?</summary><p><strong>Router not found:</strong> Connect your laptop to the router LAN, reopen WinBox and check Neighbors.</p><p><strong>No router contact:</strong> Confirm the router itself has internet and working DNS. Check the terminal for a fetch or import error; your laptop having internet is not enough.</p><p><strong>Device-mode restriction:</strong> Follow the terminal instructions to enable the required features and confirm physically at the router within the countdown.</p><p><strong>Router connected, service missing:</strong> Open Routers &rarr; Provision for that device, select the service and correct customer bridge, apply the script and verify again.</p></details></section>
<div class="setup-grid"><?php foreach ($progress['steps'] as $index=>$step): ?>
<section class="setup-card <?= $step['done']?'complete':'' ?>"><h2><span class="step-number"><?= $step['done']?'&#10003;':$index+1 ?></span><?= htmlspecialchars($step['title']) ?></h2><small><?= $step['done']?'Complete':'Action needed' ?></small><p><?= htmlspecialchars($step['detail']) ?></p><a class="setup-button" href="<?= htmlspecialchars($step['url']) ?>"><?= $step['done']?'Review':'Set up' ?></a></section>
<?php endforeach; ?></div>
<section class="setup-card"><div class="page-heading"><h2>Your devices</h2><a class="setup-button" href="mikrotik.php?open_modal=1">Add MikroTik</a></div><p>Use a separate management script for each device. Select its customer LAN bridge, deploy Hotspot or PPPoE, then verify.</p>
<div class="setup-grid"><?php foreach ($progress['routers'] as $router): ?><div class="setup-card"><h2><?= htmlspecialchars($router['name']) ?></h2><p><?= onboardingRouterVerified($router,$progress['checks'][(int)$router['id']] ?? []) ? 'Services verified' : 'Setup unfinished' ?> &middot; Last contact: <?= htmlspecialchars($router['last_seen'] ?: 'Not yet connected') ?></p><button class="setup-button" type="button" data-services="<?= htmlspecialchars($router['service_types'] ?? '') ?>" onclick="verifyRouter(<?= (int)$router['id'] ?>,this)">Verify configuration</button> <a class="setup-button" href="mikrotik.php?resume_router=<?= (int)$router['id'] ?>">Continue router setup</a><span id="verify-<?= (int)$router['id'] ?>" role="status">Live verification required.</span><p><?= htmlspecialchars($progress['checks'][(int)$router['id']]['failure_summary'] ?? '') ?></p></div><?php endforeach; ?><?php if (!$progress['routers']): ?><p>Add your first MikroTik to begin.</p><?php endif; ?></div></section>
<section class="setup-card"><h2>Before field deployment</h2><ol><li>Restart each router and confirm its management tunnel reconnects and configuration verification passes.</li><li>Join the tenant-branded open Wi-Fi or customer LAN. Check the captive portal plans and customer portal link.</li><li>Buy a package on each router. Confirm one receipt, internet activation and the recorded router collection.</li><li>Test download and upload under load. Verify the live queue matches the package; confirm expiry stops internet access and reconnect does not restore expired access.</li><li>Check WAN, cabling and power on site. Separate customer networks or VLANs when both routers run DHCP and Hotspot.</li></ol><p>Save the test results for each router before calling deployment complete. Wi-Fi is open; paid internet access is controlled by the captive portal.</p></section>
<section class="setup-card"><h2>Trial billing</h2><p>No base monthly fee applies during the trial. Collection-based invoices start after successful customer collections.</p><a class="setup-button" href="dashboard.php">Open dashboard</a></section>
</main></div>
<script>
async function verifyRouter(id,button) {
    button.disabled=true;
    const output=document.getElementById('verify-'+id);
    output.textContent='Checking configuration...';
    try {
        const body=new FormData(); body.append('router_id',id); body.append('services',button.dataset.services || '');
        const response=await fetch('api/routers/verify_provisioning.php',{method:'POST',body});
        const result=await response.json();
        if (result.all_ok) { window.location.reload(); return; }
        output.textContent=result.api_ok ? (result.all_ok ? 'Configuration verified. ' : 'Needs attention. ')+(result.checks||[]).map(c=>c.label+': '+(c.ok?'OK':'Needs attention')).join('; ') : (result.error||'Connection failed');
    } catch(e) { output.textContent='Verification unavailable. Try again.'; }
    finally { button.disabled=false; }
}
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
