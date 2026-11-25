<?php
/*
 *  NextGan WiFi Solution — PPPoE Users / Secrets Management
 *  Features:
 *  - Full bilingual support: 100% English by default, 100% Bengali when toggled
 *  - Columns: Customer Name, Mobile Number, Area, Profile, MAC, Validity, Status, Action
 *  - 3-Dot Action Menu with: Details (IP, Username, Password, MAC, etc.), Edit, Enable/Disable, Delete
 *  - Dual Active & Offline stat box mechanism
 *  - Add Area modal & area tagging
 *  - Clean mobile-compact design
 */
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    echo "<script>window.location='./admin.php?id=login'</script>";
    return;
}

include_once __DIR__ . '/../include/config.php';
include_once __DIR__ . '/../include/ros_version.php';
include_once __DIR__ . '/../include/reseller_acl.php';
include_once __DIR__ . '/../include/pppoe_helper.php';
include_once __DIR__ . '/../lib/formatbytesbites.php';

requireResellerPermission('manage_pppoe', './admin.php?id=sessions');
requireResellerPermission('view_pppoe_users', './admin.php?id=sessions');

$session = isset($_GET['session']) ? (string)$_GET['session'] : '';
if ($session === '' || !canAccessRouterSession($session)) {
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    return;
}

$isRouterConnected = (isset($API) && is_object($API) && !empty($API->connected));
$routerOfflineNotice = '';
if (!$isRouterConnected) {
    $routerOfflineNotice = ($langid === 'bn')
        ? "রাউটার <b>" . htmlspecialchars($session) . "</b> অফলাইন অথবা API পোর্ট ৮৭২৮ এ পৌঁছানো যাচ্ছে না।"
        : "Router <b>" . htmlspecialchars($session) . "</b> is offline or unreachable via API port 8728.";
} else {
    // Ensure automated expiry background script & scheduler is deployed on router
    @ngwDeployPppoeMonitorScript($API);
}

// Process POST Actions: Toggle Enable/Disable, Delete, Add Area, Delete Area
$actionMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = isset($_POST['pppoe_action']) ? trim((string)$_POST['pppoe_action']) : '';
    $targetId   = isset($_POST['secret_id']) ? trim((string)$_POST['secret_id']) : '';
    $targetName = isset($_POST['secret_name']) ? trim((string)$_POST['secret_name']) : '';

    if ($postAction === 'toggle' && $targetId !== '') {
        requireResellerPermission('enable_disable_pppoe_user', './?pppoe=users&session=' . urlencode($session));
        $toState = isset($_POST['to_state']) && $_POST['to_state'] === 'yes' ? 'yes' : 'no';
        $API->comm('/ppp/secret/set', array(
            '.id' => $targetId,
            'disabled' => $toState,
        ));
        if ($toState === 'yes') {
            // Also disconnect active session when disabling
            $liveActs = $API->comm('/ppp/active/print', array('?name' => $targetName));
            if (is_array($liveActs)) {
                foreach ($liveActs as $la) {
                    if (!empty($la['.id'])) {
                        $API->comm('/ppp/active/remove', array('.id' => $la['.id']));
                    }
                }
            }
        }
        $actionMsg = ($langid === 'bn')
            ? "ইউজার <b>" . htmlspecialchars($targetName) . "</b> সফলভাবে " . ($toState === 'yes' ? 'নিষ্ক্রিয় (Disabled)' : 'সক্রিয় (Enabled)') . " করা হয়েছে।"
            : "User <b>" . htmlspecialchars($targetName) . "</b> has been " . ($toState === 'yes' ? 'disabled' : 'enabled') . ".";
        if (function_exists('ngwRecordActivity')) {
            ngwRecordActivity('pppoe_status_changed', array(
                'target' => $targetName,
                'status' => $toState === 'yes' ? 'Disabled' : 'Enabled',
                'router' => $session,
            ));
        }
    } elseif ($postAction === 'delete' && $targetId !== '') {
        requireResellerPermission('delete_pppoe_user', './?pppoe=users&session=' . urlencode($session));
        // Remove active session first
        $liveActs = $API->comm('/ppp/active/print', array('?name' => $targetName));
        if (is_array($liveActs)) {
            foreach ($liveActs as $la) {
                if (!empty($la['.id'])) {
                    $API->comm('/ppp/active/remove', array('.id' => $la['.id']));
                }
            }
        }
        $API->comm('/ppp/secret/remove', array('.id' => $targetId));
        $actionMsg = ($langid === 'bn')
            ? "ইউজার <b>" . htmlspecialchars($targetName) . "</b> সফলভাবে মুছে ফেলা হয়েছে।"
            : "User <b>" . htmlspecialchars($targetName) . "</b> deleted successfully.";
        if (function_exists('ngwRecordActivity')) {
            ngwRecordActivity('pppoe_user_deleted', array(
                'target' => $targetName,
                'router' => $session,
            ));
        }
    } elseif ($postAction === 'add_area') {
        $areaName = isset($_POST['area_name']) ? trim((string)$_POST['area_name']) : '';
        if ($areaName !== '') {
            ngwAddPppoeArea($session, $areaName);
            $actionMsg = ($langid === 'bn')
                ? "এরিয়া <b>" . htmlspecialchars($areaName) . "</b> সফলভাবে যোগ করা হয়েছে।"
                : "Area <b>" . htmlspecialchars($areaName) . "</b> added successfully.";
        }
    } elseif ($postAction === 'delete_area') {
        $areaName = isset($_POST['area_name']) ? trim((string)$_POST['area_name']) : '';
        if ($areaName !== '') {
            ngwDeletePppoeArea($session, $areaName);
            $actionMsg = ($langid === 'bn')
                ? "এরিয়া <b>" . htmlspecialchars($areaName) . "</b> মুছে ফেলা হয়েছে।"
                : "Area <b>" . htmlspecialchars($areaName) . "</b> deleted successfully.";
        }
    }
}

// On-the-fly expiry sync
if ($isRouterConnected) {
    @ngwSyncExpiredPppoeUsers($API);
}

// Fetch all PPPoE secrets and active sessions
$rawSecrets = $isRouterConnected ? $API->comm('/ppp/secret/print') : array();
if (!is_array($rawSecrets)) {
    $rawSecrets = array();
}

$rawActive = $isRouterConnected ? $API->comm('/ppp/active/print') : array();
$activeUserMap = array();
if (is_array($rawActive)) {
    foreach ($rawActive as $act) {
        if (!empty($act['name'])) {
            $activeUserMap[$act['name']] = $act;
        }
    }
}

// Available Areas for this session
$availableAreas = ngwGetPppoeAreas($session);

// Filter secrets for PPPoE service (service == 'pppoe' or service == 'any')
$secrets = array();
$profileList = array();
$countTotal = 0;
$countActive = 0;   // Subscription is active/valid (not expired and not disabled)
$countOnline = 0;   // Currently connected in /ppp/active
$countExpired = 0;
$countDisabled = 0;

$filterStatus  = isset($_GET['status']) ? trim((string)$_GET['status']) : 'all';
$filterProfile = isset($_GET['profile']) ? trim((string)$_GET['profile']) : '';
$filterArea    = isset($_GET['area']) ? trim((string)$_GET['area']) : '';
$searchQuery   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$nowTs = time();

foreach ($rawSecrets as $s) {
    $service = isset($s['service']) ? strtolower($s['service']) : 'any';
    if ($service !== 'pppoe' && $service !== 'any') {
        continue;
    }
    $countTotal++;

    $uName = isset($s['name']) ? (string)$s['name'] : '';
    $prof = isset($s['profile']) ? (string)$s['profile'] : 'default';
    if ($prof !== '' && !in_array($prof, $profileList, true)) {
        $profileList[] = $prof;
    }

    $isDisabled = isset($s['disabled']) && ($s['disabled'] === 'true' || $s['disabled'] === true || $s['disabled'] === 'yes');
    $comment = isset($s['comment']) ? (string)$s['comment'] : '';
    $parsedExp = ngwParsePppoeComment($comment);
    $isOnline = isset($activeUserMap[$uName]);
    if ($isOnline) {
        $countOnline++;
    }

    $status = 'active';
    if ($isDisabled) {
        $status = 'disabled';
        $countDisabled++;
    } elseif ($parsedExp['is_expired']) {
        $status = 'expired';
        $countExpired++;
    } else {
        $countActive++; // Valid subscription
    }

    // Determine Offline category: anyone who is not currently online
    $isOffline = !$isOnline;

    // Apply Status / Category Filters
    if ($filterStatus === 'active' && $status !== 'active') continue;
    if ($filterStatus === 'offline' && !$isOffline) continue;
    if ($filterStatus === 'expired' && $status !== 'expired') continue;
    if ($filterStatus === 'disabled' && $status !== 'disabled') continue;
    if ($filterProfile !== '' && $prof !== $filterProfile) continue;
    if ($filterArea !== '' && strtolower($parsedExp['area']) !== strtolower($filterArea)) continue;

    if ($searchQuery !== '') {
        $haystack = strtolower(
            $uName . ' ' .
            $parsedExp['customer_name'] . ' ' .
            $parsedExp['phone'] . ' ' .
            $parsedExp['area'] . ' ' .
            $prof . ' ' .
            (isset($s['caller-id']) ? $s['caller-id'] : '') . ' ' .
            (isset($s['remote-address']) ? $s['remote-address'] : '') . ' ' .
            $comment
        );
        if (strpos($haystack, strtolower($searchQuery)) === false) {
            continue;
        }
    }

    $s['status_computed'] = $status;
    $s['is_online'] = $isOnline;
    $s['is_offline'] = $isOffline;
    $s['parsed_exp'] = $parsedExp;
    $s['active_row'] = $isOnline ? $activeUserMap[$uName] : null;
    $secrets[] = $s;
}

// Calculate total offline users (both disconnected valid users + expired users + disabled users)
$countTotalOffline = $countTotal - $countOnline;

sort($profileList);
?>

<style>
.ngw-pppoe-tabs {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.ngw-pppoe-tab {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  height: 24px;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 600;
  text-decoration: none !important;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.15);
  color: inherit;
}
.ngw-pppoe-tab.is-active {
  background: #1f6feb;
  border-color: #388bfd;
  color: #fff !important;
}

/* Floating Action Dropdown Menu to prevent table overflow clipping */
#actionFloatingMenu {
  display: none;
  position: fixed;
  z-index: 999999;
  background: #1e293b;
  border: 1px solid rgba(255, 255, 255, 0.2);
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.6);
  border-radius: 4px;
  min-width: 145px;
  padding: 4px 0;
  text-align: left;
}
.floating-menu-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  font-size: 11px;
  color: #f1f5f9;
  text-decoration: none !important;
  cursor: pointer;
  background: transparent;
  border: none;
  width: 100%;
  box-sizing: border-box;
}
.floating-menu-item:hover {
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
}
@media (max-width: 767px) {
  .ngw-hide-sm {
    display: none !important;
  }
}
</style>

<div class="row">
  <div class="col-12">
    <div class="card box-bordered">
      <div class="card-header">
        <h3>
          <i class="fa fa-sitemap"></i> <?= ($langid === 'bn') ? 'পিপিপিওই ইউজার' : 'PPPoE Users'; ?>
          <span style="font-size: 14px">
            <?php if (hasResellerPermission('add_pppoe_user')) { ?>
            &nbsp; | &nbsp; <a href="./?pppoe=add-user&session=<?= urlencode($session); ?>" title="Add PPPoE User"><i class="fa fa-user-plus"></i> <?= ($langid === 'bn') ? 'ইউজার যুক্ত করুন' : 'Add User'; ?></a>
            <?php } ?>
            &nbsp; | &nbsp; <a href="javascript:void(0)" onclick="openAreaModal()" title="Manage Areas"><i class="fa fa-map-marker"></i> <?= ($langid === 'bn') ? 'অ্যাড এরিয়া' : 'Add Area'; ?></a>
            <?php if (hasResellerPermission('reset_pppoe')) { ?>
            &nbsp; | &nbsp; <a href="./?pppoe=reset&session=<?= urlencode($session); ?>" title="Reset PPPoE User"><i class="fa fa-refresh"></i> <?= ($langid === 'bn') ? 'রিসেট' : 'Reset'; ?></a>
            <?php } ?>
            &nbsp; | &nbsp; <a href="./?pppoe=users&session=<?= urlencode($session); ?>" title="Refresh"><i class="fa fa-refresh"></i> <?= ($langid === 'bn') ? 'রিলোড' : 'Reload'; ?></a>
          </span>
          <small id="loader" style="display: none;"><i><i class='fa fa-circle-o-notch fa-spin'></i> Processing... </i></small>
        </h3>
      </div>

      <div class="card-body">
        <?php if ($routerOfflineNotice !== '') { ?>
          <div class="bg-danger pd-5 radius-3 mr-b-10 text-white"><i class="fa fa-plug"></i> <?= $routerOfflineNotice; ?> &mdash; <?= ($langid === 'bn') ? 'দয়া করে রাউটার আইপি/পোর্ট চেক করুন অথবা ভিপিএন দিয়ে সংযুক্ত হোন।' : 'Please check router IP/Port or connect via VPN.'; ?></div>
        <?php } ?>
        <?php if ($actionMsg !== '') { ?>
          <div class="bg-green pd-5 radius-3 mr-b-10"><i class="fa fa-check-circle"></i> <?= $actionMsg; ?></div>
        <?php } ?>

        <!-- Stat Summary Boxes with Dual Active/Offline Mechanism -->
        <div class="row mr-b-10">
          <!-- Box 1: Total Users -->
          <div class="col-4 col-box-4">
            <div class="box bg-blue bmh-75">
              <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=all">
                <h1><?= (int)$countTotal; ?> <span style="font-size: 14px;"><?= ($langid === 'bn') ? 'মোট' : 'Total'; ?></span></h1>
                <div><i class="fa fa-users"></i> <?= ($langid === 'bn') ? 'মোট পিপিপিওই ইউজার' : 'Total PPPoE Users'; ?></div>
              </a>
            </div>
          </div>

          <!-- Box 2: Dual Active & Offline Mechanism Box -->
          <div class="col-4 col-box-4">
            <div class="box bg-green bmh-75" style="padding: 10px 14px;">
              <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                  <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=active" style="color:inherit; text-decoration:none;">
                    <h1 style="margin:0; font-size:22px;"><?= (int)$countActive; ?> <span style="font-size:12px; font-weight:normal;"><?= ($langid === 'bn') ? 'জন' : 'Users'; ?></span></h1>
                    <div style="font-size:11px; margin-top:2px;"><i class="fa fa-check-circle"></i> <?= ($langid === 'bn') ? 'অ্যাক্টিভ ইউজার' : 'Active Users'; ?></div>
                  </a>
                </div>
                <div style="text-align:right; border-left:1px solid rgba(255,255,255,0.3); padding-left:12px;">
                  <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=offline" style="color:inherit; text-decoration:none;">
                    <div style="font-size:18px; font-weight:700; color:#fde047;"><?= (int)$countTotalOffline; ?> <span style="font-size:12px; font-weight:normal;"><?= ($langid === 'bn') ? 'জন' : 'Users'; ?></span></div>
                    <div style="font-size:11px;"><i class="fa fa-power-off"></i> <?= ($langid === 'bn') ? 'অফলাইন ইউজার' : 'Offline Users'; ?></div>
                  </a>
                </div>
              </div>
            </div>
          </div>

          <!-- Box 3: Expired / Disabled -->
          <div class="col-4 col-box-4">
            <div class="box bg-red bmh-75">
              <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=expired">
                <h1><?= (int)($countExpired + $countDisabled); ?> <span style="font-size: 14px;"><?= ($langid === 'bn') ? 'মেয়াদোত্তীর্ণ' : 'Expired'; ?></span></h1>
                <div><i class="fa fa-hourglass-end"></i> <?= ($langid === 'bn') ? 'মেয়াদোত্তীর্ণ / নিষ্ক্রিয়' : 'Expired / Disabled'; ?></div>
              </a>
            </div>
          </div>
        </div>

        <!-- Filters & Toolbar -->
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
          <!-- Category Tabs -->
          <div class="ngw-pppoe-tabs">
            <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=all&profile=<?= urlencode($filterProfile); ?>&area=<?= urlencode($filterArea); ?>" class="ngw-pppoe-tab <?= $filterStatus === 'all' ? 'is-active' : ''; ?>">
              <?= ($langid === 'bn') ? 'সব ইউজার' : 'All'; ?> (<?= (int)$countTotal; ?>)
            </a>
            <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=active&profile=<?= urlencode($filterProfile); ?>&area=<?= urlencode($filterArea); ?>" class="ngw-pppoe-tab <?= $filterStatus === 'active' ? 'is-active' : ''; ?>">
              <i class="fa fa-check-circle text-success"></i> <?= ($langid === 'bn') ? 'অ্যাক্টিভ' : 'Active'; ?> (<?= (int)$countActive; ?>)
            </a>
            <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=offline&profile=<?= urlencode($filterProfile); ?>&area=<?= urlencode($filterArea); ?>" class="ngw-pppoe-tab <?= $filterStatus === 'offline' ? 'is-active' : ''; ?>">
              <i class="fa fa-power-off text-warning"></i> <?= ($langid === 'bn') ? 'অফলাইন' : 'Offline'; ?> (<?= (int)$countTotalOffline; ?>)
            </a>
            <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=expired&profile=<?= urlencode($filterProfile); ?>&area=<?= urlencode($filterArea); ?>" class="ngw-pppoe-tab <?= $filterStatus === 'expired' ? 'is-active' : ''; ?>">
              <i class="fa fa-hourglass-end text-danger"></i> <?= ($langid === 'bn') ? 'মেয়াদোত্তীর্ণ' : 'Expired'; ?> (<?= (int)$countExpired; ?>)
            </a>
            <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=disabled&profile=<?= urlencode($filterProfile); ?>&area=<?= urlencode($filterArea); ?>" class="ngw-pppoe-tab <?= $filterStatus === 'disabled' ? 'is-active' : ''; ?>">
              <i class="fa fa-ban text-warning"></i> <?= ($langid === 'bn') ? 'নিষ্ক্রিয়' : 'Disabled'; ?> (<?= (int)$countDisabled; ?>)
            </a>
          </div>

          <!-- Search, Area & Profile Dropdowns -->
          <form method="get" action="./" style="display:inline-flex; align-items:center; gap:5px; margin:0; flex-wrap:wrap;">
            <input type="hidden" name="pppoe" value="users">
            <input type="hidden" name="session" value="<?= htmlspecialchars($session, ENT_QUOTES); ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus, ENT_QUOTES); ?>">

            <!-- Area Filter Dropdown -->
            <?php if (!empty($availableAreas)) { ?>
              <select name="area" class="form-control" style="height:26px; padding:2px 6px; font-size:11px; width:auto;" onchange="this.form.submit()">
                <option value=""><?= ($langid === 'bn') ? 'সব এরিয়া' : 'All Areas'; ?></option>
                <?php foreach ($availableAreas as $ar) { ?>
                  <option value="<?= htmlspecialchars($ar); ?>" <?= strtolower($filterArea) === strtolower($ar) ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($ar); ?>
                  </option>
                <?php } ?>
              </select>
            <?php } ?>

            <!-- Profile Filter Dropdown -->
            <?php if (!empty($profileList)) { ?>
              <select name="profile" class="form-control" style="height:26px; padding:2px 6px; font-size:11px; width:auto;" onchange="this.form.submit()">
                <option value=""><?= ($langid === 'bn') ? 'সব প্রোফাইল' : 'All Profiles'; ?></option>
                <?php foreach ($profileList as $pl) { ?>
                  <option value="<?= htmlspecialchars($pl); ?>" <?= $filterProfile === $pl ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($pl); ?>
                  </option>
                <?php } ?>
              </select>
            <?php } ?>

            <input type="search" id="filterTable" name="q" value="<?= htmlspecialchars($searchQuery); ?>" placeholder="<?= ($langid === 'bn') ? 'ইউজার, নাম, ফোন খুঁজুন...' : 'Search user, name, phone...'; ?>" style="height:26px; padding:2px 7px; font-size:11px; border-radius:3px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.18); color:inherit; width:150px;">
            <button type="submit" class="btn bg-primary pd-2p5 radius-3" style="height:26px; font-size:11px; margin:0;"><i class="fa fa-search"></i></button>
            <?php if ($searchQuery !== '' || $filterProfile !== '' || $filterArea !== '') { ?>
              <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=<?= urlencode($filterStatus); ?>" class="btn bg-secondary pd-2p5 radius-3" style="height:26px; font-size:11px; margin:0;"><i class="fa fa-times"></i></a>
            <?php } ?>
          </form>
        </div>

        <!-- PPPoE Users Table (Exact Columns: Customer Name, Mobile Number, Area, Profile, MAC, Validity, Status, Action) -->
        <div class="overflow box-bordered" style="max-height:72vh;">
          <table class="table table-bordered table-hover text-nowrap" id="dataTable" style="font-size:12px; margin-bottom:0;">
            <thead style="background:rgba(255,255,255,0.04);">
              <tr>
                <th style="width:35px;" class="text-center">#</th>
                <th><?= ($langid === 'bn') ? 'কাস্টমার নাম' : 'Customer Name'; ?></th>
                <th><?= ($langid === 'bn') ? 'মোবাইল নাম্বার' : 'Mobile Number'; ?></th>
                <th><?= ($langid === 'bn') ? 'এরিয়া' : 'Area'; ?></th>
                <th><?= ($langid === 'bn') ? 'প্রোফাইল' : 'Profile'; ?></th>
                <th class="ngw-hide-sm"><?= ($langid === 'bn') ? 'ম্যাক (MAC)' : 'MAC Address'; ?></th>
                <th><?= ($langid === 'bn') ? 'ভ্যালিডিটি' : 'Validity / Expiry'; ?></th>
                <th style="width:75px;" class="text-center"><?= ($langid === 'bn') ? 'স্ট্যাটাস' : 'Status'; ?></th>
                <th style="width:45px;" class="text-center"><?= ($langid === 'bn') ? 'অ্যাকশন' : 'Action'; ?></th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($secrets)) { ?>
              <tr><td colspan="9" class="text-center pd-10"><?= ($langid === 'bn') ? 'কোনো পিপিপিওই ইউজার পাওয়া যায়নি।' : 'No PPPoE users found matching the selected filter.'; ?></td></tr>
            <?php } else {
              foreach ($secrets as $idx => $s) {
                $uName = isset($s['name']) ? (string)$s['name'] : '';
                $pass = isset($s['password']) ? (string)$s['password'] : '';
                $prof = isset($s['profile']) ? (string)$s['profile'] : 'default';
                $callerId = isset($s['caller-id']) && $s['caller-id'] !== '' ? $s['caller-id'] : '-';
                $remoteIp = isset($s['remote-address']) && $s['remote-address'] !== '' ? $s['remote-address'] : (isset($s['active_row']['address']) ? $s['active_row']['address'] : '-');
                $localIp = isset($s['local-address']) && $s['local-address'] !== '' ? $s['local-address'] : '-';
                $status = $s['status_computed'];
                $sId = isset($s['.id']) ? (string)$s['.id'] : '';
                $pExp = $s['parsed_exp'];

                $custName = !empty($pExp['customer_name']) ? $pExp['customer_name'] : $uName;
                $custPhone = !empty($pExp['phone']) ? $pExp['phone'] : '-';
                $custArea = !empty($pExp['area']) ? $pExp['area'] : '-';

                $expLabel = '-';
                $expDetailsText = ($langid === 'bn') ? 'মেয়াদহীন / আনলিমিটেড' : 'Unlimited / None';
                if ($pExp['has_expiry']) {
                    $diffSec = $pExp['expiry_timestamp'] - $nowTs;
                    $expDetailsText = $pExp['expiry_iso'];
                    if ($diffSec <= 0) {
                        $expLabel = "<span class='badge bg-red'><b>" . (($langid === 'bn') ? 'মেয়াদোত্তীর্ণ' : 'Expired') . "</b> (" . htmlspecialchars(substr($pExp['expiry_iso'], 0, 10)) . ")</span>";
                    } elseif ($diffSec < 86400) {
                        $hrs = max(1, floor($diffSec / 3600));
                        $expLabel = "<span class='badge bg-yellow'>" . $hrs . (($langid === 'bn') ? ' ঘণ্টা বাকি' : 'h remaining') . "</span>";
                    } else {
                        $days = floor($diffSec / 86400);
                        $expLabel = "<span class='badge bg-green'>" . $days . "d (" . htmlspecialchars(substr($pExp['expiry_iso'], 0, 10)) . ")</span>";
                    }
                } elseif (!empty($pExp['validity'])) {
                    $expLabel = "<span class='badge bg-secondary'>" . htmlspecialchars($pExp['validity']) . "</span>";
                    $expDetailsText = $pExp['validity'];
                }

                // Prepare JSON payload for the Details modal
                $detailsPayload = array(
                    'customer_name' => $custName,
                    'username' => $uName,
                    'password' => $pass,
                    'phone' => $custPhone,
                    'area' => $custArea,
                    'profile' => $prof,
                    'caller_id' => $callerId,
                    'ip_address' => $remoteIp,
                    'local_address' => $localIp,
                    'expiry' => $expDetailsText,
                    'status' => ucfirst($status),
                    'created_by' => !empty($pExp['created_by']) ? $pExp['created_by'] : '-',
                    'note' => !empty($pExp['note']) ? $pExp['note'] : '-'
                );
                $jsonDetails = htmlspecialchars(json_encode($detailsPayload), ENT_QUOTES, 'UTF-8');
            ?>
              <tr>
                <td class="align-middle text-center" style="font-weight:600; opacity:0.85;"><?= $idx + 1; ?></td>
                <!-- 1. Customer Name -->
                <td class="align-middle">
                  <div style="font-weight:700; color:#38bdf8; font-size:13px;">
                    <?= htmlspecialchars($custName); ?>
                  </div>
                  <div style="font-size:11px; opacity:0.75; font-family:monospace;">
                    User: <?= htmlspecialchars($uName); ?>
                  </div>
                </td>
                <!-- 2. Mobile Number -->
                <td class="align-middle">
                  <?php if ($custPhone !== '-') { ?>
                    <span style="font-family:monospace; font-size:12px; color:#e2e8f0;">
                      <i class="fa fa-phone" style="opacity:0.7; margin-right:3px;"></i><?= htmlspecialchars($custPhone); ?>
                    </span>
                  <?php } else { ?>
                    <span style="opacity:0.4;">-</span>
                  <?php } ?>
                </td>
                <!-- 3. Area -->
                <td class="align-middle">
                  <?php if ($custArea !== '-') { ?>
                    <span class="badge bg-secondary" style="font-size:11px;">
                      <i class="fa fa-map-marker"></i> <?= htmlspecialchars($custArea); ?>
                    </span>
                  <?php } else { ?>
                    <span style="opacity:0.4;">-</span>
                  <?php } ?>
                </td>
                <!-- 4. Profile -->
                <td class="align-middle">
                  <b><?= htmlspecialchars($prof); ?></b>
                </td>
                <!-- 5. MAC Address -->
                <td class="align-middle ngw-hide-sm">
                  <code><?= htmlspecialchars($callerId); ?></code>
                </td>
                <!-- 6. Validity / Expiry -->
                <td class="align-middle">
                  <?= $expLabel; ?>
                </td>
                <!-- 7. Status -->
                <td class="align-middle text-center">
                  <?php if ($status === 'disabled') { ?>
                    <span class="badge bg-yellow" style="padding:3px 6px; font-weight:700;"><?= ($langid === 'bn') ? 'নিষ্ক্রিয়' : 'Disabled'; ?></span>
                  <?php } elseif ($status === 'expired') { ?>
                    <span class="badge bg-red" style="padding:3px 6px; font-weight:700;"><?= ($langid === 'bn') ? 'মেয়াদোত্তীর্ণ' : 'Expired'; ?></span>
                  <?php } else { ?>
                    <span class="badge bg-green" style="padding:3px 6px; font-weight:700;"><?= ($langid === 'bn') ? 'সক্রিয়' : 'Active'; ?></span>
                  <?php } ?>
                </td>
                <!-- 8. Action (3-Dot Menu) -->
                <td class="align-middle text-center" style="white-space:nowrap;">
                  <button type="button" class="btn bg-secondary pd-2p5 radius-3 text-white" style="padding:3px 8px; font-size:12px; cursor:pointer;" onclick='openRowActionMenu(event, "<?= htmlspecialchars($sId, ENT_QUOTES); ?>", "<?= htmlspecialchars(addslashes($uName), ENT_QUOTES); ?>", "<?= $status; ?>", "./?pppoe=user&user=<?= urlencode($uName); ?>&session=<?= urlencode($session); ?>", <?= $jsonDetails; ?>)' title="Actions">
                    <i class="fa fa-ellipsis-v"></i>
                  </button>
                </td>
              </tr>
            <?php } } ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Floating Action Dropdown Menu -->
<div id="actionFloatingMenu">
  <!-- 1. Details Option (New) -->
  <button type="button" class="floating-menu-item text-info" style="color:#38bdf8;" onclick="triggerDetailsModal()">
    <i class="fa fa-info-circle text-info" style="width:16px;"></i> <?= ($langid === 'bn') ? 'ইউজার বিবরণ (Details)' : 'User Details'; ?>
  </button>

  <!-- 2. Edit Option -->
  <?php if (hasResellerPermission('edit_pppoe_user')) { ?>
  <a id="floatingEditLink" href="#" class="floating-menu-item">
    <i class="fa fa-edit text-primary" style="width:16px;"></i> <?= ($langid === 'bn') ? 'ইউজার এডিট' : 'Edit User'; ?>
  </a>
  <?php } ?>

  <!-- 3. Enable / Disable Option -->
  <?php if (hasResellerPermission('enable_disable_pppoe_user')) { ?>
  <form id="floatingToggleForm" method="post" action="./?pppoe=users&session=<?= urlencode($session); ?>" style="margin:0;">
    <input type="hidden" name="pppoe_action" value="toggle">
    <input type="hidden" id="floatingSecretId" name="secret_id" value="">
    <input type="hidden" id="floatingSecretName" name="secret_name" value="">
    <input type="hidden" id="floatingToState" name="to_state" value="yes">
    <button type="submit" id="floatingToggleBtn" class="floating-menu-item">
      <i class="fa fa-lock text-yellow" style="width:16px;"></i> <?= ($langid === 'bn') ? 'ডিজেবল করুন' : 'Disable User'; ?>
    </button>
  </form>
  <?php } ?>

  <!-- 4. Delete Option -->
  <?php if (hasResellerPermission('delete_pppoe_user')) { ?>
  <form id="floatingDeleteForm" method="post" action="./?pppoe=users&session=<?= urlencode($session); ?>" style="margin:0;" onsubmit="return confirm('<?= ($langid === 'bn') ? 'আপনি কি নিশ্চিতভাবে এই পিপিওই ইউজার মুছে ফেলতে চান?' : 'Are you sure you want to permanently delete this PPPoE user?'; ?>');">
    <input type="hidden" name="pppoe_action" value="delete">
    <input type="hidden" id="floatingDelId" name="secret_id" value="">
    <input type="hidden" id="floatingDelName" name="secret_name" value="">
    <button type="submit" class="floating-menu-item text-danger" style="color:#ef4444;">
      <i class="fa fa-trash text-danger" style="width:16px;"></i> <?= ($langid === 'bn') ? 'ইউজার মুছে ফেলুন' : 'Delete User'; ?>
    </button>
  </form>
  <?php } ?>
</div>

<!-- User Details Modal (Displays IP, Username, Password, MAC, and all subscriber info) -->
<div id="detailsModal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.7); align-items:center; justify-content:center; padding:15px; box-sizing:border-box;">
  <div class="card box-bordered" style="width:100%; max-width:540px; margin:auto; background:#1e293b; color:#f8fafc; border-radius:6px; box-shadow:0 14px 36px rgba(0,0,0,0.65); overflow:hidden;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; background:#0f172a; padding:12px 16px; border-bottom:1px solid rgba(255,255,255,0.1);">
      <h3 style="margin:0; font-size:15px; color:#fff; display:flex; align-items:center; gap:8px;">
        <i class="fa fa-user-circle text-primary"></i>
        <span><?= ($langid === 'bn') ? 'ইউজার বিস্তারিত তথ্য' : 'PPPoE User Details'; ?></span>
      </h3>
      <button type="button" onclick="closeDetailsModal()" style="background:transparent; border:none; color:#94a3b8; font-size:20px; line-height:1; cursor:pointer; padding:2px 8px;" title="Close">&times;</button>
    </div>

    <div class="card-body" style="padding:16px; max-height:80vh; overflow-y:auto;">
      <table class="table table-bordered text-nowrap" style="margin-bottom:0; font-size:12px; width:100%;">
        <tbody>
          <tr>
            <td style="width:38%; font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'কাস্টমার নাম' : 'Customer Name'; ?></td>
            <td id="dtCustomerName" style="font-weight:700; color:#38bdf8;">-</td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'মোবাইল নাম্বার' : 'Mobile Number'; ?></td>
            <td id="dtPhone" style="font-family:monospace;">-</td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'পিপিপিওই ইউজারনেম' : 'PPPoE Username'; ?></td>
            <td><code id="dtUsername" style="font-size:13px; font-weight:700; color:#4ade80;">-</code></td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'পাসওয়ার্ড' : 'PPPoE Password'; ?></td>
            <td>
              <div style="display:flex; align-items:center; gap:6px;">
                <code id="dtPassword" style="font-size:13px; font-weight:700; color:#fde047;">-</code>
                <button type="button" class="btn btn-xs bg-secondary" style="padding:2px 6px; font-size:10px;" onclick="copyPasswordToClipboard()" title="Copy Password">
                  <i class="fa fa-copy"></i>
                </button>
              </div>
            </td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'এরিয়া / জোন' : 'Area / Zone'; ?></td>
            <td id="dtArea">-</td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'প্রোফাইল' : 'Profile'; ?></td>
            <td id="dtProfile" style="font-weight:700;">-</td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'আইপি অ্যাড্রেস' : 'IP Address'; ?></td>
            <td><code id="dtIpAddress" style="color:#60a5fa;">-</code></td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'লোকাল আইপি (Gateway)' : 'Local Address'; ?></td>
            <td><code id="dtLocalAddress">-</code></td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'ম্যাক / কলার আইডি' : 'MAC / Caller ID'; ?></td>
            <td><code id="dtMacAddress">-</code></td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'মেয়াদ / ভ্যালিডিটি' : 'Validity / Expiry'; ?></td>
            <td id="dtExpiry">-</td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'বর্তমান স্ট্যাটাস' : 'Status'; ?></td>
            <td id="dtStatus">-</td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'তৈরি করেছেন' : 'Created By'; ?></td>
            <td id="dtCreatedBy">-</td>
          </tr>
          <tr>
            <td style="font-weight:600; background:rgba(255,255,255,0.03);"><?= ($langid === 'bn') ? 'মন্তব্য / নোট' : 'Note / Comment'; ?></td>
            <td id="dtNote">-</td>
          </tr>
        </tbody>
      </table>

      <div style="margin-top:16px; display:flex; justify-content:space-between; align-items:center;">
        <span id="copyFeedback" style="font-size:11px; color:#4ade80; display:none;">
          <i class="fa fa-check"></i> <?= ($langid === 'bn') ? 'পাসওয়ার্ড কপি হয়েছে!' : 'Password copied!'; ?>
        </span>
        <button type="button" class="btn bg-secondary pd-5 radius-3 text-white" onclick="closeDetailsModal()" style="margin-left:auto;">
          <?= ($langid === 'bn') ? 'বন্ধ করুন' : 'Close'; ?>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Add Area Modal -->
<div id="areaModal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.7); align-items:center; justify-content:center; padding:15px; box-sizing:border-box;">
  <div class="card box-bordered" style="width:100%; max-width:480px; margin:auto; background:#1e293b; color:#f8fafc; border-radius:6px; box-shadow:0 12px 32px rgba(0,0,0,0.6); overflow:hidden;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; background:#0f172a; padding:12px 16px; border-bottom:1px solid rgba(255,255,255,0.1);">
      <h3 style="margin:0; font-size:15px; color:#fff;">
        <i class="fa fa-map-marker text-primary"></i> <?= ($langid === 'bn') ? 'এরিয়া ব্যবস্থাপনা' : 'Manage Areas / Zones'; ?>
      </h3>
      <button type="button" onclick="closeAreaModal()" style="background:transparent; border:none; color:#94a3b8; font-size:20px; line-height:1; cursor:pointer;" title="Close">&times;</button>
    </div>

    <div class="card-body" style="padding:16px;">
      <!-- Add Area Form -->
      <form method="post" action="./?pppoe=users&session=<?= urlencode($session); ?>" style="margin-bottom:16px;">
        <input type="hidden" name="pppoe_action" value="add_area">
        <label style="font-weight:600; font-size:12px; display:block; margin-bottom:6px;">
          <?= ($langid === 'bn') ? 'নতুন এরিয়া নাম লিখুন:' : 'Add New Area Name:'; ?>
        </label>
        <div class="input-group" style="display:flex;">
          <input type="text" name="area_name" class="form-control" placeholder="<?= ($langid === 'bn') ? 'যেমন: ধানমন্ডি, মিরপুর-১০, উত্তরা' : 'e.g. Sector-10, Uttara, Dhanmondi'; ?>" required style="flex:1;">
          <span class="input-group-btn">
            <button type="submit" class="btn bg-primary pd-5 radius-3 text-white" style="height:34px; margin:0; font-weight:600;">
              <i class="fa fa-plus"></i> <?= ($langid === 'bn') ? 'যুক্ত করুন' : 'Add Area'; ?>
            </button>
          </span>
        </div>
      </form>

      <!-- Existing Areas List -->
      <label style="font-weight:600; font-size:12px; display:block; margin-bottom:6px;">
        <?= ($langid === 'bn') ? 'বর্তমান এরিয়া লিস্ট:' : 'Current Areas List:'; ?>
      </label>
      <div style="max-height:200px; overflow-y:auto; border:1px solid rgba(255,255,255,0.1); border-radius:4px; padding:6px; background:#0f172a;">
        <?php if (empty($availableAreas)) { ?>
          <div style="font-size:11px; color:#94a3b8; text-align:center; padding:10px;"><?= ($langid === 'bn') ? 'এখনো কোনো এরিয়া যোগ করা হয়নি।' : 'No areas defined yet.'; ?></div>
        <?php } else {
          foreach ($availableAreas as $ar) { ?>
            <div style="display:flex; justify-content:space-between; align-items:center; padding:4px 8px; border-bottom:1px solid rgba(255,255,255,0.05); font-size:12px;">
              <span><i class="fa fa-map-marker text-primary" style="margin-right:6px;"></i> <?= htmlspecialchars($ar); ?></span>
              <form method="post" action="./?pppoe=users&session=<?= urlencode($session); ?>" style="margin:0;" onsubmit="return confirm('<?= ($langid === 'bn') ? 'আপনি কি নিশ্চিতভাবে এই এরিয়া মুছে ফেলতে চান?' : 'Are you sure you want to delete this area?'; ?>');">
                <input type="hidden" name="pppoe_action" value="delete_area">
                <input type="hidden" name="area_name" value="<?= htmlspecialchars($ar, ENT_QUOTES); ?>">
                <button type="submit" style="background:transparent; border:none; color:#ef4444; cursor:pointer; font-size:11px;" title="Delete Area">
                  <i class="fa fa-trash"></i>
                </button>
              </form>
            </div>
        <?php } } ?>
      </div>

      <div style="margin-top:14px; text-align:right;">
        <button type="button" class="btn bg-secondary pd-5 radius-3 text-white" onclick="closeAreaModal()">
          <?= ($langid === 'bn') ? 'বন্ধ করুন' : 'Close'; ?>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
var activeRowDetails = null;

function openRowActionMenu(event, userId, userName, status, editUrl, detailsObj) {
  event.stopPropagation();
  activeRowDetails = detailsObj;

  var menu = document.getElementById('actionFloatingMenu');
  var btn = event.currentTarget;
  var rect = btn.getBoundingClientRect();

  var editLink = document.getElementById('floatingEditLink');
  if (editLink) editLink.href = editUrl;

  var sId = document.getElementById('floatingSecretId');
  if (sId) sId.value = userId;
  var sName = document.getElementById('floatingSecretName');
  if (sName) sName.value = userName;

  var dId = document.getElementById('floatingDelId');
  if (dId) dId.value = userId;
  var dName = document.getElementById('floatingDelName');
  if (dName) dName.value = userName;

  var toState = document.getElementById('floatingToState');
  var toggleBtn = document.getElementById('floatingToggleBtn');
  var isBn = <?= ($langid === 'bn') ? 'true' : 'false'; ?>;

  if (toState && toggleBtn) {
    if (status === 'disabled') {
      toState.value = 'no';
      toggleBtn.innerHTML = '<i class="fa fa-unlock text-green" style="width:16px;"></i> ' + (isBn ? 'ইনেবল করুন' : 'Enable User');
    } else {
      toState.value = 'yes';
      toggleBtn.innerHTML = '<i class="fa fa-lock text-yellow" style="width:16px;"></i> ' + (isBn ? 'ডিজেবল করুন' : 'Disable User');
    }
  }

  menu.style.display = 'block';
  var menuWidth = menu.offsetWidth || 145;
  var left = rect.right - menuWidth;
  if (left < 10) left = 10;
  var top = rect.bottom + 2;

  // Viewport bottom edge detection
  if (top + 160 > window.innerHeight) {
    top = rect.top - 140;
  }
  menu.style.left = left + 'px';
  menu.style.top = top + 'px';
}

function triggerDetailsModal() {
  var menu = document.getElementById('actionFloatingMenu');
  if (menu) menu.style.display = 'none';

  if (!activeRowDetails) return;

  document.getElementById('dtCustomerName').innerText = activeRowDetails.customer_name || '-';
  document.getElementById('dtPhone').innerText = activeRowDetails.phone || '-';
  document.getElementById('dtUsername').innerText = activeRowDetails.username || '-';
  document.getElementById('dtPassword').innerText = activeRowDetails.password || '-';
  document.getElementById('dtArea').innerText = activeRowDetails.area || '-';
  document.getElementById('dtProfile').innerText = activeRowDetails.profile || '-';
  document.getElementById('dtMacAddress').innerText = activeRowDetails.caller_id || '-';
  document.getElementById('dtIpAddress').innerText = activeRowDetails.ip_address || '-';
  document.getElementById('dtLocalAddress').innerText = activeRowDetails.local_address || '-';
  document.getElementById('dtExpiry').innerText = activeRowDetails.expiry || '-';
  document.getElementById('dtStatus').innerText = activeRowDetails.status || '-';
  document.getElementById('dtCreatedBy').innerText = activeRowDetails.created_by || '-';
  document.getElementById('dtNote').innerText = activeRowDetails.note || '-';

  var modal = document.getElementById('detailsModal');
  if (modal) modal.style.display = 'flex';
}

function closeDetailsModal() {
  var modal = document.getElementById('detailsModal');
  if (modal) modal.style.display = 'none';
  var fb = document.getElementById('copyFeedback');
  if (fb) fb.style.display = 'none';
}

function copyPasswordToClipboard() {
  var passText = document.getElementById('dtPassword').innerText;
  if (!passText || passText === '-') return;
  navigator.clipboard.writeText(passText).then(function() {
    var fb = document.getElementById('copyFeedback');
    if (fb) {
      fb.style.display = 'inline-block';
      setTimeout(function() { fb.style.display = 'none'; }, 2500);
    }
  });
}

window.addEventListener('click', function(e) {
  var menu = document.getElementById('actionFloatingMenu');
  if (menu) menu.style.display = 'none';

  var dModal = document.getElementById('detailsModal');
  if (e.target === dModal) {
    closeDetailsModal();
  }

  var aModal = document.getElementById('areaModal');
  if (e.target === aModal) {
    closeAreaModal();
  }
});

function openAreaModal() {
  var m = document.getElementById('areaModal');
  if (m) m.style.display = 'flex';
}

function closeAreaModal() {
  var m = document.getElementById('areaModal');
  if (m) m.style.display = 'none';
}
</script>
