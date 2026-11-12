<?php
/**
 * NextGen WiFi Solution — VPS Deep Data Recovery, Auto-Merger & Persistent Vault
 *
 * Recovers and non-destructively merges routers (config.php), resellers (resellers.json),
 * admin accounts (admin_users.json), VPN routers (vpn_routers.json), PPPoE areas (pppoe_areas.json),
 * permission groups (permission_groups.json), user preferences, and custom voucher templates
 * from any surviving VPS backup directories, archives, sibling installations, .bak.php files,
 * git reflog/dangling objects, or PHP session files after an accidental `git reset --hard`.
 *
 * Also maintains an external Live Data Vault in /var/tmp/ngw_data_vault and backups/live-data-vault
 * so future SSH `git reset --hard` commands can never permanently wipe live VPS data.
 */

if (!function_exists('ngwParseConfigPhpRouters')) {
    /**
     * Parse include/config.php content into array('mikhmon' => rawLine, 'routers' => array(sessionName => rawLine))
     */
    function ngwParseConfigPhpRouters($rawContent) {
        $result = array(
            'admin_line' => '',
            'routers' => array(),
        );
        if (!is_string($rawContent) || trim($rawContent) === '') {
            return $result;
        }
        $lines = preg_split('/\r?\n/', $rawContent);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '$data[') !== 0) {
                continue;
            }
            if (preg_match('/^\$data\[[\'"]([^\'"]+)[\'"]\]\s*=\s*array\s*\(.*\);\s*$/', $trimmed, $m)) {
                $key = $m[1];
                if ($key === 'mikhmon') {
                    $result['admin_line'] = $trimmed;
                } else {
                    $result['routers'][$key] = $trimmed;
                }
            }
        }
        return $result;
    }
}

if (!function_exists('ngwBuildConfigPhpContent')) {
    /**
     * Build valid include/config.php content from admin line and routers map
     */
    function ngwBuildConfigPhpContent($adminLine, $routersMap) {
        if (empty($adminLine)) {
            $adminLine = "\$data['mikhmon'] = array ('1'=>'mikhmon<|<mikhmon','mikhmon>|>aWNlbA==');";
        }
        $out = "<?php \n";
        $out .= "if(substr(\$_SERVER[\"REQUEST_URI\"], -10) == \"config.php\"){header(\"Location:./\");}; \n";
        $out .= $adminLine . "\n";
        foreach ($routersMap as $sess => $line) {
            $out .= $line . "\n";
        }
        return $out;
    }
}

if (!function_exists('ngwGetLiveVaultDirs')) {
    /**
     * Return persistent vault directories that survive `git reset --hard`
     */
    function ngwGetLiveVaultDirs($appRoot) {
        $dirs = array();
        if (is_string($appRoot) && $appRoot !== '') {
            $dirs[] = rtrim($appRoot, '/\\') . '/backups/live-data-vault';
        }
        $sysTmp = '/var/tmp/ngw_data_vault';
        if (is_dir('/var/tmp') && is_writable('/var/tmp')) {
            $dirs[] = $sysTmp;
        } else {
            $dirs[] = rtrim(sys_get_temp_dir(), '/\\') . '/ngw_data_vault';
        }
        return $dirs;
    }
}

if (!function_exists('ngwSyncToLiveDataVault')) {
    /**
     * Save current live data files into the persistent vault directories
     * Only updates vault if current live data has routers/resellers, merging with existing vault data.
     */
    function ngwSyncToLiveDataVault($appRoot) {
        $appRoot = rtrim((string)$appRoot, '/\\');
        if ($appRoot === '' || !is_dir($appRoot . '/include')) {
            return false;
        }
        $filesToMirror = array(
            'include/config.php',
            'include/resellers.json',
            'include/admin_users.json',
            'include/router_owners.json',
            'include/license_usage.json',
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
        foreach (ngwGetLiveVaultDirs($appRoot) as $vaultDir) {
            if (!is_dir($vaultDir)) {
                @mkdir($vaultDir, 0755, true);
            }
            if (!is_dir($vaultDir) || !is_writable($vaultDir)) {
                continue;
            }
            foreach ($filesToMirror as $rel) {
                $src = $appRoot . '/' . $rel;
                if (!is_file($src)) {
                    continue;
                }
                $dest = $vaultDir . '/' . $rel;
                if (!is_dir(dirname($dest))) {
                    @mkdir(dirname($dest), 0755, true);
                }
                // For config.php and resellers.json, merge rather than blindly overwriting if vault has more entries
                if ($rel === 'include/config.php' && is_file($dest)) {
                    $curParsed = ngwParseConfigPhpRouters((string)@file_get_contents($src));
                    $vltParsed = ngwParseConfigPhpRouters((string)@file_get_contents($dest));
                    $mergedRouters = array_merge($vltParsed['routers'], $curParsed['routers']);
                    $admLine = !empty($curParsed['admin_line']) ? $curParsed['admin_line'] : $vltParsed['admin_line'];
                    @file_put_contents($dest, ngwBuildConfigPhpContent($admLine, $mergedRouters));
                } elseif ($rel === 'include/resellers.json' && is_file($dest)) {
                    $curList = @json_decode((string)@file_get_contents($src), true);
                    $vltList = @json_decode((string)@file_get_contents($dest), true);
                    if (is_array($curList) && is_array($vltList)) {
                        $mergedMap = array();
                        foreach ($vltList as $r) {
                            if (is_array($r) && !empty($r['id'])) {
                                $mergedMap[(string)$r['id']] = $r;
                            }
                        }
                        foreach ($curList as $r) {
                            if (is_array($r) && !empty($r['id'])) {
                                $mergedMap[(string)$r['id']] = $r;
                            }
                        }
                        @file_put_contents($dest, json_encode(array_values($mergedMap), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    } else {
                        @copy($src, $dest);
                    }
                } else {
                    @copy($src, $dest);
                }
            }
        }
        return true;
    }
}

if (!function_exists('ngwDiscoverCandidateRootsOnVps')) {
    /**
     * Find all candidate directories and extracted archives on the VPS that may hold previous data
     */
    function ngwDiscoverCandidateRootsOnVps($appRoot) {
        $appRoot = rtrim((string)$appRoot, '/\\');
        $candidates = array();

        // 1. Live data vaults
        foreach (ngwGetLiveVaultDirs($appRoot) as $vDir) {
            if (is_dir($vDir)) {
                $candidates['vault:' . $vDir] = $vDir;
            }
        }

        // 2. Sibling / alternate web roots on VPS (/var/www/nextgan-wifi, /var/www/html, /var/www/mikhmon, /root/*)
        $altWebDirs = array(
            '/var/www/nextgan-wifi',
            '/var/www/html',
            '/var/www/mikhmon',
            '/var/www/voucher',
            '/var/www/nextgen-wifi',
        );
        if (is_dir('/var/www')) {
            foreach ((array)@glob('/var/www/*', GLOB_ONLYDIR) as $d) {
                $altWebDirs[] = $d;
            }
        }
        foreach (array_unique($altWebDirs) as $dir) {
            $real = @realpath($dir);
            if ($real && $real !== $appRoot && is_dir($real)) {
                if (is_dir($real . '/include')) {
                    $candidates['webdir:' . $real] = $real;
                }
                if (is_dir($real . '/mikhmon/include')) {
                    $candidates['webdir:' . $real . '/mikhmon'] = $real . '/mikhmon';
                }
            }
        }

        // 3. Backups inside $appRoot/backups and any sibling webdir/backups and /tmp
        $backupSearchBases = array($appRoot . '/backups');
        foreach ($altWebDirs as $dir) {
            if (is_dir($dir . '/backups')) {
                $backupSearchBases[] = $dir . '/backups';
            }
        }
        foreach ((array)@glob('/tmp/ngw_*', GLOB_ONLYDIR) as $tmpDir) {
            $candidates['tmp:' . $tmpDir] = $tmpDir;
        }
        foreach ((array)@glob(rtrim(sys_get_temp_dir(), '/\\') . '/ngw_*', GLOB_ONLYDIR) as $tmpDir) {
            $candidates['tmp:' . $tmpDir] = $tmpDir;
        }

        foreach (array_unique($backupSearchBases) as $bBase) {
            if (!is_dir($bBase)) {
                continue;
            }
            // pre-update-* directories created by deploy/safe-update.sh
            $preDirs = (array)@glob($bBase . '/pre-update-*', GLOB_ONLYDIR);
            sort($preDirs);
            foreach ($preDirs as $pDir) {
                $candidates['pre-update:' . $pDir] = $pDir;
            }
            // Any other subdirectories in backups/ with an include/ folder
            foreach ((array)@glob($bBase . '/*', GLOB_ONLYDIR) as $subDir) {
                if (is_dir($subDir . '/include')) {
                    $candidates['backup-dir:' . $subDir] = $subDir;
                }
            }
        }

        return $candidates;
    }
}

if (!function_exists('ngwExtractDataFromArchivesOnVps')) {
    /**
     * Inspect .tar.gz and .zip archives in backups/ and version-history/ for config.php & json files
     */
    function ngwExtractDataFromArchivesOnVps($appRoot) {
        $appRoot = rtrim((string)$appRoot, '/\\');
        $extractedBlobs = array();
        $archivePaths = array();

        $searchDirs = array(
            $appRoot . '/backups',
            $appRoot . '/backups/version-history',
            '/var/www/nextgan-wifi/backups',
            '/var/www/nextgan-wifi/backups/version-history',
            '/var/www/html/backups',
            '/var/www/html/backups/version-history',
        );
        foreach (array_unique($searchDirs) as $sDir) {
            if (!is_dir($sDir)) continue;
            foreach ((array)@glob($sDir . '/*.{zip,tar.gz,tgz}', GLOB_BRACE) as $arch) {
                if (is_file($arch)) {
                    $archivePaths[$arch] = @filemtime($arch) ?: 0;
                }
            }
        }
        asort($archivePaths); // oldest to newest

        $targetRelFiles = array(
            'include/config.php',
            'include/resellers.json',
            'include/admin_users.json',
            'include/backup_admin.json',
            'include/permission_groups.json',
            'include/vpn_routers.json',
            'include/pppoe_areas.json',
            'include/user_prefs.json',
        );

        foreach (array_keys($archivePaths) as $archFile) {
            if (substr($archFile, -4) === '.zip' && class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($archFile) === true) {
                    $entryMap = array();
                    foreach ($targetRelFiles as $rel) {
                        foreach (array($rel, './' . $rel, 'mikhmon/' . $rel, 'mikhmonv3-upstream/' . $rel) as $tryName) {
                            $idx = $zip->locateName($tryName);
                            if ($idx !== false) {
                                $content = $zip->getFromIndex($idx);
                                if (is_string($content) && $content !== '') {
                                    $entryMap[$rel] = $content;
                                    break;
                                }
                            }
                        }
                    }
                    $zip->close();
                    if (!empty($entryMap)) {
                        $extractedBlobs['archive:' . basename($archFile)] = $entryMap;
                    }
                }
            } elseif ((substr($archFile, -7) === '.tar.gz' || substr($archFile, -4) === '.tgz') && function_exists('shell_exec')) {
                $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/ngw_arch_scan_' . uniqid();
                @mkdir($tmpDir, 0700, true);
                @shell_exec('tar -xzf ' . escapeshellarg($archFile) . ' -C ' . escapeshellarg($tmpDir) . ' 2>/dev/null');
                $entryMap = array();
                foreach ($targetRelFiles as $rel) {
                    foreach (array($tmpDir . '/' . $rel, $tmpDir . '/mikhmon/' . $rel, $tmpDir . '/mikhmonv3-upstream/' . $rel) as $tryPath) {
                        if (is_file($tryPath)) {
                            $content = @file_get_contents($tryPath);
                            if (is_string($content) && $content !== '') {
                                $entryMap[$rel] = $content;
                                break;
                            }
                        }
                    }
                }
                @shell_exec('rm -rf ' . escapeshellarg($tmpDir));
                if (!empty($entryMap)) {
                    $extractedBlobs['archive:' . basename($archFile)] = $entryMap;
                }
            }
        }

        // Also check git reflog / dangling blobs if .git exists
        $gitBase = is_dir($appRoot . '/.git') ? $appRoot : (is_dir(dirname($appRoot) . '/.git') ? dirname($appRoot) : '');
        if ($gitBase !== '' && function_exists('shell_exec')) {
            $reflogHashes = @shell_exec('git -C ' . escapeshellarg($gitBase) . ' log -g --pretty=format:%H -n 30 2>/dev/null');
            if (is_string($reflogHashes) && trim($reflogHashes) !== '') {
                $hashes = array_reverse(array_unique(array_filter(preg_split('/\r?\n/', trim($reflogHashes)))));
                foreach ($hashes as $h) {
                    if (!preg_match('/^[a-f0-9]{7,40}$/i', $h)) continue;
                    $entryMap = array();
                    foreach (array('include/config.php', 'include/resellers.json', 'include/admin_users.json', 'include/vpn_routers.json', 'include/pppoe_areas.json') as $rel) {
                        foreach (array($rel, 'mikhmon/' . $rel) as $gitRel) {
                            $out = @shell_exec('git -C ' . escapeshellarg($gitBase) . ' show ' . escapeshellarg($h . ':' . $gitRel) . ' 2>/dev/null');
                            if (is_string($out) && trim($out) !== '') {
                                $entryMap[$rel] = $out;
                                break;
                            }
                        }
                    }
                    if (!empty($entryMap)) {
                        $extractedBlobs['git-reflog:' . substr($h, 0, 7)] = $entryMap;
                    }
                }
            }
        }

        return $extractedBlobs;
    }
}

if (!function_exists('ngwScanPhpSessionsForResellers')) {
    /**
     * Scan PHP session files on the VPS (/var/lib/php/sessions, /tmp) for any active/recent reseller sessions
     * that survived `git reset --hard`.
     */
    function ngwScanPhpSessionsForResellers() {
        $recoveredResellers = array();
        $sessDirs = array('/var/lib/php/sessions', '/var/lib/php/session', sys_get_temp_dir(), '/tmp');
        $savePath = @session_save_path();
        if (is_string($savePath) && $savePath !== '') {
            if (strpos($savePath, ';') !== false) {
                $parts = explode(';', $savePath);
                $savePath = end($parts);
            }
            $sessDirs[] = $savePath;
        }
        foreach (array_unique($sessDirs) as $dir) {
            if (!is_dir($dir) || !is_readable($dir)) continue;
            foreach ((array)@glob(rtrim($dir, '/\\') . '/sess_*') as $sFile) {
                if (!is_file($sFile) || !is_readable($sFile)) continue;
                $raw = @file_get_contents($sFile);
                if (!is_string($raw) || strpos($raw, 'mikhmon_role') === false) continue;
                // Extract basic serialized session strings if role == reseller
                if (preg_match('/mikhmon_role\|s:8:"reseller"/', $raw)) {
                    $uname = '';
                    $resId = '';
                    if (preg_match('/mikhmon\|s:\d+:"([^"]+)"/', $raw, $mU)) {
                        $uname = $mU[1];
                    }
                    if (preg_match('/mikhmon_reseller_id\|s:\d+:"([^"]+)"/', $raw, $mId)) {
                        $resId = $mId[1];
                    }
                    if ($uname !== '' && $resId !== '') {
                        $recoveredResellers[$resId] = array(
                            'id' => $resId,
                            'username' => $uname,
                        );
                    }
                }
            }
        }
        return $recoveredResellers;
    }
}

if (!function_exists('ngwPerformDeepDataRecoveryAndMerge')) {
    /**
     * Perform a non-destructive deep scan & merge of all routers, resellers, admins, VPN routers,
     * PPPoE areas, user preferences, and custom templates from any surviving backups on the VPS.
     *
     * Returns a structured summary of what was scanned and recovered.
     */
    function ngwPerformDeepDataRecoveryAndMerge($appRoot = null) {
        if ($appRoot === null || $appRoot === '') {
            $appRoot = realpath(__DIR__ . '/..');
        }
        $appRoot = rtrim((string)$appRoot, '/\\');

        $report = array(
            'sources_scanned' => array(),
            'routers_recovered' => array(),
            'resellers_recovered' => array(),
            'admins_recovered' => array(),
            'vpn_recovered' => 0,
            'pppoe_recovered' => 0,
            'templates_restored' => 0,
            'total_routers_now' => 0,
            'total_resellers_now' => 0,
        );

        // Collect all source maps: label => array(relPath => content)
        $sources = array();

        // 1. Archive & git reflog blobs
        foreach (ngwExtractDataFromArchivesOnVps($appRoot) as $label => $blobMap) {
            $sources[$label] = $blobMap;
            $report['sources_scanned'][] = $label;
        }

        // 2. Filesystem candidate directories
        $relFiles = array(
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
        );
        $candidateDirs = ngwDiscoverCandidateRootsOnVps($appRoot);
        foreach ($candidateDirs as $label => $dirPath) {
            $blobMap = array();
            foreach ($relFiles as $rel) {
                $f = $dirPath . '/' . $rel;
                if (is_file($f) && is_readable($f)) {
                    $c = @file_get_contents($f);
                    if (is_string($c) && trim($c) !== '') {
                        $blobMap[$rel] = $c;
                    }
                }
            }
            if (!empty($blobMap)) {
                $sources[$label] = $blobMap;
                $report['sources_scanned'][] = $label;
            }
        }

        // --- A. Merge include/config.php (Routers & Primary Admin) ---
        $configFile = $appRoot . '/include/config.php';
        $currentConfigRaw = is_file($configFile) ? (string)@file_get_contents($configFile) : '';
        $currentConfig = ngwParseConfigPhpRouters($currentConfigRaw);
        $mergedRouters = $currentConfig['routers'];
        $adminLine = $currentConfig['admin_line'];
        $configModified = false;

        foreach ($sources as $srcLabel => $blobMap) {
            if (empty($blobMap['include/config.php'])) continue;
            $parsed = ngwParseConfigPhpRouters($blobMap['include/config.php']);
            // If current admin line is still default mikhmon/1234 and backup had a customized admin credential, prefer backup
            if (!empty($parsed['admin_line']) && strpos($parsed['admin_line'], 'mikhmon<|<mikhmon') === false && strpos($adminLine, 'mikhmon<|<mikhmon') !== false) {
                $adminLine = $parsed['admin_line'];
                $configModified = true;
            }
            foreach ($parsed['routers'] as $sessName => $rLine) {
                if (!isset($mergedRouters[$sessName])) {
                    $mergedRouters[$sessName] = $rLine;
                    $report['routers_recovered'][] = $sessName;
                    $configModified = true;
                } elseif (strpos($srcLabel, 'pre-update:') === 0 || strpos($srcLabel, 'vault:') === 0 || strpos($srcLabel, 'webdir:') === 0) {
                    // If a local VPS backup has an updated line for the same router session, prefer the VPS backup line
                    if ($mergedRouters[$sessName] !== $rLine) {
                        $mergedRouters[$sessName] = $rLine;
                        $configModified = true;
                    }
                }
            }
        }

        if ($configModified) {
            $newConfigContent = ngwBuildConfigPhpContent($adminLine, $mergedRouters);
            @file_put_contents($configFile, $newConfigContent);
        }
        $report['total_routers_now'] = count($mergedRouters);

        // --- B. Merge include/resellers.json ---
        $resFile = $appRoot . '/include/resellers.json';
        $currentResellers = is_file($resFile) ? @json_decode((string)@file_get_contents($resFile), true) : array();
        if (!is_array($currentResellers)) {
            $currentResellers = array();
        }
        $resById = array();
        $resByUser = array();
        foreach ($currentResellers as $r) {
            if (!is_array($r) || empty($r['id'])) continue;
            $idKey = (string)$r['id'];
            $resById[$idKey] = $r;
            if (!empty($r['username'])) {
                $resByUser[strtolower(trim((string)$r['username']))] = $idKey;
            }
        }
        $resModified = false;

        foreach ($sources as $srcLabel => $blobMap) {
            if (empty($blobMap['include/resellers.json'])) continue;
            $list = @json_decode($blobMap['include/resellers.json'], true);
            if (!is_array($list)) continue;
            foreach ($list as $r) {
                if (!is_array($r) || empty($r['id']) || empty($r['username'])) continue;
                $idKey = (string)$r['id'];
                $uKey = strtolower(trim((string)$r['username']));
                if (!isset($resById[$idKey]) && !isset($resByUser[$uKey])) {
                    $resById[$idKey] = $r;
                    $resByUser[$uKey] = $idKey;
                    $report['resellers_recovered'][] = $r['username'] . ' (#' . $idKey . ')';
                    $resModified = true;
                } elseif (isset($resById[$idKey]) && (strpos($srcLabel, 'pre-update:') === 0 || strpos($srcLabel, 'vault:') === 0 || strpos($srcLabel, 'webdir:') === 0)) {
                    // Merge allowed_routers from VPS backup so no assigned routers are lost
                    if (!empty($r['allowed_routers']) && is_array($r['allowed_routers'])) {
                        $curRouters = isset($resById[$idKey]['allowed_routers']) && is_array($resById[$idKey]['allowed_routers'])
                            ? $resById[$idKey]['allowed_routers']
                            : array();
                        $mergedAllowed = array_values(array_unique(array_merge($curRouters, $r['allowed_routers'])));
                        if ($mergedAllowed !== $curRouters) {
                            $resById[$idKey]['allowed_routers'] = $mergedAllowed;
                            $resModified = true;
                        }
                    }
                }
            }
        }

        if ($resModified) {
            @file_put_contents($resFile, json_encode(array_values($resById), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        $report['total_resellers_now'] = count($resById);

        // --- C. Merge include/admin_users.json ---
        $admFile = $appRoot . '/include/admin_users.json';
        $currentAdmins = is_file($admFile) ? @json_decode((string)@file_get_contents($admFile), true) : array();
        if (!is_array($currentAdmins)) {
            $currentAdmins = array();
        }
        $admByUser = array();
        foreach ($currentAdmins as $a) {
            if (!is_array($a) || empty($a['username'])) continue;
            $admByUser[strtolower(trim((string)$a['username']))] = $a;
        }
        $admModified = false;
        foreach ($sources as $srcLabel => $blobMap) {
            if (empty($blobMap['include/admin_users.json'])) continue;
            $list = @json_decode($blobMap['include/admin_users.json'], true);
            if (!is_array($list)) continue;
            foreach ($list as $a) {
                if (!is_array($a) || empty($a['username'])) continue;
                $uKey = strtolower(trim((string)$a['username']));
                if (!isset($admByUser[$uKey])) {
                    $admByUser[$uKey] = $a;
                    $report['admins_recovered'][] = $a['username'];
                    $admModified = true;
                }
            }
        }
        if ($admModified) {
            @file_put_contents($admFile, json_encode(array_values($admByUser), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // --- D. Merge include/vpn_routers.json & include/pppoe_areas.json ---
        foreach (array('include/vpn_routers.json' => 'vpn_recovered', 'include/pppoe_areas.json' => 'pppoe_recovered') as $jsonRel => $repKey) {
            $targetPath = $appRoot . '/' . $jsonRel;
            $curArr = is_file($targetPath) ? @json_decode((string)@file_get_contents($targetPath), true) : array();
            if (!is_array($curArr)) $curArr = array();
            $byKey = array();
            foreach ($curArr as $item) {
                $k = is_array($item) && isset($item['id']) ? (string)$item['id'] : md5(json_encode($item));
                $byKey[$k] = $item;
            }
            $mod = false;
            foreach ($sources as $blobMap) {
                if (empty($blobMap[$jsonRel])) continue;
                $srcArr = @json_decode($blobMap[$jsonRel], true);
                if (!is_array($srcArr)) continue;
                foreach ($srcArr as $item) {
                    $k = is_array($item) && isset($item['id']) ? (string)$item['id'] : md5(json_encode($item));
                    if (!isset($byKey[$k])) {
                        $byKey[$k] = $item;
                        $report[$repKey]++;
                        $mod = true;
                    }
                }
            }
            if ($mod) {
                @file_put_contents($targetPath, json_encode(array_values($byKey), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }

        // --- E. Restore custom Reseller Templates from surviving .bak.php or backup folders ---
        foreach ($candidateDirs as $srcLabel => $dirPath) {
            $upTplDir = $dirPath . '/uploads/reseller-templates';
            if (is_dir($upTplDir)) {
                foreach ((array)@glob($upTplDir . '/*', GLOB_ONLYDIR) as $rTplDir) {
                    $rid = basename($rTplDir);
                    $destDir = $appRoot . '/uploads/reseller-templates/' . $rid;
                    if (!is_dir($destDir)) {
                        @mkdir($destDir, 0755, true);
                    }
                    foreach ((array)@glob($rTplDir . '/*.php') as $tplFile) {
                        $destFile = $destDir . '/' . basename($tplFile);
                        if (!is_file($destFile)) {
                            if (@copy($tplFile, $destFile)) {
                                $report['templates_restored']++;
                            }
                        }
                    }
                }
            }
        }

        // Sync merged state back into the persistent Live Data Vault
        ngwSyncToLiveDataVault($appRoot);

        return $report;
    }
}
