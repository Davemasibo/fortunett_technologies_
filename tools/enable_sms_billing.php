<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db_master.php';
require_once __DIR__ . '/../includes/sms_billing.php';
ensureSmsInvoiceSchema($pdo);
echo "SMS billing schema ready. Configure each package's SMS rate under Super Admin > Plans. Existing plans default to zero. No historical usage was imported.\n";
