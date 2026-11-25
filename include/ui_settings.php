<?php
/*
 * NextGan WiFi Solution — UI Style, Admin UI Permissions & Per-Reseller UI Assignment Manager
 *
 * Rules:
 * 1. Classic UI ('classic') is ALWAYS the default for everyone (fresh browser Login page, every
 *    unassigned Admin, every unassigned Reseller, and every new account) unless explicitly assigned.
 * 2. Super Admin's OWN UI selection is strictly personal to that Super Admin account — changing Super
 *    Admin's UI NEVER changes any regular Admin's UI, any Reseller's UI, or any other browser's Login screen.
 * 3. On the SAME browser, the Login screen remembers the UI of the last logged-in user via the
 *    browser-local `ngw_last_ui` cookie, so after logout the login screen matches that user's UI.
 * 4. Regular Admins default to Classic UI and only use their explicitly assigned UI (or switch their
 *    own UI / their own isolated resellers' UIs when Super Admin grants them extra UI permissions).
 * 5. Resellers cannot change UI settings; they ALWAYS see their explicitly assigned UI or Classic
 *    default — including when Super Admin or Admin directly logs into (impersonates) a reseller account.
 */

if (!function_exists('ngwGetAvailableUiStyles')) {
    function ngwGetAvailableUiStyles($isBn = false) {
        return array(
            'classic' => array(
                'key' => 'classic',
                'name' => $isBn ? 'ক্লাসিক ইউআই (Classic)' : 'Classic UI',
                'short' => $isBn ? 'ক্লাসিক' : 'Classic',
                'tagline' => $isBn ? 'পূর্বের মূল ইন্টারফেস ও পরিচিত লেআউট (সবার জন্য ডিফল্ট)' : 'Original classic interface & familiar layout (Default for Everyone)',
                'badge' => $isBn ? 'ডিফল্ট' : 'Default',
            ),
            'modern' => array(
                'key' => 'modern',
                'name' => $isBn ? 'মডার্ন স্টুডিও (Modern Studio)' : 'Modern Studio UI',
                'tagline' => $isBn ? 'ফ্লোটিং সাইডবার, ফ্রস্টেড গ্লাস টপবার ও স্প্লিট লগইন স্টুডিও' : 'Floating rounded sidebar rail, frosted topbar & split login studio',
                'short' => $isBn ? 'মডার্ন স্টুডিও' : 'Modern Studio',
                'badge' => $isBn ? 'আধুনিক' : 'Modern',
            ),
            'compact' => array(
                'key' => 'compact',
                'name' => $isBn ? 'নোভা কমান্ড (Nova Command)' : 'Nova Command UI',
                'short' => $isBn ? 'নোভা কমান্ড' : 'Nova Command',
                'tagline' => $isBn ? 'ইনসেট পিল টপ-ডক, ফুল-হাইট কালার স্ট্রিপ বেন্টো কার্ড, ডায়াগনাল গ্রিড ও সলিড অ্যাক্টিভ পিল' : 'Inset floating pill dock, left-band bento tiles, diagonal drafting grid & solid active pills',
                'badge' => $isBn ? 'প্রো ডেক' : 'Pro Deck',
            ),
            'aurora' => array(
                'key' => 'aurora',
                'name' => $isBn ? 'ক্রিস্টাল গ্লাস ওএস (Crystal Glass)' : 'Crystal Glass OS',
                'short' => $isBn ? 'ক্রিস্টাল গ্লাস' : 'Crystal Glass',
                'tagline' => $isBn ? 'সম্পূর্ণ নতুন গ্লাসমর্ফিজম ওয়ার্কস্পেস: প্রতিটি বাটন ও অপশন ফ্রস্টেড গ্লাস, নতুন রাউটার হাব, ৩-ধাপ অ্যাড রাউটার স্টুডিও, রিসেলার কার্ড ডিরেক্টরি ও Ctrl+K কুইক লঞ্চার' : 'Full frosted glassmorphism OS: crystal glass buttons & controls, redesigned Router Hub, 3-step Add Router Studio, Reseller Card Directory & Ctrl+K launcher',
                'badge' => $isBn ? 'গ্লাস ওএস' : 'Glass OS',
            ),
        );
    }
}

if (!function_exists('ngwGetUiStyleLabel')) {
    function ngwGetUiStyleLabel($uiKey, $isBn = false) {
        $uiKey = strtolower(trim((string)$uiKey));
        if ($uiKey === '' || $uiKey === 'default') {
            return $isBn ? 'ডিফল্ট (ক্লাসিক)' : 'Default (Classic)';
        }
        $styles = ngwGetAvailableUiStyles($isBn);
        return isset($styles[$uiKey]['name']) ? $styles[$uiKey]['name'] : ucfirst($uiKey);
    }
}

if (!function_exists('ngwGetUiSettingsFilePaths')) {
    function ngwGetUiSettingsFilePaths() {
        return array(
            dirname(__DIR__) . '/uploads/ui_settings.json',
            __DIR__ . '/ui_settings.json',
        );
    }
}

if (!function_exists('ngwSanitizeUiStyleKey')) {
    function ngwSanitizeUiStyleKey($val, $allowDefault = false) {
        $v = strtolower(trim((string)$val));
        $valid = array('classic', 'modern', 'compact', 'aurora');
        if ($allowDefault && ($v === '' || $v === 'default')) {
            return 'default';
        }
        return in_array($v, $valid, true) ? $v : ($allowDefault ? 'default' : 'classic');
    }
}

if (!function_exists('ngwIsHttpsForUiCookie')) {
    function ngwIsHttpsForUiCookie() {
        if (function_exists('ngwIsHttpsRequest')) {
            return ngwIsHttpsRequest();
        }
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }
}

if (!function_exists('ngwRememberBrowserUiCookie')) {
    function ngwRememberBrowserUiCookie($uiKey) {
        $clean = ngwSanitizeUiStyleKey($uiKey, false);
        $_COOKIE['ngw_last_ui'] = $clean;
        if (headers_sent()) {
            return $clean;
        }
        $expires = time() + (365 * 86400);
        $expStr = gmdate('D, d M Y H:i:s \G\M\T', $expires);
        $isHttps = ngwIsHttpsForUiCookie();
        $header = 'Set-Cookie: ngw_last_ui=' . rawurlencode($clean)
            . '; Expires=' . $expStr
            . '; Max-Age=31536000; Path=/';
        if ($isHttps) {
            $header .= '; Secure; SameSite=None; Partitioned';
        } else {
            $header .= '; SameSite=Lax';
        }
        @header($header, false);
        return $clean;
    }
}

if (!function_exists('ngwGetBrowserUiCookieJs')) {
    function ngwGetBrowserUiCookieJs($uiKey) {
        $clean = ngwSanitizeUiStyleKey($uiKey, false);
        $jsVal = json_encode($clean);
        return "(function(){try{var v=" . $jsVal . ";var base='ngw_last_ui='+encodeURIComponent(v)+'; path=/; max-age=31536000';if(location.protocol==='https:'){document.cookie=base+'; SameSite=None; Secure; Partitioned';if(document.cookie.indexOf('ngw_last_ui='+v)===-1){document.cookie=base+'; SameSite=Lax; Secure';}}else{document.cookie=base+'; SameSite=Lax';}}catch(e){}})();";
    }
}

if (!function_exists('ngwSanitizeAllowedUiList')) {
    function ngwSanitizeAllowedUiList($list) {
        $clean = array('classic' => 'classic');
        if (is_array($list)) {
            foreach ($list as $k => $v) {
                $candidate = is_int($k) ? strtolower(trim((string)$v)) : (!empty($v) ? strtolower(trim((string)$k)) : '');
                if ($candidate === 'modern' || $candidate === 'compact' || $candidate === 'aurora') {
                    $clean[$candidate] = $candidate;
                }
            }
        }
        return array_values($clean);
    }
}

if (!function_exists('ngwReadJsonArraySafe')) {
    function ngwReadJsonArraySafe($filePath) {
        if (!is_string($filePath) || $filePath === '' || !is_file($filePath)) {
            return array();
        }
        $raw = @file_get_contents($filePath);
        if (!is_string($raw) || trim($raw) === '') {
            return array();
        }
        $decoded = @json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }
}

if (!function_exists('ngwFindResellerRecordForUi')) {
    function ngwFindResellerRecordForUi($rid, $username = '') {
        $rid = trim((string)$rid);
        $unameLower = strtolower(trim((string)$username));
        if ($rid === '' && $unameLower === '') {
            return null;
        }
        $list = function_exists('loadResellers')
            ? loadResellers()
            : ngwReadJsonArraySafe(__DIR__ . '/resellers.json');
        if (!is_array($list)) {
            return null;
        }
        foreach ($list as $r) {
            if (!is_array($r)) {
                continue;
            }
            $rIdStr = isset($r['id']) ? trim((string)$r['id']) : '';
            if ($rid !== '' && $rIdStr === $rid) {
                return $r;
            }
            if ($rid !== '' && isset($r['legacy_ids']) && is_array($r['legacy_ids'])) {
                foreach ($r['legacy_ids'] as $lid) {
                    if (trim((string)$lid) === $rid) {
                        return $r;
                    }
                }
            }
        }
        if ($unameLower !== '') {
            foreach ($list as $r) {
                if (!is_array($r)) {
                    continue;
                }
                if (isset($r['username']) && strtolower(trim((string)$r['username'])) === $unameLower) {
                    return $r;
                }
            }
        }
        return null;
    }
}

if (!function_exists('ngwFindAdminRecordForUi')) {
    function ngwFindAdminRecordForUi($aid, $username = '') {
        $aid = trim((string)$aid);
        $unameLower = strtolower(trim((string)$username));
        if ($aid === '' && $unameLower === '') {
            return null;
        }
        $list = function_exists('loadAdminUsers')
            ? loadAdminUsers()
            : ngwReadJsonArraySafe(__DIR__ . '/admin_users.json');
        if (!is_array($list)) {
            return null;
        }
        foreach ($list as $a) {
            if (!is_array($a)) {
                continue;
            }
            if ($aid !== '' && isset($a['id']) && trim((string)$a['id']) === $aid) {
                return $a;
            }
        }
        if ($unameLower !== '') {
            foreach ($list as $a) {
                if (!is_array($a)) {
                    continue;
                }
                if (isset($a['username']) && strtolower(trim((string)$a['username'])) === $unameLower) {
                    return $a;
                }
            }
        }
        return null;
    }
}

if (!function_exists('ngwLoadUiSettings')) {
    function ngwLoadUiSettings($forceReload = false) {
        if (!$forceReload && isset($GLOBALS['NGW_CACHE_UI_SETTINGS']) && is_array($GLOBALS['NGW_CACHE_UI_SETTINGS'])) {
            return $GLOBALS['NGW_CACHE_UI_SETTINGS'];
        }
        $result = array(
            'default_ui' => 'classic',
            'super_admin_ui' => array(),
            'admin_ui' => array(),
            'admin_allowed_uis' => array(),
            'reseller_ui' => array(),
            'updated_at' => '',
            'updated_by' => '',
        );
        $paths = array_reverse(ngwGetUiSettingsFilePaths());
        foreach ($paths as $file) {
            if (!is_file($file)) {
                continue;
            }
            $raw = @file_get_contents($file);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $decoded = @json_decode($raw, true);
            if (is_array($decoded)) {
                if (isset($decoded['default_ui'])) {
                    $result['default_ui'] = ngwSanitizeUiStyleKey($decoded['default_ui'], false);
                }
                if (isset($decoded['super_admin_ui']) && is_array($decoded['super_admin_ui'])) {
                    foreach ($decoded['super_admin_ui'] as $sk => $sv) {
                        $skClean = trim((string)$sk);
                        $svClean = ngwSanitizeUiStyleKey($sv, false);
                        if ($skClean !== '') {
                            $result['super_admin_ui'][$skClean] = $svClean;
                        }
                    }
                }
                if (isset($decoded['admin_ui']) && is_array($decoded['admin_ui'])) {
                    foreach ($decoded['admin_ui'] as $ak => $av) {
                        $akClean = trim((string)$ak);
                        $avClean = ngwSanitizeUiStyleKey($av, false);
                        if ($akClean !== '') {
                            $result['admin_ui'][$akClean] = $avClean;
                        }
                    }
                }
                if (isset($decoded['admin_allowed_uis']) && is_array($decoded['admin_allowed_uis'])) {
                    foreach ($decoded['admin_allowed_uis'] as $ak => $al) {
                        $akClean = trim((string)$ak);
                        if ($akClean !== '' && is_array($al)) {
                            $result['admin_allowed_uis'][$akClean] = ngwSanitizeAllowedUiList($al);
                        }
                    }
                }
                if (isset($decoded['reseller_ui']) && is_array($decoded['reseller_ui'])) {
                    foreach ($decoded['reseller_ui'] as $rk => $rv) {
                        $rkClean = trim((string)$rk);
                        $rvClean = ngwSanitizeUiStyleKey($rv, true);
                        if ($rkClean !== '' && $rvClean !== 'default') {
                            $result['reseller_ui'][$rkClean] = $rvClean;
                        }
                    }
                }
                if (!empty($decoded['updated_at'])) {
                    $result['updated_at'] = (string)$decoded['updated_at'];
                }
                if (!empty($decoded['updated_by'])) {
                    $result['updated_by'] = (string)$decoded['updated_by'];
                }
            }
        }
        $GLOBALS['NGW_CACHE_UI_SETTINGS'] = $result;
        return $result;
    }
}

if (!function_exists('ngwSaveUiSettings')) {
    function ngwSaveUiSettings($settings) {
        $clean = array(
            // Global default for all unassigned accounts is ALWAYS 'classic'
            'default_ui' => 'classic',
            'super_admin_ui' => array(),
            'admin_ui' => array(),
            'admin_allowed_uis' => array(),
            'reseller_ui' => array(),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => isset($_SESSION['mikhmon']) ? (string)$_SESSION['mikhmon'] : 'super_admin',
        );
        if (isset($settings['super_admin_ui']) && is_array($settings['super_admin_ui'])) {
            foreach ($settings['super_admin_ui'] as $sk => $sv) {
                $skClean = trim((string)$sk);
                $svClean = ngwSanitizeUiStyleKey($sv, false);
                if ($skClean !== '') {
                    $clean['super_admin_ui'][$skClean] = $svClean;
                }
            }
        }
        if (isset($settings['admin_ui']) && is_array($settings['admin_ui'])) {
            foreach ($settings['admin_ui'] as $ak => $av) {
                $akClean = trim((string)$ak);
                $avClean = ngwSanitizeUiStyleKey($av, false);
                if ($akClean !== '') {
                    $clean['admin_ui'][$akClean] = $avClean;
                }
            }
        }
        if (isset($settings['admin_allowed_uis']) && is_array($settings['admin_allowed_uis'])) {
            foreach ($settings['admin_allowed_uis'] as $ak => $al) {
                $akClean = trim((string)$ak);
                if ($akClean !== '' && is_array($al)) {
                    $clean['admin_allowed_uis'][$akClean] = ngwSanitizeAllowedUiList($al);
                }
            }
        }
        if (isset($settings['reseller_ui']) && is_array($settings['reseller_ui'])) {
            foreach ($settings['reseller_ui'] as $rk => $rv) {
                $rkClean = trim((string)$rk);
                $rvClean = ngwSanitizeUiStyleKey($rv, true);
                if ($rkClean !== '' && $rvClean !== 'default') {
                    $clean['reseller_ui'][$rkClean] = $rvClean;
                }
            }
        }
        $json = json_encode($clean, defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : 0);
        $savedAny = false;
        foreach (ngwGetUiSettingsFilePaths() as $file) {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $tmp = $file . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
            if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                @rename($tmp, $file);
                $savedAny = true;
            } elseif (@file_put_contents($file, $json) !== false) {
                $savedAny = true;
            }
        }
        $GLOBALS['NGW_CACHE_UI_SETTINGS'] = $clean;
        return $savedAny;
    }
}

if (!function_exists('ngwGetCurrentSuperAdminUiKey')) {
    function ngwGetCurrentSuperAdminUiKey() {
        $aid = isset($_SESSION['mikhmon_admin_id']) ? trim((string)$_SESSION['mikhmon_admin_id']) : '';
        if ($aid !== '') {
            return $aid;
        }
        $uname = isset($_SESSION['mikhmon']) ? trim((string)$_SESSION['mikhmon']) : '';
        $rec = ngwFindAdminRecordForUi('', $uname);
        if (is_array($rec) && !empty($rec['id'])) {
            return trim((string)$rec['id']);
        }
        if ($uname !== '') {
            return 'super:' . strtolower($uname);
        }
        return 'primary_super_admin';
    }
}

if (!function_exists('ngwGetSuperAdminUi')) {
    function ngwGetSuperAdminUi($superAdminKey = '') {
        $key = trim((string)$superAdminKey);
        if ($key === '') {
            $key = ngwGetCurrentSuperAdminUiKey();
        }
        $settings = ngwLoadUiSettings();
        if (isset($settings['super_admin_ui'][$key])) {
            return ngwSanitizeUiStyleKey($settings['super_admin_ui'][$key], false);
        }
        // One-time legacy fallback ONLY if super_admin_ui map has never been populated yet
        if (empty($settings['super_admin_ui']) && !empty($settings['default_ui']) && $settings['default_ui'] !== 'classic') {
            return ngwSanitizeUiStyleKey($settings['default_ui'], false);
        }
        return 'classic';
    }
}

if (!function_exists('ngwSetSuperAdminUi')) {
    function ngwSetSuperAdminUi($superAdminKey, $uiStyle) {
        $key = trim((string)$superAdminKey);
        if ($key === '') {
            $key = ngwGetCurrentSuperAdminUiKey();
        }
        $settings = ngwLoadUiSettings(true);
        if (!isset($settings['super_admin_ui']) || !is_array($settings['super_admin_ui'])) {
            $settings['super_admin_ui'] = array();
        }
        $cleanUi = ngwSanitizeUiStyleKey($uiStyle, false);
        $settings['super_admin_ui'][$key] = $cleanUi;
        // Never let Super Admin's personal UI change the global default
        $settings['default_ui'] = 'classic';
        $saved = ngwSaveUiSettings($settings);
        if ($saved && $key === ngwGetCurrentSuperAdminUiKey()) {
            ngwRememberBrowserUiCookie($cleanUi);
        }
        return $saved;
    }
}

if (!function_exists('ngwGetAdminAllowedUis')) {
    function ngwGetAdminAllowedUis($adminId, $adminRecord = null) {
        if (is_array($adminRecord)) {
            $role = (isset($adminRecord['admin_role']) && $adminRecord['admin_role'] === 'admin') ? 'admin' : 'super_admin';
            if ($role !== 'admin') {
                return array('classic', 'modern', 'compact', 'aurora');
            }
        }
        $aid = trim((string)$adminId);
        $settings = ngwLoadUiSettings();
        if ($aid !== '' && isset($settings['admin_allowed_uis'][$aid]) && is_array($settings['admin_allowed_uis'][$aid])) {
            return ngwSanitizeAllowedUiList($settings['admin_allowed_uis'][$aid]);
        }
        if (is_array($adminRecord) && isset($adminRecord['allowed_uis']) && is_array($adminRecord['allowed_uis'])) {
            return ngwSanitizeAllowedUiList($adminRecord['allowed_uis']);
        }
        return array('classic');
    }
}

if (!function_exists('ngwGetAdminAssignedUi')) {
    function ngwGetAdminAssignedUi($adminId, $adminRecord = null) {
        $aid = trim((string)$adminId);
        $settings = ngwLoadUiSettings();
        if ($aid !== '' && isset($settings['admin_ui'][$aid])) {
            return ngwSanitizeUiStyleKey($settings['admin_ui'][$aid], false);
        }
        if (is_array($adminRecord) && !empty($adminRecord['ui_style'])) {
            return ngwSanitizeUiStyleKey($adminRecord['ui_style'], false);
        }
        return 'classic';
    }
}

if (!function_exists('ngwSetAdminUiConfig')) {
    function ngwSetAdminUiConfig($adminId, $assignedUi, $allowedUis) {
        $aid = trim((string)$adminId);
        if ($aid === '') {
            return false;
        }
        $settings = ngwLoadUiSettings(true);
        $cleanAllowed = ngwSanitizeAllowedUiList($allowedUis);
        $cleanAssigned = ngwSanitizeUiStyleKey($assignedUi, false);
        $settings['admin_allowed_uis'][$aid] = $cleanAllowed;
        $settings['admin_ui'][$aid] = $cleanAssigned;
        return ngwSaveUiSettings($settings);
    }
}

if (!function_exists('ngwGetResellerAssignedUi')) {
    function ngwGetResellerAssignedUi($resellerId, $resellerRecord = null) {
        $settings = ngwLoadUiSettings();
        $rid = trim((string)$resellerId);
        if ($rid !== '' && isset($settings['reseller_ui'][$rid])) {
            return ngwSanitizeUiStyleKey($settings['reseller_ui'][$rid], true);
        }
        if (is_array($resellerRecord) && !empty($resellerRecord['ui_style'])) {
            return ngwSanitizeUiStyleKey($resellerRecord['ui_style'], true);
        }
        return 'default';
    }
}

if (!function_exists('ngwSetResellerAssignedUi')) {
    function ngwSetResellerAssignedUi($resellerId, $uiStyle) {
        $rid = trim((string)$resellerId);
        if ($rid === '') {
            return false;
        }
        $settings = ngwLoadUiSettings(true);
        $cleanStyle = ngwSanitizeUiStyleKey($uiStyle, true);
        if ($cleanStyle === 'default') {
            unset($settings['reseller_ui'][$rid]);
        } else {
            $settings['reseller_ui'][$rid] = $cleanStyle;
        }
        return ngwSaveUiSettings($settings);
    }
}

if (!function_exists('ngwGetPermittedUiKeysForCurrentUser')) {
    function ngwGetPermittedUiKeysForCurrentUser() {
        if (empty($_SESSION['mikhmon'])) {
            return array();
        }
        if (isset($_SESSION['mikhmon_role']) && $_SESSION['mikhmon_role'] === 'reseller') {
            return array();
        }
        if (function_exists('isMikhmonAdminStore') && isMikhmonAdminStore()) {
            return array('classic', 'modern', 'compact', 'aurora');
        }
        $admRole = isset($_SESSION['mikhmon_admin_role']) ? (string)$_SESSION['mikhmon_admin_role'] : '';
        if ($admRole === 'super_admin' || $admRole === 'admin_store') {
            return array('classic', 'modern', 'compact', 'aurora');
        }
        $aid = isset($_SESSION['mikhmon_admin_id']) ? trim((string)$_SESSION['mikhmon_admin_id']) : '';
        $admRec = function_exists('getCurrentAdminRecord') ? getCurrentAdminRecord() : ngwFindAdminRecordForUi($aid, isset($_SESSION['mikhmon']) ? $_SESSION['mikhmon'] : '');
        if ($aid === '' && is_array($admRec) && !empty($admRec['id'])) {
            $aid = (string)$admRec['id'];
        }
        return ngwGetAdminAllowedUis($aid, $admRec);
    }
}

if (!function_exists('ngwCanCurrentUserManageUi')) {
    function ngwCanCurrentUserManageUi() {
        if (empty($_SESSION['mikhmon'])) {
            return false;
        }
        if (isset($_SESSION['mikhmon_role']) && $_SESSION['mikhmon_role'] === 'reseller') {
            return false;
        }
        if (function_exists('isMikhmonAdminStore') && isMikhmonAdminStore()) {
            return true;
        }
        $permitted = ngwGetPermittedUiKeysForCurrentUser();
        // Regular Admin can manage/switch UI ONLY when granted at least one extra UI beyond 'classic'
        foreach ($permitted as $k) {
            if ($k === 'modern' || $k === 'compact' || $k === 'aurora') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('ngwResolveActiveUiStyle')) {
    function ngwResolveActiveUiStyle() {
        // 0. Unauthenticated guest (Login page):
        //    Remember the last logged-in user's UI on THIS browser via ngw_last_ui cookie;
        //    a fresh browser with no cookie always defaults to Classic UI.
        if (empty($_SESSION['mikhmon'])) {
            if (!empty($_COOKIE['ngw_last_ui'])) {
                return ngwSanitizeUiStyleKey($_COOKIE['ngw_last_ui'], false);
            }
            return 'classic';
        }

        $settings = ngwLoadUiSettings();

        // 1. Reseller session (including Super Admin / Admin 1-click direct login as Reseller)
        //    -> Strictly THAT reseller's assigned UI, else Classic ('classic'). NEVER Super Admin's UI.
        if (isset($_SESSION['mikhmon_role']) && $_SESSION['mikhmon_role'] === 'reseller') {
            $rid = isset($_SESSION['mikhmon_reseller_id']) ? trim((string)$_SESSION['mikhmon_reseller_id']) : '';
            $rUser = isset($_SESSION['mikhmon']) ? trim((string)$_SESSION['mikhmon']) : '';

            if ($rid !== '' && isset($settings['reseller_ui'][$rid])) {
                $assigned = ngwSanitizeUiStyleKey($settings['reseller_ui'][$rid], true);
                if ($assigned !== 'default') {
                    return $assigned;
                }
            }

            $resRec = ngwFindResellerRecordForUi($rid, $rUser);
            if (is_array($resRec)) {
                $recId = isset($resRec['id']) ? trim((string)$resRec['id']) : '';
                if ($recId !== '' && isset($settings['reseller_ui'][$recId])) {
                    $assignedByRec = ngwSanitizeUiStyleKey($settings['reseller_ui'][$recId], true);
                    if ($assignedByRec !== 'default') {
                        return $assignedByRec;
                    }
                }
                if (!empty($resRec['ui_style'])) {
                    $recUi = ngwSanitizeUiStyleKey($resRec['ui_style'], true);
                    if ($recUi !== 'default') {
                        return $recUi;
                    }
                }
            }
            return 'classic';
        }

        // 2. Regular Sub-Admin session (including Super Admin 1-click direct login as Regular Admin)
        //    -> Strictly THAT admin's assigned/selected UI, else Classic ('classic'). NEVER Super Admin's UI.
        $admRole = isset($_SESSION['mikhmon_admin_role']) ? (string)$_SESSION['mikhmon_admin_role'] : '';
        $aid = isset($_SESSION['mikhmon_admin_id']) ? trim((string)$_SESSION['mikhmon_admin_id']) : '';
        $aUser = isset($_SESSION['mikhmon']) ? trim((string)$_SESSION['mikhmon']) : '';
        $admRec = null;

        if ($admRole === '') {
            $admRec = ngwFindAdminRecordForUi($aid, $aUser);
            if (is_array($admRec) && isset($admRec['admin_role']) && $admRec['admin_role'] === 'admin') {
                $admRole = 'admin';
            }
        }

        if ($admRole === 'admin') {
            if ($aid !== '' && isset($settings['admin_ui'][$aid])) {
                return ngwSanitizeUiStyleKey($settings['admin_ui'][$aid], false);
            }
            if ($admRec === null) {
                $admRec = ngwFindAdminRecordForUi($aid, $aUser);
            }
            if (is_array($admRec)) {
                $recAid = isset($admRec['id']) ? trim((string)$admRec['id']) : '';
                if ($recAid !== '' && isset($settings['admin_ui'][$recAid])) {
                    return ngwSanitizeUiStyleKey($settings['admin_ui'][$recAid], false);
                }
                if (!empty($admRec['ui_style'])) {
                    return ngwSanitizeUiStyleKey($admRec['ui_style'], false);
                }
            }
            return 'classic';
        }

        // 3. Super Admin session -> ONLY this Super Admin's personal UI (defaults to 'classic')
        return ngwGetSuperAdminUi(ngwGetCurrentSuperAdminUiKey());
    }
}
