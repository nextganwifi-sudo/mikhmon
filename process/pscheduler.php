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

// remove scheduler
if ($removesch != "") {
	$API->comm("/system/scheduler/remove", array(
		".id" => "$removesch",
	));
	if (function_exists('ngwRecordActivity')) {
		ngwRecordActivity('hotspot_scheduler_changed', array(
			'router' => isset($session) ? (string)$session : '',
			'target' => (string)$removesch,
			'status' => 'Removed',
		));
	}

	echo "<script>window.location='./?system=scheduler&session=" . $session . "'</script>";
}
// enable scheduler
elseif ($enablesch != "") {
	$API->comm("/system/scheduler/set", array(
		".id" => "$enablesch",
		"disabled" => "no",
	));
	if (function_exists('ngwRecordActivity')) {
		ngwRecordActivity('hotspot_scheduler_changed', array(
			'router' => isset($session) ? (string)$session : '',
			'target' => (string)$enablesch,
			'status' => 'Enabled',
		));
	}

	echo "<script>window.location='./?system=scheduler&session=" . $session . "'</script>";
}

// disable scheduler
elseif ($disablesch != "") {
	$API->comm("/system/scheduler/set", array(
		".id" => "$disablesch",
		"disabled" => "yes",
	));
	if (function_exists('ngwRecordActivity')) {
		ngwRecordActivity('hotspot_scheduler_changed', array(
			'router' => isset($session) ? (string)$session : '',
			'target' => (string)$disablesch,
			'status' => 'Disabled',
		));
	}

	echo "<script>window.location='./?system=scheduler&session=" . $session . "'</script>";
}