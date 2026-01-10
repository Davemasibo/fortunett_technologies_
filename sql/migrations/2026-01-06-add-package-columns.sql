ALTER TABLE packages 
ADD COLUMN validity_value INT DEFAULT 30,
ADD COLUMN validity_unit VARCHAR(20) DEFAULT 'days',
ADD COLUMN device_limit INT DEFAULT 1;
