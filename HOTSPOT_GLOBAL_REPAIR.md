# Hotspot expiry correction across tenants

## Approved ghettohlink tariff correction

The owner approved the screenshot's advertised durations for tenant 9, including historical correction: KES 5/10/15/20/25/30/40/100/150/700 correspond to 30 minutes/1 hour/3 hours/6 hours/8 hours/14 hours/24 hours/4 days/7 days/30 days. `config/ghettohlink_hotspot_tariffs.json` records that authorization. This file must not be applied to another tenant.

```bash
php tools/reconcile_hotspot_expiries.php --tenant=9 --tariffs=config/ghettohlink_hotspot_tariffs.json
php tools/reconcile_hotspot_expiries.php --tenant=9 --tariffs=config/ghettohlink_hotspot_tariffs.json --apply > /root/hotspot-expiry-repairs.json
```

This corrects package durations as well as reconstructing customer deadlines. It matches exact prices, preserves shorter recorded terms, and uses original payment_date only when the activation timestamp was not saved; that conservative fallback may exclude the seconds spent awaiting the original confirmation and is explicitly recorded. It never uses the mutable STK updated_at timestamp. Unknown amounts and financial-ledger discrepancies remain review items. Successful apply writes durable router jobs; cron/retry_provisions.php must run and its router results must be audited. No live changes have been performed by local tests.

The receipt reconnect fix prevents reused payment codes from granting time. Additional guards stop v1 renew/edit requests and paid customer registration/import from creating unpurchased hotspot access. Paid registrations remain pending with no expiry; contact imports preserve existing hotspot access settings. Free packages retain their configured finite duration.

The bulk reconciliation command replaces account-specific repair scripts. It selects every hotspot customer in the chosen tenant, or every tenant, and reconstructs the purchased deadline chronologically from payment-linked duration snapshots and activation timestamps. It does not use today's package or the existing potentially inflated expiry as its starting point.

Preview the complete platform:

```bash
php tools/reconcile_hotspot_expiries.php --all-tenants > /root/hotspot-expiry-plan.json
```

Apply verified reductions and queue router synchronization:

```bash
php tools/reconcile_hotspot_expiries.php --all-tenants --apply > /root/hotspot-expiry-repairs.json
```

Both modes inspect live records under the per-customer payment lock. Apply re-evaluates evidence rather than trusting a stale preview. Every correction is logged with the original expiry and is committed atomically with its router job. The existing provisioning worker applies that job and disconnects customers whose corrected deadline has passed. The output explicitly does not certify router enforcement until a live audit and traffic test verify it.

Historical purchases without immutable terms, confirmed STKs missing from the payment ledger, and corrections that would increase access are reported for review. They are not silently marked resolved or replayed as new purchases. For historical purchases whose original terms have been verified, `--terms=/root/reviewed-hotspot-terms.json` accepts an object keyed by `tenant_id:payment_id`. Each entry supplies `validity_value`, `validity_unit`, `confirmed_at` (original activation time in the application timezone), and a non-empty `source` describing the evidence. It only fills missing data; it does not replace stored terms. Preserve this reviewed file with the correction report.

Local checks: `php tools/test_hotspot_global_policy.php`, `php tools/test_receipt_reconnect.php`, and `php tools/test_tv_onboarding.php`. Real database execution, deployment, financial corrections, and router traffic verification remain separate required steps; passing unit tests is not proof of those steps.
