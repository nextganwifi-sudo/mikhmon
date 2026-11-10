<?php
/*
 *  NextGan WiFi Solution — PPPoE User Add & Edit Form
 *  Supports Validity presets: 1d, 5d, 10d, 1m (Calendar Month), Custom
 *  RouterOS v6 & v7 Expiration Sync
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

$isRouterConnected = (isset($API) && is_object($API) && !empty($API->connected));
$isEdit = false;
$userId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$userNameParam = isset($_GET['user']) ? trim((string)$_GET['user']) : (isset($_GET['name']) ? trim((string)$_GET['name']) : '');

if ($userId !== '' || $userNameParam !== '') {
  $isEdit = true;
  requireResellerPermission('edit_pppoe_user', './?pppoe=users&session=' . $session);
} else {
  requireResellerPermission('add_pppoe_user', './?pppoe=users&session=' . $session);
}

// Ensure automated background monitor script & scheduler exist on MikroTik
if ($isRouterConnected) {
  @ngwDeployPppoeMonitorScript($API);
}

// Fetch profiles & IP pools
$profiles = $isRouterConnected ? $API->comm("/ppp/profile/print") : array();
$pools = $isRouterConnected ? $API->comm("/ip/pool/print") : array();

$userSuccess = '';
$userError = '';
if (!$isRouterConnected) {
  $userError = 'Router is offline or unreachable via API port 8728.';
}

$existingUser = null;
if ($isEdit && $isRouterConnected) {
  if ($userId !== '') {
    $found = $API->comm("/ppp/secret/print", array("?.id" => $userId));
    if (is_array($found) && !empty($found)) {
      $existingUser = $found[0];
    }
  }
  if (!$existingUser && $userNameParam !== '') {
    $found = $API->comm("/ppp/secret/print", array("?name" => $userNameParam));
    if (is_array($found) && !empty($found)) {
      $existingUser = $found[0];
    }
  }

  if (!$existingUser) {
    $userError = 'PPPoE secret not found on this router.';
  }
}

// Handle Form Submission
if (isset($_POST['save_pppoe_user'])) {
  $name = trim((string)$_POST['name']);
  $password = trim((string)$_POST['password']);
  $profile = trim((string)$_POST['profile']);
  $service = 'pppoe';
  $callerId = trim((string)$_POST['caller_id']);
  $localAddress = trim((string)$_POST['local_address']);
  $remoteAddress = trim((string)$_POST['remote_address']);
  $validity = trim((string)$_POST['validity']);
  $customValidity = trim((string)$_POST['custom_validity']);
  $userCommentNote = trim((string)$_POST['comment_note']);
  $disabled = (isset($_POST['disabled']) && $_POST['disabled'] === 'yes') ? 'yes' : 'no';

  if ($validity === 'custom' && $customValidity !== '') {
    $validity = $customValidity;
  }

  if ($name === '') {
    $userError = 'Username is required.';
  } else {
    // Check duplicates on add
    if (!$isEdit) {
      $dupCheck = $API->comm("/ppp/secret/print", array("?name" => $name));
      if (is_array($dupCheck) && !empty($dupCheck)) {
        $userError = 'PPPoE username already exists on this router.';
      }
    }

    if ($userError === '') {
      $now = time();
      $expiryTime = null;
      if ($validity !== '' && $validity !== 'none') {
        $expiryTime = ngwCalculatePppoeExpiry($validity, $now);
      }

      $resellerUser = isset($_SESSION['mikhmon_reseller_user']) ? $_SESSION['mikhmon_reseller_user'] : 'admin';
      $comment = ngwBuildPppoeComment($expiryTime, $validity, $resellerUser, $userCommentNote);

      $secretData = array(
        'name' => $name,
        'service' => $service,
        'profile' => $profile !== '' ? $profile : 'default',
        'disabled' => $disabled,
        'comment' => $comment,
      );

      if ($password !== '') {
        $secretData['password'] = $password;
      }
      if ($callerId !== '') {
        $secretData['caller-id'] = $callerId;
      } else {
        $secretData['caller-id'] = '';
      }
      if ($localAddress !== '') {
        $secretData['local-address'] = $localAddress;
      } else {
        $secretData['local-address'] = '';
      }
      if ($remoteAddress !== '') {
        $secretData['remote-address'] = $remoteAddress;
      } else {
        $secretData['remote-address'] = '';
      }

      if ($isEdit) {
        $secretData['.id'] = $existingUser['.id'];
        $res = $API->comm("/ppp/secret/set", $secretData);
        if (is_array($res) && (isset($res['!trap']) || isset($res['!fatal']))) {
          $userError = 'Failed to update PPPoE secret: ' . (isset($res['!trap'][0]['message']) ? $res['!trap'][0]['message'] : 'MikroTik error');
        } else {
          ngwRecordActivity('pppoe_user_updated', array('router' => $session, 'user' => $name, 'profile' => $profile));
          echo "<script>window.location='./?pppoe=users&session=" . $session . "&msg=updated';</script>";
          exit;
        }
      } else {
        $res = $API->comm("/ppp/secret/add", $secretData);
        if (is_array($res) && (isset($res['!trap']) || isset($res['!fatal']))) {
          $userError = 'Failed to create PPPoE secret: ' . (isset($res['!trap'][0]['message']) ? $res['!trap'][0]['message'] : 'MikroTik error');
        } else {
          ngwRecordActivity('pppoe_user_created', array('router' => $session, 'user' => $name, 'profile' => $profile));
          echo "<script>window.location='./?pppoe=users&session=" . $session . "&msg=created';</script>";
          exit;
        }
      }
    }
  }
}

// Current values
$valName = $isEdit ? (isset($existingUser['name']) ? $existingUser['name'] : '') : '';
$valPass = $isEdit ? (isset($existingUser['password']) ? $existingUser['password'] : '') : '';
$valProf = $isEdit ? (isset($existingUser['profile']) ? $existingUser['profile'] : 'default') : 'default';
$valCaller = $isEdit ? (isset($existingUser['caller-id']) ? $existingUser['caller-id'] : '') : '';
$valLocal = $isEdit ? (isset($existingUser['local-address']) ? $existingUser['local-address'] : '') : '';
$valRemote = $isEdit ? (isset($existingUser['remote-address']) ? $existingUser['remote-address'] : '') : '';
$valDisabled = $isEdit ? (isset($existingUser['disabled']) && $existingUser['disabled'] === 'true' ? 'yes' : 'no') : 'no';
$valComment = $isEdit ? (isset($existingUser['comment']) ? $existingUser['comment'] : '') : '';

$parsedComment = ngwParsePppoeComment($valComment);
$currentValidity = isset($parsedComment['validity']) ? $parsedComment['validity'] : '';
$currentNote = isset($parsedComment['note']) ? $parsedComment['note'] : $valComment;
?>

<div class="row">
  <div class="col-12 col-md-9 col-lg-8" style="max-width: 800px; margin: 0 auto;">
    <div class="card box-bordered">
      <div class="card-header bg-navy text-white d-flex justify-content-between align-items-center" style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px;">
        <h3 style="margin:0; font-size:16px; font-weight:600;">
          <i class="fa fa-plug"></i> <?= $isEdit ? 'Edit PPPoE Secret' : 'Add New PPPoE Secret'; ?>
        </h3>
        <a class="btn btn-sm bg-warning text-white" href="./?pppoe=users&session=<?= $session; ?>">
          <i class="fa fa-arrow-left"></i> Back
        </a>
      </div>

      <div class="card-body" style="padding:14px;">
        <?php if ($userError !== '') { ?>
          <div class="alert alert-danger" style="margin-bottom:12px; padding:8px 12px; border-radius:4px;">
            <i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($userError, ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php } ?>

        <?php if ($isEdit && $parsedComment['expiry']) { 
          $expTs = $parsedComment['expiry'];
          $isExp = time() >= $expTs;
        ?>
          <div style="background:<?= $isExp ? '#fff0f0' : '#f0f9ff'; ?>; border:1px solid <?= $isExp ? '#fca5a5' : '#bae6fd'; ?>; border-radius:6px; padding:10px 12px; margin-bottom:14px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
              <div>
                <strong style="color:<?= $isExp ? '#dc2626' : '#0369a1'; ?>;">
                  <i class="fa fa-clock-o"></i> Expiry: <?= date('Y-m-d H:i', $expTs); ?>
                </strong>
                <span style="font-size:12px; margin-left:6px; color:#555;">(<?= ngwFormatRemainingTime($expTs); ?>)</span>
              </div>
              <div>
                <span class="badge" style="background:<?= $isExp ? '#ef4444' : '#10b981'; ?>; color:#fff; padding:3px 8px; border-radius:12px; font-size:11px;">
                  <?= $isExp ? 'EXPIRED' : 'ACTIVE'; ?>
                </span>
                <?php if ($currentValidity !== '') { ?>
                  <span class="badge bg-secondary" style="padding:3px 8px; border-radius:12px; font-size:11px; margin-left:4px;">
                    Profile: <?= htmlspecialchars($currentValidity); ?>
                  </span>
                <?php } ?>
              </div>
            </div>
          </div>
        <?php } ?>

        <form method="post" action="" autocomplete="off">
          <table class="table" style="margin-bottom:0; width:100%;">
            <tr>
              <td style="width:35%; vertical-align:middle; font-weight:600; padding:8px 4px;">Username *</td>
              <td style="padding:8px 4px;">
                <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($valName); ?>" placeholder="e.g. ppp_user01" autofocus>
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;">Password *</td>
              <td style="padding:8px 4px;">
                <div class="input-group" style="display:flex;">
                  <input type="password" id="pppoePass" name="password" class="form-control" value="<?= htmlspecialchars($valPass); ?>" placeholder="Secret password" required style="flex:1;">
                  <div style="background:#f1f5f9; border:1px solid #ced4da; border-left:none; padding:6px 10px; cursor:pointer;" onclick="togglePassVisibility()" title="Show/Hide Password">
                    <i class="fa fa-eye" id="passToggleIcon"></i>
                  </div>
                </div>
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;">Profile *</td>
              <td style="padding:8px 4px;">
                <select name="profile" class="form-control" required>
                  <?php if (is_array($profiles)) {
                    foreach ($profiles as $pr) {
                      $prName = isset($pr['name']) ? $pr['name'] : '';
                      $sel = ($prName === $valProf) ? 'selected' : '';
                      echo "<option value=\"" . htmlspecialchars($prName) . "\" $sel>" . htmlspecialchars($prName) . "</option>";
                    }
                  } ?>
                </select>
              </td>
            </tr>

            <!-- Expiration & Validity Preset Section -->
            <tr>
              <td style="vertical-align:top; font-weight:600; padding:8px 4px;">
                Validity / Expiry
                <div style="font-size:11px; font-weight:normal; color:#6b7280;">Auto-disable on expiry</div>
              </td>
              <td style="padding:8px 4px;">
                <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px;">
                  <button type="button" class="btn btn-sm btn-outline-primary preset-btn" onclick="selectValidity('1d')">1 Day (1d)</button>
                  <button type="button" class="btn btn-sm btn-outline-primary preset-btn" onclick="selectValidity('5d')">5 Days (5d)</button>
                  <button type="button" class="btn btn-sm btn-outline-primary preset-btn" onclick="selectValidity('10d')">10 Days (10d)</button>
                  <button type="button" class="btn btn-sm btn-outline-success preset-btn" onclick="selectValidity('1m')">Calendar Month (1m)</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary preset-btn" onclick="selectValidity('none')">Unlimited / None</button>
                </div>

                <div style="display:flex; gap:6px; align-items:center;">
                  <select name="validity" id="validitySelect" class="form-control" style="max-width:180px;" onchange="handleValidityChange(this.value)">
                    <option value="" <?= ($currentValidity === '') ? 'selected' : ''; ?>>Keep Current / None</option>
                    <option value="1d" <?= ($currentValidity === '1d') ? 'selected' : ''; ?>>1 Day (1d)</option>
                    <option value="5d" <?= ($currentValidity === '5d') ? 'selected' : ''; ?>>5 Days (5d)</option>
                    <option value="10d" <?= ($currentValidity === '10d') ? 'selected' : ''; ?>>10 Days (10d)</option>
                    <option value="1m" <?= ($currentValidity === '1m') ? 'selected' : ''; ?>>Calendar Month (1m)</option>
                    <option value="custom">Custom...</option>
                    <option value="none">No Expiration</option>
                  </select>

                  <input type="text" name="custom_validity" id="customValidityInput" class="form-control" placeholder="e.g. 7d, 15d, 30d, 2m" style="display:none; max-width:160px;">
                </div>
                <small class="text-muted" style="display:block; margin-top:4px; font-size:11px;">
                  "Calendar Month" expires on the final day of the current month (or next month if renewed).
                </small>
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;">Caller ID (MAC Lock)</td>
              <td style="padding:8px 4px;">
                <input type="text" name="caller_id" class="form-control" value="<?= htmlspecialchars($valCaller); ?>" placeholder="Optional (e.g. AA:BB:CC:DD:EE:FF)">
                <small class="text-muted" style="font-size:11px;">Locks session to a specific device / MAC address.</small>
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;">Local Address</td>
              <td style="padding:8px 4px;">
                <input type="text" name="local_address" class="form-control" value="<?= htmlspecialchars($valLocal); ?>" placeholder="Optional static gateway IP">
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;">Remote Address</td>
              <td style="padding:8px 4px;">
                <input type="text" name="remote_address" class="form-control" value="<?= htmlspecialchars($valRemote); ?>" placeholder="Optional static client IP or Pool">
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;">Status</td>
              <td style="padding:8px 4px;">
                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; margin-right:16px;">
                  <input type="radio" name="disabled" value="no" <?= ($valDisabled === 'no') ? 'checked' : ''; ?>>
                  <span style="color:#16a34a; font-weight:600;">Enabled</span>
                </label>
                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                  <input type="radio" name="disabled" value="yes" <?= ($valDisabled === 'yes') ? 'checked' : ''; ?>>
                  <span style="color:#dc2626; font-weight:600;">Disabled</span>
                </label>
              </td>
            </tr>

            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;">Note / Comment</td>
              <td style="padding:8px 4px;">
                <input type="text" name="comment_note" class="form-control" value="<?= htmlspecialchars($currentNote); ?>" placeholder="Optional memo or customer phone">
              </td>
            </tr>
          </table>

          <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:8px;">
            <a class="btn bg-secondary text-white" href="./?pppoe=users&session=<?= $session; ?>">
              <i class="fa fa-times"></i> Cancel
            </a>
            <button type="submit" name="save_pppoe_user" class="btn bg-primary text-white" style="font-weight:600;">
              <i class="fa fa-save"></i> <?= $isEdit ? 'Update PPPoE Secret' : 'Save PPPoE Secret'; ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function togglePassVisibility() {
  var x = document.getElementById('pppoePass');
  var ico = document.getElementById('passToggleIcon');
  if (x.type === 'password') {
    x.type = 'text';
    ico.className = 'fa fa-eye-slash';
  } else {
    x.type = 'password';
    ico.className = 'fa fa-eye';
  }
}

function selectValidity(val) {
  var sel = document.getElementById('validitySelect');
  var customIn = document.getElementById('customValidityInput');
  if (val === 'custom') {
    sel.value = 'custom';
    customIn.style.display = 'inline-block';
    customIn.focus();
  } else {
    sel.value = val;
    customIn.style.display = 'none';
  }
}

function handleValidityChange(val) {
  var customIn = document.getElementById('customValidityInput');
  if (val === 'custom') {
    customIn.style.display = 'inline-block';
    customIn.focus();
  } else {
    customIn.style.display = 'none';
  }
}
</script>
