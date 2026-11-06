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
        $msg = "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> Permission Groups updated successfully.</div>";
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
    $msg = "<div class='bg-green pd-5 radius-3'><i class='fa fa-refresh'></i> Permission Groups restored to default settings.</div>";
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
        $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> Invalid Reseller Username (cannot be empty or equal to Admin username).</div>";
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
            $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> Username <b>" . htmlspecialchars($rUser) . "</b> already exists.</div>";
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
                $msg .= "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> Reseller <b>" . htmlspecialchars($rUser) . "</b> updated successfully.</div>";
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
                $msg .= "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> Reseller <b>" . htmlspecialchars($rUser) . "</b> created successfully.</div>";
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
/* The menu is portalled to body so table overflow cannot clip it. */
.ngw-action-wrap { position: relative; display: inline-block; }
.ngw-action-menu.ngw-action-menu-floating {
    display: block !important;
    position: fixed !important;
    z-index: 5000 !important;
    margin: 0 !important;
    min-width: 175px;
    max-width: min(260px, calc(100vw - 16px));
    background: #23282c !important;
    background-color: #23282c !important;
    color: #f0f6fc !important;
    opacity: 1 !important;
    visibility: visible;
    pointer-events: auto !important;
    border: 1px solid #3b4248 !important;
    border-radius: 5px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.45) !important;
    box-sizing: border-box;
}
.ngw-action-menu-floating a {
    display: block !important;
    position: relative;
    z-index: 1;
    padding: 7px 12px !important;
    background: #23282c !important;
    color: #e6edf3 !important;
    opacity: 1 !important;
    pointer-events: auto !important;
    cursor: pointer;
}
.ngw-action-menu-floating a:hover {
    background: #1f6feb !important;
    color: #fff !important;
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
    if (customBox) {
        customBox.style.display = (mode === 'Custom') ? 'block' : 'none';
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
            boxes[i].checked = (pk === 'reset_voucher');
        }
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
    passEl.placeholder = 'Leave blank to keep current password';
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
    document.getElementById('det_name').textContent = r.name;
    document.getElementById('det_status').innerHTML = (r.status === 'active')
        ? '<b class="text-green">Active</b>'
        : '<b class="text-red">Disabled</b>';
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
        routerContainer.textContent = 'No routers assigned';
    }
    var permissionCount = Number(r.granted_permissions_count || 0);
    document.getElementById('det_permission_count').textContent = permissionCount + ' permissions available';
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

        <?php if ($activeView === 'groups') { ?>
        <!-- PERMISSION GROUP CONFIGURATION SECTION -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">
              <i class="fa fa-sliders"></i> <?= htmlspecialchars(isset($_permission_group_label) ? $_permission_group_label : 'Permission Group'); ?>
              &nbsp; | &nbsp;
              <a href="<?= $baseResellerUrl ?>"><i class="fa fa-arrow-left"></i> <?= htmlspecialchars(isset($_reseller_list_label) ? $_reseller_list_label : 'Reseller List'); ?></a>
            </h3>
          </div>
          <div class="card-body">
            <form method="post" action="<?= $baseResellerUrl ?>&view=groups">
              <div class="mr-b-10">
                <button type="submit" name="save_permission_groups" class="btn bg-primary"><i class="fa fa-save"></i> <?= $_save ?></button>
                <button type="submit" name="reset_permission_groups" class="btn bg-warning"><i class="fa fa-refresh"></i> Restore Defaults</button>
              </div>
              <div class="overflow box-bordered" style="max-height: 70vh;">
                <table class="table table-sm table-bordered table-hover">
                  <thead>
                    <tr>
                      <th style="width: 24%;">Category</th>
                      <th style="width: 36%;">Operation Permission</th>
                      <th class="text-center" style="width: 10%;">Read</th>
                      <th class="text-center" style="width: 10%;">Write</th>
                      <th class="text-center" style="width: 10%;">Full</th>
                      <th class="text-center" style="width: 10%;">Reset Voucher</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($permCatalog as $catName => $catItems) { ?>
                      <?php foreach ($catItems as $pKey => $pLabel) {
                        $isProtected = isProtectedAdminPermission($pKey);
                      ?>
                        <tr>
                          <td><b><?= htmlspecialchars($catName); ?></b></td>
                          <td>
                            <?= htmlspecialchars($pLabel); ?>
                            <?php if ($isProtected) { ?>
                              <span class="text-warning"><i class="fa fa-shield"></i> Admin-Protected</span>
                            <?php } ?>
                          </td>
                          <?php foreach (array('Read', 'Write', 'Full', 'Reset Voucher') as $gMode) {
                            $gChecked = !$isProtected && !empty($permGroups[$gMode][$pKey]) ? 'checked' : '';
                          ?>
                            <td class="text-center">
                              <?php if ($isProtected) { ?>
                                <input type="checkbox" disabled title="Protected Administrator-Only Operation">
                              <?php } else { ?>
                                <input type="checkbox" name="grp_perms[<?= $gMode ?>][<?= htmlspecialchars($pKey) ?>]" value="1" <?= $gChecked ?>>
                              <?php } ?>
                            </td>
                          <?php } ?>
                        </tr>
                      <?php } ?>
                    <?php } ?>
                  </tbody>
                </table>
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
                        <th>Mode</th>
                        <th class="text-center"><?= htmlspecialchars(isset($_login_btn_short) ? $_login_btn_short : 'Login'); ?></th>
                        <th><?= isset($_status) ? $_status : 'Status' ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($resellers)) { ?>
                        <tr><td colspan="6" class="text-center">No reseller accounts configured.</td></tr>
                      <?php } else {
                        foreach ($resellers as $r) {
                          $rId = (string)$r['id'];
                          $rModeBadge = isset($r['permission_mode']) ? $r['permission_mode'] : 'Custom';
                          $rAssignedVariant = isset($r['assigned_template']) ? $r['assigned_template'] : 'default';
                          $statusBadge = ($r['status'] === 'active')
                            ? "<b class='text-green'>Active</b>"
                            : "<b class='text-red'>Disabled</b>";
                      ?>
                        <tr>
                          <td class="text-center">
                            <div class="ngw-action-wrap">
                              <button type="button" class="btn bg-primary ngw-dots-btn" aria-expanded="false" title="<?= htmlspecialchars($_action); ?>" aria-label="<?= htmlspecialchars($_action); ?>" aria-haspopup="true" onclick="var m=this.parentNode.querySelector('.ngw-action-menu');var o=m && m.style.display==='block';document.querySelectorAll('.ngw-action-menu').forEach(function(x){x.style.display='none';});document.querySelectorAll('.ngw-dots-btn').forEach(function(b){b.setAttribute('aria-expanded','false');});if(m&&!o){m.style.display='block';this.setAttribute('aria-expanded','true');}">
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
                                  <i class="fa <?= $r['status'] === 'active' ? 'fa-lock text-warning' : 'fa-unlock text-green'; ?>"></i> <?= $r['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                                </a>
                                <a href="javascript:void(0)" onclick="if(confirm('Are you sure you want to delete reseller <?= htmlspecialchars($r['username'], ENT_QUOTES); ?>?')){window.location='<?= $baseResellerUrl ?>&remove-reseller=<?= urlencode($rId); ?>';}">
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
        <div id="customPermChecklist" class="card mr-t-5" style="display: none; max-height: 240px; overflow-y: auto;">
          <div class="card-header">
            <h3 class="card-title" style="font-size:13px;">
              <i class="fa fa-lock"></i> Custom Permissions
              <span style="font-size: 11px; font-weight: normal;">
                &nbsp; | &nbsp;
                <a href="javascript:void(0)" onclick="setPermPreset('all')">All</a> &middot;
                <a href="javascript:void(0)" onclick="setPermPreset('reset_only')">Reset Only</a> &middot;
                <a href="javascript:void(0)" onclick="setPermPreset('none')">None</a>
              </span>
            </h3>
          </div>
          <div class="card-body">
            <?php foreach ($permCatalog as $groupName => $groupPerms) { ?>
              <div style="margin-bottom: 8px;">
                <div style="font-weight: bold; font-size:12px; padding: 2px 0; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 4px;">
                  <?= htmlspecialchars($groupName); ?>
                </div>
                <div class="row">
                  <?php foreach ($groupPerms as $pKey => $pLabel) {
                    $isProtected = isProtectedAdminPermission($pKey);
                  ?>
                    <div class="col-6 ngw-permission-col" style="padding: 3px 6px; font-size:12px;">
                      <label class="ngw-permission-tile" style="cursor:<?= $isProtected ? 'not-allowed' : 'pointer'; ?>; opacity:<?= $isProtected ? '0.55' : '1'; ?>;">
                        <input type="checkbox" class="perm-checkbox" data-perm="<?= htmlspecialchars($pKey); ?>" name="r_perms[<?= htmlspecialchars($pKey); ?>]" value="1" <?= $isProtected ? 'disabled' : ''; ?>>
                        <span><?= htmlspecialchars($pLabel); ?></span>
                      </label>
                    </div>
                  <?php } ?>
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
          <td style="width: 36%;"><b>Reseller ID</b></td>
          <td><code id="det_id"></code></td>
        </tr>
        <tr>
          <td><b><?= $_user_name ?></b></td>
          <td id="det_username"></td>
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
          <td><b>Permission Mode</b></td>
          <td><span id="det_mode" class="pd-2p5 radius-3 bg-primary"></span></td>
        </tr>
        <tr>
          <td><b>Assigned Routers</b></td>
          <td>
            <div class="ngw-router-detail-summary"><b id="det_router_count">0</b> assigned</div>
            <div id="det_routers" class="ngw-router-detail-list"></div>
          </td>
        </tr>
        <tr>
          <td><b>Access Permissions</b></td>
          <td id="det_permission_count"></td>
        </tr>
        <tr>
          <td><b>Template</b></td>
          <td><code id="det_template"></code></td>
        </tr>
        <tr>
          <td><b>Logo</b></td>
          <td>
            <img id="det_logo_img" src="./img/logo.png" alt="Logo" style="height:28px; background:#fff; padding:2px; border-radius:3px; vertical-align:middle;">
            &nbsp;<small id="det_logo_name"></small>
          </td>
        </tr>
        <tr>
          <td><b>Created At</b></td>
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
