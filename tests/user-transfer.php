<?php
require __DIR__.'/../mikhmonv3-upstream/include/user_transfer.php';
$r=['name'=>'abc','password'=>'p&\"ass','comment'=>"nov/04/2026 12:00:00 note,#&'\nবাংলা",'limit-uptime'=>'10h','uptime'=>'3h','limit-bytes-total'=>'1000','bytes-in'=>'100','bytes-out'=>'200'];
$j=json_decode(json_encode(['users'=>[$r]],JSON_UNESCAPED_UNICODE),true);
$u=transferUser($j['users'][0],'destination-profile','all');
if($u['comment']!==$r['comment']||$u['password']!==$r['password']||$u['profile']!=='destination-profile'||$u['limit-uptime']!=='25200s'||$u['limit-bytes-total']!=='700')throw new Exception('Transfer changed fields');
$r['uptime']='10h';$u=transferUser($r,'p','all');if($u['disabled']!=='yes'||$u['limit-uptime']==='0s')throw new Exception('Exhausted user became unlimited');
foreach(['1w2d03:04:05'=>788645,'1d2h3m4s'=>93784,'00:00:00'=>0] as $v=>$want)if(transferSeconds($v)!==$want)throw new Exception('Duration '.$v);
unset($r['password']);try{transferUser($r,'p','all');throw new LogicException('Missing password accepted');}catch(InvalidArgumentException $e){}
echo "PASS: exact Unicode/special-character comments and passwords, destination profile, remaining limits, exhausted users, OS-neutral durations and missing-password rejection\n";
