CREATE TABLE IF NOT EXISTS router_onboarding_checks (
 tenant_id INT NOT NULL,
 router_id INT NOT NULL,
 services VARCHAR(64) NOT NULL DEFAULT '',
 verified_at DATETIME NULL,
 checked_at DATETIME NOT NULL,
 failure_summary VARCHAR(1000) NOT NULL DEFAULT '',
 PRIMARY KEY (tenant_id, router_id)
);
CREATE TABLE IF NOT EXISTS onboarding_reminders (
 tenant_id INT NOT NULL,
 stage VARCHAR(24) NOT NULL,
 sent_at DATETIME NULL,
 PRIMARY KEY (tenant_id, stage)
);
