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
session_start();
// hide all error
error_reporting(0);
ini_set('max_execution_time', 300);

$targetBatch = trim((string)$removehotspotuserbycomment);
$getuser = array();

if ($targetBatch !== '') {
  $exactUsers = $API->comm("/ip/hotspot/user/print", array(
    "?comment" => $targetBatch,
  ));
  if (is_array($exactUsers) && !empty($exactUsers)) {
    $getuser = $exactUsers;
  } else {
    $allUsers = $API->comm("/ip/hotspot/user/print");
    if (is_array($allUsers)) {
      foreach ($allUsers as $u) {
        $uc = isset($u['comment']) ? trim((string)$u['comment']) : '';
        if ($uc === $targetBatch || ($uc !== '' && strpos($uc, $targetBatch) !== false)) {
          $getuser[] = $u;
        }
      }
    }
  }
}

$TotalReg = is_array($getuser) ? count($getuser) : 0;

$_SESSION['ubp'] = ($TotalReg > 0 && isset($getuser[0]['profile'])) ? $getuser[0]['profile'] : '';
$_SESSION['ubc'] = "";

for ($i = 0; $i < $TotalReg; $i++) {
  $userdetails = $getuser[$i];
  if (!is_array($userdetails) || empty($userdetails['.id'])) continue;
  $uid = $userdetails['.id'];
  $uname = isset($userdetails['name']) ? (string)$userdetails['name'] : '';
  if ($uname === 'default-trial') continue;

  $API->comm("/ip/hotspot/user/remove", array(
    ".id" => "$uid",
  ));
}

if ($_SESSION['ubp'] != "") {
  echo "<script>window.location='./?hotspot=users&profile=" . rawurlencode($_SESSION['ubp']) . "&session=" . rawurlencode($session) . "'</script>";
} else {
  echo "<script>window.location='./?hotspot=users&profile=all&session=" . rawurlencode($session) . "'</script>";
}

?>