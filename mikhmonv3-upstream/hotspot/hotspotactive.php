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
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
ngwSendNoCacheHeaders();
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
	header("Location:../admin.php?id=login");
	exit;
}

// load session MikroTik
$session = isset($_GET['session']) ? trim((string)$_GET['session']) : '';
$serveractive = isset($_GET['server']) ? trim((string)$_GET['server']) : '';

include_once(__DIR__ . '/../include/reseller_acl.php');
if ((function_exists('ngwIsCurrentSessionRevoked') && ngwIsCurrentSessionRevoked()) ||
	(isset($_SESSION['mikhmon_last_activity']) && (time() - (int)$_SESSION['mikhmon_last_activity']) > 300)) {
	$_SESSION = array();
	@session_unset();
	@session_destroy();
	ngwClearSessionCookie();
	http_response_code(401);
	exit('Session expired.');
}
if ($session === '' || $session === 'mikhmon' || !canAccessRouterSession($session) || !hasResellerPermission('view_active')) {
	session_write_close();
	http_response_code(403);
	exit('Access denied.');
}

// load config
include(__DIR__ . '/../include/config.php');
if (!isset($data[$session]) || !is_array($data[$session])) {
	session_write_close();
	http_response_code(404);
	exit('Router session not found.');
}
include(__DIR__ . '/../include/readcfg.php');

// lang
include(__DIR__ . '/../include/lang.php');
$selected_langid = (isset($langid) && $langid !== '') ? $langid : 'en';
include(__DIR__ . '/../lang/en.php');
$langid = $selected_langid;
if ($langid !== 'en' && is_file(__DIR__ . '/../lang/'.$langid.'.php')) {
	include(__DIR__ . '/../lang/'.$langid.'.php');
}

// routeros api
session_write_close();
include_once(__DIR__ . '/../lib/routeros_api.class.php');
include_once(__DIR__ . '/../lib/formatbytesbites.php');
include_once(__DIR__ . '/../include/ros_version.php');
$API = new RouterosAPI();
$API->debug = false;
$API->connect($iphost, $userhost, decrypt($passwdhost));

if ($serveractive != "") {
	$gethotspotactive = $API->comm("/ip/hotspot/active/print", array("?server" => "" . $serveractive . ""));
	$TotalReg = is_array($gethotspotactive) ? count($gethotspotactive) : 0;
	$counthotspotactive = $TotalReg;
} else {
	$gethotspotactive = $API->comm("/ip/hotspot/active/print");
	$TotalReg = is_array($gethotspotactive) ? count($gethotspotactive) : 0;
	$counthotspotactive = $TotalReg;
}

$userCommentMap = array();
if ($TotalReg > 0 && !empty($API->connected)) {
	$hsUsersForActive = $API->comm("/ip/hotspot/user/print", array(
		".proplist" => "name,comment",
	));
	if (is_array($hsUsersForActive)) {
		foreach ($hsUsersForActive as $hsu) {
			if (is_array($hsu) && !empty($hsu['name']) && isset($hsu['comment'])) {
				$userCommentMap[(string)$hsu['name']] = trim((string)$hsu['comment']);
			}
		}
	}
}
?>
<div class="row">
<div id="reloadHotspotActive">
<div class="col-12">
	<div class="card">
		<div class="card-header">
    		<h3><i class="fa fa-wifi"></i> <?= $_hotspot_active ?> <?php
				if ($serveractive != "") {
					echo htmlspecialchars($serveractive, ENT_QUOTES, 'UTF-8') . " ";
				}
				if ($counthotspotactive < 2) {
					echo $counthotspotactive . ($langid === 'bn' ? " টি আইটেম" : " item");
				} elseif ($counthotspotactive > 1) {
					echo $counthotspotactive . ($langid === 'bn' ? " টি আইটেম" : " items");
				};
				if ($serveractive != "") {
					echo " | <a href='./?hotspot=active&session=" . rawurlencode($session) . "'> <i class='fa fa-search'></i> " . ($langid === 'bn' ? 'সব দেখুন' : 'Show all') . "</a>";
				}
				?>			</h3>
        </div>
         <div class="card-body overflow">
<table id="tFilter" class="table table-bordered table-hover text-nowrap">
  <thead>
  <tr>
    <th></th>
    <th><?= ($langid === 'bn') ? 'সার্ভার' : 'Server'; ?></th>
    <th><?= ($langid === 'bn') ? 'ইউজার' : 'User'; ?></th>
    <th><?= ($langid === 'bn') ? 'অ্যাড্রেস' : 'Address'; ?></th>
    <th><?= ($langid === 'bn') ? 'ম্যাক অ্যাড্রেস' : 'Mac Address'; ?></th>
    <th class="text-right"><?= $_uptime ?></th>
    <th class="text-right"><?= ($langid === 'bn') ? 'বাইটস ইন' : 'Bytes In'; ?></th>
    <th class="text-right"><?= ($langid === 'bn') ? 'বাইটস আউট' : 'Bytes Out'; ?></th>
    <th class="text-right"><?= ($langid === 'bn') ? 'বাকি সময়' : 'Time Left'; ?></th>
    <th><?= ($langid === 'bn') ? 'লগইন মাধ্যম' : 'Login By'; ?></th>
    <th><?= $_comment ?></th>
  </tr>
  </thead>
  <tbody>
<?php
for ($i = 0; $i < $TotalReg; $i++) {
	$hotspotactive = $gethotspotactive[$i];
	$id = isset($hotspotactive['.id']) ? (string)$hotspotactive['.id'] : '';
	$server = isset($hotspotactive['server']) ? (string)$hotspotactive['server'] : '';
	$user = isset($hotspotactive['user']) ? (string)$hotspotactive['user'] : '';
	$address = isset($hotspotactive['address']) ? (string)$hotspotactive['address'] : '';
	$mac = isset($hotspotactive['mac-address']) ? (string)$hotspotactive['mac-address'] : '';
	$uptime = formatDTM(isset($hotspotactive['uptime']) ? $hotspotactive['uptime'] : '');
	$usesstime = formatDTM(isset($hotspotactive['session-time-left']) ? $hotspotactive['session-time-left'] : '');
	$bytesi = formatBytes(isset($hotspotactive['bytes-in']) ? $hotspotactive['bytes-in'] : 0, 2);
	$byteso = formatBytes(isset($hotspotactive['bytes-out']) ? $hotspotactive['bytes-out'] : 0, 2);
	$loginby = isset($hotspotactive['login-by']) ? (string)$hotspotactive['login-by'] : '';
	$comment = isset($hotspotactive['comment']) ? trim((string)$hotspotactive['comment']) : '';
	if (isset($userCommentMap[$user]) && $userCommentMap[$user] !== '') {
		$mappedClean = normalizeActivatedVoucherComment($userCommentMap[$user]);
		$mappedParsed = parseExpiryFromComment($mappedClean);
		if (!empty($mappedParsed['is_expiry']) || $comment === '' || preg_match('/^(?:vc|up)-/i', $comment)) {
			$comment = $mappedClean;
		}
	} else {
		$comment = normalizeActivatedVoucherComment($comment);
	}
	$safeServer = htmlspecialchars($server, ENT_QUOTES, 'UTF-8');
	$safeUser = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
	$safeAddr = htmlspecialchars($address, ENT_QUOTES, 'UTF-8');
	$safeMac = htmlspecialchars($mac, ENT_QUOTES, 'UTF-8');
	$safeLoginBy = htmlspecialchars($loginby, ENT_QUOTES, 'UTF-8');
	$safeSessionUrl = rawurlencode($session);
	$uriprocessJs = htmlspecialchars(json_encode('./?remove-user-active=' . rawurlencode($id) . '&session=' . $safeSessionUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
	echo "<tr>";
	echo "<td style='text-align:center;'>";
	if (hasResellerPermission('disconnect_active')) {
		echo "<span class='pointer' title='Remove " . $safeUser . "' onclick='loadpage(" . $uriprocessJs . ")'><i class='fa fa-minus-square text-danger'></i></span>";
	}
	echo "</td>";
	echo "<td><a title='filter " . $safeServer . "' href='./?hotspot=active&server=" . rawurlencode($server) . "&session=" . $safeSessionUrl . "'><i class='fa fa-server'></i> " . $safeServer . "</a></td>";
	if (hasResellerPermission('edit_user')) {
		echo "<td><a title='Open User " . $safeUser . "' href='./?hotspot-user=" . rawurlencode($user) . "&session=" . $safeSessionUrl . "'><i class='fa fa-edit'></i> " . $safeUser . "</a></td>";
	} else {
		echo "<td>" . $safeUser . "</td>";
	}
	echo "<td>" . $safeAddr . "</td>";
	echo "<td>" . $safeMac . "</td>";
	echo "<td style='text-align:right;'>" . htmlspecialchars((string)$uptime, ENT_QUOTES, 'UTF-8') . "</td>";
	echo "<td style='text-align:right;'>" . htmlspecialchars((string)$bytesi, ENT_QUOTES, 'UTF-8') . "</td>";
	echo "<td style='text-align:right;'>" . htmlspecialchars((string)$byteso, ENT_QUOTES, 'UTF-8') . "</td>";
	echo "<td style='text-align:right;'>" . htmlspecialchars((string)$usesstime, ENT_QUOTES, 'UTF-8') . "</td>";
	echo "<td>" . $safeLoginBy . "</td>";
	echo "<td>" . htmlspecialchars($comment, ENT_QUOTES, 'UTF-8') . "</td>";
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