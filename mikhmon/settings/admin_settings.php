<?php
/*
 *  NextGan WiFi Solution — Dedicated Admin Settings Page
 *  Supports dynamic Multi-User Admin Management (Add, Edit, Delete Admin Users),
 *  Quick Print QR setting, and Standalone Server (.zip) Download.
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
  : '';

// Handle Add or Update Admin User
if (isset($_POST['save_admin_user'])) {
  $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
    $settingsError = 'Security token expired or invalid. Reload this page and try again.';
  } else {
    $editId = isset($_POST['edit_admin_id']) ? trim((string)$_POST['edit_admin_id']) : '';
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
        } else {
          $newId = generateNextAdminUserId($adminUsers);
          $adminUsers[] = array(
            'id' => $newId,
            'name' => $admName,
            'username' => $admUser,
            'password_enc' => encrypt($admPass),
            'password_hash' => password_hash($admPass, PASSWORD_DEFAULT),
            'avatar' => '',
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

// Handle Delete Admin User
if (isset($_POST['delete_admin_user'])) {
  $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  $delId = isset($_POST['delete_admin_id']) ? trim((string)$_POST['delete_admin_id']) : '';
  if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
    $settingsError = 'Security token expired or invalid. Reload this page and try again.';
  } elseif (count($adminUsers) <= 1) {
    $settingsError = 'Cannot delete the last remaining Admin user. At least one Admin account must remain active.';
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
      if ($delId === $currentLoggedAdminId && !empty($adminUsers[0])) {
        $_SESSION['mikhmon'] = $adminUsers[0]['username'];
        $_SESSION['mikhmon_admin_id'] = $adminUsers[0]['id'];
      }
      echo "<script>window.location='./admin.php?id=admin-settings&msg=deleted'</script>";
      exit;
    }
  }
}

$editingAdmin = null;
if (isset($_GET['edit_admin']) && $_GET['edit_admin'] !== '') {
  $reqEditId = trim((string)$_GET['edit_admin']);
  foreach ($adminUsers as $adm) {
    if ((string)$adm['id'] === $reqEditId) {
      $editingAdmin = $adm;
      break;
    }
  }
}

$isBn = (isset($langid) && $langid === 'bn') || (isset($_SESSION['lang']) && $_SESSION['lang'] === 'bn');
if (isset($_GET['msg'])) {
  if ($_GET['msg'] === 'added') $settingsSuccess = $isBn ? 'নতুন অ্যাডমিন ইউজার সফলভাবে যুক্ত হয়েছে!' : 'New Admin user added successfully!';
  elseif ($_GET['msg'] === 'updated') $settingsSuccess = $isBn ? 'অ্যাডমিন ইউজার সফলভাবে আপডেট হয়েছে!' : 'Admin user updated successfully!';
  elseif ($_GET['msg'] === 'deleted') $settingsSuccess = $isBn ? 'অ্যাডমিন ইউজার সফলভাবে মুছে ফেলা হয়েছে!' : 'Admin user removed successfully!';
  elseif ($_GET['msg'] === 'qrsaved') $settingsSuccess = $isBn ? 'কুইক প্রিন্ট QR সেটিং সংরক্ষিত হয়েছে!' : 'Quick Print QR setting saved successfully!';
} elseif (isset($_GET['saved']) && $_GET['saved'] == '1') {
  $settingsSuccess = $isBn ? 'অ্যাডমিন সেটিংস সফলভাবে সংরক্ষিত হয়েছে!' : 'Admin settings saved successfully!';
}
?>
<script>
  function Pass(id){
    var x = document.getElementById(id);
    if (!x) return;
    if (x.type === 'password') {
      x.type = 'text';
    } else {
      x.type = 'password';
    }
  }
</script>

<div class="row">
  <div class="col-6">
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <h3 class="card-title" style="margin:0;"><i class="fa fa-user-secret"></i> <?= $isBn ? 'অ্যাডমিন ইউজার ও সেটিংস' : 'Admin Users &amp; Settings'; ?></h3>
        <span style="font-size:11px;padding:2px 8px;border-radius:10px;background:rgba(31,111,235,0.25);color:#fff;font-weight:bold;">
          <?= count($adminUsers); ?> <?= $isBn ? 'জন অ্যাডমিন' : ('Admin ' . (count($adminUsers) === 1 ? 'User' : 'Users')); ?>
        </span>
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

        <!-- Add / Edit Admin User Form -->
        <form autocomplete="off" method="post" action="./admin.php?id=admin-settings" style="margin-bottom:14px; padding:10px; border:1px solid rgba(128,128,128,0.25); border-radius:6px; background:rgba(0,0,0,0.08);">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="edit_admin_id" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['id'], ENT_QUOTES, 'UTF-8') : ''; ?>">
          <div style="font-weight:bold; color:<?= $editingAdmin ? '#f0ad4e' : '#2ea043'; ?>; margin-bottom:8px; font-size:13px;">
            <i class="fa <?= $editingAdmin ? 'fa-pencil-square-o' : 'fa-user-plus'; ?>"></i>
            <?= $editingAdmin ? (($isBn ? 'অ্যাডমিন ইউজার সম্পাদনা: ' : 'Edit Admin User: ') . htmlspecialchars($editingAdmin['username'], ENT_QUOTES, 'UTF-8')) : ($isBn ? 'নতুন অ্যাডমিন ইউজার যুক্ত করুন' : 'Add New Admin User'); ?>
          </div>
          <table class="table table-sm" style="margin-bottom:6px;">
            <tr>
              <td class="align-middle" style="width:34%;"><?= $isBn ? 'পূর্ণ নাম' : 'Full Name'; ?></td>
              <td>
                <input class="form-control" type="text" name="admin_name" maxlength="80" placeholder="<?= $isBn ? 'যেমন: Administrator / Manager' : 'e.g. Administrator / Manager'; ?>" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['name'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= $_user_name ?></td>
              <td>
                <input class="form-control" type="text" name="admin_username" maxlength="64" placeholder="<?= $isBn ? 'অ্যাডমিন ইউজারনেম লিখুন' : 'Enter Admin Username'; ?>" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['username'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= $_password ?></td>
              <td>
                <div class="input-group">
                  <div class="input-group-11 col-box-10">
                    <input class="group-item group-item-l" id="adm_user_pass" type="password" name="admin_password" placeholder="<?= $editingAdmin ? ($isBn ? 'বর্তমান পাসওয়ার্ড রাখতে খালি রাখুন' : 'Leave blank to keep current password') : ($isBn ? 'অ্যাডমিন পাসওয়ার্ড লিখুন' : 'Enter Admin Password'); ?>" autocomplete="new-password" <?= $editingAdmin ? '' : 'required'; ?>>
                  </div>
                  <div class="input-group-1 col-box-2">
                    <div class="group-item group-item-r pd-2p5 text-center align-middle">
                      <input title="Show/Hide Password" type="checkbox" onclick="Pass('adm_user_pass')">
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          </table>
          <div style="display:flex; justify-content:flex-end; gap:6px;">
            <?php if ($editingAdmin) { ?>
              <a class="btn bg-secondary" href="./admin.php?id=admin-settings"><i class="fa fa-times"></i> <?= $isBn ? 'বাতিল' : 'Cancel'; ?></a>
              <button class="btn bg-warning" type="submit" name="save_admin_user" style="cursor:pointer;">
                <i class="fa fa-check"></i> <?= $isBn ? 'আপডেট করুন' : 'Update Admin User'; ?>
              </button>
            <?php } else { ?>
              <button class="btn bg-primary" type="submit" name="save_admin_user" style="cursor:pointer;">
                <i class="fa fa-plus"></i> <?= $isBn ? 'অ্যাডমিন যুক্ত করুন' : 'Add Admin User'; ?>
              </button>
            <?php } ?>
          </div>
        </form>

        <!-- Admin Users List Table -->
        <div style="font-weight:bold; color:#3c8dbc; margin-bottom:6px; font-size:13px;">
          <i class="fa fa-users"></i> <?= $isBn ? 'কনফিগারকৃত অ্যাডমিন ইউজার তালিকা' : 'Configured Admin Users'; ?>
        </div>
        <div class="overflow" style="margin-bottom:14px;">
          <table class="table table-bordered table-hover table-sm" style="margin-bottom:0;">
            <thead>
              <tr>
                <th style="width:28px;">#</th>
                <th><?= $isBn ? 'পূর্ণ নাম' : 'Full Name'; ?></th>
                <th><?= $_user_name ?></th>
                <th style="width:105px;" class="text-center"><?= $_action ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($adminUsers as $idx => $adm) {
                $isSelf = ((string)$adm['id'] === $currentLoggedAdminId) || ((string)$adm['username'] === (string)$_SESSION['mikhmon']);
              ?>
              <tr>
                <td class="align-middle"><?= $idx + 1; ?></td>
                <td class="align-middle">
                  <b><?= htmlspecialchars($adm['name'], ENT_QUOTES, 'UTF-8'); ?></b>
                  <?php if ($isSelf) { ?>
                    <span style="font-size:10px; padding:1px 6px; border-radius:8px; background:#2ea043; color:#fff; margin-left:4px;"><?= $isBn ? 'আপনি' : 'You'; ?></span>
                  <?php } ?>
                </td>
                <td class="align-middle"><code><?= htmlspecialchars($adm['username'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                <td class="align-middle text-center" style="white-space:nowrap;">
                  <a class="btn bg-primary" style="padding:2px 6px; font-size:11px; margin:1px;" href="./admin.php?id=admin-settings&edit_admin=<?= urlencode($adm['id']); ?>" title="<?= $isBn ? 'সম্পাদনা' : 'Edit Admin User'; ?>">
                    <i class="fa fa-edit"></i> <?= $_edit ?>
                  </a>
                  <?php if (count($adminUsers) > 1) { 
                    $delAdminMsg = ($isBn ? 'অ্যাডমিন ইউজার মুছে ফেলবেন: ' : 'Delete Admin user ') . $adm['username'] . '?';
                  ?>
                  <form method="post" action="./admin.php?id=admin-settings" style="display:inline; margin:0;" onsubmit="return confirm(<?= htmlspecialchars(json_encode($delAdminMsg), ENT_QUOTES, 'UTF-8'); ?>);">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="delete_admin_id" value="<?= htmlspecialchars($adm['id'], ENT_QUOTES, 'UTF-8'); ?>">
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

        <div id="loadV">v<?= $_SESSION['v']; ?> </div>
        <div><b id="newVer" class="text-green"></b></div>
      </div>
    </div>
  </div>

  <div class="col-6">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="fa fa-windows"></i> <?= htmlspecialchars(isset($_srv_dl_title) ? $_srv_dl_title : 'Download Complete Standalone NextGen WiFi Solution Server'); ?></h3>
      </div>
      <div class="card-body">
        <p style="margin-top:0; margin-bottom:10px;">
          <?= isset($_srv_dl_desc) ? $_srv_dl_desc : 'Click the button below to download the complete ready-made portable server:'; ?>
        </p>
        <div style="margin-bottom:12px;">
          <a class="btn bg-green pd-10" href="./settings/export_package.php" style="display:inline-block; font-weight:bold; max-width:100%; white-space:normal;">
            <i class="fa fa-download"></i> <?= htmlspecialchars(isset($_srv_dl_btn) ? $_srv_dl_btn : 'Download Complete NextGen WiFi Solution Server (.zip)'); ?>
          </a>
        </div>
        <table class="table table-bordered table-sm" style="margin-bottom:0;">
          <tr>
            <td style="width:28px;"><b>1.</b></td>
            <td><?= isset($_srv_dl_step1) ? $_srv_dl_step1 : ''; ?></td>
          </tr>
          <tr>
            <td><b>2.</b></td>
            <td><?= isset($_srv_dl_step2) ? $_srv_dl_step2 : ''; ?></td>
          </tr>
          <tr>
            <td><b>3.</b></td>
            <td><?= isset($_srv_dl_step3) ? $_srv_dl_step3 : ''; ?></td>
          </tr>
        </table>
      </div>
    </div>
  </div>
</div>
