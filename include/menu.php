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

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {

  include ('./include/version.php');
  include_once ('./include/reseller_acl.php');

  $btnmenuactive = "font-weight: bold;background-color: #f9f9f9; color: #000000";
  if ($hotspot == "dashboard" || substr(end(explode("/", $url)), 0, 8) == "?session") {
    $shome = "active";
    $mpage = $_dashboard;
  } elseif ($hotspot == "quick-print" || $hotspot == "list-quick-print") {
    $squick = "active";
    $mpage = $_quick_print;   
  } elseif ($hotspot == "users" || $userbyprofile != "" || $hotspot == "export-users" || $removehotspotuserbycomment != "" || $removehotspotuser != "" || $removehotspotusers != "" || $disablehotspotuser || $enablehotspotuser != "") {
    $susersl = "active";
    $susers = "active";
    $mpage = $_users;
    $umenu = "menu-open";
  } elseif ($hotspotuser == "add") {
    $sadduser = "active";
    $mpage = $_users;
    $susers = "active";
    $umenu = "menu-open";
  } elseif ($hotspotuser == "generate") {
    $sgenuser = "active";
    $mpage = $_users;
    $susers = "active";
    $umenu = "menu-open";
  } elseif ($hotspot == "profile-transfer") {
    $sysmenu = "active";
    $mpage = ($langid === 'bn') ? 'প্রোফাইল ট্রান্সফার' : 'Profile Transfer';
    $schmenu = "menu-open";
  } elseif ($userbyname != ""  || $resethotspotuser != "") {
    $susers = "active";
    $mpage = $_users;
    $umenu = "menu-open";
  } elseif ($hotspot == "user-profiles") {
    $suserprofiles = "active";
    $suserprof = "active";
    $mpage = $_user_profile;
    $upmenu = "menu-open";
  } elseif ($hotspot == "active" || $removeuseractive != "") {
    $sactive = "active";
    $mpage = $_hotspot_active;
    $hamenu = "menu-open";
  } elseif ($hotspot == "hosts" || $hotspot == "hostp" || $hotspot == "hosta" || $removehost != "") {
    $shosts = "active";
    $mpage = $_hosts;
    $hmenu = "menu-open";
  } elseif ($hotspot == "dhcp-leases") {
    $slease = "active";
    $mpage = $_dhcp_leases;
  } elseif ($minterface == "traffic-monitor") {
    $strafficmonitor = "active";
    $mpage = $_traffic_monitor;  
  } elseif ($hotspot == "ipbinding" || $hotspot == "binding" || $removeipbinding != "" || $enableipbinding != "" || $disableipbinding != "") {
    $sipbind = "active";
    $mpage = $_ip_bindings;
    $ibmenu = "menu-open";
  } elseif ($hotspot == "template-editor") {
    $ssett = "active";
    $teditor = "active";
    $mpage = $_template_editor;
    $settmenu = "menu-open";
  } elseif ($hotspot == "uplogo") {
    $ssett = "active";
    $uplogo = "active";
    $mpage = $_upload_logo;
    $settmenu = "menu-open";
  } elseif ($hotspot == "cookies" || $removecookie != "") {
    $scookies = "active";
    $mpage = $_hotspot_cookies;
    $cmenu = "menu-open";
  } elseif ($hotspot == "log") {
    $log = "active";
    $slog = "active";
    $mpage = $_hotspot_log;
    $lmenu = "menu-open";
  } elseif ($report == "userlog") {
    $log = "active";
    $sulog = "active";
    $mpage = $_user_log;
    $lmenu = "menu-open";
  } elseif ($pppoe == "users" || $pppoe == "secrets" || $pppoe == "add-user" || $pppoe == "user" || $pppoe == "addsecret" || $enable_pppoe_user != "" || $disable_pppoe_user != "" || $remove_pppoe_user != "") {
    $mpppoe = "active";
    if ($pppoe == "add-user" || $pppoe == "addsecret") {
      $spppoe_adduser = "active";
    } else {
      $spppoe_users = "active";
    }
    $mpage = "PPPoE Users";
    $pppoemenu = "menu-open";
  } elseif ($pppoe == "profiles" || $pppoe == "add-profile" || $pppoe == "profile" || $remove_pppoe_profile != "") {
    $mpppoe = "active";
    if ($pppoe == "add-profile") {
      $spppoe_addprofile = "active";
    } else {
      $spppoe_profiles = "active";
    }
    $mpage = "PPPoE Profiles";
    $pppoemenu = "menu-open";
  } elseif ($pppoe == "active" || $disconnect_pppoe_active != "") {
    $mpppoe = "active";
    $spppoe_active = "active";
    $mpage = isset($_pppoe_active) ? $_pppoe_active : "Online Users";
    $pppoemenu = "menu-open";
  } elseif ($pppoe == "reset") {
    $mpppoe = "active";
    $spppoe_reset = "active";
    $mpage = "Reset PPPoE";
    $pppoemenu = "menu-open";
  } elseif ($sys == "scheduler" || $enablesch != "" || $disablesch != "" || $removesch != "") {
    $sysmenu = "active";
    $ssch = "active";
    $mpage = $_system_scheduler;
    $schmenu = "menu-open";
  } elseif ($report == "selling" || $report == "resume-report") {
    $sselling = "active";
    $mpage = $_report;
  } elseif ($userprofile == "add") {
    $suserprof = "active";
    $sadduserprof = "active";
    $mpage = $_user_profile;
    $upmenu = "menu-open";
  } elseif ($userprofilebyname != "") {
    $suserprof = "active";
    $mpage = $_user_profile;
    $upmenu = "menu-open";
  } elseif ($hotspot == "users-by-profile") {
    $susersbp = "active";
    $mpage = $_vouchers;
  } elseif ($userbyname != "") {
    $mpage = $_users;
    $susers = "active";
  } elseif ($hotspot == "about") {
    $mpage = $_about;
    $sabout = "active";
  } elseif ($hotspot == "resellers" || $id == "resellers") {
    $sresellers = "active";
    $mpage = isset($_reseller) ? $_reseller : "Reseller";
  } elseif ($id == "sessions" || $id == "remove" || $router == "new") {
    $ssesslist = "active";
    $mpage = isset($_router_menu_label) ? $_router_menu_label : "Router";
  } elseif ($id == "admin-settings" || $hotspot == "admin-settings") {
    $sadmsettings = "active";
    $ssett = "active";
    $settmenu = "menu-open";
    $mpage = $_settings;
  } elseif ($id == "settings" && $session == "new") {
    $snsettings = "active";
    $mpage = $_add_router;
  } elseif ($id == "settings" || $id == "connect") {
    $ssettings = "active";
    $mpage = $_session_settings;
  } elseif ($id == "about") {
    $sabout = "active";
    $mpage = $_about;
  } elseif ($id == "uplogo") {
    $suplogo = "active";
    $mpage = $_upload_logo;
  } elseif ($id == "editor") {
    $seditor = "active";
    $mpage = $_template_editor;
  } elseif ($hotspot == "reset-voucher" || $id == "reset-voucher") {
    $sresetvoucher = "active";
    $mpage = isset($_reset_voucher) ? $_reset_voucher : "Reset Voucher";
  } elseif ($hotspot == "vpn" || $id == "vpn") {
    $svpn = "active";
    $mpage = isset($_vpn) ? $_vpn : "VPN";
  } elseif ($hotspot == "system-update" || $id == "system-update") {
    $ssysupdate = "active";
    $ssett = "active";
    $settmenu = "menu-open";
    $mpage = isset($_system_update) ? $_system_update : "System Update";
  } elseif ($id == "activity" || $hotspot == "activity") {
    $ssett = "active";
    $settmenu = "menu-open";
    $mpage = "Activity History";
  } elseif ($id == "active-sessions" || $hotspot == "active-sessions") {
    $ssett = "active";
    $settmenu = "menu-open";
    $mpage = "Active Sessions";
  } elseif ($id == "my-profile") {
    $smyprofile = "active";
    $mpage = "My Profile";
  }
}
$ngwProfileInfo = getCurrentUserProfileSummary();
$ngwProfileHref = './admin.php?id=my-profile' . (!empty($session) && $session !== 'new' ? '&session=' . urlencode($session) : '');
$ngwLogoutHref = (!empty($session) && $id == "") ? ('./?hotspot=logout&session=' . urlencode($session)) : './admin.php?id=logout';

$idleto = "5";
$didleto = 'display:block;';
$ngwHandshake = !empty($GLOBALS['NGW_LOGIN_HANDSHAKE']) || !empty($_SESSION['mikhmon_login_handshake']);
if (!empty($_SESSION['mikhmon_login_handshake'])) {
  unset($_SESSION['mikhmon_login_handshake']);
}
$isLogoutReq = ($id === 'logout' || $hotspot === 'logout');
$isSettingsDropdownOpen = in_array(isset($id) ? $id : '', array('admin-settings', 'activity', 'active-sessions', 'system-update'), true);
?>
<span style="display:none;" id="idto"><?= $idleto ;?></span>
<?php if (!$isLogoutReq) { ?>
<script>
(function(){
  var MAX_IDLE_MS = 300000; // 5 minutes (300,000 ms)
  var isHandshake = <?= $ngwHandshake ? 'true' : 'false'; ?>;
  var logoutUrl = './admin.php?id=logout';
  try {
    if (isHandshake) {
      sessionStorage.setItem('ngw_tab_auth', '1');
      sessionStorage.setItem('ngw_last_act', String(Date.now()));
    } else {
      var tabAuth = sessionStorage.getItem('ngw_tab_auth');
      if (tabAuth !== '1') {
        window.location.replace(logoutUrl);
        return;
      }
      var prevAct = parseInt(sessionStorage.getItem('ngw_last_act') || '0', 10);
      if (prevAct > 0 && (Date.now() - prevAct) > MAX_IDLE_MS) {
        sessionStorage.removeItem('ngw_tab_auth');
        sessionStorage.removeItem('ngw_last_act');
        window.location.replace(logoutUrl);
        return;
      }
      sessionStorage.setItem('ngw_last_act', String(Date.now()));
    }
  } catch (e) {}

  var lastActTs = Date.now();
  function recordUserActivity() {
    var now = Date.now();
    if ((now - lastActTs) > MAX_IDLE_MS) {
      triggerAutoLogout();
      return;
    }
    lastActTs = now;
    try {
      sessionStorage.setItem('ngw_last_act', String(now));
    } catch (e) {}
  }

  function triggerAutoLogout() {
    try {
      sessionStorage.removeItem('ngw_tab_auth');
      sessionStorage.removeItem('ngw_last_act');
    } catch (e) {}
    window.location.replace(logoutUrl);
  }

  function checkIdleExpiration() {
    var storedTs = lastActTs;
    try {
      var s = parseInt(sessionStorage.getItem('ngw_last_act') || '0', 10);
      if (s > storedTs) storedTs = s;
    } catch (e) {}
    if ((Date.now() - storedTs) >= MAX_IDLE_MS) {
      triggerAutoLogout();
    }
  }

  var events = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'];
  for (var i = 0; i < events.length; i++) {
    window.addEventListener(events[i], recordUserActivity, { passive: true });
  }
  // Successful voucher batch steps are active work, including long batches.
  window.addEventListener('ngw-voucher-progress', recordUserActivity);
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
      checkIdleExpiration();
    }
  });
  window.addEventListener('focus', checkIdleExpiration);
  setInterval(checkIdleExpiration, 2000);
})();
</script>
<?php } ?>


<?php if ($id != "") { ?>

<div id="navbar" class="navbar">
  <div class="navbar-left">
    <a id="brand" class="ngw-brand-logo" href="javascript:void(0)" title="NextGan WiFi Solution">
      <span class="ngw-brand-emblem" aria-hidden="true"><i class="fa fa-wifi"></i></span>
      <span class="ngw-brand-text">
        <span class="ngw-brand-top">Next<span class="ngw-brand-accent">Gan</span></span>
        <span class="ngw-brand-sub">WiFi Solution</span>
      </span>
    </a>

<a id="openNav" class="navbar-hover" href="javascript:void(0)"><i class="fa fa-bars"></i></a>
<a id="closeNav" class="navbar-hover" href="javascript:void(0)"><i class="fa fa-bars"></i></a>
<a id="cpage" class="navbar-left" href="javascript:void(0)"><?= $mpage; ?></a>
<?php if (isMikhmonAdmin()) { ?>
<span class="navbar-left" style="display:inline-flex; align-items:center; gap:12px; padding:10px 10px; user-select:none;" title="Server CPU &amp; RAM">
  <span style="display:inline-flex; align-items:center; gap:5px;" title="Server CPU">
    <i id="ngw-cpu-icon" class="fa fa-microchip" style="font-size:13px; color:#2ea043; transition:color 0.3s ease;"></i>
    <span style="display:inline-block; width:32px; height:6px; background:rgba(150,150,150,0.28); border-radius:3px; overflow:hidden; vertical-align:middle;">
      <span id="ngw-cpu-bar" style="display:block; height:100%; width:12%; background:#2ea043; border-radius:3px; transition:width 0.3s ease, background-color 0.3s ease;"></span>
    </span>
    <span id="ngw-cpu-pct" style="font-size:11px; font-weight:bold; min-width:26px; display:inline-block;">--%</span>
  </span>
  <span style="display:inline-flex; align-items:center; gap:5px;" title="Server RAM">
    <svg id="ngw-ram-icon" width="14" height="13" viewBox="0 0 16 14" fill="none" style="vertical-align:middle; color:#2ea043; transition:color 0.3s ease;">
      <rect x="1" y="3" width="14" height="7" rx="1.2" stroke="currentColor" stroke-width="1.5"/>
      <rect x="3.5" y="5" width="2" height="3" fill="currentColor"/>
      <rect x="7" y="5" width="2" height="3" fill="currentColor"/>
      <rect x="10.5" y="5" width="2" height="3" fill="currentColor"/>
      <path d="M3.5 10V12.5M6.5 10V12.5M9.5 10V12.5M12.5 10V12.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
    </svg>
    <span style="display:inline-block; width:32px; height:6px; background:rgba(150,150,150,0.28); border-radius:3px; overflow:hidden; vertical-align:middle;">
      <span id="ngw-ram-bar" style="display:block; height:100%; width:28%; background:#2ea043; border-radius:3px; transition:width 0.3s ease, background-color 0.3s ease;"></span>
    </span>
    <span id="ngw-ram-pct" style="font-size:11px; font-weight:bold; min-width:26px; display:inline-block;">--%</span>
  </span>
</span>
<?php } ?>
<span id="ngw-local-datetime" class="navbar-left" title="Your browser's local date and time" style="display:inline-flex;align-items:center;gap:5px;padding:10px 7px;font-size:10px;line-height:1.2;opacity:.86;white-space:nowrap;"><i class="fa fa-calendar-o" aria-hidden="true"></i><span>--</span></span>
</div>
 <div class="navbar-right">
  <?php if (!empty($_SESSION['mikhmon_impersonator_admin'])) { ?>
  <a href="./admin.php?id=return-to-admin" style="background:#1f6feb; color:#fff; border-radius:3px; padding:4px 8px; margin-right:6px; font-weight:bold; font-size:12px;" title="Return to Admin Panel"><i class="fa fa-user-secret mr-1"></i> <?= htmlspecialchars(isset($_back_to_admin_btn) ? $_back_to_admin_btn : 'Admin Panel'); ?></a>
  <?php } ?>
  <a href="javascript:void(0)" class="ngw-profile-trigger" aria-haspopup="true" aria-expanded="false" title="<?= htmlspecialchars($ngwProfileInfo['display_name'] . ' (' . $ngwProfileInfo['role_label'] . ')', ENT_QUOTES, 'UTF-8'); ?>">
    <span class="ngw-profile-trigger-avatar">
      <?php if (!empty($ngwProfileInfo['avatar_url'])) { ?>
        <img src="<?= htmlspecialchars($ngwProfileInfo['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar">
      <?php } else { ?>
        <i class="fa fa-user-circle"></i>
      <?php } ?>
    </span>
    <span class="ngw-profile-trigger-name"><?= htmlspecialchars($ngwProfileInfo['display_name'], ENT_QUOTES, 'UTF-8'); ?></span>
    <span class="ngw-profile-trigger-badge <?= $ngwProfileInfo['is_reseller'] ? 'is-reseller' : 'is-admin'; ?>"><?= htmlspecialchars($ngwProfileInfo['role_badge'], ENT_QUOTES, 'UTF-8'); ?></span>
    <i class="fa fa-caret-down ngw-profile-trigger-caret"></i>
  </a>
   <a id="logout" href="./admin.php?id=logout" ><i class="fa fa-sign-out mr-1"></i> <?= $_logout ?></a>

  <?php $urlSep = (strpos($url, '?') !== false) ? '&' : '?'; ?>
  <select class="stheme ses text-right mr-t-10 pd-5">
    <option> <?= $_theme?></option>
    <?php for ($i = 0; $i < count($mtheme); $i++) {
      echo '<option value="'.$url.$urlSep.'set-theme='.$mtheme[$i],'">'.ucfirst($mtheme[$i]),'</option>';
    }
    ?>
  </select>
  <select class="slang ses text-right mr-t-10 pd-5">
    <option> <?= $language ?></option>
    <?php 
      $fileList = glob('lang/*');
      foreach($fileList as $filename){
        if(is_file($filename)){
          $filename = substr(explode("/",$filename)[1],0,-4);
          if($filename == "isocodelang"){}else{
            echo '<option value="'.$url.$urlSep.'setlang=' . $filename . '">'. $isocodelang[$filename]. '</option>'; 
         }   
        }
      }
    ?>
  </select>
  <a title="Idle Timeout (5m)" style="<?= $didleto; ?>"><span style="width:70px;" class="pd-5 radius-3"><i class="fa fa-clock-o mr-1"></i>  <span class="mr-1" id="timer"></span></span></a>


</div>
</div>

<div id="sidenav" class="sidenav">
<?php if (isResetOnlyReseller()) { ?>
  <a href="./admin.php?id=reset-voucher" class="menu <?= $sresetvoucher; ?>"><i class="fa fa-refresh"></i> <?= isset($_reset_voucher) ? $_reset_voucher : 'Reset Voucher'; ?></a>
  <a href="./admin.php?id=logout" class="menu"><i class="fa fa-sign-out"></i> <?= $_logout ?></a>
<?php } else { ?>
<?php if (($id == "settings" && $session == "new") || $id == "settings" && $router == "new") {
}else if (($id == "settings" || $id == "connect") && !empty($session)){
?>  
  <div class="menu text-center align-middle card-header" style="border-radius:0;"><h3 id="MikhmonSession"><?= $session; ?></h3></div>
  <a class="connect menu <?= $shome; ?>" id="<?= $session; ?>&c=settings"><i class='fa fa-tachometer'></i> <?= $_dashboard ?></a>
  <?php /* Session Settings are opened through Add Router or Edit Router, not a separate sidebar button. */ ?>
  <div class="menu spa"></div>
<?php 
} ?>  
  <a href="./admin.php?id=sessions" class="menu <?= $ssesslist; ?>"><i class="fa fa-server"></i> <?= isset($_router_menu_label) ? $_router_menu_label : 'Router'; ?></a>
  <?php if (hasResellerPermission('reset_voucher')) { ?>
  <a href="./admin.php?id=reset-voucher<?= !empty($session) && $session !== 'new' ? '&session=' . urlencode($session) : ''; ?>" class="menu <?= $sresetvoucher; ?>"><i class="fa fa-refresh"></i> <?= isset($_reset_voucher) ? $_reset_voucher : 'Reset Voucher'; ?></a>
  <?php } ?>
  <?php if (hasResellerPermission('manage_router_settings')) { ?>
  <a href="./admin.php?id=settings&router=new-<?= rand(1111,9999) ?>" class="menu <?= $snsettings ?>"><i class="fa fa-plus"></i> <?= $_add_router ?></a>
  <?php } ?>
  <?php if (isMikhmonAdmin()) { ?>
  <a href="./admin.php?id=resellers" class="menu <?= $sresellers; ?>"><i class="fa fa-users"></i> <?= isset($_reseller) ? $_reseller : 'Reseller'; ?></a>
  <a href="javascript:void(0)" id="ngw-settings-dropdown-toggle" class="menu <?= $isSettingsDropdownOpen ? 'active' : ''; ?>" role="button" aria-controls="ngw-settings-dropdown" aria-expanded="<?= $isSettingsDropdownOpen ? 'true' : 'false'; ?>" style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;">
    <span><i class="fa fa-gear"></i> <?= $_settings ?></span>
    <i id="ngw-settings-dropdown-chevron" class="fa fa-chevron-<?= $isSettingsDropdownOpen ? 'up' : 'down'; ?>" style="font-size:11px;"></i>
  </a>
  <div id="ngw-settings-dropdown" style="display:<?= $isSettingsDropdownOpen ? 'block' : 'none'; ?>;background:rgba(0,0,0,0.08);">
    <a href="./admin.php?id=admin-settings" class="menu <?= $sadmsettings; ?>" style="padding-left:34px;"><i class="fa fa-sliders"></i> <?= $_settings ?></a>
    <a href="./admin.php?id=activity" class="menu <?= (isset($id) && $id === 'activity') ? 'active' : ''; ?>" style="padding-left:34px;"><i class="fa fa-history"></i> Activity History</a>
    <a href="./admin.php?id=active-sessions" class="menu <?= (isset($id) && $id === 'active-sessions') ? 'active' : ''; ?>" style="padding-left:34px;"><i class="fa fa-desktop"></i> Active Sessions</a>
    <a href="./admin.php?id=system-update" class="menu <?= $ssysupdate; ?>" style="padding-left:34px;"><i class="fa fa-cloud-upload"></i> <?= isset($_system_update) ? $_system_update : 'System Update'; ?></a>
  </div>
  <a href="./admin.php?id=vpn" class="menu <?= $svpn; ?>"><i class="fa fa-shield"></i> <?= isset($_vpn) ? $_vpn : 'VPN'; ?></a>
  <?php if (!isset($id) || $id !== 'vpn') { ?>
  <a href="./admin.php?id=editor" class="menu <?= $seditor; ?>"><i class="fa fa-edit"></i> <?= isset($_template_editor) ? $_template_editor : 'Template Editor'; ?></a>
  <?php } ?>

  <?php } else { ?>
  <?php if ((hasResellerPermission('edit_own_template') || hasResellerPermission('save_own_template') || hasResellerPermission('preview_assigned_template') || hasResellerPermission('use_assigned_template') || hasResellerPermission('print_voucher')) && (!isset($id) || $id !== 'vpn')) { ?>
  <a href="./admin.php?id=editor" class="menu <?= $seditor; ?>"><i class="fa fa-edit"></i> <?= isset($_template_editor) ? $_template_editor : 'Template Editor'; ?></a>
  <?php } ?>
  <?php } ?>
  <a href="./admin.php?id=about" class="menu <?= $sabout; ?>"><i class="fa fa-info-circle"></i> <?= $_about ?></a>
  <a href="./admin.php?id=logout" class="menu"><i class="fa fa-sign-out"></i> <?= $_logout ?></a>
<?php } ?>
</div>

<script>
$(document).ready(function(){
  $('#ngw-settings-dropdown-toggle').on('click', function(e) {
    e.preventDefault();
    var $menu = $('#ngw-settings-dropdown');
    var isOpen = $menu.is(':visible');
    $menu.stop(true, true).slideToggle(140);
    $(this).attr('aria-expanded', isOpen ? 'false' : 'true');
    $('#ngw-settings-dropdown-chevron').toggleClass('fa-chevron-down', isOpen).toggleClass('fa-chevron-up', !isOpen);
  });
  $(".connect").click(function(){
    notify("<?= $_connecting ?>");
    connect(this.id)
  });
  $(".stheme").change(function(){
    notify("<?= $_loading_theme ?>");
    stheme(this.value)
  });
  $(".slang").change(function(){
    notify("<?= $_loading ?>");
    stheme(this.value)
  });
});
</script>
<div id="notify"><div class="message"></div></div>
<div id="temp"></div>
<?php 
} else { ?>

<div id="navbar" class="navbar">
  <div class="navbar-left">
    <a id="brand" class="ngw-brand-logo" href="./?session=<?= $session; ?>" title="NextGan WiFi Solution">
      <span class="ngw-brand-emblem" aria-hidden="true"><i class="fa fa-wifi"></i></span>
      <span class="ngw-brand-text">
        <span class="ngw-brand-top">Next<span class="ngw-brand-accent">Gan</span></span>
        <span class="ngw-brand-sub">WiFi Solution</span>
      </span>
    </a>

<a id="openNav" class="navbar-hover" href="javascript:void(0)"><i class="fa fa-bars"></i></a>
<a id="closeNav" class="navbar-hover" href="javascript:void(0)"><i class="fa fa-bars"></i></a>
<a id="cpage" class="navbar-left" href="javascript:void(0)"><?= $mpage; ?></a>
<?php if (isMikhmonAdmin()) { ?>
<span class="navbar-left" style="display:inline-flex; align-items:center; gap:12px; padding:10px 10px; user-select:none;" title="Server CPU &amp; RAM">
  <span style="display:inline-flex; align-items:center; gap:5px;" title="Server CPU">
    <i id="ngw-cpu-icon" class="fa fa-microchip" style="font-size:13px; color:#2ea043; transition:color 0.3s ease;"></i>
    <span style="display:inline-block; width:32px; height:6px; background:rgba(150,150,150,0.28); border-radius:3px; overflow:hidden; vertical-align:middle;">
      <span id="ngw-cpu-bar" style="display:block; height:100%; width:12%; background:#2ea043; border-radius:3px; transition:width 0.3s ease, background-color 0.3s ease;"></span>
    </span>
    <span id="ngw-cpu-pct" style="font-size:11px; font-weight:bold; min-width:26px; display:inline-block;">--%</span>
  </span>
  <span style="display:inline-flex; align-items:center; gap:5px;" title="Server RAM">
    <svg id="ngw-ram-icon" width="14" height="13" viewBox="0 0 16 14" fill="none" style="vertical-align:middle; color:#2ea043; transition:color 0.3s ease;">
      <rect x="1" y="3" width="14" height="7" rx="1.2" stroke="currentColor" stroke-width="1.5"/>
      <rect x="3.5" y="5" width="2" height="3" fill="currentColor"/>
      <rect x="7" y="5" width="2" height="3" fill="currentColor"/>
      <rect x="10.5" y="5" width="2" height="3" fill="currentColor"/>
      <path d="M3.5 10V12.5M6.5 10V12.5M9.5 10V12.5M12.5 10V12.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
    </svg>
    <span style="display:inline-block; width:32px; height:6px; background:rgba(150,150,150,0.28); border-radius:3px; overflow:hidden; vertical-align:middle;">
      <span id="ngw-ram-bar" style="display:block; height:100%; width:28%; background:#2ea043; border-radius:3px; transition:width 0.3s ease, background-color 0.3s ease;"></span>
    </span>
    <span id="ngw-ram-pct" style="font-size:11px; font-weight:bold; min-width:26px; display:inline-block;">--%</span>
  </span>
</span>
<?php } ?>
<span id="ngw-local-datetime" class="navbar-left" title="Your browser's local date and time" style="display:inline-flex;align-items:center;gap:5px;padding:10px 7px;font-size:10px;line-height:1.2;opacity:.86;white-space:nowrap;"><i class="fa fa-calendar-o" aria-hidden="true"></i><span>--</span></span>
</div>
 <div class="navbar-right">
  <?php if (!empty($_SESSION['mikhmon_impersonator_admin'])) { ?>
  <a href="./admin.php?id=return-to-admin" style="background:#1f6feb; color:#fff; border-radius:3px; padding:4px 8px; margin-right:6px; font-weight:bold; font-size:12px;" title="Return to Admin Panel"><i class="fa fa-user-secret mr-1"></i> <?= htmlspecialchars(isset($_back_to_admin_btn) ? $_back_to_admin_btn : 'Admin Panel'); ?></a>
  <?php } ?>
  <a href="javascript:void(0)" class="ngw-profile-trigger" aria-haspopup="true" aria-expanded="false" title="<?= htmlspecialchars($ngwProfileInfo['display_name'] . ' (' . $ngwProfileInfo['role_label'] . ')', ENT_QUOTES, 'UTF-8'); ?>">
    <span class="ngw-profile-trigger-avatar">
      <?php if (!empty($ngwProfileInfo['avatar_url'])) { ?>
        <img src="<?= htmlspecialchars($ngwProfileInfo['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar">
      <?php } else { ?>
        <i class="fa fa-user-circle"></i>
      <?php } ?>
    </span>
    <span class="ngw-profile-trigger-name"><?= htmlspecialchars($ngwProfileInfo['display_name'], ENT_QUOTES, 'UTF-8'); ?></span>
    <span class="ngw-profile-trigger-badge <?= $ngwProfileInfo['is_reseller'] ? 'is-reseller' : 'is-admin'; ?>"><?= htmlspecialchars($ngwProfileInfo['role_badge'], ENT_QUOTES, 'UTF-8'); ?></span>
    <i class="fa fa-caret-down ngw-profile-trigger-caret"></i>
  </a>
   <a id="logout" href="./?hotspot=logout&session=<?= $session; ?>" ><i class="fa fa-sign-out mr-1"></i> <?= $_logout ?></a>

  <?php $urlSep = (strpos($url, '?') !== false) ? '&' : '?'; ?>
  <select class="stheme ses text-right mr-t-10 pd-5">
    <option> <?= $_theme ?></option>
    <?php for ($i = 0; $i < count($mtheme); $i++) {
      echo '<option value="'.$url.$urlSep.'set-theme='.$mtheme[$i],'">'.ucfirst($mtheme[$i]),'</option>';
    }
    ?>
  </select>
  <select class="slang ses text-right mr-t-10 pd-5">
    <option> <?= $language ?></option>
    <?php 
      $fileList = glob('lang/*');
      foreach($fileList as $filename){
        if(is_file($filename)){
          $filename = substr(explode("/",$filename)[1],0,-4);
          if($filename == "isocodelang"){}else{
            echo '<option value="'.$url.$urlSep.'setlang=' . $filename . '">'. $isocodelang[$filename]. '</option>'; 
         }   
        }
      }
    ?>
  </select>
  <select class="connect optfa ses text-right mr-t-10 pd-5">
    <option id="MikhmonSession" value="<?= $session; ?>"><?= $hotspotname; ?></option>
      <?php
      foreach (file('./include/config.php') as $line) {
        $sesname = explode("'", $line)[1];
        if ($sesname == "" || $sesname== "mikhmon" || !canAccessRouterSession($sesname)) {
        } else {
        if($sesname == $session){
          echo '<option value="' . $sesname. '">'.$sesname. ' &#x2666;</option>';
        }else{
          echo '<option value="' . $sesname. '">'.$sesname. '</option>';
        }
        }
      }
      ?>
    
  </select>
  <a title="Idle Timeout (5m)" style="<?= $didleto; ?>"><span style="width:70px;" class="pd-5 radius-3"><i class="fa fa-clock-o mr-1"></i>  <span class="mr-1" id="timer"></span></span></a>
</div>
</div>

<div id="sidenav" class="sidenav">
  <?php if (isResetOnlyReseller()) { ?>
  <a href="./admin.php?id=reset-voucher&session=<?= urlencode($session); ?>" class="menu <?= $sresetvoucher; ?>"><i class="fa fa-refresh"></i> <?= isset($_reset_voucher) ? $_reset_voucher : 'Reset Voucher'; ?></a>
  <a href="./?hotspot=logout&session=<?= $session; ?>" class="menu"><i class="fa fa-sign-out"></i> <?= $_logout ?></a>
  <?php } else { ?>
  <div class="menu text-center align-middle card-header" style="border-radius:0;"><h3><?= $identity; ?></h3></div>
  <?php if (hasResellerPermission('view_dashboard')) { ?>
  <a href="./?session=<?= $session; ?>" class="menu <?= $shome; ?>"><i class="fa fa-dashboard"></i> <?= $_dashboard ?></a>
  <?php } ?>
  <!--hotspot-->
  <?php if (hasResellerPermission('view_users') || hasResellerPermission('add_user') || hasResellerPermission('generate_voucher') || hasResellerPermission('view_profiles') || hasResellerPermission('add_profile') || hasResellerPermission('view_active') || hasResellerPermission('view_hosts') || hasResellerPermission('view_ipbinding') || hasResellerPermission('view_cookies')) { ?>
  <div class="dropdown-btn <?= $susers . $suserprof . $sactive . $shosts . $sipbind . $scookies; ?>"><i class="fa fa-wifi"></i> Hotspot
    <i class="fa fa-caret-down"></i>
  </div>
  <div class="dropdown-container <?= $umenu . $upmenu . $hamenu . $hmenu . $ibmenu . $cmenu; ?>">
   <!--users--> 
  <?php if (hasResellerPermission('view_users') || hasResellerPermission('add_user') || hasResellerPermission('generate_voucher')) { ?>
  <div class="dropdown-btn <?= $susers; ?>"><i class="fa fa-users"></i> <?= $_users ?>
    <i class="fa fa-caret-down"></i>
  </div>
  <div class="dropdown-container <?= $umenu; ?>">
    <?php if (hasResellerPermission('view_users')) { ?>
    <a href="./?hotspot=users&profile=all&session=<?= $session; ?>" class="<?= $susersl; ?>"> &nbsp;&nbsp;&nbsp;<i class="fa fa-list "></i> <?= $_user_list ?> </a>
    <?php } ?>
    <?php if (hasResellerPermission('add_user')) { ?>
    <a href="./?hotspot-user=add&session=<?= $session; ?>" class="<?= $sadduser; ?>"> &nbsp;&nbsp;&nbsp;<i class="fa fa-user-plus "></i> <?= $_add_user ?> </a>
    <?php } ?>
    <?php if (hasResellerPermission('generate_voucher')) { ?>
    <a href="./?hotspot-user=generate&session=<?= $session; ?>" class="<?= $sgenuser; ?>"> &nbsp;&nbsp;&nbsp;<i class="fa fa-user-plus"></i> <?= $_generate ?> </a>        
    <?php } ?>
  </div>
  <?php } ?>
  <!--profile-->
  <?php if (hasResellerPermission('view_profiles') || hasResellerPermission('add_profile')) { ?>
  <div class="dropdown-btn <?= $suserprof; ?>"><i class=" fa fa-pie-chart"></i>  <?= $_user_profile ?>
    <i class="fa fa-caret-down"></i>
  </div>
  <div class="dropdown-container <?= $upmenu; ?>">
    <?php if (hasResellerPermission('view_profiles')) { ?>
    <a href="./?hotspot=user-profiles&session=<?= $session; ?>" class=" <?= $suserprofiles; ?>"> &nbsp;&nbsp;&nbsp;<i class="fa fa-list "></i> <?= $_user_profile_list ?> </a>
    <?php } ?>
    <?php if (hasResellerPermission('add_profile')) { ?>
    <a href="./?user-profile=add&session=<?= $session; ?>" class=" <?= $sadduserprof; ?>"> &nbsp;&nbsp;&nbsp;<i class="fa fa-plus-square "></i> <?= $_add_user_profile ?> </a>
    <?php } ?>
  </div>
  <?php } ?>
  <!--active-->
  <?php if (hasResellerPermission('view_active')) { ?>
  <a href="./?hotspot=active&session=<?= $session; ?>" class="menu <?= $sactive; ?>"><i class=" fa fa-wifi"></i> <?= $_hotspot_active ?></a>
  <?php } ?>
  <!--hosts-->
  <?php if (hasResellerPermission('view_hosts')) { ?>
  <a href="./?hotspot=hosts&session=<?= $session; ?>" class="menu <?= $shosts; ?>"><i class=" fa fa-laptop"></i> <?= $_hosts ?></a>
  <?php } ?>
  <!--ip bindings-->
  <?php if (hasResellerPermission('view_ipbinding')) { ?>
  <a href="./?hotspot=ipbinding&session=<?= $session; ?>" class="menu <?= $sipbind; ?>"><i class=" fa fa-address-book"></i> <?= $_ip_bindings ?></a>
  <?php } ?>
  <!--cookies-->
  <?php if (hasResellerPermission('view_cookies')) { ?>
   <a href="./?hotspot=cookies&session=<?= $session; ?>" class="menu <?= $scookies; ?>"><i class=" fa fa-hourglass"></i> <?= $_hotspot_cookies ?></a>
  <?php } ?>
  </div>
  <?php } ?>
  <!--quick print-->
  <?php if (hasResellerPermission('print_voucher')) { ?>
  <a href="./?hotspot=quick-print&session=<?= $session; ?>" class="menu <?= $squick; ?>"> <i class="fa fa-print"></i> <?= $_quick_print ?> </a>
  <?php } ?>
  <!--vouchers-->
  <?php if (hasResellerPermission('view_users')) { ?>
  <a href="./?hotspot=users-by-profile&session=<?= $session; ?>" class="menu <?= $susersbp; ?>"> <i class="fa fa-ticket"></i> <?= $_vouchers ?> </a>
  <?php } ?>
  <!--reset voucher-->
  <?php if (hasResellerPermission('reset_voucher')) { ?>
  <a href="./?hotspot=reset-voucher&session=<?= $session; ?>" class="menu <?= $sresetvoucher; ?>"> <i class="fa fa-refresh"></i> <?= isset($_reset_voucher) ? $_reset_voucher : 'Reset Voucher'; ?> </a>
  <?php } ?>

  <!--pppoe management (router-scoped)-->
  <?php if (hasResellerPermission('manage_pppoe')) { ?>
  <div class="dropdown-btn <?= $mpppoe; ?>"><i class="fa fa-plug"></i> <?= isset($_pppoe) ? $_pppoe : 'PPPoE'; ?>
    <i class="fa fa-caret-down"></i>
  </div>
  <div class="dropdown-container <?= $pppoemenu; ?>">
    <?php if (hasResellerPermission('view_pppoe_users')) { ?>
    <a href="./?pppoe=users&session=<?= $session; ?>" class="<?= $spppoe_users; ?>"><i class="fa fa-users"></i> <?= isset($_pppoe_users) ? $_pppoe_users : 'PPPoE Users'; ?></a>
    <?php } ?>
    <?php if (hasResellerPermission('view_pppoe_profiles')) { ?>
    <a href="./?pppoe=profiles&session=<?= $session; ?>" class="<?= $spppoe_profiles; ?>"><i class="fa fa-pie-chart"></i> <?= isset($_pppoe_profiles) ? $_pppoe_profiles : 'PPPoE Profiles'; ?></a>
    <?php } ?>
    <?php if (hasResellerPermission('view_pppoe_active')) { ?>
    <a href="./?pppoe=active&session=<?= $session; ?>" class="<?= $spppoe_active; ?>"><i class="fa fa-bolt"></i> <?= isset($_pppoe_active) ? $_pppoe_active : 'Active Sessions'; ?></a>
    <?php } ?>
    <?php if (hasResellerPermission('reset_pppoe')) { ?>
    <a href="./?pppoe=reset&session=<?= $session; ?>" class="<?= $spppoe_reset; ?>"><i class="fa fa-refresh"></i> <?= isset($_reset_pppoe) ? $_reset_pppoe : 'Reset PPPoE User'; ?></a>
    <?php } ?>
  </div>
  <?php } ?>
   <!--log-->
  <?php if (hasResellerPermission('view_logs')) { ?>
  <div class="dropdown-btn <?= $log; ?>"><i class=" fa fa-align-justify"></i> <?= $_log ?>
    <i class="fa fa-caret-down"></i>
  </div>
  <div class="dropdown-container <?= $lmenu; ?>">
    <a href="./?hotspot=log&session=<?= $session; ?>" class="<?= $slog; ?>"> <i class="fa fa-wifi "></i> <?= $_hotspot_log ?> </a>
    <a href="./?report=userlog&idbl=<?= strtolower(date("M")) . date("Y"); ?>&session=<?= $session; ?>" class=" <?= $sulog; ?>"> <i class="fa fa-users "></i> <?= $_user_log ?> </a>
  </div>
  <?php } ?>
  <!--system-->
  <?php $canAccessUserTransfer = hasResellerPermission('transfer_users_access') || (hasResellerPermission('export_users') && hasResellerPermission('generate_voucher')) || hasResellerPermission('same_router_profile_transfer'); ?>
  <?php if (hasResellerPermission('view_scheduler') || hasResellerPermission('reboot_shutdown') || $canAccessUserTransfer) { ?>
  <div class="dropdown-btn <?= $sysmenu; ?>"><i class=" fa fa-gear"></i> <?= $_system ?>
    <i class="fa fa-caret-down"></i> &nbsp;
  </div>
  <div class="dropdown-container <?= $schmenu; ?>">
    <?php if ($canAccessUserTransfer) { ?>
    <a href="./hotspot/usertransfer.php?session=<?= rawurlencode($session); ?>"><i class="fa fa-exchange"></i> User Transfer</a>
    <?php } ?>
    <?php if (hasResellerPermission('view_scheduler')) { ?>
    <a href="./?system=scheduler&session=<?= $session; ?>" class="<?= $ssch; ?>"> <i class="fa fa-clock-o "></i> <?= $_system_scheduler ?> </a>
    <?php } ?>
    <?php if (hasResellerPermission('reboot_shutdown')) { ?>
    <a href="./admin.php?id=reboot&session=<?= $session; ?>" class=""> <i class="fa fa-power-off "></i> <?= $_system_reboot ?> </a>            
    <a href="./admin.php?id=shutdown&session=<?= $session; ?>" class=""> <i class="fa fa-power-off "></i> <?= $_system_off ?> </a> 
    <?php } ?>
  </div>
  <?php } ?>
  <!--dhcp leases-->
  <?php if (hasResellerPermission('view_dhcp')) { ?>
  <a href="./?hotspot=dhcp-leases&session=<?= $session; ?>" class="menu <?= $slease; ?>"><i class=" fa fa-sitemap"></i> <?= $_dhcp_leases ?></a>
  <?php } ?>
  <!--traffic monitor-->
  <?php if (hasResellerPermission('view_traffic')) { ?>
  <a href="./?interface=traffic-monitor&session=<?= $session; ?>" class="menu <?= $strafficmonitor; ?>"><i class=" fa fa-area-chart"></i> <?= $_traffic_monitor ?></a>
  <?php } ?>
  <!--report-->
  <?php if (hasResellerPermission('view_report')) { ?>
  <a href="./?report=selling&idbl=<?= strtolower(date("M")) . date("Y"); ?>&session=<?= $session; ?>" class="menu <?= $sselling; ?>"><i class="nav-icon fa fa-money"></i> <?= $_report ?></a>
  <?php } ?>
  <!--router switch / back to sessions-->
  <a href="./admin.php?id=sessions" class="menu" title="Back to Router List"><i class="fa fa-server"></i> <?= isset($_router_menu_label) ? $_router_menu_label : 'Router'; ?></a>
  <!--about-->
  <a href="./?hotspot=about&session=<?= $session; ?>" class="menu <?= $sabout; ?>"><i class="fa fa-info-circle"></i> <?= $_about ?></a>
  <!--logout-->
  <a href="./?hotspot=logout&session=<?= $session; ?>" class="menu"><i class="fa fa-sign-out"></i> <?= $_logout ?></a>
  <?php } ?>
</div>
<script>
$(document).ready(function(){
  $(".connect").change(function(){
    notify("<?= $_connecting ?>");
    connect(this.value)
  });
  $(".stheme").change(function(){
    notify("<?= $_loading_theme ?>");
    stheme(this.value)
  });
  $(".slang").change(function(){
    notify("<?= $_loading ?>");
    stheme(this.value)
  });
});
</script>
<div id="notify"><div class="message"></div></div>
<div id="temp"></div>
<?php 
} ?>
<?php if (isMikhmonAdmin()) { ?>
<script>
(function(){
  var metricsInFlight = false;
  var metricsXhr = null;
  var metricsInterval = null;
  var pageUnloading = false;

  window.addEventListener('beforeunload', function(){
    pageUnloading = true;
    if (metricsInterval) clearInterval(metricsInterval);
    if (metricsXhr && metricsXhr.readyState !== 4) {
      try { metricsXhr.abort(); } catch(e) {}
    }
  });

  function pickBarColor(pct) {
    if (pct >= 85) return '#f85149';
    if (pct >= 61) return '#f0ad4e';
    return '#2ea043';
  }
  function updateServerMetricsBar() {
    if (pageUnloading || !window.jQuery || metricsInFlight) return;
    metricsInFlight = true;
    metricsXhr = $.ajax({
      url: './status/server_metrics.php',
      dataType: 'json',
      timeout: 2500,
      success: function(res) {
        if (pageUnloading || !res || !res.ok) return;
        var cpu = Math.max(1, Math.min(100, parseInt(res.cpu, 10) || 1));
        var ram = Math.max(1, Math.min(100, parseInt(res.ram, 10) || 1));
        var cpuCol = pickBarColor(cpu);
        var ramCol = pickBarColor(ram);
        var cBar = document.getElementById('ngw-cpu-bar');
        var cIco = document.getElementById('ngw-cpu-icon');
        var cPct = document.getElementById('ngw-cpu-pct');
        var rBar = document.getElementById('ngw-ram-bar');
        var rIco = document.getElementById('ngw-ram-icon');
        var rPct = document.getElementById('ngw-ram-pct');
        if (cBar) { cBar.style.width = Math.max(6, cpu) + '%'; cBar.style.backgroundColor = cpuCol; }
        if (cIco) { cIco.style.color = cpuCol; }
        if (cPct) { cPct.textContent = cpu + '%'; }
        if (rBar) { rBar.style.width = Math.max(6, ram) + '%'; rBar.style.backgroundColor = ramCol; }
        if (rIco) { rIco.style.color = ramCol; }
        if (rPct) { rPct.textContent = ram + '%'; }
      },
      complete: function() {
        metricsInFlight = false;
        metricsXhr = null;
      }
    });
  }
  $(document).ready(function(){
    updateServerMetricsBar();
    metricsInterval = setInterval(updateServerMetricsBar, 1000);
  });
})();
</script>
<?php } ?>

<style>
#navbar.navbar {
  height: 50px !important;
  overflow: visible !important;
}
#navbar.navbar .navbar-left {
  float: left !important;
  display: inline-flex !important;
  align-items: center !important;
  height: 50px !important;
}
#navbar.navbar .navbar-left > a#openNav,
#navbar.navbar .navbar-left > a#closeNav {
  float: none !important;
  height: 50px !important;
  line-height: 22px !important;
  padding: 14px 12px !important;
  box-sizing: border-box !important;
}
#navbar.navbar .navbar-left > a#cpage,
#navbar.navbar .navbar-left > span.navbar-left {
  float: none !important;
  display: inline-flex !important;
  align-items: center !important;
  height: 50px !important;
  padding-top: 0 !important;
  padding-bottom: 0 !important;
}
#navbar.navbar a#brand.ngw-brand-logo {
  float: none !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: flex-start !important;
  gap: 10px !important;
  width: 210px !important;
  height: 50px !important;
  padding: 0 12px !important;
  margin: 0 !important;
  text-decoration: none !important;
  user-select: none;
  box-sizing: border-box !important;
  border-right: 1px solid rgba(255, 255, 255, 0.1) !important;
  background: linear-gradient(90deg, rgba(14, 165, 233, 0.14) 0%, rgba(31, 111, 235, 0.03) 100%) !important;
  overflow: hidden !important;
}
#navbar.navbar a#brand.ngw-brand-logo:hover {
  background: linear-gradient(90deg, rgba(14, 165, 233, 0.22) 0%, rgba(31, 111, 235, 0.07) 100%) !important;
}
#navbar.navbar a#brand.ngw-brand-logo span.ngw-brand-emblem {
  width: 32px !important;
  height: 32px !important;
  border-radius: 8px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%) !important;
  color: #ffffff !important;
  font-size: 15px !important;
  line-height: 1 !important;
  box-shadow: 0 2px 8px rgba(14, 165, 233, 0.38), inset 0 1px 1px rgba(255, 255, 255, 0.35) !important;
  flex-shrink: 0 !important;
}
#navbar.navbar a#brand.ngw-brand-logo span.ngw-brand-text {
  display: flex !important;
  flex-direction: column !important;
  align-items: flex-start !important;
  justify-content: center !important;
  gap: 2px !important;
  line-height: 1.1 !important;
  text-align: left !important;
  min-width: 0 !important;
}
#navbar.navbar a#brand.ngw-brand-logo span.ngw-brand-top {
  display: block !important;
  font-size: 16px !important;
  font-weight: 800 !important;
  letter-spacing: 0.4px !important;
  color: #ffffff !important;
  line-height: 1.1 !important;
  white-space: nowrap !important;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.35) !important;
}
#navbar.navbar a#brand.ngw-brand-logo span.ngw-brand-accent {
  font-size: 16px !important;
  font-weight: 800 !important;
  line-height: 1.1 !important;
  color: #38bdf8 !important;
}
#navbar.navbar a#brand.ngw-brand-logo span.ngw-brand-sub {
  display: block !important;
  font-size: 10px !important;
  font-weight: 700 !important;
  text-transform: uppercase !important;
  letter-spacing: 0.9px !important;
  color: #4ade80 !important;
  margin-top: 1px !important;
  line-height: 1.1 !important;
  white-space: nowrap !important;
}
@media screen and (max-width: 800px) {
  #navbar.navbar a#brand.ngw-brand-logo {
    display: none !important;
  }
}
#navbar.navbar .navbar-right {
  float: right !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: flex-end !important;
  height: 50px !important;
  padding: 0 12px 0 6px !important;
  margin: 0 !important;
  gap: 6px !important;
}
#navbar.navbar .navbar-right > #logout,
#navbar.navbar .navbar-right > .stheme,
#navbar.navbar .navbar-right > .slang,
#navbar.navbar .navbar-right > a[title^="Idle Timeout"] {
  display: none !important;
}
#navbar.navbar .navbar-right > select.connect {
  float: none !important;
  margin: 0 4px 0 0 !important;
  height: 26px !important;
  padding: 2px 6px !important;
  font-size: 11px !important;
  line-height: 1.2 !important;
  order: 1;
}
.ngw-profile-trigger {
  float: none !important;
  order: 2;
  display: inline-flex !important;
  align-items: center !important;
  gap: 5px !important;
  height: 26px !important;
  padding: 2px 8px 2px 4px !important;
  margin: 0 !important;
  border-radius: 13px !important;
  background: rgba(20, 24, 28, 0.55) !important;
  border: 1px solid rgba(255, 255, 255, 0.28) !important;
  color: #ffffff !important;
  cursor: pointer;
  user-select: none;
  text-decoration: none !important;
  line-height: 1 !important;
  vertical-align: middle;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
  transition: background 0.16s ease, border-color 0.16s ease, transform 0.12s ease;
}
.ngw-profile-trigger:hover {
  background: rgba(31, 111, 235, 0.32) !important;
  border-color: rgba(255, 255, 255, 0.5) !important;
}
.ngw-profile-trigger-avatar {
  width: 18px !important;
  height: 18px !important;
  border-radius: 50%;
  overflow: hidden;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(32, 168, 216, 0.35);
  color: #fff;
  font-size: 12px !important;
  flex-shrink: 0;
}
.ngw-profile-trigger-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.ngw-profile-trigger-name {
  font-size: 11px !important;
  font-weight: 600;
  color: #fff;
  max-width: 78px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ngw-profile-trigger-badge {
  font-size: 9px !important;
  font-weight: 700;
  padding: 1px 5px !important;
  border-radius: 8px;
  text-transform: uppercase;
  letter-spacing: 0.25px;
  color: #fff;
  line-height: 1.2;
}
.ngw-profile-trigger-badge.is-admin {
  background: #1f6feb;
}
.ngw-profile-trigger-badge.is-reseller {
  background: #2ea043;
}
.ngw-profile-trigger-caret {
  font-size: 10px !important;
  opacity: 0.85;
  margin-left: 1px;
}
@media screen and (max-width: 750px) {
  #navbar.navbar {
    height: 44px !important;
  }
  #navbar.navbar .navbar-left,
  #navbar.navbar .navbar-right {
    height: 44px !important;
  }
  #navbar.navbar .navbar-left > a#openNav,
  #navbar.navbar .navbar-left > a#closeNav {
    height: 44px !important;
    padding: 11px 10px !important;
  }
  #navbar.navbar .navbar-left > a#cpage,
  #navbar.navbar .navbar-left > span#ngw-local-datetime {
    display: none !important;
  }
  #navbar.navbar .navbar-left > span.navbar-left {
    height: 44px !important;
    padding: 0 4px !important;
    gap: 6px !important;
  }
  #navbar.navbar .navbar-right {
    padding: 0 6px 0 2px !important;
    gap: 4px !important;
  }
  #navbar.navbar .navbar-right > select.connect {
    height: 24px !important;
    padding: 1px 4px !important;
    font-size: 10px !important;
    max-width: 105px !important;
    margin: 0 2px 0 0 !important;
  }
  .ngw-profile-trigger {
    height: 24px !important;
    padding: 2px 6px 2px 3px !important;
    gap: 4px !important;
  }
  .ngw-profile-trigger-avatar {
    width: 16px !important;
    height: 16px !important;
    font-size: 11px !important;
  }
  .ngw-profile-trigger-name {
    max-width: 62px !important;
    font-size: 10px !important;
  }
  .ngw-profile-trigger-badge {
    font-size: 8px !important;
    padding: 1px 4px !important;
  }
}
@media screen and (max-width: 480px) {
  .ngw-profile-trigger-badge {
    display: none !important;
  }
  .ngw-profile-trigger-name {
    max-width: 52px !important;
  }
  #navbar.navbar .navbar-right > select.connect {
    max-width: 84px !important;
  }
}
.ngw-profile-dropdown-card {
  position: fixed;
  top: 44px;
  right: 12px;
  width: 280px;
  max-width: calc(100vw - 24px);
  background: #2f353a;
  color: #f3f4f5;
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 10px;
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.45);
  z-index: 99999;
  display: none;
  overflow: hidden;
}
.ngw-profile-dd-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 15px;
  background: linear-gradient(135deg, rgba(31, 111, 235, 0.22), rgba(46, 160, 67, 0.12));
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}
.ngw-profile-dd-avatar {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(31, 111, 235, 0.25);
  border: 2px solid rgba(255, 255, 255, 0.25);
  font-size: 24px;
  flex-shrink: 0;
}
.ngw-profile-dd-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.ngw-profile-dd-meta {
  min-width: 0;
  flex: 1;
}
.ngw-profile-dd-title {
  font-size: 14px;
  font-weight: 700;
  color: #fff;
  margin: 0 0 2px 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ngw-profile-dd-sub {
  font-size: 11px;
  color: rgba(255, 255, 255, 0.72);
  margin-bottom: 5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ngw-profile-dd-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.ngw-profile-dd-chip {
  font-size: 10px;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.12);
  color: #e6edf3;
}
.ngw-profile-dd-body {
  padding: 6px 0;
}
.ngw-profile-dd-link {
  display: flex !important;
  align-items: center;
  gap: 10px;
  padding: 9px 15px !important;
  font-size: 13px;
  color: #e6edf3 !important;
  text-decoration: none !important;
  transition: background 0.15s ease;
}
.ngw-profile-dd-link:hover {
  background: rgba(32, 168, 216, 0.22);
  color: #fff !important;
}
.ngw-profile-dd-link i {
  width: 18px;
  text-align: center;
  font-size: 14px;
  opacity: 0.9;
}
.ngw-profile-dd-divider {
  height: 1px;
  margin: 5px 0;
  background: rgba(255, 255, 255, 0.1);
}
.ngw-profile-dd-link.is-logout {
  color: #ff7b72 !important;
}
.ngw-profile-dd-link.is-logout:hover {
  background: rgba(248, 81, 73, 0.18);
}
@media (max-width: 640px) {
  .ngw-profile-trigger-name {
    max-width: 72px;
  }
}
</style>
<div id="ngw-profile-dropdown" class="ngw-profile-dropdown-card" role="menu" aria-label="Account Profile Menu">
  <div class="ngw-profile-dd-header">
    <div class="ngw-profile-dd-avatar">
      <?php if (!empty($ngwProfileInfo['avatar_url'])) { ?>
        <img src="<?= htmlspecialchars($ngwProfileInfo['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Avatar">
      <?php } else { ?>
        <i class="fa fa-user-circle"></i>
      <?php } ?>
    </div>
    <div class="ngw-profile-dd-meta">
      <div class="ngw-profile-dd-title"><?= htmlspecialchars($ngwProfileInfo['display_name'], ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="ngw-profile-dd-sub">@<?= htmlspecialchars($ngwProfileInfo['username'], ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="ngw-profile-dd-chips">
        <span class="ngw-profile-dd-chip" style="background:<?= $ngwProfileInfo['is_reseller'] ? '#2ea043' : '#1f6feb'; ?>;color:#fff;"><?= htmlspecialchars($ngwProfileInfo['role_label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
    </div>
  </div>
  <div class="ngw-profile-dd-body">
    <a class="ngw-profile-dd-link" href="<?= htmlspecialchars($ngwProfileHref, ENT_QUOTES, 'UTF-8'); ?>">
      <i class="fa fa-user"></i>
      <span><?= ($langid === 'bn') ? 'অ্যাকাউন্ট তথ্য' : 'Account Information'; ?></span>
    </a>
    <a class="ngw-profile-dd-link" href="<?= htmlspecialchars($ngwProfileHref . '#profile-password-section', ENT_QUOTES, 'UTF-8'); ?>">
      <i class="fa fa-key"></i>
      <span><?= ($langid === 'bn') ? 'পাসওয়ার্ড পরিবর্তন' : 'Change Password'; ?></span>
    </a>
    <a class="ngw-profile-dd-link" href="<?= htmlspecialchars($ngwProfileHref . '#profile-avatar-section', ENT_QUOTES, 'UTF-8'); ?>">
      <i class="fa fa-camera"></i>
      <span><?= ($langid === 'bn') ? 'অবতার আপলোড' : 'Upload Avatar'; ?></span>
    </a>
    <div class="ngw-profile-dd-divider"></div>
    <div style="padding: 6px 14px; font-size: 11px;">
      <div style="color: #94a3b8; margin-bottom: 5px; display:flex; align-items:center; gap:6px; font-weight:600;">
        <i class="fa fa-globe text-primary"></i>
        <span><?= ($langid === 'bn') ? 'ভাষা নির্বাচন (Language)' : 'Language Selection'; ?>:</span>
      </div>
      <div style="display:flex; gap:6px;">
        <a href="?setlang=en" class="btn btn-xs <?= ($langid === 'en' || empty($langid)) ? 'bg-primary text-white' : 'bg-secondary text-white'; ?>" style="flex:1; text-align:center; padding:4px 6px; font-size:11px; text-decoration:none; border-radius:3px; font-weight:600;">
          English <?= ($langid === 'en' || empty($langid)) ? '✓' : ''; ?>
        </a>
        <a href="?setlang=bn" class="btn btn-xs <?= ($langid === 'bn') ? 'bg-primary text-white' : 'bg-secondary text-white'; ?>" style="flex:1; text-align:center; padding:4px 6px; font-size:11px; text-decoration:none; border-radius:3px; font-weight:600;">
          বাংলা <?= ($langid === 'bn') ? '✓' : ''; ?>
        </a>
      </div>
    </div>
    <div class="ngw-profile-dd-divider"></div>
    <a class="ngw-profile-dd-link is-logout" href="<?= htmlspecialchars($ngwLogoutHref, ENT_QUOTES, 'UTF-8'); ?>">
      <i class="fa fa-sign-out"></i>
      <span><?= htmlspecialchars(isset($_logout) ? $_logout : 'Logout', ENT_QUOTES, 'UTF-8'); ?></span>
    </a>
  </div>
</div>
<script>
(function () {
  $(document).ready(function () {
    var $triggers = $('.ngw-profile-trigger');
    var $dropdown = $('#ngw-profile-dropdown');
    $triggers.on('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var isOpen = $dropdown.is(':visible');
      if (isOpen) {
        $dropdown.fadeOut(120);
        $triggers.attr('aria-expanded', 'false');
      } else {
        $dropdown.fadeIn(140);
        $triggers.attr('aria-expanded', 'true');
      }
    });
    $dropdown.on('click', function (e) {
      e.stopPropagation();
    });
    $(document).on('click', function () {
      if ($dropdown.is(':visible')) {
        $dropdown.fadeOut(120);
        $triggers.attr('aria-expanded', 'false');
      }
    });
    $(document).on('keydown', function (e) {
      if (e.key === 'Escape' && $dropdown.is(':visible')) {
        $dropdown.fadeOut(120);
        $triggers.attr('aria-expanded', 'false');
      }
    });
  });

  var el = document.getElementById('ngw-local-datetime');
  if (!el) return;
  var out = el.querySelector('span');
  var zone = '';
  try { zone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
  function updateLocalClock() {
    var now = new Date();
    try {
      var date = new Intl.DateTimeFormat(undefined, {year:'numeric',month:'short',day:'2-digit'}).format(now);
      var time = new Intl.DateTimeFormat(undefined, {hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true}).format(now);
      out.textContent = date + ' · ' + time;
      el.title = 'Browser local time' + (zone ? ' (' + zone + ')' : '');
    } catch (e) {
      out.textContent = now.toLocaleString();
    }
  }
  updateLocalClock();
  window.setInterval(updateLocalClock, 1000);

  <?php if (!$isLogoutReq) { ?>
  var pingInFlight = false;
  function sendSessionHeartbeat() {
    if (pingInFlight || !window.jQuery) return;
    pingInFlight = true;
    var cpageEl = document.getElementById('cpage');
    var pageText = cpageEl ? (cpageEl.textContent || '').trim() : '';
    $.ajax({
      url: './status/session_ping.php',
      method: 'POST',
      dataType: 'json',
      timeout: 3000,
      data: {
        tz: zone || '',
        page: pageText
      },
      success: function (res) {
        if (res && res.revoked) {
          try {
            sessionStorage.removeItem('ngw_tab_auth');
            sessionStorage.removeItem('ngw_last_act');
          } catch (e) {}
          window.location.replace('./admin.php?id=login&reason=force_logout');
        }
      },
      complete: function () {
        pingInFlight = false;
      }
    });
  }
  sendSessionHeartbeat();
  window.setInterval(sendSessionHeartbeat, 4000);
  <?php } ?>
})();
</script>
<div id="main">  
<div id="loading" class="lds-dual-ring"></div>
<?php if($hotspot == 'template-editor' || $id == 'editor'){
echo '<div class="main-container">';
}else{
  echo '<div class="main-container" style="display:none">';
}
?>
