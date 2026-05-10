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

// Environment: 'sandbox' or 'production'
define('MPESA_ENV', 'sandbox');

// FortuNett platform consumer credentials (from Safaricom Daraja portal)
define('MPESA_CONSUMER_KEY',    'XhBs3sSu9aNffPnYtGrDz52J7SlKe7D4jgN5STgfbydmR0LR');
define('MPESA_CONSUMER_SECRET', 'fvS7Mnj0HKyKscKXgIW1YRBDcc6kPcvhIdLRzjks2Mm2pK4Msb0JL77SGob3Ol7d');

// Platform paybill / till number and Lipa na M-Pesa passkey
define('MPESA_SHORTCODE', '174379');
define('MPESA_PASSKEY',   'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919');

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
