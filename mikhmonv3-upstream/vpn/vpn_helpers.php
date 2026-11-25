<?php
/*
 * NextGan WiFi Solution — VPN Module Helper Library
 * Storage, Smart Static IP Allocator, Collision-Free NAT Port Allocator,
 * Lifecycle Sync, and RouterOS v6/v7 Script Escaping.
 */

if (!function_exists('ngwVpnText')) {
    function ngwVpnText($english, $bengali) {
        global $langid;
        return (isset($langid) && $langid === 'bn') ? $bengali : $english;
    }
}

if (!function_exists('ngwVpnCleanHost')) {
    function ngwVpnCleanHost($host) {
        $host = trim((string)$host);
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host);
        $host = preg_replace('#/.*$#', '', $host);
        if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
            $parts = explode(':', $host, 2);
            $host = $parts[0];
        }
        return trim($host);
    }
}

if (!function_exists('ngwVpnNormalizeRouter')) {
    function ngwVpnNormalizeRouter($vr) {
        if (!is_array($vr)) {
            return array();
        }
        $apiPort = isset($vr['port']) ? (int)$vr['port'] : 8728;
        if ($apiPort < 1 || $apiPort > 65535) {
            $apiPort = 8728;
        }
        $sstpPort = isset($vr['sstp_port']) ? (int)$vr['sstp_port'] : 443;
        if ($sstpPort < 1 || $sstpPort > 65535) {
            $sstpPort = 443;
        }
        $l2tpPort = isset($vr['l2tp_port']) ? (int)$vr['l2tp_port'] : 1701;
        if ($l2tpPort < 1 || $l2tpPort > 65535) {
            $l2tpPort = 1701;
        }
        $pptpPort = isset($vr['pptp_port']) ? (int)$vr['pptp_port'] : 1723;
        if ($pptpPort < 1 || $pptpPort > 65535) {
            $pptpPort = 1723;
        }
        $natStart = isset($vr['nat_start_port']) ? (int)$vr['nat_start_port'] : 8010;
        if ($natStart < 1024 || $natStart > 65500) {
            $natStart = 8010;
        }
        $winboxInt = isset($vr['winbox_int_port']) ? (int)$vr['winbox_int_port'] : 8291;
        if ($winboxInt < 1 || $winboxInt > 65535) {
            $winboxInt = 8291;
        }
        $apiInt = isset($vr['api_int_port']) ? (int)$vr['api_int_port'] : 8728;
        if ($apiInt < 1 || $apiInt > 65535) {
            $apiInt = 8728;
        }
        $webInt = isset($vr['web_int_port']) ? (int)$vr['web_int_port'] : 80;
        if ($webInt < 1 || $webInt > 65535) {
            $webInt = 80;
        }

        $vr['id'] = isset($vr['id']) ? (string)$vr['id'] : '';
        $vr['name'] = isset($vr['name']) ? (string)$vr['name'] : '';
        $vr['host'] = isset($vr['host']) ? ngwVpnCleanHost($vr['host']) : '';
        $vr['user'] = isset($vr['user']) ? (string)$vr['user'] : '';
        $vr['pass_enc'] = isset($vr['pass_enc']) ? (string)$vr['pass_enc'] : '';
        $vr['port'] = $apiPort;
        $vr['ros_ver'] = (isset($vr['ros_ver']) && (int)$vr['ros_ver'] === 6) ? 6 : 7;
        $vr['sstp_port'] = $sstpPort;
        $vr['l2tp_port'] = $l2tpPort;
        $vr['l2tp_ipsec_secret'] = isset($vr['l2tp_ipsec_secret']) ? (string)$vr['l2tp_ipsec_secret'] : '';
        $vr['pptp_port'] = $pptpPort;
        $vr['nat_start_port'] = $natStart;
        $vr['winbox_int_port'] = $winboxInt;
        $vr['api_int_port'] = $apiInt;
        $vr['web_int_port'] = $webInt;
        $vr['created_at'] = isset($vr['created_at']) ? (string)$vr['created_at'] : '';
        return $vr;
    }
}

if (!function_exists('ngwVpnCanAccessRouter')) {
    function ngwVpnCanAccessRouter($vr) {
        if (!is_array($vr)) {
            return false;
        }
        if (function_exists('isMikhmonAdminStore') && isMikhmonAdminStore()) {
            return true;
        }
        if (function_exists('isMikhmonSubAdmin') && isMikhmonSubAdmin()) {
            $adm = function_exists('getCurrentAdminRecord') ? getCurrentAdminRecord() : null;
            if (!is_array($adm)) {
                return false;
            }
            $myId = isset($adm['id']) ? (string)$adm['id'] : '';
            $myUser = isset($adm['username']) ? (string)$adm['username'] : '';
            if (!empty($vr['created_by_admin_id']) && (string)$vr['created_by_admin_id'] === $myId) {
                return true;
            }
            if (!empty($vr['created_by_admin_username']) && strcasecmp((string)$vr['created_by_admin_username'], $myUser) === 0) {
                return true;
            }
            return false;
        }
        return false;
    }
}

if (!function_exists('ngwVpnRoutersPath')) {
    function ngwVpnRoutersPath() {
        return __DIR__ . '/../include/vpn_routers.json';
    }
}

if (!function_exists('ngwVpnVoicePath')) {
    function ngwVpnVoicePath() {
        return __DIR__ . '/../include/voice_call_services.json';
    }
}

if (!function_exists('ngwVpnLoadRoutersRaw')) {
    function ngwVpnLoadRoutersRaw() {
        $path = ngwVpnRoutersPath();
        if (!is_file($path)) {
            return array();
        }
        $raw = @file_get_contents($path);
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return array();
        }
        $out = array();
        foreach ($decoded as $item) {
            if (is_array($item) && !empty($item['id'])) {
                $out[] = ngwVpnNormalizeRouter($item);
            }
        }
        return $out;
    }
}

if (!function_exists('ngwVpnLoadRouters')) {
    function ngwVpnLoadRouters() {
        $all = ngwVpnLoadRoutersRaw();
        if (function_exists('isMikhmonAdminStore') && isMikhmonAdminStore()) {
            return $all;
        }
        $filtered = array();
        foreach ($all as $vr) {
            if (ngwVpnCanAccessRouter($vr)) {
                $filtered[] = $vr;
            }
        }
        return $filtered;
    }
}

if (!function_exists('ngwVpnSaveRouters')) {
    function ngwVpnSaveRouters($routers) {
        $path = ngwVpnRoutersPath();
        $clean = array();
        foreach ($routers as $r) {
            if (is_array($r) && !empty($r['id'])) {
                $clean[] = ngwVpnNormalizeRouter($r);
            }
        }
        return @file_put_contents($path, json_encode(array_values($clean), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    }
}

if (!function_exists('ngwVpnVoiceInterfaceName')) {
    function ngwVpnVoiceInterfaceName() {
        return 'Voice-Call';
    }
}

if (!function_exists('ngwVpnVoiceAppPorts')) {
    function ngwVpnVoiceAppPorts() {
        return array(
            'whatsapp' => array(
                'label' => 'WhatsApp',
                'tcp' => '3478,4244,5222,5223,5228,5242,5288,5349,50318,59234',
                'udp' => '3478,34784,45395,50318,59234',
            ),
            'imo' => array(
                'label' => 'imo',
                'tcp' => '5222,5223,5228,5242,8443',
                'udp' => '3478,19302-19309,50000-50059',
            ),
            'messenger' => array(
                'label' => 'Messenger',
                'tcp' => '3478,5222,5228,5349,8883',
                'udp' => '3478,5349,19302-19309,34784,50000-50059',
            ),
        );
    }
}

if (!function_exists('ngwVpnVoicePresets')) {
    function ngwVpnVoicePresets() {
        $apps = ngwVpnVoiceAppPorts();
        return array(
            'all' => array(
                'label' => 'All (WA + imo + Messenger)',
                'tcp' => '3478,4244,5222,5223,5228,5242,5288,5349,8443,8883,50318,59234',
                'udp' => '3478,5349,19302-19309,34784,45395,50000-50059,50318,59234',
            ),
            'both' => array(
                'label' => 'WhatsApp + imo',
                'tcp' => '3478,4244,5222,5223,5228,5242,5288,5349,8443,50318,59234',
                'udp' => '3478,19302-19309,34784,45395,50000-50059,50318,59234',
            ),
            'whatsapp' => $apps['whatsapp'],
            'imo' => $apps['imo'],
            'messenger' => $apps['messenger'],
        );
    }
}

if (!function_exists('ngwVpnDefaultVoiceConfig')) {
    function ngwVpnDefaultVoiceConfig() {
        $presets = ngwVpnVoicePresets();
        return array(
            'tcp_ports' => $presets['all']['tcp'],
            'udp_ports' => $presets['all']['udp'],
            'users' => array(),
            'dismissed' => array(),
        );
    }
}

if (!function_exists('ngwVpnLoadVoiceConfig')) {
    function ngwVpnLoadVoiceConfig() {
        $defaults = ngwVpnDefaultVoiceConfig();
        $path = ngwVpnVoicePath();
        if (!is_file($path)) {
            return $defaults;
        }
        $raw = @file_get_contents($path);
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        // Backward compatibility if legacy file was a flat JSON array []
        if (array_key_exists('tcp_ports', $decoded) || array_key_exists('udp_ports', $decoded) || array_key_exists('users', $decoded)) {
            $tcp = isset($decoded['tcp_ports']) && trim((string)$decoded['tcp_ports']) !== ''
                ? trim((string)$decoded['tcp_ports'])
                : $defaults['tcp_ports'];
            $udp = isset($decoded['udp_ports']) && trim((string)$decoded['udp_ports']) !== ''
                ? trim((string)$decoded['udp_ports'])
                : $defaults['udp_ports'];
            // Upgrade previous defaults to authentic WhatsApp + imo + Messenger calling ports
            if ($tcp === '443,5222,5223,5228,4244,5242,50318,59234' || $tcp === '3478,4244,5222,5223,5228,5242,5288,5349,8443,50318,59234') {
                $tcp = $defaults['tcp_ports'];
            }
            if ($udp === '3478,45395,50318,59234' || $udp === '3478,19302-19309,34784,45395,50000-50059,50318,59234') {
                $udp = $defaults['udp_ports'];
            }
            $users = (isset($decoded['users']) && is_array($decoded['users'])) ? $decoded['users'] : array();
            $cleanUsers = array();
            foreach ($users as $u) {
                if (is_array($u) && !empty($u['vpn_id']) && !empty($u['username'])) {
                    $cleanUsers[] = array(
                        'vpn_id' => (string)$u['vpn_id'],
                        'username' => (string)$u['username'],
                        'added_at' => isset($u['added_at']) ? (string)$u['added_at'] : date('Y-m-d H:i:s'),
                        'source' => (isset($u['source']) && $u['source'] === 'detected') ? 'detected' : 'manual',
                        'last_seen' => isset($u['last_seen']) ? (string)$u['last_seen'] : '',
                        'last_flows' => isset($u['last_flows']) ? (int)$u['last_flows'] : 0,
                        'last_ports' => isset($u['last_ports']) ? (string)$u['last_ports'] : '',
                    );
                }
            }
            $dismissedRaw = (isset($decoded['dismissed']) && is_array($decoded['dismissed'])) ? $decoded['dismissed'] : array();
            $cleanDismissed = array();
            foreach ($dismissedRaw as $d) {
                if (is_array($d) && !empty($d['vpn_id']) && !empty($d['username'])) {
                    $cleanDismissed[] = array(
                        'vpn_id' => (string)$d['vpn_id'],
                        'username' => (string)$d['username'],
                        'dismissed_at' => isset($d['dismissed_at']) ? (string)$d['dismissed_at'] : date('Y-m-d H:i:s'),
                    );
                }
            }
            return array(
                'tcp_ports' => $tcp,
                'udp_ports' => $udp,
                'users' => $cleanUsers,
                'dismissed' => $cleanDismissed,
            );
        }
        return $defaults;
    }
}

if (!function_exists('ngwVpnSaveVoiceConfig')) {
    function ngwVpnSaveVoiceConfig($cfg) {
        $path = ngwVpnVoicePath();
        $payload = array(
            'tcp_ports' => isset($cfg['tcp_ports']) ? trim((string)$cfg['tcp_ports']) : ngwVpnDefaultVoiceConfig()['tcp_ports'],
            'udp_ports' => isset($cfg['udp_ports']) ? trim((string)$cfg['udp_ports']) : ngwVpnDefaultVoiceConfig()['udp_ports'],
            'users' => isset($cfg['users']) && is_array($cfg['users']) ? array_values($cfg['users']) : array(),
            'dismissed' => isset($cfg['dismissed']) && is_array($cfg['dismissed']) ? array_values($cfg['dismissed']) : array(),
        );
        return @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    }
}

if (!function_exists('ngwVpnGetVoiceUserRecord')) {
    function ngwVpnGetVoiceUserRecord($voiceCfg, $vpnId, $username) {
        if (!isset($voiceCfg['users']) || !is_array($voiceCfg['users'])) {
            return null;
        }
        foreach ($voiceCfg['users'] as $entry) {
            if (isset($entry['vpn_id'], $entry['username'])
                && (string)$entry['vpn_id'] === (string)$vpnId
                && strcasecmp((string)$entry['username'], (string)$username) === 0
            ) {
                return $entry;
            }
        }
        return null;
    }
}

if (!function_exists('ngwVpnIsVoiceUser')) {
    function ngwVpnIsVoiceUser($voiceCfg, $vpnId, $username) {
        return ngwVpnGetVoiceUserRecord($voiceCfg, $vpnId, $username) !== null;
    }
}

if (!function_exists('ngwVpnIsVoiceUserDismissed')) {
    function ngwVpnIsVoiceUserDismissed($voiceCfg, $vpnId, $username) {
        if (!isset($voiceCfg['dismissed']) || !is_array($voiceCfg['dismissed'])) {
            return false;
        }
        foreach ($voiceCfg['dismissed'] as $entry) {
            if (isset($entry['vpn_id'], $entry['username'])
                && (string)$entry['vpn_id'] === (string)$vpnId
                && strcasecmp((string)$entry['username'], (string)$username) === 0
            ) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('ngwVpnSetVoiceUser')) {
    function ngwVpnSetVoiceUser(&$voiceCfg, $vpnId, $username, $enabled, $trackDismiss = true) {
        if (!isset($voiceCfg['users']) || !is_array($voiceCfg['users'])) {
            $voiceCfg['users'] = array();
        }
        if (!isset($voiceCfg['dismissed']) || !is_array($voiceCfg['dismissed'])) {
            $voiceCfg['dismissed'] = array();
        }
        $existingRec = null;
        $filtered = array();
        foreach ($voiceCfg['users'] as $entry) {
            if (isset($entry['vpn_id'], $entry['username'])
                && (string)$entry['vpn_id'] === (string)$vpnId
                && strcasecmp((string)$entry['username'], (string)$username) === 0
            ) {
                $existingRec = $entry;
                continue;
            }
            $filtered[] = $entry;
        }
        // Always remove from dismissed list first
        $voiceCfg['dismissed'] = array_values(array_filter($voiceCfg['dismissed'], function($entry) use ($vpnId, $username) {
            return !(isset($entry['vpn_id'], $entry['username'])
                && (string)$entry['vpn_id'] === (string)$vpnId
                && strcasecmp((string)$entry['username'], (string)$username) === 0);
        }));

        if ($enabled) {
            $filtered[] = array(
                'vpn_id' => (string)$vpnId,
                'username' => (string)$username,
                'added_at' => ($existingRec && !empty($existingRec['added_at'])) ? (string)$existingRec['added_at'] : date('Y-m-d H:i:s'),
                'source' => 'manual',
                'last_seen' => ($existingRec && isset($existingRec['last_seen'])) ? (string)$existingRec['last_seen'] : '',
                'last_flows' => ($existingRec && isset($existingRec['last_flows'])) ? (int)$existingRec['last_flows'] : 0,
                'last_ports' => ($existingRec && isset($existingRec['last_ports'])) ? (string)$existingRec['last_ports'] : '',
            );
        } elseif ($trackDismiss) {
            $voiceCfg['dismissed'][] = array(
                'vpn_id' => (string)$vpnId,
                'username' => (string)$username,
                'dismissed_at' => date('Y-m-d H:i:s'),
            );
        }
        $voiceCfg['users'] = array_values($filtered);
    }
}

if (!function_exists('ngwVpnRenameVoiceUser')) {
    function ngwVpnRenameVoiceUser(&$voiceCfg, $vpnId, $oldName, $newName) {
        if (isset($voiceCfg['users']) && is_array($voiceCfg['users'])) {
            foreach ($voiceCfg['users'] as &$entry) {
                if (isset($entry['vpn_id'], $entry['username'])
                    && (string)$entry['vpn_id'] === (string)$vpnId
                    && strcasecmp((string)$entry['username'], (string)$oldName) === 0
                ) {
                    $entry['username'] = (string)$newName;
                }
            }
        }
        if (isset($voiceCfg['dismissed']) && is_array($voiceCfg['dismissed'])) {
            foreach ($voiceCfg['dismissed'] as &$entry) {
                if (isset($entry['vpn_id'], $entry['username'])
                    && (string)$entry['vpn_id'] === (string)$vpnId
                    && strcasecmp((string)$entry['username'], (string)$oldName) === 0
                ) {
                    $entry['username'] = (string)$newName;
                }
            }
        }
    }
}

if (!function_exists('ngwVpnPruneRouterVoiceUsers')) {
    function ngwVpnPruneRouterVoiceUsers(&$voiceCfg, $vpnId) {
        if (isset($voiceCfg['users']) && is_array($voiceCfg['users'])) {
            $voiceCfg['users'] = array_values(array_filter($voiceCfg['users'], function($entry) use ($vpnId) {
                return !isset($entry['vpn_id']) || (string)$entry['vpn_id'] !== (string)$vpnId;
            }));
        }
        if (isset($voiceCfg['dismissed']) && is_array($voiceCfg['dismissed'])) {
            $voiceCfg['dismissed'] = array_values(array_filter($voiceCfg['dismissed'], function($entry) use ($vpnId) {
                return !isset($entry['vpn_id']) || (string)$entry['vpn_id'] !== (string)$vpnId;
            }));
        }
    }
}

if (!function_exists('ngwVpnValidatePortList')) {
    /**
     * Validates and normalizes MikroTik dst-port expressions (e.g. "443,5222,3478-3481").
     */
    function ngwVpnValidatePortList($raw) {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return false;
        }
        $parts = preg_split('/\s*,\s*/', $raw);
        $validParts = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\d+$/', $part)) {
                $p = (int)$part;
                if ($p < 1 || $p > 65535) {
                    return false;
                }
                $validParts[] = (string)$p;
            } elseif (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                $p1 = (int)$m[1];
                $p2 = (int)$m[2];
                if ($p1 < 1 || $p2 > 65535 || $p1 > $p2) {
                    return false;
                }
                $validParts[] = $p1 . '-' . $p2;
            } else {
                return false;
            }
        }
        if (empty($validParts)) {
            return false;
        }
        return implode(',', $validParts);
    }
}

if (!function_exists('ngwVpnSafeInterfaceName')) {
    function ngwVpnSafeInterfaceName($username) {
        $name = trim((string)$username);
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if ($conv !== false) {
                $name = $conv;
            }
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $name = trim($name, '-_.');
        if ($name === '') {
            $name = 'vpn-client';
        }
        return substr($name, 0, 48);
    }
}

if (!function_exists('ngwVpnParseIpRange')) {
    /**
     * Converts a MikroTik pool range string ("10.10.20.2-10.10.20.254" or "10.10.20.0/24")
     * into an array of [startLong, endLong] pairs.
     */
    function ngwVpnParseIpRange($rangeStr) {
        $results = array();
        $tokens = preg_split('/\s*,\s*/', trim((string)$rangeStr));
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            if (strpos($tok, '-') !== false) {
                $pair = explode('-', $tok, 2);
                $s = ip2long(trim($pair[0]));
                $e = ip2long(trim($pair[1]));
                if ($s !== false && $e !== false) {
                    $sU = sprintf('%u', $s);
                    $eU = sprintf('%u', $e);
                    if ((float)$sU <= (float)$eU) {
                        $results[] = array((int)$sU, (int)$eU);
                    }
                }
            } elseif (strpos($tok, '/') !== false) {
                $pair = explode('/', $tok, 2);
                $ip = ip2long(trim($pair[0]));
                $maskBits = (int)trim($pair[1]);
                if ($ip !== false && $maskBits >= 16 && $maskBits <= 30) {
                    $mask = (~((1 << (32 - $maskBits)) - 1)) & 0xFFFFFFFF;
                    $net = ((int)sprintf('%u', $ip)) & $mask;
                    $bcast = $net | (~$mask & 0xFFFFFFFF);
                    if ($net + 1 <= $bcast - 1) {
                        $results[] = array($net + 1, $bcast - 1);
                    }
                }
            } elseif (filter_var($tok, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $u = (int)sprintf('%u', ip2long($tok));
                $results[] = array($u, $u);
            }
        }
        return $results;
    }
}

if (!function_exists('ngwVpnFindNextFreeIp')) {
    function ngwVpnFindNextFreeIp($ranges, $usedIpsMap) {
        foreach ($ranges as $r) {
            $start = (int)$r[0];
            $end = (int)$r[1];
            // Cap per-range scan for large subnets to stay instant
            $limit = min($end, $start + 2048);
            for ($cur = $start; $cur <= $limit; $cur++) {
                $ip = long2ip($cur);
                // Skip .0 and .255
                $lastOctet = $cur & 0xFF;
                if ($lastOctet === 0 || $lastOctet === 255) {
                    continue;
                }
                if (!isset($usedIpsMap[$ip])) {
                    return $ip;
                }
            }
        }
        return '';
    }
}

if (!function_exists('ngwVpnBuildProfileIpSuggestions')) {
    /**
     * Computes { profileName => nextFreeStaticIp } using /ppp/profile, /ip/pool, and /ppp/secret.
     */
    function ngwVpnBuildProfileIpSuggestions($profiles, $pools, $secrets) {
        $poolMap = array();
        if (is_array($pools)) {
            foreach ($pools as $pl) {
                if (!empty($pl['name']) && !empty($pl['ranges'])) {
                    $poolMap[(string)$pl['name']] = (string)$pl['ranges'];
                }
            }
        }

        $usedIps = array();
        if (is_array($secrets)) {
            foreach ($secrets as $sec) {
                $rem = isset($sec['remote-address']) ? trim((string)$sec['remote-address']) : '';
                if ($rem !== '' && filter_var($rem, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $usedIps[$rem] = true;
                }
                $loc = isset($sec['local-address']) ? trim((string)$sec['local-address']) : '';
                if ($loc !== '' && filter_var($loc, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $usedIps[$loc] = true;
                }
            }
        }

        $suggestions = array();
        if (is_array($profiles)) {
            foreach ($profiles as $pr) {
                $pName = isset($pr['name']) ? (string)$pr['name'] : '';
                if ($pName === '') {
                    continue;
                }
                $localAddr = isset($pr['local-address']) ? trim((string)$pr['local-address']) : '';
                $remoteRef = isset($pr['remote-address']) ? trim((string)$pr['remote-address']) : '';

                $profileUsed = $usedIps;
                if ($localAddr !== '' && filter_var($localAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $profileUsed[$localAddr] = true;
                }

                $ranges = array();
                if ($remoteRef !== '' && isset($poolMap[$remoteRef])) {
                    $ranges = ngwVpnParseIpRange($poolMap[$remoteRef]);
                } elseif ($remoteRef !== '') {
                    $ranges = ngwVpnParseIpRange($remoteRef);
                }

                // Fallback: derive /24 subnet from profile's local-address (e.g. 10.10.20.1 -> 10.10.20.2-254)
                if (empty($ranges) && $localAddr !== '' && filter_var($localAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $octets = explode('.', $localAddr);
                    if (count($octets) === 4) {
                        $prefix = $octets[0] . '.' . $octets[1] . '.' . $octets[2];
                        $ranges = ngwVpnParseIpRange($prefix . '.2-' . $prefix . '.254');
                    }
                }

                $nextIp = !empty($ranges) ? ngwVpnFindNextFreeIp($ranges, $profileUsed) : '';
                $suggestions[$pName] = array(
                    'next_ip' => $nextIp,
                    'local_address' => $localAddr,
                    'remote_pool' => $remoteRef,
                );
            }
        }
        return $suggestions;
    }
}

if (!function_exists('ngwVpnExtractUsedNatPorts')) {
    function ngwVpnExtractUsedNatPorts($natRules) {
        $used = array();
        if (!is_array($natRules)) {
            return $used;
        }
        foreach ($natRules as $rule) {
            $dstPort = isset($rule['dst-port']) ? trim((string)$rule['dst-port']) : '';
            if ($dstPort === '') {
                continue;
            }
            $tokens = preg_split('/\s*,\s*/', $dstPort);
            foreach ($tokens as $tok) {
                if (preg_match('/^\d+$/', $tok)) {
                    $used[(int)$tok] = true;
                } elseif (preg_match('/^(\d+)-(\d+)$/', $tok, $m)) {
                    $a = (int)$m[1];
                    $b = min((int)$m[2], $a + 500);
                    for ($p = $a; $p <= $b; $p++) {
                        $used[$p] = true;
                    }
                }
            }
        }
        return $used;
    }
}

if (!function_exists('ngwVpnAllocateFreePorts')) {
    function ngwVpnAllocateFreePorts($startPort, $count, $usedPortsMap) {
        $start = max(1024, min(65500, (int)$startPort));
        $allocated = array();
        $cur = $start;
        while (count($allocated) < $count && $cur <= 65535) {
            if (!isset($usedPortsMap[$cur])) {
                $allocated[] = $cur;
                $usedPortsMap[$cur] = true;
            }
            $cur++;
        }
        return $allocated;
    }
}

if (!function_exists('ngwVpnParseNatComment')) {
    /**
     * Parses our structured NAT rule comment "ngw-vpn:<username>:<service>".
     */
    function ngwVpnParseNatComment($comment) {
        $comment = trim((string)$comment);
        if (strpos($comment, 'ngw-vpn:') !== 0) {
            return null;
        }
        $parts = explode(':', $comment, 3);
        if (count($parts) !== 3) {
            return null;
        }
        return array(
            'username' => $parts[1],
            'service' => strtolower($parts[2]),
        );
    }
}

if (!function_exists('ngwVpnBuildNatComment')) {
    function ngwVpnBuildNatComment($username, $service) {
        return 'ngw-vpn:' . trim((string)$username) . ':' . strtolower(trim((string)$service));
    }
}

if (!function_exists('ngwVpnFindUserNatRules')) {
    /**
     * Finds NAT rules belonging to a specific VPN user:
     * 1) Managed rules tagged with "ngw-vpn:<username>:"
     * 2) Pre-existing manual dstnat rules targeting that user's unique Static IP ($staticIp)
     */
    function ngwVpnFindUserNatRules($natRules, $username, $staticIp = '') {
        $matched = array();
        $seenIds = array();
        if (!is_array($natRules)) {
            return $matched;
        }
        $cleanUser = trim((string)$username);
        $targetPrefix = $cleanUser !== '' ? ('ngw-vpn:' . $cleanUser . ':') : '';
        $cleanIp = trim((string)$staticIp);
        $hasValidIp = ($cleanIp !== '' && filter_var($cleanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);

        foreach ($natRules as $idx => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $rKey = !empty($rule['.id']) ? (string)$rule['.id'] : ('idx_' . $idx);
            if (isset($seenIds[$rKey])) {
                continue;
            }
            $comment = isset($rule['comment']) ? trim((string)$rule['comment']) : '';
            if ($targetPrefix !== '' && $comment !== '' && stripos($comment, $targetPrefix) === 0) {
                $matched[] = $rule;
                $seenIds[$rKey] = true;
                continue;
            }
            if ($hasValidIp) {
                $chain = strtolower(trim(isset($rule['chain']) ? (string)$rule['chain'] : ''));
                $action = strtolower(trim(isset($rule['action']) ? (string)$rule['action'] : ''));
                $toAddr = trim(isset($rule['to-addresses']) ? (string)$rule['to-addresses'] : '');
                if ($chain === 'dstnat' && in_array($action, array('dst-nat', 'netmap'), true) && $toAddr === $cleanIp) {
                    // Never hijack a rule explicitly tagged for a different managed VPN user
                    $parsed = ngwVpnParseNatComment($comment);
                    if ($parsed === null || ($cleanUser !== '' && strcasecmp($parsed['username'], $cleanUser) === 0)) {
                        $matched[] = $rule;
                        $seenIds[$rKey] = true;
                    }
                }
            }
        }
        return $matched;
    }
}

if (!function_exists('ngwVpnSyncUserNatState')) {
    function ngwVpnSyncUserNatState($API, $username, $disabledFlag, $staticIp = '') {
        $natRules = $API->comm('/ip/firewall/nat/print', array(
            '.proplist' => '.id,chain,action,to-addresses,comment',
        ));
        $matched = ngwVpnFindUserNatRules($natRules, $username, $staticIp);
        unset($natRules);
        $count = 0;
        foreach ($matched as $rule) {
            if (!empty($rule['.id'])) {
                $API->comm('/ip/firewall/nat/set', array(
                    '.id' => $rule['.id'],
                    'disabled' => $disabledFlag ? 'yes' : 'no',
                ));
                $count++;
            }
        }
        return $count;
    }
}

if (!function_exists('ngwVpnDeleteUserNatRules')) {
    function ngwVpnDeleteUserNatRules($API, $username, $staticIp = '') {
        $natRules = $API->comm('/ip/firewall/nat/print', array(
            '.proplist' => '.id,chain,action,to-addresses,comment',
        ));
        $matched = ngwVpnFindUserNatRules($natRules, $username, $staticIp);
        unset($natRules);
        $count = 0;
        foreach ($matched as $rule) {
            if (!empty($rule['.id'])) {
                $API->comm('/ip/firewall/nat/remove', array(
                    '.id' => $rule['.id'],
                ));
                $count++;
            }
        }
        return $count;
    }
}

if (!function_exists('ngwVpnUpdateUserNatMeta')) {
    function ngwVpnUpdateUserNatMeta($API, $oldUsername, $newUsername, $newStaticIp, $oldStaticIp = '') {
        $natRules = $API->comm('/ip/firewall/nat/print', array(
            '.proplist' => '.id,chain,action,to-addresses,comment',
        ));
        $matched = ngwVpnFindUserNatRules($natRules, $oldUsername, $oldStaticIp);
        unset($natRules);
        foreach ($matched as $rule) {
            if (empty($rule['.id'])) {
                continue;
            }
            $parsed = ngwVpnParseNatComment(isset($rule['comment']) ? $rule['comment'] : '');
            $update = array('.id' => $rule['.id']);
            if ($newStaticIp !== '' && filter_var($newStaticIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $update['to-addresses'] = $newStaticIp;
            }
            if ($parsed !== null && $newUsername !== '' && strcasecmp($oldUsername, $newUsername) !== 0) {
                $update['comment'] = ngwVpnBuildNatComment($newUsername, $parsed['service']);
            }
            if (count($update) > 1) {
                $API->comm('/ip/firewall/nat/set', $update);
            }
        }
    }
}

if (!function_exists('ngwVpnInferNatServiceFromPort')) {
    function ngwVpnInferNatServiceFromPort($toPort, $proto = 'tcp', $action = '') {
        $p = trim((string)$toPort);
        $pr = strtolower(trim((string)$proto));
        if ($p === '8291' || $p === '8292') {
            return 'winbox';
        }
        if ($p === '8728' || $p === '8727' || $p === '8729') {
            return 'api';
        }
        if ($p === '80' || $p === '443' || $p === '8080' || $p === '8081') {
            return 'web';
        }
        if ($p !== '') {
            return ($pr !== '' ? $pr : 'port') . ':' . $p;
        }
        return $action !== '' ? strtolower((string)$action) : 'nat';
    }
}

if (!function_exists('ngwVpnClassifyNatRule')) {
    /**
     * Classifies ANY RouterOS /ip/firewall/nat rule (both ngw-vpn managed and pre-existing manual rules)
     * strictly read-only without modifying the router.
     */
    function ngwVpnClassifyNatRule($rule, $ipToUserMap) {
        $chain = strtolower(trim(isset($rule['chain']) ? (string)$rule['chain'] : ''));
        $action = strtolower(trim(isset($rule['action']) ? (string)$rule['action'] : ''));
        $proto = strtolower(trim(isset($rule['protocol']) ? (string)$rule['protocol'] : ''));
        $dstPort = trim(isset($rule['dst-port']) ? (string)$rule['dst-port'] : '');
        $toAddr = trim(isset($rule['to-addresses']) ? (string)$rule['to-addresses'] : '');
        $toPort = trim(isset($rule['to-ports']) ? (string)$rule['to-ports'] : '');
        $srcAddr = trim(isset($rule['src-address']) ? (string)$rule['src-address'] : '');
        $comment = trim(isset($rule['comment']) ? (string)$rule['comment'] : '');

        $parsed = ngwVpnParseNatComment($comment);
        $isDstNat = ($chain === 'dstnat' && in_array($action, array('dst-nat', 'netmap', 'redirect'), true));

        $rule['_is_dstnat'] = $isDstNat;
        $rule['_is_managed'] = ($parsed !== null);
        $rule['_nat_chain'] = $chain;
        $rule['_nat_action'] = $action;
        $rule['_nat_comment'] = $comment;
        $rule['_vpn_matched'] = false;

        if ($parsed !== null) {
            $rule['_vpn_user'] = $parsed['username'];
            $rule['_vpn_service'] = $parsed['service'];
            $rule['_vpn_matched'] = true;
            return $rule;
        }

        if ($isDstNat) {
            if ($toAddr !== '' && isset($ipToUserMap[$toAddr])) {
                $rule['_vpn_user'] = $ipToUserMap[$toAddr];
                $rule['_vpn_matched'] = true;
            } elseif ($comment !== '') {
                $rule['_vpn_user'] = $comment;
            } elseif ($toAddr !== '') {
                $rule['_vpn_user'] = $toAddr;
            } else {
                $rule['_vpn_user'] = '—';
            }
            $rule['_vpn_service'] = ngwVpnInferNatServiceFromPort($toPort, $proto, $action);
        } else {
            if ($comment !== '') {
                $rule['_vpn_user'] = $comment;
            } elseif ($srcAddr !== '') {
                $rule['_vpn_user'] = $srcAddr;
            } else {
                $rule['_vpn_user'] = strtoupper($chain !== '' ? $chain : 'NAT');
            }
            $rule['_vpn_service'] = strtoupper($chain) . '/' . strtoupper($action !== '' ? $action : 'RULE');
        }
        return $rule;
    }
}

if (!function_exists('ngwVpnParsePortRules')) {
    function ngwVpnParsePortRules($expr) {
        $singles = array();
        $ranges = array();
        $parts = preg_split('/\s*,\s*/', trim((string)$expr));
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\d+$/', $part)) {
                $p = (int)$part;
                if ($p >= 1 && $p <= 65535) {
                    $singles[$p] = true;
                }
            } elseif (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                $a = (int)$m[1];
                $b = (int)$m[2];
                if ($a >= 1 && $b <= 65535 && $a <= $b) {
                    $ranges[] = array($a, $b);
                }
            }
        }
        return array('singles' => $singles, 'ranges' => $ranges);
    }
}

if (!function_exists('ngwVpnPortMatchesRules')) {
    function ngwVpnPortMatchesRules($port, $parsedRules, $excludeGenericWeb = true) {
        $p = (int)$port;
        if ($p < 1 || $p > 65535) {
            return false;
        }
        if ($excludeGenericWeb && ($p === 53 || $p === 80 || $p === 443)) {
            return false;
        }
        if (isset($parsedRules['singles'][$p])) {
            return true;
        }
        foreach ($parsedRules['ranges'] as $rng) {
            if ($p >= $rng[0] && $p <= $rng[1]) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('ngwVpnIsPublicIpv4')) {
    function ngwVpnIsPublicIpv4($ip) {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}

if (!function_exists('ngwVpnDetectVoiceCallTraffic')) {
    /**
     * Strictly READ-ONLY detection of live WhatsApp & imo Voice Call traffic per VPN user
     * using /ip/firewall/connection/print (works on both RouterOS v6 and v7).
     * Never modifies any router configuration.
     */
    function ngwVpnDetectVoiceCallTraffic($API, $pppSecrets, $pppActiveMap, $tcpExpr, $udpExpr) {
        $ipToUser = array();
        if (is_array($pppSecrets)) {
            foreach ($pppSecrets as $sec) {
                $rem = isset($sec['remote-address']) ? trim((string)$sec['remote-address']) : '';
                if ($rem !== '' && !empty($sec['name'])) {
                    $ipToUser[$rem] = (string)$sec['name'];
                }
            }
        }
        if (is_array($pppActiveMap)) {
            foreach ($pppActiveMap as $act) {
                $addr = isset($act['address']) ? trim((string)$act['address']) : '';
                if ($addr !== '' && !empty($act['name'])) {
                    $ipToUser[$addr] = (string)$act['name'];
                }
            }
        }
        if (empty($ipToUser)) {
            return array();
        }

        $conns = $API->comm('/ip/firewall/connection/print', array(
            '.proplist' => 'protocol,src-address,dst-address,dst-port',
        ));
        if (!is_array($conns) || empty($conns)) {
            return array();
        }

        $tcpRules = ngwVpnParsePortRules($tcpExpr);
        $udpRules = ngwVpnParsePortRules($udpExpr);
        $detected = array();

        foreach ($conns as $c) {
            if (!is_array($c)) {
                continue;
            }
            $srcRaw = isset($c['src-address']) ? (string)$c['src-address'] : '';
            if ($srcRaw === '') {
                continue;
            }
            $srcPos = strpos($srcRaw, ':');
            $srcIp = ($srcPos !== false) ? substr($srcRaw, 0, $srcPos) : $srcRaw;
            if (!isset($ipToUser[$srcIp])) {
                continue;
            }

            $proto = strtolower(isset($c['protocol']) ? (string)$c['protocol'] : '');
            if ($proto !== 'tcp' && $proto !== 'udp') {
                continue;
            }

            $dstRaw = isset($c['dst-address']) ? (string)$c['dst-address'] : '';
            $dstPos = strrpos($dstRaw, ':');
            $dstIp = ($dstPos !== false) ? substr($dstRaw, 0, $dstPos) : $dstRaw;
            if (!ngwVpnIsPublicIpv4($dstIp)) {
                continue;
            }

            $dstPort = isset($c['dst-port']) ? (int)$c['dst-port'] : 0;
            if ($dstPort === 0 && $dstPos !== false) {
                $dstPort = (int)substr($dstRaw, $dstPos + 1);
            }
            if ($dstPort < 1 || $dstPort > 65535) {
                continue;
            }

            $matched = ($proto === 'tcp')
                ? ngwVpnPortMatchesRules($dstPort, $tcpRules, true)
                : ngwVpnPortMatchesRules($dstPort, $udpRules, true);

            if (!$matched) {
                continue;
            }

            $uName = $ipToUser[$srcIp];
            $uKey = strtolower($uName);
            if (!isset($detected[$uKey])) {
                $detected[$uKey] = array(
                    'username' => $uName,
                    'ip' => $srcIp,
                    'tcp_flows' => 0,
                    'udp_flows' => 0,
                    'total_flows' => 0,
                    'ports' => array(),
                );
            }
            if ($proto === 'tcp') {
                $detected[$uKey]['tcp_flows']++;
            } else {
                $detected[$uKey]['udp_flows']++;
            }
            $detected[$uKey]['total_flows']++;
            $pTag = strtoupper($proto) . ':' . $dstPort;
            $detected[$uKey]['ports'][$pTag] = (isset($detected[$uKey]['ports'][$pTag]) ? $detected[$uKey]['ports'][$pTag] : 0) + 1;
        }
        unset($conns);

        foreach ($detected as $k => &$info) {
            arsort($info['ports']);
            $topKeys = array_keys(array_slice($info['ports'], 0, 5, true));
            $info['ports_summary'] = implode(', ', $topKeys);
        }
        unset($info);

        uasort($detected, function($a, $b) {
            if ($b['total_flows'] !== $a['total_flows']) {
                return $b['total_flows'] - $a['total_flows'];
            }
            return strcasecmp($a['username'], $b['username']);
        });

        return $detected;
    }
}

if (!function_exists('ngwVpnMergeDetectedVoiceUsers')) {
    /**
     * Merges live-detected Voice Call users into local voice_call_services.json config
     * (never touches the router). Respects dismissed users unless $includeDismissed is true.
     */
    function ngwVpnMergeDetectedVoiceUsers(&$voiceCfg, $vpnId, $detectedMap, $includeDismissed = false) {
        if (!isset($voiceCfg['users']) || !is_array($voiceCfg['users'])) {
            $voiceCfg['users'] = array();
        }
        if (!isset($voiceCfg['dismissed']) || !is_array($voiceCfg['dismissed'])) {
            $voiceCfg['dismissed'] = array();
        }
        $now = date('Y-m-d H:i:s');
        $changed = false;
        $addedCount = 0;

        foreach ($detectedMap as $uKey => $info) {
            $uName = $info['username'];
            if (!$includeDismissed && ngwVpnIsVoiceUserDismissed($voiceCfg, $vpnId, $uName)) {
                continue;
            }
            if ($includeDismissed) {
                $voiceCfg['dismissed'] = array_values(array_filter($voiceCfg['dismissed'], function($d) use ($vpnId, $uName) {
                    return !(isset($d['vpn_id'], $d['username'])
                        && (string)$d['vpn_id'] === (string)$vpnId
                        && strcasecmp((string)$d['username'], (string)$uName) === 0);
                }));
            }

            $foundIdx = -1;
            foreach ($voiceCfg['users'] as $idx => $entry) {
                if (isset($entry['vpn_id'], $entry['username'])
                    && (string)$entry['vpn_id'] === (string)$vpnId
                    && strcasecmp((string)$entry['username'], (string)$uName) === 0
                ) {
                    $foundIdx = $idx;
                    break;
                }
            }

            if ($foundIdx >= 0) {
                $voiceCfg['users'][$foundIdx]['last_seen'] = $now;
                $voiceCfg['users'][$foundIdx]['last_flows'] = (int)$info['total_flows'];
                $voiceCfg['users'][$foundIdx]['last_ports'] = (string)$info['ports_summary'];
                $changed = true;
            } else {
                $voiceCfg['users'][] = array(
                    'vpn_id' => (string)$vpnId,
                    'username' => (string)$uName,
                    'added_at' => $now,
                    'source' => 'detected',
                    'last_seen' => $now,
                    'last_flows' => (int)$info['total_flows'],
                    'last_ports' => (string)$info['ports_summary'],
                );
                $changed = true;
                $addedCount++;
            }
        }

        return array('changed' => $changed, 'added' => $addedCount);
    }
}
