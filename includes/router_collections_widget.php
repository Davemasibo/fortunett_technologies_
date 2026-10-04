<?php
// Router comparisons only help tenants with multiple configured devices.
try {
    $count=$db->prepare('SELECT COUNT(*) FROM mikrotik_routers WHERE tenant_id=?');
    $count->execute([$tenant_id]);
    if((int)$count->fetchColumn()<2)return;
} catch(Throwable $e) { error_log('Router comparison unavailable'); return; }
?>
<div class="status-card" style="margin-bottom:24px">
 <div class="card-header"><div><h3 class="card-title">Router collections comparison</h3><p class="card-subtitle">Compare collections across devices for the selected period.</p></div>
 <label>View <select id="collection-router" aria-label="Collections router" style="padding:8px;border-radius:8px"><option value="all">Compare routers</option></select></label></div>
 <div style="padding:20px;height:280px"><canvas id="routerCollectionsChart" aria-label="Collections comparison by router" role="img"></canvas></div>
 <p id="routerCollectionsStatus" role="status" style="padding:0 20px 20px"></p>
 <div style="padding:0 20px 20px"><a href="hotspot_locations.php">Explore Hotspot locations and interface traffic</a></div>
</div>
