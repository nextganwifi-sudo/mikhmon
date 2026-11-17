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

include_once('./include/ros_version.php');
include_once('./include/voucher_history.php');
$tzSync = ngwSyncRouterTimezone($API);
$routerNowTs = $tzSync['now_ts'];

// get user active
$getuser = $API->comm("/ip/hotspot/active/print", array(
    "?.id" => "$removeuseractive",
));
$user = $getuser[0]['user'];
// get cookie id
$getcookie = $API->comm("/ip/hotspot/cookie/print", array(
    "?user" => "$user",
));
$ck = $getcookie[0]['.id'];
// remove cookie
$API->comm("/ip/hotspot/cookie/remove", array(
    ".id" => "$ck",
));
// remove user active
$API->comm("/ip/hotspot/active/remove", array(
    ".id" => "$removeuseractive",
));
if (!empty($user)) {
    ngwMarkVoucherDisconnected($session, $user, $routerNowTs, 'manual_disconnect', 'admin reset');
}
if (function_exists('ngwRecordActivity')) {
    ngwRecordActivity('hotspot_active_disconnected', array(
        'router' => isset($session) ? (string)$session : '',
        'target' => !empty($user) ? (string)$user : (string)$removeuseractive,
    ));
}
// redirect to user active
echo "<script>window.location='./?hotspot=active&session=" . $session . "'</script>";
?>