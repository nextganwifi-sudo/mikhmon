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

// Count how many Super Admin accounts currently exist
$superAdminCount = 0;
foreach ($adminUsers as $auRow) {
  if (!isset($auRow['admin_role']) || $auRow['admin_role'] !== 'admin') {
    $superAdminCount++;
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
    $admRole = (isset($_POST['admin_role']) && $_POST['admin_role'] === 'admin') ? 'admin' : 'super_admin';

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

    // Check if demoting the last remaining Super Admin user
    $demotingLastSuper = false;
    if ($editId !== '' && $admRole === 'admin') {
      foreach ($adminUsers as $existing) {
        $exRole = (isset($existing['admin_role']) && $existing['admin_role'] === 'admin') ? 'admin' : 'super_admin';
        if ((string)$existing['id'] === $editId && $exRole === 'super_admin' && $superAdminCount <= 1) {
          $demotingLastSuper = true;
          break;
        }
      }
    }

    if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $admUser)) {
      $settingsError = $isBn ? 'এডমিন ইউজারনেম ৩ থেকে ৬৪ অক্ষরের হতে হবে (অক্ষর, সংখ্যা, ডট, আন্ডারস্কোর, @ বা হাইফেন)।' : 'Admin username must be 3–64 characters and use only letters, numbers, dot, underscore, @ or hyphen.';
    } elseif ($demotingLastSuper) {
      $settingsError = $isBn ? 'সর্বশেষ সুপার এডমিন (Super Admin) ইউজারকে সাধারণ এডমিন রোলে পরিবর্তন করা যাবে না। অন্তত একজন সুপার এডমিন থাকতে হবে।' : 'Cannot change the last remaining Super Admin account to Admin. At least one Super Admin must remain.';
    } elseif ($editId === '' && (strlen($admPass) < 4 || strlen($admPass) > 256 || preg_match('/[\r\n]/', $admPass))) {
      $settingsError = $isBn ? 'নতুন এডমিন ইউজার তৈরির জন্য পাসওয়ার্ড (৪-২৫৬ অক্ষর) আবশ্যক।' : 'Password is required (4–256 characters) when adding a new Admin user.';
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
          ? ('ইউজারনেম "' . $admUser . '" ইতিমধ্যেই অন্য এডমিন বা রিসেলার অ্যাকাউন্টে ব্যবহৃত হচ্ছে।')
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
            $settingsError = $isBn ? 'নির্বাচিত এডমিন ইউজার পাওয়া যায়নি।' : 'Selected Admin user was not found.';
          } elseif (!saveAdminUsers($adminUsers)) {
            $settingsError = $isBn ? 'এডমিন ইউজার তালিকা সেভ করা যায়নি।' : 'Could not save Admin users list. Check file permissions.';
          } else {
            if ($editId === $currentLoggedAdminId) {
              $_SESSION['mikhmon'] = $admUser;
              $_SESSION['mikhmon_admin_id'] = $editId;
              $_SESSION['mikhmon_admin_role'] = $admRole;
            }
            if (function_exists('ngwRecordActivity')) {
              ngwRecordActivity('admin_user_updated', array(
                'target' => $admUser,
                'status' => ($admRole === 'super_admin' ? 'Super Admin' : 'Admin'),
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
            $settingsError = $isBn ? 'নতুন এডমিন ইউজার সেভ করা যায়নি।' : 'Could not save new Admin user. Check file permissions.';
          } else {
            if (function_exists('ngwRecordActivity')) {
              ngwRecordActivity('admin_user_created', array(
                'target' => $admUser,
                'status' => ($admRole === 'super_admin' ? 'Super Admin' : 'Admin'),
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
    $settingsError = $isBn ? 'সর্বশেষ এডমিন ইউজার ডিলিট করা যাবে না।' : 'Cannot delete the last remaining Admin user. At least one Admin account must remain active.';
  } else {
    $deletingLastSuper = false;
    $deletedUsername = $delId;
    foreach ($adminUsers as $adm) {
      if ((string)$adm['id'] === $delId) {
        $deletedUsername = (string)$adm['username'];
        $rRole = (isset($adm['admin_role']) && $adm['admin_role'] === 'admin') ? 'admin' : 'super_admin';
        if ($rRole === 'super_admin' && $superAdminCount <= 1) {
          $deletingLastSuper = true;
        }
        break;
      }
    }
    if ($deletingLastSuper) {
      $settingsError = $isBn ? 'সর্বশেষ সুপার এডমিন (Super Admin) ইউজার ডিলিট করা যাবে না। অন্তত একজন সুপার এডমিন থাকতে হবে।' : 'Cannot delete the last remaining Super Admin user. At least one Super Admin account must remain.';
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
        $settingsError = $isBn ? 'এডমিন ইউজার পাওয়া যায়নি।' : 'Admin user not found.';
      } elseif (!saveAdminUsers($newList)) {
        $settingsError = $isBn ? 'এডমিন ইউজার ফাইল আপডেট করা যায়নি।' : 'Could not update Admin users file.';
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
  $formRole = (isset($editingAdmin['admin_role']) && $editingAdmin['admin_role'] === 'admin') ? 'admin' : 'super_admin';
  $formDisabledPerms = isset($editingAdmin['disabled_permissions']) && is_array($editingAdmin['disabled_permissions'])
    ? $editingAdmin['disabled_permissions']
    : array();
}

if (isset($_GET['msg'])) {
  if ($_GET['msg'] === 'added') $settingsSuccess = $isBn ? 'নতুন এডমিন ইউজার সফলভাবে যুক্ত হয়েছে!' : 'New Admin user added successfully!';
  elseif ($_GET['msg'] === 'updated') $settingsSuccess = $isBn ? 'এডমিন ইউজার সফলভাবে আপডেট হয়েছে!' : 'Admin user updated successfully!';
  elseif ($_GET['msg'] === 'deleted') $settingsSuccess = $isBn ? 'এডমিন ইউজার সফলভাবে মুছে ফেলা হয়েছে!' : 'Admin user removed successfully!';
  elseif ($_GET['msg'] === 'qrsaved') $settingsSuccess = $isBn ? 'কুইক প্রিন্ট QR সেটিং সংরক্ষিত হয়েছে!' : 'Quick Print QR setting saved successfully!';
} elseif (isset($_GET['saved']) && $_GET['saved'] == '1') {
  $settingsSuccess = $isBn ? 'এডমিন সেটিংস সফলভাবে সংরক্ষিত হয়েছে!' : 'Admin settings saved successfully!';
}
$showFormByDefault = ($editingAdmin !== null) || !empty($settingsError) || $forcePasswordChange;
?>
<style>
.ngw-adm-header-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding: 10px 14px;
}
.ngw-adm-header-left {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.ngw-adm-header-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.ngw-btn-add-user {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border-radius: 6px;
  background: linear-gradient(135deg, #2ea043, #238636);
  border: 1px solid rgba(255, 255, 255, 0.15);
  color: #fff !important;
  font-size: 12.5px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(46, 160, 67, 0.28);
  transition: all 0.18s ease;
  text-decoration: none !important;
}
.ngw-btn-add-user:hover {
  background: linear-gradient(135deg, #3fb950, #2ea043);
  transform: translateY(-1px);
}
.ngw-btn-compact-dl {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 10px;
  border-radius: 6px;
  background: rgba(56, 139, 253, 0.14);
  border: 1px solid rgba(56, 139, 253, 0.4);
  color: #58a6ff !important;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.18s ease;
  text-decoration: none !important;
}
.ngw-btn-compact-dl:hover {
  background: rgba(56, 139, 253, 0.25);
  border-color: #58a6ff;
  color: #fff !important;
}
.ngw-adm-form-drawer {
  margin-bottom: 18px;
  padding: 16px;
  border: 1px solid rgba(56, 139, 253, 0.35);
  border-radius: 8px;
  background: rgba(13, 17, 23, 0.45);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}
.ngw-adm-form-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  padding-bottom: 10px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.2);
}
.ngw-adm-form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}
.ngw-adm-field label {
  display: block;
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 5px;
  opacity: 0.9;
}
.ngw-adm-role-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  color: #fff;
  white-space: nowrap;
}
.ngw-adm-role-badge.is-store {
  background: linear-gradient(135deg, #7c3aed, #5b21b6);
  box-shadow: 0 1px 4px rgba(124, 58, 237, 0.35);
}
.ngw-adm-role-badge.is-admin {
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  box-shadow: 0 1px 4px rgba(37, 99, 235, 0.35);
}
.ngw-adm-perm-panel {
  margin-top: 6px;
  margin-bottom: 14px;
  padding: 12px 14px;
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.16);
}
.ngw-adm-perm-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}
.ngw-adm-perm-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 10px;
}
.ngw-perm-switch-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid rgba(128, 128, 128, 0.25);
  background: rgba(255, 255, 255, 0.03);
  cursor: pointer;
  transition: all 0.18s ease;
  margin: 0;
  user-select: none;
}
.ngw-perm-switch-card.is-active {
  border-color: rgba(46, 160, 67, 0.55);
  background: rgba(46, 160, 67, 0.1);
}
.ngw-perm-switch-card:hover {
  border-color: rgba(88, 166, 255, 0.6);
}
.ngw-perm-card-left {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.ngw-perm-icon-box {
  width: 34px;
  height: 34px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  flex-shrink: 0;
  background: rgba(255, 255, 255, 0.06);
}
.ngw-perm-card-title {
  font-size: 12.5px;
  font-weight: 700;
  line-height: 1.2;
}
.ngw-perm-card-state {
  font-size: 10.5px;
  margin-top: 2px;
  opacity: 0.78;
}
/* Modern Toggle Switch */
.ngw-toggle-wrap {
  position: relative;
  width: 40px;
  height: 22px;
  flex-shrink: 0;
}
.ngw-toggle-wrap input {
  opacity: 0;
  width: 0;
  height: 0;
  position: absolute;
}
.ngw-toggle-slider {
  position: absolute;
  cursor: pointer;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: rgba(128, 128, 128, 0.38);
  transition: 0.2s;
  border-radius: 22px;
}
.ngw-toggle-slider:before {
  position: absolute;
  content: "";
  height: 16px;
  width: 16px;
  left: 3px;
  bottom: 3px;
  background-color: #fff;
  transition: 0.2s;
  border-radius: 50%;
}
.ngw-toggle-wrap input:checked + .ngw-toggle-slider {
  background-color: #2ea043;
}
.ngw-toggle-wrap input:checked + .ngw-toggle-slider:before {
  transform: translateX(18px);
}
/* Permission Chips in User Table */
.ngw-table-perm-list {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}
.ngw-table-perm-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}
.ngw-table-perm-chip.is-on {
  background: rgba(46, 160, 67, 0.16);
  border: 1px solid rgba(46, 160, 67, 0.5);
  color: #4ade80;
}
.ngw-table-perm-chip.is-off {
  background: rgba(248, 81, 73, 0.12);
  border: 1px solid rgba(248, 81, 73, 0.35);
  color: #ff7b72;
  opacity: 0.8;
}
.ngw-table-perm-chip.is-full {
  background: rgba(124, 58, 237, 0.16);
  border: 1px solid rgba(139, 92, 246, 0.45);
  color: #c4b5fd;
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
  function ngwToggleAddUserForm(show) {
    var panel = document.getElementById('ngw_admin_form_panel');
    var btn = document.getElementById('ngw_btn_open_add_user');
    if (!panel) return;
    if (typeof show === 'undefined') {
      show = (panel.style.display === 'none' || panel.style.display === '');
    }
    panel.style.display = show ? 'block' : 'none';
    if (btn) {
      btn.style.display = show ? 'none' : 'inline-flex';
    }
    if (show) {
      var firstInput = document.getElementById('ngw_admin_name_input');
      if (firstInput) {
        setTimeout(function(){ firstInput.focus(); }, 60);
      }
    }
  }
  function ngwToggleAdminRolePerms() {
    var sel = document.getElementById('ngw_admin_role_select');
    var permBox = document.getElementById('ngw_admin_perm_box');
    if (!sel || !permBox) return;
    if (sel.value === 'admin') {
      permBox.style.display = 'block';
    } else {
      permBox.style.display = 'none';
    }
  }
  function ngwSyncPermCardStyles() {
    var cards = document.querySelectorAll('.ngw-perm-switch-card');
    for (var i = 0; i < cards.length; i++) {
      var cb = cards[i].querySelector('.ngw-adm-perm-cb');
      var st = cards[i].querySelector('.ngw-perm-card-state');
      if (!cb) continue;
      if (cb.checked) {
        cards[i].classList.add('is-active');
        if (st) st.innerHTML = st.getAttribute('data-on-text') || 'Active';
      } else {
        cards[i].classList.remove('is-active');
        if (st) st.innerHTML = st.getAttribute('data-off-text') || 'Disabled';
      }
    }
  }
  function ngwSetAllAdminPerms(checked) {
    var boxes = document.querySelectorAll('.ngw-adm-perm-cb');
    for (var i = 0; i < boxes.length; i++) {
      boxes[i].checked = !!checked;
    }
    ngwSyncPermCardStyles();
  }
</script>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header ngw-adm-header-bar">
        <div class="ngw-adm-header-left">
          <h3 class="card-title" style="margin:0;">
            <i class="fa fa-user-secret"></i> <?= $isBn ? 'সুপার এডমিন ও এডমিন ইউজার' : 'Super Admin &amp; Admin Users'; ?>
          </h3>
          <span style="font-size:11px;padding:2px 9px;border-radius:10px;background:rgba(31,111,235,0.25);color:#fff;font-weight:bold;">
            <?= count($adminUsers); ?> <?= $isBn ? 'জন ইউজার' : (count($adminUsers) === 1 ? 'User' : 'Users'); ?>
          </span>
        </div>
        <div class="ngw-adm-header-actions">
          <button type="button" id="ngw_btn_open_add_user" class="ngw-btn-add-user" style="display:<?= $showFormByDefault ? 'none' : 'inline-flex'; ?>;" onclick="ngwToggleAddUserForm(true)">
            <i class="fa fa-user-plus"></i> <?= $isBn ? 'অ্যাড ইউজার' : 'Add User'; ?>
          </button>
          <a class="ngw-btn-compact-dl" href="./settings/export_package.php" title="<?= htmlspecialchars(isset($_srv_dl_title) ? $_srv_dl_title : 'Download Complete Standalone NextGan WiFi Solution Server (.zip)', ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fa fa-download"></i> <?= $isBn ? 'সার্ভার ডাউনলোড (.zip)' : 'Download Server (.zip)'; ?>
          </a>
        </div>
      </div>

      <div class="card-body">
        <?php if ($forcePasswordChange) { ?>
        <div role="alert" style="padding:8px 12px; margin-bottom:10px; border-radius:4px; background:rgba(220,53,69,0.12); border:1px solid #dc3545; color:#dc3545; font-weight:bold;">
          <?= $isBn ? 'প্রথমবার লগইন নিরাপত্তা: সফটওয়্যার ব্যবহারের আগে ডিফল্ট এডমিন পাসওয়ার্ড পরিবর্তন করুন।' : 'First login security: change the default administrator password before using the software.'; ?>
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

        <!-- Collapsible Add / Edit Admin User Form (Hidden by default unless Add User or Edit is clicked) -->
        <?php $adminShortcutGroups = function_exists('getAdminShortcutPermissionGroups') ? getAdminShortcutPermissionGroups() : array(); ?>
        <div id="ngw_admin_form_panel" class="ngw-adm-form-drawer" style="display:<?= $showFormByDefault ? 'block' : 'none'; ?>;">
          <form autocomplete="off" method="post" action="./admin.php?id=admin-settings" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="edit_admin_id" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['id'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            <input type="hidden" name="admin_perm_form_submitted" value="1">
            <input type="hidden" name="adm_shortcut_submitted" value="1">

            <div class="ngw-adm-form-header">
              <div style="font-weight:bold; color:<?= $editingAdmin ? '#f0ad4e' : '#4ade80'; ?>; font-size:14px;">
                <i class="fa <?= $editingAdmin ? 'fa-pencil-square-o' : 'fa-user-plus'; ?>"></i>
                <?= $editingAdmin ? (($isBn ? 'ইউজার সম্পাদনা: ' : 'Edit User: ') . htmlspecialchars($editingAdmin['username'], ENT_QUOTES, 'UTF-8')) : ($isBn ? 'নতুন ইউজার অ্যাড করুন' : 'Add New User'); ?>
              </div>
              <?php if ($editingAdmin) { ?>
                <a class="btn bg-secondary" style="padding:3px 10px; font-size:11px;" href="./admin.php?id=admin-settings">
                  <i class="fa fa-times"></i> <?= $isBn ? 'বন্ধ করুন' : 'Close'; ?>
                </a>
              <?php } else { ?>
                <button type="button" class="btn bg-secondary" style="padding:3px 10px; font-size:11px; cursor:pointer;" onclick="ngwToggleAddUserForm(false)">
                  <i class="fa fa-times"></i> <?= $isBn ? 'বন্ধ করুন' : 'Close'; ?>
                </button>
              <?php } ?>
            </div>

            <div class="ngw-adm-form-grid">
              <div class="ngw-adm-field">
                <label><?= $isBn ? 'রোল (Role)' : 'Role'; ?></label>
                <select class="form-control" id="ngw_admin_role_select" name="admin_role" onchange="ngwToggleAdminRolePerms()">
                  <option value="admin" <?= $formRole === 'admin' ? 'selected' : ''; ?>>
                    <?= $isBn ? 'এডমিন' : 'Admin'; ?>
                  </option>
                  <option value="super_admin" <?= $formRole !== 'admin' ? 'selected' : ''; ?>>
                    <?= $isBn ? 'সুপার এডমিন' : 'Super Admin'; ?>
                  </option>
                </select>
              </div>

              <div class="ngw-adm-field">
                <label><?= $isBn ? 'পূর্ণ নাম' : 'Full Name'; ?></label>
                <input class="form-control" id="ngw_admin_name_input" type="text" name="admin_name" maxlength="80" placeholder="<?= $isBn ? 'পূর্ণ নাম লিখুন' : 'Enter Full Name'; ?>" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['name'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
              </div>

              <div class="ngw-adm-field">
                <label><?= $_user_name ?></label>
                <input class="form-control" type="text" name="admin_username" maxlength="64" placeholder="<?= $isBn ? 'ইউজারনেম লিখুন' : 'Enter Username'; ?>" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['username'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
              </div>

              <div class="ngw-adm-field">
                <label><?= $_password ?></label>
                <div class="input-group">
                  <div class="input-group-11 col-box-10">
                    <input class="group-item group-item-l" id="adm_user_pass" type="password" name="admin_password" placeholder="<?= $editingAdmin ? ($isBn ? 'অপরিবর্তিত রাখতে খালি রাখুন' : 'Leave blank to keep current') : ($isBn ? 'পাসওয়ার্ড লিখুন' : 'Enter Password'); ?>" autocomplete="new-password" <?= $editingAdmin ? '' : 'required'; ?>>
                  </div>
                  <div class="input-group-1 col-box-2">
                    <div class="group-item group-item-r pd-2p5 text-center align-middle">
                      <input title="Show/Hide Password" type="checkbox" onclick="Pass('adm_user_pass')">
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Redesigned Permission Section with Toggle Switches -->
            <div id="ngw_admin_perm_box" class="ngw-adm-perm-panel" style="display:<?= $formRole === 'admin' ? 'block' : 'none'; ?>;">
              <div class="ngw-adm-perm-header">
                <div style="font-size:12.5px; font-weight:700; display:flex; align-items:center; gap:6px;">
                  <i class="fa fa-sliders" style="color:#58a6ff;"></i>
                  <span><?= $isBn ? 'এডমিন পারমিশন কন্ট্রোল' : 'Admin Permissions Control'; ?></span>
                </div>
                <div style="display:flex; gap:6px;">
                  <button type="button" class="btn bg-primary" style="padding:3px 9px; font-size:10.5px; border-radius:4px; cursor:pointer;" onclick="ngwSetAllAdminPerms(true)">
                    <i class="fa fa-check-circle"></i> <?= $isBn ? 'সব চালু' : 'Enable All'; ?>
                  </button>
                  <button type="button" class="btn bg-secondary" style="padding:3px 9px; font-size:10.5px; border-radius:4px; cursor:pointer;" onclick="ngwSetAllAdminPerms(false)">
                    <i class="fa fa-ban"></i> <?= $isBn ? 'সব বন্ধ' : 'Disable All'; ?>
                  </button>
                </div>
              </div>

              <div class="ngw-adm-perm-grid">
                <?php foreach ($adminShortcutGroups as $sKey => $sInfo) {
                  $isShortcutChecked = function_exists('isAdminShortcutGroupEnabled')
                    ? isAdminShortcutGroupEnabled($sKey, $formDisabledPerms)
                    : (!in_array($sKey, $formDisabledPerms, true));
                  $sShort = $isBn ? $sInfo['short_bn'] : $sInfo['short_en'];
                  $sIcon  = $sInfo['icon'];
                  $sColor = $sInfo['color'];
                  $onText  = $isBn ? 'পারমিশন চালু আছে' : 'Permission Enabled';
                  $offText = $isBn ? 'পারমিশন বন্ধ আছে' : 'Permission Disabled';
                ?>
                  <label class="ngw-perm-switch-card <?= $isShortcutChecked ? 'is-active' : ''; ?>">
                    <div class="ngw-perm-card-left">
                      <div class="ngw-perm-icon-box" style="color:<?= htmlspecialchars($sColor, ENT_QUOTES, 'UTF-8'); ?>;">
                        <i class="fa <?= htmlspecialchars($sIcon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                      </div>
                      <div style="min-width:0;">
                        <div class="ngw-perm-card-title"><?= htmlspecialchars($sShort, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="ngw-perm-card-state" data-on-text="<?= htmlspecialchars($onText, ENT_QUOTES, 'UTF-8'); ?>" data-off-text="<?= htmlspecialchars($offText, ENT_QUOTES, 'UTF-8'); ?>">
                          <?= htmlspecialchars($isShortcutChecked ? $onText : $offText, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                      </div>
                    </div>
                    <div class="ngw-toggle-wrap">
                      <input type="checkbox" class="ngw-adm-perm-cb" name="adm_shortcut_perms[<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>]" value="1" <?= $isShortcutChecked ? 'checked' : ''; ?> onchange="ngwSyncPermCardStyles()">
                      <span class="ngw-toggle-slider"></span>
                    </div>
                  </label>
                <?php } ?>
              </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
              <?php if ($editingAdmin) { ?>
                <a class="btn bg-secondary" href="./admin.php?id=admin-settings">
                  <i class="fa fa-times"></i> <?= $isBn ? 'বাতিল' : 'Cancel'; ?>
                </a>
                <button class="btn bg-success" type="submit" name="save_admin_user" style="cursor:pointer; font-weight:bold; padding:6px 16px;">
                  <i class="fa fa-save"></i> <?= $isBn ? 'সেভ করুন' : 'Save'; ?>
                </button>
              <?php } else { ?>
                <button type="button" class="btn bg-secondary" style="cursor:pointer;" onclick="ngwToggleAddUserForm(false)">
                  <i class="fa fa-times"></i> <?= $isBn ? 'বাতিল' : 'Cancel'; ?>
                </button>
                <button class="btn bg-success" type="submit" name="save_admin_user" style="cursor:pointer; font-weight:bold; padding:6px 16px;">
                  <i class="fa fa-save"></i> <?= $isBn ? 'সেভ করুন' : 'Save'; ?>
                </button>
              <?php } ?>
            </div>
          </form>
        </div>

        <!-- Default Full-Width Admin Users List Table -->
        <div class="overflow" style="margin-bottom:10px;">
          <table class="table table-bordered table-hover" style="margin-bottom:0;">
            <thead>
              <tr>
                <th style="width:40px;" class="text-center">#</th>
                <th><?= $isBn ? 'পূর্ণ নাম' : 'Full Name'; ?></th>
                <th><?= $_user_name ?></th>
                <th style="width:140px;"><?= $isBn ? 'রোল' : 'Role'; ?></th>
                <th><?= $isBn ? 'পারমিশন' : 'Permissions'; ?></th>
                <th style="width:130px;" class="text-center"><?= $_action ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($adminUsers as $idx => $adm) {
                $isSelf = ((string)$adm['id'] === $currentLoggedAdminId) || ((string)$adm['username'] === (string)$_SESSION['mikhmon']);
                $rowRole = (isset($adm['admin_role']) && $adm['admin_role'] === 'admin') ? 'admin' : 'super_admin';
              ?>
              <tr>
                <td class="align-middle text-center"><?= $idx + 1; ?></td>
                <td class="align-middle">
                  <div style="display:flex; align-items:center; gap:8px;">
                    <span style="width:28px; height:28px; border-radius:50%; background:<?= $rowRole === 'super_admin' ? 'rgba(124,58,237,0.2)' : 'rgba(37,99,235,0.2)'; ?>; color:<?= $rowRole === 'super_admin' ? '#c4b5fd' : '#93c5fd'; ?>; display:inline-flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0;">
                      <i class="fa <?= $rowRole === 'super_admin' ? 'fa-shield' : 'fa-user'; ?>"></i>
                    </span>
                    <div>
                      <b><?= htmlspecialchars($adm['name'], ENT_QUOTES, 'UTF-8'); ?></b>
                      <?php if ($isSelf) { ?>
                        <span style="font-size:10px; padding:1px 6px; border-radius:8px; background:#2ea043; color:#fff; margin-left:4px;"><?= $isBn ? 'আপনি' : 'You'; ?></span>
                      <?php } ?>
                    </div>
                  </div>
                </td>
                <td class="align-middle"><code><?= htmlspecialchars($adm['username'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                <td class="align-middle">
                  <?php if ($rowRole === 'super_admin') { ?>
                    <span class="ngw-adm-role-badge is-store">
                      <i class="fa fa-shield"></i> <?= $isBn ? 'সুপার এডমিন' : 'Super Admin'; ?>
                    </span>
                  <?php } else { ?>
                    <span class="ngw-adm-role-badge is-admin">
                      <i class="fa fa-user-circle-o"></i> <?= $isBn ? 'এডমিন' : 'Admin'; ?>
                    </span>
                  <?php } ?>
                </td>
                <td class="align-middle">
                  <?php if ($rowRole === 'super_admin') { ?>
                    <span class="ngw-table-perm-chip is-full">
                      <i class="fa fa-check-circle"></i> <?= $isBn ? 'সব পারমিশন (ফুল অ্যাক্সেস)' : 'All Permissions (Full Access)'; ?>
                    </span>
                  <?php } else {
                    $rowDisList = isset($adm['disabled_permissions']) && is_array($adm['disabled_permissions']) ? $adm['disabled_permissions'] : array();
                  ?>
                    <div class="ngw-table-perm-list">
                      <?php foreach ($adminShortcutGroups as $sKey => $sInfo) {
                        $sEnabled = function_exists('isAdminShortcutGroupEnabled') ? isAdminShortcutGroupEnabled($sKey, $rowDisList) : (!in_array($sKey, $rowDisList, true));
                        $sShort = $isBn ? $sInfo['short_bn'] : $sInfo['short_en'];
                        $sIcon = $sInfo['icon'];
                      ?>
                        <span class="ngw-table-perm-chip <?= $sEnabled ? 'is-on' : 'is-off'; ?>">
                          <i class="fa <?= htmlspecialchars($sIcon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                          <?= htmlspecialchars($sShort, ENT_QUOTES, 'UTF-8'); ?>
                          <i class="fa <?= $sEnabled ? 'fa-check' : 'fa-times'; ?>" style="font-size:9.5px;"></i>
                        </span>
                      <?php } ?>
                    </div>
                  <?php } ?>
                </td>
                <td class="align-middle text-center" style="white-space:nowrap;">
                  <a class="btn bg-primary" style="padding:3px 8px; font-size:11px; margin:1px; border-radius:4px;" href="./admin.php?id=admin-settings&edit_admin=<?= urlencode($adm['id']); ?>" title="<?= $isBn ? 'সম্পাদনা' : 'Edit User'; ?>">
                    <i class="fa fa-edit"></i> <?= $_edit ?>
                  </a>
                  <?php if (count($adminUsers) > 1 && !($rowRole === 'super_admin' && $superAdminCount <= 1)) { 
                    $delAdminMsg = ($isBn ? 'এডমিন ইউজার মুছে ফেলবেন: ' : 'Delete Admin user ') . $adm['username'] . '?';
                  ?>
                  <form method="post" action="./admin.php?id=admin-settings" style="display:inline; margin:0;" onsubmit="return confirm(<?= htmlspecialchars(json_encode($delAdminMsg), ENT_QUOTES, 'UTF-8'); ?>);">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="delete_admin_id" value="<?= htmlspecialchars($adm['id'], ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="delete_admin_user" class="btn bg-danger" style="padding:3px 8px; font-size:11px; cursor:pointer; margin:1px; border-radius:4px;" title="<?= $_delete ?>">
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

        <div style="display:flex; align-items:center; justify-content:space-between; font-size:11px; opacity:0.75; margin-top:8px;">
          <div id="loadV">v<?= $_SESSION['v']; ?></div>
          <div><b id="newVer" class="text-green"></b></div>
        </div>
      </div>
    </div>
  </div>
</div>

