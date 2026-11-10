<?php
/*
 *  NextGan WiFi Solution — PPPoE Users / Secrets Management
 *  Supports RouterOS v6 and v7, active/expired status indicators,
 *  validity expiration countdown, Caller-ID lock, and mobile-compact view.
 */
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    echo "<script>window.location='./admin.php?id=login'</script>";
    return;
}

require_once __DIR__ . '/../include/ros_version.php';
require_once __DIR__ . '/../include/reseller_acl.php';
require_once __DIR__ . '/../include/pppoe_helper.php';

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
    $routerOfflineNotice = "Router <b>" . htmlspecialchars($session) . "</b> is offline or unreachable via API port 8728.";
} else {
    // Ensure automated expiry background script & scheduler is deployed on router
    @ngwDeployPppoeMonitorScript($API);
}

// Process Actions: Toggle Enable/Disable, Delete
$actionMsg = '';
$actionType = 'success';

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
            // Also disconnect active session
            $liveActs = $API->comm('/ppp/active/print', array('?name' => $targetName));
            if (is_array($liveActs)) {
                foreach ($liveActs as $la) {
                    if (!empty($la['.id'])) {
                        $API->comm('/ppp/active/remove', array('.id' => $la['.id']));
                    }
                }
            }
        }
        $actionMsg = "User <b>" . htmlspecialchars($targetName) . "</b> has been " . ($toState === 'yes' ? 'disabled' : 'enabled') . ".";
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
        $actionMsg = "User <b>" . htmlspecialchars($targetName) . "</b> deleted successfully.";
        if (function_exists('ngwRecordActivity')) {
            ngwRecordActivity('pppoe_user_deleted', array(
                'target' => $targetName,
                'router' => $session,
            ));
        }
    } elseif ($postAction === 'disconnect' && $targetName !== '') {
        requireResellerPermission('disconnect_pppoe_active', './?pppoe=users&session=' . urlencode($session));
        $liveActs = $API->comm('/ppp/active/print', array('?name' => $targetName));
        $discCount = 0;
        if (is_array($liveActs)) {
            foreach ($liveActs as $la) {
                if (!empty($la['.id'])) {
                    $API->comm('/ppp/active/remove', array('.id' => $la['.id']));
                    $discCount++;
                }
            }
        }
        $actionMsg = $discCount > 0
            ? "Active session for <b>" . htmlspecialchars($targetName) . "</b> disconnected."
            : "No active connection found for <b>" . htmlspecialchars($targetName) . "</b>.";
    }
}

// On-the-fly expiry sync
$syncRes = array('expired_count' => 0, 'disconnected_count' => 0);
if ($isRouterConnected) {
    $syncRes = @ngwSyncExpiredPppoeUsers($API);
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

// Filter secrets for PPPoE service (service == 'pppoe' or service == 'any')
$secrets = array();
$profileList = array();
$countTotal = 0;
$countActive = 0;
$countExpired = 0;
$countDisabled = 0;

$filterStatus  = isset($_GET['status']) ? trim((string)$_GET['status']) : 'all';
$filterProfile = isset($_GET['profile']) ? trim((string)$_GET['profile']) : '';
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

    $status = 'active';
    if ($isDisabled) {
        $status = 'disabled';
        $countDisabled++;
    } elseif ($parsedExp['is_expired']) {
        $status = 'expired';
        $countExpired++;
    } else {
        $countActive++;
    }

    // Apply Filters
    if ($filterStatus === 'active' && $status !== 'active') continue;
    if ($filterStatus === 'expired' && $status !== 'expired') continue;
    if ($filterStatus === 'disabled' && $status !== 'disabled') continue;
    if ($filterProfile !== '' && $prof !== $filterProfile) continue;
    if ($searchQuery !== '') {
        $haystack = strtolower($uName . ' ' . $prof . ' ' . (isset($s['caller-id']) ? $s['caller-id'] : '') . ' ' . (isset($s['remote-address']) ? $s['remote-address'] : '') . ' ' . $comment);
        if (strpos($haystack, strtolower($searchQuery)) === false) {
            continue;
        }
    }

    $s['status_computed'] = $status;
    $s['is_online'] = $isOnline;
    $s['active_row'] = $isOnline ? $activeUserMap[$uName] : null;
    $s['parsed_exp'] = $parsedExp;
    $secrets[] = $s;
}

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
.ngw-online-badge {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #2ea043;
  margin-right: 3px;
}
.ngw-offline-badge {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #6e7681;
  margin-right: 3px;
}
@media (max-width: 767px) {
  .ngw-hide-sm {
    display: none !important;
  }
}
</style>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px;">
        <h3 class="card-title" style="margin:0;">
          <i class="fa fa-sitemap"></i> PPPoE Users
          <small style="font-size:11px; opacity:0.8; font-weight:normal; margin-left:4px;">(<?= count($secrets); ?> / <?= $countTotal; ?>)</small>
        </h3>
        <div style="display:flex; align-items:center; gap:5px; flex-wrap:wrap;">
          <?php if (hasResellerPermission('add_pppoe_user')) { ?>
          <a class="btn bg-primary pd-2p5 radius-3" style="font-size:11px;" href="./?pppoe=add-user&session=<?= urlencode($session); ?>">
            <i class="fa fa-user-plus"></i> Add User
          </a>
          <?php } ?>
          <?php if (hasResellerPermission('view_pppoe_profiles')) { ?>
          <a class="btn bg-secondary pd-2p5 radius-3" style="font-size:11px;" href="./?pppoe=profiles&session=<?= urlencode($session); ?>">
            <i class="fa fa-pie-chart"></i> Profiles
          </a>
          <?php } ?>
          <?php if (hasResellerPermission('view_pppoe_active')) { ?>
          <a class="btn bg-secondary pd-2p5 radius-3" style="font-size:11px;" href="./?pppoe=active&session=<?= urlencode($session); ?>">
            <i class="fa fa-laptop"></i> Active Sessions
          </a>
          <?php } ?>
          <?php if (hasResellerPermission('reset_pppoe')) { ?>
          <a class="btn bg-warning pd-2p5 radius-3" style="font-size:11px;" href="./?pppoe=reset&session=<?= urlencode($session); ?>">
            <i class="fa fa-refresh"></i> Reset / Expiry
          </a>
          <?php } ?>
          <a class="btn bg-primary pd-2p5 radius-3" style="font-size:11px;" href="./?pppoe=users&session=<?= urlencode($session); ?>" title="Refresh">
            <i class="fa fa-refresh"></i>
          </a>
        </div>
      </div>

      <div class="card-body">
        <?php if ($routerOfflineNotice !== '') { ?>
          <div class="bg-danger pd-5 radius-3 mr-b-10 text-white"><i class="fa fa-plug"></i> <?= $routerOfflineNotice; ?> &mdash; Please check router IP/Port or connect via VPN.</div>
        <?php } ?>
        <?php if ($actionMsg !== '') { ?>
          <div class="bg-green pd-5 radius-3 mr-b-10"><i class="fa fa-check-circle"></i> <?= $actionMsg; ?></div>
        <?php } ?>

        <!-- Filters & Toolbar -->
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
          <!-- Category Tabs -->
          <div class="ngw-pppoe-tabs">
            <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=all&profile=<?= urlencode($filterProfile); ?>" class="ngw-pppoe-tab <?= $filterStatus === 'all' ? 'is-active' : ''; ?>">
              All (<?= (int)$countTotal; ?>)
            </a>
            <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=active&profile=<?= urlencode($filterProfile); ?>" class="ngw-pppoe-tab <?= $filterStatus === 'active' ? 'is-active' : ''; ?>">
              <span class="ngw-online-badge"></span> Active (<?= (int)$countActive; ?>)
            </a>
            <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=expired&profile=<?= urlencode($filterProfile); ?>" class="ngw-pppoe-tab <?= $filterStatus === 'expired' ? 'is-active' : ''; ?>">
              <i class="fa fa-hourglass-end text-danger"></i> Expired (<?= (int)$countExpired; ?>)
            </a>
            <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=disabled&profile=<?= urlencode($filterProfile); ?>" class="ngw-pppoe-tab <?= $filterStatus === 'disabled' ? 'is-active' : ''; ?>">
              <i class="fa fa-ban text-warning"></i> Disabled (<?= (int)$countDisabled; ?>)
            </a>
          </div>

          <!-- Search & Profile Dropdown -->
          <form method="get" action="./" style="display:inline-flex; align-items:center; gap:5px; margin:0;">
            <input type="hidden" name="pppoe" value="users">
            <input type="hidden" name="session" value="<?= htmlspecialchars($session, ENT_QUOTES); ?>">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus, ENT_QUOTES); ?>">
            <?php if (!empty($profileList)) { ?>
              <select name="profile" class="form-control" style="height:26px; padding:2px 6px; font-size:11px; width:auto;" onchange="this.form.submit()">
                <option value="">All Profiles</option>
                <?php foreach ($profileList as $pl) { ?>
                  <option value="<?= htmlspecialchars($pl); ?>" <?= $filterProfile === $pl ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($pl); ?>
                  </option>
                <?php } ?>
              </select>
            <?php } ?>
            <input type="search" name="q" value="<?= htmlspecialchars($searchQuery); ?>" placeholder="Search user..." style="height:26px; padding:2px 7px; font-size:11px; border-radius:3px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.18); color:inherit; width:130px;">
            <button type="submit" class="btn bg-primary pd-2p5 radius-3" style="height:26px; font-size:11px; margin:0;"><i class="fa fa-search"></i></button>
            <?php if ($searchQuery !== '' || $filterProfile !== '') { ?>
              <a href="./?pppoe=users&session=<?= urlencode($session); ?>&status=<?= urlencode($filterStatus); ?>" class="btn bg-secondary pd-2p5 radius-3" style="height:26px; font-size:11px; margin:0;"><i class="fa fa-times"></i></a>
            <?php } ?>
          </form>
        </div>

        <!-- PPPoE Users Table -->
        <div class="overflow box-bordered" style="max-height:72vh;">
          <table class="table table-sm table-bordered table-hover">
            <thead>
              <tr>
                <th style="width:30px;" class="text-center">#</th>
                <th>Username</th>
                <th>Password</th>
                <th>Profile</th>
                <th class="ngw-hide-sm">Caller ID / MAC</th>
                <th class="ngw-hide-sm">IP Address</th>
                <th>Expiration / Validity</th>
                <th style="width:80px;" class="text-center">Status</th>
                <th style="width:90px;" class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($secrets)) { ?>
              <tr><td colspan="9" class="text-center pd-10">No PPPoE users found matching the selected filter.</td></tr>
            <?php } else {
              foreach ($secrets as $idx => $s) {
                $uName = isset($s['name']) ? (string)$s['name'] : '';
                $pass = isset($s['password']) ? (string)$s['password'] : '';
                $prof = isset($s['profile']) ? (string)$s['profile'] : 'default';
                $callerId = isset($s['caller-id']) && $s['caller-id'] !== '' ? $s['caller-id'] : '-';
                $remoteIp = isset($s['remote-address']) && $s['remote-address'] !== '' ? $s['remote-address'] : '-';
                $status = $s['status_computed'];
                $isOnline = $s['is_online'];
                $sId = isset($s['.id']) ? (string)$s['.id'] : '';
                $pExp = $s['parsed_exp'];

                $expLabel = '-';
                if ($pExp['has_expiry']) {
                    $diffSec = $pExp['expiry_timestamp'] - $nowTs;
                    if ($diffSec <= 0) {
                        $expLabel = "<span class='text-danger'><b>Expired</b> (" . htmlspecialchars(substr($pExp['expiry_iso'], 0, 10)) . ")</span>";
                    } elseif ($diffSec < 86400) {
                        $hrs = max(1, floor($diffSec / 3600));
                        $expLabel = "<span class='text-warning'>" . $hrs . "h remaining</span>";
                    } else {
                        $days = floor($diffSec / 86400);
                        $expLabel = "<span class='text-green'>" . $days . "d (" . htmlspecialchars(substr($pExp['expiry_iso'], 0, 10)) . ")</span>";
                    }
                } elseif (!empty($pExp['validity'])) {
                    $expLabel = "Val: " . htmlspecialchars($pExp['validity']);
                }
            ?>
              <tr>
                <td class="align-middle text-center" style="font-size:11px; opacity:0.8;"><?= $idx + 1; ?></td>
                <td class="align-middle">
                  <div style="display:flex; align-items:center; gap:4px;">
                    <span class="<?= $isOnline ? 'ngw-online-badge' : 'ngw-offline-badge'; ?>" title="<?= $isOnline ? 'Connected' : 'Offline'; ?>"></span>
                    <b><?= htmlspecialchars($uName); ?></b>
                  </div>
                  <?php if ($isOnline && !empty($s['active_row']['address'])) { ?>
                    <div style="font-size:10px; opacity:0.8; margin-top:1px;">
                      Active IP: <code><?= htmlspecialchars($s['active_row']['address']); ?></code>
                    </div>
                  <?php } ?>
                </td>
                <td class="align-middle" style="font-size:11px;">
                  <code><?= htmlspecialchars($pass); ?></code>
                </td>
                <td class="align-middle" style="font-size:11px;">
                  <b><?= htmlspecialchars($prof); ?></b>
                </td>
                <td class="align-middle ngw-hide-sm" style="font-size:11px;">
                  <code><?= htmlspecialchars($callerId); ?></code>
                </td>
                <td class="align-middle ngw-hide-sm" style="font-size:11px;">
                  <code><?= htmlspecialchars($remoteIp); ?></code>
                </td>
                <td class="align-middle" style="font-size:11px;">
                  <?= $expLabel; ?>
                  <?php if (!empty($pExp['created_by'])) { ?>
                    <div style="font-size:10px; opacity:0.75;">by: <?= htmlspecialchars($pExp['created_by']); ?></div>
                  <?php } ?>
                </td>
                <td class="align-middle text-center">
                  <?php if ($status === 'disabled') { ?>
                    <span style="font-size:10px; font-weight:700; color:#fff; background:#d29922; padding:1px 5px; border-radius:3px;">Disabled</span>
                  <?php } elseif ($status === 'expired') { ?>
                    <span style="font-size:10px; font-weight:700; color:#fff; background:#f85149; padding:1px 5px; border-radius:3px;">Expired</span>
                  <?php } else { ?>
                    <span style="font-size:10px; font-weight:700; color:#fff; background:#2ea043; padding:1px 5px; border-radius:3px;">Active</span>
                  <?php } ?>
                </td>
                <td class="align-middle text-center" style="white-space:nowrap;">
                  <?php if (hasResellerPermission('edit_pppoe_user')) { ?>
                  <a href="./?pppoe=user&user=<?= urlencode($uName); ?>&session=<?= urlencode($session); ?>" class="btn bg-primary pd-2p5 radius-3" style="font-size:11px; margin:1px;" title="Edit Secret">
                    <i class="fa fa-edit"></i>
                  </a>
                  <?php } ?>

                  <?php if (hasResellerPermission('enable_disable_pppoe_user')) { ?>
                  <form method="post" action="./?pppoe=users&session=<?= urlencode($session); ?>" style="display:inline; margin:0;">
                    <input type="hidden" name="pppoe_action" value="toggle">
                    <input type="hidden" name="secret_id" value="<?= htmlspecialchars($sId, ENT_QUOTES); ?>">
                    <input type="hidden" name="secret_name" value="<?= htmlspecialchars($uName, ENT_QUOTES); ?>">
                    <input type="hidden" name="to_state" value="<?= $status === 'disabled' ? 'no' : 'yes'; ?>">
                    <button type="submit" class="btn <?= $status === 'disabled' ? 'bg-green' : 'bg-warning'; ?> pd-2p5 radius-3" style="font-size:11px; margin:1px; cursor:pointer;" title="<?= $status === 'disabled' ? 'Enable User' : 'Disable User'; ?>">
                      <i class="fa <?= $status === 'disabled' ? 'fa-unlock' : 'fa-lock'; ?>"></i>
                    </button>
                  </form>
                  <?php } ?>

                  <?php if ($isOnline && hasResellerPermission('disconnect_pppoe_active')) { ?>
                  <form method="post" action="./?pppoe=users&session=<?= urlencode($session); ?>" style="display:inline; margin:0;" onsubmit="return confirm('Disconnect live active session for <?= htmlspecialchars($uName, ENT_QUOTES); ?>?');">
                    <input type="hidden" name="pppoe_action" value="disconnect">
                    <input type="hidden" name="secret_name" value="<?= htmlspecialchars($uName, ENT_QUOTES); ?>">
                    <button type="submit" class="btn bg-warning pd-2p5 radius-3" style="font-size:11px; margin:1px; cursor:pointer;" title="Disconnect Active Session">
                      <i class="fa fa-power-off"></i>
                    </button>
                  </form>
                  <?php } ?>

                  <?php if (hasResellerPermission('delete_pppoe_user')) { ?>
                  <form method="post" action="./?pppoe=users&session=<?= urlencode($session); ?>" style="display:inline; margin:0;" onsubmit="return confirm('Are you sure you want to permanently delete PPPoE user <?= htmlspecialchars($uName, ENT_QUOTES); ?>?');">
                    <input type="hidden" name="pppoe_action" value="delete">
                    <input type="hidden" name="secret_id" value="<?= htmlspecialchars($sId, ENT_QUOTES); ?>">
                    <input type="hidden" name="secret_name" value="<?= htmlspecialchars($uName, ENT_QUOTES); ?>">
                    <button type="submit" class="btn bg-danger pd-2p5 radius-3" style="font-size:11px; margin:1px; cursor:pointer;" title="Delete User">
                      <i class="fa fa-trash"></i>
                    </button>
                  </form>
                  <?php } ?>
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
