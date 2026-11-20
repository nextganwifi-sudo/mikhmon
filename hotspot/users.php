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
  if ($prof == "all" && $comm == "" && $exp == "") {
    $getuser = $API->comm("/ip/hotspot/user/print");
    $TotalReg = is_array($getuser) ? count($getuser) : 0;
    $counttuser = $TotalReg;
    if (!empty($API->connected) && is_array($getuser)) {
      ngwSyncVoucherHistorySnapshot($API, $session, $getuser, null, $tzSync['now_ts'], 10);
    }
  } elseif ($prof == "all") {
    $getuser = $API->comm("/ip/hotspot/user/print");
    $TotalReg = is_array($getuser) ? count($getuser) : 0;
    $counttuser = $TotalReg;

  } elseif ($prof != "all") {
    $getuser = $API->comm("/ip/hotspot/user/print", array(
      "?profile" => "$prof",
    ));
    $TotalReg = is_array($getuser) ? count($getuser) : 0;
    $counttuser = $TotalReg;

  }
  if ($comm != "") {
    $getuser = $API->comm("/ip/hotspot/user/print", array(
      "?comment" => "$comm",
    //"?uptime" => "00:00:00"
    ));
    $TotalReg = is_array($getuser) ? count($getuser) : 0;
    $counttuser = $TotalReg;
    
  }
  $exp = $_GET['exp'];
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
  }
  $getprofile = $API->comm("/ip/hotspot/user/profile/print");
  $TotalReg2 = is_array($getprofile) ? count($getprofile) : 0;

  $nowRouterTs = (isset($tzSync['now_ts']) && (int)$tzSync['now_ts'] > 946684800) ? (int)$tzSync['now_ts'] : time();
  $nowRouterLabel = date('Y-m-d H:i:s', $nowRouterTs);
  $userWarnings = array();
  $warningTotal = 0;
  $warningExpiredCount = 0;
  $warningMissingDateCount = 0;
  for ($wi = 0; $wi < $TotalReg; $wi++) {
    $wEval = ngwEvaluateHotspotUserWarning(isset($getuser[$wi]) ? $getuser[$wi] : null, $nowRouterTs, $langid);
    $userWarnings[$wi] = $wEval;
    if (!empty($wEval['has_warning'])) {
      $warningTotal++;
      if ($wEval['type'] === 'expired_not_removed') {
        $warningExpiredCount++;
      } elseif ($wEval['type'] === 'missing_date') {
        $warningMissingDateCount++;
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
</style>

<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header">
    <h3><i class="fa fa-users"></i> <?= $_users ?>
      <span style="font-size: 14px">
        <?php
        if ($counttuser == 0 && $prof != "all" && $prof != "") {
          echo "<script>window.location='./?hotspot=users&profile=all&session=" . $session . "'</script>";
        } ?>
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
  <div class="row">
   <div class="col-6 pd-t-5 pd-b-5">
  <div class="input-group">
    <div class="input-group-4 col-box-4">
      <input id="filterTable" type="text" style="padding:5.8px;" class="group-item group-item-l" placeholder="<?= $_search ?>">
    </div>
    <div class="input-group-4 col-box-4">
      <select style="padding:5px;" class="group-item group-item-m" onchange="location = this.value; loader()" title="Filter by Profile">
        <option><?= $_profile ?> </option>
        <option value="./?hotspot=users&profile=all&session=<?= $session; ?>" <?= ($prof == 'all' && $comm == '') ? 'selected' : ''; ?>><?= $_show_all ?></option>
      <?php
      for ($i = 0; $i < $TotalReg2; $i++) {
        $profile = $getprofile[$i];
        $pSel = ($prof == $profile['name'] && $comm == '') ? 'selected' : '';
        echo "<option value='./?hotspot=users&profile=" . rawurlencode($profile['name']) . "&session=" . $session . "' " . $pSel . ">" . htmlspecialchars($profile['name']) . "</option>";
      }
      ?>
    </select>
  </div>
  <div class="input-group-4 col-box-4">
    <select style="padding:5px;" class="group-item group-item-r" id="comment" name="comment" onchange="if(this.value===''){location='./?hotspot=users&profile=all&session=<?= $session;?>';}else{location='./?hotspot=users&comment='+ encodeURIComponent(this.value) +'&session=<?= $session;?>';} loader();">
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
    ?>
  <button class="btn bg-red" onclick='if(confirm(<?= json_encode($delBatchConfirm); ?>)){loadpage(<?= json_encode($delBatchUrl); ?>);loader();}' title="Delete users in batch <?= htmlspecialchars($comm, ENT_QUOTES); ?>">  <i class="fa fa-trash"></i> <?= ($langid === 'bn') ? 'ডিলিট ইউজার' : 'Delete User'; ?></button>
    <?php } else if ($exp == "1") { ?>
  <button class="btn bg-red" onclick="if(confirm('<?= ($langid === 'bn') ? 'আপনি কি নিশ্চিত যে সকল মেয়াদোত্তীর্ণ ইউজার ডিলিট করতে চান?' : 'Are you sure to delete users?'; ?>')){loadpage('./?remove-hotspot-user-expired=1&session=<?= $session; ?>');loader();}else{}" title="Remove user expired">  <i class="fa fa-trash"></i> <?= ($langid === 'bn') ? 'মেয়াদোত্তীর্ণ ইউজার ডিলিট' : 'Expired Users'; ?></button>
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
  <?php if (hasResellerPermission('print_voucher')) { ?>
  <button class="btn bg-primary" title="Print" onclick="printV('qr','no');"><i class="fa fa-print"></i> <?= isset($_print) ? $_print : 'Print' ?></button>
  <button class="btn bg-primary" title='Print QR' onclick="printV('qr','yes');"><i class="fa fa-print"></i> <?= $_print_qr ?></button>
  <button class="btn bg-primary" title='Print Small'onclick="printV('small','yes');"><i class="fa fa-print"></i> <?= $_print_small ?></button>
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
    <th><?= isset($_print) ? $_print : 'Print'; ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_profile ?></th>
	  <th class="pointer" title="Click to sort">
      <i class="fa fa-sort"></i> <?= ($langid === 'bn') ? 'ম্যাক অ্যাড্রেস' : 'Mac Address'; ?>
      <?php if (hasResellerPermission('reset_mac_address') || hasResellerPermission('edit_user') || hasResellerPermission('enable_disable_user') || hasResellerPermission('reset_voucher')) {
        $resetMacConfirmMsg = ($langid === 'bn')
          ? ($comm != '' ? "আপনি কি নিশ্চিত যে এই ব্যাচের ($comm) সকল ইউজারের ম্যাক অ্যাড্রেস রিসেট করতে চান?" : (($prof != 'all' && $prof != '') ? "আপনি কি নিশ্চিত যে '$prof' প্রোফাইলের সকল ইউজারের ম্যাক অ্যাড্রেস রিসেট করতে চান?" : "আপনি কি নিশ্চিত যে সকল হটস্পট ইউজারের ম্যাক অ্যাড্রেস রিসেট করতে চান?"))
          : ($comm != '' ? "Are you sure you want to reset MAC addresses for batch ($comm)?" : (($prof != 'all' && $prof != '') ? "Are you sure you want to reset MAC addresses for profile '$prof'?" : "Are you sure you want to reset MAC addresses for all Hotspot users?"));
        $resetMacUrl = "./?reset-hotspot-macs=1&profile=" . rawurlencode($prof != '' ? $prof : 'all') . "&comment=" . rawurlencode($comm) . "&exp=" . rawurlencode($exp) . "&session=" . rawurlencode($session);
      ?>
      <button type="button" class="btn bg-warning" style="padding:1px 6px; font-size:11px; margin-left:5px; line-height:1.3; font-weight:600; border-radius:3px; cursor:pointer;" onclick='event.stopPropagation(); if(confirm(<?= json_encode($resetMacConfirmMsg); ?>)){loadpage(<?= json_encode($resetMacUrl); ?>);loader();}' title="<?= ($langid === 'bn') ? 'ম্যাক অ্যাড্রেস রিসেট করুন' : 'Reset MAC Addresses'; ?>">
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
  ?>
  <i class='fa fa-minus-square text-danger pointer' onclick='if(confirm(<?= json_encode($delUserMsg); ?>)){loadpage("./?remove-hotspot-user=<?= urlencode($uid); ?>&session=<?= urlencode($session); ?>")}else{}' title='Remove <?= htmlspecialchars($uname, ENT_QUOTES); ?>'></i>&nbsp;&nbsp;&nbsp;
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

  $popup = "javascript:window.open('./voucher/print.php?user=" . $usermode . "-" . $uname . "&qr=no&session=" . $session . "','_blank','width=320,height=550').print();";
  $popupQR = "javascript:window.open('./voucher/print.php?user=" . $usermode . "-" . $uname . "&qr=yes&session=" . $session . "','_blank','width=320,height=550').print();";
  if (hasResellerPermission('edit_user')) {
    echo "<td><a title='Open User " . $uname . "' href=./?hotspot-user=" . $uid . "&session=" . $session . "><i class='fa fa-edit'></i> " . $uname . " </a>";
    if (hasResellerPermission('reset_voucher') && $uname !== 'default-trial') {
      echo " <a title='" . htmlspecialchars($_check_voucher, ENT_QUOTES) . "' href='./?hotspot=reset-voucher&voucher=" . rawurlencode($uname) . "&session=" . rawurlencode($session) . "' style='margin-left:4px; opacity:0.8;'><i class='fa fa-search'></i></a>";
    }
    echo $warnBtnHtml;
    echo "</td>";
  } else {
    echo "<td>";
    if (hasResellerPermission('reset_voucher') && $uname !== 'default-trial') {
      echo "<a title='" . htmlspecialchars($_check_voucher, ENT_QUOTES) . "' href='./?hotspot=reset-voucher&voucher=" . rawurlencode($uname) . "&session=" . rawurlencode($session) . "'>" . htmlspecialchars($uname) . "</a>";
    } else {
      echo htmlspecialchars($uname);
    }
    echo $warnBtnHtml;
    echo "</td>";
  }
  // Password column
  if ($usermode == "vc") {
    echo "<td style='font-family:monospace; color:#888;' title='Voucher Code (Password equals Username)'>= " . htmlspecialchars($uname) . "</td>";
  } else {
    echo "<td style='font-family:monospace; font-weight:bold;'>" . htmlspecialchars($upass !== '' ? $upass : '-') . "</td>";
  }

  echo '<td class="text-center">';
  if (hasResellerPermission('print_voucher')) {
    echo '<a title="Print ' . $uname . '" href="' . $popup . '"><i class="fa fa-print"></i></a> &nbsp <a title="Print ' . $uname . '" href="' . $popupQR . '"><i class="fa fa-qrcode"></i> </a>';
  }
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
    echo "<a href=./?hotspot=users&profile=all&exp=1&session=" . $session . " title='Filter by expired'><i class='fa fa-search'></i> " . htmlspecialchars($ucommentClean)." ". $udatalimit ." ".$utimelimit . "</a>";
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

  window.ngwDeleteWarnUser = function() {
    if (!activeDelUrl) return;
    if (confirm(activeDelMsg)) {
      ngwCloseUserWarnModal();
      if (typeof loadpage === 'function') {
        loadpage(activeDelUrl);
      } else {
        window.location = activeDelUrl;
      }
    }
  };

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') ngwCloseUserWarnModal();
  });
  var backdrop = document.getElementById('ngwUserWarnModal');
  if (backdrop) {
    backdrop.addEventListener('click', function(e) {
      if (e.target === backdrop) ngwCloseUserWarnModal();
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
	
	
