<?php
session_start(); error_reporting(0);
chdir(dirname(__DIR__));
require_once './include/reseller_acl.php';
require_once './include/user_transfer.php';
$session=is_string($_GET['session']??null)?$_GET['session']:'';
if(!isset($_SESSION['mikhmon'])||!canAccessRouterSession($session)||!hasResellerPermission('export_users')||!hasResellerPermission('generate_voucher')) { http_response_code(403);exit('Access denied'); }
if(empty($_SESSION['transfer_csrf']))$_SESSION['transfer_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['transfer_csrf'];
if(isset($_SESSION['mikhmon_last_activity'])&&time()-(int)$_SESSION['mikhmon_last_activity']>300){http_response_code(401);exit('Session expired. Sign in again.');}
function transferReply($value,$status=200){http_response_code($status);header('Content-Type: application/json');header('Cache-Control: no-store');session_write_close();echo json_encode($value);exit;}
if($_SERVER['REQUEST_METHOD']==='POST') {
 try {
    if(!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf']))throw new Exception('Reload this page.');
    $_SESSION['mikhmon_last_activity']=time();
    require './include/config.php';require_once './lib/routeros_api.class.php';
    if(!isset($data[$session]))throw new Exception('Router not found.');
    $api=new RouterosAPI();$api->timeout=5;$api->attempts=1;
    if(!$api->connect(explode('!',$data[$session][1],2)[1],explode('@|@',$data[$session][2],2)[1],decrypt(explode('#|#',$data[$session][3],2)[1])))throw new Exception('Router connection failed.');
    $call=function($cmd,$args=[])use($api){$r=$api->comm($cmd,$args);if(!$api->connected||(is_array($r)&&(isset($r['!trap'])||isset($r['!fatal']))))throw new Exception('Router rejected or interrupted the request. Check users before retrying.');return $r;};
    $action=$_POST['action']??'';
    if($action==='lists'){
        $batches=[];foreach($call('/ip/hotspot/user/print',['.proplist'=>'comment']) as $row){$comment=$row['comment']??'';if(preg_match('/^(vc|up)-/',$comment))$batches[$comment]=true;}
        transferReply(['profiles'=>$call('/ip/hotspot/user/profile/print',['.proplist'=>'name']),'servers'=>$call('/ip/hotspot/print',['.proplist'=>'name']),'batches'=>array_keys($batches)]);
    }
    if($action==='export') {
        $target=(int)($_POST['target']??0);if(!in_array($target,[6,7],true))throw new Exception('Select destination OS.');
        $mode=$_POST['filter']??'all';$args=[];
        if($mode==='profile')$args['?profile']=(string)($_POST['value']??'');
        elseif($mode==='batch')$args['?comment']=(string)($_POST['value']??'');
        elseif($mode!=='all')throw new Exception('Invalid filter.');
        if($mode!=='all'&&reset($args)==='')throw new Exception('Enter a profile or exact batch comment.');
        $rows=$call('/ip/hotspot/user/print',$args);$users=[];
        foreach($rows as $row) {if(!is_array($row)||!isset($row['name'])||$row['name']==='default-trial'||($row['dynamic']??'false')==='true')continue;
            transferUser($row,$row['profile']??'default','all');unset($row['.id']);$users[]=$row;
        }
        transferReply(['format'=>'ngw-hotspot-users','version'=>1,'target_os'=>$target,'exported_at'=>gmdate('c'),'users'=>$users]);
    }
    if($action==='prepare') {
        if(($_FILES['file']['error']??1)!==UPLOAD_ERR_OK||($_FILES['file']['size']??0)>10485760)throw new Exception('Choose a transfer file smaller than 10 MB.');
        $file=json_decode(file_get_contents($_FILES['file']['tmp_name']),true);
        if(!is_array($file)||($file['format']??'')!=='ngw-hotspot-users'||($file['version']??0)!==1||!in_array($file['target_os']??0,[6,7],true)||!is_array($file['users']??null)||count($file['users'])>10000)throw new Exception('Invalid transfer file.');
        $resource=$call('/system/resource/print',['.proplist'=>'version']);
        if((int)($resource[0]['version']??0)!==$file['target_os'])throw new Exception('Destination OS does not match export target. Export again with the correct OS.');
        $profile=(string)($_POST['profile']??'');$server=(string)($_POST['server']??'all');
        if(empty($call('/ip/hotspot/user/profile/print',['?name'=>$profile])))throw new Exception('Select an existing destination profile.');
        if($server!=='all'&&empty($call('/ip/hotspot/print',['?name'=>$server])))throw new Exception('Destination server not found.');
        $users=[];$seen=[];
        foreach($file['users'] as $row){if(!is_array($row))throw new Exception('Invalid user row.');$u=transferUser($row,$profile,$server);if(isset($seen[$u['name']]))throw new Exception('Duplicate username in file.');$seen[$u['name']]=true;$users[]=$u;}
        $job=bin2hex(random_bytes(16));$_SESSION['user_transfer']=[$job=>['router'=>$session,'users'=>$users,'done'=>0,'added'=>0,'skipped'=>0]];
        transferReply(['job'=>$job,'total'=>count($users),'message'=>'Validated. Existing usernames will be skipped.']);
    }
    if($action==='step') {
        $id=(string)($_POST['job']??'');if(!isset($_SESSION['user_transfer'][$id])||$_SESSION['user_transfer'][$id]['router']!==$session)throw new Exception('Import job not found.');
        $job=&$_SESSION['user_transfer'][$id];
        $started=microtime(true);
        for($i=0;$i<3&&$job['done']<count($job['users']);$i++){
            if($i>0&&microtime(true)-$started>=6)break;
            $u=$job['users'][$job['done']];$existing=$call('/ip/hotspot/user/print',['?name'=>$u['name'],'.proplist'=>'name']);
            if(count($existing))$job['skipped']++;else{$call('/ip/hotspot/user/add',$u);$job['added']++;}$job['done']++;
        }
        transferReply(['done'=>$job['done'],'total'=>count($job['users']),'added'=>$job['added'],'skipped'=>$job['skipped']]);
    }
    throw new Exception('Unknown action.');
 }catch(Exception $e){transferReply(['error'=>$e->getMessage()],400);}
}
header('Cache-Control: no-store');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hotspot User Transfer</title><style>body{font:15px system-ui;max-width:850px;margin:25px auto;padding:15px;background:#f5f7fa}section{background:white;padding:20px;margin:16px 0;border:1px solid #ddd;border-radius:8px}input,select,button{padding:8px;margin:5px;max-width:95%}button{cursor:pointer}#status{white-space:pre-wrap}</style></head><body>
<a href="../?session=<?=htmlspecialchars(rawurlencode($session),ENT_QUOTES)?>">← Router</a><h2>Hotspot User Transfer</h2>
<p>Comments and expiry dates are copied exactly. Source users remain on their router. Native uptime/data counters cannot be restored; remaining allowances are applied. Users must log in again.</p>
<section><h3>Export</h3><form id="export"><label>Destination OS <select name="target"><option value="6">RouterOS 6</option><option value="7">RouterOS 7</option></select></label><br><select name="filter"><option value="all">All Hotspot users</option><option value="batch">Batch — exact comment</option><option value="profile">Specific profile</option></select><input name="value" placeholder="Batch comment or profile name" list="transfer-values"><button>Download file</button></form><p>The file contains passwords. Store it securely. Usage is a snapshot taken at export time.</p></section>
<section><h3>Import</h3><p>Select a destination profile whose validity/expiry rules match the source. OS selection checks compatibility; it does not convert or install profile scripts or schedulers.</p><form id="import"><input type="file" name="file" accept=".json" required><br><label>Destination profile <select name="profile" id="profile" required></select></label><label>Hotspot server <select name="server" id="server"><option>all</option></select></label><button>Validate file</button></form><button id="start" hidden>Start import</button><button id="resume" hidden>Resume import</button></section><datalist id="transfer-values"></datalist><p id="status" role="status"></p>
<script>window.transferConfig=<?=json_encode(['csrf'=>$csrf],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="../js/user-transfer.js?v=1"></script></body></html>
