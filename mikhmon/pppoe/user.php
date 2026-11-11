<?php
/*
 *  NextGan WiFi Solution — PPPoE User Add & Edit Form
 *  Supports:
 *  - Customer Name & Mobile Number (Optional)
 *  - PPPoE Username & Password (Required)
 *  - Area / Zone Tagging (Optional)
 *  - Validity presets: 1d, 5d, 10d, 1m (Calendar Month), Custom
 *  - Caller-ID / MAC Lock, Local/Remote IP
 *  - RouterOS v6 & v7 Expiration Sync
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

$session = isset($_GET['session']) ? (string)$_GET['session'] : '';
if ($session === '' || !canAccessRouterSession($session)) {
  echo "<script>window.location='./admin.php?id=sessions'</script>";
  return;
}

$isRouterConnected = (isset($API) && is_object($API) && !empty($API->connected));
$isEdit = false;
$userId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$userNameParam = isset($_GET['user']) ? trim((string)$_GET['user']) : (isset($_GET['name']) ? trim((string)$_GET['name']) : '');

if ($userId !== '' || $userNameParam !== '') {
  $isEdit = true;
  requireResellerPermission('edit_pppoe_user', './?pppoe=users&session=' . urlencode($session));
} else {
  requireResellerPermission('add_pppoe_user', './?pppoe=users&session=' . urlencode($session));
}

// Ensure automated background monitor script & scheduler exist on MikroTik
if ($isRouterConnected) {
  @ngwDeployPppoeMonitorScript($API);
}

// Fetch profiles, IP pools, and available areas
$profiles = $isRouterConnected ? $API->comm("/ppp/profile/print") : array();
$pools = $isRouterConnected ? $API->comm("/ip/pool/print") : array();
$availableAreas = ngwGetPppoeAreas($session);

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
  $customerName = trim((string)$_POST['customer_name']);
  $customerPhone = trim((string)$_POST['customer_phone']);
  $area = trim((string)$_POST['area']);
  $newAreaInput = trim((string)$_POST['new_area_input']);

  if ($area === '__new__' && $newAreaInput !== '') {
    $area = $newAreaInput;
  }
  if ($area !== '' && $area !== '__new__') {
    ngwAddPppoeArea($session, $area);
  }

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

  // Only Username and Password are strictly required
  if ($name === '') {
    $userError = 'PPPoE Username is required.';
  } elseif (!$isEdit && $password === '') {
    $userError = 'PPPoE Password is required.';
  } else {
    // Check duplicates on add
    if (!$isEdit) {
      $dupCheck = $API->comm("/ppp/secret/print", array("?name" => $name));
      if (is_array($dupCheck) && !empty($dupCheck)) {
        $userError = 'PPPoE username already exists on this router.';
      }
    }

    if ($userError === '') {
      $resellerUser = isset($_SESSION['mikhmon_reseller_user']) ? $_SESSION['mikhmon_reseller_user'] : 'admin';
      $comment = ngwBuildPppoeComment(array(
        'validity' => $validity,
        'customer_name' => $customerName,
        'phone' => $customerPhone,
        'area' => $area,
        'actor' => $resellerUser,
        'note' => $userCommentNote
      ));

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
      $secretData['caller-id'] = ($callerId !== '') ? $callerId : '';
      $secretData['local-address'] = ($localAddress !== '') ? $localAddress : '';
      $secretData['remote-address'] = ($remoteAddress !== '') ? $remoteAddress : '';

      if ($isEdit) {
        $secretData['.id'] = $existingUser['.id'];
        $res = $API->comm("/ppp/secret/set", $secretData);
        if (is_array($res) && (isset($res['!trap']) || isset($res['!fatal']))) {
          $userError = 'Failed to update PPPoE secret: ' . (isset($res['!trap'][0]['message']) ? $res['!trap'][0]['message'] : 'MikroTik error');
        } else {
          ngwRecordActivity('pppoe_user_updated', array('router' => $session, 'user' => $name, 'profile' => $profile, 'area' => $area));
          echo "<script>window.location='./?pppoe=users&session=" . urlencode($session) . "&msg=updated';</script>";
          exit;
        }
      } else {
        $res = $API->comm("/ppp/secret/add", $secretData);
        if (is_array($res) && (isset($res['!trap']) || isset($res['!fatal']))) {
          $userError = 'Failed to create PPPoE secret: ' . (isset($res['!trap'][0]['message']) ? $res['!trap'][0]['message'] : 'MikroTik error');
        } else {
          ngwRecordActivity('pppoe_user_created', array('router' => $session, 'user' => $name, 'profile' => $profile, 'area' => $area));
          echo "<script>window.location='./?pppoe=users&session=" . urlencode($session) . "&msg=created';</script>";
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
$currentNote = isset($parsedComment['note']) ? $parsedComment['note'] : '';
$valCustomerName = isset($parsedComment['customer_name']) ? $parsedComment['customer_name'] : '';
$valCustomerPhone = isset($parsedComment['phone']) ? $parsedComment['phone'] : '';
$valArea = isset($parsedComment['area']) ? $parsedComment['area'] : '';
$isBn = (isset($langid) && $langid === 'bn');
?>

<div class="row">
  <div class="col-12 col-md-9 col-lg-8" style="max-width: 800px; margin: 0 auto;">
    <div class="card box-bordered">
      <div class="card-header">
        <h3>
          <i class="fa fa-plug"></i> <?= $isEdit ? ($isBn ? 'পিপিপি সিক্রেট এডিট' : 'Edit PPPoE Secret') : ($isBn ? 'নতুন পিপিপি সিক্রেট যোগ' : 'Add New PPPoE Secret'); ?>
          <span style="font-size: 14px">
            &nbsp; | &nbsp; <a href="./?pppoe=users&session=<?= urlencode($session); ?>"><i class="fa fa-arrow-left"></i> <?= $isBn ? 'ইউজার তালিকায় ফিরুন' : 'Back to Users'; ?></a>
          </span>
        </h3>
      </div>

      <div class="card-body">
        <?php if ($userError !== '') { ?>
          <div class="bg-danger pd-5 radius-3 mr-b-10 text-white">
            <i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($userError, ENT_QUOTES, 'UTF-8'); ?>
          </div>
        <?php } ?>

        <?php if ($isEdit && !empty($parsedComment['expiry'])) { 
          $expTs = $parsedComment['expiry'];
          $isExp = time() >= $expTs;
        ?>
          <div class="<?= $isExp ? 'bg-danger' : 'bg-blue'; ?> pd-5 radius-3 mr-b-10 text-white">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
              <div>
                <strong><i class="fa fa-clock-o"></i> <?= $isBn ? 'মেয়াদঃ ' : 'Expiry: '; ?><?= date('Y-m-d H:i', $expTs); ?></strong>
                <span style="font-size:12px; margin-left:6px; opacity:0.9;">(<?= ngwFormatRemainingTime($expTs); ?>)</span>
              </div>
              <div>
                <span class="badge <?= $isExp ? 'bg-red' : 'bg-green'; ?>" style="font-size:11px;">
                  <?= $isExp ? ($isBn ? 'মেয়াদোত্তীর্ণ' : 'EXPIRED') : ($isBn ? 'সক্রিয়' : 'ACTIVE'); ?>
                </span>
                <?php if ($currentValidity !== '') { ?>
                  <span class="badge bg-secondary" style="font-size:11px; margin-left:4px;">
                    <?= $isBn ? 'ভ্যালিডিটিঃ ' : 'Validity: '; ?><?= htmlspecialchars($currentValidity); ?>
                  </span>
                <?php } ?>
              </div>
            </div>
          </div>
        <?php } ?>

        <form method="post" action="" autocomplete="off">
          <table class="table" style="margin-bottom:0; width:100%;">
            <!-- 1. Customer Name (Optional) -->
            <tr>
              <td style="width:35%; vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'কাস্টমার নাম' : 'Customer Name'; ?></td>
              <td style="padding:8px 4px;">
                <input type="text" name="customer_name" class="form-control" value="<?= htmlspecialchars($valCustomerName); ?>" placeholder="<?= $isBn ? 'কাস্টমারের নাম (ঐচ্ছিক)' : 'Full customer name (optional)'; ?>">
                <small class="text-muted" style="font-size:11px;"><?= $isBn ? 'ঐচ্ছিক — কাস্টমারের মূল নাম' : 'Optional — Customer full name'; ?></small>
              </td>
            </tr>

            <!-- 2. Customer Mobile Phone (Optional) -->
            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'মোবাইল নম্বর' : 'Customer Mobile No.'; ?></td>
              <td style="padding:8px 4px;">
                <input type="text" name="customer_phone" class="form-control" value="<?= htmlspecialchars($valCustomerPhone); ?>" placeholder="<?= $isBn ? 'উদাঃ ০১৭১২৩৪৫৬৭৮ (ঐচ্ছিক)' : 'e.g. 01712345678 (optional)'; ?>">
                <small class="text-muted" style="font-size:11px;"><?= $isBn ? 'ঐচ্ছিক — কাস্টমারের মোবাইল নম্বর' : 'Optional — Customer contact number'; ?></small>
              </td>
            </tr>

            <!-- 3. PPPoE Username (Mandatory) -->
            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;">
                <?= $isBn ? 'পিপিপি ইউজারনেম' : 'PPPoE Username'; ?> <span class="text-danger">*</span>
              </td>
              <td style="padding:8px 4px;">
                <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($valName); ?>" placeholder="<?= $isBn ? 'উদাঃ ppp_user01' : 'e.g. ppp_user01'; ?>" autofocus>
                <small class="text-muted" style="font-size:11px;"><?= $isBn ? 'বাধ্যতামূলক — রাউটারে সংযোগের ইউজারনেম' : 'Required — Router secret username'; ?></small>
              </td>
            </tr>

            <!-- 4. PPPoE Password (Mandatory) -->
            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;">
                <?= $isBn ? 'পাসওয়ার্ড' : 'PPPoE Password'; ?> <span class="text-danger">*</span>
              </td>
              <td style="padding:8px 4px;">
                <div class="input-group" style="display:flex;">
                  <input type="password" id="pppoePass" name="password" class="form-control" value="<?= htmlspecialchars($valPass); ?>" placeholder="<?= $isBn ? 'সিক্রেট পাসওয়ার্ড' : 'Secret password'; ?>" <?= $isEdit ? '' : 'required'; ?> style="flex:1;">
                  <span class="input-group-btn">
                    <button type="button" class="btn bg-secondary pd-5 radius-3" style="height:34px; margin:0;" onclick="togglePassVisibility()" title="Show/Hide Password">
                      <i class="fa fa-eye" id="passToggleIcon"></i>
                    </button>
                  </span>
                </div>
                <small class="text-muted" style="font-size:11px;"><?= $isBn ? 'বাধ্যতামূলক — রাউটারে সংযোগের পাসওয়ার্ড' : 'Required — Router secret password'; ?></small>
              </td>
            </tr>

            <!-- 5. Area / Zone Tagging (Optional) -->
            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'এরিয়া / জোন' : 'Area / Zone'; ?></td>
              <td style="padding:8px 4px;">
                <div style="display:flex; gap:6px; align-items:center;">
                  <select name="area" id="areaSelect" class="form-control" style="flex:1;" onchange="handleAreaSelect(this.value)">
                    <option value=""><?= $isBn ? '-- এরিয়া নেই / আন-অ্যাসাইনড --' : '-- No Area / Unassigned --'; ?></option>
                    <?php 
                    $matchedArea = false;
                    foreach ($availableAreas as $ar) {
                      $isSel = ($valArea !== '' && strtolower($valArea) === strtolower($ar));
                      if ($isSel) $matchedArea = true;
                      echo '<option value="' . htmlspecialchars($ar, ENT_QUOTES) . '" ' . ($isSel ? 'selected' : '') . '>' . htmlspecialchars($ar) . '</option>';
                    }
                    if ($valArea !== '' && !$matchedArea) {
                      echo '<option value="' . htmlspecialchars($valArea, ENT_QUOTES) . '" selected>' . htmlspecialchars($valArea) . '</option>';
                    }
                    ?>
                    <option value="__new__"><?= $isBn ? '+ নতুন এরিয়া যোগ করুন...' : '+ Add New Area...'; ?></option>
                  </select>
                  <input type="text" name="new_area_input" id="newAreaInput" class="form-control" placeholder="<?= $isBn ? 'নতুন এরিয়ার নাম লিখুন' : 'Type new area name'; ?>" style="display:none; flex:1;">
                </div>
                <small class="text-muted" style="font-size:11px;"><?= $isBn ? 'ঐচ্ছিক — ব্যবহারকারীকে এরিয়া বা জোনে ট্যাগ করুন' : 'Optional — Tag subscriber to an area/zone'; ?></small>
              </td>
            </tr>

            <!-- 6. Profile -->
            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'প্রোফাইল' : 'Profile'; ?> <span class="text-danger">*</span></td>
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

            <!-- 7. Expiration & Validity Preset Section -->
            <tr>
              <td style="vertical-align:top; font-weight:600; padding:8px 4px;">
                <?= $isBn ? 'ভ্যালিডিটি / মেয়াদ' : 'Validity / Expiry'; ?>
                <div style="font-size:11px; font-weight:normal; color:#6b7280;"><?= $isBn ? 'মেয়াদ শেষে স্বয়ংক্রিয়ভাবে নিষ্ক্রিয় হবে' : 'Auto-disable on expiry'; ?></div>
              </td>
              <td style="padding:8px 4px;">
                <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px;">
                  <button type="button" class="btn btn-sm bg-primary pd-2p5 radius-3 text-white" onclick="selectValidity('1d')"><?= $isBn ? '১ দিন (1d)' : '1 Day (1d)'; ?></button>
                  <button type="button" class="btn btn-sm bg-primary pd-2p5 radius-3 text-white" onclick="selectValidity('5d')"><?= $isBn ? '৫ দিন (5d)' : '5 Days (5d)'; ?></button>
                  <button type="button" class="btn btn-sm bg-primary pd-2p5 radius-3 text-white" onclick="selectValidity('10d')"><?= $isBn ? '১০ দিন (10d)' : '10 Days (10d)'; ?></button>
                  <button type="button" class="btn btn-sm bg-green pd-2p5 radius-3 text-white" onclick="selectValidity('1m')"><?= $isBn ? 'ক্যালেন্ডার মাস (1m)' : 'Calendar Month (1m)'; ?></button>
                  <button type="button" class="btn btn-sm bg-secondary pd-2p5 radius-3 text-white" onclick="selectValidity('none')"><?= $isBn ? 'মেয়াদহীন / আনলিমিটেড' : 'Unlimited / None'; ?></button>
                </div>

                <div style="display:flex; gap:6px; align-items:center;">
                  <select name="validity" id="validitySelect" class="form-control" style="max-width:200px;" onchange="handleValidityChange(this.value)">
                    <option value="" <?= ($currentValidity === '') ? 'selected' : ''; ?>><?= $isBn ? 'বর্তমান বহাল রাখুন / নেই' : 'Keep Current / None'; ?></option>
                    <option value="1d" <?= ($currentValidity === '1d') ? 'selected' : ''; ?>><?= $isBn ? '১ দিন (1d)' : '1 Day (1d)'; ?></option>
                    <option value="5d" <?= ($currentValidity === '5d') ? 'selected' : ''; ?>><?= $isBn ? '৫ দিন (5d)' : '5 Days (5d)'; ?></option>
                    <option value="10d" <?= ($currentValidity === '10d') ? 'selected' : ''; ?>><?= $isBn ? '১০ দিন (10d)' : '10 Days (10d)'; ?></option>
                    <option value="1m" <?= ($currentValidity === '1m') ? 'selected' : ''; ?>><?= $isBn ? 'ক্যালেন্ডার মাস (1m)' : 'Calendar Month (1m)'; ?></option>
                    <option value="custom"><?= $isBn ? 'কাস্টম...' : 'Custom...'; ?></option>
                    <option value="none"><?= $isBn ? 'কোনো মেয়াদ নেই' : 'No Expiration'; ?></option>
                  </select>

                  <input type="text" name="custom_validity" id="customValidityInput" class="form-control" placeholder="<?= $isBn ? 'উদাঃ 7d, 15d, 30d' : 'e.g. 7d, 15d, 30d, 2m'; ?>" style="display:none; max-width:160px;">
                </div>
                <small class="text-muted" style="display:block; margin-top:4px; font-size:11px;">
                  <?= $isBn ? '"ক্যালেন্ডার মাস" চলতি মাসের শেষ তারিখ রাত ২৩:৫৯:৫৯ পর্যন্ত সক্রিয় থাকবে।' : '"Calendar Month" expires on the final day of the current month at 23:59:59.'; ?>
                </small>
              </td>
            </tr>

            <!-- 8. Caller ID (MAC Lock) -->
            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'কলার আইডি (ম্যাক লক)' : 'Caller ID (MAC Lock)'; ?></td>
              <td style="padding:8px 4px;">
                <input type="text" name="caller_id" class="form-control" value="<?= htmlspecialchars($valCaller); ?>" placeholder="<?= $isBn ? 'ঐচ্ছিক (উদাঃ AA:BB:CC:DD:EE:FF)' : 'Optional (e.g. AA:BB:CC:DD:EE:FF)'; ?>">
                <small class="text-muted" style="font-size:11px;"><?= $isBn ? 'ঐচ্ছিক — নির্দিষ্ট ম্যাক অ্যাড্রেসে লক করতে' : 'Optional — Lock to specific MAC address'; ?></small>
              </td>
            </tr>

            <!-- 9. Remote Address -->
            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'রিমোট আইপি' : 'Remote Address'; ?></td>
              <td style="padding:8px 4px;">
                <input type="text" name="remote_address" class="form-control" value="<?= htmlspecialchars($valRemote); ?>" placeholder="<?= $isBn ? 'ঐচ্ছিক স্ট্যাটিক ক্লায়েন্ট আইপি বা পুল' : 'Optional static client IP or Pool'; ?>">
              </td>
            </tr>

            <!-- 10. Local Address -->
            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'লোকাল আইপি' : 'Local Address'; ?></td>
              <td style="padding:8px 4px;">
                <input type="text" name="local_address" class="form-control" value="<?= htmlspecialchars($valLocal); ?>" placeholder="<?= $isBn ? 'ঐচ্ছিক গেটওয়ে আইপি' : 'Optional gateway IP'; ?>">
              </td>
            </tr>

            <!-- 11. Status -->
            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'স্ট্যাটাস' : 'Status'; ?></td>
              <td style="padding:8px 4px;">
                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; margin-right:16px;">
                  <input type="radio" name="disabled" value="no" <?= ($valDisabled === 'no') ? 'checked' : ''; ?>>
                  <span style="color:#16a34a; font-weight:600;"><i class="fa fa-unlock"></i> <?= $isBn ? 'সক্রিয় (Enabled)' : 'Enabled'; ?></span>
                </label>
                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                  <input type="radio" name="disabled" value="yes" <?= ($valDisabled === 'yes') ? 'checked' : ''; ?>>
                  <span style="color:#dc2626; font-weight:600;"><i class="fa fa-lock"></i> <?= $isBn ? 'নিষ্ক্রিয় (Disabled)' : 'Disabled'; ?></span>
                </label>
              </td>
            </tr>

            <!-- 12. Note / Comment -->
            <tr>
              <td style="vertical-align:middle; font-weight:600; padding:8px 4px;"><?= $isBn ? 'নোট / মন্তব্য' : 'Note / Comment'; ?></td>
              <td style="padding:8px 4px;">
                <input type="text" name="comment_note" class="form-control" value="<?= htmlspecialchars($currentNote); ?>" placeholder="<?= $isBn ? 'ঐচ্ছিক মন্তব্য বা মেমো' : 'Optional remarks or memo'; ?>">
              </td>
            </tr>
          </table>

          <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:8px;">
            <a class="btn bg-secondary pd-5 radius-3 text-white" href="./?pppoe=users&session=<?= urlencode($session); ?>">
              <i class="fa fa-times"></i> <?= $isBn ? 'বাতিল' : 'Cancel'; ?>
            </a>
            <button type="submit" name="save_pppoe_user" class="btn bg-primary pd-5 radius-3 text-white" style="font-weight:600;">
              <i class="fa fa-save"></i> <?= $isBn ? ($isEdit ? 'আপডেট করুন' : 'সেভ করুন') : ($isEdit ? 'Update PPPoE Secret' : 'Save PPPoE Secret'); ?>
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

function handleAreaSelect(val) {
  var newIn = document.getElementById('newAreaInput');
  if (val === '__new__') {
    newIn.style.display = 'inline-block';
    newIn.focus();
  } else {
    newIn.style.display = 'none';
  }
}
</script>
