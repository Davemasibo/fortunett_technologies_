# Payment notification recovery ? 9 October 2026

The fix is deployed on 212.95.34.211 under
`/var/www/html/fortunett_technologies_`. The rollback backup is
`/root/fortunett-payment-backup-20261009T045135Z/original-files.tar.gz`;
`sms_verify.php` is backed up separately in the same directory.

## Confirmed production findings

Ghettoh Link is tenant 9. Both STK and provisioning workers run every minute.
The TalkSasa token authenticates, but the provider rejects the configured sender:
`Originator TALKSASA is not authorized to send this message`.
The operator confirmed that this free sender was discontinued and is obtaining
an approved replacement. All 132 payment SMS records in the audited three-day
window were marked failed; 583 total SMS outbox entries in that window failed.

Router 19 was reachable with four active hotspot sessions and recorded traffic.
Two other customers with current paid hotspot access had no visible device on
the router. Logs show a paid client needed 86 retry attempts before reconnecting,
and contain router reachability failures. A later read-only probe also timed out;
subsequent TCP and ping checks succeeded. Server-to-router API connectivity is intermittent; the underlying
network cause has not been isolated. No router/firewall configuration was changed.

## Changes

- Credentials persist before router contact and remain stable across retries.
- A unique tenant/payment notification carries username, password, paid expiry,
  receipt and manual Wi-Fi login instructions.
- Callback replays can repair notifications without extending paid access again.
- Failed logs and old receipts without login details do not suppress delivery.
- STK reconciliation repairs the current unexpired plan's missing notification,
  without resending obsolete historical renewals.
- `cron/retry_provisions.php` retries pending and explicitly rejected messages.
- Sender/authentication/configuration rejections are held as `blocked_config`.
  Changing the effective sender/token settings automatically resumes these jobs.
  The discontinued sender is not repeatedly contacted for the same message.
- Unknown or interrupted sends stay held for provider history verification.
- SMS diagnostics report `Sender rejected` instead of `Working` when token
  authentication succeeds but the configured sender has recent rejection evidence.

Six current paid hotspot accounts have stable credentials and six queued login
messages, all held for sender configuration. Recovery populated older valid plans
without replaying activation, changing expiry or sending obsolete receipts.
Part-payment credit behavior is unchanged. Existing customer portal credentials
and receipt reconnection remain available while SMS is blocked.

## Validation

Isolated MySQL regression tests passed for stable credentials, tenant isolation,
deduplication, rejection recovery, uncertain sends and automatic resumption after
configuration changes. Sender diagnostic regressions, onboarding simulations,
payment failure recovery, SMS fallback/retry and receipt reconnection checks passed.
Production PHP lint, deployment checksums, PHP-FPM reload and worker heartbeats
were checked. The dashboard returns its normal unauthenticated HTTP 302 redirect.
The credential queue stays blocked with at most one attempt per affected message.

## Remaining verification

Configure the approved replacement sender through SMS settings. The worker should
resume queued messages automatically. Verify a real customer's handset receipt,
manual login, automatic router session and actual Internet browsing. Provider
acceptance and existing router traffic counters alone do not prove handset
receipt or current end-to-end Internet access. No new payment or test SMS was
initiated. Existing paid notification retries made the expected rejected send
attempts before entering their blocked state.

Intermittent router connectivity remains an operational limitation. Credentials
cannot reconnect a device absent from Wi-Fi or restore access while the router
is unreachable. No zero-failure guarantee or full historical compensation was
established by this deployment.

## 10 October 2026 router workload repair

Deployed the optimized paid-expiry watchdog to tenant 9's RB951 (router 19).
The five-second deadline backstop now scans enabled managed users and actual
sessions/cookies/bypass bindings, rather than repeatedly rewriting every expired
disabled account. A named script includes an overlap guard. Paid deadlines,
one-shot expiry jobs and login guards remain in force; purchased time was not
extended. Installation verifies the script and scheduler before enabling users.

Native RouterOS execution passed before deployment. Deadline, onboarding and
watchdog installation/idempotence/failure regression checks passed. Production
and local PHP SHA256 values match. Three existing sessions remained connected.
CPU was approximately 79% before the repair. Two subsequent six-sample runs
measured 2–32% and 1–74%; short bursts and intermittent API connection failures
remain, so these observations do not establish sustained capacity or complete
end-to-end recovery.

Customer investigation (times Africa/Nairobi): account G024, phone ending 5446,
paid KES25 on 9 October at 17:05 and had an eight-hour plan ending 10 October
01:05; last recorded connection was 01:05. Account G172, phone ending 2685,
paid KES10 at 20:46 for one hour, had a recorded connection around 20:52 and
expired at 21:46. Both plans were expired at investigation time. The same phone
also has an unpaid account G195; it was not merged or activated. No customer,
provider transaction or unmatched payment matched the last seven digits of the
reported numbers ending 1697 and 5735. Receipts or corrected numbers are needed.

At 07:16 on 10 October, tenant 9 had zero paid provisions awaiting retry, six
current paid accounts with credentials, and 53 credential messages held for the
rejected TALKSASA sender. Worker heartbeats were current. Dashboard returned 302.

Rollback backups on production are
`/root/fortunett-router-expiry-before-20261010.php` and
`/root/fortunett-watchdog-before-20261010.json`. Router network, firewall,
firmware and existing session assignments were not changed.

## 10 October 2026 captive payment UX deployment

Published the new portal to GhettoLink router 19 and verified its build marker
`b06ffe245681` against the tenant renderer. Confirmed payments with an existing
activation now read status without waiting on the callback's customer lock or
repeating router provisioning. Missing provisioning is durably queued without
postponing existing retries. Processing responses expose stored credentials only
after payment confirmation and an active, unexpired subscription. Expired,
suspended and part-paid access rules remain enforced.

The portal checks immediately after an STK request and then every two seconds,
with no overlapping requests. Removed the misleading five-minute countdown.
Confirmed-but-processing payments show credentials, an explicit connection retry
and a customer-account link without navigating away automatically. Closing the
dialog exposes the saved checkout recovery action. Passwords are not stored in
browser storage. Pressing Buy again while a saved checkout is unresolved resumes
that checkout instead of issuing another STK prompt. Phone and TV handoffs remain distinct, and returning after a
rejected login does not start a navigation loop. Server retries do not require
the captive browser to remain open, but the device must remain on Wi-Fi.

SMS jobs are saved before router I/O; actual provider sending now follows the
router connection attempt so SMS latency cannot delay it. Reuse of unexpired
pending account tokens avoids inserting a new token every two-second poll.

Live verification also found router 19 marked inactive by reachability probes
despite successful API access. Router discovery and background session recovery
now include inactive/offline health states and attempt an actual connection.
Suspended and pending routers are excluded from the candidate set. Discovery
still requires device evidence or a prior assignment when several routers exist.

Mobile browser tests passed for processing credentials, close/resume without a
second charge, router-rejection recovery, and late-response/navigation races.
Layout tests passed at four mobile widths; isolated MySQL router recovery and
notification tests, payment/connectivity and receipt reconnection checks passed.
A live already-activated payment status returned HTTP 200 with credentials in
114–150 ms while its customer lock was deliberately held. This measures status
latency, not a new STK-to-Internet connection. No new real payment was initiated.
All five deployed files match local checksums. Backups are under
`/root/fortunett-portal-ux-before-20261010/`.

## Platform-wide rollout and Git reconciliation, 10 October 2026

The production edits matched incoming commit `06c3e4d`, including the previously
untracked notification helper. Saved only those payment/portal paths in the
named stash `fortunett-reviewed-payment-portal-before-06c3e4d`, then fast-forwarded
production. The encryption key, environment backups, logs and unrelated local
deletions were preserved. Do not apply this stash over the same committed fixes.

The shared backend and rendered captive template apply across tenants. Fleet
portal synchronization now includes temporarily inactive/offline routers, reads
the build marker explicitly when file listings omit contents, and verifies the
published build after triggering a download. Direct-upload recovery verifies
portal contents. The sweep also installs/verifies the optimized paid-expiry
watchdog on reachable hotspot routers. Check mode performs no writes.

Installed a server sweep every five minutes with
`/run/fortunett-portal-sync.lock`; the previous crontab is saved at
`/root/fortunett-crontab-before-fleet-20261010.txt`. Router pull schedules remain
hourly and run on startup when installed. Failed server reachability probes do
not establish whether an unreachable router has its pull scheduler installed.

The initial fleet run verified tenant 5/router 12's build `bf797eb3465d` and
tenant 9/router 19's build `990c85eaec4d`. Tenant 1/router 9, tenant 6/router 15
and tenant 14/routers 20 and 21 were unreachable and remain awaiting verification.
The server will keep attempting them. Do not report the four router copies as
updated until a successful connection and readback occurs. Shared server changes
are already published for their tenants.
