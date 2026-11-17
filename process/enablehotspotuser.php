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

$uRowEn = $API->comm("/ip/hotspot/user/print", array("?.id" => "$enablehotspotuser"));
$uEnName = (is_array($uRowEn) && !empty($uRowEn[0]['name'])) ? (string)$uRowEn[0]['name'] : (string)$enablehotspotuser;
$uEnProf = (is_array($uRowEn) && !empty($uRowEn[0]['profile'])) ? (string)$uRowEn[0]['profile'] : '';

$API->comm("/ip/hotspot/user/set", array(
	".id" => "$enablehotspotuser",
	"disabled" => "no",
));
if (function_exists('ngwRecordActivity')) {
	ngwRecordActivity('hotspot_user_enabled', array(
		'router' => isset($session) ? (string)$session : '',
		'target' => $uEnName,
		'profile' => $uEnProf,
	));
}
if ($_SESSION['ubp'] != "") {
	echo "<script>window.location='./?hotspot=users&profile=" . $_SESSION['ubp'] . "&session=" . $session . "'</script>";
} elseif ($_SESSION['ubc'] != "") {
	echo "<script>window.location='./?hotspot=users&comment=" . $_SESSION['ubc'] . "&session=" . $session . "'</script>";
} else {
	echo "<script>window.location='./?hotspot=users&profile=all&session=" . $session . "'</script>";
}


?>