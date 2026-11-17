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
  session_start();
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

$_SESSION['ubp'] = isset($getuser[0]['profile']) ? $getuser[0]['profile'] : "";
$_SESSION['ubc'] = "";

for ($i = 0; $i < $TotalReg; $i++) {
  $userdetails = $getuser[$i];
  $uid = $userdetails['.id'];

  $API->comm("/ip/hotspot/user/remove", array(
    ".id" => "$uid",
  ));
}
if ($TotalReg > 0 && function_exists('ngwRecordActivity')) {
  ngwRecordActivity('hotspot_expired_deleted', array(
    'router' => (string)$session,
    'quantity' => (string)$TotalReg,
    'profile' => ($_SESSION['ubp'] !== '' ? (string)$_SESSION['ubp'] : ''),
  ));
}
if ($_SESSION['ubp'] != "") {
  echo "<script>window.location='./?hotspot=users&profile=" . $_SESSION['ubp'] . "&session=" . $session . "'</script>";
} else {
  echo "<script>window.location='./?hotspot=users&profile=all&session=" . $session . "'</script>";
}

?>