<?php
if(PHP_SAPI!=='cli')exit;$html=file_get_contents('/root/ecoland-native-login.html');preg_match('/<title>(.*?)<\/title>/s',$html,$m);echo json_encode(['native_title'=>$m[1]??'', 'native_bytes'=>strlen($html),'native_brand'=>str_contains($html,'ecolandattic')]);
