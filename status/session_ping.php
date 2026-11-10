<?php
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
session_start();
error_reporting(0);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['mikhmon'])) {
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

$token = ngwEnsureSessionToken();
$all = ngwLoadActiveSessions(true);
if (isset($all[$token]) && is_array($all[$token])) {
    if ($tz !== '' && !empty($_SESSION['ngw_client_area'])) {
        $all[$token]['area'] = (string)$_SESSION['ngw_client_area'];
    }
    if ($pageLabel !== '') {
        $all[$token]['current_page'] = substr($pageLabel, 0, 100);
    }
    $all[$token]['last_active'] = date('Y-m-d H:i:s');
    $all[$token]['last_active_ts'] = time();
    ngwSaveActiveSessions($all);
} else {
    ngwRegisterOrTouchActiveSession(array(
        'timezone' => $tz,
        'page_label' => $pageLabel,
        'keep_page' => ($pageLabel === ''),
    ));
}

session_write_close();
echo json_encode(array('ok' => true, 'revoked' => false));
