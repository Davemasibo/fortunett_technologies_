Payment identity and router ownership
====================================

Back up the database and affected PHP files before deployment. Add the nullable
`payments.checkout_request_id` and default `clients.router_ownership` columns
before switching the application files (the migration adds both if absent).

Run from the application directory:

    php tools/migrate_payment_identity.php
    php tools/migrate_payment_identity.php --apply --root-socket

The first command reports exact gateway-backed duplicate pairs. The second uses
local database administrator authentication to install triggers; this avoids
granting SUPER to the application's database account on binary-logged servers.
Omit `--root-socket` only if the deployment account already has trigger privileges.

The migration archives full duplicate payment rows in `payment_duplicate_archive`,
retains the receipt row, and refuses automatic cleanup when the duplicate has
invoice, ledger, commission, allocation, login or payout dependencies. It never
replays activation or sends messages. Conflicting old receipt references across
customers are preserved for review. The registry reserves existing references
and rejects reuse, even after deleting or changing the original reference.

Checkout uniqueness is enforced per tenant. Insert/update triggers also reserve
receipt references transactionally, including writes from legacy endpoints.
Blank references remain nullable for pending payment initiation. Completed STK
payments retain their checkout identity after receiving the final receipt.

Verification:

    php tools/test_payment_identity_mysql.php --root-socket
    php tools/test_hotspot_onboarding.php

The MySQL suite creates and drops a randomly named isolated database, tests a
competing writer, and does not call payment, router or SMS services. Local root
database access is required only for that throwaway database and trigger setup.

Live deployment on 2026-09-13 archived 56 proven duplicates (55 for tenant 9,
KES 1,825 removed from duplicate totals). Invoices, ledger entries, commissions,
payout queues, allocations and payment activations matched the predeployment
backup exactly. Three older conflicting reference groups remain for review:
payment IDs 1/4, 16/17 and 102/104. Do not merge them based only on receipt text.

Router ownership defaults to `unknown`; dashboard creation/editing supports
`isp` and `customer`, with list labels, filtering and CSV export. Existing
ownership is not inferred from payment method, package or router model.
