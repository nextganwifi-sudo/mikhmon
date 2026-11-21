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

// hide all error
error_reporting(0);
ob_start("ob_gzhandler");

$session = isset($_GET['session']) ? trim((string)$_GET['session']) : '';
require('../lib/routeros_api.class.php');
include('../lib/formatbytesbites.php');
include('../include/config.php');

// theme  
include('../include/theme.php');

if ($session === '' || $session === 'mikhmon' || !isset($data[$session]) || !is_array($data[$session])) {
	http_response_code(404);
	echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Status Voucher</title></head><body style="font-family:sans-serif;text-align:center;padding:30px;"><h3>Invalid or unknown router session.</h3></body></html>';
	exit;
}

$iphost = explode('!', $data[$session][1])[1];
$userhost = explode('@|@', $data[$session][2])[1];
$passwdhost = explode('#|#', $data[$session][3])[1];
$hotspotname = explode('%', $data[$session][4])[1];
$dnsname = explode('^', $data[$session][5])[1];
$currency = explode('&', $data[$session][6])[1];
$safeHotspotName = htmlspecialchars((string)$hotspotname, ENT_QUOTES, 'UTF-8');

$cekindo['indo'] = array('RP', 'Rp', 'rp', 'IDR', 'idr', 'RP.', 'Rp.', 'rp.', 'IDR.', 'idr.', );

$API = new RouterosAPI();
$API->debug = false;
if ($currency == in_array($currency, $cekindo['indo'])) {
	$title = array("Status Voucher", "User/Kode Voucher", "Paket", "Lama Terhubung", "Pemakaian Data", "Sisa Data", "Masa Aktif", "Dari", "Sampai", "tidak terdaftar.", "sudah kadaluarsa.", "Tanggal", "Cek Status", " Hari", " Jam", "Aktif", "Expired");
} else {
	$title = array("Voucher Status", "User/Voucher Code", "Profile", "Uptime", "Data Usage", "Data Remaining", "Validity", "Start", "End", "not registered.", "expired.", "Date", "Check Status", " Day", " Hour", "Active", "Expired");
}
if ($currency == in_array($currency, $cekindo['indo'])) {
	$s = "";
} else {
	$s = "s";
}
?>
<!DOCTYPE html>
<html>
<head>
<title><?= htmlspecialchars($title[0], ENT_QUOTES, 'UTF-8') . " " . $safeHotspotName; ?></title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta http-equiv="pragma" content="no-cache" />
<meta name="viewport" content="width=device-width; initial-scale=1.0; maximum-scale=1.0;"/>
<!-- Font Awesome -->
<link rel="stylesheet" type="text/css" href="../css/font-awesome/css/font-awesome.min.css" />
<!-- NextGan UI -->
<link rel="stylesheet" href="../css/mikhmon-ui.<?= htmlspecialchars((string)$theme, ENT_QUOTES, 'UTF-8'); ?>.min.css">
<link rel="icon" type="image/png" href="../img/favicon.png?v=nextgan" />
<script>
function goBack() {
    window.history.back();
}
</script>

</head>
<body >
<div class="login-box" style="padding-top: 10px;">
<h3 class="text-center">Status Voucher<br><?= $safeHotspotName; ?></h3>
<p class="text-center" id="date1"><?= htmlspecialchars($title[11], ENT_QUOTES, 'UTF-8') . " : " . date("d-m-Y") . "<br>"; ?></p>
<form autocomplete="off" class="form" method="post" action="">
	<div class="input-group">
        <div class="input-group-7">
			<input type="text" class="group-item group-item-l" name="nama" placeholder="<?= htmlspecialchars($title[1], ENT_QUOTES, 'UTF-8'); ?>" autofocus required="1" />
		</div>
		<div class="input-group-5">
			<button type="submit" style="cursor: pointer; padding: 2.5px;" class="group-item group-item-r"><i class="fa fa-search"></i> <?= " " . htmlspecialchars($title[12], ENT_QUOTES, 'UTF-8'); ?></button>
		</div>
</div>
</form>
<?php
if (isset($_POST['nama'])) {
	$name = trim((string)$_POST['nama']);
	$safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
	$user = '';
	$profile = '';
	$exp = '';
	$uptime = '';
	$bytetot = '';
	$limitup = '';
	$limitbyte = '';
	$getbyteo = 0;
	$dataleft = '';
	$getvalid = '';
	if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {
		$getuser = $API->comm("/ip/hotspot/user/print", array("?name" => "$name"));
		$user = isset($getuser[0]['name']) ? (string)$getuser[0]['name'] : '';
		$profile = isset($getuser[0]['profile']) ? (string)$getuser[0]['profile'] : '';
		$exp = isset($getuser[0]['comment']) ? (string)$getuser[0]['comment'] : '';
		$uptime = formatDTM(isset($getuser[0]['uptime']) ? $getuser[0]['uptime'] : '');
		$getbytein = isset($getuser[0]['bytes-in']) ? (float)$getuser[0]['bytes-in'] : 0;
		$getbyteo = isset($getuser[0]['bytes-out']) ? (float)$getuser[0]['bytes-out'] : 0;
		$getbytetot = ($getbytein + $getbyteo);
		$bytetot = formatBytes($getbytetot, 2);
		$limitup = isset($getuser[0]['limit-uptime']) ? (string)$getuser[0]['limit-uptime'] : '';
		$limitbyte = isset($getuser[0]['limit-bytes-total']) ? (string)$getuser[0]['limit-bytes-total'] : '';
		if ($limitbyte == "") {
			$dataleft = "Unlimited";
		} elseif ((float)$limitbyte < $getbytetot) {
			$dataleft = "0 Byte";
		} else {
			$dataleft = formatBytes((float)$limitbyte - $getbytetot, 2);
		}

		$getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$profile", ));
		$ponlogin = isset($getprofile[0]['on-login']) ? (string)$getprofile[0]['on-login'] : '';
		$ponParts = explode(",", $ponlogin);
		$getvalid = isset($ponParts[3]) ? (string)$ponParts[3] : '';
		$unit = substr($getvalid, -1);
		if ($unit == "d") {
			$getvalid = substr($getvalid, 0, strlen($getvalid) - 1) . " " . $title[13];
		} elseif ($unit == "h") {
			$getvalid = substr($getvalid, 0, strlen($getvalid) - 1) . " " . $title[14];
		}
	}
  
	if ($user == "" || (substr($exp,3,1) != "/" && substr($exp,6,1) != "/")) {
		echo "<h3 class='text-center'>User <i style='color:#008CCA;'>" . $safeName . "</i> " . htmlspecialchars($title[9], ENT_QUOTES, 'UTF-8') . "</h3>";
	} elseif ($limitup == "1s" || $uptime == $limitup || ($limitbyte !== "" && $getbyteo == (float)$limitbyte)) {
		echo "<h3 class='text-center'>User <i style='color:#008CCA;'>" . $safeName . "</i> " . htmlspecialchars($title[10], ENT_QUOTES, 'UTF-8') . "</h3>";
	}
	if ($user == "" || (substr($exp,3,1) != "/" && substr($exp,6,1) != "/")) {
	} else {
		$safeUser = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
		$safeProfile = htmlspecialchars($profile, ENT_QUOTES, 'UTF-8');
		$safeUptime = htmlspecialchars((string)$uptime, ENT_QUOTES, 'UTF-8');
		$safeBytetot = htmlspecialchars((string)$bytetot, ENT_QUOTES, 'UTF-8');
		$safeDataleft = htmlspecialchars((string)$dataleft, ENT_QUOTES, 'UTF-8');
		$safeGetvalid = htmlspecialchars((string)$getvalid, ENT_QUOTES, 'UTF-8');
		$safeExp = htmlspecialchars($exp, ENT_QUOTES, 'UTF-8');
		?>
<section>
<div class="card">
<div class="card-header">
    <h3>
      <i class="fa fa-user mr-1"></i>
        User Details
    </h3>
  </div>
  <div class="card-body">
  <?php
	echo "<div style='overflow-x:auto;'>";
	echo "<table class='table table-bordered table-hover text-nowrap'>";
	echo "	<tr>";
	echo "		<td >" . htmlspecialchars($title[1], ENT_QUOTES, 'UTF-8') . "</td>";
	echo "		<td > " . $safeUser . "</td>";
	echo "	</tr>";
	echo "	<tr>";
	echo "		<td >" . htmlspecialchars($title[2], ENT_QUOTES, 'UTF-8') . "</td>";
	echo "		<td > " . $safeProfile . "</td>";
	echo "	</tr>";
	echo "	<tr>";
	echo "		<td >" . htmlspecialchars($title[3], ENT_QUOTES, 'UTF-8') . "</td>";
	echo "		<td > " . $safeUptime . "</td>";
	echo "	</tr>";
	echo "	<tr>";
	echo "		<td >" . htmlspecialchars($title[4], ENT_QUOTES, 'UTF-8') . "</td>";
	echo "		<td > " . $safeBytetot . "</td>";
	echo "	</tr>";
	if ($limitup == "1s" || $uptime == $limitup || ($limitbyte !== "" && $getbyteo == (float)$limitbyte)) {
		echo "	<tr>";
		echo "		<td >Status</td>";
		echo "		<td >" . htmlspecialchars($title[16], ENT_QUOTES, 'UTF-8') . "</td>";
		echo "	</tr>";
		echo "</table>";
		echo "</div>";
	} else {
		echo "	<tr>";
		echo "		<td >" . htmlspecialchars($title[5], ENT_QUOTES, 'UTF-8') . "</td>";
		echo "		<td > " . $safeDataleft . "</td>";
		echo "	</tr>";
		echo "	<tr>";
		echo "		<td >" . htmlspecialchars($title[6], ENT_QUOTES, 'UTF-8') . "</td>";
		echo "		<td >" . $safeGetvalid . "</td>";
		echo "	</tr>";
				echo "	<tr>";
		echo "		<td >" . htmlspecialchars($title[8], ENT_QUOTES, 'UTF-8') . "</td>";
		echo "		<td >" . $safeExp . "</td>";
		echo "	</tr>";
		echo "	<tr>";
		echo "		<td >Status</td>";
		echo "		<td >" . htmlspecialchars($title[15], ENT_QUOTES, 'UTF-8') . "</td>";
		echo "	</tr>";
		echo "</table>";
		echo "</div>";
	}
}
$API->disconnect();

}

?>
</div>
</div>
</section>
</div>
</div>
</body>
</html>
