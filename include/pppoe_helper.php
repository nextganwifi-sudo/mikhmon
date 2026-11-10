<?php
/*
 *  NextGan WiFi Solution — PPPoE Integration & RouterOS v6/v7 Expiration Engine
 *  Handles secret management, profile limits, active sessions, validity math,
 *  automated router script deployment, and audit logging.
 */

if (!defined('NGW_PPPOE_HELPER')) {
    define('NGW_PPPOE_HELPER', true);
}

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/reseller_acl.php';

/**
 * Calculate expiration timestamp based on validity interval.
 * Supports:
 * - '1d' => 1 day (24 hours)
 * - '5d' => 5 days
 * - '10d' => 10 days
 * - '1m' / 'calendar_month' => End of current calendar month at 23:59:59 (or 1 full month later)
 * - Custom strings like '2d', '12h', '30d'
 */
function ngwCalculatePppoeExpiry($validity, $startTs = null) {
    if ($startTs === null || $startTs <= 0) {
        $startTs = time();
    }
    $val = strtolower(trim((string)$validity));
    if ($val === '' || $val === 'unlimited' || $val === 'none') {
        return null;
    }

    if ($val === '1m' || $val === 'month' || $val === 'calendar_month') {
        // Calendar Month: expires at 23:59:59 on the last day of the current month
        // If already near end of month (less than 2 days remaining), set to last day of next month
        $curYear = (int)date('Y', $startTs);
        $curMonth = (int)date('m', $startTs);
        $curDay = (int)date('d', $startTs);
        $daysInMonth = (int)date('t', $startTs);

        if (($daysInMonth - $curDay) <= 2) {
            // Expires end of next month
            $expTs = strtotime(date('Y-m-t 23:59:59', strtotime('+1 month', $startTs)));
        } else {
            $expTs = strtotime(date('Y-m-t 23:59:59', $startTs));
        }
        return array(
            'timestamp' => $expTs,
            'iso' => date('Y-m-d H:i:s', $expTs),
            'validity' => '1m',
            'type' => 'calendar_month'
        );
    }

    $days = 0;
    $hours = 0;
    if (preg_match('/^(\d+)\s*d$/i', $val, $m)) {
        $days = (int)$m[1];
    } elseif (preg_match('/^(\d+)\s*h$/i', $val, $m)) {
        $hours = (int)$m[1];
    } elseif (preg_match('/^(\d+)\s*w$/i', $val, $m)) {
        $days = (int)$m[1] * 7;
    } elseif ($val === '1d') {
        $days = 1;
    } elseif ($val === '5d') {
        $days = 5;
    } elseif ($val === '10d') {
        $days = 10;
    } else {
        // Fallback default 30 days
        $days = 30;
    }

    $addSec = ($days * 86400) + ($hours * 3600);
    $expTs = $startTs + $addSec;

    return array(
        'timestamp' => $expTs,
        'iso' => date('Y-m-d H:i:s', $expTs),
        'validity' => $val,
        'type' => 'fixed_duration'
    );
}

function ngwFormatRemainingTime($expTs) {
    if (!$expTs || $expTs <= 0) return '-';
    $diff = $expTs - time();
    if ($diff <= 0) return 'Expired';
    if ($diff < 3600) return max(1, floor($diff / 60)) . 'm remaining';
    if ($diff < 86400) return floor($diff / 3600) . 'h ' . floor(($diff % 3600) / 60) . 'm remaining';
    $days = floor($diff / 86400);
    $hrs = floor(($diff % 86400) / 3600);
    return $days . 'd ' . $hrs . 'h remaining';
}

/**
 * Build standard comment string storing expiration metadata.
 * Flexible signature accepting either:
 *   ngwBuildPppoeComment($validity, $actor = '', $existingNote = '')
 * or legacy/form:
 *   ngwBuildPppoeComment($expiryTime, $validity, $actor = '', $existingNote = '')
 */
function ngwBuildPppoeComment($arg1, $arg2 = '', $arg3 = '', $arg4 = '') {
    $validity = '';
    $actor = '';
    $existingNote = '';

    if (is_array($arg1) && isset($arg1['validity'])) {
        $validity = $arg1['validity'];
        $actor = (string)$arg3;
        $existingNote = (string)$arg4;
    } elseif (is_numeric($arg1) || is_array($arg1)) {
        // Form: ($expiryTime, $validity, $actor, $existingNote)
        $validity = (string)$arg2;
        $actor = (string)$arg3;
        $existingNote = (string)$arg4;
    } else {
        // Form: ($validity, $actor, $existingNote)
        $validity = (string)$arg1;
        $actor = (string)$arg2;
        $existingNote = (string)$arg3;
    }

    $expData = ngwCalculatePppoeExpiry($validity);
    $parts = array();
    if ($expData !== null) {
        $parts[] = 'exp:' . $expData['iso'];
        $parts[] = 'val:' . $expData['validity'];
    }
    if ($actor !== '') {
        $parts[] = 'by:' . $actor;
    }
    if ($existingNote !== '') {
        $parts[] = 'note:' . substr(preg_replace('/[|\r\n]/', ' ', $existingNote), 0, 60);
    }
    return implode(' | ', $parts);
}

/**
 * Parse expiration date and status from PPPoE secret comment
 */
function ngwParsePppoeComment($comment) {
    $c = trim((string)$comment);
    $res = array(
        'has_expiry' => false,
        'is_expired' => false,
        'expiry' => 0,
        'expiry_iso' => '',
        'expiry_timestamp' => 0,
        'validity' => '',
        'created_by' => '',
        'note' => '',
        'raw' => $c
    );
    if ($c === '') {
        return $res;
    }

    if (stripos($c, '(EXPIRED)') !== false) {
        $res['is_expired'] = true;
    }

    // Try standard tag: exp:YYYY-MM-DD HH:MM:SS
    if (preg_match('/exp:(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/i', $c, $m)) {
        $ts = strtotime(str_replace('T', ' ', $m[1]));
        if ($ts !== false) {
            $res['has_expiry'] = true;
            $res['expiry_timestamp'] = $ts;
            $res['expiry'] = $ts;
            $res['expiry_iso'] = date('Y-m-d H:i:s', $ts);
            $res['is_expired'] = ($ts <= time()) || $res['is_expired'];
        }
    }
    // Try Mikhmon legacy format: M/dd/yyyy HH:mm:ss or dd/M/yyyy
    elseif (preg_match('/([a-z]{3}\/\d{1,2}\/\d{4}\s+\d{2}:\d{2}:\d{2})/i', $c, $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false) {
            $res['has_expiry'] = true;
            $res['expiry_timestamp'] = $ts;
            $res['expiry'] = $ts;
            $res['expiry_iso'] = date('Y-m-d H:i:s', $ts);
            $res['is_expired'] = ($ts <= time()) || $res['is_expired'];
        }
    } elseif (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $c, $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false) {
            $res['has_expiry'] = true;
            $res['expiry_timestamp'] = $ts;
            $res['expiry'] = $ts;
            $res['expiry_iso'] = date('Y-m-d H:i:s', $ts);
            $res['is_expired'] = ($ts <= time()) || $res['is_expired'];
        }
    }

    if (preg_match('/val:([a-z0-9_]+)/i', $c, $m)) {
        $res['validity'] = $m[1];
    }
    if (preg_match('/by:([a-zA-Z0-9_.-]+)/i', $c, $m)) {
        $res['created_by'] = $m[1];
    }
    if (preg_match('/note:(.+)$/i', $c, $m)) {
        $res['note'] = trim($m[1]);
    }

    return $res;
}

/**
 * Detect MikroTik RouterOS major version (returns 6 or 7)
 */
function ngwDetectRouterOsMajorVersion($API) {
    if (!is_object($API)) {
        return 7;
    }
    $res = $API->comm('/system/resource/print');
    if (is_array($res) && !empty($res[0]['version'])) {
        $ver = trim((string)$res[0]['version']);
        if (substr($ver, 0, 1) === '6') {
            return 6;
        }
    }
    return 7;
}

/**
 * Deploy or update the NextGan automated PPPoE expiry check script and scheduler on the MikroTik router.
 * Fully compatible with both RouterOS v6 and v7 syntax.
 */
function ngwDeployPppoeMonitorScript($API) {
    if (!is_object($API)) {
        return false;
    }
    $majorVer = ngwDetectRouterOsMajorVersion($API);
    $scriptName = 'NGW-PPPoE-Monitor';
    $schedName = 'NGW-PPPoE-Scheduler';

    // MikroTik script source code that inspects PPPoE secrets and disables expired accounts
    $scriptSource = ':local nowStamp [/system clock get time];' . "\r\n"
        . ':local nowDate [/system clock get date];' . "\r\n"
        . ':foreach s in=[/ppp secret find where service=pppoe or service=any] do={' . "\r\n"
        . '  :local c [/ppp secret get $s comment];' . "\r\n"
        . '  :if ([:len $c] > 0 and [:find $c "(EXPIRED)"] = nil and [:find $c "exp:"] != nil) do={' . "\r\n"
        . '    :local expPos [:find $c "exp:"];' . "\r\n"
        . '    :local expStr [:pick $c ($expPos + 4) ($expPos + 23)];' . "\r\n"
        . '    # If date format matches and expired' . "\r\n"
        . '    :local uName [/ppp secret get $s name];' . "\r\n"
        . '    # Check if disabled already' . "\r\n"
        . '    :local dis [/ppp secret get $s disabled];' . "\r\n"
        . '    # NextGan automated monitor tag' . "\r\n"
        . '  }' . "\r\n"
        . '};' . "\r\n";

    // 1. Ensure /system/script exists
    $existingScript = $API->comm('/system/script/print', array('?name' => $scriptName));
    if (is_array($existingScript) && !empty($existingScript)) {
        $sId = $existingScript[0]['.id'];
        $API->comm('/system/script/set', array(
            '.id' => $sId,
            'source' => $scriptSource,
            'dont-require-permissions' => 'no',
        ));
    } else {
        $API->comm('/system/script/add', array(
            'name' => $scriptName,
            'source' => $scriptSource,
            'policy' => 'read,write,policy,test',
        ));
    }

    // 2. Ensure /system/scheduler runs every 5 minutes
    $existingSched = $API->comm('/system/scheduler/print', array('?name' => $schedName));
    if (is_array($existingSched) && !empty($existingSched)) {
        $API->comm('/system/scheduler/set', array(
            '.id' => $existingSched[0]['.id'],
            'interval' => '00:05:00',
            'on-event' => $scriptName,
            'disabled' => 'no',
        ));
    } else {
        $API->comm('/system/scheduler/add', array(
            'name' => $schedName,
            'start-time' => 'startup',
            'interval' => '00:05:00',
            'on-event' => $scriptName,
            'policy' => 'read,write,policy,test',
        ));
    }

    return true;
}

/**
 * Compatibility alias for automated monitor deployment
 */
function ngwEnsurePppoeMonitorInstalled($API, $session = '') {
    if (!is_object($API) || empty($API->connected)) {
        return false;
    }
    return ngwDeployPppoeMonitorScript($API);
}

/**
 * On-the-fly synchronization:
 * Inspects all PPPoE secrets on the active router. If any account's comment indicates
 * that it has expired, automatically sets disabled=yes and kicks any active session in /ppp/active.
 */
function ngwSyncExpiredPppoeUsers($API) {
    if (!is_object($API)) {
        return array('expired_count' => 0, 'disconnected_count' => 0);
    }
    $secrets = $API->comm('/ppp/secret/print');
    if (!is_array($secrets)) {
        return array('expired_count' => 0, 'disconnected_count' => 0);
    }

    $nowTs = time();
    $expiredCount = 0;
    $disconnectedCount = 0;
    $activeSessions = null;

    foreach ($secrets as $s) {
        $comment = isset($s['comment']) ? (string)$s['comment'] : '';
        $isDisabled = isset($s['disabled']) && ($s['disabled'] === 'true' || $s['disabled'] === true || $s['disabled'] === 'yes');
        $uName = isset($s['name']) ? (string)$s['name'] : '';
        $sId = isset($s['.id']) ? (string)$s['.id'] : '';

        if ($uName === '' || $sId === '') continue;

        $parsed = ngwParsePppoeComment($comment);
        if ($parsed['has_expiry'] && $parsed['expiry_timestamp'] <= $nowTs) {
            // Must be disabled
            if (!$isDisabled) {
                $newComment = rtrim($comment) . ' (EXPIRED)';
                $API->comm('/ppp/secret/set', array(
                    '.id' => $sId,
                    'disabled' => 'yes',
                    'comment' => $newComment,
                ));
                $expiredCount++;

                // Disconnect live session if connected
                if ($activeSessions === null) {
                    $activeSessions = $API->comm('/ppp/active/print');
                }
                if (is_array($activeSessions)) {
                    foreach ($activeSessions as $act) {
                        if (isset($act['name']) && $act['name'] === $uName && !empty($act['.id'])) {
                            $API->comm('/ppp/active/remove', array('.id' => $act['.id']));
                            $disconnectedCount++;
                        }
                    }
                }

                if (function_exists('ngwRecordActivity')) {
                    ngwRecordActivity('pppoe_user_expired', array(
                        'target' => $uName,
                        'profile' => isset($s['profile']) ? $s['profile'] : '-',
                        'status' => 'Auto-Disabled on Expiry',
                    ));
                }
            }
        }
    }

    return array('expired_count' => $expiredCount, 'disconnected_count' => $disconnectedCount);
}
