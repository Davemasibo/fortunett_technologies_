<?php
require_once __DIR__ . '/includes/auth.php';
redirectIfNotLoggedIn();
require_once __DIR__ . '/includes/db_master.php';
$st = $pdo->prepare('SELECT r.id,r.name FROM mikrotik_routers r JOIN users u ON u.tenant_id=r.tenant_id WHERE u.id=? ORDER BY r.name');
$st->execute([$_SESSION['user_id']]);
$routers = $st->fetchAll(PDO::FETCH_ASSOC);
include __DIR__.'/includes/header.php';
include __DIR__.'/includes/sidebar.php';
?>
<link rel="stylesheet" href="assets/css/setup-pages.css">
<div class="main-content-wrapper"><main class="workspace-page"><div class="page-heading"><h1>Hotspot locations</h1><a class="setup-button" href="mikrotik.php">Manage routers</a></div><section class="setup-card">
<p>Live traffic and connected customers by router interface. Add a location name to the interface comment in MikroTik. A shared switch uplink combines all APs behind it.</p>
<label>Router <select id="router"><option value="">Select router</option><?php foreach ($routers as $r): ?><option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach ?></select></label><button class="setup-button" id="refresh">Refresh</button>
<p id="status" role="status"></p><div class="table-wrap"><table><thead><tr><th>Location / interface</th><th>Link</th><th>Customers</th><th>Sessions</th><th>Received Mbps</th><th>Sent Mbps</th><th>Active-session download MB</th></tr></thead><tbody id="locations"></tbody></table></div>
<p>Rates appear after two samples, about ten seconds apart, and include all traffic on that interface. Counters restart when the router or interface resets. Session downloads cover currently connected sessions only. Unmapped clients need VLAN, CAPsMAN, or AP-specific telemetry for attribution.</p>
</section><section class="setup-card"><h2>Location sales</h2>
<label>Date <input id="sales-date" type="date" value="<?= date('Y-m-d') ?>"></label>
<p id="sales-status" role="status"></p>
<div class="table-wrap"><table><thead><tr><th>Purchase location</th><th>Completed sales</th><th>Revenue (KES)</th></tr></thead><tbody id="sales"></tbody></table></div>
<p>Sales are attributed to the interface observed when a new portal checkout starts. Unattributed sales include older purchases, manual payments, and purchases whose location could not be verified. That row covers the whole ISP, not just the selected router. Moving devices does not move earlier sales. Dates use Africa/Nairobi time.</p>
</section></main></div><script>
let previous=null, busy=false;
const router=document.getElementById('router'), status=document.getElementById('status');
async function refresh(){
 if(busy||!router.value)return;
 const id=router.value; busy=true;
 try{
  const response=await fetch('api/routers/hotspot_locations.php?router_id='+encodeURIComponent(id),{cache:'no-store'});
  const data=await response.json(); if(router.value!==id)return;
  if(!data.success)throw new Error(data.message||'Could not load traffic.');
  const body=document.getElementById('locations'); body.replaceChildren();
  for(const row of data.locations){
   const old=previous&&previous.locations.find(p=>p.interface===row.interface);
   const seconds=previous?data.sampled_at-previous.sampled_at:0;
   const rate=key=>old&&seconds>0&&row[key]!==null&&old[key]!==null&&row[key]>=old[key]?((row[key]-old[key])*8/seconds/1e6).toFixed(2):'—';
   const tr=document.createElement('tr');
   for(const value of [(row.location?row.location+' / ':'')+row.interface,row.running?'Up':'Down',row.customers,row.sessions,rate('rx_bytes'),rate('tx_bytes'),(row.session_download_bytes/1e6).toFixed(2)]){const td=document.createElement('td');td.textContent=value;tr.appendChild(td);}
   body.appendChild(tr);
  }
  previous=data;status.textContent='Updated '+new Date().toLocaleTimeString()+'. Unmapped sessions: '+data.unmapped_sessions;
 }catch(error){previous=null;status.textContent=error.message;}finally{busy=false;}
}
async function refreshSales(){
 const id=router.value,date=document.getElementById('sales-date').value;
 if(!id)return;
 const target=document.getElementById('sales-status');
 try{
  const response=await fetch('api/routers/hotspot_sales.php?router_id='+encodeURIComponent(id)+'&date='+encodeURIComponent(date),{cache:'no-store'});
  const data=await response.json();if(router.value!==id||document.getElementById('sales-date').value!==date)return;
  if(!data.success)throw new Error(data.message||'Could not load sales.');
  const body=document.getElementById('sales');body.replaceChildren();
  for(const row of data.sales){
   const tr=document.createElement('tr');
   const label=row.router_id===null?'Unattributed (whole ISP)':(row.location_name?row.location_name+' / ':'')+row.interface_name;
   for(const value of [label,row.sales,Number(row.revenue).toFixed(2)]){const td=document.createElement('td');td.textContent=value;tr.appendChild(td);}body.appendChild(tr);
  }
  target.textContent=data.sales.length?'Completed payments for '+date:'No completed payments for this date.';
 }catch(error){document.getElementById('sales').replaceChildren();target.textContent=error.message;}
}
router.addEventListener('change',()=>{previous=null;document.getElementById('locations').replaceChildren();document.getElementById('sales').replaceChildren();refresh();refreshSales();});
document.getElementById('refresh').addEventListener('click',()=>{refresh();refreshSales();});
document.getElementById('sales-date').addEventListener('change',refreshSales);
if(router.options.length>1){router.selectedIndex=1;refresh();refreshSales();}
setInterval(refresh,10000);
setInterval(refreshSales,60000);
</script>
<?php include __DIR__.'/includes/footer.php'; ?>
