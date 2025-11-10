-- Backfill clients.account_number using business initial + zero-padded id
-- Format: <Initial><LPAD(id,5,'0')> e.g. Fortunett + id=6 => F00006

-- Get business initial (single character). If isp_profile missing, default to 'I'
SET @initial := (SELECT UPPER(LEFT(business_name,1)) FROM isp_profile LIMIT 1);
SET @initial = COALESCE(NULLIF(@initial, ''), 'I');

-- Update clients without account_number (NULL or empty)
UPDATE clients
SET account_number = CONCAT(@initial, LPAD(id,5,'0'))
WHERE account_number IS NULL OR account_number = '';

-- Optional: show a few rows
SELECT id, account_number FROM clients ORDER BY id DESC LIMIT 20;
