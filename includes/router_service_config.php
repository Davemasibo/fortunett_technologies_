<?php
/** Generate service setup without replacing existing bridge membership. */
function routerServiceString(string $value): string
{
    return '"' . str_replace(['\\', '"', '$', "\r", "\n"], ['\\\\', '\\"', '\\$', '', ''], $value) . '"';
}

/** Stop before changing a router when device-mode blocks required features. */
function routerDeviceModeGuard(bool $hotspot = false): string
{
    $features=$hotspot ? ['scheduler','fetch','hotspot'] : ['scheduler','fetch'];
    $parts=[':local blockedFeatures ""'];
    foreach ($features as $feature) {
        // Older RouterOS versions may not expose per-feature device-mode flags.
        $parts[]=':local featureAllowed true';
        $parts[]=':do {:set featureAllowed [/system device-mode get '.$feature.']} on-error={}';
        $parts[]=':if ($featureAllowed=false) do={:set blockedFeatures ($blockedFeatures . " '.$feature.'")}';
    }
    $enable=implode(' ',array_map(fn($feature)=>$feature.'=yes',$features));
    $message='Device-mode blocks required features. Run /system device-mode update '.$enable.' then physically power-cycle within the displayed countdown and retry setup. Blocked:';
    $parts[]=':if ([:len $blockedFeatures]>0) do={:error ('.routerServiceString($message).' . $blockedFeatures)}';
    return '{ '.implode('; ',$parts).'; }';
}

function mergeRouterServiceTypes(string $existing, array $requested): string
{
    $types = array_filter(array_merge(explode(',', $existing), $requested), fn($s) => in_array($s, ['hotspot', 'pppoe'], true));
    return implode(',', array_values(array_unique($types)));
}

function buildRouterServiceCommand(array $services, bool $noSharing, string $portalHost = '', string $loginServeUrl = '', string $portalIp = '', string $bridgeName = '', string $companyName = ''): string
{
    $sharedUsers = $noSharing ? '1' : 'unlimited';
    $parts = [routerDeviceModeGuard(in_array('hotspot',$services,true))];
    $parts[] = '/ip firewall filter set [find where action=fasttrack-connection] disabled=yes';
    $parts[] = '/ip firewall connection remove [find where fasttrack=yes]';
    $parts[] = ':local bn ""';
    $parts[] = ':local newBridge false';
    if ($bridgeName !== '') {
        $parts[] = ':set bn ' . routerServiceString($bridgeName);
        $parts[] = ':local targetBridge [/interface bridge find where name=$bn]';
        $parts[] = ':if ([:len $targetBridge]=0) do={:error ("LAN bridge not found: " . $bn . ". Run /interface bridge print and use its exact name") }';
        $parts[] = ':if ([/interface bridge get ($targetBridge->0) disabled]=true) do={:error ("LAN bridge is disabled: " . $bn)}';
    } else {
        $parts[] = ':local bf [/interface bridge find where disabled=no]';
        $parts[] = ':if ([:len $bf]>1) do={:error "Multiple bridges found. Enter the LAN bridge name in the portal"}';
        $parts[] = ':if ([:len $bf]=1) do={:set bn [/interface bridge get ($bf->0) name]} else={/interface bridge add name=bridge-local auto-mac=yes comment="FortuNett-Bridge"; :set bn "bridge-local"; :set newBridge true}';
    }
    // Preserve existing bridge membership and interface addresses when adding a
    // service later. Only a newly created bridge needs initial LAN membership.
    $parts[] = ':if ($newBridge) do={:foreach i in=[/interface ethernet find where name!="ether1"] do={:local n [/interface ethernet get $i name]; :if ([:len [/interface bridge port find where interface=$n]]=0) do={/interface bridge port add bridge=$bn interface=$n}}; :do {:foreach w in=[/interface wireless find] do={:local wn [/interface wireless get $w name]; :if ([:len [/interface bridge port find where interface=$wn]]=0) do={/interface bridge port add bridge=$bn interface=$wn}}} on-error={}}';

    if (in_array('pppoe', $services, true)) {
        $parts[] = ':do {/interface pppoe-server server remove [find service-name=pppoe-service]} on-error={}';
        $parts[] = ':do {/ip pool remove [find name=pppoe-pool]} on-error={}';
        $parts[] = ':do {/ppp profile remove [find name=pppoe-profile]} on-error={}';
        $parts[] = '/ip pool add name=pppoe-pool ranges=10.10.10.2-10.10.10.254';
        $parts[] = '/ppp profile add name=pppoe-profile local-address=10.10.10.1 remote-address=pppoe-pool dns-server=8.8.8.8,8.8.4.4';
        $parts[] = '/interface pppoe-server server add service-name=pppoe-service interface=$bn default-profile=pppoe-profile disabled=no';
        // NAT for the PPPoE pool. Without this the customer authenticates, gets an
        // address, and has no internet — the classic "connected but no data" call.
        // The hotspot block below adds its own rule for 10.5.50.0/24; a rule scoped
        // to that subnet does nothing for PPPoE, so each service needs its own.
        $parts[] = ':do {/ip firewall nat remove [find comment="FortuNett-PPPoE-NAT"]} on-error={}';
        $parts[] = '/ip firewall nat add chain=srcnat src-address=10.10.10.0/24 action=masquerade comment="FortuNett-PPPoE-NAT"';
    }

    if (in_array('hotspot', $services, true)) {
        $parts[] = ':do {/ip hotspot remove [find name=hotspot1]} on-error={}';
        $parts[] = ':do {/ip hotspot profile remove [find name=hsprof1]} on-error={}';
        $parts[] = ':do {/ip pool remove [find name=hs-pool]} on-error={}';
        $parts[] = ':do {/ip address remove [find address="10.5.50.1/24"]} on-error={}';
        $parts[] = '/ip pool add name=hs-pool ranges=10.5.50.2-10.5.50.254';
        $parts[] = '/ip address add address=10.5.50.1/24 interface=$bn';
        // DHCP server for the hotspot subnet. WITHOUT this, a client that connects
        // never gets a 10.5.50.x lease, so it can't reach the gateway (10.5.50.1)
        // and the captive portal never loads — the #1 reason "the portal isn't
        // pushing". Remove any DHCP already bound to the bridge first (e.g. the
        // factory 'defconf' server on 192.168.88.0/24, which would hand out a
        // foreign IP). dns-server is the gateway so the hotspot's DNS proxy can
        // intercept lookups and trigger the OS captive-portal detection redirect.
        $parts[] = ':do {/ip dhcp-server remove [find interface=$bn]} on-error={}';
        $parts[] = ':do {/ip dhcp-server remove [find name=hs-dhcp]} on-error={}';
        $parts[] = ':do {/ip dhcp-server network remove [find address="10.5.50.0/24"]} on-error={}';
        $parts[] = '/ip dhcp-server add name=hs-dhcp interface=$bn address-pool=hs-pool lease-time=1h disabled=no';
        $parts[] = '/ip dhcp-server network add address=10.5.50.0/24 gateway=10.5.50.1 dns-server=10.5.50.1';
        // The hotspot DNS proxy forwards to the router's own resolver — make sure it
        // has upstreams and answers queries from hotspot clients.
        $parts[] = '/ip dns set servers=8.8.8.8,8.8.4.4 allow-remote-requests=yes';
        // html-directory MUST be "hotspot" (not "flash/hotspot"): RouterOS 7 prepends
        // flash/ internally, so "flash/hotspot" becomes flash/flash/hotspot and the
        // hotspot can't find login.html → it serves a 404 instead of the portal.
        // "hotspot" resolves to flash/hotspot, which is where the fetch below writes.
        $parts[] = '/ip hotspot profile add name=hsprof1 dns-name=hotspot.fortunett.com hotspot-address=10.5.50.1 html-directory=hotspot login-by=http-pap,cookie';
        // shared-users only. The default profile must carry NO rate-limit: any user
        // that falls back to it would silently receive that speed regardless of the
        // package they paid for, and a hard-coded 5M/5M here is a speed nobody sold.
        // Caps live on the per-package profile that autoProvisionClient() creates.
        $parts[] = '/ip hotspot user profile set [find name=default] rate-limit="" shared-users=' . $sharedUsers;
        $parts[] = '/ip hotspot add name=hotspot1 interface=$bn address-pool=hs-pool profile=hsprof1 disabled=no';
        if ($companyName!=='') {
            require_once __DIR__.'/hotspot_wireless.php';
            $parts[]=hotspotWirelessCommand($companyName);
        }
        $parts[] = ':do {/ip firewall nat remove [find comment="FortuNett-Hotspot-NAT"]} on-error={}';
        $parts[] = '/ip firewall nat add chain=srcnat src-address=10.5.50.0/24 action=masquerade comment="FortuNett-Hotspot-NAT"';

        if ($portalHost) {
            $parts[] = ':do {/ip hotspot walled-garden remove [find comment="FortuNett-Portal"]} on-error={}';
            $parts[] = '/ip hotspot walled-garden add dst-host="' . $portalHost . '" comment="FortuNett-Portal"';
        }
        if ($portalIp) {
            // dst-host matches the HTTP Host header only. The IP entry is what lets
            // an unauthenticated client open the portal over HTTPS and complete an
            // STK push; without it the page loads and paying silently fails.
            $parts[] = ':do {/ip hotspot walled-garden ip remove [find comment="FortuNett-Portal-IP"]} on-error={}';
            $parts[] = '/ip hotspot walled-garden ip add dst-address=' . $portalIp . '/32 action=accept comment="FortuNett-Portal-IP"';
        }
        if ($loginServeUrl) {
            // Use the directory the profile actually reports on this device.
            $parts[] = ':local portalDir [/ip hotspot profile get [find name=hsprof1] html-directory]';
            $parts[] = ':if ([:len [/file find where name=$portalDir]]=0) do={/file add name=$portalDir type=directory}';
            $parts[] = '/tool fetch url=' . routerServiceString($loginServeUrl) . ' dst-path=($portalDir . "/login.html") check-certificate=no';
            foreach ([
                'redirect.html'=>'<meta http-equiv="refresh" content="0;url=/login">',
                'alogin.html'=>'<meta http-equiv="refresh" content="0;url=$(link-orig)">',
                'logout.html'=>'<meta http-equiv="refresh" content="0;url=/login">',
                'error.html'=>'<html><body><h3>$(error)</h3><a href="$(link-login)">Back to login</a></body></html>',
            ] as $file=>$contents) {
                $parts[]=':local supportPath ($portalDir . "/'.$file.'"); :local supportFile [/file find where name=$supportPath]; :if ([:len $supportFile]=0) do={/file add name=$supportPath contents='.routerServiceString($contents).'} else={/file set $supportFile contents='.routerServiceString($contents).'}';
            }
        }
    }

    // One physical line — the entire configuration in a single copy/paste.
    // An explicit block keeps locals in one scope even in the interactive
    // terminal, where each submitted line otherwise has its own local scope.
    return '{ ' . implode('; ', $parts) . '; }';

}
