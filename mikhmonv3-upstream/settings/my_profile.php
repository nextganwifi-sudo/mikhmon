<?php
/**
 * Self-service account profile. Account identity is read-only; only the
 * authenticated account's password can be changed here.
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
$profileId = 'Admin Account';

if ($isResellerProfile) {
    $profileRecord = getCurrentResellerRecord();
    if (!is_array($profileRecord) || $isImpersonating) {
        $profileError = $isImpersonating
            ? 'Password changes are disabled while an administrator is viewing a reseller account.'
            : 'The reseller account could not be loaded. Please sign in again.';
    } else {
        $profileName = isset($profileRecord['name']) ? (string)$profileRecord['name'] : $profileUsername;
        $profileId = isset($profileRecord['id']) ? (string)$profileRecord['id'] : '';
    }
} else {
    $backupProfile = getBackupAdminCredentials();
    $profileIsBackupAdmin = !empty($backupProfile['username']) && $profileUsername === $backupProfile['username']
        && $profileUsername !== (string)$useradm;
    $profileName = $profileIsBackupAdmin ? 'Backup Administrator' : 'Administrator';
    $profileId = 'Next Gyan WiFi Solution';
}

if (empty($_SESSION['ngw_profile_csrf'])) {
    $_SESSION['ngw_profile_csrf'] = bin2hex(random_bytes(32));
}
$profileCsrf = (string)$_SESSION['ngw_profile_csrf'];
$profileSaved = false;
$profileError = isset($profileError) ? $profileError : '';

if (isset($_POST['change_my_password'])) {
    $postedToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $currentPassword = isset($_POST['current_password']) ? (string)$_POST['current_password'] : '';
    $newPassword = isset($_POST['new_password']) ? (string)$_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : '';

    if ($postedToken === '' || !hash_equals($profileCsrf, $postedToken)) {
        $profileError = 'Security token expired. Reload this page and try again.';
    } elseif ($profileError !== '') {
        // Preserve the account-safety restriction above.
    } elseif (strlen($newPassword) < 8 || strlen($newPassword) > 256 || preg_match('/[\r\n]/', $newPassword)) {
        $profileError = 'New password must be 8–256 characters and cannot contain line breaks.';
    } elseif (!hash_equals($newPassword, $confirmPassword)) {
        $profileError = 'New password and confirmation do not match.';
    } else {
        $currentPasswordValid = false;
        if ($isResellerProfile) {
            $currentPasswordValid = is_array($profileRecord) && verifyResellerPassword($profileRecord, $currentPassword);
        } elseif ($profileIsBackupAdmin) {
            $currentPasswordValid = !empty($backupProfile['password_hash'])
                ? password_verify($currentPassword, $backupProfile['password_hash'])
                : (!empty($backupProfile['password']) && hash_equals((string)$backupProfile['password'], $currentPassword));
        } else {
            $currentPasswordValid = isset($passadm) && hash_equals((string)decrypt($passadm), $currentPassword);
        }

        if (!$currentPasswordValid) {
            $profileError = 'Current password is incorrect.';
        } else {
            $saved = false;
            if ($isResellerProfile) {
                $allResellers = loadResellers();
                foreach ($allResellers as $idx => $row) {
                    if ((string)$row['id'] === $profileId && (string)$row['username'] === $profileUsername) {
                        $allResellers[$idx]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                        $allResellers[$idx]['password_enc'] = encrypt($newPassword);
                        $saved = saveResellers($allResellers);
                        break;
                    }
                }
            } elseif ($profileIsBackupAdmin) {
                $saved = saveBackupAdminCredentials($profileUsername, $newPassword);
            } else {
                $configPath = __DIR__ . '/../include/config.php';
                $configContent = @file_get_contents($configPath);
                $encryptedPassword = encrypt($newPassword);
                if ($configContent !== false && is_string($encryptedPassword) && $encryptedPassword !== '') {
                    $replacement = "\$data['mikhmon'] = array ('1'=>'mikhmon<|<" . addslashes($profileUsername) . "','mikhmon>|>" . addslashes($encryptedPassword) . "');";
                    $replaceCount = 0;
                    $newConfig = preg_replace('/^\s*\$data\[[\'"]mikhmon[\'"]\]\s*=\s*array\s*\(.*\);\s*$/m', $replacement, $configContent, 1, $replaceCount);
                    if ($newConfig !== null && $replaceCount === 1) {
                        $tmpPath = @tempnam(dirname($configPath), '.profile-config-');
                        if ($tmpPath !== false && @file_put_contents($tmpPath, $newConfig, LOCK_EX) !== false) {
                            $perms = @fileperms($configPath);
                            if ($perms !== false) @chmod($tmpPath, $perms & 0777);
                            $saved = @rename($tmpPath, $configPath);
                            if (!$saved && is_file($tmpPath)) @unlink($tmpPath);
                        } elseif ($tmpPath !== false && is_file($tmpPath)) {
                            @unlink($tmpPath);
                        }
                    }
                }
            }
            if ($saved) {
                $profileSaved = true;
                $_SESSION['ngw_profile_csrf'] = bin2hex(random_bytes(32));
                $profileCsrf = (string)$_SESSION['ngw_profile_csrf'];
            } else {
                $profileError = 'Password could not be saved. Check storage permissions and try again.';
            }
        }
    }
}
?>
<style>
.ngw-profile-card{max-width:720px;margin:18px auto;border-radius:12px;overflow:hidden}
.ngw-profile-hero{display:flex;align-items:center;gap:14px;padding:18px;background:linear-gradient(120deg,rgba(46,125,220,.18),rgba(46,204,113,.08))}
.ngw-profile-avatar{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(46,125,220,.2);font-size:22px}
.ngw-profile-meta{font-size:12px;opacity:.78}
.ngw-profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.ngw-profile-field{padding:11px;border:1px solid rgba(128,128,128,.22);border-radius:8px;min-width:0}
.ngw-profile-field small{display:block;opacity:.7;margin-bottom:4px}
.ngw-profile-field strong{overflow-wrap:anywhere}
@media(max-width:600px){.ngw-profile-grid{grid-template-columns:1fr}}
</style>
<div class="row">
 <div class="col-12">
  <div class="card ngw-profile-card">
   <div class="ngw-profile-hero">
    <div class="ngw-profile-avatar"><i class="fa fa-user-circle"></i></div>
    <div><h3 style="margin:0">My Profile</h3><div class="ngw-profile-meta">Your account information and security</div></div>
   </div>
   <div class="card-body">
    <?php if ($profileSaved) { ?><div class="bg-green pd-5 radius-3" role="status"><i class="fa fa-check-circle"></i> Password changed successfully.</div><?php } ?>
    <?php if ($profileError !== '') { ?><div class="bg-danger pd-5 radius-3" role="alert"><i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($profileError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
    <div class="ngw-profile-grid mr-b-10">
     <div class="ngw-profile-field"><small>Full Name / Account Name</small><strong><?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?></strong></div>
     <div class="ngw-profile-field"><small>Username (Read-only)</small><strong><?= htmlspecialchars($profileUsername, ENT_QUOTES, 'UTF-8'); ?></strong></div>
     <div class="ngw-profile-field"><small>Account Type</small><strong><?= htmlspecialchars($profileRole, ENT_QUOTES, 'UTF-8'); ?></strong></div>
     <div class="ngw-profile-field"><small>Account ID</small><strong><?= htmlspecialchars($profileId, ENT_QUOTES, 'UTF-8'); ?></strong></div>
    </div>
    <h3 style="font-size:15px;margin:18px 0 10px"><i class="fa fa-shield"></i> Change Password</h3>
    <form method="post" autocomplete="off">
     <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($profileCsrf, ENT_QUOTES, 'UTF-8'); ?>">
     <div class="form-group"><label>Current Password</label><input class="form-control" type="password" name="current_password" autocomplete="current-password" required></div>
     <div class="form-group"><label>New Password</label><input class="form-control" type="password" name="new_password" minlength="8" maxlength="256" autocomplete="new-password" required></div>
     <div class="form-group"><label>Confirm New Password</label><input class="form-control" type="password" name="confirm_password" minlength="8" maxlength="256" autocomplete="new-password" required></div>
     <button class="btn bg-primary" type="submit" name="change_my_password"><i class="fa fa-lock"></i> Update My Password</button>
    </form>
    <p class="ngw-profile-meta" style="margin-top:12px">Your username and account name are managed by the administrator and cannot be changed here.</p>
   </div>
  </div>
 </div>
</div>
