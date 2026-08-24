-- Tenant expiry columns: DATE -> DATETIME
--
-- Lets a super admin extend a tenant's platform access by an hour rather than a
-- whole day (super_admin/tenants.php -> "Subscription access").
--
-- The order below matters. MySQL widens '2026-08-24' to '2026-08-24 00:00:00',
-- but a bare DATE always meant "valid through the end of that day" — every
-- comparison in the codebase was `< today`. Left at midnight, a time-aware check
-- expires the tenant a full day early, so the second statement pushes the
-- converted values to 23:59:59 of the same day.
--
-- includes/schema_guard.php::ensureTenantExpiryPrecision() performs exactly this
-- at runtime, guarded by the platform_settings flag written at the end, so
-- running this by hand and letting the guard run are equivalent — not additive.

ALTER TABLE tenants
    MODIFY COLUMN trial_ends_at        DATETIME NULL DEFAULT NULL,
    MODIFY COLUMN subscription_ends_at DATETIME NULL DEFAULT NULL;

UPDATE tenants
SET trial_ends_at = DATE_ADD(trial_ends_at, INTERVAL 86399 SECOND)
WHERE trial_ends_at IS NOT NULL
  AND TIME(trial_ends_at) = '00:00:00';

UPDATE tenants
SET subscription_ends_at = DATE_ADD(subscription_ends_at, INTERVAL 86399 SECOND)
WHERE subscription_ends_at IS NOT NULL
  AND TIME(subscription_ends_at) = '00:00:00';

INSERT INTO platform_settings (setting_key, setting_value)
VALUES ('tenant_expiry_precision_migrated', 'manual')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
