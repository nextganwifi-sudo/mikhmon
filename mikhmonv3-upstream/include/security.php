<?php
/**
 * NextGan WiFi Solution shared security helpers.
 * Keep this file independent of RouterOS voucher/profile generation.
 */

function ngwConfigureSecureSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $params = session_get_cookie_params();
    session_set_cookie_params(array(
        'lifetime' => isset($params['lifetime']) ? (int)$params['lifetime'] : 0,
        'path' => isset($params['path']) && $params['path'] !== '' ? $params['path'] : '/',
        'domain' => isset($params['domain']) ? $params['domain'] : '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
}

function ngwCsrfToken($sessionKey = 'ngw_csrf_token') {
    if (empty($_SESSION[$sessionKey]) || !is_string($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$sessionKey];
}

function ngwValidateCsrf($postedToken, $sessionKey = 'ngw_csrf_token') {
    return isset($_SESSION[$sessionKey]) &&
        is_string($_SESSION[$sessionKey]) &&
        is_string($postedToken) &&
        $postedToken !== '' &&
        hash_equals($_SESSION[$sessionKey], $postedToken);
}

/**
 * File-backed login throttle. Keys are one-way hashes; usernames/IPs are not
 * written to disk. Ten failed attempts per IP+username are allowed per 15 min.
 */
function ngwLoginThrottlePath($username) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';
    // IP-wide bucket prevents attackers bypassing the limit by cycling usernames.
    $key = hash('sha256', $ip);
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ngw-login-' . $key . '.json';
}

function ngwLoginIsThrottled($username) {
    $path = ngwLoginThrottlePath($username);
    if (!is_file($path)) {
        return false;
    }
    $raw = @file_get_contents($path);
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || !isset($data['window_start'], $data['failures'])) {
        return false;
    }
    if (time() - (int)$data['window_start'] >= 900) {
        return false;
    }
    return (int)$data['failures'] >= 10;
}

function ngwRecordLoginFailure($username) {
    $path = ngwLoginThrottlePath($username);
    $fp = @fopen($path, 'c+');
    if (!$fp) {
        // Fail closed if throttle storage is unavailable.
        return false;
    }
    @chmod($path, 0600);
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    rewind($fp);
    $raw = stream_get_contents($fp);
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || !isset($data['window_start'], $data['failures']) ||
        time() - (int)$data['window_start'] >= 900) {
        $data = array('window_start' => time(), 'failures' => 0);
    }
    $data['failures'] = (int)$data['failures'] + 1;
    rewind($fp);
    ftruncate($fp, 0);
    $written = fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $written !== false;
}

function ngwClearLoginFailures($username) {
    $path = ngwLoginThrottlePath($username);
    if (is_file($path)) {
        @unlink($path);
    }
}
