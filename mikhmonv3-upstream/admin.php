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

ob_start("ob_gzhandler");

// check url
$url = $_SERVER['REQUEST_URI'];

// load session MikroTik
$session = $_GET['session'];
$id = $_GET['id'];
$c = $_GET['c'];
$router = $_GET['router'];
$logo = $_GET['logo'];

$ids = array(
  "uplogo",
  "settings",
);


// lang
include('./lang/isocodelang.php');
include('./include/lang.php');
$selected_langid = (isset($langid) && $langid !== '') ? $langid : 'en';
include('./lang/en.php');
$langid = $selected_langid;
if ($langid !== 'en' && is_file('./lang/'.$langid.'.php')) {
  include('./lang/'.$langid.'.php');
}


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


// load config
include_once('./include/headhtml.php');
include('./include/config.php');
include('./include/readcfg.php');

include_once('./lib/routeros_api.class.php');
include_once('./lib/formatbytesbites.php');
include_once('./include/ros_version.php');
include_once('./include/reseller_acl.php');
enforceMikhmonSessionSecurity($id == "login" || $id == "logout" || substr($url, -1) == "p");
?>
    
<?php
if ($id == "login" || substr($url, -1) == "p") {

  $isBnLogin = (isset($langid) && $langid === 'bn');
  $loginCsrfToken = ngwCsrfToken('ngw_login_csrf');
  if (isset($_GET['reason']) && $_GET['reason'] === 'force_logout' && !isset($_POST['login'])) {
    $forceMsg = $isBnLogin
      ? 'অ্যাডমিনিস্ট্রেটর আপনার সেশনটি লগআউট করেছেন। অনুগ্রহ করে আবার ইউজারনেম ও পাসওয়ার্ড দিয়ে লগইন করুন।'
      : 'Your session was logged out by an Administrator. Please enter your username and password to sign in again.';
    $error = '<div style="width: 100%; padding:6px 4px; border-radius:5px;" class="bg-warning"><i class="fa fa-info-circle"></i> ' . htmlspecialchars($forceMsg) . '</div>';
  }
  if (isset($_POST['login'])) {
    $user = isset($_POST['user']) ? trim((string)$_POST['user']) : '';
    $pass = isset($_POST['pass']) ? (string)$_POST['pass'] : '';
    if (!ngwValidateCsrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '', 'ngw_login_csrf')) {
      $auth = array('authenticated' => false);
      $errText = $isBnLogin
        ? 'সিকিউরিটি টোকেনের মেয়াদ শেষ বা অবৈধ। পেজটি রিলোড করে আবার চেষ্টা করুন।'
        : 'Security token expired or invalid. Reload the login page and try again.';
      $alertTitle = $isBnLogin ? 'সতর্কবার্তা!' : 'Alert!';
      $error = '<div style="width: 100%; padding:5px 0px 5px 0px; border-radius:5px;" class="bg-danger"><i class="fa fa-ban"></i> ' . $alertTitle . '<br>' . htmlspecialchars($errText) . '</div>';
    } elseif (ngwLoginIsThrottled($user)) {
      $auth = array('authenticated' => false);
      $errText = $isBnLogin
        ? 'অতিরিক্ত ভুল লগইন চেষ্টার কারণে ১৫ মিনিট অপেক্ষা করে আবার চেষ্টা করুন।'
        : 'Too many failed login attempts. Wait 15 minutes before trying again.';
      ngwRecordActivity('login_failed', array(
        'error' => 'Login throttled (too many failed attempts)',
      ), $user !== '' ? $user : 'unknown', 'unknown');
      $alertTitle = $isBnLogin ? 'সতর্কবার্তা!' : 'Alert!';
      $error = '<div style="width: 100%; padding:5px 0px 5px 0px; border-radius:5px;" class="bg-danger"><i class="fa fa-ban"></i> ' . $alertTitle . '<br>' . htmlspecialchars($errText) . '</div>';
    } else {
      $auth = authenticateMikhmonUser($user, $pass, $useradm, decrypt($passadm));
      if (!$auth['authenticated']) {
        ngwRecordLoginFailure($user);
        $failReason = !empty($auth['error_reason']) ? (string)$auth['error_reason'] : ('Invalid username or password for "' . $user . '"');
        $attemptedRole = !empty($auth['attempted_role']) ? (string)$auth['attempted_role'] : 'unknown';
        ngwRecordActivity('login_failed', array(
          'error' => $failReason,
        ), $user !== '' ? $user : 'unknown', $attemptedRole);
      }
    }
    if (!empty($auth['authenticated'])) {
      ngwClearLoginFailures($user);
      $_SESSION["mikhmon"] = $user;
      $_SESSION["mikhmon_role"] = $auth['role'];
      $_SESSION["mikhmon_admin_id"] = ($auth['role'] === 'admin' && !empty($auth['id'])) ? (string)$auth['id'] : '';
      $_SESSION["mikhmon_reseller_id"] = isset($auth['id']) ? $auth['id'] : '';
      $_SESSION["mikhmon_permission_mode"] = isset($auth['permission_mode']) ? $auth['permission_mode'] : '';
      $_SESSION["mikhmon_template_code"] = isset($auth['template_code']) ? $auth['template_code'] : '';
      $_SESSION["mikhmon_assigned_template"] = isset($auth['assigned_template']) ? $auth['assigned_template'] : 'default';
      $_SESSION["mikhmon_logo_filename"] = isset($auth['logo_filename']) ? $auth['logo_filename'] : '';
      $_SESSION["mikhmon_routers"] = $auth['allowed_routers'];
      $_SESSION["mikhmon_permissions"] = $auth['permissions'];
      $_SESSION["mikhmon_last_activity"] = time();
      $_SESSION["mikhmon_login_handshake"] = 1;
      $_SESSION["ngw_session_token"] = bin2hex(random_bytes(16));
      unset($_SESSION['login_lang'], $_SESSION['login_theme']);
      if (function_exists('ngwResolveCurrentUserLang')) {
        ngwResolveCurrentUserLang();
      }
      if (function_exists('ngwResolveCurrentUserTheme')) {
        ngwResolveCurrentUserTheme();
      }
      ngwRegisterOrTouchActiveSession(array(
        'is_new_login' => true,
        'page_label' => 'Router List',
      ));
      ngwRecordActivity('login', array(
        'status' => 'Login Successful',
      ));
      $forceDefaultCredentialChange = ($auth['role'] === 'admin' && $user === 'mikhmon' && $pass === '123');
      if ($forceDefaultCredentialChange) {
        $_SESSION['mikhmon_force_password_change'] = 1;
      }
      session_write_close();
      if ($forceDefaultCredentialChange) {
        echo "<script>try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location='./admin.php?id=admin-settings'</script>";
        exit;
      }
      if (isResetOnlyReseller()) {
        echo "<script>try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location='./admin.php?id=reset-voucher'</script>";
      } else {
        echo "<script>try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location='./admin.php?id=sessions'</script>";
      }
      exit;
    } else {
      if (!isset($error) || $error === '') {
        $errText = isset($auth['error']) ? $auth['error'] : ($isBnLogin ? 'ইউজারনেম বা পাসওয়ার্ড সঠিক নয়।' : 'Invalid username or password.');
        if ($isBnLogin && $errText === 'Invalid username or password.') {
          $errText = 'ইউজারনেম বা পাসওয়ার্ড সঠিক নয়।';
        }
        $alertTitle = $isBnLogin ? 'সতর্কবার্তা!' : 'Alert!';
        $error = '<div style="width: 100%; padding:5px 0px 5px 0px; border-radius:5px;" class="bg-danger"><i class="fa fa-ban"></i> ' . $alertTitle . '<br>' . htmlspecialchars($errText) . '</div>';
      }
    }
  }
  
  session_write_close();
  include_once('./include/login.php');
} elseif (!isset($_SESSION["mikhmon"])) {
  session_write_close();
  echo "<script>window.location='./admin.php?id=login'</script>";
} elseif (!empty($_SESSION['mikhmon_force_password_change']) && !in_array($id, array('admin-settings', 'logout'), true)) {
  session_write_close();
  echo "<script>window.location='./admin.php?id=admin-settings'</script>";
  exit;
} elseif ($id == "return-to-admin" && !empty($_SESSION['mikhmon_impersonator_admin'])) {
  // Rotate the session ID when returning from reseller impersonation to admin.
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
  }
  $admUser = $_SESSION['mikhmon_impersonator_admin'];
  unset($_SESSION['mikhmon_impersonator_admin']);
  $_SESSION["mikhmon"] = $admUser;
  $_SESSION["mikhmon_role"] = 'admin';
  $_SESSION["mikhmon_reseller_id"] = 'admin';
  $_SESSION["mikhmon_permission_mode"] = 'Admin';
  $_SESSION["mikhmon_template_code"] = 'GLOBAL';
  $_SESSION["mikhmon_assigned_template"] = 'default';
  $_SESSION["mikhmon_logo_filename"] = '';
  $_SESSION["mikhmon_routers"] = array('*');
  $_SESSION["mikhmon_permissions"] = getDefaultPermissionMap(true);
  $_SESSION["mikhmon_last_activity"] = time();
  $_SESSION["mikhmon_login_handshake"] = 1;
  if (function_exists('ngwResolveCurrentUserLang')) {
    ngwResolveCurrentUserLang();
  }
  if (function_exists('ngwResolveCurrentUserTheme')) {
    ngwResolveCurrentUserTheme();
  }
  session_write_close();
  echo "<script>try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location='./admin.php?id=resellers'</script>";
  exit;
} elseif (isResetOnlyReseller() && $id !== "reset-voucher" && $id !== "my-profile" && $id !== "logout") {
  session_write_close();
  echo "<script>window.location='./admin.php?id=reset-voucher'</script>";
  exit;
} elseif ($id == "reset-voucher") {
  requireResellerPermission('reset_voucher', './admin.php?id=sessions');
  include_once('./include/menu.php');
  session_write_close();
  include_once('./hotspot/resetvoucher.php');
} elseif (!empty($session) && $session !== "new" && !canAccessRouterSession($session)) {
  session_write_close();
  echo "<script>window.location='./admin.php?id=sessions'</script>";
  exit;
} elseif (substr($url, -1) == "/" || substr($url, -4) == ".php") {
  session_write_close();
  echo "<script>window.location='./admin.php?id=sessions'</script>";

} elseif ($id == "sessions") {
  $_SESSION["connect"] = "";
  include_once('./include/menu.php');
  session_write_close();
  include_once('./settings/sessions.php');
} elseif ($id == "resellers") {
  if (!isMikhmonAdmin()) {
    session_write_close();
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
  }
  include_once('./include/menu.php');
  session_write_close();
  include_once('./settings/resellers.php');
} elseif ($id == "activity") {
  if (!isMikhmonAdmin()) {
    session_write_close();
    http_response_code(403);
    exit('Access denied.');
  }
  include_once('./include/menu.php');
  include_once('./settings/activity.php');
  session_write_close();
} elseif ($id == "active-sessions") {
  if (!isMikhmonAdmin()) {
    session_write_close();
    http_response_code(403);
    exit('Access denied.');
  }
  include_once('./include/menu.php');
  include_once('./settings/active_sessions.php');
  session_write_close();
} elseif ($id == "my-profile") {
  include_once('./include/menu.php');
  include_once('./settings/my_profile.php');
  session_write_close();
} elseif ($id == "admin-settings") {
  if (!isMikhmonAdmin()) {
    session_write_close();
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
  }
  include_once('./include/menu.php');
  include_once('./settings/admin_settings.php');
  session_write_close();
} elseif ($id == "vpn") {
  if (!isMikhmonAdmin()) {
    session_write_close();
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
  }
  include_once('./include/menu.php');
  session_write_close();
  include_once('./vpn/vpn_hub.php');
} elseif ($id == "system-update") {
  if (!isMikhmonAdmin()) {
    session_write_close();
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
  }
  if (!isset($_GET['download_action'])) {
    include_once('./include/menu.php');
  }
  include_once('./settings/system_update.php');
} elseif ($id == "settings" && !empty($session) || $id == "settings" && !empty($router)) {
  requireResellerPermission('manage_router_settings', './admin.php?id=sessions');
  include_once('./include/menu.php');
  include_once('./settings/settings.php');
  echo '
  <script type="text/javascript">
    document.getElementById("sessname").onkeypress = function(e) {
    var chr = String.fromCharCode(e.which);
    if (" _!@#$%^&*()+=;|?,~".indexOf(chr) >= 0)
        return false;
    };
    </script>';
} elseif ($id == "connect"  && !empty($session)) {
  ini_set("max_execution_time",30);  
  include_once('./include/menu.php');
  $targetAfterConnect = getResellerDefaultFallbackUrl($session);
  session_write_close();
  $API = new RouterosAPI();
  $API->debug = false;
  $isConnected = $API->connect($iphost, $userhost, decrypt($passwdhost));
  @session_start();
  $routerDisplayLabel = (!empty($hotspotname) && $hotspotname !== $session) ? ($hotspotname . ' (' . $session . ')') : $session;
  if ($isConnected){
    $_SESSION["connect"] = "<b class='text-green'>Connected</b>";
    ngwRecordActivity('router_connected', array(
      'router' => $routerDisplayLabel,
      'host' => (string)$iphost,
    ));
    session_write_close();
    echo "<script>window.location='" . $targetAfterConnect . "'</script>";
  } else {
    $_SESSION["connect"] = "<b class='text-red'>Not Connected</b>";
    $connErrReason = !empty($API->last_error)
      ? (string)$API->last_error
      : ('RouterOS API connection failed for ' . $iphost);
    ngwRecordActivity('router_connect_failed', array(
      'router' => $routerDisplayLabel,
      'host' => (string)$iphost,
      'error' => $connErrReason,
    ));
    session_write_close();
    $nl = '\n';
    $portHint = 'RouterOS API (port 8728; API service must be enabled)';
    if ($currency == in_array($currency, $cekindo['indo'])) {
      echo "<script>alert('NextGan WiFi Solution not connected!".$nl."Silakan periksa kembali IP, User, Password dan port ".$portHint." harus enable.')</script>";
    }else{
      echo "<script>alert('NextGan WiFi Solution not connected!".$nl."Please check the IP, Username, Password and ensure MikroTik ".$portHint." is enabled.')</script>";
    }
    if($c == "settings"){
      echo "<script>window.location='./admin.php?id=settings&session=" . $session . "'</script>";
    }else{
      echo "<script>window.location='./admin.php?id=sessions'</script>";
    }
  }
} elseif ($id == "uplogo"  && !empty($session)) {
  if (!isMikhmonAdmin() && !hasResellerPermission('edit_own_template') && !hasResellerPermission('save_own_template')) {
    requireResellerPermission('edit_own_template', './admin.php?id=sessions');
  }
  include_once('./include/menu.php');
  session_write_close();
  include_once('./settings/uplogo.php');
} elseif ($id == "reboot"  && !empty($session)) {
  requireResellerPermission('reboot_shutdown', './?session=' . $session);
  session_write_close();
  include_once('./process/reboot.php');
} elseif ($id == "shutdown"  && !empty($session)) {
  requireResellerPermission('reboot_shutdown', './?session=' . $session);
  session_write_close();
  include_once('./process/shutdown.php');
} elseif ($id == "remove-session" && $session != "") {
  requireResellerPermission('manage_router_settings', './admin.php?id=sessions');
  include_once('./include/menu.php');
  session_write_close();
  $fc = file("./include/config.php" );
  $f = fopen("./include/config.php", "w");
  $q = "'";
  $rem = '$data['.$q.$session.$q.']';
  foreach ($fc as $line) {
    if (!strstr($line, $rem))
      fputs($f, $line);
  }
  fclose($f);
  echo "<script>window.location='./admin.php?id=sessions'</script>";
} elseif ($id == "about") {
  include_once('./include/menu.php');
  session_write_close();
  include_once('./include/about.php');
} elseif ($id == "logout") {
  ngwRecordActivity('logout', array('status' => 'User Logged Out'));
  ngwRemoveCurrentActiveSession();
  include_once('./include/menu.php');
  echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> Logout...</b>";
  $_SESSION = array();
  @session_unset();
  @session_destroy();
  echo "<script>try{sessionStorage.clear();}catch(e){} window.location='./admin.php?id=login'</script>";
} elseif ($id == "remove-logo" && $logo != ""  && !empty($session)) {
  requireResellerPermission('manage_router_settings', './admin.php?id=sessions');
  include_once('./include/menu.php');
  session_write_close();
  $logopath = "./img/";
  $remlogo = $logopath . basename($logo);
  if (is_file($remlogo)) {
    @unlink($remlogo);
  }
  echo "<script>window.location='./admin.php?id=uplogo&session=" . $session . "'</script>";
} elseif ($id == "editor") {
  if (!isMikhmonAdmin() && !hasResellerPermission('edit_own_template') && !hasResellerPermission('save_own_template') && !hasResellerPermission('preview_assigned_template') && !hasResellerPermission('use_assigned_template') && !hasResellerPermission('print_voucher')) {
    requireResellerPermission('preview_assigned_template', './admin.php?id=sessions');
  }
  include_once('./include/menu.php');
  session_write_close();
  include_once('./settings/vouchereditor.php');
} elseif (empty($id)) {
  session_write_close();
  echo "<script>window.location='./admin.php?id=sessions'</script>";
} elseif(in_array($id, $ids) && empty($session)){
  session_write_close();
	echo "<script>window.location='./admin.php?id=sessions'</script>";
}
?>
<script src="js/mikhmon-ui.<?= $theme; ?>.min.js"></script>
<script src="js/mikhmon.js?v=ngw2.2"></script>
</body>
</html>
