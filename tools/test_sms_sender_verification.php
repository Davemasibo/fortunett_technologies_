<?php
require_once __DIR__ . '/../includes/sms_verify.php';
$state=['verdict'=>'ok','sender_id'=>'TALKSASA','last_error'=>'Provider rejected it: Originator TALKSASA is not authorized to send this message','last_failed_at'=>'2026-10-09 07:00:00','last_sent_at'=>null];
function checkSenderVerdict(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
checkSenderVerdict(smsApplySenderEvidence($state)['verdict']==='sender_rejected','Successful token probe cannot hide current sender rejection');
checkSenderVerdict(smsApplySenderEvidence(array_merge($state,['verdict'=>'untested']))['verdict']==='sender_rejected','Configuration-only audit also reports rejected sender');
checkSenderVerdict(smsApplySenderEvidence(array_merge($state,['sender_id'=>'NEW_SENDER']))['verdict']==='ok','Old sender rejection does not condemn a replacement sender');
checkSenderVerdict(smsApplySenderEvidence(array_merge($state,['last_sent_at'=>'2026-10-09 07:01:00']))['verdict']==='ok','Later accepted delivery clears earlier sender rejection');
checkSenderVerdict(smsApplySenderEvidence(array_merge($state,['verdict'=>'rejected']))['verdict']==='rejected','Token rejection retains its own diagnosis');
checkSenderVerdict(smsApplySenderEvidence(array_merge($state,['last_error'=>'Insufficient credit']))['verdict']==='ok','Balance errors are not misreported as sender rejection');
