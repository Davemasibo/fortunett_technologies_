<?php
/**
 * Captive-portal appearance editor — the "Hotspot" tab on settings.php.
 *
 * Expects from the including page: $pdo, $tenant_id, and $hsTheme (the
 * tenant's saved theme, already normalised by hotspotThemeLoad()).
 *
 * Nothing here decides what a colour means. The form collects choices, the
 * renderer derives the palette from them, and the preview iframe below shows
 * the page a router would actually serve — so what an operator sees while
 * editing and what a customer sees on the bus are the same bytes.
 */
if (!isset($hsTheme)) {
    require_once __DIR__ . '/includes/hotspot_theme.php';
    $hsTheme = hotspotThemeLoad($pdo, (int)$tenant_id);
}
$hsPalette = hotspotThemePalette($hsTheme);
$hsBgUrl   = $hsTheme['bg_image'] !== '' ? HS_UPLOAD_DIR . '/' . $hsTheme['bg_image'] : '';
$hsLogoUrl = $hsTheme['logo']     !== '' ? HS_UPLOAD_DIR . '/' . $hsTheme['logo']     : '';
?>
<style>
.hs-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:24px;align-items:start}
@media (max-width:1100px){.hs-grid{grid-template-columns:minmax(0,1fr)}}
.hs-swatch{display:flex;align-items:center;gap:10px}
.hs-swatch input[type=color]{
  width:46px;height:38px;padding:2px;border-radius:8px;cursor:pointer;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);
}
.hs-swatch input[type=text]{max-width:120px;font-family:ui-monospace,Menlo,monospace}
.hs-hint{font-size:11.5px;color:rgba(255,255,255,.42);line-height:1.5;margin-top:5px}
.hs-preview-wrap{position:sticky;top:16px}
.hs-frame{
  width:100%;height:660px;border:1px solid rgba(255,255,255,.12);border-radius:14px;
  background:#111;display:block;
}
.hs-asset{
  display:flex;align-items:center;gap:12px;padding:10px;border-radius:10px;
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);margin-bottom:10px;
}
.hs-asset img{max-width:96px;max-height:56px;border-radius:6px;object-fit:cover}
.hs-contrast{
  font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:20px;display:inline-block;
}
.hs-contrast.ok{background:rgba(52,211,153,.14);color:#6ee7b7;border:1px solid rgba(52,211,153,.3)}
.hs-contrast.no{background:rgba(251,191,36,.14);color:#fcd34d;border:1px solid rgba(251,191,36,.3)}
</style>

<div class="set-info-banner">
    <i class="fas fa-palette"></i>
    <div>
        <strong>Captive Portal Appearance</strong><br>
        These settings are yours alone and are saved against your account. Every router you own
        checks for changes hourly and pulls the new page by itself — <strong>Save</strong> is enough.
        Use <strong>Save &amp; push now</strong> only when you want it live this minute.
    </div>
</div>

<form method="POST" enctype="multipart/form-data" id="hsThemeForm">
<input type="hidden" name="action" value="update_hotspot_theme">

<div class="hs-grid">
  <div>

    <!-- ══ Backdrop ══════════════════════════════════════════════ -->
    <div class="set-section">
      <div class="set-section-title"><i class="fas fa-fill-drip"></i> Background</div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Style</label>
          <select name="bg_style" id="hs_bg_style" class="form-select">
            <?php foreach (['gradient' => 'Gradient (two colours)', 'solid' => 'Solid colour', 'image' => 'Wallpaper image'] as $v => $l): ?>
              <option value="<?php echo $v; ?>" <?php echo $hsTheme['bg_style'] === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Background colour</label>
          <div class="hs-swatch">
            <input type="color" id="hs_bg_color_p" value="<?php echo htmlspecialchars($hsTheme['bg_color']); ?>">
            <input type="text" name="bg_color" id="hs_bg_color" class="form-control" value="<?php echo htmlspecialchars($hsTheme['bg_color']); ?>" maxlength="7">
          </div>
        </div>
        <div class="col-md-4" id="hs_bg2_wrap">
          <label class="form-label">Second gradient colour</label>
          <div class="hs-swatch">
            <input type="color" id="hs_bg_color2_p" value="<?php echo htmlspecialchars($hsTheme['bg_color2']); ?>">
            <input type="text" name="bg_color2" id="hs_bg_color2" class="form-control" value="<?php echo htmlspecialchars($hsTheme['bg_color2']); ?>" maxlength="7">
          </div>
        </div>
      </div>

      <div id="hs_img_wrap" class="mt-3">
        <?php if ($hsBgUrl): ?>
          <div class="hs-asset">
            <img src="<?php echo htmlspecialchars($hsBgUrl); ?>?v=<?php echo substr(md5($hsTheme['bg_image']), 0, 6); ?>" alt="">
            <div style="flex:1">
              <div style="font-size:12.5px;font-weight:600">Current wallpaper</div>
              <div class="hs-hint" style="margin:0">
                <?php echo round((int)@filesize(__DIR__ . '/' . $hsBgUrl) / 1024); ?> KB — embedded in the page and stored on each router's flash
              </div>
            </div>
            <label style="font-size:12px;color:#fca5a5;cursor:pointer">
              <input type="checkbox" name="drop_bg" value="1"> Remove
            </label>
          </div>
        <?php endif; ?>
        <label class="form-label">Upload a wallpaper</label>
        <input type="file" name="bg_upload" accept="image/*" class="form-control">
        <div class="hs-hint">
          Resized and compressed automatically. It is embedded straight into the portal page rather
          than linked, because a linked image has to be allowed through the router's walled garden
          and a customer whose request is blocked just sees a broken page. That page lives on each
          router's flash, so anything over
          <?php echo round(HS_WALLPAPER_MAX / 1024); ?> KB after compression is rejected.
        </div>

        <label class="form-label mt-3">Darken the wallpaper — <span id="hs_dim_val"><?php echo (int)$hsTheme['bg_dim']; ?></span>%</label>
        <input type="range" name="bg_dim" id="hs_bg_dim" class="form-range" min="0" max="80" step="5" value="<?php echo (int)$hsTheme['bg_dim']; ?>">
        <div class="hs-hint">A photo with no overlay almost never has enough contrast behind text. 30–50% is usually right.</div>
      </div>
    </div>

    <!-- ══ Card and accent ═══════════════════════════════════════ -->
    <div class="set-section">
      <div class="set-section-title"><i class="fas fa-swatchbook"></i> Card &amp; Accent</div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Accent colour</label>
          <div class="hs-swatch">
            <input type="color" id="hs_accent_p" value="<?php echo htmlspecialchars($hsTheme['accent']); ?>">
            <input type="text" name="accent" id="hs_accent" class="form-control" value="<?php echo htmlspecialchars($hsTheme['accent']); ?>" maxlength="7">
          </div>
          <div class="hs-hint">Buttons, prices, the selected plan and the active tab.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Card</label>
          <select name="card_mode" class="form-select">
            <?php foreach (['auto' => 'Match the background automatically', 'light' => 'Always light', 'dark' => 'Always dark'] as $v => $l): ?>
              <option value="<?php echo $v; ?>" <?php echo $hsTheme['card_mode'] === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hs-hint">
            On <em>auto</em> the card flips to light on a bright background and dark on a dark one, so
            text is always readable whatever colour you pick.
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Corner rounding — <span id="hs_rad_val"><?php echo (int)$hsTheme['radius']; ?></span>px</label>
          <input type="range" name="radius" id="hs_radius" class="form-range" min="0" max="36" step="2" value="<?php echo (int)$hsTheme['radius']; ?>">
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="aurora" value="1" id="hs_aurora" <?php echo $hsTheme['aurora'] === '1' ? 'checked' : ''; ?>>
            <label class="form-check-label" for="hs_aurora" style="font-size:13px">Animated glow behind the card</label>
          </div>
        </div>
      </div>
      <div class="mt-3">
        Current result:
        <span class="hs-contrast ok"><?php echo $hsPalette['is_light'] ? 'Light card' : 'Dark card'; ?> on <?php echo htmlspecialchars($hsPalette['backdrop']); ?></span>
      </div>
    </div>

    <!-- ══ Identity ══════════════════════════════════════════════ -->
    <div class="set-section">
      <div class="set-section-title"><i class="fas fa-id-badge"></i> What the customer reads</div>
      <?php if ($hsLogoUrl): ?>
        <div class="hs-asset">
          <img src="<?php echo htmlspecialchars($hsLogoUrl); ?>?v=<?php echo substr(md5($hsTheme['logo']), 0, 6); ?>" alt="">
          <div style="flex:1"><div style="font-size:12.5px;font-weight:600">Current logo</div></div>
          <label style="font-size:12px;color:#fca5a5;cursor:pointer">
            <input type="checkbox" name="drop_logo" value="1"> Remove
          </label>
        </div>
      <?php endif; ?>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Logo (optional)</label>
          <input type="file" name="logo_upload" accept="image/*" class="form-control">
          <div class="hs-hint">Replaces the Wi-Fi glyph. PNG keeps its transparency. Max <?php echo round(HS_LOGO_MAX / 1024); ?> KB.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Headline</label>
          <input type="text" name="headline" class="form-control" maxlength="60"
                 value="<?php echo htmlspecialchars($hsTheme['headline']); ?>"
                 placeholder="<?php echo htmlspecialchars($tenant['company_name'] ?? 'Your company'); ?>">
          <div class="hs-hint">Blank uses your company name.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Subtitle</label>
          <input type="text" name="subtitle" class="form-control" maxlength="90"
                 value="<?php echo htmlspecialchars($hsTheme['subtitle']); ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Support phone</label>
          <input type="text" name="support_phone" class="form-control" maxlength="24"
                 value="<?php echo htmlspecialchars($hsTheme['support_phone']); ?>" placeholder="0712 345 678">
          <div class="hs-hint">Shown at the bottom as a tap-to-call link.</div>
        </div>
        <div class="col-12">
          <label class="form-label">Footer note</label>
          <textarea name="footer_note" class="form-control" rows="2" maxlength="240"><?php echo htmlspecialchars($hsTheme['footer_note']); ?></textarea>
        </div>
      </div>
    </div>

    <!-- ══ What is offered ═══════════════════════════════════════ -->
    <div class="set-section">
      <div class="set-section-title"><i class="fas fa-sliders-h"></i> Sections shown</div>
      <div class="row g-2">
        <?php foreach ([
            'show_login'   => ['Sign In tab', 'For customers who already have a username and password.'],
            'show_voucher' => ['Voucher tab', 'Only useful if you actually sell voucher codes.'],
            'show_paid'    => ['“Paid?” tab', 'Lets someone who paid by paybill reconnect with their M-Pesa code. Turning this off removes the only self-service recovery for a payment whose callback never arrived.'],
            'show_manual'  => ['Manual payment instructions', 'The collapsible “Pay manually” steps under the plan list.'],
        ] as $key => [$label, $help]): ?>
          <div class="col-md-6">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="<?php echo $key; ?>" value="1"
                     id="hs_<?php echo $key; ?>" <?php echo $hsTheme[$key] === '1' ? 'checked' : ''; ?>>
              <label class="form-check-label" for="hs_<?php echo $key; ?>" style="font-size:13px;font-weight:600"><?php echo $label; ?></label>
            </div>
            <div class="hs-hint" style="margin-left:26px"><?php echo $help; ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="hs-hint mt-2">The <strong>Get Online</strong> tab cannot be switched off — with every tab hidden a customer would have no way to buy anything.</div>
    </div>

    <!-- ══ Payment details ═══════════════════════════════════════ -->
    <div class="set-section">
      <div class="set-section-title"><i class="fas fa-money-check-alt"></i> Payment details shown to customers</div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Customers pay by</label>
          <select name="pay_type" class="form-select">
            <option value="paybill" <?php echo $hsTheme['pay_type'] === 'paybill' ? 'selected' : ''; ?>>Pay Bill</option>
            <option value="till"    <?php echo $hsTheme['pay_type'] === 'till'    ? 'selected' : ''; ?>>Buy Goods (Till)</option>
          </select>
          <div class="hs-hint">A till has no account field, so that step is dropped from the instructions.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Paybill / Till number</label>
          <input type="text" name="paybill" class="form-control" maxlength="16"
                 value="<?php echo htmlspecialchars($hsTheme['paybill']); ?>" placeholder="from your M-Pesa gateway">
          <div class="hs-hint">Blank reads it from your saved M-Pesa gateway.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">What to enter as the account</label>
          <input type="text" name="account_label" class="form-control" maxlength="60"
                 value="<?php echo htmlspecialchars($hsTheme['account_label']); ?>">
          <div class="hs-hint">
            Keep this as <em>your phone number</em> unless you know better — the payment matcher
            resolves a paybill reference by phone number, so anything else is more likely to end up
            unmatched.
          </div>
        </div>
        <div class="col-12">
          <label class="form-label">Extra note under the steps</label>
          <textarea name="pay_note" class="form-control" rows="2" maxlength="240"><?php echo htmlspecialchars($hsTheme['pay_note']); ?></textarea>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
      <button type="submit" class="btn btn-primary fw-semibold"><i class="fas fa-save me-2"></i>Save appearance</button>
      <button type="submit" name="push_now" value="1" class="btn fw-semibold"
              style="background:rgba(52,211,153,.15);border:1px solid rgba(52,211,153,.35);color:#6ee7b7">
        <i class="fas fa-satellite-dish me-2"></i>Save &amp; push to routers now
      </button>
      <button type="button" class="btn fw-semibold" onclick="hsReloadPreview()"
              style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);color:rgba(255,255,255,.75)">
        <i class="fas fa-sync me-2"></i>Refresh preview
      </button>
    </div>
  </div>

  <!-- ══ Live preview ════════════════════════════════════════════ -->
  <div class="hs-preview-wrap">
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.4);margin-bottom:8px">
      <i class="fas fa-mobile-alt me-1"></i> Exactly what the router serves
    </div>
    <iframe id="hsPreview" class="hs-frame" src="hotspot/preview_login.php?t=<?php echo time(); ?>"></iframe>
    <div class="hs-hint">
      This frame loads the real portal through the same code path the router's <code>/tool fetch</code>
      uses, so it is not a mock-up — it is the page. It updates when you save.
    </div>
  </div>
</div>
</form>

<script>
/* Keep the hex box and the colour picker in step, both ways. */
[['hs_bg_color','hs_bg_color_p'],['hs_bg_color2','hs_bg_color2_p'],['hs_accent','hs_accent_p']]
.forEach(function (pair) {
    var text = document.getElementById(pair[0]);
    var pick = document.getElementById(pair[1]);
    if (!text || !pick) return;
    pick.addEventListener('input', function () { text.value = pick.value; });
    text.addEventListener('input', function () {
        if (/^#[0-9a-fA-F]{6}$/.test(text.value)) pick.value = text.value;
    });
});

function hsSyncStyleFields() {
    var style = document.getElementById('hs_bg_style').value;
    document.getElementById('hs_bg2_wrap').style.display = (style === 'gradient') ? '' : 'none';
    document.getElementById('hs_img_wrap').style.display = (style === 'image')    ? '' : 'none';
}
document.getElementById('hs_bg_style').addEventListener('change', hsSyncStyleFields);
hsSyncStyleFields();

var _dim = document.getElementById('hs_bg_dim');
if (_dim) _dim.addEventListener('input', function () { document.getElementById('hs_dim_val').textContent = _dim.value; });
var _rad = document.getElementById('hs_radius');
if (_rad) _rad.addEventListener('input', function () { document.getElementById('hs_rad_val').textContent = _rad.value; });

/* Cache-bust: without a changing query string the iframe keeps the old render
   and the operator concludes the save did nothing. */
function hsReloadPreview() {
    var f = document.getElementById('hsPreview');
    if (f) f.src = 'hotspot/preview_login.php?t=' + Date.now();
}
</script>
