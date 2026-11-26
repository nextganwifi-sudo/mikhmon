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
include_once('./include/ros_version.php');
include_once('./include/voucher_history.php');
$tzSync = ngwSyncRouterTimezone($API);
$routerNowTs = $tzSync['now_ts'];
$isBnNotice = (isset($langid) && $langid === 'bn');
$deletedCount = 0;
$deletedPreviewName = '';

if ($removehotspotusers != "") {
	$uids = explode("~", $removehotspotusers);

	$nuids = count($uids);
	$removedUsersList = array();

	for ($i = 0; $i < $nuids; $i++) {
		$curUid = trim((string)$uids[$i]);
		if ($curUid === '') continue;

		$getuname = $API->comm("/ip/hotspot/user/print", array(
			"?.id" => $curUid,
		));

		if (is_array($getuname) && !empty($getuname[0]) && !empty($getuname[0]['name'])) {
			$removedUsersList[] = $getuname[0];
			if ($deletedPreviewName === '') {
				$deletedPreviewName = (string)$getuname[0]['name'];
			}
		}

		$name = (is_array($getuname) && !empty($getuname[0]['name'])) ? (string)$getuname[0]['name'] : '';

		if ($name !== '') {
			$getscr = $API->comm("/system/script/print", array(
				"?name" => "$name",
			));
			$scr = (is_array($getscr) && !empty($getscr[0]['.id'])) ? (string)$getscr[0]['.id'] : '';
			if ($scr !== '') {
				$API->comm("/system/script/remove", array(
					".id" => "$scr",
				));
			}

			$getsch = $API->comm("/system/scheduler/print", array(
				"?name" => "$name",
			));
			$sch = (is_array($getsch) && !empty($getsch[0]['.id'])) ? (string)$getsch[0]['.id'] : '';
			if ($sch !== '') {
				$API->comm("/system/scheduler/remove", array(
					".id" => "$sch",
				));
			}
		}

		$API->comm("/ip/hotspot/user/remove", array(
			".id" => $curUid,
		));
		$deletedCount++;
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

} else {
	$getuname = $API->comm("/ip/hotspot/user/print", array(
		"?.id" => "$removehotspotuser",
	));

	if (is_array($getuname) && !empty($getuname[0]) && !empty($getuname[0]['name'])) {
		ngwMarkVouchersRemoved($session, array($getuname[0]), 'manual_delete', $routerNowTs);
		$deletedPreviewName = (string)$getuname[0]['name'];
	}

	$name = (is_array($getuname) && !empty($getuname[0]['name'])) ? (string)$getuname[0]['name'] : '';

	if ($name !== '') {
		$getscr = $API->comm("/system/script/print", array(
			"?name" => "$name",
		));
		$scr = (is_array($getscr) && !empty($getscr[0]['.id'])) ? (string)$getscr[0]['.id'] : '';
		if ($scr !== '') {
			$API->comm("/system/script/remove", array(
				".id" => "$scr",
			));
		}

		$getsch = $API->comm("/system/scheduler/print", array(
			"?name" => "$name",
		));
		$sch = (is_array($getsch) && !empty($getsch[0]['.id'])) ? (string)$getsch[0]['.id'] : '';
		if ($sch !== '') {
			$API->comm("/system/scheduler/remove", array(
				".id" => "$sch",
			));
		}
	}

	$API->comm("/ip/hotspot/user/remove", array(
		".id" => "$removehotspotuser",
	));
	$deletedCount = 1;

	if (function_exists('ngwRecordActivity')) {
		ngwRecordActivity('hotspot_user_deleted', array(
			'router' => (string)$session,
			'quantity' => '1',
			'target' => ($name !== '' ? (string)$name : (string)$removehotspotuser),
			'profile' => (is_array($getuname) && !empty($getuname[0]['profile'])) ? (string)$getuname[0]['profile'] : '',
		));
	}
}

if (session_status() !== PHP_SESSION_ACTIVE) {
	@session_start();
}
$ubpRedirect = isset($_SESSION['ubp']) ? (string)$_SESSION['ubp'] : '';
$ubcRedirect = isset($_SESSION['ubc']) ? (string)$_SESSION['ubc'] : '';
if ($deletedCount === 1 && $deletedPreviewName !== '') {
	$noticeMsg = $isBnNotice
		? ('ইউজার (' . $deletedPreviewName . ') সফলভাবে ডিলিট হয়েছে।')
		: ('User (' . $deletedPreviewName . ') deleted successfully.');
} elseif ($deletedCount > 0) {
	$noticeMsg = $isBnNotice
		? ($deletedCount . 'টি ইউজার সফলভাবে ডিলিট হয়েছে।')
		: ('Successfully deleted ' . $deletedCount . ' user(s).');
} else {
	$noticeMsg = $isBnNotice
		? 'ইউজার সফলভাবে ডিলিট হয়েছে।'
		: 'User deleted successfully.';
}
$_SESSION['ngw_hotspot_users_notice'] = array(
	'type' => 'success',
	'message' => $noticeMsg,
);
@session_write_close();

if ($ubpRedirect !== "") {
	echo "<script>window.location='./?hotspot=users&profile=" . rawurlencode($ubpRedirect) . "&session=" . rawurlencode($session) . "'</script>";
} elseif ($ubcRedirect !== "") {
	echo "<script>window.location='./?hotspot=users&comment=" . rawurlencode($ubcRedirect) . "&session=" . rawurlencode($session) . "'</script>";
} else {
	echo "<script>window.location='./?hotspot=users&profile=all&session=" . rawurlencode($session) . "'</script>";
}
?>