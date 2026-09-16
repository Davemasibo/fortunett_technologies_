# Payment and delivery verification — 2026-09-15

## Outcome

Changes are in the local workspace. Production delivery has not been verified.
A checkout reference identifies an STK request; it does not prove payment.
A final receipt may also be present while local activation still needs repair.

## Changes

- Payment badges and details use the completed ledger state, matching dashboard revenue.
- Pending CSV exports no longer claim money was received.
- Failed STK reconciliation updates matching pending payment rows, with tenant isolation and protection for completed payments.
- Successful callback evidence is saved before fallible activation; amount, receipt conflicts and manual provenance are checked first.
- Confirmed payments without a completed ledger entry remain eligible for worker recovery beyond one day.
- Explicit tenant sender rejection can fall back to the configured platform SMS account and sender. Uncertain delivery does not trigger another send.
- Added a read-only aggregate audit: `php tools/audit_payment_delivery.php`.

## Checks passed

- `php tools/test_payment_failure_mysql.php`: actual daily/monthly/yearly dashboard queries exclude failed and pending amounts; failure propagation and delayed recovery selection pass in an isolated database.
- `php tools/test_payment_identity_mysql.php`: duplicate receipts, checkout aliases, concurrent writes and tenant boundaries; 11 checks pass.
- `php tools/test_hotspot_onboarding.php`: simulated purchased-duration activation, provisioning and automatic device session verification.
- `php tools/test_receipt_reconnect.php` and `php tools/test_paid_phone_recovery.php`: reconnect and failure handling.
- `php tools/test_sms_sender_fallback.php`, `php tools/test_sms_fallback.php`, `php tools/test_sms_retry.php`: fallback and duplicate-send protection using provider doubles.
- PHP syntax checks and `git diff --check` passed.

## Local environment findings

- Started the existing XAMPP MySQL service, which was stopped.
- Applied `php tools/migrate_payment_identity.php --apply` after its dry run found no duplicate pairs. The missing checkout identity column is now present.
- Local ledger: 9 completed payments totalling KES 7,523; 23 pending payments totalling KES 1,005,876. These figures are local data, not verified production balances.
- No completed payment matched a failed provider transaction in the available local records. Absence of a matching record is not independent proof of payment.
- Neither tenant has usable SMS credentials; no approved sender could be verified.
- Both configured routers were unreachable; no customer Internet session could be verified.
- One provisioning retry is queued. Neither `stk_poll` nor `retry_provisions` has a recorded worker heartbeat.

## Remaining live verification

Identify the production deployment and tenant; configure the actual provider token through settings and its approved sender ID. Confirm production scheduler entries run `cron/stk_poll.php` and `cron/retry_provisions.php` every minute. Check their heartbeats with the aggregate audit.

Use a designated customer test phone to verify STK receipt, cancellation, successful payment, callback reconciliation, one ledger credit, one entitlement extension, SMS receipt and actual Internet traffic through the router. Repeat recovery with a temporarily unavailable router and with a delayed callback. Provider acceptance alone does not prove handset SMS delivery, and router provisioning alone does not prove Internet traffic.

No real STK or SMS was sent during these checks. A zero-failure guarantee is not established by simulated tests.
