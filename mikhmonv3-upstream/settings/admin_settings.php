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
include_once('./include/ui_settings.php');

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

    // Admin UI Assignment & UI Switch/Reseller-Assign Permissions
    $admUiStyle = 'classic';
    $admAllowedUis = array('classic', 'modern', 'compact', 'aurora');
    if ($admRole === 'admin') {
      $admUiStyle = isset($_POST['admin_ui_style']) ? ngwSanitizeUiStyleKey($_POST['admin_ui_style'], false) : 'classic';
      $postedUiPerms = (isset($_POST['adm_ui_perms']) && is_array($_POST['adm_ui_perms'])) ? $_POST['adm_ui_perms'] : array();
      $admAllowedUis = ngwSanitizeAllowedUiList($postedUiPerms);
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
              $adminUsers[$idx]['ui_style'] = ($admRole === 'admin') ? $admUiStyle : 'default';
              $adminUsers[$idx]['allowed_uis'] = $admAllowedUis;
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
            if ($admRole === 'admin') {
              ngwSetAdminUiConfig($editId, $admUiStyle, $admAllowedUis);
            } else {
              $uiCfg = ngwLoadUiSettings(true);
              unset($uiCfg['admin_ui'][$editId], $uiCfg['admin_allowed_uis'][$editId]);
              ngwSaveUiSettings($uiCfg);
            }
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
            'ui_style' => ($admRole === 'admin') ? $admUiStyle : 'default',
            'allowed_uis' => $admAllowedUis,
            'password_enc' => encrypt($admPass),
            'password_hash' => password_hash($admPass, PASSWORD_DEFAULT),
            'avatar' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
          );
          if (!saveAdminUsers($adminUsers)) {
            $settingsError = $isBn ? 'নতুন এডমিন ইউজার সেভ করা যায়নি।' : 'Could not save new Admin user. Check file permissions.';
          } else {
            if ($admRole === 'admin') {
              ngwSetAdminUiConfig($newId, $admUiStyle, $admAllowedUis);
            }
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
        $uiCfgDel = ngwLoadUiSettings(true);
        unset($uiCfgDel['admin_ui'][$delId], $uiCfgDel['admin_allowed_uis'][$delId]);
        ngwSaveUiSettings($uiCfgDel);
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

// Handle 1-Click Login as Regular Admin (Super Admin Impersonation)
if (isset($_POST['login_as_admin'])) {
  $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  $loginTargetId = isset($_POST['login_admin_id']) ? trim((string)$_POST['login_admin_id']) : '';
  if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
    $settingsError = $isBn ? 'সিকিউরিটি টোকেন মেয়াদোত্তীর্ণ বা অবৈধ। পেজটি রিলোড করে আবার চেষ্টা করুন।' : 'Security token expired or invalid. Reload this page and try again.';
  } elseif (!isMikhmonAdminStore()) {
    $settingsError = $isBn ? 'শুধুমাত্র সুপার এডমিন অন্য এডমিন প্যানেলে লগইন করতে পারবেন।' : 'Only Super Admin can log in as an Admin user.';
  } elseif ($loginTargetId === '' || $loginTargetId === $currentLoggedAdminId) {
    $settingsError = $isBn ? 'অবৈধ এডমিন নির্বাচন করা হয়েছে।' : 'Invalid Admin user selected.';
  } else {
    $targetAdm = null;
    foreach ($adminUsers as $admRow) {
      if ((string)$admRow['id'] === $loginTargetId) {
        $targetAdm = $admRow;
        break;
      }
    }
    $targetRole = (is_array($targetAdm) && isset($targetAdm['admin_role']) && $targetAdm['admin_role'] === 'admin') ? 'admin' : 'super_admin';
    if (!is_array($targetAdm) || $targetRole !== 'admin') {
      $settingsError = $isBn ? 'শুধুমাত্র সাধারণ এডমিন (Admin) অ্যাকাউন্টে এক ক্লিকে লগইন করা যাবে।' : 'One-click login is only available for regular Admin accounts.';
    } else {
      if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
      }
      $superAdminUsername = !empty($_SESSION['mikhmon_impersonator_admin'])
        ? (string)$_SESSION['mikhmon_impersonator_admin']
        : (isset($_SESSION['mikhmon']) ? (string)$_SESSION['mikhmon'] : '');
      $oldTok = isset($_SESSION['ngw_session_token']) ? (string)$_SESSION['ngw_session_token'] : '';
      ngwRegenerateAuthenticatedSession($oldTok);
      $_SESSION['mikhmon_impersonator_admin'] = $superAdminUsername;
      $_SESSION['mikhmon_impersonator_return_to'] = 'admin-settings';
      $_SESSION['mikhmon'] = (string)$targetAdm['username'];
      $_SESSION['mikhmon_role'] = 'admin';
      $_SESSION['mikhmon_admin_id'] = (string)$targetAdm['id'];
      $_SESSION['mikhmon_admin_role'] = 'admin';
      $_SESSION['mikhmon_reseller_id'] = (string)$targetAdm['id'];
      $_SESSION['mikhmon_permission_mode'] = 'Admin';
      $_SESSION['mikhmon_template_code'] = 'GLOBAL';
      $_SESSION['mikhmon_assigned_template'] = 'default';
      $_SESSION['mikhmon_logo_filename'] = '';
      $_SESSION['mikhmon_routers'] = array('*');
      $_SESSION['mikhmon_permissions'] = resolveEffectiveAdminPermissions($targetAdm);
      $_SESSION['mikhmon_last_activity'] = time();
      $_SESSION['mikhmon_login_handshake'] = 1;
      unset($_SESSION['lang'], $_SESSION['theme'], $_SESSION['lang_owner'], $_SESSION['theme_owner'], $_SESSION['mikhmon_force_password_change']);
      unset($GLOBALS['NGW_ADMIN_SYNCED'], $GLOBALS['NGW_CACHE_UI_SETTINGS']);
      if (function_exists('ngwCsrfToken')) {
        ngwCsrfToken('ngw_admin_csrf');
      }
      if (function_exists('ngwResolveCurrentUserLang')) {
        $GLOBALS['langid'] = ngwResolveCurrentUserLang(true);
      }
      if (function_exists('ngwResolveCurrentUserTheme')) {
        ngwResolveCurrentUserTheme(true);
      }
      ngwRegisterOrTouchActiveSession(array(
        'is_new_login' => true,
        'page_label' => 'Admin Panel',
      ));
      if (function_exists('ngwRecordActivity')) {
        ngwRecordActivity('admin_impersonation', array('target' => (string)$targetAdm['username']), $superAdminUsername, 'admin');
      }
      session_write_close();
      echo "<script>try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location='./admin.php?id=sessions';</script>";
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

$formRole = 'admin';
$formDisabledPerms = array();
$formUiStyle = 'classic';
$formAllowedUis = array('classic');
if ($editingAdmin) {
  $formRole = (isset($editingAdmin['admin_role']) && $editingAdmin['admin_role'] === 'admin') ? 'admin' : 'super_admin';
  $formDisabledPerms = isset($editingAdmin['disabled_permissions']) && is_array($editingAdmin['disabled_permissions'])
    ? $editingAdmin['disabled_permissions']
    : array();
  $formUiStyle = ngwGetAdminAssignedUi($editingAdmin['id'], $editingAdmin);
  $formAllowedUis = ngwGetAdminAllowedUis($editingAdmin['id'], $editingAdmin);
}
$allUiStyleDefs = ngwGetAvailableUiStyles($isBn);

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
  transition: transform 0.16s ease, background 0.16s ease;
  text-decoration: none !important;
  white-space: nowrap;
}
.ngw-btn-add-user:hover {
  background: linear-gradient(135deg, #3fb950, #2ea043);
  transform: translateY(-1px);
}
.ngw-btn-compact-dl {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 11px;
  border-radius: 6px;
  background: rgba(56, 139, 253, 0.14);
  border: 1px solid rgba(56, 139, 253, 0.4);
  color: #58a6ff !important;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.16s ease, border-color 0.16s ease;
  text-decoration: none !important;
  white-space: nowrap;
}
.ngw-btn-compact-dl:hover {
  background: rgba(56, 139, 253, 0.25);
  border-color: #58a6ff;
  color: #fff !important;
}

/* ===================================================================
   Organized Admin / Super Admin Add & Edit Drawer (All 4 UIs & Themes)
   =================================================================== */
.ngw-adm-form-drawer {
  margin-bottom: 20px;
  padding: 18px 20px;
  border: 1px solid rgba(148, 163, 184, 0.28);
  border-radius: 10px;
  background: rgba(15, 23, 42, 0.32);
  box-shadow: 0 6px 22px rgba(0, 0, 0, 0.16);
}
body[data-ngw-theme="light"] .ngw-adm-form-drawer {
  background: #f8fafc;
  border-color: rgba(15, 23, 42, 0.14);
  box-shadow: 0 4px 16px rgba(15, 23, 42, 0.06);
}
body[data-ngw-ui="aurora"] .ngw-adm-form-drawer {
  background: rgba(255, 255, 255, 0.07);
  border: 1px solid rgba(255, 255, 255, 0.22);
  border-radius: 14px;
  backdrop-filter: blur(18px);
  -webkit-backdrop-filter: blur(18px);
  box-shadow: 0 12px 32px rgba(2, 6, 23, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.18);
}
body[data-ngw-ui="aurora"][data-ngw-theme="light"] .ngw-adm-form-drawer {
  background: rgba(255, 255, 255, 0.62);
  border-color: rgba(15, 23, 42, 0.14);
  box-shadow: 0 10px 26px rgba(15, 23, 42, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.85);
}
body[data-ngw-variant="compact"] .ngw-adm-form-drawer {
  border-radius: 8px;
  border-color: rgba(245, 158, 11, 0.38);
}

.ngw-adm-form-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid rgba(148, 163, 184, 0.2);
}
.ngw-adm-form-title-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.ngw-adm-form-title-ico {
  width: 34px;
  height: 34px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  flex-shrink: 0;
  background: rgba(59, 130, 246, 0.16);
  color: #60a5fa;
  border: 1px solid rgba(59, 130, 246, 0.32);
}
.ngw-adm-form-title-ico.is-edit {
  background: rgba(245, 158, 11, 0.16);
  color: #fbbf24;
  border-color: rgba(245, 158, 11, 0.34);
}
body[data-ngw-theme="light"] .ngw-adm-form-title-ico {
  color: #2563eb;
}
body[data-ngw-theme="light"] .ngw-adm-form-title-ico.is-edit {
  color: #d97706;
}
.ngw-adm-form-heading {
  font-size: 14.5px;
  font-weight: 700;
  line-height: 1.25;
}
.ngw-adm-form-sub {
  font-size: 11.5px;
  opacity: 0.72;
  margin-top: 2px;
}

.ngw-adm-section {
  padding-top: 16px;
  margin-top: 16px;
  border-top: 1px solid rgba(148, 163, 184, 0.2);
}
.ngw-adm-section.is-first {
  padding-top: 0;
  margin-top: 0;
  border-top: none;
}
.ngw-adm-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.ngw-adm-section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 700;
  line-height: 1.3;
}
.ngw-adm-section-meta {
  font-size: 11.5px;
  opacity: 0.74;
  font-variant-numeric: tabular-nums;
}

/* 2x2 Credentials Grid */
.ngw-adm-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px 16px;
}
.ngw-adm-field {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.ngw-adm-field label {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 6px;
  opacity: 0.92;
}
.ngw-adm-field-req {
  font-size: 10.5px;
  font-weight: 500;
  opacity: 0.65;
}
.ngw-adm-field .form-control,
.ngw-adm-pass-wrap input {
  width: 100%;
  height: 38px !important;
  min-height: 38px !important;
  padding: 6px 11px !important;
  font-size: 13px !important;
  border-radius: 7px !important;
  box-sizing: border-box;
}
.ngw-adm-pass-wrap {
  position: relative;
  display: flex;
  align-items: center;
  width: 100%;
}
.ngw-adm-pass-wrap input {
  padding-right: 40px !important;
}
.ngw-adm-eye-btn {
  position: absolute;
  right: 4px;
  top: 50%;
  transform: translateY(-50%);
  width: 30px;
  height: 30px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: inherit;
  opacity: 0.72;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: opacity 0.15s ease, background 0.15s ease;
  padding: 0;
}
.ngw-adm-eye-btn:hover {
  opacity: 1;
  background: rgba(148, 163, 184, 0.18);
}
.ngw-adm-field-hint {
  font-size: 11px;
  opacity: 0.68;
  margin-top: 5px;
  line-height: 1.35;
}

/* Super Admin Full-Access Info Strip */
.ngw-adm-super-note {
  margin-top: 14px;
  padding: 11px 14px;
  border-radius: 8px;
  border: 1px solid rgba(139, 92, 246, 0.34);
  background: rgba(139, 92, 246, 0.1);
  display: flex;
  align-items: flex-start;
  gap: 10px;
  font-size: 12px;
  line-height: 1.45;
}
body[data-ngw-theme="light"] .ngw-adm-super-note {
  background: rgba(124, 58, 237, 0.07);
  border-color: rgba(124, 58, 237, 0.25);
  color: #3b0764;
}
.ngw-adm-super-note i {
  font-size: 15px;
  color: #a78bfa;
  margin-top: 1px;
  flex-shrink: 0;
}
body[data-ngw-theme="light"] .ngw-adm-super-note i {
  color: #7c3aed;
}

/* Segmented Bulk Actions */
.ngw-adm-bulk-btns {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.ngw-adm-mini-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  border: 1px solid rgba(148, 163, 184, 0.3);
  background: rgba(148, 163, 184, 0.1);
  color: inherit;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.15s ease, border-color 0.15s ease;
}
.ngw-adm-mini-btn:hover {
  background: rgba(59, 130, 246, 0.18);
  border-color: rgba(59, 130, 246, 0.5);
}
.ngw-adm-mini-btn.is-primary {
  background: rgba(46, 160, 67, 0.16);
  border-color: rgba(46, 160, 67, 0.45);
  color: #4ade80;
}
body[data-ngw-theme="light"] .ngw-adm-mini-btn.is-primary {
  color: #15803d;
}
.ngw-adm-mini-btn.is-primary:hover {
  background: rgba(46, 160, 67, 0.26);
}

/* Role Badges */
.ngw-adm-role-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 700;
  color: #fff;
  white-space: nowrap;
}
.ngw-adm-role-badge.is-store {
  background: linear-gradient(135deg, #7c3aed, #5b21b6);
}
.ngw-adm-role-badge.is-admin {
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
}

/* Module Permission & Extra UI Switch Cards */
.ngw-adm-perm-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 10px;
}
.ngw-perm-switch-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid rgba(148, 163, 184, 0.24);
  background: rgba(148, 163, 184, 0.05);
  cursor: pointer;
  transition: border-color 0.16s ease, background 0.16s ease;
  margin: 0;
  user-select: none;
  min-height: 56px;
  box-sizing: border-box;
}
body[data-ngw-theme="light"] .ngw-perm-switch-card {
  background: #ffffff;
  border-color: rgba(15, 23, 42, 0.12);
}
body[data-ngw-ui="aurora"] .ngw-perm-switch-card {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.18);
  border-radius: 10px;
}
body[data-ngw-ui="aurora"][data-ngw-theme="light"] .ngw-perm-switch-card {
  background: rgba(255, 255, 255, 0.72);
  border-color: rgba(15, 23, 42, 0.12);
}
.ngw-perm-switch-card.is-active {
  border-color: rgba(46, 160, 67, 0.52);
  background: rgba(46, 160, 67, 0.1);
}
body[data-ngw-theme="light"] .ngw-perm-switch-card.is-active {
  background: rgba(22, 163, 74, 0.08);
  border-color: rgba(22, 163, 74, 0.45);
}
.ngw-perm-switch-card:hover {
  border-color: rgba(59, 130, 246, 0.58);
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
  font-size: 14px;
  flex-shrink: 0;
  background: rgba(148, 163, 184, 0.12);
}
.ngw-perm-card-title {
  font-size: 12.5px;
  font-weight: 700;
  line-height: 1.25;
}
.ngw-perm-card-state {
  font-size: 11px;
  margin-top: 2px;
  opacity: 0.74;
  line-height: 1.25;
}

/* Assigned UI Radio Grid (4 UIs) */
.ngw-adm-ui-choice-label {
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 8px;
  opacity: 0.9;
}
.ngw-adm-ui-radio-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 14px;
}
.ngw-adm-ui-radio-card {
  position: relative;
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 10px 11px;
  border-radius: 8px;
  border: 1.5px solid rgba(148, 163, 184, 0.25);
  background: rgba(148, 163, 184, 0.05);
  cursor: pointer;
  margin: 0;
  transition: border-color 0.16s ease, background 0.16s ease;
  user-select: none;
  min-height: 52px;
  box-sizing: border-box;
}
body[data-ngw-theme="light"] .ngw-adm-ui-radio-card {
  background: #ffffff;
  border-color: rgba(15, 23, 42, 0.13);
}
body[data-ngw-ui="aurora"] .ngw-adm-ui-radio-card {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.18);
  border-radius: 10px;
}
body[data-ngw-ui="aurora"][data-ngw-theme="light"] .ngw-adm-ui-radio-card {
  background: rgba(255, 255, 255, 0.72);
  border-color: rgba(15, 23, 42, 0.13);
}
.ngw-adm-ui-radio-card:hover {
  border-color: rgba(59, 130, 246, 0.65);
}
.ngw-adm-ui-radio-card.is-selected {
  border-color: #3b82f6;
  background: rgba(59, 130, 246, 0.14);
}
body[data-ngw-variant="compact"] .ngw-adm-ui-radio-card.is-selected {
  border-color: #f59e0b;
  background: rgba(245, 158, 11, 0.14);
}
.ngw-adm-ui-radio-card input[type="radio"] {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}
.ngw-adm-ui-radio-ico {
  width: 30px;
  height: 30px;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  flex-shrink: 0;
  background: rgba(148, 163, 184, 0.14);
}
.ngw-adm-ui-radio-text {
  min-width: 0;
  flex: 1;
}
.ngw-adm-ui-radio-name {
  font-size: 12px;
  font-weight: 700;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ngw-adm-ui-radio-sub {
  font-size: 10.5px;
  opacity: 0.7;
  margin-top: 2px;
}
.ngw-adm-ui-radio-check {
  font-size: 13px;
  color: #3b82f6;
  opacity: 0;
  flex-shrink: 0;
  transition: opacity 0.15s ease;
}
body[data-ngw-variant="compact"] .ngw-adm-ui-radio-check {
  color: #f59e0b;
}
.ngw-adm-ui-radio-card.is-selected .ngw-adm-ui-radio-check {
  opacity: 1;
}

/* Form Footer */
.ngw-adm-form-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid rgba(148, 163, 184, 0.2);
}
.ngw-adm-form-footer .btn {
  min-height: 36px;
  padding: 7px 18px;
  font-weight: 600;
  white-space: nowrap;
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
  background-color: rgba(148, 163, 184, 0.36);
  transition: background-color 0.18s ease;
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
  transition: transform 0.18s ease;
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
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}
.ngw-table-perm-chip.is-on {
  background: rgba(46, 160, 67, 0.15);
  border: 1px solid rgba(46, 160, 67, 0.45);
  color: #4ade80;
}
body[data-ngw-theme="light"] .ngw-table-perm-chip.is-on {
  color: #15803d;
}
.ngw-table-perm-chip.is-off {
  background: rgba(248, 81, 73, 0.1);
  border: 1px solid rgba(248, 81, 73, 0.3);
  color: #ff7b72;
  opacity: 0.82;
}
body[data-ngw-theme="light"] .ngw-table-perm-chip.is-off {
  color: #b91c1c;
}
.ngw-table-perm-chip.is-full {
  background: rgba(124, 58, 237, 0.15);
  border: 1px solid rgba(139, 92, 246, 0.42);
  color: #c4b5fd;
}
body[data-ngw-theme="light"] .ngw-table-perm-chip.is-full {
  color: #6d28d9;
}

@media (max-width: 960px) {
  .ngw-adm-ui-radio-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 640px) {
  .ngw-adm-form-drawer {
    padding: 14px;
  }
  .ngw-adm-form-grid {
    grid-template-columns: 1fr;
    gap: 12px;
  }
  .ngw-adm-perm-grid {
    grid-template-columns: 1fr;
  }
  .ngw-adm-ui-radio-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }
  .ngw-adm-form-footer {
    justify-content: stretch;
  }
  .ngw-adm-form-footer .btn {
    flex: 1 1 120px;
    text-align: center;
  }
}
</style>
<script>
  function Pass(id, btn){
    var x = document.getElementById(id);
    if (!x) return;
    var showing = (x.type === 'password');
    x.type = showing ? 'text' : 'password';
    if (btn) {
      var ico = btn.querySelector('i');
      if (ico) {
        ico.className = showing ? 'fa fa-eye-slash' : 'fa fa-eye';
      }
      btn.setAttribute('aria-pressed', showing ? 'true' : 'false');
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
    var superNote = document.getElementById('ngw_super_admin_note');
    if (!sel) return;
    var isSubAdmin = (sel.value === 'admin');
    if (permBox) permBox.style.display = isSubAdmin ? 'block' : 'none';
    if (superNote) superNote.style.display = isSubAdmin ? 'none' : 'flex';
  }
  function ngwSyncPermCardStyles() {
    var cards = document.querySelectorAll('.ngw-perm-switch-card');
    for (var i = 0; i < cards.length; i++) {
      var cb = cards[i].querySelector('input[type="checkbox"]');
      var st = cards[i].querySelector('.ngw-perm-card-state');
      if (!cb) continue;
      if (cb.checked) {
        cards[i].classList.add('is-active');
        if (st) st.textContent = st.getAttribute('data-on-text') || 'Active';
      } else {
        cards[i].classList.remove('is-active');
        if (st) st.textContent = st.getAttribute('data-off-text') || 'Disabled';
      }
    }
    var permCbs = document.querySelectorAll('.ngw-adm-perm-cb');
    var enabledCount = 0;
    for (var j = 0; j < permCbs.length; j++) {
      if (permCbs[j].checked) enabledCount++;
    }
    var counterEl = document.getElementById('ngw_adm_perm_counter');
    if (counterEl && permCbs.length > 0) {
      counterEl.textContent = enabledCount + ' / ' + permCbs.length;
    }
  }
  function ngwSyncAssignedUiRadios() {
    var cards = document.querySelectorAll('.ngw-adm-ui-radio-card');
    for (var i = 0; i < cards.length; i++) {
      var r = cards[i].querySelector('input[type="radio"]');
      if (r && r.checked) {
        cards[i].classList.add('is-selected');
      } else {
        cards[i].classList.remove('is-selected');
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
  function ngwSetAllAdminUiPerms(checked) {
    var boxes = document.querySelectorAll('.ngw-adm-ui-cb');
    for (var i = 0; i < boxes.length; i++) {
      boxes[i].checked = !!checked;
    }
    ngwSyncPermCardStyles();
  }
  document.addEventListener('DOMContentLoaded', function () {
    ngwSyncPermCardStyles();
    ngwSyncAssignedUiRadios();
  });
</script>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header ngw-adm-header-bar">
        <div class="ngw-adm-header-left">
          <h3 class="card-title" style="margin:0;">
            <i class="fa fa-user-secret"></i> <?= $isBn ? 'সুপার এডমিন ও এডমিন ইউজার' : 'Super Admin &amp; Admin Users'; ?>
          </h3>
          <span style="font-size:12px; opacity:0.75; font-variant-numeric:tabular-nums;">
            &middot; <?= count($adminUsers); ?> <?= $isBn ? 'জন ইউজার' : (count($adminUsers) === 1 ? 'User' : 'Users'); ?>
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
        <div role="alert" style="padding:8px 12px; margin-bottom:10px; border-radius:6px; background:rgba(220,53,69,0.12); border:1px solid #dc3545; color:#dc3545; font-weight:bold;">
          <?= $isBn ? 'প্রথমবার লগইন নিরাপত্তা: সফটওয়্যার ব্যবহারের আগে ডিফল্ট এডমিন পাসওয়ার্ড পরিবর্তন করুন।' : 'First login security: change the default administrator password before using the software.'; ?>
        </div>
        <?php } ?>
        <?php if (!empty($settingsError)) { ?>
        <div role="alert" style="padding:8px 12px; margin-bottom:10px; border-radius:6px; background:rgba(220,53,69,0.12); border:1px solid #dc3545; color:#dc3545; font-weight:bold;">
          <i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($settingsError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php } ?>
        <?php if (!empty($settingsSuccess)) { ?>
        <div style="padding:8px 12px; margin-bottom:10px; border-radius:6px; background:rgba(46,160,67,0.18); border:1px solid #2ea043; color:#2ea043; font-weight:bold;">
          <i class="fa fa-check-circle"></i> <?= htmlspecialchars($settingsSuccess, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php } ?>

        <!-- Collapsible Add / Edit Admin User Form -->
        <?php $adminShortcutGroups = function_exists('getAdminShortcutPermissionGroups') ? getAdminShortcutPermissionGroups() : array(); ?>
        <div id="ngw_admin_form_panel" class="ngw-adm-form-drawer" style="display:<?= $showFormByDefault ? 'block' : 'none'; ?>;">
          <form autocomplete="off" method="post" action="./admin.php?id=admin-settings" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="edit_admin_id" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['id'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            <input type="hidden" name="admin_perm_form_submitted" value="1">
            <input type="hidden" name="adm_shortcut_submitted" value="1">

            <div class="ngw-adm-form-header">
              <div class="ngw-adm-form-title-wrap">
                <span class="ngw-adm-form-title-ico <?= $editingAdmin ? 'is-edit' : ''; ?>">
                  <i class="fa <?= $editingAdmin ? 'fa-pencil' : 'fa-user-plus'; ?>"></i>
                </span>
                <div>
                  <div class="ngw-adm-form-heading">
                    <?= $editingAdmin
                      ? (($isBn ? 'ইউজার এডিট করুন: ' : 'Edit Account: ') . htmlspecialchars($editingAdmin['username'], ENT_QUOTES, 'UTF-8'))
                      : ($isBn ? 'নতুন এডমিন / সুপার এডমিন যুক্ত করুন' : 'Create Admin / Super Admin Account'); ?>
                  </div>
                  <div class="ngw-adm-form-sub">
                    <?= $editingAdmin
                      ? ($isBn ? 'লগইন তথ্য, রোল, মডিউল পারমিশন এবং ইউআই অ্যাসাইনমেন্ট পরিবর্তন করুন' : 'Update login credentials, role, module permissions, and UI assignment')
                      : ($isBn ? 'অ্যাকাউন্টের তথ্য দিন এবং প্রয়োজন অনুযায়ী পারমিশন ও ইউআই নির্ধারণ করুন' : 'Fill account details and configure permissions and UI assignment below'); ?>
                  </div>
                </div>
              </div>
              <?php if ($editingAdmin) { ?>
                <a class="btn bg-secondary" style="padding:5px 12px; font-size:11.5px; white-space:nowrap;" href="./admin.php?id=admin-settings">
                  <i class="fa fa-times"></i> <?= $isBn ? 'বন্ধ করুন' : 'Close'; ?>
                </a>
              <?php } else { ?>
                <button type="button" class="btn bg-secondary" style="padding:5px 12px; font-size:11.5px; cursor:pointer; white-space:nowrap;" onclick="ngwToggleAddUserForm(false)">
                  <i class="fa fa-times"></i> <?= $isBn ? 'বন্ধ করুন' : 'Close'; ?>
                </button>
              <?php } ?>
            </div>

            <!-- Section 1: Account & Login Credentials -->
            <div class="ngw-adm-section is-first">
              <div class="ngw-adm-section-head">
                <div class="ngw-adm-section-title">
                  <i class="fa fa-id-card-o" style="color:#60a5fa;"></i>
                  <span><?= $isBn ? 'অ্যাকাউন্ট ও লগইন তথ্য' : 'Account &amp; Login Credentials'; ?></span>
                </div>
                <span class="ngw-adm-section-meta">
                  <?= $editingAdmin
                    ? ($isBn ? 'পাসওয়ার্ড খালি রাখলে আগেরটিই থাকবে' : 'Leave password blank to keep existing')
                    : ($isBn ? 'সব তথ্য পূরণ করা আবশ্যক' : 'All fields required'); ?>
                </span>
              </div>

              <div class="ngw-adm-form-grid">
                <div class="ngw-adm-field">
                  <label for="ngw_admin_role_select">
                    <span><?= $isBn ? 'রোল (Role)' : 'Account Role'; ?></span>
                  </label>
                  <select class="form-control" id="ngw_admin_role_select" name="admin_role" onchange="ngwToggleAdminRolePerms()">
                    <option value="admin" <?= $formRole === 'admin' ? 'selected' : ''; ?>>
                      <?= $isBn ? 'এডমিন (Admin)' : 'Admin (Sub-Admin)'; ?>
                    </option>
                    <option value="super_admin" <?= $formRole !== 'admin' ? 'selected' : ''; ?>>
                      <?= $isBn ? 'সুপার এডমিন (Super Admin)' : 'Super Admin'; ?>
                    </option>
                  </select>
                </div>

                <div class="ngw-adm-field">
                  <label for="ngw_admin_name_input">
                    <span><?= $isBn ? 'পূর্ণ নাম' : 'Full Name'; ?></span>
                  </label>
                  <input class="form-control" id="ngw_admin_name_input" type="text" name="admin_name" maxlength="80" placeholder="<?= $isBn ? 'পূর্ণ নাম লিখুন' : 'Enter full name'; ?>" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['name'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
                </div>

                <div class="ngw-adm-field">
                  <label for="ngw_admin_user_input">
                    <span><?= $_user_name ?></span>
                  </label>
                  <input class="form-control" id="ngw_admin_user_input" type="text" name="admin_username" maxlength="64" placeholder="<?= $isBn ? 'ইউজারনেম লিখুন' : 'Enter username'; ?>" value="<?= $editingAdmin ? htmlspecialchars($editingAdmin['username'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
                </div>

                <div class="ngw-adm-field">
                  <label for="adm_user_pass">
                    <span><?= $_password ?></span>
                    <?php if ($editingAdmin) { ?>
                      <span class="ngw-adm-field-req"><?= $isBn ? 'ঐচ্ছিক' : 'Optional'; ?></span>
                    <?php } ?>
                  </label>
                  <div class="ngw-adm-pass-wrap">
                    <input class="form-control" id="adm_user_pass" type="password" name="admin_password" placeholder="<?= $editingAdmin ? ($isBn ? 'নতুন পাসওয়ার্ড (অপরিবর্তিত রাখতে খালি রাখুন)' : 'New password (blank to keep current)') : ($isBn ? 'নতুন পাসওয়ার্ড লিখুন' : 'Enter password'); ?>" autocomplete="new-password" <?= $editingAdmin ? '' : 'required'; ?>>
                    <button type="button" class="ngw-adm-eye-btn" onclick="Pass('adm_user_pass', this)" title="<?= $isBn ? 'পাসওয়ার্ড দেখুন / লুকান' : 'Show / Hide Password'; ?>" aria-label="Toggle Password Visibility" aria-pressed="false">
                      <i class="fa fa-eye"></i>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Quiet info bar when editing/creating a Super Admin -->
              <div id="ngw_super_admin_note" class="ngw-adm-super-note" style="display:<?= $formRole === 'admin' ? 'none' : 'flex'; ?>;">
                <i class="fa fa-shield"></i>
                <div>
                  <b><?= $isBn ? 'সুপার এডমিন ফুল অ্যাক্সেস:' : 'Super Admin Full Access:'; ?></b>
                  <?= $isBn
                    ? 'এই রোলের সব মডিউলে পূর্ণ পারমিশন স্বয়ংক্রিয়ভাবে চালু থাকে। প্রতিটি সুপার এডমিন UI Settings থেকে শুধুমাত্র নিজের ব্যক্তিগত ইউআই পরিবর্তন করতে পারেন (বাই ডিফল্ট ক্লাসিক)।'
                    : 'This role automatically has full access to every module. Each Super Admin manages only their own personal UI style from UI Settings (Classic by default).'; ?>
                </div>
              </div>
            </div>

            <!-- Admin-Only Sections (Module Permissions + UI Assignment & Access) -->
            <div id="ngw_admin_perm_box" style="display:<?= $formRole === 'admin' ? 'block' : 'none'; ?>;">
              <!-- Section 2: Admin Module Permissions -->
              <div class="ngw-adm-section">
                <div class="ngw-adm-section-head">
                  <div class="ngw-adm-section-title">
                    <i class="fa fa-sliders" style="color:#38bdf8;"></i>
                    <span><?= $isBn ? 'এডমিন মডিউল পারমিশন' : 'Admin Module Permissions'; ?></span>
                    <span class="ngw-adm-section-meta" id="ngw_adm_perm_counter"></span>
                  </div>
                  <div class="ngw-adm-bulk-btns">
                    <button type="button" class="ngw-adm-mini-btn is-primary" onclick="ngwSetAllAdminPerms(true)">
                      <i class="fa fa-check"></i> <?= $isBn ? 'সব চালু' : 'Enable All'; ?>
                    </button>
                    <button type="button" class="ngw-adm-mini-btn" onclick="ngwSetAllAdminPerms(false)">
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
                    $onText  = $isBn ? 'চালু আছে' : 'Enabled';
                    $offText = $isBn ? 'বন্ধ আছে' : 'Disabled';
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

              <!-- Section 3: UI Style Assignment & Extra UI Permissions -->
              <div class="ngw-adm-section">
                <div class="ngw-adm-section-head">
                  <div class="ngw-adm-section-title">
                    <i class="fa fa-paint-brush" style="color:#f59e0b;"></i>
                    <span><?= $isBn ? 'ইউআই অ্যাসাইন ও ইউআই পারমিশন' : 'UI Assignment &amp; UI Permissions'; ?></span>
                  </div>
                  <div class="ngw-adm-bulk-btns">
                    <button type="button" class="ngw-adm-mini-btn is-primary" onclick="ngwSetAllAdminUiPerms(true)">
                      <i class="fa fa-check"></i> <?= $isBn ? 'সব ইউআই পারমিশন চালু' : 'Allow All UIs'; ?>
                    </button>
                    <button type="button" class="ngw-adm-mini-btn" onclick="ngwSetAllAdminUiPerms(false)">
                      <i class="fa fa-ban"></i> <?= $isBn ? 'শুধু ক্লাসিক' : 'Classic Only'; ?>
                    </button>
                  </div>
                </div>

                <!-- 3A: Direct Assigned UI (Compact 4-Card Radio Selector) -->
                <div class="ngw-adm-ui-choice-label">
                  <?= $isBn ? '১. এই অ্যাডমিনের নির্ধারিত ইউআই (Assigned UI)' : '1. Assigned UI Style for this Admin'; ?>
                </div>
                <?php
                $uiChoiceIcons = array(
                  'classic' => array('icon' => 'fa-desktop', 'color' => '#94a3b8'),
                  'modern'  => array('icon' => 'fa-columns', 'color' => '#38bdf8'),
                  'compact' => array('icon' => 'fa-th-large', 'color' => '#f59e0b'),
                  'aurora'  => array('icon' => 'fa-diamond', 'color' => '#38bdf8'),
                );
                ?>
                <div class="ngw-adm-ui-radio-grid">
                  <?php foreach ($allUiStyleDefs as $uKey => $uMeta) {
                    $uSel = ($formUiStyle === $uKey);
                    $uIco = isset($uiChoiceIcons[$uKey]['icon']) ? $uiChoiceIcons[$uKey]['icon'] : 'fa-paint-brush';
                    $uCol = isset($uiChoiceIcons[$uKey]['color']) ? $uiChoiceIcons[$uKey]['color'] : '#60a5fa';
                  ?>
                    <label class="ngw-adm-ui-radio-card <?= $uSel ? 'is-selected' : ''; ?>">
                      <input type="radio" name="admin_ui_style" value="<?= htmlspecialchars($uKey, ENT_QUOTES, 'UTF-8'); ?>" <?= $uSel ? 'checked' : ''; ?> onchange="ngwSyncAssignedUiRadios()">
                      <span class="ngw-adm-ui-radio-ico" style="color:<?= htmlspecialchars($uCol, ENT_QUOTES, 'UTF-8'); ?>;">
                        <i class="fa <?= htmlspecialchars($uIco, ENT_QUOTES, 'UTF-8'); ?>"></i>
                      </span>
                      <span class="ngw-adm-ui-radio-text">
                        <span class="ngw-adm-ui-radio-name"><?= htmlspecialchars($uMeta['short'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="ngw-adm-ui-radio-sub"><?= htmlspecialchars($uMeta['badge'], ENT_QUOTES, 'UTF-8'); ?></span>
                      </span>
                      <i class="fa fa-check-circle ngw-adm-ui-radio-check"></i>
                    </label>
                  <?php } ?>
                </div>

                <!-- 3B: Extra UI Permissions (Switch Own UI & Assign to Own Resellers) -->
                <div class="ngw-adm-ui-choice-label" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px;">
                  <span><?= $isBn ? '২. অতিরিক্ত ইউআই ব্যবহার ও রিসেলারকে অ্যাসাইন পারমিশন' : '2. Permitted UIs (Switch Own UI &amp; Assign to Own Resellers)'; ?></span>
                  <span class="ngw-adm-section-meta"><?= $isBn ? 'ক্লাসিক ইউআই সর্বদা অনুমোদিত' : 'Classic UI is always included'; ?></span>
                </div>
                <div class="ngw-adm-perm-grid">
                  <?php
                  $extraUiPermsMeta = array(
                    'modern' => array(
                      'icon' => 'fa-columns',
                      'color' => '#38bdf8',
                      'title' => $allUiStyleDefs['modern']['short'],
                      'on' => $isBn ? 'ব্যবহার ও অ্যাসাইন অনুমোদিত' : 'Switch & assign permitted',
                      'off' => $isBn ? 'অনুমোদিত নয়' : 'Not permitted',
                    ),
                    'compact' => array(
                      'icon' => 'fa-th-large',
                      'color' => '#f59e0b',
                      'title' => $allUiStyleDefs['compact']['short'],
                      'on' => $isBn ? 'ব্যবহার ও অ্যাসাইন অনুমোদিত' : 'Switch & assign permitted',
                      'off' => $isBn ? 'অনুমোদিত নয়' : 'Not permitted',
                    ),
                    'aurora' => array(
                      'icon' => 'fa-diamond',
                      'color' => '#38bdf8',
                      'title' => $allUiStyleDefs['aurora']['short'],
                      'on' => $isBn ? 'ব্যবহার ও অ্যাসাইন অনুমোদিত' : 'Switch & assign permitted',
                      'off' => $isBn ? 'অনুমোদিত নয়' : 'Not permitted',
                    ),
                  );
                  foreach ($extraUiPermsMeta as $uPermKey => $uPermInfo) {
                    $uChecked = in_array($uPermKey, $formAllowedUis, true);
                  ?>
                    <label class="ngw-perm-switch-card <?= $uChecked ? 'is-active' : ''; ?>">
                      <div class="ngw-perm-card-left">
                        <div class="ngw-perm-icon-box" style="color:<?= htmlspecialchars($uPermInfo['color'], ENT_QUOTES, 'UTF-8'); ?>;">
                          <i class="fa <?= htmlspecialchars($uPermInfo['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                        </div>
                        <div style="min-width:0;">
                          <div class="ngw-perm-card-title"><?= htmlspecialchars($uPermInfo['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                          <div class="ngw-perm-card-state" data-on-text="<?= htmlspecialchars($uPermInfo['on'], ENT_QUOTES, 'UTF-8'); ?>" data-off-text="<?= htmlspecialchars($uPermInfo['off'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?= htmlspecialchars($uChecked ? $uPermInfo['on'] : $uPermInfo['off'], ENT_QUOTES, 'UTF-8'); ?>
                          </div>
                        </div>
                      </div>
                      <div class="ngw-toggle-wrap">
                        <input type="checkbox" class="ngw-adm-ui-cb" name="adm_ui_perms[<?= htmlspecialchars($uPermKey, ENT_QUOTES, 'UTF-8'); ?>]" value="1" <?= $uChecked ? 'checked' : ''; ?> onchange="ngwSyncPermCardStyles()">
                        <span class="ngw-toggle-slider"></span>
                      </div>
                    </label>
                  <?php } ?>
                </div>
              </div>
            </div>

            <div class="ngw-adm-form-footer">
              <?php if ($editingAdmin) { ?>
                <a class="btn bg-secondary" href="./admin.php?id=admin-settings">
                  <i class="fa fa-times"></i> <?= $isBn ? 'বাতিল' : 'Cancel'; ?>
                </a>
                <button class="btn bg-success" type="submit" name="save_admin_user" style="cursor:pointer;">
                  <i class="fa fa-save"></i> <?= $isBn ? 'পরিবর্তন সেভ করুন' : 'Save Changes'; ?>
                </button>
              <?php } else { ?>
                <button type="button" class="btn bg-secondary" style="cursor:pointer;" onclick="ngwToggleAddUserForm(false)">
                  <i class="fa fa-times"></i> <?= $isBn ? 'বাতিল' : 'Cancel'; ?>
                </button>
                <button class="btn bg-success" type="submit" name="save_admin_user" style="cursor:pointer;">
                  <i class="fa fa-save"></i> <?= $isBn ? 'ইউজার সেভ করুন' : 'Save User'; ?>
                </button>
              <?php } ?>
            </div>
          </form>
        </div>

        <!-- Default Full-Width Admin Users List Table -->
        <div class="overflow ngw-mobile-card-wrap" style="margin-bottom:10px;">
          <table class="table table-bordered table-hover ngw-mobile-card-table" style="margin-bottom:0;">
            <thead>
              <tr>
                <th style="width:40px;" class="text-center">#</th>
                <th><?= $isBn ? 'পূর্ণ নাম' : 'Full Name'; ?></th>
                <th><?= $_user_name ?></th>
                <th style="width:140px;"><?= $isBn ? 'রোল' : 'Role'; ?></th>
                <th><?= $isBn ? 'পারমিশন' : 'Permissions'; ?></th>
                <th style="width:190px;" class="text-center"><?= $_action ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($adminUsers as $idx => $adm) {
                $isSelf = ((string)$adm['id'] === $currentLoggedAdminId) || ((string)$adm['username'] === (string)$_SESSION['mikhmon']);
                $rowRole = (isset($adm['admin_role']) && $adm['admin_role'] === 'admin') ? 'admin' : 'super_admin';
              ?>
              <tr>
                <td class="align-middle text-center ngw-mc-hide-mobile"><?= $idx + 1; ?></td>
                <td class="align-middle ngw-mc-head" data-label="<?= htmlspecialchars($isBn ? 'পূর্ণ নাম' : 'Full Name', ENT_QUOTES, 'UTF-8'); ?>">
                  <div style="display:flex; align-items:center; gap:8px;">
                    <span style="width:28px; height:28px; border-radius:50%; background:<?= $rowRole === 'super_admin' ? 'rgba(124,58,237,0.2)' : 'rgba(37,99,235,0.2)'; ?>; color:<?= $rowRole === 'super_admin' ? '#c4b5fd' : '#93c5fd'; ?>; display:inline-flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0;">
                      <i class="fa <?= $rowRole === 'super_admin' ? 'fa-shield' : 'fa-user'; ?>"></i>
                    </span>
                    <div>
                      <b><?= htmlspecialchars($adm['name'], ENT_QUOTES, 'UTF-8'); ?></b>
                      <?php if ($isSelf) { ?>
                        <span style="font-size:10.5px; opacity:0.75; margin-left:4px;">(<?= $isBn ? 'আপনি' : 'You'; ?>)</span>
                      <?php } ?>
                    </div>
                  </div>
                </td>
                <td class="align-middle" data-label="<?= htmlspecialchars($_user_name, ENT_QUOTES, 'UTF-8'); ?>"><code><?= htmlspecialchars($adm['username'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                <td class="align-middle" data-label="<?= htmlspecialchars($isBn ? 'রোল' : 'Role', ENT_QUOTES, 'UTF-8'); ?>">
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
                <td class="align-middle ngw-mc-stack" data-label="<?= htmlspecialchars($isBn ? 'পারমিশন' : 'Permissions', ENT_QUOTES, 'UTF-8'); ?>">
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
                      <?php }
                        $rowAssignedUi = ngwGetAdminAssignedUi($adm['id'], $adm);
                        $rowAllowedUis = ngwGetAdminAllowedUis($adm['id'], $adm);
                        $rowAssignedLabel = isset($allUiStyleDefs[$rowAssignedUi]['short']) ? $allUiStyleDefs[$rowAssignedUi]['short'] : ucfirst($rowAssignedUi);
                      ?>
                        <span class="ngw-table-perm-chip is-full" title="<?= $isBn ? 'নির্ধারিত ইউআই' : 'Assigned UI'; ?>">
                          <i class="fa fa-paint-brush"></i> UI: <?= htmlspecialchars($rowAssignedLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php if (in_array('modern', $rowAllowedUis, true)) { ?>
                          <span class="ngw-table-perm-chip is-on" title="<?= $isBn ? 'মডার্ন ইউআই পারমিশন চালু' : 'Modern UI permission granted'; ?>">
                            <i class="fa fa-columns"></i> <?= htmlspecialchars($allUiStyleDefs['modern']['short'], ENT_QUOTES, 'UTF-8'); ?> <i class="fa fa-check" style="font-size:9.5px;"></i>
                          </span>
                        <?php } ?>
                        <?php if (in_array('compact', $rowAllowedUis, true)) { ?>
                          <span class="ngw-table-perm-chip is-on" title="<?= $isBn ? 'নোভা কমান্ড ইউআই পারমিশন চালু' : 'Nova Command UI permission granted'; ?>">
                            <i class="fa fa-th-large"></i> <?= htmlspecialchars($allUiStyleDefs['compact']['short'], ENT_QUOTES, 'UTF-8'); ?> <i class="fa fa-check" style="font-size:9.5px;"></i>
                          </span>
                        <?php } ?>
                        <?php if (in_array('aurora', $rowAllowedUis, true)) { ?>
                          <span class="ngw-table-perm-chip is-on" title="<?= $isBn ? 'ক্রিস্টাল গ্লাস ইউআই পারমিশন চালু' : 'Crystal Glass UI permission granted'; ?>">
                            <i class="fa fa-diamond"></i> <?= htmlspecialchars($allUiStyleDefs['aurora']['short'], ENT_QUOTES, 'UTF-8'); ?> <i class="fa fa-check" style="font-size:9.5px;"></i>
                          </span>
                        <?php } ?>
                    </div>
                  <?php } ?>
                </td>
                <td class="align-middle text-center ngw-mc-actions" data-label="<?= htmlspecialchars($_action, ENT_QUOTES, 'UTF-8'); ?>" style="white-space:nowrap;">
                  <?php if ($rowRole === 'admin' && !$isSelf) { ?>
                  <form method="post" action="./admin.php?id=admin-settings" style="display:inline; margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="login_admin_id" value="<?= htmlspecialchars($adm['id'], ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="login_as_admin" class="btn bg-success" style="padding:3px 8px; font-size:11px; cursor:pointer; margin:1px; border-radius:4px;" title="<?= $isBn ? 'এই এডমিনের প্যানেলে এক ক্লিকে লগইন করুন' : '1-Click Login to this Admin Panel'; ?>">
                      <i class="fa fa-sign-in"></i> <?= $isBn ? 'লগইন' : 'Login'; ?>
                    </button>
                  </form>
                  <?php } ?>
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

