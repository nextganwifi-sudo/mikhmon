<?php
session_start();
error_reporting(0);
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/reseller_acl.php';

if (empty($_SESSION['mikhmon'])) {
    header('Location:../admin.php?id=login');
    exit;
}
if (!isMikhmonAdmin()) {
    http_response_code(403);
    echo '<div class="card"><div class="card-body">Access denied. Administrator only.</div></div>';
    exit;
}

ngwRegisterOrTouchActiveSession(array('page_label' => 'Active Sessions'));
$currentToken = ngwEnsureSessionToken();
$csrfToken = ngwCsrfToken('ngw_active_sessions_csrf');
$alertMsg = '';
$alertType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (!ngwValidateCsrf($postedCsrf, 'ngw_active_sessions_csrf')) {
        $alertMsg = 'Security token expired. Please refresh the page and try again.';
        $alertType = 'danger';
    } elseif (!empty($_POST['force_logout_token'])) {
        $targetToken = trim((string)$_POST['force_logout_token']);
        $revoked = ngwRevokeActiveSession($targetToken, (string)$_SESSION['mikhmon']);
        if (is_array($revoked)) {
            ngwRecordActivity('session_force_logout', array(
                'target' => $revoked['username'] . ' (' . ucfirst($revoked['role']) . ')',
                'status' => 'Forced Logout by ' . $_SESSION['mikhmon'],
                'ip' => isset($revoked['ip']) ? $revoked['ip'] : '',
                'device' => isset($revoked['device']) ? $revoked['device'] : '',
                'area' => isset($revoked['area']) ? $revoked['area'] : '',
            ));
            if ($targetToken === $currentToken) {
                $_SESSION = array();
                @session_unset();
                @session_destroy();
                echo "<script>try{sessionStorage.clear();}catch(e){} window.location='./admin.php?id=login&reason=force_logout';</script>";
                exit;
            }
            $alertMsg = 'Session for user "' . htmlspecialchars($revoked['username'], ENT_QUOTES, 'UTF-8') . '" (' . htmlspecialchars($revoked['device'], ENT_QUOTES, 'UTF-8') . ') has been logged out.';
            $alertType = 'success';
        } else {
            $alertMsg = 'Selected session is no longer active.';
            $alertType = 'warning';
        }
    } elseif (!empty($_POST['force_logout_all_others'])) {
        $allActive = ngwLoadActiveSessions(false);
        $countRevoked = 0;
        foreach ($allActive as $tk => $sessRow) {
            if ($tk === $currentToken) continue;
            $rev = ngwRevokeActiveSession($tk, (string)$_SESSION['mikhmon']);
            if (is_array($rev)) {
                $countRevoked++;
                ngwRecordActivity('session_force_logout', array(
                    'target' => $rev['username'] . ' (' . ucfirst($rev['role']) . ')',
                    'status' => 'Forced Logout (All Others) by ' . $_SESSION['mikhmon'],
                    'ip' => isset($rev['ip']) ? $rev['ip'] : '',
                    'device' => isset($rev['device']) ? $rev['device'] : '',
                    'area' => isset($rev['area']) ? $rev['area'] : '',
                ));
            }
        }
        $alertMsg = $countRevoked > 0
            ? ($countRevoked . ' other active session(s) have been logged out.')
            : 'No other active sessions found.';
        $alertType = 'success';
    }
}

$activeSessions = ngwLoadActiveSessions(false);
uasort($activeSessions, function ($a, $b) use ($currentToken) {
    if ($a['token'] === $currentToken) return -1;
    if ($b['token'] === $currentToken) return 1;
    $tsA = isset($a['last_active_ts']) ? (int)$a['last_active_ts'] : 0;
    $tsB = isset($b['last_active_ts']) ? (int)$b['last_active_ts'] : 0;
    return $tsB - $tsA;
});

$totalCount = count($activeSessions);
$adminCount = 0;
$resellerCount = 0;
foreach ($activeSessions as $s) {
    if (isset($s['role']) && $s['role'] === 'reseller') {
        $resellerCount++;
    } else {
        $adminCount++;
    }
}
$nowTs = time();
?>
<style>
.ngw-role-pill {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 3px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  color: #fff;
}
.ngw-role-pill.is-admin {
  background: #1f6feb;
}
.ngw-role-pill.is-reseller {
  background: #2ea043;
}
.ngw-online-dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #4ade80;
  margin-right: 4px;
}
.ngw-sess-show-xs {
  display: none;
}
.ngw-sess-col-act {
  width: 120px;
}
@media screen and (max-width: 767px) {
  .ngw-sess-hide-xs {
    display: none !important;
  }
  .ngw-sess-show-xs {
    display: block !important;
  }
  .ngw-sess-col-act {
    width: auto !important;
  }
  .ngw-role-pill {
    padding: 1px 4px;
    font-size: 9px;
  }
}
</style>

<?php $isBn = (isset($langid) && $langid === 'bn') || (isset($_SESSION['lang']) && $_SESSION['lang'] === 'bn'); ?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px;">
        <h3 style="margin:0;">
          <i class="fa fa-desktop"></i> <?= $isBn ? 'অ্যাক্টিভ সেশন' : 'Active Sessions'; ?> (<?= (int)$totalCount; ?>)
          <small style="font-size:11px; opacity:0.8; font-weight:normal; margin-left:6px;">
            <?= $isBn ? 'অ্যাডমিন' : 'Admin'; ?>: <b><?= (int)$adminCount; ?></b> &middot; <?= $isBn ? 'রিসেলার' : 'Reseller'; ?>: <b><?= (int)$resellerCount; ?></b>
          </small>
        </h3>
        <div style="display:flex; align-items:center; gap:4px; flex-wrap:wrap;">
          <a class="btn bg-primary pd-2p5 radius-3" style="font-size:11px;" href="./admin.php?id=activity">
            <i class="fa fa-history"></i> <?= $isBn ? 'অ্যাক্টিভিটি হিস্টরি' : 'Activity History'; ?>
          </a>
          <?php if ($totalCount > 1) { ?>
          <form method="post" action="./admin.php?id=active-sessions" style="margin:0; display:inline;" onsubmit="return confirm('<?= $isBn ? 'আপনার বর্তমান ব্রাউজার ব্যতীত অন্য সব সেশন ফোর্স লগআউট করতে চান?' : 'Force logout all other active sessions except your current browser?'; ?>');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="force_logout_all_others" value="1">
            <button type="submit" class="btn bg-warning pd-2p5 radius-3" style="cursor:pointer; font-size:11px;">
              <i class="fa fa-sign-out"></i> <?= $isBn ? 'অন্য সব সেশন লগআউট করুন' : 'Logout All Others'; ?>
            </button>
          </form>
          <?php } ?>
          <a class="btn bg-primary pd-2p5 radius-3" style="font-size:11px;" href="./admin.php?id=active-sessions" title="Refresh">
            <i class="fa fa-refresh"></i>
          </a>
        </div>
      </div>

      <div class="card-body">
        <?php if ($alertMsg !== '') { ?>
          <div class="bg-<?= $alertType === 'danger' ? 'danger' : ($alertType === 'warning' ? 'warning' : 'green'); ?> pd-5 radius-3 mr-b-10">
            <i class="fa <?= $alertType === 'danger' ? 'fa-ban' : 'fa-check-circle'; ?>"></i>
            <?= $alertMsg; ?>
          </div>
        <?php } ?>

        <div class="overflow box-bordered" style="max-height:72vh;">
          <table class="table table-sm table-bordered table-hover">
            <thead>
              <tr>
                <th><?= $isBn ? 'ইউজার ও রোল' : 'User &amp; Role'; ?></th>
                <th><?= $isBn ? 'ডিভাইস ও ব্রাউজার' : 'Device &amp; Browser'; ?></th>
                <th class="ngw-sess-hide-xs"><?= $isBn ? 'এলাকা / লোকেশন ও আইপি' : 'Area / Location &amp; IP'; ?></th>
                <th><?= $isBn ? 'সক্রিয় পেজ / রাউটার' : 'Active Page / Router'; ?></th>
                <th class="ngw-sess-hide-xs"><?= $isBn ? 'লগইন ও সর্বশেষ সক্রিয়' : 'Login &amp; Last Active'; ?></th>
                <th class="text-center ngw-sess-col-act"><?= $_action ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($activeSessions)) { ?>
                <tr>
                  <td colspan="6" class="text-center pd-10"><?= $isBn ? 'কোনো সক্রিয় সেশন পাওয়া যায়নি।' : 'No active sessions found.'; ?></td>
                </tr>
              <?php } else {
                foreach ($activeSessions as $tk => $sess) {
                  $isCurrent = ($tk === $currentToken);
                  $role = isset($sess['role']) && $sess['role'] === 'reseller' ? 'reseller' : 'admin';
                  $devType = isset($sess['device_type']) ? (string)$sess['device_type'] : 'Desktop';
                  $devIcon = ($devType === 'Mobile') ? 'fa-mobile' : (($devType === 'Tablet') ? 'fa-tablet' : 'fa-laptop');
                  $lastTs = isset($sess['last_active_ts']) ? (int)$sess['last_active_ts'] : $nowTs;
                  $agoSec = max(0, $nowTs - $lastTs);
                  if ($agoSec < 10) {
                    $agoText = $isBn ? 'এইমাত্র' : 'Just now';
                  } elseif ($agoSec < 60) {
                    $agoText = $agoSec . ($isBn ? ' সেকেন্ড আগে' : 's ago');
                  } else {
                    $agoText = floor($agoSec / 60) . 'm ' . ($agoSec % 60) . ($isBn ? ' সেকেন্ড আগে' : 's ago');
                  }
                  $areaName = isset($sess['area']) && $sess['area'] !== '' ? $sess['area'] : 'Local Area';
                  $ipAddr = isset($sess['ip']) ? $sess['ip'] : '-';
              ?>
                <tr>
                  <td class="align-middle">
                    <div style="display:flex; align-items:center; gap:4px; flex-wrap:wrap;">
                      <b><?= htmlspecialchars(isset($sess['display_name']) ? $sess['display_name'] : $sess['username'], ENT_QUOTES, 'UTF-8'); ?></b>
                      <span class="ngw-role-pill <?= $role === 'reseller' ? 'is-reseller' : 'is-admin'; ?>">
                        <?= $role === 'reseller' ? ($isBn ? 'রিসেলার' : 'Reseller') : ($isBn ? 'অ্যাডমিন' : 'Admin'); ?>
                      </span>
                      <?php if ($isCurrent) { ?>
                        <span style="background:rgba(56,189,248,0.18); color:#38bdf8; border:1px solid rgba(56,189,248,0.4); border-radius:3px; padding:0 4px; font-size:9px; font-weight:700;"><?= $isBn ? 'বর্তমান' : 'Current'; ?></span>
                      <?php } ?>
                    </div>
                    <div style="font-size:11px; opacity:0.78;">
                      <code><?= htmlspecialchars($sess['username'], ENT_QUOTES, 'UTF-8'); ?></code>
                      <?php if (!empty($sess['impersonated_by'])) { ?>
                        <span class="text-warning">(via <?= htmlspecialchars($sess['impersonated_by'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                      <?php } ?>
                    </div>
                  </td>

                  <td class="align-middle" style="font-size:11px;">
                    <div>
                      <i class="fa <?= $devIcon; ?>" style="width:12px; text-align:center;"></i>
                      <b><?= htmlspecialchars(isset($sess['os']) ? $sess['os'] : 'OS', ENT_QUOTES, 'UTF-8'); ?></b>
                      &middot;
                      <?= htmlspecialchars(isset($sess['browser']) ? $sess['browser'] : 'Browser', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div style="font-size:10px; opacity:0.75;">
                      <?= htmlspecialchars($devType, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="ngw-sess-show-xs" style="font-size:10px; opacity:0.82; margin-top:2px;">
                      <i class="fa fa-map-marker text-danger"></i> <?= htmlspecialchars($areaName, ENT_QUOTES, 'UTF-8'); ?> &middot; <code><?= htmlspecialchars($ipAddr, ENT_QUOTES, 'UTF-8'); ?></code>
                    </div>
                  </td>

                  <td class="align-middle ngw-sess-hide-xs" style="font-size:12px;">
                    <div>
                      <i class="fa fa-map-marker text-danger" style="width:12px; text-align:center;"></i>
                      <b><?= htmlspecialchars($areaName, ENT_QUOTES, 'UTF-8'); ?></b>
                    </div>
                    <div style="font-size:11px; opacity:0.78;">
                      IP: <code><?= htmlspecialchars($ipAddr, ENT_QUOTES, 'UTF-8'); ?></code>
                    </div>
                  </td>

                  <td class="align-middle" style="font-size:11px;">
                    <div>
                      <span class="ngw-online-dot"></span>
                      <b><?= htmlspecialchars(isset($sess['current_page']) ? $sess['current_page'] : 'Dashboard', ENT_QUOTES, 'UTF-8'); ?></b>
                    </div>
                    <?php if (!empty($sess['router_session'])) { ?>
                      <div style="font-size:10px; opacity:0.78;">
                        Router: <code><?= htmlspecialchars($sess['router_session'], ENT_QUOTES, 'UTF-8'); ?></code>
                      </div>
                    <?php } ?>
                    <div class="ngw-sess-show-xs" style="font-size:10px; margin-top:2px;">
                      <span class="text-green"><?= htmlspecialchars($agoText, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                  </td>

                  <td class="align-middle ngw-sess-hide-xs" style="font-size:11px;">
                    <div>
                      <b><?= $isBn ? 'সক্রিয়:' : 'Active:'; ?></b> <span class="text-green"><?= htmlspecialchars($agoText, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div style="opacity:0.75;">
                      <?= $isBn ? 'লগইন:' : 'Login:'; ?> <?= htmlspecialchars(isset($sess['login_at']) ? $sess['login_at'] : '-', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                  </td>

                  <td class="align-middle text-center ngw-sess-col-act">
                    <?php 
                      $forceLogoutConfirm = $isCurrent 
                        ? ($isBn ? 'আপনার বর্তমান সেশন লগআউট করতে চান?' : 'Log out your own current session?') 
                        : (($isBn ? 'ফোর্স লগআউট করতে চান: ' : 'Force logout ') . $sess['username'] . '?');
                    ?>
                    <form method="post" action="./admin.php?id=active-sessions" style="margin:0;" onsubmit="return confirm(<?= htmlspecialchars(json_encode($forceLogoutConfirm), ENT_QUOTES, 'UTF-8'); ?>);">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                      <input type="hidden" name="force_logout_token" value="<?= htmlspecialchars($tk, ENT_QUOTES, 'UTF-8'); ?>">
                      <button type="submit" class="btn bg-danger pd-2p5 radius-3" style="cursor:pointer; font-weight:bold; font-size:10px; margin:0;">
                        <i class="fa fa-power-off"></i> <?= $isCurrent ? ($isBn ? 'লগআউট' : 'Logout') : ($isBn ? 'ফোর্স লগআউট' : 'Force Logout'); ?>
                      </button>
                    </form>
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
