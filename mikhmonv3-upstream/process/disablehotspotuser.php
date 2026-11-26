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

$uRowDis = $API->comm("/ip/hotspot/user/print", array("?.id" => "$disablehotspotuser"));
$uDisName = (is_array($uRowDis) && !empty($uRowDis[0]['name'])) ? (string)$uRowDis[0]['name'] : (string)$disablehotspotuser;
$uDisProf = (is_array($uRowDis) && !empty($uRowDis[0]['profile'])) ? (string)$uRowDis[0]['profile'] : '';

$API->comm("/ip/hotspot/user/set", array(
	".id" => "$disablehotspotuser",
	"disabled" => "yes",
));
if (function_exists('ngwRecordActivity')) {
	ngwRecordActivity('hotspot_user_disabled', array(
		'router' => isset($session) ? (string)$session : '',
		'target' => $uDisName,
		'profile' => $uDisProf,
	));
}
if (!empty($_SESSION['ubc'])) {
	echo "<script>window.location='./?hotspot=users&comment=" . rawurlencode((string)$_SESSION['ubc']) . "&session=" . rawurlencode($session) . "'</script>";
} else {
	echo "<script>window.location='./?hotspot=users&session=" . rawurlencode($session) . "'</script>";
}


?>