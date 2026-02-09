<?php
// MPESA / Safaricom Daraja configuration
// Paste your Consumer Key and Consumer Secret here (provided by Safaricom)
// Also provide your Business Shortcode and Passkey for STK push (Lipa na M-Pesa Online)

// Environment: 'sandbox' or 'production'
define('MPESA_ENV', 'sandbox');

// Consumer credentials (from Safaricom Daraja)
define('MPESA_CONSUMER_KEY', 'XhBs3sSu9aNffPnYtGrDz52J7SlKe7D4jgN5STgfbydmR0LR');
define('MPESA_CONSUMER_SECRET', 'fvS7Mnj0HKyKscKXgIW1YRBDcc6kPcvhIdLRzjks2Mm2pK4Msb0JL77SGob3Ol7d');

// Business Shortcode & Passkey (required for STK push)
// Example sandbox shortcode: 174379 and a passkey provided with your sandbox credentials.
define('MPESA_SHORTCODE', '174379');
define('MPESA_PASSKEY', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919');

// Callback URL for STK push result (set to your public endpoint that accepts mpesa callback)
// Callback URL for STK push result
define('MPESA_CALLBACK_URL', 'http://72.61.147.86/fortunett_technologies_/api/mpesa/callback.php');

// Endpoints
if (MPESA_ENV === 'production') {
    define('MPESA_OAUTH_URL', 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    define('MPESA_STK_PUSH_URL', 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
} else {
    define('MPESA_OAUTH_URL', 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
    define('MPESA_STK_PUSH_URL', 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
}

// Optional: override timeout (seconds)
define('MPESA_HTTP_TIMEOUT', 30);

?>