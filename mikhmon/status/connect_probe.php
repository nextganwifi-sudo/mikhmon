<?php
/*
 * NextGan WiFi Solution — Fast Two-Stage Router Connection Probe
 * Stage 1 (ping): Fast TCP/port reachability & latency measurement (<=3s)
 * Stage 2 (auth): Fast RouterOS API authentication & identity verification
 */
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
ngwSendNoCacheHeaders();
@session_start();
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["mikhmon"])) {
    session_write_close();
    http_response_code(401);
    echo json_encode(array(
        'ok' => false,
        'step' => 'session',
        'error' => 'Session expired. Please log in again.',
        'redirect' => './admin.php?id=login',
    ));
    exit;
}

$session = isset($_GET['session']) ? trim((string)$_GET['session']) : '';
$step = isset($_GET['step']) ? strtolower(trim((string)$_GET['step'])) : 'ping';
$cParam = isset($_GET['c']) ? trim((string)$_GET['c']) : '';
if ($step !== 'ping' && $step !== 'auth') {
    $step = 'ping';
}

include_once __DIR__ . '/../include/reseller_acl.php';
if (function_exists('ngwIsCurrentSessionRevoked') && ngwIsCurrentSessionRevoked()) {
    $_SESSION = array();
    @session_unset();
    @session_destroy();
    ngwClearSessionCookie();
    http_response_code(401);
    echo json_encode(array(
        'ok' => false,
        'step' => 'session',
        'error' => 'Your session was logged out by an Administrator.',
        'redirect' => './admin.php?id=login&reason=force_logout',
    ));
    exit;
}

if ($session === '' || !canAccessRouterSession($session)) {
    $diagDenied = function_exists('ngwDiagnoseRouterConnectFailure')
        ? ngwDiagnoseRouterConnectFailure($session, '', '', '', '', 'access_denied')
        : array('fault' => 'Router Access Denied', 'error' => "Access denied for router '{$session}'.");
    ngwRecordActivity('router_connect_failed', array(
        'router' => $session !== '' ? $session : '(empty)',
        'fault' => $diagDenied['fault'],
        'error' => $diagDenied['error'],
    ));
    session_write_close();
    http_response_code(403);
    echo json_encode(array(
        'ok' => false,
        'step' => $step,
        'error' => 'Access denied for this router session.',
    ));
    exit;
}

include_once __DIR__ . '/../include/lang.php';
$selected_langid = (isset($langid) && $langid !== '') ? $langid : 'en';
$isBn = ($selected_langid === 'bn');
$canEditSettings = hasResellerPermission('manage_router_settings');

// Release session lock during network I/O
session_write_close();

if (is_file(__DIR__ . '/../include/data_recovery.php')) {
    include_once __DIR__ . '/../include/data_recovery.php';
    if (function_exists('ngwInvalidatePhpFileCache')) {
        ngwInvalidatePhpFileCache(__DIR__ . '/../include/config.php');
    }
}

$data = array();
if (is_file(__DIR__ . '/../include/config.php')) {
    include __DIR__ . '/../include/config.php';
}

if (!isset($data[$session]) || !is_array($data[$session])) {
    $diagMissing = function_exists('ngwDiagnoseRouterConnectFailure')
        ? ngwDiagnoseRouterConnectFailure($session, '', '', '', '', 'not_found')
        : array('fault' => 'Router Config Missing', 'error' => "Router configuration '{$session}' not found.");
    ngwRecordActivity('router_connect_failed', array(
        'router' => $session,
        'fault' => $diagMissing['fault'],
        'error' => $diagMissing['error'],
    ));
    http_response_code(404);
    echo json_encode(array(
        'ok' => false,
        'step' => $step,
        'error' => $isBn ? 'রাউটার কনফিগারেশন খুঁজে পাওয়া যায়নি।' : 'Router configuration not found.',
    ));
    exit;
}

$iphostRaw = isset($data[$session][1]) && strpos((string)$data[$session][1], '!') !== false
    ? explode('!', (string)$data[$session][1], 2)[1]
    : '';
$userhost = isset($data[$session][2]) && strpos((string)$data[$session][2], '@|@') !== false
    ? explode('@|@', (string)$data[$session][2], 2)[1]
    : '';
$passwdhost = isset($data[$session][3]) && strpos((string)$data[$session][3], '#|#') !== false
    ? explode('#|#', (string)$data[$session][3], 2)[1]
    : '';
$hotspotname = isset($data[$session][4]) && strpos((string)$data[$session][4], '%') !== false
    ? explode('%', (string)$data[$session][4], 2)[1]
    : $session;

$iphost = trim((string)$iphostRaw);
$routerDisplayLabel = ($hotspotname !== '' && $hotspotname !== $session) ? ($hotspotname . ' (' . $session . ')') : $session;

// Parse target host/port identically to RouterosAPI::connect()
$cleanTarget = $iphost;
$rawHostOnly = $cleanTarget;
if (strpos($rawHostOnly, ':') !== false) {
    $rawHostOnly = explode(':', $rawHostOnly, 2)[0];
}
$isVirtual = (
    $rawHostOnly === '127.0.0.2' ||
    $rawHostOnly === '127.0.0.1' ||
    $rawHostOnly === 'localhost' ||
    stripos($rawHostOnly, 'mock') !== false ||
    stripos($rawHostOnly, 'virtual') !== false ||
    stripos($rawHostOnly, 'simulator') !== false ||
    stripos($iphost, 'demo') !== false
);

if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $cleanTarget, $schemeMatch)) {
    $cleanTarget = substr($cleanTarget, strlen($schemeMatch[0]));
    if (preg_match('/:\d+$/', $cleanTarget, $portMatch) && (int)substr($portMatch[0], 1) === 22) {
        $cleanTarget = substr($cleanTarget, 0, -strlen($portMatch[0]));
    }
}
$probeHost = $cleanTarget;
$probePort = 8728;
if (strpos($cleanTarget, ':') !== false) {
    $hp = explode(':', $cleanTarget, 2);
    $probeHost = trim($hp[0]);
    if ($hp[1] !== '' && is_numeric($hp[1])) {
        $probePort = (int)$hp[1];
    }
}

if ($step === 'ping') {
    if ($iphost === '' || $probeHost === '') {
        $diagEmptyIp = function_exists('ngwDiagnoseRouterConnectFailure')
            ? ngwDiagnoseRouterConnectFailure($session, '', $userhost, '', '', 'ping')
            : array('fault' => 'Missing Host/IP', 'error' => "Host/IP is empty in Router Settings for '{$session}'.", 'user' => $userhost);
        ngwRecordActivity('router_connect_failed', array(
            'router' => $routerDisplayLabel,
            'host' => '',
            'user' => $userhost,
            'fault' => $diagEmptyIp['fault'],
            'error' => $diagEmptyIp['error'],
        ));
        echo json_encode(array(
            'ok' => false,
            'step' => 'ping',
            'host' => '',
            'port' => $probePort,
            'can_edit_settings' => $canEditSettings,
            'error' => $isBn
                ? 'রাউটারের IP অ্যাড্রেস বা হোস্টনেম সেট করা নেই।'
                : 'Router IP address / hostname is empty in configuration.',
        ));
        exit;
    }

    if ($isVirtual) {
        echo json_encode(array(
            'ok' => true,
            'step' => 'ping',
            'host' => $probeHost,
            'port' => $probePort,
            'latency_ms' => 1,
            'is_virtual' => true,
            'router_label' => $routerDisplayLabel,
            'message' => $isBn
                ? 'পিং ঠিক আছে (1 ms) — সব ঠিক আছে, সার্ভার যাচাই করে ঢুকছে...'
                : 'Ping OK (1 ms) — Reachable! Authenticating & entering...',
        ));
        exit;
    }

    $t0 = microtime(true);
    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_client(
        'tcp://' . $probeHost . ':' . $probePort,
        $errno,
        $errstr,
        3,
        STREAM_CLIENT_CONNECT
    );
    $latencyMs = max(1, (int)round((microtime(true) - $t0) * 1000));

    if (!$sock) {
        $errNoInt = (int)$errno;
        $errStrClean = trim((string)$errstr);
        if ($errNoInt === 110 || stripos($errStrClean, 'timed out') !== false) {
            $reason = $isBn
                ? "পিং টাইমআউট ({$probeHost}:{$probePort}) — রাউটার অফলাইন, IP ভুল, অথবা ফায়ারওয়ালে পোর্ট {$probePort} ব্লক করা।"
                : "Ping timed out ({$probeHost}:{$probePort}) — Router offline, wrong IP, or API port {$probePort} is blocked.";
        } elseif ($errNoInt === 111 || stripos($errStrClean, 'refused') !== false) {
            $reason = $isBn
                ? "কানেকশন রিফিউজড ({$probeHost}:{$probePort}) — MikroTik-এর IP > Services > api (পোর্ট {$probePort}) বন্ধ বা পরিবর্তিত।"
                : "Connection refused ({$probeHost}:{$probePort}) — MikroTik IP -> Services -> api port {$probePort} is disabled or changed.";
        } elseif (stripos($errStrClean, 'getaddrinfo') !== false || stripos($errStrClean, 'Name or service not known') !== false) {
            $reason = $isBn
                ? "DNS / হোস্টনেম পাওয়া যায়নি ('{$probeHost}') — সঠিক IP বা ডোমেইন দিন।"
                : "DNS lookup failed for '{$probeHost}' — Invalid router IP or domain name.";
        } elseif (stripos($errStrClean, 'No route to host') !== false || stripos($errStrClean, 'unreachable') !== false) {
            $reason = $isBn
                ? "নেটওয়ার্ক রিচেবল নয় ({$probeHost}:{$probePort}) — রাউটার বা VPN টানেল বিচ্ছিন্ন রয়েছে।"
                : "Network unreachable ({$probeHost}:{$probePort}) — Router or VPN tunnel is disconnected.";
        } else {
            $reason = $isBn
                ? "রাউটারে পৌঁছানো যায়নি ({$probeHost}:{$probePort})" . ($errStrClean !== '' ? " — {$errStrClean}" : '')
                : "Cannot reach {$probeHost}:{$probePort}" . ($errStrClean !== '' ? " — {$errStrClean}" : '');
        }

        @session_start();
        $_SESSION['connect'] = $isBn ? "<b class='text-red'>সংযুক্ত নয় (Not Connected)</b>" : "<b class='text-red'>Not Connected</b>";
        $diagPing = function_exists('ngwDiagnoseRouterConnectFailure')
            ? ngwDiagnoseRouterConnectFailure($session, $iphost, $userhost, '', $errStrClean !== '' ? ($errStrClean . ' ' . $reason) : $reason, 'ping')
            : array('fault' => 'Router Unreachable', 'error' => $reason, 'host' => $iphost, 'user' => $userhost);
        ngwRecordActivity('router_connect_failed', array(
            'router' => $routerDisplayLabel,
            'host' => $diagPing['host'],
            'user' => isset($diagPing['user']) ? $diagPing['user'] : $userhost,
            'fault' => $diagPing['fault'],
            'error' => $diagPing['error'],
        ));
        session_write_close();

        echo json_encode(array(
            'ok' => false,
            'step' => 'ping',
            'host' => $probeHost,
            'port' => $probePort,
            'latency_ms' => $latencyMs,
            'can_edit_settings' => $canEditSettings,
            'error' => $reason,
        ));
        exit;
    }

    @fclose($sock);
    echo json_encode(array(
        'ok' => true,
        'step' => 'ping',
        'host' => $probeHost,
        'port' => $probePort,
        'latency_ms' => $latencyMs,
        'is_virtual' => false,
        'router_label' => $routerDisplayLabel,
        'message' => $isBn
            ? "পিং ঠিক আছে ({$latencyMs} ms) — সব ঠিক আছে, সার্ভার যাচাই করে ঢুকছে..."
            : "Ping OK ({$latencyMs} ms) — Reachable! Authenticating & entering...",
    ));
    exit;
}

// Stage 2: Fast RouterOS API authentication + identity fetch
include_once __DIR__ . '/../lib/routeros_api.class.php';
include_once __DIR__ . '/../include/ros_version.php';

$API = new RouterosAPI();
$API->debug = false;
$API->timeout = 8;
$API->attempts = 1;

$tAuth0 = microtime(true);
$isConnected = $API->connect($iphost, $userhost, decrypt($passwdhost));
$authMs = max(1, (int)round((microtime(true) - $tAuth0) * 1000));

$identity = $hotspotname;
if ($isConnected) {
    $idRes = $API->comm('/system/identity/print');
    if (is_array($idRes) && isset($idRes[0]['name']) && trim((string)$idRes[0]['name']) !== '') {
        $identity = trim((string)$idRes[0]['name']);
    }
    $API->disconnect();
}

@session_start();
if ($isConnected) {
    $_SESSION['connect'] = $isBn ? "<b class='text-green'>সংযুক্ত (Connected)</b>" : "<b class='text-green'>Connected</b>";
    $_SESSION[$session . '_identity'] = $identity;
    ngwRecordActivity('router_connected', array(
        'router' => $routerDisplayLabel,
        'host' => $iphost,
    ));
    $targetAfterConnect = ($cParam === 'settings')
        ? ('./?session=' . rawurlencode($session))
        : getResellerDefaultFallbackUrl($session);
    session_write_close();

    echo json_encode(array(
        'ok' => true,
        'step' => 'auth',
        'host' => $probeHost,
        'port' => $probePort,
        'auth_ms' => $authMs,
        'identity' => $identity,
        'redirect' => $targetAfterConnect,
        'message' => $isBn
            ? 'সবকিছু ঠিক আছে! রাউটার ড্যাশবোর্ডে প্রবেশ করছে...'
            : 'All checks passed! Opening router dashboard...',
    ));
    exit;
}

$_SESSION['connect'] = $isBn ? "<b class='text-red'>সংযুক্ত নয় (Not Connected)</b>" : "<b class='text-red'>Not Connected</b>";
$rawErr = !empty($API->last_error) ? (string)$API->last_error : ('RouterOS API login failed for ' . $iphost);
$diagAuth = function_exists('ngwDiagnoseRouterConnectFailure')
    ? ngwDiagnoseRouterConnectFailure($session, $iphost, $userhost, decrypt($passwdhost), $rawErr, 'auth')
    : array('fault' => 'Router API Login Failed', 'error' => $rawErr, 'host' => $iphost, 'user' => $userhost);
$userFacingErr = $rawErr;
if ($isBn && (stripos($rawErr, 'login') !== false || stripos($rawErr, 'password') !== false)) {
    $userFacingErr = "MikroTik API লগইন ব্যর্থ ({$probeHost}:{$probePort}) — ইউজারনেম ('{$userhost}') বা পাসওয়ার্ড ভুল অথবা ইউজারের API পারমিশন নেই।";
}
ngwRecordActivity('router_connect_failed', array(
    'router' => $routerDisplayLabel,
    'host' => $diagAuth['host'],
    'user' => isset($diagAuth['user']) ? $diagAuth['user'] : $userhost,
    'fault' => $diagAuth['fault'],
    'error' => $diagAuth['error'],
));
session_write_close();

echo json_encode(array(
    'ok' => false,
    'step' => 'auth',
    'host' => $probeHost,
    'port' => $probePort,
    'can_edit_settings' => $canEditSettings,
    'error' => $userFacingErr,
));
exit;
