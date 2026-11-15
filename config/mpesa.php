<?php
/**
 * Platform-level M-Pesa / Safaricom Daraja configuration
 *
 * These are FortuNett's own credentials used as a FALLBACK for tenants
 * that have not configured their own M-Pesa API gateway in Settings → Payments.
 *
 * ┌──────────────────────────────────────────────────────────────────────┐
 * │  Credential priority (MpesaAPI class):                               │
 * │  1. Tenant's own credentials (payment_gateways table, mpesa_api type)│
 * │  2. These platform constants (fallback / shared paybill)             │
 * └──────────────────────────────────────────────────────────────────────┘
 *
 * For the SHARED PAYBILL flow (tenants without own API creds):
 *  - Customers pay to MPESA_SHORTCODE using account numbers like "J0023"
 *    where "J" is the tenant's account_prefix and "0023" is the client ID.
 *  - Register MPESA_C2B_CONFIRMATION_URL in Safaricom Daraja as the
 *    C2B Confirmation URL for MPESA_SHORTCODE.
 *
 * Replace sandbox values with your production credentials before going live.
 */

// Credentials are supplied by the server environment and must never live in source.
define('MPESA_ENV', getenv('MPESA_ENV') ?: 'sandbox');

// FortuNett platform consumer credentials (from Safaricom Daraja portal)
define('MPESA_CONSUMER_KEY',    getenv('MPESA_CONSUMER_KEY') ?: '');
define('MPESA_CONSUMER_SECRET', getenv('MPESA_CONSUMER_SECRET') ?: '');

// Platform paybill / till number and Lipa na M-Pesa passkey
define('MPESA_SHORTCODE', getenv('MPESA_SHORTCODE') ?: '');
define('MPESA_PASSKEY',   getenv('MPESA_PASSKEY') ?: '');

/**
 * STK Push callback URL.
 * For tenant-specific flows the MpesaAPI class auto-resolves this from
 * the tenant's subdomain (https://{subdomain}.fortunetttech.site/api/payment/callback.php).
 * This constant is only used when the host cannot be auto-detected.
 */
define('MPESA_CALLBACK_URL', 'https://fortunetttech.site/api/payment/callback.php');

/**
 * C2B Confirmation URL — register this in Daraja for the platform shortcode.
 * This handles all payments made to the shared paybill via account numbers.
 */
define('MPESA_C2B_CONFIRMATION_URL', 'https://fortunetttech.site/api/payment/c2b_confirmation.php');
define('MPESA_C2B_VALIDATION_URL',   'https://fortunetttech.site/api/payment/c2b_validation.php');

// API base URLs (set automatically below — do not edit)
if (MPESA_ENV === 'production') {
    define('MPESA_OAUTH_URL',    'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    define('MPESA_STK_PUSH_URL', 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
} else {
    define('MPESA_OAUTH_URL',    'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    define('MPESA_STK_PUSH_URL', 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
}

define('MPESA_HTTP_TIMEOUT', 30);
