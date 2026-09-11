# Hotspot expiry correction across tenants

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
