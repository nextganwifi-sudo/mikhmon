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
 * Strictly check whether a Hotspot user comment contains a valid calendar expiry date.
 * Never mutates the original comment. Supports RouterOS 6 (mmm/dd/yyyy [HH:MM:SS]),
 * RouterOS 7 (yyyy-mm-dd [HH:MM:SS]), and numeric (mm/dd/yyyy [HH:MM:SS]) formats.
 * Unactivated batch comments (vc-..., up-...) without a real calendar date return false.
 */
function transferParseCalendarDateFromComment($comment) {
    $raw = trim((string)$comment);
    if ($raw === '' || preg_match('/^(?:vc|up)-/i', $raw)) {
        return array(
            'has_calendar_date' => false,
            'is_expired' => false,
            'iso_datetime' => '',
            'timestamp' => false
        );
    }

    $clean = preg_replace('/^exp:/i', '', $raw);
    $parsed = parseExpiryFromComment($clean);
    if (!empty($parsed['is_expiry']) && !empty($parsed['iso_datetime'])) {
        $ts = strtotime($parsed['iso_datetime']);
        if ($ts !== false && (int)date('Y', $ts) >= 2020) {
            return array(
                'has_calendar_date' => true,
                'is_expired' => ($ts <= time()),
                'iso_datetime' => $parsed['iso_datetime'],
                'timestamp' => $ts
            );
        }
    }

    // Support date-only ROS 7 format: YYYY-MM-DD (valid through 23:59:59 of that date)
    if (preg_match('/^(\d{4}-\d{1,2}-\d{1,2})(?:\s|$)/', $clean, $m)) {
        $d = parseRouterOSDate($m[1]);
        if (!empty($d['valid'])) {
            $isoDt = $d['iso'] . ' 23:59:59';
            $ts = strtotime($isoDt);
            if ($ts !== false && (int)date('Y', $ts) >= 2020) {
                return array(
                    'has_calendar_date' => true,
                    'is_expired' => ($ts <= time()),
                    'iso_datetime' => $isoDt,
                    'timestamp' => $ts
                );
            }
        }
    }

    // Support date-only ROS 6 format: mmm/dd/yyyy (valid through 23:59:59 of that date)
    if (preg_match('/^([a-zA-Z]{3}\/\d{1,2}\/\d{4})(?:\s|$)/', $clean, $m)) {
        $d = parseRouterOSDate($m[1]);
        if (!empty($d['valid'])) {
            $isoDt = $d['iso'] . ' 23:59:59';
            $ts = strtotime($isoDt);
            if ($ts !== false && (int)date('Y', $ts) >= 2020) {
                return array(
                    'has_calendar_date' => true,
                    'is_expired' => ($ts <= time()),
                    'iso_datetime' => $isoDt,
                    'timestamp' => $ts
                );
            }
        }
    }

    return array(
        'has_calendar_date' => false,
        'is_expired' => false,
        'iso_datetime' => '',
        'timestamp' => false
    );
}

/**
 * Normalize and enrich a Hotspot user row for export or import across RouterOS 6 and 7.
 * Preserves username, password, profile, VERBATIM comment (zero extra text), MAC address,
 * remaining uptime/data, and strict auto-activation eligibility (valid MAC + active calendar date).
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

    // Keep original comment 100% verbatim without appending, prepending, or reformatting anything.
    $comment = isset($row['comment']) && is_string($row['comment']) ? trim($row['comment']) : '';

    // In voucher mode (vc-...), if password was left blank by ROS7, fallback to username
    if ($password === '' && (strpos($comment, 'vc-') === 0 || strpos($comment, ' vc-') !== false)) {
        $password = $name;
    }

    $resolvedProfile = ($profile !== '') ? $profile : (isset($row['profile']) && $row['profile'] !== '' ? (string)$row['profile'] : 'default');

    // Resolve MAC address from: 1) user record, 2) active session map, 3) hotspot cookie map
    $mac = '';
    if (isset($row['mac-address']) && is_string($row['mac-address']) && $row['mac-address'] !== '' && $row['mac-address'] !== '00:00:00:00:00:00') {
        $mac = strtoupper(str_replace('-', ':', trim($row['mac-address'])));
    } elseif (!empty($context['active_macs'][$name])) {
        $mac = strtoupper(str_replace('-', ':', trim($context['active_macs'][$name])));
    } elseif (!empty($context['cookie_macs'][$name])) {
        $mac = strtoupper(str_replace('-', ':', trim($context['cookie_macs'][$name])));
    }

    $hasValidMac = ($mac !== '' && $mac !== '00:00:00:00:00:00');
    $calInfo = transferParseCalendarDateFromComment($comment);

    $isDisabled = isset($row['disabled']) && ($row['disabled'] === 'true' || $row['disabled'] === true || $row['disabled'] === 'yes');
    $usedUptimeSec = isset($row['uptime']) ? transferParseDurationSeconds($row['uptime']) : 0;
    $limitUptimeRaw = isset($row['limit-uptime']) ? trim((string)$row['limit-uptime']) : '';
    $isExpired = $isDisabled || ($limitUptimeRaw === '1s' || $limitUptimeRaw === '00:00:01');

    if (!empty($calInfo['has_calendar_date']) && !empty($calInfo['is_expired'])) {
        $isExpired = true;
    }

    // Strict eligibility rule: auto-activate ONLY users that have BOTH a valid MAC address
    // AND a valid non-expired calendar date in their comment.
    $isEligibleAutoActive = ($hasValidMac && !empty($calInfo['has_calendar_date']) && !$isExpired);

    $out = array(
        'name' => $name,
        'password' => $password,
        'profile' => $resolvedProfile,
        'comment' => $comment
    );

    if ($server !== '' && $server !== 'all') {
        $out['server'] = $server;
    }

    if ($hasValidMac) {
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
        $out['auto_active'] = $isEligibleAutoActive;
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
 * Build the universal RouterOS 6 & 7 0-second DHCP Server lease-script block.
 * Runs instantly when a device receives a DHCP lease ($leaseBound=1) BEFORE the OS sends
 * its captive portal probe. Strictly checks:
 *   1. User exists in /ip/hotspot/user with matching mac-address & disabled=no
 *   2. limit-uptime is not 1s / 00:00:01
 *   3. comment starts with a valid calendar date (mmm/dd/yyyy or yyyy-mm-dd) that has not expired
 * Logs the device straight into /ip/hotspot/active (creating native mac-cookie, zero IP Binding).
 */
function transferBuildDhcpLeaseScriptBlock() {
    $body = ':if ($leaseBound = 1) do={ '
        . ':do { '
        . ':local hmac $leaseActMAC; '
        . ':local hip $leaseActIP; '
        . ':if ([:len $hmac] > 0 and [:len $hip] > 0) do={ '
        . ':local uids [/ip hotspot user find where mac-address=$hmac and disabled=no]; '
        . ':if ([:len $uids] > 0) do={ '
        . ':local mMap {"jan"="01";"feb"="02";"mar"="03";"apr"="04";"may"="05";"jun"="06";"jul"="07";"aug"="08";"sep"="09";"oct"="10";"nov"="11";"dec"="12";"Jan"="01";"Feb"="02";"Mar"="03";"Apr"="04";"May"="05";"Jun"="06";"Jul"="07";"Aug"="08";"Sep"="09";"Oct"="10";"Nov"="11";"Dec"="12";"JAN"="01";"FEB"="02";"MAR"="03";"APR"="04";"MAY"="05";"JUN"="06";"JUL"="07";"AUG"="08";"SEP"="09";"OCT"="10";"NOV"="11";"DEC"="12"}; '
        . ':local dateint do={ :if ([:len $d] >= 10 and [:pick $d 4 5] = "-" and [:pick $d 7 8] = "-") do={ :return [:tonum ([:pick $d 0 4] . [:pick $d 5 7] . [:pick $d 8 10])]; }; :if ([:len $d] >= 11 and [:pick $d 3 4] = "/" and [:pick $d 6 7] = "/") do={ :local mm ($m->[:pick $d 0 3]); :if ([:len $mm] = 2) do={ :return [:tonum ([:pick $d 7 11] . $mm . [:pick $d 4 6])]; }; }; :return 0; }; '
        . ':local timeint do={ :if ([:len $t] >= 8 and [:pick $t 2 3] = ":" and [:pick $t 5 6] = ":") do={ :return ([:tonum [:pick $t 0 2]] * 3600 + [:tonum [:pick $t 3 5]] * 60 + [:tonum [:pick $t 6 8]]); }; :return 86399; }; '
        . ':local cdate [/system clock get date]; '
        . ':local ctime [/system clock get time]; '
        . ':local today [$dateint d=$cdate m=$mMap]; '
        . ':local curtime [$timeint t=$ctime]; '
        . ':if ($today > 20200000) do={ '
        . ':local u [:pick $uids 0]; '
        . ':local uname [/ip hotspot user get $u name]; '
        . ':local upass [/ip hotspot user get $u password]; '
        . ':local ulimit [/ip hotspot user get $u limit-uptime]; '
        . ':local ucomm [/ip hotspot user get $u comment]; '
        . ':if ($ulimit != "1s" and $ulimit != "00:00:01" and [:len $ucomm] >= 10) do={ '
        . ':local expd 0; :local expt 86399; '
        . ':if ([:pick $ucomm 4 5] = "-" and [:pick $ucomm 7 8] = "-") do={ :set expd [$dateint d=[:pick $ucomm 0 10] m=$mMap]; :if ([:len $ucomm] >= 19) do={ :set expt [$timeint t=[:pick $ucomm 11 19]]; }; } else={ :if ([:len $ucomm] >= 11 and [:pick $ucomm 3 4] = "/" and [:pick $ucomm 6 7] = "/") do={ :set expd [$dateint d=[:pick $ucomm 0 11] m=$mMap]; :if ([:len $ucomm] >= 20) do={ :set expt [$timeint t=[:pick $ucomm 12 20]]; }; }; }; '
        . ':if ($expd > 20200000 and (($expd > $today) or ($expd = $today and $expt > $curtime))) do={ '
        . ':local actIds [/ip hotspot active find where user=$uname]; '
        . ':if ([:len $actIds] = 0) do={ '
        . ':do { /ip hotspot active login user=$uname password=$upass mac-address=$hmac ip=$hip; } on-error={}; '
        . '}; }; }; }; }; }; } on-error={}; };';

    return "# BEGIN NGW-TRANSFER-AUTOACTIVE\r\n" . $body . "\r\n# END NGW-TRANSFER-AUTOACTIVE";
}

/**
 * Safely merge the NGW 0-second auto-active block into an existing DHCP server lease-script
 * without disturbing any custom scripts written by the router administrator.
 */
function transferMergeDhcpLeaseScript($existingScript, $block = null) {
    if ($block === null) {
        $block = transferBuildDhcpLeaseScriptBlock();
    }
    $existing = (string)$existingScript;
    $pattern = '/# BEGIN NGW-TRANSFER-AUTOACTIVE[\s\S]*?# END NGW-TRANSFER-AUTOACTIVE/m';
    if (preg_match($pattern, $existing)) {
        $merged = preg_replace($pattern, str_replace(array('\\', '$'), array('\\\\', '\\$'), $block), $existing, 1);
        return trim((string)$merged);
    }
    $trimmed = trim($existing);
    if ($trimmed === '') {
        return $block;
    }
    return $trimmed . "\r\n" . $block;
}

/**
 * Build the universal RouterOS 6 & 7 lightweight 5-second fallback & expiry monitor script.
 * 1. Cleans up any legacy ngw-auto-active IP bindings.
 * 2. Expires users whose comment calendar date has passed (sets limit-uptime=1s, kicks Active/Cookie/Host).
 * 3. Fallback auto-login for unauthorized /ip/hotspot/host devices (e.g. static IP or already-connected
 *    clients) ONLY if the user has a matching MAC address AND a valid future calendar date in comment.
 */
function transferBuildFallbackMonitorScript() {
    return ':local mMap {"jan"="01";"feb"="02";"mar"="03";"apr"="04";"may"="05";"jun"="06";"jul"="07";"aug"="08";"sep"="09";"oct"="10";"nov"="11";"dec"="12";"Jan"="01";"Feb"="02";"Mar"="03";"Apr"="04";"May"="05";"Jun"="06";"Jul"="07";"Aug"="08";"Sep"="09";"Oct"="10";"Nov"="11";"Dec"="12";"JAN"="01";"FEB"="02";"MAR"="03";"APR"="04";"MAY"="05";"JUN"="06";"JUL"="07";"AUG"="08";"SEP"="09";"OCT"="10";"NOV"="11";"DEC"="12"}; '
        . ':local dateint do={ :if ([:len $d] >= 10 and [:pick $d 4 5] = "-" and [:pick $d 7 8] = "-") do={ :return [:tonum ([:pick $d 0 4] . [:pick $d 5 7] . [:pick $d 8 10])]; }; :if ([:len $d] >= 11 and [:pick $d 3 4] = "/" and [:pick $d 6 7] = "/") do={ :local mm ($m->[:pick $d 0 3]); :if ([:len $mm] = 2) do={ :return [:tonum ([:pick $d 7 11] . $mm . [:pick $d 4 6])]; }; }; :return 0; }; '
        . ':local timeint do={ :if ([:len $t] >= 8 and [:pick $t 2 3] = ":" and [:pick $t 5 6] = ":") do={ :return ([:tonum [:pick $t 0 2]] * 3600 + [:tonum [:pick $t 3 5]] * 60 + [:tonum [:pick $t 6 8]]); }; :return 86399; }; '
        . ':local date [/system clock get date]; :local time [/system clock get time]; '
        . ':local today [$dateint d=$date m=$mMap]; :local curtime [$timeint t=$time]; '
        . ':do { /ip hotspot ip-binding remove [find where comment~"ngw-auto-active:"]; } on-error={}; '
        . ':if ($today > 20200000) do={ '
        . ':foreach i in=[/ip hotspot user find where disabled=no] do={ '
        . ':local comment [/ip hotspot user get $i comment]; '
        . ':if ([:len $comment] >= 10) do={ '
        . ':local limit [/ip hotspot user get $i limit-uptime]; '
        . ':if ($limit != "1s" and $limit != "00:00:01") do={ '
        . ':local expd 0; :local expt 86399; '
        . ':if ([:pick $comment 4 5] = "-" and [:pick $comment 7 8] = "-") do={ :set expd [$dateint d=[:pick $comment 0 10] m=$mMap]; :if ([:len $comment] >= 19) do={ :set expt [$timeint t=[:pick $comment 11 19]]; }; } else={ :if ([:len $comment] >= 11 and [:pick $comment 3 4] = "/" and [:pick $comment 6 7] = "/") do={ :set expd [$dateint d=[:pick $comment 0 11] m=$mMap]; :if ([:len $comment] >= 20) do={ :set expt [$timeint t=[:pick $comment 12 20]]; }; }; }; '
        . ':if ($expd > 20200000 and (($expd < $today) or ($expd = $today and $expt <= $curtime))) do={ '
        . ':local uname [/ip hotspot user get $i name]; :local umac [/ip hotspot user get $i mac-address]; '
        . ':do { /ip hotspot user set limit-uptime=1s $i; } on-error={}; '
        . ':do { /ip hotspot active remove [find where user=$uname]; } on-error={}; '
        . ':do { /ip hotspot cookie remove [find where user=$uname]; } on-error={}; '
        . ':if ([:len $umac] > 0 and $umac != "00:00:00:00:00:00") do={ :do { /ip hotspot host remove [find where mac-address=$umac]; } on-error={}; }; '
        . '}; }; }; }; '
        . ':foreach h in=[/ip hotspot host find where authorized=no and bypassed=no] do={ '
        . ':local hmac [/ip hotspot host get $h mac-address]; '
        . ':local hip [/ip hotspot host get $h to-address]; '
        . ':if ([:len $hip] = 0) do={ :set hip [/ip hotspot host get $h address]; }; '
        . ':if ([:len $hmac] > 0 and [:len $hip] > 0) do={ '
        . ':local uids [/ip hotspot user find where mac-address=$hmac and disabled=no]; '
        . ':if ([:len $uids] > 0) do={ '
        . ':local u [:pick $uids 0]; '
        . ':local uname [/ip hotspot user get $u name]; '
        . ':local upass [/ip hotspot user get $u password]; '
        . ':local ulimit [/ip hotspot user get $u limit-uptime]; '
        . ':local ucomm [/ip hotspot user get $u comment]; '
        . ':if ($ulimit != "1s" and $ulimit != "00:00:01" and [:len $ucomm] >= 10) do={ '
        . ':local expd 0; :local expt 86399; '
        . ':if ([:pick $ucomm 4 5] = "-" and [:pick $ucomm 7 8] = "-") do={ :set expd [$dateint d=[:pick $ucomm 0 10] m=$mMap]; :if ([:len $ucomm] >= 19) do={ :set expt [$timeint t=[:pick $ucomm 11 19]]; }; } else={ :if ([:len $ucomm] >= 11 and [:pick $ucomm 3 4] = "/" and [:pick $ucomm 6 7] = "/") do={ :set expd [$dateint d=[:pick $ucomm 0 11] m=$mMap]; :if ([:len $ucomm] >= 20) do={ :set expt [$timeint t=[:pick $ucomm 12 20]]; }; }; }; '
        . ':if ($expd > 20200000 and (($expd > $today) or ($expd = $today and $expt > $curtime))) do={ '
        . ':if ([:len [/ip hotspot active find where user=$uname]] = 0) do={ '
        . ':do { /ip hotspot active login user=$uname password=$upass mac-address=$hmac ip=$hip; } on-error={}; '
        . '}; }; }; }; }; }; }';
}

/**
 * Deploy or update the universal RouterOS 6 & 7 Zero-Captive-Portal Auto-Active system:
 * 1. Cleans up any legacy ngw-auto-active IP bindings so users NEVER live in IP Binding.
 * 2. Enables cookie & mac-cookie login on Hotspot server profiles for native session persistence.
 * 3. Installs a non-destructive 0-second DHCP Server lease-script on Hotspot DHCP servers so
 *    eligible devices (valid MAC + active calendar date in comment) are logged into Hotspot Active
 *    at DHCP bind time (~0.1s) BEFORE their OS sends a captive portal probe.
 * 4. Deploys a lightweight 5-second fallback & expiry scheduler for static IP / pre-connected hosts.
 */
function transferDeployAutoActiveMonitor($api) {
    $routerOsMajor = detectRouterOSMajorVersion($api);
    $schName = 'NGW-Transfer-AutoActive-Monitor';
    $stats = array(
        'dhcp_hooked' => 0,
        'profiles_updated' => 0,
        'scheduler_deployed' => false
    );

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
                    $stats['profiles_updated']++;
                }
            }
        }
    }

    // 3. Install non-destructive 0-second DHCP Server lease-script on Hotspot-facing DHCP servers
    $hsInterfaces = array();
    $hsServers = @$api->comm('/ip/hotspot/print');
    if (is_array($hsServers)) {
        foreach ($hsServers as $hs) {
            if (!empty($hs['interface'])) {
                $hsInterfaces[(string)$hs['interface']] = true;
            }
        }
    }

    $dhcpServers = @$api->comm('/ip/dhcp-server/print');
    if (is_array($dhcpServers)) {
        $leaseBlock = transferBuildDhcpLeaseScriptBlock();
        $targets = array();
        foreach ($dhcpServers as $ds) {
            if (empty($ds['.id'])) continue;
            $disabled = isset($ds['disabled']) && ($ds['disabled'] === 'true' || $ds['disabled'] === true || $ds['disabled'] === 'yes');
            if ($disabled) continue;
            $iface = isset($ds['interface']) ? (string)$ds['interface'] : '';
            if (empty($hsInterfaces) || isset($hsInterfaces[$iface])) {
                $targets[] = $ds;
            }
        }
        // Fallback: if bridge/VLAN interface names didn't match directly, hook all active DHCP servers
        if (empty($targets)) {
            foreach ($dhcpServers as $ds) {
                if (empty($ds['.id'])) continue;
                $disabled = isset($ds['disabled']) && ($ds['disabled'] === 'true' || $ds['disabled'] === true || $ds['disabled'] === 'yes');
                if (!$disabled) {
                    $targets[] = $ds;
                }
            }
        }
        foreach ($targets as $ds) {
            $existingLeaseScript = isset($ds['lease-script']) ? (string)$ds['lease-script'] : '';
            $mergedLeaseScript = transferMergeDhcpLeaseScript($existingLeaseScript, $leaseBlock);
            $res = @$api->comm('/ip/dhcp-server/set', array(
                '.id' => $ds['.id'],
                'lease-script' => $mergedLeaseScript
            ));
            if (!is_array($res) || (!isset($res['!trap']) && !isset($res['!fatal']))) {
                $stats['dhcp_hooked']++;
            }
        }
    }

    // 4. Universal ROS 6 & ROS 7 5-second fallback & expiry scheduler
    $onEvent = transferBuildFallbackMonitorScript();
    $existing = $api->comm('/system/scheduler/print', array('?name' => $schName));
    $params = array(
        'name' => $schName,
        'start-time' => '00:00:05',
        'interval' => '00:00:05',
        'on-event' => $onEvent,
        'disabled' => 'no',
        'comment' => 'Zero-Portal Transferred Users (MAC + Calendar Date) Fallback & Expiry Monitor (OS 6 & 7)'
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
    $stats['scheduler_deployed'] = true;

    return $stats;
}


