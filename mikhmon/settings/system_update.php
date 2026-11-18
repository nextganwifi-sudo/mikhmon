<?php
/*
 *  NextGan WiFi Solution — Minimal 1-Click GitHub Safe Update + Software Version & 10-Step Rollback
 *  Connects to a GitHub Repository URL, pulls code updates, increments Software Version,
 *  and maintains at most 10 rollback snapshots (auto-deleting older ones to keep VPS storage clean)
 *  while strictly preserving all live routers, resellers, templates, and logos.
 */
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
error_reporting(0);
@set_time_limit(300);
@ini_set('max_execution_time', '300');

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include_once('./include/reseller_acl.php');
include_once('./include/data_recovery.php');

if (!isMikhmonAdmin() || (function_exists('isMikhmonAdminStore') && !isMikhmonAdminStore() && !hasResellerPermission('manage_global_security'))) {
    echo "<script>window.location='./admin.php?id=sessions'</script>";
    exit;
}

$sysUpdateCsrf = ngwCsrfToken('ngw_sysupdate_csrf');

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
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION["v"] = $state['current_version'];
    }
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
        'include/router_meta.json',
        'include/admin_users.json',
        'include/backup_admin.json',
        'include/resellers.json',
        'include/user_prefs.json',
        'include/permission_groups.json',
        'include/active_sessions.php',
        'include/active_sessions.json',
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
    // 30-Day Backend Voucher History files inside data/
    $dataDir = $rootDir . '/data';
    if (is_dir($dataDir)) {
        foreach (glob($dataDir . '/voucher_history_*.json') as $vhFile) {
            if (is_file($vhFile)) {
                $rel = 'data/' . basename($vhFile);
                $protectedFiles[$rel] = @file_get_contents($vhFile);
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
        'include/router_meta.json',
        'include/admin_users.json',
        'include/backup_admin.json',
        'include/resellers.json',
        'include/user_prefs.json',
        'include/permission_groups.json',
        'include/active_sessions.php',
        'include/active_sessions.json',
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
    if (strpos($norm, 'backups/') === 0 || strpos($norm, 'data/') === 0) {
        return true;
    }
    if (preg_match('#^(img|images)/logo-.*\.(png|jpg|jpeg|webp)$#i', $norm)) {
        return true;
    }
    return false;
}

/**
 * Check if a relative path should be skipped during code snapshots & Git file diffs
 */
function ngwShouldSkipSnapshotRelPath($relPath) {
    $norm = ltrim(str_replace('\\', '/', (string)$relPath), '/');
    if ($norm === '' || isProtectedDataRelPath($norm)) {
        return true;
    }
    $skipPrefixes = array(
        '.git/',
        '.aistudio/',
        'backups/',
        'node_modules/',
        'dist/',
        'mikhmon/',
        'mikhmonv3-upstream/',
        'uploads/',
        'data/',
    );
    foreach ($skipPrefixes as $pfx) {
        if ($norm === rtrim($pfx, '/') || strpos($norm, $pfx) === 0) {
            return true;
        }
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
            $dirIt = new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS);
            $filterIt = new RecursiveCallbackFilterIterator($dirIt, function ($current) use ($rootDir) {
                $full = $current->getPathname();
                if ($current->isLink()) {
                    return false;
                }
                $rel = ltrim(str_replace('\\', '/', substr($full, strlen($rootDir))), '/');
                if ($current->isDir()) {
                    return !ngwShouldSkipSnapshotRelPath($rel . '/');
                }
                return !ngwShouldSkipSnapshotRelPath($rel);
            });
            $it = new RecursiveIteratorIterator($filterIt, RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($it as $item) {
                if ($item->isFile()) {
                    $full = $item->getRealPath();
                    if (!$full || strpos($full, $rootDir) !== 0) {
                        continue;
                    }
                    $rel = ltrim(str_replace('\\', '/', substr($full, strlen($rootDir))), '/');
                    if (ngwShouldSkipSnapshotRelPath($rel)) {
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
            . ' --exclude=.git --exclude=.aistudio --exclude=backups --exclude=node_modules --exclude=dist'
            . ' --exclude=mikhmon --exclude=mikhmonv3-upstream --exclude=uploads --exclude=data'
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

/**
 * Default shortcut highlights for versions that do not yet have recorded diff metadata
 */
function ngwDefaultVersionHighlights() {
    return array(
        'bn' => array(
            'অ্যাডমিন রোল ও ৪টি শর্টকাট পারমিশন (Hotspot, PPP, Reseller ও VPN Permission)',
            'রাউটার ওপেন করার সময় লাইভ পিং ও ইনস্ট্যান্ট কানেকশন স্ট্যাটাস পপআপ',
            'রিসেলার ও অ্যাডমিনের A-to-Z অ্যাক্টিভিটি হিস্ট্রি (১ দিনের ডিফল্ট ভিউ ও কাস্টম ডেট ফিল্টার)',
            'রিসেট ভাউচারে ডেট/কমেন্ট অক্ষুণ্ণ রেখে মোবাইল মডেল, MAC ও আপটাইম ক্লিয়ার সিস্টেম',
            'মোবাইল ব্রাউজার মিনিমাইজ বা রিলোড করলেও অটো-লগআউট না হওয়ার ৩০ দিনের সেশন সুরক্ষা',
            'গিট সেফ আপডেটে রাউটার, রিসেলার, অ্যাডমিন ও টেমপ্লেট ডাটা ১০০% অক্ষুণ্ণ রাখার ভল্ট সিস্টেম',
            'প্রত্যেকটি ভার্সনের সাথে শর্টকাট ভার্সন ডিটেইলস (চেঞ্জলগ) দেখার সুবিধা'
        ),
        'en' => array(
            'Admin Store & Admin roles with 4 shortcut permissions (Hotspot, PPP, Reseller & VPN Permission)',
            'Interactive Router Connection modal with live ping verification and instant status feedback',
            'Full A-to-Z Reseller & Admin Activity History (1-day default screen + custom date filter)',
            'Smart Reset Voucher clearing device model, MAC & uptime while preserving date/comment',
            'Persistent 30-day mobile browser login session (prevents unexpected logouts on minimize/reload)',
            'Zero-loss Git Safe Update & Data Vault protecting routers, resellers, admins & templates',
            'Version Details button on every version showing shortcut release highlights & changed files'
        )
    );
}

/**
 * Build concise Bengali & English shortcut feature summaries from changed files & commit messages
 */
function ngwBuildVersionChangeSummary($changedFilesList, $fromVer, $toVer, $commitSubjects = array()) {
    $bnBullets = array();
    $enBullets = array();
    $paths = array();

    if (is_array($changedFilesList)) {
        foreach ($changedFilesList as $item) {
            if (is_array($item) && isset($item['path'])) {
                $paths[] = strtolower((string)$item['path']);
            } elseif (is_string($item)) {
                $clean = preg_replace('/^(new|modified|added|updated):\s*/i', '', trim($item));
                $paths[] = strtolower($clean);
            }
        }
    }

    $hasMatch = function($needles) use ($paths) {
        foreach ($paths as $p) {
            foreach ((array)$needles as $n) {
                if (strpos($p, strtolower($n)) !== false) {
                    return true;
                }
            }
        }
        return false;
    };

    if ($hasMatch(array('admin_settings.php', 'reseller_acl.php'))) {
        $bnBullets[] = 'অ্যাডমিন ও রিসেলার পারমিশন সিস্টেম আপডেট (Hotspot, PPP, Reseller, VPN শর্টকাট পারমিশন ও রোল কন্ট্রোল)';
        $enBullets[] = 'Admin & Reseller role permission control updated (Hotspot, PPP, Reseller & VPN shortcut permissions)';
    }
    if ($hasMatch(array('settings/sessions.php', 'status/ping.php', 'process/photspot.php'))) {
        $bnBullets[] = 'রাউটার লিস্ট, লাইভ পিং চেক এবং স্মার্ট কানেক্টিং স্ট্যাটাস সিস্টেম উন্নত করা হয়েছে';
        $enBullets[] = 'Router list, live ping verification, and smart connection status modal improved';
    }
    if ($hasMatch(array('settings/activity.php', 'activity_logger.php'))) {
        $bnBullets[] = 'রিসেলার ও অ্যাডমিন অ্যাক্টিভিটি হিস্ট্রি ট্র্যাকিং এবং ডেট ফিল্টারিং সিস্টেম আপডেট';
        $enBullets[] = 'Reseller & Admin A-to-Z activity history tracking and date filter updated';
    }
    if ($hasMatch(array('resetvoucher.php', 'hotspot/users.php', 'hotspot/userprofile.php', 'generateuser.php'))) {
        $bnBullets[] = 'হটস্পট ইউজার, ভাউচার রিসেট (ডেট/কমেন্ট রেখে ডিভাইস ও আপটাইম ক্লিয়ার) ও প্রোফাইল ম্যানেজমেন্ট আপডেট';
        $enBullets[] = 'Hotspot users, smart voucher reset (preserving date/comment), and profile management updated';
    }
    if ($hasMatch(array('pppoe/', 'ppp/'))) {
        $bnBullets[] = 'PPPoE সিক্রেট, প্রোফাইল ও অ্যাক্টিভ কানেকশন ম্যানেজমেন্ট ফিচার আপডেট';
        $enBullets[] = 'PPPoE secrets, profiles, and active connection management updated';
    }
    if ($hasMatch(array('vpn/vpn_hub.php', 'vpn/'))) {
        $bnBullets[] = 'VPN (WireGuard) হাব ও টানেল কনফিগারেশন সিস্টেম আপডেট';
        $enBullets[] = 'VPN (WireGuard) Hub and tunnel configuration updated';
    }
    if ($hasMatch(array('settings/resellers.php', 'reseller'))) {
        $bnBullets[] = 'রিসেলার ম্যানেজমেন্ট, ক্রেডিট লিমিট ও রাউটার অ্যাক্সেস কন্ট্রোল আপডেট';
        $enBullets[] = 'Reseller management, balance/limits, and router access control updated';
    }
    if ($hasMatch(array('include/security.php', 'index.php', 'admin.php'))) {
        $bnBullets[] = 'মোবাইল ও ব্রাউজার লগইন সেশন স্থায়িত্ব এবং সিকিউরিটি সিস্টেম উন্নত করা হয়েছে';
        $enBullets[] = 'Mobile browser login session persistence and core security improved';
    }
    if ($hasMatch(array('settings/system_update.php', 'include/data_recovery.php', 'deploy/safe-update.sh'))) {
        $bnBullets[] = 'গিট সেফ আপডেট, ভার্সন ডিটেইলস চেঞ্জলগ এবং ডাটা ভল্ট রিকভারি সিস্টেম আপডেট';
        $enBullets[] = 'Git Safe Update, Version Details changelog viewer, and Data Vault recovery updated';
    }
    if ($hasMatch(array('include/menu.php', 'css/', 'js/'))) {
        $bnBullets[] = 'ড্যাশবোর্ড মেনু, নেভিগেশন ও ইউজার ইন্টারফেস (UI) উন্নত করা হয়েছে';
        $enBullets[] = 'Dashboard menu, navigation, and User Interface (UI) refined';
    }
    if ($hasMatch(array('voucher/', 'report/'))) {
        $bnBullets[] = 'ভাউচার প্রিন্ট টেমপ্লেট এবং সেলস রিপোর্ট সিস্টেম আপডেট';
        $enBullets[] = 'Voucher print templates and sales reporting updated';
    }

    if (is_array($commitSubjects)) {
        foreach ($commitSubjects as $subj) {
            $subj = trim((string)$subj);
            if ($subj !== '' && count($enBullets) < 8) {
                $bnBullets[] = 'গিট আপডেট নোট: ' . $subj;
                $enBullets[] = 'Git commit: ' . $subj;
            }
        }
    }

    if (empty($bnBullets)) {
        $cnt = count($paths);
        $bnBullets[] = 'ভার্সন v' . $fromVer . ' থেকে v' . $toVer . '-এ মোট ' . $cnt . 'টি কোর ফাইল ও পারফরম্যান্স আপডেট করা হয়েছে।';
        $enBullets[] = 'Updated ' . $cnt . ' core application files with stability and performance improvements from v' . $fromVer . ' to v' . $toVer . '.';
    }

    return array(
        'bn' => array_values(array_unique($bnBullets)),
        'en' => array_values(array_unique($enBullets))
    );
}

/**
 * Prepare normalized modal payload for any version entry (current or history)
 */
function ngwFormatVersionDetailsPayload($entry, $fallbackFromVer, $fallbackToVer, $isBnUi) {
    $defaults = ngwDefaultVersionHighlights();
    $fromVer = !empty($entry['from_version']) ? (string)$entry['from_version'] : (string)$fallbackFromVer;
    $toVer = !empty($entry['to_version']) ? (string)$entry['to_version'] : (string)$fallbackToVer;
    $savedAt = !empty($entry['saved_at']) ? (string)$entry['saved_at'] : (!empty($entry['updated_at']) ? (string)$entry['updated_at'] : date('Y-m-d H:i'));
    $bullets = $isBnUi
        ? (!empty($entry['summary_bn']) && is_array($entry['summary_bn']) ? $entry['summary_bn'] : $defaults['bn'])
        : (!empty($entry['summary_en']) && is_array($entry['summary_en']) ? $entry['summary_en'] : $defaults['en']);
    $changedFiles = !empty($entry['changed_files']) && is_array($entry['changed_files']) ? $entry['changed_files'] : array();
    $newCnt = isset($entry['new_count']) ? (int)$entry['new_count'] : 0;
    $modCnt = isset($entry['modified_count']) ? (int)$entry['modified_count'] : count($changedFiles);
    $totalCnt = isset($entry['updated_count']) ? (int)$entry['updated_count'] : count($changedFiles);

    return array(
        'from_version' => $fromVer,
        'to_version' => $toVer,
        'saved_at' => $savedAt,
        'bullets' => array_values($bullets),
        'changed_files' => array_slice(array_values($changedFiles), 0, 60),
        'total_files' => $totalCnt,
        'new_files' => $newCnt,
        'modified_files' => $modCnt
    );
}

$versionState = loadVersionState($versionStateFile);
$updateNotice = '';
$updateError = '';
$updateInfo = '';
$updateChangedFiles = array();
$recoveryReport = null;
$isBnUi = (isset($langid) && $langid === 'bn') || (isset($_SESSION['lang']) && $_SESSION['lang'] === 'bn');

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Release session lock immediately on GET so page load and concurrent status pings never block
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
} elseif (!ngwValidateCsrf(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '', 'ngw_sysupdate_csrf')) {
    $updateError = $isBnUi
        ? 'সিকিউরিটি টোকেন মেয়াদোত্তীর্ণ বা অবৈধ। পেজটি রিলোড করে আবার চেষ্টা করুন।'
        : 'Security token expired or invalid. Reload this page and try again.';
    $_POST = array();
}

// Fast (<1ms) vault check only if live config.php has 0 routers (never run heavy archive/git scans on GET)
if (function_exists('ngwQuickRestoreFromVaultIfEmpty')) {
    ngwQuickRestoreFromVaultIfEmpty($appRoot);
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
        'include/router_meta.json',
        'include/resellers.json',
        'include/admin_users.json',
        'include/backup_admin.json',
        'include/permission_groups.json',
        'include/activity_log.php',
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
        foreach (array('include/config.php', 'include/router_meta.json', 'include/resellers.json', 'include/admin_users.json', 'include/permission_groups.json', 'include/vpn_routers.json', 'include/pppoe_areas.json') as $rel) {
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
        ngwSyncToLiveDataVault($appRoot, true);
    }
    $protectedBefore = collectProtectedDataFiles($appRoot);
    $gitDir = is_dir($appRoot . '/.git') ? $appRoot : (is_dir(dirname($appRoot) . '/.git') ? dirname($appRoot) : '');

    if ($gitRepoUrl !== '' && function_exists('shell_exec')) {
        $tmpClone = sys_get_temp_dir() . '/ngw_git_sync_' . uniqid();
        $cmd = 'git clone --depth 5 ' . escapeshellarg($gitRepoUrl) . ' ' . escapeshellarg($tmpClone) . ' 2>&1';
        $out = @shell_exec($cmd);
        if (is_dir($tmpClone . '/mikhmon') && is_file($tmpClone . '/mikhmon/admin.php')) {
            $srcRoot = $tmpClone . '/mikhmon';
        } elseif (is_dir($tmpClone . '/mikhmonv3-upstream') && is_file($tmpClone . '/mikhmonv3-upstream/admin.php')) {
            $srcRoot = $tmpClone . '/mikhmonv3-upstream';
        } else {
            $srcRoot = $tmpClone;
        }
        if (is_file($srcRoot . '/admin.php') && is_file($srcRoot . '/index.php')) {
            $commitNotes = array();
            $rawLog = @shell_exec('git -C ' . escapeshellarg($tmpClone) . ' log -n 3 --pretty=format:%s 2>/dev/null');
            if (!empty($rawLog)) {
                foreach (preg_split('/\r?\n/', trim((string)$rawLog)) as $ln) {
                    $ln = trim($ln);
                    if ($ln !== '') $commitNotes[] = $ln;
                }
            }

            // Clean up any deprecated license files if they existed on the server
            foreach (array('include/license_manager.php', 'include/store_licenses.json', 'include/router_owners.json') as $deprecatedRel) {
                if (is_file($appRoot . '/' . $deprecatedRel) && !is_file($srcRoot . '/' . $deprecatedRel)) {
                    @unlink($appRoot . '/' . $deprecatedRel);
                }
            }
            // Compare remote source files with the live installation first.
            // Do not snapshot, bump the version, or show success when nothing changed.
            $changedFiles = array();
            $srcDirIt = new RecursiveDirectoryIterator($srcRoot, RecursiveDirectoryIterator::SKIP_DOTS);
            $srcFilterIt = new RecursiveCallbackFilterIterator($srcDirIt, function ($current) use ($srcRoot) {
                if ($current->isLink()) {
                    return false;
                }
                $full = $current->getPathname();
                $rel = ltrim(str_replace('\\', '/', substr($full, strlen($srcRoot))), '/');
                if ($current->isDir()) {
                    return !ngwShouldSkipSnapshotRelPath($rel . '/');
                }
                return !ngwShouldSkipSnapshotRelPath($rel);
            });
            $it = new RecursiveIteratorIterator($srcFilterIt, RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($it as $item) {
                if ($item->isFile()) {
                    $full = $item->getRealPath();
                    if (!$full || strpos($full, $srcRoot) !== 0) {
                        continue;
                    }
                    $rel = ltrim(str_replace('\\', '/', substr($full, strlen($srcRoot))), '/');
                    if (ngwShouldSkipSnapshotRelPath($rel)) {
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
                if (function_exists('ngwSyncToLiveDataVault')) {
                    ngwSyncToLiveDataVault($appRoot, true);
                }
                @shell_exec('rm -rf ' . escapeshellarg($tmpClone));
                $updateNotice = '';
                $updateInfo = 'No new files or changes were found. The installed software is already up to date.';
            } else {
                // Only create a rollback snapshot when a real code change exists.
                $prevVersionLabel = (string)$versionState['current_version'];
                $nextVersionLabel = incrementVersionNumber($prevVersionLabel);
                $snapEntry = createVersionSnapshotArchive($appRoot, $versionSnapDir, $prevVersionLabel);
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
                        if (substr($dest, -4) === '.php' && function_exists('ngwInvalidatePhpFileCache')) {
                            ngwInvalidatePhpFileCache($dest);
                        }
                        // Also mirror into $appRoot/mikhmon/ if it is a separate real directory
                        $mikhSub = $appRoot . '/mikhmon/' . $change['path'];
                        if (is_dir($appRoot . '/mikhmon') && !is_link($appRoot . '/mikhmon')) {
                            if (!is_dir(dirname($mikhSub))) {
                                @mkdir(dirname($mikhSub), 0755, true);
                            }
                            @copy($change['source'], $mikhSub);
                            if (substr($mikhSub, -4) === '.php' && function_exists('ngwInvalidatePhpFileCache')) {
                                ngwInvalidatePhpFileCache($mikhSub);
                            }
                        }
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
                $chkCfg = function_exists('ngwParseConfigPhpRouters') && is_file($appRoot . '/include/config.php')
                    ? ngwParseConfigPhpRouters((string)@file_get_contents($appRoot . '/include/config.php'))
                    : array('routers' => array());
                if (empty($chkCfg['routers']) && function_exists('ngwPerformDeepDataRecoveryAndMerge')) {
                    $recoveryReport = ngwPerformDeepDataRecoveryAndMerge($appRoot);
                } elseif (function_exists('ngwSyncToLiveDataVault')) {
                    ngwSyncToLiveDataVault($appRoot, true);
                }
                @shell_exec('rm -rf ' . escapeshellarg($tmpClone));

                if ($updatedCount > 0) {
                    $summaryPair = ngwBuildVersionChangeSummary($changedFiles, $prevVersionLabel, $nextVersionLabel, $commitNotes);
                    $changeDetailsMeta = array(
                        'from_version' => $prevVersionLabel,
                        'to_version' => $nextVersionLabel,
                        'saved_at' => date('Y-m-d H:i:s'),
                        'updated_count' => $updatedCount,
                        'new_count' => $newFileCount,
                        'modified_count' => $modifiedFileCount,
                        'summary_bn' => $summaryPair['bn'],
                        'summary_en' => $summaryPair['en'],
                        'changed_files' => array_slice($changedFileLabels, 0, 60)
                    );
                    if ($snapEntry !== null) {
                        $snapEntry = array_merge($snapEntry, $changeDetailsMeta);
                        array_unshift($versionState['history'], $snapEntry);
                    }
                    $versionState['current_version'] = $nextVersionLabel;
                    $versionState['updated_at'] = date('Y-m-d H:i');
                    $versionState['latest_details'] = $changeDetailsMeta;
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
        $prevVersionLabel = (string)$versionState['current_version'];
        $nextVersionLabel = incrementVersionNumber($prevVersionLabel);
        $snapEntry = createVersionSnapshotArchive($appRoot, $versionSnapDir, $prevVersionLabel);
        $out = @shell_exec('git -C ' . escapeshellarg($gitDir) . ' pull --ff-only 2>&1');
        restoreProtectedDataFiles($appRoot, $protectedBefore);
        $chkCfg = function_exists('ngwParseConfigPhpRouters') && is_file($appRoot . '/include/config.php')
            ? ngwParseConfigPhpRouters((string)@file_get_contents($appRoot . '/include/config.php'))
            : array('routers' => array());
        if (empty($chkCfg['routers']) && function_exists('ngwPerformDeepDataRecoveryAndMerge')) {
            $recoveryReport = ngwPerformDeepDataRecoveryAndMerge($appRoot);
        } elseif (function_exists('ngwSyncToLiveDataVault')) {
            ngwSyncToLiveDataVault($appRoot, true);
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
            $changedPathOutput = @shell_exec('git -C ' . escapeshellarg($gitDir) . ' diff --name-status ORIG_HEAD..HEAD 2>&1');
            $updateChangedFiles = array();
            $newFileCount = 0;
            $modifiedFileCount = 0;
            foreach (preg_split('/\\r?\\n/', trim((string)$changedPathOutput)) as $line) {
                if (preg_match('/^([A-Z])[0-9]*\\s+(.+)$/', $line, $match)) {
                    $st = ($match[1] === 'A') ? 'new' : 'modified';
                    if ($st === 'new') $newFileCount++; else $modifiedFileCount++;
                    $updateChangedFiles[] = $st . ': ' . $match[2];
                }
            }
            $commitNotes = array();
            $rawLog = @shell_exec('git -C ' . escapeshellarg($gitDir) . ' log ORIG_HEAD..HEAD --pretty=format:%s 2>/dev/null');
            if (!empty($rawLog)) {
                foreach (preg_split('/\r?\n/', trim((string)$rawLog)) as $ln) {
                    $ln = trim($ln);
                    if ($ln !== '') $commitNotes[] = $ln;
                }
            }
            $summaryPair = ngwBuildVersionChangeSummary($updateChangedFiles, $prevVersionLabel, $nextVersionLabel, $commitNotes);
            $changeDetailsMeta = array(
                'from_version' => $prevVersionLabel,
                'to_version' => $nextVersionLabel,
                'saved_at' => date('Y-m-d H:i:s'),
                'updated_count' => count($updateChangedFiles),
                'new_count' => $newFileCount,
                'modified_count' => $modifiedFileCount,
                'summary_bn' => $summaryPair['bn'],
                'summary_en' => $summaryPair['en'],
                'changed_files' => array_slice($updateChangedFiles, 0, 60)
            );
            if ($snapEntry !== null) {
                $snapEntry = array_merge($snapEntry, $changeDetailsMeta);
                array_unshift($versionState['history'], $snapEntry);
            }
            $versionState['current_version'] = $nextVersionLabel;
            $versionState['updated_at'] = date('Y-m-d H:i');
            $versionState['latest_details'] = $changeDetailsMeta;
            $versionState = saveAndPruneVersionState($versionStateFile, $versionSnapDir, $versionState);

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

$currentVersionDetailsEntry = isset($versionState['latest_details']) && is_array($versionState['latest_details'])
    ? $versionState['latest_details']
    : (!empty($versionState['history'][0]) ? $versionState['history'][0] : array('saved_at' => $versionState['updated_at']));
$prevVerForCurrent = !empty($versionState['history'][0]['version']) ? $versionState['history'][0]['version'] : $versionState['current_version'];
$currentModalPayload = ngwFormatVersionDetailsPayload($currentVersionDetailsEntry, $prevVerForCurrent, $versionState['current_version'], $isBnUi);
?>
<style>
#loading { display: none !important; }
#main .main-container, .main-container { display: block !important; }
</style>
<script>
(function(){
  var ld = document.getElementById('loading');
  if (ld) ld.style.display = 'none';
  var mc = document.querySelectorAll('.main-container');
  for (var i = 0; i < mc.length; i++) mc[i].style.display = 'block';
})();
</script>
<div class="row">
  <div class="col-6">
    <div class="card">
      <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px;">
        <h3 class="card-title" style="margin:0;">
          <i class="fa fa-github"></i> <?= htmlspecialchars(isset($_system_update) ? $_system_update : 'System Update'); ?>
        </h3>
        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
          <span style="font-size:12px; padding:3px 10px; border-radius:4px; background:#2ea043; color:#fff; font-weight:bold;">
            <i class="fa fa-tag"></i> <?= htmlspecialchars(isset($_software_version_label) ? $_software_version_label : 'Software Version'); ?>: v<?= htmlspecialchars($versionState['current_version']); ?>
          </span>
          <button type="button"
                  class="btn bg-primary pd-5"
                  style="cursor:pointer; font-size:11px; font-weight:bold; border-radius:4px;"
                  data-version-payload="<?= htmlspecialchars(json_encode($currentModalPayload), ENT_QUOTES, 'UTF-8'); ?>"
                  onclick="ngwOpenVersionDetailsModal(this)">
            <i class="fa fa-list-ul"></i> <?= $isBnUi ? 'ভার্সন ডিটেইলস' : 'Version Details'; ?>
          </button>
        </div>
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
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($sysUpdateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
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
            <div style="font-size:12px; opacity:0.85; padding:8px 10px; border:1px dashed rgba(150,150,150,0.35); border-radius:6px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
              <span>
                <?= htmlspecialchars(isset($_no_previous_versions) ? $_no_previous_versions : 'No previous version snapshots yet. A snapshot will be saved automatically on your next Git Safe Update.'); ?>
              </span>
              <button type="button"
                      class="btn bg-primary pd-5"
                      style="cursor:pointer; font-size:11px; font-weight:bold;"
                      data-version-payload="<?= htmlspecialchars(json_encode($currentModalPayload), ENT_QUOTES, 'UTF-8'); ?>"
                      onclick="ngwOpenVersionDetailsModal(this)">
                <i class="fa fa-list-ul"></i> <?= $isBnUi ? 'বর্তমান ভার্সন ডিটেইলস (v' . htmlspecialchars($versionState['current_version']) . ')' : 'Current Version Details (v' . htmlspecialchars($versionState['current_version']) . ')'; ?>
              </button>
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
                <?php foreach ($versionState['history'] as $hIdx => $hItem) {
                    $rowFromVer = isset($hItem['version']) ? (string)$hItem['version'] : '1.0';
                    $rowToVer = !empty($hItem['to_version'])
                        ? (string)$hItem['to_version']
                        : ($hIdx === 0 ? (string)$versionState['current_version'] : incrementVersionNumber($rowFromVer));
                    $rowPayload = ngwFormatVersionDetailsPayload($hItem, $rowFromVer, $rowToVer, $isBnUi);
                ?>
                  <tr>
                    <td class="align-middle">
                      <b>v<?= htmlspecialchars($rowFromVer); ?></b>
                      <small style="opacity:0.75; margin-left:4px;">&rarr; v<?= htmlspecialchars($rowToVer); ?></small>
                    </td>
                    <td class="align-middle"><small><?= htmlspecialchars($hItem['saved_at']); ?></small></td>
                    <td class="align-middle text-right" style="white-space:nowrap;">
                      <button type="button"
                              class="btn bg-primary pd-5"
                              style="cursor:pointer; font-size:11px; font-weight:bold; margin-right:4px;"
                              data-version-payload="<?= htmlspecialchars(json_encode($rowPayload), ENT_QUOTES, 'UTF-8'); ?>"
                              onclick="ngwOpenVersionDetailsModal(this)">
                        <i class="fa fa-list-ul"></i> <?= $isBnUi ? 'ভার্সন ডিটেইলস' : 'Version Details'; ?>
                      </button>
                      <form method="post" action="" style="margin:0; display:inline;" onsubmit="return confirm('<?= htmlspecialchars(isset($_rollback_confirm) ? $_rollback_confirm : 'Are you sure you want to switch back to version', ENT_QUOTES); ?> v<?= htmlspecialchars($hItem['version'], ENT_QUOTES); ?>?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($sysUpdateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="rollback_version_id" value="<?= htmlspecialchars($hItem['id']); ?>">
                        <button type="submit" class="btn bg-warning pd-5" style="cursor:pointer; font-size:11px; font-weight:bold;">
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
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($sysUpdateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" name="run_vps_data_recovery" class="btn bg-green pd-10" style="cursor:pointer; font-weight:bold;">
              <i class="fa fa-magic"></i> <?= $isBnUi ? 'ভিপিএস থেকে পূর্বের ডাটা স্ক্যান ও রিকভার করুন' : 'Scan & Recover Previous VPS Data'; ?>
            </button>
          </form>

          <form method="post" action="" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($sysUpdateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
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
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($sysUpdateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
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

<!-- Version Details Modal -->
<div id="ngw-version-details-modal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.65); align-items:center; justify-content:center; padding:16px;" onclick="if(event.target===this) ngwCloseVersionDetailsModal();">
  <div style="background:#1e2430; color:#e6edf3; width:100%; max-width:560px; border-radius:10px; border:1px solid rgba(255,255,255,0.14); box-shadow:0 16px 40px rgba(0,0,0,0.5); overflow:hidden;">
    <div style="padding:13px 16px; background:#161b22; border-bottom:1px solid rgba(255,255,255,0.1); display:flex; align-items:center; justify-content:space-between;">
      <div style="font-weight:bold; font-size:14px; display:flex; align-items:center; gap:8px;">
        <i class="fa fa-code-fork" style="color:#2ea043; font-size:16px;"></i>
        <span id="ngw-vd-title"><?= $isBnUi ? 'ভার্সন ডিটেইলস ও নতুন ফিচারসমূহ' : 'Version Details & Release Highlights'; ?></span>
      </div>
      <button type="button" onclick="ngwCloseVersionDetailsModal()" style="background:transparent; border:none; color:#adbac7; font-size:18px; cursor:pointer; line-height:1;">&times;</button>
    </div>

    <div style="padding:16px; max-height:75vh; overflow-y:auto;">
      <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; background:rgba(46,160,67,0.12); border:1px solid rgba(46,160,67,0.35); padding:10px 12px; border-radius:7px; margin-bottom:14px;">
        <div>
          <div style="font-size:11px; opacity:0.8;"><?= $isBnUi ? 'ভার্সন পরিবর্তন (Version Transition):' : 'Version Transition:'; ?></div>
          <div id="ngw-vd-badge" style="font-size:15px; font-weight:bold; color:#3fb950; margin-top:2px;">v1.0 &rarr; v1.1</div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:11px; opacity:0.8;"><?= $isBnUi ? 'আপডেটের সময়:' : 'Updated At:'; ?></div>
          <div id="ngw-vd-date" style="font-size:12px; font-weight:bold; margin-top:2px;">-</div>
        </div>
      </div>

      <div style="margin-bottom:12px;">
        <div style="font-size:13px; font-weight:bold; margin-bottom:8px; color:#58a6ff;">
          <i class="fa fa-star"></i> <?= $isBnUi ? 'এই ভার্সনে নতুন যা যা যুক্ত ও পরিবর্তন করা হয়েছে (শর্টকাট):' : 'What Changed / New Features Added (Shortcut Summary):'; ?>
        </div>
        <ul id="ngw-vd-bullets" style="margin:0; padding-left:20px; font-size:13px; line-height:1.65;"></ul>
      </div>

      <div id="ngw-vd-files-wrap" style="display:none; border-top:1px solid rgba(255,255,255,0.1); padding-top:10px; margin-top:10px;">
        <div style="font-size:12px; font-weight:bold; margin-bottom:6px; opacity:0.9;">
          <i class="fa fa-file-code-o"></i> <span id="ngw-vd-files-summary"></span>
        </div>
        <div id="ngw-vd-files-list" style="max-height:150px; overflow-y:auto; background:rgba(0,0,0,0.28); padding:8px 10px; border-radius:6px; font-family:monospace; font-size:11px; line-height:1.5;"></div>
      </div>
    </div>

    <div style="padding:10px 16px; background:#161b22; border-top:1px solid rgba(255,255,255,0.1); text-align:right;">
      <button type="button" class="btn bg-secondary pd-5" style="cursor:pointer; font-weight:bold; padding:6px 16px;" onclick="ngwCloseVersionDetailsModal()">
        <i class="fa fa-times"></i> <?= $isBnUi ? 'বন্ধ করুন' : 'Close'; ?>
      </button>
    </div>
  </div>
</div>

<script>
function ngwOpenVersionDetailsModal(btnEl) {
  var raw = btnEl ? btnEl.getAttribute('data-version-payload') : '';
  if (!raw) return;
  var data = null;
  try {
    data = JSON.parse(raw);
  } catch (e) {
    return;
  }
  var modal = document.getElementById('ngw-version-details-modal');
  if (!modal) return;

  var fromV = data.from_version || '1.0';
  var toV = data.to_version || fromV;
  var badgeEl = document.getElementById('ngw-vd-badge');
  if (badgeEl) {
    badgeEl.innerHTML = (fromV === toV)
      ? ('v' + fromV + ' (<?= $isBnUi ? 'বর্তমান ভার্সন' : 'Current Version'; ?>)')
      : ('v' + fromV + ' &rarr; v' + toV);
  }

  var dateEl = document.getElementById('ngw-vd-date');
  if (dateEl) {
    dateEl.textContent = data.saved_at || '-';
  }

  var bulletsEl = document.getElementById('ngw-vd-bullets');
  if (bulletsEl) {
    bulletsEl.innerHTML = '';
    var list = Array.isArray(data.bullets) ? data.bullets : [];
    for (var i = 0; i < list.length; i++) {
      var li = document.createElement('li');
      li.style.marginBottom = '5px';
      li.textContent = list[i];
      bulletsEl.appendChild(li);
    }
  }

  var filesWrap = document.getElementById('ngw-vd-files-wrap');
  var filesSummary = document.getElementById('ngw-vd-files-summary');
  var filesList = document.getElementById('ngw-vd-files-list');
  var cFiles = Array.isArray(data.changed_files) ? data.changed_files : [];
  if (filesWrap && filesSummary && filesList) {
    if (cFiles.length > 0) {
      filesWrap.style.display = 'block';
      filesSummary.textContent = '<?= $isBnUi ? 'পরিবর্তিত ফাইল তালিকা' : 'Changed Files'; ?> (' + cFiles.length + ')';
      filesList.innerHTML = '';
      for (var j = 0; j < cFiles.length; j++) {
        var row = document.createElement('div');
        row.textContent = cFiles[j];
        filesList.appendChild(row);
      }
    } else {
      filesWrap.style.display = 'none';
    }
  }

  modal.style.display = 'flex';
}

function ngwCloseVersionDetailsModal() {
  var modal = document.getElementById('ngw-version-details-modal');
  if (modal) {
    modal.style.display = 'none';
  }
}
</script>

