<?php
// Shared by onboarding and the existing-device Internet setup dialog.
function renderRouterWanForm(string $prefix): void { ?>
<div class="wan-fields" data-prefix="<?= htmlspecialchars($prefix) ?>">
 <section class="wan-section" aria-labelledby="<?= $prefix ?>ConnectionTitle">
  <div class="wan-section-heading"><span class="wan-step-number">1</span><div><h4 id="<?= $prefix ?>ConnectionTitle">How does your provider connect you?</h4><p>Most connections use Automatic. Use the other options only if your provider gave you those details.</p></div></div>
  <input type="hidden" id="<?= $prefix ?>Mode" value="dhcp">
  <fieldset class="wan-connection-options"><legend class="wan-sr-only">Internet connection type</legend>
   <?php foreach (['dhcp'=>['Automatic','No login details needed','Recommended'],'static'=>['Fixed IP address','Your provider gave you IP settings',''],'pppoe'=>['Provider login','Your provider gave you a username and password','']] as $mode=>$option): ?>
   <label class="wan-option"><input type="radio" name="<?= $prefix ?>Connection" value="<?= $mode ?>" <?= $mode==='dhcp'?'checked':'' ?> onchange="document.getElementById('<?= $prefix ?>Mode').value=this.value;wanFields('<?= $prefix ?>')"><span><strong><?= $option[0] ?></strong><small><?= $option[1] ?></small><?php if ($option[2]): ?><em><?= $option[2] ?></em><?php endif; ?></span></label>
   <?php endforeach; ?>
  </fieldset>
  <div class="wan-inline-tip" id="<?= $prefix ?>ModeHint">Connect your provider's cable to port <strong>ether1</strong>. Automatic also works when their equipment supplies a bridged Ethernet connection.</div>
  <div id="<?= $prefix ?>Static" class="wan-input-grid" hidden>
   <div class="wan-field"><label for="<?= $prefix ?>Address">IP address and prefix <span>Required</span></label><input type="text" id="<?= $prefix ?>Address" placeholder="For example, 192.168.1.2/24" autocomplete="off"><small>Copy the address exactly as your provider supplied it.</small></div>
   <div class="wan-field"><label for="<?= $prefix ?>Gateway">Gateway <span>Required</span></label><input type="text" id="<?= $prefix ?>Gateway" placeholder="For example, 192.168.1.1" autocomplete="off"></div>
  </div>
  <div id="<?= $prefix ?>Pppoe" class="wan-input-grid" hidden>
   <div class="wan-field"><label for="<?= $prefix ?>Username">Provider username <span>Required</span></label><input type="text" id="<?= $prefix ?>Username" autocomplete="off" placeholder="Username from your provider"></div>
   <div class="wan-field"><label for="<?= $prefix ?>Password">Provider password <span>Required</span></label><input id="<?= $prefix ?>Password" type="password" autocomplete="new-password" placeholder="Password from your provider"><small>Enter it each time you prepare a new installer.</small></div>
  </div>
 </section>
 <section class="wan-section" aria-labelledby="<?= $prefix ?>LanTitle">
  <div class="wan-section-heading"><span class="wan-step-number">2</span><div><h4 id="<?= $prefix ?>LanTitle">Choose your customer network</h4><p>This is the router network your customers connect to.</p></div></div>
  <div class="wan-field"><label for="<?= $prefix ?>Lan">Customer network (LAN bridge) <span>Required</span></label><input type="text" id="<?= $prefix ?>Lan" maxlength="64" list="<?= $prefix ?>Bridges" required placeholder="Choose or enter your customer bridge" autocomplete="off"><datalist id="<?= $prefix ?>Bridges"></datalist><small id="<?= $prefix ?>BridgeHint">Use the exact existing bridge name. It must be separate from the provider's connection.</small></div>
  <details class="wan-help"><summary>Where do I find this name?</summary><p>Open WinBox → Bridge and copy the name of the bridge used by your customers. If the router is online, available names appear as you type. If you are unsure which bridge serves customers, ask your installer before continuing.</p></details>
 </section>
 <details class="wan-advanced" id="<?= $prefix ?>Advanced">
  <summary><span>Advanced connection settings<small>Different uplink port, WAN bridge, VLAN or DNS</small></span><span class="wan-optional">Optional</span></summary>
  <div class="wan-input-grid">
   <div class="wan-field"><label for="<?= $prefix ?>Interface">Provider port or WAN bridge</label><input type="text" id="<?= $prefix ?>Interface" value="ether1" maxlength="64" required><small>Keep ether1 unless your installer uses another port or a dedicated WAN bridge.</small></div>
   <div class="wan-field"><label for="<?= $prefix ?>Vlan">VLAN ID</label><input id="<?= $prefix ?>Vlan" type="number" min="1" max="4094" placeholder="Leave empty unless supplied"><small>Only enter a number your provider gave you.</small></div>
   <div class="wan-field wan-field-wide"><label for="<?= $prefix ?>Dns">DNS servers <span id="<?= $prefix ?>DnsRequired">Optional</span></label><input type="text" id="<?= $prefix ?>Dns" placeholder="Automatic from your provider"><small>For a fixed IP address, enter your provider's DNS servers, separated by commas.</small></div>
  </div>
 </details>
 <div class="wan-install-note"><i class="fas fa-circle-info" aria-hidden="true"></i><p>You can prepare the installer while the router is offline. Apply it onsite through a customer LAN port, then return here to check the connection.</p></div>
</div>
<?php }
