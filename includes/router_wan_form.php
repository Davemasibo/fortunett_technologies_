<?php
// Shared by new-router onboarding and the existing-router WAN modal.
function renderRouterWanForm(string $prefix): void { ?>
<div class="wan-fields" data-prefix="<?= htmlspecialchars($prefix) ?>" style="display:grid;gap:10px;margin:14px 0;text-align:left;">
 <p>Choose the ISP handoff. An upstream bridge supplying ordinary Ethernet normally uses DHCP on ether1. For a bridge inside this router, enter its dedicated WAN bridge name.</p>
 <label>Uplink interface <input id="<?= $prefix ?>Interface" value="ether1" maxlength="64" required></label>
 <label>Connection type <select id="<?= $prefix ?>Mode" onchange="wanFields('<?= $prefix ?>')"><option value="dhcp">DHCP (default)</option><option value="static">Static IPv4</option><option value="pppoe">ISP PPPoE client</option></select></label>
 <label>VLAN ID (optional) <input id="<?= $prefix ?>Vlan" type="number" min="1" max="4094" placeholder="Blank for untagged Ethernet"></label>
 <div id="<?= $prefix ?>Static" hidden>
  <label>WAN IPv4/prefix <input id="<?= $prefix ?>Address" placeholder="192.168.1.2/24"></label>
  <label>Gateway <input id="<?= $prefix ?>Gateway" placeholder="192.168.1.1"></label>
 </div>
 <div id="<?= $prefix ?>Pppoe" hidden>
  <label>ISP username <input id="<?= $prefix ?>Username" autocomplete="off"></label>
  <label>ISP password <input id="<?= $prefix ?>Password" type="password" autocomplete="new-password"></label>
 </div>
 <label>DNS servers <input id="<?= $prefix ?>Dns" placeholder="Automatic; required for static IP"></label>
 <label>Customer LAN bridge <input id="<?= $prefix ?>Lan" maxlength="64" required placeholder="Exact existing customer bridge name"></label>
 <p style="font-size:12px;">The LAN bridge must already exist and be separate from the WAN. The script preserves bridge ports. Apply locally through a LAN port; changing WAN settings can interrupt management. Keep the downloaded script private because it contains setup credentials.</p>
</div>
<?php }
