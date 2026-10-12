<?php
require_once __DIR__.'/../includes/sms_billing.php';
function smsBillCheck(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
smsBillCheck(smsMessageUnits(str_repeat('a',160))===1 && smsMessageUnits(str_repeat('a',161))===2,'GSM single and multipart boundaries');
smsBillCheck(smsMessageUnits(str_repeat('^',80))===1 && smsMessageUnits(str_repeat('^',81))===2,'GSM extension characters use two septets');
smsBillCheck(smsMessageUnits(str_repeat('漢',70))===1 && smsMessageUnits(str_repeat('漢',71))===2,'Unicode SMS boundaries');
smsBillCheck(smsMessageUnits(str_repeat('😀',35))===1 && smsMessageUnits(str_repeat('😀',36))===2,'Emoji use UTF-16 surrogate pairs');
