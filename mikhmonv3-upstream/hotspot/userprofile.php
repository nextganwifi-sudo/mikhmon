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
if (!isset($_SESSION["mikhmon"])) {
	echo '
<html>
<head><title>403 Forbidden</title></head>
<body bgcolor="white">
<center><h1>403 Forbidden</h1></center>
<hr><center>nginx/1.14.0</center>
</body>
</html>
';
} else {
	include_once('./include/ros_version.php');
	include_once('./include/reseller_acl.php');
	$routerOsMajor = detectRouterOSMajorVersion($API);
	$routerClockRow = array();
	if ($routerOsMajor === 7) {
		$routerClock = $API->comm("/system/clock/print");
		$routerClockRow = (is_array($routerClock) && isset($routerClock[0]) && is_array($routerClock[0])) ? $routerClock[0] : array();
	}

	if ((isset($_GET['migrate-ros7']) && $_GET['migrate-ros7'] == '1') || (isset($_GET['sync_ros7']) && $_GET['sync_ros7'] == '1')) {
		requireResellerPermission('edit_profile', './?hotspot=user-profiles&session=' . $session);
		syncOutdatedRos7ProfilesAndComments($API, true);
		$_SESSION['ngw_ros7_synced'][$session] = time();
		echo "<script>window.location='./?hotspot=user-profiles&session=" . $session . "'</script>";
		exit;
	}

	if (empty($API->is_virtual) && !empty($API->connected) && $routerOsMajor === 7 && empty($_SESSION['ngw_ros7_synced'][$session])) {
		syncOutdatedRos7ProfilesAndComments($API, false);
		$_SESSION['ngw_ros7_synced'][$session] = time();
	}

// get user profile
	$getprofile = $API->comm("/ip/hotspot/user/profile/print");
	$TotalReg = is_array($getprofile) ? count($getprofile) : 0;
// count user profile
	$countprofile = $TotalReg;
// count hotspot users per profile in a single pass
	$profileUserCounts = array();
	$allProfileUsers = $API->comm("/ip/hotspot/user/print");
	if (is_array($allProfileUsers)) {
		foreach ($allProfileUsers as $pUserRow) {
			if (!is_array($pUserRow)) {
				continue;
			}
			$uNameChk = isset($pUserRow['name']) ? (string)$pUserRow['name'] : '';
			if ($uNameChk === 'default-trial') {
				continue;
			}
			$uProfKey = (isset($pUserRow['profile']) && $pUserRow['profile'] !== '') ? (string)$pUserRow['profile'] : 'default';
			if (!isset($profileUserCounts[$uProfKey])) {
				$profileUserCounts[$uProfKey] = 0;
			}
			$profileUserCounts[$uProfKey]++;
		}
	}
}
?>
<div class="row">
<div class="col-12">
<div class="card">
<div class="card-header align-middle">
    <h3><i class=" fa fa-pie-chart"></i> <?= $_user_profile ?> 
    <?php if (hasResellerPermission('add_profile')) { ?>
    &nbsp; | &nbsp; <a href="./?user-profile=add&session=<?= $session; ?>" title="<?= $_add ?>"><i class="fa fa-user-plus"></i> <?= $_add ?></a>
    <?php } ?>
    <?php if (hasResellerPermission('edit_profile')) { ?>
    &nbsp; | &nbsp; <a href="./?hotspot=user-profiles&migrate-ros7=1&session=<?= $session; ?>" title="<?= ($langid === 'bn') ? 'ROS6/ROS7 মেয়াদ স্ক্রিপ্ট সিঙ্ক করুন' : 'Sync Universal ROS6/ROS7 Expiry Scripts'; ?>"><i class="fa fa-refresh"></i> <?= ($langid === 'bn') ? 'ROS6/ROS7 মেয়াদ সিঙ্ক' : 'Sync ROS6/ROS7 Expiry'; ?></a>
    <?php } ?>
	</h3>
</div>
<!-- /.card-header -->
<div class="card-body">
<div class="overflow box-bordered" style="max-height: 75vh"> 			   
<table id="tFilter" class="table table-bordered table-hover text-nowrap">
  <thead>
  <tr> 
		<th style="min-width:50px;" class="text-center" >
		<?php
	if ($countprofile < 2) {
		echo $countprofile . ($langid === 'bn' ? " টি আইটেম  " : " item  ");
	} elseif ($countprofile > 1) {
		echo $countprofile . ($langid === 'bn' ? " টি আইটেম   " : " items   ");
	}
	?></th>
		<th class="align-middle"><?= $_name ?></th>
		<th class="align-middle"><?= ($langid === 'bn') ? 'শেয়ার্ড<br>ইউজার' : 'Shared<br>Users'; ?></th>
		<th class="align-middle"><?= ($langid === 'bn') ? 'রেট<br>লিমিট' : 'Rate<br>Limit'; ?></th>
		<th class="align-middle"><?= $_expired_mode ?></th>
		<th class="align-middle"><?= $_validity ?></th>
		<th class="text-right align-middle" > <?= $_price." ".$currency; ?></th>
		<th class="text-right align-middle" style="width:74px; max-width:86px; white-space:normal; line-height:1.15; font-size:11px;"> <?= $_selling_price." ".$currency; ?></th>
		<th class="text-center align-middle" style="width:70px; max-width:82px; white-space:normal; line-height:1.15; font-size:11px;"><?= ($langid === 'bn') ? 'ইউজার' : 'Users'; ?></th>
		<th class="align-middle"><?= $_lock_user ?></th>
    </tr>
  </thead>
  <tbody>
<?php

for ($i = 0; $i < $TotalReg; $i++) {

	$profiledetalis = $getprofile[$i];
	$pid = isset($profiledetalis['.id']) ? $profiledetalis['.id'] : '';
	$pname = isset($profiledetalis['name']) ? (string)$profiledetalis['name'] : '';
	$psharedu = isset($profiledetalis['shared-users']) ? $profiledetalis['shared-users'] : '';
	$pratelimit = isset($profiledetalis['rate-limit']) ? $profiledetalis['rate-limit'] : '';
	$ponlogin = isset($profiledetalis['on-login']) ? (string)$profiledetalis['on-login'] : '';
	$profUserCount = isset($profileUserCounts[$pname]) ? (int)$profileUserCounts[$pname] : 0;
	$getmonexpired = $API->comm("/system/scheduler/print", array(
    "?name" => "$pname",
  ));
  $monexpired = (is_array($getmonexpired) && isset($getmonexpired[0]) && is_array($getmonexpired[0])) ? $getmonexpired[0] : array();
  $monid = isset($monexpired['.id']) ? $monexpired['.id'] : '';
	$pmon = isset($monexpired['name']) ? $monexpired['name'] : '';
	$chkpmon = isset($monexpired['disabled']) ? $monexpired['disabled'] : '';
	if(empty($pmon) || $chkpmon == "true"){$moncolor = "text-orange";}else{$moncolor = "text-green";}
	echo "<tr>";
	?>
  <td style='text-align:center;'>
  <?php if (hasResellerPermission('delete_profile')) { 
    if ($profUserCount > 0) {
      $delProfMsg = ($langid === 'bn')
        ? ('আপনি কি প্রোফাইলটি ডিলিট করতে নিশ্চিত (' . $pname . ')? সতর্কতা: এই প্রোফাইলে বর্তমানে ' . $profUserCount . ' জন ইউজার রয়েছে!')
        : ('Are you sure to delete profile (' . $pname . ')? Warning: ' . $profUserCount . ' user(s) currently belong to this profile!');
    } else {
      $delProfMsg = ($langid === 'bn' ? 'আপনি কি প্রোফাইলটি ডিলিট করতে নিশ্চিত' : 'Are you sure to delete profile') . ' (' . $pname . ')?';
    }
  ?>
  <i class='fa fa-minus-square text-danger pointer' onclick='if(confirm(<?= json_encode($delProfMsg); ?>)){loadpage("./?remove-user-profile=<?= urlencode($pid); ?>&pname=<?= urlencode($pname); ?>&session=<?= urlencode($session); ?>")}else{}' title='<?= htmlspecialchars($_remove . ' ' . $pname, ENT_QUOTES); ?>'></i>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
  <?php } ?>
  <?php
	if (hasResellerPermission('view_users')) {
		echo "<a title='" . ($langid === 'bn' ? 'প্রোফাইল অনুযায়ী ইউজার খুলুন ' : 'Open User by profile ') . htmlspecialchars($pname, ENT_QUOTES, 'UTF-8') . "'  href='./?hotspot=users&profile=" . rawurlencode($pname) . "&session=" . rawurlencode((string)$session) . "'><i class='fa fa-users'></i></a>";
	}
	echo "</td>";
	if (hasResellerPermission('edit_profile')) {
		echo "<td><a title='" . ($langid === 'bn' ? 'ইউজার প্রোফাইল খুলুন ' : 'Open User Profile ') . htmlspecialchars($pname, ENT_QUOTES, 'UTF-8') . "' href='./?user-profile=" . rawurlencode((string)$pid) . "&session=" . rawurlencode((string)$session) . "'><i class='fa fa-edit'></i> <i class='fa fa-ci fa-circle ".$moncolor."'></i> " . htmlspecialchars($pname, ENT_QUOTES, 'UTF-8') . "</a></td>";
	} else {
		echo "<td><i class='fa fa-ci fa-circle ".$moncolor."'></i> " . htmlspecialchars($pname, ENT_QUOTES, 'UTF-8') . "</td>";
	}
//$profiledetalis = $ARRAY[$i];echo "<td>" . $profiledetalis['name'];echo "</td>";
	echo "<td>" . htmlspecialchars((string)$psharedu, ENT_QUOTES, 'UTF-8');
	echo "</td>";
	echo "<td>" . htmlspecialchars((string)$pratelimit, ENT_QUOTES, 'UTF-8');
	echo "</td>";

	echo "<td>";
	$getexpmode = explode(",", $ponlogin);
// get expired mode
	$expmode = isset($getexpmode[1]) ? $getexpmode[1] : '';
	if ($expmode == "rem") {
		echo ($langid === 'bn') ? "রিমুভ (Remove)" : "Remove";
	} elseif ($expmode == "ntf") {
		echo ($langid === 'bn') ? "নোটিশ (Notice)" : "Notice";
	} elseif ($expmode == "remc") {
		echo ($langid === 'bn') ? "রিমুভ ও রেকর্ড" : "Remove & Record";
	} elseif ($expmode == "ntfc") {
		echo ($langid === 'bn') ? "নোটিশ ও রেকর্ড" : "Notice & Record";
	} else {

	}
	echo "</td>";
	echo "<td>";
// get validity
	$getvalid = explode(",", $ponlogin);
	echo isset($getvalid[3]) ? htmlspecialchars((string)$getvalid[3], ENT_QUOTES, 'UTF-8') : '';

	echo "</td>";

	echo "<td style='text-align:right;'>";
// get price
	$getprice = explode(",", $ponlogin);
	$price = isset($getprice[2]) ? trim((string)$getprice[2]) : '';
	if ($price == "" || $price == "0") {
		echo "";
	} else {
		if ($currency == in_array($currency, $cekindo['indo'])) {
			echo number_format((float)$price, 0, ",", ".");
		} else {
			echo number_format((float)$price, 2);
		}
	}

	echo "</td>";
	echo "<td style='text-align:right; width:74px; max-width:86px; font-variant-numeric:tabular-nums; padding-left:4px; padding-right:6px;'>";
// get selling price (compact column)
	$getsprice = explode(",", $ponlogin);
	$sprice = isset($getsprice[4]) ? trim((string)$getsprice[4]) : '';
	if ($sprice == "" || $sprice == "0") {
		echo "";
	} else {
		if ($currency == in_array($currency, $cekindo['indo'])) {
			echo number_format((float)$sprice, 0, ",", ".");
		} else {
			echo number_format((float)$sprice, 2);
		}
	}

	echo "</td>";
	$userBadgeClass = $profUserCount > 0 ? 'bg-primary' : 'bg-grey';
	echo "<td style='text-align:center; width:70px; max-width:82px; font-variant-numeric:tabular-nums; padding-left:4px; padding-right:4px;'>";
	if (hasResellerPermission('view_users')) {
		echo "<a href='./?hotspot=users&profile=" . rawurlencode($pname) . "&session=" . rawurlencode((string)$session) . "' class='" . $userBadgeClass . "' title='" . htmlspecialchars(($langid === 'bn' ? 'প্রোফাইল অনুযায়ী ইউজার দেখুন: ' : 'View users in profile: ') . $pname, ENT_QUOTES, 'UTF-8') . "' style='display:inline-block; min-width:34px; padding:2px 7px; border-radius:4px; font-weight:700; font-size:12px; color:#fff; text-decoration:none;'>" . $profUserCount . "</a>";
	} else {
		echo "<span class='" . $userBadgeClass . "' style='display:inline-block; min-width:34px; padding:2px 7px; border-radius:4px; font-weight:700; font-size:12px; color:#fff;'>" . $profUserCount . "</span>";
	}
	echo "</td>";
	echo "<td>";

	$getgracep = explode(",", $ponlogin);
	$lockVal = isset($getgracep[6]) ? trim((string)$getgracep[6]) : '';
	if ($langid === 'bn') {
		echo ($lockVal === 'Enable') ? 'চালু (Enable)' : (($lockVal === 'Disable') ? 'বন্ধ (Disable)' : htmlspecialchars($lockVal, ENT_QUOTES, 'UTF-8'));
	} else {
		echo htmlspecialchars($lockVal, ENT_QUOTES, 'UTF-8');
	}
	echo "</td>";
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
