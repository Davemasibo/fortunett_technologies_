<?php
require_once __DIR__.'/../includes/sms_config.php';
foreach ([
    ['success'=>false,'message'=>'Originator TALKSASA is not authorized to send this message'],
    ['success'=>false,'message'=>'Insufficient credits'],
    ['success'=>false,'auth_failure'=>true,'message'=>'Platform token rejected'],
    ['success'=>false,'uncertain'=>true,'message'=>'Response timed out'],
] as $failure) {
    $result=smsFallbackFailure($failure);
    if(!str_contains($result['message'],$failure['message'])
       || ($result['auth_failure']??false)!==($failure['auth_failure']??false)
       || ($result['uncertain']??false)!==($failure['uncertain']??false)
       || $result['success']!==false) throw new RuntimeException('Fallback misrepresented provider failure');
}
echo "PASS: sender rejection, credit rejection, authentication and uncertain delivery remain distinct\n";
