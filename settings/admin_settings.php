<?php
/*
 *  NextGan WiFi Solution — Dedicated Admin Settings Page
 *  Supports dynamic Multi-User Admin Management (Add, Edit, Delete Admin Users),
 *  Quick Print QR setting, and Standalone Server (.zip) Download.
 */
error_reporting(0);
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

include_once('./include/reseller_acl.php');

if (!isMikhmonAdminStore()) {
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
$isBn = (isset($langid) && $langid === 'bn') || (isset($_SESSION['lang']) && $_SESSION['lang'] === 'bn');
$permCategories = getAllMikhmonPermissionCategories();
$permMeta = function_exists('getMikhmonPermissionSectionsMeta') ? getMikhmonPermissionSectionsMeta() : array();

// Count how many Admin Store accounts currently exist
$adminStoreCount = 0;
foreach ($adminUsers as $auRow) {
  if (!isset($auRow['admin_role']) || $auRow['admin_role'] === 'admin_store') {
    $adminStoreCount++;
  }
}

// Handle Add or Update Admin User
if (isset($_POST['save_admin_user'])) {
  $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
    $settingsError = $isBn ? 'সিকিউরিটি টোকেন মেয়াদোত্তীর্ণ বা অবৈধ। পেজটি রিলোড করে আবার চেষ্টা করুন।' : 'Security token expired or invalid. Reload this page and try again.';
  } else {
    $editId = isset($_POST['edit_admin_id']) ? trim((string)$_POST['edit_admin_id']) : '';
    $admName = isset($_POST['admin_name']) ? trim((string)$_POST['admin_name']) : '';
    $admUser = isset($_POST['admin_username']) ? trim((string)$_POST['admin_username']) : '';
    $admPass = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
    $admRole = (isset($_POST['admin_role']) && $_POST['admin_role'] === 'admin') ? 'admin' : 'admin_store';

    $shortcutGroups = function_exists('getAdminShortcutPermissionGroups') ? getAdminShortcutPermissionGroups() : array();
    $disabledPerms = array();
    if ($admRole === 'admin' && !empty($_POST['admin_perm_form_submitted'])) {
      if (isset($_POST['adm_shortcut_submitted'])) {
        $postedShortcuts = isset($_POST['adm_shortcut_perms']) && is_array($_POST['adm_shortcut_perms']) ? $_POST['adm_shortcut_perms'] : array();
        foreach ($shortcutGroups as $sKey => $sMeta) {
          if (empty($postedShortcuts[$sKey])) {
            $disabledPerms[] = $sKey;
          }
        }
      } else {
        $postedAllowed = isset($_POST['adm_perms']) && is_array($_POST['adm_perms']) ? $_POST['adm_perms'] : array();
        foreach ($permCategories as $catName => $catItems) {
          foreach ($catItems as $pKey => $pLabel) {
            if ($pKey === 'manage_admin_account') {
              continue;
            }
            if (empty($postedAllowed[$pKey])) {
              $disabledPerms[] = $pKey;
            }
          }
        }
      }
      $disabledPerms = sanitizeAdminDisabledPermissions($disabledPerms, 'admin');
    }

    if ($admName === '') {
      $admName = $admUser;
    }

    // Check if demoting the last remaining Admin Store user
    $demotingLastStore = false;
    if ($editId !== '' && $admRole === 'admin') {
      foreach ($adminUsers as $existing) {
        $exRole = (!isset($existing['admin_role']) || $existing['admin_role'] === 'admin_store') ? 'admin_store' : 'admin';
        if ((string)$existing['id'] === $editId && $exRole === 'admin_store' && $adminStoreCount <= 1) {
          $demotingLastStore = true;
          break;
        }
      }
    }

    if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $admUser)) {
      $settingsError = $isBn ? 'অ্যাডমিন ইউজারনেম ৩ থেকে ৬৪ অক্ষরের হতে হবে (অক্ষর, সংখ্যা, ডট, আন্ডারস্কোর, @ বা হাইফেন)।' : 'Admin username must be 3–64 characters and use only letters, numbers, dot, underscore, @ or hyphen.';
    } elseif ($demotingLastStore) {
      $settingsError = $isBn ? 'সর্বশেষ Admin Store ইউজারকে সাধারণ Admin রোলে পরিবর্তন করা যাবে না। অন্তত একজন Admin Store থাকতে হবে।' : 'Cannot change the last remaining Admin Store account to Admin. At least one Admin Store must remain.';
    } elseif ($editId === '' && (strlen($admPass) < 4 || strlen($admPass) > 256 || preg_match('/[\r\n]/', $admPass))) {
      $settingsError = $isBn ? 'নতুন অ্যাডমিন ইউজার তৈরির জন্য পাসওয়ার্ড (৪-২৫৬ অক্ষর) আবশ্যক।' : 'Password is required (4–256 characters) when adding a new Admin user.';
    } elseif ($editId !== '' && $admPass !== '' && (strlen($admPass) < 4 || strlen($admPass) > 256 || preg_match('/[\r\n]/', $admPass))) {
      $settingsError = $isBn ? 'পাসওয়ার্ড ৪ থেকে ২৫৬ অক্ষরের মধ্যে হতে হবে।' : 'Password must be between 4 and 256 characters.';
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
        $settingsError = $isBn
          ? ('ইউজারনেম "' . $admUser . '" ইতিমধ্যেই অন্য অ্যাডমিন বা রিসেলার অ্যাকাউন্টে ব্যবহৃত হচ্ছে।')
          : ('Username "' . $admUser . '" is already in use by another Admin or Reseller account.');
      } else {
        if ($editId !== '') {
          $found = false;
          foreach ($adminUsers as $idx => $existing) {
            if ((string)$existing['id'] === $editId) {
              $adminUsers[$idx]['name'] = $admName;
              $adminUsers[$idx]['username'] = $admUser;
              $adminUsers[$idx]['admin_role'] = $admRole;
              $adminUsers[$idx]['disabled_permissions'] = ($admRole === 'admin') ? $disabledPerms : array();
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
            $settingsError = $isBn ? 'নির্বাচিত অ্যাডমিন ইউজার পাওয়া যায়নি।' : 'Selected Admin user was not found.';
          } elseif (!saveAdminUsers($adminUsers)) {
            $settingsError = $isBn ? 'অ্যাডমিন ইউজার তালিকা সেভ করা যায়নি।' : 'Could not save Admin users list. Check file permissions.';
          } else {
            if ($editId === $currentLoggedAdminId) {
              $_SESSION['mikhmon'] = $admUser;
              $_SESSION['mikhmon_admin_id'] = $editId;
              $_SESSION['mikhmon_admin_role'] = $admRole;
            }
            if (function_exists('ngwRecordActivity')) {
              ngwRecordActivity('admin_user_updated', array(
                'target' => $admUser,
                'status' => ($admRole === 'admin_store' ? 'Admin Store' : 'Admin'),
              ));
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
            'admin_role' => $admRole,
            'disabled_permissions' => ($admRole === 'admin') ? $disabledPerms : array(),
            'password_enc' => encrypt($admPass),
            'password_hash' => password_hash($admPass, PASSWORD_DEFAULT),
            'avatar' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
          );
          if (!saveAdminUsers($adminUsers)) {
            $settingsError = $isBn ? 'নতুন অ্যাডমিন ইউজার সেভ করা যায়নি।' : 'Could not save new Admin user. Check file permissions.';
          } else {
            if (function_exists('ngwRecordActivity')) {
              ngwRecordActivity('admin_user_created', array(
                'target' => $admUser,
                'status' => ($admRole === 'admin_store' ? 'Admin Store' : 'Admin'),
              ));
            }
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
    $settingsError = $isBn ? 'সিকিউরিটি টোকেন মেয়াদোত্তীর্ণ বা অবৈধ। পেজটি রিলোড করে আবার চেষ্টা করুন।' : 'Security token expired or invalid. Reload this page and try again.';
  } elseif (count($adminUsers) <= 1) {
    $settingsError = $isBn ? 'সর্বশেষ অ্যাডমিন ইউজার ডিলিট করা যাবে না।' : 'Cannot delete the last remaining Admin user. At least one Admin account must remain active.';
  } else {
    $deletingLastStore = false;
    $deletedUsername = $delId;
    foreach ($adminUsers as $adm) {
      if ((string)$adm['id'] === $delId) {
        $deletedUsername = (string)$adm['username'];
        $rRole = (!isset($adm['admin_role']) || $adm['admin_role'] === 'admin_store') ? 'admin_store' : 'admin';
        if ($rRole === 'admin_store' && $adminStoreCount <= 1) {
          $deletingLastStore = true;
        }
        break;
      }
    }
    if ($deletingLastStore) {
      $settingsError = $isBn ? 'সর্বশেষ Admin Store ইউজার ডিলিট করা যাবে না। অন্তত একজন Admin Store থাকতে হবে।' : 'Cannot delete the last remaining Admin Store user. At least one Admin Store account must remain.';
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
        $settingsError = $isBn ? 'অ্যাডমিন ইউজার পাওয়া যায়নি।' : 'Admin user not found.';
      } elseif (!saveAdminUsers($newList)) {
        $settingsError = $isBn ? 'অ্যাডমিন ইউজার ফাইল আপডেট করা যায়নি।' : 'Could not update Admin users file.';
      } else {
        $adminUsers = $newList;
        if ($delId === $currentLoggedAdminId && !empty($adminUsers[0])) {
          $_SESSION['mikhmon'] = $adminUsers[0]['username'];
          $_SESSION['mikhmon_admin_id'] = $adminUsers[0]['id'];
        }
        if (function_exists('ngwRecordActivity')) {
          ngwRecordActivity('admin_user_deleted', array('target' => $deletedUsername));
        }
        echo "<script>window.location='./admin.php?id=admin-settings&msg=deleted'</script>";
        exit;
      }
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

$formRole = 'admin';
$formDisabledPerms = array();
if ($editingAdmin) {
  $formRole = (isset($editingAdmin['admin_role']) && $editingAdmin['admin_role'] === 'admin') ? 'admin' : 'admin_store';
  $formDisabledPerms = isset($editingAdmin['disabled_permissions']) && is_array($editingAdmin['disabled_permissions'])
    ? $editingAdmin['disabled_permissions']
    : array();
}

if (isset($_GET['msg'])) {
  if ($_GET['msg'] === 'added') $settingsSuccess = $isBn ? 'নতুন অ্যাডমিন ইউজার সফলভাবে যুক্ত হয়েছে!' : 'New Admin user added successfully!';
  elseif ($_GET['msg'] === 'updated') $settingsSuccess = $isBn ? 'অ্যাডমিন ইউজার সফলভাবে আপডেট হয়েছে!' : 'Admin user updated successfully!';
  elseif ($_GET['msg'] === 'deleted') $settingsSuccess = $isBn ? 'অ্যাডমিন ইউজার সফলভাবে মুছে ফেলা হয়েছে!' : 'Admin user removed successfully!';
  elseif ($_GET['msg'] === 'qrsaved') $settingsSuccess = $isBn ? 'কুইক প্রিন্ট QR সেটিং সংরক্ষিত হয়েছে!' : 'Quick Print QR setting saved successfully!';
} elseif (isset($_GET['saved']) && $_GET['saved'] == '1') {
  $settingsSuccess = $isBn ? 'অ্যাডমিন সেটিংস সফলভাবে সংরক্ষিত হয়েছে!' : 'Admin settings saved successfully!';
}
?>
<style>
.ngw-adm-role-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 10px;
  font-weight: 700;
  color: #fff;
  white-space: nowrap;
}
.ngw-adm-role-badge.is-store {
  background: #6e40c9;
}
.ngw-adm-role-badge.is-admin {
  background: #1f6feb;
}
.ngw-adm-perm-panel {
  margin-top: 8px;
  margin-bottom: 10px;
  padding: 8px 10px;
  border: 1px solid rgba(56, 139, 253, 0.35);
  border-radius: 6px;
  background: rgba(31, 111, 235, 0.06);
}
.ngw-adm-perm-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(215px, 1fr));
  gap: 8px;
}
.ngw-adm-shortcut-card {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  border: 1px solid rgba(128, 128, 128, 0.28);
  border-radius: 6px;
  padding: 9px 10px;
  background: rgba(0, 0, 0, 0.14);
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
  margin: 0;
}
.ngw-adm-shortcut-card:hover {
  border-color: rgba(56, 139, 253, 0.65);
  background: rgba(31, 111, 235, 0.08);
}
.ngw-adm-shortcut-card input[type="checkbox"] {
  margin-top: 3px;
  width: 15px;
  height: 15px;
  flex-shrink: 0;
  cursor: pointer;
}
.ngw-adm-shortcut-title {
  font-size: 12px;
  font-weight: 700;
  line-height: 1.25;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 3px;
}
.ngw-adm-shortcut-desc {
  font-size: 10.5px;
  opacity: 0.82;
  line-height: 1.3;
  font-weight: normal;
}
</style>
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
  function ngwToggleAdminRolePerms() {
    var sel = document.getElementById('ngw_admin_role_select');
    var permBox = document.getElementById('ngw_admin_perm_box');
    var storeNote = document.getElementById('ngw_admin_store_note');
    if (!sel || !permBox || !storeNote) return;
    if (sel.value === 'admin') {
      permBox.style.display = 'block';
      storeNote.style.display = 'none';
    } else {
      permBox.style.display = 'none';
      storeNote.style.display = 'block';
    }
  }
  function ngwSetAllAdminPerms(checked) {
    var boxes = document.querySelectorAll('.ngw-adm-perm-cb');
    for (var i = 0; i < boxes.length; i++) {
      boxes[i].checked = !!checked;
    }
  }
</script>

<div class="row">
  <div class="col-6">
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <h3 class="card-title" style="margin:0;"><i class="fa fa-user-secret"></i> <?= $isBn ? 'অ্যাডমিন স্টোর ও অ্যাডমিন ইউজার' : 'Admin Store &amp; Admin Users'; ?></h3>
        <span style="font-size:11px;padding:2px 8px;border-radius:10px;background:rgba(31,111,235,0.25);color:#fff;font-weight:bold;">
          <?= count($adminUsers); ?> <?= $isBn ? 'জন ইউজার' : ('Admin ' . (count($adminUsers) === 1 ? 'User' : 'Users')); ?>
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
          <input type="hidden" name="admin_perm_form_submitted" value="1">
          <div style="font-weight:bold; color:<?= $editingAdmin ? '#f0ad4e' : '#2ea043'; ?>; margin-bottom:8px; font-size:13px;">
            <i class="fa <?= $editingAdmin ? 'fa-pencil-square-o' : 'fa-user-plus'; ?>"></i>
            <?= $editingAdmin ? (($isBn ? 'অ্যাডমিন ইউজার সম্পাদনা: ' : 'Edit Admin User: ') . htmlspecialchars($editingAdmin['username'], ENT_QUOTES, 'UTF-8')) : ($isBn ? 'নতুন অ্যাডমিন / অ্যাডমিন স্টোর যুক্ত করুন' : 'Add New Admin / Admin Store User'); ?>
          </div>
          <table class="table table-sm" style="margin-bottom:6px;">
            <tr>
              <td class="align-middle" style="width:34%;"><?= $isBn ? 'রোল (Role)' : 'Role'; ?></td>
              <td>
                <select class="form-control" id="ngw_admin_role_select" name="admin_role" onchange="ngwToggleAdminRolePerms()">
                  <option value="admin" <?= $formRole === 'admin' ? 'selected' : ''; ?>>
                    <?= $isBn ? 'Admin (অ্যাডমিন — নতুন আলাদা প্যানেল, রিসেলার ও রাউটার অ্যাড করতে পারবে)' : 'Admin (Separate Workspace — Can add Routers, Resellers & all features)'; ?>
                  </option>
                  <option value="admin_store" <?= $formRole === 'admin_store' ? 'selected' : ''; ?>>
                    <?= $isBn ? 'Admin Store (অ্যাডমিন স্টোর — সম্পূর্ণ সিস্টেম ও সকল অ্যাডমিনের রাউটার নিয়ন্ত্রণ)' : 'Admin Store (Master Control — All Routers, All Admins & Full Access)'; ?>
                  </option>
                </select>
              </td>
            </tr>
            <tr>
              <td class="align-middle"><?= $isBn ? 'পূর্ণ নাম' : 'Full Name'; ?></td>
              <td>
                <input class="form-control" type="text" name="admin_name" maxlength="80" placeholder="<?= $isBn ? 'যেমন: Admin Name / Store Manager' : 'e.g. Admin Name / Store Manager'; ?>" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['name'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
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

          <!-- Info note when Admin Store is selected -->
          <div id="ngw_admin_store_note" style="display:<?= $formRole === 'admin_store' ? 'block' : 'none'; ?>; font-size:11px; padding:6px 10px; margin-bottom:8px; border-radius:4px; background:rgba(110,64,201,0.16); border:1px solid rgba(110,64,201,0.45);">
            <i class="fa fa-shield"></i>
            <?= $isBn
              ? '<b>Admin Store:</b> এই রোলের ইউজার অ্যাডমিন স্টোর ইউজার ম্যানেজমেন্টসহ পুরো সফটওয়্যারের সব ফিচার এবং সকল অ্যাডমিনের যুক্ত করা রাউটার দেখতে ও নিয়ন্ত্রণ করতে পারবে।'
              : '<b>Admin Store:</b> Full unrestricted access to Admin Store user management, all routers added by any Admin, and all software features.'; ?>
          </div>

          <!-- Shortcut Permission Toggle Box when Admin role is selected -->
          <?php $adminShortcutGroups = function_exists('getAdminShortcutPermissionGroups') ? getAdminShortcutPermissionGroups() : array(); ?>
          <input type="hidden" name="adm_shortcut_submitted" value="1">
          <div id="ngw_admin_perm_box" class="ngw-adm-perm-panel" style="display:<?= $formRole === 'admin' ? 'block' : 'none'; ?>;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px; margin-bottom:6px;">
              <div style="font-size:12px; font-weight:bold;">
                <i class="fa fa-sliders text-primary"></i>
                <?= $isBn ? 'অ্যাডমিন শর্টকাট পারমিশন (যেটি দিবেন সেটির সম্পূর্ণ ফাংশন কাজ করবে):' : 'Admin Shortcut Permissions (Each toggle enables the full module):'; ?>
              </div>
              <div style="display:flex; gap:4px;">
                <button type="button" class="btn bg-primary" style="padding:2px 7px; font-size:10px; cursor:pointer;" onclick="ngwSetAllAdminPerms(true)">
                  <i class="fa fa-check-square-o"></i> <?= $isBn ? 'সব চালু' : 'Enable All'; ?>
                </button>
                <button type="button" class="btn bg-secondary" style="padding:2px 7px; font-size:10px; cursor:pointer;" onclick="ngwSetAllAdminPerms(false)">
                  <i class="fa fa-square-o"></i> <?= $isBn ? 'সব বন্ধ' : 'Disable All'; ?>
                </button>
              </div>
            </div>
            <div class="ngw-adm-perm-grid">
              <?php foreach ($adminShortcutGroups as $sKey => $sInfo) {
                $isShortcutChecked = function_exists('isAdminShortcutGroupEnabled')
                  ? isAdminShortcutGroupEnabled($sKey, $formDisabledPerms)
                  : (!in_array($sKey, $formDisabledPerms, true));
                $sTitle = $isBn ? $sInfo['title_bn'] : $sInfo['title_en'];
                $sDesc  = $isBn ? $sInfo['desc_bn'] : $sInfo['desc_en'];
                $sIcon  = $sInfo['icon'];
                $sColor = $sInfo['color'];
              ?>
                <label class="ngw-adm-shortcut-card">
                  <input type="checkbox" class="ngw-adm-perm-cb" name="adm_shortcut_perms[<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>]" value="1" <?= $isShortcutChecked ? 'checked' : ''; ?>>
                  <div style="min-width:0; flex:1;">
                    <div class="ngw-adm-shortcut-title" style="color:<?= htmlspecialchars($sColor, ENT_QUOTES, 'UTF-8'); ?>;">
                      <i class="fa <?= htmlspecialchars($sIcon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                      <span><?= htmlspecialchars($sTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="ngw-adm-shortcut-desc"><?= htmlspecialchars($sDesc, ENT_QUOTES, 'UTF-8'); ?></div>
                  </div>
                </label>
              <?php } ?>
            </div>
          </div>

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
          <i class="fa fa-users"></i> <?= $isBn ? 'কনফিগারকৃত অ্যাডমিন স্টোর ও অ্যাডমিন তালিকা' : 'Configured Admin Store &amp; Admin Users'; ?>
        </div>
        <div class="overflow" style="margin-bottom:14px;">
          <table class="table table-bordered table-hover table-sm" style="margin-bottom:0;">
            <thead>
              <tr>
                <th style="width:28px;">#</th>
                <th><?= $isBn ? 'পূর্ণ নাম' : 'Full Name'; ?></th>
                <th><?= $_user_name ?></th>
                <th><?= $isBn ? 'রোল ও পারমিশন' : 'Role &amp; Permissions'; ?></th>
                <th style="width:105px;" class="text-center"><?= $_action ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($adminUsers as $idx => $adm) {
                $isSelf = ((string)$adm['id'] === $currentLoggedAdminId) || ((string)$adm['username'] === (string)$_SESSION['mikhmon']);
                $rowRole = (isset($adm['admin_role']) && $adm['admin_role'] === 'admin') ? 'admin' : 'admin_store';
                $rowDisCount = ($rowRole === 'admin' && isset($adm['disabled_permissions']) && is_array($adm['disabled_permissions']))
                  ? count($adm['disabled_permissions'])
                  : 0;
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
                <td class="align-middle">
                  <?php if ($rowRole === 'admin_store') { ?>
                    <span class="ngw-adm-role-badge is-store">
                      <i class="fa fa-shield"></i> <?= $isBn ? 'Admin Store (অ্যাডমিন স্টোর)' : 'Admin Store'; ?>
                    </span>
                  <?php } else {
                    $rowDisList = isset($adm['disabled_permissions']) && is_array($adm['disabled_permissions']) ? $adm['disabled_permissions'] : array();
                  ?>
                    <span class="ngw-adm-role-badge is-admin">
                      <i class="fa fa-user-circle-o"></i> <?= $isBn ? 'Admin (অ্যাডমিন)' : 'Admin'; ?>
                    </span>
                    <div style="display:flex; flex-wrap:wrap; gap:3px; margin-top:4px;">
                      <?php foreach ($adminShortcutGroups as $sKey => $sInfo) {
                        $sEnabled = function_exists('isAdminShortcutGroupEnabled') ? isAdminShortcutGroupEnabled($sKey, $rowDisList) : (!in_array($sKey, $rowDisList, true));
                        $sShort = $isBn ? $sInfo['short_bn'] : $sInfo['short_en'];
                      ?>
                        <span style="font-size:10px; padding:1px 5px; border-radius:3px; border:1px solid <?= $sEnabled ? '#2ea043' : 'rgba(248,81,73,0.55)'; ?>; background:<?= $sEnabled ? 'rgba(46,160,67,0.16)' : 'rgba(248,81,73,0.14)'; ?>; color:<?= $sEnabled ? '#4ade80' : '#ff7b72'; ?>;">
                          <i class="fa <?= $sEnabled ? 'fa-check' : 'fa-times'; ?>"></i> <?= htmlspecialchars($sShort, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                      <?php } ?>
                    </div>
                  <?php } ?>
                </td>
                <td class="align-middle text-center" style="white-space:nowrap;">
                  <a class="btn bg-primary" style="padding:2px 6px; font-size:11px; margin:1px;" href="./admin.php?id=admin-settings&edit_admin=<?= urlencode($adm['id']); ?>" title="<?= $isBn ? 'সম্পাদনা' : 'Edit Admin User'; ?>">
                    <i class="fa fa-edit"></i> <?= $_edit ?>
                  </a>
                  <?php if (count($adminUsers) > 1 && !($rowRole === 'admin_store' && $adminStoreCount <= 1)) { 
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
