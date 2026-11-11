<?php
/*
 * NextGan WiFi Solution — Per-User Persistent Language Resolver
 * Ensures each Admin and each Reseller has their own independent language setting ('en' or 'bn')
 * stored persistently across logins without affecting other users.
 */

if (!function_exists('ngwGetUserPrefsFilePath')) {
    function ngwGetUserPrefsFilePath() {
        return __DIR__ . '/user_prefs.json';
    }
}

if (!function_exists('ngwLoadAllUserPrefs')) {
    function ngwLoadAllUserPrefs() {
        $file = ngwGetUserPrefsFilePath();
        if (!is_file($file)) {
            return array();
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return array();
        }
        $decoded = @json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }
}

if (!function_exists('ngwSaveAllUserPrefs')) {
    function ngwSaveAllUserPrefs($prefs) {
        if (!is_array($prefs)) {
            $prefs = array();
        }
        $file = ngwGetUserPrefsFilePath();
        $json = json_encode($prefs, defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : 0);
        $tmp = $file . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $file);
            return true;
        }
        return @file_put_contents($file, $json) !== false;
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
    function ngwResolveCurrentUserLang() {
        $allowed = array('en', 'bn');
        if (!empty($_SESSION['mikhmon'])) {
            $keys = ngwGetCurrentUserPrefKeys();
            $ownerSig = implode('|', $keys);
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
            // Fallback check in resellers.json / admins.json if present
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
                $admFile = __DIR__ . '/admins.json';
                if (is_file($admFile)) {
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
            if (isset($_SESSION['lang_owner']) && $_SESSION['lang_owner'] === $ownerSig && !empty($_SESSION['lang']) && in_array($_SESSION['lang'], $allowed, true)) {
                return $_SESSION['lang'];
            }
            $_SESSION['lang'] = 'en';
            $_SESSION['lang_owner'] = $ownerSig;
            return 'en';
        }

        if (!empty($_SESSION['login_lang']) && in_array($_SESSION['login_lang'], $allowed, true)) {
            return $_SESSION['login_lang'];
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
            $prefs = ngwLoadAllUserPrefs();
            foreach ($keys as $k) {
                if (!isset($prefs[$k]) || !is_array($prefs[$k])) {
                    $prefs[$k] = array();
                }
                $prefs[$k]['lang'] = $newLang;
                $prefs[$k]['updated_at'] = date('Y-m-d H:i:s');
            }
            ngwSaveAllUserPrefs($prefs);

            // Also persist inside resellers.json if user is a reseller
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
            }
            $_SESSION['lang'] = $newLang;
            $_SESSION['lang_owner'] = $ownerSig;
        } else {
            $_SESSION['login_lang'] = $newLang;
            $_SESSION['lang'] = $newLang;
        }
        $GLOBALS['langid'] = $newLang;
        $GLOBALS['isBn'] = ($newLang === 'bn');
        return $newLang;
    }
}

$langid = ngwResolveCurrentUserLang();
$isBn = ($langid === 'bn');
$GLOBALS['langid'] = $langid;
$GLOBALS['isBn'] = $isBn;

// Always ensure English base strings and active language strings are loaded into current scope
if (is_file(__DIR__ . '/../lang/en.php')) {
    include __DIR__ . '/../lang/en.php';
}
$langid = $GLOBALS['langid'];
$isBn = $GLOBALS['isBn'];
if ($langid !== 'en' && is_file(__DIR__ . '/../lang/' . $langid . '.php')) {
    include __DIR__ . '/../lang/' . $langid . '.php';
}
$langid = $GLOBALS['langid'];
$isBn = $GLOBALS['isBn'];