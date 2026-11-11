<?php
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
session_start();
error_reporting(0);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['mikhmon'])) {
    session_write_close();
    echo json_encode(array('ok' => false, 'authenticated' => false));
    exit;
}

require_once __DIR__ . '/../include/reseller_acl.php';

if (ngwIsCurrentSessionRevoked()) {
    $_SESSION = array();
    @session_unset();
    @session_destroy();
    echo json_encode(array('ok' => false, 'revoked' => true));
    exit;
}

$tz = isset($_REQUEST['tz']) ? trim((string)$_REQUEST['tz']) : '';
$pageLabel = isset($_REQUEST['page']) ? trim((string)$_REQUEST['page']) : '';

if ($tz !== '') {
    $_SESSION['ngw_client_tz'] = substr($tz, 0, 64);
    $_SESSION['ngw_client_area'] = ngwFormatAreaFromTimezone($_SESSION['ngw_client_tz']);
}
$clientArea = !empty($_SESSION['ngw_client_area']) ? (string)$_SESSION['ngw_client_area'] : '';
$token = ngwEnsureSessionToken();

// Release PHP session lock immediately before file I/O
session_write_close();

$all = ngwLoadActiveSessions(true);
if (isset($all[$token]) && is_array($all[$token])) {
    $now = time();
    $prevTs = isset($all[$token]['last_active_ts']) ? (int)$all[$token]['last_active_ts'] : 0;
    $prevPage = isset($all[$token]['current_page']) ? (string)$all[$token]['current_page'] : '';
    $newPage = ($pageLabel !== '') ? substr($pageLabel, 0, 100) : $prevPage;
    $prevArea = isset($all[$token]['area']) ? (string)$all[$token]['area'] : '';
    $newArea = ($tz !== '' && $clientArea !== '') ? $clientArea : $prevArea;

    // Only write to disk if page/area changed or >= 12 seconds elapsed since last write
    if ($newPage !== $prevPage || $newArea !== $prevArea || ($now - $prevTs) >= 12) {
        $all[$token]['area'] = $newArea;
        $all[$token]['current_page'] = $newPage;
        $all[$token]['last_active'] = date('Y-m-d H:i:s', $now);
        $all[$token]['last_active_ts'] = $now;
        ngwSaveActiveSessions($all);
    }
} else {
    ngwRegisterOrTouchActiveSession(array(
        'timezone' => $tz,
        'page_label' => $pageLabel,
        'keep_page' => ($pageLabel === ''),
    ));
}

echo json_encode(array('ok' => true, 'revoked' => false));
