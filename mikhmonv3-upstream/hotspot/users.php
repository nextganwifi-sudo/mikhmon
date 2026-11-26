<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

// hide all error
error_reporting(0);
ini_set('max_execution_time', 300);

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
  include_once('./include/ros_version.php');
  include_once('./include/voucher_history.php');
  $tzSync = ngwSyncRouterTimezone($API);

  if (empty($API->is_virtual) && !empty($API->connected) && empty($_SESSION['ngw_ros7_synced'][$session])) {
    if (detectRouterOSMajorVersion($API) === 7) {
      syncOutdatedRos7ProfilesAndComments($API, false);
    }
    $_SESSION['ngw_ros7_synced'][$session] = time();
  }

  $exp = isset($_GET['exp']) ? $_GET['exp'] : '';
  if ($exp != "") {
    $getuser6 = $API->comm("/ip/hotspot/user/print", array(
      "?limit-uptime" => "1s",
    ));
    $getuser7 = $API->comm("/ip/hotspot/user/print", array(
      "?limit-uptime" => "00:00:01",
    ));
    $getuser = array_merge(is_array($getuser6) ? $getuser6 : array(), is_array($getuser7) ? $getuser7 : array());
    $TotalReg = count($getuser);
    $counttuser = $TotalReg;
  } elseif ($comm != "") {
    $getuser = $API->comm("/ip/hotspot/user/print", array(
      "?comment" => "$comm",
    ));
    $TotalReg = is_array($getuser) ? count($getuser) : 0;
    $counttuser = $TotalReg;
  } else {
    $getuser = $API->comm("/ip/hotspot/user/print");
    $TotalReg = is_array($getuser) ? count($getuser) : 0;
    $counttuser = $TotalReg;
    if (!empty($API->connected) && is_array($getuser)) {
      ngwSyncVoucherHistorySnapshot($API, $session, $getuser, null, $tzSync['now_ts'], 10);
    }
  }

  $nowRouterTs = (isset($tzSync['now_ts']) && (int)$tzSync['now_ts'] > 946684800) ? (int)$tzSync['now_ts'] : time();
  $nowRouterLabel = date('Y-m-d H:i:s', $nowRouterTs);
  $userWarnings = array();
  $warningTotal = 0;
  $warningExpiredCount = 0;
  $warningMissingDateCount = 0;
  $allMacCount = 0;
  $randomMacCount = 0;
  for ($wi = 0; $wi < $TotalReg; $wi++) {
    $wRow = isset($getuser[$wi]) && is_array($getuser[$wi]) ? $getuser[$wi] : null;
    $wEval = ngwEvaluateHotspotUserWarning($wRow, $nowRouterTs, $langid);
    $userWarnings[$wi] = $wEval;
    if (!empty($wEval['has_warning'])) {
      $warningTotal++;
      if ($wEval['type'] === 'expired_not_removed') {
        $warningExpiredCount++;
      } elseif ($wEval['type'] === 'missing_date') {
        $warningMissingDateCount++;
      }
    }
    if ($wRow !== null) {
      $wName = isset($wRow['name']) ? (string)$wRow['name'] : '';
      $wDyn = isset($wRow['dynamic']) && (string)$wRow['dynamic'] === 'true';
      $wMac = isset($wRow['mac-address']) ? trim((string)$wRow['mac-address']) : '';
      if ($wName !== 'default-trial' && !$wDyn && $wMac !== '' && $wMac !== '00:00:00:00:00:00') {
        $allMacCount++;
        if (function_exists('isRandomizedMacAddress') && isRandomizedMacAddress($wMac)) {
          $randomMacCount++;
        }
      }
    }
  }
  $initialWarnFilter = (isset($_GET['warning']) && (string)$_GET['warning'] === '1');
}
?>
<style>
.ngw-uwarn-topbtn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 7px;
  margin-left: 2px;
  font-size: 11px;
  font-weight: 700;
  line-height: 1.5;
  border-radius: 3px;
  border: 1px solid rgba(245, 158, 11, 0.58);
  background: rgba(245, 158, 11, 0.16);
  color: #f59e0b;
  cursor: pointer;
  vertical-align: middle;
  transition: background .15s ease, color .15s ease, border-color .15s ease, opacity .15s ease;
}
.ngw-uwarn-topbtn:hover {
  background: rgba(245, 158, 11, 0.30);
  border-color: #f59e0b;
}
.ngw-uwarn-topbtn.is-zero {
  opacity: 0.55;
}
.ngw-uwarn-topbtn.is-active {
  background: #f59e0b;
  color: #111827;
  border-color: #d97706;
}
.ngw-uwarn-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 19px;
  height: 19px;
  padding: 0;
  margin-left: 4px;
  border-radius: 3px;
  border: 1px solid rgba(245, 158, 11, 0.6);
  background: rgba(245, 158, 11, 0.18);
  color: #f59e0b;
  font-size: 10px;
  line-height: 1;
  cursor: pointer;
  vertical-align: middle;
  transition: background .14s ease, color .14s ease, border-color .14s ease;
}
.ngw-uwarn-btn:hover {
  background: #f59e0b;
  color: #111827;
  border-color: #d97706;
}
.ngw-uwarn-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: 14px;
}
.ngw-uwarn-backdrop.open {
  display: flex;
}
.ngw-uwarn-modal {
  width: 100%;
  max-width: 420px;
  margin: 0;
  border-radius: 5px;
  overflow: hidden;
  box-shadow: 0 14px 36px rgba(0, 0, 0, 0.4);
}
.ngw-uwarn-modal .card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.ngw-uwarn-close {
  background: transparent;
  border: 0;
  color: inherit;
  font-size: 20px;
  line-height: 1;
  cursor: pointer;
  opacity: 0.75;
  padding: 0 4px;
}
.ngw-uwarn-close:hover { opacity: 1; }
.ngw-uwarn-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 7px;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 700;
  background: rgba(245, 158, 11, 0.18);
  color: #f59e0b;
  border: 1px solid rgba(245, 158, 11, 0.45);
}
.ngw-uwarn-reason {
  margin: 10px 0 12px;
  padding: 9px 11px;
  border-radius: 4px;
  border-left: 3px solid #f59e0b;
  background: rgba(245, 158, 11, 0.10);
  font-size: 12.5px;
  line-height: 1.45;
}
.ngw-uwarn-kv {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.ngw-uwarn-kv td {
  padding: 5px 4px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.18);
  vertical-align: top;
}
.ngw-uwarn-kv td:first-child {
  width: 115px;
  opacity: 0.75;
  font-weight: 600;
}
.ngw-uwarn-kv tr:last-child td {
  border-bottom: 0;
}
.ngw-uwarn-foot {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 6px;
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px solid rgba(128, 128, 128, 0.2);
}
.ngw-rmac-modal {
  max-width: 320px;
}
.ngw-rmac-options {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 4px 0 2px;
}
.ngw-rmac-opt {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 10px;
  border-radius: 4px;
  border: 1px solid rgba(128, 128, 128, 0.32);
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  transition: border-color .14s ease, background .14s ease;
  user-select: none;
}
.ngw-rmac-opt:hover {
  border-color: rgba(245, 158, 11, 0.7);
  background: rgba(245, 158, 11, 0.08);
}
.ngw-rmac-opt.is-selected {
  border-color: #f59e0b;
  background: rgba(245, 158, 11, 0.14);
}
.ngw-rmac-opt-left {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.ngw-rmac-opt input[type="radio"] {
  margin: 0;
  accent-color: #f59e0b;
  cursor: pointer;
}
.ngw-rmac-count {
  font-size: 11px;
  font-weight: 700;
  opacity: 0.78;
}
/* Compact deletion notice & deleting pill across all 4 UIs */
.ngw-user-notice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 6px 10px;
  margin-bottom: 8px;
  border-radius: 5px;
  border: 1px solid rgba(34, 197, 94, 0.45);
  background: rgba(34, 197, 94, 0.14);
  color: #22c55e;
  font-size: 12px;
  font-weight: 600;
  line-height: 1.35;
  transition: opacity 0.2s ease;
}
body[data-ngw-theme="light"] .ngw-user-notice,
body[data-ngw-theme="blue"] .ngw-user-notice,
body[data-ngw-theme="green"] .ngw-user-notice,
body[data-ngw-theme="pink"] .ngw-user-notice {
  background: rgba(22, 163, 74, 0.12);
  border-color: rgba(22, 163, 74, 0.38);
  color: #15803d;
}
.ngw-user-notice-main {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  min-width: 0;
  flex: 1 1 auto;
}
.ngw-user-notice-main span {
  overflow-wrap: anywhere;
}
.ngw-user-notice-close {
  background: transparent;
  border: 0;
  color: inherit;
  font-size: 15px;
  line-height: 1;
  padding: 0 3px;
  cursor: pointer;
  opacity: 0.75;
  flex-shrink: 0;
}
.ngw-user-notice-close:hover {
  opacity: 1;
}
.ngw-user-del-pill {
  position: fixed;
  top: 64px;
  right: 16px;
  z-index: 9500;
  display: none;
  align-items: center;
  gap: 7px;
  padding: 6px 12px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.92);
  color: #f8fafc;
  border: 1px solid rgba(248, 113, 113, 0.55);
  box-shadow: 0 8px 22px rgba(0, 0, 0, 0.32);
  font-size: 12px;
  font-weight: 600;
  line-height: 1.25;
  max-width: calc(100vw - 24px);
  pointer-events: none;
}
.ngw-user-del-pill.is-shown {
  display: inline-flex;
}
.ngw-user-del-pill i {
  color: #f87171;
}
@media screen and (max-width: 767px) {
  .ngw-user-notice {
    padding: 5px 8px;
    font-size: 11.5px;
    margin-bottom: 6px;
  }
  .ngw-user-del-pill {
    top: 56px;
    right: auto;
    left: 50%;
    transform: translateX(-50%);
    padding: 5px 11px;
    font-size: 11.5px;
  }
}
</style>

<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header">
    <h3><i class="fa fa-users"></i> <?= $_users ?>
      <span style="font-size: 14px">
         <?php if (hasResellerPermission('add_user')) { ?>
         &nbsp; | &nbsp; <a href="./?hotspot-user=add&session=<?= $session; ?>" title="Add User"><i class="fa fa-user-plus"></i> <?= $_add ?></a>
         <?php } ?>
         <?php if (hasResellerPermission('generate_voucher')) { ?>
        &nbsp; | &nbsp; <a href="./?hotspot-user=generate&session=<?= $session; ?>" title="Generate User"><i class="fa fa-users"></i> <?= $_generate ?></a>
         <?php } ?>
         <?php if (hasResellerPermission('export_users')) { ?>
         &nbsp; | &nbsp; <a href="<?= str_replace("=users", "=export-users", $url); ?>&export=script" title="Download User List as Mikrotik Script"><i class="fa fa-download"></i> Script</a>&nbsp; | &nbsp; <a href="<?= str_replace("=users", "=export-users", $url); ?>&export=csv" title="Download User List as CSV"><i class="fa fa-download"></i> CSV</a>
         <?php } ?>
         <?php
         $warnTopTitle = ($warningTotal > 0)
           ? (($langid === 'bn')
               ? "ওয়ার্নিং ইউজার: {$warningTotal} (মেয়াদ শেষ: {$warningExpiredCount}, ডেট নেই: {$warningMissingDateCount}) — ক্লিক করে ফিল্টার করুন"
               : "Warning users: {$warningTotal} (Expired: {$warningExpiredCount}, No Date: {$warningMissingDateCount}) — click to filter")
           : (($langid === 'bn')
               ? "কোনো ওয়ার্নিং ইউজার নেই"
               : "No warning users");
         ?>
         &nbsp; | &nbsp; <button type="button" id="ngwWarnFilterBtn" class="ngw-uwarn-topbtn<?= $warningTotal === 0 ? ' is-zero' : ''; ?><?= ($initialWarnFilter && $warningTotal > 0) ? ' is-active' : ''; ?>" title="<?= htmlspecialchars($warnTopTitle, ENT_QUOTES, 'UTF-8'); ?>" onclick="ngwToggleUserWarnFilter()"><i class="fa fa-exclamation-triangle"></i> <span><?= $warningTotal; ?></span></button>
        </span>  &nbsp;
        <small id="loader" style="display: none;" ><i><i class='fa fa-circle-o-notch fa-spin'></i> <?= $_processing ?> </i></small>
    </h3>
    
</div>
<div class="card-body">
  <?php if (!empty($ngwHotspotUsersNotice) && !empty($ngwHotspotUsersNotice['message'])) { ?>
  <div id="ngwUserDeleteNotice" class="ngw-user-notice" role="status">
    <div class="ngw-user-notice-main">
      <i class="fa fa-check-circle" aria-hidden="true"></i>
      <span><?= htmlspecialchars((string)$ngwHotspotUsersNotice['message'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <button type="button" class="ngw-user-notice-close" onclick="var n=document.getElementById('ngwUserDeleteNotice');if(n)n.style.display='none';" aria-label="Close">&times;</button>
  </div>
  <?php } ?>
  <div class="row">
   <div class="col-6 pd-t-5 pd-b-5">
  <div class="input-group">
    <div class="input-group-6 col-box-6">
      <input id="filterTable" type="text" style="padding:5.8px;" class="group-item group-item-l" placeholder="<?= $_search ?>">
    </div>
    <div class="input-group-6 col-box-6">
      <select style="padding:5px;" class="group-item group-item-r" id="comment" name="comment" onchange="if(this.value===''){location='./?hotspot=users&session=<?= rawurlencode($session); ?>';}else{location='./?hotspot=users&comment='+ encodeURIComponent(this.value) +'&session=<?= rawurlencode($session); ?>';} loader();">
      <?php
      echo "<option value=''>" . $_comment . "</option>";
      $TotalReg = count($getuser);
      for ($i = 0; $i < $TotalReg; $i++) {
        $ucomment = $getuser[$i]['comment'];
        $uprofile = $getuser[$i]['profile'];
        $acomment .= ",".$ucomment."#". $uprofile;
      }

      $ocomment=  explode(",",$acomment);
      
      $comments=array_count_values($ocomment) ;
      foreach ($comments as $tcomment=>$value) {

        if (preg_match('/^(?:vc|up)-/i', $tcomment)) {
          $cVal = explode("#", $tcomment)[0];
          $cProf = explode("#", $tcomment)[1] ?? '';
          $cSel = ($comm !== '' && $comm === $cVal) ? 'selected' : '';
          echo "<option value='" . htmlspecialchars($cVal, ENT_QUOTES) . "' " . $cSel . ">" . htmlspecialchars($cVal) . " " . htmlspecialchars($cProf) . " [" . $value . "]</option>";
        }
   
      }

      ?>
      </select>
    </div>
  </div>
  </div>
 
  <div class="col-6">
    <?php if (hasResellerPermission('delete_user')) { ?>
    <?php if ($comm != "") {
      $delBatchConfirm = ($langid === 'bn')
        ? "আপনি কি নিশ্চিত যে এই ব্যাচের ($comm) সকল ইউজার ডিলিট করতে চান?"
        : "Are you sure you want to delete all users in batch ($comm)?";
      $delBatchUrl = "./?remove-hotspot-user-by-comment=" . rawurlencode($comm) . "&session=" . rawurlencode($session);
      $delBatchStatus = ($langid === 'bn') ? 'ব্যাচ ডিলিট হচ্ছে...' : 'Deleting batch...';
    ?>
  <button type="button" class="btn bg-red" onclick='if(confirm(<?= json_encode($delBatchConfirm); ?>)){ngwRunHotspotDelete(<?= json_encode($delBatchUrl); ?>, <?= json_encode($delBatchStatus); ?>, this);}' title="Delete users in batch <?= htmlspecialchars($comm, ENT_QUOTES); ?>">  <i class="fa fa-trash"></i> <?= ($langid === 'bn') ? 'ডিলিট ইউজার' : 'Delete User'; ?></button>
    <?php } else if ($exp == "1") {
      $delExpStatus = ($langid === 'bn') ? 'মেয়াদোত্তীর্ণ ইউজার ডিলিট হচ্ছে...' : 'Deleting expired users...';
    ?>
  <button type="button" class="btn bg-red" onclick='if(confirm(<?= json_encode(($langid === 'bn') ? "আপনি কি নিশ্চিত যে সকল মেয়াদোত্তীর্ণ ইউজার ডিলিট করতে চান?" : "Are you sure to delete users?"); ?>)){ngwRunHotspotDelete("./?remove-hotspot-user-expired=1&session=<?= rawurlencode($session); ?>", <?= json_encode($delExpStatus); ?>, this);}' title="Remove user expired">  <i class="fa fa-trash"></i> <?= ($langid === 'bn') ? 'মেয়াদোত্তীর্ণ ইউজার ডিলিট' : 'Expired Users'; ?></button>
      <?php } ?>
    <?php } ?>
  <script>
    function printV(a,b){
    var comm = document.getElementById('comment').value;
    var url = "./voucher/print.php?id="+encodeURIComponent(comm)+"&"+a+"="+b+"&session=<?= $session; ?>";
    if (comm === "" ){
      <?php if ($langid === 'bn') { ?>
      alert('প্রিন্ট করার জন্য প্রথমে একটি কমেন্ট / ব্যাচ সিলেক্ট করুন!');
      <?php } elseif ($currency == in_array($currency, $cekindo['indo'])) { ?>
      alert('Silakan pilih salah satu Comment terlebih dulu!');
      <?php
    } else { ?>
      alert('Please choose one of the Comments first!');
      <?php
    } ?>
    }else{
      var win = window.open(url, '_blank');
      win.focus();
    }}
  </script>
  <?php
  $showBatchPrintBtn = ($comm !== '');
  if (hasResellerPermission('print_voucher') && $showBatchPrintBtn) { ?>
  <button class="btn bg-primary" title="Print" onclick="printV('qr','no');"><i class="fa fa-print"></i> <?= isset($_print) ? $_print : 'Print' ?></button>
  <?php } ?>
  </div>
</div>

<div class="overflow mr-t-10 box-bordered" style="max-height: 75vh">
<table id="dataTable" class="table table-bordered table-hover text-nowrap">
  <thead>
  <tr>
    <th style="min-width:50px;" class="align-middle text-center" id="cuser"><?= $counttuser; ?></th>
    <th style="min-width:50px;" class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= isset($_server) ? $_server : 'Server'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_name ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_password ?></th>
    <th class="text-center"><?= ($langid === 'bn') ? 'ওয়ার্নিং' : 'Warning'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_profile ?></th>
	  <th class="pointer" title="Click to sort">
      <i class="fa fa-sort"></i> <?= ($langid === 'bn') ? 'ম্যাক অ্যাড্রেস' : 'Mac Address'; ?>
      <?php
      $canResetMac = (hasResellerPermission('reset_mac_address') || hasResellerPermission('edit_user') || hasResellerPermission('enable_disable_user') || hasResellerPermission('reset_voucher'));
      $resetMacUrl = "./?reset-hotspot-macs=1&comment=" . rawurlencode($comm) . "&exp=" . rawurlencode($exp) . "&session=" . rawurlencode($session);
      if ($canResetMac) {
      ?>
      <button type="button" class="btn bg-warning" style="padding:1px 6px; font-size:11px; margin-left:5px; line-height:1.3; font-weight:600; border-radius:3px; cursor:pointer;" onclick="event.stopPropagation(); ngwOpenResetMacModal();" title="<?= ($langid === 'bn') ? 'ম্যাক অ্যাড্রেস রিসেট করুন' : 'Reset MAC Addresses'; ?>">
        <i class="fa fa-refresh"></i> <?= ($langid === 'bn') ? 'রিসেট ম্যাক' : 'Reset MAC'; ?>
      </button>
      <?php } ?>
    </th>
    <th class="text-right align-middle pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_uptime_user ?></th>
    <th class="text-right align-middle pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= isset($_bytes_in) ? $_bytes_in : 'Bytes In'; ?></th>
    <th class="text-right align-middle pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= isset($_bytes_out) ? $_bytes_out : 'Bytes Out'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_comment ?></th>
    </tr>
  </thead>
  <tbody id="tbody">
<?php
for ($i = 0; $i < $TotalReg; $i++) {
  $userdetails = $getuser[$i];
  $uid = $userdetails['.id'];
  $userver = $userdetails['server'];
  $uname = $userdetails['name'];
  $upass = isset($userdetails['password']) ? $userdetails['password'] : (isset($userdetails['password.']) ? $userdetails['password.'] : '');
  $uprofile = $userdetails['profile'];
  $umacadd = $userdetails['mac-address'];
  $uuptime = formatDTM($userdetails['uptime']);
  $ubytesi = formatBytes($userdetails['bytes-in'], 2);
  $ubyteso = formatBytes($userdetails['bytes-out'], 2);

  $ucomment = $userdetails['comment'];
  $udisabled = $userdetails['disabled'];
  $utimelimit = $userdetails['limit-uptime'];
  if ($utimelimit == '1s' || $utimelimit == '00:00:01') {
    $utimelimit = ' expired';
  } else {
    $utimelimit = ' ' . $utimelimit;
  }
  $udatalimit = $userdetails['limit-bytes-total'];
  if ($udatalimit == '') {
    $udatalimit = '';
  } else {
    $udatalimit = ' ' . formatBytes($udatalimit, 2);
  }

  $uWarn = isset($userWarnings[$i]) ? $userWarnings[$i] : array('has_warning' => false);
  $rowWarnAttr = !empty($uWarn['has_warning'])
    ? (' data-ngw-warn="1" data-ngw-warn-type="' . htmlspecialchars($uWarn['type'], ENT_QUOTES, 'UTF-8') . '"')
    : ' data-ngw-warn="0"';

  echo "<tr" . $rowWarnAttr . ">";
  ?>
  <td style='text-align:center;'>
  <?php if (hasResellerPermission('delete_user')) { 
    $delUserMsg = ($langid === 'bn') ? 'আপনি কি নিশ্চিত যে এই ইউজার (' . $uname . ') ডিলিট করতে চান?' : 'Are you sure to delete username (' . $uname . ')?';
    $delSingleUrl = './?remove-hotspot-user=' . urlencode($uid) . '&session=' . urlencode($session);
    $delSingleStatus = ($langid === 'bn') ? 'ডিলিট হচ্ছে...' : 'Deleting...';
  ?>
  <i class='fa fa-minus-square text-danger pointer' onclick='if(confirm(<?= json_encode($delUserMsg); ?>)){ngwRunHotspotDelete(<?= json_encode($delSingleUrl); ?>, <?= json_encode($delSingleStatus); ?>, this);}' title='Remove <?= htmlspecialchars($uname, ENT_QUOTES); ?>'></i>&nbsp;&nbsp;&nbsp;
  <?php } ?>
  <?php
  if (hasResellerPermission('enable_disable_user')) {
    if ($udisabled == "true") {
      $uriprocess = "'./?enable-hotspot-user=" . $uid . "&session=" . $session."'";
      echo '<span class="text-warning pointer" title="Enable User ' . $uname . '"  onclick="loadpage('.$uriprocess.')"><i class="fa fa-lock "></i></span>';
    } else {
      $uriprocess = "'./?disable-hotspot-user=" . $uid . "&session=" . $session."'";
      echo '<span class="pointer" title="Disable User ' . $uname . '"  onclick="loadpage('.$uriprocess.')"><i class="fa fa-unlock "></i></span>';
    }
  }
  echo '</td>';
  echo "<td>" . $userver . "</td>";
  
  // Resolve user mode & handle SSL/ROS7 empty password
  if (empty($upass) && (substr($ucomment, 0, 3) == "vc-" || $ucomment == "vc")) {
    $upass = $uname;
  }
  if ($uname == $upass || substr($ucomment, 0, 3) == "vc-") {
    $usermode = "vc";
    if (empty($upass)) {
      $upass = $uname;
    }
  } else {
    $usermode = "up";
  }

  $warnBtnHtml = '';
  if (!empty($uWarn['has_warning'])) {
    $wDelUrl = hasResellerPermission('delete_user')
      ? ('./?remove-hotspot-user=' . urlencode($uid) . '&session=' . urlencode($session))
      : '';
    $wDelMsg = ($langid === 'bn')
      ? ('আপনি কি নিশ্চিত যে এই ইউজার (' . $uname . ') ডিলিট করতে চান?')
      : ('Are you sure to delete username (' . $uname . ')?');
    $wOpenUrl = hasResellerPermission('edit_user')
      ? ('./?hotspot-user=' . rawurlencode($uid) . '&session=' . rawurlencode($session))
      : '';
    $wCommentCleanDisp = normalizeActivatedVoucherComment($ucomment);
    $warnBtnHtml = ' <button type="button" class="ngw-uwarn-btn"'
      . ' title="' . htmlspecialchars($uWarn['reason'], ENT_QUOTES, 'UTF-8') . '"'
      . ' data-w-user="' . htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') . '"'
      . ' data-w-label="' . htmlspecialchars($uWarn['short_label'], ENT_QUOTES, 'UTF-8') . '"'
      . ' data-w-reason="' . htmlspecialchars($uWarn['reason'], ENT_QUOTES, 'UTF-8') . '"'
      . ' data-w-uptime="' . htmlspecialchars($uuptime, ENT_QUOTES, 'UTF-8') . '"'
      . ' data-w-comment="' . htmlspecialchars($wCommentCleanDisp !== '' ? $wCommentCleanDisp : '-', ENT_QUOTES, 'UTF-8') . '"'
      . ' data-w-expiry="' . htmlspecialchars($uWarn['expiry_display'] !== '' ? $uWarn['expiry_display'] : '-', ENT_QUOTES, 'UTF-8') . '"'
      . ' data-w-open-url="' . htmlspecialchars($wOpenUrl, ENT_QUOTES, 'UTF-8') . '"'
      . ' data-w-del-url="' . htmlspecialchars($wDelUrl, ENT_QUOTES, 'UTF-8') . '"'
      . ' data-w-del-msg="' . htmlspecialchars($wDelMsg, ENT_QUOTES, 'UTF-8') . '"'
      . ' onclick="event.stopPropagation(); ngwOpenUserWarnModal(this);"'
      . ' aria-label="' . htmlspecialchars($uWarn['short_label'], ENT_QUOTES, 'UTF-8') . '">'
      . '<i class="fa fa-exclamation-triangle"></i></button>';
  }

  if (hasResellerPermission('edit_user')) {
    echo "<td><a title='Open User " . $uname . "' href=./?hotspot-user=" . $uid . "&session=" . $session . "><i class='fa fa-edit'></i> " . $uname . " </a></td>";
  } else {
    echo "<td>";
    if (hasResellerPermission('reset_voucher') && $uname !== 'default-trial') {
      echo "<a title='" . htmlspecialchars($_check_voucher, ENT_QUOTES) . "' href='./?hotspot=reset-voucher&voucher=" . rawurlencode($uname) . "&session=" . rawurlencode($session) . "'>" . htmlspecialchars($uname) . "</a>";
    } else {
      echo htmlspecialchars($uname);
    }
    echo "</td>";
  }
  // Password column
  if ($usermode == "vc") {
    echo "<td style='font-family:monospace; color:#888;' title='Voucher Code (Password equals Username)'>= " . htmlspecialchars($uname) . "</td>";
  } else {
    echo "<td style='font-family:monospace; font-weight:bold;'>" . htmlspecialchars($upass !== '' ? $upass : '-') . "</td>";
  }

  // Warning column (Check Voucher + Yellow Warning button)
  echo '<td class="text-center" style="white-space:nowrap;">';
  if (hasResellerPermission('reset_voucher') && $uname !== 'default-trial') {
    echo "<a title='" . htmlspecialchars($_check_voucher, ENT_QUOTES) . "' href='./?hotspot=reset-voucher&voucher=" . rawurlencode($uname) . "&session=" . rawurlencode($session) . "' style='opacity:0.85;'><i class='fa fa-search'></i></a>";
  }
  echo $warnBtnHtml;
  echo '</td>';
  echo "<td>" . $uprofile . "</td>";
  echo "<td style=' text-align:left'>" . htmlspecialchars($umacadd) . renderRandomizedMacBadge($umacadd) . "</td>";
  echo "<td style=' text-align:right'>" . $uuptime . "</td>";
  echo "<td style=' text-align:right'>" . $ubytesi . "</td>";
  echo "<td style=' text-align:right'>" . $ubyteso . "</td>";
  $ucommentClean = normalizeActivatedVoucherComment($ucomment);
  echo "<td>";
  if ($uname == "default-trial") {
  } else if (substr($ucommentClean,0,3) == "vc-" || substr($ucommentClean,0,3) == "up-") {
    echo "<a href=./?hotspot=users&comment=" . rawurlencode($ucommentClean) . "&session=" . $session . " title='Filter by " . htmlspecialchars($ucommentClean, ENT_QUOTES) . "'><i class='fa fa-search'></i> ". htmlspecialchars($ucommentClean)." ". $udatalimit ." ".$utimelimit . "</a>";
  } else if ($utimelimit == ' expired') {
    echo "<a href=./?hotspot=users&exp=1&session=" . rawurlencode($session) . " title='Filter by expired'><i class='fa fa-search'></i> " . htmlspecialchars($ucommentClean)." ". $udatalimit ." ".$utimelimit . "</a>";
  }else{
    echo htmlspecialchars($ucommentClean).' ';
  }
  echo "</td>";
  echo "</tr>";
}
?>
  </tbody>
</table>
</div>
</div>
</div>
</div>
</div>

<div id="ngwUserDeletingPill" class="ngw-user-del-pill" role="status" aria-live="polite">
  <i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i>
  <span id="ngwUserDeletingText"><?= ($langid === 'bn') ? 'ডিলিট হচ্ছে...' : 'Deleting...'; ?></span>
</div>

<!-- Compact Warning Detail Modal -->
<div class="ngw-uwarn-backdrop" id="ngwUserWarnModal">
  <div class="card ngw-uwarn-modal">
    <div class="card-header">
      <h3 style="margin:0; font-size:14px; display:flex; align-items:center; gap:7px;">
        <i class="fa fa-exclamation-triangle" style="color:#f59e0b;"></i>
        <span id="ngwUwUser">-</span>
        <span class="ngw-uwarn-pill" id="ngwUwBadge">-</span>
      </h3>
      <button type="button" class="ngw-uwarn-close" onclick="ngwCloseUserWarnModal()" aria-label="Close">&times;</button>
    </div>
    <div class="card-body">
      <div class="ngw-uwarn-reason" id="ngwUwReason"></div>
      <table class="ngw-uwarn-kv">
        <tr>
          <td><?= ($langid === 'bn') ? 'আপটাইম' : 'Uptime'; ?></td>
          <td id="ngwUwUptime" style="font-family:monospace;">-</td>
        </tr>
        <tr>
          <td><?= ($langid === 'bn') ? 'কমেন্ট' : 'Comment'; ?></td>
          <td id="ngwUwComment" style="font-family:monospace; word-break:break-word;">-</td>
        </tr>
        <tr>
          <td><?= ($langid === 'bn') ? 'মেয়াদ (ক্যালেন্ডার)' : 'Calendar Expiry'; ?></td>
          <td id="ngwUwExpiry" style="font-family:monospace;">-</td>
        </tr>
        <tr>
          <td><?= ($langid === 'bn') ? 'রাউটার সময়' : 'Router Time'; ?></td>
          <td style="font-family:monospace;"><?= htmlspecialchars($nowRouterLabel, ENT_QUOTES, 'UTF-8'); ?></td>
        </tr>
      </table>
      <div class="ngw-uwarn-foot">
        <a href="#" id="ngwUwOpenBtn" class="btn bg-primary" style="display:none; padding:4px 10px; font-size:12px;"><i class="fa fa-edit"></i> <?= ($langid === 'bn') ? 'খুলুন' : 'Open'; ?></a>
        <button type="button" id="ngwUwDelBtn" class="btn bg-red" style="display:none; padding:4px 10px; font-size:12px;" onclick="ngwDeleteWarnUser()"><i class="fa fa-trash"></i> <?= ($langid === 'bn') ? 'ডিলিট' : 'Remove'; ?></button>
        <button type="button" class="btn bg-secondary" style="padding:4px 10px; font-size:12px;" onclick="ngwCloseUserWarnModal()"><?= ($langid === 'bn') ? 'বন্ধ' : 'Close'; ?></button>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($canResetMac)) { ?>
<!-- Compact Reset MAC Confirmation Popup -->
<div class="ngw-uwarn-backdrop" id="ngwResetMacModal">
  <div class="card ngw-uwarn-modal ngw-rmac-modal">
    <div class="card-header">
      <h3 style="margin:0; font-size:14px; display:flex; align-items:center; gap:7px;">
        <i class="fa fa-exclamation-triangle" style="color:#f59e0b;"></i>
        <span><?= ($langid === 'bn') ? 'আপনি কি নিশ্চিত?' : 'Are you sure?'; ?></span>
      </h3>
      <button type="button" class="ngw-uwarn-close" onclick="ngwCloseResetMacModal()" aria-label="Close">&times;</button>
    </div>
    <div class="card-body">
      <div class="ngw-rmac-options" role="radiogroup" aria-label="<?= ($langid === 'bn') ? 'ম্যাক রিসেট অপশন' : 'Reset MAC option'; ?>">
        <label class="ngw-rmac-opt is-selected" id="ngwRmacOptAll">
          <span class="ngw-rmac-opt-left">
            <input type="radio" name="ngw_reset_mac_scope" value="all" checked onchange="ngwSyncResetMacOptUI()">
            <span><?= ($langid === 'bn') ? 'সব ম্যাক' : 'All MAC'; ?></span>
          </span>
          <span class="ngw-rmac-count">(<?= (int)$allMacCount; ?>)</span>
        </label>
        <label class="ngw-rmac-opt" id="ngwRmacOptRandom">
          <span class="ngw-rmac-opt-left">
            <input type="radio" name="ngw_reset_mac_scope" value="random" onchange="ngwSyncResetMacOptUI()">
            <span><?= ($langid === 'bn') ? 'র্যান্ডম ম্যাক' : 'Random MAC'; ?></span>
          </span>
          <span class="ngw-rmac-count">(<?= (int)$randomMacCount; ?>)</span>
        </label>
      </div>
      <div class="ngw-uwarn-foot">
        <button type="button" class="btn bg-secondary" style="padding:4px 12px; font-size:12px;" onclick="ngwCloseResetMacModal()"><?= ($langid === 'bn') ? 'বাতিল' : 'Cancel'; ?></button>
        <button type="button" class="btn bg-warning" style="padding:4px 14px; font-size:12px; font-weight:600;" onclick="ngwConfirmResetMac()"><?= ($langid === 'bn') ? 'ওকে' : 'OK'; ?></button>
      </div>
    </div>
  </div>
</div>
<?php } ?>

<script>
(function() {
  var warnOnly = <?= ($initialWarnFilter && $warningTotal > 0) ? 'true' : 'false'; ?>;
  var totalUsers = <?= (int)$counttuser; ?>;
  var activeDelUrl = '';
  var activeDelMsg = '';

  function applyCombinedUserFilter() {
    var input = document.getElementById('filterTable');
    var q = input ? input.value.trim().toLowerCase() : '';
    var rows = document.querySelectorAll('#dataTable tbody tr');
    var shown = 0;
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      var warnOk = !warnOnly || (r.getAttribute('data-ngw-warn') === '1');
      var txtOk = (q === '') || (r.textContent.toLowerCase().indexOf(q) > -1);
      var vis = warnOk && txtOk;
      r.style.display = vis ? '' : 'none';
      if (vis) shown++;
    }
    var cntEl = document.getElementById('cuser');
    if (cntEl) {
      cntEl.textContent = (warnOnly || q !== '') ? (shown + '/' + totalUsers) : String(totalUsers);
    }
  }

  window.ngwToggleUserWarnFilter = function() {
    var btn = document.getElementById('ngwWarnFilterBtn');
    if (!btn) return;
    warnOnly = !warnOnly;
    btn.classList.toggle('is-active', warnOnly);
    applyCombinedUserFilter();
  };

  window.ngwOpenUserWarnModal = function(btn) {
    var modal = document.getElementById('ngwUserWarnModal');
    if (!modal || !btn) return;
    document.getElementById('ngwUwUser').textContent = btn.getAttribute('data-w-user') || '-';
    document.getElementById('ngwUwBadge').textContent = btn.getAttribute('data-w-label') || 'Warning';
    document.getElementById('ngwUwReason').textContent = btn.getAttribute('data-w-reason') || '';
    document.getElementById('ngwUwUptime').textContent = btn.getAttribute('data-w-uptime') || '-';
    document.getElementById('ngwUwComment').textContent = btn.getAttribute('data-w-comment') || '-';
    document.getElementById('ngwUwExpiry').textContent = btn.getAttribute('data-w-expiry') || '-';

    var openUrl = btn.getAttribute('data-w-open-url') || '';
    var openBtn = document.getElementById('ngwUwOpenBtn');
    if (openBtn) {
      if (openUrl) {
        openBtn.href = openUrl;
        openBtn.style.display = '';
      } else {
        openBtn.style.display = 'none';
      }
    }

    activeDelUrl = btn.getAttribute('data-w-del-url') || '';
    activeDelMsg = btn.getAttribute('data-w-del-msg') || 'Delete user?';
    var delBtn = document.getElementById('ngwUwDelBtn');
    if (delBtn) {
      delBtn.style.display = activeDelUrl ? '' : 'none';
    }

    modal.classList.add('open');
  };

  window.ngwCloseUserWarnModal = function() {
    var modal = document.getElementById('ngwUserWarnModal');
    if (modal) modal.classList.remove('open');
  };

  var ngwDeleteInFlight = false;
  window.ngwRunHotspotDelete = function(delUrl, statusLabel, triggerEl) {
    if (!delUrl || ngwDeleteInFlight) return;
    ngwDeleteInFlight = true;
    if (triggerEl && triggerEl.tagName === 'BUTTON') {
      triggerEl.disabled = true;
    }
    if (typeof loader === 'function') loader();
    var pill = document.getElementById('ngwUserDeletingPill');
    var pillTxt = document.getElementById('ngwUserDeletingText');
    if (pill && pill.parentNode !== document.body) {
      document.body.appendChild(pill);
    }
    if (pillTxt && statusLabel) {
      pillTxt.textContent = statusLabel;
    }
    if (pill) {
      pill.classList.add('is-shown');
    }
    if (window.jQuery && jQuery('#temp').length) {
      jQuery('#temp').load(delUrl, function(resp, status) {
        if (status === 'error') {
          ngwDeleteInFlight = false;
          if (triggerEl && triggerEl.tagName === 'BUTTON') triggerEl.disabled = false;
          var ldr = document.getElementById('loader');
          if (ldr) ldr.style.display = 'none';
          if (pill && pillTxt) {
            pillTxt.textContent = <?= json_encode(($langid === 'bn') ? 'ডিলিট ব্যর্থ হয়েছে, আবার চেষ্টা করুন।' : 'Delete failed. Please try again.'); ?>;
            setTimeout(function() { if (pill) pill.classList.remove('is-shown'); }, 3500);
          }
        }
      });
    } else if (typeof loadpage === 'function') {
      loadpage(delUrl);
    } else {
      window.location = delUrl;
    }
  };

  window.ngwDeleteWarnUser = function() {
    if (!activeDelUrl) return;
    if (confirm(activeDelMsg)) {
      ngwCloseUserWarnModal();
      ngwRunHotspotDelete(activeDelUrl, <?= json_encode(($langid === 'bn') ? 'ডিলিট হচ্ছে...' : 'Deleting...'); ?>);
    }
  };

  var noticeEl = document.getElementById('ngwUserDeleteNotice');
  if (noticeEl) {
    setTimeout(function() {
      noticeEl.style.opacity = '0';
      setTimeout(function() {
        if (noticeEl && noticeEl.parentNode) noticeEl.style.display = 'none';
      }, 220);
    }, 6500);
  }

  var resetMacBaseUrl = <?= json_encode(isset($resetMacUrl) ? $resetMacUrl : '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  window.ngwSyncResetMacOptUI = function() {
    var sel = document.querySelector('input[name="ngw_reset_mac_scope"]:checked');
    var val = sel ? sel.value : 'all';
    var optAll = document.getElementById('ngwRmacOptAll');
    var optRnd = document.getElementById('ngwRmacOptRandom');
    if (optAll) optAll.classList.toggle('is-selected', val === 'all');
    if (optRnd) optRnd.classList.toggle('is-selected', val === 'random');
  };

  window.ngwOpenResetMacModal = function() {
    var modal = document.getElementById('ngwResetMacModal');
    if (!modal) return;
    var allRadio = document.querySelector('input[name="ngw_reset_mac_scope"][value="all"]');
    if (allRadio) allRadio.checked = true;
    ngwSyncResetMacOptUI();
    modal.classList.add('open');
  };

  window.ngwCloseResetMacModal = function() {
    var modal = document.getElementById('ngwResetMacModal');
    if (modal) modal.classList.remove('open');
  };

  window.ngwConfirmResetMac = function() {
    if (!resetMacBaseUrl) return;
    var sel = document.querySelector('input[name="ngw_reset_mac_scope"]:checked');
    var scope = (sel && sel.value === 'random') ? 'random' : 'all';
    var url = resetMacBaseUrl + '&mac_scope=' + encodeURIComponent(scope);
    ngwCloseResetMacModal();
    if (typeof loadpage === 'function') {
      loadpage(url);
      if (typeof loader === 'function') loader();
    } else {
      window.location = url;
    }
  };

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      ngwCloseUserWarnModal();
      ngwCloseResetMacModal();
    }
  });
  var backdrop = document.getElementById('ngwUserWarnModal');
  if (backdrop) {
    backdrop.addEventListener('click', function(e) {
      if (e.target === backdrop) ngwCloseUserWarnModal();
    });
  }
  var rmacBackdrop = document.getElementById('ngwResetMacModal');
  if (rmacBackdrop) {
    rmacBackdrop.addEventListener('click', function(e) {
      if (e.target === rmacBackdrop) ngwCloseResetMacModal();
    });
  }

  function bindFilterOverride() {
    if (window.jQuery) {
      window.jQuery('#filterTable').off('keyup').on('keyup input', applyCombinedUserFilter);
    } else {
      var inp = document.getElementById('filterTable');
      if (inp) inp.addEventListener('input', applyCombinedUserFilter);
    }
    if (warnOnly) {
      applyCombinedUserFilter();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { setTimeout(bindFilterOverride, 0); });
  } else {
    setTimeout(bindFilterOverride, 0);
  }
})();
</script>
	
	
