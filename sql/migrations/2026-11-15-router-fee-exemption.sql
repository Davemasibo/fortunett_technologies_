-- Mark a configured MikroTik as fee-exempt without deleting its management record.
ALTER TABLE mikrotik_routers
    ADD COLUMN IF NOT EXISTS platform_billable TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Whether this router incurs a platform monthly fee';
