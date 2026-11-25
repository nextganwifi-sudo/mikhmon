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

if ($removehotspotusers != "") {
	$uids = explode("~", $removehotspotusers);

	$nuids = count($uids);
	$removedUsersList = array();

	for ($i = 0; $i < $nuids; $i++) {

		$getuname = $API->comm("/ip/hotspot/user/print", array(
			"?.id" => "$uids[$i]",
		));

		if (is_array($getuname) && !empty($getuname[0]) && !empty($getuname[0]['name'])) {
			$removedUsersList[] = $getuname[0];
		}

		$name = $getuname[0]['name'];

		$getscr = $API->comm("/system/script/print", array(
			"?name" => "$name",
		));

		$scr = $getscr[0]['.id'];

		$getsch = $API->comm("/system/scheduler/print", array(
			"?name" => "$name",
		));

		$sch = $getsch[0]['.id'];

		$API->comm("/system/script/remove", array(
			".id" => "$scr",
		));

		$API->comm("/system/scheduler/remove", array(
			".id" => "$sch",
		));

		$API->comm("/ip/hotspot/user/remove", array(
			".id" => "$uids[$i]",
		));

	}

	if (!empty($removedUsersList)) {
		ngwMarkVouchersRemoved($session, $removedUsersList, 'manual_delete', $routerNowTs);
		if (function_exists('ngwRecordActivity')) {
			$delNames = array();
			foreach ($removedUsersList as $ru) {
				if (!empty($ru['name'])) $delNames[] = (string)$ru['name'];
			}
			$delPreview = implode(', ', array_slice($delNames, 0, 5)) . (count($delNames) > 5 ? (' +' . (count($delNames) - 5) . ' more') : '');
			ngwRecordActivity('hotspot_user_deleted', array(
				'router' => (string)$session,
				'quantity' => (string)count($removedUsersList),
				'target' => $delPreview,
			));
		}
	}

	if ($_SESSION['ubp'] != "") {
		echo "<script>window.location='./?hotspot=users&profile=" . $_SESSION['ubp'] . "&session=" . $session . "'</script>";
	} elseif ($_SESSION['ubc'] != "") {
		echo "<script>window.location='./?hotspot=users&comment=" . $_SESSION['ubc'] . "&session=" . $session . "'</script>";
	} else {
		echo "<script>window.location='./?hotspot=users&profile=all&session=" . $session . "'</script>";
	}


} else {
	$getuname = $API->comm("/ip/hotspot/user/print", array(
		"?.id" => "$removehotspotuser",
	));

	if (is_array($getuname) && !empty($getuname[0]) && !empty($getuname[0]['name'])) {
		ngwMarkVouchersRemoved($session, array($getuname[0]), 'manual_delete', $routerNowTs);
	}

	$name = $getuname[0]['name'];

	$getscr = $API->comm("/system/script/print", array(
		"?name" => "$name",
	));

	$scr = $getscr[0]['.id'];

	$getsch = $API->comm("/system/scheduler/print", array(
		"?name" => "$name",
	));

	$sch = $getsch[0]['.id'];

	$API->comm("/system/script/remove", array(
		".id" => "$scr",
	));

	$API->comm("/system/scheduler/remove", array(
		".id" => "$sch",
	));

	$API->comm("/ip/hotspot/user/remove", array(
		".id" => "$removehotspotuser",
	));

	if (function_exists('ngwRecordActivity')) {
		ngwRecordActivity('hotspot_user_deleted', array(
			'router' => (string)$session,
			'quantity' => '1',
			'target' => ($name !== '' ? (string)$name : (string)$removehotspotuser),
			'profile' => (is_array($getuname) && !empty($getuname[0]['profile'])) ? (string)$getuname[0]['profile'] : '',
		));
	}


	if ($_SESSION['ubp'] != "") {
		echo "<script>window.location='./?hotspot=users&profile=" . $_SESSION['ubp'] . "&session=" . $session . "'</script>";
	} elseif ($_SESSION['ubc'] != "") {
		echo "<script>window.location='./?hotspot=users&comment=" . $_SESSION['ubc'] . "&session=" . $session . "'</script>";
	} else {
		echo "<script>window.location='./?hotspot=users&profile=all&session=" . $session . "'</script>";
	}
}
?>