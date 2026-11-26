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
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
} else {
  include_once(__DIR__ . '/../include/reseller_acl.php');
  if (!hasResellerPermission('manage_router_settings')) {
    http_response_code(403);
    exit('Access denied.');
  }
  $isNewRouterAction = ($id == "settings" && explode("-", (string)$router)[0] == "new");
  if (!$isNewRouterAction && isset($session) && (string)$session !== '' && !canAccessRouterSession((string)$session)) {
    http_response_code(403);
    exit('Access denied.');
  }
  $settingsCsrfToken = ngwCsrfToken('ngw_settings_csrf');
  if (is_file('./include/data_recovery.php')) {
    include_once('./include/data_recovery.php');
  }

  // Always reload config.php with OPcache invalidated so $data reflects disk state
  if (function_exists('ngwInvalidatePhpFileCache')) {
    ngwInvalidatePhpFileCache('./include/config.php');
  }
  if (is_file('./include/config.php')) {
    include('./include/config.php');
  }

  // Legacy GET links using ?id=settings&router=new-XXXX should never pre-create blank routers in config.php
  if ($id == "settings" && explode("-", (string)$router)[0] == "new" && !isset($_POST['save'])) {
    session_write_close();
    echo "<script>window.location.replace('./admin.php?id=settings&session=new')</script>";
    exit;
  }

  $isNewRouterMode = ($id == "settings" && (
    (string)$session === 'new' ||
    explode('-', (string)$router)[0] === 'new' ||
    (explode('-', (string)$session)[0] === 'new' && !isset($data[$session]))
  ));
  if (!$isNewRouterMode && isset($session) && (string)$session !== '' && !canAccessRouterSession((string)$session)) {
    http_response_code(403);
    exit('Access denied.');
  }

  if (!$isNewRouterMode && isset($data[$session]) && is_file('./include/readcfg.php')) {
    include('./include/readcfg.php');
  } else {
    $iphost = '';
    $userhost = '';
    $passwdhost = '';
    $hotspotname = '';
    $dnsname = '';
    $currency = '';
    $areload = '';
    $iface = '';
    $infolp = '';
    $idleto = '';
    $livereport = '';
  }

  $settingsSaveError = '';
  $postedDisplaySession = null;
  $postedPassPlain = null;

  if (isset($_POST['save'])) {
    if (!ngwValidateCsrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '', 'ngw_settings_csrf')) {
      $settingsSaveError = (isset($langid) && $langid === 'bn')
        ? 'সিকিউরিটি টোকেন অবৈধ বা মেয়াদোত্তীর্ণ। পেজটি রিলোড করে আবার চেষ্টা করুন।'
        : 'Security token expired or invalid. Reload this page and try again.';
    } else {
    $raw_ipmik = preg_replace('/\s+/', '', isset($_POST['ipmik']) ? (string)$_POST['ipmik'] : '');
    $siphost = str_replace("'", '', $raw_ipmik);
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $siphost, $schemeMatch)) {
      $siphost = substr($siphost, strlen($schemeMatch[0]));
      if (preg_match('/:\d+$/', $siphost, $portMatch) && (int)substr($portMatch[0], 1) === 22) {
        $siphost = substr($siphost, 0, -strlen($portMatch[0]));
      }
    }
    $suserhost = str_replace("'", '', isset($_POST['usermik']) ? trim((string)$_POST['usermik']) : '');
    $rawPassPost = isset($_POST['passmik']) ? (string)$_POST['passmik'] : '';
    $spasswdhost = encrypt($rawPassPost);
    $shotspotname = str_replace("'", '', isset($_POST['hotspotname']) ? trim((string)$_POST['hotspotname']) : '');
    $sdnsname = str_replace("'", '', isset($_POST['dnsname']) ? trim((string)$_POST['dnsname']) : '');
    $scurrency = str_replace("'", '', isset($_POST['currency']) ? trim((string)$_POST['currency']) : 'Tk');
    if ($scurrency === '') {
      $scurrency = 'Tk';
    }
    $sreload = isset($_POST['areload']) ? (int)$_POST['areload'] : 10;
    if ($sreload < 10) {
      $sreload = 10;
    }
    $siface = str_replace("'", '', isset($_POST['iface']) ? trim((string)$_POST['iface']) : '1');
    if ($siface === '') {
      $siface = '1';
    }
    $sinfolp = isset($_POST['infolp']) ? implode(unpack('H*', (string)$_POST['infolp'])) : (isset($infolp) ? (string)$infolp : '');
    $sidleto = str_replace("'", '', isset($_POST['idleto']) ? trim((string)$_POST['idleto']) : '10');
    if ($sidleto === '') {
      $sidleto = '10';
    }
    $rawDisplaySes = trim(isset($_POST['sessname']) ? (string)$_POST['sessname'] : ($isNewRouterMode ? '' : (string)$session));
    $rawDisplaySes = str_replace(array("'", '"', "\\", "\r", "\n", "<", ">"), '', $rawDisplaySes);
    $rawDisplaySes = preg_replace('/\s+/', ' ', $rawDisplaySes);
    if (function_exists('mb_substr')) {
      $rawDisplaySes = mb_substr($rawDisplaySes, 0, 60, 'UTF-8');
    } else {
      $rawDisplaySes = substr($rawDisplaySes, 0, 60);
    }
    $currentDisplaySes = (!$isNewRouterMode && function_exists('getRouterDisplaySessionName')) ? getRouterDisplaySessionName((string)$session) : ($isNewRouterMode ? '' : (string)$session);

    $slivereport = str_replace("'", '', isset($_POST['livereport']) ? trim((string)$_POST['livereport']) : (isset($livereport) && $livereport !== '' ? (string)$livereport : 'disable'));
    if ($slivereport === '') {
      $slivereport = 'disable';
    }

    // Preserve posted values in case validation or write fails
    $postedDisplaySession = $rawDisplaySes;
    $postedPassPlain = $rawPassPost;
    $iphost = $siphost;
    $userhost = $suserhost;
    $hotspotname = $shotspotname;
    $dnsname = $sdnsname;
    $currency = $scurrency;
    $areload = (string)$sreload;
    $iface = $siface;
    $idleto = $sidleto;
    $livereport = $slivereport;

    if ($isNewRouterMode) {
      if ($rawDisplaySes === '' || strtolower($rawDisplaySes) === 'mikhmon' || strtolower($rawDisplaySes) === 'new' || preg_match('/^new-\d+$/i', $rawDisplaySes)) {
        $settingsSaveError = (isset($langid) && $langid === 'bn')
          ? 'অনুগ্রহ করে একটি সঠিক সেশন নাম লিখুন (খালি, "new" বা "mikhmon" দেওয়া যাবে না)।'
          : 'Please enter a valid Session Name (cannot be empty, "new", or "mikhmon").';
      } elseif ($siphost === '' || $suserhost === '' || $rawPassPost === '' || $shotspotname === '' || $sdnsname === '') {
        $settingsSaveError = (isset($langid) && $langid === 'bn')
          ? 'রাউটার সেভ করতে আইপি, ইউজারনেম, পাসওয়ার্ড, হটস্পট নাম ও ডিএনএস নাম সঠিকভাবে পূরণ করুন।'
          : 'Please fill in IP, Username, Password, Hotspot Name, and DNS Name before saving.';
      }
    } else {
      if ($rawDisplaySes === '' || strtolower($rawDisplaySes) === 'mikhmon') {
        $rawDisplaySes = $currentDisplaySes !== '' ? $currentDisplaySes : (string)$session;
        $postedDisplaySession = $rawDisplaySes;
      }
      if ($siphost === '' || $suserhost === '' || $shotspotname === '' || $sdnsname === '') {
        $settingsSaveError = (isset($langid) && $langid === 'bn')
          ? 'রাউটারের আইপি, ইউজারনেম, হটস্পট নাম ও ডিএনএস নাম খালি রাখা যাবে না।'
          : 'Router IP, Username, Hotspot Name, and DNS Name cannot be empty.';
      }
    }

    if ($settingsSaveError === '') {
    // Determine collision-free internal key for config.php while allowing identical Display Session Names (groups)
    $existingDataKeys = (isset($data) && is_array($data)) ? array_keys($data) : array();
    $oldSessionArg = $isNewRouterMode ? '' : (string)$session;
    $desiredAsciiKey = preg_replace('/\s+/', '-', $rawDisplaySes);
    $isCleanAsciiDesired = (bool)preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $desiredAsciiKey) && strtolower($desiredAsciiKey) !== 'mikhmon';

    $keyTakenByOther = false;
    if ($isCleanAsciiDesired) {
      foreach ($existingDataKeys as $existingKey) {
        if ($existingKey === 'mikhmon' || (!$isNewRouterMode && strcasecmp((string)$existingKey, (string)$session) === 0)) {
          continue;
        }
        if (strcasecmp((string)$existingKey, $desiredAsciiKey) === 0) {
          $keyTakenByOther = true;
          break;
        }
      }
    }

    if ($isNewRouterMode) {
      // Brand new router: pick a unique internal key (e.g. Shahin, Shahin_2, Shahin_3...) without ever overwriting existing routers
      $sesname = function_exists('generateUniqueRouterSessionKey')
        ? generateUniqueRouterSessionKey($rawDisplaySes, $existingDataKeys, '')
        : ($isCleanAsciiDesired && !$keyTakenByOther ? $desiredAsciiKey : ('router_' . time()));
    } elseif ($rawDisplaySes === $currentDisplaySes) {
      // Existing router saved with same display session name: keep its unique internal key intact
      $sesname = (string)$session;
    } elseif ($isCleanAsciiDesired && !$keyTakenByOther) {
      // Existing router renamed to a free ASCII session name: rename internal key cleanly
      $sesname = $desiredAsciiKey;
    } else {
      // Existing router moved into an already-used session group name (or non-ASCII name):
      // Keep its own unique internal key so logos, reseller assignments, history, and other admins' routers are never disturbed
      $sesname = (string)$session;
    }

    $newRouterLine = "\$data['" . $sesname . "'] = array ('1'=>'" . $sesname . "!" . $siphost . "','" . $sesname . "@|@" . $suserhost . "','" . $sesname . "#|#" . $spasswdhost . "','" . $sesname . "%" . $shotspotname . "','" . $sesname . "^" . $sdnsname . "','" . $sesname . "&" . $scurrency . "','" . $sesname . "*" . $sreload . "','" . $sesname . "(" . $siface . "','" . $sesname . ")" . $sinfolp . "','" . $sesname . "=" . $sidleto . "','" . $sesname . "@!@" . $slivereport . "');";

    $configPath = './include/config.php';
    $savedOk = false;
    if (function_exists('ngwSaveRouterSessionToConfig')) {
      $savedOk = ngwSaveRouterSessionToConfig($oldSessionArg, $sesname, $newRouterLine, $configPath);
    } else {
      $content = @file_get_contents($configPath);
      if ($content !== false) {
        $lines = preg_split('/\r?\n/', $content);
        $outLines = array();
        $found = false;
        foreach ($lines as $ln) {
          if ($oldSessionArg !== '' && (strpos($ln, "\$data['" . $oldSessionArg . "']") !== false || strpos($ln, '$data["' . $oldSessionArg . '"]') !== false)) {
            $outLines[] = $newRouterLine;
            $found = true;
          } elseif ($sesname !== $oldSessionArg && (strpos($ln, "\$data['" . $sesname . "']") !== false || strpos($ln, '$data["' . $sesname . '"]') !== false)) {
            continue;
          } else {
            $outLines[] = $ln;
          }
        }
        if (!$found) {
          $outLines[] = $newRouterLine;
        }
        $savedOk = (@file_put_contents($configPath, implode("\n", $outLines) . "\n", LOCK_EX) !== false);
        if ($savedOk && function_exists('recordRouterOwnerOnCreateOrRename')) {
          recordRouterOwnerOnCreateOrRename($oldSessionArg, $sesname);
        }
      }
    }

    if (!$savedOk) {
      $_SESSION['connect'] = "";
      $settingsSaveError = (isset($langid) && $langid === 'bn')
        ? 'রাউটার সেটিংস সেভ করা যায়নি: config.php রাইটেবল নয়।'
        : 'Could not save router settings: config.php is not writable.';
    } else {
      if (function_exists('setRouterDisplaySessionName')) {
        setRouterDisplaySessionName($sesname, $rawDisplaySes);
      }
      if (function_exists('ngwRecordActivity')) {
        ngwRecordActivity('router_settings_saved', array(
          'router' => (string)$sesname,
          'session_group' => (string)$rawDisplaySes,
          'host' => (string)$siphost,
          'user' => (string)$suserhost,
        ));
      }
      @session_start();
      $_SESSION['connect'] = '';
      $_SESSION['router_settings_notice'] = (isset($langid) && $langid === 'bn')
        ? ($rawDisplaySes . ($shotspotname !== '' ? (' (' . $shotspotname . ')') : '') . ' এর জন্য রাউটার সেটিংস সফলভাবে সেভ হয়েছে।')
        : ('Router settings saved successfully for ' . $rawDisplaySes . ($shotspotname !== '' ? (' (' . $shotspotname . ')') : '') . '.');
      session_write_close();
      echo '<script>window.location.replace(' . json_encode('./admin.php?id=settings&session=' . rawurlencode($sesname), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ')</script>';
      exit;
    }
    }
    }
  }
  if ($currency == "") {
    $currency = "Tk";
  }
  if ($areload == "") {
    $areload = "10";
  }
  if ($iface == "") {
    $iface = "1";
  }
  if ($idleto == "") {
    $idleto = "10";
  }
  if ($livereport == "") {
    $livereport = "disable";
  }
  $display_iphost = $iphost;
  if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $display_iphost, $schemeMatch)) {
    $display_iphost = substr($display_iphost, strlen($schemeMatch[0]));
    if (preg_match('/:\d+$/', $display_iphost, $portMatch) && (int)substr($portMatch[0], 1) === 22) {
      $display_iphost = substr($display_iphost, 0, -strlen($portMatch[0]));
    }
  }
}
?>
<script>
  function PassMk(){
    var x = document.getElementById('passmk');
    if (x.type === 'password') {
    x.type = 'text';
    } else {
    x.type = 'password';
    }}
    function PassAdm(){
    var x = document.getElementById('passadm');
    if (x.type === 'password') {
    x.type = 'text';
    } else {
    x.type = 'password';
  }}

</script>

<?php
$ngwIsGlassOsSettings = (function_exists('ngwResolveActiveUiStyle') && ngwResolveActiveUiStyle() === 'aurora');
$isBnSettings = (isset($langid) && $langid === 'bn');
$inputDisplaySession = ($postedDisplaySession !== null)
  ? $postedDisplaySession
  : ($isNewRouterMode
      ? ''
      : (function_exists('getRouterDisplaySessionName') ? getRouterDisplaySessionName((string)$session) : (string)$session));
$inputPassPlain = ($postedPassPlain !== null)
  ? $postedPassPlain
  : ($isNewRouterMode ? '' : decrypt($passwdhost));
$existingGroupSuggestions = array();
if (isset($data) && is_array($data)) {
  foreach (array_keys($data) as $candKey) {
    if ($candKey === 'mikhmon' || explode('-', (string)$candKey)[0] === 'new') continue;
    if (!canAccessRouterSession((string)$candKey)) continue;
    $candDisp = function_exists('getRouterDisplaySessionName') ? getRouterDisplaySessionName((string)$candKey) : (string)$candKey;
    $candNorm = function_exists('mb_strtolower') ? mb_strtolower($candDisp, 'UTF-8') : strtolower($candDisp);
    if ($candDisp !== '' && !isset($existingGroupSuggestions[$candNorm])) {
      $existingGroupSuggestions[$candNorm] = $candDisp;
    }
  }
  natcasesort($existingGroupSuggestions);
}
?>
<form autocomplete="off" method="post" action="" name="settings">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($settingsCsrfToken, ENT_QUOTES, 'UTF-8'); ?>"/>

<?php if ($ngwIsGlassOsSettings) { ?>
<div class="ngw-gl-studio">
  <div class="ngw-gl-hero">
    <div class="ngw-gl-hero-left">
      <div class="ngw-gl-hero-icon" aria-hidden="true"><i class="fa <?= $isNewRouterMode ? 'fa-plus-circle' : 'fa-sliders'; ?>"></i></div>
      <div>
        <h2 class="ngw-gl-hero-title">
          <?= $isNewRouterMode ? ($isBnSettings ? 'নতুন রাউটার যুক্ত করুন' : 'Add New MikroTik Router') : $_session_settings; ?>
        </h2>
        <div class="ngw-gl-hero-sub">
          <span><?= $isBnSettings ? '৩ ধাপে রাউটার সেশন, API সংযোগ ও হটস্পট সেটিংস কনফিগার করুন' : 'Configure router identity, MikroTik API credentials & hotspot preferences in 3 clear steps'; ?></span>
        </div>
      </div>
    </div>
    <div class="ngw-gl-hero-actions">
      <a href="./admin.php?id=sessions" class="ngw-glass-btn">
        <i class="fa fa-arrow-left" aria-hidden="true"></i>
        <span><?= $_router_list ?></span>
      </a>
      <button type="button" class="ngw-glass-btn" onclick="location.reload();" title="Reload data">
        <i class="fa fa-refresh" aria-hidden="true"></i>
        <span><?= $isBnSettings ? 'রিলোড' : 'Reload'; ?></span>
      </button>
    </div>
  </div>

  <?php if ($settingsSaveError !== '') { ?>
    <div class="alert alert-danger" role="alert">
      <i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <?= htmlspecialchars($settingsSaveError, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php } ?>

  <?php if (!empty($_SESSION['router_settings_notice'])) { ?>
    <div class="alert alert-success" role="status">
      <i class="fa fa-check-circle" aria-hidden="true"></i> <?= htmlspecialchars($_SESSION['router_settings_notice'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php unset($_SESSION['router_settings_notice']); ?>
  <?php } ?>

  <div class="ngw-gl-studio-grid">
    <div class="ngw-gl-studio-steps">
      <!-- Step 01: Router Identity & Group -->
      <section class="ngw-gl-step-card">
        <div class="ngw-gl-step-head">
          <span class="ngw-gl-step-num">01</span>
          <div>
            <h3 class="ngw-gl-step-title"><?= $_session ?></h3>
            <div class="ngw-gl-step-desc"><?= $isBnSettings ? 'রাউটারের সেশন বা গ্রুপের নাম লিখুন (একই নামের গ্রুপে একাধিক রাউটার রাখা যায়)' : 'Name this router session or pick an existing group label'; ?></div>
          </div>
        </div>
        <div class="ngw-gl-form-grid">
          <div class="ngw-gl-fgroup is-full">
            <label for="sessname"><i class="fa fa-tag" aria-hidden="true"></i> <?= $_session_name ?></label>
            <input class="form-control" id="sessname" type="text" name="sessname" list="ngw-session-name-suggestions" placeholder="<?= $isBnSettings ? 'যেমন: Main-Branch বা Zone-1' : 'e.g. Main-Branch or Zone-1'; ?>" title="Session Name" value="<?= htmlspecialchars($inputDisplaySession, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
            <?php if (!empty($existingGroupSuggestions)) { ?>
            <datalist id="ngw-session-name-suggestions">
              <?php foreach ($existingGroupSuggestions as $suggName) { ?>
                <option value="<?= htmlspecialchars($suggName, ENT_QUOTES, 'UTF-8'); ?>"></option>
              <?php } ?>
            </datalist>
            <div class="ngw-gl-sugg-chips">
              <?php $suggIdx = 0; foreach ($existingGroupSuggestions as $suggName) { if ($suggIdx >= 8) break; $suggIdx++; ?>
              <button type="button" class="ngw-glass-btn ngw-gl-sugg-chip" onclick='var el=document.getElementById("sessname"); if(el){el.value=<?= json_encode((string)$suggName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>; el.dispatchEvent(new Event("input"));}'>
                <i class="fa fa-folder-o" aria-hidden="true"></i> <?= htmlspecialchars($suggName, ENT_QUOTES, 'UTF-8'); ?>
              </button>
              <?php } ?>
            </div>
            <?php } ?>
          </div>
        </div>
      </section>

      <!-- Step 02: MikroTik API Credentials -->
      <section class="ngw-gl-step-card">
        <div class="ngw-gl-step-head">
          <span class="ngw-gl-step-num">02</span>
          <div>
            <h3 class="ngw-gl-step-title"><?= $isBnSettings ? 'মাইক্রোটিক API সংযোগ' : 'MikroTik API Connection'; ?> <?= $_SESSION["connect"]; ?></h3>
            <div class="ngw-gl-step-desc"><?= $isBnSettings ? 'রাউটারের আইপি/ক্লাউড ঠিকানা এবং API ইউজার ও পাসওয়ার্ড দিন' : 'Router IP or IP Cloud host along with API credentials'; ?></div>
          </div>
        </div>
        <div class="ngw-gl-form-grid">
          <div class="ngw-gl-fgroup is-full">
            <label for="ipmik_input"><i class="fa fa-globe" aria-hidden="true"></i> <?= $isBnSettings ? 'মাইক্রোটিক আইপি / হোস্ট' : 'IP MikroTik / Cloud Host'; ?></label>
            <input class="form-control" id="ipmik_input" type="text" name="ipmik" placeholder="192.168.88.1 or host:port" title="IP MikroTik / IP Cloud MikroTik" value="<?= htmlspecialchars($display_iphost, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
          </div>
          <div class="ngw-gl-fgroup">
            <label for="usermk"><i class="fa fa-user" aria-hidden="true"></i> <?= $_user_name ?></label>
            <input class="form-control" id="usermk" type="text" name="usermik" title="User MikroTik" value="<?= htmlspecialchars($userhost, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
          </div>
          <div class="ngw-gl-fgroup">
            <label for="passmk"><i class="fa fa-key" aria-hidden="true"></i> <?= $_password ?></label>
            <div class="ngw-gl-input-unit">
              <input class="form-control" id="passmk" type="password" name="passmik" title="Password MikroTik" value="<?= htmlspecialchars($inputPassPlain, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
              <button type="button" class="ngw-glass-btn" style="height:42px;width:44px;padding:0;flex-shrink:0;" onclick="PassMk()" title="Show/Hide Password" aria-label="Show/Hide Password">
                <i class="fa fa-eye" aria-hidden="true"></i>
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- Step 03: Hotspot & Display Preferences -->
      <section class="ngw-gl-step-card">
        <div class="ngw-gl-step-head">
          <span class="ngw-gl-step-num">03</span>
          <div>
            <h3 class="ngw-gl-step-title"><?= $isBnSettings ? 'হটস্পট ও লাইভ ডেটা সেটিংস' : 'Hotspot & Live Preferences'; ?></h3>
            <div class="ngw-gl-step-desc"><?= $isBnSettings ? 'ভাউচারে প্রদর্শিত হটস্পট নাম, DNS, কারেন্সি এবং লাইভ ট্রাফিক ইন্টারফেস' : 'Hotspot branding, login DNS, currency, and live reporting options'; ?></div>
          </div>
        </div>
        <div class="ngw-gl-form-grid">
          <div class="ngw-gl-fgroup">
            <label for="ngw_gl_hsname"><i class="fa fa-wifi" aria-hidden="true"></i> <?= $_hotspot_name ?></label>
            <input class="form-control" id="ngw_gl_hsname" type="text" maxlength="50" name="hotspotname" title="Hotspot Name" value="<?= htmlspecialchars($hotspotname, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
          </div>
          <div class="ngw-gl-fgroup">
            <label for="ngw_gl_dns"><i class="fa fa-link" aria-hidden="true"></i> <?= $_dns_name ?></label>
            <input class="form-control" id="ngw_gl_dns" type="text" maxlength="500" name="dnsname" title="DNS Name [IP->Hotspot->Server Profiles->DNS Name]" value="<?= htmlspecialchars($dnsname, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
          </div>
          <div class="ngw-gl-fgroup">
            <label for="ngw_gl_curr"><i class="fa fa-money" aria-hidden="true"></i> <?= $_currency ?></label>
            <input class="form-control" id="ngw_gl_curr" type="text" maxlength="4" name="currency" title="currency" value="<?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
          </div>
          <div class="ngw-gl-fgroup">
            <label><i class="fa fa-refresh" aria-hidden="true"></i> <?= $_auto_reload ?></label>
            <div class="ngw-gl-input-unit">
              <input class="form-control" type="number" min="10" max="3600" name="areload" title="Auto Reload in sec [min 10]" value="<?= htmlspecialchars($areload, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
              <span class="ngw-gl-unit-badge"><?= $_sec ?></span>
            </div>
          </div>
          <div class="ngw-gl-fgroup">
            <label><i class="fa fa-clock-o" aria-hidden="true"></i> <?= $_idle_timeout ?></label>
            <div class="ngw-gl-input-unit">
              <select class="form-control" name="idleto" required="1">
                <option value="<?= htmlspecialchars($idleto, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($idleto, ENT_QUOTES, 'UTF-8'); ?></option>
                <option value="5">5</option>
                <option value="10">10</option>
                <option value="30">30</option>
                <option value="60">60</option>
                <option value="disable"><?= isset($_disable) ? $_disable : 'disable' ?></option>
              </select>
              <span class="ngw-gl-unit-badge"><?= $_min ?></span>
            </div>
          </div>
          <div class="ngw-gl-fgroup">
            <label><i class="fa fa-area-chart" aria-hidden="true"></i> <?= $_traffic_interface ?></label>
            <input class="form-control" type="number" min="1" max="99" name="iface" title="Traffic Interface" value="<?= htmlspecialchars($iface, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
          </div>
          <?php if (!empty($livereport)) { ?>
          <div class="ngw-gl-fgroup is-full">
            <label><i class="fa fa-line-chart" aria-hidden="true"></i> <?= $_live_report ?></label>
            <select class="form-control" name="livereport">
              <option value="<?= htmlspecialchars($livereport, ENT_QUOTES, 'UTF-8'); ?>"><?= ($livereport === 'enable') ? (isset($_enable) ? $_enable : 'Enable') : (isset($_disable) ? $_disable : 'Disable'); ?></option>
              <option value="enable"><?= isset($_enable) ? $_enable : 'Enable' ?></option>
              <option value="disable"><?= isset($_disable) ? $_disable : 'Disable' ?></option>
            </select>
          </div>
          <?php } ?>
        </div>
      </section>
    </div>

    <!-- Sticky Right Live Preview & Action Console -->
    <aside class="ngw-gl-studio-aside">
      <div class="ngw-gl-preview-card">
        <div class="ngw-gl-preview-label">
          <i class="fa fa-eye" aria-hidden="true"></i>
          <span><?= $isBnSettings ? 'লাইভ রাউটার প্রিভিউ' : 'Live Router Preview'; ?></span>
        </div>
        <div class="ngw-gl-rcard-top">
          <div class="ngw-gl-rcard-ico" style="cursor:default;"><i class="fa fa-server"></i></div>
          <div class="ngw-gl-rcard-meta">
            <div class="ngw-gl-rcard-name" id="ngwGlPrevHs"><?= htmlspecialchars($hotspotname !== '' ? $hotspotname : ($isBnSettings ? 'নতুন হটস্পট' : 'New Hotspot'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="ngw-gl-rcard-sub">
              <span class="ngw-gl-chip is-accent" id="ngwGlPrevSess"><?= htmlspecialchars($inputDisplaySession !== '' ? $inputDisplaySession : ($isBnSettings ? 'সেশন গ্রুপ' : 'Session Group'), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
          </div>
        </div>

        <div class="ngw-gl-preview-rows">
          <div class="ngw-gl-preview-row">
            <span><?= $isBnSettings ? 'আইপি/হোস্ট' : 'Host IP'; ?></span>
            <b id="ngwGlPrevIp"><?= htmlspecialchars($display_iphost !== '' ? $display_iphost : '—', ENT_QUOTES, 'UTF-8'); ?></b>
          </div>
          <div class="ngw-gl-preview-row">
            <span><?= $isBnSettings ? 'ডিএনএস' : 'DNS'; ?></span>
            <b id="ngwGlPrevDns"><?= htmlspecialchars($dnsname !== '' ? $dnsname : '—', ENT_QUOTES, 'UTF-8'); ?></b>
          </div>
          <div class="ngw-gl-preview-row">
            <span><?= $isBnSettings ? 'ইউজার' : 'API User'; ?></span>
            <b id="ngwGlPrevUser"><?= htmlspecialchars($userhost !== '' ? $userhost : '—', ENT_QUOTES, 'UTF-8'); ?></b>
          </div>
        </div>

        <div class="ngw-gl-studio-actions">
          <button type="submit" name="save" value="<?= htmlspecialchars($_save, ENT_QUOTES, 'UTF-8'); ?>" class="ngw-glass-btn is-primary" style="width:100%;height:42px;font-size:14px;">
            <i class="fa fa-save" aria-hidden="true"></i>
            <span><?= $_save ?></span>
          </button>
          <div class="ngw-gl-action-row">
            <?php if ($isNewRouterMode) { ?>
            <button type="button" class="ngw-glass-btn" disabled style="height:38px;opacity:0.5;cursor:not-allowed;" title="<?= htmlspecialchars($isBnSettings ? 'প্রথমে রাউটার সেভ করুন' : 'Save router settings first', ENT_QUOTES, 'UTF-8'); ?>">
              <i class="fa fa-external-link" aria-hidden="true"></i>
              <span><?= $isBnSettings ? 'ওপেন' : 'Open'; ?></span>
            </button>
            <button type="button" class="ngw-glass-btn" id="ping_test" disabled style="height:38px;opacity:0.5;cursor:not-allowed;" title="<?= htmlspecialchars($isBnSettings ? 'প্রথমে রাউটার সেভ করুন' : 'Save router settings first', ENT_QUOTES, 'UTF-8'); ?>">
              <i class="fa fa-signal" aria-hidden="true"></i>
              <span><?= isset($_ping) ? $_ping : 'Ping'; ?></span>
            </button>
            <?php } else { ?>
            <button type="button" class="ngw-glass-btn is-success connect" id="<?= htmlspecialchars($session, ENT_QUOTES, 'UTF-8'); ?>&c=settings" style="height:38px;">
              <i class="fa fa-external-link" aria-hidden="true"></i>
              <span><?= $isBnSettings ? 'ওপেন' : 'Open'; ?></span>
            </button>
            <button type="button" class="ngw-glass-btn" id="ping_test" style="height:38px;">
              <i class="fa fa-signal" aria-hidden="true"></i>
              <span><?= isset($_ping) ? $_ping : 'Ping'; ?></span>
            </button>
            <?php } ?>
          </div>
        </div>
      </div>

      <div id="ping"></div>
    </aside>
  </div>
</div>
<?php } else { ?>
<div class="row">
	<div class="col-12">
  		<div class="card" >
  			<div class="card-header" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 12px;">
  				<h3 class="card-title"><i class="fa <?= $isNewRouterMode ? 'fa-plus-circle' : 'fa-gear'; ?>"></i> <?= $isNewRouterMode ? $_add_router : $_session_settings ?> &nbsp; | &nbsp;&nbsp;<i onclick="location.reload();" class="fa fa-refresh pointer " title="Reload data"></i></h3>
          <a href="./admin.php?id=sessions" class="btn btn-sm bg-secondary" style="display:inline-flex;align-items:center;gap:5px;text-decoration:none;"><i class="fa fa-arrow-left"></i> <?= $_router_list ?></a>
  			</div>
        <div class="card-body">
          <?php if ($settingsSaveError !== '') { ?>
          <div class="alert alert-danger" role="alert"><i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($settingsSaveError, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php } ?>
          <?php if (!empty($_SESSION['router_settings_notice'])) { ?>
          <div class="alert alert-success" role="status"><?= htmlspecialchars($_SESSION['router_settings_notice'], ENT_QUOTES, 'UTF-8'); ?></div>
          <?php unset($_SESSION['router_settings_notice']); } ?>
    	   <div class="row">
			     <div class="col-6">
            <div class="col-12">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title"><?= $_session ?></h3>
                </div>
                <div class="card-body">
                  <table class="table ngw-form-stack-table">
                    <tr>
                      <td><?= $_session_name ?></td>
                      <td>
                        <input class="form-control" id="sessname" type="text" name="sessname" list="ngw-session-name-suggestions" title="Session Name" value="<?= htmlspecialchars($inputDisplaySession, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
                        <?php if (!empty($existingGroupSuggestions)) { ?>
                        <datalist id="ngw-session-name-suggestions">
                          <?php foreach ($existingGroupSuggestions as $suggName) { ?>
                            <option value="<?= htmlspecialchars($suggName, ENT_QUOTES, 'UTF-8'); ?>"></option>
                          <?php } ?>
                        </datalist>
                        <?php } ?>
                      </td>
                    </tr>
                  </table>
                </div>
              </div>
            </div>
            <div class="col-12">
				      <div class="card">
        	     <div class="card-header">
            	   <h3 class="card-title"><?= isset($langid) && $langid === 'bn' ? 'মাইক্রোটিক API' : 'MikroTik API' ?> <?= $_SESSION["connect"]; ?></h3>
        	     </div>
        	     <div class="card-body">
				<table class="table table-sm ngw-form-stack-table">
					<tr>
	  					<td class="align-middle"><?= isset($langid) && $langid === 'bn' ? 'মাইক্রোটিক আইপি' : 'IP MikroTik' ?> </td><td>
							<div class="input-group">
								<div class="input-group-12 col-box-12">
									<input class="form-control" id="ipmik_input" type="text" size="15" name="ipmik" title="IP MikroTik / IP Cloud MikroTik" value="<?= htmlspecialchars($display_iphost, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
								</div>
							</div>
						</td>
					</tr>
					<tr>
						<td class="align-middle"><?= $_user_name ?>  </td><td><input class="form-control" id="usermk" type="text" size="10" name="usermik" title="User MikroTik" value="<?= htmlspecialchars($userhost, ENT_QUOTES, 'UTF-8'); ?>" required="1"/></td>
					</tr>
					<tr>
						<td class="align-middle"><?= $_password ?>  </td><td>
							<div class="input-group">
								<div class="input-group-11 col-box-10">
        						<input class="group-item group-item-l" id="passmk" type="password" name="passmik" title="Password MikroTik" value="<?= htmlspecialchars($inputPassPlain, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
        						</div>
            					<div class="input-group-1 col-box-2">
            						<div class="group-item group-item-r pd-2p5 text-center align-middle">
                						<input title="Show/Hide Password" type="checkbox" onclick="PassMk()">
            						</div>
            					</div>
    						</div>
						</td>
					</tr>
					<tr>
						<td colspan="2">
								<div class="input-group-4">
									<input class="group-item group-item-l" type="submit" style="cursor: pointer;" name="save" value="<?= $_save ?>"/>
								</div>
								<div class="input-group-4">	
                  <?php if ($isNewRouterMode) { ?>
                  <span class="group-item group-item-md pd-2p5 text-center align-middle" style="opacity:0.5;cursor:not-allowed;" title="<?= htmlspecialchars($isBnSettings ? 'প্রথমে রাউটার সেভ করুন' : 'Save router settings first', ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-external-link"></i> <?= (isset($langid) && $langid === 'bn') ? 'ওপেন' : 'Open' ?></span>
                  <?php } else { ?>
                  <span class="connect pointer group-item group-item-md pd-2p5 text-center align-middle" id="<?= htmlspecialchars($session, ENT_QUOTES, 'UTF-8'); ?>&c=settings"><i class="fa fa-external-link"></i> <?= (isset($langid) && $langid === 'bn') ? 'ওপেন' : 'Open' ?></span>
                  <?php } ?>
								</div>
								<div class="input-group-3">	
                  <?php if ($isNewRouterMode) { ?>
                  <span class="group-item group-item-md pd-2p5 text-center align-middle" id="ping_test" style="opacity:0.5;cursor:not-allowed;" title="<?= htmlspecialchars($isBnSettings ? 'প্রথমে রাউটার সেভ করুন' : 'Save router settings first', ENT_QUOTES, 'UTF-8'); ?>"><?= isset($_ping) ? $_ping : 'Ping' ?></span>
                  <?php } else { ?>
                  <span class="pointer group-item group-item-md pd-2p5 text-center align-middle" id="ping_test"><?= isset($_ping) ? $_ping : 'Ping' ?></span>
                  <?php } ?>
              	</div>
              	<div class="input-group-1">	
									<div style="cursor: pointer;" class="group-item group-item-r pd-2p5 text-center" onclick="location.reload();" title="Reload Data"><i class="fa fa-refresh"></i></div>
								</div>
            		</div>	
    					</td>
    				</tr>
				</table>
			</div>
    </div>  	
    <div id="ping">
    </div>	
	</div>
</div>
<div class="col-6">
<div class="col-12">
	<div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= isset($langid) && $langid === 'bn' ? 'হটস্পট তথ্য (NextGan WiFi Solution)' : 'NextGan WiFi Solution Data' ?></h3>
        </div>
    <div class="card-body">    
	<table class="table table-sm ngw-form-stack-table">
	<tr>
	<td class="align-middle"><?= $_hotspot_name ?>  </td><td><input class="form-control" type="text" size="15" maxlength="50" name="hotspotname" title="Hotspot Name" value="<?= htmlspecialchars($hotspotname, ENT_QUOTES, 'UTF-8'); ?>" required="1"/></td>
	</tr>
	<tr>
	<td class="align-middle"><?= $_dns_name ?>  </td><td><input class="form-control" type="text" size="15" maxlength="500" name="dnsname" title="DNS Name [IP->Hotspot->Server Profiles->DNS Name]" value="<?= htmlspecialchars($dnsname, ENT_QUOTES, 'UTF-8'); ?>" required="1"/></td>
	</tr>
	<tr>
	<td class="align-middle"><?= $_currency ?>  </td><td><input class="form-control" type="text" size="3" maxlength="4" name="currency" title="currency" value="<?= htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?>" required="1"/></td>
	</tr>
	<tr> 
	<td class="align-middle"><?= $_auto_reload ?></td><td>
	<div class="input-group">
		<div class="input-group-10">
        	<input class="group-item group-item-l" type="number" min="10" max="3600" name="areload" title="Auto Reload in sec [min 10]" value="<?= htmlspecialchars($areload, ENT_QUOTES, 'UTF-8'); ?>" required="1"/>
    	</div>
            <div class="input-group-2">
                <span class="group-item group-item-r pd-2p5 text-center align-middle"><?= $_sec ?></span>
            </div>
        </div>
	</td>
  </tr>
  <tr>
  <td class="align-middle"><?= $_idle_timeout ?></td>
  <td>
  <div class="input-group">
  <div class="input-group-9">
      <select class="group-item group-item-l" name="idleto" required="1">
          <option value="<?= htmlspecialchars($idleto, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($idleto, ENT_QUOTES, 'UTF-8'); ?></option>
				  <option value="5">5</option>
          <option value="10">10</option>
          <option value="30">30</option>
          <option value="60">60</option>
          <option value="disable"><?= isset($_disable) ? $_disable : 'disable' ?></option>
      </select>
  </div>
  <div class="input-group-3">
                <span class="group-item group-item-r pd-3p5 text-center align-middle"><?= $_min ?></span>
            </div>
        </div>
    </td>
	</tr>
	<tr>
	<td class="align-middle"><?= $_traffic_interface ?></td><td><input class="form-control" type="number" min="1" max="99" name="iface" title="Traffic Interface" value="<?= htmlspecialchars($iface, ENT_QUOTES, 'UTF-8'); ?>" required="1"/></td>
	</tr>
  <?php if (empty($livereport)) {
  } else { ?>
  <tr>
    <td><?= $_live_report ?></td>
    <td>
      <select class="form-control" name="livereport" >
          <option value="<?= htmlspecialchars($livereport, ENT_QUOTES, 'UTF-8'); ?>"><?= ($livereport === 'enable') ? (isset($_enable) ? $_enable : 'Enable') : (isset($_disable) ? $_disable : 'Disable'); ?></option>
				  <option value="enable"><?= isset($_enable) ? $_enable : 'Enable' ?></option>
				  <option value="disable"><?= isset($_disable) ? $_disable : 'Disable' ?></option>
		  </select>
    </td>
  </tr>
  <?php 
} ?>
</table>
</div>
</div>
</div>
</div>
</div>
<?php } ?>
</form>
<script type="text/javascript">
(function () {
  var actualSessionKey = <?= json_encode((string)$session, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var isNewRouterMode = <?= $isNewRouterMode ? 'true' : 'false'; ?>;
  var pingBtn = document.getElementById('ping_test');
  if (pingBtn) {
    pingBtn.onclick = function () {
      if (isNewRouterMode || !actualSessionKey || actualSessionKey === 'new') return;
      if (window.jQuery) {
        window.jQuery('#ping').load('./status/ping-test.php?ping&session=' + encodeURIComponent(actualSessionKey));
      }
    };
  }
  window.closeX = function () {
    if (window.jQuery) {
      window.jQuery('#pingX').hide();
    } else {
      var el = document.getElementById('pingX');
      if (el) el.style.display = 'none';
    }
  };
  var sesInput = document.getElementById('sessname');
  function chksname() {
    if (!sesInput) return;
    var v = (sesInput.value || '').trim().toLowerCase();
    if (v === 'mikhmon') {
      sesInput.value = '';
    }
  }
  if (sesInput) {
    sesInput.onkeyup = chksname;
    sesInput.onchange = chksname;
  }

  // Live preview binding for Crystal Glass Router Studio
  function bindPreview(inputEl, targetId, fallback) {
    var target = document.getElementById(targetId);
    if (!inputEl || !target) return;
    var sync = function () {
      var val = (inputEl.value || '').trim();
      target.textContent = val !== '' ? val : fallback;
    };
    inputEl.addEventListener('input', sync);
    inputEl.addEventListener('change', sync);
  }
  bindPreview(document.getElementById('ngw_gl_hsname'), 'ngwGlPrevHs', <?= json_encode($isBnSettings ? 'নতুন হটস্পট' : 'New Hotspot'); ?>);
  bindPreview(sesInput, 'ngwGlPrevSess', <?= json_encode($isBnSettings ? 'সেশন গ্রুপ' : 'Session Group'); ?>);
  bindPreview(document.getElementById('ipmik_input'), 'ngwGlPrevIp', '—');
  bindPreview(document.getElementById('ngw_gl_dns'), 'ngwGlPrevDns', '—');
  bindPreview(document.getElementById('usermk'), 'ngwGlPrevUser', '—');
})();
</script>





