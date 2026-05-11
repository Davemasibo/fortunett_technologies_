-- Fix platform_mpesa_config C2B URLs: update any stored /api/mpesa/ paths to /api/payment/
-- Safe to run multiple times (REPLACE only changes rows that contain the old path).

UPDATE platform_mpesa_config
SET
    callback_url          = REPLACE(callback_url,          '/api/mpesa/', '/api/payment/'),
    c2b_validation_url    = REPLACE(c2b_validation_url,    '/api/mpesa/', '/api/payment/'),
    c2b_confirmation_url  = REPLACE(c2b_confirmation_url,  '/api/mpesa/', '/api/payment/')
WHERE id = 1;

SELECT
    callback_url,
    c2b_validation_url,
    c2b_confirmation_url
FROM platform_mpesa_config
WHERE id = 1;
