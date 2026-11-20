<?php
/*
 * NextGan WiFi Solution — Centralized MikroTik VPN Module
 * Clean rebuild:
 *  - VPN Router Card List -> 3-Tab Router Dashboard (VPN Users, Port Forward, Voice Call)
 *  - PPP Secret CRUD (SSTP, L2TP, PPTP, Any) with Smart Static IP Suggestion
 *  - Automatic Collision-Free 3-Port NAT Forwarding (Winbox, API, Web) + Lifecycle Sync
 *  - Idempotent RouterOS v6 & v7 Client Auto-Connect Scripts (separate from Voice Call)
 *  - Opt-In WhatsApp Voice Call Bypass Scripts (ROS v6 & v7, editable TCP/UDP DST-Ports)
 */

require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
error_reporting(0);

if (!isset($_SESSION['mikhmon'])) {
    header('Location:../admin.php?id=login');
    exit;
}

include_once('./include/reseller_acl.php');
include_once('./include/vpn_api_normalize.php');
include_once('./include/config.php');
include_once('./lib/routeros_api.class.php');
include_once(__DIR__ . '/vpn_helpers.php');

if (!isMikhmonAdmin() || (!isMikhmonAdminStore() && !hasResellerPermission('manage_vpn'))) {
    echo "<div class='card pd-15'><h3 class='text-danger'><i class='fa fa-ban'></i> " . ngwVpnText('Access Denied', 'অ্যাক্সেস অনুমোদিত নয়') . "</h3></div>";
    exit;
}

if (empty($_SESSION['vpn_hub_csrf'])) {
    try {
        $_SESSION['vpn_hub_csrf'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['vpn_hub_csrf'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}
$vpnCsrfToken = (string)$_SESSION['vpn_hub_csrf'];
$vpnFlashMsg = isset($_SESSION['ngw_vpn_flash_msg']) ? (string)$_SESSION['ngw_vpn_flash_msg'] : '';
$vpnFlashErr = isset($_SESSION['ngw_vpn_flash_err']) ? (string)$_SESSION['ngw_vpn_flash_err'] : '';
$vpnNatResult = (isset($_SESSION['ngw_vpn_nat_result']) && is_array($_SESSION['ngw_vpn_nat_result'])) ? $_SESSION['ngw_vpn_nat_result'] : null;
$vpnCachedNatCounts = (isset($_SESSION['ngw_vpn_nat_counts']) && is_array($_SESSION['ngw_vpn_nat_counts'])) ? $_SESSION['ngw_vpn_nat_counts'] : array();
unset($_SESSION['ngw_vpn_flash_msg'], $_SESSION['ngw_vpn_flash_err'], $_SESSION['ngw_vpn_nat_result']);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$activeTheme = isset($theme) && $theme !== '' ? strtolower((string)$theme) : 'dark';
$isHotspotContext = (isset($_GET['hotspot']) && $_GET['hotspot'] === 'vpn' && !empty($_GET['session']));
$baseVpnUrl = $isHotspotContext
    ? ('./?hotspot=vpn&session=' . rawurlencode((string)$_GET['session']))
    : './admin.php?id=vpn';

$vpnRouters = ngwVpnLoadRouters();
$voiceCfg = ngwVpnLoadVoiceConfig();

$activeVpnId = isset($_REQUEST['vpn_id']) ? trim((string)$_REQUEST['vpn_id']) : '';
$activeTab = isset($_REQUEST['tab']) ? strtolower(trim((string)$_REQUEST['tab'])) : 'users';
if (!in_array($activeTab, array('users', 'nat', 'voice'), true)) {
    $activeTab = 'users';
}

$vpnMsg = $vpnFlashMsg;
$vpnErr = $vpnFlashErr;

$activeRouter = null;
if ($activeVpnId !== '') {
    foreach ($vpnRouters as $vr) {
        if ($vr['id'] === $activeVpnId) {
            $activeRouter = $vr;
            break;
        }
    }
    if ($activeRouter === null) {
        $activeVpnId = '';
    }
}

function ngwVpnRedirect($url, $msg = '', $err = '', $natResult = null) {
    @session_start();
    if ($msg !== '') {
        $_SESSION['ngw_vpn_flash_msg'] = $msg;
    }
    if ($err !== '') {
        $_SESSION['ngw_vpn_flash_err'] = $err;
    }
    if (is_array($natResult)) {
        $_SESSION['ngw_vpn_nat_result'] = $natResult;
    }
    session_write_close();
    echo '<script>window.location.replace(' . json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>';
    exit;
}

function ngwVpnResolveUniqueSecretIp($API, $secId, $username = '') {
    $secrets = $API->comm('/ppp/secret/print', array(
        '.proplist' => '.id,name,remote-address',
    ));
    if (!is_array($secrets)) {
        return '';
    }
    $targetIp = '';
    $targetId = (string)$secId;
    foreach ($secrets as $sec) {
        if (!is_array($sec)) {
            continue;
        }
        $idMatch = ($targetId !== '' && isset($sec['.id']) && (string)$sec['.id'] === $targetId);
        $nameMatch = ($targetId === '' && $username !== '' && isset($sec['name']) && strcasecmp((string)$sec['name'], $username) === 0);
        if ($idMatch || $nameMatch) {
            $targetIp = isset($sec['remote-address']) ? trim((string)$sec['remote-address']) : '';
            if ($targetId === '' && isset($sec['.id'])) {
                $targetId = (string)$sec['.id'];
            }
            break;
        }
    }
    if ($targetIp === '' || filter_var($targetIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return '';
    }
    foreach ($secrets as $sec) {
        if (!is_array($sec)) {
            continue;
        }
        $thisId = isset($sec['.id']) ? (string)$sec['.id'] : '';
        if ($targetId !== '' && $thisId === $targetId) {
            continue;
        }
        $otherIp = isset($sec['remote-address']) ? trim((string)$sec['remote-address']) : '';
        if ($otherIp === $targetIp) {
            // Shared IP across multiple secrets — do not delete by IP
            return '';
        }
    }
    return $targetIp;
}

function ngwVpnConnectRouter($router) {
    $API = new RouterosAPI();
    $API->debug = false;
    $API->timeout = 5;
    $API->attempts = 1;
    $API->port = (int)$router['port'];
    $rawPass = '';
    if (!empty($router['pass_enc'])) {
        $rawPass = function_exists('decrypt') ? decrypt($router['pass_enc']) : base64_decode($router['pass_enc']);
    }
    $ok = $API->connect($router['host'], $router['user'], $rawPass);
    return array('ok' => (bool)$ok, 'api' => $API, 'error' => (string)$API->last_error);
}

// =========================================================================
// HANDLE POST ACTIONS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = isset($_POST['vpn_csrf_token']) ? (string)$_POST['vpn_csrf_token'] : '';
    if ($postedCsrf === '' || !hash_equals($vpnCsrfToken, $postedCsrf)) {
        $vpnErr = ngwVpnText('Security token expired. Reload and try again.', 'সিকিউরিটি টোকেনের মেয়াদ শেষ হয়েছে। রিলোড করে আবার চেষ্টা করুন।');
    } else {
        $action = isset($_POST['vpn_action']) ? trim((string)$_POST['vpn_action']) : '';

        // 1. Add / Update VPN Router (5 core fields only; preserves VPN settings on edit)
        if ($action === 'save_router') {
            $editId = trim((string)(isset($_POST['router_id']) ? $_POST['router_id'] : ''));
            $rName = trim((string)(isset($_POST['r_name']) ? $_POST['r_name'] : ''));
            $rHost = ngwVpnCleanHost(isset($_POST['r_host']) ? $_POST['r_host'] : '');
            $rPort = isset($_POST['r_port']) ? (int)$_POST['r_port'] : 8728;
            $rUser = trim((string)(isset($_POST['r_user']) ? $_POST['r_user'] : ''));
            $rPass = (string)(isset($_POST['r_pass']) ? $_POST['r_pass'] : '');
            $returnToWorkspace = !empty($_POST['return_to_workspace']);

            if ($rName === '' || $rHost === '' || $rUser === '' || $rPort < 1 || $rPort > 65535) {
                $vpnErr = ngwVpnText('Enter a valid router name, host, username and API port.', 'সঠিক রাউটার নাম, হোস্ট, ইউজারনেম ও API পোর্ট দিন।');
            } else {
                $allRaw = ngwVpnLoadRoutersRaw();
                $savedId = $editId;
                if ($editId !== '') {
                    $found = false;
                    foreach ($allRaw as $idx => $vr) {
                        if ($vr['id'] === $editId && ngwVpnCanAccessRouter($vr)) {
                            $allRaw[$idx]['name'] = $rName;
                            $allRaw[$idx]['host'] = $rHost;
                            $allRaw[$idx]['port'] = $rPort;
                            $allRaw[$idx]['user'] = $rUser;
                            if ($rPass !== '') {
                                $allRaw[$idx]['pass_enc'] = function_exists('encrypt') ? encrypt($rPass) : base64_encode($rPass);
                            }
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $vpnErr = ngwVpnText('VPN Router not found.', 'VPN রাউটার পাওয়া যায়নি।');
                    }
                } else {
                    $admRec = function_exists('getCurrentAdminRecord') ? getCurrentAdminRecord() : null;
                    $savedId = 'vpn_' . time() . '_' . mt_rand(100, 999);
                    $allRaw[] = array(
                        'id' => $savedId,
                        'name' => $rName,
                        'host' => $rHost,
                        'port' => $rPort,
                        'user' => $rUser,
                        'pass_enc' => function_exists('encrypt') ? encrypt($rPass) : base64_encode($rPass),
                        'ros_ver' => 7,
                        'sstp_port' => 443,
                        'l2tp_port' => 1701,
                        'l2tp_ipsec_secret' => '',
                        'pptp_port' => 1723,
                        'nat_start_port' => 8010,
                        'winbox_int_port' => 8291,
                        'api_int_port' => 8728,
                        'web_int_port' => 80,
                        'created_by_admin_id' => (is_array($admRec) && !empty($admRec['id'])) ? (string)$admRec['id'] : 'adm_1',
                        'created_by_admin_username' => (is_array($admRec) && !empty($admRec['username'])) ? (string)$admRec['username'] : (string)$_SESSION['mikhmon'],
                        'created_at' => date('Y-m-d H:i:s'),
                    );
                }
                if ($vpnErr === '') {
                    if (ngwVpnSaveRouters($allRaw)) {
                        $dest = $returnToWorkspace
                            ? ($baseVpnUrl . '&vpn_id=' . rawurlencode($savedId) . '&tab=' . rawurlencode($activeTab))
                            : $baseVpnUrl;
                        ngwVpnRedirect($dest, $editId === ''
                            ? ngwVpnText('VPN Router added.', 'VPN রাউটার যুক্ত হয়েছে।')
                            : ngwVpnText('VPN Router updated.', 'VPN রাউটার আপডেট হয়েছে।'));
                    } else {
                        $vpnErr = ngwVpnText('Could not save VPN Router storage.', 'VPN রাউটার সংরক্ষণ করা যায়নি।');
                    }
                }
            }
        }

        // 1B. Save VPN Router Settings (ROS version, SSTP/L2TP/NAT/Internal Ports only)
        elseif ($action === 'save_router_settings' && $activeRouter !== null) {
            $rVer = (isset($_POST['r_ros_ver']) && (int)$_POST['r_ros_ver'] === 6) ? 6 : 7;
            $rSstp = isset($_POST['r_sstp_port']) ? (int)$_POST['r_sstp_port'] : 443;
            $rL2tpSecret = trim((string)(isset($_POST['r_l2tp_secret']) ? $_POST['r_l2tp_secret'] : ''));
            $rNatStart = isset($_POST['r_nat_start']) ? (int)$_POST['r_nat_start'] : 8010;
            $rWinboxInt = isset($_POST['r_winbox_int']) ? (int)$_POST['r_winbox_int'] : 8291;
            $rApiInt = isset($_POST['r_api_int']) ? (int)$_POST['r_api_int'] : 8728;
            $rWebInt = isset($_POST['r_web_int']) ? (int)$_POST['r_web_int'] : 80;

            if ($rSstp < 1 || $rSstp > 65535 || $rNatStart < 1024 || $rNatStart > 65500 || $rWinboxInt < 1 || $rWinboxInt > 65535 || $rApiInt < 1 || $rApiInt > 65535 || $rWebInt < 1 || $rWebInt > 65535) {
                $vpnErr = ngwVpnText('One or more port numbers are out of range.', 'এক বা একাধিক পোর্ট নম্বর সঠিক রেঞ্জে নেই।');
            } else {
                $allRaw = ngwVpnLoadRoutersRaw();
                foreach ($allRaw as $idx => $vr) {
                    if ($vr['id'] === $activeRouter['id'] && ngwVpnCanAccessRouter($vr)) {
                        $allRaw[$idx]['ros_ver'] = $rVer;
                        $allRaw[$idx]['sstp_port'] = $rSstp;
                        $allRaw[$idx]['l2tp_ipsec_secret'] = $rL2tpSecret;
                        $allRaw[$idx]['nat_start_port'] = $rNatStart;
                        $allRaw[$idx]['winbox_int_port'] = $rWinboxInt;
                        $allRaw[$idx]['api_int_port'] = $rApiInt;
                        $allRaw[$idx]['web_int_port'] = $rWebInt;
                        break;
                    }
                }
                if (ngwVpnSaveRouters($allRaw)) {
                    ngwVpnRedirect(
                        $baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=' . rawurlencode($activeTab),
                        ngwVpnText('VPN settings saved.', 'ভিপিএন সেটিংস সংরক্ষিত হয়েছে।')
                    );
                } else {
                    $vpnErr = ngwVpnText('Could not save VPN settings.', 'ভিপিএন সেটিংস সংরক্ষণ করা যায়নি।');
                }
            }
        }

        // 2. Delete VPN Router
        elseif ($action === 'delete_router') {
            $delId = trim((string)(isset($_POST['del_router_id']) ? $_POST['del_router_id'] : ''));
            $allRaw = ngwVpnLoadRoutersRaw();
            $allRaw = array_values(array_filter($allRaw, function($vr) use ($delId) {
                if (!isset($vr['id']) || $vr['id'] !== $delId) {
                    return true;
                }
                return !ngwVpnCanAccessRouter($vr);
            }));
            if (ngwVpnSaveRouters($allRaw)) {
                ngwVpnPruneRouterVoiceUsers($voiceCfg, $delId);
                ngwVpnSaveVoiceConfig($voiceCfg);
                ngwVpnRedirect($baseVpnUrl, ngwVpnText('VPN Router removed.', 'VPN রাউটার মুছে ফেলা হয়েছে।'));
            } else {
                $vpnErr = ngwVpnText('Could not remove VPN Router.', 'VPN রাউটার মুছে ফেলা যায়নি।');
            }
        }

        // 3. Save Voice Call Ports + Optional Add VPN User (from Add Voice Call Modal)
        elseif ($action === 'save_voice_ports' && $activeRouter !== null) {
            $tcpRaw = isset($_POST['voice_tcp_ports']) ? (string)$_POST['voice_tcp_ports'] : '';
            $udpRaw = isset($_POST['voice_udp_ports']) ? (string)$_POST['voice_udp_ports'] : '';
            $voiceUser = trim((string)(isset($_POST['voice_username']) ? $_POST['voice_username'] : ''));
            $tcpValid = ngwVpnValidatePortList($tcpRaw);
            $udpValid = ngwVpnValidatePortList($udpRaw);
            if ($tcpValid === false || $udpValid === false) {
                $vpnErr = ngwVpnText('Enter valid comma-separated TCP and UDP ports.', 'সঠিক কমা-সেপারেটেড TCP ও UDP পোর্ট দিন।');
            } else {
                $voiceCfg['tcp_ports'] = $tcpValid;
                $voiceCfg['udp_ports'] = $udpValid;
                if ($voiceUser !== '' && strlen($voiceUser) <= 100) {
                    ngwVpnSetVoiceUser($voiceCfg, $activeRouter['id'], $voiceUser, true);
                }
                if (ngwVpnSaveVoiceConfig($voiceCfg)) {
                    $savedMsg = ($voiceUser !== '')
                        ? ngwVpnText('Voice Call saved for "' . $voiceUser . '".', '"' . $voiceUser . '" ভয়েস কল তালিকায় সেভ হয়েছে।')
                        : ngwVpnText('Voice Call ports saved.', 'ভয়েস কল পোর্ট সেভ হয়েছে।');
                    ngwVpnRedirect($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=voice', $savedMsg);
                } else {
                    $vpnErr = ngwVpnText('Could not save Voice Call config.', 'ভয়েস কল কনফিগ সংরক্ষণ করা যায়নি।');
                }
            }
        }

        // 4. Toggle Voice Call Opt-In for a User
        elseif ($action === 'toggle_voice_user' && $activeRouter !== null) {
            $uName = trim((string)(isset($_POST['username']) ? $_POST['username'] : ''));
            $enableVoice = (isset($_POST['voice_enabled']) && $_POST['voice_enabled'] === '1');
            $retTab = isset($_POST['return_tab']) && in_array($_POST['return_tab'], array('users', 'voice'), true) ? $_POST['return_tab'] : 'users';
            if ($uName !== '' && strlen($uName) <= 100) {
                ngwVpnSetVoiceUser($voiceCfg, $activeRouter['id'], $uName, $enableVoice);
                ngwVpnSaveVoiceConfig($voiceCfg);
                ngwVpnRedirect(
                    $baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=' . rawurlencode($retTab),
                    $enableVoice
                        ? ngwVpnText('Added to Voice Call.', 'ভয়েস কল তালিকায় যুক্ত হয়েছে।')
                        : ngwVpnText('Removed from Voice Call.', 'ভয়েস কল তালিকা থেকে সরানো হয়েছে।')
                );
            }
        }

        // 5. Update Router NAT Start Port
        elseif ($action === 'save_nat_start' && $activeRouter !== null) {
            $newStart = isset($_POST['nat_start_port']) ? (int)$_POST['nat_start_port'] : 8010;
            if ($newStart < 1024 || $newStart > 65500) {
                $vpnErr = ngwVpnText('Start port must be between 1024 and 65500.', 'স্টার্ট পোর্ট 1024 থেকে 65500 এর মধ্যে হতে হবে।');
            } else {
                $allRaw = ngwVpnLoadRoutersRaw();
                foreach ($allRaw as $idx => $vr) {
                    if ($vr['id'] === $activeRouter['id'] && ngwVpnCanAccessRouter($vr)) {
                        $allRaw[$idx]['nat_start_port'] = $newStart;
                        break;
                    }
                }
                ngwVpnSaveRouters($allRaw);
                ngwVpnRedirect($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=nat', ngwVpnText('External start port updated.', 'এক্সটারনাল স্টার্ট পোর্ট আপডেট হয়েছে।'));
            }
        }

        // 6. RouterOS API Mutations (Secret CRUD, NAT CRUD & Live Voice Scan)
        elseif ($activeRouter !== null && in_array($action, array('create_secret', 'edit_secret', 'toggle_secret', 'delete_secret', 'create_nat', 'edit_nat', 'toggle_nat', 'delete_nat', 'scan_voice_traffic'), true)) {
            $conn = ngwVpnConnectRouter($activeRouter);
            if (!$conn['ok']) {
                $vpnErr = ngwVpnText('Failed to connect to VPN Router API.', 'VPN রাউটারের API-তে সংযোগ ব্যর্থ হয়েছে।') . ($conn['error'] !== '' ? (' (' . $conn['error'] . ')') : '');
            } else {
                $API = $conn['api'];

                if ($action === 'create_secret') {
                    $uName = trim((string)(isset($_POST['u_name']) ? $_POST['u_name'] : ''));
                    $uPass = (string)(isset($_POST['u_pass']) ? $_POST['u_pass'] : '');
                    $uService = strtolower(trim((string)(isset($_POST['u_service']) ? $_POST['u_service'] : 'sstp')));
                    $uProfile = trim((string)(isset($_POST['u_profile']) ? $_POST['u_profile'] : 'default'));
                    $uRemoteIp = trim((string)(isset($_POST['u_remote_ip']) ? $_POST['u_remote_ip'] : ''));
                    $uComment = trim((string)(isset($_POST['u_comment']) ? $_POST['u_comment'] : ''));
                    $uVoice = !empty($_POST['u_voice_call']);

                    if (!in_array($uService, array('sstp', 'l2tp', 'pptp', 'any'), true)) {
                        $uService = 'sstp';
                    }
                    if ($uName === '' || $uPass === '' || strlen($uName) > 80) {
                        $vpnErr = ngwVpnText('Username and password are required.', 'ইউজারনেম ও পাসওয়ার্ড আবশ্যক।');
                    } elseif ($uRemoteIp === '' || !filter_var($uRemoteIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $vpnErr = ngwVpnText('A valid Static IPv4 address is required.', 'সঠিক Static IPv4 অ্যাড্রেস দিন।');
                    } else {
                        $existingSecrets = $API->comm('/ppp/secret/print', array('.proplist' => '.id,name,remote-address'));
                        $dupName = false;
                        $dupIp = false;
                        if (is_array($existingSecrets)) {
                            foreach ($existingSecrets as $ex) {
                                if (isset($ex['name']) && strcasecmp((string)$ex['name'], $uName) === 0) {
                                    $dupName = true;
                                }
                                if (isset($ex['remote-address']) && trim((string)$ex['remote-address']) === $uRemoteIp) {
                                    $dupIp = true;
                                }
                            }
                        }
                        if ($dupName) {
                            $vpnErr = ngwVpnText('Username already exists on this router.', 'এই ইউজারনেমটি রাউটারে আগে থেকেই আছে।');
                        } elseif ($dupIp) {
                            $vpnErr = ngwVpnText('Static IP is already assigned to another secret.', 'এই Static IP-টি অন্য ইউজারের জন্য ব্যবহৃত হচ্ছে।');
                        } else {
                            $payload = array(
                                'name' => $uName,
                                'password' => $uPass,
                                'service' => $uService,
                                'profile' => $uProfile !== '' ? $uProfile : 'default',
                                'remote-address' => $uRemoteIp,
                                'disabled' => 'no',
                            );
                            if ($uComment !== '') {
                                $payload['comment'] = $uComment;
                            }
                            $res = $API->comm('/ppp/secret/add', $payload);
                            if (is_array($res) && isset($res['!trap'])) {
                                $trapMsg = isset($res['!trap'][0]['message']) ? $res['!trap'][0]['message'] : 'RouterOS rejected request';
                                $vpnErr = $trapMsg;
                            } else {
                                if ($uVoice) {
                                    ngwVpnSetVoiceUser($voiceCfg, $activeRouter['id'], $uName, true);
                                    ngwVpnSaveVoiceConfig($voiceCfg);
                                }
                                $API->disconnect();
                                ngwVpnRedirect($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=users', ngwVpnText('VPN User created.', 'VPN ইউজার তৈরি হয়েছে।'));
                            }
                        }
                    }
                } elseif ($action === 'edit_secret') {
                    $secId = trim((string)(isset($_POST['sec_id']) ? $_POST['sec_id'] : ''));
                    $oldName = trim((string)(isset($_POST['old_name']) ? $_POST['old_name'] : ''));
                    $uName = trim((string)(isset($_POST['u_name']) ? $_POST['u_name'] : ''));
                    $uPass = (string)(isset($_POST['u_pass']) ? $_POST['u_pass'] : '');
                    $uService = strtolower(trim((string)(isset($_POST['u_service']) ? $_POST['u_service'] : 'sstp')));
                    $uProfile = trim((string)(isset($_POST['u_profile']) ? $_POST['u_profile'] : 'default'));
                    $uRemoteIp = trim((string)(isset($_POST['u_remote_ip']) ? $_POST['u_remote_ip'] : ''));
                    $uComment = trim((string)(isset($_POST['u_comment']) ? $_POST['u_comment'] : ''));
                    $uVoice = !empty($_POST['u_voice_call']);

                    if (!in_array($uService, array('sstp', 'l2tp', 'pptp', 'any'), true)) {
                        $uService = 'sstp';
                    }
                    if ($secId === '' || $uName === '') {
                        $vpnErr = ngwVpnText('Invalid secret record.', 'সঠিক ইউজার রেকর্ড পাওয়া যায়নি।');
                    } elseif ($uRemoteIp === '' || !filter_var($uRemoteIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $vpnErr = ngwVpnText('A valid Static IPv4 address is required.', 'সঠিক Static IPv4 অ্যাড্রেস দিন।');
                    } else {
                        $oldUniqueIp = ngwVpnResolveUniqueSecretIp($API, $secId, $oldName);
                        $update = array(
                            '.id' => $secId,
                            'name' => $uName,
                            'service' => $uService,
                            'profile' => $uProfile !== '' ? $uProfile : 'default',
                            'remote-address' => $uRemoteIp,
                            'comment' => $uComment,
                        );
                        if ($uPass !== '') {
                            $update['password'] = $uPass;
                        }
                        $res = $API->comm('/ppp/secret/set', $update);
                        if (is_array($res) && isset($res['!trap'])) {
                            $vpnErr = isset($res['!trap'][0]['message']) ? $res['!trap'][0]['message'] : 'RouterOS rejected update';
                        } else {
                            // Sync linked NAT rules (both ngw-vpn managed & pre-existing manual rules for this user's static IP)
                            if ($oldName !== '' || $oldUniqueIp !== '') {
                                ngwVpnUpdateUserNatMeta($API, $oldName, $uName, $uRemoteIp, $oldUniqueIp);
                                if ($oldName !== '' && strcasecmp($oldName, $uName) !== 0) {
                                    ngwVpnRenameVoiceUser($voiceCfg, $activeRouter['id'], $oldName, $uName);
                                }
                            }
                            ngwVpnSetVoiceUser($voiceCfg, $activeRouter['id'], $uName, $uVoice);
                            ngwVpnSaveVoiceConfig($voiceCfg);
                            $API->disconnect();
                            ngwVpnRedirect($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=users', ngwVpnText('VPN User updated.', 'VPN ইউজার আপডেট হয়েছে।'));
                        }
                    }
                } elseif ($action === 'toggle_secret') {
                    $secId = trim((string)(isset($_POST['sec_id']) ? $_POST['sec_id'] : ''));
                    $uName = trim((string)(isset($_POST['u_name']) ? $_POST['u_name'] : ''));
                    $targetDisable = (isset($_POST['target_disabled']) && $_POST['target_disabled'] === '1');
                    if ($secId !== '') {
                        $uniqueStaticIp = ngwVpnResolveUniqueSecretIp($API, $secId, $uName);
                        $API->comm('/ppp/secret/set', array(
                            '.id' => $secId,
                            'disabled' => $targetDisable ? 'yes' : 'no',
                        ));
                        // Disconnect active PPP session immediately when disabling
                        if ($targetDisable && $uName !== '') {
                            $actives = $API->comm('/ppp/active/print', array('?name' => $uName, '.proplist' => '.id'));
                            if (is_array($actives)) {
                                foreach ($actives as $act) {
                                    if (!empty($act['.id'])) {
                                        $API->comm('/ppp/active/remove', array('.id' => $act['.id']));
                                    }
                                }
                            }
                        }
                        // Auto-sync all linked NAT rules (managed + pre-existing manual dstnat targeting this static IP)
                        $syncedNat = ($uName !== '' || $uniqueStaticIp !== '') ? ngwVpnSyncUserNatState($API, $uName, $targetDisable, $uniqueStaticIp) : 0;
                        $API->disconnect();
                        $statusMsg = $targetDisable
                            ? ngwVpnText('User disabled' . ($syncedNat > 0 ? " ($syncedNat NAT synced)." : '.'), 'ইউজার ডিজেবল করা হয়েছে' . ($syncedNat > 0 ? " ($syncedNat টি NAT সিঙ্ক হয়েছে)।" : '।'))
                            : ngwVpnText('User enabled' . ($syncedNat > 0 ? " ($syncedNat NAT synced)." : '.'), 'ইউজার ইনেবল করা হয়েছে' . ($syncedNat > 0 ? " ($syncedNat টি NAT সিঙ্ক হয়েছে)।" : '।'));
                        ngwVpnRedirect($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=users', $statusMsg);
                    }
                } elseif ($action === 'delete_secret') {
                    $secId = trim((string)(isset($_POST['sec_id']) ? $_POST['sec_id'] : ''));
                    $uName = trim((string)(isset($_POST['u_name']) ? $_POST['u_name'] : ''));
                    if ($secId !== '') {
                        // Resolve user's unique Static IP BEFORE deleting secret so all its NAT rules are also removed
                        $uniqueStaticIp = ngwVpnResolveUniqueSecretIp($API, $secId, $uName);
                        // Disconnect active session if online
                        if ($uName !== '') {
                            $actives = $API->comm('/ppp/active/print', array('?name' => $uName, '.proplist' => '.id'));
                            if (is_array($actives)) {
                                foreach ($actives as $act) {
                                    if (!empty($act['.id'])) {
                                        $API->comm('/ppp/active/remove', array('.id' => $act['.id']));
                                    }
                                }
                            }
                        }
                        $API->comm('/ppp/secret/remove', array('.id' => $secId));
                        // Auto-delete ALL linked NAT rules (both ngw-vpn managed & pre-existing manual rules for this user's static IP)
                        $deletedNat = ($uName !== '' || $uniqueStaticIp !== '') ? ngwVpnDeleteUserNatRules($API, $uName, $uniqueStaticIp) : 0;
                        // Remove from Voice Call opt-in list
                        if ($uName !== '') {
                            ngwVpnSetVoiceUser($voiceCfg, $activeRouter['id'], $uName, false);
                            ngwVpnSaveVoiceConfig($voiceCfg);
                        }
                        $API->disconnect();
                        ngwVpnRedirect(
                            $baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=users',
                            ngwVpnText('User removed' . ($deletedNat > 0 ? " ($deletedNat NAT removed)." : '.'), 'ইউজার মুছে ফেলা হয়েছে' . ($deletedNat > 0 ? " ($deletedNat টি NAT মুছে ফেলা হয়েছে)।" : '।'))
                        );
                    }
                } elseif ($action === 'create_nat') {
                    $uName = trim((string)(isset($_POST['nat_username']) ? $_POST['nat_username'] : ''));
                    $startPort = isset($_POST['nat_start_port']) ? (int)$_POST['nat_start_port'] : (int)$activeRouter['nat_start_port'];
                    if ($startPort < 1024 || $startPort > 65500) {
                        $startPort = (int)$activeRouter['nat_start_port'];
                    }

                    $servicesToCreate = array();
                    if (!empty($_POST['fwd_winbox'])) {
                        $p = isset($_POST['int_winbox']) ? (int)$_POST['int_winbox'] : (int)$activeRouter['winbox_int_port'];
                        if ($p >= 1 && $p <= 65535) {
                            $servicesToCreate['winbox'] = $p;
                        }
                    }
                    if (!empty($_POST['fwd_api'])) {
                        $p = isset($_POST['int_api']) ? (int)$_POST['int_api'] : (int)$activeRouter['api_int_port'];
                        if ($p >= 1 && $p <= 65535) {
                            $servicesToCreate['api'] = $p;
                        }
                    }
                    if (!empty($_POST['fwd_web'])) {
                        $p = isset($_POST['int_web']) ? (int)$_POST['int_web'] : (int)$activeRouter['web_int_port'];
                        if ($p >= 1 && $p <= 65535) {
                            $servicesToCreate['web'] = $p;
                        }
                    }

                    if ($uName === '' || empty($servicesToCreate)) {
                        $vpnErr = ngwVpnText('Select a VPN user and at least one port service.', 'একজন VPN ইউজার এবং অন্তত একটি পোর্ট সার্ভিস সিলেক্ট করুন।');
                    } else {
                        $secrets = $API->comm('/ppp/secret/print', array('.proplist' => 'name,remote-address'));
                        $targetSecret = null;
                        if (is_array($secrets)) {
                            foreach ($secrets as $sec) {
                                if (isset($sec['name']) && strcasecmp((string)$sec['name'], $uName) === 0) {
                                    $targetSecret = $sec;
                                    break;
                                }
                            }
                        }
                        $staticIp = ($targetSecret && isset($targetSecret['remote-address'])) ? trim((string)$targetSecret['remote-address']) : '';
                        if (!$targetSecret || !filter_var($staticIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            $vpnErr = ngwVpnText('Selected user does not have a valid Static IP.', 'নির্বাচিত ইউজারের বৈধ Static IP নেই।');
                        } else {
                            $existingNat = $API->comm('/ip/firewall/nat/print', array(
                                '.proplist' => 'chain,action,dst-port,to-addresses,to-ports,comment',
                            ));
                            $usedPorts = ngwVpnExtractUsedNatPorts($existingNat);

                            // Check already-existing services for this user (both ngw-vpn managed and pre-existing manual rules)
                            $existingUserSvcs = array();
                            $existingTargetPorts = array();
                            foreach ($existingNat as $er) {
                                $parsed = ngwVpnParseNatComment(isset($er['comment']) ? $er['comment'] : '');
                                if ($parsed && strcasecmp($parsed['username'], $uName) === 0) {
                                    $existingUserSvcs[$parsed['service']] = true;
                                }
                                $erChain = strtolower(isset($er['chain']) ? (string)$er['chain'] : '');
                                $erToAddr = trim(isset($er['to-addresses']) ? (string)$er['to-addresses'] : '');
                                $erToPort = trim(isset($er['to-ports']) ? (string)$er['to-ports'] : '');
                                if ($erChain === 'dstnat' && $erToAddr === $staticIp && $erToPort !== '') {
                                    $existingTargetPorts[$erToPort] = true;
                                }
                            }

                            $toAdd = array();
                            foreach ($servicesToCreate as $svcKey => $intPort) {
                                if (!isset($existingUserSvcs[$svcKey]) && !isset($existingTargetPorts[(string)$intPort])) {
                                    $toAdd[$svcKey] = $intPort;
                                }
                            }

                            if (empty($toAdd)) {
                                $vpnErr = ngwVpnText('Selected port forwards already exist for this user.', 'নির্বাচিত পোর্ট ফরওয়ার্ডগুলো এই ইউজারের জন্য আগেই তৈরি করা আছে।');
                            } else {
                                $extPorts = ngwVpnAllocateFreePorts($startPort, count($toAdd), $usedPorts);
                                if (count($extPorts) < count($toAdd)) {
                                    $vpnErr = ngwVpnText('Not enough free external ports available.', 'পর্যাপ্ত খালি এক্সটারনাল পোর্ট পাওয়া যায়নি।');
                                } else {
                                    $secDisabled = isset($targetSecret['disabled']) && ($targetSecret['disabled'] === 'true' || $targetSecret['disabled'] === 'yes');
                                    $svcLabels = array('winbox' => 'Winbox', 'api' => 'API', 'web' => 'Web');
                                    $createdLinks = array();
                                    $i = 0;
                                    foreach ($toAdd as $svcKey => $intPort) {
                                        $extP = (int)$extPorts[$i];
                                        $API->comm('/ip/firewall/nat/add', array(
                                            'chain' => 'dstnat',
                                            'protocol' => 'tcp',
                                            'dst-port' => (string)$extP,
                                            'action' => 'dst-nat',
                                            'to-addresses' => $staticIp,
                                            'to-ports' => (string)$intPort,
                                            'comment' => ngwVpnBuildNatComment($targetSecret['name'], $svcKey),
                                            'disabled' => $secDisabled ? 'yes' : 'no',
                                        ));
                                        $createdLinks[] = array(
                                            'service' => isset($svcLabels[$svcKey]) ? $svcLabels[$svcKey] : strtoupper($svcKey),
                                            'ext_port' => $extP,
                                            'int_port' => (int)$intPort,
                                            'addr' => $activeRouter['host'] . ':' . $extP,
                                        );
                                        $i++;
                                    }
                                    if (!empty($_POST['save_default_start']) && $startPort !== (int)$activeRouter['nat_start_port']) {
                                        $allRaw = ngwVpnLoadRoutersRaw();
                                        foreach ($allRaw as $rIdx => $rItem) {
                                            if ($rItem['id'] === $activeRouter['id'] && ngwVpnCanAccessRouter($rItem)) {
                                                $allRaw[$rIdx]['nat_start_port'] = $startPort;
                                                break;
                                            }
                                        }
                                        ngwVpnSaveRouters($allRaw);
                                    }
                                    $API->disconnect();
                                    $natResultPayload = array(
                                        'username' => (string)$targetSecret['name'],
                                        'static_ip' => $staticIp,
                                        'host' => (string)$activeRouter['host'],
                                        'links' => $createdLinks,
                                    );
                                    ngwVpnRedirect(
                                        $baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=nat',
                                        ngwVpnText('NAT port forwards created.', 'NAT পোর্ট ফরওয়ার্ড তৈরি হয়েছে।'),
                                        '',
                                        $natResultPayload
                                    );
                                }
                            }
                        }
                    }
                } elseif ($action === 'edit_nat') {
                    $natId = trim((string)(isset($_POST['nat_id']) ? $_POST['nat_id'] : ''));
                    $natChain = strtolower(trim((string)(isset($_POST['nat_chain']) ? $_POST['nat_chain'] : 'dstnat')));
                    $natProto = strtolower(trim((string)(isset($_POST['nat_protocol']) ? $_POST['nat_protocol'] : '')));
                    $natDstPortRaw = trim((string)(isset($_POST['nat_dst_port']) ? $_POST['nat_dst_port'] : ''));
                    $natToAddr = trim((string)(isset($_POST['nat_to_addresses']) ? $_POST['nat_to_addresses'] : ''));
                    $natToPortsRaw = trim((string)(isset($_POST['nat_to_ports']) ? $_POST['nat_to_ports'] : ''));
                    $natComment = trim((string)(isset($_POST['nat_comment']) ? $_POST['nat_comment'] : ''));
                    $natDisabled = (isset($_POST['nat_disabled']) && $_POST['nat_disabled'] === 'yes') ? 'yes' : 'no';

                    if (!in_array($natProto, array('tcp', 'udp', ''), true)) {
                        $natProto = 'tcp';
                    }
                    if (strlen($natComment) > 150) {
                        $natComment = substr($natComment, 0, 150);
                    }
                    $dstPortValid = ($natDstPortRaw === '') ? '' : ngwVpnValidatePortList($natDstPortRaw);
                    $toPortsValid = ($natToPortsRaw === '') ? '' : ngwVpnValidatePortList($natToPortsRaw);

                    if ($natId === '') {
                        $vpnErr = ngwVpnText('Invalid NAT rule.', 'সঠিক NAT রুল পাওয়া যায়নি।');
                    } elseif ($natDstPortRaw !== '' && $dstPortValid === false) {
                        $vpnErr = ngwVpnText('Enter a valid external port.', 'সঠিক এক্সটারনাল পোর্ট দিন।');
                    } elseif ($natToPortsRaw !== '' && $toPortsValid === false) {
                        $vpnErr = ngwVpnText('Enter a valid internal port.', 'সঠিক ইন্টারনাল পোর্ট দিন।');
                    } elseif ($natToAddr !== '' && filter_var($natToAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                        $vpnErr = ngwVpnText('Enter a valid target Static IPv4 address.', 'সঠিক টার্গেট Static IPv4 অ্যাড্রেস দিন।');
                    } else {
                        // Conflict check for external dst-port on other dstnat rules
                        $portConflict = false;
                        if ($natChain === 'dstnat' && $dstPortValid !== '' && preg_match('/^\d+$/', $dstPortValid)) {
                            $otherNats = $API->comm('/ip/firewall/nat/print', array(
                                '.proplist' => '.id,chain,protocol,dst-port',
                            ));
                            if (is_array($otherNats)) {
                                foreach ($otherNats as $on) {
                                    if (!is_array($on) || (isset($on['.id']) && (string)$on['.id'] === $natId)) {
                                        continue;
                                    }
                                    $oChain = strtolower(trim(isset($on['chain']) ? (string)$on['chain'] : ''));
                                    $oProto = strtolower(trim(isset($on['protocol']) ? (string)$on['protocol'] : ''));
                                    $oPort = trim(isset($on['dst-port']) ? (string)$on['dst-port'] : '');
                                    if ($oChain === 'dstnat' && $oPort === $dstPortValid && ($natProto === '' || $oProto === '' || $oProto === $natProto)) {
                                        $portConflict = true;
                                        break;
                                    }
                                }
                            }
                            unset($otherNats);
                        }

                        if ($portConflict) {
                            $vpnErr = ngwVpnText('External port ' . $dstPortValid . ' is already used by another NAT rule.', 'এক্সটারনাল পোর্ট ' . $dstPortValid . ' অন্য NAT রুলে ব্যবহৃত হচ্ছে।');
                        } else {
                            $update = array(
                                '.id' => $natId,
                                'comment' => $natComment,
                                'disabled' => $natDisabled,
                            );
                            if ($natProto !== '') {
                                $update['protocol'] = $natProto;
                            }
                            if ($dstPortValid !== '') {
                                $update['dst-port'] = $dstPortValid;
                            }
                            if ($natToAddr !== '') {
                                $update['to-addresses'] = $natToAddr;
                            }
                            if ($toPortsValid !== '') {
                                $update['to-ports'] = $toPortsValid;
                            }
                            $res = $API->comm('/ip/firewall/nat/set', $update);
                            if (is_array($res) && isset($res['!trap'])) {
                                $vpnErr = isset($res['!trap'][0]['message']) ? $res['!trap'][0]['message'] : 'RouterOS rejected NAT update';
                            } else {
                                $API->disconnect();
                                ngwVpnRedirect(
                                    $baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=nat',
                                    ngwVpnText('NAT rule updated.', 'NAT রুল আপডেট হয়েছে।')
                                );
                            }
                        }
                    }
                } elseif ($action === 'toggle_nat') {
                    $natId = trim((string)(isset($_POST['nat_id']) ? $_POST['nat_id'] : ''));
                    $targetDisable = (isset($_POST['target_disabled']) && $_POST['target_disabled'] === '1');
                    if ($natId !== '') {
                        $API->comm('/ip/firewall/nat/set', array(
                            '.id' => $natId,
                            'disabled' => $targetDisable ? 'yes' : 'no',
                        ));
                        $API->disconnect();
                        ngwVpnRedirect($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=nat', $targetDisable ? ngwVpnText('NAT rule disabled.', 'NAT রুল ডিজেবল করা হয়েছে।') : ngwVpnText('NAT rule enabled.', 'NAT রুল ইনেবল করা হয়েছে।'));
                    }
                } elseif ($action === 'delete_nat') {
                    $natId = trim((string)(isset($_POST['nat_id']) ? $_POST['nat_id'] : ''));
                    if ($natId !== '') {
                        $API->comm('/ip/firewall/nat/remove', array('.id' => $natId));
                        $API->disconnect();
                        ngwVpnRedirect($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=nat', ngwVpnText('NAT rule removed.', 'NAT রুল মুছে ফেলা হয়েছে।'));
                    }
                } elseif ($action === 'scan_voice_traffic') {
                    // Strictly READ-ONLY on-demand scan of /ip/firewall/connection/print — never modifies router
                    $scanSecrets = $API->comm('/ppp/secret/print', array('.proplist' => 'name,remote-address'));
                    $scanActives = $API->comm('/ppp/active/print', array('.proplist' => 'name,address'));
                    $scanActiveMap = array();
                    if (is_array($scanActives)) {
                        foreach ($scanActives as $act) {
                            if (!empty($act['name'])) {
                                $scanActiveMap[strtolower((string)$act['name'])] = $act;
                            }
                        }
                    }
                    $detectedNow = ngwVpnDetectVoiceCallTraffic(
                        $API,
                        is_array($scanSecrets) ? $scanSecrets : array(),
                        $scanActiveMap,
                        $voiceCfg['tcp_ports'],
                        $voiceCfg['udp_ports']
                    );
                    $API->disconnect();
                    unset($scanSecrets, $scanActives, $scanActiveMap);
                    $restoreFlag = (isset($_POST['restore_dismissed']) && $_POST['restore_dismissed'] === '1');
                    $mergeRes = ngwVpnMergeDetectedVoiceUsers($voiceCfg, $activeRouter['id'], $detectedNow, $restoreFlag);
                    if ($mergeRes['changed']) {
                        ngwVpnSaveVoiceConfig($voiceCfg);
                    }
                    $detTotal = count($detectedNow);
                    $addedNow = (int)$mergeRes['added'];
                    ngwVpnRedirect(
                        $baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=voice',
                        ngwVpnText(
                            'Live Voice Call scan complete: ' . $detTotal . ' active user(s) detected (' . $addedNow . ' newly added).',
                            'লাইভ ভয়েস কল স্ক্যান সম্পন্ন: ' . $detTotal . ' জন ইউজারে ভয়েস কল ট্রাফিক পাওয়া গেছে (নতুন যুক্ত ' . $addedNow . ' জন)।'
                        )
                    );
                }

                $API->disconnect();
            }
        }
    }
}

// =========================================================================
// LOAD LIVE DATA WHEN INSIDE A ROUTER WORKSPACE (LIGHTWEIGHT & TAB-SCOPED)
// =========================================================================
$isConnected = false;
$connError = '';
$pppSecrets = array();
$pppProfiles = array();
$ipPools = array();
$pppActiveMap = array();
$vpnNatRules = array();
$dstNatCount = ($activeRouter !== null && isset($vpnCachedNatCounts[$activeRouter['id']]))
    ? (int)$vpnCachedNatCounts[$activeRouter['id']]
    : null;
$natByUser = array();
$usedNatPortsList = array();
$profileSuggestions = array();
$voiceOptInSecrets = array();
$voiceRecordByUser = array();

if ($activeRouter !== null) {
    $conn = ngwVpnConnectRouter($activeRouter);
    $isConnected = $conn['ok'];
    $connError = $conn['error'];
    if ($isConnected) {
        $API = $conn['api'];

        // Tab-scoped minimal .proplist queries to avoid CPU/memory overhead on both MikroTik & server
        if ($activeTab === 'users') {
            $rawSecrets = $API->comm('/ppp/secret/print', array('.proplist' => '.id,name,password,service,profile,remote-address,comment,disabled'));
            $rawActives = $API->comm('/ppp/active/print', array('.proplist' => 'name,address'));
            $rawProfiles = $API->comm('/ppp/profile/print', array('.proplist' => 'name,local-address,remote-address'));
            $rawPools = $API->comm('/ip/pool/print', array('.proplist' => 'name,ranges'));
            $rawNat = $API->comm('/ip/firewall/nat/print', array('.proplist' => '.id,chain,action,protocol,dst-port,to-addresses,to-ports,comment,disabled'));
        } elseif ($activeTab === 'nat') {
            $rawSecrets = $API->comm('/ppp/secret/print', array('.proplist' => '.id,name,remote-address,disabled'));
            $rawActives = array();
            $rawProfiles = array();
            $rawPools = array();
            $rawNat = $API->comm('/ip/firewall/nat/print', array('.proplist' => '.id,chain,action,protocol,dst-port,to-addresses,to-ports,src-address,dst-address,comment,disabled'));
        } else {
            // Voice Call tab: never fetch profiles/pools/NAT or auto-dump firewall connections on page load
            $rawSecrets = $API->comm('/ppp/secret/print', array('.proplist' => '.id,name,service,remote-address,disabled'));
            $rawActives = $API->comm('/ppp/active/print', array('.proplist' => 'name,address'));
            $rawProfiles = array();
            $rawPools = array();
            $rawNat = null;
        }
        $API->disconnect();

        $pppSecrets = is_array($rawSecrets) ? $rawSecrets : array();
        $pppProfiles = is_array($rawProfiles) ? $rawProfiles : array();
        $ipPools = is_array($rawPools) ? $rawPools : array();

        $ipToUserMap = array();
        foreach ($pppSecrets as $sec) {
            $rem = isset($sec['remote-address']) ? trim((string)$sec['remote-address']) : '';
            if ($rem !== '' && !empty($sec['name'])) {
                $ipToUserMap[$rem] = (string)$sec['name'];
            }
        }
        if (is_array($rawActives)) {
            foreach ($rawActives as $act) {
                if (!empty($act['name'])) {
                    $pppActiveMap[strtolower((string)$act['name'])] = $act;
                    $addr = isset($act['address']) ? trim((string)$act['address']) : '';
                    if ($addr !== '' && !isset($ipToUserMap[$addr])) {
                        $ipToUserMap[$addr] = (string)$act['name'];
                    }
                }
            }
        }
        unset($rawSecrets, $rawActives, $rawProfiles, $rawPools);

        if (is_array($rawNat)) {
            $usedPortsAssoc = ngwVpnExtractUsedNatPorts($rawNat);
            $usedNatPortsList = array_keys($usedPortsAssoc);
            unset($usedPortsAssoc);

            $dstNatCount = 0;
            foreach ($rawNat as $rule) {
                $classified = ngwVpnClassifyNatRule($rule, $ipToUserMap);
                if ($activeTab === 'nat') {
                    $vpnNatRules[] = $classified;
                }
                if (!empty($classified['_is_dstnat'])) {
                    $dstNatCount++;
                    if ($activeTab === 'users' && !empty($classified['_vpn_matched']) && !empty($classified['_vpn_user'])) {
                        $uKey = strtolower((string)$classified['_vpn_user']);
                        $svcKey = strtolower((string)$classified['_vpn_service']);
                        if (!isset($natByUser[$uKey])) {
                            $natByUser[$uKey] = array();
                        }
                        if (!isset($natByUser[$uKey][$svcKey])) {
                            $natByUser[$uKey][$svcKey] = $classified;
                        }
                    }
                }
            }
            unset($rawNat);

            // Cache lightweight DST-NAT count in session so Voice Call tab displays accurate badge without fetching NAT
            if (!isset($vpnCachedNatCounts[$activeRouter['id']]) || (int)$vpnCachedNatCounts[$activeRouter['id']] !== $dstNatCount) {
                @session_start();
                if (!isset($_SESSION['ngw_vpn_nat_counts']) || !is_array($_SESSION['ngw_vpn_nat_counts'])) {
                    $_SESSION['ngw_vpn_nat_counts'] = array();
                }
                $_SESSION['ngw_vpn_nat_counts'][$activeRouter['id']] = $dstNatCount;
                session_write_close();
            }
        }

        if ($activeTab === 'users') {
            $profileSuggestions = ngwVpnBuildProfileIpSuggestions($pppProfiles, $ipPools, $pppSecrets);
        }

        // Build Voice Call user list (manually enabled + saved detected voice users)
        foreach ($pppSecrets as $sec) {
            $sName = isset($sec['name']) ? (string)$sec['name'] : '';
            if ($sName === '') {
                continue;
            }
            $rec = ngwVpnGetVoiceUserRecord($voiceCfg, $activeRouter['id'], $sName);
            if ($rec !== null) {
                $voiceOptInSecrets[] = $sec;
                $voiceRecordByUser[strtolower($sName)] = $rec;
            }
        }

        // Sort Voice Call users: highest detected flows first, then online, then alphabetical
        if ($activeTab === 'voice' && count($voiceOptInSecrets) > 1) {
            usort($voiceOptInSecrets, function($a, $b) use ($voiceRecordByUser, $pppActiveMap) {
                $ka = strtolower(isset($a['name']) ? (string)$a['name'] : '');
                $kb = strtolower(isset($b['name']) ? (string)$b['name'] : '');
                $la = isset($voiceRecordByUser[$ka]['last_flows']) ? (int)$voiceRecordByUser[$ka]['last_flows'] : 0;
                $lb = isset($voiceRecordByUser[$kb]['last_flows']) ? (int)$voiceRecordByUser[$kb]['last_flows'] : 0;
                if ($lb !== $la) {
                    return $lb - $la;
                }
                $oa = isset($pppActiveMap[$ka]) ? 1 : 0;
                $ob = isset($pppActiveMap[$kb]) ? 1 : 0;
                if ($ob !== $oa) {
                    return $ob - $oa;
                }
                return strcasecmp($ka, $kb);
            });
        }
    }
}

$routerCardColors = array('bg-blue', 'bg-teal', 'bg-indigo', 'bg-cyan', 'bg-green', 'bg-purple');
$fixedVoiceIf = ngwVpnVoiceInterfaceName();
$voiceApps = ngwVpnVoiceAppPorts();
$voicePresets = ngwVpnVoicePresets();
$preselectNatUser = isset($_GET['preselect']) ? trim((string)$_GET['preselect']) : '';
$usersWithIp = array();
foreach ($pppSecrets as $sec) {
    $rem = isset($sec['remote-address']) ? trim((string)$sec['remote-address']) : '';
    if (!empty($sec['name']) && filter_var($rem, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $usersWithIp[] = $sec;
    }
}
$activeVoicePreset = 'custom';
foreach ($voicePresets as $pk => $pv) {
    if (trim((string)$voiceCfg['tcp_ports']) === $pv['tcp'] && trim((string)$voiceCfg['udp_ports']) === $pv['udp']) {
        $activeVoicePreset = $pk;
        break;
    }
}
?>

<style>
.ngw-vpn-wrap {
  --vpn-surface: #3a4149;
  --vpn-head-bg: #343b41;
  --vpn-surface-alt: #2f353a;
  --vpn-border: rgba(255, 255, 255, 0.12);
  --vpn-text: #f0f3f5;
  --vpn-muted: #9aa5b1;
  --vpn-accent: #20a8d8;
  --vpn-success: #4dbd74;
  --vpn-danger: #f86c6b;
  --vpn-warning: #ffc107;
  --vpn-code-bg: #1e2328;
  --vpn-code-fg: #dbe6f0;
  width: 100%;
  min-width: 0;
}
.ngw-vpn-wrap[data-theme="light"],
.ngw-vpn-wrap[data-theme="blue"],
.ngw-vpn-wrap[data-theme="green"],
.ngw-vpn-wrap[data-theme="pink"] {
  --vpn-surface: #ffffff;
  --vpn-head-bg: #f0f3f5;
  --vpn-surface-alt: #f7f9fb;
  --vpn-border: #d8dee4;
  --vpn-text: #23282c;
  --vpn-muted: #63707c;
  --vpn-code-bg: #1c2128;
  --vpn-code-fg: #e6edf3;
}
.ngw-vpn-wrap[data-theme="green"] { --vpn-accent: #4dbd74; }
.ngw-vpn-wrap[data-theme="pink"] { --vpn-accent: #e83e8c; }

.ngw-vpn-wrap * { box-sizing: border-box; }
.ngw-vpn-mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-variant-numeric: tabular-nums;
}
.ngw-vpn-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
}
.ngw-vpn-topbar-left,
.ngw-vpn-topbar-right {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.ngw-vpn-meta {
  font-size: 12px;
  color: var(--vpn-muted);
}
.ngw-vpn-status-dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  margin-right: 5px;
  vertical-align: middle;
}
.ngw-vpn-status-on { color: var(--vpn-success); font-weight: 600; font-size: 12px; }
.ngw-vpn-status-off { color: var(--vpn-muted); font-size: 12px; }
.ngw-vpn-status-dis { color: var(--vpn-danger); font-size: 12px; }

.ngw-vpn-tabs {
  display: flex;
  align-items: center;
  gap: 4px;
  border-bottom: 1px solid var(--vpn-border);
  padding: 0 12px;
  overflow-x: auto;
}
.ngw-vpn-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 14px;
  font-size: 13px;
  font-weight: 600;
  color: var(--vpn-muted);
  text-decoration: none !important;
  border-bottom: 2px solid transparent;
  white-space: nowrap;
  transition: color 0.15s ease, border-color 0.15s ease;
}
.ngw-vpn-tab:hover { color: var(--vpn-text); }
.ngw-vpn-tab.active {
  color: var(--vpn-accent);
  border-bottom-color: var(--vpn-accent);
}
.ngw-vpn-tab-count {
  font-size: 11px;
  opacity: 0.8;
  font-variant-numeric: tabular-nums;
}

.ngw-vpn-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.ngw-vpn-filters {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}
.ngw-vpn-seg {
  display: inline-flex;
  border: 1px solid var(--vpn-border);
  border-radius: 4px;
  overflow: hidden;
  background: var(--vpn-surface-alt);
}
.ngw-vpn-seg button {
  border: 0;
  background: transparent;
  color: var(--vpn-muted);
  padding: 5px 10px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.ngw-vpn-seg button.active {
  background: var(--vpn-accent);
  color: #ffffff;
}

.ngw-vpn-table-wrap {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.ngw-vpn-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.ngw-vpn-table th,
.ngw-vpn-table td {
  padding: 8px 10px;
  border-bottom: 1px solid var(--vpn-border);
  vertical-align: middle;
  text-align: left;
  white-space: nowrap;
}
.ngw-vpn-table th {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--vpn-muted);
  font-weight: 600;
}
.ngw-vpn-table tbody tr:hover {
  background: rgba(127, 127, 127, 0.08);
}

.ngw-vpn-copy-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: transparent;
  border: 1px solid var(--vpn-border);
  border-radius: 3px;
  padding: 2px 6px;
  font-size: 11.5px;
  color: inherit;
  cursor: pointer;
  margin: 1px 2px 1px 0;
}
.ngw-vpn-copy-link:hover {
  border-color: var(--vpn-accent);
  color: var(--vpn-accent);
}
.ngw-vpn-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 28px;
  padding: 0 7px;
  border-radius: 3px;
  border: 1px solid var(--vpn-border);
  background: transparent;
  color: inherit;
  cursor: pointer;
  font-size: 12px;
  gap: 4px;
  text-decoration: none !important;
}
.ngw-vpn-icon-btn:hover {
  border-color: var(--vpn-accent);
  color: var(--vpn-accent);
}
.ngw-vpn-icon-btn.is-danger:hover {
  border-color: var(--vpn-danger);
  color: var(--vpn-danger);
}
.ngw-vpn-icon-btn.is-success {
  border-color: rgba(77, 189, 116, 0.45);
  color: var(--vpn-success);
}
.ngw-vpn-icon-btn.is-primary {
  background: var(--vpn-accent);
  border-color: var(--vpn-accent);
  color: #ffffff;
}
.ngw-vpn-actions {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
}
.ngw-vpn-menu-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
}
.ngw-vpn-dots-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 28px;
  padding: 0;
  border-radius: 4px;
  border: 1px solid var(--vpn-border);
  background: var(--vpn-surface-alt);
  color: var(--vpn-text);
  cursor: pointer;
  font-size: 13px;
  transition: border-color 0.15s ease, background-color 0.15s ease, color 0.15s ease;
}
.ngw-vpn-dots-btn:hover,
.ngw-vpn-dots-btn[aria-expanded="true"] {
  background: var(--vpn-accent);
  border-color: var(--vpn-accent);
  color: #ffffff;
}
.ngw-vpn-dropdown {
  display: none;
  position: fixed;
  min-width: 172px;
  padding: 4px 0;
  border-radius: 4px;
  border: 1px solid var(--vpn-border);
  background: var(--vpn-surface);
  color: var(--vpn-text);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.34);
  z-index: 10020;
  text-align: left;
}
.ngw-vpn-dropdown.open {
  display: block;
}
.ngw-vpn-dropdown form {
  display: block;
  margin: 0;
}
.ngw-vpn-dd-item {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 7px 12px;
  border: 0;
  background: transparent;
  color: var(--vpn-text);
  font-size: 12.5px;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
  white-space: nowrap;
  text-decoration: none !important;
}
.ngw-vpn-dd-item i {
  width: 14px;
  text-align: center;
  font-size: 12px;
  opacity: 0.9;
}
.ngw-vpn-dd-item:hover {
  background: var(--vpn-accent);
  color: #ffffff;
}
.ngw-vpn-dd-item.is-danger {
  color: var(--vpn-danger);
}
.ngw-vpn-dd-item.is-danger:hover {
  background: var(--vpn-danger);
  color: #ffffff;
}
.ngw-vpn-dd-item.is-success i {
  color: var(--vpn-success);
}
.ngw-vpn-dd-item.is-success:hover i {
  color: #ffffff;
}
.ngw-vpn-dd-sep {
  height: 1px;
  margin: 4px 0;
  background: var(--vpn-border);
}

.ngw-vpn-router-card {
  position: relative;
  cursor: pointer;
  transition: transform 0.12s ease, opacity 0.12s ease;
}
.ngw-vpn-router-card:hover {
  transform: translateY(-1px);
}
.ngw-vpn-router-actions {
  margin-top: 6px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  font-size: 12px;
}
.ngw-vpn-router-actions a,
.ngw-vpn-router-actions button {
  background: transparent;
  border: 0;
  color: #ffffff;
  padding: 0;
  cursor: pointer;
  font-size: 12px;
  text-decoration: none;
  opacity: 0.92;
}
.ngw-vpn-router-actions a:hover,
.ngw-vpn-router-actions button:hover {
  opacity: 1;
  text-decoration: underline;
}

.ngw-vpn-empty {
  text-align: center;
  padding: 36px 16px;
  color: var(--vpn-muted);
}
.ngw-vpn-empty i {
  font-size: 30px;
  opacity: 0.45;
  margin-bottom: 10px;
  display: block;
}

.ngw-vpn-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 12px;
}
.ngw-vpn-form-grid.cols-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.ngw-vpn-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.ngw-vpn-field.span-2 { grid-column: span 2; }
.ngw-vpn-field label {
  font-size: 11.5px;
  font-weight: 600;
  color: var(--vpn-muted);
  margin: 0;
}
.ngw-vpn-input {
  width: 100%;
  height: 34px;
  padding: 5px 9px;
  font-size: 13px;
  border-radius: 3px;
  border: 1px solid var(--vpn-border);
  background: var(--vpn-surface-alt);
  color: var(--vpn-text);
}
.ngw-vpn-input:focus {
  outline: none;
  border-color: var(--vpn-accent);
}
.ngw-vpn-divider {
  border: 0;
  border-top: 1px solid var(--vpn-border);
  margin: 12px 0;
}

.ngw-vpn-nat-builder {
  border: 1px solid var(--vpn-border);
  border-radius: 4px;
  padding: 12px;
  margin-bottom: 14px;
  background: var(--vpn-surface-alt);
}
.ngw-vpn-nat-svcs {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin-top: 10px;
}
.ngw-vpn-nat-svc-box {
  border: 1px solid var(--vpn-border);
  border-radius: 4px;
  padding: 8px 10px;
  background: var(--vpn-surface);
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.ngw-vpn-nat-svc-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12px;
  font-weight: 600;
}
.ngw-vpn-nat-ext-preview {
  font-size: 11.5px;
  color: var(--vpn-accent);
}

.ngw-vpn-voice-panel {
  border: 1px solid var(--vpn-border);
  border-radius: 4px;
  margin-bottom: 14px;
  background: var(--vpn-surface-alt);
  overflow: hidden;
}
.ngw-vpn-voice-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding: 11px 14px;
  background: var(--vpn-head-bg);
  border-bottom: 1px solid var(--vpn-border);
}
.ngw-vpn-voice-title {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--vpn-text);
  display: flex;
  align-items: center;
  gap: 7px;
}
.ngw-vpn-voice-sub {
  font-size: 11.5px;
  color: var(--vpn-muted);
  margin-top: 2px;
}
.ngw-vpn-voice-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  padding: 14px;
}
.ngw-vpn-voice-box {
  border: 1px solid var(--vpn-border);
  border-radius: 4px;
  background: var(--vpn-surface);
  padding: 11px 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.ngw-vpn-voice-box-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.ngw-vpn-proto-label {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  font-weight: 700;
  color: var(--vpn-text);
}
.ngw-vpn-proto-tag {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  background: var(--vpn-accent);
  color: #ffffff;
}
.ngw-vpn-proto-tag.is-udp {
  background: var(--vpn-success);
}
.ngw-vpn-voice-textarea {
  width: 100%;
  min-height: 54px;
  padding: 7px 10px;
  font-size: 12.5px;
  line-height: 1.5;
  border-radius: 3px;
  border: 1px solid var(--vpn-border);
  background: var(--vpn-surface-alt);
  color: var(--vpn-text);
  resize: vertical;
}
.ngw-vpn-voice-textarea:focus {
  outline: none;
  border-color: var(--vpn-accent);
}
.ngw-vpn-voice-breakdown {
  font-size: 11px;
  line-height: 1.45;
  color: var(--vpn-muted);
}
.ngw-vpn-voice-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding: 10px 14px;
  background: var(--vpn-head-bg);
  border-top: 1px solid var(--vpn-border);
}
.ngw-vpn-route-summary {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  font-size: 12px;
  color: var(--vpn-muted);
}
.ngw-vpn-route-summary strong {
  color: var(--vpn-text);
}

.ngw-vpn-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.62);
  z-index: 10030;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 14px;
}
.ngw-vpn-modal-backdrop.open {
  display: flex;
}
.ngw-vpn-modal.card {
  width: 100%;
  max-width: 560px;
  max-height: calc(100dvh - 28px);
  margin: 0 !important;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  background: var(--vpn-surface);
  color: var(--vpn-text);
  border: 1px solid var(--vpn-border);
  border-radius: 4px;
  box-shadow: 0 14px 36px rgba(0, 0, 0, 0.45);
}
.ngw-vpn-modal.card.wide { max-width: 700px; }
.ngw-vpn-modal.card > .card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 11px 15px;
  background: var(--vpn-head-bg);
  border-bottom: 1px solid var(--vpn-border);
  color: var(--vpn-text);
  flex-shrink: 0;
}
.ngw-vpn-modal.card > .card-header h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 7px;
  color: var(--vpn-text);
}
.ngw-vpn-modal-close {
  border: 1px solid var(--vpn-border);
  border-radius: 3px;
  background: var(--vpn-surface-alt);
  color: var(--vpn-muted);
  width: 26px;
  height: 26px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  line-height: 1;
  cursor: pointer;
  padding: 0;
}
.ngw-vpn-modal-close:hover {
  color: var(--vpn-text);
  border-color: var(--vpn-accent);
}
.ngw-vpn-modal.card > .card-body,
.ngw-vpn-modal-form-body {
  padding: 15px;
  overflow-y: auto;
  flex: 1 1 auto;
}
.ngw-vpn-modal-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  padding: 10px 15px;
  background: var(--vpn-head-bg);
  border-top: 1px solid var(--vpn-border);
  flex-shrink: 0;
}
.ngw-vpn-modal-foot-right {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.ngw-vpn-code-shell {
  border: 1px solid var(--vpn-border);
  border-radius: 4px;
  overflow: hidden;
  background: var(--vpn-code-bg);
}
.ngw-vpn-code-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 7px 11px;
  background: rgba(255, 255, 255, 0.04);
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  font-size: 11.5px;
  color: #9aa5b1;
}
.ngw-vpn-codebox {
  display: block;
  width: 100%;
  min-height: 185px;
  padding: 11px 12px;
  border: 0;
  background: var(--vpn-code-bg);
  color: var(--vpn-code-fg);
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
  line-height: 1.55;
  resize: vertical;
  white-space: pre;
  overflow-x: auto;
}
.ngw-vpn-codebox:focus {
  outline: none;
}

.ngw-vpn-toast {
  position: fixed;
  bottom: 18px;
  right: 18px;
  z-index: 10050;
  background: var(--vpn-accent);
  color: #ffffff;
  padding: 8px 14px;
  border-radius: 4px;
  font-size: 12.5px;
  font-weight: 600;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
  opacity: 0;
  transform: translateY(8px);
  pointer-events: none;
  transition: opacity 0.16s ease, transform 0.16s ease;
}
.ngw-vpn-toast.show {
  opacity: 1;
  transform: translateY(0);
}

.ngw-vpn-confirm-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding-bottom: 11px;
  margin-bottom: 12px;
  border-bottom: 1px solid var(--vpn-border);
}
.ngw-vpn-confirm-icon {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: rgba(77, 189, 116, 0.16);
  color: var(--vpn-success);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
}
.ngw-vpn-confirm-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.ngw-vpn-confirm-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 9px 11px;
  border: 1px solid var(--vpn-border);
  border-radius: 4px;
  background: var(--vpn-surface-alt);
}
.ngw-vpn-confirm-svc {
  font-size: 11.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--vpn-muted);
  min-width: 60px;
}
.ngw-vpn-confirm-addr {
  font-size: 13px;
  font-weight: 600;
  color: var(--vpn-text);
  word-break: break-all;
}
.ngw-vpn-preset-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
}
.ngw-vpn-preset-row .ngw-vpn-seg {
  flex-wrap: wrap;
}
.ngw-vpn-purpose-list {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.ngw-vpn-purpose-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 11px;
  border: 1px solid var(--vpn-border);
  border-radius: 4px;
  background: var(--vpn-surface-alt);
  color: var(--vpn-text);
  font-size: 12.5px;
  font-weight: 500;
  cursor: pointer;
  user-select: none;
  margin: 0;
}
.ngw-vpn-purpose-chip:hover {
  border-color: var(--vpn-accent);
}
.ngw-vpn-purpose-chip input[type="checkbox"] {
  margin: 0;
  accent-color: var(--vpn-accent);
}
.ngw-vpn-gen-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

@media (max-width: 767px) {
  .ngw-vpn-form-grid,
  .ngw-vpn-form-grid.cols-3,
  .ngw-vpn-nat-svcs,
  .ngw-vpn-voice-grid {
    grid-template-columns: 1fr;
  }
  .ngw-vpn-field.span-2 { grid-column: span 1; }
}
</style>

<div class="row ngw-vpn-wrap" data-theme="<?= htmlspecialchars($activeTheme, ENT_QUOTES, 'UTF-8'); ?>">
  <div class="col-12">

    <?php if ($vpnMsg !== '') { ?>
      <div class="alert alert-success" role="status" style="margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;">
        <span><i class="fa fa-check-circle"></i> <?= htmlspecialchars($vpnMsg, ENT_QUOTES, 'UTF-8'); ?></span>
        <button type="button" onclick="this.parentElement.style.display='none'" style="background:none;border:0;color:inherit;cursor:pointer;">&times;</button>
      </div>
    <?php } ?>
    <?php if ($vpnErr !== '') { ?>
      <div class="alert alert-danger" role="alert" style="margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;">
        <span><i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($vpnErr, ENT_QUOTES, 'UTF-8'); ?></span>
        <button type="button" onclick="this.parentElement.style.display='none'" style="background:none;border:0;color:inherit;cursor:pointer;">&times;</button>
      </div>
    <?php } ?>

    <?php if ($activeRouter === null) { ?>
      <!-- =============================================================== -->
      <!-- VIEW 1: VPN ROUTER CARD LIST                                    -->
      <!-- =============================================================== -->
      <div class="card">
        <div class="card-header">
          <div class="ngw-vpn-topbar">
            <div class="ngw-vpn-topbar-left">
              <h3 class="card-title" style="margin:0;">
                <i class="fa fa-shield"></i> <?= ngwVpnText('VPN Routers', 'ভিপিএন রাউটার') ?>
                <span class="ngw-vpn-meta ngw-vpn-mono" style="margin-left:6px;">(<?= count($vpnRouters); ?>)</span>
              </h3>
              <?php if (!empty($vpnRouters)) { ?>
                <input type="search" id="vpnRouterSearch" class="ngw-vpn-input" placeholder="<?= ngwVpnText('Search...', 'অনুসন্ধান...'); ?>" style="width:180px;height:30px;">
              <?php } ?>
            </div>
            <div class="ngw-vpn-topbar-right">
              <button type="button" class="btn bg-primary" onclick="ngwVpnOpenRouterModal(null)">
                <i class="fa fa-plus"></i> <?= ngwVpnText('Add VPN Router', 'নতুন VPN রাউটার') ?>
              </button>
            </div>
          </div>
        </div>
        <div class="card-body">
          <?php if (empty($vpnRouters)) { ?>
            <div class="ngw-vpn-empty">
              <i class="fa fa-shield"></i>
              <div style="margin-bottom:12px;"><?= ngwVpnText('No VPN router added yet.', 'এখনো কোনো VPN রাউটার যুক্ত করা হয়নি।') ?></div>
              <button type="button" class="btn bg-primary" onclick="ngwVpnOpenRouterModal(null)">
                <i class="fa fa-plus"></i> <?= ngwVpnText('Add VPN Router', 'নতুন VPN রাউটার') ?>
              </button>
            </div>
          <?php } else { ?>
            <div class="row" id="vpnRouterGrid">
              <?php foreach ($vpnRouters as $idx => $vr) {
                $cardColor = $routerCardColors[$idx % count($routerCardColors)];
                $openHref = $baseVpnUrl . '&vpn_id=' . rawurlencode($vr['id']) . '&tab=users';
                $safeRouterJson = htmlspecialchars(json_encode(array(
                    'id' => $vr['id'],
                    'name' => $vr['name'],
                    'host' => $vr['host'],
                    'port' => $vr['port'],
                    'user' => $vr['user'],
                    'ros_ver' => $vr['ros_ver'],
                    'sstp_port' => $vr['sstp_port'],
                    'l2tp_ipsec_secret' => $vr['l2tp_ipsec_secret'],
                    'nat_start_port' => $vr['nat_start_port'],
                    'winbox_int_port' => $vr['winbox_int_port'],
                    'api_int_port' => $vr['api_int_port'],
                    'web_int_port' => $vr['web_int_port'],
                ), JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
              ?>
                <div class="col-6" data-vpn-router-card="<?= htmlspecialchars(strtolower($vr['name'] . ' ' . $vr['host']), ENT_QUOTES, 'UTF-8'); ?>">
                  <div class="box bmh-75 box-bordered <?= $cardColor; ?> ngw-vpn-router-card" onclick="if(!event.target.closest('.ngw-vpn-router-actions')){window.location.href=<?= htmlspecialchars(json_encode($openHref), ENT_QUOTES, 'UTF-8'); ?>;}">
                    <div class="box-group">
                      <div class="box-group-icon">
                        <span><i class="fa fa-shield"></i></span>
                      </div>
                      <div class="box-group-area">
                        <div style="font-weight:700;font-size:15px;line-height:1.3;">
                          <?= htmlspecialchars($vr['name'], ENT_QUOTES, 'UTF-8'); ?>
                          <span class="ngw-vpn-mono" style="font-size:11px;font-weight:500;opacity:0.85;margin-left:4px;">ROS v<?= (int)$vr['ros_ver']; ?></span>
                        </div>
                        <div class="ngw-vpn-mono" style="font-size:12px;opacity:0.92;margin-top:2px;">
                          <?= htmlspecialchars($vr['host'], ENT_QUOTES, 'UTF-8'); ?>:<?= (int)$vr['port']; ?>
                          &nbsp;&middot;&nbsp; SSTP :<?= (int)$vr['sstp_port']; ?>
                          &nbsp;&middot;&nbsp; NAT <?= (int)$vr['nat_start_port']; ?>+
                        </div>
                        <div class="ngw-vpn-router-actions">
                          <a href="<?= htmlspecialchars($openHref, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-external-link"></i> <?= ngwVpnText('Open', 'খুলুন') ?></a>
                          <button type="button" onclick='ngwVpnOpenRouterModal(<?= $safeRouterJson; ?>)'><i class="fa fa-edit"></i> <?= ngwVpnText('Edit', 'এডিট') ?></button>
                          <form method="post" action="<?= htmlspecialchars($baseVpnUrl, ENT_QUOTES, 'UTF-8'); ?>" style="display:inline;" onsubmit="return confirm(<?= htmlspecialchars(json_encode(ngwVpnText('Remove VPN Router "' . $vr['name'] . '"?', 'VPN রাউটার "' . $vr['name'] . '" মুছে ফেলবেন?')), ENT_QUOTES, 'UTF-8'); ?>);">
                            <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="vpn_action" value="delete_router">
                            <input type="hidden" name="del_router_id" value="<?= htmlspecialchars($vr['id'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit"><i class="fa fa-trash"></i> <?= ngwVpnText('Delete', 'ডিলিট') ?></button>
                          </form>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php } ?>
            </div>
          <?php } ?>
        </div>
      </div>

    <?php } else {
      $workspaceBaseUrl = $baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']);
      $activeRouterJson = htmlspecialchars(json_encode(array(
          'id' => $activeRouter['id'],
          'name' => $activeRouter['name'],
          'host' => $activeRouter['host'],
          'port' => $activeRouter['port'],
          'user' => $activeRouter['user'],
          'ros_ver' => $activeRouter['ros_ver'],
          'sstp_port' => $activeRouter['sstp_port'],
          'l2tp_ipsec_secret' => $activeRouter['l2tp_ipsec_secret'],
          'nat_start_port' => $activeRouter['nat_start_port'],
          'winbox_int_port' => $activeRouter['winbox_int_port'],
          'api_int_port' => $activeRouter['api_int_port'],
          'web_int_port' => $activeRouter['web_int_port'],
      ), JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    ?>
      <!-- =============================================================== -->
      <!-- VIEW 2: ACTIVE VPN ROUTER WORKSPACE                             -->
      <!-- =============================================================== -->
      <div class="card">
        <div class="card-header">
          <div class="ngw-vpn-topbar">
            <div class="ngw-vpn-topbar-left">
              <a href="<?= htmlspecialchars($baseVpnUrl, ENT_QUOTES, 'UTF-8'); ?>" class="ngw-vpn-icon-btn" title="<?= ngwVpnText('VPN Routers', 'রাউটার লিস্ট') ?>">
                <i class="fa fa-arrow-left"></i> <?= ngwVpnText('Routers', 'রাউটার') ?>
              </a>
              <h3 class="card-title" style="margin:0;">
                <i class="fa fa-shield"></i> <?= htmlspecialchars($activeRouter['name'], ENT_QUOTES, 'UTF-8'); ?>
              </h3>
              <?php if (!$isConnected) { ?>
                <span class="ngw-vpn-status-dis"><span class="ngw-vpn-status-dot" style="background:var(--vpn-danger);"></span><?= ngwVpnText('Offline', 'অফলাইন') ?></span>
              <?php } ?>
            </div>
            <div class="ngw-vpn-topbar-right">
              <button type="button" class="ngw-vpn-icon-btn" onclick='ngwVpnOpenSettingsModal(<?= $activeRouterJson; ?>)'>
                <i class="fa fa-cog"></i> <?= ngwVpnText('Settings', 'সেটিংস') ?>
              </button>
              <a href="<?= htmlspecialchars($workspaceBaseUrl . '&tab=' . rawurlencode($activeTab), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-vpn-icon-btn" title="<?= ngwVpnText('Refresh', 'রিলোড') ?>">
                <i class="fa fa-refresh"></i>
              </a>
            </div>
          </div>
        </div>

        <?php if (!$isConnected) { ?>
          <div class="card-body">
            <div class="ngw-vpn-empty">
              <i class="fa fa-plug"></i>
              <div style="margin-bottom:6px;font-weight:600;color:var(--vpn-text);"><?= ngwVpnText('Unable to connect to RouterOS API', 'RouterOS API-তে সংযোগ করা যায়নি') ?></div>
              <?php if ($connError !== '') { ?>
                <div class="ngw-vpn-meta ngw-vpn-mono" style="margin-bottom:14px;"><?= htmlspecialchars($connError, ENT_QUOTES, 'UTF-8'); ?></div>
              <?php } ?>
              <div style="display:inline-flex;gap:8px;">
                <a href="<?= htmlspecialchars($workspaceBaseUrl . '&tab=' . rawurlencode($activeTab), ENT_QUOTES, 'UTF-8'); ?>" class="btn bg-primary"><i class="fa fa-refresh"></i> <?= ngwVpnText('Retry', 'পুনরায় চেষ্টা') ?></a>
                <button type="button" class="btn bg-secondary" onclick='ngwVpnOpenRouterModal(<?= $activeRouterJson; ?>, true)'><i class="fa fa-cog"></i> <?= ngwVpnText('Edit Router', 'রাউটার এডিট') ?></button>
              </div>
            </div>
          </div>
        <?php } else { ?>
          <!-- 3 Clean Tabs -->
          <div class="ngw-vpn-tabs">
            <a class="ngw-vpn-tab <?= $activeTab === 'users' ? 'active' : ''; ?>" href="<?= htmlspecialchars($workspaceBaseUrl . '&tab=users', ENT_QUOTES, 'UTF-8'); ?>">
              <i class="fa fa-users"></i> <?= ngwVpnText('VPN Users', 'ভিপিএন ইউজার') ?>
              <span class="ngw-vpn-tab-count">(<?= count($pppSecrets); ?>)</span>
            </a>
            <a class="ngw-vpn-tab <?= $activeTab === 'nat' ? 'active' : ''; ?>" href="<?= htmlspecialchars($workspaceBaseUrl . '&tab=nat', ENT_QUOTES, 'UTF-8'); ?>">
              <i class="fa fa-random"></i> <?= ngwVpnText('Port Forward', 'পোর্ট ফরওয়ার্ড') ?>
              <?php if ($dstNatCount !== null) { ?>
                <span class="ngw-vpn-tab-count">(<?= (int)$dstNatCount; ?>)</span>
              <?php } ?>
            </a>
            <a class="ngw-vpn-tab <?= $activeTab === 'voice' ? 'active' : ''; ?>" href="<?= htmlspecialchars($workspaceBaseUrl . '&tab=voice', ENT_QUOTES, 'UTF-8'); ?>">
              <i class="fa fa-whatsapp"></i> <?= ngwVpnText('Voice Call', 'ভয়েস কল') ?>
              <span class="ngw-vpn-tab-count">(<?= count($voiceOptInSecrets); ?>)</span>
            </a>
          </div>

          <div class="card-body">

            <?php if ($activeTab === 'users') { ?>
              <!-- ======================================================= -->
              <!-- TAB 1: VPN USERS (PPP SECRETS)                          -->
              <!-- ======================================================= -->
              <div class="ngw-vpn-toolbar">
                <div class="ngw-vpn-filters">
                  <button type="button" class="btn bg-primary" onclick="ngwVpnOpenUserModal(null)">
                    <i class="fa fa-plus"></i> <?= ngwVpnText('Create User', 'নতুন ইউজার') ?>
                  </button>
                  <div class="ngw-vpn-seg" id="vpnSvcFilter">
                    <button type="button" class="active" data-svc="all"><?= ngwVpnText('All', 'সব') ?></button>
                    <button type="button" data-svc="sstp">SSTP</button>
                    <button type="button" data-svc="l2tp">L2TP</button>
                    <button type="button" data-svc="pptp">PPTP</button>
                  </div>
                </div>
                <div>
                  <input type="search" id="vpnUserSearch" class="ngw-vpn-input" placeholder="<?= ngwVpnText('Search user or IP...', 'ইউজার বা IP খুঁজুন...'); ?>" style="width:210px;height:32px;">
                </div>
              </div>

              <?php if (empty($pppSecrets)) { ?>
                <div class="ngw-vpn-empty">
                  <i class="fa fa-user-secret"></i>
                  <div style="margin-bottom:12px;"><?= ngwVpnText('No PPP secrets found.', 'কোনো PPP Secret পাওয়া যায়নি।') ?></div>
                  <button type="button" class="btn bg-primary" onclick="ngwVpnOpenUserModal(null)">
                    <i class="fa fa-plus"></i> <?= ngwVpnText('Create User', 'নতুন ইউজার') ?>
                  </button>
                </div>
              <?php } else { ?>
                <div class="ngw-vpn-table-wrap">
                  <table class="ngw-vpn-table" id="vpnUsersTable">
                    <thead>
                      <tr>
                        <th><?= ngwVpnText('User', 'ইউজার') ?></th>
                        <th><?= ngwVpnText('Service', 'সার্ভিস') ?></th>
                        <th><?= ngwVpnText('Static IP', 'স্ট্যাটিক IP') ?></th>
                        <th><?= ngwVpnText('Profile', 'প্রোফাইল') ?></th>
                        <th><?= ngwVpnText('Forwarded Ports', 'ফরওয়ার্ড পোর্ট') ?></th>
                        <th><?= ngwVpnText('Status', 'স্ট্যাটাস') ?></th>
                        <th><?= ngwVpnText('Voice', 'ভয়েস') ?></th>
                        <th style="text-align:right;"><?= ngwVpnText('Actions', 'অ্যাকশন') ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($pppSecrets as $sec) {
                        $sId = isset($sec['.id']) ? (string)$sec['.id'] : '';
                        $sName = isset($sec['name']) ? (string)$sec['name'] : '';
                        $sPass = isset($sec['password']) ? (string)$sec['password'] : '';
                        $sService = strtolower(isset($sec['service']) && $sec['service'] !== '' ? (string)$sec['service'] : 'any');
                        $sProfile = isset($sec['profile']) ? (string)$sec['profile'] : 'default';
                        $sRemote = isset($sec['remote-address']) ? trim((string)$sec['remote-address']) : '';
                        $sComment = isset($sec['comment']) ? trim((string)$sec['comment']) : '';
                        $sDisabled = isset($sec['disabled']) && ($sec['disabled'] === 'true' || $sec['disabled'] === 'yes');
                        $isOnline = isset($pppActiveMap[strtolower($sName)]);
                        $isVoiceOn = ngwVpnIsVoiceUser($voiceCfg, $activeRouter['id'], $sName);
                        $uNat = isset($natByUser[strtolower($sName)]) ? $natByUser[strtolower($sName)] : array();

                        $secPayload = htmlspecialchars(json_encode(array(
                            'id' => $sId,
                            'name' => $sName,
                            'password' => $sPass,
                            'service' => $sService,
                            'profile' => $sProfile,
                            'remote_ip' => $sRemote,
                            'comment' => $sComment,
                            'voice' => $isVoiceOn,
                            'if_name' => $fixedVoiceIf,
                        ), JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                      ?>
                        <tr data-vpn-row-svc="<?= htmlspecialchars($sService, ENT_QUOTES, 'UTF-8'); ?>" data-vpn-row-search="<?= htmlspecialchars(strtolower($sName . ' ' . $sRemote . ' ' . $sProfile . ' ' . $sComment), ENT_QUOTES, 'UTF-8'); ?>">
                          <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($sName, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if ($sComment !== '') { ?>
                              <div class="ngw-vpn-meta" style="max-width:170px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($sComment, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php } ?>
                          </td>
                          <td class="ngw-vpn-mono" style="text-transform:uppercase;font-size:12px;"><?= htmlspecialchars($sService, ENT_QUOTES, 'UTF-8'); ?></td>
                          <td class="ngw-vpn-mono"><?= $sRemote !== '' ? htmlspecialchars($sRemote, ENT_QUOTES, 'UTF-8') : '<span class="ngw-vpn-meta">&mdash;</span>'; ?></td>
                          <td><?= htmlspecialchars($sProfile, ENT_QUOTES, 'UTF-8'); ?></td>
                          <td>
                            <?php if (!empty($uNat)) {
                              foreach (array('winbox' => 'WB', 'api' => 'API', 'web' => 'WEB') as $svcKey => $svcLabel) {
                                if (isset($uNat[$svcKey]) && !empty($uNat[$svcKey]['dst-port'])) {
                                  $extP = (string)$uNat[$svcKey]['dst-port'];
                                  $copyTarget = $activeRouter['host'] . ':' . $extP;
                            ?>
                              <button type="button" class="ngw-vpn-copy-link ngw-vpn-mono" onclick='ngwVpnCopyText(<?= htmlspecialchars(json_encode($copyTarget), ENT_QUOTES, 'UTF-8'); ?>)' title="<?= htmlspecialchars($copyTarget, ENT_QUOTES, 'UTF-8'); ?>">
                                <?= $svcLabel; ?>:<?= htmlspecialchars($extP, ENT_QUOTES, 'UTF-8'); ?> <i class="fa fa-copy" style="font-size:10px;opacity:0.7;"></i>
                              </button>
                            <?php } } } else { ?>
                              <a href="<?= htmlspecialchars($workspaceBaseUrl . '&tab=nat&preselect=' . rawurlencode($sName), ENT_QUOTES, 'UTF-8'); ?>" class="ngw-vpn-meta" style="text-decoration:none;"><?= ngwVpnText('+ Forward', '+ ফরওয়ার্ড') ?></a>
                            <?php } ?>
                          </td>
                          <td>
                            <?php if ($sDisabled) { ?>
                              <span class="ngw-vpn-status-dis"><span class="ngw-vpn-status-dot" style="background:var(--vpn-danger);"></span><?= ngwVpnText('Disabled', 'ডিজেবল') ?></span>
                            <?php } elseif ($isOnline) { ?>
                              <span class="ngw-vpn-status-on"><span class="ngw-vpn-status-dot" style="background:var(--vpn-success);"></span><?= ngwVpnText('Online', 'অনলাইন') ?></span>
                            <?php } else { ?>
                              <span class="ngw-vpn-status-off"><span class="ngw-vpn-status-dot" style="background:var(--vpn-muted);"></span><?= ngwVpnText('Offline', 'অফলাইন') ?></span>
                            <?php } ?>
                          </td>
                          <td>
                            <?php if ($isVoiceOn) { ?>
                              <span class="ngw-vpn-status-on"><i class="fa fa-whatsapp"></i> <?= ngwVpnText('ON', 'চালু') ?></span>
                            <?php } else { ?>
                              <span class="ngw-vpn-meta">&mdash;</span>
                            <?php } ?>
                          </td>
                          <td style="text-align:right;">
                            <div class="ngw-vpn-menu-wrap">
                              <button type="button" class="ngw-vpn-dots-btn" onclick="ngwVpnToggleMenu(this, event)" aria-haspopup="true" aria-expanded="false" title="<?= ngwVpnText('Actions', 'অ্যাকশন') ?>">
                                <i class="fa fa-ellipsis-v"></i>
                              </button>
                              <div class="ngw-vpn-dropdown" role="menu">
                                <button type="button" class="ngw-vpn-dd-item" role="menuitem" onclick='ngwVpnCloseAllMenus();ngwVpnOpenClientScript(<?= $secPayload; ?>)'>
                                  <i class="fa fa-terminal"></i> <?= ngwVpnText('Script (v6 / v7)', 'স্ক্রিপ্ট (v6 / v7)') ?>
                                </button>
                                <button type="button" class="ngw-vpn-dd-item" role="menuitem" onclick='ngwVpnCloseAllMenus();ngwVpnOpenUserModal(<?= $secPayload; ?>)'>
                                  <i class="fa fa-pencil"></i> <?= ngwVpnText('Edit', 'এডিট') ?>
                                </button>
                                <form method="post" action="<?= htmlspecialchars($workspaceBaseUrl . '&tab=users', ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="vpn_action" value="toggle_secret">
                                  <input type="hidden" name="sec_id" value="<?= htmlspecialchars($sId, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="u_name" value="<?= htmlspecialchars($sName, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="target_disabled" value="<?= $sDisabled ? '0' : '1'; ?>">
                                  <button type="submit" class="ngw-vpn-dd-item <?= $sDisabled ? 'is-success' : ''; ?>" role="menuitem">
                                    <i class="fa fa-power-off"></i> <?= $sDisabled ? ngwVpnText('Enable', 'ইনেবল') : ngwVpnText('Disable', 'ডিজেবল') ?>
                                  </button>
                                </form>
                                <form method="post" action="<?= htmlspecialchars($workspaceBaseUrl . '&tab=users', ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="vpn_action" value="toggle_voice_user">
                                  <input type="hidden" name="username" value="<?= htmlspecialchars($sName, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="voice_enabled" value="<?= $isVoiceOn ? '0' : '1'; ?>">
                                  <input type="hidden" name="return_tab" value="users">
                                  <button type="submit" class="ngw-vpn-dd-item <?= $isVoiceOn ? '' : 'is-success'; ?>" role="menuitem">
                                    <i class="fa fa-whatsapp"></i> <?= $isVoiceOn ? ngwVpnText('Voice Call: OFF', 'ভয়েস কল বন্ধ') : ngwVpnText('Voice Call: ON', 'ভয়েস কল চালু') ?>
                                  </button>
                                </form>
                                <div class="ngw-vpn-dd-sep"></div>
                                <form method="post" action="<?= htmlspecialchars($workspaceBaseUrl . '&tab=users', ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode(ngwVpnText('Remove VPN user "' . $sName . '" and its linked NAT rules?', 'ইউজার "' . $sName . '" এবং এর সাথে যুক্ত NAT রুল মুছে ফেলবেন?')), ENT_QUOTES, 'UTF-8'); ?>);">
                                  <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="vpn_action" value="delete_secret">
                                  <input type="hidden" name="sec_id" value="<?= htmlspecialchars($sId, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="u_name" value="<?= htmlspecialchars($sName, ENT_QUOTES, 'UTF-8'); ?>">
                                  <button type="submit" class="ngw-vpn-dd-item is-danger" role="menuitem">
                                    <i class="fa fa-trash"></i> <?= ngwVpnText('Remove', 'রিমুভ') ?>
                                  </button>
                                </form>
                              </div>
                            </div>
                          </td>
                        </tr>
                      <?php } ?>
                    </tbody>
                  </table>
                </div>
              <?php } ?>

            <?php } elseif ($activeTab === 'nat') { ?>
              <!-- ======================================================= -->
              <!-- TAB 2: PORT FORWARDING (LIST-ONLY + FORWARD POPUP)      -->
              <!-- ======================================================= -->
              <div class="ngw-vpn-toolbar">
                <div class="ngw-vpn-filters">
                  <button type="button" class="btn bg-primary" onclick="ngwVpnOpenNatModal()">
                    <i class="fa fa-random"></i> <?= ngwVpnText('Forward', 'ফরওয়ার্ড') ?>
                  </button>
                  <div class="ngw-vpn-seg" id="vpnNatFilter">
                    <button type="button" class="active" data-nat-filter="dstnat">DST-NAT (<?= $dstNatCount; ?>)</button>
                    <button type="button" data-nat-filter="all"><?= ngwVpnText('All NAT', 'সব NAT') ?> (<?= count($vpnNatRules); ?>)</button>
                    <button type="button" data-nat-filter="active"><?= ngwVpnText('Active', 'চালু') ?></button>
                    <button type="button" data-nat-filter="disabled"><?= ngwVpnText('Disabled', 'ডিজেবল') ?></button>
                  </div>
                </div>
                <?php if (!empty($vpnNatRules)) { ?>
                  <div>
                    <input type="search" id="vpnNatSearch" class="ngw-vpn-input" placeholder="<?= ngwVpnText('Search user, IP, port...', 'ইউজার, IP বা পোর্ট খুঁজুন...'); ?>" style="width:220px;height:32px;">
                  </div>
                <?php } ?>
              </div>

              <?php if (empty($vpnNatRules)) { ?>
                <div class="ngw-vpn-empty">
                  <i class="fa fa-random"></i>
                  <div style="margin-bottom:12px;"><?= ngwVpnText('No NAT rules found.', 'কোনো NAT রুল পাওয়া যায়নি।') ?></div>
                  <button type="button" class="btn bg-primary" onclick="ngwVpnOpenNatModal()">
                    <i class="fa fa-random"></i> <?= ngwVpnText('Forward', 'ফরওয়ার্ড') ?>
                  </button>
                </div>
              <?php } else { ?>
                <div class="ngw-vpn-table-wrap">
                  <table class="ngw-vpn-table" id="vpnNatTable">
                    <thead>
                      <tr>
                        <th><?= ngwVpnText('User / Comment', 'ইউজার / কমেন্ট') ?></th>
                        <th><?= ngwVpnText('Service', 'সার্ভিস') ?></th>
                        <th><?= ngwVpnText('External Access', 'এক্সটারনাল অ্যাক্সেস') ?></th>
                        <th><?= ngwVpnText('Internal Target', 'ইন্টারনাল টার্গেট') ?></th>
                        <th><?= ngwVpnText('Status', 'স্ট্যাটাস') ?></th>
                        <th style="text-align:right;"><?= ngwVpnText('Actions', 'অ্যাকশন') ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($vpnNatRules as $nr) {
                        $nId = isset($nr['.id']) ? (string)$nr['.id'] : '';
                        $nUser = isset($nr['_vpn_user']) ? (string)$nr['_vpn_user'] : '';
                        $nSvc = strtoupper(isset($nr['_vpn_service']) ? (string)$nr['_vpn_service'] : '');
                        $nIsDstNat = !empty($nr['_is_dstnat']);
                        $nComment = isset($nr['_nat_comment']) ? (string)$nr['_nat_comment'] : '';
                        $nChain = isset($nr['_nat_chain']) ? (string)$nr['_nat_chain'] : (isset($nr['chain']) ? (string)$nr['chain'] : 'dstnat');
                        $nAction = isset($nr['_nat_action']) ? (string)$nr['_nat_action'] : (isset($nr['action']) ? (string)$nr['action'] : 'dst-nat');
                        $nProto = strtolower(trim(isset($nr['protocol']) ? (string)$nr['protocol'] : ''));
                        $nExtPort = isset($nr['dst-port']) ? trim((string)$nr['dst-port']) : '';
                        $nToIp = isset($nr['to-addresses']) ? trim((string)$nr['to-addresses']) : '';
                        $nToPort = isset($nr['to-ports']) ? trim((string)$nr['to-ports']) : '';
                        $nSrcAddr = isset($nr['src-address']) ? trim((string)$nr['src-address']) : '';
                        $nDisabled = isset($nr['disabled']) && ($nr['disabled'] === 'true' || $nr['disabled'] === 'yes');
                        $extHostPort = ($nExtPort !== '') ? ($activeRouter['host'] . ':' . $nExtPort) : '';
                        $internalTarget = $nIsDstNat
                            ? ($nToIp . ($nToPort !== '' ? (':' . $nToPort) : ''))
                            : ($nSrcAddr !== '' ? $nSrcAddr : (isset($nr['dst-address']) && $nr['dst-address'] !== '' ? (string)$nr['dst-address'] : 'All'));
                        $showSubComment = ($nComment !== '' && strcasecmp($nComment, $nUser) !== 0 && strpos($nComment, 'ngw-vpn:') !== 0);
                        $searchBlob = strtolower($nUser . ' ' . $nComment . ' ' . $nSvc . ' ' . $nExtPort . ' ' . $nToIp . ' ' . $nToPort . ' ' . $nSrcAddr);
                        $natEditPayload = htmlspecialchars(json_encode(array(
                            'id' => $nId,
                            'chain' => $nChain,
                            'action' => $nAction,
                            'protocol' => $nProto,
                            'dst_port' => $nExtPort,
                            'to_addresses' => $nToIp,
                            'to_ports' => $nToPort,
                            'comment' => $nComment,
                            'disabled' => $nDisabled,
                            'user' => $nUser,
                        ), JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                      ?>
                        <tr data-nat-dst="<?= $nIsDstNat ? '1' : '0'; ?>" data-nat-dis="<?= $nDisabled ? '1' : '0'; ?>" data-nat-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>" style="<?= $nIsDstNat ? '' : 'display:none;'; ?>">
                          <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($nUser, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php if ($showSubComment) { ?>
                              <div class="ngw-vpn-meta" style="max-width:190px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($nComment, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php } ?>
                          </td>
                          <td class="ngw-vpn-mono" style="font-size:12px;"><?= htmlspecialchars($nSvc, ENT_QUOTES, 'UTF-8'); ?></td>
                          <td>
                            <?php if ($extHostPort !== '') { ?>
                              <button type="button" class="ngw-vpn-copy-link ngw-vpn-mono" onclick='ngwVpnCopyText(<?= htmlspecialchars(json_encode($extHostPort), ENT_QUOTES, 'UTF-8'); ?>)'>
                                <?= htmlspecialchars($extHostPort, ENT_QUOTES, 'UTF-8'); ?> <i class="fa fa-copy" style="font-size:11px;opacity:0.75;"></i>
                              </button>
                            <?php } else { ?>
                              <span class="ngw-vpn-meta">&mdash;</span>
                            <?php } ?>
                          </td>
                          <td class="ngw-vpn-mono"><?= $internalTarget !== '' ? htmlspecialchars($internalTarget, ENT_QUOTES, 'UTF-8') : '<span class="ngw-vpn-meta">&mdash;</span>'; ?></td>
                          <td>
                            <?php if ($nDisabled) { ?>
                              <span class="ngw-vpn-status-dis"><span class="ngw-vpn-status-dot" style="background:var(--vpn-danger);"></span><?= ngwVpnText('Disabled', 'ডিজেবল') ?></span>
                            <?php } else { ?>
                              <span class="ngw-vpn-status-on"><span class="ngw-vpn-status-dot" style="background:var(--vpn-success);"></span><?= ngwVpnText('Active', 'চালু') ?></span>
                            <?php } ?>
                          </td>
                          <td style="text-align:right;">
                            <div class="ngw-vpn-menu-wrap">
                              <button type="button" class="ngw-vpn-dots-btn" onclick="ngwVpnToggleMenu(this, event)" aria-haspopup="true" aria-expanded="false" title="<?= ngwVpnText('Actions', 'অ্যাকশন') ?>">
                                <i class="fa fa-ellipsis-v"></i>
                              </button>
                              <div class="ngw-vpn-dropdown" role="menu">
                                <?php if ($extHostPort !== '') { ?>
                                  <button type="button" class="ngw-vpn-dd-item" role="menuitem" onclick='ngwVpnCloseAllMenus();ngwVpnCopyText(<?= htmlspecialchars(json_encode($extHostPort), ENT_QUOTES, 'UTF-8'); ?>)'>
                                    <i class="fa fa-copy"></i> <?= ngwVpnText('Copy Address', 'অ্যাড্রেস কপি') ?>
                                  </button>
                                <?php } ?>
                                <button type="button" class="ngw-vpn-dd-item" role="menuitem" onclick='ngwVpnCloseAllMenus();ngwVpnOpenNatEditModal(<?= $natEditPayload; ?>)'>
                                  <i class="fa fa-pencil"></i> <?= ngwVpnText('Edit', 'এডিট') ?>
                                </button>
                                <form method="post" action="<?= htmlspecialchars($workspaceBaseUrl . '&tab=nat', ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="vpn_action" value="toggle_nat">
                                  <input type="hidden" name="nat_id" value="<?= htmlspecialchars($nId, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="target_disabled" value="<?= $nDisabled ? '0' : '1'; ?>">
                                  <button type="submit" class="ngw-vpn-dd-item <?= $nDisabled ? 'is-success' : ''; ?>" role="menuitem">
                                    <i class="fa fa-power-off"></i> <?= $nDisabled ? ngwVpnText('Enable', 'ইনেবল') : ngwVpnText('Disable', 'ডিজেবল') ?>
                                  </button>
                                </form>
                                <div class="ngw-vpn-dd-sep"></div>
                                <form method="post" action="<?= htmlspecialchars($workspaceBaseUrl . '&tab=nat', ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirm(<?= htmlspecialchars(json_encode(ngwVpnText('Remove this NAT rule?', 'এই NAT রুলটি মুছে ফেলবেন?')), ENT_QUOTES, 'UTF-8'); ?>);">
                                  <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="vpn_action" value="delete_nat">
                                  <input type="hidden" name="nat_id" value="<?= htmlspecialchars($nId, ENT_QUOTES, 'UTF-8'); ?>">
                                  <button type="submit" class="ngw-vpn-dd-item is-danger" role="menuitem">
                                    <i class="fa fa-trash"></i> <?= ngwVpnText('Remove', 'রিমুভ') ?>
                                  </button>
                                </form>
                              </div>
                            </div>
                          </td>
                        </tr>
                      <?php } ?>
                    </tbody>
                  </table>
                </div>
              <?php } ?>

            <?php } elseif ($activeTab === 'voice') {
              $detectedVoiceCount = 0;
              foreach ($voiceOptInSecrets as $vSec) {
                  $vk = strtolower(isset($vSec['name']) ? (string)$vSec['name'] : '');
                  if (isset($voiceRecordByUser[$vk]) && ($voiceRecordByUser[$vk]['source'] === 'detected' || !empty($voiceRecordByUser[$vk]['last_seen']))) {
                      $detectedVoiceCount++;
                  }
              }
            ?>
              <!-- ======================================================= -->
              <!-- TAB 3: VOICE CALL (LIST-ONLY + ADD VOICE CALL POPUP)    -->
              <!-- ======================================================= -->
              <div class="ngw-vpn-toolbar">
                <div class="ngw-vpn-filters">
                  <button type="button" class="btn bg-primary" onclick="ngwVpnOpenVoiceModal()">
                    <i class="fa fa-plus"></i> <?= ngwVpnText('Add Voice Call', 'অ্যাড ভয়েস কল') ?>
                  </button>
                  <form method="post" action="<?= htmlspecialchars($workspaceBaseUrl . '&tab=voice', ENT_QUOTES, 'UTF-8'); ?>" style="display:inline;">
                    <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="vpn_action" value="scan_voice_traffic">
                    <button type="submit" class="ngw-vpn-icon-btn" style="height:32px;padding:0 10px;">
                      <i class="fa fa-refresh"></i> <?= ngwVpnText('Scan Live', 'লাইভ স্ক্যান') ?>
                    </button>
                  </form>
                  <span class="ngw-vpn-meta ngw-vpn-mono">
                    <?= count($voiceOptInSecrets); ?>
                    <?php if ($detectedVoiceCount > 0) { ?>
                      &middot; <span class="ngw-vpn-status-on"><?= $detectedVoiceCount; ?> <?= ngwVpnText('Detected', 'ডিটেক্টেড') ?></span>
                    <?php } ?>
                  </span>
                </div>
                <?php if (!empty($voiceOptInSecrets)) { ?>
                  <div>
                    <input type="search" id="vpnVoiceSearch" class="ngw-vpn-input" placeholder="<?= ngwVpnText('Search user or IP...', 'ইউজার বা IP খুঁজুন...'); ?>" style="width:210px;height:32px;">
                  </div>
                <?php } ?>
              </div>

              <?php if (empty($voiceOptInSecrets)) { ?>
                <div class="ngw-vpn-empty">
                  <i class="fa fa-whatsapp"></i>
                  <div style="margin-bottom:12px;"><?= ngwVpnText('No Voice Call users yet.', 'এখনো কোনো ভয়েস কল ইউজার নেই।') ?></div>
                  <button type="button" class="btn bg-primary" onclick="ngwVpnOpenVoiceModal()">
                    <i class="fa fa-plus"></i> <?= ngwVpnText('Add Voice Call', 'অ্যাড ভয়েস কল') ?>
                  </button>
                </div>
              <?php } else { ?>
                <div class="ngw-vpn-table-wrap">
                  <table class="ngw-vpn-table" id="vpnVoiceTable">
                    <thead>
                      <tr>
                        <th><?= ngwVpnText('User', 'ইউজার') ?></th>
                        <th><?= ngwVpnText('Interface / Table', 'ইন্টারফেস / টেবিল') ?></th>
                        <th><?= ngwVpnText('Traffic', 'ট্রাফিক') ?></th>
                        <th><?= ngwVpnText('Static IP', 'স্ট্যাটিক IP') ?></th>
                        <th><?= ngwVpnText('Status', 'স্ট্যাটাস') ?></th>
                        <th style="text-align:right;"><?= ngwVpnText('Actions', 'অ্যাকশন') ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($voiceOptInSecrets as $sec) {
                        $sName = isset($sec['name']) ? (string)$sec['name'] : '';
                        $sKey = strtolower($sName);
                        $sService = strtolower(isset($sec['service']) && $sec['service'] !== '' ? (string)$sec['service'] : 'any');
                        $sRemote = isset($sec['remote-address']) ? trim((string)$sec['remote-address']) : '';
                        $sDisabled = isset($sec['disabled']) && ($sec['disabled'] === 'true' || $sec['disabled'] === 'yes');
                        $isOnline = isset($pppActiveMap[$sKey]);
                        $ifName = $fixedVoiceIf;
                        $savedRec = isset($voiceRecordByUser[$sKey]) ? $voiceRecordByUser[$sKey] : null;
                        $voicePayload = htmlspecialchars(json_encode(array(
                            'name' => $sName,
                            'if_name' => $ifName,
                            'service' => $sService,
                        ), JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                        $vSearch = strtolower($sName . ' ' . $sRemote . ' ' . $sService . ' ' . ($savedRec && !empty($savedRec['last_ports']) ? $savedRec['last_ports'] : ''));
                      ?>
                        <tr data-voice-search="<?= htmlspecialchars($vSearch, ENT_QUOTES, 'UTF-8'); ?>">
                          <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($sName, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="ngw-vpn-meta ngw-vpn-mono" style="text-transform:uppercase;font-size:11px;"><?= htmlspecialchars($sService, ENT_QUOTES, 'UTF-8'); ?></div>
                          </td>
                          <td class="ngw-vpn-mono"><?= htmlspecialchars($ifName, ENT_QUOTES, 'UTF-8'); ?></td>
                          <td>
                            <?php if ($savedRec && ($savedRec['source'] === 'detected' || !empty($savedRec['last_seen']))) { ?>
                              <div class="<?= $isOnline ? 'ngw-vpn-status-on' : 'ngw-vpn-status-off'; ?> ngw-vpn-mono">
                                <span class="ngw-vpn-status-dot" style="background:<?= $isOnline ? 'var(--vpn-success)' : 'var(--vpn-accent)'; ?>;"></span>
                                <?= ngwVpnText('Detected', 'ডিটেক্টেড') ?>
                                <?php if (!empty($savedRec['last_flows'])) { ?>
                                  &middot; <?= number_format((int)$savedRec['last_flows']); ?> <?= ngwVpnText('flows', 'ফ্লো') ?>
                                <?php } ?>
                              </div>
                              <?php if (!empty($savedRec['last_ports'])) { ?>
                                <div class="ngw-vpn-meta ngw-vpn-mono" style="font-size:11px;"><?= htmlspecialchars($savedRec['last_ports'], ENT_QUOTES, 'UTF-8'); ?></div>
                              <?php } ?>
                            <?php } else { ?>
                              <span class="ngw-vpn-meta"><?= ngwVpnText('Manual', 'ম্যানুয়াল') ?></span>
                            <?php } ?>
                          </td>
                          <td class="ngw-vpn-mono"><?= $sRemote !== '' ? htmlspecialchars($sRemote, ENT_QUOTES, 'UTF-8') : '&mdash;'; ?></td>
                          <td>
                            <?php if ($sDisabled) { ?>
                              <span class="ngw-vpn-status-dis"><span class="ngw-vpn-status-dot" style="background:var(--vpn-danger);"></span><?= ngwVpnText('Disabled', 'ডিজেবল') ?></span>
                            <?php } elseif ($isOnline) { ?>
                              <span class="ngw-vpn-status-on"><span class="ngw-vpn-status-dot" style="background:var(--vpn-success);"></span><?= ngwVpnText('Online', 'অনলাইন') ?></span>
                            <?php } else { ?>
                              <span class="ngw-vpn-status-off"><span class="ngw-vpn-status-dot" style="background:var(--vpn-muted);"></span><?= ngwVpnText('Offline', 'অফলাইন') ?></span>
                            <?php } ?>
                          </td>
                          <td style="text-align:right;">
                            <div class="ngw-vpn-menu-wrap">
                              <button type="button" class="ngw-vpn-dots-btn" onclick="ngwVpnToggleMenu(this, event)" aria-haspopup="true" aria-expanded="false" title="<?= ngwVpnText('Actions', 'অ্যাকশন') ?>">
                                <i class="fa fa-ellipsis-v"></i>
                              </button>
                              <div class="ngw-vpn-dropdown" role="menu">
                                <button type="button" class="ngw-vpn-dd-item" role="menuitem" onclick='ngwVpnCloseAllMenus();ngwVpnOpenVoiceScript(<?= $voicePayload; ?>)'>
                                  <i class="fa fa-terminal"></i> <?= ngwVpnText('Script', 'স্ক্রিপ্ট') ?>
                                </button>
                                <div class="ngw-vpn-dd-sep"></div>
                                <form method="post" action="<?= htmlspecialchars($workspaceBaseUrl . '&tab=voice', ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="vpn_action" value="toggle_voice_user">
                                  <input type="hidden" name="username" value="<?= htmlspecialchars($sName, ENT_QUOTES, 'UTF-8'); ?>">
                                  <input type="hidden" name="voice_enabled" value="0">
                                  <input type="hidden" name="return_tab" value="voice">
                                  <button type="submit" class="ngw-vpn-dd-item is-danger" role="menuitem">
                                    <i class="fa fa-trash"></i> <?= ngwVpnText('Remove', 'রিমুভ') ?>
                                  </button>
                                </form>
                              </div>
                            </div>
                          </td>
                        </tr>
                      <?php } ?>
                    </tbody>
                  </table>
                </div>
              <?php } ?>
            <?php } ?>

          </div>
        <?php } ?>
      </div>
    <?php } ?>
  </div>

  <!-- ======================================================================= -->
  <!-- MODAL 1: ADD / EDIT VPN ROUTER (5 CORE FIELDS ONLY)                     -->
  <!-- ======================================================================= -->
  <div class="ngw-vpn-modal-backdrop" id="vpnRouterModal">
    <div class="card ngw-vpn-modal">
      <div class="card-header">
        <h3><i class="fa fa-shield"></i> <span id="vpnRouterModalTitle"><?= ngwVpnText('Add VPN Router', 'নতুন VPN রাউটার') ?></span></h3>
        <button type="button" class="ngw-vpn-modal-close" onclick="ngwVpnCloseModal('vpnRouterModal')" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="<?= htmlspecialchars($activeRouter !== null ? ($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=' . rawurlencode($activeTab)) : $baseVpnUrl, ENT_QUOTES, 'UTF-8'); ?>" style="display:flex;flex-direction:column;min-height:0;flex:1 1 auto;">
        <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="vpn_action" value="save_router">
        <input type="hidden" name="router_id" id="rEditId" value="">
        <input type="hidden" name="return_to_workspace" id="rReturnWs" value="0">
        <div class="card-body ngw-vpn-modal-form-body">
          <div class="ngw-vpn-form-grid">
            <div class="ngw-vpn-field span-2">
              <label><?= ngwVpnText('Router Name', 'রাউটার নাম') ?></label>
              <input type="text" name="r_name" id="rName" class="ngw-vpn-input" required placeholder="Dubai-Core">
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Host', 'হোস্ট / IP') ?></label>
              <input type="text" name="r_host" id="rHost" class="ngw-vpn-input ngw-vpn-mono" required placeholder="vpn.example.com">
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('API Port', 'API পোর্ট') ?></label>
              <input type="number" name="r_port" id="rPort" class="ngw-vpn-input ngw-vpn-mono" min="1" max="65535" value="8728" required>
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Admin User', 'অ্যাডমিন ইউজার') ?></label>
              <input type="text" name="r_user" id="rUser" class="ngw-vpn-input" required autocomplete="off">
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Password', 'পাসওয়ার্ড') ?></label>
              <input type="password" name="r_pass" id="rPass" class="ngw-vpn-input" autocomplete="new-password">
            </div>
          </div>
        </div>
        <div class="ngw-vpn-modal-foot">
          <div class="ngw-vpn-modal-foot-right">
            <button type="button" class="btn bg-secondary" onclick="ngwVpnCloseModal('vpnRouterModal')"><?= ngwVpnText('Cancel', 'বাতিল') ?></button>
            <button type="submit" class="btn bg-primary"><i class="fa fa-save"></i> <?= ngwVpnText('Save', 'সেভ') ?></button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <?php if ($activeRouter !== null) { ?>
  <!-- ======================================================================= -->
  <!-- MODAL 1B: VPN ROUTER SETTINGS (SSTP / L2TP / NAT / INTERNAL PORTS)      -->
  <!-- ======================================================================= -->
  <div class="ngw-vpn-modal-backdrop" id="vpnSettingsModal">
    <div class="card ngw-vpn-modal">
      <div class="card-header">
        <h3><i class="fa fa-cog"></i> <?= ngwVpnText('VPN Settings', 'ভিপিএন সেটিংস') ?></h3>
        <button type="button" class="ngw-vpn-modal-close" onclick="ngwVpnCloseModal('vpnSettingsModal')" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="<?= htmlspecialchars($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=' . rawurlencode($activeTab), ENT_QUOTES, 'UTF-8'); ?>" style="display:flex;flex-direction:column;min-height:0;flex:1 1 auto;">
        <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="vpn_action" value="save_router_settings">
        <div class="card-body ngw-vpn-modal-form-body">
          <div class="ngw-vpn-form-grid">
            <div class="ngw-vpn-field">
              <label>RouterOS</label>
              <select name="r_ros_ver" id="sRosVer" class="ngw-vpn-input">
                <option value="7">RouterOS v7</option>
                <option value="6">RouterOS v6</option>
              </select>
            </div>
            <div class="ngw-vpn-field">
              <label>SSTP Port</label>
              <input type="number" name="r_sstp_port" id="sSstpPort" class="ngw-vpn-input ngw-vpn-mono" min="1" max="65535" value="<?= (int)$activeRouter['sstp_port']; ?>">
            </div>
            <div class="ngw-vpn-field">
              <label>L2TP IPsec Secret</label>
              <input type="text" name="r_l2tp_secret" id="sL2tpSecret" class="ngw-vpn-input ngw-vpn-mono" value="<?= htmlspecialchars($activeRouter['l2tp_ipsec_secret'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?= ngwVpnText('Optional', 'ঐচ্ছিক') ?>">
            </div>
            <div class="ngw-vpn-field">
              <label>NAT Start Port</label>
              <input type="number" name="r_nat_start" id="sNatStart" class="ngw-vpn-input ngw-vpn-mono" min="1024" max="65500" value="<?= (int)$activeRouter['nat_start_port']; ?>">
            </div>
          </div>

          <hr class="ngw-vpn-divider">

          <div class="ngw-vpn-form-grid cols-3">
            <div class="ngw-vpn-field">
              <label>Winbox Port</label>
              <input type="number" name="r_winbox_int" id="sWinboxInt" class="ngw-vpn-input ngw-vpn-mono" min="1" max="65535" value="<?= (int)$activeRouter['winbox_int_port']; ?>">
            </div>
            <div class="ngw-vpn-field">
              <label>API Port</label>
              <input type="number" name="r_api_int" id="sApiInt" class="ngw-vpn-input ngw-vpn-mono" min="1" max="65535" value="<?= (int)$activeRouter['api_int_port']; ?>">
            </div>
            <div class="ngw-vpn-field">
              <label>Web Port</label>
              <input type="number" name="r_web_int" id="sWebInt" class="ngw-vpn-input ngw-vpn-mono" min="1" max="65535" value="<?= (int)$activeRouter['web_int_port']; ?>">
            </div>
          </div>
        </div>
        <div class="ngw-vpn-modal-foot">
          <div class="ngw-vpn-modal-foot-right">
            <button type="button" class="btn bg-secondary" onclick="ngwVpnCloseModal('vpnSettingsModal')"><?= ngwVpnText('Cancel', 'বাতিল') ?></button>
            <button type="submit" class="btn bg-primary"><i class="fa fa-save"></i> <?= ngwVpnText('Save', 'সেভ') ?></button>
          </div>
        </div>
      </form>
    </div>
  </div>
  <?php } ?>

  <?php if ($activeRouter !== null && $isConnected) { ?>
  <!-- ======================================================================= -->
  <!-- MODAL 2: CREATE / EDIT PPP SECRET USER                                  -->
  <!-- ======================================================================= -->
  <div class="ngw-vpn-modal-backdrop" id="vpnUserModal">
    <div class="card ngw-vpn-modal">
      <div class="card-header">
        <h3><i class="fa fa-user-secret"></i> <span id="vpnUserModalTitle"><?= ngwVpnText('Create VPN User', 'নতুন VPN ইউজার') ?></span></h3>
        <button type="button" class="ngw-vpn-modal-close" onclick="ngwVpnCloseModal('vpnUserModal')" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="<?= htmlspecialchars($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=users', ENT_QUOTES, 'UTF-8'); ?>" style="display:flex;flex-direction:column;min-height:0;flex:1 1 auto;">
        <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="vpn_action" id="uAction" value="create_secret">
        <input type="hidden" name="sec_id" id="uSecId" value="">
        <input type="hidden" name="old_name" id="uOldName" value="">
        <div class="card-body ngw-vpn-modal-form-body">
          <div class="ngw-vpn-form-grid">
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Username', 'ইউজারনেম') ?></label>
              <input type="text" name="u_name" id="uName" class="ngw-vpn-input" required maxlength="80" autocomplete="off">
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Password', 'পাসওয়ার্ড') ?></label>
              <div style="display:flex;gap:4px;">
                <input type="text" name="u_pass" id="uPass" class="ngw-vpn-input ngw-vpn-mono" required autocomplete="off">
                <button type="button" class="ngw-vpn-icon-btn" style="height:34px;" onclick="ngwVpnGenPass()" title="<?= ngwVpnText('Generate', 'জেনারেট') ?>"><i class="fa fa-key"></i></button>
              </div>
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Service', 'সার্ভিস') ?></label>
              <select name="u_service" id="uService" class="ngw-vpn-input">
                <option value="sstp">SSTP</option>
                <option value="l2tp">L2TP</option>
                <option value="pptp">PPTP</option>
                <option value="any">Any</option>
              </select>
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('PPP Profile', 'PPP প্রোফাইল') ?></label>
              <select name="u_profile" id="uProfile" class="ngw-vpn-input" onchange="ngwVpnOnProfileChange(false)">
                <?php foreach ($pppProfiles as $pr) {
                  $pName = isset($pr['name']) ? (string)$pr['name'] : '';
                  if ($pName === '') continue;
                  $locInfo = !empty($pr['local-address']) ? (' (' . $pr['local-address'] . ')') : '';
                ?>
                  <option value="<?= htmlspecialchars($pName, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($pName . $locInfo, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php } ?>
              </select>
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Static Remote IP', 'স্ট্যাটিক রিমোট IP') ?></label>
              <input type="text" name="u_remote_ip" id="uRemoteIp" class="ngw-vpn-input ngw-vpn-mono" required placeholder="10.10.20.2">
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Comment', 'কমেন্ট') ?></label>
              <input type="text" name="u_comment" id="uComment" class="ngw-vpn-input" placeholder="<?= ngwVpnText('Optional', 'ঐচ্ছিক') ?>">
            </div>
            <div class="ngw-vpn-field span-2">
              <label style="display:inline-flex;align-items:center;gap:7px;cursor:pointer;color:var(--vpn-text);font-size:12.5px;">
                <input type="checkbox" name="u_voice_call" id="uVoiceCall" value="1">
                <i class="fa fa-whatsapp" style="color:var(--vpn-success);"></i> <?= ngwVpnText('Enable Voice Call Bypass', 'ভয়েস কল বাইপাস চালু করুন') ?>
              </label>
            </div>
          </div>
        </div>
        <div class="ngw-vpn-modal-foot">
          <div class="ngw-vpn-modal-foot-right">
            <button type="button" class="btn bg-secondary" onclick="ngwVpnCloseModal('vpnUserModal')"><?= ngwVpnText('Cancel', 'বাতিল') ?></button>
            <button type="submit" class="btn bg-primary"><i class="fa fa-check"></i> <?= ngwVpnText('Save', 'সংরক্ষণ') ?></button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ======================================================================= -->
  <!-- MODAL 3: CLIENT AUTO-CONNECT SCRIPT (ROS v6 & v7)                       -->
  <!-- ======================================================================= -->
  <div class="ngw-vpn-modal-backdrop" id="vpnClientScriptModal">
    <div class="card ngw-vpn-modal wide">
      <div class="card-header">
        <h3><i class="fa fa-terminal"></i> <span id="csTitle">MikroTik VPN Script</span></h3>
        <button type="button" class="ngw-vpn-modal-close" onclick="ngwVpnCloseModal('vpnClientScriptModal')" aria-label="Close">&times;</button>
      </div>
      <div class="card-body">
        <div class="ngw-vpn-toolbar" style="margin-bottom:12px;">
          <div class="ngw-vpn-seg" id="csRosToggle">
            <button type="button" class="active" data-ros="7" onclick="ngwVpnSetCsRos(7)">RouterOS v7</button>
            <button type="button" data-ros="6" onclick="ngwVpnSetCsRos(6)">RouterOS v6</button>
          </div>
          <div class="ngw-vpn-seg" id="csProtoToggle">
            <button type="button" data-proto="sstp" onclick="ngwVpnSetCsProto('sstp')">SSTP</button>
            <button type="button" data-proto="l2tp" onclick="ngwVpnSetCsProto('l2tp')">L2TP</button>
            <button type="button" data-proto="pptp" onclick="ngwVpnSetCsProto('pptp')">PPTP</button>
          </div>
        </div>
        <div class="ngw-vpn-form-grid cols-3" style="margin-bottom:12px;">
          <div class="ngw-vpn-field">
            <label><?= ngwVpnText('Interface Name', 'ইন্টারফেস নাম') ?></label>
            <input type="text" id="csIfName" class="ngw-vpn-input ngw-vpn-mono" value="<?= htmlspecialchars($fixedVoiceIf, ENT_QUOTES, 'UTF-8'); ?>" oninput="ngwVpnRenderClientScript()">
          </div>
          <div class="ngw-vpn-field" id="csSstpPortWrap">
            <label>SSTP Port</label>
            <input type="number" id="csSstpPort" class="ngw-vpn-input ngw-vpn-mono" min="1" max="65535" value="<?= (int)$activeRouter['sstp_port']; ?>" oninput="ngwVpnRenderClientScript()">
          </div>
          <div class="ngw-vpn-field" id="csIpsecWrap" style="display:none;">
            <label>IPsec Secret</label>
            <input type="text" id="csIpsecSecret" class="ngw-vpn-input ngw-vpn-mono" value="<?= htmlspecialchars($activeRouter['l2tp_ipsec_secret'], ENT_QUOTES, 'UTF-8'); ?>" oninput="ngwVpnRenderClientScript()">
          </div>
          <div class="ngw-vpn-field">
            <label><?= ngwVpnText('Server Address', 'সার্ভার অ্যাড্রেস') ?></label>
            <input type="text" id="csHost" class="ngw-vpn-input ngw-vpn-mono" value="<?= htmlspecialchars($activeRouter['host'], ENT_QUOTES, 'UTF-8'); ?>" readonly>
          </div>
        </div>
        <div class="ngw-vpn-code-shell">
          <div class="ngw-vpn-code-bar ngw-vpn-mono">
            <span id="csCodeMeta">MikroTik Terminal &middot; Interface: <?= htmlspecialchars($fixedVoiceIf, ENT_QUOTES, 'UTF-8'); ?></span>
            <span><?= ngwVpnText('Paste in New Terminal', 'New Terminal-এ পেস্ট করুন') ?></span>
          </div>
          <textarea id="csCodeBox" class="ngw-vpn-codebox" readonly spellcheck="false"></textarea>
        </div>
      </div>
      <div class="ngw-vpn-modal-foot">
        <span class="ngw-vpn-meta ngw-vpn-mono">Interface: <?= htmlspecialchars($fixedVoiceIf, ENT_QUOTES, 'UTF-8'); ?></span>
        <div class="ngw-vpn-modal-foot-right">
          <button type="button" class="btn bg-secondary" onclick="ngwVpnCloseModal('vpnClientScriptModal')"><?= ngwVpnText('Close', 'বন্ধ') ?></button>
          <button type="button" class="btn bg-primary" onclick="ngwVpnCopyText(document.getElementById('csCodeBox').value)"><i class="fa fa-copy"></i> <?= ngwVpnText('Copy Script', 'স্ক্রিপ্ট কপি করুন') ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- ======================================================================= -->
  <!-- MODAL 4: VOICE CALL SCRIPT GENERATOR (ROS v6 & v7 + PURPOSE SELECT)     -->
  <!-- ======================================================================= -->
  <div class="ngw-vpn-modal-backdrop" id="vpnVoiceScriptModal">
    <div class="card ngw-vpn-modal wide">
      <div class="card-header">
        <h3><i class="fa fa-terminal"></i> <span id="vsTitle"><?= ngwVpnText('Script', 'স্ক্রিপ্ট') ?></span></h3>
        <button type="button" class="ngw-vpn-modal-close" onclick="ngwVpnCloseModal('vpnVoiceScriptModal')" aria-label="Close">&times;</button>
      </div>
      <div class="card-body">
        <div class="ngw-vpn-form-grid" style="margin-bottom:12px;">
          <div class="ngw-vpn-field">
            <label>RouterOS</label>
            <div class="ngw-vpn-seg" id="vsRosToggle">
              <button type="button" class="active" data-ros="7" onclick="ngwVpnSetVsRos(7)">RouterOS v7</button>
              <button type="button" data-ros="6" onclick="ngwVpnSetVsRos(6)">RouterOS v6</button>
            </div>
          </div>
          <div class="ngw-vpn-field">
            <label><?= ngwVpnText('Purpose', 'উদ্দেশ্য / সার্ভিস') ?></label>
            <div class="ngw-vpn-purpose-list" id="vsPurposeList">
              <label class="ngw-vpn-purpose-chip"><input type="checkbox" value="whatsapp" checked onchange="ngwVpnOnVsPurposeChange()"> WhatsApp</label>
              <label class="ngw-vpn-purpose-chip"><input type="checkbox" value="imo" checked onchange="ngwVpnOnVsPurposeChange()"> imo</label>
              <label class="ngw-vpn-purpose-chip"><input type="checkbox" value="messenger" checked onchange="ngwVpnOnVsPurposeChange()"> Messenger</label>
            </div>
          </div>
        </div>

        <div class="ngw-vpn-form-grid" style="margin-bottom:12px;">
          <div class="ngw-vpn-field">
            <label><span class="ngw-vpn-proto-tag ngw-vpn-mono" style="margin-right:4px;">TCP</span> DST-Port</label>
            <textarea id="vsTcpInput" class="ngw-vpn-voice-textarea ngw-vpn-mono" rows="2" oninput="ngwVpnResetVsGenerated()"></textarea>
          </div>
          <div class="ngw-vpn-field">
            <label><span class="ngw-vpn-proto-tag is-udp ngw-vpn-mono" style="margin-right:4px;">UDP</span> DST-Port</label>
            <textarea id="vsUdpInput" class="ngw-vpn-voice-textarea ngw-vpn-mono" rows="2" oninput="ngwVpnResetVsGenerated()"></textarea>
          </div>
        </div>

        <div class="ngw-vpn-gen-bar">
          <span class="ngw-vpn-meta ngw-vpn-mono" id="vsMetaLine"></span>
          <button type="button" class="btn bg-primary" onclick="ngwVpnGenerateVsScript()">
            <i class="fa fa-bolt"></i> <?= ngwVpnText('Generate', 'জেনারেট') ?>
          </button>
        </div>

        <div class="ngw-vpn-code-shell" id="vsCodeWrap" style="display:none;">
          <div class="ngw-vpn-code-bar ngw-vpn-mono">
            <span id="vsCodePurpose">Voice-Call</span>
            <span><?= ngwVpnText('Paste in New Terminal', 'New Terminal-এ পেস্ট করুন') ?></span>
          </div>
          <textarea id="vsCodeBox" class="ngw-vpn-codebox" style="min-height:210px;" readonly spellcheck="false"></textarea>
        </div>
      </div>
      <div class="ngw-vpn-modal-foot">
        <span class="ngw-vpn-meta ngw-vpn-mono">0.0.0.0/0 &rarr; <?= htmlspecialchars($fixedVoiceIf, ENT_QUOTES, 'UTF-8'); ?></span>
        <div class="ngw-vpn-modal-foot-right">
          <button type="button" class="btn bg-secondary" onclick="ngwVpnCloseModal('vpnVoiceScriptModal')"><?= ngwVpnText('Close', 'বন্ধ') ?></button>
          <button type="button" class="btn bg-primary" id="vsCopyBtn" style="display:none;" onclick="ngwVpnCopyText(document.getElementById('vsCodeBox').value)"><i class="fa fa-copy"></i> <?= ngwVpnText('Copy Script', 'স্ক্রিপ্ট কপি') ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- ======================================================================= -->
  <!-- MODAL 5: PORT FORWARD POPUP                                             -->
  <!-- ======================================================================= -->
  <div class="ngw-vpn-modal-backdrop" id="vpnNatModal">
    <div class="card ngw-vpn-modal">
      <div class="card-header">
        <h3><i class="fa fa-random"></i> <?= ngwVpnText('Port Forward', 'পোর্ট ফরওয়ার্ড') ?></h3>
        <button type="button" class="ngw-vpn-modal-close" onclick="ngwVpnCloseModal('vpnNatModal')" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="<?= htmlspecialchars($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=nat', ENT_QUOTES, 'UTF-8'); ?>" id="vpnNatCreateForm" style="display:flex;flex-direction:column;min-height:0;flex:1 1 auto;">
        <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="vpn_action" value="create_nat">
        <div class="card-body ngw-vpn-modal-form-body">
          <div class="ngw-vpn-form-grid">
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('VPN User', 'ভিপিএন ইউজার') ?></label>
              <select name="nat_username" id="natUserSelect" class="ngw-vpn-input" required>
                <option value=""><?= ngwVpnText('-- Select User --', '-- ইউজার সিলেক্ট করুন --') ?></option>
                <?php foreach ($usersWithIp as $u) {
                  $sel = ($preselectNatUser !== '' && strcasecmp($preselectNatUser, (string)$u['name']) === 0) ? 'selected' : '';
                ?>
                  <option value="<?= htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8'); ?>" data-ip="<?= htmlspecialchars($u['remote-address'], ENT_QUOTES, 'UTF-8'); ?>" <?= $sel; ?>>
                    <?= htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8'); ?> (<?= htmlspecialchars($u['remote-address'], ENT_QUOTES, 'UTF-8'); ?>)
                  </option>
                <?php } ?>
              </select>
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('External Start Port', 'এক্সটারনাল স্টার্ট পোর্ট') ?></label>
              <input type="number" name="nat_start_port" id="natStartPortInput" class="ngw-vpn-input ngw-vpn-mono" min="1024" max="65500" value="<?= (int)$activeRouter['nat_start_port']; ?>" oninput="ngwVpnUpdateNatPreview()">
            </div>
          </div>

          <div class="ngw-vpn-nat-svcs">
            <div class="ngw-vpn-nat-svc-box">
              <div class="ngw-vpn-nat-svc-head">
                <label style="display:flex;align-items:center;gap:6px;margin:0;cursor:pointer;color:var(--vpn-text);">
                  <input type="checkbox" name="fwd_winbox" id="fwdWinbox" value="1" checked onchange="ngwVpnUpdateNatPreview()"> Winbox
                </label>
                <span class="ngw-vpn-nat-ext-preview ngw-vpn-mono" id="prevExtWinbox">Ext: &mdash;</span>
              </div>
              <input type="number" name="int_winbox" class="ngw-vpn-input ngw-vpn-mono" min="1" max="65535" value="<?= (int)$activeRouter['winbox_int_port']; ?>" placeholder="8291">
            </div>

            <div class="ngw-vpn-nat-svc-box">
              <div class="ngw-vpn-nat-svc-head">
                <label style="display:flex;align-items:center;gap:6px;margin:0;cursor:pointer;color:var(--vpn-text);">
                  <input type="checkbox" name="fwd_api" id="fwdApi" value="1" checked onchange="ngwVpnUpdateNatPreview()"> API
                </label>
                <span class="ngw-vpn-nat-ext-preview ngw-vpn-mono" id="prevExtApi">Ext: &mdash;</span>
              </div>
              <input type="number" name="int_api" class="ngw-vpn-input ngw-vpn-mono" min="1" max="65535" value="<?= (int)$activeRouter['api_int_port']; ?>" placeholder="8728">
            </div>

            <div class="ngw-vpn-nat-svc-box">
              <div class="ngw-vpn-nat-svc-head">
                <label style="display:flex;align-items:center;gap:6px;margin:0;cursor:pointer;color:var(--vpn-text);">
                  <input type="checkbox" name="fwd_web" id="fwdWeb" value="1" checked onchange="ngwVpnUpdateNatPreview()"> Web
                </label>
                <span class="ngw-vpn-nat-ext-preview ngw-vpn-mono" id="prevExtWeb">Ext: &mdash;</span>
              </div>
              <input type="number" name="int_web" class="ngw-vpn-input ngw-vpn-mono" min="1" max="65535" value="<?= (int)$activeRouter['web_int_port']; ?>" placeholder="80">
            </div>
          </div>

          <div style="margin-top:10px;">
            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:var(--vpn-muted);">
              <input type="checkbox" name="save_default_start" value="1">
              <?= ngwVpnText('Save start port as default', 'এই স্টার্ট পোর্ট ডিফল্ট হিসেবে সেভ করুন') ?>
            </label>
          </div>
        </div>
        <div class="ngw-vpn-modal-foot">
          <div class="ngw-vpn-modal-foot-right">
            <button type="button" class="btn bg-secondary" onclick="ngwVpnCloseModal('vpnNatModal')"><?= ngwVpnText('Cancel', 'বাতিল') ?></button>
            <button type="submit" class="btn bg-primary"><i class="fa fa-check"></i> <?= ngwVpnText('Set', 'সেট করুন') ?></button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ======================================================================= -->
  <!-- MODAL 6: PORT FORWARD CONFIRMATION + COPY LINKS                         -->
  <!-- ======================================================================= -->
  <?php if ($vpnNatResult !== null && !empty($vpnNatResult['links'])) { ?>
  <div class="ngw-vpn-modal-backdrop open" id="vpnNatConfirmModal">
    <div class="card ngw-vpn-modal">
      <div class="card-header">
        <h3><i class="fa fa-check-circle" style="color:var(--vpn-success);"></i> <?= ngwVpnText('Port Forward Confirmed', 'পোর্ট ফরওয়ার্ড সম্পন্ন হয়েছে') ?></h3>
        <button type="button" class="ngw-vpn-modal-close" onclick="ngwVpnCloseModal('vpnNatConfirmModal')" aria-label="Close">&times;</button>
      </div>
      <div class="card-body">
        <div class="ngw-vpn-confirm-head">
          <span class="ngw-vpn-confirm-icon"><i class="fa fa-check"></i></span>
          <div>
            <div style="font-weight:700;font-size:14px;color:var(--vpn-text);">
              <?= htmlspecialchars((string)$vpnNatResult['username'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="ngw-vpn-meta ngw-vpn-mono">
              <?= htmlspecialchars((string)$vpnNatResult['static_ip'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
          </div>
        </div>
        <div class="ngw-vpn-confirm-list" id="natConfirmLinks">
          <?php foreach ($vpnNatResult['links'] as $lnk) {
            $lnkAddr = (string)$lnk['addr'];
            $lnkSvc = (string)$lnk['service'];
          ?>
            <div class="ngw-vpn-confirm-row" data-nat-copy-line="<?= htmlspecialchars($lnkSvc . ': ' . $lnkAddr, ENT_QUOTES, 'UTF-8'); ?>">
              <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                <span class="ngw-vpn-confirm-svc ngw-vpn-mono"><?= htmlspecialchars($lnkSvc, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="ngw-vpn-confirm-addr ngw-vpn-mono"><?= htmlspecialchars($lnkAddr, ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
              <button type="button" class="ngw-vpn-icon-btn is-primary" onclick='ngwVpnCopyText(<?= htmlspecialchars(json_encode($lnkAddr), ENT_QUOTES, 'UTF-8'); ?>)'>
                <i class="fa fa-copy"></i> <?= ngwVpnText('Copy', 'কপি') ?>
              </button>
            </div>
          <?php } ?>
        </div>
      </div>
      <div class="ngw-vpn-modal-foot">
        <button type="button" class="ngw-vpn-icon-btn" onclick="ngwVpnCopyAllNatLinks()">
          <i class="fa fa-files-o fa-copy"></i> <?= ngwVpnText('Copy All', 'সব কপি') ?>
        </button>
        <div class="ngw-vpn-modal-foot-right">
          <button type="button" class="btn bg-primary" onclick="ngwVpnCloseModal('vpnNatConfirmModal')"><?= ngwVpnText('Done', 'ঠিক আছে') ?></button>
        </div>
      </div>
    </div>
  </div>
  <?php } ?>

  <!-- ======================================================================= -->
  <!-- MODAL 5B: EDIT NAT / PORT FORWARD RULE                                  -->
  <!-- ======================================================================= -->
  <div class="ngw-vpn-modal-backdrop" id="vpnNatEditModal">
    <div class="card ngw-vpn-modal">
      <div class="card-header">
        <h3><i class="fa fa-pencil"></i> <span id="natEditTitle"><?= ngwVpnText('Edit Port Forward', 'পোর্ট ফরওয়ার্ড এডিট') ?></span></h3>
        <button type="button" class="ngw-vpn-modal-close" onclick="ngwVpnCloseModal('vpnNatEditModal')" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="<?= htmlspecialchars($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=nat', ENT_QUOTES, 'UTF-8'); ?>" style="display:flex;flex-direction:column;min-height:0;flex:1 1 auto;">
        <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="vpn_action" value="edit_nat">
        <input type="hidden" name="nat_id" id="enNatId" value="">
        <input type="hidden" name="nat_chain" id="enNatChain" value="dstnat">
        <div class="card-body ngw-vpn-modal-form-body">
          <div class="ngw-vpn-form-grid">
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Protocol', 'প্রোটোকল') ?></label>
              <select name="nat_protocol" id="enProto" class="ngw-vpn-input">
                <option value="tcp">TCP</option>
                <option value="udp">UDP</option>
                <option value="">Any</option>
              </select>
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('External Port', 'এক্সটারনাল পোর্ট') ?></label>
              <input type="text" name="nat_dst_port" id="enDstPort" class="ngw-vpn-input ngw-vpn-mono" placeholder="8010">
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Target Static IP', 'টার্গেট স্ট্যাটিক IP') ?></label>
              <input type="text" name="nat_to_addresses" id="enToAddr" list="ngwVpnIpDatalist" class="ngw-vpn-input ngw-vpn-mono" placeholder="10.10.20.2">
              <datalist id="ngwVpnIpDatalist">
                <?php foreach ($usersWithIp as $u) { ?>
                  <option value="<?= htmlspecialchars($u['remote-address'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($u['name'] . ' (' . $u['remote-address'] . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                <?php } ?>
              </datalist>
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Internal Port', 'ইন্টারনাল পোর্ট') ?></label>
              <input type="text" name="nat_to_ports" id="enToPorts" class="ngw-vpn-input ngw-vpn-mono" placeholder="8291">
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Comment', 'কমেন্ট') ?></label>
              <input type="text" name="nat_comment" id="enComment" class="ngw-vpn-input" maxlength="150">
            </div>
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('Status', 'স্ট্যাটাস') ?></label>
              <select name="nat_disabled" id="enDisabled" class="ngw-vpn-input">
                <option value="no"><?= ngwVpnText('Active', 'চালু') ?></option>
                <option value="yes"><?= ngwVpnText('Disabled', 'ডিজেবল') ?></option>
              </select>
            </div>
          </div>
        </div>
        <div class="ngw-vpn-modal-foot">
          <div class="ngw-vpn-modal-foot-right">
            <button type="button" class="btn bg-secondary" onclick="ngwVpnCloseModal('vpnNatEditModal')"><?= ngwVpnText('Cancel', 'বাতিল') ?></button>
            <button type="submit" class="btn bg-primary"><i class="fa fa-save"></i> <?= ngwVpnText('Save', 'সেভ') ?></button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ======================================================================= -->
  <!-- MODAL 7: ADD VOICE CALL + PURPOSE SCRIPT GENERATOR                      -->
  <!-- ======================================================================= -->
  <div class="ngw-vpn-modal-backdrop" id="vpnVoiceModal">
    <div class="card ngw-vpn-modal wide">
      <div class="card-header">
        <h3><i class="fa fa-whatsapp" style="color:var(--vpn-success);"></i> <?= ngwVpnText('Add Voice Call', 'অ্যাড ভয়েস কল') ?></h3>
        <button type="button" class="ngw-vpn-modal-close" onclick="ngwVpnCloseModal('vpnVoiceModal')" aria-label="Close">&times;</button>
      </div>
      <form method="post" action="<?= htmlspecialchars($baseVpnUrl . '&vpn_id=' . rawurlencode($activeRouter['id']) . '&tab=voice', ENT_QUOTES, 'UTF-8'); ?>" style="display:flex;flex-direction:column;min-height:0;flex:1 1 auto;">
        <input type="hidden" name="vpn_csrf_token" value="<?= htmlspecialchars($vpnCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="vpn_action" value="save_voice_ports">
        <div class="card-body ngw-vpn-modal-form-body">
          <div class="ngw-vpn-form-grid" style="margin-bottom:12px;">
            <div class="ngw-vpn-field">
              <label><?= ngwVpnText('VPN User', 'ভিপিএন ইউজার') ?></label>
              <select name="voice_username" id="vmUserSelect" class="ngw-vpn-input" onchange="ngwVpnResetVmGenerated()">
                <option value=""><?= ngwVpnText('-- Optional: Select User --', '-- ঐচ্ছিক: ইউজার সিলেক্ট করুন --') ?></option>
                <?php foreach ($pppSecrets as $sec) {
                  $sn = isset($sec['name']) ? (string)$sec['name'] : '';
                  if ($sn === '') continue;
                  $sr = isset($sec['remote-address']) ? trim((string)$sec['remote-address']) : '';
                ?>
                  <option value="<?= htmlspecialchars($sn, ENT_QUOTES, 'UTF-8'); ?>">
                    <?= htmlspecialchars($sn . ($sr !== '' ? (' (' . $sr . ')') : ''), ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php } ?>
              </select>
            </div>
            <div class="ngw-vpn-field">
              <label>RouterOS</label>
              <div class="ngw-vpn-seg" id="vmRosToggle">
                <button type="button" class="active" data-ros="7" onclick="ngwVpnSetVmRos(7)">RouterOS v7</button>
                <button type="button" data-ros="6" onclick="ngwVpnSetVmRos(6)">RouterOS v6</button>
              </div>
            </div>
          </div>

          <div class="ngw-vpn-field" style="margin-bottom:12px;">
            <label><?= ngwVpnText('Purpose', 'উদ্দেশ্য / সার্ভিস') ?></label>
            <div class="ngw-vpn-purpose-list" id="vmPurposeList">
              <label class="ngw-vpn-purpose-chip"><input type="checkbox" value="whatsapp" checked onchange="ngwVpnOnVmPurposeChange()"> WhatsApp</label>
              <label class="ngw-vpn-purpose-chip"><input type="checkbox" value="imo" checked onchange="ngwVpnOnVmPurposeChange()"> imo</label>
              <label class="ngw-vpn-purpose-chip"><input type="checkbox" value="messenger" checked onchange="ngwVpnOnVmPurposeChange()"> Messenger</label>
            </div>
          </div>

          <div class="ngw-vpn-form-grid" style="margin-bottom:12px;">
            <div class="ngw-vpn-field">
              <label><span class="ngw-vpn-proto-tag ngw-vpn-mono" style="margin-right:4px;">TCP</span> DST-Port</label>
              <textarea id="voiceTcpInput" name="voice_tcp_ports" class="ngw-vpn-voice-textarea ngw-vpn-mono" rows="2" required oninput="ngwVpnResetVmGenerated()"><?= htmlspecialchars($voiceCfg['tcp_ports'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="ngw-vpn-field">
              <label><span class="ngw-vpn-proto-tag is-udp ngw-vpn-mono" style="margin-right:4px;">UDP</span> DST-Port</label>
              <textarea id="voiceUdpInput" name="voice_udp_ports" class="ngw-vpn-voice-textarea ngw-vpn-mono" rows="2" required oninput="ngwVpnResetVmGenerated()"><?= htmlspecialchars($voiceCfg['udp_ports'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
          </div>

          <div class="ngw-vpn-gen-bar">
            <span class="ngw-vpn-meta ngw-vpn-mono">Interface &amp; Table: <?= htmlspecialchars($fixedVoiceIf, ENT_QUOTES, 'UTF-8'); ?></span>
            <button type="button" class="btn bg-primary" onclick="ngwVpnGenerateVmScript()">
              <i class="fa fa-bolt"></i> <?= ngwVpnText('Generate', 'জেনারেট') ?>
            </button>
          </div>

          <div class="ngw-vpn-code-shell" id="vmCodeWrap" style="display:none;">
            <div class="ngw-vpn-code-bar ngw-vpn-mono">
              <span id="vmCodePurpose">Voice-Call</span>
              <span><?= ngwVpnText('Paste in New Terminal', 'New Terminal-এ পেস্ট করুন') ?></span>
            </div>
            <textarea id="vmCodeBox" class="ngw-vpn-codebox" style="min-height:170px;" readonly spellcheck="false"></textarea>
          </div>
        </div>
        <div class="ngw-vpn-modal-foot">
          <button type="button" class="ngw-vpn-icon-btn is-primary" id="vmCopyBtn" style="display:none;" onclick="ngwVpnCopyText(document.getElementById('vmCodeBox').value)">
            <i class="fa fa-copy"></i> <?= ngwVpnText('Copy Script', 'স্ক্রিপ্ট কপি') ?>
          </button>
          <div class="ngw-vpn-modal-foot-right">
            <button type="button" class="btn bg-secondary" onclick="ngwVpnCloseModal('vpnVoiceModal')"><?= ngwVpnText('Cancel', 'বাতিল') ?></button>
            <button type="submit" class="btn bg-primary"><i class="fa fa-save"></i> <?= ngwVpnText('Save', 'সেভ') ?></button>
          </div>
        </div>
      </form>
    </div>
  </div>
  <?php } ?>

  <div class="ngw-vpn-toast" id="ngwVpnToast">Copied</div>
</div>

<script>
(function() {
  var vpnConfig = <?= json_encode(array(
    'router' => $activeRouter ? array(
      'host' => $activeRouter['host'],
      'ros_ver' => (int)$activeRouter['ros_ver'],
      'sstp_port' => (int)$activeRouter['sstp_port'],
      'l2tp_ipsec_secret' => (string)$activeRouter['l2tp_ipsec_secret'],
      'nat_start_port' => (int)$activeRouter['nat_start_port'],
    ) : null,
    'profileSuggestions' => $profileSuggestions,
    'usedNatPorts' => $usedNatPortsList,
    'voiceInterface' => $fixedVoiceIf,
    'voiceApps' => $voiceApps,
    'voicePresets' => $voicePresets,
    'voiceTcp' => $voiceCfg['tcp_ports'],
    'voiceUdp' => $voiceCfg['udp_ports'],
    'preselectNatUser' => $preselectNatUser,
    'copiedLabel' => ngwVpnText('Copied!', 'কপি হয়েছে!'),
    'selectPurposeMsg' => ngwVpnText('Select at least one service or enter ports first.', 'অন্তত একটি সার্ভিস সিলেক্ট করুন বা পোর্ট দিন।'),
    'generatedLabel' => ngwVpnText('Script generated!', 'স্ক্রিপ্ট জেনারেট হয়েছে!'),
    'addRouterTitle' => ngwVpnText('Add VPN Router', 'নতুন VPN রাউটার'),
    'editRouterTitle' => ngwVpnText('Edit VPN Router', 'VPN রাউটার এডিট'),
    'createUserTitle' => ngwVpnText('Create VPN User', 'নতুন VPN ইউজার'),
    'editUserTitle' => ngwVpnText('Edit VPN User', 'VPN ইউজার এডিট'),
    'editNatTitle' => ngwVpnText('Edit Port Forward', 'পোর্ট ফরওয়ার্ড এডিট'),
    'scriptTitlePrefix' => ngwVpnText('Script', 'স্ক্রিপ্ট'),
  ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

  var toastTimer = null;
  window.ngwVpnToast = function(msg) {
    var el = document.getElementById('ngwVpnToast');
    if (!el) return;
    el.textContent = msg || vpnConfig.copiedLabel;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function() { el.classList.remove('show'); }, 1800);
  };

  window.ngwVpnCopyText = function(text) {
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function() {
        ngwVpnToast(vpnConfig.copiedLabel);
      }).catch(function() { fallbackCopy(text); });
    } else {
      fallbackCopy(text);
    }
  };

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); ngwVpnToast(vpnConfig.copiedLabel); } catch (e) {}
    document.body.removeChild(ta);
  }

  window.ngwVpnCloseModal = function(id) {
    var m = document.getElementById(id);
    if (m) m.classList.remove('open');
  };

  window.ngwVpnCloseAllMenus = function() {
    var openDrops = document.querySelectorAll('.ngw-vpn-dropdown.open');
    for (var i = 0; i < openDrops.length; i++) openDrops[i].classList.remove('open');
    var expBtns = document.querySelectorAll('.ngw-vpn-dots-btn[aria-expanded="true"]');
    for (var j = 0; j < expBtns.length; j++) expBtns[j].setAttribute('aria-expanded', 'false');
  };

  window.ngwVpnToggleMenu = function(btn, evt) {
    if (evt) evt.stopPropagation();
    var wrap = btn.closest('.ngw-vpn-menu-wrap');
    if (!wrap) return;
    var menu = wrap.querySelector('.ngw-vpn-dropdown');
    if (!menu) return;
    var wasOpen = menu.classList.contains('open');
    ngwVpnCloseAllMenus();
    if (wasOpen) return;

    menu.classList.add('open');
    btn.setAttribute('aria-expanded', 'true');

    var rect = btn.getBoundingClientRect();
    var mRect = menu.getBoundingClientRect();
    var vw = window.innerWidth || document.documentElement.clientWidth;
    var vh = window.innerHeight || document.documentElement.clientHeight;

    var left = rect.right - mRect.width;
    if (left < 8) left = 8;
    if (left + mRect.width > vw - 8) left = vw - mRect.width - 8;

    var top = rect.bottom + 4;
    if (top + mRect.height > vh - 8 && rect.top - mRect.height - 4 > 8) {
      top = rect.top - mRect.height - 4;
    }

    menu.style.left = Math.round(left) + 'px';
    menu.style.top = Math.round(top) + 'px';
  };

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      ngwVpnCloseAllMenus();
      var openModals = document.querySelectorAll('.ngw-vpn-modal-backdrop.open');
      for (var i = 0; i < openModals.length; i++) openModals[i].classList.remove('open');
    }
  });
  document.addEventListener('click', function(e) {
    if (!e.target.closest || !e.target.closest('.ngw-vpn-menu-wrap')) {
      ngwVpnCloseAllMenus();
    }
    if (e.target && e.target.classList && e.target.classList.contains('ngw-vpn-modal-backdrop')) {
      e.target.classList.remove('open');
    }
  });
  window.addEventListener('scroll', function() { ngwVpnCloseAllMenus(); }, true);
  window.addEventListener('resize', function() { ngwVpnCloseAllMenus(); });

  // Router list search
  var routerSearch = document.getElementById('vpnRouterSearch');
  if (routerSearch) {
    routerSearch.addEventListener('input', function() {
      var q = this.value.trim().toLowerCase();
      var cards = document.querySelectorAll('[data-vpn-router-card]');
      for (var i = 0; i < cards.length; i++) {
        var hay = cards[i].getAttribute('data-vpn-router-card') || '';
        cards[i].style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
      }
    });
  }

  // Router Add/Edit Modal (5 core fields only)
  window.ngwVpnOpenRouterModal = function(data, fromWorkspace) {
    var modal = document.getElementById('vpnRouterModal');
    if (!modal) return;
    document.getElementById('vpnRouterModalTitle').textContent = data ? vpnConfig.editRouterTitle : vpnConfig.addRouterTitle;
    document.getElementById('rEditId').value = data ? (data.id || '') : '';
    document.getElementById('rReturnWs').value = fromWorkspace ? '1' : '0';
    document.getElementById('rName').value = data ? (data.name || '') : '';
    document.getElementById('rHost').value = data ? (data.host || '') : '';
    document.getElementById('rPort').value = data ? (data.port || 8728) : 8728;
    document.getElementById('rUser').value = data ? (data.user || '') : '';
    var passEl = document.getElementById('rPass');
    passEl.value = '';
    passEl.required = !data;
    passEl.placeholder = data ? '••••••••' : '';
    modal.classList.add('open');
  };

  // Router VPN Settings Modal (ROS, SSTP, L2TP, NAT, Internal Ports)
  window.ngwVpnOpenSettingsModal = function(data) {
    var modal = document.getElementById('vpnSettingsModal');
    if (!modal) return;
    if (data) {
      document.getElementById('sRosVer').value = String(data.ros_ver || 7);
      document.getElementById('sSstpPort').value = data.sstp_port || 443;
      document.getElementById('sL2tpSecret').value = data.l2tp_ipsec_secret || '';
      document.getElementById('sNatStart').value = data.nat_start_port || 8010;
      document.getElementById('sWinboxInt').value = data.winbox_int_port || 8291;
      document.getElementById('sApiInt').value = data.api_int_port || 8728;
      document.getElementById('sWebInt').value = data.web_int_port || 80;
    }
    modal.classList.add('open');
  };

  // Users search & service filter
  var activeSvcFilter = 'all';
  function filterUserRows() {
    var qEl = document.getElementById('vpnUserSearch');
    var q = qEl ? qEl.value.trim().toLowerCase() : '';
    var rows = document.querySelectorAll('#vpnUsersTable tbody tr');
    for (var i = 0; i < rows.length; i++) {
      var svc = rows[i].getAttribute('data-vpn-row-svc') || '';
      var hay = rows[i].getAttribute('data-vpn-row-search') || '';
      var svcOk = (activeSvcFilter === 'all' || svc === activeSvcFilter);
      var qOk = (q === '' || hay.indexOf(q) !== -1);
      rows[i].style.display = (svcOk && qOk) ? '' : 'none';
    }
  }
  var userSearch = document.getElementById('vpnUserSearch');
  if (userSearch) userSearch.addEventListener('input', filterUserRows);
  var svcFilterBox = document.getElementById('vpnSvcFilter');
  if (svcFilterBox) {
    svcFilterBox.addEventListener('click', function(e) {
      var btn = e.target.closest('button[data-svc]');
      if (!btn) return;
      activeSvcFilter = btn.getAttribute('data-svc');
      var all = svcFilterBox.querySelectorAll('button');
      for (var i = 0; i < all.length; i++) all[i].classList.remove('active');
      btn.classList.add('active');
      filterUserRows();
    });
  }

  // Port Forward (NAT) search & filter
  var activeNatFilter = 'dstnat';
  function filterNatRows() {
    var qEl = document.getElementById('vpnNatSearch');
    var q = qEl ? qEl.value.trim().toLowerCase() : '';
    var rows = document.querySelectorAll('#vpnNatTable tbody tr');
    for (var i = 0; i < rows.length; i++) {
      var isDst = rows[i].getAttribute('data-nat-dst') === '1';
      var isDis = rows[i].getAttribute('data-nat-dis') === '1';
      var hay = rows[i].getAttribute('data-nat-search') || '';
      var fOk = true;
      if (activeNatFilter === 'dstnat') fOk = isDst;
      else if (activeNatFilter === 'active') fOk = !isDis;
      else if (activeNatFilter === 'disabled') fOk = isDis;
      var qOk = (q === '' || hay.indexOf(q) !== -1);
      rows[i].style.display = (fOk && qOk) ? '' : 'none';
    }
  }
  var natSearch = document.getElementById('vpnNatSearch');
  if (natSearch) natSearch.addEventListener('input', filterNatRows);
  var natFilterBox = document.getElementById('vpnNatFilter');
  if (natFilterBox) {
    natFilterBox.addEventListener('click', function(e) {
      var btn = e.target.closest('button[data-nat-filter]');
      if (!btn) return;
      activeNatFilter = btn.getAttribute('data-nat-filter');
      var all = natFilterBox.querySelectorAll('button');
      for (var i = 0; i < all.length; i++) all[i].classList.remove('active');
      btn.classList.add('active');
      filterNatRows();
    });
  }

  // Voice Call user table search
  var voiceSearch = document.getElementById('vpnVoiceSearch');
  if (voiceSearch) {
    voiceSearch.addEventListener('input', function() {
      var q = this.value.trim().toLowerCase();
      var rows = document.querySelectorAll('#vpnVoiceTable tbody tr');
      for (var i = 0; i < rows.length; i++) {
        var hay = rows[i].getAttribute('data-voice-search') || '';
        rows[i].style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
      }
    });
  }

  // User Create / Edit Modal + Smart Static IP Auto-Suggest
  var isEditingUser = false;
  window.ngwVpnOnProfileChange = function(forceFill) {
    if (isEditingUser && !forceFill) return;
    var profEl = document.getElementById('uProfile');
    var ipEl = document.getElementById('uRemoteIp');
    if (!profEl || !ipEl) return;
    var info = vpnConfig.profileSuggestions && vpnConfig.profileSuggestions[profEl.value];
    if (info && info.next_ip) {
      ipEl.value = info.next_ip;
    }
  };

  window.ngwVpnGenPass = function() {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    var out = '';
    for (var i = 0; i < 10; i++) out += chars.charAt(Math.floor(Math.random() * chars.length));
    var p = document.getElementById('uPass');
    if (p) p.value = out;
  };

  window.ngwVpnOpenUserModal = function(data) {
    var modal = document.getElementById('vpnUserModal');
    if (!modal) return;
    isEditingUser = !!data;
    document.getElementById('vpnUserModalTitle').textContent = data ? vpnConfig.editUserTitle : vpnConfig.createUserTitle;
    document.getElementById('uAction').value = data ? 'edit_secret' : 'create_secret';
    document.getElementById('uSecId').value = data ? (data.id || '') : '';
    document.getElementById('uOldName').value = data ? (data.name || '') : '';
    document.getElementById('uName').value = data ? (data.name || '') : '';
    var passEl = document.getElementById('uPass');
    passEl.value = data ? (data.password || '') : '';
    passEl.required = !data;
    document.getElementById('uService').value = data ? (data.service || 'sstp') : 'sstp';
    var profEl = document.getElementById('uProfile');
    if (data && data.profile) {
      profEl.value = data.profile;
    }
    document.getElementById('uRemoteIp').value = data ? (data.remote_ip || '') : '';
    document.getElementById('uComment').value = data ? (data.comment || '') : '';
    document.getElementById('uVoiceCall').checked = !!(data && data.voice);
    if (!data) {
      ngwVpnOnProfileChange(true);
    }
    modal.classList.add('open');
  };

  // Auto NAT Port Preview
  window.ngwVpnUpdateNatPreview = function() {
    var startEl = document.getElementById('natStartPortInput');
    if (!startEl) return;
    var start = parseInt(startEl.value, 10);
    if (isNaN(start) || start < 1024) start = 8010;
    var used = {};
    var arr = vpnConfig.usedNatPorts || [];
    for (var i = 0; i < arr.length; i++) used[parseInt(arr[i], 10)] = true;

    function nextFree(cur) {
      while (cur <= 65535 && used[cur]) cur++;
      if (cur <= 65535) { used[cur] = true; return cur; }
      return null;
    }

    var cursor = start;
    var items = [
      { cb: 'fwdWinbox', out: 'prevExtWinbox' },
      { cb: 'fwdApi', out: 'prevExtApi' },
      { cb: 'fwdWeb', out: 'prevExtWeb' }
    ];
    for (var j = 0; j < items.length; j++) {
      var cb = document.getElementById(items[j].cb);
      var out = document.getElementById(items[j].out);
      if (!cb || !out) continue;
      if (cb.checked) {
        var p = nextFree(cursor);
        if (p !== null) {
          out.textContent = 'Ext: :' + p;
          cursor = p + 1;
        } else {
          out.textContent = 'Full';
        }
      } else {
        out.textContent = '—';
      }
    }
  };
  window.ngwVpnOpenNatModal = function(preselectUser) {
    var modal = document.getElementById('vpnNatModal');
    if (!modal) return;
    var sel = document.getElementById('natUserSelect');
    if (sel && preselectUser) {
      sel.value = preselectUser;
    }
    ngwVpnUpdateNatPreview();
    modal.classList.add('open');
  };

  window.ngwVpnOpenNatEditModal = function(natData) {
    var modal = document.getElementById('vpnNatEditModal');
    if (!modal || !natData) return;
    document.getElementById('natEditTitle').textContent = vpnConfig.editNatTitle + (natData.user ? (' — ' + natData.user) : '');
    document.getElementById('enNatId').value = natData.id || '';
    document.getElementById('enNatChain').value = natData.chain || 'dstnat';
    document.getElementById('enProto').value = (natData.protocol === 'tcp' || natData.protocol === 'udp') ? natData.protocol : '';
    document.getElementById('enDstPort').value = natData.dst_port || '';
    document.getElementById('enToAddr').value = natData.to_addresses || '';
    document.getElementById('enToPorts').value = natData.to_ports || '';
    document.getElementById('enComment').value = natData.comment || '';
    document.getElementById('enDisabled').value = natData.disabled ? 'yes' : 'no';
    modal.classList.add('open');
  };

  window.ngwVpnCopyAllNatLinks = function() {
    var rows = document.querySelectorAll('#natConfirmLinks [data-nat-copy-line]');
    var lines = [];
    for (var i = 0; i < rows.length; i++) {
      var val = rows[i].getAttribute('data-nat-copy-line');
      if (val) lines.push(val);
    }
    if (lines.length > 0) {
      ngwVpnCopyText(lines.join('\n'));
    }
  };

  if (document.getElementById('natStartPortInput')) {
    ngwVpnUpdateNatPreview();
    if (vpnConfig.preselectNatUser && !document.getElementById('vpnNatConfirmModal')) {
      ngwVpnOpenNatModal(vpnConfig.preselectNatUser);
    }
  }

  // RouterOS String Escaper
  function rosEsc(val) {
    return String(val == null ? '' : val)
      .replace(/\\/g, '\\\\')
      .replace(/"/g, '\\"')
      .replace(/\$/g, '\\$');
  }

  // Merge & deduplicate port lists from selected Voice Call apps (WhatsApp / imo / Messenger)
  function mergePortStrings(portStrList) {
    var seen = {};
    var items = [];
    for (var i = 0; i < portStrList.length; i++) {
      var parts = String(portStrList[i] || '').split(',');
      for (var j = 0; j < parts.length; j++) {
        var tok = parts[j].trim();
        if (!tok || seen[tok]) continue;
        seen[tok] = true;
        var num = parseInt(tok.split('-')[0], 10) || 0;
        items.push({ tok: tok, num: num });
      }
    }
    items.sort(function(a, b) {
      if (a.num !== b.num) return a.num - b.num;
      return a.tok.localeCompare(b.tok);
    });
    return items.map(function(x) { return x.tok; }).join(',');
  }

  function getSelectedApps(containerId) {
    var box = document.getElementById(containerId);
    if (!box) return [];
    var cbs = box.querySelectorAll('input[type="checkbox"]:checked');
    var out = [];
    for (var i = 0; i < cbs.length; i++) out.push(cbs[i].value);
    return out;
  }

  function getPurposeLabel(selectedKeys) {
    var apps = vpnConfig.voiceApps || {};
    var names = [];
    for (var i = 0; i < selectedKeys.length; i++) {
      if (apps[selectedKeys[i]]) names.push(apps[selectedKeys[i]].label);
    }
    return names.length > 0 ? names.join(' + ') : 'Custom';
  }

  function computeAppPorts(selectedKeys) {
    var apps = vpnConfig.voiceApps || {};
    var tcpList = [];
    var udpList = [];
    for (var i = 0; i < selectedKeys.length; i++) {
      var a = apps[selectedKeys[i]];
      if (!a) continue;
      if (a.tcp) tcpList.push(a.tcp);
      if (a.udp) udpList.push(a.udp);
    }
    return {
      tcp: mergePortStrings(tcpList),
      udp: mergePortStrings(udpList)
    };
  }

  function detectMatchingApps(tcpVal, udpVal) {
    var normTcp = mergePortStrings([tcpVal || '']);
    var normUdp = mergePortStrings([udpVal || '']);
    var allKeys = ['whatsapp', 'imo', 'messenger'];
    for (var mask = 1; mask < (1 << allKeys.length); mask++) {
      var subset = [];
      for (var bit = 0; bit < allKeys.length; bit++) {
        if (mask & (1 << bit)) subset.push(allKeys[bit]);
      }
      var merged = computeAppPorts(subset);
      if (merged.tcp === normTcp && merged.udp === normUdp) {
        return subset;
      }
    }
    return null;
  }

  function syncPurposeCheckboxesToPorts(containerId, tcpVal, udpVal) {
    var box = document.getElementById(containerId);
    if (!box) return;
    var matched = detectMatchingApps(tcpVal, udpVal);
    var cbs = box.querySelectorAll('input[type="checkbox"]');
    for (var i = 0; i < cbs.length; i++) {
      cbs[i].checked = matched ? (matched.indexOf(cbs[i].value) !== -1) : false;
    }
  }

  function resolvePurposeLabel(selectedKeys, tcpVal, udpVal) {
    var baseLabel = getPurposeLabel(selectedKeys);
    if (selectedKeys.length === 0) return 'Custom';
    var expected = computeAppPorts(selectedKeys);
    if (mergePortStrings([tcpVal || '']) !== expected.tcp || mergePortStrings([udpVal || '']) !== expected.udp) {
      return baseLabel + ' (Custom)';
    }
    return baseLabel;
  }

  // Shared Voice-Call Script Builder (ROS v6 & v7)
  function buildVoiceBypassScript(rosVer, tcpPorts, udpPorts, purposeLabel, userName) {
    var fixedName = vpnConfig.voiceInterface || 'Voice-Call';
    var lines = [];
    var header = '# ' + (purposeLabel || 'Voice-Call') + ' | RouterOS v' + rosVer;
    if (userName) header += ' | User: ' + userName;
    lines.push(header);
    lines.push(':local rtName "' + rosEsc(fixedName) + '";');
    lines.push(':local vpnIf "' + rosEsc(fixedName) + '";');
    if (rosVer === 7) {
      lines.push(':if ([:len [/routing table find name=$rtName]] = 0) do={');
      lines.push('  /routing table add name=$rtName fib');
      lines.push('}');
    }
    if (tcpPorts) {
      lines.push(':if ([:len [/ip firewall mangle find comment=("VOICE-CALL-TCP-" . $rtName)]] > 0) do={');
      lines.push('  /ip firewall mangle set [find comment=("VOICE-CALL-TCP-" . $rtName)] chain=prerouting protocol=tcp dst-port="' + rosEsc(tcpPorts) + '" action=mark-routing new-routing-mark=$rtName passthrough=no disabled=no');
      lines.push('} else={');
      lines.push('  /ip firewall mangle add chain=prerouting protocol=tcp dst-port="' + rosEsc(tcpPorts) + '" action=mark-routing new-routing-mark=$rtName passthrough=no comment=("VOICE-CALL-TCP-" . $rtName) disabled=no');
      lines.push('}');
    }
    if (udpPorts) {
      lines.push(':if ([:len [/ip firewall mangle find comment=("VOICE-CALL-UDP-" . $rtName)]] > 0) do={');
      lines.push('  /ip firewall mangle set [find comment=("VOICE-CALL-UDP-" . $rtName)] chain=prerouting protocol=udp dst-port="' + rosEsc(udpPorts) + '" action=mark-routing new-routing-mark=$rtName passthrough=no disabled=no');
      lines.push('} else={');
      lines.push('  /ip firewall mangle add chain=prerouting protocol=udp dst-port="' + rosEsc(udpPorts) + '" action=mark-routing new-routing-mark=$rtName passthrough=no comment=("VOICE-CALL-UDP-" . $rtName) disabled=no');
      lines.push('}');
    }
    if (rosVer === 7) {
      lines.push(':if ([:len [/ip route find comment=("VOICE-CALL-ROUTE-" . $rtName)]] > 0) do={');
      lines.push('  /ip route set [find comment=("VOICE-CALL-ROUTE-" . $rtName)] dst-address=0.0.0.0/0 gateway=$vpnIf routing-table=$rtName disabled=no');
      lines.push('} else={');
      lines.push('  /ip route add dst-address=0.0.0.0/0 gateway=$vpnIf routing-table=$rtName comment=("VOICE-CALL-ROUTE-" . $rtName) disabled=no');
      lines.push('}');
    } else {
      lines.push(':if ([:len [/ip route find comment=("VOICE-CALL-ROUTE-" . $rtName)]] > 0) do={');
      lines.push('  /ip route set [find comment=("VOICE-CALL-ROUTE-" . $rtName)] dst-address=0.0.0.0/0 gateway=$vpnIf routing-mark=$rtName disabled=no');
      lines.push('} else={');
      lines.push('  /ip route add dst-address=0.0.0.0/0 gateway=$vpnIf routing-mark=$rtName comment=("VOICE-CALL-ROUTE-" . $rtName) disabled=no');
      lines.push('}');
    }
    return lines.join('\n');
  }

  // MODAL 7: Add Voice Call (Purpose Select -> Generate -> Copy / Save)
  var vmRosVer = (vpnConfig.router && vpnConfig.router.ros_ver === 6) ? 6 : 7;

  window.ngwVpnOpenVoiceModal = function() {
    var modal = document.getElementById('vpnVoiceModal');
    if (!modal) return;
    vmRosVer = (vpnConfig.router && vpnConfig.router.ros_ver === 6) ? 6 : 7;
    var userEl = document.getElementById('vmUserSelect');
    if (userEl) userEl.value = '';
    var tcpEl = document.getElementById('voiceTcpInput');
    var udpEl = document.getElementById('voiceUdpInput');
    if (tcpEl) tcpEl.value = vpnConfig.voiceTcp || '';
    if (udpEl) udpEl.value = vpnConfig.voiceUdp || '';
    syncPurposeCheckboxesToPorts('vmPurposeList', vpnConfig.voiceTcp || '', vpnConfig.voiceUdp || '');
    syncVmRosButtons();
    ngwVpnResetVmGenerated();
    modal.classList.add('open');
  };

  window.ngwVpnSetVmRos = function(v) {
    vmRosVer = (v === 6) ? 6 : 7;
    syncVmRosButtons();
    ngwVpnResetVmGenerated();
  };

  function syncVmRosButtons() {
    var btns = document.querySelectorAll('#vmRosToggle button');
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('active', parseInt(btns[i].getAttribute('data-ros'), 10) === vmRosVer);
    }
  }

  window.ngwVpnResetVmGenerated = function() {
    var wrap = document.getElementById('vmCodeWrap');
    var copyBtn = document.getElementById('vmCopyBtn');
    var box = document.getElementById('vmCodeBox');
    if (wrap) wrap.style.display = 'none';
    if (copyBtn) copyBtn.style.display = 'none';
    if (box) box.value = '';
  };

  window.ngwVpnOnVmPurposeChange = function() {
    var keys = getSelectedApps('vmPurposeList');
    var merged = computeAppPorts(keys);
    var tcpEl = document.getElementById('voiceTcpInput');
    var udpEl = document.getElementById('voiceUdpInput');
    if (tcpEl) tcpEl.value = merged.tcp;
    if (udpEl) udpEl.value = merged.udp;
    ngwVpnResetVmGenerated();
  };

  window.ngwVpnGenerateVmScript = function() {
    var tcpEl = document.getElementById('voiceTcpInput');
    var udpEl = document.getElementById('voiceUdpInput');
    var userEl = document.getElementById('vmUserSelect');
    var tcp = tcpEl ? tcpEl.value.trim() : '';
    var udp = udpEl ? udpEl.value.trim() : '';
    if (!tcp && !udp) {
      ngwVpnToast(vpnConfig.selectPurposeMsg);
      return;
    }
    var keys = getSelectedApps('vmPurposeList');
    var purposeLabel = resolvePurposeLabel(keys, tcp, udp);
    var uName = userEl ? userEl.value.trim() : '';
    var script = buildVoiceBypassScript(vmRosVer, tcp, udp, purposeLabel, uName);
    var box = document.getElementById('vmCodeBox');
    var wrap = document.getElementById('vmCodeWrap');
    var copyBtn = document.getElementById('vmCopyBtn');
    var pSpan = document.getElementById('vmCodePurpose');
    if (box) box.value = script;
    if (pSpan) pSpan.textContent = purposeLabel + ' · ROS v' + vmRosVer + ' · ' + (vpnConfig.voiceInterface || 'Voice-Call');
    if (wrap) wrap.style.display = '';
    if (copyBtn) copyBtn.style.display = '';
    ngwVpnToast(vpnConfig.generatedLabel);
  };

  // Client Auto-Connect Script Modal (ROS v6 & v7)
  var csState = { user: null, ros: 7, proto: 'sstp' };

  window.ngwVpnOpenClientScript = function(uData) {
    csState.user = uData;
    csState.ros = (vpnConfig.router && vpnConfig.router.ros_ver === 6) ? 6 : 7;
    csState.proto = (uData.service === 'l2tp' || uData.service === 'pptp' || uData.service === 'sstp') ? uData.service : 'sstp';
    document.getElementById('csTitle').textContent = 'VPN Script — ' + uData.name;
    document.getElementById('csIfName').value = vpnConfig.voiceInterface || 'Voice-Call';
    document.getElementById('csSstpPort').value = vpnConfig.router ? (vpnConfig.router.sstp_port || 443) : 443;
    document.getElementById('csIpsecSecret').value = vpnConfig.router ? (vpnConfig.router.l2tp_ipsec_secret || '') : '';
    syncCsButtons();
    ngwVpnRenderClientScript();
    document.getElementById('vpnClientScriptModal').classList.add('open');
  };

  window.ngwVpnSetCsRos = function(v) {
    csState.ros = (v === 6) ? 6 : 7;
    syncCsButtons();
    ngwVpnRenderClientScript();
  };

  window.ngwVpnSetCsProto = function(p) {
    csState.proto = p;
    syncCsButtons();
    ngwVpnRenderClientScript();
  };

  function syncCsButtons() {
    var rosBtns = document.querySelectorAll('#csRosToggle button');
    for (var i = 0; i < rosBtns.length; i++) {
      rosBtns[i].classList.toggle('active', parseInt(rosBtns[i].getAttribute('data-ros'), 10) === csState.ros);
    }
    var pBtns = document.querySelectorAll('#csProtoToggle button');
    for (var j = 0; j < pBtns.length; j++) {
      pBtns[j].classList.toggle('active', pBtns[j].getAttribute('data-proto') === csState.proto);
    }
    var sstpWrap = document.getElementById('csSstpPortWrap');
    var ipsecWrap = document.getElementById('csIpsecWrap');
    if (sstpWrap) sstpWrap.style.display = (csState.proto === 'sstp') ? '' : 'none';
    if (ipsecWrap) ipsecWrap.style.display = (csState.proto === 'l2tp') ? '' : 'none';
  }

  window.ngwVpnRenderClientScript = function() {
    if (!csState.user || !vpnConfig.router) return;
    var u = csState.user;
    var host = vpnConfig.router.host;
    var ifName = (document.getElementById('csIfName').value || vpnConfig.voiceInterface || 'Voice-Call').trim();
    var sstpPort = parseInt(document.getElementById('csSstpPort').value, 10) || 443;
    var ipsecSec = (document.getElementById('csIpsecSecret').value || '').trim();
    var metaEl = document.getElementById('csCodeMeta');
    if (metaEl) {
      metaEl.textContent = 'ROS v' + csState.ros + ' · ' + csState.proto.toUpperCase() + ' · Interface: ' + ifName;
    }
    var script = '';

    if (csState.proto === 'sstp') {
      var connectPart = (csState.ros === 7)
        ? ('connect-to="' + rosEsc(host) + '" port=' + sstpPort)
        : ('connect-to="' + rosEsc(sstpPort === 443 ? host : (host + ':' + sstpPort)) + '"');
      script =
        ':local vpnName "' + rosEsc(ifName) + '";\n' +
        ':if ([:len [/interface sstp-client find name=$vpnName]] > 0) do={\n' +
        '  /interface sstp-client set [find name=$vpnName] ' + connectPart + ' user="' + rosEsc(u.name) + '" password="' + rosEsc(u.password) + '" profile=default-encryption disabled=no\n' +
        '} else={\n' +
        '  /interface sstp-client add name=$vpnName ' + connectPart + ' user="' + rosEsc(u.name) + '" password="' + rosEsc(u.password) + '" profile=default-encryption disabled=no\n' +
        '}';
    } else if (csState.proto === 'l2tp') {
      var ipsecPart = ipsecSec !== ''
        ? ('use-ipsec=yes ipsec-secret="' + rosEsc(ipsecSec) + '"')
        : 'use-ipsec=no';
      script =
        ':local vpnName "' + rosEsc(ifName) + '";\n' +
        ':if ([:len [/interface l2tp-client find name=$vpnName]] > 0) do={\n' +
        '  /interface l2tp-client set [find name=$vpnName] connect-to="' + rosEsc(host) + '" user="' + rosEsc(u.name) + '" password="' + rosEsc(u.password) + '" profile=default-encryption ' + ipsecPart + ' disabled=no\n' +
        '} else={\n' +
        '  /interface l2tp-client add name=$vpnName connect-to="' + rosEsc(host) + '" user="' + rosEsc(u.name) + '" password="' + rosEsc(u.password) + '" profile=default-encryption ' + ipsecPart + ' disabled=no\n' +
        '}';
    } else {
      script =
        ':local vpnName "' + rosEsc(ifName) + '";\n' +
        ':if ([:len [/interface pptp-client find name=$vpnName]] > 0) do={\n' +
        '  /interface pptp-client set [find name=$vpnName] connect-to="' + rosEsc(host) + '" user="' + rosEsc(u.name) + '" password="' + rosEsc(u.password) + '" profile=default-encryption disabled=no\n' +
        '} else={\n' +
        '  /interface pptp-client add name=$vpnName connect-to="' + rosEsc(host) + '" user="' + rosEsc(u.name) + '" password="' + rosEsc(u.password) + '" profile=default-encryption disabled=no\n' +
        '}';
    }

    document.getElementById('csCodeBox').value = script;
  };

  // MODAL 4: Voice Call Row Script Modal (ROS v6/v7 + Purpose Select -> Generate -> Copy)
  var vsState = { user: null, ros: 7 };

  window.ngwVpnOpenVoiceScript = function(vData) {
    vsState.user = vData;
    vsState.ros = (vpnConfig.router && vpnConfig.router.ros_ver === 6) ? 6 : 7;
    document.getElementById('vsTitle').textContent = (vpnConfig.scriptTitlePrefix || 'Script') + ' — ' + vData.name;
    var fixedName = vpnConfig.voiceInterface || 'Voice-Call';
    var meta = document.getElementById('vsMetaLine');
    if (meta) {
      meta.textContent = 'Interface & Table: ' + fixedName;
    }
    var tcpEl = document.getElementById('vsTcpInput');
    var udpEl = document.getElementById('vsUdpInput');
    if (tcpEl) tcpEl.value = vpnConfig.voiceTcp || '';
    if (udpEl) udpEl.value = vpnConfig.voiceUdp || '';
    syncPurposeCheckboxesToPorts('vsPurposeList', vpnConfig.voiceTcp || '', vpnConfig.voiceUdp || '');
    syncVsButtons();
    ngwVpnResetVsGenerated();
    document.getElementById('vpnVoiceScriptModal').classList.add('open');
  };

  window.ngwVpnSetVsRos = function(v) {
    vsState.ros = (v === 6) ? 6 : 7;
    syncVsButtons();
    ngwVpnResetVsGenerated();
  };

  function syncVsButtons() {
    var btns = document.querySelectorAll('#vsRosToggle button');
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('active', parseInt(btns[i].getAttribute('data-ros'), 10) === vsState.ros);
    }
  }

  window.ngwVpnResetVsGenerated = function() {
    var wrap = document.getElementById('vsCodeWrap');
    var copyBtn = document.getElementById('vsCopyBtn');
    var box = document.getElementById('vsCodeBox');
    if (wrap) wrap.style.display = 'none';
    if (copyBtn) copyBtn.style.display = 'none';
    if (box) box.value = '';
  };

  window.ngwVpnOnVsPurposeChange = function() {
    var keys = getSelectedApps('vsPurposeList');
    var merged = computeAppPorts(keys);
    var tcpEl = document.getElementById('vsTcpInput');
    var udpEl = document.getElementById('vsUdpInput');
    if (tcpEl) tcpEl.value = merged.tcp;
    if (udpEl) udpEl.value = merged.udp;
    ngwVpnResetVsGenerated();
  };

  window.ngwVpnGenerateVsScript = function() {
    var tcpEl = document.getElementById('vsTcpInput');
    var udpEl = document.getElementById('vsUdpInput');
    var tcp = tcpEl ? tcpEl.value.trim() : '';
    var udp = udpEl ? udpEl.value.trim() : '';
    if (!tcp && !udp) {
      ngwVpnToast(vpnConfig.selectPurposeMsg);
      return;
    }
    var keys = getSelectedApps('vsPurposeList');
    var purposeLabel = resolvePurposeLabel(keys, tcp, udp);
    var uName = (vsState.user && vsState.user.name) ? vsState.user.name : '';
    var script = buildVoiceBypassScript(vsState.ros, tcp, udp, purposeLabel, uName);
    var box = document.getElementById('vsCodeBox');
    var wrap = document.getElementById('vsCodeWrap');
    var copyBtn = document.getElementById('vsCopyBtn');
    var pSpan = document.getElementById('vsCodePurpose');
    if (box) box.value = script;
    if (pSpan) pSpan.textContent = purposeLabel + ' · ROS v' + vsState.ros + ' · ' + (vpnConfig.voiceInterface || 'Voice-Call');
    if (wrap) wrap.style.display = '';
    if (copyBtn) copyBtn.style.display = '';
    ngwVpnToast(vpnConfig.generatedLabel);
  };
})();
</script>
