<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *  NextGan WiFi Solution — Reseller Management, Permission Groups, Templates & Logos
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

// Remove reseller custom logo
if (isset($_GET['remove-reseller-logo']) && $_GET['remove-reseller-logo'] !== '') {
    $lid = $_GET['remove-reseller-logo'];
    removeResellerCustomLogo($lid);
    echo "<script>window.location='" . $baseResellerUrl . "&edit-reseller=" . urlencode($lid) . "'</script>";
    exit;
}

// Toggle active / disabled
if (isset($_GET['toggle-reseller']) && $_GET['toggle-reseller'] !== '') {
    $tid = $_GET['toggle-reseller'];
    foreach ($resellers as $idx => $r) {
        if ((string)$r['id'] === (string)$tid) {
            $resellers[$idx]['status'] = ($r['status'] === 'active') ? 'disabled' : 'active';
            break;
        }
    }
    saveResellers($resellers);
    echo "<script>window.location='" . $baseResellerUrl . "&edit-reseller=" . urlencode($tid) . "'</script>";
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
    echo "<script>window.location='" . $baseResellerUrl . "'</script>";
    exit;
}

// Save Permission Groups (Read / Write / Full)
if (isset($_POST['save_permission_groups'])) {
    $activeView = 'groups';
    $updatedGroups = array();
    foreach (array('Read', 'Write', 'Full') as $gMode) {
        $postedG = isset($_POST['grp_perms'][$gMode]) && is_array($_POST['grp_perms'][$gMode]) ? $_POST['grp_perms'][$gMode] : array();
        $gMap = getDefaultPermissionMap(false);
        foreach ($gMap as $pk => $pv) {
            $gMap[$pk] = !isProtectedAdminPermission($pk) && !empty($postedG[$pk]);
        }
        $updatedGroups[$gMode] = sanitizeResellerPermissions($gMap);
    }
    if (savePermissionGroups($updatedGroups)) {
        $permGroups = loadPermissionGroups();
        $msg = "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> Permission Groups (<b>Read</b>, <b>Write</b>, <b>Full</b>) updated. All resellers using these modes have been updated automatically.</div>";
    }
}

// Reset Permission Groups to Defaults
if (isset($_POST['reset_permission_groups'])) {
    $activeView = 'groups';
    $defs = getDefaultPermissionGroupDefinitions();
    savePermissionGroups($defs);
    $permGroups = loadPermissionGroups();
    $msg = "<div class='bg-green pd-5 radius-3'><i class='fa fa-refresh'></i> Permission Groups restored to default settings.</div>";
}

// Save (Create or Update) reseller
if (isset($_POST['save_reseller'])) {
    $editId = isset($_POST['reseller_id']) ? trim($_POST['reseller_id']) : '';
    $rUser = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', trim($_POST['r_username']));
    $rName = trim($_POST['r_name']);
    $rPass = isset($_POST['r_password']) ? (string)$_POST['r_password'] : '';
    $rStatus = (isset($_POST['r_status']) && $_POST['r_status'] === 'disabled') ? 'disabled' : 'active';
    $rMode = isset($_POST['r_permission_mode']) && in_array($_POST['r_permission_mode'], array('Read', 'Write', 'Full', 'Custom'), true)
        ? $_POST['r_permission_mode']
        : 'Write';
    $rTemplateCode = isset($_POST['r_template_code']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', strtoupper(trim($_POST['r_template_code']))) : '';
    $rAssignedTpl = isset($_POST['r_assigned_template']) && in_array($_POST['r_assigned_template'], array('default', 'thermal', 'small'), true)
        ? $_POST['r_assigned_template']
        : 'default';
    $rTplStatus = (isset($_POST['r_template_status']) && $_POST['r_template_status'] === 'inactive') ? 'inactive' : 'active';
    $assignedRouters = isset($_POST['r_routers']) && is_array($_POST['r_routers']) ? array_values($_POST['r_routers']) : array();
    $postedPerms = isset($_POST['r_perms']) && is_array($_POST['r_perms']) ? $_POST['r_perms'] : array();

    $customPerms = getDefaultPermissionMap(false);
    foreach ($customPerms as $pk => $pv) {
        $customPerms[$pk] = !isProtectedAdminPermission($pk) && !empty($postedPerms[$pk]);
    }
    $customPerms = sanitizeResellerPermissions($customPerms);

    if ($rUser === '' || strtolower($rUser) === strtolower($useradm)) {
        $msg = "<div class='bg-danger pd-5 radius-3'><i class='fa fa-ban'></i> Invalid Reseller Username (cannot be empty or equal to Admin username).</div>";
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
        } else {
            if ($editId !== '') {
                foreach ($resellers as $idx => $r) {
                    if ((string)$r['id'] === (string)$editId) {
                        $resellers[$idx]['username'] = $rUser;
                        $resellers[$idx]['name'] = $rName !== '' ? $rName : $rUser;
                        $resellers[$idx]['status'] = $rStatus;
                        $resellers[$idx]['permission_mode'] = $rMode;
                        $resellers[$idx]['template_code'] = $rTemplateCode !== '' ? $rTemplateCode : $r['template_code'];
                        $resellers[$idx]['assigned_template'] = $rAssignedTpl;
                        $resellers[$idx]['template_status'] = $rTplStatus;
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
                        // Handle optional reseller logo upload
                        if (isset($_FILES['r_logo_upload']) && !empty($_FILES['r_logo_upload']['tmp_name'])) {
                            $upRes = handleResellerLogoUpload($editId, $_FILES['r_logo_upload']);
                            if ($upRes['ok']) {
                                $resellers[$idx]['logo_filename'] = $upRes['filename'];
                            } else {
                                $msg .= "<div class='bg-danger pd-5 radius-3 mr-b-5'><i class='fa fa-ban'></i> Logo Upload Error: " . htmlspecialchars($upRes['error']) . "</div>";
                            }
                        }
                        break;
                    }
                }
                saveResellers($resellers);
                ensureResellerTemplateFiles($editId);
                $msg .= "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> Reseller <b>" . htmlspecialchars($rUser) . "</b> (ID: " . htmlspecialchars($editId) . ") updated successfully.</div>";
            } else {
                if ($rPass === '') {
                    $rPass = '123456';
                }
                $newId = getNextResellerId($resellers);
                if ($rTemplateCode === '') {
                    $rTemplateCode = sprintf('NGW-%03d', max(1, (int)$newId - 100));
                }
                $newLogoFilename = '';
                if (isset($_FILES['r_logo_upload']) && !empty($_FILES['r_logo_upload']['tmp_name'])) {
                    $upRes = handleResellerLogoUpload($newId, $_FILES['r_logo_upload']);
                    if ($upRes['ok']) {
                        $newLogoFilename = $upRes['filename'];
                    } else {
                        $msg .= "<div class='bg-danger pd-5 radius-3 mr-b-5'><i class='fa fa-ban'></i> Logo Upload Error: " . htmlspecialchars($upRes['error']) . "</div>";
                    }
                }
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
                    'assigned_template' => $rAssignedTpl,
                    'template_status' => $rTplStatus,
                    'logo_filename' => $newLogoFilename,
                    'allowed_routers' => $assignedRouters,
                    'permissions' => ($rMode === 'Custom') ? $customPerms : $permGroups[$rMode],
                    'created_at' => date('Y-m-d H:i:s'),
                );
                saveResellers($resellers);
                ensureResellerTemplateFiles($newId);
                $editId = $newId;
                $msg .= "<div class='bg-green pd-5 radius-3'><i class='fa fa-check'></i> Reseller <b>" . htmlspecialchars($rUser) . "</b> (ID: " . htmlspecialchars($newId) . ") created successfully.</div>";
            }
        }
    }
}

$resellers = loadResellers();
$editingReseller = null;
$reqEditId = isset($_GET['edit-reseller']) ? $_GET['edit-reseller'] : (isset($_GET['edit']) ? $_GET['edit'] : (isset($editId) ? $editId : ''));
if ($reqEditId === 'new') {
    $editingReseller = null;
} elseif ($reqEditId !== '') {
    $editingReseller = findResellerByIdOrUsername($reqEditId);
} elseif (!empty($resellers) && !isset($_GET['new'])) {
    $editingReseller = $resellers[0];
}

$nextCandidateId = getNextResellerId($resellers);
$formId = $editingReseller ? (string)$editingReseller['id'] : '';
$formUser = $editingReseller ? $editingReseller['username'] : '';
$formName = $editingReseller ? $editingReseller['name'] : '';
$formStatus = $editingReseller ? $editingReseller['status'] : 'active';
$formMode = $editingReseller && !empty($editingReseller['permission_mode']) ? $editingReseller['permission_mode'] : 'Write';
$formTplCode = $editingReseller && !empty($editingReseller['template_code']) ? $editingReseller['template_code'] : sprintf('NGW-%03d', max(1, (int)$nextCandidateId - 100));
$formAssignedTpl = $editingReseller && !empty($editingReseller['assigned_template']) ? $editingReseller['assigned_template'] : 'default';
$formTplStatus = $editingReseller && !empty($editingReseller['template_status']) ? $editingReseller['template_status'] : 'active';
$formLogoFile = $editingReseller && !empty($editingReseller['logo_filename']) ? $editingReseller['logo_filename'] : '';
$formRouters = $editingReseller && isset($editingReseller['allowed_routers']) ? $editingReseller['allowed_routers'] : array();
$formPerms = $editingReseller ? resolveEffectiveResellerPermissions($editingReseller) : $permGroups['Write'];

// Count enabled permissions per group for concise group summaries
function getGroupSummaryStats($groupMap, $catalog) {
    $totalEnabled = 0;
    $catSummaries = array();
    foreach ($catalog as $catName => $items) {
        $cCount = 0;
        foreach ($items as $k => $lbl) {
            if (!empty($groupMap[$k])) {
                $totalEnabled++;
                $cCount++;
            }
        }
        if ($cCount > 0) {
            $catSummaries[] = $catName . ' (' . $cCount . ')';
        }
    }
    return array('total' => $totalEnabled, 'categories' => implode(', ', $catSummaries));
}
$readSummary = getGroupSummaryStats($permGroups['Read'], $permCatalog);
$writeSummary = getGroupSummaryStats($permGroups['Write'], $permCatalog);
$fullSummary = getGroupSummaryStats($permGroups['Full'], $permCatalog);
?>
<script>
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
    var summaryRead = document.getElementById('groupSummary_Read');
    var summaryWrite = document.getElementById('groupSummary_Write');
    var summaryFull = document.getElementById('groupSummary_Full');
    if (summaryRead) summaryRead.style.display = (mode === 'Read') ? 'block' : 'none';
    if (summaryWrite) summaryWrite.style.display = (mode === 'Write') ? 'block' : 'none';
    if (summaryFull) summaryFull.style.display = (mode === 'Full') ? 'block' : 'none';
    if (customBox) customBox.style.display = (mode === 'Custom') ? 'block' : 'none';
}
function setPermPreset(mode) {
    var boxes = document.querySelectorAll('.perm-checkbox');
    for (var i = 0; i < boxes.length; i++) {
        if (boxes[i].disabled) continue;
        if (mode === 'all') {
            boxes[i].checked = true;
        } else if (mode === 'none') {
            boxes[i].checked = false;
        }
    }
}
</script>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          <i class="fa fa-users"></i> Reseller Management
          &nbsp; | &nbsp;
          <a href="<?= $baseResellerUrl ?>&edit-reseller=new" class="<?= $activeView === 'resellers' && !$formId ? 'text-warning' : '' ?>"><i class="fa fa-user-plus"></i> Add Reseller</a>
          &nbsp; | &nbsp;
          <a href="<?= $baseResellerUrl ?>&view=groups" class="<?= $activeView === 'groups' ? 'text-warning' : '' ?>"><i class="fa fa-sliders"></i> Permission Groups (Read / Write / Full)</a>
          &nbsp; | &nbsp;
          <a href="./admin.php?id=sessions"><i class="fa fa-server"></i> <?= $_router_list ?></a>
        </h3>
      </div>
      <div class="card-body">
        <?php if ($msg !== '') { echo "<div class='mr-b-10'>" . $msg . "</div>"; } ?>

        <?php if ($activeView === 'groups') { ?>
        <!-- PERMISSION GROUPS CONFIGURATION SECTION -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">
              <i class="fa fa-sliders"></i> Configure Permission Groups (Read / Write / Full)
              &nbsp; | &nbsp;
              <a href="<?= $baseResellerUrl ?>"><i class="fa fa-arrow-left"></i> Back to Reseller List</a>
            </h3>
          </div>
          <div class="card-body">
            <p style="margin-bottom: 10px;">
              Configure the default permissions included in the <b>Read</b>, <b>Write</b>, and <b>Full</b> reseller permission groups.
              Protected Administrator-only and Reseller Management operations are locked and can never be granted to resellers.
            </p>
            <form method="post" action="<?= $baseResellerUrl ?>&view=groups">
              <div class="mr-b-10">
                <button type="submit" name="save_permission_groups" class="btn bg-primary"><i class="fa fa-save"></i> Save Permission Groups</button>
                <button type="submit" name="reset_permission_groups" class="btn bg-warning"><i class="fa fa-refresh"></i> Restore Default Groups</button>
              </div>
              <div class="overflow box-bordered" style="max-height: 70vh;">
                <table class="table table-sm table-bordered table-hover">
                  <thead>
                    <tr>
                      <th style="width: 28%;">Category</th>
                      <th style="width: 42%;">Operation Permission</th>
                      <th class="text-center" style="width: 10%;">Read</th>
                      <th class="text-center" style="width: 10%;">Write</th>
                      <th class="text-center" style="width: 10%;">Full</th>
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
                          <?php foreach (array('Read', 'Write', 'Full') as $gMode) {
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
              <div class="mr-t-10">
                <button type="submit" name="save_permission_groups" class="btn bg-primary"><i class="fa fa-save"></i> Save Permission Groups</button>
              </div>
            </form>
          </div>
        </div>
        <?php } else { ?>
        <!-- RESELLER ACCOUNTS & TEMPLATE/LOGO/PERMISSION ASSIGNMENT -->
        <div class="row">
          <!-- Left Column: Reseller Accounts List -->
          <div class="col-5">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-list"></i> Reseller List (<?= count($resellers); ?>)</h3>
              </div>
              <div class="card-body">
                <div class="overflow box-bordered" style="max-height: 75vh;">
                  <table class="table table-sm table-bordered table-hover text-nowrap">
                    <thead>
                      <tr>
                        <th class="text-center">Action</th>
                        <th>ID</th>
                        <th><?= $_user_name ?></th>
                        <th>Mode</th>
                        <th>Template</th>
                        <th>Routers</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($resellers)) { ?>
                        <tr><td colspan="7" class="text-center">No reseller accounts configured.</td></tr>
                      <?php } else {
                        foreach ($resellers as $r) {
                          $isCurrent = ((string)$formId === (string)$r['id']);
                          $rRouters = isset($r['allowed_routers']) && !empty($r['allowed_routers']) ? implode(', ', $r['allowed_routers']) : 'None';
                          $rModeBadge = isset($r['permission_mode']) ? $r['permission_mode'] : 'Custom';
                          $rTplBadge = (isset($r['template_code']) ? $r['template_code'] : 'NGW-001') . ' (' . ucfirst(isset($r['assigned_template']) ? $r['assigned_template'] : 'default') . ')';
                          $statusBadge = ($r['status'] === 'active')
                            ? "<b class='text-green'>Active</b>"
                            : "<b class='text-red'>Disabled</b>";
                      ?>
                        <tr style="<?= $isCurrent ? 'font-weight:bold;' : '' ?>">
                          <td class="text-center">
                            <a href="<?= $baseResellerUrl ?>&edit-reseller=<?= urlencode($r['id']); ?>" title="Edit <?= htmlspecialchars($r['username']); ?>"><i class="fa fa-edit text-primary"></i></a>
                            &nbsp;
                            <a href="<?= $baseResellerUrl ?>&toggle-reseller=<?= urlencode($r['id']); ?>" title="<?= $r['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> <?= htmlspecialchars($r['username']); ?>">
                              <i class="fa <?= $r['status'] === 'active' ? 'fa-unlock text-green' : 'fa-lock text-warning'; ?>"></i>
                            </a>
                            &nbsp;
                            <a href="<?= $baseResellerUrl ?>&remove-reseller=<?= urlencode($r['id']); ?>" title="Delete <?= htmlspecialchars($r['username']); ?>">
                              <i class="fa fa-minus-square text-danger"></i>
                            </a>
                          </td>
                          <td><code><?= htmlspecialchars($r['id']); ?></code></td>
                          <td>
                            <a href="<?= $baseResellerUrl ?>&edit-reseller=<?= urlencode($r['id']); ?>">
                              <?= htmlspecialchars($r['username']); ?>
                            </a>
                            <br><small><?= htmlspecialchars($r['name']); ?></small>
                          </td>
                          <td><span class="pd-2p5 radius-3 bg-primary"><?= htmlspecialchars($rModeBadge); ?></span></td>
                          <td><code><?= htmlspecialchars($rTplBadge); ?></code></td>
                          <td><?= htmlspecialchars($rRouters); ?></td>
                          <td><?= $statusBadge; ?></td>
                        </tr>
                      <?php } } ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <!-- Right Column: Create / Edit Reseller, Template, Logo & Permission Mode -->
          <div class="col-7">
            <form autocomplete="off" method="post" enctype="multipart/form-data" action="<?= $baseResellerUrl ?><?= $formId ? '&edit-reseller=' . urlencode($formId) : '&edit-reseller=new' ?>">
              <input type="hidden" name="reseller_id" value="<?= htmlspecialchars($formId); ?>">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title">
                    <i class="fa <?= $formId ? 'fa-edit' : 'fa-user-plus'; ?>"></i>
                    <?= $formId ? 'Edit Reseller #' . htmlspecialchars($formId) . ': ' . htmlspecialchars($formUser) : 'Add New Reseller (ID #' . htmlspecialchars($nextCandidateId) . ')'; ?>
                  </h3>
                </div>
                <div class="card-body">
                  <div class="mr-b-10">
                    <button type="submit" name="save_reseller" class="btn bg-primary"><i class="fa fa-save"></i> <?= $_save ?></button>
                    <a class="btn bg-warning" href="<?= $baseResellerUrl ?>&edit-reseller=new"><i class="fa fa-plus"></i> New Reseller</a>
                    <a class="btn bg-green" href="<?= $baseResellerUrl ?>&view=groups"><i class="fa fa-sliders"></i> Configure Groups</a>
                  </div>

                  <table class="table table-sm">
                    <tr>
                      <td class="align-middle" style="width: 32%;">Internal Reseller ID</td>
                      <td>
                        <input class="form-control" type="text" value="<?= htmlspecialchars($formId !== '' ? $formId : $nextCandidateId); ?>" readonly disabled>
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle"><?= $_user_name ?></td>
                      <td>
                        <input class="form-control" type="text" name="r_username" value="<?= htmlspecialchars($formUser); ?>" placeholder="e.g. kiosk_utara" required="1">
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle"><?= $_name ?></td>
                      <td>
                        <input class="form-control" type="text" name="r_name" value="<?= htmlspecialchars($formName); ?>" placeholder="Outlet / Reseller Display Name" required="1">
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle"><?= $_password ?> <?= $formId ? '<small>(Leave blank to keep)</small>' : '' ?></td>
                      <td>
                        <div class="input-group">
                          <div class="input-group-11 col-box-10">
                            <input class="group-item group-item-l" id="r_password" type="password" name="r_password" value="" placeholder="<?= $formId ? 'Enter new password to reset' : 'Password'; ?>" <?= $formId ? '' : 'required="1"' ?>>
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
                      <td class="align-middle">Account Status</td>
                      <td>
                        <select class="form-control" name="r_status">
                          <option value="active" <?= $formStatus === 'active' ? 'selected' : ''; ?>>Active (Can Login)</option>
                          <option value="disabled" <?= $formStatus === 'disabled' ? 'selected' : ''; ?>>Disabled (Suspended)</option>
                        </select>
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle">Assigned Routers<br><small>Server-side isolated</small></td>
                      <td>
                        <?php if (empty($allRouters)) { ?>
                          <span>No routers configured yet.</span>
                        <?php } else {
                          foreach ($allRouters as $rSess) {
                            $checked = in_array($rSess, $formRouters, true) ? 'checked' : '';
                            $rHotspotName = isset($data[$rSess][4]) ? explode('%', $data[$rSess][4])[1] : $rSess;
                        ?>
                          <label style="display:inline-block; margin-right: 14px; cursor:pointer;">
                            <input type="checkbox" name="r_routers[]" value="<?= htmlspecialchars($rSess); ?>" <?= $checked; ?>>
                            <b><?= htmlspecialchars($rSess); ?></b> (<?= htmlspecialchars($rHotspotName); ?>)
                          </label>
                        <?php } } ?>
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle">Template Code &amp; Layout</td>
                      <td>
                        <div class="row">
                          <div class="col-4">
                            <input class="form-control" type="text" name="r_template_code" value="<?= htmlspecialchars($formTplCode); ?>" placeholder="NGW-001" title="Unique Template Code">
                          </div>
                          <div class="col-4">
                            <select class="form-control" name="r_assigned_template" title="Assigned Voucher Template Variant">
                              <option value="default" <?= $formAssignedTpl === 'default' ? 'selected' : ''; ?>>Default Template</option>
                              <option value="thermal" <?= $formAssignedTpl === 'thermal' ? 'selected' : ''; ?>>Thermal Template</option>
                              <option value="small" <?= $formAssignedTpl === 'small' ? 'selected' : ''; ?>>Small Template</option>
                            </select>
                          </div>
                          <div class="col-4">
                            <select class="form-control" name="r_template_status" title="Template Status">
                              <option value="active" <?= $formTplStatus === 'active' ? 'selected' : ''; ?>>Template Active</option>
                              <option value="inactive" <?= $formTplStatus === 'inactive' ? 'selected' : ''; ?>>Inactive (Fallback)</option>
                            </select>
                          </div>
                        </div>
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle">Reseller Logo<br><small>PNG / JPG / WebP (&le;2MB)</small></td>
                      <td>
                        <?php
                        $hasCustomLogo = false;
                        $previewLogoUrl = './img/logo.png';
                        if ($formId !== '' && $formLogoFile !== '') {
                            $relCheck = './uploads/reseller-logos/' . $formId . '/' . $formLogoFile;
                            if (is_file($relCheck)) {
                                $hasCustomLogo = true;
                                $previewLogoUrl = $relCheck . '?t=' . time();
                            }
                        }
                        ?>
                        <div class="mr-b-5">
                          <img src="<?= htmlspecialchars($previewLogoUrl); ?>" alt="Reseller Logo Preview" style="height: 32px; background: #fff; padding: 2px; border-radius: 3px; vertical-align: middle;">
                          <span style="margin-left: 8px;">
                            <?php if ($hasCustomLogo) { ?>
                              <b class="text-green">Custom Logo Active</b> (<code>uploads/reseller-logos/<?= htmlspecialchars($formId); ?>/<?= htmlspecialchars($formLogoFile); ?></code>)
                              &nbsp;
                              <a class="btn bg-danger" style="padding: 2px 6px; font-size: 11px;" href="<?= $baseResellerUrl ?>&remove-reseller-logo=<?= urlencode($formId); ?>" title="Remove Custom Logo &amp; Return to Default"><i class="fa fa-trash"></i> Reset to Default</a>
                            <?php } else { ?>
                              <small>Using Default Logo Fallback (<code>img/logo.png</code>)</small>
                            <?php } ?>
                          </span>
                        </div>
                        <input class="form-control" type="file" name="r_logo_upload" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                      </td>
                    </tr>
                    <tr>
                      <td class="align-middle">Permission Mode</td>
                      <td>
                        <select class="form-control" id="r_permission_mode" name="r_permission_mode" onchange="onPermissionModeChange(this.value)">
                          <option value="Read" <?= $formMode === 'Read' ? 'selected' : ''; ?>>Read (View-Only Group)</option>
                          <option value="Write" <?= $formMode === 'Write' ? 'selected' : ''; ?>>Write (Standard Voucher Operations Group)</option>
                          <option value="Full" <?= $formMode === 'Full' ? 'selected' : ''; ?>>Full (All Non-Admin Reseller Operations Group)</option>
                          <option value="Custom" <?= $formMode === 'Custom' ? 'selected' : ''; ?>>Custom (Manual Granular Checklist)</option>
                        </select>
                      </td>
                    </tr>
                  </table>

                  <!-- Concise Group Summaries when Read, Write, or Full is selected -->
                  <div id="groupSummary_Read" class="box box-bordered pd-10 mr-t-10" style="display: <?= $formMode === 'Read' ? 'block' : 'none'; ?>;">
                    <b><i class="fa fa-eye text-primary"></i> Read Mode Group Summary (<?= $readSummary['total']; ?> permissions enabled):</b><br>
                    <small><?= htmlspecialchars($readSummary['categories']); ?></small><br>
                    <small class="text-warning">Individual checklist is hidden in Read mode. Switch to <b>Custom</b> to select individual permissions or click <a href="<?= $baseResellerUrl ?>&view=groups"><b>Configure Groups</b></a> to edit the Read group.</small>
                  </div>

                  <div id="groupSummary_Write" class="box box-bordered pd-10 mr-t-10" style="display: <?= $formMode === 'Write' ? 'block' : 'none'; ?>;">
                    <b><i class="fa fa-pencil text-green"></i> Write Mode Group Summary (<?= $writeSummary['total']; ?> permissions enabled):</b><br>
                    <small><?= htmlspecialchars($writeSummary['categories']); ?></small><br>
                    <small class="text-warning">Individual checklist is hidden in Write mode. Switch to <b>Custom</b> to select individual permissions or click <a href="<?= $baseResellerUrl ?>&view=groups"><b>Configure Groups</b></a> to edit the Write group.</small>
                  </div>

                  <div id="groupSummary_Full" class="box box-bordered pd-10 mr-t-10" style="display: <?= $formMode === 'Full' ? 'block' : 'none'; ?>;">
                    <b><i class="fa fa-unlock-alt text-warning"></i> Full Mode Group Summary (<?= $fullSummary['total']; ?> permissions enabled):</b><br>
                    <small><?= htmlspecialchars($fullSummary['categories']); ?></small><br>
                    <small class="text-warning">Grants all non-admin reseller operations. Protected Administrator-only and Reseller Management privileges remain strictly blocked.</small>
                  </div>

                  <!-- Individual Checklist (Shown only when Custom mode is selected) -->
                  <div id="customPermChecklist" class="card mr-t-10" style="display: <?= $formMode === 'Custom' ? 'block' : 'none'; ?>;">
                    <div class="card-header">
                      <h3 class="card-title">
                        <i class="fa fa-lock"></i> Custom Granular Operation Permissions
                        <span style="font-size: 12px; font-weight: normal;">
                          &nbsp; | &nbsp; Quick Select:
                          <a href="javascript:void(0)" onclick="setPermPreset('all')">Enable All Allowed</a> &middot;
                          <a href="javascript:void(0)" onclick="setPermPreset('none')">Disable All</a>
                        </span>
                      </h3>
                    </div>
                    <div class="card-body">
                      <?php foreach ($permCatalog as $groupName => $groupPerms) { ?>
                        <div style="margin-bottom: 10px;">
                          <div style="font-weight: bold; padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 6px;">
                            <i class="fa fa-check-square-o"></i> <?= htmlspecialchars($groupName); ?>
                          </div>
                          <div class="row">
                            <?php foreach ($groupPerms as $pKey => $pLabel) {
                              $isProtected = isProtectedAdminPermission($pKey);
                              $isChecked = !$isProtected && !empty($formPerms[$pKey]) ? 'checked' : '';
                            ?>
                              <div class="col-6" style="padding: 2px 8px;">
                                <label style="cursor:<?= $isProtected ? 'not-allowed' : 'pointer'; ?>; font-weight:normal; opacity:<?= $isProtected ? '0.6' : '1'; ?>;">
                                  <input type="checkbox" class="perm-checkbox" data-perm="<?= htmlspecialchars($pKey); ?>" name="r_perms[<?= htmlspecialchars($pKey); ?>]" value="1" <?= $isChecked; ?> <?= $isProtected ? 'disabled' : ''; ?>>
                                  <?= htmlspecialchars($pLabel); ?>
                                </label>
                              </div>
                            <?php } ?>
                          </div>
                        </div>
                      <?php } ?>
                    </div>
                  </div>

                  <div class="mr-t-10">
                    <button type="submit" name="save_reseller" class="btn bg-primary"><i class="fa fa-save"></i> <?= $_save ?></button>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>
        <?php } ?>
      </div>
    </div>
  </div>
</div>
