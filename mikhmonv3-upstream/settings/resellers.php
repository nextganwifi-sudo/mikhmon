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
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include_once('./include/reseller_acl.php');

if (!isMikhmonAdmin()) {
    $thm = isset($_SESSION['theme']) && $_SESSION['theme'] !== '' ? $_SESSION['theme'] : 'dark';
    echo '<div class="row"><div class="col-6"><div class="card"><div class="card-header"><h3><i class="fa fa-ban text-danger"></i> Access Denied</h3></div><div class="card-body"><p>Only Administrator can manage Reseller accounts and Permission Groups.</p><a class="btn bg-primary" href="./admin.php?id=sessions"><i class="fa fa-arrow-left"></i> Back</a></div></div></div></div></div></div><script src="./js/mikhmon-ui.' . htmlspecialchars($thm, ENT_QUOTES) . '.min.js"></script><script src="./js/mikhmon.js"></script></body></html>';
    exit;
}

$baseResellerUrl = !empty($session) ? "./?hotspot=resellers&session=" . urlencode($session) : "./admin.php?id=resellers";
$allRouters = getConfiguredRouterSessions();
$permCatalog = getAllMikhmonPermissionCategories();
$permSectionsMeta = function_exists('getMikhmonPermissionSectionsMeta') ? getMikhmonPermissionSectionsMeta() : array();
$isBn = (isset($langid) && $langid === 'bn') || (isset($_SESSION['lang']) && $_SESSION['lang'] === 'bn');
$permGroups = loadPermissionGroups();
$resellers = loadResellers();
$msg = "";
$activeView = isset($_GET['view']) && $_GET['view'] === 'groups' ? 'groups' : 'resellers';

// 1-Click Login as Reseller (Admin Impersonation)
if (isset($_GET['login-as-reseller']) && $_GET['login-as-reseller'] !== '') {
    $targetResellerId = trim((string)$_GET['login-as-reseller']);
    $targetReseller = findResellerByIdOrUsername($targetResellerId);
    if ($targetReseller) {
        @session_start();
        $adminUsername = isset($_SESSION['mikhmon']) ? $_SESSION['mikhmon'] : $useradm;
        $_SESSION['mikhmon_impersonator_admin'] = $adminUsername;
        $effectivePerms = resolveEffectiveResellerPermissions($targetReseller);
        $_SESSION["mikhmon"] = $targetReseller['username'];
        $_SESSION["mikhmon_role"] = 'reseller';
        $_SESSION["mikhmon_reseller_id"] = (string)$targetReseller['id'];
        $_SESSION["mikhmon_permission_mode"] = isset($targetReseller['permission_mode']) ? $targetReseller['permission_mode'] : 'Custom';
        $_SESSION["mikhmon_template_code"] = isset($targetReseller['template_code']) ? $targetReseller['template_code'] : 'NGW-001';
        $_SESSION["mikhmon_assigned_template"] = isset($targetReseller['assigned_template']) ? $targetReseller['assigned_template'] : 'default';
        $_SESSION["mikhmon_logo_filename"] = isset($targetReseller['logo_filename']) ? $targetReseller['logo_filename'] : '';
        $_SESSION["mikhmon_routers"] = isset($targetReseller['allowed_routers']) && is_array($targetReseller['allowed_routers']) ? $targetReseller['allowed_routers'] : array();
        $_SESSION["mikhmon_permissions"] = $effectivePerms;
        $_SESSION["mikhmon_last_activity"] = time();
        $_SESSION["mikhmon_login_handshake"] = 1;
        unset($_SESSION['lang']);
        if (function_exists('ngwResolveCurrentUserLang')) {
            $GLOBALS['langid'] = ngwResolveCurrentUserLang();
        }
        if (function_exists('ngwResolveCurrentUserTheme')) {
            ngwResolveCurrentUserTheme();
        }
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
        echo "<script>try{sessionStorage.setItem('ngw_tab_auth','1');sessionStorage.setItem('ngw_last_act',String(Date.now()));}catch(e){} window.location='" . $redir . "';</script>";
        exit;
    }
}

// Toggle active / disabled
if (isset($_GET['toggle-reseller']) && $_GET['toggle-reseller'] !== '') {
    $tid = $_GET['toggle-reseller'];
    $statusChanged = '';
    foreach ($resellers as $idx => $r) {
        if ((string)$r['id'] === (string)$tid) {
            $resellers[$idx]['status'] = ($r['status'] === 'active') ? 'disabled' : 'active';
            $statusChanged = $resellers[$idx]['status'];
            break;
        }
    }
    saveResellers($resellers);
    if ($statusChanged !== '') ngwRecordActivity('reseller_status_changed', array('target' => $tid, 'status' => $statusChanged));
    echo "<script>window.location='" . $baseResellerUrl . "'</script>";
    exit;
}

// Delete reseller
if (isset($_GET['remove-reseller']) && $_GET['remove-reseller'] !== '') {
    $rid = $_GET['remove-reseller'];
    $filtered = array();
    foreach ($resellers as $r) {
        if ((string)$r['id'] !== (string)$rid) {
            $filtered[] = $r;
        }
    }
    saveResellers($filtered);
    ngwRecordActivity('reseller_deleted', array('target' => $rid));
    echo "<script>window.location='" . $baseResellerUrl . "'</script>";
    exit;
}

// Save Permission Groups (Read / Write / Full / Reset Voucher)
if (isset($_POST['save_permission_groups'])) {
    $activeView = 'groups';
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
        foreach ($resellers as $rIdx => $rRow) {
            $rMode = isset($rRow['permission_mode']) ? $rRow['permission_mode'] : 'Custom';
            if (isset($permGroups[$rMode])) {
                $resellers[$rIdx]['permissions'] = $permGroups[$rMode];
            }
        }
        saveResellers($resellers);
        ngwRecordActivity('permission_groups_updated');
        $msg = "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> " . ($isBn ? 'পারমিশন গ্রুপ সফলভাবে আপডেট হয়েছে।' : 'Permission Groups updated successfully.') . "</div>";
    }
}

// Reset Permission Groups to Defaults
if (isset($_POST['reset_permission_groups'])) {
    $activeView = 'groups';
    $defs = getDefaultPermissionGroupDefinitions();
    savePermissionGroups($defs);
    $permGroups = loadPermissionGroups(true);
    foreach ($resellers as $rIdx => $rRow) {
        $rMode = isset($rRow['permission_mode']) ? $rRow['permission_mode'] : 'Custom';
        if (isset($permGroups[$rMode])) {
            $resellers[$rIdx]['permissions'] = $permGroups[$rMode];
        }
    }
    saveResellers($resellers);
    $msg = "<div class='bg-green pd-5 radius-3'><i class='fa fa-refresh'></i> " . ($isBn ? 'পারমিশন গ্রুপ ডিফল্ট সেটিংসে রিস্টোর হয়েছে।' : 'Permission Groups restored to default settings.') . "</div>";
}

// Save (Create or Update) reseller
$openModalOnLoad = false;
if (isset($_POST['save_reseller'])) {
    $editId = isset($_POST['reseller_id']) ? trim($_POST['reseller_id']) : '';
    $rUser = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', trim($_POST['r_username']));
    $rName = trim($_POST['r_name']);
    $rPass = isset($_POST['r_password']) ? (string)$_POST['r_password'] : '';
    $rStatus = (isset($_POST['r_status']) && $_POST['r_status'] === 'disabled') ? 'disabled' : 'active';
    $rMode = isset($_POST['r_permission_mode']) && in_array($_POST['r_permission_mode'], array('Read', 'Write', 'Full', 'Reset Voucher', 'Custom'), true)
        ? $_POST['r_permission_mode']
        : 'Write';
    $assignedRouters = isset($_POST['r_routers']) && is_array($_POST['r_routers']) ? array_values($_POST['r_routers']) : array();
    $postedPerms = isset($_POST['r_perms']) && is_array($_POST['r_perms']) ? $_POST['r_perms'] : array();

    $customPerms = getDefaultPermissionMap(false);
    foreach ($customPerms as $pk => $pv) {
        $customPerms[$pk] = !isProtectedAdminPermission($pk) && !empty($postedPerms[$pk]);
    }
    $customPerms = sanitizeResellerPermissions($customPerms);

    if ($rUser === '' || strtolower($rUser) === strtolower($useradm)) {
        $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> " . ($isBn ? 'অবৈধ রিসেলার ইউজারনেম (খালি বা অ্যাডমিন ইউজারনেমের সমান হতে পারবে না)।' : 'Invalid Reseller Username (cannot be empty or equal to Admin username).') . "</div>";
        $openModalOnLoad = true;
    } else {
        $duplicate = false;
        foreach ($resellers as $r) {
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
                foreach ($resellers as $idx => $r) {
                    if ((string)$r['id'] === (string)$editId) {
                        $resellers[$idx]['username'] = $rUser;
                        $resellers[$idx]['name'] = $rName !== '' ? $rName : $rUser;
                        $resellers[$idx]['status'] = $rStatus;
                        $resellers[$idx]['permission_mode'] = $rMode;
                        $resellers[$idx]['allowed_routers'] = $assignedRouters;
                        if ($rMode === 'Custom') {
                            $resellers[$idx]['permissions'] = $customPerms;
                        } else {
                            $resellers[$idx]['permissions'] = $permGroups[$rMode];
                        }
                        if ($rPass !== '') {
                            $resellers[$idx]['password_hash'] = password_hash($rPass, PASSWORD_DEFAULT);
                            $resellers[$idx]['password_enc'] = encrypt($rPass);
                        }
                        break;
                    }
                }
                saveResellers($resellers);
                ngwRecordActivity('reseller_updated', array('target' => $rUser, 'status' => $rStatus));
                ensureResellerTemplateFiles($editId);
                $msg .= "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> " . ($isBn ? ('রিসেলার <b>' . htmlspecialchars($rUser) . '</b> সফলভাবে আপডেট হয়েছে।') : ('Reseller <b>' . htmlspecialchars($rUser) . '</b> updated successfully.')) . "</div>";
            } else {
                if ($rPass === '') {
                    $rPass = '123456';
                }
                $newId = getNextResellerId($resellers);
                $rTemplateCode = sprintf('NGW-%03d', max(1, (int)$newId - 100));
                $resellers[] = array(
                    'id' => $newId,
                    'legacy_ids' => array(),
                    'username' => $rUser,
                    'name' => $rName !== '' ? $rName : $rUser,
                    'password_hash' => password_hash($rPass, PASSWORD_DEFAULT),
                    'password_enc' => encrypt($rPass),
                    'status' => $rStatus,
                    'permission_mode' => $rMode,
                    'template_code' => $rTemplateCode,
                    'assigned_template' => 'default',
                    'template_status' => 'active',
                    'logo_filename' => '',
                    'allowed_routers' => $assignedRouters,
                    'permissions' => ($rMode === 'Custom') ? $customPerms : $permGroups[$rMode],
                    'created_at' => date('Y-m-d H:i:s'),
                );
                saveResellers($resellers);
                ngwRecordActivity('reseller_created', array('target' => $rUser, 'status' => $rStatus));
                ensureResellerTemplateFiles($newId);
                $msg .= "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> " . ($isBn ? ('রিসেলার <b>' . htmlspecialchars($rUser) . '</b> সফলভাবে তৈরি হয়েছে।') : ('Reseller <b>' . htmlspecialchars($rUser) . '</b> created successfully.')) . "</div>";
            }
        }
    }
}

$resellers = loadResellers();
$defaultLinkSession = !empty($session) ? $session : (!empty($allRouters) ? $allRouters[0] : 'MikroTik-v6');
$tplEditorBaseUrl = !empty($session) ? './?hotspot=template-editor' : './admin.php?id=editor';
$upLogoBaseUrl = !empty($session) ? './?hotspot=uplogo' : './admin.php?id=uplogo';

// Map router session -> Hotspot Name for display
$routerHotspotMap = array();
foreach ($allRouters as $rSess) {
    $routerHotspotMap[$rSess] = isset($data[$rSess][4]) && explode('%', $data[$rSess][4])[1] !== ''
        ? explode('%', $data[$rSess][4])[1]
        : $rSess;
}

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
    $resellersClientMap[$rId] = array(
        'id' => $rId,
        'username' => (string)$r['username'],
        'password' => getResellerPlainPassword($r),
        'name' => (string)$r['name'],
        'status' => (string)$r['status'],
        'permission_mode' => isset($r['permission_mode']) ? (string)$r['permission_mode'] : 'Write',
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
    cursor: pointer;
    padding: 6px 10px;
}
.ngw-router-dd-panel {
    display: none;
    position: absolute;
    left: 0;
    right: 0;
    top: 100%;
    margin-top: 2px;
    background: #23282c;
    color: #f0f6fc;
    border: 1px solid #3b4248;
    border-radius: 4px;
    max-height: 210px;
    overflow-y: auto;
    z-index: 2100;
    padding: 6px 10px;
    box-shadow: 0 6px 16px rgba(0,0,0,0.5);
}
.ngw-router-dd-item {
    display: block;
    padding: 5px 4px;
    cursor: pointer;
    font-weight: normal;
    margin: 0;
}
.ngw-router-dd-item:hover {
    background: rgba(255,255,255,0.08);
    border-radius: 3px;
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
    var ddWrap = document.getElementById('routerDropdownWrap');
    var ddPanel = document.getElementById('routerDropdownPanel');
    if (ddWrap && ddPanel && !ddWrap.contains(e.target)) {
        ddPanel.style.display = 'none';
    }
});

window.addEventListener('resize', function() {
    closeResellerActionMenus();
});
window.addEventListener('scroll', function() {
    closeResellerActionMenus();
}, true);

function toggleRouterDropdown(ev) {
    if (ev) ev.stopPropagation();
    var p = document.getElementById('routerDropdownPanel');
    if (p) {
        p.style.display = (p.style.display === 'block') ? 'none' : 'block';
    }
}

function updateRouterDropdownLabel() {
    var checks = document.querySelectorAll('.ngw-router-checkbox');
    var selectedNames = [];
    for (var i = 0; i < checks.length; i++) {
        if (checks[i].checked) {
            selectedNames.push(checks[i].getAttribute('data-label') || checks[i].value);
        }
    }
    var lbl = document.getElementById('routerDropdownLabel');
    if (!lbl) return;
    if (selectedNames.length === 0) {
        lbl.innerHTML = '<?= htmlspecialchars(isset($_select_router_btn) ? $_select_router_btn : "Select Router"); ?>';
    } else if (selectedNames.length <= 2) {
        lbl.innerHTML = selectedNames.join(', ');
    } else {
        lbl.innerHTML = selectedNames.length + ' Routers Selected';
    }
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
    document.getElementById('r_permission_mode').value = 'Write';
    onPermissionModeChange('Write');

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
    document.getElementById('r_permission_mode').value = r.permission_mode;
    onPermissionModeChange(r.permission_mode);

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
        <h3 class="card-title">
          <i class="fa fa-users"></i> <a href="<?= $baseResellerUrl ?>" class="<?= $activeView === 'resellers' ? 'text-warning' : '' ?>"><?= htmlspecialchars(isset($_reseller_management) ? $_reseller_management : 'Reseller Management'); ?></a>
          &nbsp; | &nbsp;
          <a href="javascript:void(0)" onclick="openAddResellerModal()"><i class="fa fa-user-plus"></i> <?= htmlspecialchars(isset($_add_reseller_label) ? $_add_reseller_label : 'Add Reseller'); ?></a>
          &nbsp; | &nbsp;
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
              <div class="ngw-pg-toolbar">
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                  <button type="submit" name="save_permission_groups" class="btn bg-primary"><i class="fa fa-save"></i> <?= $_save ?></button>
                  <button type="submit" name="reset_permission_groups" class="btn bg-warning"><i class="fa fa-refresh"></i> <?= $isBn ? 'ডিফল্ট রিস্টোর করুন' : 'Restore Defaults' ?></button>
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
        <?php } else { ?>
        <!-- FULL-WIDTH RESELLER LIST (ONLY RESELLER LIST SHOWN BY DEFAULT) -->
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-list"></i> <?= htmlspecialchars(isset($_reseller_list_label) ? $_reseller_list_label : 'Reseller List'); ?> (<?= count($resellers); ?>)</h3>
              </div>
              <div class="card-body">
                <div class="overflow box-bordered" style="min-height: 340px;">
                  <table class="table table-sm table-bordered table-hover text-nowrap">
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
                        <tr>
                          <td class="text-center">
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
                                <a href="<?= $baseResellerUrl ?>&toggle-reseller=<?= urlencode($rId); ?>">
                                  <i class="fa <?= $r['status'] === 'active' ? 'fa-lock text-warning' : 'fa-unlock text-green'; ?>"></i> <?= $r['status'] === 'active' ? ($isBn ? 'নিষ্ক্রিয় করুন' : 'Deactivate') : ($isBn ? 'সক্রিয় করুন' : 'Activate'); ?>
                                </a>
                                <a href="javascript:void(0)" onclick="if(confirm('<?= $isBn ? 'আপনি কি নিশ্চিতভাবে এই রিসেলার মুছে ফেলতে চান: ' : 'Are you sure you want to delete reseller ' ?><?= htmlspecialchars($r['username'], ENT_QUOTES); ?>?')){window.location='<?= $baseResellerUrl ?>&remove-reseller=<?= urlencode($rId); ?>';}">
                                  <i class="fa fa-trash text-danger"></i> <?= htmlspecialchars($_delete); ?>
                                </a>
                              </div>
                            </div>
                          </td>
                          <td><b><?= htmlspecialchars($r['username']); ?></b></td>
                          <td><?= htmlspecialchars($r['name']); ?></td>
                          <td><span class="ngw-mode-chip"><?= htmlspecialchars($rModeBadge); ?></span></td>
                          <td class="text-center">
                            <a href="<?= $baseResellerUrl ?>&login-as-reseller=<?= urlencode($rId); ?>" class="btn bg-green pd-2p5 radius-3 ngw-login-btn" title="<?= htmlspecialchars((isset($_login_as_reseller_tip) ? $_login_as_reseller_tip : 'Login as Reseller') . ': ' . $r['username']); ?>">
                              <i class="fa fa-sign-in"></i> <?= htmlspecialchars(isset($_login_btn_short) ? $_login_btn_short : 'Login'); ?>
                            </a>
                          </td>
                          <td><?= $statusBadge; ?></td>
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
        <input type="hidden" name="reseller_id" id="f_reseller_id" value="">
        <table class="table table-sm">
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
            <td class="align-middle"><?= htmlspecialchars(isset($_select_router_btn) ? $_select_router_btn : 'Select Router'); ?></td>
            <td>
              <div id="routerDropdownWrap" class="ngw-router-dd-wrap">
                <button type="button" class="form-control ngw-router-dd-btn" onclick="toggleRouterDropdown(event)">
                  <span id="routerDropdownLabel"><?= htmlspecialchars(isset($_select_router_btn) ? $_select_router_btn : 'Select Router'); ?></span>
                  <i class="fa fa-caret-down"></i>
                </button>
                <div id="routerDropdownPanel" class="ngw-router-dd-panel" onclick="event.stopPropagation();">
                  <?php if (empty($allRouters)) { ?>
                    <div class="pd-5">No routers configured.</div>
                  <?php } else {
                    foreach ($allRouters as $rSess) {
                      $rHotspotName = isset($routerHotspotMap[$rSess]) ? $routerHotspotMap[$rSess] : $rSess;
                  ?>
                    <label class="ngw-router-dd-item">
                      <input type="checkbox" class="ngw-router-checkbox" name="r_routers[]" value="<?= htmlspecialchars($rSess); ?>" data-label="<?= htmlspecialchars($rHotspotName); ?>" onchange="updateRouterDropdownLabel()">
                      &nbsp;<b><?= htmlspecialchars($rHotspotName); ?></b> <small>(<?= htmlspecialchars($rSess); ?>)</small>
                    </label>
                  <?php } } ?>
                </div>
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
