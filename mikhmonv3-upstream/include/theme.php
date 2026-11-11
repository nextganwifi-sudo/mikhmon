<?php
/*
 * NextGan WiFi Solution — Per-User Persistent Theme Resolver
 */
include_once __DIR__ . '/lang.php';

$theme = "dark";
$themecolor = "#3a4149";

if (!function_exists('ngwGetThemeColorMap')) {
    function ngwGetThemeColorMap() {
        return array(
            'dark'  => '#3a4149',
            'light' => '#008BC9',
            'blue'  => '#008BC9',
            'green' => '#4DBD74',
            'pink'  => '#E83E8C',
        );
    }
}

if (!function_exists('ngwResolveCurrentUserTheme')) {
    function ngwResolveCurrentUserTheme($forceReloadFromStorage = false) {
        $map = ngwGetThemeColorMap();
        if (!empty($_SESSION['mikhmon'])) {
            $keys = ngwGetCurrentUserPrefKeys();
            $ownerSig = implode('|', $keys);
            if (!$forceReloadFromStorage && isset($_SESSION['theme_owner']) && $_SESSION['theme_owner'] === $ownerSig && !empty($_SESSION['theme']) && isset($map[$_SESSION['theme']])) {
                return array('theme' => $_SESSION['theme'], 'themecolor' => $map[$_SESSION['theme']]);
            }
            $prefs = ngwLoadAllUserPrefs();
            foreach ($keys as $k) {
                if (isset($prefs[$k]) && is_array($prefs[$k]) && !empty($prefs[$k]['theme'])) {
                    $t = strtolower(trim((string)$prefs[$k]['theme']));
                    if (isset($map[$t])) {
                        $_SESSION['theme'] = $t;
                        $_SESSION['themecolor'] = $map[$t];
                        $_SESSION['theme_owner'] = $ownerSig;
                        return array('theme' => $t, 'themecolor' => $map[$t]);
                    }
                }
            }
            $_SESSION['theme'] = 'dark';
            $_SESSION['themecolor'] = $map['dark'];
            $_SESSION['theme_owner'] = $ownerSig;
            return array('theme' => 'dark', 'themecolor' => $map['dark']);
        }
        if (!empty($_SESSION['login_theme']) && isset($map[$_SESSION['login_theme']])) {
            return array('theme' => $_SESSION['login_theme'], 'themecolor' => $map[$_SESSION['login_theme']]);
        }
        return array('theme' => 'dark', 'themecolor' => $map['dark']);
    }
}

if (!function_exists('ngwSaveCurrentUserTheme')) {
    function ngwSaveCurrentUserTheme($newTheme) {
        $map = ngwGetThemeColorMap();
        $newTheme = strtolower(trim((string)$newTheme));
        if (!isset($map[$newTheme])) {
            $newTheme = 'dark';
        }
        $color = $map[$newTheme];
        if (!empty($_SESSION['mikhmon'])) {
            $keys = ngwGetCurrentUserPrefKeys();
            $ownerSig = implode('|', $keys);
            $prefs = ngwLoadAllUserPrefs();
            foreach ($keys as $k) {
                if (!isset($prefs[$k]) || !is_array($prefs[$k])) {
                    $prefs[$k] = array();
                }
                $prefs[$k]['theme'] = $newTheme;
                $prefs[$k]['themecolor'] = $color;
                $prefs[$k]['updated_at'] = date('Y-m-d H:i:s');
            }
            ngwSaveAllUserPrefs($prefs);
            $_SESSION['theme'] = $newTheme;
            $_SESSION['themecolor'] = $color;
            $_SESSION['theme_owner'] = $ownerSig;
        } else {
            $_SESSION['login_theme'] = $newTheme;
            $_SESSION['theme'] = $newTheme;
            $_SESSION['themecolor'] = $color;
        }
        return array('theme' => $newTheme, 'themecolor' => $color);
    }
}

if (isset($_GET['set-theme']) && $_GET['set-theme'] !== '') {
    $reqTheme = strtolower(trim((string)$_GET['set-theme']));
    $themeMap = ngwGetThemeColorMap();
    if (isset($themeMap[$reqTheme])) {
        ngwSaveCurrentUserTheme($reqTheme);
    }
}

$_resolvedTheme = ngwResolveCurrentUserTheme();
$theme = $_resolvedTheme['theme'];
$themecolor = $_resolvedTheme['themecolor'];