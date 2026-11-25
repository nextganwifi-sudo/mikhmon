<?php
require_once dirname(__DIR__) . '/include/security.php';
ngwConfigureSecureSession();
ngwSendNoCacheHeaders();
session_start(); error_reporting(0);
chdir(dirname(__DIR__));
require_once './include/reseller_acl.php';
require_once './include/user_transfer.php';
require_once './include/user_transfer_ui.php';
include './include/theme.php';
$transferTheme = $_SESSION['theme'] ?? $theme;
if (!in_array($transferTheme, array('dark','light','blue','green','pink'), true)) $transferTheme='dark';

$session=is_string($_GET['session']??null)?$_GET['session']:'';
$canExportUsers = hasResellerPermission('export_users');
$canExportRsc = hasResellerPermission('export_rsc_users');
$canImportUsers = hasResellerPermission('import_transfer_users');
$canAutoActivateUsers = hasResellerPermission('auto_activate_transfer_users');
$canTransferAccess = hasResellerPermission('transfer_users_access') || $canExportUsers || $canExportRsc || $canImportUsers || hasResellerPermission('same_router_profile_transfer');
if(!isset($_SESSION['mikhmon'])||!canAccessRouterSession($session)||!$canTransferAccess) { http_response_code(403);exit(transferText('Access denied')); }
if(empty($_SESSION['transfer_csrf']))$_SESSION['transfer_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['transfer_csrf'];
if((function_exists('ngwIsCurrentSessionRevoked') && ngwIsCurrentSessionRevoked()) || (isset($_SESSION['mikhmon_last_activity'])&&time()-(int)$_SESSION['mikhmon_last_activity']>300)){
    $_SESSION = array();
    @session_unset();
    @session_destroy();
    ngwClearSessionCookie();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(array('error' => transferText('Session expired. Sign in again.')));
        exit;
    }
    http_response_code(401);
    exit(transferText('Session expired. Sign in again.'));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['mikhmon_last_activity'] = time();
    if (function_exists('ngwRegisterOrTouchActiveSession')) { ngwRegisterOrTouchActiveSession(); }
}
function transferReply($value,$status=200){http_response_code($status);header('Content-Type: application/json');header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');session_write_close();if(isset($value['error']))$value['error']=transferText($value['error']);if(isset($value['message']))$value['message']=transferText($value['message']);echo json_encode($value);exit;}

if($_SERVER['REQUEST_METHOD']==='POST') {
 try {
    if(!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf']))throw new Exception('Reload this page.');
    $_SESSION['mikhmon_last_activity']=time();
    require './include/config.php';require_once './lib/routeros_api.class.php';
    if(!isset($data[$session]))throw new Exception('Router not found.');
    $_GET['session'] = $session;
    $api=new RouterosAPI();$api->timeout=8;$api->attempts=1;
    if(!$api->connect(explode('!',$data[$session][1],2)[1],explode('@|@',$data[$session][2],2)[1],decrypt(explode('#|#',$data[$session][3],2)[1])))throw new Exception('Router connection failed.');
    $call=function($cmd,$args=[])use($api){$r=$api->comm($cmd,$args);if(!$api->connected||(is_array($r)&&(isset($r['!trap'])||isset($r['!fatal']))))throw new Exception('Router rejected or interrupted the request. Check users before retrying.');return is_array($r)?$r:[];};
    $action=$_POST['action']??'';

    if($action==='lists'){
        $batches=[];
        foreach($call('/ip/hotspot/user/print') as $row){
            $comment=trim((string)($row['comment']??''));
            if($comment==='')continue;
            if(preg_match('/^(vc|up)-/i',$comment)){
                $batches[$comment]=true;
            } elseif(preg_match('/((?:vc|up)-.*)$/i',$comment,$bm)){
                $batches[trim($bm[1])]=true;
            }
        }
        transferReply([
            'profiles'=>$call('/ip/hotspot/user/profile/print'),
            'servers'=>$call('/ip/hotspot/print'),
            'batches'=>array_keys($batches)
        ]);
    }

    if($action==='export' || $action==='export-rsc') {
        if ($action === 'export' && !$canExportUsers) {
            transferReply(['error' => 'Access denied'], 403);
        }
        if ($action === 'export-rsc' && !$canExportRsc) {
            transferReply(['error' => 'Access denied'], 403);
        }
        $target=(int)($_POST['target']??0);if(!in_array($target,[6,7],true))throw new Exception('Select destination OS.');
        $mode=$_POST['filter']??'all';$args=[];
        $filterVal = trim((string)($_POST['value']??''));
        if($mode==='profile'){
            if($filterVal==='')throw new Exception('Enter a profile or exact batch comment.');
            $args['?profile']=$filterVal;
        } elseif($mode==='batch'){
            if($filterVal==='')throw new Exception('Enter a profile or exact batch comment.');
        } elseif($mode!=='all'){
            throw new Exception('Invalid filter.');
        }

        // Gather active sessions, cookies, ip-bindings, and profiles so active users transfer seamlessly without re-login
        $activeMacs = [];
        foreach($call('/ip/hotspot/active/print') as $act){
            $au = trim((string)($act['user'] ?? $act['name'] ?? ''));
            $am = trim((string)($act['mac-address'] ?? ''));
            if($au !== '' && $am !== '') $activeMacs[$au] = $am;
        }

        $cookieMacs = [];
        $cookieExpires = [];
        $rawCookies = @$api->comm('/ip/hotspot/cookie/print');
        if(is_array($rawCookies)){
            foreach($rawCookies as $ck){
                $cu = trim((string)($ck['user'] ?? ''));
                $cm = trim((string)($ck['mac-address'] ?? ''));
                if($cu !== '' && $cm !== '') $cookieMacs[$cu] = $cm;
                if($cu !== '' && !empty($ck['expires-in'])) $cookieExpires[$cu] = (string)$ck['expires-in'];
            }
        }

        $profileValidities = [];
        foreach($call('/ip/hotspot/user/profile/print') as $pr){
            $pn = trim((string)($pr['name'] ?? ''));
            if($pn !== '' && !empty($pr['on-login'])){
                $v = transferExtractProfileValidity($pr['on-login']);
                if($v !== '') $profileValidities[$pn] = $v;
            }
        }

        $context = [
            'active_macs' => $activeMacs,
            'cookie_macs' => $cookieMacs,
            'cookie_expires' => $cookieExpires,
            'profile_validities' => $profileValidities,
            'include_meta' => true
        ];

        $rows=$call('/ip/hotspot/user/print',$args);$users=[];
        foreach($rows as $row) {
            if(!is_array($row)||!isset($row['name'])||$row['name']==='default-trial'||($row['dynamic']??'false')==='true')continue;
            if($mode==='batch'){
                $rComm = trim((string)($row['comment']??''));
                if($rComm !== $filterVal && stripos($rComm, $filterVal) === false) continue;
            }
            $users[]=transferUser($row,$row['profile']??'default','all',$context);
        }
        if($action==='export-rsc'){
            if(function_exists('ngwRecordActivity')) ngwRecordActivity('hotspot_users_transferred', ['router'=>$session,'quantity'=>(string)count($users),'detail'=>'Exported .rsc script']);
            transferReply(['rsc'=>transferRsc($users,$target),'target_os'=>$target,'count'=>count($users)]);
        }
        if(function_exists('ngwRecordActivity')) ngwRecordActivity('hotspot_users_transferred', ['router'=>$session,'quantity'=>(string)count($users),'detail'=>'Exported transfer JSON']);
        transferReply(['format'=>'ngw-hotspot-users','version'=>1,'target_os'=>$target,'exported_at'=>gmdate('c'),'users'=>$users]);
    }

    if($action==='prepare') {
        if (!$canImportUsers) {
            transferReply(['error' => 'Access denied'], 403);
        }
        if(($_FILES['file']['error']??1)!==UPLOAD_ERR_OK||($_FILES['file']['size']??0)>10485760)throw new Exception('Choose a transfer file smaller than 10 MB.');
        $file=json_decode(file_get_contents($_FILES['file']['tmp_name']),true);
        if(!is_array($file)||($file['format']??'')!=='ngw-hotspot-users'||!in_array($file['target_os']??0,[6,7],true)||!is_array($file['users']??null)||count($file['users'])>10000)throw new Exception('Invalid transfer file.');

        $existingProfiles = [];
        foreach($call('/ip/hotspot/user/profile/print') as $pr){
            if(!empty($pr['name'])) $existingProfiles[$pr['name']] = true;
        }
        if(empty($existingProfiles)) $existingProfiles['default'] = true;
        $fallbackProfile = isset($existingProfiles['default']) ? 'default' : (string)array_key_first($existingProfiles);

        $profile=(string)($_POST['profile']??'');
        $server=(string)($_POST['server']??'all');
        $autoActivate = !empty($_POST['auto_activate']) && $canAutoActivateUsers;

        if($profile !== '__keep__' && !isset($existingProfiles[$profile])) {
            throw new Exception('Select an existing destination profile.');
        }
        if($server!=='all'&&empty($call('/ip/hotspot/print',['?name'=>$server])))throw new Exception('Destination server not found.');

        $users=[];$seen=[];
        foreach($file['users'] as $row){
            if(!is_array($row))throw new Exception('Invalid user row.');
            $origProf = isset($row['profile']) && is_string($row['profile']) ? $row['profile'] : $fallbackProfile;
            $targetProf = ($profile === '__keep__') ? (isset($existingProfiles[$origProf]) ? $origProf : $fallbackProfile) : $profile;
            $u=transferUser($row,$targetProf,$server,['include_meta'=>true]);
            if(isset($seen[$u['name']]))continue;
            $seen[$u['name']]=true;
            $users[]=$u;
        }

        // Always clean up any legacy ngw-auto-active IP bindings so users never stay stuck in IP Binding
        $oldBindings = @$api->comm('/ip/hotspot/ip-binding/print');
        if(is_array($oldBindings)){
            foreach($oldBindings as $bd){
                $bc = trim((string)($bd['comment'] ?? ''));
                if(!empty($bd['.id']) && strpos($bc, 'ngw-auto-active:') === 0){
                    @$api->comm('/ip/hotspot/ip-binding/remove', ['.id' => $bd['.id']]);
                }
            }
        }

        $deployStats = ['dhcp_hooked' => 0, 'profiles_updated' => 0, 'scheduler_deployed' => false];
        if($autoActivate) {
            $deployStats = transferDeployAutoActiveMonitor($api);
        }

        $eligibleCount = 0;
        foreach($users as $uRow){
            if(!empty($uRow['auto_active'])) $eligibleCount++;
        }

        // Map currently connected Hotspot hosts (MAC => IP) so connected devices can be logged into Active immediately
        $hostIps = [];
        $rawHosts = @$api->comm('/ip/hotspot/host/print');
        if(is_array($rawHosts)){
            foreach($rawHosts as $h){
                $hm = strtoupper(str_replace('-', ':', trim((string)($h['mac-address'] ?? ''))));
                $hip = trim((string)($h['to-address'] ?? $h['address'] ?? ''));
                if($hm !== '' && $hip !== '') $hostIps[$hm] = $hip;
            }
        }

        // Pre-fetch existing usernames on destination router for fast lookup
        $existingUsersMap = [];
        foreach($call('/ip/hotspot/user/print') as $eu){
            if(!empty($eu['name']) && !empty($eu['.id'])){
                $existingUsersMap[(string)$eu['name']] = (string)$eu['.id'];
            }
        }

        $job=bin2hex(random_bytes(16));
        $_SESSION['user_transfer']=[$job=>[
            'router'=>$session,
            'users'=>$users,
            'auto_activate'=>$autoActivate,
            'dhcp_hooked'=>(int)($deployStats['dhcp_hooked'] ?? 0),
            'host_ips'=>$hostIps,
            'existing_map'=>$existingUsersMap,
            'done'=>0,
            'added'=>0,
            'auto_activated'=>0,
            'skipped'=>0
        ]];
        transferReply([
            'job'=>$job,
            'total'=>count($users),
            'eligible_auto_active'=>$eligibleCount,
            'dhcp_hooked'=>(int)($deployStats['dhcp_hooked'] ?? 0),
            'message'=>'Validated. Existing usernames will be skipped.'
        ]);
    }

    if($action==='step') {
        if (!$canImportUsers) {
            transferReply(['error' => 'Access denied'], 403);
        }
        $id=(string)($_POST['job']??'');if(!isset($_SESSION['user_transfer'][$id])||$_SESSION['user_transfer'][$id]['router']!==$session)throw new Exception('Import job not found.');
        $job=&$_SESSION['user_transfer'][$id];
        $started=microtime(true);
        for($i=0;$i<12&&$job['done']<count($job['users']);$i++){
            if($i>0&&microtime(true)-$started>=3.5)break;
            $u=$job['users'][$job['done']];
            $isAutoActive = !empty($u['auto_active']) && !empty($job['auto_activate']);
            $userAddPayload = $u;
            unset($userAddPayload['auto_active'], $userAddPayload['uptime_used']);

            $existingId = $job['existing_map'][$u['name']] ?? '';
            if($existingId === ''){
                $existing=$call('/ip/hotspot/user/print',['?name'=>$u['name']]);
                if(!empty($existing[0]['.id'])) $existingId = (string)$existing[0]['.id'];
            }

            if($existingId !== ''){
                // If user already exists and auto-activate is enabled, sync their MAC, comment/expiry, profile & remaining limit-uptime
                if($isAutoActive){
                    $syncParams = ['.id' => $existingId, 'profile' => $u['profile'], 'comment' => $u['comment'] ?? ''];
                    if(!empty($u['mac-address']) && $u['mac-address'] !== '00:00:00:00:00:00'){
                        $syncParams['mac-address'] = $u['mac-address'];
                    }
                    if(!empty($u['limit-uptime'])){
                        $syncParams['limit-uptime'] = $u['limit-uptime'];
                    }
                    @$api->comm('/ip/hotspot/user/set', $syncParams);
                }
                $job['skipped']++;
            } else {
                $call('/ip/hotspot/user/add',$userAddPayload);
                $job['added']++;
            }

            // Auto-login into Hotspot Active & Cookie (NO IP Binding!)
            if($isAutoActive && !empty($u['mac-address']) && $u['mac-address'] !== '00:00:00:00:00:00') {
                $mac = strtoupper(str_replace('-', ':', trim($u['mac-address'])));
                $job['auto_activated']++;
                if(!empty($job['host_ips'][$mac])) {
                    @$api->comm('/ip/hotspot/active/login', [
                        'user' => $u['name'],
                        'password' => $u['password'] ?? '',
                        'mac-address' => $mac,
                        'ip' => $job['host_ips'][$mac]
                    ]);
                }
            }

            $job['done']++;
        }
        if ($job['done'] >= count($job['users']) && function_exists('ngwRecordActivity')) {
            ngwRecordActivity('hotspot_users_transferred', [
                'router' => $session,
                'quantity' => (string)$job['added'],
                'detail' => 'Imported to router (Skipped: ' . (int)$job['skipped'] . ', Auto-active: ' . (int)$job['auto_activated'] . ')',
            ]);
        }
        transferReply([
            'done'=>$job['done'],
            'total'=>count($job['users']),
            'added'=>$job['added'],
            'auto_activated'=>$job['auto_activated'],
            'dhcp_hooked'=>(int)($job['dhcp_hooked'] ?? 0),
            'skipped'=>$job['skipped']
        ]);
    }
    throw new Exception('Unknown action.');
 }catch(Exception $e){transferReply(['error'=>$e->getMessage()],400);}
}
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="<?= $transferLanguage === 'bn' ? 'bn' : 'en' ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= transferHtml('User Transfer') ?></title>
<link rel="stylesheet" href="../css/mikhmon-ui.<?= $transferTheme ?>.min.css">
<link rel="stylesheet" href="../css/font-awesome/css/font-awesome.min.css">
<style>
.ngw-transfer{max-width:880px;margin:0 auto;padding:10px;box-sizing:border-box}
.ngw-transfer .transfer-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.ngw-transfer .card{box-sizing:border-box;margin-bottom:10px;min-width:0;border-radius:4px;overflow:hidden}
.ngw-transfer .card-header{padding:8px 10px}.ngw-transfer .card-header h3{margin:0;font-size:15px}
.ngw-transfer .card-body{padding:10px}.ngw-transfer .btn{padding:7px 10px;font-size:13px}
.ngw-transfer .form-control{font-size:13px;padding:6px 8px}
.ngw-transfer input[type=file]{overflow:hidden;font-size:12px}
.ngw-transfer .transfer-grid .card{border-top:0}.ngw-transfer .page-bar{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.ngw-transfer .field{margin-bottom:10px}.ngw-transfer label{display:block;margin-bottom:4px;font-weight:600;font-size:13px}
.ngw-transfer .form-control{width:100%;box-sizing:border-box;min-height:32px}
.ngw-transfer .transfer-help{font-size:12px;line-height:1.5;opacity:.85;margin-top:6px}
.ngw-transfer .transfer-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.ngw-transfer .transfer-status{margin-top:12px;padding:8px 10px;border:1px solid currentColor;border-radius:4px;white-space:pre-wrap;overflow-wrap:anywhere}
.ngw-transfer [hidden]{display:none!important}
.ngw-transfer progress{width:100%;margin-top:10px;accent-color:currentColor}
@media(max-width:720px){.ngw-transfer .transfer-grid{grid-template-columns:1fr}.ngw-transfer{padding:10px}}
</style></head><body><main class="ngw-transfer">
<div class="card"><div class="card-header page-bar">
  <h3><i class="fa fa-exchange"></i> <?= transferHtml('User Transfer') ?></h3>
  <div style="display:flex;gap:6px;flex-wrap:wrap;">
    <a class="btn bg-primary" href="./profiletransfer.php?session=<?=htmlspecialchars(rawurlencode($session),ENT_QUOTES)?>"><i class="fa fa-random"></i> <?= transferHtml('Same-Router Profile Transfer') ?></a>
    <a class="btn bg-secondary" href="../?session=<?=htmlspecialchars(rawurlencode($session),ENT_QUOTES)?>"><i class="fa fa-arrow-left"></i> <?= transferHtml('Back to router') ?></a>
  </div>
</div></div>
<div class="transfer-grid">
<?php if ($canExportUsers || $canExportRsc) { ?>
<section class="card box-bordered"><div class="card-header bg-primary"><h3><i class="fa fa-download"></i> <?= transferHtml('Export users') ?></h3></div><div class="card-body">
<form id="export"><div class="field"><label for="target"><?= transferHtml('Destination OS') ?></label><select class="form-control" id="target" name="target"><option value="6">RouterOS 6 &amp; 7 (OS 6 Mode)</option><option value="7">RouterOS 7 &amp; 6 (OS 7 Mode)</option></select></div>
<div class="field"><label for="filter"><?= transferHtml('Filter users') ?></label><select class="form-control" id="filter" name="filter"><option value="all"><?= transferHtml('All Hotspot users') ?></option><option value="batch"><?= ($transferLanguage === 'bn' ? 'ব্যাচ' : 'Batch') ?></option><option value="profile"><?= transferHtml('Specific profile') ?></option></select></div>
<div class="field" id="filter-value-field" hidden><label for="filter-value"><?= transferHtml('Batch comment or profile name') ?></label><input class="form-control" id="filter-value" name="value" list="transfer-values" autocomplete="off"></div>
<div style="display:flex;gap:6px;flex-wrap:wrap;">
  <?php if ($canExportUsers) { ?>
  <button class="btn bg-primary" type="submit"><i class="fa fa-download"></i> <?= transferHtml('Download file') ?></button>
  <?php } ?>
  <?php if ($canExportRsc) { ?>
  <button class="btn bg-success" type="submit" value="rsc"><i class="fa fa-terminal"></i> <?= $transferLanguage === 'bn' ? 'RSC ডাউনলোড' : 'Download RSC' ?></button>
  <?php } ?>
</div>
<div class="transfer-help">
  <?= $transferLanguage === 'bn'
    ? '• ফাইল ডাউনলোড (.json): ইউজারনেম, পাসওয়ার্ড, প্রোফাইল, অবিকল কমেন্ট, ম্যাক অ্যাড্রেস ও অবশিষ্ট মেয়াদ/আপটাইম সংরক্ষিত থাকে (কোনো IP বাইন্ডিং বা কমেন্ট পরিবর্তন ছাড়াই ০-সেকেন্ডে সরাসরি Active হবে)।' . "\n<br>" . '• RSC ডাউনলোড (.rsc): শুধুমাত্র ইউজারনেম, পাসওয়ার্ড, প্রোফাইল এবং কমেন্টসহ স্ক্রিপ্ট ডাউনলোড হবে।'
    : '• Download file (.json): Preserves username, password, profile, verbatim comment, MAC address & remaining uptime (0-second auto-login to Active via DHCP without IP Binding or comment edits).' . "\n<br>" . '• Download RSC (.rsc): Exports only username, password, profile, and comment.'; ?>
</div>
</form>
</div></section>
<?php } ?>
<?php if ($canImportUsers) { ?>
<section class="card box-bordered"><div class="card-header bg-success"><h3><i class="fa fa-upload"></i> <?= transferHtml('Import users') ?></h3></div><div class="card-body">

<form id="import"><div class="field"><label for="transfer-file"><?= transferHtml('Transfer file') ?></label><input class="form-control" type="file" id="transfer-file" name="file" accept=".json" required></div>
<div class="field"><label for="profile"><?= transferHtml('Destination profile') ?></label><select class="form-control" name="profile" id="profile" required><option value="__keep__"><?= transferHtml('Keep original profile (if exists on router)') ?></option></select></div>
<?php if ($canAutoActivateUsers) { ?>
<div class="field">
  <label style="display:flex;align-items:flex-start;gap:6px;cursor:pointer;font-weight:600;font-size:12px;">
    <input type="checkbox" name="auto_activate" value="1" checked style="margin-top:2px;">
    <span><?= transferHtml('0-Second Auto-Login for MAC + Calendar Date users (DHCP Lease Script + Hotspot Cookie, no IP Binding, original comment intact)') ?></span>
  </label>
</div>
<?php } ?>

<button class="btn bg-primary" type="submit"><i class="fa fa-check"></i> <?= transferHtml('Validate file') ?></button></form>
<div class="transfer-actions"><button class="btn bg-success" id="start" hidden><i class="fa fa-play"></i> <?= transferHtml('Start import') ?></button><button class="btn bg-warning" id="resume" hidden><i class="fa fa-refresh"></i> <?= transferHtml('Resume import') ?></button></div>
</div></section>
<?php } ?>
</div><datalist id="transfer-values"></datalist>
<div class="transfer-status" role="status" aria-live="polite"><span id="status"><?= transferHtml('Loading router data…') ?></span><progress id="transfer-progress" hidden></progress></div>
<script>window.transferConfig=<?=json_encode(['csrf'=>$csrf,'text'=>$transferStrings],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="../js/user-transfer.js?v=4"></script>
</main></body></html>
