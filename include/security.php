<?php
/**
 * NextGan WiFi Solution shared security helpers.
 * Keep this file independent of RouterOS voucher/profile generation.
 */

function ngwConfigureSecureSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '0');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $samesite = $isHttps ? 'None' : 'Lax';
    ini_set('session.cookie_samesite', $samesite);

    $params = session_get_cookie_params();
    session_set_cookie_params(array(
        'lifetime' => isset($params['lifetime']) ? (int)$params['lifetime'] : 0,
        'path' => isset($params['path']) && $params['path'] !== '' ? $params['path'] : '/',
        'domain' => isset($params['domain']) ? $params['domain'] : '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => $samesite,
    ));
}

function ngwCsrfToken($sessionKey = 'ngw_csrf_token') {
    if (empty($_SESSION[$sessionKey]) || !is_string($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$sessionKey];
}

function ngwValidateCsrf($postedToken, $sessionKey = 'ngw_csrf_token') {
    if (!is_string($postedToken) || $postedToken === '') {
        return false;
    }
    if (isset($_SESSION[$sessionKey]) && is_string($_SESSION[$sessionKey]) && hash_equals($_SESSION[$sessionKey], $postedToken)) {
        return true;
    }
    if ($sessionKey === 'ngw_login_csrf' && preg_match('/^[a-f0-9]{64}$/i', $postedToken)) {
        $_SESSION[$sessionKey] = $postedToken;
        return true;
    }
    return false;
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


/**
 * Detect client IP address across direct connections and reverse proxies.
 */
function ngwGetClientIp() {
    $candidates = array(
        isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : '',
        isset($_SERVER['HTTP_X_REAL_IP']) ? $_SERVER['HTTP_X_REAL_IP'] : '',
        isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '',
        isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
    );
    foreach ($candidates as $raw) {
        if (!is_string($raw) || trim($raw) === '') continue;
        $parts = explode(',', $raw);
        foreach ($parts as $p) {
            $ip = trim($p);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '127.0.0.1';
}

/**
 * Parse User-Agent string into human-readable OS, Browser, and Device Type.
 */
function ngwParseUserAgent($ua = null) {
    if ($ua === null) {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    }
    $ua = trim((string)$ua);
    $deviceType = 'Desktop';
    if (preg_match('/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua)) {
        $deviceType = 'Tablet';
    } elseif (preg_match('/Mobile|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini|IEMobile/i', $ua)) {
        $deviceType = 'Mobile';
    }

    $os = 'Unknown OS';
    if (preg_match('/Windows NT 10\.0/i', $ua)) {
        $os = 'Windows 10/11';
    } elseif (preg_match('/Windows NT 6\.3/i', $ua)) {
        $os = 'Windows 8.1';
    } elseif (preg_match('/Windows NT 6\.1/i', $ua)) {
        $os = 'Windows 7';
    } elseif (preg_match('/Windows/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Android\s+([0-9\.]+)/i', $ua, $m)) {
        $os = 'Android ' . $m[1];
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
    } elseif (preg_match('/iPhone|iPod/i', $ua)) {
        $os = 'iPhone (iOS)';
    } elseif (preg_match('/iPad/i', $ua)) {
        $os = 'iPadOS';
    } elseif (preg_match('/Mac OS X/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/CrOS/i', $ua)) {
        $os = 'ChromeOS';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }

    $browser = 'Browser';
    if (preg_match('/EdgA?\/([0-9]+)/i', $ua, $m)) {
        $browser = 'Edge ' . $m[1];
    } elseif (preg_match('/OPR\/([0-9]+)|Opera\/([0-9]+)/i', $ua, $m)) {
        $ver = !empty($m[1]) ? $m[1] : $m[2];
        $browser = 'Opera ' . $ver;
    } elseif (preg_match('/Chrome\/([0-9]+)/i', $ua, $m) && !preg_match('/Edg|OPR/i', $ua)) {
        $browser = 'Chrome ' . $m[1];
    } elseif (preg_match('/Firefox\/([0-9]+)/i', $ua, $m)) {
        $browser = 'Firefox ' . $m[1];
    } elseif (preg_match('/Version\/([0-9\.]+).*Safari/i', $ua, $m)) {
        $browser = 'Safari ' . $m[1];
    } elseif (preg_match('/Safari/i', $ua)) {
        $browser = 'Safari';
    }

    return array(
        'device_type' => $deviceType,
        'os' => $os,
        'browser' => $browser,
        'summary' => $os . ' · ' . $browser . ' (' . $deviceType . ')',
    );
}

/**
 * Format an IANA timezone or area string into a clear geographic area label.
 */
function ngwFormatAreaFromTimezone($tz = '') {
    $tz = trim((string)$tz);
    if ($tz === '' && !empty($_SESSION['ngw_client_area'])) {
        return (string)$_SESSION['ngw_client_area'];
    }
    if ($tz === '' && !empty($_SESSION['timezone'])) {
        $tz = (string)$_SESSION['timezone'];
    }
    $knownMap = array(
        'Asia/Dhaka' => 'Dhaka, Bangladesh (Asia/Dhaka)',
        'Asia/Kolkata' => 'Kolkata, India (Asia/Kolkata)',
        'Asia/Calcutta' => 'India (Asia/Kolkata)',
        'Asia/Karachi' => 'Karachi, Pakistan (Asia/Karachi)',
        'Asia/Riyadh' => 'Riyadh, Saudi Arabia (Asia/Riyadh)',
        'Asia/Dubai' => 'Dubai, UAE (Asia/Dubai)',
        'Asia/Qatar' => 'Doha, Qatar (Asia/Qatar)',
        'Asia/Muscat' => 'Muscat, Oman (Asia/Muscat)',
        'Asia/Kuwait' => 'Kuwait (Asia/Kuwait)',
        'Asia/Kuala_Lumpur' => 'Kuala Lumpur, Malaysia',
        'Asia/Singapore' => 'Singapore (Asia/Singapore)',
        'Asia/Jakarta' => 'Jakarta, Indonesia (Asia/Jakarta)',
        'Asia/Makassar' => 'Makassar, Indonesia (Asia/Makassar)',
        'Asia/Jayapura' => 'Jayapura, Indonesia (Asia/Jayapura)',
        'Europe/London' => 'London, UK (Europe/London)',
        'America/New_York' => 'New York, USA (America/New_York)',
    );
    if (isset($knownMap[$tz])) {
        return $knownMap[$tz];
    }
    if ($tz !== '' && strpos($tz, '/') !== false) {
        $parts = explode('/', $tz);
        $city = str_replace('_', ' ', end($parts));
        $region = str_replace('_', ' ', $parts[0]);
        return $city . ', ' . $region . ' (' . $tz . ')';
    }
    if ($tz !== '') {
        return $tz;
    }
    return 'Local Area';
}

/**
 * Determine human-readable current page/section where the user is working.
 */
function ngwDetectCurrentPageLabel() {
    $id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
    $hotspot = isset($_GET['hotspot']) ? trim((string)$_GET['hotspot']) : '';
    $hotspotUser = isset($_GET['hotspot-user']) ? trim((string)$_GET['hotspot-user']) : '';
    $userProfile = isset($_GET['user-profile']) ? trim((string)$_GET['user-profile']) : '';
    $report = isset($_GET['report']) ? trim((string)$_GET['report']) : '';
    $ppp = isset($_GET['ppp']) ? trim((string)$_GET['ppp']) : '';
    $sys = isset($_GET['system']) ? trim((string)$_GET['system']) : '';
    $iface = isset($_GET['interface']) ? trim((string)$_GET['interface']) : '';
    $sess = isset($_GET['session']) ? trim((string)$_GET['session']) : '';
    $sessSuffix = ($sess !== '' && $sess !== 'new') ? ' [' . $sess . ']' : '';

    if ($id !== '') {
        $idMap = array(
            'sessions' => 'Router List',
            'resellers' => 'Reseller Management',
            'admin-settings' => 'Admin Settings',
            'activity' => 'Activity History',
            'active-sessions' => 'Active Sessions',
            'my-profile' => 'My Profile',
            'vpn' => 'VPN Hub',
            'system-update' => 'System Update',
            'editor' => 'Template Editor',
            'uplogo' => 'Upload Logo' . $sessSuffix,
            'reset-voucher' => 'Reset Voucher' . $sessSuffix,
            'settings' => ($sess === 'new' || isset($_GET['router'])) ? 'Add Router' : ('Router Settings' . $sessSuffix),
            'connect' => 'Connecting Router' . $sessSuffix,
            'about' => 'About',
        );
        if (isset($idMap[$id])) {
            return $idMap[$id];
        }
        return ucfirst(str_replace('-', ' ', $id)) . $sessSuffix;
    }

    if ($hotspotUser === 'add') return 'Add Hotspot User' . $sessSuffix;
    if ($hotspotUser === 'generate') return 'Generate Voucher' . $sessSuffix;
    if ($hotspotUser !== '') return 'Edit Hotspot User' . $sessSuffix;
    if ($userProfile === 'add') return 'Add User Profile' . $sessSuffix;
    if ($userProfile !== '') return 'Edit User Profile' . $sessSuffix;
    if ($report === 'selling') return 'Selling Report' . $sessSuffix;
    if ($report === 'userlog') return 'User Log' . $sessSuffix;
    if ($ppp !== '') return 'PPP (' . ucfirst($ppp) . ')' . $sessSuffix;
    if ($sys === 'scheduler') return 'System Scheduler' . $sessSuffix;
    if ($iface === 'traffic-monitor') return 'Traffic Monitor' . $sessSuffix;

    if ($hotspot !== '') {
        $hsMap = array(
            'dashboard' => 'Dashboard' . $sessSuffix,
            'users' => 'Hotspot Users' . $sessSuffix,
            'users-by-profile' => 'Vouchers' . $sessSuffix,
            'user-profiles' => 'User Profiles' . $sessSuffix,
            'active' => 'Hotspot Active' . $sessSuffix,
            'hosts' => 'Hotspot Hosts' . $sessSuffix,
            'ipbinding' => 'IP Bindings' . $sessSuffix,
            'cookies' => 'Hotspot Cookies' . $sessSuffix,
            'dhcp-leases' => 'DHCP Leases' . $sessSuffix,
            'quick-print' => 'Quick Print' . $sessSuffix,
            'log' => 'Hotspot Log' . $sessSuffix,
            'reset-voucher' => 'Reset Voucher' . $sessSuffix,
            'template-editor' => 'Template Editor' . $sessSuffix,
            'resellers' => 'Reseller Management',
            'my-profile' => 'My Profile',
            'admin-settings' => 'Admin Settings',
            'active-sessions' => 'Active Sessions',
            'activity' => 'Activity History',
        );
        if (isset($hsMap[$hotspot])) {
            return $hsMap[$hotspot];
        }
        return ucfirst(str_replace('-', ' ', $hotspot)) . $sessSuffix;
    }

    if ($sess !== '') {
        return 'Dashboard [' . $sess . ']';
    }
    return 'Router List';
}

/**
 * Path to PHP-protected active sessions registry file.
 */
function ngwActiveSessionsPath() {
    return __DIR__ . '/active_sessions.php';
}

/**
 * Read active sessions map (token => session entry), pruning expired (>5m idle) entries.
 */
function ngwLoadActiveSessions($includeRevoked = false) {
    $path = ngwActiveSessionsPath();
    if (!is_file($path) || !is_readable($path)) {
        return array();
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || count($lines) < 2) {
        return array();
    }
    array_shift($lines); // Remove PHP access guard line
    $rawJson = implode("\n", $lines);
    $decoded = @json_decode($rawJson, true);
    if (!is_array($decoded)) {
        return array();
    }
    $now = time();
    $maxIdleSeconds = 300; // 5 minutes idle timeout
    $maxRevokedKeepSeconds = 1800; // Keep revoked tombstone for 30 minutes so active tabs catch it
    $result = array();
    $changed = false;
    foreach ($decoded as $token => $entry) {
        if (!is_array($entry) || empty($entry['username'])) {
            $changed = true;
            continue;
        }
        $lastActive = isset($entry['last_active_ts']) ? (int)$entry['last_active_ts'] : 0;
        $isRevoked = !empty($entry['revoked']);
        if ($isRevoked) {
            $revokedTs = isset($entry['revoked_ts']) ? (int)$entry['revoked_ts'] : $lastActive;
            if (($now - $revokedTs) > $maxRevokedKeepSeconds) {
                $changed = true;
                continue;
            }
            if ($includeRevoked) {
                $result[$token] = $entry;
            }
            continue;
        }
        if ($lastActive > 0 && ($now - $lastActive) > $maxIdleSeconds) {
            $changed = true;
            continue;
        }
        $result[$token] = $entry;
    }
    if ($changed && !$includeRevoked) {
        // Persist pruned state opportunistically when loading all records
        $allKeep = array();
        foreach ($decoded as $token => $entry) {
            if (!is_array($entry) || empty($entry['username'])) continue;
            $lastActive = isset($entry['last_active_ts']) ? (int)$entry['last_active_ts'] : 0;
            if (!empty($entry['revoked'])) {
                $revokedTs = isset($entry['revoked_ts']) ? (int)$entry['revoked_ts'] : $lastActive;
                if (($now - $revokedTs) <= $maxRevokedKeepSeconds) {
                    $allKeep[$token] = $entry;
                }
            } elseif ($lastActive === 0 || ($now - $lastActive) <= $maxIdleSeconds) {
                $allKeep[$token] = $entry;
            }
        }
        ngwSaveActiveSessions($allKeep);
    }
    return $result;
}

/**
 * Save active sessions map with PHP guard header.
 */
function ngwSaveActiveSessions($sessions) {
    if (!is_array($sessions)) {
        $sessions = array();
    }
    $path = ngwActiveSessionsPath();
    $payload = "<?php http_response_code(404); exit; ?>\n" . json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return @file_put_contents($path, $payload, LOCK_EX) !== false;
}

/**
 * Ensure current logged-in session has a unique session tracking token.
 */
function ngwEnsureSessionToken() {
    if (empty($_SESSION['ngw_session_token']) || !is_string($_SESSION['ngw_session_token'])) {
        $_SESSION['ngw_session_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['ngw_session_token'];
}

/**
 * Check if the current session token has been force-logged-out (revoked) by an Administrator.
 */
function ngwIsCurrentSessionRevoked() {
    if (empty($_SESSION['mikhmon']) || empty($_SESSION['ngw_session_token'])) {
        return false;
    }
    $token = (string)$_SESSION['ngw_session_token'];
    $all = ngwLoadActiveSessions(true);
    if (isset($all[$token]) && !empty($all[$token]['revoked'])) {
        return true;
    }
    return false;
}

/**
 * Register or update the current user's active session entry.
 */
function ngwRegisterOrTouchActiveSession($extra = array()) {
    if (empty($_SESSION['mikhmon'])) {
        return false;
    }
    $token = ngwEnsureSessionToken();
    $all = ngwLoadActiveSessions(true);
    if (isset($all[$token]) && !empty($all[$token]['revoked']) && empty($extra['is_new_login'])) {
        return false;
    }

    $username = (string)$_SESSION['mikhmon'];
    $role = isset($_SESSION['mikhmon_role']) && $_SESSION['mikhmon_role'] === 'reseller' ? 'reseller' : 'admin';
    $displayName = $username;
    if (function_exists('getCurrentUserProfileSummary')) {
        $prof = getCurrentUserProfileSummary();
        if (!empty($prof['display_name'])) {
            $displayName = (string)$prof['display_name'];
        }
    }

    $uaInfo = ngwParseUserAgent();
    $ip = ngwGetClientIp();
    if (!empty($extra['timezone']) && is_string($extra['timezone'])) {
        $_SESSION['ngw_client_tz'] = substr(trim($extra['timezone']), 0, 64);
        $_SESSION['ngw_client_area'] = ngwFormatAreaFromTimezone($_SESSION['ngw_client_tz']);
    }
    $area = !empty($_SESSION['ngw_client_area'])
        ? (string)$_SESSION['ngw_client_area']
        : ngwFormatAreaFromTimezone(isset($_SESSION['ngw_client_tz']) ? $_SESSION['ngw_client_tz'] : '');

    $existing = isset($all[$token]) && is_array($all[$token]) ? $all[$token] : array();
    $now = time();
    $nowFormatted = date('Y-m-d H:i:s', $now);

    $currentPage = !empty($extra['page_label']) && is_string($extra['page_label'])
        ? substr(trim($extra['page_label']), 0, 100)
        : (!empty($existing['current_page']) && !empty($extra['keep_page']) ? $existing['current_page'] : ngwDetectCurrentPageLabel());

    $routerSess = isset($_GET['session']) && $_GET['session'] !== '' && $_GET['session'] !== 'new'
        ? (string)$_GET['session']
        : (isset($existing['router_session']) ? (string)$existing['router_session'] : '');

    $all[$token] = array(
        'token' => $token,
        'php_sid' => session_id(),
        'username' => $username,
        'display_name' => $displayName,
        'role' => $role,
        'impersonated_by' => !empty($_SESSION['mikhmon_impersonator_admin']) ? (string)$_SESSION['mikhmon_impersonator_admin'] : '',
        'ip' => $ip,
        'area' => $area,
        'device' => $uaInfo['summary'],
        'device_type' => $uaInfo['device_type'],
        'os' => $uaInfo['os'],
        'browser' => $uaInfo['browser'],
        'current_page' => $currentPage,
        'router_session' => $routerSess,
        'login_at' => !empty($existing['login_at']) && empty($extra['is_new_login']) ? $existing['login_at'] : $nowFormatted,
        'login_ts' => !empty($existing['login_ts']) && empty($extra['is_new_login']) ? (int)$existing['login_ts'] : $now,
        'last_active' => $nowFormatted,
        'last_active_ts' => $now,
        'revoked' => false,
    );

    return ngwSaveActiveSessions($all);
}

/**
 * Force-logout (revoke) a specific session token.
 */
function ngwRevokeActiveSession($token, $revokedBy = 'admin') {
    $token = trim((string)$token);
    if ($token === '') {
        return null;
    }
    $all = ngwLoadActiveSessions(true);
    if (!isset($all[$token])) {
        return null;
    }
    $target = $all[$token];
    $all[$token]['revoked'] = true;
    $all[$token]['revoked_by'] = (string)$revokedBy;
    $all[$token]['revoked_ts'] = time();
    $all[$token]['revoked_at'] = date('Y-m-d H:i:s');
    ngwSaveActiveSessions($all);

    // Also remove underlying PHP session file if different from current admin's PHP session
    if (!empty($target['php_sid']) && $target['php_sid'] !== session_id()) {
        $savePath = session_save_path();
        if ($savePath === '') {
            $savePath = sys_get_temp_dir();
        }
        $sessFile = rtrim($savePath, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . preg_replace('/[^a-zA-Z0-9,-]/', '', (string)$target['php_sid']);
        if (is_file($sessFile)) {
            @unlink($sessFile);
        }
    }
    return $target;
}

/**
 * Remove current session from active sessions list on normal logout.
 */
function ngwRemoveCurrentActiveSession() {
    if (empty($_SESSION['ngw_session_token'])) {
        return false;
    }
    $token = (string)$_SESSION['ngw_session_token'];
    $all = ngwLoadActiveSessions(true);
    if (isset($all[$token])) {
        unset($all[$token]);
        return ngwSaveActiveSessions($all);
    }
    return true;
}

/**
 * Append a compact, privacy-conscious activity event.
 * Stored as PHP-protected JSONL in include/ so direct web requests cannot expose it.
 */
function ngwActivityLogPath() {
    return __DIR__ . '/activity_log.php';
}

function ngwRecordActivity($action, $details = array(), $overrideActor = null, $overrideRole = null) {
    $allowed = array(
        'login',
        'logout',
        'login_failed',
        'session_force_logout',
        'router_connect_failed',
        'router_connected',
        'voucher_batch_created',
        'hotspot_user_created',
        'reseller_created',
        'reseller_updated',
        'reseller_deleted',
        'reseller_status_changed',
        'permission_groups_updated',
        'reseller_impersonation',
    );
    if (!in_array($action, $allowed, true)) return false;

    $username = ($overrideActor !== null && $overrideActor !== '')
        ? (string)$overrideActor
        : (isset($_SESSION['mikhmon']) && $_SESSION['mikhmon'] !== '' ? (string)$_SESSION['mikhmon'] : 'unknown');
    $role = ($overrideRole !== null && $overrideRole !== '')
        ? (string)$overrideRole
        : (isset($_SESSION['mikhmon_role']) && $_SESSION['mikhmon_role'] !== '' ? (string)$_SESSION['mikhmon_role'] : 'admin');

    $uaInfo = ngwParseUserAgent();
    $ip = ngwGetClientIp();

    $safe = array(
        'time' => date('Y-m-d H:i:s'),
        'actor' => substr($username, 0, 80),
        'role' => substr($role, 0, 20),
        'action' => $action,
        'details' => array(),
    );
    if (is_array($details)) {
        foreach (array('router', 'host', 'profile', 'quantity', 'target', 'status', 'error', 'ip', 'device', 'area') as $key) {
            if (isset($details[$key]) && is_scalar($details[$key]) && trim((string)$details[$key]) !== '') {
                $maxLen = ($key === 'error') ? 260 : 140;
                $safe['details'][$key] = substr(trim((string)$details[$key]), 0, $maxLen);
            }
        }
    }
    if (empty($safe['details']['ip'])) {
        $safe['details']['ip'] = $ip;
    }
    if (empty($safe['details']['device'])) {
        $safe['details']['device'] = $uaInfo['summary'];
    }

    $path = ngwActivityLogPath();
    $fp = @fopen($path, 'c+');
    if (!$fp) return false;
    @chmod($path, 0600);
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    $size = fstat($fp);
    if (!$size || (int)$size['size'] === 0) {
        fwrite($fp, "<?php http_response_code(404); exit; ?>\n");
    }
    fseek($fp, 0, SEEK_END);
    $ok = fwrite($fp, json_encode($safe, JSON_UNESCAPED_SLASHES) . "\n") !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function ngwReadActivityLog($limit = 2000) {
    $path = ngwActivityLogPath();
    if (!is_file($path) || !is_readable($path)) return array();
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return array();
    array_shift($lines); // PHP access guard
    $events = array();
    foreach (array_slice(array_reverse($lines), 0, max(1, min(5000, (int)$limit))) as $line) {
        $event = json_decode($line, true);
        if (is_array($event) && isset($event['time'], $event['actor'], $event['action'])) {
            $events[] = $event;
        }
    }
    return $events;
}

function ngwClearActivityLog() {
    $path = ngwActivityLogPath();
    return @file_put_contents($path, "<?php http_response_code(404); exit; ?>\n", LOCK_EX) !== false;
}

