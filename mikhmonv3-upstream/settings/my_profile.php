<?php
/**
 * Self-service Account Profile for Administrators and Resellers.
 * - Account Information: Account Name, Login User, Account Type (Admin can edit Account Name & Login User; Reseller is read-only).
 * - Upload Avatar: Upload or remove profile avatar.
 * - Change Password: Change own password with Show/Hide toggle.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
if (!isset($_SESSION['mikhmon'])) {
    http_response_code(401);
    echo '<div class="card"><div class="card-body">Please sign in again.</div></div>';
    return;
}
require_once __DIR__ . '/../include/reseller_acl.php';

$isResellerProfile = isset($_SESSION['mikhmon_role']) && $_SESSION['mikhmon_role'] === 'reseller';
$isImpersonating = !empty($_SESSION['mikhmon_impersonator_admin']);
$profileUsername = (string)$_SESSION['mikhmon'];
$profileRole = $isResellerProfile ? 'Reseller' : 'Administrator';
$profileName = $profileUsername;
$profileId = 'adm_1';
$uploadKey = 'admin';
$adminRecord = null;
$profileRecord = null;

if ($isResellerProfile) {
    $profileRecord = getCurrentResellerRecord();
    if (!is_array($profileRecord)) {
        $profileError = 'The reseller account could not be loaded. Please sign in again.';
    } else {
        $profileName = isset($profileRecord['name']) ? (string)$profileRecord['name'] : $profileUsername;
        $profileId = isset($profileRecord['id']) ? (string)$profileRecord['id'] : '';
        $uploadKey = $profileId;
    }
} else {
    $adminRecord = getCurrentAdminRecord();
    if (is_array($adminRecord)) {
        $profileId = !empty($adminRecord['id']) ? (string)$adminRecord['id'] : 'adm_1';
        $profileName = !empty($adminRecord['name']) ? (string)$adminRecord['name'] : 'Administrator';
        $profileUsername = !empty($adminRecord['username']) ? (string)$adminRecord['username'] : $profileUsername;
        $uploadKey = $profileId;
    } else {
        $profileName = 'Administrator';
        $profileId = 'adm_1';
        $uploadKey = 'admin';
    }
}

if (empty($_SESSION['ngw_profile_csrf'])) {
    $_SESSION['ngw_profile_csrf'] = bin2hex(random_bytes(32));
}
$profileCsrf = (string)$_SESSION['ngw_profile_csrf'];
$profileSavedMsg = '';
$profileError = isset($profileError) ? $profileError : '';

// Handle Profile Avatar Upload
if (isset($_POST['upload_my_avatar'])) {
    $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if ($postedToken === '' || !hash_equals($profileCsrf, $postedToken)) {
        $profileError = 'Security token expired. Reload this page and try again.';
    } elseif (!isset($_FILES['profile_logo_file']) || empty($_FILES['profile_logo_file']['tmp_name'])) {
        $profileError = 'Please select a PNG, JPG, or WebP image file to upload.';
    } else {
        $upRes = handleResellerLogoUpload($uploadKey, $_FILES['profile_logo_file']);
        if (!empty($upRes['ok']) && !empty($upRes['filename'])) {
            $newLogoFn = (string)$upRes['filename'];
            if ($isResellerProfile && $profileId !== '') {
                $allResellers = loadResellers(true);
                foreach ($allResellers as $idx => $row) {
                    if ((string)$row['id'] === $profileId) {
                        $allResellers[$idx]['logo_filename'] = $newLogoFn;
                        saveResellers($allResellers);
                        break;
                    }
                }
                $_SESSION['mikhmon_logo_filename'] = $newLogoFn;
                unset($GLOBALS['NGW_RESELLER_SYNCED']);
            } else {
                $allAdmins = loadAdminUsers(true);
                foreach ($allAdmins as $idx => $adm) {
                    if ((string)$adm['id'] === $profileId) {
                        $allAdmins[$idx]['avatar'] = $newLogoFn;
                        $allAdmins[$idx]['updated_at'] = date('Y-m-d H:i:s');
                        saveAdminUsers($allAdmins);
                        break;
                    }
                }
            }
            $profileSavedMsg = 'Your profile avatar has been updated.';
        } else {
            $profileError = isset($upRes['error']) ? (string)$upRes['error'] : 'Could not upload avatar image.';
        }
    }
}

// Handle Profile Avatar Removal
if (isset($_POST['remove_my_avatar'])) {
    $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if ($postedToken === '' || !hash_equals($profileCsrf, $postedToken)) {
        $profileError = 'Security token expired. Reload this page and try again.';
    } else {
        if ($isResellerProfile && $profileId !== '') {
            removeResellerCustomLogo($profileId);
            $_SESSION['mikhmon_logo_filename'] = '';
            unset($GLOBALS['NGW_RESELLER_SYNCED']);
        } else {
            $foldersToClear = array('admin', preg_replace('/[^a-zA-Z0-9_\-]/', '', $profileId));
            foreach ($foldersToClear as $f) {
                if ($f === '') continue;
                $adminDir = dirname(__DIR__) . '/uploads/reseller-logos/' . $f;
                if (is_dir($adminDir)) {
                    foreach (glob($adminDir . '/logo.*') as $oldFile) {
                        if (is_file($oldFile)) {
                            @unlink($oldFile);
                        }
                    }
                }
            }
            $allAdmins = loadAdminUsers(true);
            foreach ($allAdmins as $idx => $adm) {
                if ((string)$adm['id'] === $profileId) {
                    $allAdmins[$idx]['avatar'] = '';
                    $allAdmins[$idx]['updated_at'] = date('Y-m-d H:i:s');
                    saveAdminUsers($allAdmins);
                    break;
                }
            }
        }
        $profileSavedMsg = 'Profile avatar reset to default.';
    }
}

// Handle Admin Account Info Update (Account Name & Login User)
if (!$isResellerProfile && isset($_POST['save_admin_info'])) {
    $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $newName = isset($_POST['admin_name']) ? trim((string)$_POST['admin_name']) : '';
    $newUsername = isset($_POST['admin_username']) ? trim((string)$_POST['admin_username']) : '';

    if ($postedToken === '' || !hash_equals($profileCsrf, $postedToken)) {
        $profileError = 'Security token expired. Reload this page and try again.';
    } elseif ($newName === '' || strlen($newName) > 80) {
        $profileError = 'Account Name is required (up to 80 characters).';
    } elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $newUsername)) {
        $profileError = 'Login User must be 3–64 characters and use only letters, numbers, dot, underscore, @ or hyphen.';
    } else {
        $allAdmins = loadAdminUsers(true);
        $duplicate = false;
        foreach ($allAdmins as $adm) {
            if ((string)$adm['id'] !== $profileId && strtolower((string)$adm['username']) === strtolower($newUsername)) {
                $duplicate = true;
                break;
            }
        }
        if (!$duplicate) {
            foreach (loadResellers() as $r) {
                if (isset($r['username']) && strtolower((string)$r['username']) === strtolower($newUsername)) {
                    $duplicate = true;
                    break;
                }
            }
        }
        if ($duplicate) {
            $profileError = 'That username is already in use by another account.';
        } else {
            $updated = false;
            foreach ($allAdmins as $idx => $adm) {
                if ((string)$adm['id'] === $profileId) {
                    $allAdmins[$idx]['name'] = $newName;
                    $allAdmins[$idx]['username'] = $newUsername;
                    $allAdmins[$idx]['updated_at'] = date('Y-m-d H:i:s');
                    $updated = saveAdminUsers($allAdmins);
                    break;
                }
            }
            if ($updated) {
                $profileName = $newName;
                $profileUsername = $newUsername;
                $adminRecord = getCurrentAdminRecord();
                $_SESSION['mikhmon'] = $newUsername;
                $_SESSION['mikhmon_admin_id'] = $profileId;
                $_SESSION['ngw_profile_csrf'] = bin2hex(random_bytes(32));
                $profileCsrf = (string)$_SESSION['ngw_profile_csrf'];
                $profileSavedMsg = 'Account information updated successfully.';
            } else {
                $profileError = 'Could not save account information.';
            }
        }
    }
}

// Handle Password Change (for both Admin and Reseller)
if (isset($_POST['change_my_password'])) {
    $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $currentPassword = isset($_POST['current_password']) ? (string)$_POST['current_password'] : '';
    $newPassword = isset($_POST['new_password']) ? (string)$_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : '';

    if ($postedToken === '' || !hash_equals($profileCsrf, $postedToken)) {
        $profileError = 'Security token expired. Reload this page and try again.';
    } elseif ($isImpersonating) {
        $profileError = 'Password changes are disabled while an administrator is viewing a reseller account.';
    } elseif (strlen($newPassword) < 4 || strlen($newPassword) > 256 || preg_match('/[\r\n]/', $newPassword)) {
        $profileError = 'New password must be 4–256 characters and cannot contain line breaks.';
    } elseif (!hash_equals($newPassword, $confirmPassword)) {
        $profileError = 'New password and confirmation do not match.';
    } else {
        $currentPasswordValid = false;
        if ($isResellerProfile) {
            $currentPasswordValid = is_array($profileRecord) && verifyResellerPassword($profileRecord, $currentPassword);
        } else {
            $adminRecord = getCurrentAdminRecord();
            $currentPasswordValid = (is_array($adminRecord) && verifyAdminUserPassword($adminRecord, $currentPassword)) ||
                (isset($passadm) && hash_equals((string)decrypt($passadm), $currentPassword)) ||
                ($profileUsername === 'mikhmon' && $currentPassword === '1234');
        }

        if (!$currentPasswordValid) {
            $profileError = 'Current password is incorrect.';
        } else {
            $saved = false;
            if ($isResellerProfile) {
                $allResellers = loadResellers(true);
                foreach ($allResellers as $idx => $row) {
                    if ((string)$row['id'] === $profileId && (string)$row['username'] === $profileUsername) {
                        $allResellers[$idx]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                        $allResellers[$idx]['password_enc'] = encrypt($newPassword);
                        $saved = saveResellers($allResellers);
                        break;
                    }
                }
            } else {
                $allAdmins = loadAdminUsers(true);
                foreach ($allAdmins as $idx => $adm) {
                    if ((string)$adm['id'] === $profileId) {
                        $allAdmins[$idx]['password_enc'] = encrypt($newPassword);
                        $allAdmins[$idx]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                        $allAdmins[$idx]['updated_at'] = date('Y-m-d H:i:s');
                        $saved = saveAdminUsers($allAdmins);
                        break;
                    }
                }
            }

            if ($saved) {
                $profileSavedMsg = 'Password changed successfully.';
                $_SESSION['ngw_profile_csrf'] = bin2hex(random_bytes(32));
                $profileCsrf = (string)$_SESSION['ngw_profile_csrf'];
            } else {
                $profileError = 'Password could not be saved. Check storage permissions and try again.';
            }
        }
    }
}

$currentAvatarUrl = getCurrentUserAvatarUrl();
?>
<style>
.ngw-profile-wrap {
  max-width: 840px;
  margin: 16px auto;
}
.ngw-profile-card {
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 16px;
}
.ngw-profile-hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
  padding: 18px 20px;
  background: linear-gradient(125deg, rgba(31, 111, 235, 0.22), rgba(46, 160, 67, 0.12));
  border-bottom: 1px solid rgba(128, 128, 128, 0.2);
}
.ngw-profile-hero-left {
  display: flex;
  align-items: center;
  gap: 16px;
}
.ngw-profile-avatar-lg {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(31, 111, 235, 0.22);
  border: 2px solid rgba(255, 255, 255, 0.28);
  font-size: 32px;
  flex-shrink: 0;
}
.ngw-profile-avatar-lg img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.ngw-profile-role-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  border-radius: 14px;
  font-size: 11px;
  font-weight: 700;
  color: #fff;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.ngw-profile-meta {
  font-size: 12px;
  opacity: 0.8;
}
.ngw-profile-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}
.ngw-profile-field {
  padding: 12px 14px;
  border: 1px solid rgba(128, 128, 128, 0.24);
  border-radius: 8px;
  min-width: 0;
  background: rgba(0, 0, 0, 0.08);
}
.ngw-profile-field small {
  display: block;
  opacity: 0.72;
  font-size: 11px;
  margin-bottom: 5px;
}
.ngw-profile-field strong {
  font-size: 14px;
  overflow-wrap: anywhere;
}
.ngw-profile-two-col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.ngw-pw-input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.ngw-pw-input-wrap .form-control {
  padding-right: 40px;
  width: 100%;
}
.ngw-pw-toggle-btn {
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  background: transparent;
  border: none;
  color: inherit;
  opacity: 0.75;
  cursor: pointer;
  padding: 4px 8px;
  font-size: 14px;
}
.ngw-pw-toggle-btn:hover {
  opacity: 1;
}
.ngw-avatar-upload-box {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  padding: 10px;
  border: 1px dashed rgba(128, 128, 128, 0.38);
  border-radius: 8px;
  margin-bottom: 12px;
}
@media (max-width: 768px) {
  .ngw-profile-wrap {
    margin: 4px auto;
  }
  .ngw-profile-card {
    margin-bottom: 8px;
  }
  .ngw-profile-hero {
    padding: 10px 12px;
    gap: 10px;
  }
  .ngw-profile-hero-left {
    gap: 10px;
  }
  .ngw-profile-avatar-lg {
    width: 46px;
    height: 46px;
    font-size: 24px;
  }
  .ngw-profile-grid,
  .ngw-profile-two-col {
    grid-template-columns: 1fr;
    gap: 8px;
  }
  .ngw-profile-field {
    padding: 8px 10px;
  }
}
</style>

<?php $isBn = (isset($langid) && $langid === 'bn') || (isset($_SESSION['lang']) && $_SESSION['lang'] === 'bn'); ?>
<div class="row">
  <div class="col-12">
    <div class="ngw-profile-wrap">
      <?php if ($profileSavedMsg !== '') { ?>
        <div class="bg-green pd-10 radius-3 mr-b-10" role="status">
          <i class="fa fa-check-circle"></i> <?= htmlspecialchars($profileSavedMsg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php } ?>
      <?php if ($profileError !== '') { ?>
        <div class="bg-danger pd-10 radius-3 mr-b-10" role="alert">
          <i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($profileError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php } ?>

      <!-- Account Information Card (Account Name, Login User, Account Type) -->
      <div class="card ngw-profile-card">
        <div class="ngw-profile-hero">
          <div class="ngw-profile-hero-left">
            <div class="ngw-profile-avatar-lg">
              <?php if ($currentAvatarUrl !== '') { ?>
                <img src="<?= htmlspecialchars($currentAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Avatar">
              <?php } else { ?>
                <i class="fa fa-user-circle"></i>
              <?php } ?>
            </div>
            <div>
              <h3 id="ngw-hero-display-name" style="margin:0 0 4px 0;font-size:18px;"><?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?></h3>
              <div class="ngw-profile-meta">@<span id="ngw-hero-username"><?= htmlspecialchars($profileUsername, ENT_QUOTES, 'UTF-8'); ?></span></div>
            </div>
          </div>
          <div>
            <span class="ngw-profile-role-pill" style="background:<?= $isResellerProfile ? '#2ea043' : '#1f6feb'; ?>;">
              <i class="fa <?= $isResellerProfile ? 'fa-id-badge' : 'fa-shield'; ?>"></i>
              <?= htmlspecialchars($isBn ? ($isResellerProfile ? 'রিসেলার' : 'অ্যাডমিনিস্ট্রেটর') : $profileRole, ENT_QUOTES, 'UTF-8'); ?>
            </span>
          </div>
        </div>
        <div class="card-body">
          <?php if (!$isResellerProfile) { ?>
            <form method="post" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($profileCsrf, ENT_QUOTES, 'UTF-8'); ?>">
              <div class="ngw-profile-grid">
                <div class="ngw-profile-field">
                  <small><i class="fa fa-user"></i> <?= $isBn ? 'অ্যাকাউন্টের নাম' : 'Account Name'; ?></small>
                  <input class="form-control" type="text" name="admin_name" maxlength="80" value="<?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="ngw-profile-field">
                  <small><i class="fa fa-at"></i> <?= $isBn ? 'লগইন ইউজার' : 'Login User'; ?></small>
                  <input class="form-control" type="text" name="admin_username" maxlength="64" value="<?= htmlspecialchars($profileUsername, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="ngw-profile-field">
                  <small><i class="fa fa-id-card-o"></i> <?= $isBn ? 'অ্যাকাউন্টের ধরন' : 'Account Type'; ?></small>
                  <strong style="display:block;padding-top:6px;"><?= htmlspecialchars($isBn ? 'অ্যাডমিনিস্ট্রেটর' : $profileRole, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
              </div>
              <div style="margin-top:10px;text-align:right;">
                <button class="btn bg-primary" type="submit" name="save_admin_info">
                  <i class="fa fa-save"></i> <?= $isBn ? 'অ্যাকাউন্ট তথ্য সংরক্ষণ করুন' : 'Save Account Info'; ?>
                </button>
              </div>
            </form>
          <?php } else { ?>
            <div class="ngw-profile-grid">
              <div class="ngw-profile-field">
                <small><i class="fa fa-user"></i> <?= $isBn ? 'অ্যাকাউন্টের নাম' : 'Account Name'; ?></small>
                <strong><?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?></strong>
              </div>
              <div class="ngw-profile-field">
                <small><i class="fa fa-at"></i> <?= $isBn ? 'লগইন ইউজার' : 'Login User'; ?></small>
                <strong><?= htmlspecialchars($profileUsername, ENT_QUOTES, 'UTF-8'); ?></strong>
              </div>
              <div class="ngw-profile-field">
                <small><i class="fa fa-id-card-o"></i> <?= $isBn ? 'অ্যাকাউন্টের ধরন' : 'Account Type'; ?></small>
                <strong><?= htmlspecialchars($isBn ? 'রিসেলার' : $profileRole, ENT_QUOTES, 'UTF-8'); ?></strong>
              </div>
            </div>
          <?php } ?>
        </div>
      </div>

      <!-- Two-Column Self-Service Actions: Upload Avatar & Change Password -->
      <div class="ngw-profile-two-col">
        <!-- Upload Avatar -->
        <div id="profile-avatar-section" class="card ngw-profile-card">
          <div class="card-header">
            <h3><i class="fa fa-camera"></i> <?= $isBn ? 'প্রোফাইল ছবি আপলোড' : 'Upload Avatar'; ?></h3>
          </div>
          <div class="card-body">
            <div class="ngw-avatar-upload-box">
              <div class="ngw-profile-avatar-lg" style="width:54px;height:54px;font-size:26px;">
                <?php if ($currentAvatarUrl !== '') { ?>
                  <img id="ngw-avatar-live-preview" src="<?= htmlspecialchars($currentAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Current Avatar">
                <?php } else { ?>
                  <i class="fa fa-user-circle"></i>
                <?php } ?>
              </div>
              <div style="min-width:0;flex:1;">
                <div style="font-weight:600;font-size:13px;margin-bottom:2px;">
                  <?= $currentAvatarUrl !== '' ? ($isBn ? 'কাস্টম প্রোফাইল ছবি চালু আছে' : 'Custom Avatar Active') : ($isBn ? 'ডিফল্ট প্রোফাইল আইকন চালু আছে' : 'Default Avatar Active'); ?>
                </div>
                <div class="ngw-profile-meta">
                  <?= $isBn ? 'উপরে ডানপাশের প্রোফাইল আইকনে দেখানো হবে (PNG, JPG, WebP, সর্বোচ্চ ২ MB)।' : 'Shown in the top-right profile icon (PNG, JPG, WebP, max 2 MB).'; ?>
                </div>
              </div>
            </div>

            <form method="post" enctype="multipart/form-data" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($profileCsrf, ENT_QUOTES, 'UTF-8'); ?>">
              <div class="form-group" style="margin-bottom:12px;">
                <label style="display:block;margin-bottom:5px;font-size:12px;"><?= $isBn ? 'প্রোফাইল ছবি নির্বাচন করুন (PNG / JPG / WebP)' : 'Select Avatar Image (PNG / JPG / WebP)'; ?></label>
                <input class="form-control" type="file" name="profile_logo_file" accept="image/png,image/jpeg,image/webp" required>
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="btn bg-primary" type="submit" name="upload_my_avatar">
                  <i class="fa fa-upload"></i> <?= $isBn ? 'ছবি আপলোড করুন' : 'Upload Avatar'; ?>
                </button>
              </div>
            </form>

            <?php if ($currentAvatarUrl !== '') { ?>
              <form method="post" style="margin-top:8px;" onsubmit="return confirm('<?= $isBn ? 'আপনার প্রোফাইল ছবি ডিফল্ট আইকনে রিসেট করতে চান?' : 'Reset your profile avatar to the default icon?'; ?>');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($profileCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="btn bg-danger" type="submit" name="remove_my_avatar">
                  <i class="fa fa-trash"></i> <?= $isBn ? 'ছবি সরান' : 'Remove Avatar'; ?>
                </button>
              </form>
            <?php } ?>
          </div>
        </div>

        <!-- Change Password Card -->
        <div id="profile-password-section" class="card ngw-profile-card">
          <div class="card-header">
            <h3><i class="fa fa-lock"></i> <?= $isBn ? 'পাসওয়ার্ড পরিবর্তন করুন' : 'Change Password'; ?></h3>
          </div>
          <div class="card-body">
            <form method="post" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($profileCsrf, ENT_QUOTES, 'UTF-8'); ?>">
              <div class="form-group" style="margin-bottom:10px;">
                <label style="display:block;margin-bottom:4px;font-size:12px;"><?= $isBn ? 'বর্তমান পাসওয়ার্ড' : 'Current Password'; ?></label>
                <div class="ngw-pw-input-wrap">
                  <input class="form-control" type="password" id="ngw_cur_pw" name="current_password" autocomplete="current-password" placeholder="<?= $isBn ? 'বর্তমান পাসওয়ার্ড লিখুন' : 'Enter current password'; ?>" required>
                  <button type="button" class="ngw-pw-toggle-btn" data-target="ngw_cur_pw" title="Show / Hide Password" aria-label="Show or hide current password">
                    <i class="fa fa-eye"></i>
                  </button>
                </div>
              </div>
              <div class="form-group" style="margin-bottom:10px;">
                <label style="display:block;margin-bottom:4px;font-size:12px;"><?= $isBn ? 'নতুন পাসওয়ার্ড (৪+ অক্ষর)' : 'New Password (4+ characters)'; ?></label>
                <div class="ngw-pw-input-wrap">
                  <input class="form-control" type="password" id="ngw_new_pw" name="new_password" minlength="4" maxlength="256" autocomplete="new-password" placeholder="<?= $isBn ? 'নতুন পাসওয়ার্ড লিখুন' : 'Enter new password'; ?>" required>
                  <button type="button" class="ngw-pw-toggle-btn" data-target="ngw_new_pw" title="Show / Hide Password" aria-label="Show or hide new password">
                    <i class="fa fa-eye"></i>
                  </button>
                </div>
              </div>
              <div class="form-group" style="margin-bottom:12px;">
                <label style="display:block;margin-bottom:4px;font-size:12px;"><?= $isBn ? 'নতুন পাসওয়ার্ড নিশ্চিত করুন' : 'Confirm New Password'; ?></label>
                <div class="ngw-pw-input-wrap">
                  <input class="form-control" type="password" id="ngw_cfm_pw" name="confirm_password" minlength="4" maxlength="256" autocomplete="new-password" placeholder="<?= $isBn ? 'পুনরায় নতুন পাসওয়ার্ড লিখুন' : 'Re-enter new password'; ?>" required>
                  <button type="button" class="ngw-pw-toggle-btn" data-target="ngw_cfm_pw" title="Show / Hide Password" aria-label="Show or hide confirm password">
                    <i class="fa fa-eye"></i>
                  </button>
                </div>
              </div>
              <button class="btn bg-primary" type="submit" name="change_my_password">
                <i class="fa fa-check"></i> <?= $isBn ? 'পাসওয়ার্ড পরিবর্তন করুন' : 'Change Password'; ?>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var btns = document.querySelectorAll('.ngw-pw-toggle-btn');
  for (var i = 0; i < btns.length; i++) {
    btns[i].addEventListener('click', function () {
      var targetId = this.getAttribute('data-target');
      var input = document.getElementById(targetId);
      if (!input) return;
      var icon = this.querySelector('i');
      if (input.type === 'password') {
        input.type = 'text';
        if (icon) {
          icon.classList.remove('fa-eye');
          icon.classList.add('fa-eye-slash');
        }
      } else {
        input.type = 'password';
        if (icon) {
          icon.classList.remove('fa-eye-slash');
          icon.classList.add('fa-eye');
        }
      }
    });
  }

  <?php if ($profileSavedMsg !== '') { ?>
  // Synchronize top-right navbar avatar & name immediately after update
  var newAvatarUrl = <?= json_encode($currentAvatarUrl); ?>;
  var newDisplayName = <?= json_encode($profileName); ?>;
  var newUsername = <?= json_encode($profileUsername); ?>;
  var avatarHolders = document.querySelectorAll('.ngw-profile-trigger-avatar, .ngw-profile-dd-avatar');
  for (var j = 0; j < avatarHolders.length; j++) {
    if (newAvatarUrl) {
      avatarHolders[j].innerHTML = '<img src="' + newAvatarUrl.replace(/"/g, '&quot;') + '" alt="Avatar">';
    } else {
      avatarHolders[j].innerHTML = '<i class="fa fa-user-circle"></i>';
    }
  }
  var nameHolders = document.querySelectorAll('.ngw-profile-trigger-name, .ngw-profile-dd-title');
  for (var k = 0; k < nameHolders.length; k++) {
    nameHolders[k].textContent = newDisplayName;
  }
  var subHolders = document.querySelectorAll('.ngw-profile-dd-sub');
  for (var m = 0; m < subHolders.length; m++) {
    subHolders[m].textContent = '@' + newUsername;
  }
  <?php } ?>
})();
</script>

