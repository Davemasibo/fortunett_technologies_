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
