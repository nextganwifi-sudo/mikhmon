<?php
/*
 *  NextGan WiFi Solution — Bulk Reset Hotspot User MAC Addresses
 *  Resets MAC addresses based on active User List filter:
 *  - Specific Batch ($comm != "") -> Only resets MACs of users in that batch
 *  - Specific Profile ($prof != "all" && $prof != "") -> Only resets MACs of users in that profile
 *  - Show All ($prof == "all") -> Resets MACs of all Hotspot users
 */
session_start();
error_reporting(0);
ini_set('max_execution_time', 300);

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

$targetProf = isset($_GET['profile']) ? trim((string)$_GET['profile']) : (isset($prof) ? trim((string)$prof) : 'all');
$targetComm = isset($_GET['comment']) ? trim((string)$_GET['comment']) : (isset($comm) ? trim((string)$comm) : '');
$targetExp  = isset($_GET['exp']) ? trim((string)$_GET['exp']) : (isset($exp) ? trim((string)$exp) : '');

$getuser = array();
if ($targetComm !== '') {
  $byComm = $API->comm("/ip/hotspot/user/print", array(
    "?comment" => $targetComm,
  ));
  if (is_array($byComm) && !empty($byComm)) {
    $getuser = $byComm;
  } else {
    $allU = $API->comm("/ip/hotspot/user/print");
    if (is_array($allU)) {
      foreach ($allU as $u) {
        $uc = isset($u['comment']) ? trim((string)$u['comment']) : '';
        if ($uc === $targetComm || ($uc !== '' && strpos($uc, $targetComm) !== false)) {
          $getuser[] = $u;
        }
      }
    }
  }
} elseif ($targetExp !== '') {
  $u6 = $API->comm("/ip/hotspot/user/print", array("?limit-uptime" => "1s"));
  $u7 = $API->comm("/ip/hotspot/user/print", array("?limit-uptime" => "00:00:01"));
  $getuser = array_merge(is_array($u6) ? $u6 : array(), is_array($u7) ? $u7 : array());
} elseif ($targetProf !== '' && $targetProf !== 'all') {
  $byProf = $API->comm("/ip/hotspot/user/print", array(
    "?profile" => $targetProf,
  ));
  if (is_array($byProf)) {
    $getuser = $byProf;
  }
} else {
  $allU = $API->comm("/ip/hotspot/user/print");
  if (is_array($allU)) {
    $getuser = $allU;
  }
}

$resetNames = array();
$resetCount = 0;

if (is_array($getuser)) {
  foreach ($getuser as $userdetails) {
    if (!is_array($userdetails) || empty($userdetails['.id'])) continue;
    $uid = (string)$userdetails['.id'];
    $uname = isset($userdetails['name']) ? (string)$userdetails['name'] : '';
    if ($uname === 'default-trial' || (isset($userdetails['dynamic']) && $userdetails['dynamic'] === 'true')) {
      continue;
    }

    $umac = isset($userdetails['mac-address']) ? trim((string)$userdetails['mac-address']) : '';
    if ($umac !== '' && $umac !== '00:00:00:00:00:00') {
      $API->comm("/ip/hotspot/user/set", array(
        ".id" => $uid,
        "mac-address" => "00:00:00:00:00:00",
      ));
      @$API->comm("/ip/hotspot/user/set", array(
        ".id" => $uid,
        "mac-address" => "",
      ));
      if ($uname !== '') {
        $resetNames[$uname] = true;
      }
      $resetCount++;
    }
  }
}

// Also clear Hotspot cookies for any users whose MAC was reset
if (!empty($resetNames)) {
  $cookies = @$API->comm("/ip/hotspot/cookie/print");
  if (is_array($cookies)) {
    foreach ($cookies as $ck) {
      $cu = isset($ck['user']) ? (string)$ck['user'] : '';
      if ($cu !== '' && isset($resetNames[$cu]) && !empty($ck['.id'])) {
        @$API->comm("/ip/hotspot/cookie/remove", array(".id" => $ck['.id']));
      }
    }
  }
}

if (function_exists('ngwRecordActivity')) {
  ngwRecordActivity('hotspot_bulk_reset_mac', array(
    'router' => $session,
    'profile' => $targetProf,
    'batch' => $targetComm,
    'count' => $resetCount
  ));
}

if ($targetComm !== '') {
  echo "<script>window.location='./?hotspot=users&comment=" . rawurlencode($targetComm) . "&session=" . rawurlencode($session) . "'</script>";
} elseif ($targetProf !== '' && $targetProf !== 'all') {
  echo "<script>window.location='./?hotspot=users&profile=" . rawurlencode($targetProf) . "&session=" . rawurlencode($session) . "'</script>";
} else {
  echo "<script>window.location='./?hotspot=users&profile=all&session=" . rawurlencode($session) . "'</script>";
}
?>
