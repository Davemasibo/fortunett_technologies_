-- Fix missing package_price column
ALTER TABLE clients ADD COLUMN IF NOT EXISTS package_price DECIMAL(10,2) DEFAULT 0;

-- Verify column exists
SELECT 'Column added successfully' as status;
