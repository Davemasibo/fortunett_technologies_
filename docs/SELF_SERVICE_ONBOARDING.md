Self-service trial onboarding
============================

Deploy the changed PHP files and run:

    php tools/migrate_self_service_onboarding.php

Tenants resume from onboarding.php. Existing devices require a fresh successful
configuration verification before router setup counts as complete. Failed checks
clear previous verification. Verification is historical, not a live availability
guarantee. Customer tests still need to be performed by the tenant.

Super admins can open super_admin/onboarding.php via Tenant Management to see
unfinished active trials, last verification failures and delivered reminder stages.

Preview reminder content without sending email:

    php cron/onboarding_reminders.php

After reviewing recipients in the admin report and approving delivery, schedule
the following hourly using the deployment's PHP executable and absolute paths:

    1php cron/onboarding_reminders.php --send

The job uses registered tenant admin emails, verified accounts, active trial dates
and saved router verification. It sends at most one current stage (welcome, day1,
day3), with a minimum 24-hour gap; old trials do not receive a backlog. Successful
delivery is recorded and completed setups are excluded. A database advisory lock
prevents overlapping jobs. SMTP delivery and database recording cannot form one
transaction: a crash after delivery but before recording can cause a retry.

No provisioning token or router password is included in email. Existing signup
email verification remains responsible for unverified accounts. This job does not
extend trials or alter billing. Preview output contains no email addresses.
