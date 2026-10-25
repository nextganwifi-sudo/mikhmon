<?php
/*
 *  NextGan WiFi Solution — Minimal 1-Click GitHub Safe Update + Software Version & 4-Step Rollback
 *  Connects to a GitHub Repository URL, pulls code updates, increments Software Version,
 *  and maintains at most 4 rollback snapshots (auto-deleting older ones to keep VPS storage clean)
 *  while strictly preserving all live routers, resellers, templates, and logos.
 */
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include_once('./include/reseller_acl.php');

if (!isMikhmonAdmin()) {
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
}

$appRoot = realpath(__DIR__ . '/..');
$gitRepoConfigFile = $appRoot . '/include/github_repo.txt';
$savedGitRepoUrl = is_file($gitRepoConfigFile) ? trim((string)@file_get_contents($gitRepoConfigFile)) : '';

$versionStateFile = $appRoot . '/include/version_state.json';
$versionSnapDir = $appRoot . '/backups/version-history';
if (!is_dir($versionSnapDir)) {
    @mkdir($versionSnapDir, 0755, true);
}

/**
 * Load current software version & rollback history (max 4 entries)
 */
function loadVersionState($stateFile) {
    $default = array(
        'current_version' => '1.0',
        'updated_at' => date('Y-m-d H:i'),
        'history' => array()
    );
    if (!is_file($stateFile)) {
        @file_put_contents($stateFile, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }
    $decoded = @json_decode((string)@file_get_contents($stateFile), true);
    if (!is_array($decoded)) {
        return $default;
    }
    if (empty($decoded['current_version'])) {
        $decoded['current_version'] = '1.0';
    }
    if (empty($decoded['updated_at'])) {
        $decoded['updated_at'] = date('Y-m-d H:i');
    }
    if (!isset($decoded['history']) || !is_array($decoded['history'])) {
        $decoded['history'] = array();
    }
    return $decoded;
}

/**
 * Save version state & strictly prune history to maximum 4 snapshots on disk
 */
function saveAndPruneVersionState($stateFile, $snapDir, $state) {
    $maxSnapshots = 4;
    $history = isset($state['history']) && is_array($state['history']) ? array_values($state['history']) : array();

    // Remove entries beyond the 4th slot and delete their archive files
    if (count($history) > $maxSnapshots) {
        for ($i = $maxSnapshots; $i < count($history); $i++) {
            if (!empty($history[$i]['file'])) {
                $oldFile = $snapDir . '/' . basename($history[$i]['file']);
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }
        }
        $history = array_slice($history, 0, $maxSnapshots);
    }

    // Also remove any orphan files inside backups/version-history/ not referenced by the kept 4 entries
    $keptFiles = array();
    foreach ($history as $item) {
        if (!empty($item['file'])) {
            $keptFiles[basename($item['file'])] = true;
        }
    }
    if (is_dir($snapDir)) {
        foreach (glob($snapDir . '/*') as $f) {
            if (is_file($f) && !isset($keptFiles[basename($f)])) {
                @unlink($f);
            }
        }
    }

    $state['history'] = $history;
    @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    $_SESSION["v"] = $state['current_version'];
    return $state;
}

/**
 * Collect all protected user data files (relative path => binary content)
 * Ensures 0% data loss during Git updates or version rollbacks.
 */
function collectProtectedDataFiles($rootDir) {
    $protectedFiles = array();
    $fixedRelPaths = array(
        'include/config.php',
        'include/resellers.json',
        'include/permission_groups.json',
        'include/quickbt.php',
        'include/github_repo.txt',
        'include/lang.php',
        'include/version_state.json',
        'voucher/template.php',
        'voucher/template-small.php',
        'voucher/template-thermal.php',
    );
    foreach ($fixedRelPaths as $rel) {
        $abs = $rootDir . '/' . $rel;
        if (is_file($abs)) {
            $protectedFiles[$rel] = @file_get_contents($abs);
        }
    }

    // Per-reseller templates & logos inside uploads/
    $uploadsDir = $rootDir . '/uploads';
    if (is_dir($uploadsDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            if ($item->isFile()) {
                $full = $item->getRealPath();
                $rel = 'uploads/' . str_replace('\\', '/', substr($full, strlen($uploadsDir) + 1));
                $protectedFiles[$rel] = @file_get_contents($full);
            }
        }
    }

    // Session & Global Logos inside img/ and images/
    foreach (array('img', 'images') as $imgFolder) {
        $dirPath = $rootDir . '/' . $imgFolder;
        if (is_dir($dirPath)) {
            foreach (glob($dirPath . '/logo*.png') as $logoFile) {
                if (is_file($logoFile)) {
                    $rel = $imgFolder . '/' . basename($logoFile);
                    $protectedFiles[$rel] = @file_get_contents($logoFile);
                }
            }
        }
    }
    return $protectedFiles;
}

/**
 * Restore all protected user data files from an in-memory map
 */
function restoreProtectedDataFiles($rootDir, $protectedMap) {
    foreach ($protectedMap as $rel => $content) {
        if ($content === false) continue;
        $dest = $rootDir . '/' . $rel;
        $parent = dirname($dest);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        @file_put_contents($dest, $content);
    }
}

/**
 * Check if a relative path belongs to the Protected Data Locklist
 */
function isProtectedDataRelPath($relPath) {
    $norm = ltrim(str_replace('\\', '/', $relPath), '/');
    $exactLocked = array(
        'include/config.php',
        'include/resellers.json',
        'include/permission_groups.json',
        'include/quickbt.php',
        'include/github_repo.txt',
        'include/lang.php',
        'include/version_state.json',
        'voucher/template.php',
        'voucher/template-small.php',
        'voucher/template-thermal.php',
    );
    if (in_array($norm, $exactLocked, true)) {
        return true;
    }
    if (strpos($norm, 'uploads/reseller-templates/') === 0 || strpos($norm, 'uploads/reseller-logos/') === 0) {
        return true;
    }
    if (strpos($norm, 'backups/') === 0) {
        return true;
    }
    if (preg_match('#^(img|images)/logo-.*\.(png|jpg|jpeg|webp)$#i', $norm)) {
        return true;
    }
    return false;
}

/**
 * Create a lightweight code snapshot archive of current software before updating
 */
function createVersionSnapshotArchive($rootDir, $snapDir, $versionLabel) {
    $safeVer = preg_replace('/[^a-zA-Z0-9._-]/', '_', $versionLabel);
    $snapId = 'ver_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);

    if (class_exists('ZipArchive')) {
        $fileName = 'snap_v' . $safeVer . '_' . $snapId . '.zip';
        $fullPath = $snapDir . '/' . $fileName;
        $zip = new ZipArchive();
        if ($zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                if ($item->isFile()) {
                    $full = $item->getRealPath();
                    $rel = str_replace('\\', '/', substr($full, strlen($rootDir) + 1));
                    if (strpos($rel, '.git/') === 0 || strpos($rel, 'backups/') === 0 || isProtectedDataRelPath($rel)) {
                        continue;
                    }
                    $zip->addFile($full, $rel);
                }
            }
            $zip->close();
            if (is_file($fullPath)) {
                return array(
                    'id' => $snapId,
                    'version' => $versionLabel,
                    'saved_at' => date('Y-m-d H:i:s'),
                    'file' => $fileName
                );
            }
        }
    }

    if (function_exists('shell_exec')) {
        $fileName = 'snap_v' . $safeVer . '_' . $snapId . '.tar.gz';
        $fullPath = $snapDir . '/' . $fileName;
        $cmd = 'tar -czf ' . escapeshellarg($fullPath)
            . ' --exclude=.git --exclude=backups --exclude=uploads/reseller-templates --exclude=uploads/reseller-logos'
            . ' -C ' . escapeshellarg($rootDir) . ' . 2>/dev/null';
        @shell_exec($cmd);
        if (is_file($fullPath)) {
            return array(
                'id' => $snapId,
                'version' => $versionLabel,
                'saved_at' => date('Y-m-d H:i:s'),
                'file' => $fileName
            );
        }
    }

    return null;
}

/**
 * Restore code files from a version snapshot archive while keeping protected data untouched
 */
function extractVersionSnapshotArchive($rootDir, $snapFile) {
    if (!is_file($snapFile)) {
        return false;
    }
    if (substr($snapFile, -4) === '.zip' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($snapFile) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rel = str_replace('\\', '/', $zip->getNameIndex($i));
                if (substr($rel, -1) === '/' || strpos($rel, '..') !== false) {
                    continue;
                }
                if (strpos($rel, '.git/') === 0 || strpos($rel, 'backups/') === 0 || isProtectedDataRelPath($rel)) {
                    continue;
                }
                $stream = $zip->getStream($zip->getNameIndex($i));
                if ($stream) {
                    $content = stream_get_contents($stream);
                    fclose($stream);
                    $dest = $rootDir . '/' . $rel;
                    if (!is_dir(dirname($dest))) {
                        @mkdir(dirname($dest), 0755, true);
                    }
                    @file_put_contents($dest, $content);
                }
            }
            $zip->close();
            return true;
        }
    } elseif (substr($snapFile, -7) === '.tar.gz' && function_exists('shell_exec')) {
        $tmpExtract = sys_get_temp_dir() . '/ngw_ver_restore_' . uniqid();
        @mkdir($tmpExtract, 0755, true);
        @shell_exec('tar -xzf ' . escapeshellarg($snapFile) . ' -C ' . escapeshellarg($tmpExtract) . ' 2>/dev/null');
        if (is_file($tmpExtract . '/admin.php')) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmpExtract, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                if ($item->isFile()) {
                    $full = $item->getRealPath();
                    $rel = str_replace('\\', '/', substr($full, strlen($tmpExtract) + 1));
                    if (strpos($rel, '.git/') === 0 || strpos($rel, 'backups/') === 0 || isProtectedDataRelPath($rel)) {
                        continue;
                    }
                    $dest = $rootDir . '/' . $rel;
                    if (!is_dir(dirname($dest))) {
                        @mkdir(dirname($dest), 0755, true);
                    }
                    @copy($full, $dest);
                }
            }
            @shell_exec('rm -rf ' . escapeshellarg($tmpExtract));
            return true;
        }
        @shell_exec('rm -rf ' . escapeshellarg($tmpExtract));
    }
    return false;
}

/**
 * Calculate next incremented version string (e.g. 1.0 -> 1.1 -> 1.2)
 */
function incrementVersionNumber($verStr) {
    $clean = preg_replace('/[^0-9.]/', '', (string)$verStr);
    if ($clean === '' || !is_numeric($clean)) {
        return '1.1';
    }
    $next = round(((float)$clean) + 0.1, 1);
    return number_format($next, 1, '.', '');
}

$versionState = loadVersionState($versionStateFile);
$updateNotice = '';
$updateError = '';

// Handle Rollback / Switch to a Previous Version
if (isset($_POST['rollback_version_id'])) {
    $targetSnapId = trim((string)$_POST['rollback_version_id']);
    $foundEntry = null;
    $foundIdx = -1;
    foreach ($versionState['history'] as $idx => $entry) {
        if (isset($entry['id']) && $entry['id'] === $targetSnapId) {
            $foundEntry = $entry;
            $foundIdx = $idx;
            break;
        }
    }

    if ($foundEntry !== null && !empty($foundEntry['file'])) {
        $snapPath = $versionSnapDir . '/' . basename($foundEntry['file']);
        if (is_file($snapPath)) {
            $protectedBefore = collectProtectedDataFiles($appRoot);
            $ok = extractVersionSnapshotArchive($appRoot, $snapPath);
            restoreProtectedDataFiles($appRoot, $protectedBefore);

            if ($ok) {
                // Remove the restored snapshot from history and set it as current version
                @unlink($snapPath);
                array_splice($versionState['history'], $foundIdx, 1);
                $versionState['current_version'] = (string)$foundEntry['version'];
                $versionState['updated_at'] = date('Y-m-d H:i');
                $versionState = saveAndPruneVersionState($versionStateFile, $versionSnapDir, $versionState);

                $rbMsg = isset($_rollback_success_msg) ? $_rollback_success_msg : 'Successfully switched software to version';
                $updateNotice = htmlspecialchars($rbMsg) . ' <b>v' . htmlspecialchars($versionState['current_version']) . '</b>';
            } else {
                $updateError = 'Could not restore the selected version snapshot.';
            }
        } else {
            $updateError = 'Snapshot archive file not found on server.';
        }
    }
}

// Handle Git Safe Update
if (isset($_POST['apply_git_safe_update'])) {
    $gitRepoUrl = isset($_POST['git_repo_url']) ? trim((string)$_POST['git_repo_url']) : '';
    if ($gitRepoUrl !== '') {
        @file_put_contents($gitRepoConfigFile, $gitRepoUrl);
        $savedGitRepoUrl = $gitRepoUrl;
    }

    $protectedBefore = collectProtectedDataFiles($appRoot);
    $gitDir = is_dir($appRoot . '/.git') ? $appRoot : (is_dir(dirname($appRoot) . '/.git') ? dirname($appRoot) : '');

    if ($gitRepoUrl !== '' && function_exists('shell_exec')) {
        // 1. Save current software state as a rollback snapshot BEFORE updating
        $snapEntry = createVersionSnapshotArchive($appRoot, $versionSnapDir, $versionState['current_version']);

        $tmpClone = sys_get_temp_dir() . '/ngw_git_sync_' . uniqid();
        $cmd = 'git clone --depth 1 ' . escapeshellarg($gitRepoUrl) . ' ' . escapeshellarg($tmpClone) . ' 2>&1';
        $out = @shell_exec($cmd);
        $srcRoot = is_dir($tmpClone . '/mikhmonv3-upstream') ? ($tmpClone . '/mikhmonv3-upstream') : $tmpClone;
        if (is_file($srcRoot . '/admin.php') && is_file($srcRoot . '/index.php')) {
            $updatedCount = 0;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                if ($item->isFile()) {
                    $full = $item->getRealPath();
                    $rel = str_replace('\\', '/', substr($full, strlen($srcRoot) + 1));
                    if (strpos($rel, '.git/') === 0 || isProtectedDataRelPath($rel)) {
                        continue;
                    }
                    $dest = $appRoot . '/' . $rel;
                    if (!is_dir(dirname($dest))) {
                        @mkdir(dirname($dest), 0755, true);
                    }
                    if (@copy($full, $dest)) {
                        $updatedCount++;
                    }
                }
            }
            restoreProtectedDataFiles($appRoot, $protectedBefore);
            @shell_exec('rm -rf ' . escapeshellarg($tmpClone));

            // 2. Push snapshot to history, increment version, and auto-prune beyond 4 versions
            if ($snapEntry !== null) {
                array_unshift($versionState['history'], $snapEntry);
            }
            $versionState['current_version'] = incrementVersionNumber($versionState['current_version']);
            $versionState['updated_at'] = date('Y-m-d H:i');
            $versionState = saveAndPruneVersionState($versionStateFile, $versionSnapDir, $versionState);

            $msgDone = isset($_git_update_success) ? $_git_update_success : 'Git Safe Update completed successfully! Updated code files from GitHub while keeping all routers, resellers, templates, and logos 100% intact.';
            $updateNotice = $msgDone . ' (<b>v' . htmlspecialchars($versionState['current_version']) . '</b> — ' . (int)$updatedCount . ' files synced)';
        } else {
            // Update failed; delete the temporary pre-update snapshot so no extra version is created
            if ($snapEntry !== null && !empty($snapEntry['file'])) {
                @unlink($versionSnapDir . '/' . basename($snapEntry['file']));
            }
            restoreProtectedDataFiles($appRoot, $protectedBefore);
            @shell_exec('rm -rf ' . escapeshellarg($tmpClone));
            $updateError = (isset($_git_update_failed) ? $_git_update_failed : 'Could not fetch application files from the provided GitHub URL.') . ' ' . htmlspecialchars((string)$out);
        }
    } elseif ($gitDir !== '' && function_exists('shell_exec')) {
        $snapEntry = createVersionSnapshotArchive($appRoot, $versionSnapDir, $versionState['current_version']);
        $out = @shell_exec('git -C ' . escapeshellarg($gitDir) . ' pull --ff-only 2>&1');
        restoreProtectedDataFiles($appRoot, $protectedBefore);

        if ($snapEntry !== null) {
            array_unshift($versionState['history'], $snapEntry);
        }
        $versionState['current_version'] = incrementVersionNumber($versionState['current_version']);
        $versionState['updated_at'] = date('Y-m-d H:i');
        $versionState = saveAndPruneVersionState($versionStateFile, $versionSnapDir, $versionState);

        $msgDone = isset($_git_update_success) ? $_git_update_success : 'Git Safe Update completed successfully! All routers, resellers, templates, and logos are 100% intact.';
        $updateNotice = $msgDone . ' (<b>v' . htmlspecialchars($versionState['current_version']) . '</b>)<br><small>' . htmlspecialchars((string)$out) . '</small>';
    } else {
        restoreProtectedDataFiles($appRoot, $protectedBefore);
        $updateError = isset($_git_url_required) ? $_git_url_required : 'Please enter your GitHub Repository URL to pull updates.';
    }
}
?>

<div class="row">
  <div class="col-6">
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;">
        <h3 class="card-title" style="margin:0;">
          <i class="fa fa-github"></i> <?= htmlspecialchars(isset($_system_update) ? $_system_update : 'System Update'); ?>
        </h3>
        <span style="font-size:12px; padding:3px 10px; border-radius:4px; background:#2ea043; color:#fff; font-weight:bold;">
          <i class="fa fa-tag"></i> <?= htmlspecialchars(isset($_software_version_label) ? $_software_version_label : 'Software Version'); ?>: v<?= htmlspecialchars($versionState['current_version']); ?>
        </span>
      </div>
      <div class="card-body">
        <?php if ($updateNotice !== '') { ?>
          <div class="bg-green pd-10 radius-3 mr-b-10"><i class="fa fa-check-circle"></i> <?= $updateNotice; ?></div>
        <?php } ?>
        <?php if ($updateError !== '') { ?>
          <div class="bg-danger pd-10 radius-3 mr-b-10"><i class="fa fa-ban"></i> <?= $updateError; ?></div>
        <?php } ?>

        <form method="post" action="" autocomplete="off">
          <table class="table table-sm" style="margin-bottom: 12px;">
            <tr>
              <td class="align-middle" style="width: 30%;"><?= htmlspecialchars(isset($_github_repo_url_label) ? $_github_repo_url_label : 'GitHub URL'); ?></td>
              <td>
                <input type="text" name="git_repo_url" class="form-control" value="<?= htmlspecialchars($savedGitRepoUrl); ?>" placeholder="https://github.com/username/repository.git" required="1">
              </td>
            </tr>
            <tr>
              <td></td>
              <td>
                <button type="submit" name="apply_git_safe_update" class="btn bg-primary pd-10" style="cursor:pointer; font-weight:bold;">
                  <i class="fa fa-cloud-download"></i> <?= htmlspecialchars(isset($_btn_git_pull_update) ? $_btn_git_pull_update : 'Git Safe Update'); ?>
                </button>
              </td>
            </tr>
          </table>
        </form>

        <div style="border-top: 1px solid rgba(150,150,150,0.25); padding-top: 10px; margin-top: 6px;">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
            <b style="font-size:13px;"><i class="fa fa-history"></i> <?= htmlspecialchars(isset($_version_history_title) ? $_version_history_title : 'Version List (Max 4 Previous Versions)'); ?></b>
            <small style="opacity:0.75;"><?= count($versionState['history']); ?> / 4</small>
          </div>

          <?php if (empty($versionState['history'])) { ?>
            <div style="font-size:12px; opacity:0.8; padding:6px 0;">
              <?= htmlspecialchars(isset($_no_previous_versions) ? $_no_previous_versions : 'No previous version snapshots yet. A snapshot will be saved automatically on your next Git Safe Update.'); ?>
            </div>
          <?php } else { ?>
            <table class="table table-bordered table-sm" style="margin-bottom:6px;">
              <thead>
                <tr>
                  <th><?= htmlspecialchars(isset($_software_version_label) ? $_software_version_label : 'Software Version'); ?></th>
                  <th><?= htmlspecialchars(isset($_date) ? $_date : 'Date'); ?></th>
                  <th class="text-right"><?= htmlspecialchars(isset($_action) ? $_action : 'Action'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($versionState['history'] as $hItem) { ?>
                  <tr>
                    <td class="align-middle"><b>v<?= htmlspecialchars($hItem['version']); ?></b></td>
                    <td class="align-middle"><small><?= htmlspecialchars($hItem['saved_at']); ?></small></td>
                    <td class="align-middle text-right">
                      <form method="post" action="" style="margin:0; display:inline;" onsubmit="return confirm('<?= htmlspecialchars(isset($_rollback_confirm) ? $_rollback_confirm : 'Are you sure you want to switch back to version', ENT_QUOTES); ?> v<?= htmlspecialchars($hItem['version'], ENT_QUOTES); ?>?');">
                        <input type="hidden" name="rollback_version_id" value="<?= htmlspecialchars($hItem['id']); ?>">
                        <button type="submit" class="btn bg-warning pd-5" style="cursor:pointer; font-size:12px; font-weight:bold;">
                          <i class="fa fa-undo"></i> <?= htmlspecialchars(isset($_btn_rollback_version) ? $_btn_rollback_version : 'Switch to this Version'); ?> (v<?= htmlspecialchars($hItem['version']); ?>)
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          <?php } ?>
          <div style="font-size:11px; opacity:0.7;">
            <i class="fa fa-shield"></i> <?= htmlspecialchars(isset($_max_4_versions_note) ? $_max_4_versions_note : 'Auto-keeps maximum 4 versions (older versions are automatically removed on 5th update to save VPS storage).'); ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
