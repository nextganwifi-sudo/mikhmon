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
require_once __DIR__ . '/include/security.php';
ngwConfigureSecureSession();
session_start();
// hide all error
error_reporting(0);
// check url

ob_start("ob_gzhandler");


$url = $_SERVER['REQUEST_URI'];

// load session MikroTik

$session = $_GET['session'];

if (!isset($_SESSION["mikhmon"])) {
  header("Location:./admin.php?id=login");
} elseif (empty($session)) {
  if (isset($_GET['hotspot']) && $_GET['hotspot'] === 'about') {
    echo "<script>window.location='./admin.php?id=about'</script>";
  } else {
    echo "<script>window.location='./admin.php?id=sessions'</script>";
  }
} else {
  include_once('./include/ros_version.php');
  include_once('./include/reseller_acl.php');
  enforceMikhmonSessionSecurity(isset($_GET['hotspot']) && $_GET['hotspot'] === 'logout');
  if (isResetOnlyReseller() && (!isset($_GET['hotspot']) || ($_GET['hotspot'] !== 'reset-voucher' && $_GET['hotspot'] !== 'logout'))) {
    session_write_close();
    echo "<script>window.location='./admin.php?id=reset-voucher&session=" . urlencode($session) . "'</script>";
    exit;
  }
  if (!canAccessRouterSession($session)) {
    session_write_close();
    if (isResetOnlyReseller()) {
      echo "<script>window.location='./admin.php?id=reset-voucher'</script>";
    } elseif (isset($_GET['hotspot']) && $_GET['hotspot'] === 'about') {
      echo "<script>window.location='./admin.php?id=about'</script>";
    } else {
      echo "<script>window.location='./admin.php?id=sessions'</script>";
    }
    exit;
  }
  $_SESSION["$session"] = $session;
  $setsession = $_SESSION["$session"];

  $_SESSION["connect"] = "";

// time zone
  date_default_timezone_set($_SESSION['timezone']);

// lang
  include('./lang/isocodelang.php');
  include('./include/lang.php');
  $selected_langid = (isset($langid) && $langid !== '') ? $langid : 'en';
  include('./lang/en.php');
  $langid = $selected_langid;
  if ($langid !== 'en' && is_file('./lang/'.$langid.'.php')) {
    include('./lang/'.$langid.'.php');
  }

// load config
  include('./include/config.php');
  include('./include/readcfg.php');

// theme  
  include('./include/theme.php');
  include('./settings/settheme.php');
  include('./settings/setlang.php');
  if ($_SESSION['theme'] == "") {
    $theme = $theme;
    $themecolor = $themecolor;
  } else {
    $theme = $_SESSION['theme'];
    $themecolor = $_SESSION['themecolor'];
  }

// get variable
  $hotspot = $_GET['hotspot'];
  $hotspotuser = $_GET['hotspot-user'];
  $userbyname = $_GET['hotspot-user'];
  $removeuseractive = $_GET['remove-user-active'];
  $removehost = $_GET['remove-host'];
  $removecookie = $_GET['remove-cookie'];
  $removeipbinding = $_GET['remove-ip-binding'];
  $removehotspotuser = $_GET['remove-hotspot-user'];
  $removehotspotusers = $_GET['remove-hotspot-users'];
  $removeuserprofile = $_GET['remove-user-profile'];
  $resethotspotuser = $_GET['reset-hotspot-user'];
  $resethotspotmacs = $_GET['reset-hotspot-macs'];
  $removehotspotuserbycomment = $_GET['remove-hotspot-user-by-comment'];
  $removeexpiredhotspotuser = $_GET['remove-hotspot-user-expired'];
  $enablehotspotuser = $_GET['enable-hotspot-user'];
  $disablehotspotuser = $_GET['disable-hotspot-user'];
  $enableipbinding = $_GET['enable-ip-binding'];
  $disableipbinding = $_GET['disable-ip-binding'];
  $userprofile = $_GET['user-profile'];
  $userprofilebyname = $_GET['user-profile'];
  $sys = $_GET['system'];
  $enablesch = $_GET['enable-scheduler'];
  $disablesch = $_GET['disable-scheduler'];
  $removesch = $_GET['remove-scheduler'];
  $macbinding = $_GET['mac'];
  $ipbinding = $_GET['addr'];
  $pppoe = isset($_GET['pppoe']) ? $_GET['pppoe'] : (isset($_GET['ppp']) ? $_GET['ppp'] : '');
  $pppoe_user = isset($_GET['pppoe-user']) ? $_GET['pppoe-user'] : (isset($_GET['secret']) ? $_GET['secret'] : '');
  $enable_pppoe_user = isset($_GET['enable-pppoe-user']) ? $_GET['enable-pppoe-user'] : (isset($_GET['enable-pppsecret']) ? $_GET['enable-pppsecret'] : '');
  $disable_pppoe_user = isset($_GET['disable-pppoe-user']) ? $_GET['disable-pppoe-user'] : (isset($_GET['disable-pppsecret']) ? $_GET['disable-pppsecret'] : '');
  $remove_pppoe_user = isset($_GET['remove-pppoe-user']) ? $_GET['remove-pppoe-user'] : (isset($_GET['remove-pppsecret']) ? $_GET['remove-pppsecret'] : '');
  $remove_pppoe_profile = isset($_GET['remove-pppoe-profile']) ? $_GET['remove-pppoe-profile'] : (isset($_GET['remove-pprofile']) ? $_GET['remove-pprofile'] : (($pppoe === 'remove-profile' && isset($_GET['id'])) ? $_GET['id'] : ''));
  $disconnect_pppoe_active = isset($_GET['disconnect-pppoe-active']) ? $_GET['disconnect-pppoe-active'] : (isset($_GET['remove-pactive']) ? $_GET['remove-pactive'] : (($pppoe === 'disconnect-active' && isset($_GET['id'])) ? $_GET['id'] : ''));
  $ppp = $pppoe;
  $secretbyname = $pppoe_user;
  $enablesecr = $enable_pppoe_user;
  $disablesecr = $disable_pppoe_user;
  $removesecr = $remove_pppoe_user;
  $removepprofile = $remove_pppoe_profile;
  $removepactive = $disconnect_pppoe_active;
  $srv = $_GET['srv'];
  $prof = $_GET['profile'];
  $comm = $_GET['comment'];
  $serveractive = $_GET['server'];
  $report = $_GET['report'];
  $removereport = $_GET['remove-report'];
  $minterface = $_GET['interface'];

  // Pre-update navigation session state before releasing the session file lock
  if ($hotspot == "dashboard" || substr(explode("=", $url)[0], -9) == "/?session") {
    $_SESSION['ubn'] = "";
  } elseif ($hotspot == "add-user") {
    $_SESSION['hua'] = "";
  } elseif ($hotspot == "users" && $prof == "all") {
    $_SESSION['ubp'] = "";
    $_SESSION['hua'] = "";
    $_SESSION['ubc'] = "";
    $_SESSION['vcr'] = "";
  } elseif ($hotspot == "users" && $prof != "") {
    $_SESSION['ubp'] = $prof;
    $_SESSION['hua'] = "";
    $_SESSION['ubc'] = "";
    $_SESSION['vcr'] = "";
  } elseif ($hotspot == "users" && $comm != "") {
    $_SESSION['ubc'] = $comm;
    $_SESSION['hua'] = "";
    $_SESSION['ubp'] = "";
    $_SESSION['vcr'] = "";
  } elseif ($hotspot == "users-by-profile") {
    $_SESSION['ubp'] = "";
    $_SESSION['hua'] = "";
    $_SESSION['ubc'] = "";
    $_SESSION['vcr'] = "active";
  } elseif (substr((string)$hotspotuser, 0, 1) == "*") {
    $_SESSION['ubn'] = $hotspotuser;
    $_SESSION['hua'] = "";
  } elseif ($hotspotuser != "" && $hotspotuser != "add" && $hotspotuser != "generate") {
    $_SESSION['ubn'] = $hotspotuser;
  } elseif ($hotspot == "active") {
    $_SESSION['ubp'] = "";
    $_SESSION['hua'] = "hotspotactive";
    $_SESSION['ubc'] = "";
  }

  $pagehotspot = array('users','hosts','ipbinding','cookies','log','dhcp-leases');
  $pageppp = array('secrets','profiles','active',);
  $pagereport = array('userlog','selling');

  // CRITICAL FOR FAST NAVIGATION: Release PHP session file lock before connecting
  // to remote MikroTik RouterOS API so rapid page switching never blocks!
  if ($hotspot !== "logout") {
    session_write_close();
  }

// routeros api
  include_once('./lib/routeros_api.class.php');
  include_once('./lib/formatbytesbites.php');
  $API = new RouterosAPI();
  $API->debug = false;
  $localOnlyHotspotPages = array('resellers', 'system-update', 'admin-settings', 'activity', 'active-sessions', 'my-profile', 'template-editor', 'uplogo', 'about', 'logout', 'reset-voucher');
  $curHotspotReq = isset($_GET['hotspot']) ? $_GET['hotspot'] : '';
  // Render the completed voucher result from session data only; do not reconnect
  // to a slow router after the voucher creation POST has already succeeded.
  require_once __DIR__ . '/include/voucher_request.php';
  $isVoucherResultRequest = isLocalVoucherResultRequest($_SERVER['REQUEST_METHOD'], $_GET);
  if (!$isVoucherResultRequest && !in_array($curHotspotReq, $localOnlyHotspotPages, true)) {
    $isRouterConnected = $API->connect($iphost, $userhost, decrypt($passwdhost));
    if (!$isRouterConnected) {
      $routerDisplayLabel = (!empty($hotspotname) && $hotspotname !== $session) ? ($hotspotname . ' (' . $session . ')') : $session;
      $connErrReason = !empty($API->last_error) ? (string)$API->last_error : ('RouterOS API connection failed for ' . $iphost);
      ngwRecordActivity('router_connect_failed', array(
        'router' => $routerDisplayLabel,
        'host' => (string)$iphost,
        'error' => $connErrReason,
      ));
    }
    if (!empty($_SESSION[$session . '_identity'])) {
      $identity = $_SESSION[$session . '_identity'];
    } elseif ($isRouterConnected) {
      $getidentity = $API->comm("/system/identity/print");
      $identity = isset($getidentity[0]['name']) ? $getidentity[0]['name'] : $hotspotname;
      if (!empty($identity)) {
        @session_start();
        $_SESSION[$session . '_identity'] = $identity;
        session_write_close();
      }
    } else {
      $identity = $hotspotname;
    }
  } else {
    $identity = !empty($_SESSION[$session . '_identity']) ? $_SESSION[$session . '_identity'] : $hotspotname;
  }

  include_once('./include/headhtml.php');

  include_once('./include/menu.php');

  $disable_sci = '<script>
  document.getElementById("comment").onkeypress = function(e) {
    var chr = String.fromCharCode(e.which);
    if (" _!@#$%^&*()+=;|?,.~".indexOf(chr) >= 0)
        return false;
};
</script>';

  $defRedirect = './?session=' . $session;

// logout
  if ($hotspot == "logout") {
    ngwRecordActivity('logout', array('status' => 'User Logged Out'));
    ngwRemoveCurrentActiveSession();
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Logout...</b>";

    $_SESSION = array();
    @session_unset();
    @session_destroy();
    echo "<script>try{sessionStorage.clear();}catch(e){}</script>";
    echo "<script>window.location='./admin.php?id=login'</script>";
  }
// reset voucher
  elseif ($hotspot == "reset-voucher") {
    requireResellerPermission('reset_voucher', $defRedirect);
    include_once('./hotspot/resetvoucher.php');
  }
// resellers management
  elseif ($hotspot == "resellers") {
    if (!isMikhmonAdmin()) {
      echo "<script>window.location='" . $defRedirect . "'</script>";
      exit;
    }
    include_once('./settings/resellers.php');
  }
// Self-service account profile
  elseif ($hotspot == "my-profile") {
    include_once('./settings/my_profile.php');
  }
// activity history
  elseif ($hotspot == "activity") {
    if (!isMikhmonAdmin()) {
      echo "<script>window.location='" . $defRedirect . "'</script>";
      exit;
    }
    include_once('./settings/activity.php');
  }
// active sessions
  elseif ($hotspot == "active-sessions") {
    if (!isMikhmonAdmin()) {
      echo "<script>window.location='" . $defRedirect . "'</script>";
      exit;
    }
    include_once('./settings/active_sessions.php');
  }
// vpn hub
  elseif ($hotspot == "vpn") {
    if (!isMikhmonAdmin()) {
      echo "<script>window.location='" . $defRedirect . "'</script>";
      exit;
    }
    include_once('./vpn/vpn_hub.php');
  }
// system update & vps center
  elseif ($hotspot == "system-update") {
    if (!isMikhmonAdmin()) {
      echo "<script>window.location='" . $defRedirect . "'</script>";
      exit;
    }
    include_once('./settings/system_update.php');
  }
// admin settings
  elseif ($hotspot == "admin-settings") {
    if (!isMikhmonAdmin()) {
      echo "<script>window.location='" . $defRedirect . "'</script>";
      exit;
    }
    include_once('./settings/admin_settings.php');
  }
// redirect to home
  elseif (substr(explode("=", $url)[0],-9) == "/?session") {
    requireResellerPermission('view_dashboard', './admin.php?id=sessions');
    include_once('./dashboard/home.php');
    $_SESSION['ubn'] = "";
  }

// redirect to home
  elseif ($hotspot == "dashboard") {
    requireResellerPermission('view_dashboard', './admin.php?id=sessions');
    include_once('./dashboard/home.php');
    $_SESSION['ubn'] = "";

  }

// hotspot log
  elseif ($hotspot == "log") {
    requireResellerPermission('view_logs', $defRedirect);
    include_once('./hotspot/log.php');
  }

// hotspot log
  elseif ($report == "userlog") {
    requireResellerPermission('view_logs', $defRedirect);
    include_once('./report/userlog.php');
  }

// about
  elseif ($hotspot == "about") {
    include_once('./include/about.php');
  }

// bad request
  elseif (substr($url, -1) == "=") {
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Bad request! redirect to Home......</b>";

    echo "<script>window.location='./'</script>";
  }

// hotspot add users
  elseif ($hotspot == "add-user") {
    requireResellerPermission('add_user', $defRedirect);
    $_SESSION['hua'] = "";
    include_once('./hotspot/adduser.php');
  }

// hotspot users
  elseif ($hotspot == "users" && $prof == "all") {
    requireResellerPermission('view_users', $defRedirect);
    $_SESSION['ubp'] = "";
    $_SESSION['hua'] = "";
    $_SESSION['ubc'] = "";
    $_SESSION['vcr'] = "";
    include_once('./hotspot/users.php');
  }

// hotspot users filter by profile
  elseif ($hotspot == "users" && $prof != "") {
    requireResellerPermission('view_users', $defRedirect);
    $_SESSION['ubp'] = $prof;
    $_SESSION['hua'] = "";
    $_SESSION['ubc'] = "";
    $_SESSION['vcr'] = "";
    include_once('./hotspot/users.php');
  }

// hotspot users filter by comment
  elseif ($hotspot == "users" && $comm != "") {
    requireResellerPermission('view_users', $defRedirect);
    $_SESSION['ubc'] = $comm;
    $_SESSION['hua'] = "";
    $_SESSION['ubp'] = "";
    $_SESSION['vcr'] = "";
    include_once('./hotspot/users.php');
  }

// hotspot by profile
  elseif ($hotspot == "users-by-profile") {
    requireResellerPermission('view_users', $defRedirect);
    $_SESSION['ubp'] = "";
    $_SESSION['hua'] = "";
    $_SESSION['ubc'] = "";
    $_SESSION['vcr'] = "active";
    include_once('./hotspot/userbyprofile.php');
  }
// export hotspot users
  elseif ($hotspot == "export-users") {
    requireResellerPermission('export_users', $defRedirect);
    include_once('./hotspot/exportusers.php');
  }
// same-router hotspot profile transfer
  elseif ($hotspot == "profile-transfer") {
    echo "<script>window.location='./hotspot/profiletransfer.php?session=" . rawurlencode($session) . "'</script>";
    exit;
  }

// add hotspot user
  elseif ($hotspotuser == "add") {
    requireResellerPermission('add_user', $defRedirect);
    include_once('./hotspot/adduser.php');
    echo $disable_sci;
  }

// add hotspot user
  elseif ($hotspotuser == "generate") {
    requireResellerPermission('generate_voucher', $defRedirect);
    include_once('./hotspot/generateuser.php');
    echo $disable_sci;
  }

// hotspot users filter by name
  elseif (substr($hotspotuser, 0, 1) == "*") {
    requireResellerPermission('edit_user', $defRedirect);
    $_SESSION['ubn'] = $hotspotuser;
    $_SESSION['hua'] = "";
    include_once('./hotspot/userbyname.php');
  } elseif ($hotspotuser != "") {
    requireResellerPermission('edit_user', $defRedirect);
    $_SESSION['ubn'] = $hotspotuser;
    include_once('./hotspot/userbyname.php');
  }

// remove hotspot user
  elseif ($removehotspotuser != "" || $removehotspotusers != "") {
    requireResellerPermission('delete_user', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removehotspotuser.php');
  }

// remove hotspot user by comment
  elseif ($removehotspotuserbycomment != "") {
    requireResellerPermission('delete_user', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removehotspotuserbycomment.php');
  }

// remove expired hotspot user
elseif ($removeexpiredhotspotuser != "") {
  requireResellerPermission('delete_user', $defRedirect);
  echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

  include_once('./process/removeexpiredhotspotuser.php');
}  

// reset hotspot user
  elseif ($resethotspotuser != "") {
    requireResellerPermission('enable_disable_user', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/resethotspotuser.php');
  }

// bulk reset hotspot MAC addresses (by profile, batch, or all)
  elseif ($resethotspotmacs != "") {
    if (!hasResellerPermission('edit_user') && !hasResellerPermission('enable_disable_user') && !hasResellerPermission('reset_voucher')) {
      requireResellerPermission('edit_user', $defRedirect);
    }
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/resethotspotmacs.php');
  }

// enable hotspot user
  elseif ($enablehotspotuser != "") {
    requireResellerPermission('enable_disable_user', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/enablehotspotuser.php');
  }

// disable hotspot user
  elseif ($disablehotspotuser != "") {
    requireResellerPermission('enable_disable_user', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/disablehotspotuser.php');
  }

// user profile
  elseif ($hotspot == "user-profiles") {
    requireResellerPermission('view_profiles', $defRedirect);
    include_once('./hotspot/userprofile.php');
  }

// add  user profile
  elseif ($userprofile == "add") {
    requireResellerPermission('add_profile', $defRedirect);
    include_once('./hotspot/adduserprofile.php');
  }

// User profile by name
  elseif (substr($userprofile, 0, 1) == "*") {
    requireResellerPermission('edit_profile', $defRedirect);
    include_once('./hotspot/userprofilebyname.php');
  } elseif ($userprofile != "") {
    requireResellerPermission('edit_profile', $defRedirect);
    include_once('./hotspot/userprofilebyname.php');
  }


// remove user profile
  elseif ($removeuserprofile != "") {
    requireResellerPermission('delete_profile', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removeuserprofile.php');
  }

// hotspot active
  elseif ($hotspot == "active") {
    requireResellerPermission('view_active', $defRedirect);
    $_SESSION['ubp'] = "";
    $_SESSION['hua'] = "hotspotactive";
    $_SESSION['ubc'] = "";
    include_once('./hotspot/hotspotactive.php');
  }

// dhcp leases
  elseif ($hotspot == "dhcp-leases") {
    requireResellerPermission('view_dhcp', $defRedirect);
    include_once('./dhcp/dhcpleases.php');
  }

// traffic monitor
  elseif ($minterface == "traffic-monitor") {
    requireResellerPermission('view_traffic', $defRedirect);
    include_once('./traffic/trafficmonitor.php');
}

// hotspot hosts
  elseif ($hotspot == "hosts" || $hotspot == "hostp" || $hotspot == "hosta") {
    requireResellerPermission('view_hosts', $defRedirect);
    include_once('./hotspot/hosts.php');
  }

// hotspot bindings
  elseif ($hotspot == "binding") {
    requireResellerPermission('view_ipbinding', $defRedirect);
    include_once('./hotspot/binding.php');
  }

// template editor
  elseif ($hotspot == "template-editor") {
    if (!isMikhmonAdmin() && !hasResellerPermission('edit_own_template')) {
      requireResellerPermission('edit_own_template', $defRedirect);
    }
    include_once('./settings/vouchereditor.php');
  }

// upload logo
  elseif ($hotspot == "uplogo") {
    if (!isMikhmonAdmin() && !hasResellerPermission('edit_own_template') && !hasResellerPermission('save_own_template')) {
      requireResellerPermission('edit_own_template', $defRedirect);
    }
    include_once('./settings/uplogo.php');
  }

// hotspot Cookies
  elseif ($hotspot == "cookies") {
    requireResellerPermission('view_cookies', $defRedirect);
    include_once('./hotspot/cookies.php');
  }

// remove hotspot Cookies
  elseif ($removecookie != "") {
    requireResellerPermission('delete_cookies', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removecookie.php');
  }

// hotspot Ip Bindings
  elseif ($hotspot == "ipbinding") {
    requireResellerPermission('view_ipbinding', $defRedirect);
    include_once('./hotspot/ipbinding.php');
  }

// remove enable disable ipbinding
  elseif ($removeipbinding != "" || $enableipbinding != "" || $disableipbinding != "") {
    requireResellerPermission('manage_ipbinding', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/pipbinding.php');
  }


// remove user active
  elseif ($removeuseractive != "") {
    requireResellerPermission('disconnect_active', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removeuseractive.php');
  }

// remove host
  elseif ($removehost != "") {
    requireResellerPermission('manage_hosts', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removehost.php');
  }


// makebinding
  elseif ($macbinding != "") {
    requireResellerPermission('manage_hosts', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/makebinding.php');
  }

// selling
  elseif ($report == "selling") {
    requireResellerPermission('view_report', $defRedirect);
    include_once('./report/selling.php');
  }

// selling
elseif ($report == "resume-report") {
  requireResellerPermission('view_report', $defRedirect);
  include_once('./report/resumereport.php');
}

// selling
elseif ($report == "export") {
  requireResellerPermission('export_report', $defRedirect);
  include_once('./report/export.php');
}

// selling
  elseif ($removereport != "") {
    requireResellerPermission('delete_report', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/removereport.php');
  }

// PPPoE Users list
  elseif ($pppoe == "users" || $pppoe == "secrets") {
    requireResellerPermission('view_pppoe_users', $defRedirect);
    include_once('./include/pppoe_helper.php');
    include_once('./pppoe/users.php');
  }

// Add PPPoE User
  elseif ($pppoe == "add-user" || $pppoe == "addsecret") {
    requireResellerPermission('add_pppoe_user', $defRedirect);
    include_once('./include/pppoe_helper.php');
    include_once('./pppoe/user.php');
  }

// Edit PPPoE User
  elseif ($pppoe == "user" || $pppoe == "secret" || $pppoe_user != "") {
    requireResellerPermission('edit_pppoe_user', $defRedirect);
    include_once('./include/pppoe_helper.php');
    include_once('./pppoe/user.php');
  }

// Enable PPPoE User
  elseif ($enable_pppoe_user != "") {
    requireResellerPermission('enable_disable_pppoe_user', $defRedirect);
    $API->comm("/ppp/secret/set", array(".id" => $enable_pppoe_user, "disabled" => "no"));
    ngwRecordActivity('pppoe_user_enabled', array('router' => $session, 'id' => $enable_pppoe_user));
    echo "<script>window.location='./?pppoe=users&session=" . $session . "&msg=enabled';</script>";
    exit;
  }

// Disable PPPoE User
  elseif ($disable_pppoe_user != "") {
    requireResellerPermission('enable_disable_pppoe_user', $defRedirect);
    $API->comm("/ppp/secret/set", array(".id" => $disable_pppoe_user, "disabled" => "yes"));
    $sec = $API->comm("/ppp/secret/print", array("?.id" => $disable_pppoe_user));
    if (is_array($sec) && !empty($sec) && isset($sec[0]['name'])) {
      $acts = $API->comm("/ppp/active/print", array("?name" => $sec[0]['name']));
      if (is_array($acts)) {
        foreach ($acts as $a) {
          if (isset($a['.id'])) $API->comm("/ppp/active/remove", array(".id" => $a['.id']));
        }
      }
    }
    ngwRecordActivity('pppoe_user_disabled', array('router' => $session, 'id' => $disable_pppoe_user));
    echo "<script>window.location='./?pppoe=users&session=" . $session . "&msg=disabled';</script>";
    exit;
  }

// Remove PPPoE User
  elseif ($remove_pppoe_user != "") {
    requireResellerPermission('delete_pppoe_user', $defRedirect);
    $sec = $API->comm("/ppp/secret/print", array("?.id" => $remove_pppoe_user));
    if (is_array($sec) && !empty($sec) && isset($sec[0]['name'])) {
      $acts = $API->comm("/ppp/active/print", array("?name" => $sec[0]['name']));
      if (is_array($acts)) {
        foreach ($acts as $a) {
          if (isset($a['.id'])) $API->comm("/ppp/active/remove", array(".id" => $a['.id']));
        }
      }
    }
    $API->comm("/ppp/secret/remove", array(".id" => $remove_pppoe_user));
    ngwRecordActivity('pppoe_user_deleted', array('router' => $session, 'id' => $remove_pppoe_user));
    echo "<script>window.location='./?pppoe=users&session=" . $session . "&msg=deleted';</script>";
    exit;
  }

// PPPoE Profiles list
  elseif ($pppoe == "profiles") {
    requireResellerPermission('view_pppoe_profiles', $defRedirect);
    include_once('./include/pppoe_helper.php');
    include_once('./pppoe/profiles.php');
  }

// Add PPPoE Profile
  elseif ($pppoe == "add-profile") {
    requireResellerPermission('manage_pppoe_profiles', $defRedirect);
    include_once('./include/pppoe_helper.php');
    include_once('./pppoe/profile.php');
  }

// Edit PPPoE Profile
  elseif ($pppoe == "profile" || $pppoe == "edit-profile") {
    requireResellerPermission('manage_pppoe_profiles', $defRedirect);
    include_once('./include/pppoe_helper.php');
    include_once('./pppoe/profile.php');
  }

// Remove PPPoE Profile
  elseif ($remove_pppoe_profile != "") {
    requireResellerPermission('manage_pppoe_profiles', $defRedirect);
    $API->comm("/ppp/profile/remove", array(".id" => $remove_pppoe_profile));
    ngwRecordActivity('pppoe_profile_deleted', array('router' => $session, 'id' => $remove_pppoe_profile));
    echo "<script>window.location='./?pppoe=profiles&session=" . $session . "&msg=deleted';</script>";
    exit;
  }

// PPPoE Active Sessions
  elseif ($pppoe == "active") {
    requireResellerPermission('view_pppoe_active', $defRedirect);
    include_once('./include/pppoe_helper.php');
    include_once('./pppoe/active.php');
  }

// Disconnect PPPoE Active Session
  elseif ($disconnect_pppoe_active != "") {
    requireResellerPermission('disconnect_pppoe_active', $defRedirect);
    $API->comm("/ppp/active/remove", array(".id" => $disconnect_pppoe_active));
    ngwRecordActivity('pppoe_active_disconnected', array('router' => $session, 'id' => $disconnect_pppoe_active));
    echo "<script>window.location='./?pppoe=active&session=" . $session . "&msg=disconnected';</script>";
    exit;
  }

// Reset PPPoE User & Session Recovery Tool
  elseif ($pppoe == "reset") {
    requireResellerPermission('reset_pppoe', $defRedirect);
    include_once('./include/pppoe_helper.php');
    include_once('./pppoe/reset_pppoe.php');
  }

// sys scheduler
  elseif ($sys == "scheduler") {
    requireResellerPermission('view_scheduler', $defRedirect);
    include_once('./system/scheduler.php');
  }
// remove enable disable scheduler
  elseif ($removesch != "" || $enablesch != "" || $disablesch != "") {
    requireResellerPermission('manage_scheduler', $defRedirect);
    echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Processing...</b>";

    include_once('./process/pscheduler.php');
  }

  ?>

</div>
</div>
</div>
<?php
$needsHighcharts = ($hotspot == "dashboard" || substr(end(explode("/", $url)), 0, 8) == "?session" || $minterface == "traffic-monitor");
if ($needsHighcharts) {
?>
<script src="./js/highcharts/highcharts.js"></script>
<script src="./js/highcharts/themes/hc.<?= $theme; ?>.js"></script>
<?php } ?>
<script src="./js/mikhmon-ui.<?= $theme; ?>.min.js"></script>
<script src="./js/mikhmon.js?v=ngw2.2"></script>
<script>
(function(){
  try {
    if (window.jQuery) {
      $(".main-container").show();
      $("#loading").hide();
    }
  } catch(e) {}
  setTimeout(function(){
    var l = document.getElementById("loading");
    if (l) l.style.display = "none";
    var c = document.querySelectorAll(".main-container");
    for (var i = 0; i < c.length; i++) {
      c[i].style.display = "block";
    }
  }, 200);
})();
</script>

<?php
if ($hotspot == "dashboard" || substr(end(explode("/", $url)), 0, 8) == "?session") {
  $canSeeLogs = hasResellerPermission('view_logs');
  $canSeeHotspotBox = (hasResellerPermission('view_active') || hasResellerPermission('view_users') || hasResellerPermission('add_user') || hasResellerPermission('generate_voucher'));
  $canSeeReport = hasResellerPermission('view_report');
  echo '<script>';
  if ($canSeeLogs) {
    echo '
    $("#r_3").load("./dashboard/aload.php?session=' . $session . '&load=logs #r_3");';
  }
  echo '
    var interval1 = "' . ($areload * 1000) . '";
    var dashboard = setInterval(function() {
      
    $("#r_1").load("./dashboard/aload.php?session=' . $session . '&load=sysresource #r_1"); ';
  if ($canSeeHotspotBox) {
    echo '
    $("#r_2").load("./dashboard/aload.php?session=' . $session . '&load=hotspot #r_2"); ';
  }
  if ($canSeeLogs) {
    echo '
    $("#r_3").load("./dashboard/aload.php?session=' . $session . '&load=logs #r_3"); ';
  }
  echo '
  }, interval1);
';
if ($canSeeReport && ($livereport == "enable" || $livereport == "")) {
  if($_SESSION[$session.'sdate'] != $_SESSION[$session.'idhr']){
    $_SESSION[$session.'totalHr'] = "0";
    echo '$("#r_4").load("./report/livereport.php?session=' . $session . ' #r_4");';
    }else if ($_SESSION[$session.'sdate'] == $_SESSION[$session.'idhr']){  
    }else{
      echo '$("#r_4").load("./report/livereport.php?session=' . $session . ' #r_4");';
    }
  echo  '
    var interval2 = "65432";
    var livereport = setInterval(function() {
    $("#r_4").load("./report/livereport.php?session=' . $session . ' #r_4"); 
  }, interval2);
 ';}
  echo ' 
  function cancelPage(){
    window.stop();
    clearInterval(dashboard);';
    if ($canSeeReport && ($livereport == "enable" || $livereport == "")) {
    echo '
    clearInterval(livereport);';
    }
  echo '
    }
  window.addEventListener("beforeunload", function(){
    clearInterval(dashboard);';
    if ($canSeeReport && ($livereport == "enable" || $livereport == "")) {
    echo '
    clearInterval(livereport);';
    }
  echo '
  });
</script>';

} elseif ($hotspot == "active" && $serveractive != "") {
  echo '<script>
  $(document).ready(function(){
    var interval = "' . ($areload * 1000) . '";
    setInterval(function() {
    $("#reloadHotspotActive").load("./hotspot/hotspotactive.php?server=' . $serveractive . '&session=' . $session . '"); }, interval);})
</script>
';
} elseif ($hotspot == "active" && $serveractive == "") {
  echo '<script>
  $(document).ready(function(){
    var interval = "' . ($areload * 1000) . '";
    setInterval(function() {
    $("#reloadHotspotActive").load("./hotspot/hotspotactive.php?session=' . $session . '"); }, interval);})
</script>
';
} elseif ($userprofile == "add" || substr($userprofile, 0, 1) == "*" || $userprofile != "") {
  echo "<script>
  //enable disable input on ready
$(document).ready(function(){
    var exp = document.getElementById('expmode').value;
    var val = document.getElementById('validity').style;
    var vali = document.getElementById('validi');
    if (exp === 'rem' || exp === 'remc') {
      val.display= 'table-row';
      vali.type = 'text';
      $('#validi').focus();
    } else if (exp === 'ntf' || exp === 'ntfc') {
      val.display = 'table-row';
      vali.type = 'text';
      $('#validi').focus();
    } else {
      val.display = 'none';
      vali.type = 'hidden';
    }
});
</script>";

} elseif (in_array($hotspot, $pagehotspot) || in_array($ppp, $pageppp) || in_array($report, $pagereport) || $sys == "scheduler") {
echo '
<script>
$(document).ready(function(){
  makeAllSortable();
  $("#filterTable").on("keyup", function() {
    var value = $(this).val().toLowerCase();
    $("#dataTable tbody tr").filter(function() {
      $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
    });
  });
});

</script>
';
}
}
?>
</body>
</html>
