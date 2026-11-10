<?php
/*
 *  NextGan WiFi Solution — Dedicated Reset PPPoE User Module
 *  Multi-field lookup (Username, Caller ID/MAC, Active IP)
 *  Caller-ID unlock, session disconnect, validity renewal & account re-enabling
 */
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
  echo "<script>window.location='./admin.php?id=login'</script>";
  return;
}

include_once('./include/config.php');
include_once('./include/ros_version.php');
include_once('./include/reseller_acl.php');
include_once('./include/pppoe_helper.php');
include_once('./lib/formatbytesbites.php');

requireResellerPermission('reset_pppoe', './admin.php?id=sessions');

$isRouterConnected = (isset($API) && is_object($API) && !empty($API->connected));

$searchQuery = isset($_REQUEST['q']) ? trim((string)$_REQUEST['q']) : '';
$matchedSecret = null;
$matchedActive = null;
$matchedBy = '';
$actionSuccess = '';
$actionError = '';

// Helper to find PPPoE secret by username, caller-id or active IP
function findPppoeUserByAnyField($API, $q) {
  $q = trim($q);
  if ($q === '') return null;

  // 1. Exact username match
  $byName = $API->comm("/ppp/secret/print", array("?name" => $q));
  if (is_array($byName) && !empty($byName) && isset($byName[0]['.id'])) {
    $sec = $byName[0];
    $act = $API->comm("/ppp/active/print", array("?name" => $sec['name']));
    $activeRow = (is_array($act) && !empty($act)) ? $act[0] : null;
    return array('secret' => $sec, 'active' => $activeRow, 'by' => 'Username');
  }

  // 2. Caller ID / MAC match
  if (strpos($q, ':') !== false || strpos($q, '-') !== false) {
    $macNorm = str_replace('-', ':', strtolower($q));
    $byCaller = $API->comm("/ppp/secret/print", array("?caller-id" => $macNorm));
    if (is_array($byCaller) && !empty($byCaller) && isset($byCaller[0]['.id'])) {
      $sec = $byCaller[0];
      $act = $API->comm("/ppp/active/print", array("?name" => $sec['name']));
      $activeRow = (is_array($act) && !empty($act)) ? $act[0] : null;
      return array('secret' => $sec, 'active' => $activeRow, 'by' => 'Caller ID / MAC');
    }
  }

  // 3. Search in Active sessions by IP or User
  $actSearch = $API->comm("/ppp/active/print", array("?address" => $q));
  if (is_array($actSearch) && !empty($actSearch) && isset($actSearch[0]['name'])) {
    $actRow = $actSearch[0];
    $byName = $API->comm("/ppp/secret/print", array("?name" => $actRow['name']));
    if (is_array($byName) && !empty($byName) && isset($byName[0]['.id'])) {
      return array('secret' => $byName[0], 'active' => $actRow, 'by' => 'Active IP (' . $q . ')');
    }
  }

  // 4. Case-insensitive substring match on name
  $allSec = $API->comm("/ppp/secret/print");
  if (is_array($allSec)) {
    $qLower = strtolower($q);
    foreach ($allSec as $sec) {
      $sName = isset($sec['name']) ? strtolower($sec['name']) : '';
      $sCaller = isset($sec['caller-id']) ? strtolower($sec['caller-id']) : '';
      if ($sName === $qLower || ($sCaller !== '' && strpos($sCaller, $qLower) !== false)) {
        $act = $API->comm("/ppp/active/print", array("?name" => $sec['name']));
        $activeRow = (is_array($act) && !empty($act)) ? $act[0] : null;
        return array('secret' => $sec, 'active' => $activeRow, 'by' => 'Matched (' . $sec['name'] . ')');
      }
    }
  }

  return null;
}

// Handle Reset / Recovery Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && isset($_POST['secret_id'])) {
  $actionType = $_POST['action_type'];
  $secretId = $_POST['secret_id'];
  $secretName = isset($_POST['secret_name']) ? $_POST['secret_name'] : '';

  $currentSec = $API->comm("/ppp/secret/print", array("?.id" => $secretId));
  if (is_array($currentSec) && !empty($currentSec)) {
    $sec = $currentSec[0];
    $sName = $sec['name'];

    // 1. Clear Caller-ID / MAC Lock
    if ($actionType === 'clear_caller_id') {
      $API->comm("/ppp/secret/set", array(".id" => $secretId, "caller-id" => ""));
      $actionSuccess = "Caller-ID lock cleared for PPPoE user '$sName'.";
      ngwRecordActivity('pppoe_caller_id_cleared', array('router' => $session, 'user' => $sName));
    }

    // 2. Disconnect Active Session
    elseif ($actionType === 'disconnect_active') {
      $actRows = $API->comm("/ppp/active/print", array("?name" => $sName));
      if (is_array($actRows) && !empty($actRows)) {
        foreach ($actRows as $ar) {
          if (isset($ar['.id'])) {
            $API->comm("/ppp/active/remove", array(".id" => $ar['.id']));
          }
        }
        $actionSuccess = "Active session for PPPoE user '$sName' was disconnected.";
      } else {
        $actionSuccess = "User '$sName' has no active session to disconnect.";
      }
      ngwRecordActivity('pppoe_active_disconnected', array('router' => $session, 'user' => $sName));
    }

    // 3. Re-Enable Secret
    elseif ($actionType === 'enable_secret') {
      $API->comm("/ppp/secret/set", array(".id" => $secretId, "disabled" => "no"));
      $actionSuccess = "PPPoE secret '$sName' has been re-enabled.";
      ngwRecordActivity('pppoe_user_enabled', array('router' => $session, 'user' => $sName));
    }

    // 4. Extend / Renew Expiration
    elseif ($actionType === 'renew_validity') {
      $newVal = isset($_POST['renew_preset']) ? trim($_POST['renew_preset']) : '1m';
      $newExpiry = ngwCalculatePppoeExpiry($newVal, time());
      $resellerUser = isset($_SESSION['mikhmon_reseller_user']) ? $_SESSION['mikhmon_reseller_user'] : 'admin';
      $parsed = ngwParsePppoeComment(isset($sec['comment']) ? $sec['comment'] : '');
      $newComment = ngwBuildPppoeComment($newExpiry, $newVal, $resellerUser, isset($parsed['note']) ? $parsed['note'] : '');

      $API->comm("/ppp/secret/set", array(
        ".id" => $secretId,
        "disabled" => "no",
        "comment" => $newComment
      ));
      $expTsShow = is_array($newExpiry) && isset($newExpiry['timestamp']) ? $newExpiry['timestamp'] : (is_numeric($newExpiry) ? (int)$newExpiry : time());
      $actionSuccess = "Validity renewed ($newVal) until " . date('Y-m-d H:i', $expTsShow) . " and account re-enabled.";
      ngwRecordActivity('pppoe_renewed', array('router' => $session, 'user' => $sName, 'validity' => $newVal));
    }

    // 5. Full 1-Click Reset (Clear lock + Enable + Disconnect Active + Optional renew)
    elseif ($actionType === 'full_reset') {
      // Disconnect active
      $actRows = $API->comm("/ppp/active/print", array("?name" => $sName));
      if (is_array($actRows)) {
        foreach ($actRows as $ar) {
          if (isset($ar['.id'])) {
            $API->comm("/ppp/active/remove", array(".id" => $ar['.id']));
          }
        }
      }

      $resetData = array(
        ".id" => $secretId,
        "disabled" => "no",
        "caller-id" => ""
      );

      // If validity preset selected for reset
      $resetVal = isset($_POST['reset_validity']) ? trim($_POST['reset_validity']) : '';
      if ($resetVal !== '' && $resetVal !== 'keep') {
        $newExpiry = ngwCalculatePppoeExpiry($resetVal, time());
        $resellerUser = isset($_SESSION['mikhmon_reseller_user']) ? $_SESSION['mikhmon_reseller_user'] : 'admin';
        $parsed = ngwParsePppoeComment(isset($sec['comment']) ? $sec['comment'] : '');
        $resetData['comment'] = ngwBuildPppoeComment($newExpiry, $resetVal, $resellerUser, isset($parsed['note']) ? $parsed['note'] : '');
      }

      $API->comm("/ppp/secret/set", $resetData);
      $actionSuccess = "Full Reset Complete! Caller ID unlocked, live sessions disconnected, account re-enabled.";
      ngwRecordActivity('pppoe_full_reset', array('router' => $session, 'user' => $sName));
    }

    $searchQuery = $sName;
  } else {
    $actionError = "Selected PPPoE user could not be found on router.";
  }
}

// Perform Lookup if query given
if ($searchQuery !== '' && $isRouterConnected) {
  $found = findPppoeUserByAnyField($API, $searchQuery);
  if ($found) {
    $matchedSecret = $found['secret'];
    $matchedActive = $found['active'];
    $matchedBy = $found['by'];
  }
} elseif (!$isRouterConnected) {
  $actionError = "Router is offline or unreachable via API port 8728.";
}
?>

<div class="row">
  <div class="col-12 col-md-10 col-lg-8" style="max-width: 820px; margin: 0 auto;">
    <div class="card box-bordered">
      <div class="card-header bg-navy text-white d-flex justify-content-between align-items-center" style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px;">
        <h3 style="margin:0; font-size:16px; font-weight:600;">
          <i class="fa fa-refresh"></i> Reset PPPoE User & Session Recovery
        </h3>
        <a class="btn btn-sm bg-secondary text-white" href="./?pppoe=users&session=<?= $session; ?>">
          <i class="fa fa-users"></i> Users List
        </a>
      </div>

      <div class="card-body" style="padding:14px;">
        <?php if ($actionSuccess !== '') { ?>
          <div class="alert alert-success" style="padding:8px 12px; margin-bottom:12px; border-radius:4px;">
            <i class="fa fa-check-circle"></i> <?= htmlspecialchars($actionSuccess); ?>
          </div>
        <?php } ?>
        <?php if ($actionError !== '') { ?>
          <div class="alert alert-danger" style="padding:8px 12px; margin-bottom:12px; border-radius:4px;">
            <i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($actionError); ?>
          </div>
        <?php } ?>

        <!-- Search Box -->
        <form method="get" action="" style="margin-bottom:16px;">
          <input type="hidden" name="pppoe" value="reset">
          <input type="hidden" name="session" value="<?= htmlspecialchars($session); ?>">
          <div style="display:flex; gap:6px;">
            <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($searchQuery); ?>" placeholder="Search by PPPoE Username, Caller ID / MAC, or Active IP..." autofocus required>
            <button type="submit" class="btn bg-primary text-white" style="font-weight:600; white-space:nowrap;">
              <i class="fa fa-search"></i> Search
            </button>
          </div>
          <small class="text-muted" style="display:block; margin-top:4px; font-size:11px;">
            Examples: <code>user123</code> &bull; <code>48:8F:5A:21:0B:4D</code> &bull; <code>192.168.10.45</code>
          </small>
        </form>

        <?php if ($searchQuery !== '' && !$matchedSecret) { ?>
          <div style="background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:12px; border-radius:6px; text-align:center;">
            <i class="fa fa-info-circle fa-2x" style="display:block; margin-bottom:6px;"></i>
            <strong>No PPPoE user found matching "<?= htmlspecialchars($searchQuery); ?>"</strong>
            <p style="font-size:12px; margin:4px 0 0 0;">Please verify the username, MAC address, or active IP and try again.</p>
          </div>
        <?php } ?>

        <?php if ($matchedSecret) { 
          $sId = $matchedSecret['.id'];
          $sName = $matchedSecret['name'];
          $sPass = isset($matchedSecret['password']) ? $matchedSecret['password'] : '';
          $sProf = isset($matchedSecret['profile']) ? $matchedSecret['profile'] : 'default';
          $sCaller = isset($matchedSecret['caller-id']) ? $matchedSecret['caller-id'] : '';
          $sDisabled = (isset($matchedSecret['disabled']) && $matchedSecret['disabled'] === 'true');
          $sComment = isset($matchedSecret['comment']) ? $matchedSecret['comment'] : '';

          $parsed = ngwParsePppoeComment($sComment);
          $hasExp = !empty($parsed['expiry']);
          $expTs = $hasExp ? $parsed['expiry'] : null;
          $isExp = $hasExp ? (time() >= $expTs) : false;
        ?>
          <!-- Found User Card -->
          <div style="border:1px solid #cbd5e1; border-radius:8px; overflow:hidden; margin-top:10px;">
            <div style="background:#f1f5f9; padding:10px 14px; border-bottom:1px solid #cbd5e1; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
              <div>
                <strong style="font-size:15px; color:#0f172a;">
                  <i class="fa fa-user"></i> <?= htmlspecialchars($sName); ?>
                </strong>
                <span class="badge" style="background:#e0e7ff; color:#3730a3; font-size:11px; margin-left:6px;">
                  Matched via <?= htmlspecialchars($matchedBy); ?>
                </span>
              </div>
              <div>
                <span class="badge" style="background:<?= $sDisabled ? '#ef4444' : '#10b981'; ?>; color:#fff; font-size:11px; padding:3px 8px;">
                  <?= $sDisabled ? 'DISABLED' : 'ENABLED'; ?>
                </span>
                <?php if ($hasExp) { ?>
                  <span class="badge" style="background:<?= $isExp ? '#f87171' : '#38bdf8'; ?>; color:#fff; font-size:11px; padding:3px 8px; margin-left:4px;">
                    <?= $isExp ? 'EXPIRED' : 'VALID'; ?>
                  </span>
                <?php } ?>
              </div>
            </div>

            <div style="padding:14px; background:#fff;">
              <!-- Details Grid -->
              <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; margin-bottom:14px; font-size:13px;">
                <div style="background:#f8fafc; padding:8px 10px; border-radius:6px; border:1px solid #e2e8f0;">
                  <div style="color:#64748b; font-size:11px;">Profile</div>
                  <strong style="color:#0284c7;"><?= htmlspecialchars($sProf); ?></strong>
                </div>

                <div style="background:#f8fafc; padding:8px 10px; border-radius:6px; border:1px solid #e2e8f0;">
                  <div style="color:#64748b; font-size:11px;">Password</div>
                  <strong id="resetPassTxt">&bull;&bull;&bull;&bull;&bull;&bull;</strong>
                  <button type="button" class="btn btn-xs btn-outline-secondary" style="padding:1px 6px; font-size:10px; margin-left:6px;" onclick="toggleResetPass('<?= addslashes(htmlspecialchars($sPass)); ?>')">
                    Show
                  </button>
                </div>

                <div style="background:#f8fafc; padding:8px 10px; border-radius:6px; border:1px solid #e2e8f0;">
                  <div style="color:#64748b; font-size:11px;">Caller-ID (MAC Lock)</div>
                  <strong><?= $sCaller !== '' ? htmlspecialchars($sCaller) : '<span style="color:#10b981;">None (Unlocked)</span>'; ?></strong>
                </div>

                <div style="background:#f8fafc; padding:8px 10px; border-radius:6px; border:1px solid #e2e8f0;">
                  <div style="color:#64748b; font-size:11px;">Active Session</div>
                  <?php if ($matchedActive) { ?>
                    <strong style="color:#15803d;"><i class="fa fa-bolt" style="color:#eab308;"></i> Connected (<?= htmlspecialchars($matchedActive['address']); ?>)</strong>
                  <?php } else { ?>
                    <span style="color:#64748b;">Offline</span>
                  <?php } ?>
                </div>

                <?php if ($hasExp) { ?>
                  <div style="background:#f8fafc; padding:8px 10px; border-radius:6px; border:1px solid #e2e8f0; grid-column:1 / -1;">
                    <div style="color:#64748b; font-size:11px;">Expiration Details</div>
                    <strong style="color:<?= $isExp ? '#dc2626' : '#0369a1'; ?>;">
                      <?= date('Y-m-d H:i:s', $expTs); ?>
                    </strong>
                    <span style="font-size:12px; margin-left:6px; color:#64748b;">(<?= ngwFormatRemainingTime($expTs); ?>)</span>
                    <?php if (isset($parsed['validity'])) { ?>
                      <span class="badge bg-secondary" style="font-size:10px; margin-left:6px;"><?= htmlspecialchars($parsed['validity']); ?></span>
                    <?php } ?>
                  </div>
                <?php } ?>
              </div>

              <!-- Quick Recovery Actions -->
              <h4 style="font-size:13px; font-weight:600; color:#334155; margin-bottom:8px; border-bottom:1px solid #e2e8f0; padding-bottom:4px;">
                <i class="fa fa-wrench"></i> Recovery & Reset Actions
              </h4>

              <div style="display:flex; flex-direction:column; gap:8px;">
                <!-- Full 1-Click Reset -->
                <form method="post" action="" style="margin:0;">
                  <input type="hidden" name="secret_id" value="<?= htmlspecialchars($sId); ?>">
                  <input type="hidden" name="secret_name" value="<?= htmlspecialchars($sName); ?>">
                  <input type="hidden" name="action_type" value="full_reset">
                  <input type="hidden" name="q" value="<?= htmlspecialchars($sName); ?>">

                  <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div>
                      <strong style="color:#1d4ed8; font-size:13px;"><i class="fa fa-bolt"></i> 1-Click Complete Reset & Reconnect</strong>
                      <div style="font-size:11px; color:#475569;">Clears Caller-ID lock, re-enables secret, and kicks active session.</div>
                    </div>
                    <div style="display:flex; gap:6px; align-items:center;">
                      <select name="reset_validity" class="form-control" style="font-size:12px; padding:3px 6px; height:auto; width:auto;">
                        <option value="keep">Keep Expiry</option>
                        <option value="1d">Renew 1 Day</option>
                        <option value="5d">Renew 5 Days</option>
                        <option value="10d">Renew 10 Days</option>
                        <option value="1m">Renew 1 Month (Calendar)</option>
                      </select>
                      <button type="submit" class="btn btn-sm bg-primary text-white" style="font-weight:600;" onclick="return confirm('Execute Full Reset for user \'<?= addslashes($sName); ?>\'?');">
                        Execute Full Reset
                      </button>
                    </div>
                  </div>
                </form>

                <!-- Granular Actions Grid -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:8px;">
                  <!-- Clear Caller-ID -->
                  <?php if ($sCaller !== '') { ?>
                    <form method="post" action="" style="margin:0;">
                      <input type="hidden" name="secret_id" value="<?= htmlspecialchars($sId); ?>">
                      <input type="hidden" name="secret_name" value="<?= htmlspecialchars($sName); ?>">
                      <input type="hidden" name="action_type" value="clear_caller_id">
                      <input type="hidden" name="q" value="<?= htmlspecialchars($sName); ?>">
                      <button type="submit" class="btn btn-sm btn-outline-warning" style="width:100%; text-align:left; font-size:12px; padding:6px 10px;" onclick="return confirm('Clear Caller-ID (MAC) lock? User will be able to connect from any device.');">
                        <i class="fa fa-unlock"></i> Clear Caller-ID (MAC Lock)
                      </button>
                    </form>
                  <?php } ?>

                  <!-- Disconnect Active -->
                  <?php if ($matchedActive) { ?>
                    <form method="post" action="" style="margin:0;">
                      <input type="hidden" name="secret_id" value="<?= htmlspecialchars($sId); ?>">
                      <input type="hidden" name="secret_name" value="<?= htmlspecialchars($sName); ?>">
                      <input type="hidden" name="action_type" value="disconnect_active">
                      <input type="hidden" name="q" value="<?= htmlspecialchars($sName); ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger" style="width:100%; text-align:left; font-size:12px; padding:6px 10px;" onclick="return confirm('Disconnect active session for user \'<?= addslashes($sName); ?>\'?');">
                        <i class="fa fa-power-off"></i> Disconnect Active Session
                      </button>
                    </form>
                  <?php } ?>

                  <!-- Re-enable Secret -->
                  <?php if ($sDisabled) { ?>
                    <form method="post" action="" style="margin:0;">
                      <input type="hidden" name="secret_id" value="<?= htmlspecialchars($sId); ?>">
                      <input type="hidden" name="secret_name" value="<?= htmlspecialchars($sName); ?>">
                      <input type="hidden" name="action_type" value="enable_secret">
                      <input type="hidden" name="q" value="<?= htmlspecialchars($sName); ?>">
                      <button type="submit" class="btn btn-sm btn-outline-success" style="width:100%; text-align:left; font-size:12px; padding:6px 10px;">
                        <i class="fa fa-check-circle"></i> Re-Enable Disabled Account
                      </button>
                    </form>
                  <?php } ?>

                  <!-- Quick Renew Presets -->
                  <form method="post" action="" style="margin:0;">
                    <input type="hidden" name="secret_id" value="<?= htmlspecialchars($sId); ?>">
                    <input type="hidden" name="secret_name" value="<?= htmlspecialchars($sName); ?>">
                    <input type="hidden" name="action_type" value="renew_validity">
                    <input type="hidden" name="q" value="<?= htmlspecialchars($sName); ?>">
                    <div style="display:flex; gap:4px;">
                      <select name="renew_preset" class="form-control" style="font-size:12px; padding:4px 6px; height:auto;">
                        <option value="1d">Renew 1 Day</option>
                        <option value="5d">Renew 5 Days</option>
                        <option value="10d">Renew 10 Days</option>
                        <option value="1m" selected>Renew 1 Month (Calendar)</option>
                      </select>
                      <button type="submit" class="btn btn-sm btn-outline-primary" style="white-space:nowrap; font-size:12px;" onclick="return confirm('Renew validity for \'<?= addslashes($sName); ?>\'?');">
                        Renew
                      </button>
                    </div>
                  </form>
                </div>
              </div>

              <!-- Direct edit link -->
              <div style="margin-top:14px; text-align:right;">
                <a href="./?pppoe=user&id=<?= urlencode($sId); ?>&session=<?= $session; ?>" class="btn btn-xs bg-secondary text-white" style="padding:4px 10px;">
                  <i class="fa fa-edit"></i> Full Edit in PPPoE Secret Form
                </a>
              </div>
            </div>
          </div>
        <?php } ?>
      </div>
    </div>
  </div>
</div>

<script>
var passShown = false;
function toggleResetPass(actualPass) {
  var el = document.getElementById('resetPassTxt');
  if (passShown) {
    el.innerHTML = '&bull;&bull;&bull;&bull;&bull;&bull;';
    passShown = false;
  } else {
    el.innerText = actualPass !== '' ? actualPass : '(none)';
    passShown = true;
  }
}
</script>
