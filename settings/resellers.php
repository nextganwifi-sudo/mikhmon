<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *  NextGan WiFi Solution — Clean Reseller Management (Full-Width List, Three-Dot Menu, Details Modal & Popup Add/Edit)
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 */
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include_once('./include/reseller_acl.php');
include_once('./include/ui_settings.php');
$resellerCsrf = ngwCsrfToken('ngw_resellers_csrf');

if (!isMikhmonAdmin() || (!hasResellerPermission('view_resellers') && !hasResellerPermission('manage_resellers'))) {
    $thm = isset($_SESSION['theme']) && $_SESSION['theme'] !== '' ? $_SESSION['theme'] : 'dark';
    echo '<div class="row"><div class="col-6"><div class="card"><div class="card-header"><h3><i class="fa fa-ban text-danger"></i> Access Denied</h3></div><div class="card-body"><p>You do not have permission to manage Reseller accounts.</p><a class="btn bg-primary" href="./admin.php?id=sessions"><i class="fa fa-arrow-left"></i> Back</a></div></div></div></div></div></div><script src="./js/mikhmon-ui.' . htmlspecialchars($thm, ENT_QUOTES) . '.min.js"></script><script src="./js/mikhmon.js"></script></body></html>';
    exit;
}

$canManageResellers = hasResellerPermission('manage_resellers');
$canAssignResellerUi = function_exists('ngwCanCurrentUserManageUi') && ngwCanCurrentUserManageUi();
$permittedResellerUiKeys = $canAssignResellerUi ? ngwGetPermittedUiKeysForCurrentUser() : array();
$baseResellerUrl = !empty($session) ? "./?hotspot=resellers&session=" . urlencode($session) : "./admin.php?id=resellers";
$allRouters = array();
foreach (getConfiguredRouterSessions() as $cfgRouterSess) {
    if (canAccessRouterSession($cfgRouterSess)) {
        $allRouters[] = $cfgRouterSess;
    }
}
$permCatalog = getAllMikhmonPermissionCategories();
$permSectionsMeta = function_exists('getMikhmonPermissionSectionsMeta') ? getMikhmonPermissionSectionsMeta() : array();
$isBn = (isset($langid) && $langid === 'bn') || (isset($_SESSION['lang']) && $_SESSION['lang'] === 'bn');
$permGroups = loadPermissionGroups();
$allResellers = loadResellers();
$resellers = getAccessibleResellers();
$msg = "";
$activeView = isset($_GET['view']) && $_GET['view'] === 'groups' ? 'groups' : 'resellers';

// 1-Click Login as Reseller (Admin Impersonation)
if (isset($_GET['login-as-reseller']) && $_GET['login-as-reseller'] !== '') {
    if (!ngwValidateCsrf(isset($_GET['csrf']) ? (string)$_GET['csrf'] : '', 'ngw_resellers_csrf')) {
        $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> " . ($isBn ? 'সিকিউরিটি টোকেন অবৈধ। পেজটি রিলোড করে আবার চেষ্টা করুন।' : 'Security token expired or invalid. Reload and try again.') . "</div>";
    } else {
        $targetResellerId = trim((string)$_GET['login-as-reseller']);
        $targetReseller = findResellerByIdOrUsername($targetResellerId);
        if ($targetReseller && canCurrentAdminAccessReseller($targetReseller)) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            $existingImpersonator = !empty($_SESSION['mikhmon_impersonator_admin']) ? (string)$_SESSION['mikhmon_impersonator_admin'] : '';
            $existingReturnTo = !empty($_SESSION['mikhmon_impersonator_return_to']) ? (string)$_SESSION['mikhmon_impersonator_return_to'] : '';
            $adminUsername = $existingImpersonator !== '' ? $existingImpersonator : (isset($_SESSION['mikhmon']) ? $_SESSION['mikhmon'] : $useradm);
            $oldTok = isset($_SESSION['ngw_session_token']) ? (string)$_SESSION['ngw_session_token'] : '';
            ngwRegenerateAuthenticatedSession($oldTok);
            $_SESSION['mikhmon_impersonator_admin'] = $adminUsername;
            if ($existingReturnTo !== '') {
                $_SESSION['mikhmon_impersonator_return_to'] = $existingReturnTo;
            } else {
                $_SESSION['mikhmon_impersonator_return_to'] = 'resellers';
            }
            $effectivePerms = resolveEffectiveResellerPermissions($targetReseller);
            $_SESSION["mikhmon"] = $targetReseller['username'];
            $_SESSION["mikhmon_role"] = 'reseller';
            $_SESSION["mikhmon_reseller_id"] = (string)$targetReseller['id'];
            unset($_SESSION['mikhmon_admin_id'], $_SESSION['mikhmon_admin_role'], $GLOBALS['NGW_CACHE_UI_SETTINGS'], $GLOBALS['NGW_ADMIN_SYNCED']);
            $_SESSION["mikhmon_permission_mode"] = isset($targetReseller['permission_mode']) ? $targetReseller['permission_mode'] : 'Custom';
            $_SESSION["mikhmon_template_code"] = isset($targetReseller['template_code']) ? $targetReseller['template_code'] : 'NGW-001';
            $_SESSION["mikhmon_assigned_template"] = isset($targetReseller['assigned_template']) ? $targetReseller['assigned_template'] : 'default';
            $_SESSION["mikhmon_logo_filename"] = isset($targetReseller['logo_filename']) ? $targetReseller['logo_filename'] : '';
            $_SESSION["mikhmon_routers"] = isset($targetReseller['allowed_routers']) && is_array($targetReseller['allowed_routers']) ? $targetReseller['allowed_routers'] : array();
            $_SESSION["mikhmon_permissions"] = $effectivePerms;
            $_SESSION["mikhmon_last_activity"] = time();
            $_SESSION["mikhmon_login_handshake"] = 1;
            unset($_SESSION['lang'], $_SESSION['theme'], $_SESSION['lang_owner'], $_SESSION['theme_owner']);
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
                'page_label' => 'Reseller Session',
            ));
            ngwRecordActivity('reseller_impersonation', array('target' => $targetReseller['username']));
            $isResetOnly = isResetOnlyReseller();
            $allowedR = $_SESSION["mikhmon_routers"];
            session_write_close();
            if ($isResetOnly) {
                $firstR = !empty($allowedR) ? $allowedR[0] : '';
                $redir = $firstR !== '' ? './admin.php?id=reset-voucher&session=' . urlencode($firstR) : './admin.php?id=reset-voucher';
            } elseif (!empty($session) && in_array($session, $allowedR, true)) {
                $redir = getResellerDefaultFallbackUrl($session);
            } else {
                $redir = './admin.php?id=sessions';
            }
            echo "<script>try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location=" . json_encode($redir, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";</script>";
            exit;
        }
    }
}

// Toggle active / disabled
if (isset($_GET['toggle-reseller']) && $_GET['toggle-reseller'] !== '') {
    if (!$canManageResellers || !ngwValidateCsrf(isset($_GET['csrf']) ? (string)$_GET['csrf'] : '', 'ngw_resellers_csrf')) {
        $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> " . ($isBn ? 'সিকিউরিটি টোকেন অবৈধ বা পারমিশন নেই।' : 'Security token expired or permission denied.') . "</div>";
    } else {
        $tid = $_GET['toggle-reseller'];
        $statusChanged = '';
        foreach ($allResellers as $idx => $r) {
            if ((string)$r['id'] === (string)$tid && canCurrentAdminAccessReseller($r)) {
                $allResellers[$idx]['status'] = ($r['status'] === 'active') ? 'disabled' : 'active';
                $statusChanged = $allResellers[$idx]['status'];
                break;
            }
        }
        if ($statusChanged !== '') {
            saveResellers($allResellers);
            ngwRecordActivity('reseller_status_changed', array('target' => $tid, 'status' => $statusChanged));
        }
        echo "<script>window.location=" . json_encode($baseResellerUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . "</script>";
        exit;
    }
}

// Delete reseller
if (isset($_GET['remove-reseller']) && $_GET['remove-reseller'] !== '') {
    if (!$canManageResellers || !ngwValidateCsrf(isset($_GET['csrf']) ? (string)$_GET['csrf'] : '', 'ngw_resellers_csrf')) {
        $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> " . ($isBn ? 'সিকিউরিটি টোকেন অবৈধ বা পারমিশন নেই।' : 'Security token expired or permission denied.') . "</div>";
    } else {
        $rid = $_GET['remove-reseller'];
        $filtered = array();
        $deletedAny = false;
        foreach ($allResellers as $r) {
            if ((string)$r['id'] === (string)$rid && canCurrentAdminAccessReseller($r)) {
                $deletedAny = true;
                continue;
            }
            $filtered[] = $r;
        }
        if ($deletedAny) {
            saveResellers($filtered);
            ngwRecordActivity('reseller_deleted', array('target' => $rid));
        }
        echo "<script>window.location=" . json_encode($baseResellerUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . "</script>";
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !ngwValidateCsrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '', 'ngw_resellers_csrf')) {
    $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> " . ($isBn ? 'সিকিউরিটি টোকেনের মেয়াদ শেষ বা অবৈধ। পেজটি রিলোড করে আবার চেষ্টা করুন।' : 'Security token expired or invalid. Reload this page and try again.') . "</div>";
    unset($_POST['save_permission_groups'], $_POST['reset_permission_groups'], $_POST['save_reseller'], $_POST['save_reseller_routers_only']);
}

// Quick Assign Routers Only (from 3-dot menu popup)
if (isset($_POST['save_reseller_routers_only']) && $canManageResellers) {
    $qResId = isset($_POST['quick_reseller_id']) ? trim((string)$_POST['quick_reseller_id']) : '';
    $qRawRouters = isset($_POST['r_routers']) && is_array($_POST['r_routers']) ? array_values($_POST['r_routers']) : array();
    $qAssignedRouters = array();
    foreach ($qRawRouters as $rtCand) {
        $rtCand = trim((string)$rtCand);
        if ($rtCand !== '' && canAccessRouterSession($rtCand)) {
            $qAssignedRouters[] = $rtCand;
        }
    }
    $qAssignedRouters = array_values(array_unique($qAssignedRouters));
    $qUpdatedUser = '';
    if ($qResId !== '') {
        foreach ($allResellers as $idx => $r) {
            if ((string)$r['id'] === $qResId && canCurrentAdminAccessReseller($r)) {
                $allResellers[$idx]['allowed_routers'] = $qAssignedRouters;
                $qUpdatedUser = (string)$r['username'];
                break;
            }
        }
    }
    if ($qUpdatedUser !== '') {
        saveResellers($allResellers);
        ngwRecordActivity('reseller_routers_updated', array('target' => $qUpdatedUser, 'status' => count($qAssignedRouters) . ' router(s)'));
        $msg = "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> " . ($isBn
            ? ('রিসেলার <b>' . htmlspecialchars($qUpdatedUser) . '</b>-এর রাউটার অ্যাসাইনমেন্ট আপডেট হয়েছে (' . count($qAssignedRouters) . 'টি)।')
            : ('Assigned routers updated for <b>' . htmlspecialchars($qUpdatedUser) . '</b> (' . count($qAssignedRouters) . ' selected).')) . "</div>";
    } else {
        $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> " . ($isBn ? 'রিসেলার পাওয়া যায়নি বা আপনার অ্যাক্সেস নেই।' : 'Reseller not found or access denied.') . "</div>";
    }
}

// Save Permission Groups (Read / Write / Full / Reset Voucher) — Super Admin only
if (isset($_POST['save_permission_groups']) && $canManageResellers) {
    $activeView = 'groups';
    if (!isMikhmonAdminStore()) {
        $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> " . ($isBn ? 'শুধুমাত্র সুপার এডমিন গ্লোবাল পারমিশন গ্রুপ পরিবর্তন করতে পারবেন।' : 'Only Super Admin can modify global Permission Groups.') . "</div>";
    } else {
        $updatedGroups = array();
        foreach (array('Read', 'Write', 'Full', 'Reset Voucher') as $gMode) {
            $postedG = isset($_POST['grp_perms'][$gMode]) && is_array($_POST['grp_perms'][$gMode]) ? $_POST['grp_perms'][$gMode] : array();
            $gMap = getDefaultPermissionMap(false);
            foreach ($gMap as $pk => $pv) {
                $gMap[$pk] = !isProtectedAdminPermission($pk) && !empty($postedG[$pk]);
            }
            $updatedGroups[$gMode] = sanitizeResellerPermissions($gMap);
        }
        if (savePermissionGroups($updatedGroups)) {
            $permGroups = loadPermissionGroups(true);
            foreach ($allResellers as $rIdx => $rRow) {
                if (!canCurrentAdminAccessReseller($rRow)) continue;
                $rMode = isset($rRow['permission_mode']) ? $rRow['permission_mode'] : 'Custom';
                if (isset($permGroups[$rMode])) {
                    $allResellers[$rIdx]['permissions'] = $permGroups[$rMode];
                }
            }
            saveResellers($allResellers);
            ngwRecordActivity('permission_groups_updated');
            $msg = "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> " . ($isBn ? 'পারমিশন গ্রুপ সফলভাবে আপডেট হয়েছে।' : 'Permission Groups updated successfully.') . "</div>";
        }
    }
}

// Reset Permission Groups to Defaults — Super Admin only
if (isset($_POST['reset_permission_groups']) && $canManageResellers) {
    $activeView = 'groups';
    if (!isMikhmonAdminStore()) {
        $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> " . ($isBn ? 'শুধুমাত্র সুপার এডমিন গ্লোবাল পারমিশন গ্রুপ রিসেট করতে পারবেন।' : 'Only Super Admin can reset global Permission Groups.') . "</div>";
    } else {
        $defs = getDefaultPermissionGroupDefinitions();
        savePermissionGroups($defs);
        $permGroups = loadPermissionGroups(true);
        foreach ($allResellers as $rIdx => $rRow) {
            if (!canCurrentAdminAccessReseller($rRow)) continue;
            $rMode = isset($rRow['permission_mode']) ? $rRow['permission_mode'] : 'Custom';
            if (isset($permGroups[$rMode])) {
                $allResellers[$rIdx]['permissions'] = $permGroups[$rMode];
            }
        }
        saveResellers($allResellers);
        $msg = "<div class='bg-green pd-5 radius-3'><i class='fa fa-refresh'></i> " . ($isBn ? 'পারমিশন গ্রুপ ডিফল্ট সেটিংসে রিস্টোর হয়েছে।' : 'Permission Groups restored to default settings.') . "</div>";
    }
}

// Save (Create or Update) reseller
$openModalOnLoad = false;
if (isset($_POST['save_reseller']) && $canManageResellers) {
    $editId = isset($_POST['reseller_id']) ? trim($_POST['reseller_id']) : '';
    $rUser = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', trim($_POST['r_username']));
    $rName = trim($_POST['r_name']);
    $rPass = isset($_POST['r_password']) ? (string)$_POST['r_password'] : '';
    $rStatus = (isset($_POST['r_status']) && $_POST['r_status'] === 'disabled') ? 'disabled' : 'active';
    $rMode = isset($_POST['r_permission_mode']) && in_array($_POST['r_permission_mode'], array('Read', 'Write', 'Full', 'Reset Voucher', 'Custom'), true)
        ? $_POST['r_permission_mode']
        : 'Write';
    $rawRouters = isset($_POST['r_routers']) && is_array($_POST['r_routers']) ? array_values($_POST['r_routers']) : array();
    $assignedRouters = array();
    foreach ($rawRouters as $rtCandidate) {
        $rtCandidate = trim((string)$rtCandidate);
        if ($rtCandidate !== '' && canAccessRouterSession($rtCandidate)) {
            $assignedRouters[] = $rtCandidate;
        }
    }
    $postedPerms = isset($_POST['r_perms']) && is_array($_POST['r_perms']) ? $_POST['r_perms'] : array();

    $customPerms = getDefaultPermissionMap(false);
    foreach ($customPerms as $pk => $pv) {
        $customPerms[$pk] = !isProtectedAdminPermission($pk) && !empty($postedPerms[$pk]);
    }
    $customPerms = sanitizeResellerPermissions($customPerms);

    $collidesWithAdmin = (strtolower($rUser) === strtolower($useradm)) || (findAdminUserByIdOrUsername($rUser) !== null);
    if ($rUser === '' || $collidesWithAdmin) {
        $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> " . ($isBn ? 'অবৈধ রিসেলার ইউজারনেম (খালি বা অ্যাডমিন ইউজারনেমের সমান হতে পারবে না)।' : 'Invalid Reseller Username (cannot be empty or equal to an Admin username).') . "</div>";
        $openModalOnLoad = true;
    } else {
        $duplicate = false;
        foreach ($allResellers as $r) {
            if (strtolower($r['username']) === strtolower($rUser) && (string)$r['id'] !== (string)$editId) {
                $duplicate = true;
                break;
            }
        }
        if ($duplicate) {
            $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> " . ($isBn ? ('ইউজারনেম <b>' . htmlspecialchars($rUser) . '</b> আগে থেকেই রয়েছে।') : ('Username <b>' . htmlspecialchars($rUser) . '</b> already exists.')) . "</div>";
            $openModalOnLoad = true;
        } else {
            if ($editId !== '') {
                $updatedExisting = false;
                foreach ($allResellers as $idx => $r) {
                    if ((string)$r['id'] === (string)$editId && canCurrentAdminAccessReseller($r)) {
                        $allResellers[$idx]['username'] = $rUser;
                        $allResellers[$idx]['name'] = $rName !== '' ? $rName : $rUser;
                        $allResellers[$idx]['status'] = $rStatus;
                        $allResellers[$idx]['permission_mode'] = $rMode;
                        $allResellers[$idx]['allowed_routers'] = $assignedRouters;
                        if ($rMode === 'Custom') {
                            $allResellers[$idx]['permissions'] = $customPerms;
                        } else {
                            $allResellers[$idx]['permissions'] = $permGroups[$rMode];
                        }
                        if ($rPass !== '') {
                            $allResellers[$idx]['password_hash'] = password_hash($rPass, PASSWORD_DEFAULT);
                            $allResellers[$idx]['password_enc'] = encrypt($rPass);
                        }
                        if ($canAssignResellerUi && isset($_POST['r_ui_style'])) {
                            $rUiAssigned = ngwSanitizeUiStyleKey($_POST['r_ui_style'], true);
                            if ($rUiAssigned === 'default' || in_array($rUiAssigned, $permittedResellerUiKeys, true)) {
                                $allResellers[$idx]['ui_style'] = $rUiAssigned;
                                ngwSetResellerAssignedUi($editId, $rUiAssigned);
                            }
                        }
                        $updatedExisting = true;
                        break;
                    }
                }
                if ($updatedExisting) {
                    saveResellers($allResellers);
                    ngwRecordActivity('reseller_updated', array('target' => $rUser, 'status' => $rStatus));
                    ensureResellerTemplateFiles($editId);
                    $msg .= "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> " . ($isBn ? ('রিসেলার <b>' . htmlspecialchars($rUser) . '</b> সফলভাবে আপডেট হয়েছে।') : ('Reseller <b>' . htmlspecialchars($rUser) . '</b> updated successfully.')) . "</div>";
                }
            } else {
                if ($rPass === '') {
                    $rPass = '123456';
                }
                $newId = getNextResellerId($allResellers);
                $rTemplateCode = sprintf('NGW-%03d', max(1, (int)$newId - 100));
                $creatorAdm = getCurrentAdminRecord();
                $creatorAdmId = (is_array($creatorAdm) && !empty($creatorAdm['id']))
                    ? (string)$creatorAdm['id']
                    : (isset($_SESSION['mikhmon_admin_id']) ? (string)$_SESSION['mikhmon_admin_id'] : '');
                $creatorAdmUsername = (is_array($creatorAdm) && !empty($creatorAdm['username']))
                    ? (string)$creatorAdm['username']
                    : (isset($_SESSION['mikhmon']) ? (string)$_SESSION['mikhmon'] : '');
                $creatorAdmName = (is_array($creatorAdm) && !empty($creatorAdm['name']))
                    ? (string)$creatorAdm['name']
                    : $creatorAdmUsername;
                $creatorAdmRole = isMikhmonAdminStore() ? 'super_admin' : 'admin';
                $rNewUiAssigned = 'default';
                if ($canAssignResellerUi && isset($_POST['r_ui_style'])) {
                    $candUi = ngwSanitizeUiStyleKey($_POST['r_ui_style'], true);
                    if ($candUi === 'default' || in_array($candUi, $permittedResellerUiKeys, true)) {
                        $rNewUiAssigned = $candUi;
                    }
                }
                $allResellers[] = array(
                    'id' => $newId,
                    'legacy_ids' => array(),
                    'username' => $rUser,
                    'name' => $rName !== '' ? $rName : $rUser,
                    'password_hash' => password_hash($rPass, PASSWORD_DEFAULT),
                    'password_enc' => encrypt($rPass),
                    'status' => $rStatus,
                    'permission_mode' => $rMode,
                    'ui_style' => $rNewUiAssigned,
                    'template_code' => $rTemplateCode,
                    'assigned_template' => 'default',
                    'template_status' => 'active',
                    'logo_filename' => '',
                    'allowed_routers' => $assignedRouters,
                    'permissions' => ($rMode === 'Custom') ? $customPerms : $permGroups[$rMode],
                    'created_at' => date('Y-m-d H:i:s'),
                    'created_by_admin_id' => $creatorAdmId,
                    'created_by_admin_username' => $creatorAdmUsername,
                    'created_by_admin_name' => $creatorAdmName,
                    'created_by_admin_role' => $creatorAdmRole,
                );
                saveResellers($allResellers);
                if ($canAssignResellerUi && function_exists('ngwSetResellerAssignedUi')) {
                    ngwSetResellerAssignedUi($newId, $rNewUiAssigned);
                }
                ngwRecordActivity('reseller_created', array('target' => $rUser, 'status' => $rStatus));
                ensureResellerTemplateFiles($newId);
                $msg .= "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> " . ($isBn ? ('রিসেলার <b>' . htmlspecialchars($rUser) . '</b> সফলভাবে তৈরি হয়েছে।') : ('Reseller <b>' . htmlspecialchars($rUser) . '</b> created successfully.')) . "</div>";
            }
        }
    }
}

$allResellers = loadResellers();
$resellers = getAccessibleResellers();
$defaultLinkSession = !empty($session) ? $session : (!empty($allRouters) ? $allRouters[0] : 'MikroTik-v6');
$tplEditorBaseUrl = !empty($session) ? './?hotspot=template-editor' : './admin.php?id=editor';
$upLogoBaseUrl = !empty($session) ? './?hotspot=uplogo' : './admin.php?id=uplogo';

// Map router session -> Hotspot Name & Display Session Group for searchable Router Picker Popup
$routerHotspotMap = array();
$routerPickerItems = array();
$routerPickerGroups = array();
foreach ($allRouters as $rSess) {
    $hsDisplay = isset($data[$rSess][4]) && strpos((string)$data[$rSess][4], '%') !== false && explode('%', $data[$rSess][4])[1] !== ''
        ? trim((string)explode('%', $data[$rSess][4])[1])
        : $rSess;
    $dispSessName = function_exists('getRouterDisplaySessionName') ? getRouterDisplaySessionName($rSess) : $rSess;
    $grpKey = function_exists('mb_strtolower') ? mb_strtolower($dispSessName, 'UTF-8') : strtolower($dispSessName);
    $routerHotspotMap[$rSess] = $hsDisplay;
    $routerPickerItems[] = array(
        'key' => $rSess,
        'hotspot' => $hsDisplay,
        'group' => $dispSessName,
        'group_key' => $grpKey,
    );
    if (!isset($routerPickerGroups[$grpKey])) {
        $routerPickerGroups[$grpKey] = array('label' => $dispSessName, 'count' => 0);
    }
    $routerPickerGroups[$grpKey]['count']++;
}
uasort($routerPickerGroups, function ($a, $b) {
    if ($a['count'] !== $b['count']) {
        return ($b['count'] - $a['count']);
    }
    return strnatcasecmp($a['label'], $b['label']);
});

// Prepare JSON data of resellers for instant Popup Edit & Details Modal
$resellersClientMap = array();
foreach ($resellers as $r) {
    $rId = (string)$r['id'];
    $rRoutersList = isset($r['allowed_routers']) && is_array($r['allowed_routers'])
        ? array_values(array_unique(array_filter($r['allowed_routers'], 'is_string')))
        : array();
    // Display only the configured Hotspot name; keep session names internally for API routing.
    $rRouterLabels = array();
    foreach ($rRoutersList as $routerSessionName) {
        $hotspotDisplayName = isset($routerHotspotMap[$routerSessionName]) && trim((string)$routerHotspotMap[$routerSessionName]) !== ''
            ? trim((string)$routerHotspotMap[$routerSessionName])
            : 'Hotspot name unavailable';
        $rRouterLabels[] = $hotspotDisplayName;
    }
    $rEffectivePermissions = resolveEffectiveResellerPermissions($r);
    $rGrantedPermissionCount = is_array($rEffectivePermissions) ? count(array_filter($rEffectivePermissions)) : 0;
    $rAssignedVariant = isset($r['assigned_template']) ? $r['assigned_template'] : 'default';
    $rLogoUrl = resolveActiveVoucherLogo($defaultLinkSession, './', true, $rId);
    include_once('./include/ui_settings.php');
    $rUiStyleVal = function_exists('ngwGetResellerAssignedUi') ? ngwGetResellerAssignedUi($rId, $r) : 'default';
    $resellersClientMap[$rId] = array(
        'id' => $rId,
        'username' => (string)$r['username'],
        'password' => getResellerPlainPassword($r),
        'name' => (string)$r['name'],
        'status' => (string)$r['status'],
        'permission_mode' => isset($r['permission_mode']) ? (string)$r['permission_mode'] : 'Write',
        'ui_style' => $rUiStyleVal,
        'allowed_routers' => $rRoutersList,
        'allowed_router_labels' => $rRouterLabels,
        'allowed_routers_count' => count($rRouterLabels),
        'allowed_routers_text' => !empty($rRouterLabels) ? implode(', ', $rRouterLabels) : 'None',
        'granted_permissions_count' => $rGrantedPermissionCount,
        'template_code' => isset($r['template_code']) ? (string)$r['template_code'] : 'NGW-001',
        'assigned_template' => ucfirst($rAssignedVariant),
        'logo_filename' => isset($r['logo_filename']) && $r['logo_filename'] !== '' ? (string)$r['logo_filename'] : 'Default Logo',
        'logo_url' => $rLogoUrl,
        'created_at' => isset($r['created_at']) ? (string)$r['created_at'] : '-',
        'permissions' => $rEffectivePermissions,
        'tpl_url' => $tplEditorBaseUrl . '&template=' . urlencode($rAssignedVariant) . '&session=' . urlencode($defaultLinkSession) . '&reseller_id=' . urlencode($rId),
        'logo_edit_url' => $upLogoBaseUrl . '&session=' . urlencode($defaultLinkSession) . '&reseller_id=' . urlencode($rId),
    );
}
?>

<style>
.ngw-action-wrap {
    position: relative;
    display: inline-block;
}
.ngw-dots-btn {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.18);
    color: inherit;
    border-radius: 4px;
    padding: 3px 10px;
    cursor: pointer;
    font-size: 15px;
    line-height: 1.2;
}
.ngw-dots-btn:hover {
    background: #1f6feb;
    color: #fff;
}
.ngw-action-menu {
    display: none;
    position: absolute;
    left: 0;
    top: 100%;
    margin-top: 3px;
    min-width: 175px;
    background: #23282c;
    color: #f0f6fc;
    border: 1px solid #3b4248;
    border-radius: 5px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.45);
    z-index: 1050;
    text-align: left;
    padding: 4px 0;
}
.ngw-action-menu a {
    display: block;
    padding: 7px 12px;
    color: #e6edf3 !important;
    text-decoration: none;
    font-size: 13px;
    font-weight: normal;
    white-space: nowrap;
}
.ngw-action-menu a:hover {
    background: #1f6feb;
    color: #fff !important;
}
.ngw-action-menu a i {
    width: 18px;
    text-align: center;
    margin-right: 5px;
}
.ngw-modal-backdrop {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.65);
    z-index: 2000;
    overflow-y: auto;
    padding: 30px 15px;
}
.ngw-modal-box {
    max-width: 580px;
    margin: 20px auto;
    border-radius: 6px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.6);
}
.ngw-router-dd-wrap {
    position: relative;
}
.ngw-router-dd-btn {
    width: 100%;
    text-align: left;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 7px 11px;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.ngw-router-dd-btn:hover {
    border-color: #388bfd;
}
.ngw-router-trigger-left {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    min-width: 0;
    overflow: hidden;
}
.ngw-router-trigger-left span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ngw-router-trigger-action {
    flex-shrink: 0;
    font-size: 11px;
    font-weight: 700;
    opacity: 0.85;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.ngw-router-preview-list {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 6px;
}
.ngw-router-preview-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 7px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    background: rgba(56, 139, 253, 0.15);
    border: 1px solid rgba(56, 139, 253, 0.35);
    max-width: 100%;
}
.ngw-router-preview-item button {
    background: transparent;
    border: 0;
    color: inherit;
    cursor: pointer;
    padding: 0 2px;
    font-size: 12px;
    line-height: 1;
    opacity: 0.75;
}
.ngw-router-preview-item button:hover {
    opacity: 1;
    color: #f85149;
}

/* Dedicated Searchable Assign Routers Popup Modal (Works across ALL 4 UIs & 5 Themes) */
.ngw-rpicker-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(5, 9, 16, 0.72);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 3200;
    align-items: center;
    justify-content: center;
    padding: 18px 14px;
    box-sizing: border-box;
}
.ngw-rpicker-backdrop.is-open {
    display: flex;
}
.ngw-rpicker-dialog {
    width: 100%;
    max-width: 720px;
    max-height: min(86vh, 680px);
    display: flex;
    flex-direction: column;
    border-radius: 12px;
    overflow: hidden;
    background: #1b212a;
    color: #f0f6fc;
    border: 1px solid rgba(148, 163, 184, 0.28);
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.58);
    animation: ngwRpickerIn 0.16s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes ngwRpickerIn {
    from { opacity: 0; transform: translateY(8px) scale(0.985); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.ngw-rpicker-backdrop[data-theme="light"] .ngw-rpicker-dialog,
.ngw-rpicker-backdrop[data-theme="blue"] .ngw-rpicker-dialog,
.ngw-rpicker-backdrop[data-theme="green"] .ngw-rpicker-dialog,
.ngw-rpicker-backdrop[data-theme="pink"] .ngw-rpicker-dialog {
    background: #ffffff;
    color: #0f172a;
    border-color: #cbd5e1;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.24);
}
.ngw-rpicker-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 18px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.2);
    background: rgba(148, 163, 184, 0.06);
}
.ngw-rpicker-title-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.ngw-rpicker-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: rgba(37, 99, 235, 0.18);
    color: #3b82f6;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}
.ngw-rpicker-title {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.25;
}
.ngw-rpicker-sub {
    font-size: 12px;
    opacity: 0.72;
    margin-top: 2px;
    font-variant-numeric: tabular-nums;
}
.ngw-rpicker-close {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1px solid rgba(148, 163, 184, 0.26);
    background: transparent;
    color: inherit;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: background-color 0.14s ease, border-color 0.14s ease;
}
.ngw-rpicker-close:hover {
    background: rgba(239, 68, 68, 0.14);
    border-color: rgba(239, 68, 68, 0.5);
    color: #ef4444;
}
.ngw-rpicker-controls {
    padding: 12px 18px 10px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
    display: flex;
    flex-direction: column;
    gap: 9px;
}
.ngw-rpicker-search-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.ngw-rpicker-search-box {
    position: relative;
    flex: 1 1 240px;
    min-width: 180px;
}
.ngw-rpicker-search-box i.fa-search {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0.58;
    font-size: 12.5px;
    pointer-events: none;
    z-index: 2;
}
.ngw-rpicker-search-input {
    width: 100%;
    height: 36px;
    padding: 6px 30px 6px 32px !important;
    border-radius: 8px !important;
    border: 1px solid rgba(148, 163, 184, 0.34);
    background: rgba(148, 163, 184, 0.08);
    color: inherit;
    font-size: 13px;
    box-sizing: border-box;
}
.ngw-rpicker-search-input:focus {
    outline: none;
    border-color: #3b82f6;
}
.ngw-rpicker-search-clear {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    width: 22px;
    height: 22px;
    border: 0;
    border-radius: 5px;
    background: transparent;
    color: inherit;
    opacity: 0.6;
    cursor: pointer;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}
.ngw-rpicker-search-clear.is-shown {
    display: inline-flex;
}
.ngw-rpicker-search-clear:hover {
    opacity: 1;
    background: rgba(148, 163, 184, 0.18);
}
.ngw-rpicker-group-select {
    height: 36px;
    min-width: 170px;
    max-width: 230px;
    padding: 5px 10px !important;
    border-radius: 8px !important;
    border: 1px solid rgba(148, 163, 184, 0.34);
    background: rgba(148, 163, 184, 0.08);
    color: inherit;
    font-size: 12.5px;
}
.ngw-rpicker-backdrop[data-theme="dark"] .ngw-rpicker-group-select option {
    background: #1b212a;
    color: #f0f6fc;
}
.ngw-rpicker-subbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}
.ngw-rpicker-tabs {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 3px;
    border-radius: 8px;
    background: rgba(148, 163, 184, 0.12);
}
.ngw-rpicker-tab {
    border: 0;
    background: transparent;
    color: inherit;
    opacity: 0.78;
    font-size: 11.5px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
    cursor: pointer;
    white-space: nowrap;
    transition: background-color 0.14s ease, color 0.14s ease, opacity 0.14s ease;
}
.ngw-rpicker-tab:hover {
    opacity: 1;
}
.ngw-rpicker-tab.is-active {
    background: #2563eb;
    color: #ffffff;
    opacity: 1;
}
.ngw-rpicker-bulk {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.ngw-rpicker-bulk-btn {
    border: 1px solid rgba(148, 163, 184, 0.32);
    background: rgba(148, 163, 184, 0.08);
    color: inherit;
    font-size: 11.5px;
    font-weight: 600;
    padding: 4px 9px;
    border-radius: 6px;
    cursor: pointer;
    white-space: nowrap;
    transition: background-color 0.14s ease, border-color 0.14s ease;
}
.ngw-rpicker-bulk-btn:hover {
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.16);
}
.ngw-rpicker-body {
    flex: 1 1 auto;
    overflow-y: auto;
    padding: 14px 18px;
    min-height: 180px;
}
.ngw-rpicker-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 9px;
}
.ngw-rpicker-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    margin: 0 !important;
    border-radius: 9px;
    border: 1px solid rgba(148, 163, 184, 0.24);
    background: rgba(148, 163, 184, 0.05);
    cursor: pointer;
    transition: border-color 0.14s ease, background-color 0.14s ease, transform 0.12s ease;
    user-select: none;
}
.ngw-rpicker-card.is-hidden-card {
    display: none !important;
}
.ngw-rpicker-card:hover {
    border-color: rgba(59, 130, 246, 0.6);
    background: rgba(59, 130, 246, 0.08);
}
.ngw-rpicker-card.is-selected {
    border-color: #2563eb;
    background: rgba(37, 99, 235, 0.14);
}
.ngw-rpicker-card input[type="checkbox"] {
    width: 16px;
    height: 16px;
    margin: 0;
    flex-shrink: 0;
    cursor: pointer;
    accent-color: #2563eb;
}
.ngw-rpicker-card-ico {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    background: rgba(148, 163, 184, 0.14);
    color: inherit;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
}
.ngw-rpicker-card.is-selected .ngw-rpicker-card-ico {
    background: #2563eb;
    color: #ffffff;
}
.ngw-rpicker-card-meta {
    min-width: 0;
    flex: 1;
}
.ngw-rpicker-card-name {
    font-size: 13px;
    font-weight: 700;
    line-height: 1.25;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ngw-rpicker-card-group {
    font-size: 11.5px;
    opacity: 0.7;
    margin-top: 2px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ngw-rpicker-empty {
    display: none;
    text-align: center;
    padding: 36px 16px;
    opacity: 0.8;
}
.ngw-rpicker-empty.is-visible {
    display: block;
}
.ngw-rpicker-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    padding: 12px 18px;
    border-top: 1px solid rgba(148, 163, 184, 0.2);
    background: rgba(148, 163, 184, 0.05);
}
.ngw-rpicker-footer-summary {
    font-size: 12.5px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}
.ngw-rpicker-footer-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}
@media (max-width: 640px) {
    .ngw-rpicker-backdrop {
        padding: 10px 8px;
    }
    .ngw-rpicker-dialog {
        max-height: 92vh;
    }
    .ngw-rpicker-header,
    .ngw-rpicker-footer {
        padding: 10px 12px;
    }
    .ngw-rpicker-controls {
        padding: 10px 12px 8px;
    }
    .ngw-rpicker-body {
        padding: 10px 12px;
    }
    .ngw-rpicker-grid {
        grid-template-columns: 1fr;
    }
    .ngw-rpicker-group-select {
        max-width: none;
        width: 100%;
    }
}
.ngw-modal-box.is-custom-mode {
    max-width: 820px;
}

/* Redesigned Side-by-Side Permission Group & Custom Permission UI */
.ngw-pg-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    padding: 8px 10px;
    margin-bottom: 12px;
    border-radius: 6px;
    background: rgba(128,128,128,0.08);
    border: 1px solid rgba(128,128,128,0.22);
}
.ngw-pg-search-wrap {
    position: relative;
    min-width: 220px;
    flex: 1;
    max-width: 320px;
}
.ngw-pg-search-wrap i {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0.65;
    font-size: 12px;
    pointer-events: none;
}
.ngw-pg-search-input {
    width: 100%;
    padding: 6px 10px 6px 28px !important;
    font-size: 12.5px !important;
    height: 32px !important;
    border-radius: 5px !important;
    box-sizing: border-box;
}
.ngw-pg-workspace {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.ngw-pg-sidebar {
    width: 225px;
    flex-shrink: 0;
    border-radius: 6px;
    border: 1px solid rgba(128,128,128,0.24);
    background: rgba(128,128,128,0.05);
    padding: 6px;
    position: sticky;
    top: 10px;
}
.ngw-pg-sidebar-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.7;
    padding: 4px 8px 6px 8px;
    border-bottom: 1px solid rgba(128,128,128,0.18);
    margin-bottom: 5px;
}
.ngw-pg-nav-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    text-align: left;
    padding: 7px 9px;
    margin-bottom: 3px;
    border-radius: 5px;
    border: 1px solid transparent;
    background: transparent;
    color: inherit;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.14s ease;
    box-sizing: border-box;
}
.ngw-pg-nav-btn:hover {
    background: rgba(128,128,128,0.14);
}
.ngw-pg-nav-btn.active {
    background: rgba(31, 111, 235, 0.2);
    border-color: rgba(56, 139, 253, 0.55);
    color: #58a6ff;
}
.ngw-pg-nav-left {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.ngw-pg-nav-ico {
    width: 22px;
    height: 22px;
    border-radius: 5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: #fff;
    flex-shrink: 0;
}
.ngw-pg-nav-cnt {
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 10px;
    background: rgba(128,128,128,0.2);
}
.ngw-pg-content {
    flex: 1;
    min-width: 0;
}
.ngw-pg-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.ngw-pg-grid.single-col {
    grid-template-columns: 1fr;
}
.ngw-pg-sec-card {
    border-radius: 6px;
    border: 1px solid rgba(128,128,128,0.28);
    background: rgba(128,128,128,0.04);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.ngw-pg-sec-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 10px;
    border-bottom: 1px solid rgba(128,128,128,0.22);
    flex-wrap: wrap;
}
.ngw-pg-sec-title-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}
.ngw-pg-sec-icon {
    width: 26px;
    height: 26px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 13px;
    flex-shrink: 0;
}
.ngw-pg-sec-title {
    font-size: 13.5px;
    font-weight: 700;
    margin: 0;
    line-height: 1.25;
}
.ngw-pg-sec-badge {
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 10px;
    background: rgba(128,128,128,0.22);
}
.ngw-pg-sec-quick {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}
.ngw-pg-qbtn {
    padding: 2px 6px;
    font-size: 10px;
    font-weight: 700;
    border-radius: 3px;
    border: 1px solid rgba(128,128,128,0.35);
    background: rgba(128,128,128,0.12);
    color: inherit;
    cursor: pointer;
}
.ngw-pg-qbtn:hover {
    background: #1f6feb;
    border-color: #388bfd;
    color: #fff;
}
.ngw-pg-sec-body {
    padding: 8px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.ngw-pg-item-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 6px 8px;
    border-radius: 5px;
    border: 1px solid rgba(128,128,128,0.2);
    background: rgba(128,128,128,0.05);
    transition: background 0.12s ease, border-color 0.12s ease;
}
.ngw-pg-item-box:hover {
    border-color: rgba(56, 139, 253, 0.45);
    background: rgba(31, 111, 235, 0.06);
}
.ngw-pg-item-info {
    flex: 1;
    min-width: 0;
}
.ngw-pg-item-label {
    font-size: 12px;
    font-weight: 600;
    line-height: 1.35;
    display: block;
    word-break: break-word;
}
.ngw-pg-item-modes {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
    flex-wrap: wrap;
}
.ngw-pg-mode-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 6px;
    border-radius: 4px;
    font-size: 10.5px;
    font-weight: 700;
    border: 1px solid rgba(128,128,128,0.28);
    background: rgba(128,128,128,0.08);
    cursor: pointer;
    user-select: none;
    margin: 0;
    line-height: 1.1;
}
.ngw-pg-mode-pill input[type="checkbox"] {
    margin: 0;
    width: 12px;
    height: 12px;
    cursor: pointer;
}
.ngw-pg-mode-pill.is-checked.mode-Read {
    background: rgba(14, 165, 233, 0.2);
    border-color: #0ea5e9;
    color: #38bdf8;
}
.ngw-pg-mode-pill.is-checked.mode-Write {
    background: rgba(34, 197, 94, 0.2);
    border-color: #22c55e;
    color: #4ade80;
}
.ngw-pg-mode-pill.is-checked.mode-Full {
    background: rgba(139, 92, 246, 0.22);
    border-color: #8b5cf6;
    color: #c4b5fd;
}
.ngw-pg-mode-pill.is-checked.mode-Reset {
    background: rgba(245, 158, 11, 0.2);
    border-color: #f59e0b;
    color: #fcd34d;
}
.ngw-pg-mode-pill.is-disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

/* Custom Permissions Tiles inside Add/Edit Reseller Modal */
.ngw-cp-filter-bar {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.ngw-cp-cat-pill {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid rgba(128,128,128,0.28);
    background: rgba(128,128,128,0.08);
    color: inherit;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.ngw-cp-cat-pill.active {
    background: #1f6feb;
    border-color: #388bfd;
    color: #fff;
}
.ngw-cp-tiles-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px;
}
.ngw-cp-tile {
    display: flex !important;
    align-items: flex-start;
    gap: 7px;
    padding: 6px 8px;
    border-radius: 5px;
    border: 1px solid rgba(128,128,128,0.24);
    background: rgba(128,128,128,0.06);
    cursor: pointer;
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.3;
    margin: 0 !important;
    transition: all 0.12s ease;
}
.ngw-cp-tile:hover {
    border-color: rgba(56, 139, 253, 0.5);
}
.ngw-cp-tile.is-checked {
    background: rgba(31, 111, 235, 0.16);
    border-color: #388bfd;
}
.ngw-cp-tile input[type="checkbox"] {
    margin-top: 2px;
    flex-shrink: 0;
}
@media (max-width: 960px) {
    .ngw-pg-workspace {
        flex-direction: column;
    }
    .ngw-pg-sidebar {
        width: 100%;
        position: static;
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }
    .ngw-pg-sidebar-title {
        width: 100%;
    }
    .ngw-pg-nav-btn {
        width: auto;
        flex: 1 1 auto;
    }
    .ngw-pg-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 600px) {
    .ngw-pg-item-box {
        flex-direction: column;
        align-items: flex-start;
    }
    .ngw-cp-tiles-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
var NGW_RESELLERS = <?= json_encode($resellersClientMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

function closeResellerActionMenus(exceptId) {
    var allMenus = document.querySelectorAll('.ngw-action-menu');
    for (var i = 0; i < allMenus.length; i++) {
        if (!exceptId || allMenus[i].id !== exceptId) {
            allMenus[i].style.display = 'none';
        }
    }
}

function toggleActionMenu(ev, id, trigger) {
    if (ev) ev.stopPropagation();
    var targetId = 'actionMenu_' + id;
    var menu = document.getElementById(targetId);
    if (!menu) return;

    var wasOpen = menu.style.display === 'block';
    closeResellerActionMenus();
    if (wasOpen) return;

    // Render the menu at document level so table overflow/scroll containers
    // cannot clip it or push the reseller row down.
    if (menu.parentNode !== document.body) {
        document.body.appendChild(menu);
    }
    menu.classList.add('ngw-action-menu-floating');
    var page = document.querySelector('.ngw-reseller-page');
    menu.setAttribute('data-theme', page ? (page.getAttribute('data-theme') || 'dark') : 'dark');
    menu.style.display = 'block';
    menu.style.position = 'fixed';
    menu.style.zIndex = '5000';
    menu.style.visibility = 'hidden';
    menu.style.maxHeight = 'min(260px, calc(100vh - 24px))';
    menu.style.overflowY = 'auto';

    var rect = trigger ? trigger.getBoundingClientRect() : null;
    var menuRect = menu.getBoundingClientRect();
    var margin = 8;
    var left = rect ? rect.left : margin;
    left = Math.max(margin, Math.min(left, window.innerWidth - menuRect.width - margin));

    var below = rect ? window.innerHeight - rect.bottom : window.innerHeight;
    var above = rect ? rect.top : 0;
    var top = (below >= menuRect.height + 8 || below >= above)
        ? (rect ? rect.bottom + 5 : margin)
        : (rect.top - menuRect.height - 5);
    top = Math.max(margin, Math.min(top, window.innerHeight - menuRect.height - margin));

    menu.style.left = Math.round(left) + 'px';
    menu.style.top = Math.round(top) + 'px';
    menu.style.visibility = 'visible';
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.ngw-action-wrap') && !e.target.closest('.ngw-action-menu-floating')) {
        closeResellerActionMenus();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var rpModal = document.getElementById('ngwRouterPickerModal');
        if (rpModal && rpModal.classList.contains('is-open')) {
            closeRouterPickerModal();
            e.stopPropagation();
        }
    }
});

window.addEventListener('resize', function() {
    closeResellerActionMenus();
});
window.addEventListener('scroll', function() {
    closeResellerActionMenus();
}, true);

var NGW_ROUTER_PICKER_MODE = 'form';
var NGW_ROUTER_PICKER_QUICK_ID = '';
var NGW_ROUTER_PICKER_TAB = 'all';

function syncRouterHiddenInputs() {
    var hiddenBox = document.getElementById('ngwSelectedRoutersHidden');
    if (!hiddenBox) return;
    hiddenBox.innerHTML = '';
    var checks = document.querySelectorAll('.ngw-router-checkbox');
    for (var i = 0; i < checks.length; i++) {
        if (checks[i].checked) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'r_routers[]';
            inp.value = checks[i].value;
            hiddenBox.appendChild(inp);
        }
    }
}

function updateRouterDropdownLabel() {
    var checks = document.querySelectorAll('.ngw-router-checkbox');
    var selectedItems = [];
    for (var i = 0; i < checks.length; i++) {
        var card = checks[i].closest('.ngw-rpicker-card');
        if (card) {
            card.classList.toggle('is-selected', !!checks[i].checked);
        }
        if (checks[i].checked) {
            selectedItems.push({
                value: checks[i].value,
                label: checks[i].getAttribute('data-label') || checks[i].value
            });
        }
    }
    var totalCount = checks.length;
    var selCount = selectedItems.length;

    if (NGW_ROUTER_PICKER_MODE === 'form') {
        syncRouterHiddenInputs();
        var lbl = document.getElementById('routerDropdownLabel');
        if (lbl) {
            if (selCount === 0) {
                lbl.textContent = <?= json_encode(isset($_select_router_btn) ? $_select_router_btn : ($isBn ? 'রাউটার নির্বাচন করুন' : 'Select Router')); ?>;
            } else if (selCount <= 2) {
                lbl.textContent = selectedItems.map(function(it){ return it.label; }).join(', ');
            } else {
                lbl.textContent = selCount + <?= json_encode($isBn ? 'টি রাউটার নির্বাচিত' : ' Routers Selected'); ?>;
            }
        }
        var previewBox = document.getElementById('routerSelectedPreview');
        if (previewBox) {
            previewBox.innerHTML = '';
            var maxShow = 6;
            for (var p = 0; p < Math.min(selCount, maxShow); p++) {
                (function(item) {
                    var chip = document.createElement('span');
                    chip.className = 'ngw-router-preview-item';
                    var txt = document.createElement('span');
                    txt.textContent = item.label;
                    var rmBtn = document.createElement('button');
                    rmBtn.type = 'button';
                    rmBtn.title = <?= json_encode($isBn ? 'অপসারণ করুন' : 'Remove'); ?>;
                    rmBtn.innerHTML = '&times;';
                    rmBtn.onclick = function() {
                        var cbs = document.querySelectorAll('.ngw-router-checkbox');
                        for (var k = 0; k < cbs.length; k++) {
                            if (cbs[k].value === item.value) {
                                cbs[k].checked = false;
                                break;
                            }
                        }
                        updateRouterDropdownLabel();
                    };
                    chip.appendChild(txt);
                    chip.appendChild(rmBtn);
                    previewBox.appendChild(chip);
                })(selectedItems[p]);
            }
            if (selCount > maxShow) {
                var more = document.createElement('span');
                more.className = 'ngw-router-preview-item';
                more.textContent = '+' + (selCount - maxShow) + <?= json_encode($isBn ? ' আরও' : ' more'); ?>;
                previewBox.appendChild(more);
            }
        }
    }

    var cntHeader = document.getElementById('ngwRpickerCountHeader');
    if (cntHeader) {
        cntHeader.textContent = <?= json_encode($isBn ? 'নির্বাচিত: ' : 'Selected: '); ?> + selCount + ' / ' + totalCount;
    }
    var cntFooter = document.getElementById('ngwRpickerFooterCount');
    if (cntFooter) {
        cntFooter.textContent = selCount + <?= json_encode($isBn ? 'টি রাউটার নির্বাচিত' : ' router(s) selected'); ?>;
    }
    var tabAll = document.getElementById('ngwRpickerTabAllCnt');
    var tabSel = document.getElementById('ngwRpickerTabSelCnt');
    var tabUnsel = document.getElementById('ngwRpickerTabUnselCnt');
    if (tabAll) tabAll.textContent = String(totalCount);
    if (tabSel) tabSel.textContent = String(selCount);
    if (tabUnsel) tabUnsel.textContent = String(totalCount - selCount);
}

function setRouterPickerTab(tabKey, btn) {
    NGW_ROUTER_PICKER_TAB = tabKey || 'all';
    var tabs = document.querySelectorAll('.ngw-rpicker-tab');
    for (var i = 0; i < tabs.length; i++) {
        tabs[i].classList.remove('is-active');
    }
    if (btn) {
        btn.classList.add('is-active');
    } else {
        var defaultTab = document.querySelector('.ngw-rpicker-tab[data-tab="' + NGW_ROUTER_PICKER_TAB + '"]');
        if (defaultTab) defaultTab.classList.add('is-active');
    }
    applyRouterPickerFilters();
}

function applyRouterPickerFilters() {
    var searchEl = document.getElementById('ngwRouterPickerSearch');
    var clearBtn = document.getElementById('ngwRouterPickerSearchClear');
    var grpEl = document.getElementById('ngwRouterPickerGroupFilter');
    var q = searchEl ? searchEl.value.trim().toLowerCase() : '';
    var qTokens = q !== '' ? q.split(/\s+/).filter(Boolean) : [];
    var grp = grpEl ? String(grpEl.value || '').trim().toLowerCase() : '';
    if (clearBtn) {
        clearBtn.classList.toggle('is-shown', q !== '');
    }
    var cards = document.querySelectorAll('#ngwRouterPickerGrid .ngw-rpicker-card');
    var shown = 0;
    for (var i = 0; i < cards.length; i++) {
        var card = cards[i];
        var cb = card.querySelector('.ngw-router-checkbox');
        var isChecked = cb ? !!cb.checked : false;
        var hay = ((card.getAttribute('data-search') || '') + ' ' + (card.textContent || '')).toLowerCase();
        var cGrp = String(card.getAttribute('data-group') || '').trim().toLowerCase();
        var okSearch = true;
        if (qTokens.length > 0) {
            for (var t = 0; t < qTokens.length; t++) {
                if (hay.indexOf(qTokens[t]) === -1) {
                    okSearch = false;
                    break;
                }
            }
        }
        var okGroup = (grp === '' || cGrp === grp);
        var okTab = (NGW_ROUTER_PICKER_TAB === 'all') ||
                    (NGW_ROUTER_PICKER_TAB === 'selected' && isChecked) ||
                    (NGW_ROUTER_PICKER_TAB === 'unselected' && !isChecked);
        var vis = okSearch && okGroup && okTab;
        card.classList.toggle('is-hidden-card', !vis);
        if (vis) {
            card.style.removeProperty('display');
            shown++;
        } else {
            card.style.setProperty('display', 'none', 'important');
        }
    }
    var emptyEl = document.getElementById('ngwRouterPickerEmpty');
    if (emptyEl) {
        emptyEl.classList.toggle('is-visible', cards.length > 0 && shown === 0);
    }
}

function clearRouterPickerSearch() {
    var searchEl = document.getElementById('ngwRouterPickerSearch');
    if (searchEl) {
        searchEl.value = '';
        searchEl.focus();
    }
    applyRouterPickerFilters();
}

function resetRouterPickerFilters() {
    var searchEl = document.getElementById('ngwRouterPickerSearch');
    var grpEl = document.getElementById('ngwRouterPickerGroupFilter');
    if (searchEl) searchEl.value = '';
    if (grpEl) grpEl.value = '';
    setRouterPickerTab('all');
}

function routerPickerBulkAction(action) {
    var cards = document.querySelectorAll('#ngwRouterPickerGrid .ngw-rpicker-card');
    for (var i = 0; i < cards.length; i++) {
        var cb = cards[i].querySelector('.ngw-router-checkbox');
        if (!cb) continue;
        if (action === 'clear_all') {
            cb.checked = false;
        } else if (!cards[i].classList.contains('is-hidden-card') && cards[i].style.display !== 'none') {
            cb.checked = (action === 'select_visible');
        }
    }
    updateRouterDropdownLabel();
    applyRouterPickerFilters();
}

function ensureRouterPickerModalPortaled() {
    var modal = document.getElementById('ngwRouterPickerModal');
    if (modal && document.body && modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    return modal;
}

function bindRouterPickerListeners() {
    var searchEl = document.getElementById('ngwRouterPickerSearch');
    if (searchEl && !searchEl.getAttribute('data-ngw-bound')) {
        searchEl.setAttribute('data-ngw-bound', '1');
        ['input', 'keyup', 'search', 'change'].forEach(function(evtName) {
            searchEl.addEventListener(evtName, applyRouterPickerFilters);
        });
    }
    var grpEl = document.getElementById('ngwRouterPickerGroupFilter');
    if (grpEl && !grpEl.getAttribute('data-ngw-bound')) {
        grpEl.setAttribute('data-ngw-bound', '1');
        ['change', 'input'].forEach(function(evtName) {
            grpEl.addEventListener(evtName, applyRouterPickerFilters);
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindRouterPickerListeners);
} else {
    bindRouterPickerListeners();
}

function openRouterPickerModal(ev) {
    if (ev) ev.stopPropagation();
    NGW_ROUTER_PICKER_MODE = 'form';
    NGW_ROUTER_PICKER_QUICK_ID = '';
    var modal = ensureRouterPickerModalPortaled();
    bindRouterPickerListeners();
    var titleEl = document.getElementById('ngwRpickerTitle');
    if (titleEl) {
        titleEl.textContent = <?= json_encode($isBn ? 'রাউটার অ্যাসাইন করুন' : 'Assign Routers'); ?>;
    }
    var doneBtn = document.getElementById('ngwRpickerDoneBtn');
    var saveQuickBtn = document.getElementById('ngwRpickerSaveQuickBtn');
    if (doneBtn) doneBtn.style.display = 'inline-flex';
    if (saveQuickBtn) saveQuickBtn.style.display = 'none';
    resetRouterPickerFilters();
    updateRouterDropdownLabel();
    if (modal) {
        modal.classList.add('is-open');
        var searchEl = document.getElementById('ngwRouterPickerSearch');
        if (searchEl) {
            setTimeout(function(){ searchEl.focus(); }, 50);
        }
    }
}

function openQuickRouterAssignModal(id) {
    closeResellerActionMenus();
    var r = NGW_RESELLERS[String(id)];
    if (!r) return;
    NGW_ROUTER_PICKER_MODE = 'quick';
    NGW_ROUTER_PICKER_QUICK_ID = String(r.id);
    var modal = ensureRouterPickerModalPortaled();
    bindRouterPickerListeners();
    var titleEl = document.getElementById('ngwRpickerTitle');
    if (titleEl) {
        titleEl.textContent = <?= json_encode($isBn ? 'রাউটার অ্যাসাইন: ' : 'Assign Routers: '); ?> + (r.name || r.username);
    }
    var rChecks = document.querySelectorAll('.ngw-router-checkbox');
    var allowedList = Array.isArray(r.allowed_routers) ? r.allowed_routers : [];
    for (var i = 0; i < rChecks.length; i++) {
        rChecks[i].checked = (allowedList.indexOf(rChecks[i].value) !== -1);
    }
    var doneBtn = document.getElementById('ngwRpickerDoneBtn');
    var saveQuickBtn = document.getElementById('ngwRpickerSaveQuickBtn');
    if (doneBtn) doneBtn.style.display = 'none';
    if (saveQuickBtn) saveQuickBtn.style.display = 'inline-flex';
    resetRouterPickerFilters();
    updateRouterDropdownLabel();
    if (modal) {
        modal.classList.add('is-open');
        var searchEl = document.getElementById('ngwRouterPickerSearch');
        if (searchEl) {
            setTimeout(function(){ searchEl.focus(); }, 50);
        }
    }
}

function submitQuickRouterAssign() {
    var form = document.getElementById('ngwQuickRouterAssignForm');
    var idInp = document.getElementById('ngwQuickRouterResellerId');
    var box = document.getElementById('ngwQuickRouterHiddenInputs');
    if (!form || !idInp || !box || !NGW_ROUTER_PICKER_QUICK_ID) return;
    idInp.value = NGW_ROUTER_PICKER_QUICK_ID;
    box.innerHTML = '';
    var checks = document.querySelectorAll('.ngw-router-checkbox');
    for (var i = 0; i < checks.length; i++) {
        if (checks[i].checked) {
            var h = document.createElement('input');
            h.type = 'hidden';
            h.name = 'r_routers[]';
            h.value = checks[i].value;
            box.appendChild(h);
        }
    }
    form.submit();
}

function closeRouterPickerModal() {
    var modal = document.getElementById('ngwRouterPickerModal');
    if (modal) {
        modal.classList.remove('is-open');
    }
    if (NGW_ROUTER_PICKER_MODE === 'form') {
        updateRouterDropdownLabel();
    }
}

function toggleRouterDropdown(ev) {
    openRouterPickerModal(ev);
}

function PassReseller() {
    var x = document.getElementById('r_password');
    if (x.type === 'password') {
        x.type = 'text';
    } else {
        x.type = 'password';
    }
}

function onPermissionModeChange(mode) {
    var customBox = document.getElementById('customPermChecklist');
    var modalBox = document.querySelector('#resellerFormModal .ngw-modal-box');
    if (customBox) {
        customBox.style.display = (mode === 'Custom') ? 'block' : 'none';
    }
    if (modalBox) {
        if (mode === 'Custom') {
            modalBox.classList.add('is-custom-mode');
        } else {
            modalBox.classList.remove('is-custom-mode');
        }
    }
    syncCustomTileHighlights();
}

function syncCustomTileHighlights() {
    var boxes = document.querySelectorAll('.perm-checkbox');
    for (var i = 0; i < boxes.length; i++) {
        var tile = boxes[i].closest('.ngw-cp-tile');
        if (tile) {
            if (boxes[i].checked) {
                tile.classList.add('is-checked');
            } else {
                tile.classList.remove('is-checked');
            }
        }
    }
}

function setPermPreset(mode) {
    var boxes = document.querySelectorAll('.perm-checkbox');
    for (var i = 0; i < boxes.length; i++) {
        if (boxes[i].disabled) continue;
        var pk = boxes[i].getAttribute('data-perm');
        if (mode === 'all') {
            boxes[i].checked = (pk !== 'view_scheduler' && pk !== 'manage_scheduler');
        } else if (mode === 'none') {
            boxes[i].checked = false;
        } else if (mode === 'reset_only') {
            boxes[i].checked = (pk === 'reset_voucher' || pk === 'switch_language_theme');
        }
    }
    syncCustomTileHighlights();
}

function toggleCustomSectionPerms(secSlug, state) {
    var secEl = document.querySelector('.ngw-cp-sec-card[data-sec="' + secSlug + '"]');
    if (!secEl) return;
    var boxes = secEl.querySelectorAll('.perm-checkbox');
    for (var i = 0; i < boxes.length; i++) {
        if (!boxes[i].disabled && boxes[i].closest('.ngw-cp-tile').style.display !== 'none') {
            boxes[i].checked = state;
        }
    }
    syncCustomTileHighlights();
}

function filterCustomSection(secSlug, btn) {
    var pills = document.querySelectorAll('.ngw-cp-cat-pill');
    for (var i = 0; i < pills.length; i++) {
        pills[i].classList.remove('active');
    }
    if (btn) btn.classList.add('active');
    var cards = document.querySelectorAll('.ngw-cp-sec-card');
    for (var j = 0; j < cards.length; j++) {
        var cSlug = cards[j].getAttribute('data-sec');
        cards[j].style.display = (secSlug === 'all' || cSlug === secSlug) ? '' : 'none';
    }
}

function filterCustomPermissionsBySearch(q) {
    q = (q || '').toLowerCase().trim();
    var cards = document.querySelectorAll('.ngw-cp-sec-card');
    for (var i = 0; i < cards.length; i++) {
        var tiles = cards[i].querySelectorAll('.ngw-cp-tile');
        var visibleInCard = 0;
        for (var j = 0; j < tiles.length; j++) {
            var txt = (tiles[j].getAttribute('data-search') || tiles[j].textContent || '').toLowerCase();
            var match = (q === '' || txt.indexOf(q) !== -1);
            tiles[j].style.display = match ? '' : 'none';
            if (match) visibleInCard++;
        }
        cards[i].style.display = (visibleInCard > 0) ? '' : 'none';
    }
}

function filterPgSection(secSlug, btn) {
    var navBtns = document.querySelectorAll('.ngw-pg-nav-btn');
    for (var i = 0; i < navBtns.length; i++) {
        navBtns[i].classList.remove('active');
    }
    if (btn) btn.classList.add('active');
    var grid = document.getElementById('ngwPgGrid');
    if (grid) {
        if (secSlug === 'all') {
            grid.classList.remove('single-col');
        } else {
            grid.classList.add('single-col');
        }
    }
    var cards = document.querySelectorAll('#ngwPgGrid .ngw-pg-sec-card');
    for (var j = 0; j < cards.length; j++) {
        var cSlug = cards[j].getAttribute('data-sec');
        cards[j].style.display = (secSlug === 'all' || cSlug === secSlug) ? '' : 'none';
    }
}

function filterPgPermissionsBySearch(q) {
    q = (q || '').toLowerCase().trim();
    var cards = document.querySelectorAll('#ngwPgGrid .ngw-pg-sec-card');
    for (var i = 0; i < cards.length; i++) {
        var items = cards[i].querySelectorAll('.ngw-pg-item-box');
        var visibleCount = 0;
        for (var j = 0; j < items.length; j++) {
            var txt = (items[j].getAttribute('data-search') || items[j].textContent || '').toLowerCase();
            var match = (q === '' || txt.indexOf(q) !== -1);
            items[j].style.display = match ? '' : 'none';
            if (match) visibleCount++;
        }
        cards[i].style.display = (visibleCount > 0) ? '' : 'none';
    }
}

function togglePgSectionMode(secSlug, modeName) {
    var card = document.querySelector('#ngwPgGrid .ngw-pg-sec-card[data-sec="' + secSlug + '"]');
    if (!card) return;
    var inputs = card.querySelectorAll('input.pg-mode-cb[data-mode="' + modeName + '"]:not(:disabled)');
    var allChecked = true;
    for (var i = 0; i < inputs.length; i++) {
        if (!inputs[i].checked) {
            allChecked = false;
            break;
        }
    }
    var nextState = !allChecked;
    for (var j = 0; j < inputs.length; j++) {
        inputs[j].checked = nextState;
        syncPgModePill(inputs[j]);
    }
}

function syncPgModePill(cb) {
    if (!cb) return;
    var pill = cb.closest('.ngw-pg-mode-pill');
    if (!pill) return;
    if (cb.checked) {
        pill.classList.add('is-checked');
    } else {
        pill.classList.remove('is-checked');
    }
}

function openAddResellerModal() {
    document.getElementById('modalFormTitle').innerHTML = '<i class="fa fa-user-plus"></i> <?= htmlspecialchars(isset($_add_reseller_label) ? $_add_reseller_label : "Add Reseller"); ?>';
    document.getElementById('f_reseller_id').value = '';
    document.getElementById('f_username').value = '';
    document.getElementById('f_name').value = '';
    var passEl = document.getElementById('r_password');
    passEl.value = '';
    passEl.required = true;
    passEl.placeholder = '<?= htmlspecialchars($_password); ?>';
    document.getElementById('f_pass_hint').style.display = 'none';
    document.getElementById('f_status').value = 'active';
    var uiSelAdd = document.getElementById('f_ui_style');
    if (uiSelAdd) uiSelAdd.value = 'default';
    document.getElementById('r_permission_mode').value = 'Write';
    onPermissionModeChange('Write');

    NGW_ROUTER_PICKER_MODE = 'form';
    NGW_ROUTER_PICKER_QUICK_ID = '';
    var rChecks = document.querySelectorAll('.ngw-router-checkbox');
    for (var i = 0; i < rChecks.length; i++) {
        rChecks[i].checked = false;
    }
    updateRouterDropdownLabel();

    var pChecks = document.querySelectorAll('.perm-checkbox');
    for (var j = 0; j < pChecks.length; j++) {
        if (!pChecks[j].disabled) pChecks[j].checked = false;
    }

    document.getElementById('resellerFormModal').style.display = 'block';
}

function openEditResellerModal(id) {
    var r = NGW_RESELLERS[String(id)];
    if (!r) return;
    document.getElementById('modalFormTitle').innerHTML = '<i class="fa fa-edit"></i> <?= htmlspecialchars($_edit); ?>: ' + r.username;
    document.getElementById('f_reseller_id').value = r.id;
    document.getElementById('f_username').value = r.username;
    document.getElementById('f_name').value = r.name;
    var passEl = document.getElementById('r_password');
    passEl.value = '';
    passEl.required = false;
    passEl.placeholder = <?= json_encode($isBn ? 'বর্তমান পাসওয়ার্ড অপরিবর্তিত রাখতে খালি রাখুন' : 'Leave blank to keep current password'); ?>;
    document.getElementById('f_pass_hint').style.display = 'inline';
    document.getElementById('f_status').value = r.status;
    var uiSelEdit = document.getElementById('f_ui_style');
    if (uiSelEdit) {
        var desiredUi = r.ui_style || 'default';
        var matchedOpt = false;
        for (var uo = 0; uo < uiSelEdit.options.length; uo++) {
            if (uiSelEdit.options[uo].value === desiredUi) {
                matchedOpt = true;
                break;
            }
        }
        uiSelEdit.value = matchedOpt ? desiredUi : 'default';
    }
    document.getElementById('r_permission_mode').value = r.permission_mode;
    onPermissionModeChange(r.permission_mode);

    NGW_ROUTER_PICKER_MODE = 'form';
    NGW_ROUTER_PICKER_QUICK_ID = '';
    var rChecks = document.querySelectorAll('.ngw-router-checkbox');
    for (var i = 0; i < rChecks.length; i++) {
        var val = rChecks[i].value;
        rChecks[i].checked = (r.allowed_routers.indexOf(val) !== -1);
    }
    updateRouterDropdownLabel();

    var pChecks = document.querySelectorAll('.perm-checkbox');
    for (var j = 0; j < pChecks.length; j++) {
        var pk = pChecks[j].getAttribute('data-perm');
        if (!pChecks[j].disabled) {
            pChecks[j].checked = !!(r.permissions && r.permissions[pk]);
        }
    }
    syncCustomTileHighlights();

    document.getElementById('resellerFormModal').style.display = 'block';
}

function closeResellerFormModal() {
    document.getElementById('resellerFormModal').style.display = 'none';
}

function openResellerDetailsModal(id) {
    var r = NGW_RESELLERS[String(id)];
    if (!r) return;
    document.getElementById('det_id').textContent = '#' + r.id;
    document.getElementById('det_username').textContent = r.username;
    var detPassEl = document.getElementById('det_password');
    if (detPassEl) {
        detPassEl.textContent = (r.password !== undefined && r.password !== '') ? r.password : '-';
    }
    document.getElementById('det_name').textContent = r.name;
    document.getElementById('det_status').innerHTML = (r.status === 'active')
        ? '<b class="text-green">' + <?= json_encode($isBn ? 'সক্রিয়' : 'Active'); ?> + '</b>'
        : '<b class="text-red">' + <?= json_encode($isBn ? 'নিষ্ক্রিয়' : 'Disabled'); ?> + '</b>';
    document.getElementById('det_mode').textContent = r.permission_mode;
    var routerList = Array.isArray(r.allowed_router_labels) ? r.allowed_router_labels : [];
    document.getElementById('det_router_count').textContent = Number(r.allowed_routers_count || routerList.length);
    var routerContainer = document.getElementById('det_routers');
    routerContainer.textContent = '';
    if (routerList.length) {
        var routerListElement = document.createElement('div');
        routerListElement.className = 'ngw-router-detail-list';
        routerList.forEach(function(routerName, index) {
            var item = document.createElement('div');
            item.className = 'ngw-router-detail-item';
            var number = document.createElement('span');
            number.className = 'ngw-router-detail-number';
            number.textContent = String(index + 1);
            var label = document.createElement('span');
            label.className = 'ngw-router-detail-name';
            label.textContent = routerName;
            item.appendChild(number);
            item.appendChild(label);
            routerListElement.appendChild(item);
        });
        routerContainer.appendChild(routerListElement);
    } else {
        routerContainer.textContent = <?= json_encode($isBn ? 'কোনো রাউটার অ্যাসাইন করা নেই' : 'No routers assigned'); ?>;
    }
    var permissionCount = Number(r.granted_permissions_count || 0);
    document.getElementById('det_permission_count').textContent = permissionCount + <?= json_encode($isBn ? 'টি পারমিশন চালু আছে' : ' permissions available'); ?>;
    document.getElementById('det_template').textContent = r.template_code + ' (' + r.assigned_template + ')';
    document.getElementById('det_created').textContent = r.created_at;
    document.getElementById('det_logo_img').src = r.logo_url;
    document.getElementById('det_logo_name').textContent = r.logo_filename;
    document.getElementById('det_btn_edit').onclick = function() {
        closeResellerDetailsModal();
        openEditResellerModal(r.id);
    };
    document.getElementById('det_btn_tpl').href = r.tpl_url;
    document.getElementById('det_btn_logo').href = r.logo_edit_url;
    document.getElementById('resellerDetailsModal').style.display = 'block';
}

function closeResellerDetailsModal() {
    document.getElementById('resellerDetailsModal').style.display = 'none';
}
</script>

<?php $resellerTheme = isset($_SESSION['theme']) && $_SESSION['theme'] !== '' ? $_SESSION['theme'] : 'dark'; ?>
<div class="row ngw-reseller-page" data-theme="<?= htmlspecialchars($resellerTheme, ENT_QUOTES, 'UTF-8'); ?>">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title ngw-reseller-nav-title">
          <a href="<?= $baseResellerUrl ?>" class="<?= $activeView === 'resellers' ? 'text-warning' : '' ?>"><i class="fa fa-users"></i> <?= htmlspecialchars(isset($_reseller_management) ? $_reseller_management : 'Reseller Management'); ?></a>
          <span class="ngw-reseller-nav-sep">&nbsp;|&nbsp;</span>
          <a href="javascript:void(0)" onclick="openAddResellerModal()"><i class="fa fa-user-plus"></i> <?= htmlspecialchars(isset($_add_reseller_label) ? $_add_reseller_label : 'Add Reseller'); ?></a>
          <span class="ngw-reseller-nav-sep">&nbsp;|&nbsp;</span>
          <a href="<?= $baseResellerUrl ?>&view=groups" class="<?= $activeView === 'groups' ? 'text-warning' : '' ?>"><i class="fa fa-sliders"></i> <?= htmlspecialchars(isset($_permission_group_label) ? $_permission_group_label : 'Permission Group'); ?></a>
        </h3>
      </div>
      <div class="card-body">
        <?php if ($msg !== '') { echo "<div class='mr-b-10'>" . $msg . "</div>"; } ?>

        <?php if ($activeView === 'groups') {
          $totalPermCount = 0;
          foreach ($permCatalog as $cItems) {
            $totalPermCount += count($cItems);
          }
        ?>
        <!-- PERMISSION GROUP CONFIGURATION SECTION (SIDE-BY-SIDE CARDS & CATEGORY NAVIGATION) -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">
              <i class="fa fa-sliders"></i> <?= htmlspecialchars(isset($_permission_group_label) ? $_permission_group_label : ($isBn ? 'পারমিশন গ্রুপ কনফিগারেশন' : 'Permission Group')); ?>
              &nbsp; | &nbsp;
              <a href="<?= $baseResellerUrl ?>"><i class="fa fa-arrow-left"></i> <?= htmlspecialchars(isset($_reseller_list_label) ? $_reseller_list_label : ($isBn ? 'রিসেলার লিস্ট' : 'Reseller List')); ?></a>
            </h3>
          </div>
          <div class="card-body">
            <form method="post" action="<?= $baseResellerUrl ?>&view=groups">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($resellerCsrf, ENT_QUOTES, 'UTF-8'); ?>">
              <div class="ngw-pg-toolbar">
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                  <?php if (isMikhmonAdminStore()) { ?>
                  <button type="submit" name="save_permission_groups" class="btn bg-primary"><i class="fa fa-save"></i> <?= $_save ?></button>
                  <button type="submit" name="reset_permission_groups" class="btn bg-warning"><i class="fa fa-refresh"></i> <?= $isBn ? 'ডিফল্ট রিস্টোর করুন' : 'Restore Defaults' ?></button>
                  <?php } else { ?>
                  <span style="font-size:12px; opacity:0.85;"><i class="fa fa-lock"></i> <?= $isBn ? 'শুধুমাত্র সুপার এডমিন গ্লোবাল পারমিশন গ্রুপ এডিট করতে পারবেন (শুধুমাত্র দেখার মোড)।' : 'Read-only view: only Super Admin can edit global Permission Groups.' ?></span>
                  <?php } ?>
                </div>
                <div class="ngw-pg-search-wrap">
                  <i class="fa fa-search"></i>
                  <input type="text" class="form-control ngw-pg-search-input" placeholder="<?= $isBn ? 'যেকোনো পারমিশন খুঁজুন (যেমন: ট্রান্সফার, পিপিপি, হটস্পট)...' : 'Search permissions (e.g., Transfer, PPPoE, Hotspot)...' ?>" onkeyup="filterPgPermissionsBySearch(this.value)">
                </div>
              </div>

              <div class="ngw-pg-workspace">
                <!-- Left Section Filter Sidebar -->
                <aside class="ngw-pg-sidebar">
                  <div class="ngw-pg-sidebar-title">
                    <i class="fa fa-layer-group"></i> <?= $isBn ? 'সেকশন অনুযায়ী দেখুন' : 'Filter by Section' ?>
                  </div>
                  <button type="button" class="ngw-pg-nav-btn active" onclick="filterPgSection('all', this)">
                    <span class="ngw-pg-nav-left">
                      <span class="ngw-pg-nav-ico" style="background:#1f6feb;"><i class="fa fa-th-large"></i></span>
                      <span><?= $isBn ? 'সবগুলো সেকশন' : 'All Sections' ?></span>
                    </span>
                    <span class="ngw-pg-nav-cnt"><?= (int)$totalPermCount ?></span>
                  </button>
                  <?php foreach ($permCatalog as $catName => $catItems) {
                    $sMeta = isset($permSectionsMeta[$catName]) ? $permSectionsMeta[$catName] : array(
                      'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '_', $catName)),
                      'icon' => 'fa-folder',
                      'color' => '#1f6feb',
                      'short_en' => $catName,
                      'short_bn' => $catName,
                    );
                    $navLabel = $isBn && !empty($sMeta['short_bn']) ? $sMeta['short_bn'] : (!empty($sMeta['short_en']) ? $sMeta['short_en'] : $catName);
                  ?>
                    <button type="button" class="ngw-pg-nav-btn" onclick="filterPgSection('<?= htmlspecialchars($sMeta['slug'], ENT_QUOTES) ?>', this)">
                      <span class="ngw-pg-nav-left">
                        <span class="ngw-pg-nav-ico" style="background:<?= htmlspecialchars($sMeta['color'], ENT_QUOTES) ?>;"><i class="fa <?= htmlspecialchars($sMeta['icon'], ENT_QUOTES) ?>"></i></span>
                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($navLabel) ?></span>
                      </span>
                      <span class="ngw-pg-nav-cnt"><?= count($catItems) ?></span>
                    </button>
                  <?php } ?>
                </aside>

                <!-- Right Side-by-Side Section Cards -->
                <div class="ngw-pg-content">
                  <div class="ngw-pg-grid" id="ngwPgGrid">
                    <?php foreach ($permCatalog as $catName => $catItems) {
                      $sMeta = isset($permSectionsMeta[$catName]) ? $permSectionsMeta[$catName] : array(
                        'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '_', $catName)),
                        'icon' => 'fa-folder',
                        'color' => '#1f6feb',
                        'bg' => 'rgba(31, 111, 235, 0.14)',
                        'border' => 'rgba(56, 139, 253, 0.38)',
                        'title_en' => $catName,
                        'title_bn' => $catName,
                        'items_bn' => array(),
                      );
                      $secTitle = $isBn && !empty($sMeta['title_bn']) ? $sMeta['title_bn'] : (!empty($sMeta['title_en']) ? $sMeta['title_en'] : $catName);
                      $hasEditableInSec = false;
                      foreach ($catItems as $kCheck => $vCheck) {
                        if (!isProtectedAdminPermission($kCheck)) {
                          $hasEditableInSec = true;
                          break;
                        }
                      }
                    ?>
                      <section class="ngw-pg-sec-card" data-sec="<?= htmlspecialchars($sMeta['slug'], ENT_QUOTES) ?>" style="border-color:<?= htmlspecialchars($sMeta['border'], ENT_QUOTES) ?>;">
                        <div class="ngw-pg-sec-header" style="background:<?= htmlspecialchars($sMeta['bg'], ENT_QUOTES) ?>; border-bottom-color:<?= htmlspecialchars($sMeta['border'], ENT_QUOTES) ?>;">
                          <div class="ngw-pg-sec-title-wrap">
                            <span class="ngw-pg-sec-icon" style="background:<?= htmlspecialchars($sMeta['color'], ENT_QUOTES) ?>;">
                              <i class="fa <?= htmlspecialchars($sMeta['icon'], ENT_QUOTES) ?>"></i>
                            </span>
                            <div>
                              <h4 class="ngw-pg-sec-title"><?= htmlspecialchars($secTitle) ?></h4>
                            </div>
                            <span class="ngw-pg-sec-badge"><?= count($catItems) ?></span>
                          </div>
                          <?php if ($hasEditableInSec) { ?>
                            <div class="ngw-pg-sec-quick" title="<?= $isBn ? 'এই সেকশনের সবগুলো এক ক্লিকে টগল করুন' : 'Toggle entire section column' ?>">
                              <button type="button" class="ngw-pg-qbtn" onclick="togglePgSectionMode('<?= htmlspecialchars($sMeta['slug'], ENT_QUOTES) ?>', 'Read')">Read ✓</button>
                              <button type="button" class="ngw-pg-qbtn" onclick="togglePgSectionMode('<?= htmlspecialchars($sMeta['slug'], ENT_QUOTES) ?>', 'Write')">Write ✓</button>
                              <button type="button" class="ngw-pg-qbtn" onclick="togglePgSectionMode('<?= htmlspecialchars($sMeta['slug'], ENT_QUOTES) ?>', 'Full')">Full ✓</button>
                            </div>
                          <?php } ?>
                        </div>

                        <div class="ngw-pg-sec-body">
                          <?php foreach ($catItems as $pKey => $pLabelEn) {
                            $isProtected = isProtectedAdminPermission($pKey);
                            $pLabelBn = isset($sMeta['items_bn'][$pKey]) ? $sMeta['items_bn'][$pKey] : $pLabelEn;
                            $displayLabel = $isBn ? $pLabelBn : $pLabelEn;
                            $searchKeywords = $catName . ' ' . $secTitle . ' ' . $pKey . ' ' . $pLabelEn . ' ' . $pLabelBn;
                          ?>
                            <div class="ngw-pg-item-box" data-search="<?= htmlspecialchars($searchKeywords, ENT_QUOTES) ?>">
                              <div class="ngw-pg-item-info">
                                <span class="ngw-pg-item-label">
                                  <?= htmlspecialchars($displayLabel) ?>
                                  <?php if ($isProtected) { ?>
                                    <span class="text-warning" style="font-size:10.5px; margin-left:4px;"><i class="fa fa-shield"></i> <?= $isBn ? 'অ্যাডমিন সংরক্ষিত' : 'Admin-Only' ?></span>
                                  <?php } ?>
                                </span>
                              </div>
                              <div class="ngw-pg-item-modes">
                                <?php foreach (array('Read' => 'Read', 'Write' => 'Write', 'Full' => 'Full', 'Reset Voucher' => 'Reset') as $gMode => $shortModeLabel) {
                                  $gChecked = !$isProtected && !empty($permGroups[$gMode][$pKey]);
                                  $modeCssKey = ($gMode === 'Reset Voucher') ? 'Reset' : $gMode;
                                ?>
                                  <label class="ngw-pg-mode-pill mode-<?= $modeCssKey ?> <?= $gChecked ? 'is-checked' : '' ?> <?= $isProtected ? 'is-disabled' : '' ?>" title="<?= htmlspecialchars($gMode . ': ' . $displayLabel, ENT_QUOTES) ?>">
                                    <?php if ($isProtected) { ?>
                                      <input type="checkbox" disabled>
                                    <?php } else { ?>
                                      <input type="checkbox" class="pg-mode-cb" data-mode="<?= htmlspecialchars($gMode, ENT_QUOTES) ?>" name="grp_perms[<?= $gMode ?>][<?= htmlspecialchars($pKey, ENT_QUOTES) ?>]" value="1" <?= $gChecked ? 'checked' : '' ?> onchange="syncPgModePill(this)">
                                    <?php } ?>
                                    <span><?= htmlspecialchars($shortModeLabel) ?></span>
                                  </label>
                                <?php } ?>
                              </div>
                            </div>
                          <?php } ?>
                        </div>
                      </section>
                    <?php } ?>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>
        <?php } else {
          $ngwIsGlassOsReseller = (function_exists('ngwResolveActiveUiStyle') && ngwResolveActiveUiStyle() === 'aurora');
          if ($ngwIsGlassOsReseller) {
            $resTotalCnt = count($resellers);
            $resActiveCnt = 0;
            $resDisabledCnt = 0;
            foreach ($resellers as $rcItem) {
              if (isset($rcItem['status']) && $rcItem['status'] === 'active') {
                $resActiveCnt++;
              } else {
                $resDisabledCnt++;
              }
            }
        ?>
        <!-- CRYSTAL GLASS OS: RESELLER DIRECTORY STUDIO -->
        <div class="ngw-gl-res-shell">
          <div class="ngw-gl-hero">
            <div class="ngw-gl-hero-left">
              <div class="ngw-gl-hero-icon" aria-hidden="true"><i class="fa fa-users"></i></div>
              <div>
                <h2 class="ngw-gl-hero-title"><?= htmlspecialchars(isset($_reseller_management) ? $_reseller_management : 'Reseller Management'); ?></h2>
                <div class="ngw-gl-hero-sub">
                  <span><?= $isBn ? 'রিসেলার কার্ড ডিরেক্টরি, ১-ক্লিক লগইন এবং দ্রুত রাউটার অ্যাসাইন' : 'Glass reseller directory with direct actions, 1-click login & router assignment'; ?></span>
                </div>
              </div>
            </div>
            <div class="ngw-gl-hero-actions">
              <a href="<?= $baseResellerUrl ?>&view=groups" class="ngw-glass-btn">
                <i class="fa fa-sliders" aria-hidden="true"></i>
                <span><?= htmlspecialchars(isset($_permission_group_label) ? $_permission_group_label : 'Permission Group'); ?></span>
              </a>
              <button type="button" class="ngw-glass-btn is-primary" onclick="openAddResellerModal()">
                <i class="fa fa-user-plus" aria-hidden="true"></i>
                <span><?= htmlspecialchars(isset($_add_reseller_label) ? $_add_reseller_label : 'Add Reseller'); ?></span>
              </button>
            </div>
          </div>

          <div class="ngw-gl-kpi-row">
            <div class="ngw-gl-kpi-tile">
              <span class="ngw-gl-kpi-ico"><i class="fa fa-users"></i></span>
              <div>
                <div class="ngw-gl-kpi-val"><?= (int)$resTotalCnt; ?></div>
                <div class="ngw-gl-kpi-lbl"><?= $isBn ? 'মোট রিসেলার' : 'Total Resellers'; ?></div>
              </div>
            </div>
            <div class="ngw-gl-kpi-tile">
              <span class="ngw-gl-kpi-ico is-green"><i class="fa fa-check-circle"></i></span>
              <div>
                <div class="ngw-gl-kpi-val"><?= (int)$resActiveCnt; ?></div>
                <div class="ngw-gl-kpi-lbl"><?= $isBn ? 'সক্রিয় অ্যাকাউন্ট' : 'Active Accounts'; ?></div>
              </div>
            </div>
            <div class="ngw-gl-kpi-tile">
              <span class="ngw-gl-kpi-ico is-red"><i class="fa fa-ban"></i></span>
              <div>
                <div class="ngw-gl-kpi-val"><?= (int)$resDisabledCnt; ?></div>
                <div class="ngw-gl-kpi-lbl"><?= $isBn ? 'নিষ্ক্রিয় অ্যাকাউন্ট' : 'Disabled Accounts'; ?></div>
              </div>
            </div>
          </div>

          <div class="ngw-gl-toolbar">
            <div class="ngw-gl-toolbar-top">
              <div class="ngw-gl-search-wrap">
                <i class="fa fa-search" aria-hidden="true"></i>
                <input type="search" id="ngwGlResSearch" placeholder="<?= $isBn ? 'নাম, ইউজারনেম বা পারমিশন মোড দিয়ে রিসেলার খুঁজুন...' : 'Search resellers by name, username, or mode...'; ?>" oninput="ngwFilterGlassResellers()">
              </div>
              <div class="ngw-gl-group-bar">
                <button type="button" class="ngw-gl-group-btn is-active" data-gl-res-status="all" onclick="ngwSetGlassResStatus('all', this)">
                  <span><?= $isBn ? 'সব' : 'All'; ?></span>
                  <span class="ngw-gl-group-cnt"><?= (int)$resTotalCnt; ?></span>
                </button>
                <button type="button" class="ngw-gl-group-btn" data-gl-res-status="active" onclick="ngwSetGlassResStatus('active', this)">
                  <span><?= $isBn ? 'সক্রিয়' : 'Active'; ?></span>
                  <span class="ngw-gl-group-cnt"><?= (int)$resActiveCnt; ?></span>
                </button>
                <button type="button" class="ngw-gl-group-btn" data-gl-res-status="disabled" onclick="ngwSetGlassResStatus('disabled', this)">
                  <span><?= $isBn ? 'নিষ্ক্রিয়' : 'Disabled'; ?></span>
                  <span class="ngw-gl-group-cnt"><?= (int)$resDisabledCnt; ?></span>
                </button>
              </div>
            </div>
          </div>

          <?php if (empty($resellers)) { ?>
            <div class="ngw-gl-empty">
              <div class="ngw-gl-empty-ico"><i class="fa fa-user-plus"></i></div>
              <h3 style="margin:0 0 6px;font-size:17px;"><?= $isBn ? 'কোনো রিসেলার অ্যাকাউন্ট তৈরি করা হয়নি' : 'No reseller accounts yet'; ?></h3>
              <p style="margin:0 0 16px;font-size:13px;opacity:0.75;"><?= $isBn ? 'নতুন রিসেলার তৈরি করে নির্দিষ্ট রাউটার ও পারমিশন অ্যাসাইন করুন।' : 'Create your first reseller account and assign specific routers and permissions.'; ?></p>
              <button type="button" class="ngw-glass-btn is-primary" onclick="openAddResellerModal()">
                <i class="fa fa-user-plus"></i> <?= htmlspecialchars(isset($_add_reseller_label) ? $_add_reseller_label : 'Add Reseller'); ?>
              </button>
            </div>
          <?php } else { ?>
            <div class="ngw-gl-res-grid" id="ngwGlResGrid">
              <?php foreach ($resellers as $r) {
                $rId = (string)$r['id'];
                $rMetaClient = isset($resellersClientMap[$rId]) ? $resellersClientMap[$rId] : array();
                $rModeBadge = isset($r['permission_mode']) ? (string)$r['permission_mode'] : 'Custom';
                $rIsActive = (isset($r['status']) && $r['status'] === 'active');
                $rRouterCount = isset($rMetaClient['allowed_routers_count']) ? (int)$rMetaClient['allowed_routers_count'] : 0;
                $rInitialSrc = trim((string)$r['name']) !== '' ? trim((string)$r['name']) : (string)$r['username'];
                $rInitial = function_exists('mb_substr') ? mb_substr($rInitialSrc, 0, 1, 'UTF-8') : substr($rInitialSrc, 0, 1);
                $rOwnerInfo = function_exists('getResellerOwnerInfo') ? getResellerOwnerInfo($r) : null;
                $rAdminBadgeLbl = '';
                if (is_array($rOwnerInfo) && isset($rOwnerInfo['added_by_role']) && $rOwnerInfo['added_by_role'] === 'admin') {
                  $rAdminDispName = !empty($rOwnerInfo['added_by_name'])
                    ? (string)$rOwnerInfo['added_by_name']
                    : (!empty($rOwnerInfo['added_by_username']) ? (string)$rOwnerInfo['added_by_username'] : 'Admin');
                  $rAdminBadgeLbl = ($isBn ? 'অ্যাডমিন: ' : 'Admin: ') . $rAdminDispName;
                }
                $delResMsg = ($isBn ? 'আপনি কি নিশ্চিতভাবে এই রিসেলার মুছে ফেলতে চান: ' : 'Are you sure you want to delete reseller ') . $r['username'] . '?';
                $resSearchHay = strtolower($r['username'] . ' ' . $r['name'] . ' ' . $rModeBadge . ' ' . (isset($rMetaClient['allowed_routers_text']) ? $rMetaClient['allowed_routers_text'] : ''));
              ?>
              <article class="ngw-gl-res-card" data-gl-res-card data-status="<?= $rIsActive ? 'active' : 'disabled'; ?>" data-search="<?= htmlspecialchars($resSearchHay, ENT_QUOTES, 'UTF-8'); ?>">
                <div>
                  <div class="ngw-gl-res-head">
                    <div class="ngw-gl-res-user">
                      <span class="ngw-gl-res-avatar" aria-hidden="true"><?= htmlspecialchars($rInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                      <div style="min-width:0;">
                        <div class="ngw-gl-res-name" title="<?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="ngw-gl-res-handle">@<?= htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                      </div>
                    </div>
                    <span class="ngw-gl-chip <?= $rIsActive ? 'is-success' : 'is-danger'; ?>">
                      <?= $rIsActive ? ($isBn ? 'সক্রিয়' : 'Active') : ($isBn ? 'নিষ্ক্রিয়' : 'Disabled'); ?>
                    </span>
                  </div>

                  <div class="ngw-gl-res-meta" style="margin-top:12px;">
                    <span class="ngw-gl-chip is-accent">
                      <i class="fa fa-shield" aria-hidden="true"></i> <?= htmlspecialchars($rModeBadge, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <button type="button" class="ngw-glass-btn" style="padding:3px 9px;font-size:11.5px;border-radius:8px;" onclick="openQuickRouterAssignModal('<?= htmlspecialchars($rId, ENT_QUOTES, 'UTF-8'); ?>')" title="<?= $isBn ? 'রাউটার অ্যাসাইন করুন' : 'Assign Routers'; ?>">
                      <i class="fa fa-server" aria-hidden="true"></i>
                      <span><?= $rRouterCount; ?> <?= $isBn ? 'রাউটার' : 'Routers'; ?></span>
                    </button>
                    <?php if ($rAdminBadgeLbl !== '') { ?>
                    <span class="ngw-gl-chip">
                      <i class="fa fa-user-o" aria-hidden="true"></i> <?= htmlspecialchars($rAdminBadgeLbl, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <?php } ?>
                  </div>
                </div>

                <div class="ngw-gl-res-actions">
                  <a href="<?= $baseResellerUrl ?>&login-as-reseller=<?= urlencode($rId); ?>&csrf=<?= urlencode($resellerCsrf); ?>" class="ngw-glass-btn is-success is-grow" title="<?= htmlspecialchars((isset($_login_as_reseller_tip) ? $_login_as_reseller_tip : 'Login as Reseller') . ': ' . $r['username']); ?>">
                    <i class="fa fa-sign-in" aria-hidden="true"></i>
                    <span><?= htmlspecialchars(isset($_login_btn_short) ? $_login_btn_short : 'Login'); ?></span>
                  </a>
                  <button type="button" class="ngw-glass-btn is-primary" onclick="openEditResellerModal('<?= htmlspecialchars($rId, ENT_QUOTES, 'UTF-8'); ?>')" title="<?= htmlspecialchars($_edit); ?>">
                    <i class="fa fa-edit" aria-hidden="true"></i>
                  </button>
                  <button type="button" class="ngw-glass-btn" onclick="openResellerDetailsModal('<?= htmlspecialchars($rId, ENT_QUOTES, 'UTF-8'); ?>')" title="<?= htmlspecialchars(isset($_details_label) ? $_details_label : 'Details'); ?>">
                    <i class="fa fa-info-circle" aria-hidden="true"></i>
                  </button>
                  <a href="<?= $baseResellerUrl ?>&toggle-reseller=<?= urlencode($rId); ?>&csrf=<?= urlencode($resellerCsrf); ?>" class="ngw-glass-btn <?= $rIsActive ? 'is-warning' : 'is-success'; ?>" title="<?= $rIsActive ? ($isBn ? 'নিষ্ক্রিয় করুন' : 'Deactivate') : ($isBn ? 'সক্রিয় করুন' : 'Activate'); ?>">
                    <i class="fa <?= $rIsActive ? 'fa-lock' : 'fa-unlock'; ?>" aria-hidden="true"></i>
                  </a>
                  <button type="button" class="ngw-glass-btn is-danger" title="<?= htmlspecialchars($_delete); ?>" onclick='if(confirm(<?= json_encode($delResMsg); ?>)){window.location="<?= $baseResellerUrl ?>&remove-reseller=<?= urlencode($rId); ?>&csrf=<?= urlencode($resellerCsrf); ?>";}'>
                    <i class="fa fa-trash" aria-hidden="true"></i>
                  </button>
                </div>
              </article>
              <?php } ?>
            </div>

            <div id="ngwGlResEmpty" class="ngw-gl-empty" style="display:none;">
              <div class="ngw-gl-empty-ico"><i class="fa fa-search"></i></div>
              <h3 style="margin:0 0 6px;font-size:16px;"><?= $isBn ? 'কোনো রিসেলার পাওয়া যায়নি' : 'No matching resellers found'; ?></h3>
              <button type="button" class="ngw-glass-btn is-primary" onclick="var s=document.getElementById('ngwGlResSearch'); if(s)s.value=''; ngwSetGlassResStatus('all', document.querySelector('[data-gl-res-status=all]'));">
                <i class="fa fa-undo"></i> <?= $isBn ? 'ফিল্টার রিসেট করুন' : 'Reset Filters'; ?>
              </button>
            </div>
          <?php } ?>
        </div>

        <script>
        (function () {
          var currentResStatus = 'all';
          window.ngwSetGlassResStatus = function (st, btn) {
            currentResStatus = st || 'all';
            var btns = document.querySelectorAll('[data-gl-res-status]');
            for (var i = 0; i < btns.length; i++) {
              btns[i].classList.remove('is-active');
            }
            if (btn) btn.classList.add('is-active');
            window.ngwFilterGlassResellers();
          };
          window.ngwFilterGlassResellers = function () {
            var qEl = document.getElementById('ngwGlResSearch');
            var q = qEl ? qEl.value.trim().toLowerCase() : '';
            var cards = document.querySelectorAll('[data-gl-res-card]');
            var shown = 0;
            for (var i = 0; i < cards.length; i++) {
              var st = cards[i].getAttribute('data-status') || 'active';
              var hay = cards[i].getAttribute('data-search') || '';
              var ok = (currentResStatus === 'all' || st === currentResStatus) && (q === '' || hay.indexOf(q) !== -1);
              cards[i].style.display = ok ? '' : 'none';
              if (ok) shown++;
            }
            var empty = document.getElementById('ngwGlResEmpty');
            if (empty) empty.style.display = (cards.length > 0 && shown === 0) ? 'block' : 'none';
          };
        })();
        </script>
        <?php } else { ?>
        <!-- FULL-WIDTH RESELLER LIST (CLASSIC / MODERN / NOVA) -->
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-list"></i> <?= htmlspecialchars(isset($_reseller_list_label) ? $_reseller_list_label : 'Reseller List'); ?> (<?= count($resellers); ?>)</h3>
              </div>
              <div class="card-body">
                <div class="overflow box-bordered ngw-mobile-card-wrap" style="min-height: 340px;">
                  <table class="table table-sm table-bordered table-hover text-nowrap ngw-mobile-card-table">
                    <thead>
                      <tr>
                        <th class="text-center" style="width: 70px;"><?= $_action ?></th>
                        <th><?= $_user_name ?></th>
                        <th><?= $_name ?></th>
                        <th><?= $isBn ? 'পারমিশন মোড' : 'Mode' ?></th>
                        <th class="text-center"><?= htmlspecialchars(isset($_login_btn_short) ? $_login_btn_short : 'Login'); ?></th>
                        <th><?= isset($_status) ? $_status : 'Status' ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($resellers)) { ?>
                        <tr><td colspan="6" class="text-center"><?= $isBn ? 'কোনো রিসেলার অ্যাকাউন্ট তৈরি করা হয়নি।' : 'No reseller accounts configured.' ?></td></tr>
                      <?php } else {
                        foreach ($resellers as $r) {
                          $rId = (string)$r['id'];
                          $rModeBadge = isset($r['permission_mode']) ? $r['permission_mode'] : 'Custom';
                          $rAssignedVariant = isset($r['assigned_template']) ? $r['assigned_template'] : 'default';
                          $statusBadge = ($r['status'] === 'active')
                            ? ("<b class='text-green'>" . ($isBn ? 'সক্রিয়' : 'Active') . "</b>")
                            : ("<b class='text-red'>" . ($isBn ? 'নিষ্ক্রিয়' : 'Disabled') . "</b>");
                      ?>
                        <tr class="ngw-mc-has-corner-menu">
                          <td class="text-center ngw-mc-corner-menu" data-label="<?= htmlspecialchars($_action, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="ngw-action-wrap">
                              <button type="button" class="btn bg-primary ngw-dots-btn" onclick="toggleActionMenu(event, '<?= htmlspecialchars($rId, ENT_QUOTES); ?>', this)" title="<?= htmlspecialchars($_action); ?>" aria-label="<?= htmlspecialchars($_action); ?>" aria-haspopup="true">
                                <i class="fa fa-ellipsis-v"></i>
                              </button>
                              <div id="actionMenu_<?= htmlspecialchars($rId); ?>" class="ngw-action-menu card">
                                <a href="javascript:void(0)" onclick="openResellerDetailsModal('<?= htmlspecialchars($rId, ENT_QUOTES); ?>')">
                                  <i class="fa fa-info-circle text-info"></i> <?= htmlspecialchars(isset($_details_label) ? $_details_label : 'Details'); ?>
                                </a>
                                <a href="javascript:void(0)" onclick="openEditResellerModal('<?= htmlspecialchars($rId, ENT_QUOTES); ?>')">
                                  <i class="fa fa-edit text-primary"></i> <?= htmlspecialchars($_edit); ?>
                                </a>
                                <?php if ($canManageResellers) { ?>
                                <a href="javascript:void(0)" onclick="openQuickRouterAssignModal('<?= htmlspecialchars($rId, ENT_QUOTES); ?>')">
                                  <i class="fa fa-server text-info"></i> <?= $isBn ? 'রাউটার অ্যাসাইন' : 'Assign Routers'; ?>
                                </a>
                                <?php } ?>
                                <a href="<?= $baseResellerUrl ?>&toggle-reseller=<?= urlencode($rId); ?>&csrf=<?= urlencode($resellerCsrf); ?>">
                                  <i class="fa <?= $r['status'] === 'active' ? 'fa-lock text-warning' : 'fa-unlock text-green'; ?>"></i> <?= $r['status'] === 'active' ? ($isBn ? 'নিষ্ক্রিয় করুন' : 'Deactivate') : ($isBn ? 'সক্রিয় করুন' : 'Activate'); ?>
                                </a>
                                <?php $delResMsg = ($isBn ? 'আপনি কি নিশ্চিতভাবে এই রিসেলার মুছে ফেলতে চান: ' : 'Are you sure you want to delete reseller ') . $r['username'] . '?'; ?>
                                <a href="javascript:void(0)" onclick='if(confirm(<?= json_encode($delResMsg); ?>)){window.location="<?= $baseResellerUrl ?>&remove-reseller=<?= urlencode($rId); ?>&csrf=<?= urlencode($resellerCsrf); ?>";}'>
                                  <i class="fa fa-trash text-danger"></i> <?= htmlspecialchars($_delete); ?>
                                </a>
                              </div>
                            </div>
                          </td>
                          <td class="ngw-mc-head" data-label="<?= htmlspecialchars($_user_name, ENT_QUOTES, 'UTF-8'); ?>">
                            <b><?= htmlspecialchars($r['username']); ?></b>
                            <?php
                            $rOwnerInfo = function_exists('getResellerOwnerInfo') ? getResellerOwnerInfo($r) : null;
                            if (is_array($rOwnerInfo) && isset($rOwnerInfo['added_by_role']) && $rOwnerInfo['added_by_role'] === 'admin') {
                                $rAdminDispName = !empty($rOwnerInfo['added_by_name'])
                                    ? (string)$rOwnerInfo['added_by_name']
                                    : (!empty($rOwnerInfo['added_by_username']) ? (string)$rOwnerInfo['added_by_username'] : 'Admin');
                                $rAdminBadgeLbl = $isBn
                                    ? ('এডমিন অ্যাড করেছে: ' . $rAdminDispName)
                                    : ('Added by Admin: ' . $rAdminDispName);
                            ?>
                              <span style="display:inline-block; margin-left:6px; padding:1px 6px; border-radius:4px; font-size:10px; font-weight:700; background:rgba(56,189,248,0.18); color:#38bdf8;">
                                <i class="fa fa-user-o"></i> <?= htmlspecialchars($rAdminBadgeLbl); ?>
                              </span>
                            <?php } ?>
                          </td>
                          <td data-label="<?= htmlspecialchars($_name, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($r['name']); ?></td>
                          <td data-label="<?= htmlspecialchars($isBn ? 'পারমিশন মোড' : 'Mode', ENT_QUOTES, 'UTF-8'); ?>"><span class="ngw-mode-chip"><?= htmlspecialchars($rModeBadge); ?></span></td>
                          <td class="text-center ngw-mc-actions is-full-cta" data-label="<?= htmlspecialchars(isset($_login_btn_short) ? $_login_btn_short : 'Login', ENT_QUOTES, 'UTF-8'); ?>">
                            <a href="<?= $baseResellerUrl ?>&login-as-reseller=<?= urlencode($rId); ?>&csrf=<?= urlencode($resellerCsrf); ?>" class="btn bg-green pd-2p5 radius-3 ngw-login-btn" title="<?= htmlspecialchars((isset($_login_as_reseller_tip) ? $_login_as_reseller_tip : 'Login as Reseller') . ': ' . $r['username']); ?>">
                              <i class="fa fa-sign-in"></i> <?= htmlspecialchars(isset($_login_btn_short) ? $_login_btn_short : 'Login'); ?>
                            </a>
                          </td>
                          <td data-label="<?= htmlspecialchars(isset($_status) ? $_status : 'Status', ENT_QUOTES, 'UTF-8'); ?>"><?= $statusBadge; ?></td>
                        </tr>
                      <?php } } ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php } ?>
        <?php } ?>
      </div>
    </div>
  </div>
</div>

<!-- POPUP MODAL 1: ADD / EDIT RESELLER -->
<div id="resellerFormModal" class="ngw-modal-backdrop" onclick="if(event.target===this)closeResellerFormModal();">
  <div class="card ngw-modal-box">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <h3 class="card-title" id="modalFormTitle" style="margin:0;"><i class="fa fa-user-plus"></i> <?= htmlspecialchars(isset($_add_reseller_label) ? $_add_reseller_label : 'Add Reseller'); ?></h3>
      <span class="pointer" onclick="closeResellerFormModal()" style="font-size:18px; padding:0 6px;" title="<?= htmlspecialchars($_close); ?>"><i class="fa fa-close text-red"></i></span>
    </div>
    <div class="card-body">
      <form autocomplete="off" method="post" action="<?= $baseResellerUrl ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($resellerCsrf, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="reseller_id" id="f_reseller_id" value="">
        <table class="table table-sm ngw-form-stack-table">
          <tr>
            <td class="align-middle" style="width: 34%;"><?= $_user_name ?></td>
            <td>
              <input class="form-control" type="text" name="r_username" id="f_username" value="" placeholder="Username" required="1">
            </td>
          </tr>
          <tr>
            <td class="align-middle"><?= $_name ?></td>
            <td>
              <input class="form-control" type="text" name="r_name" id="f_name" value="" placeholder="Full Name / Outlet Name" required="1">
            </td>
          </tr>
          <tr>
            <td class="align-middle"><?= $_password ?> <small id="f_pass_hint" style="display:none;">(Optional)</small></td>
            <td>
              <div class="input-group">
                <div class="input-group-11 col-box-10">
                  <input class="group-item group-item-l" id="r_password" type="password" name="r_password" value="" placeholder="<?= htmlspecialchars($_password); ?>">
                </div>
                <div class="input-group-1 col-box-2">
                  <div class="group-item group-item-r pd-2p5 text-center align-middle">
                    <input title="Show/Hide Password" type="checkbox" onclick="PassReseller()">
                  </div>
                </div>
              </div>
            </td>
          </tr>
          <tr>
            <td class="align-middle"><?= isset($_status) ? $_status : 'Status' ?></td>
            <td>
              <select class="form-control" name="r_status" id="f_status">
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
              </select>
            </td>
          </tr>
          <tr>
            <td class="align-middle"><?= $isBn ? 'রাউটার অ্যাসাইন' : htmlspecialchars(isset($_select_router_btn) ? $_select_router_btn : 'Assign Routers'); ?></td>
            <td>
              <div id="routerDropdownWrap" class="ngw-router-dd-wrap">
                <button type="button" id="routerPickerTriggerBtn" class="form-control ngw-router-dd-btn" onclick="openRouterPickerModal(event)">
                  <span class="ngw-router-trigger-left">
                    <i class="fa fa-server"></i>
                    <span id="routerDropdownLabel"><?= htmlspecialchars(isset($_select_router_btn) ? $_select_router_btn : ($isBn ? 'রাউটার নির্বাচন করুন' : 'Select Router')); ?></span>
                  </span>
                  <span class="ngw-router-trigger-action">
                    <i class="fa fa-search"></i>
                    <span><?= $isBn ? 'নির্বাচন করুন' : 'Choose'; ?></span>
                  </span>
                </button>
                <div id="routerSelectedPreview" class="ngw-router-preview-list"></div>
                <div id="ngwSelectedRoutersHidden" style="display:none;"></div>
              </div>
            </td>
          </tr>
          <tr>
            <td class="align-middle">Permission Mode</td>
            <td>
              <select class="form-control" id="r_permission_mode" name="r_permission_mode" onchange="onPermissionModeChange(this.value)">
                <option value="Read">Read</option>
                <option value="Write" selected>Write</option>
                <option value="Full">Full</option>
                <option value="Reset Voucher">Reset Voucher</option>
                <option value="Custom">Custom</option>
              </select>
            </td>
          </tr>
          <?php if ($canAssignResellerUi) {
            $ngwResUiOpts = ngwGetAvailableUiStyles($isBn);
          ?>
          <tr>
            <td class="align-middle"><?= $isBn ? 'ইউআই স্টাইল (UI)' : 'Assigned UI Style'; ?></td>
            <td>
              <select class="form-control" id="f_ui_style" name="r_ui_style">
                <option value="default"><?= $isBn ? 'ডিফল্ট (ক্লাসিক ইউআই)' : 'Default (Classic UI)'; ?></option>
                <?php foreach ($ngwResUiOpts as $uKey => $uMeta) {
                  if (!in_array($uKey, $permittedResellerUiKeys, true)) { continue; }
                ?>
                <option value="<?= htmlspecialchars($uKey, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($uMeta['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php } ?>
              </select>
            </td>
          </tr>
          <?php } ?>
        </table>

        <!-- Custom Granular Checklist (Shown only when Custom mode is selected) -->
        <div id="customPermChecklist" class="card mr-t-5" style="display: none;">
          <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <h3 class="card-title" style="font-size:13px; margin:0;">
              <i class="fa fa-lock"></i> <?= $isBn ? 'কাস্টম পারমিশন নির্বাচন' : 'Custom Permissions' ?>
              <span style="font-size: 11px; font-weight: normal;">
                &nbsp; | &nbsp;
                <a href="javascript:void(0)" onclick="setPermPreset('all')"><?= $isBn ? 'সব সিলেক্ট' : 'All' ?></a> &middot;
                <a href="javascript:void(0)" onclick="setPermPreset('reset_only')"><?= $isBn ? 'শুধু রিসেট' : 'Reset Only' ?></a> &middot;
                <a href="javascript:void(0)" onclick="setPermPreset('none')"><?= $isBn ? 'আনসিলেক্ট' : 'None' ?></a>
              </span>
            </h3>
            <input type="text" class="form-control" style="width:210px; height:26px; font-size:11.5px; padding:3px 8px;" placeholder="<?= $isBn ? 'পারমিশন খুঁজুন...' : 'Search permission...' ?>" onkeyup="filterCustomPermissionsBySearch(this.value)">
          </div>
          <div class="card-body" style="max-height: 360px; overflow-y: auto; padding: 8px;">
            <!-- Section Filter Pills -->
            <div class="ngw-cp-filter-bar">
              <button type="button" class="ngw-cp-cat-pill active" onclick="filterCustomSection('all', this)">
                <i class="fa fa-th-large"></i> <?= $isBn ? 'সব সেকশন' : 'All' ?>
              </button>
              <?php foreach ($permCatalog as $groupName => $groupPerms) {
                $sMeta = isset($permSectionsMeta[$groupName]) ? $permSectionsMeta[$groupName] : array(
                  'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '_', $groupName)),
                  'icon' => 'fa-folder',
                  'short_en' => $groupName,
                  'short_bn' => $groupName,
                );
                $pillLbl = $isBn && !empty($sMeta['short_bn']) ? $sMeta['short_bn'] : (!empty($sMeta['short_en']) ? $sMeta['short_en'] : $groupName);
              ?>
                <button type="button" class="ngw-cp-cat-pill" onclick="filterCustomSection('<?= htmlspecialchars($sMeta['slug'], ENT_QUOTES) ?>', this)">
                  <i class="fa <?= htmlspecialchars($sMeta['icon'], ENT_QUOTES) ?>"></i> <?= htmlspecialchars($pillLbl) ?>
                </button>
              <?php } ?>
            </div>

            <!-- Categorized Colorful Section Boxes -->
            <?php foreach ($permCatalog as $groupName => $groupPerms) {
              $sMeta = isset($permSectionsMeta[$groupName]) ? $permSectionsMeta[$groupName] : array(
                'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '_', $groupName)),
                'icon' => 'fa-folder',
                'color' => '#1f6feb',
                'bg' => 'rgba(31, 111, 235, 0.14)',
                'border' => 'rgba(56, 139, 253, 0.38)',
                'title_en' => $groupName,
                'title_bn' => $groupName,
                'items_bn' => array(),
              );
              $secTitle = $isBn && !empty($sMeta['title_bn']) ? $sMeta['title_bn'] : (!empty($sMeta['title_en']) ? $sMeta['title_en'] : $groupName);
            ?>
              <div class="ngw-pg-sec-card ngw-cp-sec-card" data-sec="<?= htmlspecialchars($sMeta['slug'], ENT_QUOTES) ?>" style="margin-bottom: 10px; border-color:<?= htmlspecialchars($sMeta['border'], ENT_QUOTES) ?>;">
                <div class="ngw-pg-sec-header" style="padding: 6px 10px; background:<?= htmlspecialchars($sMeta['bg'], ENT_QUOTES) ?>; border-bottom-color:<?= htmlspecialchars($sMeta['border'], ENT_QUOTES) ?>;">
                  <div class="ngw-pg-sec-title-wrap">
                    <span class="ngw-pg-sec-icon" style="width:22px; height:22px; font-size:11px; background:<?= htmlspecialchars($sMeta['color'], ENT_QUOTES) ?>;">
                      <i class="fa <?= htmlspecialchars($sMeta['icon'], ENT_QUOTES) ?>"></i>
                    </span>
                    <span style="font-weight:700; font-size:12.5px;"><?= htmlspecialchars($secTitle) ?></span>
                    <span class="ngw-pg-sec-badge"><?= count($groupPerms) ?></span>
                  </div>
                  <div class="ngw-pg-sec-quick">
                    <button type="button" class="ngw-pg-qbtn" onclick="toggleCustomSectionPerms('<?= htmlspecialchars($sMeta['slug'], ENT_QUOTES) ?>', true)"><?= $isBn ? 'সব ✓' : 'All ✓' ?></button>
                    <button type="button" class="ngw-pg-qbtn" onclick="toggleCustomSectionPerms('<?= htmlspecialchars($sMeta['slug'], ENT_QUOTES) ?>', false)"><?= $isBn ? 'মুছুন' : 'Clear' ?></button>
                  </div>
                </div>
                <div class="ngw-pg-sec-body" style="padding: 7px;">
                  <div class="ngw-cp-tiles-grid">
                    <?php foreach ($groupPerms as $pKey => $pLabelEn) {
                      $isProtected = isProtectedAdminPermission($pKey);
                      $pLabelBn = isset($sMeta['items_bn'][$pKey]) ? $sMeta['items_bn'][$pKey] : $pLabelEn;
                      $displayLabel = $isBn ? $pLabelBn : $pLabelEn;
                      $searchStr = $groupName . ' ' . $secTitle . ' ' . $pKey . ' ' . $pLabelEn . ' ' . $pLabelBn;
                    ?>
                      <label class="ngw-cp-tile" data-search="<?= htmlspecialchars($searchStr, ENT_QUOTES) ?>" style="cursor:<?= $isProtected ? 'not-allowed' : 'pointer'; ?>; opacity:<?= $isProtected ? '0.5' : '1'; ?>;">
                        <input type="checkbox" class="perm-checkbox" data-perm="<?= htmlspecialchars($pKey, ENT_QUOTES); ?>" name="r_perms[<?= htmlspecialchars($pKey, ENT_QUOTES); ?>]" value="1" <?= $isProtected ? 'disabled' : ''; ?> onchange="syncCustomTileHighlights()">
                        <span><?= htmlspecialchars($displayLabel); ?></span>
                      </label>
                    <?php } ?>
                  </div>
                </div>
              </div>
            <?php } ?>
          </div>
        </div>

        <div class="mr-t-10 text-right">
          <button type="submit" name="save_reseller" class="btn bg-primary pd-10" style="cursor:pointer; font-weight:bold;">
            <i class="fa fa-save"></i> <?= $_save ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- POPUP MODAL 2: RESELLER DETAILS -->
<div id="resellerDetailsModal" class="ngw-modal-backdrop" onclick="if(event.target===this)closeResellerDetailsModal();">
  <div class="card ngw-modal-box">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <h3 class="card-title" style="margin:0;"><i class="fa fa-info-circle text-info"></i> <?= htmlspecialchars(isset($_reseller_details_title) ? $_reseller_details_title : 'Reseller Details'); ?></h3>
      <span class="pointer" onclick="closeResellerDetailsModal()" style="font-size:18px; padding:0 6px;" title="<?= htmlspecialchars($_close); ?>"><i class="fa fa-close text-red"></i></span>
    </div>
    <div class="card-body">
      <table class="table table-bordered table-sm">
        <tr>
          <td style="width: 36%;"><b><?= $isBn ? 'রিসেলার আইডি' : 'Reseller ID' ?></b></td>
          <td><code id="det_id"></code></td>
        </tr>
        <tr>
          <td><b><?= $_user_name ?></b></td>
          <td><b id="det_username"></b></td>
        </tr>
        <tr>
          <td><b><?= $_password ?></b></td>
          <td><code id="det_password" style="font-size:13px; font-weight:bold;"></code></td>
        </tr>
        <tr>
          <td><b><?= $_name ?></b></td>
          <td id="det_name"></td>
        </tr>
        <tr>
          <td><b><?= isset($_status) ? $_status : 'Status' ?></b></td>
          <td id="det_status"></td>
        </tr>
        <tr>
          <td><b><?= $isBn ? 'পারমিশন মোড' : 'Permission Mode' ?></b></td>
          <td><span id="det_mode" class="pd-2p5 radius-3 bg-primary"></span></td>
        </tr>
        <tr>
          <td><b><?= $isBn ? 'অনুমোদিত রাউটারসমূহ' : 'Assigned Routers' ?></b></td>
          <td>
            <div class="ngw-router-detail-summary"><b id="det_router_count">0</b> <?= $isBn ? 'টি নির্ধারিত' : 'assigned' ?></div>
            <div id="det_routers" class="ngw-router-detail-list"></div>
          </td>
        </tr>
        <tr>
          <td><b><?= $isBn ? 'অ্যাক্সেস পারমিশন' : 'Access Permissions' ?></b></td>
          <td id="det_permission_count"></td>
        </tr>
        <tr>
          <td><b><?= $isBn ? 'টেমপ্লেট' : 'Template' ?></b></td>
          <td><code id="det_template"></code></td>
        </tr>
        <tr>
          <td><b><?= $isBn ? 'লোগো' : 'Logo' ?></b></td>
          <td>
            <img id="det_logo_img" src="./img/logo.png" alt="Logo" style="height:28px; background:#fff; padding:2px; border-radius:3px; vertical-align:middle;">
            &nbsp;<small id="det_logo_name"></small>
          </td>
        </tr>
        <tr>
          <td><b><?= $isBn ? 'তৈরির সময়' : 'Created At' ?></b></td>
          <td id="det_created"></td>
        </tr>
      </table>
      <div class="mr-t-10 text-right">
        <button type="button" id="det_btn_edit" class="btn bg-primary"><i class="fa fa-edit"></i> <?= $_edit ?></button>
        <a id="det_btn_tpl" href="javascript:void(0)" class="btn bg-blue"><i class="fa fa-code"></i> <?= $_template_editor ?></a>
        <a id="det_btn_logo" href="javascript:void(0)" class="btn bg-green"><i class="fa fa-image"></i> <?= $_upload_logo ?></a>
      </div>
    </div>
  </div>
</div>

<!-- POPUP MODAL 3: SEARCHABLE & GROUPED ASSIGN ROUTERS PICKER (ALL 4 UIs) -->
<div id="ngwRouterPickerModal" class="ngw-rpicker-backdrop" data-theme="<?= htmlspecialchars($resellerTheme, ENT_QUOTES, 'UTF-8'); ?>" onclick="if(event.target===this)closeRouterPickerModal();">
  <div class="ngw-rpicker-dialog" role="dialog" aria-modal="true" aria-labelledby="ngwRpickerTitle">
    <div class="ngw-rpicker-header">
      <div class="ngw-rpicker-title-wrap">
        <span class="ngw-rpicker-icon"><i class="fa fa-server"></i></span>
        <div>
          <h3 id="ngwRpickerTitle" class="ngw-rpicker-title"><?= $isBn ? 'রাউটার অ্যাসাইন করুন' : 'Assign Routers'; ?></h3>
          <div id="ngwRpickerCountHeader" class="ngw-rpicker-sub">
            <?= $isBn ? 'নির্বাচিত: 0 / ' . count($routerPickerItems) : 'Selected: 0 / ' . count($routerPickerItems); ?>
          </div>
        </div>
      </div>
      <button type="button" class="ngw-rpicker-close" onclick="closeRouterPickerModal()" aria-label="<?= htmlspecialchars($_close); ?>">&times;</button>
    </div>

    <div class="ngw-rpicker-controls">
      <div class="ngw-rpicker-search-row">
        <div class="ngw-rpicker-search-box">
          <i class="fa fa-search" aria-hidden="true"></i>
          <input type="text" id="ngwRouterPickerSearch" class="ngw-rpicker-search-input" placeholder="<?= $isBn ? 'রাউটার বা গ্রুপের নাম দিয়ে খুঁজুন...' : 'Search by hotspot name or session group...'; ?>" oninput="applyRouterPickerFilters()" autocomplete="off">
          <button type="button" id="ngwRouterPickerSearchClear" class="ngw-rpicker-search-clear" onclick="clearRouterPickerSearch()" title="<?= $isBn ? 'মুছুন' : 'Clear'; ?>">&times;</button>
        </div>

        <?php if (!empty($routerPickerGroups)) { ?>
        <select id="ngwRouterPickerGroupFilter" class="ngw-rpicker-group-select" onchange="applyRouterPickerFilters()" aria-label="Filter by Session Group">
          <option value=""><?= $isBn ? ('সব গ্রুপ (' . count($routerPickerItems) . ')') : ('All Groups (' . count($routerPickerItems) . ')'); ?></option>
          <?php foreach ($routerPickerGroups as $gk => $gMeta) { ?>
          <option value="<?= htmlspecialchars($gk, ENT_QUOTES, 'UTF-8'); ?>">
            <?= htmlspecialchars($gMeta['label'] . ' (' . $gMeta['count'] . ')', ENT_QUOTES, 'UTF-8'); ?>
          </option>
          <?php } ?>
        </select>
        <?php } ?>
      </div>

      <div class="ngw-rpicker-subbar">
        <div class="ngw-rpicker-tabs" role="tablist">
          <button type="button" class="ngw-rpicker-tab is-active" data-tab="all" onclick="setRouterPickerTab('all', this)">
            <?= $isBn ? 'সব' : 'All'; ?> (<span id="ngwRpickerTabAllCnt"><?= count($routerPickerItems); ?></span>)
          </button>
          <button type="button" class="ngw-rpicker-tab" data-tab="selected" onclick="setRouterPickerTab('selected', this)">
            <?= $isBn ? 'নির্বাচিত' : 'Selected'; ?> (<span id="ngwRpickerTabSelCnt">0</span>)
          </button>
          <button type="button" class="ngw-rpicker-tab" data-tab="unselected" onclick="setRouterPickerTab('unselected', this)">
            <?= $isBn ? 'অনির্বাচিত' : 'Unselected'; ?> (<span id="ngwRpickerTabUnselCnt"><?= count($routerPickerItems); ?></span>)
          </button>
        </div>

        <div class="ngw-rpicker-bulk">
          <button type="button" class="ngw-rpicker-bulk-btn" onclick="routerPickerBulkAction('select_visible')">
            <i class="fa fa-check-square-o"></i> <?= $isBn ? 'সব দৃশ্যমান সিলেক্ট' : 'Select Visible'; ?>
          </button>
          <button type="button" class="ngw-rpicker-bulk-btn" onclick="routerPickerBulkAction('clear_visible')">
            <i class="fa fa-square-o"></i> <?= $isBn ? 'দৃশ্যমান মুছুন' : 'Clear Visible'; ?>
          </button>
          <button type="button" class="ngw-rpicker-bulk-btn" onclick="routerPickerBulkAction('clear_all')">
            <i class="fa fa-times"></i> <?= $isBn ? 'সব মুছুন' : 'Clear All'; ?>
          </button>
        </div>
      </div>
    </div>

    <div class="ngw-rpicker-body">
      <?php if (empty($routerPickerItems)) { ?>
        <div style="text-align:center; padding:32px 12px; opacity:0.78;">
          <i class="fa fa-server" style="font-size:24px; margin-bottom:8px; display:block;"></i>
          <?= $isBn ? 'কোনো রাউটার কনফিগার করা নেই।' : 'No routers configured yet.'; ?>
        </div>
      <?php } else { ?>
        <div id="ngwRouterPickerGrid" class="ngw-rpicker-grid">
          <?php
          if (is_file('./include/router_model_helper.php')) {
            include_once('./include/router_model_helper.php');
          }
          foreach ($routerPickerItems as $rpItem) {
            $searchHay = strtolower($rpItem['hotspot'] . ' ' . $rpItem['group'] . ' ' . $rpItem['key']);
            $rpHw = function_exists('ngwGetRouterHardwareInfo') ? ngwGetRouterHardwareInfo($rpItem['key']) : null;
            $rpHwImg = (is_array($rpHw) && !empty($rpHw['image'])) ? (string)$rpHw['image'] : '';
            $rpHwLbl = (is_array($rpHw) && !empty($rpHw['label'])) ? (string)$rpHw['label'] : '';
          ?>
            <label class="ngw-rpicker-card"
                   data-search="<?= htmlspecialchars($searchHay, ENT_QUOTES, 'UTF-8'); ?>"
                   data-group="<?= htmlspecialchars($rpItem['group_key'], ENT_QUOTES, 'UTF-8'); ?>">
              <input type="checkbox"
                     class="ngw-router-checkbox"
                     value="<?= htmlspecialchars($rpItem['key'], ENT_QUOTES, 'UTF-8'); ?>"
                     data-label="<?= htmlspecialchars($rpItem['hotspot'], ENT_QUOTES, 'UTF-8'); ?>"
                     onchange="updateRouterDropdownLabel(); applyRouterPickerFilters();">
              <span class="ngw-rpicker-card-ico<?= ($rpHwImg !== '') ? ' has-hw-img' : ''; ?>" aria-hidden="true">
                <?php if ($rpHwImg !== '') { ?>
                  <img class="ngw-rtr-hw-img" src="<?= htmlspecialchars($rpHwImg, ENT_QUOTES, 'UTF-8'); ?>" alt="<?= htmlspecialchars($rpHwLbl, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async">
                <?php } else { ?>
                  <i class="fa fa-server"></i>
                <?php } ?>
              </span>
              <span class="ngw-rpicker-card-meta">
                <span class="ngw-rpicker-card-name" title="<?= htmlspecialchars($rpItem['hotspot'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($rpItem['hotspot'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="ngw-rpicker-card-group" title="<?= htmlspecialchars($rpItem['group'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($rpItem['group'], ENT_QUOTES, 'UTF-8'); ?></span>
              </span>
            </label>
          <?php } ?>
        </div>
        <div id="ngwRouterPickerEmpty" class="ngw-rpicker-empty">
          <div style="font-size:13.5px; font-weight:600; margin-bottom:8px;">
            <?= $isBn ? 'আপনার খোঁজের সাথে কোনো রাউটার মিলেনি।' : 'No routers match your search or filter.'; ?>
          </div>
          <button type="button" class="ngw-rpicker-bulk-btn" onclick="resetRouterPickerFilters()">
            <i class="fa fa-undo"></i> <?= $isBn ? 'ফিল্টার রিসেট করুন' : 'Reset Filters'; ?>
          </button>
        </div>
      <?php } ?>
    </div>

    <div class="ngw-rpicker-footer">
      <div id="ngwRpickerFooterCount" class="ngw-rpicker-footer-summary">
        <?= $isBn ? '0টি রাউটার নির্বাচিত' : '0 router(s) selected'; ?>
      </div>
      <div class="ngw-rpicker-footer-actions">
        <button type="button" class="btn bg-secondary" onclick="closeRouterPickerModal()">
          <?= $isBn ? 'বন্ধ করুন' : 'Close'; ?>
        </button>
        <button type="button" id="ngwRpickerDoneBtn" class="btn bg-primary" onclick="closeRouterPickerModal()">
          <i class="fa fa-check"></i> <?= $isBn ? 'সম্পন্ন করুন' : 'Done'; ?>
        </button>
        <button type="button" id="ngwRpickerSaveQuickBtn" class="btn bg-success" style="display:none;" onclick="submitQuickRouterAssign()">
          <i class="fa fa-save"></i> <?= $isBn ? 'রাউটার সেভ করুন' : 'Save Routers'; ?>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Hidden Quick Router Assign Form (for 3-dot Assign Routers action) -->
<form id="ngwQuickRouterAssignForm" method="post" action="<?= $baseResellerUrl ?>" style="display:none;">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($resellerCsrf, ENT_QUOTES, 'UTF-8'); ?>">
  <input type="hidden" name="save_reseller_routers_only" value="1">
  <input type="hidden" name="quick_reseller_id" id="ngwQuickRouterResellerId" value="">
  <div id="ngwQuickRouterHiddenInputs"></div>
</form>

<?php if (isset($_GET['edit-reseller']) && $_GET['edit-reseller'] !== '') {
    $reqE = $_GET['edit-reseller'];
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($reqE === 'new') { ?>
    openAddResellerModal();
    <?php } else { ?>
    openEditResellerModal(<?= json_encode((string)$reqE); ?>);
    <?php } ?>
});
</script>
<?php } ?>
