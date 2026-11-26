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
$getuser6 = $API->comm("/ip/hotspot/user/print", array(
  "?limit-uptime" => "1s",
));
$getuser7 = $API->comm("/ip/hotspot/user/print", array(
  "?limit-uptime" => "00:00:01",
));
$getuser = array_merge(is_array($getuser6) ? $getuser6 : array(), is_array($getuser7) ? $getuser7 : array());
include_once('./include/ros_version.php');
include_once('./include/voucher_history.php');
$tzSync = ngwSyncRouterTimezone($API);
$routerNowTs = $tzSync['now_ts'];

$TotalReg = count($getuser);
if ($TotalReg > 0) {
  ngwMarkVouchersRemoved($session, $getuser, 'expired_auto_removed', $routerNowTs);
}

$expProfile = isset($getuser[0]['profile']) ? (string)$getuser[0]['profile'] : '';
$deletedCount = 0;

for ($i = 0; $i < $TotalReg; $i++) {
  $userdetails = $getuser[$i];
  if (!is_array($userdetails) || empty($userdetails['.id'])) continue;
  $uname = isset($userdetails['name']) ? (string)$userdetails['name'] : '';
  if ($uname === 'default-trial') continue;
  $uid = $userdetails['.id'];

  $API->comm("/ip/hotspot/user/remove", array(
    ".id" => "$uid",
  ));
  $deletedCount++;
}
if ($deletedCount > 0 && function_exists('ngwRecordActivity')) {
  ngwRecordActivity('hotspot_expired_deleted', array(
    'router' => (string)$session,
    'quantity' => (string)$deletedCount,
    'profile' => $expProfile,
  ));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}
$_SESSION['ubp'] = "";
$_SESSION['ubc'] = "";
$isBnNotice = (isset($langid) && $langid === 'bn');
if ($deletedCount > 0) {
  $noticeMsg = $isBnNotice
    ? ($deletedCount . 'টি মেয়াদোত্তীর্ণ ইউজার সফলভাবে ডিলিট হয়েছে।')
    : ('Successfully deleted ' . $deletedCount . ' expired user(s).');
} else {
  $noticeMsg = $isBnNotice
    ? 'ডিলিট করার মতো কোনো মেয়াদোত্তীর্ণ ইউজার পাওয়া যায়নি।'
    : 'No expired users found to delete.';
}
$_SESSION['ngw_hotspot_users_notice'] = array(
  'type' => 'success',
  'message' => $noticeMsg,
);
@session_write_close();

echo "<script>window.location='./?hotspot=users&session=" . rawurlencode($session) . "'</script>";

?>