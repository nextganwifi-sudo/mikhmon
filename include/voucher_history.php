<?php
/*
 *  NextGan WiFi Solution — 30-Day Backend Voucher History & Last Disconnect Tracker
 *  Stores removed/expired/manual-deleted voucher history for 30 days (1 month)
 *  and tracks live + historical disconnect timestamps across RouterOS v6 & v7.
 */

if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -19) == "voucher_history.php") {
    header("Location:./");
    exit;
}

if (!defined('NGW_VOUCHER_HISTORY_RETENTION')) {
    define('NGW_VOUCHER_HISTORY_RETENTION', 30 * 86400);
}

include_once __DIR__ . '/mac_vendor.php';

/**
 * Resolve storage directory (/data) and ensure it is protected.
 */
function ngwGetVoucherHistoryDir() {
    $rootDir = realpath(__DIR__ . '/..');
    if ($rootDir === false) {
        $rootDir = dirname(__DIR__);
    }
    $dataDir = $rootDir . '/data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0750, true);
    }
    $htaccess = $dataDir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dataDir;
}

/**
 * Resolve per-router history JSON path.
 */
function ngwGetVoucherHistoryFilePath($session) {
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$session);
    if ($safe === '' || $safe === null) {
        $safe = 'default';
    }
    return ngwGetVoucherHistoryDir() . '/voucher_history_' . $safe . '.json';
}

/**
 * Sync PHP default timezone and compute router current timestamp from /system/clock/print.
 */
function ngwSyncRouterTimezone($API = null) {
    $tz = '';
    $clockDate = '';
    $clockTime = '';

    if ($API && !empty($API->connected)) {
        $getClock = $API->comm('/system/clock/print');
        if (is_array($getClock) && isset($getClock[0]) && is_array($getClock[0])) {
            $tz = isset($getClock[0]['time-zone-name']) ? trim((string)$getClock[0]['time-zone-name']) : '';
            $clockDate = isset($getClock[0]['date']) ? trim((string)$getClock[0]['date']) : '';
            $clockTime = isset($getClock[0]['time']) ? trim((string)$getClock[0]['time']) : '';
        }
    }

    if ($tz === '' && isset($_SESSION['timezone']) && trim((string)$_SESSION['timezone']) !== '') {
        $tz = trim((string)$_SESSION['timezone']);
    }

    if ($tz !== '' && $tz !== 'manual') {
        if (@date_default_timezone_set($tz)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['timezone'] = $tz;
            }
        }
    }

    $nowTs = time();
    if ($clockDate !== '' && function_exists('parseRouterOSDate')) {
        $parsedClockDate = parseRouterOSDate($clockDate);
        if (!empty($parsedClockDate['valid'])) {
            $tClean = preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $clockTime) ? $clockTime : date('H:i:s');
            if (strlen($tClean) === 7) {
                $tClean = '0' . $tClean;
            }
            $ts = strtotime($parsedClockDate['iso'] . ' ' . $tClean);
            if ($ts !== false && $ts > 946684800) {
                $nowTs = $ts;
            }
        }
    }

    return array(
        'timezone' => date_default_timezone_get(),
        'now_ts'   => $nowTs,
        'date'     => $clockDate,
        'time'     => $clockTime,
    );
}

/**
 * Load router voucher history store and prune records older than 30 days.
 */
function ngwLoadVoucherHistory($session, $nowTs = null) {
    $now = ($nowTs !== null && (int)$nowTs > 0) ? (int)$nowTs : time();
    $file = ngwGetVoucherHistoryFilePath($session);
    $store = array(
        'session'            => (string)$session,
        'updated_at_ts'      => 0,
        'last_full_scan_ts'  => 0,
        'last_dhcp_scan_ts'  => 0,
        'records'            => array(),
    );

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if (is_string($raw) && $raw !== '') {
            $decoded = @json_decode($raw, true);
            if (is_array($decoded)) {
                $store['session'] = isset($decoded['session']) ? (string)$decoded['session'] : (string)$session;
                $store['updated_at_ts'] = isset($decoded['updated_at_ts']) ? (int)$decoded['updated_at_ts'] : 0;
                $store['last_full_scan_ts'] = isset($decoded['last_full_scan_ts']) ? (int)$decoded['last_full_scan_ts'] : 0;
                $store['last_dhcp_scan_ts'] = isset($decoded['last_dhcp_scan_ts']) ? (int)$decoded['last_dhcp_scan_ts'] : 0;
                if (isset($decoded['records']) && is_array($decoded['records'])) {
                    $store['records'] = $decoded['records'];
                }
            }
        }
    }

    $store['records'] = ngwPruneVoucherHistoryRecords($store['records'], $now);
    return $store;
}

/**
 * Keep active users and keep removed vouchers for full 30 days (1 month).
 */
function ngwPruneVoucherHistoryRecords($records, $nowTs = null) {
    if (!is_array($records)) {
        return array();
    }
    $now = ($nowTs !== null && (int)$nowTs > 0) ? (int)$nowTs : time();
    $cutoff = $now - NGW_VOUCHER_HISTORY_RETENTION;
    $kept = array();

    foreach ($records as $k => $rec) {
        if (!is_array($rec) || empty($rec['voucher'])) {
            continue;
        }
        $isRemoved = !empty($rec['removed']);
        if ($isRemoved) {
            $refTs = !empty($rec['removed_at_ts'])
                ? (int)$rec['removed_at_ts']
                : (!empty($rec['last_seen_ts']) ? (int)$rec['last_seen_ts'] : (int)(isset($rec['first_seen_ts']) ? $rec['first_seen_ts'] : 0));
            if ($refTs > 0 && $refTs < $cutoff) {
                continue;
            }
        } else {
            // Live users remain while present; if somehow never seen for 45 days without removal mark, prune stale orphan
            $lastSeen = isset($rec['last_seen_ts']) ? (int)$rec['last_seen_ts'] : 0;
            if ($lastSeen > 0 && ($now - $lastSeen) > (45 * 86400)) {
                continue;
            }
        }
        $kept[strtolower(trim((string)$k))] = $rec;
    }

    return $kept;
}

/**
 * Save history JSON atomically with LOCK_EX.
 */
function ngwSaveVoucherHistory($session, $store, $nowTs = null) {
    $now = ($nowTs !== null && (int)$nowTs > 0) ? (int)$nowTs : time();
    $file = ngwGetVoucherHistoryFilePath($session);
    if (!isset($store['records']) || !is_array($store['records'])) {
        $store['records'] = array();
    }
    $store['session'] = (string)$session;
    $store['updated_at_ts'] = $now;
    $store['records'] = ngwPruneVoucherHistoryRecords($store['records'], $now);

    $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmpFile = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
        if (!@rename($tmpFile, $file)) {
            @unlink($tmpFile);
            return @file_put_contents($file, $json, LOCK_EX) !== false;
        }
        return true;
    }
    return @file_put_contents($file, $json, LOCK_EX) !== false;
}

/**
 * Build/update a single voucher record entry from RouterOS /ip/hotspot/user row + optional active row.
 */
function ngwBuildOrUpdateHistoryRecord($existingRec, $u, $activeRow, $nowTs, $latestScriptRec = null) {
    $uname = isset($u['name']) ? trim((string)$u['name']) : '';
    $rec = is_array($existingRec) ? $existingRec : array();

    $rec['voucher'] = $uname;
    if (isset($u['password']) && $u['password'] !== '') {
        $rec['password'] = (string)$u['password'];
    } elseif (!isset($rec['password'])) {
        $rec['password'] = $uname;
    }
    if (isset($u['profile']) && $u['profile'] !== '') {
        $rec['profile'] = (string)$u['profile'];
    } elseif (!isset($rec['profile'])) {
        $rec['profile'] = '';
    }

    $rawComment = isset($u['comment']) ? trim((string)$u['comment']) : '';
    $cleanComment = function_exists('normalizeActivatedVoucherComment')
        ? normalizeActivatedVoucherComment($rawComment)
        : $rawComment;
    if ($cleanComment !== '') {
        $rec['comment'] = $cleanComment;
    } elseif (!isset($rec['comment'])) {
        $rec['comment'] = '';
    }

    if (preg_match('/^(?:vc|up)-/i', $rawComment)) {
        $rec['batch'] = $rawComment;
    } elseif (!isset($rec['batch'])) {
        $rec['batch'] = '';
    }

    $uMac = isset($u['mac-address']) ? trim((string)$u['mac-address']) : '';
    if ($uMac !== '' && $uMac !== '00:00:00:00:00:00') {
        $rec['mac'] = $uMac;
    } elseif ($activeRow && !empty($activeRow['mac-address']) && $activeRow['mac-address'] !== '00:00:00:00:00:00') {
        $rec['mac'] = trim((string)$activeRow['mac-address']);
    } elseif ($latestScriptRec && !empty($latestScriptRec['mac'])) {
        $rec['mac'] = trim((string)$latestScriptRec['mac']);
    } elseif (!isset($rec['mac'])) {
        $rec['mac'] = '';
    }

    if ($activeRow && !empty($activeRow['address'])) {
        $rec['ip'] = trim((string)$activeRow['address']);
    } elseif ($latestScriptRec && !empty($latestScriptRec['ip'])) {
        $rec['ip'] = trim((string)$latestScriptRec['ip']);
    } elseif (!isset($rec['ip'])) {
        $rec['ip'] = '';
    }

    $rec['uptime'] = ($activeRow && !empty($activeRow['uptime']))
        ? (string)$activeRow['uptime']
        : (isset($u['uptime']) && $u['uptime'] !== '' ? (string)$u['uptime'] : (isset($rec['uptime']) ? $rec['uptime'] : '0s'));
    $rec['limit_uptime'] = isset($u['limit-uptime']) ? (string)$u['limit-uptime'] : (isset($rec['limit_uptime']) ? $rec['limit_uptime'] : '');

    $liveIn = isset($u['bytes-in']) ? (int)$u['bytes-in'] : 0;
    $actIn = ($activeRow && isset($activeRow['bytes-in'])) ? (int)$activeRow['bytes-in'] : 0;
    $rec['bytes_in'] = max($liveIn, $actIn, isset($rec['bytes_in']) ? (int)$rec['bytes_in'] : 0);

    $liveOut = isset($u['bytes-out']) ? (int)$u['bytes-out'] : 0;
    $actOut = ($activeRow && isset($activeRow['bytes-out'])) ? (int)$activeRow['bytes-out'] : 0;
    $rec['bytes_out'] = max($liveOut, $actOut, isset($rec['bytes_out']) ? (int)$rec['bytes_out'] : 0);

    // Parse expiry from comment or script record
    $parsedExp = function_exists('parseExpiryFromComment') ? parseExpiryFromComment($cleanComment) : array('is_expiry' => false);
    if (!empty($parsedExp['is_expiry']) && !empty($parsedExp['iso_datetime'])) {
        $rec['has_expiry'] = true;
        $rec['expiry_iso'] = $parsedExp['iso_datetime'];
        $rec['expiry_legacy'] = $parsedExp['legacy_datetime'];
    } elseif ($latestScriptRec && !empty($latestScriptRec['has_expiry'])) {
        $rec['has_expiry'] = true;
        $rec['expiry_iso'] = (string)$latestScriptRec['expiry_iso'];
        $rec['expiry_legacy'] = (string)$latestScriptRec['expiry_legacy'];
    } elseif (!isset($rec['has_expiry'])) {
        $rec['has_expiry'] = false;
        $rec['expiry_iso'] = '';
        $rec['expiry_legacy'] = '';
    }

    if ($latestScriptRec && !empty($latestScriptRec['login_datetime'])) {
        $rec['login_date'] = isset($latestScriptRec['login_date']) ? (string)$latestScriptRec['login_date'] : '';
        $rec['login_time'] = isset($latestScriptRec['login_time']) ? (string)$latestScriptRec['login_time'] : '';
        $rec['login_datetime'] = (string)$latestScriptRec['login_datetime'];
        if (!empty($latestScriptRec['validity'])) {
            $rec['validity'] = (string)$latestScriptRec['validity'];
        }
    } elseif (!isset($rec['login_datetime'])) {
        $rec['login_date'] = '';
        $rec['login_time'] = '';
        $rec['login_datetime'] = '';
        $rec['validity'] = isset($rec['validity']) ? $rec['validity'] : '';
    }

    if (empty($rec['first_seen_ts'])) {
        $rec['first_seen_ts'] = $nowTs;
    }
    $rec['last_seen_ts'] = $nowTs;

    // Live user exists on router, so clear any previous removal flag (e.g., if recreated with same code)
    $rec['removed'] = false;
    $rec['removed_at_ts'] = 0;
    $rec['removed_at'] = '';
    $rec['removed_reason'] = '';

    // Track Online / Offline transition for Last Disconnect
    $isOnlineNow = ($activeRow !== null);
    if ($isOnlineNow) {
        $rec['was_online'] = true;
        $rec['last_online_ts'] = $nowTs;
    } else {
        $wasOnline = !empty($rec['was_online']);
        $rec['was_online'] = false;
        if ($wasOnline) {
            $prevDisc = isset($rec['last_disconnect_ts']) ? (int)$rec['last_disconnect_ts'] : 0;
            $lastOn = isset($rec['last_online_ts']) ? (int)$rec['last_online_ts'] : 0;
            if ($prevDisc < $lastOn || $prevDisc === 0) {
                $rec['last_disconnect_ts'] = $nowTs;
                $rec['last_disconnect_at'] = date('Y-m-d H:i:s', $nowTs);
                $rec['last_disconnect_source'] = 'snapshot';
                if (empty($rec['last_disconnect_reason'])) {
                    $rec['last_disconnect_reason'] = '';
                }
            }
        }
    }

    if (!isset($rec['last_disconnect_ts'])) {
        $rec['last_disconnect_ts'] = 0;
        $rec['last_disconnect_at'] = '';
        $rec['last_disconnect_source'] = '';
        $rec['last_disconnect_reason'] = '';
    }

    return $rec;
}

/**
 * Full-router snapshot sync: preserves all vouchers and detects both
 * online->offline transitions and vouchers removed from the router.
 */
function ngwSyncVoucherHistorySnapshot($API, $session, $hotspotUsers = null, $activeRows = null, $routerNowTs = null, $minScanIntervalSec = 10) {
    if ((string)$session === '') {
        return false;
    }
    $now = ($routerNowTs !== null && (int)$routerNowTs > 0) ? (int)$routerNowTs : time();
    $store = ngwLoadVoucherHistory($session, $now);

    if ($hotspotUsers === null) {
        if (!$API || empty($API->connected)) {
            return false;
        }
        if ($minScanIntervalSec > 0 && !empty($store['last_full_scan_ts']) && ($now - (int)$store['last_full_scan_ts']) < $minScanIntervalSec) {
            return true;
        }
        $hotspotUsers = $API->comm('/ip/hotspot/user/print');
    }

    if (!is_array($hotspotUsers) || isset($hotspotUsers['!trap'])) {
        return false;
    }

    if ($activeRows === null && $API && !empty($API->connected)) {
        $activeRows = $API->comm('/ip/hotspot/active/print');
    }
    $activeMap = array();
    if (is_array($activeRows) && !isset($activeRows['!trap'])) {
        foreach ($activeRows as $ar) {
            if (!is_array($ar) || empty($ar['user'])) {
                continue;
            }
            $uKey = strtolower(trim((string)$ar['user']));
            if ($uKey !== '' && !isset($activeMap[$uKey])) {
                $activeMap[$uKey] = $ar;
            }
        }
    }

    $liveKeys = array();
    foreach ($hotspotUsers as $u) {
        if (!is_array($u)) {
            continue;
        }
        $uname = isset($u['name']) ? trim((string)$u['name']) : '';
        if ($uname === '' || $uname === 'default-trial') {
            continue;
        }
        $key = strtolower($uname);
        $liveKeys[$key] = true;
        $existing = isset($store['records'][$key]) ? $store['records'][$key] : null;
        $activeRow = isset($activeMap[$key]) ? $activeMap[$key] : null;
        $store['records'][$key] = ngwBuildOrUpdateHistoryRecord($existing, $u, $activeRow, $now, null);
    }

    // Any previously live voucher absent from router now -> mark Removed from server with timestamp
    foreach ($store['records'] as $key => $rec) {
        if (!is_array($rec) || !empty($rec['removed'])) {
            continue;
        }
        if (!isset($liveKeys[$key])) {
            $removedTs = $now;
            $removedReason = 'removed_from_router';
            $lastSeen = isset($rec['last_seen_ts']) ? (int)$rec['last_seen_ts'] : 0;

            if (!empty($rec['has_expiry']) && !empty($rec['expiry_iso'])) {
                $expTs = strtotime((string)$rec['expiry_iso']);
                if ($expTs !== false && $expTs <= $now) {
                    $removedReason = 'expired_auto_removed';
                    if ($lastSeen <= 0 || $expTs >= ($lastSeen - 120)) {
                        $removedTs = $expTs;
                    }
                }
            }

            $rec['removed'] = true;
            $rec['removed_at_ts'] = $removedTs;
            $rec['removed_at'] = date('Y-m-d H:i:s', $removedTs);
            $rec['removed_reason'] = $removedReason;

            if (!empty($rec['was_online'])) {
                $rec['was_online'] = false;
                $discTs = ($removedTs <= $now) ? $removedTs : $now;
                if (empty($rec['last_disconnect_ts']) || (int)$rec['last_disconnect_ts'] < (int)(isset($rec['last_online_ts']) ? $rec['last_online_ts'] : 0)) {
                    $rec['last_disconnect_ts'] = $discTs;
                    $rec['last_disconnect_at'] = date('Y-m-d H:i:s', $discTs);
                    $rec['last_disconnect_source'] = 'removed';
                }
            }
            $store['records'][$key] = $rec;
        }
    }

    // Periodically enrich live users from MikroTik DHCP Leases (max once per 60s)
    // so device hostname/model/vendor are preserved before DHCP leases expire or vouchers are deleted.
    $lastDhcpScanTs = isset($store['last_dhcp_scan_ts']) ? (int)$store['last_dhcp_scan_ts'] : 0;
    if ($API && !empty($API->connected) && ($now - $lastDhcpScanTs) >= 60 && function_exists('ngwClassifyDeviceFromMetadata')) {
        $dhcpLeases = $API->comm('/ip/dhcp-server/lease/print');
        if (is_array($dhcpLeases) && !isset($dhcpLeases['!trap'])) {
            $leaseByMac = array();
            $leaseByIp = array();
            foreach ($dhcpLeases as $dl) {
                if (!is_array($dl)) continue;
                $lMac = isset($dl['mac-address']) ? ngwNormalizeMac($dl['mac-address']) : '';
                $lAMac = isset($dl['active-mac-address']) ? ngwNormalizeMac($dl['active-mac-address']) : '';
                $lIp = isset($dl['address']) ? trim((string)$dl['address']) : '';
                $lAIp = isset($dl['active-address']) ? trim((string)$dl['active-address']) : '';
                if ($lMac !== '' && $lMac !== '00:00:00:00:00:00') $leaseByMac[$lMac] = $dl;
                if ($lAMac !== '' && $lAMac !== '00:00:00:00:00:00') $leaseByMac[$lAMac] = $dl;
                if ($lIp !== '') $leaseByIp[$lIp] = $dl;
                if ($lAIp !== '') $leaseByIp[$lAIp] = $dl;
            }
            foreach ($liveKeys as $k => $_unusedFlag) {
                if (!isset($store['records'][$k]) || !is_array($store['records'][$k])) continue;
                $rec = $store['records'][$k];
                $rMac = isset($rec['mac']) ? ngwNormalizeMac($rec['mac']) : '';
                $rIp = isset($rec['ip']) ? trim((string)$rec['ip']) : '';
                if ($rMac === '' && $rIp === '') continue;

                $hitLease = null;
                if ($rMac !== '' && isset($leaseByMac[$rMac])) {
                    $hitLease = $leaseByMac[$rMac];
                } elseif ($rIp !== '' && isset($leaseByIp[$rIp])) {
                    $hitLease = $leaseByIp[$rIp];
                }

                $lHost = ($hitLease && !empty($hitLease['host-name'])) ? trim((string)$hitLease['host-name']) : (isset($rec['device_hostname']) ? (string)$rec['device_hostname'] : '');
                $lCls = '';
                if ($hitLease) {
                    $lCls = !empty($hitLease['class-id']) ? trim((string)$hitLease['class-id']) : (!empty($hitLease['vendor-class-id']) ? trim((string)$hitLease['vendor-class-id']) : '');
                }
                if ($lCls === '' && !empty($rec['device_class_id'])) {
                    $lCls = (string)$rec['device_class_id'];
                }

                // Avoid slow remote OUI network calls inside bulk snapshot loop: run classify only when
                // DHCP lease gives new info or MAC wasn't already classified
                if ($hitLease !== null || (empty($rec['device_vendor']) && empty($rec['device_model']) && empty($rec['device_type']))) {
                    if ($hitLease !== null || ($rMac !== '' && ngwLookupOfflineMacVendor($rMac) !== '')) {
                        $dev = ngwClassifyDeviceFromMetadata($rMac, $lHost, $lCls);
                        if (!empty($dev['vendor'])) $rec['device_vendor'] = $dev['vendor'];
                        if (!empty($dev['model'])) $rec['device_model'] = $dev['model'];
                        if (!empty($dev['device_type'])) $rec['device_type'] = $dev['device_type'];
                        if (!empty($dev['hostname'])) $rec['device_hostname'] = $dev['hostname'];
                        if (!empty($dev['class_id'])) $rec['device_class_id'] = $dev['class_id'];
                        $store['records'][$k] = $rec;
                    }
                }
            }
            $store['last_dhcp_scan_ts'] = $now;
        }
    }

    $store['last_full_scan_ts'] = $now;
    return ngwSaveVoucherHistory($session, $store, $now);
}

/**
 * Update a single live voucher in backend history (used by Reset Voucher & User Edit page).
 */
function ngwRecordSingleVoucherSnapshot($session, $u, $activeRow = null, $routerNowTs = null, $latestScriptRec = null) {
    if ((string)$session === '' || !is_array($u) || empty($u['name'])) {
        return null;
    }
    $uname = trim((string)$u['name']);
    if ($uname === '' || $uname === 'default-trial') {
        return null;
    }
    $now = ($routerNowTs !== null && (int)$routerNowTs > 0) ? (int)$routerNowTs : time();
    $store = ngwLoadVoucherHistory($session, $now);
    $key = strtolower($uname);
    $existing = isset($store['records'][$key]) ? $store['records'][$key] : null;
    $updated = ngwBuildOrUpdateHistoryRecord($existing, $u, $activeRow, $now, $latestScriptRec);
    $store['records'][$key] = $updated;
    ngwSaveVoucherHistory($session, $store, $now);
    return $updated;
}

/**
 * Persist discovered Device Vendor / Model / Type / Hostname for a voucher into 30-day backend history.
 */
function ngwSaveVoucherDeviceInfo($session, $username, $deviceInfo, $routerNowTs = null) {
    $uname = trim((string)$username);
    if ((string)$session === '' || $uname === '' || $uname === 'default-trial' || !is_array($deviceInfo)) {
        return false;
    }
    $now = ($routerNowTs !== null && (int)$routerNowTs > 0) ? (int)$routerNowTs : time();
    $store = ngwLoadVoucherHistory($session, $now);
    $key = strtolower($uname);
    $rec = isset($store['records'][$key]) && is_array($store['records'][$key])
        ? $store['records'][$key]
        : array('voucher' => $uname, 'first_seen_ts' => $now, 'last_seen_ts' => $now, 'removed' => false);

    $changed = false;
    $fieldMap = array(
        'vendor'      => 'device_vendor',
        'model'       => 'device_model',
        'device_type' => 'device_type',
        'hostname'    => 'device_hostname',
        'class_id'    => 'device_class_id',
    );
    foreach ($fieldMap as $srcKey => $dstKey) {
        $val = isset($deviceInfo[$srcKey]) ? trim((string)$deviceInfo[$srcKey]) : '';
        if ($val !== '' && (!isset($rec[$dstKey]) || (string)$rec[$dstKey] !== $val)) {
            $rec[$dstKey] = $val;
            $changed = true;
        }
    }
    if (!$changed) {
        return true;
    }
    $store['records'][$key] = $rec;
    return ngwSaveVoucherHistory($session, $store, $now);
}

/**
 * Record explicit deletion of one or more hotspot users before removing from MikroTik.
 */
function ngwMarkVouchersRemoved($session, $usersList, $reason = 'manual_delete', $routerNowTs = null) {
    if ((string)$session === '' || !is_array($usersList) || empty($usersList)) {
        return false;
    }
    $now = ($routerNowTs !== null && (int)$routerNowTs > 0) ? (int)$routerNowTs : time();
    $store = ngwLoadVoucherHistory($session, $now);

    foreach ($usersList as $u) {
        if (!is_array($u) || empty($u['name'])) {
            continue;
        }
        $uname = trim((string)$u['name']);
        if ($uname === '' || $uname === 'default-trial') {
            continue;
        }
        $key = strtolower($uname);
        $existing = isset($store['records'][$key]) ? $store['records'][$key] : null;
        $rec = ngwBuildOrUpdateHistoryRecord($existing, $u, null, $now, null);
        $rec['removed'] = true;
        $rec['removed_at_ts'] = $now;
        $rec['removed_at'] = date('Y-m-d H:i:s', $now);
        $rec['removed_reason'] = (string)$reason;
        if ($existing && !empty($existing['was_online'])) {
            $rec['was_online'] = false;
            $rec['last_disconnect_ts'] = $now;
            $rec['last_disconnect_at'] = date('Y-m-d H:i:s', $now);
            $rec['last_disconnect_source'] = (string)$reason;
        }
        $store['records'][$key] = $rec;
    }

    return ngwSaveVoucherHistory($session, $store, $now);
}

/**
 * Record explicit disconnect timestamp when user is kicked/disabled/reset.
 */
function ngwMarkVoucherDisconnected($session, $username, $routerNowTs = null, $source = 'manual_disconnect', $reason = '') {
    $uname = trim((string)$username);
    if ((string)$session === '' || $uname === '' || $uname === 'default-trial') {
        return false;
    }
    $now = ($routerNowTs !== null && (int)$routerNowTs > 0) ? (int)$routerNowTs : time();
    $store = ngwLoadVoucherHistory($session, $now);
    $key = strtolower($uname);
    $rec = isset($store['records'][$key]) && is_array($store['records'][$key])
        ? $store['records'][$key]
        : array('voucher' => $uname, 'first_seen_ts' => $now, 'last_seen_ts' => $now, 'removed' => false);

    $rec['voucher'] = $uname;
    $rec['was_online'] = false;
    $rec['last_disconnect_ts'] = $now;
    $rec['last_disconnect_at'] = date('Y-m-d H:i:s', $now);
    $rec['last_disconnect_source'] = (string)$source;
    if ($reason !== '') {
        $rec['last_disconnect_reason'] = (string)$reason;
    }
    $store['records'][$key] = $rec;
    return ngwSaveVoucherHistory($session, $store, $now);
}

/**
 * Parse RouterOS /log/print time string (ROS6 & ROS7) relative to $routerNowTs.
 * Supports:
 * - "HH:MM:SS" (today; or yesterday if timestamp is ahead of $routerNowTs + 300s)
 * - "nov/16 14:32:10"
 * - "nov/16/2026 14:32:10"
 * - "11-16 14:32:10"
 * - "2026-11-16 14:32:10"
 */
function ngwParseMikroTikLogTimestamp($timeStr, $routerNowTs = null) {
    $raw = strtolower(trim((string)$timeStr));
    if ($raw === '') {
        return false;
    }
    $now = ($routerNowTs !== null && (int)$routerNowTs > 0) ? (int)$routerNowTs : time();
    $months = array(
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    );

    // 1. YYYY-MM-DD HH:MM:SS
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})\s+(\d{1,2}):(\d{2}):(\d{2})$/', $raw, $m)) {
        return mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
    }

    // 2. mmm/dd/yyyy HH:MM:SS
    if (preg_match('/^([a-z]{3})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2}):(\d{2})$/', $raw, $m)) {
        if (isset($months[$m[1]])) {
            return mktime((int)$m[4], (int)$m[5], (int)$m[6], $months[$m[1]], (int)$m[2], (int)$m[3]);
        }
    }

    // 3. mmm/dd HH:MM:SS (RouterOS 6/7 log format from earlier days)
    if (preg_match('/^([a-z]{3})\/(\d{1,2})\s+(\d{1,2}):(\d{2}):(\d{2})$/', $raw, $m)) {
        if (isset($months[$m[1]])) {
            $year = (int)date('Y', $now);
            $ts = mktime((int)$m[3], (int)$m[4], (int)$m[5], $months[$m[1]], (int)$m[2], $year);
            if ($ts !== false && $ts > ($now + 86400)) {
                $ts = mktime((int)$m[3], (int)$m[4], (int)$m[5], $months[$m[1]], (int)$m[2], $year - 1);
            }
            return $ts;
        }
    }

    // 4. MM-DD HH:MM:SS (RouterOS 7 format for earlier days of the year)
    if (preg_match('/^(\d{1,2})-(\d{1,2})\s+(\d{1,2}):(\d{2}):(\d{2})$/', $raw, $m)) {
        $year = (int)date('Y', $now);
        $ts = mktime((int)$m[3], (int)$m[4], (int)$m[5], (int)$m[1], (int)$m[2], $year);
        if ($ts !== false && $ts > ($now + 86400)) {
            $ts = mktime((int)$m[3], (int)$m[4], (int)$m[5], (int)$m[1], (int)$m[2], $year - 1);
        }
        return $ts;
    }

    // 5. HH:MM:SS (today's log entries)
    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $raw, $m)) {
        $y = (int)date('Y', $now);
        $mo = (int)date('n', $now);
        $d = (int)date('j', $now);
        $ts = mktime((int)$m[1], (int)$m[2], (int)$m[3], $mo, $d, $y);
        if ($ts !== false && $ts > ($now + 300)) {
            $ts -= 86400;
        }
        return $ts;
    }

    return false;
}

/**
 * Scan RouterOS /log/print for a specific user/MAC's latest Hotspot logout event.
 */
function ngwFindLastDisconnectFromRouterLog($API, $username, $mac = '', $routerNowTs = null, $prefetchedLogs = null) {
    $uname = trim((string)$username);
    $macClean = str_replace('-', ':', strtolower(trim((string)$mac)));
    if ($macClean === '00:00:00:00:00:00') {
        $macClean = '';
    }
    if ($uname === '' && $macClean === '') {
        return null;
    }
    $now = ($routerNowTs !== null && (int)$routerNowTs > 0) ? (int)$routerNowTs : time();

    $logRows = $prefetchedLogs;
    if ($logRows === null) {
        if (!$API || empty($API->connected)) {
            return null;
        }
        $logRows = $API->comm('/log/print', array('?topics' => 'hotspot,info,debug'));
        if (!is_array($logRows) || empty($logRows) || isset($logRows['!trap'])) {
            $allLogs = $API->comm('/log/print');
            $logRows = array();
            if (is_array($allLogs)) {
                foreach ($allLogs as $lr) {
                    if (!is_array($lr)) continue;
                    $topics = isset($lr['topics']) ? strtolower((string)$lr['topics']) : '';
                    $msg = isset($lr['message']) ? strtolower((string)$lr['message']) : '';
                    if (strpos($topics, 'hotspot') !== false || strpos($msg, 'logged out') !== false) {
                        $logRows[] = $lr;
                    }
                }
            }
        }
    }

    if (!is_array($logRows) || empty($logRows)) {
        return null;
    }

    $userPattern = $uname !== ''
        ? '/(?:^|->\s*|\s)' . preg_quote($uname, '/') . '\s*(?:\(|:)/i'
        : '';

    $best = null;
    // Reverse order: RouterOS returns logs oldest -> newest
    for ($i = count($logRows) - 1; $i >= 0; $i--) {
        $row = $logRows[$i];
        if (!is_array($row) || empty($row['message'])) {
            continue;
        }
        $msg = (string)$row['message'];
        if (stripos($msg, 'logged out') === false) {
            continue;
        }
        $msgLower = strtolower(str_replace('-', ':', $msg));
        $matchesUser = ($userPattern !== '' && preg_match($userPattern, $msg));
        $matchesMac = ($macClean !== '' && strpos($msgLower, $macClean) !== false);
        if (!$matchesUser && !$matchesMac) {
            continue;
        }

        $rawTime = isset($row['time']) ? trim((string)$row['time']) : '';
        $ts = ngwParseMikroTikLogTimestamp($rawTime, $now);
        if ($ts === false) {
            continue;
        }

        $reason = '';
        if (preg_match('/logged out:\s*(.+)$/i', $msg, $rm)) {
            $reason = trim((string)$rm[1]);
        }

        $best = array(
            'found'    => true,
            'ts'       => $ts,
            'at'       => date('Y-m-d H:i:s', $ts),
            'reason'   => $reason,
            'raw_time' => $rawTime,
            'message'  => $msg,
        );
        break;
    }

    return $best;
}

/**
 * Convert ASCII digits to Bengali numerals when UI language is Bengali.
 */
function ngwToBanglaDigits($str) {
    return strtr((string)$str, array(
        '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪',
        '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯',
    ));
}

/**
 * Format human-readable relative duration (e.g. "২ ঘণ্টা ১৫ মিনিট আগে" / "2h 15m ago").
 */
function ngwFormatRelativeDuration($pastTs, $nowTs = null, $langid = 'en') {
    $past = (int)$pastTs;
    $now = ($nowTs !== null && (int)$nowTs > 0) ? (int)$nowTs : time();
    if ($past <= 0) {
        return '';
    }
    $diff = max(0, $now - $past);
    $isBn = ($langid === 'bn');

    if ($diff < 60) {
        if ($diff <= 5) {
            return $isBn ? 'এইমাত্র' : 'Just now';
        }
        return $isBn ? (ngwToBanglaDigits($diff) . ' সেকেন্ড আগে') : ($diff . 's ago');
    }

    $days = (int)floor($diff / 86400);
    $hours = (int)floor(($diff % 86400) / 3600);
    $mins = (int)floor(($diff % 3600) / 60);

    $parts = array();
    if ($days > 0) {
        $parts[] = $isBn ? (ngwToBanglaDigits($days) . ' দিন') : ($days . 'd');
    }
    if ($hours > 0) {
        $parts[] = $isBn ? (ngwToBanglaDigits($hours) . ' ঘণ্টা') : ($hours . 'h');
    }
    if ($days === 0 && $mins > 0) {
        $parts[] = $isBn ? (ngwToBanglaDigits($mins) . ' মিনিট') : ($mins . 'm');
    }
    if (empty($parts)) {
        $parts[] = $isBn ? '১ মিনিট' : '1m';
    }

    return implode(' ', $parts) . ($isBn ? ' আগে' : ' ago');
}

/**
 * Resolve Last Disconnect info for an offline voucher from RouterOS logs + backend 30-day history.
 */
function ngwResolveVoucherDisconnectInfo($API, $session, $username, $mac = '', $isOnline = false, $historyRec = null, $routerNowTs = null, $langid = 'en', $liveUserRow = null) {
    $now = ($routerNowTs !== null && (int)$routerNowTs > 0) ? (int)$routerNowTs : time();
    if ($isOnline) {
        return array(
            'has_disconnect'  => false,
            'ts'              => 0,
            'datetime'        => '',
            'relative'        => '',
            'reason'          => '',
            'never_logged_in' => false,
        );
    }

    $histTs = ($historyRec && !empty($historyRec['last_disconnect_ts'])) ? (int)$historyRec['last_disconnect_ts'] : 0;
    $histReason = ($historyRec && !empty($historyRec['last_disconnect_reason'])) ? (string)$historyRec['last_disconnect_reason'] : '';

    $logHit = ngwFindLastDisconnectFromRouterLog($API, $username, $mac, $now);
    $logTs = ($logHit && !empty($logHit['ts']) && $logHit['ts'] <= ($now + 300)) ? (int)$logHit['ts'] : 0;
    $logReason = ($logHit && !empty($logHit['reason'])) ? (string)$logHit['reason'] : '';

    $bestTs = 0;
    $bestReason = '';
    if ($logTs > 0 && $logTs >= $histTs) {
        $bestTs = $logTs;
        $bestReason = $logReason !== '' ? $logReason : $histReason;
        // Persist newly discovered router log disconnect time to 30-day backend history
        if ((string)$session !== '' && $username !== '' && $logTs > $histTs) {
            ngwMarkVoucherDisconnected($session, $username, $bestTs, 'router_log', $bestReason);
        }
    } elseif ($histTs > 0) {
        $bestTs = $histTs;
        $bestReason = $histReason !== '' ? $histReason : $logReason;
    }

    $neverLoggedIn = false;
    if ($bestTs <= 0 && is_array($liveUserRow)) {
        $rawUp = isset($liveUserRow['uptime']) ? trim((string)$liveUserRow['uptime']) : '';
        $bIn = isset($liveUserRow['bytes-in']) ? (int)$liveUserRow['bytes-in'] : 0;
        $bOut = isset($liveUserRow['bytes-out']) ? (int)$liveUserRow['bytes-out'] : 0;
        $cStr = isset($liveUserRow['comment']) ? trim((string)$liveUserRow['comment']) : '';
        $isAct = function_exists('isUsedActivatedVoucherComment') ? isUsedActivatedVoucherComment($cStr) : false;
        if (($rawUp === '' || $rawUp === '0s' || $rawUp === '00:00:00') && $bIn === 0 && $bOut === 0 && !$isAct) {
            $neverLoggedIn = true;
        }
    }

    return array(
        'has_disconnect'  => ($bestTs > 0),
        'ts'              => $bestTs,
        'datetime'        => ($bestTs > 0) ? date('Y-m-d H:i:s', $bestTs) : '',
        'relative'        => ($bestTs > 0) ? ngwFormatRelativeDuration($bestTs, $now, $langid) : '',
        'reason'          => $bestReason,
        'never_logged_in' => $neverLoggedIn,
    );
}

/**
 * Search and merge removed voucher records across backend 30-day JSON history
 * AND MikroTik /system/script (comment="mikhmon"), returning enriched records with
 * removal status, removal timestamp, expiry, and last disconnect time.
 */
function ngwMergeRemovedVoucherRecords($session, $searchQuery, $scriptRecs = array(), $routerNowTs = null) {
    $q = strtolower(trim((string)$searchQuery));
    if ($q === '') {
        return array();
    }
    $qMacNorm = str_replace('-', ':', $q);
    $now = ($routerNowTs !== null && (int)$routerNowTs > 0) ? (int)$routerNowTs : time();
    $cutoff = $now - NGW_VOUCHER_HISTORY_RETENTION;
    $store = ngwLoadVoucherHistory($session, $now);

    $merged = array();

    // 1. Index matching /system/script records within 30 days (+1 day future tolerance for clock drift)
    if (is_array($scriptRecs)) {
        foreach ($scriptRecs as $sr) {
            if (!is_array($sr) || empty($sr['voucher'])) {
                continue;
            }
            $loginDate = isset($sr['login_date']) ? trim((string)$sr['login_date']) : '';
            $loginTime = isset($sr['login_time']) ? trim((string)$sr['login_time']) : '00:00:00';
            $parsed = function_exists('parseRouterOSDate') ? parseRouterOSDate($loginDate) : array('valid' => false);
            $stamp = false;
            if (!empty($parsed['valid'])) {
                if (!preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $loginTime)) $loginTime = '00:00:00';
                if (strlen($loginTime) === 5) $loginTime .= ':00';
                $stamp = strtotime($parsed['iso'] . ' ' . $loginTime);
            }
            // Keep if within last 30 days (with 1-day clock drift buffer) or if expiry was within 30 days
            $expStamp = (!empty($sr['has_expiry']) && !empty($sr['expiry_iso'])) ? strtotime((string)$sr['expiry_iso']) : false;
            $within30d = false;
            if ($stamp !== false && $stamp >= $cutoff && $stamp <= ($now + 86400)) {
                $within30d = true;
            } elseif ($expStamp !== false && $expStamp >= $cutoff && $expStamp <= ($now + 86400)) {
                $within30d = true;
            }
            if (!$within30d) {
                continue;
            }

            $key = strtolower(trim((string)$sr['voucher']));
            $hist = isset($store['records'][$key]) ? $store['records'][$key] : null;
            $removedTs = ($hist && !empty($hist['removed_at_ts'])) ? (int)$hist['removed_at_ts'] : 0;
            if ($removedTs <= 0 && $expStamp !== false && $expStamp <= $now) {
                $removedTs = $expStamp;
            } elseif ($removedTs <= 0 && $stamp !== false) {
                $removedTs = $stamp;
            }

            $sr['_timestamp'] = ($stamp !== false) ? $stamp : $removedTs;
            $sr['removed'] = true;
            $sr['removed_at_ts'] = $removedTs;
            $sr['removed_at'] = ($removedTs > 0) ? date('Y-m-d H:i:s', $removedTs) : '';
            $sr['removed_reason'] = ($hist && !empty($hist['removed_reason'])) ? $hist['removed_reason'] : 'expired_auto_removed';
            $sr['last_disconnect_ts'] = ($hist && !empty($hist['last_disconnect_ts'])) ? (int)$hist['last_disconnect_ts'] : 0;
            $sr['last_disconnect_at'] = ($hist && !empty($hist['last_disconnect_at'])) ? (string)$hist['last_disconnect_at'] : '';
            $sr['last_disconnect_reason'] = ($hist && !empty($hist['last_disconnect_reason'])) ? (string)$hist['last_disconnect_reason'] : '';
            $sr['uptime'] = ($hist && !empty($hist['uptime'])) ? (string)$hist['uptime'] : '';
            $sr['bytes_in'] = ($hist && isset($hist['bytes_in'])) ? (int)$hist['bytes_in'] : 0;
            $sr['bytes_out'] = ($hist && isset($hist['bytes_out'])) ? (int)$hist['bytes_out'] : 0;
            $sr['password'] = ($hist && !empty($hist['password'])) ? (string)$hist['password'] : (string)$sr['voucher'];
            $sr['device_vendor'] = ($hist && !empty($hist['device_vendor'])) ? (string)$hist['device_vendor'] : '';
            $sr['device_model'] = ($hist && !empty($hist['device_model'])) ? (string)$hist['device_model'] : '';
            $sr['device_type'] = ($hist && !empty($hist['device_type'])) ? (string)$hist['device_type'] : '';
            $sr['device_hostname'] = ($hist && !empty($hist['device_hostname'])) ? (string)$hist['device_hostname'] : '';
            $sr['device_class_id'] = ($hist && !empty($hist['device_class_id'])) ? (string)$hist['device_class_id'] : '';
            $merged[] = $sr;
        }
    }

    // 2. Include matching records from 30-day backend JSON store (catches rem/ntf, manual deletes, & cleared scripts)
    foreach ($store['records'] as $key => $hist) {
        if (!is_array($hist) || empty($hist['voucher'])) {
            continue;
        }
        $vLow = strtolower(trim((string)$hist['voucher']));
        $pLow = isset($hist['password']) ? strtolower(trim((string)$hist['password'])) : '';
        $mNorm = isset($hist['mac']) ? str_replace('-', ':', strtolower(trim((string)$hist['mac']))) : '';
        $ipLow = isset($hist['ip']) ? strtolower(trim((string)$hist['ip'])) : '';

        $matches = ($vLow === $q)
            || ($pLow !== '' && $pLow === $q)
            || ($mNorm !== '' && $mNorm !== '00:00:00:00:00:00' && $mNorm === $qMacNorm)
            || ($ipLow !== '' && $ipLow === $q);

        if (!$matches) {
            continue;
        }

        // Skip if already represented by identical voucher entry from scripts, unless backend has extra fields
        $alreadyIdx = -1;
        foreach ($merged as $idx => $mItem) {
            if (strtolower(trim((string)$mItem['voucher'])) === $vLow) {
                $alreadyIdx = $idx;
                break;
            }
        }
        if ($alreadyIdx >= 0) {
            continue;
        }

        $removedTs = !empty($hist['removed_at_ts'])
            ? (int)$hist['removed_at_ts']
            : (!empty($hist['last_seen_ts']) ? (int)$hist['last_seen_ts'] : (int)(isset($hist['first_seen_ts']) ? $hist['first_seen_ts'] : 0));

        if ($removedTs > 0 && $removedTs < $cutoff) {
            continue;
        }

        $loginDisp = !empty($hist['login_datetime'])
            ? (string)$hist['login_datetime']
            : (!empty($hist['first_seen_ts']) ? date('Y-m-d H:i:s', (int)$hist['first_seen_ts']) : '-');

        $merged[] = array(
            'id'                     => 'backend_' . $key,
            'voucher'                => (string)$hist['voucher'],
            'password'               => !empty($hist['password']) ? (string)$hist['password'] : (string)$hist['voucher'],
            'login_date'             => isset($hist['login_date']) ? (string)$hist['login_date'] : '',
            'login_time'             => isset($hist['login_time']) ? (string)$hist['login_time'] : '',
            'login_datetime'         => $loginDisp,
            'price'                  => '',
            'ip'                     => isset($hist['ip']) ? (string)$hist['ip'] : '',
            'mac'                    => isset($hist['mac']) ? (string)$hist['mac'] : '',
            'validity'               => isset($hist['validity']) ? (string)$hist['validity'] : '',
            'profile'                => isset($hist['profile']) ? (string)$hist['profile'] : '',
            'comment'                => isset($hist['comment']) ? (string)$hist['comment'] : '',
            'has_expiry'             => !empty($hist['has_expiry']),
            'expiry_iso'             => isset($hist['expiry_iso']) ? (string)$hist['expiry_iso'] : '',
            'expiry_legacy'          => isset($hist['expiry_legacy']) ? (string)$hist['expiry_legacy'] : '',
            'expiry_display'         => !empty($hist['has_expiry']) ? ($hist['expiry_iso'] . ' (' . $hist['expiry_legacy'] . ')') : '',
            '_timestamp'             => $removedTs,
            'removed'                => true,
            'removed_at_ts'          => $removedTs,
            'removed_at'             => ($removedTs > 0) ? date('Y-m-d H:i:s', $removedTs) : '',
            'removed_reason'         => !empty($hist['removed_reason']) ? (string)$hist['removed_reason'] : 'removed_from_router',
            'last_disconnect_ts'     => !empty($hist['last_disconnect_ts']) ? (int)$hist['last_disconnect_ts'] : 0,
            'last_disconnect_at'     => isset($hist['last_disconnect_at']) ? (string)$hist['last_disconnect_at'] : '',
            'last_disconnect_reason' => isset($hist['last_disconnect_reason']) ? (string)$hist['last_disconnect_reason'] : '',
            'uptime'                 => isset($hist['uptime']) ? (string)$hist['uptime'] : '',
            'bytes_in'               => isset($hist['bytes_in']) ? (int)$hist['bytes_in'] : 0,
            'bytes_out'              => isset($hist['bytes_out']) ? (int)$hist['bytes_out'] : 0,
            'device_vendor'          => isset($hist['device_vendor']) ? (string)$hist['device_vendor'] : '',
            'device_model'           => isset($hist['device_model']) ? (string)$hist['device_model'] : '',
            'device_type'            => isset($hist['device_type']) ? (string)$hist['device_type'] : '',
            'device_hostname'        => isset($hist['device_hostname']) ? (string)$hist['device_hostname'] : '',
            'device_class_id'        => isset($hist['device_class_id']) ? (string)$hist['device_class_id'] : '',
        );
    }

    usort($merged, function ($a, $b) {
        $ta = !empty($a['removed_at_ts']) ? (int)$a['removed_at_ts'] : (isset($a['_timestamp']) ? (int)$a['_timestamp'] : 0);
        $tb = !empty($b['removed_at_ts']) ? (int)$b['removed_at_ts'] : (isset($b['_timestamp']) ? (int)$b['_timestamp'] : 0);
        return $ta <=> $tb;
    });

    return $merged;
}
?>
