# Hotspot End-to-End Test — RB951 to Auto-Authentication

How to prove the whole chain works on a real RB951: a customer connects, picks a
plan, pays with M-Pesa, and is put online **without anyone touching the admin
portal**.

Work through it in order. Each stage has a check that must pass before the next
one is worth attempting — that way a failure tells you *which* link broke.

---

## Stage 0 — Server prerequisites (once)

```bash
# 1. Repair schema drift. Safe to re-run; prints what it changed.
php tools/repair_status_enums.php

# 2. Point any stored callback URL at /api/payment/ (the /api/mpesa/ folder is gone).
php tools/fix_mpesa_callback_urls.php --dry-run    # inspect
php tools/fix_mpesa_callback_urls.php              # apply

# 3. Baseline health check — fix anything it reports before testing.
php tools/diagnose_autoactivation.php
```

**Must pass:** `repair_status_enums.php` ends with `Done.` and
`diagnose_autoactivation.php` reports no issues other than historical payments.

> If you skip step 1, `mpesa_transactions` is missing `tenant_id` and the captive
> portal will poll forever after a successful payment.

Confirm the cron entries exist:

```
*/15 * * * * php /var/www/html/cron/check_expiry.php        >> /var/log/fortunett_expiry.log 2>&1
* * * * * php /var/www/html/cron/enforce_sessions.php >> /var/log/fortunett_enforce.log 2>&1
* * * * * php /var/www/html/cron/retry_provisions.php >> /var/log/fortunett_provision.log 2>&1
30   * * * * php /var/www/html/cron/sync_hotspot_pages.php  >> /var/log/fortunett_portal_sync.log 2>&1
```

---

## Stage 1 — Tenant setup

1. **Hotspot packages exist.** Packages → at least one with
   `connection_type = hotspot`, `status = active`, a price, and a validity.
   Add a KES 1 plan for testing — you will pay this for real.
2. **M-Pesa configured.** Either the tenant's own paybill (Payments → M-Pesa API,
   all four of consumer key/secret/passkey/shortcode) or the platform paybill.
3. **If using the tenant's own paybill:** click **Register C2B**. The success
   message must name URLs under `/api/payment/`. If it errors, Safaricom has
   rejected them — re-check the domain is public and HTTPS.
4. **If using the platform paybill:** register
   `https://<your-domain>/api/payment/c2b_confirmation.php` as the Confirmation
   URL manually in the Daraja portal. There is no in-app button for the platform
   shortcode. Also confirm the tenant has an `account_prefix`
   (`diagnose_autoactivation.php` flags this).

**Check:**
```bash
curl -s https://<sub>.fortunetttech.site/api/payment/tenant_c2b_validation.php
# {"status":"ok","endpoint":"tenant_c2b_validation"}
```

---

## Stage 2 — Router provisioning (RB951)

The RB951 is a `mipsbe` board with ~16 MB flash. The login page is ~54 KB, so
space is not an issue, but **RouterOS 6 vs 7 changes the html-directory path** —
the code writes to `flash/hotspot` *and* `flash/flash/hotspot` to cover both.

1. Admin portal → **Routers** → add the RB951 (IP, API port 8728, credentials).
   The API service must be enabled: `/ip service enable api`.
2. Set up the hotspot on the router if it isn't already
   (`/ip hotspot setup` on the bridge/wlan interface).
3. Click **Sync Portal** in the Routers toolbar.

**Check on the router:**
```
/file print where name~"login.html"          # in flash/hotspot (and/or flash/flash/hotspot)
/file print where name~"fortunett-portal"    # fortunett-portal.ver — the build fingerprint
/system scheduler print                      # FortuNett-Portal-Sync, interval 1h, start-time=startup
/ip hotspot profile print                    # html-directory=flash/hotspot, login-by includes http-pap
/ip hotspot walled-garden print              # your portal host is listed
/ip firewall nat print                       # masquerade for the hotspot subnet
```

**Must pass:** `login.html` exists and `fortunett-portal.ver` contains a 12-char
hex string. Compare it against what the server would serve:

```bash
curl -s "https://<sub>.fortunetttech.site/hotspot/login_version.php?token=<provisioning_token>"
```

The two must be identical. If they differ, the router is holding a stale page.

> **Router the server can't reach** (CGNAT, no port forward)? Open
> `/hotspot/self_update_script.php` in the admin portal, paste the block into
> WinBox → New Terminal. It installs the same script + scheduler, and the router
> pulls everything itself from then on.

---

## Stage 3 — Verify auto-sync actually works

This is the part that means you never re-provision again. Prove it once:

1. Note the current fingerprint: `/file print where name~"fortunett-portal"`.
2. On the server, change something visible — e.g. edit a hotspot package's price,
   or change the tenant's brand colour.
3. Force the router to check now instead of waiting the hour:
   ```
   /system script run FortuNett-Portal-Sync
   /log print where message~"FortuNett"
   ```

**Must pass:** the log shows `new build <hash> - downloading` then
`login page updated`, and `fortunett-portal.ver` now holds the new hash.

Run it a second time with nothing changed — the log should stay silent and the
file should not be rewritten. That confirms it is not re-downloading needlessly.

---

## Stage 4 — Captive portal on a phone

Connect a phone to the hotspot SSID and let the captive portal open (or browse to
any `http://` site).

Check the page renders correctly:

- Brand colour and company name are the tenant's, not the defaults
- **Get Online** is the first tab and lists your plans
- Duration filter chips (Hourly / Daily / …) appear when you have plans with
  different validities, and filtering keeps a valid plan selected
- Selecting a plan updates the summary card and the button reads
  `Pay KES <amount>`
- A KES 0 plan reads `Get Free Access` instead

> Nothing renders / you see raw `$(if error)` text → the router is serving a page
> RouterOS didn't template. Re-check `html-directory` in Stage 2.

---

## Stage 5 — Pay and get connected (the real test)

Pick the KES 1 plan, enter a **real** M-Pesa number, tap pay.

Expected sequence on screen:

| Step | Shows | Meaning |
|---|---|---|
| 1 | Sending request | Server accepted, calling Daraja |
| 2 | Check your phone | STK push delivered — PIN prompt appears |
| 3 | Confirming payment | PIN entered, waiting for the callback |
| 4 | Activating your internet | Callback landed, client activated |
| — | Credentials + "Connecting you…" | Auto-login posts to RouterOS |

Then the phone should have internet **with no manual step**.

**Server-side confirmation:**
```bash
tail -20 logs/mpesa_callbacks.log
php tools/diagnose_autoactivation.php --days=1   # must NOT list your test client
```

```sql
SELECT status, expiry_date FROM clients WHERE phone LIKE '%<last9digits>';
SELECT amount, status, payment_method FROM payments ORDER BY id DESC LIMIT 1;
```

Client must be `active` with a future `expiry_date`, payment `completed`.

**On the router:**
```
/ip hotspot active print      # your device is listed
/ip hotspot user print        # the hotspot user was created
```

### Stage 5b — Paybill route

Repeat, but use **Pay manually with Paybill** and enter **your phone number** as
the account number (what the on-screen instructions say).

```bash
tail -5 logs/mpesa_c2b.log
```

**Must pass:** `MATCHED via phone: ... -> tenant=N client=N` followed by
`OK: tenant=N client=N`. Then open the **Paid?** tab, enter the M-Pesa code, and
you should be connected.

> `UNROUTABLE` means the resolver could not identify the payer. The log line
> shows both the account ref and the MSISDN — usually the customer's phone in
> `clients` doesn't match the paying number, or the same phone exists under two
> tenants (the resolver refuses rather than credit the wrong ISP).

---

## Stage 6 — Renewal

Let the KES 1 plan expire (or set `expiry_date` to the past), then reconnect.
Signing in should report the subscription expired and drop you on the plans tab.
Pay again — the expiry should **extend**, and the device should reconnect
automatically.

---

## If it fails, in order of likelihood

| Symptom | Cause | Fix |
|---|---|---|
| "Payment could not be initiated: … Data truncated" | `clients.status` enum missing `pending` | `php tools/repair_status_enums.php` |
| Portal spins on "Confirming payment" forever | `mpesa_transactions.tenant_id` missing, or the callback URL is wrong/unreachable | Stage 0 steps 1–2; check `logs/mpesa_callbacks.log` is being written |
| Paybill payment taken, client never activates | C2B confirmation URL not registered, or registered against the old `/api/mpesa/` path | Stage 1 steps 3–4; `logs/mpesa_c2b.log` empty = Safaricom never called you |
| `UNROUTABLE` in `mpesa_c2b.log` | Payer's phone not on the client record | Fix the phone on the client, or give them their `account_number` |
| Login page is stale after an edit | Router's scheduler missing | `/system scheduler print`; re-run **Sync Portal**, or paste `self_update_script.php` |
| Client active in the DB but no internet | Router unreachable when provisioning ran | `cron/retry_provisions.php`; check `pending_provisions` |

`php tools/diagnose_autoactivation.php` covers most of this table automatically —
run it first.


## Purchased-time enforcement

Run these offline regressions before deployment:

```sh
php -n tools/test_payment_connectivity.php
php -n tools/test_package_deadlines.php
```

Hotspot and PPPoE access now end at each customer's purchased `expiry_date`, with
zero grace. Provisioning installs a one-shot `fn-exp-*` scheduler on the router
that disables the account and removes its live sessions at that deadline.
The profile's login/up hook checks the stored deadline on reconnect, including
a reboot that missed the scheduled event. Hotspot also carries a cumulative
uptime limit, preserving used counters on retry, and disables new MAC cookies.
A profile session timeout by itself would restart on reconnect; these controls
use the purchased expiry in addition to the session limit. See the official
[MikroTik Hotspot controls](https://manual.mikrotik.com/docs/authentication-authorization-accounting/hotspot-captive-portal/)
and [scheduler behavior](https://help.mikrotik.com/docs/spaces/ROS/pages/40992881/Scheduler).

Each package has its own profile, purchased speed cap and device count. Empty
speeds, invalid durations, unknown duration units and duplicate explicit profile
names are rejected. Existing shared profile names are separated during client
provisioning. Package edits change the shared profile; already-paid absolute
expiry dates are never recalculated by those edits.

STK initiation snapshots the selected package and duration in
`payment_purchase_terms`. Callbacks and queries use that snapshot, even when
another purchase or a package edit changes the current package before payment
confirms. Historical payments without snapshots retain their existing package
resolution. An unpaid registration's provisional date is not credited as paid
time, and polling an old receipt does not restart a subscription.

Both callback URLs use the shared pipeline. `payment_activations` prevents
repeated grants and `pending_provisions` retains failed router setup. Provisioning
keeps the router account disabled unless the profile, time limit and scheduler
are successfully written and read back. RADIUS sync writes an `Expiration`
attribute and the package's `Mikrotik-Group`; FreeRADIUS must run its
[expiration module after SQL](https://wiki.freeradius.org/modules/rlm-expiration)
to calculate the remaining Session-Timeout on each authentication.

Deploy the one-minute `retry_provisions` and `enforce_sessions` schedules above.
The retry job automatically queues existing active customers whose
`router_services.paid_expiry_at` does not match the purchased expiry, so existing
router accounts receive the new controls. Tables/columns are created on use.
This backfill reconnects existing customers. The fifteen-minute job remains for
status transitions and reminders; the one-minute sweep is a recovery check,
not the primary deadline timer once provisioning has succeeded.

Live acceptance checks (not covered by the offline router double):

- Verify router date/time and NTP, scripting permissions and the installed
  `fn-exp-*` scheduler. Router-local deadlines require a correctly functioning
  clock and scheduler; later clock changes or manual removal of the controls
  invalidate that assumption.
- Buy 30 minutes; verify the database expiry, profile speed/device limit,
  user `limit-uptime`, and scheduler time. Disconnect and reconnect halfway
  through; the original expiry must remain unchanged.
- Leave the device online through expiry with the server-side cron disabled;
  verify the router cuts it at the scheduled time (RouterOS clock resolution).
- Reboot across expiry and attempt password/cookie reconnects. Access must be
  denied. Verify the same behavior for hourly, daily and monthly packages and
  PPPoE, and ensure unrelated customers' profiles are unaffected.
- Replay a callback, poll the old receipt after expiry, and start two different
  package purchases before paying. Each confirmed purchase must grant its own
  duration once.
- Reject a scheduler write or disconnect the router during provisioning; the
  account must not become an unlimited user, and the retry queue must retain
  the failure until the router can install the deadline.

No live payment, MikroTik execution or deployed FreeRADIUS configuration is
verified by the offline PHP tests. Complete these checks on the deployment's
RouterOS versions before treating the rollout as validated.


## Tenant dashboard application status

Package create/edit, customer edit, pause/resume, expiry reductions, and individual/bulk package changes now save a durable `dashboard_sync_jobs` entry in the same transaction as the edit. The package/customer pages immediately refresh their lists and process jobs through the authenticated, tenant-scoped `api/dashboard_sync.php` endpoint. It reports pending work separately from saved data. Price/contact-only edits do not reconnect sessions. Access-setting edits reconnect affected users with the remaining purchased deadline. Package changes and resuming access never restart a duration; manual grace and unpaid extensions are rejected.

Keep `cron/retry_provisions.php` scheduled every minute. It also drains dashboard jobs when the browser is closed; failures remain queued and become eligible after 30 seconds. Jobs read the latest customer/package values and share payment locks for customer provisioning. A router outage cannot be applied in real time; the UI keeps the change pending instead of claiming success. Large packages apply progressively, one customer/router job at a time in the browser (up to 30 per cron run).

Live acceptance checks:

1. Edit a package speed while a paid user is online. Confirm the list changes without page reload, the progress panel reaches applied, and the reconnected session uses the new profile/rate. Confirm expiry is unchanged.
2. Suspend an online hotspot/PPPoE user. Confirm the session is removed, the account is disabled, and hotspot cookies are removed. Resume before expiry and verify only remaining purchased time is available; resume after expiry must not enable access.
3. Disconnect router management, save an edit, then close the browser. Restore management connectivity and verify the minute cron applies it. Reopen the dashboard and verify pending status clears.
4. Rename credentials and verify the former account cannot reconnect. Test edits on another tenant and ensure their jobs/status are inaccessible.
5. Try a later expiry, grace hours, and a package switch. The first two must be rejected; a package switch must preserve expiry. Bulk package changes must reject mixed connection types.

Offline checks: `php -n tools/test_dashboard_sync.php`, `php -n tools/test_payment_connectivity.php`, `php -n tools/test_package_deadlines.php`, and `node --check dashboard-sync.js`. Live router/database/browser acceptance remains required before production rollout.


## Paid-but-offline recovery and short-package verification

The shared STK reconciler accepts successful provider queries without requiring callback-only receipt metadata. Checkout IDs deduplicate the later receipt callback. Confirmation is retained if activation fails, and the minute reconciler retries. An unknown query result or an old pending payment is never silently declared unpaid based on age. Completed legacy payments with existing expiry are not replayed as fresh purchases. Prompt payments are recorded as `mpesa_stk` and displayed as **M-Pesa (phone prompt)**; the transaction modal reads the stored method rather than guessing Cash from the receipt text.

The portal retains the checkout across reopening, prevents overlapping polls, and continues connection recovery beyond five minutes. Known hotspot devices are resolved against the router host table and can be logged in through RouterOS after the paid deadline is installed, even if their browser closed. A successful API command alone is not evidence of Internet access: the active session is read back, and live testing must still verify traffic from the customer's Wi-Fi device. The browser login remains a fallback. Multi-router tenants require either observed device context or a saved router assignment.

Provisioning installs an exact per-customer deadline, finite uptime and session caps, a reconnect guard, and a five-second router-local watchdog for missed events/reboots. The watchdog is a fallback, not an added grace allowance. `expiry_policy_version=2` causes the minute provisioning backfill to upgrade existing active customers. Deploy the refreshed hotspot page as well as PHP; an old page retains its old polling behavior.

Offline verification: `php -n tools/test_hotspot_onboarding.php`, `node tools/test_hotspot_portal.js`, and `php -n tools/test_dashboard_sync.php`. These use fake external systems and do not prove a live payment or Internet session. Live audit (read only, after the file is deployed): `php tools/audit_paid_connectivity.php --router=ID --client=ID`. It omits passwords, phone numbers and receipt values.

Live acceptance requires a Safaricom-confirmed test payment, matching checkout/receipt and paid expiry in the database, an active hotspot session on the selected router, successful HTTPS traffic with mobile data disabled, then loss of Internet at the paid deadline. Run both the full 30-minute and three-hour tests, including a reconnect without resetting expiry. Also test a lost callback, a closed captive browser, a temporarily unreachable router, and a late duplicate callback. No live test was completed in the local workspace: its configured database refused connections; the VPS/router selection is pending.

RouterOS references: [Hotspot limits and login methods](https://help.mikrotik.com/docs/spaces/ROS/pages/56459266/HotSpot%20-%20Captive%20portal), [direct hotspot login command](https://manual.mikrotik.com/docs/cli-reference/ip/hotspot/active/login/).


## Connect TV / Device

The captive portal now offers Connect TV / Device below the package list. Its dialog accepts a valid unicast Wi-Fi MAC address, optional device name, paid package and M-Pesa number. TV purchases use a separate tenant/device account; phone purchases exclude those accounts. The TV account is bound to the entered MAC, and the phone polling the payment receives a TV-specific completion response without router credentials or a phone login handoff.

TVs use the same finite uptime, purchased deadline and reconnect guard as other paid hotspot customers. Exact-MAC bypass entries on a bound TV are removed during provisioning and expiry. Expiry policy version 3 also sweeps already-disabled paid accounts, because disabling an account alone does not prove its active session was removed. The minute enforcement worker reconnects eligible bound devices after reboot without extending their expiry. An expired device cannot use that reconnect path.

Verification: `php -n tools/test_tv_onboarding.php` and `node tools/test_hotspot_portal.js`. Live test: connect a TV to Wi-Fi, enter its Wi-Fi MAC on a phone, pay, verify Internet on the TV and that the phone has not received that subscription. Reboot the TV during paid time and verify recovery; at expiry verify traffic stops and reboot/reconnect does not restore it. Deploy the refreshed portal HTML and PHP; run the existing minute expiry/provisioning jobs so policy version 3 reaches active accounts. Live device verification remains outstanding.


## Paid phone recovery (11 September)

The enforcement worker now reconnects paid phones using their remembered device MAC, as well as bound TVs. It only considers the assigned router service with the current purchased expiry and policy version, rechecks entitlement under the payment lock, and updates last_seen after verifying an active session. An existing session is left running. Provision retries skip portal uploads. Status polling aborts a hung request after 45 seconds and resumes checking the same checkout.

Mobile normalization is shared by the M-Pesa gateway and hotspot endpoint: 07/01, bare 7/1, +254, and spaced formats are accepted without a carrier-prefix allowlist. Valid formatting cannot guarantee Safaricom will deliver a prompt to an unavailable or ineligible SIM.

Local checks: `php tools/test_paid_phone_recovery.php`, `php tools/test_tv_onboarding.php`, `node tools/test_hotspot_portal.js`.

Live acceptance remains required: deploy the code and updated hotspot/login.html to router 9; verify cron/enforce_sessions.php, cron/retry_provisions.php and cron/stk_poll.php run every minute. On an authorized test customer, confirm a payment, close the captive browser, keep Wi-Fi connected, and verify an active router session and actual Internet traffic. Repeat after a brief router outage; verify exact paid expiry is unchanged. Run `php tools/audit_paid_connectivity.php --router=9 --client=ID` before and after. The audit is read-only and reports provisioning failure reasons and whether the remembered device is visible on the router.
