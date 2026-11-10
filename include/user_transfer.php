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

    // Normalize comment expiry date to universal Mikhmon format (mmm/dd/yyyy HH:MM:SS)
    // so both RouterOS 6 and RouterOS 7 background schedulers recognize it.
    $cleanCommentForParse = preg_replace('/^exp:/i', '', $comment);
    $parsedExp = parseExpiryFromComment($cleanCommentForParse);
    $isActivated = false;
    $isDisabled = isset($row['disabled']) && ($row['disabled'] === 'true' || $row['disabled'] === true || $row['disabled'] === 'yes');
    $isExpired = $isDisabled;

    if (!empty($parsedExp['is_expiry'])) {
        $isActivated = true;
        $comment = $parsedExp['legacy_datetime'] . ($parsedExp['note'] !== '' ? ' ' . $parsedExp['note'] : '');
        $expTs = strtotime($parsedExp['iso_datetime']);
        if ($expTs !== false && $expTs <= time()) {
            $isExpired = true;
        }
    }

    // Check if user was active via uptime, active session, or cookie even without a comment expiry stamp
    $usedUptimeSec = isset($row['uptime']) ? transferParseDurationSeconds($row['uptime']) : 0;
    $limitUptimeRaw = isset($row['limit-uptime']) ? trim((string)$row['limit-uptime']) : '';
    if ($limitUptimeRaw === '1s' || $limitUptimeRaw === '00:00:01') {
        $isExpired = true;
    }

    if (!$isActivated && !$isExpired) {
        if ($usedUptimeSec > 0 || !empty($context['active_macs'][$name]) || !empty($context['cookie_macs'][$name])) {
            $remValiditySec = 0;
            if (!empty($context['cookie_expires'][$name])) {
                $remValiditySec = transferParseDurationSeconds($context['cookie_expires'][$name]);
            } elseif (!empty($context['profile_validities'][$resolvedProfile])) {
                $profValSec = transferParseDurationSeconds($context['profile_validities'][$resolvedProfile]);
                if ($profValSec > 0) {
                    if ($usedUptimeSec >= $profValSec) {
                        $isExpired = true;
                    } else {
                        $remValiditySec = $profValSec - $usedUptimeSec;
                    }
                }
            }
            if (!$isExpired) {
                $isActivated = true;
                if ($remValiditySec > 0) {
                    $expTs = time() + $remValiditySec;
                    $legExp = strtolower(date('M/d/Y', $expTs)) . ' ' . date('H:i:s', $expTs);
                    $comment = $legExp . ($comment !== '' ? ' ' . $comment : '');
                }
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
 * Generate a RouterOS 6 & RouterOS 7 compatible .rsc script containing:
 * - Username (name), Password (password), Profile (profile), and Comment (comment)
 * - MAC Address & Remaining Uptime if present
 * - Auto-Active IP Binding (bypassed) for already-activated users with known MAC addresses
 */
function transferRsc($users, $target) {
    $lines = array(
        '# NextGan Hotspot User Transfer Script - RouterOS ' . (int)$target . ' (Compatible with OS 6 & OS 7)',
        '# Includes: Username (name), Password (password), Profile (profile), Comment (comment), and Auto-Active MAC Bindings',
        '/ip hotspot user'
    );
    foreach ($users as $row) {
        $user = transferUser($row, isset($row['profile']) ? $row['profile'] : 'default');
        $nameQ = transferRscQuote($user['name']);
        $passQ = transferRscQuote($user['password']);
        $profQ = transferRscQuote($user['profile']);
        $commQ = transferRscQuote($user['comment']);

        $extraArgs = '';
        if (!empty($user['mac-address'])) {
            $extraArgs .= ' mac-address=' . transferRscQuote($user['mac-address']);
        }
        if (!empty($user['limit-uptime'])) {
            $extraArgs .= ' limit-uptime=' . transferRscQuote($user['limit-uptime']);
        }
        if (!empty($user['limit-bytes-total'])) {
            $extraArgs .= ' limit-bytes-total=' . transferRscQuote($user['limit-bytes-total']);
        }

        // If profile exists on destination router, use it; otherwise fallback to "default" so user is never lost
        $lines[] = ':if ([:len [/ip hotspot user find where name=' . $nameQ . ']] = 0) do={ :if ([:len [/ip hotspot user profile find where name=' . $profQ . ']] > 0) do={ /ip hotspot user add name=' . $nameQ . ' password=' . $passQ . ' profile=' . $profQ . ' comment=' . $commQ . $extraArgs . '; } else={ /ip hotspot user add name=' . $nameQ . ' password=' . $passQ . ' profile="default" comment=' . $commQ . $extraArgs . '; }; };';

        // If user was already active and has a MAC address, also add auto-active bypassed IP binding
        if (!empty($row['auto_active']) && !empty($user['mac-address'])) {
            $macQ = transferRscQuote($user['mac-address']);
            $bindCommQ = transferRscQuote('ngw-auto-active:' . $user['name'] . ' | ' . $user['comment']);
            $lines[] = ':if ([:len [/ip hotspot ip-binding find where mac-address=' . $macQ . ']] = 0) do={ /ip hotspot ip-binding add mac-address=' . $macQ . ' type=bypassed comment=' . $bindCommQ . '; };';
        }
    }
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Deploy or update the universal RouterOS 6 & 7 background scheduler on the destination router
 * so that auto-activated transferred users (and their bypassed IP bindings + hosts) are
 * automatically disconnected and cleaned up the moment their expiration timestamp arrives.
 */
function transferDeployAutoActiveMonitor($api) {
    $routerOsMajor = detectRouterOSMajorVersion($api);
    $schName = 'NGW-Transfer-AutoActive-Monitor';

    $onEvent = ':local dateint do={ :local montharray ("jan","feb","mar","apr","may","jun","jul","aug","sep","oct","nov","dec"); :if ([:pick $d 4 5] = "-") do={ :local yr [:pick $d 0 4]; :local mo [:pick $d 5 7]; :local dy [:pick $d 8 10]; :return [:tonum ("$yr$mo$dy")]; } else={ :local days [:pick $d 4 6]; :local month [:pick $d 0 3]; :local year [:pick $d 7 11]; :local monthint ([:find $montharray $month]); :if ([:len $monthint] > 0) do={ :local mnum ($monthint + 1); :if ($mnum < 10) do={ :return [:tonum ("$year" . "0" . "$mnum$days")]; } else={ :return [:tonum ("$year$mnum$days")]; }; } else={ :return 0; }; }; }; :local timeint do={ :local hours [:tonum [:pick $t 0 2]]; :local minutes [:tonum [:pick $t 3 5]]; :local seconds [:tonum [:pick $t 6 8]]; :return ($hours * 3600 + $minutes * 60 + $seconds); }; :local date [/system clock get date]; :local time [/system clock get time]; :local today [$dateint d=$date]; :local curtime [$timeint t=$time]; :if ($today > 20200000) do={ :foreach b in=[/ip hotspot ip-binding find where comment~"ngw-auto-active:"] do={ :local bmac [/ip hotspot ip-binding get $b mac-address]; :local bcomm [/ip hotspot ip-binding get $b comment]; :local pipePos [:find $bcomm "|"]; :if ([:typeof $pipePos] != "nil") do={ :local uname [:pick $bcomm 16 ($pipePos - 1)]; :local expPart [:pick $bcomm ($pipePos + 2) [:len $bcomm]]; :local expd 0; :local expt 0; :if ([:pick $expPart 4 5] = "-") do={ :set expd [$dateint d=[:pick $expPart 0 10]]; :set expt [$timeint t=[:pick $expPart 11 19]]; } else={ :if ([:pick $expPart 3 4] = "/" and [:pick $expPart 6 7] = "/") do={ :set expd [$dateint d=[:pick $expPart 0 11]]; :set expt [$timeint t=[:pick $expPart 12 20]]; }; }; :if ($expd > 20200000 and (($expd < $today) or ($expd = $today and $expt <= $curtime))) do={ [ /ip hotspot ip-binding remove $b ]; [ /ip hotspot active remove [find where user=$uname] ]; [ /ip hotspot cookie remove [find where user=$uname] ]; :if ([:len $bmac] > 0) do={ [ /ip hotspot host remove [find where mac-address=$bmac] ]; }; }; }; }; }';

    $existing = $api->comm('/system/scheduler/print', array('?name' => $schName));
    $params = array(
        'name' => $schName,
        'start-time' => '00:01:00',
        'interval' => '00:02:00',
        'on-event' => $onEvent,
        'disabled' => 'no',
        'comment' => 'Auto-Active Transferred Hotspot Users Expiry Monitor (OS 6 & 7)'
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
