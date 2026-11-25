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
    if (strpos($norm, 'uploads/reseller-templates/') === 0 || strpos($norm, 'uploads/reseller-logos/') === 0 || strpos($norm, 'uploads/admin-templates/') === 0 || strpos($norm, 'uploads/admin-logos/') === 0 || $norm === 'uploads/user_prefs.json' || $norm === 'uploads/ui_settings.json') {
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
 * Load structured visual/UI release notes from include/release_notes.json if available
 */
function ngwLoadReleaseNotes($rootDir) {
    $file = rtrim((string)$rootDir, '/\\') . '/include/release_notes.json';
    if (is_file($file)) {
        $decoded = @json_decode((string)@file_get_contents($file), true);
        if (is_array($decoded) && !empty($decoded['bn']) && is_array($decoded['bn']) && !empty($decoded['en']) && is_array($decoded['en'])) {
            return array(
                'release_id' => isset($decoded['release_id']) ? trim((string)$decoded['release_id']) : '',
                'updated_at' => isset($decoded['updated_at']) ? trim((string)$decoded['updated_at']) : '',
                'bn' => array_values($decoded['bn']),
                'en' => array_values($decoded['en']),
            );
        }
    }
    $fallback = ngwDefaultVersionHighlights();
    return array(
        'release_id' => '',
        'updated_at' => '',
        'bn' => $fallback['bn'],
        'en' => $fallback['en'],
    );
}

/**
 * Default visual UI highlights for versions that do not yet have recorded diff metadata
 */
function ngwDefaultVersionHighlights() {
    return array(
        'bn' => array(
            'হটস্পট ইউজার লিস্টের উপরের অপশনগুলোর পাশে ছোট ইয়েলো ওয়ার্নিং বাটন ও সমস্যাযুক্ত ইউজারের পাশে কমপ্যাক্ট ইয়েলো ওয়ার্নিং বাটন যুক্ত হয়েছে।',
            'ভিপিএন (VPN) সেকশনে নতুন রাউটার কার্ড লিস্ট, PPP Secret ইউজার ম্যানেজমেন্ট, থ্রি-ডট মেনু, পোর্ট ফরওয়ার্ড পপআপ/এডিট এবং ভয়েস কল (WhatsApp, imo, Messenger) জেনারেট স্ক্রিপ্ট যুক্ত হয়েছে।',
            'সুপার এডমিন ও এডমিন রোলসহ শর্টকাট পারমিশন কন্ট্রোল ও অ্যাক্টিভিটি হিস্ট্রি সিস্টেম।',
            'রাউটার ওপেন করার সময় লাইভ পিং ও ইনস্ট্যান্ট কানেকশন স্ট্যাটাস পপআপ।',
            'রিসেট ভাউচারে ডেট/কমেন্ট অক্ষুণ্ণ রেখে ডিভাইস, MAC ও আপটাইম ক্লিয়ার সুবিধা।',
            'সিস্টেম আপডেটের ভার্সন ডিটেইলসে সরাসরি ইউআই ও ফিচারের পরিবর্তন দেখার সুবিধা।'
        ),
        'en' => array(
            'Added compact yellow Warning filter button in Hotspot Users header and tiny yellow warning buttons beside flagged users.',
            'Redesigned VPN section with Router Cards, PPP Secret management, three-dot action menu, Port Forward popup/Edit, and Voice Call (WhatsApp, imo, Messenger) script generator.',
            'Super Admin & Admin roles with shortcut permission controls and A-to-Z activity history.',
            'Live ping and instant connection status popup when opening routers.',
            'Smart Reset Voucher clearing device, MAC & uptime while preserving calendar date/comment.',
            'Visual-only Version Details view inside System Update showing UI & feature changes directly.'
        )
    );
}

/**
 * Build concise Bengali & English visual/UI change summaries (strictly no file names or technical counts)
 */
function ngwBuildVersionChangeSummary($changedFilesList, $fromVer, $toVer, $commitSubjects = array(), $remoteReleaseNotes = null, $prevReleaseId = '') {
    // Prefer curated visual release notes bundled with the pulled release when available & updated
    if (is_array($remoteReleaseNotes) && !empty($remoteReleaseNotes['bn']) && !empty($remoteReleaseNotes['en'])) {
        $rId = isset($remoteReleaseNotes['release_id']) ? trim((string)$remoteReleaseNotes['release_id']) : '';
        if ($rId !== '' && $rId !== trim((string)$prevReleaseId)) {
            return array(
                'release_id' => $rId,
                'bn' => array_values(array_unique($remoteReleaseNotes['bn'])),
                'en' => array_values(array_unique($remoteReleaseNotes['en'])),
            );
        }
    }

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

    if ($hasMatch(array('hotspot/users.php', 'include/ros_version.php'))) {
        $bnBullets[] = 'হটস্পট ইউজার লিস্টের ইউআই আপডেট — উপরের অপশন ও সমস্যাযুক্ত ইউজারের পাশে ছোট ইয়েলো ওয়ার্নিং বাটন ও ডিটেইল পপআপ যুক্ত হয়েছে।';
        $enBullets[] = 'Hotspot User List UI updated — compact yellow warning filter button in header and per-user warning indicator popup added.';
    }
    if ($hasMatch(array('vpn/vpn_hub.php', 'vpn/'))) {
        $bnBullets[] = 'ভিপিএন (VPN) ড্যাশবোর্ড আপডেট — রাউটার কার্ড ভিউ, PPP Secret থ্রি-ডট মেনু, পোর্ট ফরওয়ার্ড পপআপ/এডিট এবং ভয়েস কল (WhatsApp, imo, Messenger) পারপাস সিলেক্ট ও Generate স্ক্রিপ্ট যুক্ত হয়েছে।';
        $enBullets[] = 'VPN dashboard updated — Router Cards, PPP Secret three-dot menu, Port Forward popup/Edit, and Voice Call purpose selection with Generate script flow.';
    }
    if ($hasMatch(array('settings/system_update.php', 'include/release_notes.json'))) {
        $bnBullets[] = 'সিস্টেম আপডেটের ভার্সন ডিটেইলস পপআপ আপডেট — সরাসরি ইউআই ও ফিচারের ভিজ্যুয়াল পরিবর্তনগুলো দেখানো হচ্ছে।';
        $enBullets[] = 'System Update Version Details popup updated — directly displays visual UI & feature changes.';
    }
    if ($hasMatch(array('admin_settings.php', 'reseller_acl.php'))) {
        $bnBullets[] = 'অ্যাডমিন ও রিসেলার পারমিশন কন্ট্রোল ও অ্যাক্সেস সিস্টেম আপডেট করা হয়েছে।';
        $enBullets[] = 'Admin & Reseller permission controls and access restrictions updated.';
    }
    if ($hasMatch(array('settings/sessions.php', 'status/ping.php', 'process/photspot.php'))) {
        $bnBullets[] = 'রাউটার লিস্ট, লাইভ পিং চেক এবং কানেকশন স্ট্যাটাস পপআপ উন্নত করা হয়েছে।';
        $enBullets[] = 'Router list, live ping check, and connection status modal improved.';
    }
    if ($hasMatch(array('settings/activity.php', 'activity_logger.php'))) {
        $bnBullets[] = 'রিসেলার ও অ্যাডমিন অ্যাক্টিভিটি হিস্ট্রি ভিউ ও ডেট ফিল্টারিং আপডেট করা হয়েছে।';
        $enBullets[] = 'Reseller & Admin activity history view and date filtering updated.';
    }
    if ($hasMatch(array('resetvoucher.php', 'hotspot/userprofile.php', 'generateuser.php'))) {
        $bnBullets[] = 'ভাউচার রিসেট, জেনারেটর ও হটস্পট প্রোফাইল ম্যানেজমেন্ট ইউআই আপডেট করা হয়েছে।';
        $enBullets[] = 'Voucher reset, generator, and hotspot profile management UI updated.';
    }
    if ($hasMatch(array('pppoe/', 'ppp/'))) {
        $bnBullets[] = 'PPPoE ইউজার, প্রোফাইল ও অ্যাক্টিভ কানেকশন পেজের ইউআই আপডেট করা হয়েছে।';
        $enBullets[] = 'PPPoE users, profiles, and active connections UI updated.';
    }
    if ($hasMatch(array('settings/resellers.php'))) {
        $bnBullets[] = 'রিসেলার ম্যানেজমেন্ট ও রাউটার অ্যাক্সেস ইউআই আপডেট করা হয়েছে।';
        $enBullets[] = 'Reseller management and router access UI updated.';
    }
    if ($hasMatch(array('include/security.php', 'index.php', 'admin.php'))) {
        $bnBullets[] = 'লগইন সেশন স্থায়িত্ব ও সিকিউরিটি সুরক্ষা উন্নত করা হয়েছে।';
        $enBullets[] = 'Login session stability and security protections improved.';
    }
    if ($hasMatch(array('include/menu.php', 'css/', 'js/'))) {
        $bnBullets[] = 'ড্যাশবোর্ড মেনু, নেভিগেশন ও ইউজার ইন্টারফেস (UI) লেআউট উন্নত করা হয়েছে।';
        $enBullets[] = 'Dashboard menu, navigation, and User Interface (UI) layout refined.';
    }
    if ($hasMatch(array('voucher/', 'report/'))) {
        $bnBullets[] = 'ভাউচার প্রিন্ট টেমপ্লেট ও সেলস রিপোর্ট ভিউ আপডেট করা হয়েছে।';
        $enBullets[] = 'Voucher print templates and sales report views updated.';
    }

    if (empty($bnBullets)) {
        $bnBullets[] = 'ভার্সন v' . $fromVer . ' থেকে v' . $toVer . '-এ সফটওয়্যারের ইউজার ইন্টারফেস, স্পিড এবং স্থায়িত্ব উন্নত করা হয়েছে।';
        $enBullets[] = 'User interface, performance, and overall stability improved from v' . $fromVer . ' to v' . $toVer . '.';
    }

    return array(
        'release_id' => is_array($remoteReleaseNotes) && isset($remoteReleaseNotes['release_id']) ? (string)$remoteReleaseNotes['release_id'] : '',
        'bn' => array_values(array_unique($bnBullets)),
        'en' => array_values(array_unique($enBullets))
    );
}

/**
 * Prepare normalized modal payload for any version entry (visual UI changes only — no file lists)
 */
function ngwFormatVersionDetailsPayload($entry, $fallbackFromVer, $fallbackToVer, $isBnUi, $liveReleaseNotes = null) {
    $defaults = is_array($liveReleaseNotes) && !empty($liveReleaseNotes['bn']) && !empty($liveReleaseNotes['en'])
        ? $liveReleaseNotes
        : ngwDefaultVersionHighlights();
    $fromVer = !empty($entry['from_version']) ? (string)$entry['from_version'] : (string)$fallbackFromVer;
    $toVer = !empty($entry['to_version']) ? (string)$entry['to_version'] : (string)$fallbackToVer;
    $savedAt = !empty($entry['saved_at']) ? (string)$entry['saved_at'] : (!empty($entry['updated_at']) ? (string)$entry['updated_at'] : date('Y-m-d H:i'));
    $bullets = $isBnUi
        ? (!empty($entry['summary_bn']) && is_array($entry['summary_bn']) ? $entry['summary_bn'] : $defaults['bn'])
        : (!empty($entry['summary_en']) && is_array($entry['summary_en']) ? $entry['summary_en'] : $defaults['en']);

    return array(
        'from_version' => $fromVer,
        'to_version' => $toVer,
        'saved_at' => $savedAt,
        'bullets' => array_values($bullets)
    );
}

$versionState = loadVersionState($versionStateFile);
$liveReleaseNotes = ngwLoadReleaseNotes($appRoot);
$updateNotice = '';
$updateError = '';
$updateInfo = '';
$updateVisualSummary = array();
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
        'uploads/ui_settings.json',
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

    $allowedImportRelPaths = array(
        'include/config.php' => true,
        'include/router_meta.json' => true,
        'include/resellers.json' => true,
        'include/admin_users.json' => true,
        'include/permission_groups.json' => true,
        'include/vpn_routers.json' => true,
        'include/pppoe_areas.json' => true,
        'include/voice_call_services.json' => true,
        'include/user_prefs.json' => true,
    );

    $decodedJson = @json_decode($rawUpload, true);
    if (is_array($decodedJson) && isset($decodedJson['files']) && is_array($decodedJson['files'])) {
        // Full Backup Bundle JSON — strictly confine extracted entries to allowlisted data paths
        foreach ($decodedJson['files'] as $rel => $b64) {
            $relClean = ltrim(str_replace('\\', '/', trim((string)$rel)), '/');
            if ($relClean === '' || strpos($relClean, '..') !== false || !isset($allowedImportRelPaths[$relClean])) {
                continue;
            }
            $decodedContent = @base64_decode((string)$b64, true);
            if ($decodedContent !== false) {
                $dest = $tempRestoreDir . '/' . $relClean;
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
    $gitUrlValid = false;
    if ($gitRepoUrl !== '' && strlen($gitRepoUrl) <= 300 && $gitRepoUrl[0] !== '-' && !preg_match('/[\s\x00-\x1F]/', $gitRepoUrl)) {
        $parsedGit = @parse_url($gitRepoUrl);
        if (is_array($parsedGit) && isset($parsedGit['scheme'], $parsedGit['host']) && strtolower((string)$parsedGit['scheme']) === 'https') {
            $gHost = strtolower((string)$parsedGit['host']);
            $isBlockedHost = ($gHost === '' || $gHost === 'localhost' || substr($gHost, -6) === '.local' || substr($gHost, -9) === '.internal');
            if (!$isBlockedHost && filter_var($gHost, FILTER_VALIDATE_IP)) {
                if (filter_var($gHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    $isBlockedHost = true;
                }
            }
            if (!$isBlockedHost && preg_match('/^[a-z0-9.-]+$/', $gHost) && strpos($gHost, '.') !== false) {
                $gitUrlValid = true;
            }
        }
    }

    if (!$gitUrlValid) {
        $updateError = $isBnUi
            ? 'অবৈধ GitHub রিপোজিটরি URL। শুধুমাত্র সঠিক https:// GitHub URL দিন।'
            : 'Invalid Git repository URL. Please provide a valid public https:// repository URL.';
    } else {
        @file_put_contents($gitRepoConfigFile, $gitRepoUrl);
        $savedGitRepoUrl = $gitRepoUrl;

        if (function_exists('ngwSyncToLiveDataVault')) {
            ngwSyncToLiveDataVault($appRoot, true);
        }
        $protectedBefore = collectProtectedDataFiles($appRoot);
        $gitDir = is_dir($appRoot . '/.git') ? $appRoot : (is_dir(dirname($appRoot) . '/.git') ? dirname($appRoot) : '');

        if ($gitRepoUrl !== '' && function_exists('shell_exec')) {
            $tmpClone = sys_get_temp_dir() . '/ngw_git_sync_' . uniqid();
            $cmd = 'git clone --depth 5 -- ' . escapeshellarg($gitRepoUrl) . ' ' . escapeshellarg($tmpClone) . ' 2>&1';
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
                $remoteReleaseNotes = ngwLoadReleaseNotes($srcRoot);
                $prevReleaseId = isset($versionState['latest_details']['release_id']) ? (string)$versionState['latest_details']['release_id'] : '';
                @shell_exec('rm -rf ' . escapeshellarg($tmpClone));

                if ($updatedCount > 0) {
                    $summaryPair = ngwBuildVersionChangeSummary($changedFiles, $prevVersionLabel, $nextVersionLabel, $commitNotes, $remoteReleaseNotes, $prevReleaseId);
                    $changeDetailsMeta = array(
                        'from_version' => $prevVersionLabel,
                        'to_version' => $nextVersionLabel,
                        'saved_at' => date('Y-m-d H:i:s'),
                        'release_id' => isset($summaryPair['release_id']) ? (string)$summaryPair['release_id'] : '',
                        'summary_bn' => $summaryPair['bn'],
                        'summary_en' => $summaryPair['en']
                    );
                    if ($snapEntry !== null) {
                        $snapEntry = array_merge($snapEntry, $changeDetailsMeta);
                        array_unshift($versionState['history'], $snapEntry);
                    }
                    $versionState['current_version'] = $nextVersionLabel;
                    $versionState['updated_at'] = date('Y-m-d H:i');
                    $versionState['latest_details'] = $changeDetailsMeta;
                    $versionState = saveAndPruneVersionState($versionStateFile, $versionSnapDir, $versionState);
                    $liveReleaseNotes = ngwLoadReleaseNotes($appRoot);

                    $updateNotice = $isBnUi
                        ? ('সফটওয়্যার সফলভাবে আপডেট হয়েছে! বর্তমান ভার্সন: <b>v' . htmlspecialchars($versionState['current_version']) . '</b>।')
                        : ('Software updated successfully! Current version: <b>v' . htmlspecialchars($versionState['current_version']) . '</b>.');
                    $updateVisualSummary = $isBnUi ? $summaryPair['bn'] : $summaryPair['en'];
                } else {
                    if ($snapEntry !== null && !empty($snapEntry['file'])) {
                        @unlink($versionSnapDir . '/' . basename($snapEntry['file']));
                    }
                    $updateNotice = '';
                    $updateInfo = $isBnUi
                        ? 'কোনো নতুন পরিবর্তন পাওয়া যায়নি; সফটওয়্যার ভার্সন অপরিবর্তিত রয়েছে।'
                        : 'No changes could be applied; software version was not changed.';
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
            $pulledReleaseNotes = ngwLoadReleaseNotes($appRoot);
            $prevReleaseId = isset($versionState['latest_details']['release_id']) ? (string)$versionState['latest_details']['release_id'] : '';
            $summaryPair = ngwBuildVersionChangeSummary($updateChangedFiles, $prevVersionLabel, $nextVersionLabel, $commitNotes, $pulledReleaseNotes, $prevReleaseId);
            $changeDetailsMeta = array(
                'from_version' => $prevVersionLabel,
                'to_version' => $nextVersionLabel,
                'saved_at' => date('Y-m-d H:i:s'),
                'release_id' => isset($summaryPair['release_id']) ? (string)$summaryPair['release_id'] : '',
                'summary_bn' => $summaryPair['bn'],
                'summary_en' => $summaryPair['en']
            );
            if ($snapEntry !== null) {
                $snapEntry = array_merge($snapEntry, $changeDetailsMeta);
                array_unshift($versionState['history'], $snapEntry);
            }
            $versionState['current_version'] = $nextVersionLabel;
            $versionState['updated_at'] = date('Y-m-d H:i');
            $versionState['latest_details'] = $changeDetailsMeta;
            $versionState = saveAndPruneVersionState($versionStateFile, $versionSnapDir, $versionState);
            $liveReleaseNotes = $pulledReleaseNotes;

            $updateNotice = $isBnUi
                ? ('সফটওয়্যার সফলভাবে আপডেট হয়েছে! বর্তমান ভার্সন: <b>v' . htmlspecialchars($versionState['current_version']) . '</b>।')
                : ('Software updated successfully! Current version: <b>v' . htmlspecialchars($versionState['current_version']) . '</b>.');
            $updateVisualSummary = $isBnUi ? $summaryPair['bn'] : $summaryPair['en'];
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
    : (!empty($versionState['history'][0]) ? $versionState['history'][0] : array('saved_at' => !empty($liveReleaseNotes['updated_at']) ? $liveReleaseNotes['updated_at'] : $versionState['updated_at']));
$prevVerForCurrent = !empty($versionState['history'][0]['version']) ? $versionState['history'][0]['version'] : $versionState['current_version'];
$currentModalPayload = ngwFormatVersionDetailsPayload($currentVersionDetailsEntry, $prevVerForCurrent, $versionState['current_version'], $isBnUi, $liveReleaseNotes);
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
        <?php if (!empty($updateVisualSummary)) { ?>
          <div class="pd-10 radius-3 mr-b-10" style="background:rgba(46,160,67,0.08); border-left:3px solid #2ea043;">
            <b><i class="fa fa-sparkles fa-star"></i> <?= $isBnUi ? 'এই আপডেটে ইউআই ও ফিচারে যা যা পরিবর্তন হয়েছে' : 'What Changed in UI & Features'; ?></b>
            <ul style="margin:6px 0 0 18px; padding:0; font-size:12px; line-height:1.6;">
              <?php foreach ($updateVisualSummary as $visItem) { ?>
                <li><?= htmlspecialchars($visItem); ?></li>
              <?php } ?>
            </ul>
          </div>
        <?php } ?>

        <form method="post" action="" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($sysUpdateCsrf, ENT_QUOTES, 'UTF-8'); ?>">
          <table class="table table-sm ngw-form-stack-table" style="margin-bottom: 12px;">
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
            <div class="overflow ngw-mobile-card-wrap">
            <table class="table table-bordered table-sm ngw-mobile-card-table" style="margin-bottom:6px;">
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
                    $rowPayload = ngwFormatVersionDetailsPayload($hItem, $rowFromVer, $rowToVer, $isBnUi, $liveReleaseNotes);
                ?>
                  <tr>
                    <td class="align-middle ngw-mc-head" data-label="<?= htmlspecialchars(isset($_software_version_label) ? $_software_version_label : 'Software Version', ENT_QUOTES, 'UTF-8'); ?>">
                      <b>v<?= htmlspecialchars($rowFromVer); ?></b>
                      <small style="opacity:0.75; margin-left:4px;">&rarr; v<?= htmlspecialchars($rowToVer); ?></small>
                    </td>
                    <td class="align-middle" data-label="<?= htmlspecialchars(isset($_date) ? $_date : 'Date', ENT_QUOTES, 'UTF-8'); ?>"><small><?= htmlspecialchars($hItem['saved_at']); ?></small></td>
                    <td class="align-middle text-right ngw-mc-actions" data-label="<?= htmlspecialchars(isset($_action) ? $_action : 'Action', ENT_QUOTES, 'UTF-8'); ?>" style="white-space:nowrap;">
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
            </div>
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

      <div style="margin-bottom:6px;">
        <div style="font-size:13px; font-weight:bold; margin-bottom:10px; color:#58a6ff;">
          <i class="fa fa-eye"></i> <?= $isBnUi ? 'ইউআই ও ফিচারে যা যা পরিবর্তন হয়েছে:' : 'Visual & UI Changes in This Version:'; ?>
        </div>
        <ul id="ngw-vd-bullets" style="margin:0; padding:0; list-style:none; font-size:13px; line-height:1.6;"></ul>
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
      li.style.cssText = 'display:flex; align-items:flex-start; gap:8px; padding:7px 9px; margin-bottom:6px; border-radius:6px; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07);';
      var icon = document.createElement('i');
      icon.className = 'fa fa-check-circle';
      icon.style.cssText = 'color:#3fb950; margin-top:3px; flex-shrink:0; font-size:13px;';
      var txt = document.createElement('span');
      txt.textContent = list[i];
      li.appendChild(icon);
      li.appendChild(txt);
      bulletsEl.appendChild(li);
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

