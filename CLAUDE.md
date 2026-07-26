# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

A multi-tenant ISP management system for managing internet service businesses using MikroTik routers. Handles customer accounts (PPPoE/hotspot), billing, M-Pesa payments, and router provisioning. Targeted at East African ISPs (Kenya). Deployed at `*.fortunetttech.site`.

## Commands

### PHP
```bash
composer format          # Auto-fix PHP formatting (PSR-12 via PHP CS Fixer)
composer format:check    # Dry-run format check
composer lint:php        # PHPStan static analysis
```

### JavaScript
```bash
npm run lint:js          # ESLint check
npm run lint:js:fix      # Auto-fix ESLint issues
npm run format           # Prettier format
npm run check:format     # Verify Prettier formatting
```

No test suite exists.

## Architecture

### Multi-Tenancy
Tenant isolation is subdomain-based: `HTTP_HOST` is parsed to extract the subdomain, which is looked up in the `tenants` table. Every database query is filtered by `tenant_id`. Wildcard DNS (`*.fortunetttech.site`) routes all subdomains to the same server.

### Request Flow
- **Admin portal:** Direct `.php` pages (e.g., `dashboard.php`, `billing.php`). All protected pages call `redirectIfNotLoggedIn()` from `includes/auth.php`.
- **API endpoints:** `/api/{domain}/{action}.php` — POST-only, return JSON. Validate `$_SESSION['user_id']` and verify tenant ownership on every request.
- **Customer portal:** `/customer/` — Uses `CustomerAuth` class; supports password, voucher, and MikroTik password auth modes.

### Database
Raw PDO — no ORM. Global `$pdo` is established in `includes/db_master.php` and used directly throughout. All queries use prepared statements. Core tables: `tenants`, `users`, `clients`, `packages`, `mikrotik_routers`, `payments`, `payment_gateways`, `mpesa_transactions`.

### Key Classes (`/classes/`)
- `MikrotikAPI.php` + `RouterOSAPI.php` — MikroTik RouterOS API client; used for provisioning PPPoE/hotspot users and fetching router stats.
- `MpesaAPI.php` — Safaricom Daraja STK Push and B2C; config in `config/mpesa.php`.
- `CustomerAuth.php` — Customer portal session management.
- `EmailHelper.php` / `SMSHelper.php` — Email (PHPMailer/SMTP) and SMS (TalkSasa) dispatch with DB logging.

### Payment Flow
1. STK Push initiated via `/api/payment/stk_push.php`
2. M-Pesa calls back to `/api/payment/callback.php`
3. Payment written to `payments` and `mpesa_transactions` tables
4. Client expiry extended based on package validity

### Configuration
Environment loaded from `.env` via `includes/env.php`. Sensitive config:
- `config/mpesa.php` — M-Pesa consumer key/secret, passkey
- `config/database.php` — DB credentials (falls back to hardcoded localhost defaults)
- `config/mikrotik.php` — Router connection defaults
- `config/constants.php` — App-wide enums (payment methods, status values, roles)

### Includes Pattern
`includes/` contains procedural helpers included at the top of pages. Key ones:
- `db_master.php` — establishes `$pdo`
- `auth.php` — session/login functions
- `tenant.php` — tenant context helpers
- `payment_gateway.php` — routes payment processing by gateway type
- `header.php`, `sidebar.php`, `footer.php` — shared layout

### MikroTik Provisioning
Routers are provisioned via the RouterOS API. Package changes (upgrade/downgrade/expiry) trigger API calls to add/remove/update PPPoE profiles or hotspot users. The provisioning script template is at `provisioning_script_template.rsc`.

### Captive Portal Auto-Sync
**Never re-provision a router just to publish a login-page change.** Every router runs a `FortuNett-Portal-Sync` script + scheduler (hourly + on startup) that pulls updates itself.

- `hotspot/login.html` — the template. MikroTik vars (`$(link-login-only)`, `$(mac-esc)`, `$(error)`, `$(username)`, `$(link-logout)`) must survive rendering; never introduce a literal `$(` anywhere else in the file.
- `hotspot/render_login.php` — builds the page. Single source of truth for both the served page and its version hash.
- `hotspot/login_serve.php?token=` — serves the rendered page to `/tool fetch`.
- `hotspot/login_version.php?token=` — returns `substr(sha1($renderedPage), 0, 12)`. The router fetches this first and only downloads the page when it differs, so edits to the template, a tenant's brand colour or their packages all publish automatically. There is no version number to bump.
- `includes/hotspot_sync.php` — `hotspotPortalUrls()`, the RouterOS script body, `installHotspotSyncScheduler()` (API install) and `hotspotSyncInstallerRsc()` (paste-able block).
- `hotspot/self_update_script.php` — serves that paste-able block for routers the API can't reach.
- `cron/sync_hotspot_pages.php` — hourly server-side sweep over reachable routers; CGNAT routers are skipped and rely on their own scheduler.
- Admin UI: **Sync Portal** button on `mikrotik.php` (`router_id=all`), plus per-router deploy.

### Never Trust the Callback Alone
M-Pesa callbacks are not guaranteed to arrive — a CDN/WAF in front of the callback URL, a Safaricom hiccup, or a customer who cancels all leave `mpesa_transactions` stuck on `pending`. Every payment path therefore needs a pull-based resolution:

- `hotspot_payment_status.php` calls `stkQuery()` inline once the transaction is ~25s old, throttled to roughly every 12s. This is what surfaces "you cancelled the payment" (ResultCode 1032) instead of spinning for five minutes.
- `cron/stk_poll.php` is the backstop for anyone who closed the portal. **It must be in crontab** — it is easy to miss because it was absent from this file's cron list for a long time.
- ResultCodes worth naming to the customer: `1032` cancelled, `1037` PIN timeout, `1019` expired, `2001` wrong PIN, `1` insufficient balance.

### Platform Suspension Loop
`check_suspensions.php` suspends on **outstanding invoices**, not on dates. Consequences that are not obvious from the super-admin UI:

- Setting a tenant to `active` or extending their days does **not** stick — the next daily run re-suspends them while any invoice is unpaid. The only durable fix is settling or waiving the invoices (`settle_all_invoices`, or per-invoice `mark_invoice_paid`).
- `super_admin/billing.php` defaulted its invoice list to the *current month*, so the older overdue invoices actually causing the suspension were invisible and their Mark as Paid buttons unreachable. Use `?all=1` / the **All Outstanding** button.
- The tenant detail page shows an outstanding-invoice banner explaining this, with Mark All Paid / Waive All.

### Paybill Account Matching
`includes/account_resolver.php` — `resolveAccountRef($pdo, $billRef, $msisdn, $tenantId|null)` is the **only** place that decides who paid. All four C2B handlers (platform + tenant, validation + confirmation) use it, so validation can never reject a reference confirmation would accept.

Match order: exact `clients.account_number` → phone (from the BillRef **or** the paying MSISDN, last 9 digits) → `PREFIX+client_id` → bare client id (tenant-scoped only). Phone matching matters because the captive portal's manual-paybill instructions tell customers to enter *their phone number* as the account.

Two rules that must not be relaxed:
- Duplicates **within one tenant** resolve to the active/newest row; a phone spanning **different tenants** must refuse — crediting the wrong ISP is worse than manual reconciliation.
- The prefix lookup mirrors `AccountNumberGenerator::getPrefix()` precedence (`tenant_settings` → `users.account_prefix` **any role** → subdomain → admin username). The old resolver checked only `users.account_prefix WHERE role='admin'`, so tenants whose admin row has another role could never be matched.

Validation handlers **accept unresolved references** rather than returning `C2B00011`. Bouncing the payment at the till strands a customer who mistyped one character; confirmation logs it as UNROUTABLE for a human instead.

### Speed Limit Enforcement
Rate limits live on the **package profile only**, never on the individual user.

- `rate-limit` format is `rx/tx` from the router's view — `{upload}M/{download}M`. See [[feedback_mikrotik_rate_limit]].
- A `rate-limit` set directly on an `/ip hotspot user` or `/ppp secret` **overrides the profile's**. Provisioning blanks it explicitly (`=rate-limit=`) on every add and update so the profile is the single source of truth. A leftover per-user value is how a 5M plan delivered line speed.
- The profile name must never resolve to `default` — RouterOS's default profile has no rate-limit, so falling back to it disables the cap entirely. `autoProvisionClient()` generates `pkg{id}-{slug}` when the package has no explicit `mikrotik_profile`.
- Never invent a speed. Missing `download_speed` used to default to 10 Mbps, silently over-delivering; it now provisions uncapped and logs loudly so the misconfigured package is findable.
- Changing a profile's rate-limit does not affect established sessions — kick them (`kickPPPoESession` / `kickHotspotSession`) or the old rate persists.

### Expiry Enforcement
`cron/check_expiry.php` runs every 15 min: `active` → `grace` (throttled) after expiry, → `inactive` (disabled + kicked) after `$graceDays`.

Two rules learned the hard way:
- **Never hard-code an `ALTER TABLE … MODIFY … ENUM(…)` in a cron.** This file used to reassert `ENUM('active','inactive','suspended','grace')` every 15 minutes, deleting the `pending` and `expired` members and re-breaking the hotspot STK push within the quarter hour of every migration. Use `ensurePaymentStatusEnums()`, which only ever adds members.
- Status-driven passes alone are not enough. A client already marked inactive — by an admin, a failed run, or an unreachable router — is never revisited, so their session stays up forever. The **enforcement sweep** at the end works from the router's view instead: list live PPPoE/hotspot sessions and cut any whose client is not currently `active` with a future expiry. It only touches usernames that map to a client of that tenant, so the operator's own admin links are never severed.

### Schema Guards
`includes/schema_guard.php` repairs schema drift in place when a deployment is missing a migration. Call `ensurePaymentStatusEnums($pdo)` at the top of any endpoint on the payment path. One-shot repair: `php tools/repair_status_enums.php` (or `sql/migrations/2026-07-26-payment-autoactivation.sql`).

It covers two distinct failure modes:
- **Out-of-range ENUM value** — production runs `STRICT_TRANS_TABLES`, so this throws `SQLSTATE[01000] 1265 Data truncated`. Surfaced to customers as "Payment could not be initiated" (`clients.status='pending'`) and silently blanked `payments.payment_method='mpesa_paybill'`.
- **Missing column** — a hard 1054 on *every* configuration. `mpesa_transactions` lacked `tenant_id`, `mpesa_receipt_number` and `raw_callback`; that INSERT sits **before** `process_payment_success()` in the C2B handlers, so the handler aborted and the customer was never activated despite the money arriving. `tenant_id` is also SELECTed by `hotspot_payment_status.php`, so STK polling never saw `completed` either.

**Diagnosing "paid but not connected": `php tools/diagnose_autoactivation.php`.** It walks the whole chain — callback logs, per-tenant M-Pesa wiring, C2B registration URLs, completed-payments-with-inactive-clients, the provisioning queue — and prints the broken links most-likely-cause first.

Note when reading column metadata: **MariaDB returns `information_schema.COLUMN_DEFAULT` already quoted** (`'inactive'`) while MySQL returns it raw (`inactive`). Quoting it again produces `DEFAULT '\'inactive\''` and a 1067 error.

## SaaS Platform Layer

### Super Admin Portal (`/super_admin/`)
Separate portal for FortuNett staff only. Auth guard: `super_admin/includes/auth.php` — checks `$_SESSION['is_super_admin']`. Login at `/super_admin/login.php`. Super admin users have `is_super_admin = TRUE` in the `users` table and `tenant_id = NULL`.

- `super_admin/index.php` — MRR dashboard, revenue trend charts, overdue invoice alerts
- `super_admin/tenants.php` — Tenant list/detail, suspend/activate buttons
- `super_admin/billing.php` — Platform invoice management, mark-paid, generate invoices
- `api/super_admin/tenants.php` — REST API: `set_status`, `save_notes`, `set_plan`, `mark_invoice_paid`
- `api/super_admin/billing.php` — Invoice generation endpoint (also used by billing page form)

### Platform Subscription Plans (`platform_subscription_plans` table)
Three tiers: Starter (KSH 25/PPPoE user, 3% hotspot commission), Growth (KSH 20/user, 2.5%), Enterprise (KSH 15/user, 2%). Rates are read per-tenant at billing time. Tenants are assigned `subscription_plan_id` on signup (default: Starter).

### Billing Engine (`/cron/monthly_billing.php`)
Runs on the 1st of each month. For each active/trial tenant: counts PPPoE users, sums hotspot collections, calculates fee, inserts a `platform_invoices` row, and emails an invoice to the tenant admin. Invoice number format: `INV-YYYY-MM-TENANTID`.

### Suspension Engine (`/cron/check_suspensions.php`)
Runs daily. Marks overdue invoices (past due_date + 7 grace days), sends 3-day warning emails, suspends tenants with overdue invoices, and auto-reactivates when all invoices are paid.

### Cron Schedule
```
# Monthly billing — 1st of each month at 06:00
0 6 1 * * php /var/www/html/cron/monthly_billing.php

# Daily suspension checks — 08:00
0 8 * * * php /var/www/html/cron/check_suspensions.php

# Router status — every 5 minutes
*/5 * * * * php /var/www/html/cron/check_router_status.php

# Client expiry enforcement — every 15 minutes
*/15 * * * * php /var/www/html/cron/check_expiry.php >> /var/log/fortunett_expiry.log 2>&1

# STK reconciliation — every 2 minutes. REQUIRED, not optional: without it a
# missing callback leaves the payment 'pending' forever.
*/2 * * * * php /var/www/html/cron/stk_poll.php >> /var/log/fortunett_stk_poll.log 2>&1

# Captive portal sweep — hourly, offset from the top of the hour to spread load
30 * * * * php /var/www/html/cron/sync_hotspot_pages.php >> /var/log/fortunett_portal_sync.log 2>&1
```

### Database Isolation Rules (enforced post-migration)
All queries against `clients`, `packages`, `payments`, `mikrotik_routers`, `payment_gateways`, `messages`, `vouchers`, `customer_sessions`, `payment_auto_logins`, `customer_activity_log` **must** include `tenant_id = ?`. The migration `sql/migrations/2026-03-26-saas-upgrade.sql` adds `tenant_id` columns to the five tables that were missing it and back-fills them.

## UI Design System — Dark Neumorphism

The app uses **dark neumorphism** throughout all portals (admin, customer, super-admin, and auth pages). All new UI must follow these rules.

### Auth Pages (`css/auth.css`)
Auth pages (login, signup, forgot-password, customer login) share a single CSS file with these tokens:
```css
/* Dark page with blue radial glow at top */
body.auth-page { background: #0e0e0d; }

/* Neumorphic card */
.auth-container {
  background: rgb(34, 34, 33);
  border-radius: 22px;
  box-shadow: 14px 14px 28px rgb(10,10,9),
              -7px -7px 18px rgb(44,44,42),
              0 0 0 1px rgba(255,255,255,0.043);
  padding: 40px 36px;
  max-width: 420px;
}

/* Dark inset inputs */
.form-control-auth {
  background: rgb(27,27,26);
  box-shadow: inset 4px 4px 8px rgb(10,10,9), inset -2px -2px 5px rgb(42,42,40);
  border: 1px solid rgba(255,255,255,0.06);
  color: #e8e8e6;
}

/* Button with brand glow */
.btn-auth {
  background: var(--brand-gradient);
  box-shadow: 0 4px 18px var(--brand-glow);
}
```
Font: `Plus Jakarta Sans` (loaded from Google Fonts in auth.css). Brand variables (`--brand`, `--brand-glow`, `--brand-gradient`) are set inline per-page from tenant branding.

### Customer Login
`customer/login.php` is a full PHP login page using `CustomerAuth->login()`. Accepts username / phone / email + password (or MikroTik password fallback). Sets `$_SESSION['customer_token']` on success and redirects to `customer/dashboard.php`.

### Color System (Admin Portal)
```css
/* Set dynamically in includes/header.php from tenant brand_color */
--primary-color: /* tenant brand color (default #3B6EA5) */
--primary-dark:  /* darkened by 40 RGB units */
--primary-light: /* lightened by 40 RGB units */
--sidebar-width: 250px
--sidebar-collapsed-width: 72px
--navbar-height: 60px
```
Never use hardcoded hex colors for primary/brand. Always use `var(--primary-color)`, `var(--primary-dark)`, `var(--primary-light)`.

### Dark Sidebar (both portals)
```css
/* Admin portal sidebar */
background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-color) 100%);
/* Customer portal sidebar */
background: linear-gradient(180deg, #111827 0%, #1e3a5f 55%, #2C5282 100%);
```
Sidebar menu items: `color: rgba(255,255,255,0.85)` default, active gets `rgba(255,255,255,0.15)` background + left border `var(--primary-light)`.

### Admin Dashboard Cards
- Background: `#fff`, border: `1px solid #E5E7EB`, border-radius: `10–12px`
- Hover: `box-shadow: 0 4px 18px rgba(0,0,0,.08)` + top-border reveal via `::after` pseudo-element
- Defined in `css/premium-theme.css`

### Buttons (Admin Portal)
```css
.btn-primary { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%); }
/* Hover: translateY(-1px), opacity 0.9 */
```
Never override Bootstrap button variants with hardcoded colors.

### Status Badges
Defined in `css/premium-theme.css`. Use `.status-badge.active/inactive/suspended/expired`. Active badge gets a pulsing green dot via `::before` with `pulse-dot` keyframe animation.

### Toast Notifications
- Admin portal: `showToast(msg, type)` — `includes/footer.php`
- Customer portal: `showCustToast(msg, type)` — `customer/includes/footer.php`
- Never use `alert()` for user-facing feedback

### Key Animation Classes (`css/premium-theme.css`)
- `fadeInUp` — page entrance for cards
- `pulse-dot` — status indicator
- `shimmer` — loading skeleton
- `gradientShift` — animated welcome banner (customer portal)

### M-Pesa Integration Rules
- Credentials per-tenant in `payment_gateways` table. `config/mpesa.php` is sandbox fallback only.
- Sandbox returns success code but **never pushes to real phones** — detect with `$mpesa->getEnvironment()` and show warning toast
- Callback URL: `credentials.callback_url` → `MPESA_CALLBACK_URL` constant → auto-detect from `$_SERVER['HTTP_HOST']`

### Dashboard Charts
- All data from `api/dashboard/stats.php` via AJAX — no hardcoded dummy data
- Auto-refresh every 60s; manual via `#dash-refresh-icon` spin animation
- Chart instances: `chartPayments`, `chartRegistrations`, `chartMonthly`, `chartPackage` (global, destroyed+rebuilt on refresh)
- Revenue queries always filter `status = 'completed'`
