<link rel="stylesheet" href="expiry-modal.css">
<div id="expiryModal" class="expiry-overlay" style="display:none" role="dialog" aria-modal="true" aria-labelledby="expiryTitle">
  <div class="expiry-card">
    <header class="expiry-header"><div><p class="expiry-eyebrow">CUSTOMER ACCESS</p><h2 id="expiryTitle">Manage expiry</h2><p id="expiryCustomerName"></p></div><button type="button" class="expiry-close" onclick="closeExpiryModal()" aria-label="Close expiry dialog">&times;</button></header>
    <div class="expiry-body">
      <div class="expiry-current"><span>Current expiry</span><strong id="currentExpiryDisplay"></strong></div>
      <label for="expiryAction">What would you like to do?</label>
      <select id="expiryAction" onchange="ExpiryModal.selectAction()">
        <?php if ($canGrantOwnerAccess): ?><option value="owner" id="expiryOwnerOption">Grant owner access without payment</option><?php endif; ?>
        <option value="date">Shorten expiry</option><option value="package">Change package</option>
      </select>
      <section id="expiryDateSection">
        <p id="expiryActionHelp" class="expiry-help"></p>
        <div id="expiryPresets" class="expiry-presets" aria-label="Access duration">
          <button type="button" onclick="ExpiryModal.preset(0,30)">30 days</button><button type="button" onclick="ExpiryModal.preset(1)">1 year</button><button type="button" onclick="ExpiryModal.preset(5)">5 years</button><button type="button" onclick="ExpiryModal.preset(10)">10 years</button>
        </div>
        <div class="expiry-date-grid"><div><label for="expiryCalendar">Expiry date</label><input type="date" id="expiryCalendar" required></div><div><label for="expiryClock">Time</label><input type="time" id="expiryClock" required></div></div>
        <p class="expiry-help">Times use <?php echo htmlspecialchars(date_default_timezone_get()); ?>.</p>
        <p id="expiryPreview" class="expiry-preview" aria-live="polite"></p>
        <input type="hidden" id="ownerAccessExpiry"><input type="hidden" id="expiryDateInput">
      </section>
      <section id="expiryPackageSection" hidden>
        <p class="expiry-help">Change the customer's package while keeping their current expiry.</p>
        <label for="expiryPackageSelect">Package</label><select id="expiryPackageSelect"><option value="">Choose a package</option>
        <?php foreach ($packages as $pkg): ?><option value="<?php echo (int)$pkg['id']; ?>" data-type="<?php echo htmlspecialchars($pkg['type']); ?>"><?php echo htmlspecialchars($pkg['name']); ?> — KES <?php echo number_format($pkg['price']); ?></option><?php endforeach; ?>
        </select>
      </section>
      <p id="expiryFeedback" class="expiry-feedback" role="status"></p>
    </div>
    <footer class="expiry-footer"><button type="button" class="expiry-secondary" onclick="closeExpiryModal()">Cancel</button><button type="button" id="expirySave" class="expiry-primary" onclick="ExpiryModal.save()">Save changes</button></footer>
  </div>
</div>
<script src="expiry-modal.js"></script>
