<?php
/*
 *  NextGan WiFi Solution — VPS Hosting, Custom Domain & 1-Click Zero-Data-Loss Safe Update Center
 *  Strictly protects routers (include/config.php), resellers (include/resellers.json),
 *  per-reseller HTML templates (uploads/reseller-templates/*), and logos (uploads/reseller-logos/*).
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
$backupDir = $appRoot . '/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

/**
 * Pure-PHP ZIP Builder for Data Backups (works even without ZipArchive extension)
 */
if (!class_exists('NextGanSafeBackupZip')) {
    class NextGanSafeBackupZip {
        private $datasec = array();
        private $ctrl_dir = array();
        private $old_offset = 0;

        private function unix2DosTime($unixtime = 0) {
            $timearray = ($unixtime == 0) ? getdate() : getdate($unixtime);
            if ($timearray['year'] < 1980) {
                $timearray['year'] = 1980; $timearray['mon'] = 1; $timearray['mday'] = 1;
                $timearray['hours'] = 0; $timearray['minutes'] = 0; $timearray['seconds'] = 0;
            }
            return (($timearray['year'] - 1980) << 25) | ($timearray['mon'] << 21) | ($timearray['mday'] << 16)
                | ($timearray['hours'] << 11) | ($timearray['minutes'] << 5) | ($timearray['seconds'] >> 1);
        }

        public function addFileContent($data, $name, $time = 0) {
            $name = str_replace('\\', '/', $name);
            $dtime = substr("00000000" . dechex($this->unix2DosTime($time)), -8);
            $hexdtime = pack('H*', $dtime[6] . $dtime[7] . $dtime[4] . $dtime[5] . $dtime[2] . $dtime[3] . $dtime[0] . $dtime[1]);
            $unc_len = strlen($data);
            $crc = crc32($data);
            $zdata = gzcompress($data, 6);
            $zdata = substr(substr($zdata, 0, strlen($zdata) - 4), 2);
            $c_len = strlen($zdata);

            $fr = "\x50\x4b\x03\x04\x14\x00\x00\x00\x08\x00" . $hexdtime
                . pack('V', $crc) . pack('V', $c_len) . pack('V', $unc_len)
                . pack('v', strlen($name)) . pack('v', 0) . $name . $zdata;
            $this->datasec[] = $fr;

            $cdrec = "\x50\x4b\x01\x02\x00\x00\x14\x00\x00\x00\x08\x00" . $hexdtime
                . pack('V', $crc) . pack('V', $c_len) . pack('V', $unc_len)
                . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0)
                . pack('v', 0) . pack('v', 0) . pack('V', 32)
                . pack('V', $this->old_offset) . $name;
            $this->old_offset += strlen($fr);
            $this->ctrl_dir[] = $cdrec;
        }

        public function getArchiveBytes() {
            $data = implode('', $this->datasec);
            $ctrldir = implode('', $this->ctrl_dir);
            return $data . $ctrldir . "\x50\x4b\x05\x06\x00\x00\x00\x00"
                . pack('v', count($this->ctrl_dir)) . pack('v', count($this->ctrl_dir))
                . pack('V', strlen($ctrldir)) . pack('V', strlen($data)) . "\x00\x00";
        }
    }
}

/**
 * Collect all protected user data files (relative path => binary content)
 */
function collectProtectedDataFiles($rootDir) {
    $protectedFiles = array();
    $fixedRelPaths = array(
        'include/config.php',
        'include/resellers.json',
        'include/permission_groups.json',
        'include/quickbt.php',
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
 * Build a ZIP archive of all protected data files and save a copy in backups/
 */
function buildProtectedDataSnapshotZip($rootDir, $saveToBackupsDir = true) {
    $files = collectProtectedDataFiles($rootDir);
    $zip = new NextGanSafeBackupZip();
    foreach ($files as $rel => $bytes) {
        if ($bytes !== false) {
            $zip->addFileContent($bytes, $rel, time());
        }
    }
    $archiveBytes = $zip->getArchiveBytes();
    $fileName = 'NextGan-Data-Backup-' . date('Ymd-His') . '.zip';
    if ($saveToBackupsDir) {
        $bDir = $rootDir . '/backups';
        if (!is_dir($bDir)) {
            @mkdir($bDir, 0755, true);
        }
        @file_put_contents($bDir . '/' . $fileName, $archiveBytes);
    }
    return array('filename' => $fileName, 'bytes' => $archiveBytes, 'count' => count($files));
}

// Handle direct script downloads & data backup downloads
if (isset($_GET['download_action'])) {
    $dlAct = $_GET['download_action'];
    if ($dlAct === 'data_backup') {
        $snap = buildProtectedDataSnapshotZip($appRoot, true);
        if (ob_get_length()) { @ob_end_clean(); }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $snap['filename'] . '"');
        header('Content-Length: ' . strlen($snap['bytes']));
        echo $snap['bytes'];
        exit;
    } elseif ($dlAct === 'vps_install_sh') {
        $shFile = $appRoot . '/deploy/vps-install.sh';
        if (is_file($shFile)) {
            if (ob_get_length()) { @ob_end_clean(); }
            header('Content-Type: text/x-shellscript');
            header('Content-Disposition: attachment; filename="vps-install.sh"');
            echo file_get_contents($shFile);
            exit;
        }
    } elseif ($dlAct === 'safe_update_sh') {
        $shFile = $appRoot . '/deploy/safe-update.sh';
        if (is_file($shFile)) {
            if (ob_get_length()) { @ob_end_clean(); }
            header('Content-Type: text/x-shellscript');
            header('Content-Disposition: attachment; filename="safe-update.sh"');
            echo file_get_contents($shFile);
            exit;
        }
    }
}

$updateNotice = '';
$updateError = '';

// Handle 1: Create Snapshot Backup on Server
if (isset($_POST['create_server_snapshot'])) {
    $snap = buildProtectedDataSnapshotZip($appRoot, true);
    $updateNotice = 'Protected Data Snapshot created successfully: <b>' . htmlspecialchars($snap['filename']) . '</b> (' . (int)$snap['count'] . ' protected data files archived).';
}

// Handle 2: 1-Click Safe Update via Git Pull
if (isset($_POST['apply_git_safe_update'])) {
    $protectedBefore = collectProtectedDataFiles($appRoot);
    $snap = buildProtectedDataSnapshotZip($appRoot, true);
    $gitRepoUrl = isset($_POST['git_repo_url']) ? trim($_POST['git_repo_url']) : '';

    $gitDir = is_dir($appRoot . '/.git') ? $appRoot : (is_dir(dirname($appRoot) . '/.git') ? dirname($appRoot) : '');
    if ($gitDir === '' && $gitRepoUrl !== '' && function_exists('proc_open')) {
        // Clone to temp and sync code files only
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
            $updateNotice = '1-Click Safe Git Update completed! Updated <b>' . $updatedCount . '</b> code files while preserving 100% of your routers, resellers, HTML templates, and logos (Backup: <code>' . htmlspecialchars($snap['filename']) . '</code>).';
        } else {
            @shell_exec('rm -rf ' . escapeshellarg($tmpClone));
            $updateError = 'Could not locate application root in the provided Git repository URL. Output: ' . htmlspecialchars((string)$out);
        }
    } elseif ($gitDir !== '' && function_exists('shell_exec')) {
        $out = @shell_exec('git -C ' . escapeshellarg($gitDir) . ' pull --ff-only 2>&1');
        restoreProtectedDataFiles($appRoot, $protectedBefore);
        $updateNotice = '1-Click Safe Git Pull completed and all protected data verified! (Backup: <code>' . htmlspecialchars($snap['filename']) . '</code>) <br><small>Git Output: ' . htmlspecialchars((string)$out) . '</small>';
    } else {
        restoreProtectedDataFiles($appRoot, $protectedBefore);
        $updateError = 'No local .git directory found. Please enter your GitHub Repository URL in the box or use the <b>1-Click Safe ZIP Update</b> next to it.';
    }
}

// Handle 3: 1-Click Safe Update via Uploaded AI Studio ZIP
if (isset($_POST['apply_zip_safe_update']) && isset($_FILES['update_zip_file'])) {
    if (!isset($_FILES['update_zip_file']['tmp_name']) || !is_uploaded_file($_FILES['update_zip_file']['tmp_name'])) {
        $updateError = 'Please select a valid .zip file exported from AI Studio.';
    } elseif (!class_exists('ZipArchive')) {
        $updateError = 'PHP ZipArchive extension is required to extract uploaded ZIP packages on the server.';
    } else {
        $protectedBefore = collectProtectedDataFiles($appRoot);
        $snap = buildProtectedDataSnapshotZip($appRoot, true);
        $za = new ZipArchive();
        if ($za->open($_FILES['update_zip_file']['tmp_name']) === true) {
            $updatedCount = 0;
            $skippedProtected = 0;
            for ($i = 0; $i < $za->numFiles; $i++) {
                $entryName = str_replace('\\', '/', $za->getNameIndex($i));
                if (substr($entryName, -1) === '/') continue;

                // Strip common wrapper prefixes from AI Studio / Server ZIPs
                $rel = $entryName;
                if (strpos($rel, 'mikhmonv3-upstream/') !== false) {
                    $pos = strpos($rel, 'mikhmonv3-upstream/');
                    $rel = substr($rel, $pos + strlen('mikhmonv3-upstream/'));
                } elseif (strpos($rel, 'Mikhmon Server/mikhmon/') !== false) {
                    $pos = strpos($rel, 'Mikhmon Server/mikhmon/');
                    $rel = substr($rel, $pos + strlen('Mikhmon Server/mikhmon/'));
                }

                // Skip non-web root files if top-level project zip
                if (strpos($rel, 'node_modules/') === 0 || strpos($rel, '.git/') === 0 || $rel === 'server.ts' || $rel === 'package.json') {
                    continue;
                }

                if (isProtectedDataRelPath($rel)) {
                    $skippedProtected++;
                    continue;
                }

                $streamContent = $za->getFromIndex($i);
                if ($streamContent !== false) {
                    $destPath = $appRoot . '/' . ltrim($rel, '/');
                    $destDir = dirname($destPath);
                    if (!is_dir($destDir)) {
                        @mkdir($destDir, 0755, true);
                    }
                    if (@file_put_contents($destPath, $streamContent) !== false) {
                        $updatedCount++;
                    }
                }
            }
            $za->close();
            // Always re-apply protected data files from pre-update memory snapshot
            restoreProtectedDataFiles($appRoot, $protectedBefore);
            $updateNotice = '1-Click Safe ZIP Update completed! Updated <b>' . $updatedCount . '</b> application code files and protected <b>' . count($protectedBefore) . '</b> live data files (Routers, Resellers, Templates & Logos untouched). Pre-update backup saved as <code>' . htmlspecialchars($snap['filename']) . '</code>.';
        } else {
            $updateError = 'Failed to open uploaded ZIP archive.';
        }
    }
}

// Handle 4: Restore Data Backup ZIP
if (isset($_POST['restore_data_backup']) && isset($_FILES['backup_zip_file'])) {
    if (!isset($_FILES['backup_zip_file']['tmp_name']) || !is_uploaded_file($_FILES['backup_zip_file']['tmp_name'])) {
        $updateError = 'Please select a valid Data Backup .zip file to restore.';
    } elseif (!class_exists('ZipArchive')) {
        $updateError = 'PHP ZipArchive extension is required to extract backup archives.';
    } else {
        $za = new ZipArchive();
        if ($za->open($_FILES['backup_zip_file']['tmp_name']) === true) {
            $restoredCount = 0;
            for ($i = 0; $i < $za->numFiles; $i++) {
                $entryName = ltrim(str_replace('\\', '/', $za->getNameIndex($i)), '/');
                if (substr($entryName, -1) === '/') continue;
                if (isProtectedDataRelPath($entryName)) {
                    $bytes = $za->getFromIndex($i);
                    if ($bytes !== false) {
                        $dest = $appRoot . '/' . $entryName;
                        if (!is_dir(dirname($dest))) {
                            @mkdir(dirname($dest), 0755, true);
                        }
                        if (@file_put_contents($dest, $bytes) !== false) {
                            $restoredCount++;
                        }
                    }
                }
            }
            $za->close();
            $updateNotice = 'Data Backup restored successfully! Restored <b>' . $restoredCount . '</b> protected data files (Routers, Resellers, HTML Templates & Logos).';
        } else {
            $updateError = 'Could not open Data Backup ZIP archive.';
        }
    }
}

$protectedNow = collectProtectedDataFiles($appRoot);
$resellersCount = count(loadResellers());
$routersCount = count(getConfiguredRouterSessions());
$defaultSess = !empty($session) ? $session : 'MikroTik-v6';
$baseSelfUrl = (isset($_GET['hotspot']) && $_GET['hotspot'] === 'system-update')
    ? ('./?hotspot=system-update&session=' . urlencode($defaultSess))
    : ('./admin.php?id=system-update' . (!empty($session) ? '&session=' . urlencode($session) : ''));
?>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          <i class="fa fa-cloud-upload"></i> <?= htmlspecialchars(isset($_sys_update_title) ? $_sys_update_title : 'VPS Hosting, Custom Domain & 1-Click Safe Update Center'); ?>
          &nbsp; | &nbsp;
          <a href="./admin.php?id=sessions"><i class="fa fa-server"></i> <?= $_router_list ?></a>
          &nbsp; | &nbsp;
          <a href="./admin.php?id=resellers"><i class="fa fa-users"></i> <?= isset($_reseller) ? $_reseller : 'Reseller' ?></a>
        </h3>
      </div>
      <div class="card-body">
        <?php if ($updateNotice !== '') { ?>
          <div class="bg-green pd-10 radius-3 mr-b-10"><i class="fa fa-check-circle"></i> <?= $updateNotice; ?></div>
        <?php } ?>
        <?php if ($updateError !== '') { ?>
          <div class="bg-danger pd-10 radius-3 mr-b-10"><i class="fa fa-ban"></i> <?= $updateError; ?></div>
        <?php } ?>

        <!-- ROW 1: ZERO-DATA-LOSS SAFE UPDATE & DATA BACKUP -->
        <div class="row">
          <!-- Left: 1-Click Safe Update (AI Studio ZIP & Git Pull) -->
          <div class="col-6">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-refresh"></i> <?= htmlspecialchars(isset($_safe_update_box_title) ? $_safe_update_box_title : '1-Click Zero-Data-Loss Safe Update (AI Studio to VPS)'); ?></h3>
              </div>
              <div class="card-body">
                <p style="margin-top:0;">
                  <?= isset($_safe_update_box_desc) ? $_safe_update_box_desc : 'Update your live VPS software whenever you make changes in AI Studio. All <b>Routers, Resellers, Passwords, Custom HTML Templates, and Logos</b> are strictly locked and preserved.'; ?>
                </p>

                <!-- Option A: Safe ZIP Upload -->
                <div class="box box-bordered pd-10 mr-b-10">
                  <h4 style="margin:0 0 6px 0;"><i class="fa fa-file-archive-o text-green"></i> <?= htmlspecialchars(isset($_safe_zip_title) ? $_safe_zip_title : 'Method 1: Upload Update ZIP from AI Studio (Recommended)'); ?></h4>
                  <small style="display:block; margin-bottom:8px;">
                    <?= isset($_safe_zip_hint) ? $_safe_zip_hint : 'Download the ZIP from AI Studio (or Server ZIP) and upload it here. Only code files will be updated; your live data will never be overwritten.'; ?>
                  </small>
                  <form method="post" enctype="multipart/form-data" action="">
                    <div class="input-group">
                      <div class="input-group-8 col-box-8">
                        <input type="file" name="update_zip_file" accept=".zip,application/zip" class="group-item group-item-l" required="1">
                      </div>
                      <div class="input-group-4 col-box-4">
                        <button type="submit" name="apply_zip_safe_update" class="btn bg-green" style="width:100%; padding:6px 10px;">
                          <i class="fa fa-check"></i> <?= htmlspecialchars(isset($_btn_apply_zip_update) ? $_btn_apply_zip_update : 'Apply Safe Update'); ?>
                        </button>
                      </div>
                    </div>
                  </form>
                </div>

                <!-- Option B: 1-Click Git Pull Update -->
                <div class="box box-bordered pd-10">
                  <h4 style="margin:0 0 6px 0;"><i class="fa fa-github text-primary"></i> <?= htmlspecialchars(isset($_safe_git_title) ? $_safe_git_title : 'Method 2: 1-Click Git Pull Safe Update'); ?></h4>
                  <small style="display:block; margin-bottom:8px;">
                    <?= isset($_safe_git_hint) ? $_safe_git_hint : 'If your VPS is connected to GitHub, click below to automatically back up your data, pull the latest code changes, and restore all live data.'; ?>
                  </small>
                  <form method="post" action="">
                    <div class="input-group">
                      <div class="input-group-8 col-box-8">
                        <input type="text" name="git_repo_url" class="group-item group-item-l" placeholder="Optional Git Repo URL (or leave blank if .git exists)">
                      </div>
                      <div class="input-group-4 col-box-4">
                        <button type="submit" name="apply_git_safe_update" class="btn bg-primary" style="width:100%; padding:6px 10px;">
                          <i class="fa fa-cloud-download"></i> <?= htmlspecialchars(isset($_btn_git_pull_update) ? $_btn_git_pull_update : 'Git Safe Update'); ?>
                        </button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>

          <!-- Right: Protected Data Locklist & 1-Click Backup/Restore -->
          <div class="col-6">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-shield text-green"></i> <?= htmlspecialchars(isset($_protected_data_title) ? $_protected_data_title : 'Protected Data Locklist & 1-Click Backup'); ?></h3>
              </div>
              <div class="card-body">
                <table class="table table-sm table-bordered">
                  <thead>
                    <tr>
                      <th>Protected Data Item</th>
                      <th>Path</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td><b>MikroTik Routers (API &amp; SSH)</b></td>
                      <td><code>include/config.php</code></td>
                      <td><span class="text-green"><i class="fa fa-lock"></i> Protected (<?= (int)$routersCount; ?> Routers)</span></td>
                    </tr>
                    <tr>
                      <td><b>Resellers &amp; Permissions</b></td>
                      <td><code>include/resellers.json</code></td>
                      <td><span class="text-green"><i class="fa fa-lock"></i> Protected (<?= (int)$resellersCount; ?> Resellers)</span></td>
                    </tr>
                    <tr>
                      <td><b>Per-Reseller HTML Templates</b></td>
                      <td><code>uploads/reseller-templates/*</code></td>
                      <td><span class="text-green"><i class="fa fa-lock"></i> Protected</span></td>
                    </tr>
                    <tr>
                      <td><b>Per-Reseller &amp; Session Logos</b></td>
                      <td><code>uploads/reseller-logos/*, img/logo-*</code></td>
                      <td><span class="text-green"><i class="fa fa-lock"></i> Protected (<?= count($protectedNow); ?> files)</span></td>
                    </tr>
                  </tbody>
                </table>

                <div class="mr-t-10 mr-b-10">
                  <a class="btn bg-green" href="<?= $baseSelfUrl; ?>&download_action=data_backup">
                    <i class="fa fa-download"></i> <?= htmlspecialchars(isset($_btn_dl_data_backup) ? $_btn_dl_data_backup : 'Download Data Backup (.zip)'); ?>
                  </a>
                  <form method="post" action="" style="display:inline;">
                    <button type="submit" name="create_server_snapshot" class="btn bg-blue">
                      <i class="fa fa-save"></i> <?= htmlspecialchars(isset($_btn_save_snapshot) ? $_btn_save_snapshot : 'Save Snapshot on Server'); ?>
                    </button>
                  </form>
                </div>

                <form method="post" enctype="multipart/form-data" action="">
                  <div class="input-group">
                    <div class="input-group-8 col-box-8">
                      <input type="file" name="backup_zip_file" accept=".zip,application/zip" class="group-item group-item-l" required="1">
                    </div>
                    <div class="input-group-4 col-box-4">
                      <button type="submit" name="restore_data_backup" class="btn bg-warning" style="width:100%; padding:6px 10px;">
                        <i class="fa fa-undo"></i> <?= htmlspecialchars(isset($_btn_restore_backup) ? $_btn_restore_backup : 'Restore Data ZIP'); ?>
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>

        <!-- ROW 2: COMPLETE STEP-BY-STEP PLANNING & VPS GUIDES -->
        <div class="row">
          <!-- Guide 1: Per-Reseller HTML Template & Logo Editing on VPS -->
          <div class="col-6">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-code text-blue"></i> <?= htmlspecialchars(isset($_guide_reseller_tpl_title) ? $_guide_reseller_tpl_title : '1. How to Edit Each Reseller\'s HTML Template & Logo on VPS'); ?></h3>
              </div>
              <div class="card-body">
                <ol style="padding-left:18px; margin-top:0; line-height:1.65;">
                  <li><?= isset($_guide_reseller_tpl_1) ? $_guide_reseller_tpl_1 : '<b>Edit Individual Reseller HTML Code:</b> Open <b>Template Editor</b> (or click the <code>&lt;/&gt;</code> code icon next to any reseller in <b>Reseller Management</b>). At the top-right dropdown (<code>Scope</code>), select the Reseller (e.g. <code>#101 reseller1</code>), choose <code>Default</code>, <code>Thermal</code>, or <code>Small</code>, edit the HTML code, and click <b>Save</b> &amp; <b>Publish</b>.'; ?></li>
                  <li><?= isset($_guide_reseller_tpl_2) ? $_guide_reseller_tpl_2 : '<b>Upload Individual Reseller Logo:</b> Open <b>Upload Logo</b> (or click the image icon next to any reseller in <b>Reseller Management</b>). Select the Reseller from the <b>Target Scope</b> dropdown, choose their PNG/JPG/WebP logo file, and click <b>Upload</b>.'; ?></li>
                  <li><?= isset($_guide_reseller_tpl_3) ? $_guide_reseller_tpl_3 : '<b>Automatic Isolation:</b> Each reseller\'s HTML templates are stored in <code>uploads/reseller-templates/{id}/</code> and logos in <code>uploads/reseller-logos/{id}/</code>. Whenever that reseller prints vouchers, their own HTML code and logo are used automatically.'; ?></li>
                </ol>
                <div>
                  <a class="btn bg-primary" href="./admin.php?id=editor&template=default&session=<?= urlencode($defaultSess); ?>"><i class="fa fa-code"></i> <?= $_template_editor ?></a>
                  <a class="btn bg-purple" href="./admin.php?id=uplogo&session=<?= urlencode($defaultSess); ?>"><i class="fa fa-upload"></i> <?= $_upload_logo ?></a>
                  <a class="btn bg-green" href="./admin.php?id=resellers"><i class="fa fa-users"></i> <?= isset($_reseller) ? $_reseller : 'Reseller' ?></a>
                </div>
              </div>
            </div>
          </div>

          <!-- Guide 2: LightNode VPS + Custom Domain Setup -->
          <div class="col-6">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title"><i class="fa fa-globe text-green"></i> <?= htmlspecialchars(isset($_guide_vps_domain_title) ? $_guide_vps_domain_title : '2. How to Host on LightNode VPS with Your Custom Domain'); ?></h3>
              </div>
              <div class="card-body">
                <ol style="padding-left:18px; margin-top:0; line-height:1.65;">
                  <li><?= isset($_guide_vps_step_1) ? $_guide_vps_step_1 : '<b>Point Domain DNS to LightNode VPS IP:</b> In your domain DNS panel (Cloudflare / Namecheap / GoDaddy), add an <b>A Record</b> for <code>@</code> pointing to your LightNode VPS Public IP, and an <b>A Record</b> for <code>www</code> pointing to the same Public IP.'; ?></li>
                  <li><?= isset($_guide_vps_step_2) ? $_guide_vps_step_2 : '<b>Upload &amp; Run 1-Command Auto-Installer on Ubuntu/Debian VPS:</b> SSH into your LightNode VPS as <code>root</code>, upload the application folder to <code>/var/www/nextgan-wifi</code>, and run:'; ?></li>
                </ol>
                <pre style="background:#161b22; color:#58a6ff; padding:8px 10px; border-radius:4px; font-size:12px; overflow-x:auto;">sudo bash /var/www/nextgan-wifi/deploy/vps-install.sh --domain yourdomain.com</pre>
                <div style="margin-top:8px;">
                  <a class="btn bg-green" href="<?= $baseSelfUrl; ?>&download_action=vps_install_sh"><i class="fa fa-download"></i> Download vps-install.sh</a>
                  <a class="btn bg-blue" href="<?= $baseSelfUrl; ?>&download_action=safe_update_sh"><i class="fa fa-download"></i> Download safe-update.sh</a>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>
