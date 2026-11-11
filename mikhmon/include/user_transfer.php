<?php
require_once __DIR__ . '/ros_version.php';

/**
 * Convert RouterOS duration string (e.g. "1d02:03:04", "01:23:45", "2h30m10s", "1s") to seconds.
 */
function transferParseDurationSeconds($dur) {
    $dur = strtolower(trim((string)$dur));
    if ($dur === '' || $dur === '0' || $dur === '0s' || $dur === '00:00:00') {
        return 0;
    }
    return parseRouterOSValiditySeconds($dur);
}

/**
 * Format seconds into RouterOS duration string (e.g. "2d03:15:20" or "01:05:00").
 */
function transferFormatDurationSeconds($sec) {
    $sec = max(1, (int)$sec);
    $days = floor($sec / 86400);
    $rem = $sec % 86400;
    $hrs = str_pad((string)floor($rem / 3600), 2, '0', STR_PAD_LEFT);
    $mins = str_pad((string)floor(($rem % 3600) / 60), 2, '0', STR_PAD_LEFT);
    $secs = str_pad((string)($rem % 60), 2, '0', STR_PAD_LEFT);
    if ($days > 0) {
        return $days . 'd' . $hrs . ':' . $mins . ':' . $secs;
    }
    return $hrs . ':' . $mins . ':' . $secs;
}

/**
 * Extract validity string from a Hotspot profile's on-login script if present.
 */
function transferExtractProfileValidity($onLogin) {
    $onLogin = (string)$onLogin;
    if (preg_match('/:put\s*\(",[^,]*,[^,]*,([^,]+),/i', $onLogin, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Normalize and enrich a Hotspot user row for export or import across RouterOS 6 and 7.
 * Preserves username, password, profile, comment, MAC address, remaining uptime/data,
 * and auto-activation state (from user MAC, active session, or Hotspot cookie).
 */
function transferUser($row, $profile = '', $server = 'all', $context = array()) {
    if (!is_array($row) || !isset($row['name']) || !is_string($row['name'])) {
        throw new InvalidArgumentException('Missing name; export requires password access.');
    }
    $name = trim($row['name']);
    if ($name === '' || strlen($name) > 255) {
        throw new InvalidArgumentException('Invalid username.');
    }

    // Safely resolve password (supports ROS 6 & ROS 7 including voucher codes with empty password)
    $password = '';
    if (isset($row['password']) && is_string($row['password'])) {
        $password = $row['password'];
    } elseif (isset($row['password.']) && is_string($row['password.'])) {
        $password = $row['password.'];
    }
    $comment = isset($row['comment']) && is_string($row['comment']) ? trim($row['comment']) : '';

    // In voucher mode (vc-...), if password was left blank by ROS7, fallback to username
    if ($password === '' && (strpos($comment, 'vc-') === 0 || strpos($comment, ' vc-') !== false)) {
        $password = $name;
    }

    $resolvedProfile = ($profile !== '') ? $profile : (isset($row['profile']) && $row['profile'] !== '' ? (string)$row['profile'] : 'default');

    // Resolve MAC address from: 1) user record, 2) active session map, 3) hotspot cookie map, 4) ip-binding map
    $mac = '';
    if (isset($row['mac-address']) && is_string($row['mac-address']) && $row['mac-address'] !== '' && $row['mac-address'] !== '00:00:00:00:00:00') {
        $mac = strtoupper(str_replace('-', ':', trim($row['mac-address'])));
    } elseif (!empty($context['active_macs'][$name])) {
        $mac = strtoupper(str_replace('-', ':', trim($context['active_macs'][$name])));
    } elseif (!empty($context['cookie_macs'][$name])) {
        $mac = strtoupper(str_replace('-', ':', trim($context['cookie_macs'][$name])));
    } elseif (!empty($context['binding_macs'][$name])) {
        $mac = strtoupper(str_replace('-', ':', trim($context['binding_macs'][$name])));
    }

    // Keep the user's original comment 100% intact without adding any extra text or extra date.
    // If an earlier transfer accidentally prepended a duplicate date stamp, strip the duplicate.
    $comment = preg_replace('/^([a-zA-Z]{3}\/\d{2}\/\d{4}\s+\d{2}:\d{2}:\d{2})\s+(?=(?:[a-zA-Z]{3}\/\d{2}\/\d{4}|\d{4}-\d{2}-\d{2})\s+\d{2}:\d{2}:\d{2})/i', '', $comment);

    $cleanCommentForParse = preg_replace('/^exp:/i', '', $comment);
    $parsedExp = parseExpiryFromComment($cleanCommentForParse);
    $isActivated = false;
    $isDisabled = isset($row['disabled']) && ($row['disabled'] === 'true' || $row['disabled'] === true || $row['disabled'] === 'yes');
    $isExpired = $isDisabled;

    if (!empty($parsedExp['is_expiry'])) {
        $isActivated = true;
        $comment = $parsedExp['legacy_datetime'];
        $expTs = strtotime($parsedExp['iso_datetime']);
        if ($expTs !== false && $expTs <= time()) {
            $isExpired = true;
        }
    }

    // Check if user was active via uptime, active session, or cookie without modifying their comment
    $usedUptimeSec = isset($row['uptime']) ? transferParseDurationSeconds($row['uptime']) : 0;
    $limitUptimeRaw = isset($row['limit-uptime']) ? trim((string)$row['limit-uptime']) : '';
    if ($limitUptimeRaw === '1s' || $limitUptimeRaw === '00:00:01') {
        $isExpired = true;
    }

    if (!$isActivated && !$isExpired) {
        if ($usedUptimeSec > 0 || !empty($context['active_macs'][$name]) || !empty($context['cookie_macs'][$name])) {
            if (!empty($context['profile_validities'][$resolvedProfile])) {
                $profValSec = transferParseDurationSeconds($context['profile_validities'][$resolvedProfile]);
                if ($profValSec > 0 && $usedUptimeSec >= $profValSec) {
                    $isExpired = true;
                }
            }
            if (!$isExpired) {
                $isActivated = true;
            }
        }
    }

    if (!empty($row['auto_active']) && !$isExpired) {
        $isActivated = true;
    }

    $out = array(
        'name' => $name,
        'password' => $password,
        'profile' => $resolvedProfile,
        'comment' => $comment
    );

    if ($server !== '' && $server !== 'all') {
        $out['server'] = $server;
    }

    if ($mac !== '' && $mac !== '00:00:00:00:00:00') {
        $out['mac-address'] = $mac;
    }

    // Preserve or calculate remaining limit-uptime
    if ($limitUptimeRaw !== '' && !$isExpired) {
        $limitSec = transferParseDurationSeconds($limitUptimeRaw);
        if ($limitSec > 0) {
            $remSec = ($usedUptimeSec > 0 && $usedUptimeSec < $limitSec) ? ($limitSec - $usedUptimeSec) : $limitSec;
            $out['limit-uptime'] = transferFormatDurationSeconds($remSec);
        }
    } elseif ($isExpired) {
        $out['limit-uptime'] = '1s';
    }

    // Preserve or calculate remaining limit-bytes-total
    if (isset($row['limit-bytes-total']) && is_numeric($row['limit-bytes-total']) && (float)$row['limit-bytes-total'] > 0) {
        $limitBytes = (float)$row['limit-bytes-total'];
        $usedBytes = (float)($row['bytes-in'] ?? 0) + (float)($row['bytes-out'] ?? 0);
        $remBytes = ($usedBytes > 0 && $usedBytes < $limitBytes) ? ($limitBytes - $usedBytes) : $limitBytes;
        $out['limit-bytes-total'] = (string)max(1, (int)$remBytes);
    }

    // Attach metadata for JSON transfer auto-activation (stripped before raw /ip/hotspot/user/add)
    if (!empty($context['include_meta'])) {
        $out['auto_active'] = ($isActivated && !$isExpired && $mac !== '');
        if ($usedUptimeSec > 0) {
            $out['uptime_used'] = isset($row['uptime']) ? (string)$row['uptime'] : '00:00:00';
        }
    }

    return $out;
}

function transferRscQuote($value) {
    $value = (string)$value;
    $out = '"';
    for ($i = 0; $i < strlen($value); $i++) {
        $byte = ord($value[$i]);
        if ($byte < 32 || $byte > 126) { $out .= '\\' . strtoupper(bin2hex($value[$i])); }
        elseif ($value[$i] === '"' || $value[$i] === '\\' || $value[$i] === '$') { $out .= '\\' . $value[$i]; }
        else { $out .= $value[$i]; }
    }
    return $out . '"';
}

/**
 * Generate a RouterOS 6 & RouterOS 7 compatible .rsc script containing ONLY the 4 fields:
 * - Username (name), Password (password), Profile (profile), and Comment (comment)
 */
function transferRsc($users, $target) {
    $lines = array(
        '# NextGan Hotspot User Transfer Script - RouterOS ' . (int)$target . ' (Compatible with OS 6 & OS 7)',
        '# Includes: Username (name), Password (password), Profile (profile), and Comment (comment)',
        '/ip hotspot user'
    );
    foreach ($users as $row) {
        $user = transferUser($row, isset($row['profile']) ? $row['profile'] : 'default');
        $nameQ = transferRscQuote($user['name']);
        $passQ = transferRscQuote($user['password']);
        $profQ = transferRscQuote($user['profile']);
        $commQ = transferRscQuote($user['comment']);

        $lines[] = 'add name=' . $nameQ . ' password=' . $passQ . ' profile=' . $profQ . ' comment=' . $commQ;
    }
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Deploy or update the universal RouterOS 6 & 7 background scheduler on the destination router:
 * 1. Cleans up any legacy ngw-auto-active IP bindings so transferred users are NEVER stuck in IP Binding.
 * 2. Enables cookie & mac-cookie login on Hotspot server profiles so active sessions persist in Cookies.
 * 3. Runs every 20s to:
 *    a) Expire users whose comment timestamp has passed (sets limit-uptime=1s and removes from Active, Cookie, and Host).
 *    b) Auto-login already-used transferred vouchers (with MAC lock & active expiry comment) directly into
 *       Hotspot -> Active (/ip/hotspot/active/login) when their device connects to Hotspot Host, without IP Binding.
 */
function transferDeployAutoActiveMonitor($api) {
    $routerOsMajor = detectRouterOSMajorVersion($api);
    $schName = 'NGW-Transfer-AutoActive-Monitor';

    // 1. Clean up any legacy ngw-auto-active IP bindings immediately via API
    $oldBindings = @$api->comm('/ip/hotspot/ip-binding/print');
    if (is_array($oldBindings)) {
        foreach ($oldBindings as $bd) {
            $bc = isset($bd['comment']) ? (string)$bd['comment'] : '';
            if (!empty($bd['.id']) && strpos($bc, 'ngw-auto-active:') === 0) {
                @$api->comm('/ip/hotspot/ip-binding/remove', array('.id' => $bd['.id']));
            }
        }
    }

    // 2. Ensure cookie & mac-cookie are enabled on Hotspot server profiles so sessions create Hotspot Cookies
    $hsServerProfiles = @$api->comm('/ip/hotspot/profile/print');
    if (is_array($hsServerProfiles)) {
        foreach ($hsServerProfiles as $hsp) {
            if (empty($hsp['.id'])) continue;
            $loginBy = isset($hsp['login-by']) ? (string)$hsp['login-by'] : '';
            if ($loginBy !== '') {
                $methods = array_filter(array_map('trim', explode(',', $loginBy)));
                $changed = false;
                if (!in_array('cookie', $methods, true)) {
                    $methods[] = 'cookie';
                    $changed = true;
                }
                if (!in_array('mac-cookie', $methods, true)) {
                    $methods[] = 'mac-cookie';
                    $changed = true;
                }
                if ($changed) {
                    @$api->comm('/ip/hotspot/profile/set', array(
                        '.id' => $hsp['.id'],
                        'login-by' => implode(',', $methods)
                    ));
                }
            }
        }
    }

    // 3. Universal ROS 6 & ROS 7 scheduler script:
    //    - Removes any leftover ngw-auto-active ip-bindings
    //    - Enforces comment expiration timestamp across all Hotspot users (sets limit-uptime=1s, kicks Active/Cookie/Host)
    //    - Auto-logs in authorized transferred MACs from /ip/hotspot/host directly into /ip/hotspot/active
    $onEvent = ':local dateint do={ :local montharray ("jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec"); :if ([:pick $d 4 5] = "-") do={ :local yr [:pick $d 0 4]; :local mo [:pick $d 5 7]; :local dy [:pick $d 8 10]; :return [:tonum ("$yr$mo$dy")]; } else={ :local days [:pick $d 4 6]; :local month [:pick $d 0 3]; :local year [:pick $d 7 11]; :local monthint ([:find $montharray $month]); :if ([:len $monthint] > 0) do={ :local mnum ($monthint + 1); :if ($mnum < 10) do={ :return [:tonum ("$year" . "0" . "$mnum$days")]; } else={ :return [:tonum ("$year$mnum$days")]; }; } else={ :return 0; }; }; }; :local timeint do={ :local hours [:tonum [:pick $t 0 2]]; :local minutes [:tonum [:pick $t 3 5]]; :local seconds [:tonum [:pick $t 6 8]]; :return ($hours * 3600 + $minutes * 60 + $seconds); }; :local date [/system clock get date]; :local time [/system clock get time]; :local today [$dateint d=$date]; :local curtime [$timeint t=$time]; [ /ip hotspot ip-binding remove [find where comment~"ngw-auto-active:"] ]; :if ($today > 20200000) do={ :foreach i in=[/ip hotspot user find where disabled=no] do={ :local comment [/ip hotspot user get $i comment]; :if ([:len $comment] >= 19) do={ :local limit [/ip hotspot user get $i limit-uptime]; :if ($limit != "1s" and $limit != "00:00:01") do={ :local expd 0; :local expt 0; :if ([:pick $comment 4 5] = "-") do={ :set expd [$dateint d=[:pick $comment 0 10]]; :set expt [$timeint t=[:pick $comment 11 19]]; } else={ :if ([:pick $comment 3 4] = "/" and [:pick $comment 6 7] = "/") do={ :set expd [$dateint d=[:pick $comment 0 11]]; :set expt [$timeint t=[:pick $comment 12 20]]; }; }; :if ($expd > 20200000 and (($expd < $today) or ($expd = $today and $expt <= $curtime))) do={ :local uname [/ip hotspot user get $i name]; :local umac [/ip hotspot user get $i mac-address]; [ /ip hotspot user set limit-uptime=1s $i ]; [ /ip hotspot active remove [find where user=$uname] ]; [ /ip hotspot cookie remove [find where user=$uname] ]; :if ([:len $umac] > 0 and $umac != "00:00:00:00:00:00") do={ [ /ip hotspot host remove [find where mac-address=$umac] ]; }; }; }; }; }; :foreach h in=[/ip hotspot host find where authorized=no and bypassed=no] do={ :local hmac [/ip hotspot host get $h mac-address]; :local hip [/ip hotspot host get $h to-address]; :if ([:len $hip] = 0) do={ :set hip [/ip hotspot host get $h address]; }; :if ([:len $hmac] > 0 and [:len $hip] > 0) do={ :local uids [/ip hotspot user find where mac-address=$hmac and disabled=no]; :if ([:len $uids] > 0) do={ :local u [:pick $uids 0]; :local uname [/ip hotspot user get $u name]; :local upass [/ip hotspot user get $u password]; :local ulimit [/ip hotspot user get $u limit-uptime]; :local ucomm [/ip hotspot user get $u comment]; :local pfx [:pick $ucomm 0 3]; :if ($ulimit != "1s" and $ulimit != "00:00:01" and [:len $ucomm] > 0 and $pfx != "vc-" and $pfx != "up-" and [:pick $ucomm 0 4] != "exp:") do={ :if ([:len [/ip hotspot active find where user=$uname]] = 0) do={ [ /ip hotspot active login user=$uname password=$upass mac-address=$hmac ip=$hip ]; }; }; }; }; }; }';

    $existing = $api->comm('/system/scheduler/print', array('?name' => $schName));
    $params = array(
        'name' => $schName,
        'start-time' => '00:00:10',
        'interval' => '00:00:20',
        'on-event' => $onEvent,
        'disabled' => 'no',
        'comment' => 'Auto-Active Transferred Hotspot Users & Expiry Monitor (OS 6 & 7)'
    );
    if ($routerOsMajor === 7) {
        $clock = $api->comm('/system/clock/print');
        if (is_array($clock) && !empty($clock[0]['date'])) {
            $params['start-date'] = $clock[0]['date'];
        }
    }
    if (is_array($existing) && !empty($existing[0]['.id'])) {
        $params['.id'] = $existing[0]['.id'];
        @$api->comm('/system/scheduler/set', $params);
    } else {
        @$api->comm('/system/scheduler/add', $params);
    }
}

