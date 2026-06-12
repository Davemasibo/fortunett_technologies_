-- Migration: 2026-06-07-expiry-reminders
-- Adds columns to track whether pre-expiry SMS reminders have been sent,
-- preventing duplicate messages when the cron runs every 15 minutes.
-- Safe to run repeatedly (IF NOT EXISTS / IF NOT EXIST patterns).

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS expiry_reminder_3d_sent TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS expiry_reminder_1d_sent TINYINT(1) NOT NULL DEFAULT 0;

-- Reset reminder flags when a client renews (expiry_date pushed forward)
-- This is handled in code (payment_pipeline.php step 2 already updates expiry_date).
-- A DB trigger would also work but code-side reset in pipeline is sufficient.
