# Captive portal refresh and purchased-time verification

Local verification: 2026-09-11. Changes are not deployed. Live verification is pending: SSH authentication to the VPS was rejected.

The subsequent user recording (19 seconds) shows the selected package disappearing on reload before payment. This does not establish the cause of that particular reload. The simplified portal now restores a partial phone number, selected package and receipt draft from session storage for up to 30 minutes, without issuing payment or reconnect requests. A checkout that started after the draft takes precedence; confirmed credential handoff clears the draft. Live Android/RouterOS reload behaviour still needs checking.

The user's design reference is implemented with a white panel, reconnect above the package grid, two columns of coloured cards, and green Buy Now controls. Package selection opens a phone dialog. Prices and durations come from package records; speeds and data allowances are shown accurately. Optional sign-in and voucher controls sit below the packages. The shared template applies to tenants using this renderer; company/logo and enabled feature settings remain in use, while the simplified layout uses the reference colours. The hidden RouterOS handoff form remains available when the Paid feature is disabled.

`tools/test_hotspot_portal_layout.js` exercises the production PHP renderer, all ten approved tariff fixtures, four viewport widths (320/390/720/1024), forced reload recovery, reopening a selected card, receipt draft restoration and buy-only tenants. Five scenarios passed. Preview images are in `artifacts/hotspot-simplified-portal.png` and `artifacts/hotspot-simplified-payment.png`. They show fixture data, not a live router capture.

The previous portal automatically resumed a saved checkout on every page load. A completed checkout submitted the RouterOS login form; a router rejection returned to the same page and repeated the submission. In a mobile-sized Chrome test with simulated router rejection, the original template submitted **25 logins in nine seconds without customer input**.

The corrected template offers **Check previous payment** after navigation. A new purchase still requests its M-Pesa prompt and hands credentials to RouterOS after confirmation. A returned portal requires an explicit connection retry. Timed sign-in retries are removed, and a late sign-in response cannot navigate away from the Buy tab.

The date calculation also rejects invalid original timestamps rather than substituting the current time. Epoch-zero dates remain valid. This prevents a bad historical timestamp from restarting purchased time.

Verified locally:

- Five actual-browser scenarios passed: uninterrupted phone entry across the former refresh interval, no repeat navigation after router rejection, one simulated prompt for phone submission with handoff only after confirmation, no timed sign-in retries, and no navigation from a late sign-in response. Browser execution reported no script errors.
- `php tools/test_hotspot_onboarding.php`: 151 checks passed, exercising actual PHP activation/provisioning functions with database, gateway and router doubles. All ten advertised durations were covered: 30 minutes; 1, 3, 6, 8, 14 and 24 hours; 4, 7 and 30 days. Tests cover duplicate confirmation, receipt aliases, durable retries, finite router limits, scheduler failures, and exact-expiry rejection. A separate paid renewal may add purchased time; login, receipt reuse and provisioning retries may not.
- `php tools/test_connectivity_audit.php`: 15 checks passed, including excess database entitlement, later router deadlines, RouterOS legacy dates, missing deadlines, unlimited uptime and incomplete purchase evidence.

These simulations do not prove Safaricom prompt delivery, database locking under real concurrent callbacks, RouterOS script execution, or actual Internet cutoff.

After deploying the repository changes on the VPS, publish and check the captive page on tenant 9's routers:

```bash
php cron/sync_hotspot_pages.php --tenant=9 --force
php cron/sync_hotspot_pages.php --tenant=9 --check
```

For each router, substitute its database ID and capture the read-only audit:

```bash
php tools/audit_paid_connectivity.php --router=ID --all --evidence --tariffs=config/ghettohlink_hotspot_tariffs.json > /root/hotspot-verification.json
```

The audit compares reconstructed purchase entitlement with database expiry and reads router scheduler/uptime limits. It flags missing historical evidence rather than declaring it correct. Its clock comparison allows clock-request latency. Readback does not prove that a scheduler's script runs or that all traffic paths are blocked; the report explicitly leaves traffic verification false. Run during a quiet window or recheck customers whose payments/renewals changed during the audit. Use the existing historical repair workflow to review and correct flagged legacy accounts.

Finish live verification on a test device connected to the actual captive Wi-Fi: slowly enter the phone number, receive and approve one real prompt, record the saved purchase duration and absolute expiry, and verify Internet access. Refresh, reconnect and recheck the same payment without changing that expiry. At the recorded expiry, observe ongoing traffic stop and verify that a fresh reconnect or receipt reuse cannot restore access. Repeat router reboot/reconnect and provisioning-failure recovery on a test router. Confirm the real router deadline and traffic cutoff for each advertised duration before making a fleet-wide guarantee.

Browser test setup can live outside the repository. With Playwright installed and `NODE_PATH` pointing to its node_modules, run `node tools/test_hotspot_portal_browser.js`. Set `PORTAL_BROWSER` to an installed Chrome/Edge executable if Playwright's Chromium is unavailable. `PORTAL_TEMPLATE` and `PORTAL_EXPECT_REFRESH_LOOP=1` allow reproducing the loop against a saved original template. All gateway and router endpoints in this test are intercepted; it sends no real payment prompt.
