<?php
/*
 *  NextGan WiFi Solution — Main Admin Store & License Quota Management Center
 *  Supports:
 *    - Main Admin Store (Super Admin): Create/Edit Licensed Sub-Admins, configure Router/Voucher/Active User/Reseller Quotas,
 *      set License Expiry dates with Auto-Lock, 1-Click Login as Admin, and view all Routers & Resellers across all Licensed Admins.
 *    - Licensed Sub-Admin: View own License & Quota status and update own account name/password.
 */
error_reporting(0);
if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

include_once('./include/reseller_acl.php');

if (!isMikhmonAdmin()) {
  echo "<script>window.location='./admin.php?id=sessions'</script>";
  exit;
}

if (empty($_SESSION['admin_settings_csrf'])) {
  if (function_exists('random_bytes')) {
    $_SESSION['admin_settings_csrf'] = bin2hex(random_bytes(32));
  } else {
    $_SESSION['admin_settings_csrf'] = bin2hex(openssl_random_pseudo_bytes(32));
  }
}
$csrfToken = (string)$_SESSION['admin_settings_csrf'];
$forcePasswordChange = !empty($_SESSION['mikhmon_force_password_change']);

$settingsError = '';
$settingsSuccess = '';
$adminUsers = loadAdminUsers(true);
$currentLoggedRecord = getCurrentAdminRecord();
$currentLoggedAdminId = is_array($currentLoggedRecord) && !empty($currentLoggedRecord['id'])
  ? (string)$currentLoggedRecord['id']
  : 'adm_1';
$isSuperStore = isSuperAdminStore();
$isBn = (isset($langid) && $langid === 'bn') || (isset($_SESSION['lang']) && $_SESSION['lang'] === 'bn');

// 1-Click Login as Licensed Admin (Super Admin Store Impersonation)
if ($isSuperStore && isset($_GET['login-as-admin']) && $_GET['login-as-admin'] !== '') {
  $targetAdminId = trim((string)$_GET['login-as-admin']);
  $targetAdm = findAdminUserByIdOrUsername($targetAdminId);
  if (is_array($targetAdm) && (string)$targetAdm['id'] !== $currentLoggedAdminId) {
    $_SESSION['mikhmon_impersonator_admin'] = $_SESSION['mikhmon'];
    $_SESSION['mikhmon_impersonator_admin_id'] = $currentLoggedAdminId;
    $_SESSION['mikhmon'] = (string)$targetAdm['username'];
    $_SESSION['mikhmon_role'] = 'admin';
    $_SESSION['mikhmon_admin_id'] = (string)$targetAdm['id'];
    $_SESSION['mikhmon_reseller_id'] = (string)$targetAdm['id'];
    $_SESSION['mikhmon_permission_mode'] = 'Admin';
    $_SESSION['mikhmon_last_activity'] = time();
    $_SESSION['mikhmon_login_handshake'] = 1;
    session_write_close();
    echo "<script>try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location='./admin.php?id=sessions';</script>";
    exit;
  }
}

// Quick Toggle Suspend / Activate Licensed Admin
if ($isSuperStore && isset($_POST['toggle_admin_status'])) {
  $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  $targetId = isset($_POST['target_admin_id']) ? trim((string)$_POST['target_admin_id']) : '';
  if ($postedToken !== '' && hash_equals($csrfToken, $postedToken) && $targetId !== '' && $targetId !== 'adm_1') {
    foreach ($adminUsers as $idx => $adm) {
      if ((string)$adm['id'] === $targetId) {
        $adminUsers[$idx]['status'] = (isset($adm['status']) && $adm['status'] === 'suspended') ? 'active' : 'suspended';
        $adminUsers[$idx]['updated_at'] = date('Y-m-d H:i:s');
        saveAdminUsers($adminUsers);
        echo "<script>window.location='./admin.php?id=admin-settings&msg=updated'</script>";
        exit;
      }
    }
  }
}

// Quick Extend +30 Days License
if ($isSuperStore && isset($_POST['extend_admin_30d'])) {
  $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  $targetId = isset($_POST['target_admin_id']) ? trim((string)$_POST['target_admin_id']) : '';
  if ($postedToken !== '' && hash_equals($csrfToken, $postedToken) && $targetId !== '' && $targetId !== 'adm_1') {
    foreach ($adminUsers as $idx => $adm) {
      if ((string)$adm['id'] === $targetId) {
        $currExp = !empty($adm['license_expires_at']) ? trim((string)$adm['license_expires_at']) : '';
        $baseTs = ($currExp !== '' && $currExp >= date('Y-m-d')) ? strtotime($currExp) : time();
        if ($baseTs === false) $baseTs = time();
        $adminUsers[$idx]['license_expires_at'] = date('Y-m-d', strtotime('+30 days', $baseTs));
        $adminUsers[$idx]['status'] = 'active';
        $adminUsers[$idx]['updated_at'] = date('Y-m-d H:i:s');
        saveAdminUsers($adminUsers);
        echo "<script>window.location='./admin.php?id=admin-settings&msg=renewed'</script>";
        exit;
      }
    }
  }
}

// Handle Add or Update Admin User
if (isset($_POST['save_admin_user'])) {
  $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
    $settingsError = 'Security token expired or invalid. Reload this page and try again.';
  } else {
    $editId = isset($_POST['edit_admin_id']) ? trim((string)$_POST['edit_admin_id']) : '';
    // If Licensed Sub-Admin, force editId to their own ID only
    if (!$isSuperStore) {
      $editId = $currentLoggedAdminId;
    }
    $admName = isset($_POST['admin_name']) ? trim((string)$_POST['admin_name']) : '';
    $admUser = isset($_POST['admin_username']) ? trim((string)$_POST['admin_username']) : '';
    $admPass = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';

    if ($admName === '') {
      $admName = $admUser;
    }

    if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $admUser)) {
      $settingsError = 'Admin username must be 3–64 characters and use only letters, numbers, dot, underscore, @ or hyphen.';
    } elseif ($editId === '' && (strlen($admPass) < 4 || strlen($admPass) > 256 || preg_match('/[\r\n]/', $admPass))) {
      $settingsError = 'Password is required (4–256 characters) when adding a new Admin user.';
    } elseif ($editId !== '' && $admPass !== '' && (strlen($admPass) < 4 || strlen($admPass) > 256 || preg_match('/[\r\n]/', $admPass))) {
      $settingsError = 'Password must be between 4 and 256 characters.';
    } else {
      $duplicate = false;
      foreach ($adminUsers as $existing) {
        if ((string)$existing['id'] !== $editId && strtolower((string)$existing['username']) === strtolower($admUser)) {
          $duplicate = true;
          break;
        }
      }
      if (!$duplicate) {
        foreach (loadResellers() as $res) {
          if (isset($res['username']) && strtolower((string)$res['username']) === strtolower($admUser)) {
            $duplicate = true;
            break;
          }
        }
      }

      if ($duplicate) {
        $settingsError = 'Username "' . $admUser . '" is already in use by another Admin or Reseller account.';
      } else {
        // Parse License & Quota fields (only Super Admin Store can set these)
        $admTier = isset($_POST['admin_tier']) && $_POST['admin_tier'] === 'super_admin' ? 'super_admin' : 'licensed_admin';
        $admStatus = isset($_POST['admin_status']) && $_POST['admin_status'] === 'suspended' ? 'suspended' : 'active';
        $admExpires = isset($_POST['license_expires_at']) ? trim((string)$_POST['license_expires_at']) : '';
        if ($admExpires !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $admExpires)) {
          $admExpires = '';
        }
        $maxRouters = isset($_POST['max_routers']) ? max(0, (int)$_POST['max_routers']) : 0;
        $maxTotalUsers = isset($_POST['max_total_users']) ? max(0, (int)$_POST['max_total_users']) : 0;
        $maxBatchQty = isset($_POST['max_batch_qty']) ? max(0, (int)$_POST['max_batch_qty']) : 0;
        $maxMonthlyVouchers = isset($_POST['max_monthly_vouchers']) ? max(0, (int)$_POST['max_monthly_vouchers']) : 0;
        $maxActiveUsers = isset($_POST['max_active_users']) ? max(0, (int)$_POST['max_active_users']) : 0;
        $maxResellers = isset($_POST['max_resellers']) ? max(0, (int)$_POST['max_resellers']) : 0;
        $admPhone = isset($_POST['admin_phone']) ? trim((string)$_POST['admin_phone']) : '';
        $admNotes = isset($_POST['admin_notes']) ? trim((string)$_POST['admin_notes']) : '';

        if ($editId !== '') {
          $found = false;
          foreach ($adminUsers as $idx => $existing) {
            if ((string)$existing['id'] === $editId) {
              $adminUsers[$idx]['name'] = $admName;
              $adminUsers[$idx]['username'] = $admUser;
              if ($admPass !== '') {
                $adminUsers[$idx]['password_enc'] = encrypt($admPass);
                $adminUsers[$idx]['password_hash'] = password_hash($admPass, PASSWORD_DEFAULT);
              }
              if ($isSuperStore) {
                $isPrimaryAdm = ((string)$existing['id'] === 'adm_1');
                $adminUsers[$idx]['admin_tier'] = $isPrimaryAdm ? 'super_admin' : $admTier;
                $adminUsers[$idx]['status'] = $isPrimaryAdm ? 'active' : $admStatus;
                $adminUsers[$idx]['license_expires_at'] = ($isPrimaryAdm || $admTier === 'super_admin') ? '' : $admExpires;
                $adminUsers[$idx]['max_routers'] = ($isPrimaryAdm || $admTier === 'super_admin') ? 0 : $maxRouters;
                $adminUsers[$idx]['max_total_users'] = ($isPrimaryAdm || $admTier === 'super_admin') ? 0 : $maxTotalUsers;
                $adminUsers[$idx]['max_batch_qty'] = ($isPrimaryAdm || $admTier === 'super_admin') ? 0 : $maxBatchQty;
                $adminUsers[$idx]['max_monthly_vouchers'] = ($isPrimaryAdm || $admTier === 'super_admin') ? 0 : $maxMonthlyVouchers;
                $adminUsers[$idx]['max_active_users'] = ($isPrimaryAdm || $admTier === 'super_admin') ? 0 : $maxActiveUsers;
                $adminUsers[$idx]['max_resellers'] = ($isPrimaryAdm || $admTier === 'super_admin') ? 0 : $maxResellers;
                $adminUsers[$idx]['phone'] = $admPhone;
                $adminUsers[$idx]['notes'] = $admNotes;
              }
              $adminUsers[$idx]['updated_at'] = date('Y-m-d H:i:s');
              $found = true;
              break;
            }
          }
          if (!$found) {
            $settingsError = 'Selected Admin user was not found.';
          } elseif (!saveAdminUsers($adminUsers)) {
            $settingsError = 'Could not save Admin users list. Check file permissions.';
          } else {
            if ($editId === $currentLoggedAdminId) {
              $_SESSION['mikhmon'] = $admUser;
              $_SESSION['mikhmon_admin_id'] = $editId;
            }
            unset($_SESSION['mikhmon_force_password_change']);
            echo "<script>window.location='./admin.php?id=admin-settings&msg=updated'</script>";
            exit;
          }
        } elseif ($isSuperStore) {
          $newId = generateNextAdminUserId($adminUsers);
          $adminUsers[] = array(
            'id' => $newId,
            'name' => $admName,
            'username' => $admUser,
            'password_enc' => encrypt($admPass),
            'password_hash' => password_hash($admPass, PASSWORD_DEFAULT),
            'avatar' => '',
            'admin_tier' => $admTier,
            'status' => $admStatus,
            'license_expires_at' => ($admTier === 'super_admin') ? '' : $admExpires,
            'max_routers' => ($admTier === 'super_admin') ? 0 : $maxRouters,
            'max_total_users' => ($admTier === 'super_admin') ? 0 : $maxTotalUsers,
            'max_batch_qty' => ($admTier === 'super_admin') ? 0 : $maxBatchQty,
            'max_monthly_vouchers' => ($admTier === 'super_admin') ? 0 : $maxMonthlyVouchers,
            'max_active_users' => ($admTier === 'super_admin') ? 0 : $maxActiveUsers,
            'max_resellers' => ($admTier === 'super_admin') ? 0 : $maxResellers,
            'phone' => $admPhone,
            'notes' => $admNotes,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
          );
          if (!saveAdminUsers($adminUsers)) {
            $settingsError = 'Could not save new Admin user. Check file permissions.';
          } else {
            echo "<script>window.location='./admin.php?id=admin-settings&msg=added'</script>";
            exit;
          }
        }
      }
    }
  }
}

// Handle Delete Admin User (Super Admin Store only)
if ($isSuperStore && isset($_POST['delete_admin_user'])) {
  $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  $delId = isset($_POST['delete_admin_id']) ? trim((string)$_POST['delete_admin_id']) : '';
  if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
    $settingsError = 'Security token expired or invalid. Reload this page and try again.';
  } elseif ($delId === 'adm_1') {
    $settingsError = $isBn ? 'মূল অ্যাডমিন স্টোর (adm_1) ডিলিট করা যাবে না।' : 'The Primary Admin Store account (adm_1) cannot be deleted.';
  } elseif (count($adminUsers) <= 1) {
    $settingsError = 'Cannot delete the last remaining Admin user.';
  } else {
    $newList = array();
    $deletedAny = false;
    foreach ($adminUsers as $adm) {
      if ((string)$adm['id'] === $delId) {
        $deletedAny = true;
        continue;
      }
      $newList[] = $adm;
    }
    if (!$deletedAny) {
      $settingsError = 'Admin user not found.';
    } elseif (!saveAdminUsers($newList)) {
      $settingsError = 'Could not update Admin users file.';
    } else {
      $adminUsers = $newList;
      echo "<script>window.location='./admin.php?id=admin-settings&msg=deleted'</script>";
      exit;
    }
  }
}

$editingAdmin = null;
if (!$isSuperStore) {
  $editingAdmin = $currentLoggedRecord;
} elseif (isset($_GET['edit_admin']) && $_GET['edit_admin'] !== '') {
  $reqEditId = trim((string)$_GET['edit_admin']);
  foreach ($adminUsers as $adm) {
    if ((string)$adm['id'] === $reqEditId) {
      $editingAdmin = $adm;
      break;
    }
  }
}

if (isset($_GET['msg'])) {
  if ($_GET['msg'] === 'added') $settingsSuccess = $isBn ? 'নতুন লাইসেন্সড অ্যাডমিন সফলভাবে তৈরি হয়েছে!' : 'New Licensed Admin created successfully!';
  elseif ($_GET['msg'] === 'updated') $settingsSuccess = $isBn ? 'অ্যাডমিন ও লাইসেন্স তথ্য সফলভাবে আপডেট হয়েছে!' : 'Admin & License settings updated successfully!';
  elseif ($_GET['msg'] === 'deleted') $settingsSuccess = $isBn ? 'অ্যাডমিন অ্যাকাউন্ট সফলভাবে মুছে ফেলা হয়েছে!' : 'Admin account removed successfully!';
  elseif ($_GET['msg'] === 'renewed') $settingsSuccess = $isBn ? 'লাইসেন্সের মেয়াদ সফলভাবে ৩০ দিন বাড়ানো হয়েছে!' : 'License extended by +30 days successfully!';
} elseif (isset($_GET['saved']) && $_GET['saved'] == '1') {
  $settingsSuccess = $isBn ? 'সেটিংস সফলভাবে সংরক্ষিত হয়েছে!' : 'Settings saved successfully!';
}

$allRouters = getConfiguredRouterSessions();
$allResellers = loadResellers();
?>
<script>
  function Pass(id){
    var x = document.getElementById(id);
    if (!x) return;
    x.type = (x.type === 'password') ? 'text' : 'password';
  }
  function setExpiryOffsetDays(days) {
    var inp = document.getElementById('license_expires_at_input');
    if (!inp) return;
    if (days <= 0) {
      inp.value = '';
      return;
    }
    var d = new Date();
    d.setDate(d.getDate() + days);
    var yyyy = d.getFullYear();
    var mm = String(d.getMonth() + 1).padStart(2, '0');
    var dd = String(d.getDate()).padStart(2, '0');
    inp.value = yyyy + '-' + mm + '-' + dd;
  }
  function toggleLicenseQuotaBox() {
    var sel = document.getElementById('admin_tier_select');
    var box = document.getElementById('license_quota_fields_box');
    if (!sel || !box) return;
    box.style.display = (sel.value === 'super_admin') ? 'none' : 'block';
  }
</script>

<?php if ($isSuperStore) {
  $totalLicensedCount = 0;
  $activeLicensedCount = 0;
  $expiredOrSuspendedCount = 0;
  foreach ($adminUsers as $a) {
    if (isset($a['admin_tier']) && $a['admin_tier'] === 'licensed_admin') {
      $totalLicensedCount++;
      $lr = '';
      if (ngwIsAdminAccountLocked($a, $lr)) {
        $expiredOrSuspendedCount++;
      } else {
        $activeLicensedCount++;
      }
    }
  }
?>
<!-- Super Admin Store Top Summary Bar -->
<div class="row">
  <div class="col-3">
    <div class="box bmh-75 box-bordered bg-blue">
      <div class="box-group">
        <div class="box-group-icon"><i class="fa fa-building"></i></div>
        <div class="box-group-area">
          <span>
            <b><?= $isBn ? 'মেইন অ্যাডমিন স্টোর' : 'Main Admin Store'; ?></b><br>
            <?= $isBn ? 'মোট অ্যাডমিন: ' : 'Total Admins: '; ?><?= count($adminUsers); ?><br>
            <?= $isBn ? 'লাইসেন্সড অ্যাডমিন: ' : 'Licensed Admins: '; ?><?= $totalLicensedCount; ?>
          </span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-3">
    <div class="box bmh-75 box-bordered bg-green">
      <div class="box-group">
        <div class="box-group-icon"><i class="fa fa-check-circle"></i></div>
        <div class="box-group-area">
          <span>
            <b><?= $isBn ? 'সচল লাইসেন্স' : 'Active Licenses'; ?></b><br>
            <?= $isBn ? 'অ্যাক্টিভ অ্যাডমিন: ' : 'Active: '; ?><?= $activeLicensedCount; ?><br>
            <?= $isBn ? 'মেয়াদোত্তীর্ণ/লক: ' : 'Locked/Expired: '; ?><?= $expiredOrSuspendedCount; ?>
          </span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-3">
    <div class="box bmh-75 box-bordered bg-purple">
      <div class="box-group">
        <div class="box-group-icon"><i class="fa fa-server"></i></div>
        <div class="box-group-area">
          <span>
            <b><?= $isBn ? 'স্টোরের মোট রাউটার' : 'Total Store Routers'; ?></b><br>
            <?= count($allRouters); ?> <?= $isBn ? 'টি সংযুক্ত রাউটার' : 'Configured Routers'; ?><br>
            <a href="./admin.php?id=sessions" style="color:#fff;text-decoration:underline;"><?= $isBn ? 'সব রাউটার দেখুন' : 'View All Routers'; ?></a>
          </span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-3">
    <div class="box bmh-75 box-bordered bg-teal">
      <div class="box-group">
        <div class="box-group-icon"><i class="fa fa-users"></i></div>
        <div class="box-group-area">
          <span>
            <b><?= $isBn ? 'স্টোরের মোট রিসেলার' : 'Total Store Resellers'; ?></b><br>
            <?= count($allResellers); ?> <?= $isBn ? 'জন রিসেলার' : 'Reseller Accounts'; ?><br>
            <a href="./admin.php?id=resellers" style="color:#fff;text-decoration:underline;"><?= $isBn ? 'সব রিসেলার দেখুন' : 'View All Resellers'; ?></a>
          </span>
        </div>
      </div>
    </div>
  </div>
</div>
<?php } ?>

<div class="row">
  <div class="<?= $isSuperStore ? 'col-5' : 'col-6'; ?>">
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <h3 class="card-title" style="margin:0;">
          <i class="fa fa-key"></i>
          <?php if ($isSuperStore) { ?>
            <?= $editingAdmin ? (($isBn ? 'অ্যাডমিন ও লাইসেন্স এডিট: ' : 'Edit Admin & License: ') . htmlspecialchars($editingAdmin['username'], ENT_QUOTES, 'UTF-8')) : ($isBn ? 'নতুন লাইসেন্সড অ্যাডমিন তৈরি করুন' : 'Create Licensed Admin'); ?>
          <?php } else { ?>
            <?= $isBn ? 'আমার অ্যাকাউন্ট ও পাসওয়ার্ড পরিবর্তন' : 'My Account & Password'; ?>
          <?php } ?>
        </h3>
      </div>
      <div class="card-body">
        <?php if ($forcePasswordChange) { ?>
        <div role="alert" style="padding:8px 12px; margin-bottom:10px; border-radius:4px; background:rgba(220,53,69,0.12); border:1px solid #dc3545; color:#dc3545; font-weight:bold;">
          <?= $isBn ? 'প্রথমবার লগইন নিরাপত্তা: সফটওয়্যার ব্যবহারের আগে ডিফল্ট অ্যাডমিন পাসওয়ার্ড পরিবর্তন করুন।' : 'First login security: change the default administrator password before using the software.'; ?>
        </div>
        <?php } ?>
        <?php if (!empty($settingsError)) { ?>
        <div role="alert" style="padding:8px 12px; margin-bottom:10px; border-radius:4px; background:rgba(220,53,69,0.12); border:1px solid #dc3545; color:#dc3545; font-weight:bold;">
          <i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($settingsError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php } ?>
        <?php if (!empty($settingsSuccess)) { ?>
        <div style="padding:8px 12px; margin-bottom:10px; border-radius:4px; background:rgba(46,160,67,0.18); border:1px solid #2ea043; color:#2ea043; font-weight:bold;">
          <i class="fa fa-check-circle"></i> <?= htmlspecialchars($settingsSuccess, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php } ?>

        <form autocomplete="off" method="post" action="./admin.php?id=admin-settings" style="padding:10px; border:1px solid rgba(128,128,128,0.25); border-radius:6px; background:rgba(0,0,0,0.08);">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="edit_admin_id" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['id'], ENT_QUOTES, 'UTF-8') : ''; ?>">

          <table class="table table-sm" style="margin-bottom:8px;">
            <tr>
              <td class="align-middle" style="width:38%;"><?= $isBn ? 'পূর্ণ নাম / প্রতিষ্ঠানের নাম' : 'Full / ISP Name'; ?></td>
              <td>
                <input class="form-control" type="text" name="admin_name" maxlength="80" placeholder="<?= $isBn ? 'যেমন: করিম ওয়াইফাই জোন' : 'e.g. Karim WiFi Zone'; ?>" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['name'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= $_user_name ?></td>
              <td>
                <input class="form-control" type="text" name="admin_username" maxlength="64" placeholder="<?= $isBn ? 'লগইন ইউজারনেম' : 'Login Username'; ?>" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['username'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= $_password ?></td>
              <td>
                <div class="input-group">
                  <div class="input-group-11 col-box-10">
                    <input class="group-item group-item-l" id="adm_user_pass" type="password" name="admin_password" placeholder="<?= $editingAdmin ? ($isBn ? 'বর্তমান পাসওয়ার্ড রাখতে খালি রাখুন' : 'Leave blank to keep current password') : ($isBn ? 'পাসওয়ার্ড দিন' : 'Enter Password'); ?>" autocomplete="new-password" <?= $editingAdmin ? '' : 'required'; ?>>
                  </div>
                  <div class="input-group-1 col-box-2">
                    <div class="group-item group-item-r pd-2p5 text-center align-middle">
                      <input title="Show/Hide Password" type="checkbox" onclick="Pass('adm_user_pass')">
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            <?php if ($isSuperStore) {
              $isEditingPrimary = ($editingAdmin && (string)$editingAdmin['id'] === 'adm_1');
              $currTier = $editingAdmin && isset($editingAdmin['admin_tier']) ? (string)$editingAdmin['admin_tier'] : 'licensed_admin';
              if ($isEditingPrimary) $currTier = 'super_admin';
            ?>
            <tr>
              <td class="align-middle"><?= $isBn ? 'অ্যাডমিন ধরন (Role)' : 'Admin Role Tier'; ?></td>
              <td>
                <?php if ($isEditingPrimary) { ?>
                  <input type="hidden" name="admin_tier" value="super_admin">
                  <b class="text-green"><?= $isBn ? 'মেইন অ্যাডমিন স্টোর (Super Admin)' : 'Main Admin Store (Super Admin)'; ?></b>
                <?php } else { ?>
                  <select class="form-control" id="admin_tier_select" name="admin_tier" onchange="toggleLicenseQuotaBox()">
                    <option value="licensed_admin" <?= $currTier === 'licensed_admin' ? 'selected' : ''; ?>><?= $isBn ? 'লাইসেন্সড অ্যাডমিন (লিমিট যুক্ত সাব-অ্যাডমিন)' : 'Licensed Sub-Admin (Quota Limited)'; ?></option>
                    <option value="super_admin" <?= $currTier === 'super_admin' ? 'selected' : ''; ?>><?= $isBn ? 'সুপার অ্যাডমিন (লিমিট ছাড়া স্টোর ওনার)' : 'Super Admin (Unlimited Store Owner)'; ?></option>
                  </select>
                <?php } ?>
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= $isBn ? 'মোবাইল / নোট' : 'Phone / Note'; ?></td>
              <td>
                <input class="form-control" type="text" name="admin_phone" maxlength="100" placeholder="<?= $isBn ? 'মোবাইল নম্বর বা নোট (ঐচ্ছিক)' : 'Phone number or note (optional)'; ?>" value="<?= $editingAdmin && isset($editingAdmin['phone']) ? htmlspecialchars($editingAdmin['phone'], ENT_QUOTES, 'UTF-8') : ''; ?>">
              </td>
            </tr>
            <?php } ?>
          </table>

          <?php if ($isSuperStore && !$isEditingPrimary) { ?>
          <div id="license_quota_fields_box" style="display:<?= $currTier === 'super_admin' ? 'none' : 'block'; ?>; padding:10px; margin-bottom:10px; border-radius:6px; border:1px solid rgba(56,139,253,0.35); background:rgba(31,111,235,0.07);">
            <div style="font-weight:bold; color:#58a6ff; margin-bottom:8px; font-size:13px;">
              <i class="fa fa-sliders"></i> <?= $isBn ? 'লাইসেন্সের মেয়াদ ও কোটা লিমিট (০ = আনলিমিটেড)' : 'License Validity & Quota Limits (0 = Unlimited)'; ?>
            </div>
            <table class="table table-sm" style="margin-bottom:4px;">
              <tr>
                <td class="align-middle" style="width:45%;"><?= $isBn ? 'অ্যাকাউন্ট স্ট্যাটাস' : 'Account Status'; ?></td>
                <td>
                  <?php $currSt = ($editingAdmin && isset($editingAdmin['status'])) ? $editingAdmin['status'] : 'active'; ?>
                  <select class="form-control" name="admin_status">
                    <option value="active" <?= $currSt === 'active' ? 'selected' : ''; ?>><?= $isBn ? 'সচল (Active)' : 'Active'; ?></option>
                    <option value="suspended" <?= $currSt === 'suspended' ? 'selected' : ''; ?>><?= $isBn ? 'স্থগিত / লক (Suspended)' : 'Suspended / Locked'; ?></option>
                  </select>
                </td>
              </tr>
              <tr>
                <td class="align-middle"><?= $isBn ? 'লাইসেন্সের মেয়াদ (Expiry)' : 'License Expiry Date'; ?></td>
                <td>
                  <input class="form-control" type="date" id="license_expires_at_input" name="license_expires_at" value="<?= $editingAdmin && !empty($editingAdmin['license_expires_at']) ? htmlspecialchars($editingAdmin['license_expires_at'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                  <div style="margin-top:4px; display:flex; gap:4px; flex-wrap:wrap;">
                    <button type="button" class="btn bg-blue" style="padding:1px 6px; font-size:11px;" onclick="setExpiryOffsetDays(30)">+30 <?= $isBn ? 'দিন' : 'Days'; ?></button>
                    <button type="button" class="btn bg-blue" style="padding:1px 6px; font-size:11px;" onclick="setExpiryOffsetDays(90)">+90 <?= $isBn ? 'দিন' : 'Days'; ?></button>
                    <button type="button" class="btn bg-blue" style="padding:1px 6px; font-size:11px;" onclick="setExpiryOffsetDays(365)">+1 <?= $isBn ? 'বছর' : 'Year'; ?></button>
                    <button type="button" class="btn bg-green" style="padding:1px 6px; font-size:11px;" onclick="setExpiryOffsetDays(0)"><?= $isBn ? 'আজীবন (Lifetime)' : 'Lifetime'; ?></button>
                  </div>
                </td>
              </tr>
              <tr>
                <td class="align-middle"><?= $isBn ? 'সর্বোচ্চ রাউটার অ্যাড লিমিট' : 'Max Routers Allowed'; ?></td>
                <td>
                  <input class="form-control" type="number" min="0" max="10000" name="max_routers" value="<?= $editingAdmin && isset($editingAdmin['max_routers']) ? (int)$editingAdmin['max_routers'] : 1; ?>" required>
                  <small style="opacity:0.8;"><?= $isBn ? 'যেমন: ১ বা ২ টি রাউটার (০ দিলে আনলিমিটেড)' : 'e.g. 1 or 2 routers (0 = Unlimited)'; ?></small>
                </td>
              </tr>
              <tr>
                <td class="align-middle"><?= $isBn ? 'রাউটারে মোট ইউজার লিমিট' : 'Max Total Users (Per Router)'; ?></td>
                <td>
                  <input class="form-control" type="number" min="0" max="500000" name="max_total_users" value="<?= $editingAdmin && isset($editingAdmin['max_total_users']) ? (int)$editingAdmin['max_total_users'] : 500; ?>" required>
                </td>
              </tr>
              <tr>
                <td class="align-middle"><?= $isBn ? 'একবারে ব্যাচ ভাউচার লিমিট' : 'Max Batch Vouchers (Single Click)'; ?></td>
                <td>
                  <input class="form-control" type="number" min="0" max="5000" name="max_batch_qty" value="<?= $editingAdmin && isset($editingAdmin['max_batch_qty']) ? (int)$editingAdmin['max_batch_qty'] : 100; ?>" required>
                </td>
              </tr>
              <tr>
                <td class="align-middle"><?= $isBn ? 'মাসিক মোট ভাউচার লিমিট' : 'Monthly Voucher Generation Limit'; ?></td>
                <td>
                  <input class="form-control" type="number" min="0" max="1000000" name="max_monthly_vouchers" value="<?= $editingAdmin && isset($editingAdmin['max_monthly_vouchers']) ? (int)$editingAdmin['max_monthly_vouchers'] : 2000; ?>" required>
                </td>
              </tr>
              <tr>
                <td class="align-middle"><?= $isBn ? 'সর্বোচ্চ অ্যাক্টিভ ইউজার লিমিট' : 'Max Active Online Users Limit'; ?></td>
                <td>
                  <input class="form-control" type="number" min="0" max="100000" name="max_active_users" value="<?= $editingAdmin && isset($editingAdmin['max_active_users']) ? (int)$editingAdmin['max_active_users'] : 100; ?>" required>
                </td>
              </tr>
              <tr>
                <td class="align-middle"><?= $isBn ? 'সর্বোচ্চ রিসেলার তৈরির লিমিট' : 'Max Resellers Allowed'; ?></td>
                <td>
                  <input class="form-control" type="number" min="0" max="10000" name="max_resellers" value="<?= $editingAdmin && isset($editingAdmin['max_resellers']) ? (int)$editingAdmin['max_resellers'] : 5; ?>" required>
                </td>
              </tr>
            </table>
          </div>
          <?php } ?>

          <div style="display:flex; justify-content:flex-end; gap:6px;">
            <?php if ($editingAdmin && $isSuperStore) { ?>
              <a class="btn bg-secondary" href="./admin.php?id=admin-settings"><i class="fa fa-times"></i> <?= $isBn ? 'বাতিল' : 'Cancel'; ?></a>
              <button class="btn bg-warning" type="submit" name="save_admin_user" style="cursor:pointer;">
                <i class="fa fa-check"></i> <?= $isBn ? 'লাইসেন্স ও তথ্য আপডেট করুন' : 'Update Admin & License'; ?>
              </button>
            <?php } elseif (!$isSuperStore) { ?>
              <button class="btn bg-primary" type="submit" name="save_admin_user" style="cursor:pointer;">
                <i class="fa fa-save"></i> <?= $isBn ? 'তথ্য সংরক্ষণ করুন' : 'Save My Account'; ?>
              </button>
            <?php } else { ?>
              <button class="btn bg-primary" type="submit" name="save_admin_user" style="cursor:pointer;">
                <i class="fa fa-plus"></i> <?= $isBn ? 'লাইসেন্সড অ্যাডমিন তৈরি করুন' : 'Create Licensed Admin'; ?>
              </button>
            <?php } ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($isSuperStore) { ?>
  <!-- Super Admin Store: All Licensed Admins & Store Inventory -->
  <div class="col-7">
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <h3 class="card-title" style="margin:0;"><i class="fa fa-users"></i> <?= $isBn ? 'মেইন অ্যাডমিন স্টোর — সব অ্যাডমিন ও লাইসেন্স তালিকা' : 'Main Admin Store — Configured Admins & Licenses'; ?></h3>
      </div>
      <div class="card-body">
        <div class="overflow" style="margin-bottom:16px;">
          <table class="table table-bordered table-hover table-sm" style="margin-bottom:0;">
            <thead>
              <tr>
                <th>#</th>
                <th><?= $isBn ? 'অ্যাডমিন ও ইউজারনেম' : 'Admin & Username'; ?></th>
                <th><?= $isBn ? 'স্ট্যাটাস ও মেয়াদ' : 'License & Expiry'; ?></th>
                <th><?= $isBn ? 'রাউটার ও রিসেলার' : 'Routers & Resellers'; ?></th>
                <th><?= $isBn ? 'ইউজার ও ভাউচার কোটা' : 'Voucher & User Quotas'; ?></th>
                <th class="text-center"><?= $_action ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($adminUsers as $idx => $adm) {
                $admId = (string)$adm['id'];
                $isSelf = ($admId === $currentLoggedAdminId);
                $isAdmSuper = ($admId === 'adm_1' || (isset($adm['admin_tier']) && $adm['admin_tier'] === 'super_admin'));
                $ownedRouters = ngwGetRoutersForAdmin($admId, false);
                $ownedResellers = ngwGetResellersForAdmin($admId);
                $usedMonthly = ngwGetAdminMonthlyVoucherUsage($admId);
                $lockReason = '';
                $isLocked = ngwIsAdminAccountLocked($adm, $lockReason);
                $daysRem = ngwGetLicenseDaysRemaining($adm);
              ?>
              <tr>
                <td class="align-middle"><?= $idx + 1; ?></td>
                <td class="align-middle">
                  <b><?= htmlspecialchars($adm['name'], ENT_QUOTES, 'UTF-8'); ?></b>
                  <?php if ($isSelf) { ?>
                    <span style="font-size:10px; padding:1px 6px; border-radius:4px; background:#2ea043; color:#fff; margin-left:4px;"><?= $isBn ? 'আপনি' : 'You'; ?></span>
                  <?php } ?>
                  <br>
                  <code><?= htmlspecialchars($adm['username'], ENT_QUOTES, 'UTF-8'); ?></code>
                  <?php if (!empty($adm['phone'])) { ?>
                    <br><small style="opacity:0.8;"><i class="fa fa-phone"></i> <?= htmlspecialchars($adm['phone'], ENT_QUOTES, 'UTF-8'); ?></small>
                  <?php } ?>
                </td>
                <td class="align-middle">
                  <?php if ($isAdmSuper) { ?>
                    <b class="text-green"><i class="fa fa-star"></i> <?= $isBn ? 'মেইন অ্যাডমিন স্টোর' : 'Main Admin Store'; ?></b><br>
                    <small><?= $isBn ? 'আনলিমিটেড / আজীবন' : 'Unlimited / Lifetime'; ?></small>
                  <?php } else { ?>
                    <?php if ($isLocked) { ?>
                      <b class="text-red"><i class="fa fa-lock"></i> <?= ($lockReason === 'expired') ? ($isBn ? 'মেয়াদ উত্তীর্ণ ( অটো-লক)' : 'Expired (Auto-Locked)') : ($isBn ? 'স্থগিত (Suspended)' : 'Suspended'); ?></b>
                    <?php } else { ?>
                      <b class="text-green"><i class="fa fa-check-circle"></i> <?= $isBn ? 'সচল (Active)' : 'Active'; ?></b>
                    <?php } ?>
                    <br>
                    <?php if (empty($adm['license_expires_at'])) { ?>
                      <small><?= $isBn ? 'মেয়াদ: আজীবন (Lifetime)' : 'Validity: Lifetime'; ?></small>
                    <?php } else { ?>
                      <small><?= $isBn ? 'মেয়াদ: ' : 'Exp: '; ?><?= htmlspecialchars($adm['license_expires_at'], ENT_QUOTES, 'UTF-8'); ?>
                      <?php if ($daysRem !== null) { ?>
                        (<?= $daysRem >= 0 ? ($daysRem . ($isBn ? ' দিন বাকি' : 'd left')) : ($isBn ? 'মেয়াদ শেষ' : 'Expired'); ?>)
                      <?php } ?>
                      </small>
                    <?php } ?>
                  <?php } ?>
                </td>
                <td class="align-middle">
                  <?php if ($isAdmSuper) { ?>
                    <?= $isBn ? 'রাউটার: ' : 'Routers: '; ?><b><?= count($ownedRouters); ?></b> / ∞<br>
                    <?= $isBn ? 'রিসেলার: ' : 'Resellers: '; ?><b><?= count($ownedResellers); ?></b> / ∞
                  <?php } else {
                    $maxR = (int)$adm['max_routers'];
                    $maxRes = (int)$adm['max_resellers'];
                  ?>
                    <?= $isBn ? 'রাউটার: ' : 'Routers: '; ?><b><?= count($ownedRouters); ?></b> / <?= $maxR > 0 ? $maxR : '∞'; ?><br>
                    <?= $isBn ? 'রিসেলার: ' : 'Resellers: '; ?><b><?= count($ownedResellers); ?></b> / <?= $maxRes > 0 ? $maxRes : '∞'; ?>
                  <?php } ?>
                </td>
                <td class="align-middle" style="font-size:12px;">
                  <?php if ($isAdmSuper) { ?>
                    <span><?= $isBn ? 'সব কোটা আনলিমিটেড' : 'All Quotas Unlimited'; ?></span>
                  <?php } else {
                    $maxM = (int)$adm['max_monthly_vouchers'];
                    $maxTot = (int)$adm['max_total_users'];
                    $maxAct = (int)$adm['max_active_users'];
                    $maxB = (int)$adm['max_batch_qty'];
                  ?>
                    <?= $isBn ? 'মাসিক ভাউচার: ' : 'Monthly: '; ?><b><?= $usedMonthly; ?></b>/<?= $maxM > 0 ? $maxM : '∞'; ?><br>
                    <?= $isBn ? 'মোট ইউজার: ' : 'Total Users: '; ?><b><?= $maxTot > 0 ? $maxTot : '∞'; ?></b> · <?= $isBn ? 'ব্যাচ: ' : 'Batch: '; ?><b><?= $maxB > 0 ? $maxB : '∞'; ?></b><br>
                    <?= $isBn ? 'অ্যাক্টিভ ইউজার লিমিট: ' : 'Active Limit: '; ?><b><?= $maxAct > 0 ? $maxAct : '∞'; ?></b>
                  <?php } ?>
                </td>
                <td class="align-middle text-center" style="white-space:nowrap;">
                  <?php if (!$isSelf) { ?>
                  <a class="btn bg-green" style="padding:2px 6px; font-size:11px; margin:1px;" href="./admin.php?id=admin-settings&login-as-admin=<?= urlencode($admId); ?>" title="<?= $isBn ? 'এই অ্যাডমিন হিসেবে প্রবেশ করুন' : 'Login as this Admin'; ?>">
                    <i class="fa fa-sign-in"></i> <?= $isBn ? 'প্রবেশ' : 'Login'; ?>
                  </a>
                  <?php } ?>
                  <a class="btn bg-primary" style="padding:2px 6px; font-size:11px; margin:1px;" href="./admin.php?id=admin-settings&edit_admin=<?= urlencode($admId); ?>" title="<?= $isBn ? 'এডিট ও লিমিট পরিবর্তন' : 'Edit Quotas & License'; ?>">
                    <i class="fa fa-edit"></i> <?= $_edit ?>
                  </a>
                  <?php if (!$isAdmSuper) { ?>
                  <form method="post" action="./admin.php?id=admin-settings" style="display:inline; margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="target_admin_id" value="<?= htmlspecialchars($admId, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="extend_admin_30d" class="btn bg-blue" style="padding:2px 6px; font-size:11px; cursor:pointer; margin:1px;" title="<?= $isBn ? 'মেয়াদ +৩০ দিন বাড়ান' : 'Extend +30 Days'; ?>">
                      +30d
                    </button>
                    <button type="submit" name="toggle_admin_status" class="btn <?= (isset($adm['status']) && $adm['status'] === 'suspended') ? 'bg-green' : 'bg-warning'; ?>" style="padding:2px 6px; font-size:11px; cursor:pointer; margin:1px;" title="<?= (isset($adm['status']) && $adm['status'] === 'suspended') ? ($isBn ? 'সচল করুন' : 'Activate') : ($isBn ? 'স্থগিত/লক করুন' : 'Suspend'); ?>">
                      <i class="fa <?= (isset($adm['status']) && $adm['status'] === 'suspended') ? 'fa-unlock' : 'fa-ban'; ?>"></i>
                    </button>
                  </form>
                  <?php } ?>
                  <?php if ($admId !== 'adm_1' && count($adminUsers) > 1) {
                    $delAdminMsg = ($isBn ? 'অ্যাডমিন অ্যাকাউন্ট মুছে ফেলবেন: ' : 'Delete Admin user ') . $adm['username'] . '?';
                  ?>
                  <form method="post" action="./admin.php?id=admin-settings" style="display:inline; margin:0;" onsubmit="return confirm(<?= htmlspecialchars(json_encode($delAdminMsg), ENT_QUOTES, 'UTF-8'); ?>);">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="delete_admin_id" value="<?= htmlspecialchars($admId, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="delete_admin_user" class="btn bg-danger" style="padding:2px 6px; font-size:11px; cursor:pointer; margin:1px;" title="<?= $_delete ?>">
                      <i class="fa fa-trash"></i>
                    </button>
                  </form>
                  <?php } ?>
                </td>
              </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>

        <!-- Hierarchical Store Breakdown: All Routers & Resellers across Licensed Admins -->
        <div style="font-weight:bold; color:#3c8dbc; margin-bottom:8px; font-size:13px;">
          <i class="fa fa-sitemap"></i> <?= $isBn ? 'অ্যাডমিন ও রিসেলার অনুযায়ী যুক্ত সব রাউটার তালিকা (Main Admin Store Tree)' : 'All Routers & Resellers by Admin (Main Admin Store Hierarchy)'; ?>
        </div>
        <div class="overflow">
          <table class="table table-bordered table-sm" style="margin-bottom:0;">
            <thead>
              <tr>
                <th><?= $isBn ? 'রাউটার সেশন (Hotspot)' : 'Router Session (Hotspot)'; ?></th>
                <th><?= $isBn ? 'মালিক অ্যাডমিন (Licensed Admin)' : 'Owner Admin'; ?></th>
                <th><?= $isBn ? 'যুক্তকারী (Created By)' : 'Added By'; ?></th>
                <th><?= $isBn ? 'নির্ধারিত রিসেলার' : 'Assigned Resellers'; ?></th>
                <th class="text-center"><?= $_action ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($allRouters)) { ?>
              <tr>
                <td colspan="5" class="text-center"><?= $isBn ? 'কোনো রাউটার যুক্ত করা হয়নি।' : 'No routers configured yet.'; ?></td>
              </tr>
              <?php } else {
                foreach ($allRouters as $rSess) {
                  $hsTitle = (isset($data[$rSess][4]) && strpos($data[$rSess][4], '%') !== false)
                    ? explode('%', $data[$rSess][4])[1]
                    : $rSess;
                  $ownerInfo = ngwGetRouterOwnerInfo($rSess);
                  $assignedResNames = array();
                  foreach ($allResellers as $resRow) {
                    if (isset($resRow['allowed_routers']) && is_array($resRow['allowed_routers']) && in_array($rSess, $resRow['allowed_routers'], true)) {
                      $assignedResNames[] = $resRow['username'];
                    }
                  }
              ?>
              <tr>
                <td class="align-middle">
                  <b><?= htmlspecialchars($hsTitle, ENT_QUOTES, 'UTF-8'); ?></b> (<code><?= htmlspecialchars($rSess, ENT_QUOTES, 'UTF-8'); ?></code>)
                </td>
                <td class="align-middle">
                  <b><?= htmlspecialchars($ownerInfo['owner_admin_name'], ENT_QUOTES, 'UTF-8'); ?></b>
                  (<code><?= htmlspecialchars($ownerInfo['owner_admin_username'], ENT_QUOTES, 'UTF-8'); ?></code>)
                </td>
                <td class="align-middle">
                  <?= htmlspecialchars($ownerInfo['created_by_username'], ENT_QUOTES, 'UTF-8'); ?>
                  (<?= htmlspecialchars($ownerInfo['created_by_role'], ENT_QUOTES, 'UTF-8'); ?>)
                </td>
                <td class="align-middle">
                  <?= !empty($assignedResNames) ? htmlspecialchars(implode(', ', $assignedResNames), ENT_QUOTES, 'UTF-8') : '<span style="opacity:0.6;">—</span>'; ?>
                </td>
                <td class="align-middle text-center" style="white-space:nowrap;">
                  <span class="connect pointer btn bg-green" style="padding:2px 6px; font-size:11px;" id="<?= htmlspecialchars($rSess, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-external-link"></i> <?= $_open ?></span>
                  <a class="btn bg-primary" style="padding:2px 6px; font-size:11px;" href="./admin.php?id=settings&session=<?= urlencode($rSess); ?>"><i class="fa fa-edit"></i> <?= $_edit ?></a>
                </td>
              </tr>
              <?php } } ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <?php } else {
    // Licensed Sub-Admin: My License & Quota Summary Card
    $myRouters = ngwGetRoutersForAdmin($currentLoggedAdminId, false);
    $myResellers = ngwGetResellersForAdmin($currentLoggedAdminId);
    $myMonthlyUsed = ngwGetAdminMonthlyVoucherUsage($currentLoggedAdminId);
    $myDaysLeft = ngwGetLicenseDaysRemaining($currentLoggedRecord);
    $maxR = isset($currentLoggedRecord['max_routers']) ? (int)$currentLoggedRecord['max_routers'] : 0;
    $maxRes = isset($currentLoggedRecord['max_resellers']) ? (int)$currentLoggedRecord['max_resellers'] : 0;
    $maxM = isset($currentLoggedRecord['max_monthly_vouchers']) ? (int)$currentLoggedRecord['max_monthly_vouchers'] : 0;
    $maxTot = isset($currentLoggedRecord['max_total_users']) ? (int)$currentLoggedRecord['max_total_users'] : 0;
    $maxAct = isset($currentLoggedRecord['max_active_users']) ? (int)$currentLoggedRecord['max_active_users'] : 0;
    $maxB = isset($currentLoggedRecord['max_batch_qty']) ? (int)$currentLoggedRecord['max_batch_qty'] : 0;
  ?>
  <div class="col-6">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fa fa-id-card"></i> <?= $isBn ? 'আমার লাইসেন্স ও কোটা স্ট্যাটাস' : 'My License & Quota Status'; ?></h3>
      </div>
      <div class="card-body">
        <table class="table table-bordered table-sm">
          <tr>
            <td style="width:48%;"><b><?= $isBn ? 'লাইসেন্স স্ট্যাটাস' : 'License Status'; ?></b></td>
            <td><b class="text-green"><i class="fa fa-check-circle"></i> <?= $isBn ? 'সচল (Active Licensed Admin)' : 'Active Licensed Admin'; ?></b></td>
          </tr>
          <tr>
            <td><b><?= $isBn ? 'লাইসেন্সের মেয়াদ' : 'License Expiration'; ?></b></td>
            <td>
              <?php if (empty($currentLoggedRecord['license_expires_at'])) { ?>
                <b><?= $isBn ? 'আজীবন (Lifetime)' : 'Lifetime'; ?></b>
              <?php } else { ?>
                <b><?= htmlspecialchars($currentLoggedRecord['license_expires_at'], ENT_QUOTES, 'UTF-8'); ?></b>
                <?php if ($myDaysLeft !== null) { ?>
                  (<?= $myDaysLeft; ?> <?= $isBn ? 'দিন বাকি' : 'days remaining'; ?>)
                <?php } ?>
              <?php } ?>
            </td>
          </tr>
          <tr>
            <td><b><?= $isBn ? 'রাউটার অ্যাড কোটা' : 'Router Limit'; ?></b></td>
            <td><b><?= count($myRouters); ?></b> / <?= $maxR > 0 ? $maxR : ($isBn ? 'আনলিমিটেড' : 'Unlimited'); ?></td>
          </tr>
          <tr>
            <td><b><?= $isBn ? 'রিসেলার তৈরির কোটা' : 'Reseller Limit'; ?></b></td>
            <td><b><?= count($myResellers); ?></b> / <?= $maxRes > 0 ? $maxRes : ($isBn ? 'আনলিমিটেড' : 'Unlimited'); ?></td>
          </tr>
          <tr>
            <td><b><?= $isBn ? 'মাসিক ভাউচার জেনারেট কোটা' : 'Monthly Voucher Quota'; ?></b></td>
            <td><b><?= $myMonthlyUsed; ?></b> / <?= $maxM > 0 ? $maxM : ($isBn ? 'আনলিমিটেড' : 'Unlimited'); ?></td>
          </tr>
          <tr>
            <td><b><?= $isBn ? 'রাউটারে মোট ইউজার লিমিট' : 'Max Total Users per Router'; ?></b></td>
            <td><b><?= $maxTot > 0 ? $maxTot : ($isBn ? 'আনলিমিটেড' : 'Unlimited'); ?></b></td>
          </tr>
          <tr>
            <td><b><?= $isBn ? 'একবারে ব্যাচ ভাউচার লিমিট' : 'Max Batch Vouchers per Click'; ?></b></td>
            <td><b><?= $maxB > 0 ? $maxB : ($isBn ? 'আনলিমিটেড' : 'Unlimited'); ?></b></td>
          </tr>
          <tr>
            <td><b><?= $isBn ? 'সর্বোচ্চ অ্যাক্টিভ ইউজার লিমিট' : 'Max Active Online Users'; ?></b></td>
            <td><b><?= $maxAct > 0 ? $maxAct : ($isBn ? 'আনলিমিটেড' : 'Unlimited'); ?></b></td>
          </tr>
        </table>
      </div>
    </div>
  </div>
  <?php } ?>
</div>
