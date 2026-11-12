<?php
/*
 *  NextGan WiFi Solution — Minimal 1-Click GitHub Safe Update + Software Version & 10-Step Rollback
 *  Connects to a GitHub Repository URL, pulls code updates, increments Software Version,
 *  and maintains at most 10 rollback snapshots (auto-deleting older ones to keep VPS storage clean)
 *  while strictly preserving all live routers, resellers, templates, and logos.
 */
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include_once('./include/reseller_acl.php');
include_once('./include/data_recovery.php');

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
$dataSnapDir = $appRoot . '/backups/data-snapshots';
if (!is_dir($dataSnapDir)) {
    @mkdir($dataSnapDir, 0755, true);
}

/**
 * Load current software version & rollback history (max 10 entries)
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
 * Save version state & strictly prune history to maximum 10 snapshots on disk
 */
function saveAndPruneVersionState($stateFile, $snapDir, $state) {
    $maxSnapshots = 10;
    $history = isset($state['history']) && is_array($state['history']) ? array_values($state['history']) : array();

    // Remove entries beyond the 10th slot and delete their archive files
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

    // Also remove any orphan files inside backups/version-history/ not referenced by the kept 10 entries
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
        'include/admin_users.json',
        'include/backup_admin.json',
        'include/resellers.json',
        'include/user_prefs.json',
        'include/permission_groups.json',
        'include/active_sessions.php',
        'include/activity_log.php',
        'include/vpn_routers.json',
        'include/pppoe_areas.json',
        'include/voice_call_services.json',
        'include/quickbt.php',
        'include/github_repo.txt',
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
        'include/admin_users.json',
        'include/backup_admin.json',
        'include/resellers.json',
        'include/user_prefs.json',
        'include/permission_groups.json',
        'include/active_sessions.php',
        'include/activity_log.php',
        'include/vpn_routers.json',
        'include/pppoe_areas.json',
        'include/voice_call_services.json',
        'include/quickbt.php',
        'include/github_repo.txt',
        'include/version_state.json',
        'voucher/template.php',
        'voucher/template-small.php',
        'voucher/template-thermal.php',
    );
    if (in_array($norm, $exactLocked, true)) {
        return true;
    }
    if (strpos($norm, 'uploads/reseller-templates/') === 0 || strpos($norm, 'uploads/reseller-logos/') === 0 || $norm === 'uploads/user_prefs.json') {
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
$updateInfo = '';
$updateChangedFiles = array();
$recoveryReport = null;
$isBnUi = (isset($langid) && $langid === 'bn') || (isset($_SESSION['lang']) && $_SESSION['lang'] === 'bn');

// Always run an automatic non-destructive recovery & vault sync pass
if (function_exists('ngwPerformDeepDataRecoveryAndMerge')) {
    $autoRec = ngwPerformDeepDataRecoveryAndMerge($appRoot);
    if (!empty($autoRec['routers_recovered']) || !empty($autoRec['resellers_recovered']) || !empty($autoRec['admins_recovered'])) {
        $recoveryReport = $autoRec;
    }
}

// Handle Download Full Data Backup (.json)
if (isset($_POST['download_data_backup'])) {
    $backupBundle = array(
        'exported_at' => date('Y-m-d H:i:s'),
        'app' => 'NextGen WiFi Solution',
        'version' => $versionState['current_version'],
        'files' => array(),
    );
    $exportRelPaths = array(
        'include/config.php',
        'include/resellers.json',
        'include/admin_users.json',
        'include/backup_admin.json',
        'include/permission_groups.json',
        'include/vpn_routers.json',
        'include/pppoe_areas.json',
        'include/voice_call_services.json',
        'include/user_prefs.json',
        'uploads/user_prefs.json',
        'voucher/template.php',
        'voucher/template-small.php',
        'voucher/template-thermal.php',
    );
    foreach ($exportRelPaths as $rel) {
        $abs = $appRoot . '/' . $rel;
        if (is_file($abs)) {
            $backupBundle['files'][$rel] = base64_encode((string)@file_get_contents($abs));
        }
    }
    if (!headers_sent()) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="NextGen-WiFi-Data-Backup-' . date('Ymd-His') . '.json"');
        echo json_encode($backupBundle, JSON_PRETTY_PRINT);
        exit;
    }
}

// Handle Manual Deep Scan & Recover VPS Data
if (isset($_POST['run_vps_data_recovery'])) {
    if (function_exists('ngwPerformDeepDataRecoveryAndMerge')) {
        $recoveryReport = ngwPerformDeepDataRecoveryAndMerge($appRoot);
        $recRoutersCnt = count($recoveryReport['routers_recovered']);
        $recResCnt = count($recoveryReport['resellers_recovered']);
        $recAdmCnt = count($recoveryReport['admins_recovered']);
        if ($recRoutersCnt > 0 || $recResCnt > 0 || $recAdmCnt > 0 || $recoveryReport['templates_restored'] > 0) {
            $updateNotice = $isBnUi
                ? ('ডাটা রিকভারি ও মার্জ সম্পন্ন হয়েছে! নতুন যুক্ত রাউটার: <b>' . $recRoutersCnt . 'টি</b>, রিসেলার: <b>' . $recResCnt . 'টি</b>, টেমপ্লেট: <b>' . (int)$recoveryReport['templates_restored'] . 'টি</b>। বর্তমান মোট রাউটার: <b>' . (int)$recoveryReport['total_routers_now'] . 'টি</b>, মোট রিসেলার: <b>' . (int)$recoveryReport['total_resellers_now'] . 'টি</b>।')
                : ('Data recovery & merge complete! Recovered Routers: <b>' . $recRoutersCnt . '</b>, Resellers: <b>' . $recResCnt . '</b>, Templates: <b>' . (int)$recoveryReport['templates_restored'] . '</b>. Total Routers now: <b>' . (int)$recoveryReport['total_routers_now'] . '</b>, Total Resellers now: <b>' . (int)$recoveryReport['total_resellers_now'] . '</b>.');
        } else {
            $updateInfo = $isBnUi
                ? ('ভিপিএস-এর সব ব্যাকআপ ফোল্ডার, আর্কাইভ ও গিট হিস্ট্রি স্ক্যান করা হয়েছে (' . count($recoveryReport['sources_scanned']) . 'টি সোর্স)। বর্তমান মোট রাউটার: ' . (int)$recoveryReport['total_routers_now'] . 'টি এবং রিসেলার: ' . (int)$recoveryReport['total_resellers_now'] . 'টি সুরক্ষিত আছে।')
                : ('Scanned all VPS backup folders, archives, and Git history (' . count($recoveryReport['sources_scanned']) . ' sources). Currently active Routers: ' . (int)$recoveryReport['total_routers_now'] . ' and Resellers: ' . (int)$recoveryReport['total_resellers_now'] . ' are synced and protected in Vault.');
        }
    }
}

// Handle Upload & Merge Data Backup (.json or config.php or resellers.json)
if (isset($_POST['upload_data_backup']) && isset($_FILES['backup_data_file']) && is_uploaded_file($_FILES['backup_data_file']['tmp_name'])) {
    $rawUpload = (string)@file_get_contents($_FILES['backup_data_file']['tmp_name']);
    $upName = strtolower(trim((string)$_FILES['backup_data_file']['name']));
    $tempRestoreDir = rtrim(sys_get_temp_dir(), '/\\') . '/ngw_manual_import_' . uniqid();
    @mkdir($tempRestoreDir . '/include', 0700, true);
    @mkdir($tempRestoreDir . '/voucher', 0700, true);
    @mkdir($tempRestoreDir . '/uploads', 0700, true);
    $importedAny = false;

    $decodedJson = @json_decode($rawUpload, true);
    if (is_array($decodedJson) && isset($decodedJson['files']) && is_array($decodedJson['files'])) {
        // Full Backup Bundle JSON
        foreach ($decodedJson['files'] as $rel => $b64) {
            $decodedContent = @base64_decode((string)$b64, true);
            if ($decodedContent !== false) {
                $dest = $tempRestoreDir . '/' . $rel;
                if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0700, true);
                @file_put_contents($dest, $decodedContent);
                $importedAny = true;
            }
        }
    } elseif (is_array($decodedJson)) {
        // Direct resellers.json or admin_users.json
        if (isset($decodedJson[0]['allowed_routers']) || isset($decodedJson[0]['permission_mode'])) {
            @file_put_contents($tempRestoreDir . '/include/resellers.json', $rawUpload);
            $importedAny = true;
        } elseif (isset($decodedJson[0]['password_enc']) || isset($decodedJson[0]['username'])) {
            @file_put_contents($tempRestoreDir . '/include/admin_users.json', $rawUpload);
            $importedAny = true;
        }
    } elseif (strpos($rawUpload, '$data[') !== false) {
        // Direct config.php upload
        @file_put_contents($tempRestoreDir . '/include/config.php', $rawUpload);
        $importedAny = true;
    }

    if ($importedAny) {
        // Copy into live-data-vault and run deep merge
        $vaultDir = $appRoot . '/backups/pre-update-manual-' . date('Ymd-His');
        @mkdir($vaultDir . '/include', 0755, true);
        foreach (array('include/config.php', 'include/resellers.json', 'include/admin_users.json', 'include/permission_groups.json', 'include/vpn_routers.json', 'include/pppoe_areas.json') as $rel) {
            if (is_file($tempRestoreDir . '/' . $rel)) {
                @copy($tempRestoreDir . '/' . $rel, $vaultDir . '/' . $rel);
            }
        }
        @shell_exec('rm -rf ' . escapeshellarg($tempRestoreDir));
        $recoveryReport = ngwPerformDeepDataRecoveryAndMerge($appRoot);
        $updateNotice = $isBnUi
            ? ('আপলোডকৃত ব্যাকআপ ফাইল সফলভাবে মার্জ করা হয়েছে! বর্তমান মোট রাউটার: <b>' . (int)$recoveryReport['total_routers_now'] . 'টি</b>, মোট রিসেলার: <b>' . (int)$recoveryReport['total_resellers_now'] . 'টি</b>।')
            : ('Uploaded backup file merged successfully! Total Routers: <b>' . (int)$recoveryReport['total_routers_now'] . '</b>, Total Resellers: <b>' . (int)$recoveryReport['total_resellers_now'] . '</b>.');
    } else {
        @shell_exec('rm -rf ' . escapeshellarg($tempRestoreDir));
        $updateError = $isBnUi
            ? 'আপলোডকৃত ফাইলটি সঠিক ব্যাকআপ JSON, config.php অথবা resellers.json ফাইল নয়।'
            : 'Uploaded file is not a valid Backup JSON, config.php, or resellers.json file.';
    }
}

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
            if (function_exists('ngwPerformDeepDataRecoveryAndMerge')) {
                ngwPerformDeepDataRecoveryAndMerge($appRoot);
            }

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

    if (function_exists('ngwSyncToLiveDataVault')) {
        ngwSyncToLiveDataVault($appRoot);
    }
    $protectedBefore = collectProtectedDataFiles($appRoot);
    $gitDir = is_dir($appRoot . '/.git') ? $appRoot : (is_dir(dirname($appRoot) . '/.git') ? dirname($appRoot) : '');

    if ($gitRepoUrl !== '' && function_exists('shell_exec')) {
        $tmpClone = sys_get_temp_dir() . '/ngw_git_sync_' . uniqid();
        $cmd = 'git clone --depth 1 ' . escapeshellarg($gitRepoUrl) . ' ' . escapeshellarg($tmpClone) . ' 2>&1';
        $out = @shell_exec($cmd);
        if (is_dir($tmpClone . '/mikhmon') && is_file($tmpClone . '/mikhmon/admin.php')) {
            $srcRoot = $tmpClone . '/mikhmon';
        } elseif (is_dir($tmpClone . '/mikhmonv3-upstream') && is_file($tmpClone . '/mikhmonv3-upstream/admin.php')) {
            $srcRoot = $tmpClone . '/mikhmonv3-upstream';
        } else {
            $srcRoot = $tmpClone;
        }
        if (is_file($srcRoot . '/admin.php') && is_file($srcRoot . '/index.php')) {
            // Compare remote source files with the live installation first.
            // Do not snapshot, bump the version, or show success when nothing changed.
            $changedFiles = array();
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
                    $remoteHash = @hash_file('sha256', $full);
                    $localHash = is_file($dest) ? @hash_file('sha256', $dest) : false;
                    if ($remoteHash !== false && ($localHash === false || !hash_equals($localHash, $remoteHash))) {
                        $changedFiles[] = array(
                            'path' => $rel,
                            'status' => is_file($dest) ? 'modified' : 'new',
                            'source' => $full,
                            'destination' => $dest
                        );
                    }
                }
            }

            if (count($changedFiles) === 0) {
                restoreProtectedDataFiles($appRoot, $protectedBefore);
                if (function_exists('ngwPerformDeepDataRecoveryAndMerge')) {
                    $recoveryReport = ngwPerformDeepDataRecoveryAndMerge($appRoot);
                }
                @shell_exec('rm -rf ' . escapeshellarg($tmpClone));
                $updateNotice = '';
                $updateInfo = 'No new files or changes were found. The installed software is already up to date.';
            } else {
                // Only create a rollback snapshot when a real code change exists.
                $snapEntry = createVersionSnapshotArchive($appRoot, $versionSnapDir, $versionState['current_version']);
                $updatedCount = 0;
                $newFileCount = 0;
                $modifiedFileCount = 0;
                $changedFileLabels = array();

                foreach ($changedFiles as $change) {
                    $dest = $change['destination'];
                    if (!is_dir(dirname($dest))) {
                        @mkdir(dirname($dest), 0755, true);
                    }
                    if (@copy($change['source'], $dest)) {
                        $updatedCount++;
                        if ($change['status'] === 'new') {
                            $newFileCount++;
                        } else {
                            $modifiedFileCount++;
                        }
                        $changedFileLabels[] = $change['status'] . ': ' . $change['path'];
                    }
                }

                restoreProtectedDataFiles($appRoot, $protectedBefore);
                if (is_file($appRoot . '/include/data_recovery.php')) {
                    include_once($appRoot . '/include/data_recovery.php');
                }
                if (function_exists('ngwPerformDeepDataRecoveryAndMerge')) {
                    $recoveryReport = ngwPerformDeepDataRecoveryAndMerge($appRoot);
                }
                @shell_exec('rm -rf ' . escapeshellarg($tmpClone));

                if ($updatedCount > 0) {
                    if ($snapEntry !== null) {
                        array_unshift($versionState['history'], $snapEntry);
                    }
                    $versionState['current_version'] = incrementVersionNumber($versionState['current_version']);
                    $versionState['updated_at'] = date('Y-m-d H:i');
                    $versionState = saveAndPruneVersionState($versionStateFile, $versionSnapDir, $versionState);

                    $updateNotice = 'Update applied: <b>' . (int)$updatedCount . ' files changed</b> ('
                        . (int)$newFileCount . ' new, ' . (int)$modifiedFileCount . ' modified). '
                        . 'Software version: <b>v' . htmlspecialchars($versionState['current_version']) . '</b>.';
                    $updateChangedFiles = $changedFileLabels;
                } else {
                    if ($snapEntry !== null && !empty($snapEntry['file'])) {
                        @unlink($versionSnapDir . '/' . basename($snapEntry['file']));
                    }
                    $updateNotice = '';
                    $updateInfo = 'No files could be updated; no software version was changed.';
                }
            }
        } else {
            restoreProtectedDataFiles($appRoot, $protectedBefore);
            @shell_exec('rm -rf ' . escapeshellarg($tmpClone));
            $updateError = (isset($_git_update_failed) ? $_git_update_failed : 'Could not fetch application files from the provided GitHub URL.') . ' ' . htmlspecialchars((string)$out);
        }
    } elseif ($gitDir !== '' && function_exists('shell_exec')) {
        $snapEntry = createVersionSnapshotArchive($appRoot, $versionSnapDir, $versionState['current_version']);
        $out = @shell_exec('git -C ' . escapeshellarg($gitDir) . ' pull --ff-only 2>&1');
        restoreProtectedDataFiles($appRoot, $protectedBefore);
        if (function_exists('ngwPerformDeepDataRecoveryAndMerge')) {
            $recoveryReport = ngwPerformDeepDataRecoveryAndMerge($appRoot);
        }
        $pullOutput = (string)$out;
        $alreadyCurrent = (stripos($pullOutput, 'Already up to date') !== false)
            || (stripos($pullOutput, 'Already up-to-date') !== false);
        $pullChanged = (stripos($pullOutput, 'Updating ') !== false)
            || (stripos($pullOutput, 'Fast-forward') !== false);

        if ($alreadyCurrent) {
            if ($snapEntry !== null && !empty($snapEntry['file'])) {
                @unlink($versionSnapDir . '/' . basename($snapEntry['file']));
            }
            $updateNotice = '';
            $updateInfo = 'No new commits or file changes were found. The installed software is already up to date.';
        } elseif ($pullChanged) {
            if ($snapEntry !== null) {
                array_unshift($versionState['history'], $snapEntry);
            }
            $versionState['current_version'] = incrementVersionNumber($versionState['current_version']);
            $versionState['updated_at'] = date('Y-m-d H:i');
            $versionState = saveAndPruneVersionState($versionStateFile, $versionSnapDir, $versionState);

            $changedPathOutput = @shell_exec('git -C ' . escapeshellarg($gitDir) . ' diff --name-status ORIG_HEAD..HEAD 2>&1');
            $updateChangedFiles = array();
            foreach (preg_split('/\\r?\\n/', trim((string)$changedPathOutput)) as $line) {
                if (preg_match('/^[A-Z][0-9]*\\s+(.+)$/', $line, $match)) {
                    $updateChangedFiles[] = $match[1];
                }
            }
            $updateNotice = 'Update applied. Changed files: <b>' . count($updateChangedFiles) . '</b>. Software version: <b>v'
                . htmlspecialchars($versionState['current_version']) . '</b>.';
        } else {
            if ($snapEntry !== null && !empty($snapEntry['file'])) {
                @unlink($versionSnapDir . '/' . basename($snapEntry['file']));
            }
            $updateError = 'Git pull did not confirm an update. No success was reported. ' . htmlspecialchars($pullOutput);
        }
    } else {
        restoreProtectedDataFiles($appRoot, $protectedBefore);
        $updateError = isset($_git_url_required) ? $_git_url_required : 'Please enter your GitHub Repository URL to pull updates.';
    }
}

$curParsedCfg = function_exists('ngwParseConfigPhpRouters') && is_file($appRoot . '/include/config.php')
    ? ngwParseConfigPhpRouters((string)@file_get_contents($appRoot . '/include/config.php'))
    : array('routers' => array());
$curResList = is_file($appRoot . '/include/resellers.json')
    ? @json_decode((string)@file_get_contents($appRoot . '/include/resellers.json'), true)
    : array();
$liveRouterCount = isset($curParsedCfg['routers']) && is_array($curParsedCfg['routers']) ? count($curParsedCfg['routers']) : 0;
$liveResellerCount = is_array($curResList) ? count($curResList) : 0;
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
        <?php if ($updateInfo !== '') { ?>
          <div class="pd-10 radius-3 mr-b-10" style="background:rgba(128,128,128,0.12);"><i class="fa fa-info-circle"></i> <?= htmlspecialchars($updateInfo); ?></div>
        <?php } ?>
        <?php if (!empty($updateChangedFiles)) { ?>
          <div class="pd-10 radius-3 mr-b-10" style="background:rgba(46,160,67,0.08);">
            <b><i class="fa fa-list"></i> Changed files</b>
            <ul style="margin:6px 0 0 18px; padding:0; font-size:12px;">
              <?php foreach ($updateChangedFiles as $changedFile) { ?>
                <li><?= htmlspecialchars($changedFile); ?></li>
              <?php } ?>
            </ul>
          </div>
        <?php } ?>

        <form method="post" action="" autocomplete="off">
          <table class="table table-sm" style="margin-bottom: 12px;">
            <tr>
              <td class="align-middle" style="width: 30%;"><?= htmlspecialchars(isset($_github_repo_url_label) ? $_github_repo_url_label : 'GitHub URL'); ?></td>
              <td>
                <input type="text" id="ngw_git_repo_url" name="git_repo_url" class="form-control" value="<?= htmlspecialchars($savedGitRepoUrl); ?>" placeholder="https://github.com/username/repository.git" required="1">
                <div style="margin-top:5px; display:flex; align-items:center; flex-wrap:wrap; gap:5px; font-size:11px;">
                  <span style="opacity:0.75;"><?= $isBnUi ? 'দ্রুত নির্বাচন:' : 'Quick Select:'; ?></span>
                  <button type="button" class="btn bg-secondary pd-2p5 radius-3" style="font-size:10px; margin:0; cursor:pointer;" onclick="document.getElementById('ngw_git_repo_url').value='https://github.com/nextganwifi-sudo/mikhmon.git';">
                    <i class="fa fa-github"></i> nextganwifi-sudo/mikhmon.git
                  </button>
                  <button type="button" class="btn bg-secondary pd-2p5 radius-3" style="font-size:10px; margin:0; cursor:pointer;" onclick="document.getElementById('ngw_git_repo_url').value='https://github.com/nextganwifi-sudo/API.git';">
                    <i class="fa fa-github"></i> nextganwifi-sudo/API.git (AI Studio Direct)
                  </button>
                </div>
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
            <b style="font-size:13px;"><i class="fa fa-history"></i> <?= htmlspecialchars(isset($_version_history_title) ? $_version_history_title : 'Version List (Max 10 Previous Versions)'); ?></b>
            <small style="opacity:0.75;"><?= count($versionState['history']); ?> / 10</small>
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
            <i class="fa fa-shield"></i> <?= htmlspecialchars(isset($_max_4_versions_note) ? $_max_4_versions_note : 'Auto-keeps maximum 10 versions (the oldest version is automatically removed on the 11th update to save VPS storage).'); ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-6">
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;">
        <h3 class="card-title" style="margin:0;">
          <i class="fa fa-database"></i> <?= $isBnUi ? 'ভিপিএস ডাটা রিকভারি ও সেফ ভল্ট' : 'VPS Data Recovery & Safe Vault'; ?>
        </h3>
        <span style="font-size:11px; padding:3px 8px; border-radius:4px; background:#1f6feb; color:#fff; font-weight:bold;">
          <i class="fa fa-server"></i> <?= $isBnUi ? 'রাউটার:' : 'Routers:'; ?> <?= (int)$liveRouterCount; ?> | <?= $isBnUi ? 'রিসেলার:' : 'Resellers:'; ?> <?= (int)$liveResellerCount; ?>
        </span>
      </div>
      <div class="card-body">
        <p style="font-size:12px; opacity:0.85; margin-top:0;">
          <?= $isBnUi
            ? 'টার্মিনালে <code>git reset --hard</code> বা আপডেটের কারণে ভিপিএস-এর কোনো রাউটার বা রিসেলার ডাটা বাদ পড়ে থাকলে নিচের বাটনে ক্লিক করে ভিপিএস-এর সকল ব্যাকআপ ফোল্ডার, স্ন্যাপশট ও গিট হিস্ট্রি স্ক্যান করে স্বয়ংক্রিয়ভাবে মার্জ ও রিকভার করতে পারবেন।'
            : 'If any routers or resellers were overwritten by a terminal git reset, click below to deep-scan all VPS backup directories, archives, and Git reflogs to automatically recover and merge your data.'; ?>
        </p>

        <?php if (is_array($recoveryReport) && (!empty($recoveryReport['routers_recovered']) || !empty($recoveryReport['resellers_recovered']))) { ?>
          <div class="bg-green pd-10 radius-3 mr-b-10" style="font-size:12px;">
            <b><i class="fa fa-check"></i> <?= $isBnUi ? 'রিকভারকৃত ডাটা তালিকা:' : 'Recovered Data Items:'; ?></b>
            <?php if (!empty($recoveryReport['routers_recovered'])) { ?>
              <div><?= $isBnUi ? 'রাউটার:' : 'Routers:'; ?> <?= htmlspecialchars(implode(', ', $recoveryReport['routers_recovered'])); ?></div>
            <?php } ?>
            <?php if (!empty($recoveryReport['resellers_recovered'])) { ?>
              <div><?= $isBnUi ? 'রিসেলার:' : 'Resellers:'; ?> <?= htmlspecialchars(implode(', ', $recoveryReport['resellers_recovered'])); ?></div>
            <?php } ?>
          </div>
        <?php } ?>

        <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px;">
          <form method="post" action="" style="margin:0;">
            <button type="submit" name="run_vps_data_recovery" class="btn bg-green pd-10" style="cursor:pointer; font-weight:bold;">
              <i class="fa fa-magic"></i> <?= $isBnUi ? 'ভিপিএস থেকে পূর্বের ডাটা স্ক্যান ও রিকভার করুন' : 'Scan & Recover Previous VPS Data'; ?>
            </button>
          </form>

          <form method="post" action="" style="margin:0;">
            <button type="submit" name="download_data_backup" class="btn bg-primary pd-10" style="cursor:pointer; font-weight:bold;">
              <i class="fa fa-download"></i> <?= $isBnUi ? 'ডাটা ব্যাকআপ ডাউনলোড (.json)' : 'Download Data Backup (.json)'; ?>
            </button>
          </form>
        </div>

        <div style="border-top: 1px solid rgba(150,150,150,0.25); padding-top: 10px;">
          <b style="font-size:12px; display:block; margin-bottom:6px;">
            <i class="fa fa-upload"></i> <?= $isBnUi ? 'ব্যাকআপ ফাইল (.json / config.php / resellers.json) আপলোড ও মার্জ করুন:' : 'Upload & Merge Backup File (.json / config.php / resellers.json):'; ?>
          </b>
          <form method="post" action="" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="file" name="backup_data_file" class="form-control" style="flex:1; min-width:180px; font-size:12px;" required="1">
            <button type="submit" name="upload_data_backup" class="btn bg-warning pd-5" style="cursor:pointer; font-weight:bold; font-size:12px;">
              <i class="fa fa-check-circle"></i> <?= $isBnUi ? 'আপলোড ও মার্জ করুন' : 'Upload & Merge'; ?>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

