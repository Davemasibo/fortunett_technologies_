from pathlib import Path
p=Path('hotspot_diagnostics.php');s=p.read_text(encoding='utf-8')
s=s.replace("$pageTitle = 'Hotspot Diagnostics';", "if (empty($_SESSION['hotspot_diagnostics_csrf'])) $_SESSION['hotspot_diagnostics_csrf'] = bin2hex(random_bytes(32));\n$pageTitle = 'Hotspot Diagnostics';")
a=s.index('<style>');b=s.index('</style>',a)+len('</style>');s=s[:a]+'<link rel="stylesheet" href="assets/diagnostics.css?v=20261013">'+s[b:]
s=s.replace('<h1>Hotspot Diagnostics</h1>', '<div class="hd-eyebrow">Network health / Hotspot</div><h1>Get hotspot customers connected</h1>')
s=s.replace("Verify each router's hotspot server, login page, and client provisioning in real time.", 'Check the LAN, payment portal and paid access separately. Each failed check shows the next action.')
s=s.replace(' Verify All',' Run all checks')
a=s.index('  <!-- Payment');b=s.index('  <?php if (empty($routers)):',a);auto=s[a:b];s=s[:a]+'''  <div class="hd-guide" aria-label="Diagnostic workflow">
    <strong>Check in this order</strong>
    <span><span class="step">1</span>Router &amp; customer IP</span>
    <span><span class="step">2</span>Portal &amp; packages</span>
    <span><span class="step">3</span>Payment &amp; access</span>
    <span><span class="step">4</span>Verify on Wi-Fi</span>
  </div>
''' +s[b:]
auto=auto.replace('Payment ? Access Automation','Payment &amp; access records')
auto=auto.replace('Every link that has to hold for a customer to pay and be connected without anyone touching anything. Checks are scoped to how <em>you</em> collect — anything shared across all ISPs is grouped separately and reported to FortuNett, not to you.', 'Hotspot payment evidence for this tenant. Expired access is shown separately from current access problems; platform issues show who handles them.')
# Move tenant payment evidence below live router checks.
pos=s.index('  <!--',s.index('id="hd-grid"')) if False else s.index('  <div class="hd-lookup')
s=s[:pos]+auto+s[pos:]
s=s.replace('<div class="hd-sec">','<details class="hd-sec hd-details"><summary>Show detailed checks</summary>',2)
# Close the two advanced sections, not router card or outer layout.
s=s.replace('id="coex-<?= (int)$r[\'id\'] ?>"></div>\n        </div>', 'id="coex-<?= (int)$r[\'id\'] ?>"></div>\n        </details>')
s=s.replace('id="vpn-<?= (int)$r[\'id\'] ?>"></div>\n        </div>', 'id="vpn-<?= (int)$r[\'id\'] ?>"></div>\n        </details>')
s=s.replace('          <button class="hd-act primary" onclick="recheck', '          <button class="hd-act primary" onclick="recheck',1)
s=s.replace('        <div class="hd-actions">', '''        <div class="hd-result" id="repair-result-<?= (int)$r['id'] ?>" role="status" aria-live="polite"></div>
        <div class="hd-actions">
          <button class="hd-act deploy" id="portal-repair-<?= (int)$r['id'] ?>" hidden onclick="repairPortal(<?= (int)$r['id'] ?>, this)">Repair portal access</button>''',1)
s=s.replace("el.innerHTML = '<span class=\"v-icon\">' + icon + '</span><div><h4>' + title + '</h4><p>' + (msg || '') + '</p></div>';", "el.setAttribute('role', 'status');\n    el.innerHTML = '<span class=\"v-icon\" aria-hidden=\"true\">' + icon + '</span><div><h4>' + escHtml(title) + '</h4><p>' + escHtml(msg || '') + '</p></div>';")
a=s.index('    let html =',s.index('function renderChecks'));b=s.index('\n}\n\nfunction row(',a)
s=s[:a]+'''    if (d.checks && d.verdict) {
        const v = d.verdict;
        setVerdict(verdict, v.level, VERDICT_ICON[v.level] || VERDICT_ICON.warn, v.title, v.message);
        checks.innerHTML = row('Router API', 'ok', 'Authenticated') + d.checks.map(c => rowWide(c.label, c.level, c.detail, c.action)).join('');
        checks.insertAdjacentHTML('beforeend', row('Hotspot accounts', 'skip', (d.router_users ?? '?') + ' on router; ' + d.db_active + ' active accounts in tenant')
            + row('Live hotspot sessions', 'skip', d.active_sessions === null ? 'Could not read sessions' : d.active_sessions + ' connected now'));
        const portal = d.checks.find(c => c.id === 'portal');
        document.getElementById('portal-repair-' + routerId).hidden = !portal || portal.level !== 'fail';
        if (d.checked_at) checks.insertAdjacentHTML('beforeend', '<div class="hd-timestamp">Checked ' + escHtml(new Date(d.checked_at).toLocaleString()) + '</div>');
    } else {
        setVerdict(verdict, 'warn', VERDICT_ICON.warn, 'Incomplete diagnostic response', 'Reload this page and run the checks again.');
    }
''' +s[b:]
s=s.replace("fetch('api/diagnostics/automation_chain.php', { method: 'POST' })", "fetch('api/diagnostics/automation_chain.php', { method: 'POST', body: new URLSearchParams({service: 'hotspot'}) })")
s=s.replace("g.checks.map(c => rowWide(c.label, c.level, c.detail, c.action)).join('')", "g.checks.map(c => rowWide(c.label, c.level, c.detail, c.action) + actionLink(c)).join('')")
s=s.replace("const mkUser   = d.db_username ||", "const mkUser   = d.db_username ? escHtml(d.db_username) :")
s=s.replace("return '<div class=\"hd-check\"><span class=\"lbl\">' + label +", "return '<div class=\"hd-check\"><span class=\"lbl\">' + escHtml(label) +")
s=s.replace("badge.toUpperCase()", "statusLabel(badge)").replace("level.toUpperCase()", "statusLabel(level)")
s=s.replace("// Auto-run on load", '''const diagnosticsCsrf = <?= json_encode($_SESSION['hotspot_diagnostics_csrf']) ?>;
function statusLabel(level) { return ({ok:'PASS', warn:'REVIEW', fail:'FIX', skip:'INFO'})[level] || 'INFO'; }
function actionLink(c) {
    if (c.level === 'ok') return '';
    let url = '', text = '';
    if (/credentials|callback|collect|C2B/i.test(c.label)) { url='settings.php'; text='Open payment settings'; }
    else if (/package|speed/i.test(c.label)) { url='packages.php'; text='Review packages'; }
    else if (/customer|access records/i.test(c.label)) { url='clients.php'; text='Review clients'; }
    else if (/matched|transactions/i.test(c.label)) { url='payments.php'; text='Review payments'; }
    else if (/target/i.test(c.label)) { url='mikrotik.php'; text='Open routers'; }
    return url ? '<a class="hd-btn" href="' + url + '">' + text + '</a>' : '';
}
function repairPortal(routerId, btn) {
    const output = document.getElementById('repair-result-' + routerId);
    btn.disabled = true; output.className='hd-result'; output.textContent='Updating portal allow rules…';
    fetch(checkUrl('repair_hotspot_portal.php'), {method:'POST', body:new URLSearchParams({router_id:routerId, csrf_token:diagnosticsCsrf})})
        .then(r=>r.json()).then(d=>{
            output.className='hd-result' + (d.success ? '' : ' error');
            output.textContent=[d.message, ...(d.applied || [])].join(' ');
            recheck(routerId);
        }).catch(e=>{output.className='hd-result error'; output.textContent='Repair failed: ' + e.message;})
        .finally(()=>{btn.disabled=false;});
}
// Auto-run on load''')
# Keep visible results even when the global toast helper is unavailable.
s=s.replace("const lines = [].concat(d.applied || [], d.skipped || [], d.failed || []);", "const lines = [].concat(d.applied || [], d.skipped || [], d.failed || []);\n            document.getElementById('repair-result-' + routerId).textContent = [d.message || d.error, ...lines].filter(Boolean).join(' ');")
s=s.replace("if (d.success) setTimeout(() => recheck(routerId), 1000);", "document.getElementById('repair-result-' + routerId).textContent = d.message || 'Deployment finished; run checks to verify.';\n            if (d.success) setTimeout(() => recheck(routerId), 1000);")
p.write_text(s,encoding='utf-8')

