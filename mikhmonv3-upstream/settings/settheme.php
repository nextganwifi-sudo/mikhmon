<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *  NextGan WiFi Solution — Per-User Theme Switcher
 */
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
error_reporting(0);

include_once(__DIR__ . '/../include/theme.php');

$reqUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
$cleanUrl = preg_replace('/([?&])set-theme=[^&]*(&|$)/', '$1', $reqUri);
$cleanUrl = str_replace('?&', '?', $cleanUrl);
$cleanUrl = preg_replace('/&&+/', '&', $cleanUrl);
$cleanUrl = rtrim($cleanUrl, '?&');

if ($cleanUrl === '' || substr($cleanUrl, -9) === 'index.php' || substr($cleanUrl, -9) === 'admin.php' || substr($cleanUrl, -12) === 'settheme.php' || substr($cleanUrl, -1) === '/') {
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $refClean = preg_replace('/([?&])set-theme=[^&]*(&|$)/', '$1', (string)$_SERVER['HTTP_REFERER']);
        $refClean = str_replace('?&', '?', $refClean);
        $refClean = preg_replace('/&&+/', '&', $refClean);
        $refClean = rtrim($refClean, '?&');
        if ($refClean !== '') {
            $cleanUrl = $refClean;
        }
    }
}
if (empty($cleanUrl)) {
    $cleanUrl = './admin.php?id=sessions';
}
$url2 = $cleanUrl;

$gettheme = isset($_GET['set-theme']) ? strtolower(trim((string)$_GET['set-theme'])) : '';
$mtheme = array('dark', 'light', 'blue', 'green', 'pink');

if (!empty($gettheme)) {
    if (in_array($gettheme, $mtheme, true)) {
        ngwSaveCurrentUserTheme($gettheme);
    }
    session_write_close();
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    if (!$isAjax && !headers_sent()) {
        header('Location: ' . $url2);
        exit;
    }
    echo "<script>window.location.replace(" . json_encode($url2) . ");</script>";
    exit;
}
?>