<?php
function planPricingValidationError(array $input): ?string {
    if (!is_string($input['name'] ?? null) || trim($input['name']) === '' || strlen(trim($input['name'])) > 100) return 'Enter a plan name of up to 100 characters.';
    foreach (['pppoe_fee_per_user'=>'PPPoE fee','hotspot_commission_rate'=>'Hotspot commission','base_monthly_fee'=>'Router fee','sms_rate'=>'SMS fee'] as $key=>$label) {
        $value=filter_var($input[$key] ?? null,FILTER_VALIDATE_FLOAT);
        $max=$key==='hotspot_commission_rate' ? 100 : 99999999;
        if ($value===false || !is_finite((float)$value) || $value<0 || $value>$max) return $label.' must be a number between 0 and '.$max.'.';
        $precision=$key==='sms_rate' ? 4 : 2;
        if (abs($value-round($value,$precision))>0.00000001) return $label.' supports up to '.$precision.' decimal places.';
    }
    foreach (['max_clients'=>'Maximum clients','max_routers'=>'Maximum routers','trial_days'=>'Trial duration'] as $key=>$label) {
        if ($key!=='trial_days' && ($input[$key] ?? '')==='') continue;
        $value=filter_var($input[$key] ?? null,FILTER_VALIDATE_INT);
        $max=$key==='trial_days' ? 365 : 2147483647;
        if ($value===false || $value<0 || $value>$max) return $label.' must be a whole number between 0 and '.$max.'.';
    }
    return null;
}
