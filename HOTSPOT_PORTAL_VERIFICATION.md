# Captive portal refresh and purchased-time verification

Update: tenant appearance has since been restored across captive and customer portals. Router build `0b944725a972` was verified CURRENT on 2026-09-11 at 20:18:56 Africa/Nairobi; see `CUSTOMER_THEME_VERIFICATION.md`. The earlier fixed reference colors described below are superseded by the saved tenant palette.

Deployed on 2026-09-11 to `/var/www/html/fortunett_technologies_` and tenant 9's router 19 (rb951). Router build `20f02b99eb51` was read back as CURRENT at 18:42 Africa/Nairobi. Original files and repair evidence are backed up under `/root/fortunett-portal-20260911/`.

Live results:

- Applied the three owner-approved package duration corrections and 44 customer expiry corrections (41 initially, then 3 after resolving checkout/receipt ledger aliases). Both aliases must map to the same confirmed STK with identical amount and duration; conflicting or unproven duplicates still require review. Financial ledger rows are preserved, and audit evidence retains their payment IDs.
- Read back and verified all ten current package profiles. All tenant 9 dashboard synchronization jobs finished with zero unapplied jobs. Fixed a production schema mismatch: router service states now use `expired`/`suspended` instead of the unsupported `inactive` value.
- Fixed the portal sync script's live RouterOS syntax error caused by attempting to rename a file. It now updates the version marker's contents; API script failures are checked rather than reported as success. Both the new layout and draft recovery were found in the page downloaded by the router. Cloudflare adds a 367-byte analytics script to that response; the rest matched the server-rendered page.
- A temporary isolated account on the real router was enabled with a 45-second deadline. Its scheduled expiry event ran once, and the account was disabled after expiry. The temporary account and its schedule were removed. This proves the tested router event executed; it does not measure packet cutoff latency or prove real-phone M-Pesa delivery.
- The final live audit covers all 67 tenant 9 customers, with no enabled-unentitled or router-deadline-beyond-database findings. The audit recorded eighteen historical purchase review findings, with only G020 active. On 2026-09-11 the owner confirmed G020 was manually created and its access is authorized, resolving that account's review question. Its existing access through 6 October remains unchanged. The original audit counts are retained as evidence, with the owner resolution recorded separately; the other seventeen historical findings remain unresolved. Historical financial exceptions and the outstanding real-device tests still prevent a universal entitlement guarantee.

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

The simulations do not prove Safaricom prompt delivery, database locking under real concurrent callbacks or actual Internet cutoff. The separate live test above verifies execution of an isolated router deadline event.

To republish or check the captive page on tenant 9's routers:

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

Router file operations were checked against the [MikroTik Files documentation](https://help.mikrotik.com/docs/spaces/ROS/pages/2555971/Files). The decisive compatibility evidence was the live script error and successful subsequent update.
