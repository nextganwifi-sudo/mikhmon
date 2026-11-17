<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *  NextGan WiFi Solution — Per-User Language Switcher
 */
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
error_reporting(0);

include_once(__DIR__ . '/../lang/isocodelang.php');
include_once(__DIR__ . '/../include/lang.php');

$reqUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
$cleanUrl = preg_replace('/([?&])setlang=[^&]*(&|$)/', '$1', $reqUri);
$cleanUrl = str_replace('?&', '?', $cleanUrl);
$cleanUrl = preg_replace('/&&+/', '&', $cleanUrl);
$cleanUrl = rtrim($cleanUrl, '?&');

// If the clicked URL lost its query string (e.g. bare ?setlang=bn), fall back to same-origin HTTP_REFERER
if ($cleanUrl === '' || substr($cleanUrl, -9) === 'index.php' || substr($cleanUrl, -9) === 'admin.php' || substr($cleanUrl, -11) === 'setlang.php' || substr($cleanUrl, -1) === '/') {
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $refParts = @parse_url((string)$_SERVER['HTTP_REFERER']);
        $reqHost = isset($_SERVER['HTTP_HOST']) ? strtolower(explode(':', (string)$_SERVER['HTTP_HOST'])[0]) : '';
        $refHost = isset($refParts['host']) ? strtolower((string)$refParts['host']) : '';
        if ($refHost === '' || ($reqHost !== '' && $refHost === $reqHost)) {
            $refClean = (isset($refParts['path']) ? $refParts['path'] : '/') . (isset($refParts['query']) && $refParts['query'] !== '' ? ('?' . $refParts['query']) : '');
            $refClean = preg_replace('/([?&])setlang=[^&]*(&|$)/', '$1', $refClean);
            $refClean = str_replace('?&', '?', $refClean);
            $refClean = preg_replace('/&&+/', '&', $refClean);
            $refClean = rtrim($refClean, '?&');
            if ($refClean !== '') {
                $cleanUrl = $refClean;
            }
        }
    }
}
if (empty($cleanUrl) || preg_match('#^(?:https?:)?//#i', $cleanUrl)) {
    $cleanUrl = './admin.php?id=sessions';
}
$url2 = $cleanUrl;

$getlang = isset($_GET['setlang']) ? strtolower(trim((string)$_GET['setlang'])) : '';

if (!empty($getlang)) {
    if (!empty($isocodelang[$getlang]) || in_array($getlang, array('en', 'bn'), true)) {
        ngwSaveCurrentUserLang($getlang);
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