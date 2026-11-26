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
if (!isset($API) || !is_object($API)) {
  http_response_code(403);
  exit('Access denied.');
}
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  http_response_code(403);
  exit('Access denied.');
}
ini_set('max_execution_time', 300);

$targetBatch = trim((string)$removehotspotuserbycomment);
$getuser = array();

if ($targetBatch !== '') {
  $exactUsers = $API->comm("/ip/hotspot/user/print", array(
    "?comment" => $targetBatch,
  ));
  if (is_array($exactUsers) && !empty($exactUsers)) {
    $getuser = $exactUsers;
  } else {
    $allUsers = $API->comm("/ip/hotspot/user/print");
    if (is_array($allUsers)) {
      foreach ($allUsers as $u) {
        $uc = isset($u['comment']) ? trim((string)$u['comment']) : '';
        if ($uc === $targetBatch || ($uc !== '' && strpos($uc, $targetBatch) !== false)) {
          $getuser[] = $u;
        }
      }
    }
  }
}

include_once('./include/ros_version.php');
include_once('./include/voucher_history.php');
$tzSync = ngwSyncRouterTimezone($API);
$routerNowTs = $tzSync['now_ts'];

$TotalReg = is_array($getuser) ? count($getuser) : 0;

if ($TotalReg > 0) {
  ngwMarkVouchersRemoved($session, $getuser, 'manual_batch_delete', $routerNowTs);
}

$deletedCount = 0;
$batchProfile = ($TotalReg > 0 && isset($getuser[0]['profile'])) ? (string)$getuser[0]['profile'] : '';

for ($i = 0; $i < $TotalReg; $i++) {
  $userdetails = $getuser[$i];
  if (!is_array($userdetails) || empty($userdetails['.id'])) continue;
  $uid = $userdetails['.id'];
  $uname = isset($userdetails['name']) ? (string)$userdetails['name'] : '';
  if ($uname === 'default-trial') continue;

  $API->comm("/ip/hotspot/user/remove", array(
    ".id" => "$uid",
  ));
  $deletedCount++;
}

if ($deletedCount > 0 && function_exists('ngwRecordActivity')) {
  ngwRecordActivity('hotspot_users_batch_deleted', array(
    'router' => (string)$session,
    'target' => $targetBatch,
    'profile' => $batchProfile,
    'quantity' => (string)$deletedCount,
  ));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}
$_SESSION['ubp'] = $batchProfile;
$_SESSION['ubc'] = "";
$isBnNotice = (isset($langid) && $langid === 'bn');
if ($deletedCount > 0) {
  $noticeMsg = $isBnNotice
    ? ('ব্যাচ (' . $targetBatch . ') থেকে ' . $deletedCount . 'টি ইউজার সফলভাবে ডিলিট হয়েছে।')
    : ('Successfully deleted ' . $deletedCount . ' user(s) from batch (' . $targetBatch . ').');
} else {
  $noticeMsg = $isBnNotice
    ? ('ব্যাচ (' . $targetBatch . ') এ ডিলিট করার মতো কোনো ইউজার পাওয়া যায়নি।')
    : ('No users found to delete in batch (' . $targetBatch . ').');
}
$_SESSION['ngw_hotspot_users_notice'] = array(
  'type' => 'success',
  'message' => $noticeMsg,
);
$ubpRedirect = $_SESSION['ubp'];
@session_write_close();

if ($ubpRedirect != "") {
  echo "<script>window.location='./?hotspot=users&profile=" . rawurlencode($ubpRedirect) . "&session=" . rawurlencode($session) . "'</script>";
} else {
  echo "<script>window.location='./?hotspot=users&profile=all&session=" . rawurlencode($session) . "'</script>";
}

?>