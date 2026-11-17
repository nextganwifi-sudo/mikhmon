<?php
/*
 *  Copyright (C) 2019 Laksamadi Guko.
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
session_start();
// hide all error
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {
// load session MikroTik
  $session = isset($_GET['session']) ? (string)$_GET['session'] : '';
  include_once('../include/reseller_acl.php');
  if ($session === '' || !canAccessRouterSession($session) || (function_exists('ngwIsCurrentSessionRevoked') && ngwIsCurrentSessionRevoked())) {
    session_write_close();
    http_response_code(403);
    exit('Access denied.');
  }

// load config
  include('../include/config.php');
  if (!isset($data[$session])) {
    session_write_close();
    http_response_code(404);
    exit;
  }
  $iphost = explode('!', $data[$session][1])[1];
  $userhost = explode('@|@', $data[$session][2])[1];
  $passwdhost = explode('#|#', $data[$session][3])[1];
  $curency = explode('&', $data[$session][6])[1];

// lang
include('../include/lang.php');
$selected_langid = (isset($langid) && $langid !== '') ? $langid : 'en';
include('../lang/en.php');
$langid = $selected_langid;
if ($langid !== 'en' && is_file('../lang/'.$langid.'.php')) {
  include('../lang/'.$langid.'.php');
}
session_write_close();

  include_once('../lib/routeros_api.class.php');

  $API = new RouterosAPI();
  $API->debug = false;
  $API->connect($iphost, $userhost, decrypt($passwdhost));

  $uprofname = isset($_GET['name']) ? (string)$_GET['name'] : '';
  if ($uprofname != "") {
    $getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$uprofname"));
    $ponlogin = isset($getprofile[0]['on-login']) ? (string)$getprofile[0]['on-login'] : '';
    $parts = explode(",", $ponlogin);
    $valPart = isset($parts[3]) ? $parts[3] : '';
    $getprice = isset($parts[2]) ? $parts[2] : 0;
    $getsprice = isset($parts[4]) ? $parts[4] : 0;
    $lockPart = isset($parts[6]) ? $parts[6] : '';
    $getvalid = $_validity. " : " . $valPart;
    $getlock = "| ".$_lock_user." : " . $lockPart;
    $price = '';
    $sprice = '';
    if ((float)$getprice != 0) {
      if ($curency == "Rp" || $curency == "rp" || $curency == "IDR" || $curency == "idr") {
        $price = "| ".$_price." : " . $curency . " " . number_format((float)$getprice, 0, ",", ".");
      } else {
        $price = "| ".$_price." : " . $curency . " " . number_format((float)$getprice);
      }
    }
    if ((float)$getsprice != 0) {
      if ($curency == "Rp" || $curency == "rp" || $curency == "IDR" || $curency == "idr") {
        $sprice = "| ".$_selling_price." : " . $curency . " " . number_format((float)$getsprice, 0, ",", ".");
      } else {
        $sprice = "| ".$_selling_price." : " . $curency . " " . number_format((float)$getsprice);
      }
    }
    echo '<b id="getdata">' . htmlspecialchars($getvalid . ' ' . $price . ' ' . $sprice . ' ' . $getlock, ENT_QUOTES, 'UTF-8') . '</b>';
    echo '<span id="validity">' . htmlspecialchars($valPart, ENT_QUOTES, 'UTF-8') . '</span> ';
  }
}
?>
