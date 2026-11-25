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
error_reporting(0);
require_once __DIR__ . '/include/security.php';
ngwConfigureSecureSession();
ngwSendNoCacheHeaders();
@session_start();

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


// lang & theme early switcher
if (isset($_GET['setlang']) && $_GET['setlang'] !== '') {
  include('./settings/setlang.php');
}
if (isset($_GET['set-theme']) && $_GET['set-theme'] !== '') {
  include('./settings/settheme.php');
}

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
if ($_SESSION['theme'] == "") {
    $theme = $theme;
    $themecolor = $themecolor;
  } else {
    $theme = $_SESSION['theme'];
    $themecolor = $_SESSION['themecolor'];
}


// load config
include_once('./include/headhtml.php');
clearstatcache(true, './include/config.php');
if (function_exists('opcache_invalidate')) {
  @opcache_invalidate('./include/config.php', true);
  $realCfg = @realpath('./include/config.php');
  if ($realCfg) {
    @opcache_invalidate($realCfg, true);
  }
}
include('./include/config.php');
include('./include/readcfg.php');

include_once('./lib/routeros_api.class.php');
include_once('./lib/formatbytesbites.php');
include_once('./include/ros_version.php');
include_once('./include/reseller_acl.php');
$reqPathOnly = (string)parse_url((string)$url, PHP_URL_PATH);
$isBareAdminEntry = (empty($id) && empty($session) && empty($router) && (substr($reqPathOnly, -1) === '/' || substr($reqPathOnly, -4) === '.php'));
$isLoginRoute = ($id == "login" || ($isBareAdminEntry && !isset($_SESSION["mikhmon"])));
enforceMikhmonSessionSecurity($isLoginRoute || $id == "logout");
if (!$isLoginRoute && isset($_SESSION["mikhmon"]) && function_exists('ngwCsrfToken')) {
  ngwCsrfToken('ngw_admin_csrf');
}
?>
    
<?php
if ($isLoginRoute) {

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
      ngwRecordActivity('login_failed', array(
        'fault' => 'CSRF Token Expired',
        'target' => $user !== '' ? $user : 'unknown',
        'error' => 'Login CSRF security token expired or invalid (page not reloaded or cookies blocked)',
      ), $user !== '' ? $user : 'unknown', 'unknown');
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
        'fault' => '15m Login Lockout',
        'target' => $user !== '' ? $user : 'unknown',
        'error' => 'Login throttled after repeated failed attempts (Wait 15 minutes)',
      ), $user !== '' ? $user : 'unknown', 'unknown');
      $alertTitle = $isBnLogin ? 'সতর্কবার্তা!' : 'Alert!';
      $error = '<div style="width: 100%; padding:5px 0px 5px 0px; border-radius:5px;" class="bg-danger"><i class="fa fa-ban"></i> ' . $alertTitle . '<br>' . htmlspecialchars($errText) . '</div>';
    } else {
      $auth = authenticateMikhmonUser($user, $pass, $useradm, decrypt($passadm));
      if (!$auth['authenticated']) {
        ngwRecordLoginFailure($user);
        $failReason = !empty($auth['error_reason']) ? (string)$auth['error_reason'] : ('Invalid username or password for "' . $user . '"');
        $failFault = !empty($auth['fault']) ? (string)$auth['fault'] : 'Login Failed';
        $attemptedRole = !empty($auth['attempted_role']) ? (string)$auth['attempted_role'] : 'unknown';
        ngwRecordActivity('login_failed', array(
          'fault' => $failFault,
          'target' => $user !== '' ? $user : '(empty)',
          'error' => $failReason,
        ), $user !== '' ? $user : 'unknown', $attemptedRole);
      }
    }
    if (!empty($auth['authenticated'])) {
      ngwClearLoginFailures($user);
      ngwRegenerateAuthenticatedSession();
      $_SESSION["mikhmon"] = $user;
      $_SESSION["mikhmon_role"] = $auth['role'];
      $_SESSION["mikhmon_admin_id"] = ($auth['role'] === 'admin' && !empty($auth['id'])) ? (string)$auth['id'] : '';
      $_SESSION["mikhmon_admin_role"] = ($auth['role'] === 'admin')
        ? (isset($auth['admin_role']) && $auth['admin_role'] === 'admin' ? 'admin' : 'admin_store')
        : '';
      $_SESSION["mikhmon_reseller_id"] = isset($auth['id']) ? $auth['id'] : '';
      $_SESSION["mikhmon_permission_mode"] = isset($auth['permission_mode']) ? $auth['permission_mode'] : '';
      $_SESSION["mikhmon_template_code"] = isset($auth['template_code']) ? $auth['template_code'] : '';
      $_SESSION["mikhmon_assigned_template"] = isset($auth['assigned_template']) ? $auth['assigned_template'] : 'default';
      $_SESSION["mikhmon_logo_filename"] = isset($auth['logo_filename']) ? $auth['logo_filename'] : '';
      $_SESSION["mikhmon_routers"] = $auth['allowed_routers'];
      $_SESSION["mikhmon_permissions"] = $auth['permissions'];
      $_SESSION["mikhmon_last_activity"] = time();
      $_SESSION["mikhmon_login_handshake"] = 1;
      unset($_SESSION['login_lang'], $_SESSION['login_theme'], $_SESSION['lang_owner'], $_SESSION['theme_owner']);
      if (function_exists('ngwResolveCurrentUserLang')) {
        ngwResolveCurrentUserLang(true);
      }
      if (function_exists('ngwResolveCurrentUserTheme')) {
        ngwResolveCurrentUserTheme(true);
      }
      ngwRegisterOrTouchActiveSession(array(
        'is_new_login' => true,
        'page_label' => 'Router List',
      ));
      ngwRecordActivity('login', array(
        'status' => 'Login Successful',
      ));
      $ngwPostLoginUi = function_exists('ngwResolveActiveUiStyle') ? ngwResolveActiveUiStyle() : 'classic';
      if (function_exists('ngwRememberBrowserUiCookie')) {
        ngwRememberBrowserUiCookie($ngwPostLoginUi);
      }
      $ngwPostLoginCookieJs = function_exists('ngwGetBrowserUiCookieJs') ? ngwGetBrowserUiCookieJs($ngwPostLoginUi) : '';
      $forceDefaultCredentialChange = ($auth['role'] === 'admin' && $user === 'mikhmon' && $pass === '123');
      if ($forceDefaultCredentialChange) {
        $_SESSION['mikhmon_force_password_change'] = 1;
      }
      session_write_close();
      if ($forceDefaultCredentialChange) {
        echo "<script>" . $ngwPostLoginCookieJs . "try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location='./admin.php?id=admin-settings'</script>";
        exit;
      }
      if (isResetOnlyReseller()) {
        echo "<script>" . $ngwPostLoginCookieJs . "try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location='./admin.php?id=reset-voucher'</script>";
      } else {
        echo "<script>" . $ngwPostLoginCookieJs . "try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location='./admin.php?id=sessions'</script>";
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
  if (!ngwValidateCsrf(isset($_GET['csrf']) ? (string)$_GET['csrf'] : '', 'ngw_admin_csrf')) {
    session_write_close();
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
  }
  $admUser = (string)$_SESSION['mikhmon_impersonator_admin'];
  $returnTo = (!empty($_SESSION['mikhmon_impersonator_return_to']) && $_SESSION['mikhmon_impersonator_return_to'] === 'admin-settings')
    ? 'admin-settings'
    : 'resellers';
  $admRec = findAdminUserByIdOrUsername($admUser);
  if ($admRec === null) {
    unset($_SESSION['mikhmon_impersonator_admin'], $_SESSION['mikhmon_impersonator_return_to']);
    session_write_close();
    echo "<script>window.location='./admin.php?id=logout'</script>";
    exit;
  }
  $oldTok = isset($_SESSION['ngw_session_token']) ? (string)$_SESSION['ngw_session_token'] : '';
  unset($_SESSION['mikhmon_impersonator_admin'], $_SESSION['mikhmon_impersonator_return_to']);
  ngwRegenerateAuthenticatedSession($oldTok);
  $_SESSION["mikhmon"] = !empty($admRec['username']) ? (string)$admRec['username'] : $admUser;
  $_SESSION["mikhmon_role"] = 'admin';
  $_SESSION["mikhmon_admin_id"] = !empty($admRec['id']) ? (string)$admRec['id'] : 'admin';
  $_SESSION["mikhmon_admin_role"] = (isset($admRec['admin_role']) && $admRec['admin_role'] === 'admin') ? 'admin' : 'super_admin';
  $_SESSION["mikhmon_reseller_id"] = $_SESSION["mikhmon_admin_id"];
  $_SESSION["mikhmon_permission_mode"] = ($_SESSION["mikhmon_admin_role"] === 'super_admin') ? 'Super Admin' : 'Admin';
  $_SESSION["mikhmon_template_code"] = 'GLOBAL';
  $_SESSION["mikhmon_assigned_template"] = 'default';
  $_SESSION["mikhmon_logo_filename"] = '';
  $_SESSION["mikhmon_routers"] = array('*');
  $_SESSION["mikhmon_permissions"] = resolveEffectiveAdminPermissions($admRec);
  $_SESSION["mikhmon_last_activity"] = time();
  $_SESSION["mikhmon_login_handshake"] = 1;
  unset($_SESSION['lang'], $_SESSION['theme'], $_SESSION['lang_owner'], $_SESSION['theme_owner'], $GLOBALS['NGW_ADMIN_SYNCED'], $GLOBALS['NGW_CACHE_UI_SETTINGS']);
  if (function_exists('ngwResolveCurrentUserLang')) {
    ngwResolveCurrentUserLang(true);
  }
  if (function_exists('ngwResolveCurrentUserTheme')) {
    ngwResolveCurrentUserTheme(true);
  }
  if ($returnTo === 'admin-settings' && $_SESSION["mikhmon_admin_role"] !== 'super_admin') {
    $returnTo = 'sessions';
  }
  ngwRegisterOrTouchActiveSession(array(
    'is_new_login' => true,
    'page_label' => ($returnTo === 'admin-settings' ? 'Admin Management' : 'Reseller Management'),
  ));
  session_write_close();
  echo "<script>try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location='./admin.php?id=" . $returnTo . "'</script>";
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
} elseif (!empty($session) && $session !== "new" && strpos((string)$session, 'new-') !== 0 && !canAccessRouterSession($session)) {
  session_write_close();
  echo "<script>window.location='./admin.php?id=sessions'</script>";
  exit;
} elseif ($isBareAdminEntry) {
  session_write_close();
  echo "<script>window.location='./admin.php?id=sessions'</script>";

} elseif ($id == "sessions") {
  $_SESSION["connect"] = "";
  include_once('./include/menu.php');
  include_once('./settings/sessions.php');
  session_write_close();
} elseif ($id == "resellers") {
  if (!isMikhmonAdmin() || (!hasResellerPermission('view_resellers') && !hasResellerPermission('manage_resellers'))) {
    session_write_close();
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
  }
  include_once('./include/menu.php');
  session_write_close();
  include_once('./settings/resellers.php');
} elseif ($id == "activity") {
  if (!isMikhmonAdmin() || !hasResellerPermission('view_logs')) {
    session_write_close();
    http_response_code(403);
    exit('Access denied.');
  }
  include_once('./include/menu.php');
  include_once('./settings/activity.php');
  session_write_close();
} elseif ($id == "active-sessions") {
  if (!isMikhmonAdmin() || !hasResellerPermission('view_active')) {
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
  if (!isMikhmonAdminStore()) {
    session_write_close();
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
  }
  include_once('./include/menu.php');
  include_once('./settings/admin_settings.php');
  session_write_close();
} elseif ($id == "ui-settings") {
  include_once('./include/ui_settings.php');
  if (!function_exists('ngwCanCurrentUserManageUi') || !ngwCanCurrentUserManageUi()) {
    session_write_close();
    http_response_code(403);
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
  }
  include_once('./include/menu.php');
  include_once('./settings/ui_settings.php');
  session_write_close();
} elseif ($id == "vpn") {
  if (!isMikhmonAdmin() || (!isMikhmonAdminStore() && !hasResellerPermission('manage_vpn'))) {
    session_write_close();
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
  }
  include_once('./include/menu.php');
  session_write_close();
  include_once('./vpn/vpn_hub.php');
} elseif ($id == "system-update") {
  if (!isMikhmonAdmin() || (!isMikhmonAdminStore() && !hasResellerPermission('manage_global_security'))) {
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
    var _ngwSessEl = document.getElementById("sessname");
    if (_ngwSessEl) {
      _ngwSessEl.onkeypress = function(e) {
        var code = e.which || e.keyCode;
        if (!code || code < 32) return true;
        var chr = String.fromCharCode(code);
        if ("\x27\x22<>\\\\".indexOf(chr) >= 0) return false;
      };
    }
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
  $dispSessionForLog = function_exists('getRouterDisplaySessionName') ? getRouterDisplaySessionName($session) : $session;
  $routerDisplayLabel = (!empty($hotspotname) && $hotspotname !== $dispSessionForLog) ? ($hotspotname . ' (' . $dispSessionForLog . ')') : $dispSessionForLog;
  if ($isConnected){
    $_SESSION["connect"] = ($langid === 'bn') ? "<b class='text-green'>সংযুক্ত (Connected)</b>" : "<b class='text-green'>Connected</b>";
    ngwRecordActivity('router_connected', array(
      'router' => $routerDisplayLabel,
      'host' => (string)$iphost,
    ));
    session_write_close();
    echo "<script>window.location='" . $targetAfterConnect . "'</script>";
  } else {
    $_SESSION["connect"] = ($langid === 'bn') ? "<b class='text-red'>সংযুক্ত নয় (Not Connected)</b>" : "<b class='text-red'>Not Connected</b>";
    $connErrReason = !empty($API->last_error)
      ? (string)$API->last_error
      : ('RouterOS API connection failed for ' . $iphost);
    $diagInfo = function_exists('ngwDiagnoseRouterConnectFailure')
      ? ngwDiagnoseRouterConnectFailure($session, $iphost, $userhost, decrypt($passwdhost), $connErrReason, 'connect')
      : array('fault' => 'Router Connection Failed', 'error' => $connErrReason, 'host' => (string)$iphost, 'user' => (string)$userhost);
    ngwRecordActivity('router_connect_failed', array(
      'router' => $routerDisplayLabel,
      'host' => $diagInfo['host'],
      'user' => isset($diagInfo['user']) ? $diagInfo['user'] : (string)$userhost,
      'fault' => $diagInfo['fault'],
      'error' => $diagInfo['error'],
    ));
    session_write_close();
    $nl = '\n';
    $portHint = 'RouterOS API (port 8728; API service must be enabled)';
    if ($langid === 'bn') {
      echo "<script>alert('NextGan WiFi Solution সংযুক্ত হতে পারেনি!".$nl."অনুগ্রহ করে মাইক্রোটিকের IP, Username, Password এবং API সার্ভিস (পোর্ট ৮৭২৮) চালু আছে কিনা যাচাই করুন।')</script>";
    } elseif ($currency == in_array($currency, $cekindo['indo'])) {
      echo "<script>alert('NextGan WiFi Solution not connected!".$nl."Silakan periksa kembali IP, User, Password dan port ".$portHint." harus enable.')</script>";
    }else{
      echo "<script>alert('NextGan WiFi Solution not connected!".$nl."Please check the IP, Username, Password and ensure MikroTik ".$portHint." is enabled.')</script>";
    }
    if($c == "settings"){
      echo "<script>window.location=" . json_encode('./admin.php?id=settings&session=' . rawurlencode((string)$session), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . "</script>";
    }else{
      echo "<script>window.location='./admin.php?id=sessions'</script>";
    }
  }
} elseif ($id == "uplogo"  && !empty($session)) {
  if (!isMikhmonAdminStore() && !hasResellerPermission('edit_own_template') && !hasResellerPermission('save_own_template') && !hasResellerPermission('manage_global_templates')) {
    requireResellerPermission('edit_own_template', './admin.php?id=sessions');
  }
  if (!canAccessRouterSession($session)) {
    session_write_close();
    echo "<script>window.location.replace('./admin.php?id=sessions')</script>";
    exit;
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
  if (!canAccessRouterSession($session) || !ngwValidateCsrf(isset($_REQUEST['csrf']) ? (string)$_REQUEST['csrf'] : '', 'ngw_admin_csrf')) {
    session_write_close();
    echo "<script>window.location.replace('./admin.php?id=sessions')</script>";
    exit;
  }
  $delDispName = function_exists('getRouterDisplaySessionName') ? getRouterDisplaySessionName($session) : (string)$session;
  $delHsName = (isset($data[$session][4]) && strpos($data[$session][4], '%') !== false)
    ? explode('%', $data[$session][4])[1]
    : '';
  $delNoticeLabel = ($delHsName !== '' && $delHsName !== $delDispName)
    ? ($delDispName . ' - ' . $delHsName)
    : $delDispName;
  if (is_file('./include/data_recovery.php')) {
    include_once('./include/data_recovery.php');
  }
  if (function_exists('ngwDeleteRouterSessionFromConfig')) {
    ngwDeleteRouterSessionFromConfig($session, './include/config.php');
  } else {
    $fc = is_file("./include/config.php") ? file("./include/config.php") : array();
    $f = fopen("./include/config.php", "w");
    if ($f) {
      $q = "'";
      $rem = '$data['.$q.$session.$q.']';
      foreach ($fc as $line) {
        if (!strstr($line, $rem))
          fputs($f, $line);
      }
      fclose($f);
    }
    if (function_exists('removeRouterOwnerMeta')) {
      removeRouterOwnerMeta($session);
    }
  }
  ngwRecordActivity('router_deleted', array('router' => $delNoticeLabel, 'session_key' => (string)$session));
  $_SESSION['router_settings_notice'] = (isset($langid) && $langid === 'bn')
    ? ('রাউটার সেশন (' . $delNoticeLabel . ') সফলভাবে ডিলিট করা হয়েছে।')
    : ('Router session (' . $delNoticeLabel . ') has been deleted successfully.');
  session_write_close();
  echo "<script>window.location.replace('./admin.php?id=sessions')</script>";
  exit;
} elseif ($id == "about") {
  include_once('./include/menu.php');
  session_write_close();
  include_once('./include/about.php');
} elseif ($id == "logout") {
  $ngwLogoutUi = function_exists('ngwResolveActiveUiStyle') ? ngwResolveActiveUiStyle() : 'classic';
  if (function_exists('ngwRememberBrowserUiCookie')) {
    ngwRememberBrowserUiCookie($ngwLogoutUi);
  }
  $ngwLogoutCookieJs = function_exists('ngwGetBrowserUiCookieJs') ? ngwGetBrowserUiCookieJs($ngwLogoutUi) : '';
  ngwRecordActivity('logout', array('status' => 'User Logged Out'));
  ngwRemoveCurrentActiveSession();
  include_once('./include/menu.php');
  echo "<b class='cl-w'><i class='fa fa-circle-o-notch fa-spin' style='font-size:24px'></i> " . (($langid === 'bn') ? "লগআউট হচ্ছে..." : "Logout...") . "</b>";
  $_SESSION = array();
  @session_unset();
  @session_destroy();
  ngwClearSessionCookie();
  echo "<script>" . $ngwLogoutCookieJs . "try{sessionStorage.clear();}catch(e){} window.location='./admin.php?id=login'</script>";
} elseif ($id == "remove-logo" && $logo != ""  && !empty($session)) {
  if (!isMikhmonAdminStore() && !hasResellerPermission('manage_router_settings') && !hasResellerPermission('edit_own_template') && !hasResellerPermission('save_own_template')) {
    requireResellerPermission('manage_router_settings', './admin.php?id=sessions');
  }
  if (!ngwValidateCsrf(isset($_REQUEST['csrf']) ? (string)$_REQUEST['csrf'] : '', 'ngw_admin_csrf')) {
    session_write_close();
    echo "<script>window.location=" . json_encode('./admin.php?id=uplogo&session=' . rawurlencode((string)$session), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . "</script>";
    exit;
  }
  include_once('./include/menu.php');
  session_write_close();
  $logoBase = basename((string)$logo);
  if (preg_match('/^logo-([a-zA-Z0-9_\-]+)\.(png|jpg|jpeg|webp)$/i', $logoBase, $logoMatches)) {
    $logoTargetSession = isset($logoMatches[1]) ? (string)$logoMatches[1] : '';
    if (isMikhmonAdminStore() || ($logoTargetSession !== '' && canAccessRouterSession($logoTargetSession))) {
      $logopath = "./img/";
      $remlogo = $logopath . $logoBase;
      if (is_file($remlogo)) {
        @unlink($remlogo);
      }
      $imagesLogo = "./images/" . $logoBase;
      if (is_file($imagesLogo)) {
        @unlink($imagesLogo);
      }
    }
  }
  echo "<script>window.location=" . json_encode('./admin.php?id=uplogo&session=' . rawurlencode((string)$session), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . "</script>";
} elseif ($id == "editor") {
  if (!isMikhmonAdminStore() && !hasResellerPermission('manage_global_templates') && !hasResellerPermission('edit_own_template') && !hasResellerPermission('save_own_template') && !hasResellerPermission('preview_assigned_template') && !hasResellerPermission('use_assigned_template') && !hasResellerPermission('print_voucher')) {
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
</div>
</div>
<script src="js/mikhmon-ui.<?= $theme; ?>.min.js"></script>
<script src="js/mikhmon.js?v=ngw2.5"></script>
<script>
(function(){
  try {
    if (window.jQuery) {
      $(".main-container").show();
      $("#loading").hide();
    }
  } catch(e) {}
  var l = document.getElementById("loading");
  if (l) l.style.display = "none";
  var c = document.querySelectorAll(".main-container");
  for (var i = 0; i < c.length; i++) {
    c[i].style.display = "block";
  }
})();
</script>
</body>
</html>

