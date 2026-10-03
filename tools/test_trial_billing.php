<?php
require_once __DIR__.'/../includes/trial_billing.php';
function assertTrial(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$trial=platformTrialCharges('trial',0,15,0,1500);
assertTrial(!$trial['eligible'] && $trial['base_fee']===0.0 && $trial['users']===0,'Trial with users but no revenue creates no invoice');
$trial=platformTrialCharges('trial',1200,15,2,1500);
assertTrial($trial['eligible'] && $trial['base_fee']===0.0 && $trial['users']===2,'Revenue trial bills paying PPPoE customers and waives base fee');
$trial=platformTrialCharges('trial',500,15,0,1500);
assertTrial($trial['eligible'] && $trial['users']===0,'Hotspot-only trial does not charge unpaid PPPoE users');
$active=platformTrialCharges('active',0,15,0,1500);
assertTrial($active['eligible'] && $active['users']===15 && $active['base_fee']===1500.0,'Active subscription preserves contracted plan charges');
