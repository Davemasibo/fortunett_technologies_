# FortuNett → FyberPay: Gap Analysis & Upgrade Roadmap

_Date: 2026-06-15 • Reference target: fyberpay.com architecture spec_

## 0. TL;DR — Strategic recommendation

You have **two** codebases:

1. **Legacy PHP** (`C:\xampp\htdocs\fortunett_technologies_`) — current production. Strong at MikroTik provisioning, WireGuard NAT traversal, captive portal, C2B, and **two complete Kotlin mobile apps**. But it is a synchronous monolith with weak security posture and none of FyberPay's reliability/automation backbone.
2. **V2 monorepo** (`C:\fortunett-platform`) — a NestJS 10 + Next.js 15 rebuild that is **architecturally the same design as FyberPay** and already **~55-65% complete on the backend**.

**Recommendation: converge on V2. Do not retrofit FyberPay's architecture onto the PHP app.** V2 already has the expensive structural backbone (outbox, guards, encryption, RADIUS, RouterOS client, dunning state machine). The remaining distance to FyberPay is mostly **feature modules on a correct foundation**, plus **porting a handful of proven legacy pieces** (WireGuard tunnel, hotspot provisioning, C2B prefix routing, the mobile apps' API). This is a *finish-and-port* effort, not a rewrite.

Keep legacy PHP in production as-is while V2 is finished; migrate tenants over per-domain as V2 modules reach parity.

---

## 1. Capability matrix — FyberPay vs Legacy PHP vs V2

Legend: ✅ done · 🟡 partial/in-progress · 🔴 missing/not started

| Capability (FyberPay) | Legacy PHP | V2 monorepo | Notes / where the gap is |
|---|---|---|---|
| **Monorepo + shared DTO/Zod package** | 🔴 | ✅ | V2 has `@fortunett/shared`, Turbo, pnpm |
| **SQL migrations as source of truth** | 🟡 (runtime `ALTER TABLE` self-heal) | ✅ | Legacy mutates schema at request time |
| **Transactional outbox + listeners + dead-letter** | 🔴 (all inline/synchronous) | ✅ | V2's biggest win; legacy has none |
| **Subdomain tenancy + Redis cache + 3 guards** | 🟡 (two divergent resolvers) | ✅ | Legacy `CustomerAuth` lacks tenant filter |
| **Roles + org_memberships (multi-org user)** | 🔴 (single string role) | ✅ | |
| **Argon2 + lockout + OTP + rotating refresh** | 🔴 (bcrypt, no MFA/lockout) | ✅ (OTP delivery stubbed) | |
| **AES-256-GCM for ALL secrets** | 🟡 (only gateway creds, CBC; router pwd + WG key plaintext) | 🟡 (GCM, but `updateGateway` writes plaintext bug) | |
| **M-Pesa STK Push** | ✅ | ✅ | Both solid |
| **M-Pesa C2B (paybill)** | ✅ (prefix routing) | 🔴 | Port legacy logic to V2 |
| **M-Pesa B2C / disbursement / reversal** | 🔴 (queue table, no consumer; payouts manual) | 🔴 (tables exist, no code) | Neither pays ISPs automatically |
| **Multi-gateway (Paystack/card/manual)** | 🔴 (stubs "coming soon") | 🟡 (interface only) | |
| **Idempotency keys (HTTP-level)** | 🟡 (receipt-based only) | ✅ (interceptor) | |
| **Circuit breakers on external calls** | 🔴 | ✅ | |
| **Dropped-callback STK poll recovery** | ✅ | ✅ | |
| **Invoice state machine + PDF + credit ledger** | 🔴 (invoices created already-paid, no PDF, ledger write-only) | 🟡 (state machine ✅, no PDF, credit ledger partial) | |
| **Dunning state machine (walled-garden→suspend ladder)** | 🟡 (cron grace ladder, hardcoded days, duplicated) | ✅ | |
| **Pre-expiry auto-charge (card/mandate on file)** | 🔴 | 🔴 | FyberPay also lists this as a gap |
| **Compensation (extensions/boosts/credit, bulk grants)** | 🔴 | 🔴 | |
| **Router bootstrap script generator (click→self-config)** | ✅ (WireGuard + heartbeat) | 🟡 (generator exists) | Legacy is more complete here |
| **WireGuard/SSTP tunnel to reach NATed routers** | ✅ (WireGuard) | 🔴 (raw TCP 8728, no tunnel) | **Port WG to V2** — critical |
| **Stateful service reconciler (desired vs live, diff)** | 🔴 (fire-and-forget) | 🔴 (event types defined, never emitted) | FyberPay's NasService |
| **Firewall reconciler + drift detection/auto-heal** | 🔴 | 🔴 | |
| **RADIUS provisioning (radcheck/group/acct)** | 🟡 (write path + nightly reconcile, PPPoE only) | ✅ | |
| **RADIUS CoA (live throttle/disconnect, port 3799)** | 🔴 (next-reconnect only; uses MikroTik API kick) | 🔴 (port configured, no code) | |
| **GenieACS / TR-069 CPE management** | 🔴 | 🔴 | |
| **OLT / FTTH (SmartOLT)** | 🔴 | 🔴 | Both deferred |
| **GIS / subscriber mapping (PostGIS, QGIS)** | 🔴 | 🔴 | |
| **Notifications: multi-gateway SMS (8x)** | 🔴 (TalkSasa only; `provider` field cosmetic) | 🔴 (logger stubs, nodemailer unused) | |
| **Notifications: email templating + delivery log** | 🟡 (single template set, failures swallowed) | 🔴 | |
| **WhatsApp Cloud API + skills classification** | 🔴 | 🔴 | |
| **Push notifications (Web Push / FCM)** | 🔴 | 🔴 | |
| **Sandbox guard (block demo-org real sends)** | 🔴 | 🔴 | |
| **Customer self-service portal** | 🟡 (balance/pay/renew/profile; no invoices/tickets/usage) | 🔴 (`app/portal` empty) | Legacy ahead here |
| **Support tickets / inbox** | 🔴 | 🔴 (frontend mock only) | |
| **Super-admin / platform-billing portal** | ✅ (PHP super_admin) | 🔴 (`listPlatformInvoices` returns `[]`) | Legacy ahead here |
| **Analytics aggregators + CSV export** | 🟡 (dashboard stats) | 🟡 (dashboard ✅, no analytics module/export) | |
| **Audit trail (audit_logs + history tables)** | 🟡 (activity log) | 🟡 (audit_logs + subscription_history) | |
| **Platform kill switches (Zod-validated settings)** | 🔴 | 🟡 (`platform_settings` table) | |
| **AI assistant (SSE chat + action/read tools)** | 🔴 | 🔴 | |
| **PWA (Serwist, offline, install prompt)** | 🔴 | 🟡 (Next 15, PWA not wired) | |
| **Mobile apps (Android, JWT, STK)** | ✅✅ (admin + customer, well-built) | 🔴 | **Legacy's standout asset** |
| **eTIMS (KRA VAT) integration** | 🔴 | 🔴 | FyberPay also future-scoped |

---

## 2. Where each system genuinely leads

**Legacy PHP is ahead of V2 on:**
- **WireGuard NAT traversal** — V2 talks raw TCP to `8728` with no tunnel; this won't reach routers behind CGNAT/dynamic IPs. Legacy's `WireGuardManager` + deterministic `10.200.200.{id}` + heartbeat re-register is production-proven and **must be ported into V2 before V2 can manage real-world routers.**
- **Hotspot provisioning + captive portal** — full RouterOS 6/7 login-page upload, walled-garden, MAC-bind. V2's hotspot controller is a read-only stub.
- **C2B prefix routing** — `{PREFIX}{CLIENT_ID}` account decoding for shared paybill. V2 has no C2B at all.
- **Two complete Kotlin mobile apps** — admin + customer, proper JWT (15-min access + hashed refresh in EncryptedSharedPreferences), STK with status polling. These consume the legacy `/api/v1/` layer; they'd need a base-URL/host switch to point at V2.
- **Super-admin platform billing** — working monthly billing engine, suspension/reactivation, invoice emails.

**V2 is ahead of legacy on essentially everything architectural:** outbox, guards, encryption-at-rest discipline, circuit breakers, HTTP idempotency, dunning state machine, RADIUS provisioning, and a clean module-per-domain layout that matches FyberPay 1:1.

---

## 3. The gaps that matter most (critical missing logic)

These are the items that separate "an ISP billing app" from FyberPay's **fully-automated, self-healing** platform. In priority order:

### Tier 1 — Reliability & automation backbone (mostly DONE in V2, MISSING in legacy)
1. **Transactional outbox for every side effect.** Legacy runs invoice/ledger/commission/RADIUS/SMS/provisioning *inline inside the Safaricom callback request* — a slow router slows the callback, and a mid-pipeline crash leaves partial state (the pipeline has **zero DB transactions**). This is the single biggest reliability gap in legacy and the single biggest thing V2 already fixes.
2. **Stateful network reconciler + drift detection.** *Neither* system has FyberPay's `NasService` (desired-vs-live diff, tag-namespaced resources, `applyService/removeService/reconcileService`) or the drift scheduler (detect-only for services, auto-heal for firewall). Today both systems are fire-and-forget: if someone Winbox-edits a router or a provision half-applies, nothing converges it back. **This is the highest-value *new* capability to build.**
3. **RADIUS CoA.** Live throttle/disconnect without waiting for reconnect. Both systems lack it; FyberPay uses it for FUP enforcement and instant suspension. V2 already has the port wired — needs the packet-builder.

### Tier 2 — Money correctness & automation
4. **Automated ISP payouts (B2C/disbursement).** Both systems queue payouts but pay ISPs **manually**. FyberPay's `DisbursementListener` + reversal state machine automate this. Build on V2's outbox.
5. **Pre-expiry auto-charge.** Neither charges before expiry; customers silently lapse. FyberPay flags this as a gap too — be the one who closes it (requires a stored-mandate/`autoChargeConsent` model).
6. **Invoice PDFs + credit-balance ledger that's actually read.** Legacy writes ledger rows nobody consumes; V2 has the state machine but no PDF. FyberPay renders PDFs preferring PPPoE username as account number.
7. **Fix the live bugs surfaced by the audit** (see §5).

### Tier 3 — Coverage parity
8. **Notifications platform.** Multi-gateway SMS (FyberPay supports 8), email templating with real delivery tracking, **WhatsApp Cloud API + skills classification**, Web Push, and the **sandbox guard** that prevents demo orgs from texting real Kenyan numbers. Both systems are at logger-stub / single-provider level.
9. **Customer/subscriber portal in V2** (`app/portal` is empty) — port legacy's balance/pay/renew/profile and add invoice history, usage (wire `radacct`), and support tickets.
10. **Super-admin + platform billing in V2** — port the working PHP engine.
11. **GenieACS/TR-069** for FTTH ONT auto-config (tier-gated like FyberPay).

### Tier 4 — Differentiators / future
12. GIS/PostGIS subscriber mapping (QGIS/QField), AI assistant (SSE chat + action/read tools), eTIMS KRA integration, growth/pSEO engine.

---

## 4. Recommended roadmap (converge-on-V2)

**Phase 0 — Stabilize V2 foundation (1-2 wks)**
- Fix `SettingsService.updateGateway` plaintext-credential bug (it writes plaintext into `*_encrypted` columns, so per-tenant M-Pesa silently falls back to platform creds).
- Wire OTP delivery (currently `logger.log`).
- Decide BullMQ vs `@nestjs/schedule` (BullMQ is a dependency but unused) — FyberPay uses BullMQ for invoice-gen/usage-rollup; keep it for heavy fan-out, crons for pollers.

**Phase 1 — Make V2 able to run real routers (2-3 wks)**
- **Port WireGuard tunnel** from legacy `WireGuardManager` into V2 network module; prefer `vpn_ip` for all RouterOS calls.
- Port hotspot provisioning + captive-portal login-page upload.
- Finish PPPoE/Hotspot controllers (currently read-only stubs).

**Phase 2 — Money parity (2-3 wks)**
- Port C2B prefix routing into V2 payments.
- Implement B2C disbursement listener + reversal state machine (off the outbox).
- Invoice PDF service + credit-ledger consumption + pre-expiry auto-charge job.

**Phase 3 — Automation backbone (the FyberPay differentiator) (3-4 wks)**
- Build the **stateful NasService reconciler** (desired-vs-live diff, tag namespace `FortuNett-svc:*`, apply/remove/reconcile) + **drift scheduler** (detect services, auto-heal firewall).
- Implement **RADIUS CoA** (port 3799 packet builder) for live FUP throttle + instant disconnect.

**Phase 4 — Coverage parity (3-4 wks)**
- Notifications module: multi-gateway SMS adapters, email templating + delivery tracking, **sandbox guard**, then WhatsApp + Web Push.
- Subscriber portal (`app/portal`) + super-admin/platform-billing port.
- Point the **existing Kotlin mobile apps** at V2 (`/api/v1/`-equivalent or a thin compatibility layer) — this is low effort for high perceived parity.

**Phase 5 — Differentiators**
- GenieACS/TR-069, GIS/PostGIS, AI assistant, eTIMS.

---

## 5. Live bugs found during the audit (fix regardless of roadmap)

**Legacy PHP:**
- `gateway_type` mismatch: `MpesaAPI`/`stk_push.php` use `'mpesa_api'`, but `callback.php`/`stk_poll.php` decide `platformCollected` via `gateway_type = 'mpesa'` → **every STK payment may be treated as platform-collected**, queuing payouts even for own-credential ISPs.
- `tenant_bills` vs `platform_invoices` not reconciled: paying a platform invoice via in-app STK writes `tenant_bills`, but suspension/reactivation reads `platform_invoices` → **paid tenants may stay suspended**.
- Payment pipeline has **no DB transaction** — partial state on crash.
- Duplicate reminder logic in `cron/expiry_reminders.php` **and** `cron/check_expiry.php` step 0 → racy/duplicate SMS.
- Reminder flags marked "sent" even when SMS **fails** — customers silently never warned.
- `CustomerAuth::login()` has **no `tenant_id` filter** → cross-tenant login risk.
- Router passwords + WireGuard private keys stored **plaintext**.
- `MpesaAPI` curl uses `CURLOPT_SSL_VERIFYPEER => false`.

**V2:**
- `SettingsService.updateGateway` stores Daraja creds **plaintext** in `*_encrypted` columns (breaks per-tenant payments).
- `NAS_SERVICE_DRIFTED` etc. event types defined but never emitted/handled (reconciler not built).
- `notification_logs` + `gateway_credentials` tables exist in SQL but unmodeled in Prisma / unused.

---

## 6. Bottom line

FyberPay's advantage isn't features — it's **automation and self-healing**: every side effect goes through an outbox, every external call is circuit-broken and idempotent, and the network layer continuously reconciles desired state against what's actually on the router. **Your V2 already has the outbox, guards, encryption, and RADIUS.** The three things that will make V2 *feel* like FyberPay are: (1) the **stateful network reconciler + drift detection**, (2) **RADIUS CoA** for live enforcement, and (3) the **notifications platform with WhatsApp + sandbox guard**. Everything else is porting proven legacy logic onto the better foundation.
