<?php
function transferSeconds($value) {
    $value=(string)$value;
    if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/D',$value,$m) && $value!=='') {
        return (int)($m[1]??0)*604800+(int)($m[2]??0)*86400+(int)($m[3]??0)*3600+(int)($m[4]??0)*60+(int)($m[5]??0);
    }
    if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(\d+):(\d{2}):(\d{2})$/D',$value,$m)) return (int)$m[1]*604800+(int)$m[2]*86400+(int)$m[3]*3600+(int)$m[4]*60+(int)$m[5];
    if (ctype_digit($value)) return (int)$value;
    throw new InvalidArgumentException('Unsupported uptime: '.$value);
}
function transferUser($row,$profile,$server) {
    foreach(['name','password','comment'] as $key) if(!isset($row[$key])||!is_string($row[$key])) throw new InvalidArgumentException('Missing '.$key.'; export requires password access.');
    if($row['name']===''||strlen($row['name'])>255) throw new InvalidArgumentException('Invalid username.');
    $out=['name'=>$row['name'],'password'=>$row['password'],'comment'=>$row['comment'],'profile'=>$profile,'server'=>$server,'disabled'=>in_array($row['disabled']??'false',['true','yes',true],true)?'yes':'no'];
    foreach(['mac-address','address','email'] as $key) if(isset($row[$key])&&is_string($row[$key]))$out[$key]=$row[$key];
    $limit=transferSeconds($row['limit-uptime']??'0s');$used=transferSeconds($row['uptime']??'0s');
    $out['limit-uptime']=$limit>0?max(1,$limit-$used).'s':'0s';
    if($limit>0&&$used>=$limit)$out['disabled']='yes';
    foreach(['limit-bytes-in'=>'bytes-in','limit-bytes-out'=>'bytes-out','limit-bytes-total'=>null] as $key=>$counter) {
        $cap=$row[$key]??'0';$usage=$counter===null?(float)($row['bytes-in']??0)+(float)($row['bytes-out']??0):(float)($row[$counter]??0);
        if(!is_numeric($cap)||$cap<0||!is_finite((float)$cap)||$usage<0)throw new InvalidArgumentException('Invalid data allowance.');
        $out[$key]=$cap>0?sprintf('%.0f',max(1,$cap-$usage)):'0';
        if($cap>0&&$usage>=$cap)$out['disabled']='yes';
    }
    return $out;
}
