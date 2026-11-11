<?php
/*
 * NextGan WiFi Solution — Per-User Persistent Language Resolver
 * Ensures each Admin and each Reseller has their own independent language setting ('en' or 'bn')
 * stored persistently across logins without affecting other users.
 */

if (!function_exists('ngwGetUserPrefsFilePaths')) {
    function ngwGetUserPrefsFilePaths() {
        return array(
            dirname(__DIR__) . '/uploads/user_prefs.json',
            __DIR__ . '/user_prefs.json',
        );
    }
}

if (!function_exists('ngwGetUserPrefsFilePath')) {
    function ngwGetUserPrefsFilePath() {
        return __DIR__ . '/user_prefs.json';
    }
}

if (!function_exists('ngwLoadAllUserPrefs')) {
    function ngwLoadAllUserPrefs() {
        $merged = array();
        $paths = array_reverse(ngwGetUserPrefsFilePaths());
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
                foreach ($decoded as $k => $v) {
                    if (is_array($v)) {
                        $merged[$k] = isset($merged[$k]) && is_array($merged[$k]) ? array_merge($merged[$k], $v) : $v;
                    }
                }
            }
        }
        return $merged;
    }
}

if (!function_exists('ngwSaveAllUserPrefs')) {
    function ngwSaveAllUserPrefs($prefs) {
        if (!is_array($prefs)) {
            $prefs = array();
        }
        $json = json_encode($prefs, defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : 0);
        $savedAny = false;
        foreach (ngwGetUserPrefsFilePaths() as $file) {
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
        return $savedAny;
    }
}

if (!function_exists('ngwGetCurrentUserPrefKeys')) {
    function ngwGetCurrentUserPrefKeys() {
        if (empty($_SESSION['mikhmon'])) {
            return array();
        }
        $username = strtolower(trim((string)$_SESSION['mikhmon']));
        $role = isset($_SESSION['mikhmon_role']) ? strtolower(trim((string)$_SESSION['mikhmon_role'])) : 'admin';
        $keys = array();
        if ($role === 'reseller') {
            $resId = isset($_SESSION['mikhmon_reseller_id']) ? strtolower(trim((string)$_SESSION['mikhmon_reseller_id'])) : '';
            if ($resId !== '') {
                $keys[] = 'reseller_id:' . $resId;
            }
            if ($username !== '') {
                $keys[] = 'reseller_user:' . $username;
            }
        } else {
            $admId = isset($_SESSION['mikhmon_admin_id']) ? strtolower(trim((string)$_SESSION['mikhmon_admin_id'])) : '';
            if ($admId !== '') {
                $keys[] = 'admin_id:' . $admId;
            }
            if ($username !== '') {
                $keys[] = 'admin_user:' . $username;
            }
        }
        return array_values(array_unique($keys));
    }
}

if (!function_exists('ngwResolveCurrentUserLang')) {
    function ngwResolveCurrentUserLang($forceReloadFromStorage = false) {
        $allowed = array('en', 'bn');
        if (!empty($_SESSION['mikhmon'])) {
            $keys = ngwGetCurrentUserPrefKeys();
            $ownerSig = implode('|', $keys);
            $cookieKey = 'ngw_ulang_' . substr(md5($ownerSig), 0, 12);

            // 1. Active session for the same logged-in user takes immediate priority unless forced to reload on login
            if (!$forceReloadFromStorage && isset($_SESSION['lang_owner']) && $_SESSION['lang_owner'] === $ownerSig && !empty($_SESSION['lang']) && in_array($_SESSION['lang'], $allowed, true)) {
                return $_SESSION['lang'];
            }

            // 2. Check user_prefs.json (uploads/user_prefs.json & include/user_prefs.json)
            $prefs = ngwLoadAllUserPrefs();
            foreach ($keys as $k) {
                if (isset($prefs[$k]) && is_array($prefs[$k]) && !empty($prefs[$k]['lang'])) {
                    $candidate = strtolower(trim((string)$prefs[$k]['lang']));
                    if (in_array($candidate, $allowed, true)) {
                        $_SESSION['lang'] = $candidate;
                        $_SESSION['lang_owner'] = $ownerSig;
                        return $candidate;
                    }
                }
            }

            // 3. Check resellers.json or admin_users.json
            $role = isset($_SESSION['mikhmon_role']) ? strtolower(trim((string)$_SESSION['mikhmon_role'])) : 'admin';
            $username = strtolower(trim((string)$_SESSION['mikhmon']));
            if ($role === 'reseller') {
                $resFile = __DIR__ . '/resellers.json';
                if (is_file($resFile)) {
                    $resList = @json_decode((string)@file_get_contents($resFile), true);
                    if (is_array($resList)) {
                        $resId = isset($_SESSION['mikhmon_reseller_id']) ? (string)$_SESSION['mikhmon_reseller_id'] : '';
                        foreach ($resList as $r) {
                            if (!is_array($r)) continue;
                            $rIdMatch = ($resId !== '' && isset($r['id']) && (string)$r['id'] === $resId);
                            $rUserMatch = (isset($r['username']) && strtolower(trim((string)$r['username'])) === $username);
                            if (($rIdMatch || $rUserMatch) && !empty($r['lang']) && in_array($r['lang'], $allowed, true)) {
                                $_SESSION['lang'] = $r['lang'];
                                $_SESSION['lang_owner'] = $ownerSig;
                                return $r['lang'];
                            }
                        }
                    }
                }
            } else {
                foreach (array(__DIR__ . '/admin_users.json', __DIR__ . '/admins.json') as $admFile) {
                    if (!is_file($admFile)) continue;
                    $admData = @json_decode((string)@file_get_contents($admFile), true);
                    $admList = (is_array($admData) && isset($admData['admins']) && is_array($admData['admins'])) ? $admData['admins'] : (is_array($admData) ? $admData : array());
                    $admId = isset($_SESSION['mikhmon_admin_id']) ? (string)$_SESSION['mikhmon_admin_id'] : '';
                    foreach ($admList as $a) {
                        if (!is_array($a)) continue;
                        $aIdMatch = ($admId !== '' && isset($a['id']) && (string)$a['id'] === $admId);
                        $aUserMatch = (isset($a['username']) && strtolower(trim((string)$a['username'])) === $username);
                        if (($aIdMatch || $aUserMatch) && !empty($a['lang']) && in_array($a['lang'], $allowed, true)) {
                            $_SESSION['lang'] = $a['lang'];
                            $_SESSION['lang_owner'] = $ownerSig;
                            return $a['lang'];
                        }
                    }
                }
            }

            // 4. Per-user browser cookie fallback
            if (!empty($_COOKIE[$cookieKey])) {
                $cLang = strtolower(trim((string)$_COOKIE[$cookieKey]));
                if (in_array($cLang, $allowed, true)) {
                    $_SESSION['lang'] = $cLang;
                    $_SESSION['lang_owner'] = $ownerSig;
                    return $cLang;
                }
            }

            $_SESSION['lang'] = 'en';
            $_SESSION['lang_owner'] = $ownerSig;
            return 'en';
        }

        if (!empty($_SESSION['login_lang']) && in_array($_SESSION['login_lang'], $allowed, true)) {
            return $_SESSION['login_lang'];
        }
        if (!empty($_COOKIE['ngw_login_lang']) && in_array($_COOKIE['ngw_login_lang'], $allowed, true)) {
            return $_COOKIE['ngw_login_lang'];
        }
        return 'en';
    }
}

if (!function_exists('ngwSaveCurrentUserLang')) {
    function ngwSaveCurrentUserLang($newLang) {
        $newLang = (strtolower(trim((string)$newLang)) === 'bn') ? 'bn' : 'en';
        if (!empty($_SESSION['mikhmon'])) {
            $keys = ngwGetCurrentUserPrefKeys();
            $ownerSig = implode('|', $keys);
            $cookieKey = 'ngw_ulang_' . substr(md5($ownerSig), 0, 12);
            if (!headers_sent()) {
                @setcookie($cookieKey, $newLang, time() + 31536000, '/');
            }
            $_COOKIE[$cookieKey] = $newLang;

            $prefs = ngwLoadAllUserPrefs();
            foreach ($keys as $k) {
                if (!isset($prefs[$k]) || !is_array($prefs[$k])) {
                    $prefs[$k] = array();
                }
                $prefs[$k]['lang'] = $newLang;
                $prefs[$k]['updated_at'] = date('Y-m-d H:i:s');
            }
            ngwSaveAllUserPrefs($prefs);

            // Also persist inside resellers.json or admin_users.json
            $role = isset($_SESSION['mikhmon_role']) ? strtolower(trim((string)$_SESSION['mikhmon_role'])) : 'admin';
            $username = strtolower(trim((string)$_SESSION['mikhmon']));
            if ($role === 'reseller') {
                $resFile = __DIR__ . '/resellers.json';
                if (is_file($resFile)) {
                    $resList = @json_decode((string)@file_get_contents($resFile), true);
                    if (is_array($resList)) {
                        $resId = isset($_SESSION['mikhmon_reseller_id']) ? (string)$_SESSION['mikhmon_reseller_id'] : '';
                        $changed = false;
                        foreach ($resList as $idx => $r) {
                            if (!is_array($r)) continue;
                            $rIdMatch = ($resId !== '' && isset($r['id']) && (string)$r['id'] === $resId);
                            $rUserMatch = (isset($r['username']) && strtolower(trim((string)$r['username'])) === $username);
                            if ($rIdMatch || $rUserMatch) {
                                $resList[$idx]['lang'] = $newLang;
                                $changed = true;
                            }
                        }
                        if ($changed) {
                            @file_put_contents($resFile, json_encode($resList, defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : 0), LOCK_EX);
                        }
                    }
                }
            } else {
                $admFile = __DIR__ . '/admin_users.json';
                if (is_file($admFile)) {
                    $admList = @json_decode((string)@file_get_contents($admFile), true);
                    if (is_array($admList)) {
                        $admId = isset($_SESSION['mikhmon_admin_id']) ? (string)$_SESSION['mikhmon_admin_id'] : '';
                        $changed = false;
                        foreach ($admList as $idx => $a) {
                            if (!is_array($a)) continue;
                            $aIdMatch = ($admId !== '' && isset($a['id']) && (string)$a['id'] === $admId);
                            $aUserMatch = (isset($a['username']) && strtolower(trim((string)$a['username'])) === $username);
                            if ($aIdMatch || $aUserMatch) {
                                $admList[$idx]['lang'] = $newLang;
                                $changed = true;
                            }
                        }
                        if ($changed) {
                            @file_put_contents($admFile, json_encode($admList, defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : 0), LOCK_EX);
                        }
                    }
                }
            }
            $_SESSION['lang'] = $newLang;
            $_SESSION['lang_owner'] = $ownerSig;
        } else {
            if (!headers_sent()) {
                @setcookie('ngw_login_lang', $newLang, time() + 31536000, '/');
            }
            $_COOKIE['ngw_login_lang'] = $newLang;
            $_SESSION['login_lang'] = $newLang;
            $_SESSION['lang'] = $newLang;
        }
        $GLOBALS['langid'] = $newLang;
        $GLOBALS['isBn'] = ($newLang === 'bn');
        return $newLang;
    }
}

// Immediately apply ?setlang=en or ?setlang=bn if present in request
if (isset($_GET['setlang']) && $_GET['setlang'] !== '') {
    $reqLang = strtolower(trim((string)$_GET['setlang']));
    if (in_array($reqLang, array('en', 'bn'), true)) {
        ngwSaveCurrentUserLang($reqLang);
    }
}

$_ngwTargetLang = ngwResolveCurrentUserLang();
$_ngwTargetIsBn = ($_ngwTargetLang === 'bn');

// Always ensure English base strings and active language strings are loaded into both local and global scopes
if (is_file(__DIR__ . '/../lang/en.php')) {
    include __DIR__ . '/../lang/en.php';
}
$langid = $_ngwTargetLang;
$isBn = $_ngwTargetIsBn;
if ($_ngwTargetLang !== 'en' && is_file(__DIR__ . '/../lang/' . $_ngwTargetLang . '.php')) {
    include __DIR__ . '/../lang/' . $_ngwTargetLang . '.php';
}
$langid = $_ngwTargetLang;
$isBn = $_ngwTargetIsBn;
$GLOBALS['langid'] = $langid;
$GLOBALS['isBn'] = $isBn;
foreach (get_defined_vars() as $_ngwVarKey => $_ngwVarVal) {
    if ($_ngwVarKey !== 'GLOBALS' && $_ngwVarKey !== '_ngwVarKey' && $_ngwVarKey !== '_ngwVarVal') {
        if ((isset($_ngwVarKey[0]) && $_ngwVarKey[0] === '_') || in_array($_ngwVarKey, array('langid', 'langname', 'language', 'isBn'), true)) {
            $GLOBALS[$_ngwVarKey] = $_ngwVarVal;
        }
    }
}