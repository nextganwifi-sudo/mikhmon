<?php
/*
 * NextGan WiFi Solution — UI Style, Admin UI Permissions & Per-Reseller UI Assignment Page
 *
 * Access & Isolation Rules:
 * - Super Admin: can change System Default UI ('classic' by default), configure every regular Admin's
 *   Assigned UI and UI Permissions ('modern', 'compact'), and assign UIs to any Reseller with full filtering.
 * - Regular Admin (Sub-Admin): ONLY has access when Super Admin has granted at least one extra UI
 *   ('modern' and/or 'compact'). Can ONLY switch their OWN UI and assign UIs to THEIR OWN isolated
 *   resellers (getAccessibleResellers / canCurrentAdminAccessReseller), strictly limited to Classic +
 *   their granted UI(s). Cannot change System Default UI or touch any other Admin's resellers.
 * - Resellers & non-permitted Admins: HTTP 403 Access Denied.
 */
include_once('./include/ui_settings.php');

if (!function_exists('ngwCanCurrentUserManageUi') || !ngwCanCurrentUserManageUi()) {
    http_response_code(403);
    echo "<div class='card'><div class='card-body'><div class='alert bg-danger'><i class='fa fa-ban'></i> Access Denied: UI Style management is not permitted for your account.</div></div></div>";
    return;
}

$isBnUi = (isset($langid) && $langid === 'bn');
$isSuperAdminUi = function_exists('isMikhmonAdminStore') && isMikhmonAdminStore();
$permittedUiKeys = ngwGetPermittedUiKeysForCurrentUser();
$uiCsrfToken = function_exists('ngwCsrfToken') ? ngwCsrfToken('ngw_admin_csrf') : '';
$uiStyles = ngwGetAvailableUiStyles($isBnUi);
$uiNotice = '';
$uiError = '';

$currentAdminRec = function_exists('getCurrentAdminRecord') ? getCurrentAdminRecord() : null;
$currentAdminId = (is_array($currentAdminRec) && !empty($currentAdminRec['id']))
    ? trim((string)$currentAdminRec['id'])
    : (isset($_SESSION['mikhmon_admin_id']) ? trim((string)$_SESSION['mikhmon_admin_id']) : '');

if (!empty($_SESSION['ngw_ui_settings_notice'])) {
    $uiNotice = (string)$_SESSION['ngw_ui_settings_notice'];
    unset($_SESSION['ngw_ui_settings_notice']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ngw_ui_action'])) {
    $postedCsrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (!function_exists('ngwValidateCsrf') || !ngwValidateCsrf($postedCsrf, 'ngw_admin_csrf')) {
        $uiError = $isBnUi
            ? 'সিকিউরিটি টোকেন যাচাই ব্যর্থ হয়েছে। পেজটি রিলোড করে আবার চেষ্টা করুন।'
            : 'Security token validation failed. Please reload the page and try again.';
    } else {
        $action = (string)$_POST['ngw_ui_action'];
        $currentSettings = ngwLoadUiSettings(true);

        if ($action === 'save_default_ui' || $action === 'save_super_admin_ui') {
            if (!$isSuperAdminUi) {
                http_response_code(403);
                $uiError = $isBnUi
                    ? 'শুধুমাত্র সুপার অ্যাডমিন এই অপশন পরিবর্তন করতে পারবেন।'
                    : 'Only Super Admin can change this setting.';
            } else {
                $newSuperUi = ngwSanitizeUiStyleKey(isset($_POST['default_ui']) ? $_POST['default_ui'] : 'classic', false);
                $superKey = function_exists('ngwGetCurrentSuperAdminUiKey') ? ngwGetCurrentSuperAdminUiKey() : $currentAdminId;
                ngwSetSuperAdminUi($superKey, $newSuperUi);
                if (function_exists('ngwRememberBrowserUiCookie')) {
                    ngwRememberBrowserUiCookie($newSuperUi);
                }
                if (function_exists('ngwRecordActivity')) {
                    ngwRecordActivity('ui_settings_updated', array(
                        'target' => 'Super Admin Personal UI',
                        'status' => ucfirst($newSuperUi),
                    ));
                }
                $lbl = ngwGetUiStyleLabel($newSuperUi, $isBnUi);
                $_SESSION['ngw_ui_settings_notice'] = $isBnUi
                    ? ('আপনার নিজের (সুপার অ্যাডমিন) ইউআই সফলভাবে "' . $lbl . '" এ পরিবর্তন করা হয়েছে। অন্য অ্যাডমিন ও রিসেলারদের ইউআই অপরিবর্তিত রয়েছে।')
                    : ('Your Super Admin UI has been updated to "' . $lbl . '". All Admin and Reseller UIs remain unchanged.');
                $cookieJs = function_exists('ngwGetBrowserUiCookieJs') ? ngwGetBrowserUiCookieJs($newSuperUi) : '';
                echo "<script>" . $cookieJs . "window.location='./admin.php?id=ui-settings';</script>";
                return;
            }
        } elseif ($action === 'save_admin_ui') {
            if (!$isSuperAdminUi) {
                http_response_code(403);
                $uiError = $isBnUi
                    ? 'শুধুমাত্র সুপার অ্যাডমিন অন্য অ্যাডমিনদের ইউআই পারমিশন পরিবর্তন করতে পারবেন।'
                    : 'Only Super Admin can manage Admin UI assignments and permissions.';
            } else {
                $postedAdminUi = (isset($_POST['admin_ui']) && is_array($_POST['admin_ui'])) ? $_POST['admin_ui'] : array();
                $postedAdminPerms = (isset($_POST['admin_allowed_uis']) && is_array($_POST['admin_allowed_uis'])) ? $_POST['admin_allowed_uis'] : array();
                $allAdmins = function_exists('loadAdminUsers') ? loadAdminUsers(true) : array();
                $updatedAdmins = 0;

                foreach ($allAdmins as $idx => $admRow) {
                    $rowRole = (isset($admRow['admin_role']) && $admRow['admin_role'] === 'admin') ? 'admin' : 'super_admin';
                    if ($rowRole !== 'admin') {
                        continue;
                    }
                    $aid = isset($admRow['id']) ? trim((string)$admRow['id']) : '';
                    if ($aid === '') {
                        continue;
                    }
                    $assigned = isset($postedAdminUi[$aid]) ? ngwSanitizeUiStyleKey($postedAdminUi[$aid], false) : 'classic';
                    $rawPerms = (isset($postedAdminPerms[$aid]) && is_array($postedAdminPerms[$aid])) ? $postedAdminPerms[$aid] : array();
                    $allowedList = ngwSanitizeAllowedUiList($rawPerms);

                    $currentSettings['admin_ui'][$aid] = $assigned;
                    $currentSettings['admin_allowed_uis'][$aid] = $allowedList;
                    $allAdmins[$idx]['ui_style'] = $assigned;
                    $allAdmins[$idx]['allowed_uis'] = $allowedList;
                    $updatedAdmins++;
                }

                ngwSaveUiSettings($currentSettings);
                if (function_exists('saveAdminUsers') && !empty($allAdmins)) {
                    saveAdminUsers($allAdmins);
                }
                if (function_exists('ngwRecordActivity')) {
                    ngwRecordActivity('admin_ui_permissions_updated', array(
                        'target' => 'Admin UI Permissions',
                        'status' => $updatedAdmins . ' admin(s) updated',
                    ));
                }
                $_SESSION['ngw_ui_settings_notice'] = $isBnUi
                    ? 'অ্যাডমিনদের ইউআই অ্যাসাইনমেন্ট ও পারমিশন সফলভাবে সংরক্ষণ করা হয়েছে।'
                    : 'Admin UI assignments and permissions saved successfully.';
                echo "<script>window.location='./admin.php?id=ui-settings';</script>";
                return;
            }
        } elseif ($action === 'save_my_ui') {
            if ($isSuperAdminUi || $currentAdminId === '') {
                http_response_code(403);
                $uiError = $isBnUi ? 'অবৈধ অনুরোধ।' : 'Invalid request.';
            } else {
                $chosenMyUi = ngwSanitizeUiStyleKey(isset($_POST['my_ui']) ? $_POST['my_ui'] : 'classic', false);
                if (!in_array($chosenMyUi, $permittedUiKeys, true)) {
                    http_response_code(403);
                    $uiError = $isBnUi
                        ? 'এই ইউআই স্টাইল ব্যবহারের পারমিশন আপনার অ্যাকাউন্টে অনুমোদিত নয়।'
                        : 'You do not have permission to switch to the requested UI style.';
                } else {
                    $currentSettings['admin_ui'][$currentAdminId] = $chosenMyUi;
                    ngwSaveUiSettings($currentSettings);
                    if (function_exists('ngwRememberBrowserUiCookie')) {
                        ngwRememberBrowserUiCookie($chosenMyUi);
                    }
                    if (function_exists('loadAdminUsers') && function_exists('saveAdminUsers')) {
                        $allAdmins = loadAdminUsers(true);
                        foreach ($allAdmins as $idx => $admRow) {
                            if (isset($admRow['id']) && (string)$admRow['id'] === $currentAdminId) {
                                $allAdmins[$idx]['ui_style'] = $chosenMyUi;
                                break;
                            }
                        }
                        saveAdminUsers($allAdmins);
                    }
                    $lbl = ngwGetUiStyleLabel($chosenMyUi, $isBnUi);
                    $_SESSION['ngw_ui_settings_notice'] = $isBnUi
                        ? ('আপনার ইউআই স্টাইল সফলভাবে "' . $lbl . '" এ পরিবর্তন করা হয়েছে।')
                        : ('Your UI style has been updated to "' . $lbl . '".');
                    $cookieJs = function_exists('ngwGetBrowserUiCookieJs') ? ngwGetBrowserUiCookieJs($chosenMyUi) : '';
                    echo "<script>" . $cookieJs . "window.location='./admin.php?id=ui-settings';</script>";
                    return;
                }
            }
        } elseif ($action === 'save_reseller_ui') {
            $submittedMap = (isset($_POST['reseller_ui']) && is_array($_POST['reseller_ui'])) ? $_POST['reseller_ui'] : array();
            $allResellers = function_exists('loadResellers') ? loadResellers(true) : array();
            $newResMap = (isset($currentSettings['reseller_ui']) && is_array($currentSettings['reseller_ui']))
                ? $currentSettings['reseller_ui']
                : array();

            $accessibleIds = array();
            foreach ($allResellers as $resCheck) {
                $rcId = isset($resCheck['id']) ? trim((string)$resCheck['id']) : '';
                if ($rcId !== '' && ($isSuperAdminUi || (function_exists('canCurrentAdminAccessReseller') && canCurrentAdminAccessReseller($resCheck)))) {
                    $accessibleIds[$rcId] = true;
                }
            }

            $rejectedCount = 0;
            $appliedCount = 0;

            // Reject any forged reseller IDs outside current Admin's isolation boundary
            foreach ($submittedMap as $subRid => $subVal) {
                $subRidClean = trim((string)$subRid);
                if ($subRidClean === '' || !isset($accessibleIds[$subRidClean])) {
                    $rejectedCount++;
                    continue;
                }
                $subUiClean = ngwSanitizeUiStyleKey($subVal, true);
                if ($subUiClean !== 'default' && !in_array($subUiClean, $permittedUiKeys, true)) {
                    $rejectedCount++;
                }
            }

            foreach ($allResellers as $idx => $res) {
                $rid = isset($res['id']) ? trim((string)$res['id']) : '';
                if ($rid === '' || !isset($accessibleIds[$rid])) {
                    continue;
                }
                if (!array_key_exists($rid, $submittedMap)) {
                    continue;
                }
                $chosen = ngwSanitizeUiStyleKey($submittedMap[$rid], true);
                if ($chosen !== 'default' && !in_array($chosen, $permittedUiKeys, true)) {
                    continue;
                }
                if ($chosen === 'default') {
                    unset($newResMap[$rid]);
                } else {
                    $newResMap[$rid] = $chosen;
                }
                $allResellers[$idx]['ui_style'] = $chosen;
                $appliedCount++;
            }

            if ($rejectedCount > 0 && $appliedCount === 0) {
                http_response_code(403);
                $uiError = $isBnUi
                    ? 'অননুমোদিত রিসেলার বা ইউআই স্টাইল পরিবর্তনের চেষ্টা বাতিল করা হয়েছে।'
                    : 'Unauthorized reseller or unpermitted UI style assignment was rejected.';
            } else {
                $currentSettings['reseller_ui'] = $newResMap;
                ngwSaveUiSettings($currentSettings);
                if (function_exists('saveResellers') && !empty($allResellers)) {
                    saveResellers($allResellers);
                }
                if (function_exists('ngwRecordActivity')) {
                    ngwRecordActivity('reseller_ui_assigned', array(
                        'target' => 'Reseller UI Assignments',
                        'status' => $appliedCount . ' reseller(s) saved',
                    ));
                }
                $warnSuffix = $rejectedCount > 0
                    ? ($isBnUi ? ' (কিছু অননুমোদিত অনুরোধ উপেক্ষা করা হয়েছে)' : ' (some unauthorized items were skipped)')
                    : '';
                $_SESSION['ngw_ui_settings_notice'] = ($isBnUi
                    ? 'রিসেলার ইউআই অ্যাসাইনমেন্ট সফলভাবে সংরক্ষণ করা হয়েছে।'
                    : 'Reseller UI assignments saved successfully.') . $warnSuffix;
                echo "<script>window.location='./admin.php?id=ui-settings';</script>";
                return;
            }
        } elseif ($action === 'reset_all_reseller_ui') {
            $allResellers = function_exists('loadResellers') ? loadResellers(true) : array();
            $newResMap = (isset($currentSettings['reseller_ui']) && is_array($currentSettings['reseller_ui']))
                ? $currentSettings['reseller_ui']
                : array();
            foreach ($allResellers as $idx => $res) {
                $rid = isset($res['id']) ? trim((string)$res['id']) : '';
                if ($rid === '') {
                    continue;
                }
                if (!$isSuperAdminUi && (!function_exists('canCurrentAdminAccessReseller') || !canCurrentAdminAccessReseller($res))) {
                    continue;
                }
                $allResellers[$idx]['ui_style'] = 'default';
                unset($newResMap[$rid]);
            }
            $currentSettings['reseller_ui'] = $newResMap;
            ngwSaveUiSettings($currentSettings);
            if (function_exists('saveResellers') && !empty($allResellers)) {
                saveResellers($allResellers);
            }
            $_SESSION['ngw_ui_settings_notice'] = $isBnUi
                ? 'আপনার আওতাধীন সকল রিসেলারের ইউআই ক্লাসিক ডিফল্টে রিসেট করা হয়েছে।'
                : 'All accessible resellers have been reset to Classic Default UI.';
            echo "<script>window.location='./admin.php?id=ui-settings';</script>";
            return;
        }
    }
}

$uiSettings = ngwLoadUiSettings(true);
$currentDefaultUi = 'classic';
$currentMyUi = $isSuperAdminUi
    ? (function_exists('ngwGetSuperAdminUi') ? ngwGetSuperAdminUi(ngwGetCurrentSuperAdminUiKey()) : 'classic')
    : ngwGetAdminAssignedUi($currentAdminId, $currentAdminRec);

$allAdminUsers = ($isSuperAdminUi && function_exists('loadAdminUsers')) ? loadAdminUsers(true) : array();
$regularAdmins = array();
foreach ($allAdminUsers as $admItem) {
    $rRole = (isset($admItem['admin_role']) && $admItem['admin_role'] === 'admin') ? 'admin' : 'super_admin';
    if ($rRole === 'admin') {
        $regularAdmins[] = $admItem;
    }
}

$visibleResellers = $isSuperAdminUi
    ? (function_exists('loadResellers') ? loadResellers(true) : array())
    : (function_exists('getAccessibleResellers') ? getAccessibleResellers() : array());

$customOverrideCount = 0;
$ownerFilterOptions = array();
foreach ($visibleResellers as $rItem) {
    $rid = isset($rItem['id']) ? (string)$rItem['id'] : '';
    if (ngwGetResellerAssignedUi($rid, $rItem) !== 'default') {
        $customOverrideCount++;
    }
    if ($isSuperAdminUi) {
        $cRole = isset($rItem['created_by_admin_role']) ? trim((string)$rItem['created_by_admin_role']) : '';
        $cId = isset($rItem['created_by_admin_id']) ? trim((string)$rItem['created_by_admin_id']) : '';
        $cName = isset($rItem['created_by_admin_name']) && trim((string)$rItem['created_by_admin_name']) !== ''
            ? trim((string)$rItem['created_by_admin_name'])
            : (isset($rItem['created_by_admin_username']) ? trim((string)$rItem['created_by_admin_username']) : '');
        if ($cRole === 'admin' && $cId !== '') {
            $ownerKey = 'admin:' . $cId;
            $ownerFilterOptions[$ownerKey] = ($cName !== '' ? $cName : $cId);
        } else {
            $ownerFilterOptions['super_admin'] = $isBnUi ? 'সুপার অ্যাডমিন' : 'Super Admin';
        }
    }
}
?>

<style>
.ngw-ui-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}
.ngw-ui-option-card {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border-radius: 12px;
  border: 2px solid rgba(148, 163, 184, 0.24);
  background: rgba(148, 163, 184, 0.05);
  cursor: pointer;
  transition: border-color 0.16s ease, transform 0.16s ease, box-shadow 0.16s ease, background-color 0.16s ease;
}
.ngw-ui-option-card:hover {
  border-color: #3b82f6;
  transform: translateY(-2px);
}
.ngw-ui-option-card.is-selected {
  border-color: #3b82f6;
  background: rgba(59, 130, 246, 0.1);
  box-shadow: 0 10px 24px -12px rgba(59, 130, 246, 0.45);
}
.ngw-ui-option-card input[type="radio"] {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}
.ngw-ui-wireframe {
  height: 98px;
  border-radius: 9px;
  border: 1px solid rgba(148, 163, 184, 0.22);
  background: #0f172a;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.ngw-wf-top {
  height: 16px;
  background: #1e293b;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 7px;
}
.ngw-wf-dot-row {
  display: inline-flex;
  gap: 3px;
}
.ngw-wf-dot {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: rgba(148, 163, 184, 0.55);
}
.ngw-wf-body {
  flex: 1;
  display: flex;
  padding: 0;
}
.ngw-wf-side {
  width: 26%;
  background: #141c2f;
  border-right: 1px solid rgba(255, 255, 255, 0.08);
  padding: 6px 5px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.ngw-wf-side span {
  height: 5px;
  border-radius: 3px;
  background: rgba(148, 163, 184, 0.35);
}
.ngw-wf-side span:first-child {
  background: #3b82f6;
}
.ngw-wf-content {
  flex: 1;
  padding: 7px;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 5px;
  align-content: start;
}
.ngw-wf-tile {
  height: 24px;
  border-radius: 4px;
  background: #1e293b;
  border-top: 2px solid #3b82f6;
}
.ngw-wf-wide {
  grid-column: 1 / -1;
  height: 28px;
  border-radius: 4px;
  background: #182235;
  border: 1px solid rgba(148, 163, 184, 0.14);
}
/* Classic wireframe */
.ngw-ui-wireframe.wf-classic { background: #2f353a; }
.ngw-ui-wireframe.wf-classic .ngw-wf-top { background: #3a4149; }
.ngw-ui-wireframe.wf-classic .ngw-wf-side { background: #3a4149; }
.ngw-ui-wireframe.wf-classic .ngw-wf-tile { background: #20a8d8; border-top: none; border-radius: 2px; }
.ngw-ui-wireframe.wf-classic .ngw-wf-tile:nth-child(2) { background: #4dbd74; }
.ngw-ui-wireframe.wf-classic .ngw-wf-tile:nth-child(3) { background: #f86c6b; }
/* Modern Studio wireframe */
.ngw-ui-wireframe.wf-modern .ngw-wf-body { padding: 5px; gap: 6px; }
.ngw-ui-wireframe.wf-modern .ngw-wf-side { border-radius: 7px; border: 1px solid rgba(148, 163, 184, 0.18); }
.ngw-ui-wireframe.wf-modern .ngw-wf-content { padding: 1px 2px; }
.ngw-ui-wireframe.wf-modern .ngw-wf-tile { border-radius: 6px; }
/* Nova Command wireframe (distinct diagonal grid, floating pill top dock, amber/gold + left-band bento tiles) */
.ngw-ui-wireframe.wf-compact {
  background: #0a0a0d;
  background-image: repeating-linear-gradient(-45deg, rgba(245, 158, 11, 0.10) 0 1px, transparent 1px 10px);
  padding: 5px;
  gap: 5px;
}
.ngw-ui-wireframe.wf-compact .ngw-wf-top {
  height: 14px;
  background: #131318;
  border: 1px solid rgba(245, 158, 11, 0.55);
  border-radius: 99px;
  padding: 0 7px;
}
.ngw-ui-wireframe.wf-compact .ngw-wf-dot { background: #f59e0b; }
.ngw-ui-wireframe.wf-compact .ngw-wf-body { gap: 5px; }
.ngw-ui-wireframe.wf-compact .ngw-wf-side {
  width: 24%;
  background: #111116;
  border: 1px solid rgba(255, 255, 255, 0.14);
  border-radius: 6px;
  padding: 5px 4px;
}
.ngw-ui-wireframe.wf-compact .ngw-wf-side span:first-child {
  background: #f59e0b;
  height: 7px;
  border-radius: 99px;
}
.ngw-ui-wireframe.wf-compact .ngw-wf-content { padding: 0; gap: 4px; }
.ngw-ui-wireframe.wf-compact .ngw-wf-tile {
  background: #17171f;
  border: 1px solid rgba(255, 255, 255, 0.14);
  border-top: 1px solid rgba(255, 255, 255, 0.14);
  border-left: 7px solid #f59e0b;
  border-radius: 3px;
  height: 24px;
  box-shadow: 2px 2px 0 rgba(0, 0, 0, 0.7);
}
.ngw-ui-wireframe.wf-compact .ngw-wf-tile:nth-child(2) { border-left-color: #10b981; }
.ngw-ui-wireframe.wf-compact .ngw-wf-tile:nth-child(3) { border-left-color: #f43f5e; }
.ngw-ui-wireframe.wf-compact .ngw-wf-wide {
  background: #14141b;
  border: 1px solid rgba(255, 255, 255, 0.14);
  border-left: 4px solid #f59e0b;
  border-radius: 3px;
  height: 30px;
  box-shadow: 2px 2px 0 rgba(0, 0, 0, 0.7);
}
/* Crystal Glass OS wireframe (Floating translucent glass topbar + glass button dock + frosted glass tiles) */
.ngw-ui-wireframe.wf-aurora {
  background:
    radial-gradient(circle at 18% 15%, rgba(56, 189, 248, 0.42), transparent 55%),
    radial-gradient(circle at 86% 85%, rgba(168, 85, 247, 0.36), transparent 55%),
    #081022;
  border-color: rgba(125, 211, 252, 0.48);
  padding: 6px;
  gap: 6px;
}
.ngw-ui-wireframe.wf-aurora .ngw-wf-top {
  height: 14px;
  background: rgba(255, 255, 255, 0.14);
  border: 1px solid rgba(255, 255, 255, 0.28);
  border-radius: 7px;
  padding: 0 7px;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.35);
}
.ngw-ui-wireframe.wf-aurora .ngw-wf-dot { background: #38bdf8; }
.ngw-ui-wireframe.wf-aurora .ngw-wf-body { gap: 6px; }
.ngw-ui-wireframe.wf-aurora .ngw-wf-side {
  width: 26%;
  background: rgba(255, 255, 255, 0.11);
  border: 1px solid rgba(255, 255, 255, 0.24);
  border-radius: 8px;
  padding: 5px 4px;
  gap: 4px;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.28);
}
.ngw-ui-wireframe.wf-aurora .ngw-wf-side span {
  height: 7px;
  border-radius: 4px;
  background: rgba(255, 255, 255, 0.16);
  border: 1px solid rgba(255, 255, 255, 0.22);
}
.ngw-ui-wireframe.wf-aurora .ngw-wf-side span:first-child {
  background: rgba(56, 189, 248, 0.42);
  border-color: rgba(125, 211, 252, 0.65);
}
.ngw-ui-wireframe.wf-aurora .ngw-wf-content { padding: 0; gap: 5px; }
.ngw-ui-wireframe.wf-aurora .ngw-wf-tile {
  height: 25px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.28);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.36);
}
.ngw-ui-wireframe.wf-aurora .ngw-wf-wide {
  height: 28px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.10);
  border: 1px solid rgba(125, 211, 252, 0.34);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.28);
}

.ngw-ui-opt-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.ngw-ui-opt-title {
  font-size: 14.5px;
  font-weight: 700;
}
.ngw-ui-opt-badge {
  font-size: 10.5px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 6px;
  background: rgba(59, 130, 246, 0.16);
  color: #60a5fa;
}
.ngw-ui-option-card.is-selected .ngw-ui-opt-badge {
  background: #3b82f6;
  color: #ffffff;
}
.ngw-ui-opt-desc {
  font-size: 12px;
  opacity: 0.78;
  line-height: 1.45;
}
.ngw-ui-res-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}
.ngw-ui-filter-group {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  flex: 1;
}
.ngw-ui-eff-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 7px;
  font-size: 11.5px;
  font-weight: 600;
  background: rgba(148, 163, 184, 0.14);
}
.ngw-ui-eff-tag.is-custom {
  background: rgba(16, 185, 129, 0.16);
  color: #10b981;
}
.ngw-ui-owner-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  background: rgba(59, 130, 246, 0.14);
  color: #60a5fa;
}
.ngw-ui-owner-pill.is-super {
  background: rgba(139, 92, 246, 0.16);
  color: #c4b5fd;
}
.ngw-ui-perm-check-wrap {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.ngw-ui-perm-check {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  margin: 0;
  padding: 4px 10px;
  border-radius: 6px;
  border: 1px solid rgba(148, 163, 184, 0.28);
  background: rgba(148, 163, 184, 0.06);
}
@media screen and (max-width: 767px) {
  .ngw-ui-grid {
    grid-template-columns: 1fr !important;
    gap: 10px !important;
  }
  .ngw-ui-res-toolbar {
    flex-direction: column;
    align-items: stretch;
  }
  .ngw-ui-filter-group {
    flex-direction: column;
    align-items: stretch;
    width: 100%;
  }
  .ngw-ui-filter-group .form-control,
  .ngw-ui-res-toolbar > input.form-control {
    max-width: 100% !important;
    width: 100% !important;
  }
}
</style>

<div class="row">
  <div class="col-12">
    <?php if ($uiNotice !== '') { ?>
      <div class="alert bg-success" style="margin: 8px 6px 10px;">
        <i class="fa fa-check-circle"></i> <?= htmlspecialchars($uiNotice, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php } ?>
    <?php if ($uiError !== '') { ?>
      <div class="alert bg-danger" style="margin: 8px 6px 10px;">
        <i class="fa fa-ban"></i> <?= htmlspecialchars($uiError, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php } ?>

    <!-- Card 1: Super Admin = My Personal UI Style | Permitted Sub-Admin = My UI Style -->
    <div class="card">
      <div class="card-header" style="justify-content: space-between;">
        <h3>
          <i class="fa fa-paint-brush"></i>
          <?php if ($isSuperAdminUi) { ?>
            <?= $isBnUi ? 'আমার নিজের ইউআই স্টাইল (সুপার অ্যাডমিন)' : 'My Personal UI Style (Super Admin)'; ?>
          <?php } else { ?>
            <?= $isBnUi ? 'আমার ইউআই স্টাইল পরিবর্তন' : 'My UI Style Selection'; ?>
          <?php } ?>
        </h3>
        <span style="font-size: 12px; opacity: 0.78;">
          <?= $isSuperAdminUi
            ? ($isBnUi ? 'আপনার চালু ইউআই:' : 'Your Active UI:')
            : ($isBnUi ? 'আপনার চালু ইউআই:' : 'Your Active UI:'); ?>
          <b><?= htmlspecialchars(ngwGetUiStyleLabel($currentMyUi, $isBnUi), ENT_QUOTES, 'UTF-8'); ?></b>
        </span>
      </div>
      <div class="card-body">
        <p style="margin-top: 0; margin-bottom: 14px; font-size: 13px; opacity: 0.82;">
          <?php if ($isSuperAdminUi) { ?>
            <?= $isBnUi
              ? 'সুপার অ্যাডমিন হিসেবে এখানে আপনার নিজের ইউআই পরিবর্তন করলে অন্য কোনো অ্যাডমিন বা রিসেলারের ইউআই পরিবর্তন হবে না। বাই ডিফল্ট সবার ক্লাসিক ইউআই থাকবে এবং নিচে যাকে যে ইউআই অ্যাসাইন করবেন তার অ্যাকাউন্টে সেটিই দেখাবে।'
              : 'Changing your UI here only affects your own Super Admin view — it never changes any Admin or Reseller UI. Classic UI is the default for everyone unless explicitly assigned below.'; ?>
          <?php } else { ?>
            <?= $isBnUi
              ? 'সুপার অ্যাডমিন আপনাকে নিচের ইউআই স্টাইলগুলোর পারমিশন দিয়েছেন। এখান থেকে আপনি নিজের ইউআই পরিবর্তন করতে এবং নিচে শুধুমাত্র আপনার নিজস্ব রিসেলারদের ইউআই অ্যাসাইন করতে পারবেন।'
              : 'Super Admin has granted you access to the UI styles below. You can switch your own UI here and assign these permitted UIs to your own resellers below.'; ?>
          <?php } ?>
        </p>

        <form method="post" action="./admin.php?id=ui-settings" id="ngwDefaultUiForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($uiCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="ngw_ui_action" value="<?= $isSuperAdminUi ? 'save_default_ui' : 'save_my_ui'; ?>">

          <div class="ngw-ui-grid">
            <?php foreach ($uiStyles as $sKey => $sMeta) {
              if (!$isSuperAdminUi && !in_array($sKey, $permittedUiKeys, true)) {
                  continue;
              }
              $isSelected = ($currentMyUi === $sKey);
              $radioName = $isSuperAdminUi ? 'default_ui' : 'my_ui';
            ?>
            <label class="ngw-ui-option-card <?= $isSelected ? 'is-selected' : ''; ?>" data-ui-card="<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="radio" name="<?= htmlspecialchars($radioName, ENT_QUOTES, 'UTF-8'); ?>" value="<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>" <?= $isSelected ? 'checked' : ''; ?>>
              <div class="ngw-ui-wireframe wf-<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true">
                <div class="ngw-wf-top">
                  <span class="ngw-wf-dot-row"><span class="ngw-wf-dot"></span><span class="ngw-wf-dot"></span><span class="ngw-wf-dot"></span></span>
                  <span class="ngw-wf-dot" style="width:18px;border-radius:3px;"></span>
                </div>
                <div class="ngw-wf-body">
                  <div class="ngw-wf-side"><span></span><span></span><span></span><span></span></div>
                  <div class="ngw-wf-content">
                    <div class="ngw-wf-tile"></div>
                    <div class="ngw-wf-tile"></div>
                    <div class="ngw-wf-tile"></div>
                    <div class="ngw-wf-wide"></div>
                  </div>
                </div>
              </div>
              <div class="ngw-ui-opt-head">
                <span class="ngw-ui-opt-title"><?= htmlspecialchars($sMeta['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="ngw-ui-opt-badge"><?= $isSelected ? ($isBnUi ? 'চালু আছে' : 'Active') : htmlspecialchars($sMeta['badge'], ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
              <div class="ngw-ui-opt-desc"><?= htmlspecialchars($sMeta['tagline'], ENT_QUOTES, 'UTF-8'); ?></div>
            </label>
            <?php } ?>
          </div>

          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <button type="submit" class="btn bg-primary">
              <i class="fa fa-save"></i>
              <?= $isSuperAdminUi
                ? ($isBnUi ? 'আমার ইউআই সেভ করুন' : 'Save My Super Admin UI')
                : ($isBnUi ? 'আমার ইউআই সেভ করুন' : 'Save My UI Style'); ?>
            </button>
          </div>
        </form>
      </div>
    </div>

    <?php if ($isSuperAdminUi) { ?>
    <!-- Card 2 (Super Admin Only): Admin UI Assignment & UI Permissions -->
    <div class="card">
      <div class="card-header" style="justify-content: space-between;">
        <h3>
          <i class="fa fa-user-secret"></i>
          <?= $isBnUi ? 'অ্যাডমিন ইউআই অ্যাসাইন ও পারমিশন' : 'Admin UI Assignment &amp; Permissions'; ?>
        </h3>
        <span style="font-size: 12px; opacity: 0.78;">
          <?= $isBnUi ? ('মোট অ্যাডমিন: ' . count($regularAdmins)) : ('Regular Admins: ' . count($regularAdmins)); ?>
        </span>
      </div>
      <div class="card-body">
        <?php if (empty($regularAdmins)) { ?>
          <p style="margin:0; opacity:0.78;">
            <?= $isBnUi ? 'এখনো কোনো সাধারণ অ্যাডমিন তৈরি করা হয়নি।' : 'No regular Admin accounts exist yet.'; ?>
          </p>
        <?php } else { ?>
          <p style="margin-top:0; margin-bottom:12px; font-size:12.5px; opacity:0.8;">
            <?= $isBnUi
              ? 'প্রতিটি অ্যাডমিনের বাই ডিফল্ট ক্লাসিক ইউআই থাকবে। নিচে মডার্ন বা নোভা কমান্ড পারমিশন চালু করলে সেই অ্যাডমিন কেবল অনুমোদিত ইউআইগুলোর মধ্যে নিজে পরিবর্তন করতে এবং তার নিজস্ব রিসেলারদের অ্যাসাইন করতে পারবে।'
              : 'Every regular Admin defaults to Classic UI. Grant Modern Studio and/or Nova Command permissions below to let an Admin switch their own UI and assign those UIs only to their own isolated resellers.'; ?>
          </p>
          <form method="post" action="./admin.php?id=ui-settings" id="ngwAdminUiPermForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($uiCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="ngw_ui_action" value="save_admin_ui">

            <div class="ngw-ui-res-toolbar">
              <input type="search" id="ngwUiAdminSearch" class="form-control" style="max-width:260px;" placeholder="<?= $isBnUi ? 'অ্যাডমিনের নাম বা ইউজারনেম খুঁজুন...' : 'Search admin name or username...'; ?>">
              <button type="submit" class="btn bg-primary">
                <i class="fa fa-save"></i> <?= $isBnUi ? 'অ্যাডমিন ইউআই পারমিশন সেভ করুন' : 'Save Admin UI Permissions'; ?>
              </button>
            </div>

            <div class="overflow ngw-mobile-card-wrap">
              <table class="table table-bordered table-hover ngw-mobile-card-table" id="ngwUiAdminTable">
                <thead>
                  <tr>
                    <th style="width:45px;">#</th>
                    <th><?= $isBnUi ? 'অ্যাডমিনের নাম' : 'Admin Name'; ?></th>
                    <th><?= $isBnUi ? 'ইউজারনেম' : 'Username'; ?></th>
                    <th style="min-width:200px;"><?= $isBnUi ? 'নির্ধারিত ইউআই' : 'Assigned UI'; ?></th>
                    <th style="min-width:280px;"><?= $isBnUi ? 'অনুমোদিত ইউআই পারমিশন (ক্লাসিক সর্বদা চালু)' : 'Granted UI Permissions (Classic Always On)'; ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($regularAdmins as $ai => $admRow) {
                    $aid = isset($admRow['id']) ? (string)$admRow['id'] : '';
                    $aName = !empty($admRow['name']) ? (string)$admRow['name'] : (string)$admRow['username'];
                    $aUser = isset($admRow['username']) ? (string)$admRow['username'] : '';
                    $aAssigned = ngwGetAdminAssignedUi($aid, $admRow);
                    $aAllowed = ngwGetAdminAllowedUis($aid, $admRow);
                  ?>
                  <tr class="ngw-ui-adm-row" data-search="<?= htmlspecialchars(strtolower($aName . ' ' . $aUser . ' ' . $aid), ENT_QUOTES, 'UTF-8'); ?>">
                    <td class="ngw-mc-hide-mobile"><?= ($ai + 1); ?></td>
                    <td class="ngw-mc-head" data-label="<?= htmlspecialchars($isBnUi ? 'অ্যাডমিনের নাম' : 'Admin Name', ENT_QUOTES, 'UTF-8'); ?>"><b><?= htmlspecialchars($aName, ENT_QUOTES, 'UTF-8'); ?></b></td>
                    <td data-label="<?= htmlspecialchars($isBnUi ? 'ইউজারনেম' : 'Username', ENT_QUOTES, 'UTF-8'); ?>"><code><?= htmlspecialchars($aUser, ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td class="ngw-mc-stack" data-label="<?= htmlspecialchars($isBnUi ? 'নির্ধারিত ইউআই' : 'Assigned UI', ENT_QUOTES, 'UTF-8'); ?>">
                      <select class="form-control" name="admin_ui[<?= htmlspecialchars($aid, ENT_QUOTES, 'UTF-8'); ?>]" style="min-height:32px; padding:4px 8px;">
                        <?php foreach ($uiStyles as $sKey => $sMeta) { ?>
                          <option value="<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>" <?= $aAssigned === $sKey ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($sMeta['name'], ENT_QUOTES, 'UTF-8'); ?>
                          </option>
                        <?php } ?>
                      </select>
                    </td>
                    <td class="ngw-mc-stack" data-label="<?= htmlspecialchars($isBnUi ? 'অনুমোদিত ইউআই পারমিশন' : 'Granted UI Permissions', ENT_QUOTES, 'UTF-8'); ?>">
                      <div class="ngw-ui-perm-check-wrap">
                        <label class="ngw-ui-perm-check">
                          <input type="checkbox" name="admin_allowed_uis[<?= htmlspecialchars($aid, ENT_QUOTES, 'UTF-8'); ?>][modern]" value="1" <?= in_array('modern', $aAllowed, true) ? 'checked' : ''; ?>>
                          <span><?= htmlspecialchars($uiStyles['modern']['short'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                        <label class="ngw-ui-perm-check">
                          <input type="checkbox" name="admin_allowed_uis[<?= htmlspecialchars($aid, ENT_QUOTES, 'UTF-8'); ?>][compact]" value="1" <?= in_array('compact', $aAllowed, true) ? 'checked' : ''; ?>>
                          <span><?= htmlspecialchars($uiStyles['compact']['short'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                        <label class="ngw-ui-perm-check">
                          <input type="checkbox" name="admin_allowed_uis[<?= htmlspecialchars($aid, ENT_QUOTES, 'UTF-8'); ?>][aurora]" value="1" <?= in_array('aurora', $aAllowed, true) ? 'checked' : ''; ?>>
                          <span><?= htmlspecialchars($uiStyles['aurora']['short'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                      </div>
                    </td>
                  </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          </form>
        <?php } ?>
      </div>
    </div>
    <?php } ?>

    <!-- Card 3: Per-Reseller UI Assignment (Super Admin = All with Owner Filter | Sub-Admin = Isolated Own Resellers Only) -->
    <div class="card">
      <div class="card-header" style="justify-content: space-between;">
        <h3>
          <i class="fa fa-users"></i>
          <?= $isSuperAdminUi
            ? ($isBnUi ? 'নির্দিষ্ট রিসেলারের জন্য ইউআই অ্যাসাইনমেন্ট (ফিল্টারিং সহ)' : 'Per-Reseller UI Assignment')
            : ($isBnUi ? 'আমার রিসেলারদের ইউআই অ্যাসাইনমেন্ট' : 'My Resellers UI Assignment'); ?>
        </h3>
        <span style="font-size: 12px; opacity: 0.78;" id="ngwUiResellerCountLabel">
          <?= $isBnUi
            ? ('মোট রিসেলার: ' . count($visibleResellers) . ' | কাস্টম ইউআই: ' . $customOverrideCount)
            : ('Total Resellers: ' . count($visibleResellers) . ' | Custom Assigned: ' . $customOverrideCount); ?>
        </span>
      </div>
      <div class="card-body">
        <?php if (empty($visibleResellers)) { ?>
          <p style="margin: 0; opacity: 0.78;">
            <?= $isBnUi ? 'আপনার আওতায় এখনো কোনো রিসেলার তৈরি করা হয়নি।' : 'No resellers found under your account yet.'; ?>
          </p>
        <?php } else { ?>
          <form method="post" action="./admin.php?id=ui-settings" id="ngwResellerUiForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($uiCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="ngw_ui_action" value="save_reseller_ui">

            <div class="ngw-ui-res-toolbar">
              <div class="ngw-ui-filter-group">
                <input type="search" id="ngwUiResellerSearch" class="form-control" style="max-width: 230px;" placeholder="<?= $isBnUi ? 'রিসেলারের নাম বা ইউজারনেম খুঁজুন...' : 'Search reseller name or username...'; ?>">

                <?php if ($isSuperAdminUi && count($ownerFilterOptions) > 0) { ?>
                <select id="ngwUiOwnerFilter" class="form-control" style="max-width: 195px;" aria-label="Filter by Owner">
                  <option value="all"><?= $isBnUi ? 'সব মালিক (All Owners)' : 'All Owners (Admins)'; ?></option>
                  <?php foreach ($ownerFilterOptions as $oKey => $oLabel) { ?>
                  <option value="<?= htmlspecialchars($oKey, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($oLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                  <?php } ?>
                </select>
                <?php } ?>

                <select id="ngwUiAssignedFilter" class="form-control" style="max-width: 195px;" aria-label="Filter by Assigned UI">
                  <option value="all"><?= $isBnUi ? 'সব ইউআই (All UIs)' : 'All Assigned UIs'; ?></option>
                  <option value="default"><?= $isBnUi ? 'ডিফল্ট (ক্লাসিক)' : 'Default (Classic)'; ?></option>
                  <?php foreach ($uiStyles as $sKey => $sMeta) {
                    if (!$isSuperAdminUi && !in_array($sKey, $permittedUiKeys, true)) { continue; }
                  ?>
                  <option value="<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($sMeta['short'], ENT_QUOTES, 'UTF-8'); ?></option>
                  <?php } ?>
                </select>

                <span id="ngwUiVisibleStatus" style="font-size:11.5px; opacity:0.75;"></span>
              </div>

              <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="submit" class="btn bg-success">
                  <i class="fa fa-check"></i> <?= $isBnUi ? 'রিসেলার ইউআই সেভ করুন' : 'Save Reseller Assignments'; ?>
                </button>
              </div>
            </div>

            <div class="overflow ngw-mobile-card-wrap">
              <table class="table table-bordered table-hover ngw-mobile-card-table" id="ngwUiResellerTable">
                <thead>
                  <tr>
                    <th style="width: 50px;">#</th>
                    <th><?= $isBnUi ? 'রিসেলারের নাম' : 'Reseller Name'; ?></th>
                    <th><?= $isBnUi ? 'ইউজারনেম' : 'Username'; ?></th>
                    <?php if ($isSuperAdminUi) { ?>
                    <th><?= $isBnUi ? 'মালিক (অ্যাডমিন)' : 'Owner (Admin)'; ?></th>
                    <?php } ?>
                    <th><?= $isBnUi ? 'স্ট্যাটাস' : 'Status'; ?></th>
                    <th><?= $isBnUi ? 'কার্যকর ইউআই' : 'Effective UI'; ?></th>
                    <th style="min-width: 220px;"><?= $isBnUi ? 'নির্ধারিত ইউআই' : 'Assigned UI Style'; ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($visibleResellers as $i => $res) {
                    $rid = isset($res['id']) ? (string)$res['id'] : '';
                    $rName = isset($res['name']) && $res['name'] !== '' ? (string)$res['name'] : (string)$res['username'];
                    $rUser = isset($res['username']) ? (string)$res['username'] : '';
                    $rStatus = isset($res['status']) ? (string)$res['status'] : 'active';
                    $assignedUi = ngwGetResellerAssignedUi($rid, $res);
                    $effectiveUi = ($assignedUi === 'default') ? 'classic' : $assignedUi;
                    $isCustom = ($assignedUi !== 'default');

                    $cRole = isset($res['created_by_admin_role']) ? trim((string)$res['created_by_admin_role']) : '';
                    $cId = isset($res['created_by_admin_id']) ? trim((string)$res['created_by_admin_id']) : '';
                    $cName = isset($res['created_by_admin_name']) && trim((string)$res['created_by_admin_name']) !== ''
                        ? trim((string)$res['created_by_admin_name'])
                        : (isset($res['created_by_admin_username']) ? trim((string)$res['created_by_admin_username']) : '');
                    $ownerKey = ($cRole === 'admin' && $cId !== '') ? ('admin:' . $cId) : 'super_admin';
                    $ownerDisp = ($ownerKey === 'super_admin')
                        ? ($isBnUi ? 'সুপার অ্যাডমিন' : 'Super Admin')
                        : ($cName !== '' ? $cName : $cId);
                  ?>
                  <tr class="ngw-ui-res-row"
                      data-search="<?= htmlspecialchars(strtolower($rName . ' ' . $rUser . ' ' . $rid . ' ' . $ownerDisp), ENT_QUOTES, 'UTF-8'); ?>"
                      data-owner="<?= htmlspecialchars($ownerKey, ENT_QUOTES, 'UTF-8'); ?>"
                      data-ui="<?= htmlspecialchars($assignedUi, ENT_QUOTES, 'UTF-8'); ?>">
                    <td class="ngw-mc-hide-mobile"><?= ($i + 1); ?></td>
                    <td class="ngw-mc-head" data-label="<?= htmlspecialchars($isBnUi ? 'রিসেলারের নাম' : 'Reseller Name', ENT_QUOTES, 'UTF-8'); ?>"><b><?= htmlspecialchars($rName, ENT_QUOTES, 'UTF-8'); ?></b></td>
                    <td data-label="<?= htmlspecialchars($isBnUi ? 'ইউজারনেম' : 'Username', ENT_QUOTES, 'UTF-8'); ?>"><code><?= htmlspecialchars($rUser, ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <?php if ($isSuperAdminUi) { ?>
                    <td data-label="<?= htmlspecialchars($isBnUi ? 'মালিক (অ্যাডমিন)' : 'Owner (Admin)', ENT_QUOTES, 'UTF-8'); ?>">
                      <span class="ngw-ui-owner-pill <?= $ownerKey === 'super_admin' ? 'is-super' : ''; ?>">
                        <i class="fa <?= $ownerKey === 'super_admin' ? 'fa-shield' : 'fa-user'; ?>"></i>
                        <?= htmlspecialchars($ownerDisp, ENT_QUOTES, 'UTF-8'); ?>
                      </span>
                    </td>
                    <?php } ?>
                    <td data-label="<?= htmlspecialchars($isBnUi ? 'স্ট্যাটাস' : 'Status', ENT_QUOTES, 'UTF-8'); ?>">
                      <?php if ($rStatus === 'active') { ?>
                        <span class="text-green" style="font-weight:600;"><?= $isBnUi ? 'সক্রিয়' : 'Active'; ?></span>
                      <?php } else { ?>
                        <span class="text-red" style="font-weight:600;"><?= $isBnUi ? 'নিষ্ক্রিয়' : 'Disabled'; ?></span>
                      <?php } ?>
                    </td>
                    <td data-label="<?= htmlspecialchars($isBnUi ? 'কার্যকর ইউআই' : 'Effective UI', ENT_QUOTES, 'UTF-8'); ?>">
                      <span class="ngw-ui-eff-tag <?= $isCustom ? 'is-custom' : ''; ?>">
                        <i class="fa <?= $isCustom ? 'fa-star' : 'fa-globe'; ?>"></i>
                        <?= htmlspecialchars(ngwGetUiStyleLabel($effectiveUi, $isBnUi), ENT_QUOTES, 'UTF-8'); ?>
                        <?= $isCustom ? '' : ($isBnUi ? ' (ডিফল্ট)' : ' (Default)'); ?>
                      </span>
                    </td>
                    <td class="ngw-mc-stack" data-label="<?= htmlspecialchars($isBnUi ? 'নির্ধারিত ইউআই' : 'Assigned UI Style', ENT_QUOTES, 'UTF-8'); ?>">
                      <select class="form-control" name="reseller_ui[<?= htmlspecialchars($rid, ENT_QUOTES, 'UTF-8'); ?>]" style="min-height: 32px; padding: 4px 8px;">
                        <option value="default" <?= $assignedUi === 'default' ? 'selected' : ''; ?>>
                          <?= $isBnUi ? 'ডিফল্ট (ক্লাসিক ইউআই)' : 'Default (Classic UI)'; ?>
                        </option>
                        <?php foreach ($uiStyles as $sKey => $sMeta) {
                          if (!$isSuperAdminUi && !in_array($sKey, $permittedUiKeys, true)) {
                              continue;
                          }
                        ?>
                        <option value="<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>" <?= $assignedUi === $sKey ? 'selected' : ''; ?>>
                          <?= htmlspecialchars($sMeta['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php } ?>
                      </select>
                    </td>
                  </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          </form>

          <?php if ($customOverrideCount > 0) { ?>
          <form method="post" action="./admin.php?id=ui-settings" style="margin-top: 12px;" onsubmit="return confirm(<?= htmlspecialchars(json_encode($isBnUi ? 'আপনার আওতাধীন সব রিসেলারের কাস্টম ইউআই মুছে ডিফল্টে রিসেট করবেন?' : 'Reset all accessible resellers back to Default UI?'), ENT_QUOTES, 'UTF-8'); ?>);">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($uiCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="ngw_ui_action" value="reset_all_reseller_ui">
            <button type="submit" class="btn bg-secondary">
              <i class="fa fa-undo"></i> <?= $isBnUi ? 'সব রিসেলার ডিফল্টে রিসেট করুন' : 'Reset All Resellers to Default'; ?>
            </button>
          </form>
          <?php } ?>
        <?php } ?>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var cards = document.querySelectorAll('.ngw-ui-option-card');
  for (var i = 0; i < cards.length; i++) {
    cards[i].addEventListener('click', function () {
      for (var j = 0; j < cards.length; j++) {
        cards[j].classList.remove('is-selected');
      }
      this.classList.add('is-selected');
      var radio = this.querySelector('input[type="radio"]');
      if (radio) radio.checked = true;
    });
  }

  var admSearch = document.getElementById('ngwUiAdminSearch');
  if (admSearch) {
    admSearch.addEventListener('input', function () {
      var q = this.value.trim().toLowerCase();
      var rows = document.querySelectorAll('#ngwUiAdminTable tbody tr.ngw-ui-adm-row');
      for (var a = 0; a < rows.length; a++) {
        var hay = rows[a].getAttribute('data-search') || '';
        rows[a].style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
      }
    });
  }

  var searchEl = document.getElementById('ngwUiResellerSearch');
  var ownerEl = document.getElementById('ngwUiOwnerFilter');
  var uiEl = document.getElementById('ngwUiAssignedFilter');
  var statusEl = document.getElementById('ngwUiVisibleStatus');

  function applyResellerFilters() {
    var q = searchEl ? searchEl.value.trim().toLowerCase() : '';
    var owner = ownerEl ? ownerEl.value : 'all';
    var uiF = uiEl ? uiEl.value : 'all';
    var rows = document.querySelectorAll('#ngwUiResellerTable tbody tr.ngw-ui-res-row');
    var shown = 0;
    for (var k = 0; k < rows.length; k++) {
      var hay = rows[k].getAttribute('data-search') || '';
      var rOwner = rows[k].getAttribute('data-owner') || '';
      var rUi = rows[k].getAttribute('data-ui') || 'default';
      var okQ = (!q || hay.indexOf(q) !== -1);
      var okOwner = (owner === 'all' || rOwner === owner);
      var okUi = (uiF === 'all' || rUi === uiF);
      var visible = okQ && okOwner && okUi;
      rows[k].style.display = visible ? '' : 'none';
      if (visible) shown++;
    }
    if (statusEl) {
      statusEl.textContent = (q || owner !== 'all' || uiF !== 'all')
        ? (shown + ' / ' + rows.length)
        : '';
    }
  }

  if (searchEl) searchEl.addEventListener('input', applyResellerFilters);
  if (ownerEl) ownerEl.addEventListener('change', applyResellerFilters);
  if (uiEl) uiEl.addEventListener('change', applyResellerFilters);
})();
</script>
