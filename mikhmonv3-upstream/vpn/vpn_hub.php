<?php
/*
 *  NextGan WiFi Solution — Centralized MikroTik VPN Hub & Voice Call Bypass Module
 *  Features:
 *  - Dedicated VPN Server router list with Lazy Connect
 *  - PPP Secret creation (L2TP, SSTP, PPTP, Any) with Pool/Profile detection & Group Comment
 *  - Automated 3-port serial NAT forwarding (Winbox 8010, API 8011, Web 8012)
 *  - One-click WhatsApp Voice Call Traffic Bypass push ("ভয়েস কল") for ROS6 & ROS7
 *  - Real-time Active VPN connections monitor
 */
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

include_once('./include/reseller_acl.php');
include_once('./include/config.php');
include_once('./lib/routeros_api.class.php');
include_once('./lib/formatbytesbites.php');

if (!isMikhmonAdmin() && !hasResellerPermission('manage_router_settings')) {
    echo "<div class='card pd-15'><h3 class='text-danger'><i class='fa fa-ban'></i> Access Denied</h3><p>Only Administrator or authorized users can access the VPN Hub.</p></div>";
    exit;
}

$vpnStoragePath = __DIR__ . '/../include/vpn_routers.json';

/** Language-aware VPN Hub labels; $langid is set by the main application. */
function vpnText($english, $bengali) {
    global $langid;
    return (isset($langid) && $langid === 'bn') ? $bengali : $english;
}
function vpnSafeName($value) {
    $value = trim((string)$value);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) $value = $converted;
    }
    $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value);
    $value = trim($value, '_-');
    return $value !== '' ? $value : 'vpn';
}

function loadVpnRouters() {
    global $vpnStoragePath;
    if (!file_exists($vpnStoragePath)) {
        return array();
    }
    $raw = @file_get_contents($vpnStoragePath);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function saveVpnRouters($routers) {
    global $vpnStoragePath;
    return @file_put_contents($vpnStoragePath, json_encode(array_values($routers), JSON_PRETTY_PRINT)) !== false;
}

$vpnRouters = loadVpnRouters();
$vpnMsg = '';
$vpnErr = '';

// Handle VPN Router CRUD.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_vpn_router']) || isset($_POST['add_vpn_router'])) {
        $editId = trim((string)(isset($_POST['vpn_router_id']) ? $_POST['vpn_router_id'] : ''));
        $rName = trim((string)$_POST['vpn_name']);
        $rHost = trim((string)$_POST['vpn_host']);
        $rUser = trim((string)$_POST['vpn_user']);
        $rPass = (string)(isset($_POST['vpn_pass']) ? $_POST['vpn_pass'] : '');
        $rPort = !empty($_POST['vpn_port']) ? (int)$_POST['vpn_port'] : 8728;
        $rVer = isset($_POST['vpn_ros_ver']) && (int)$_POST['vpn_ros_ver'] === 6 ? 6 : 7;
        if ($rName === '' || $rHost === '' || $rUser === '' || $rPort < 1 || $rPort > 65535) {
            $vpnErr = 'Please enter a valid router name, host, username and API port.';
        } else {
            $found = false;
            foreach ($vpnRouters as $idx => $vr) {
                if ($editId !== '' && isset($vr['id']) && $vr['id'] === $editId) {
                    $vpnRouters[$idx]['name'] = $rName;
                    $vpnRouters[$idx]['host'] = $rHost;
                    $vpnRouters[$idx]['user'] = $rUser;
                    $vpnRouters[$idx]['port'] = $rPort;
                    $vpnRouters[$idx]['ros_ver'] = $rVer;
                    if ($rPass !== '') {
                        $vpnRouters[$idx]['pass_enc'] = function_exists('encrypt') ? encrypt($rPass) : base64_encode($rPass);
                    }
                    $found = true;
                    break;
                }
            }
            if ($editId !== '' && !$found) {
                $vpnErr = 'VPN Router not found; no changes were saved.';
            } else {
                if ($editId === '') {
                    $vpnRouters[] = array(
                        'id' => 'vpn_' . time() . '_' . mt_rand(100, 999),
                        'name' => $rName, 'host' => $rHost, 'user' => $rUser,
                        'pass_enc' => function_exists('encrypt') ? encrypt($rPass) : base64_encode($rPass),
                        'port' => $rPort, 'ros_ver' => $rVer, 'created_at' => date('Y-m-d H:i:s'),
                    );
                    $vpnMsg = 'VPN Router added successfully.';
                } else {
                    $vpnMsg = 'VPN Router updated successfully.';
                }
                saveVpnRouters($vpnRouters);
            }
        }
    } elseif (isset($_POST['delete_vpn_router'])) {
        $delId = trim((string)$_POST['del_id']);
        $vpnRouters = array_values(array_filter($vpnRouters, function($vr) use ($delId) {
            return !isset($vr['id']) || $vr['id'] !== $delId;
        }));
        saveVpnRouters($vpnRouters);
        $vpnMsg = 'VPN Router removed.';
    }
}

// Active opened VPN router
$activeVpnId = isset($_GET['vpn_id']) ? trim($_GET['vpn_id']) : (isset($_POST['active_vpn_id']) ? trim($_POST['active_vpn_id']) : '');
$currentVpnRouter = null;
foreach ($vpnRouters as $vr) {
    if ($vr['id'] === $activeVpnId) {
        $currentVpnRouter = $vr;
        break;
    }
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : (isset($_POST['active_tab']) ? $_POST['active_tab'] : 'secrets');
$API = null;
$isConnected = false;
$routerResource = array();
$routerPools = array();
$routerProfiles = array();
$routerNatRules = array();
$routerPppSecrets = array();
$routerPppActive = array();
$routerMangleRules = array();

// Lazy connection: Only connect if a specific VPN router is opened
if ($currentVpnRouter !== null) {
    $API = new RouterosAPI();
    $API->debug = false;
    $API->timeout = 3;
    $API->attempts = 1;
    $API->port = !empty($currentVpnRouter['port']) ? (int)$currentVpnRouter['port'] : 8728;
    
    $vPass = '';
    if (!empty($currentVpnRouter['pass_enc'])) {
        $vPass = function_exists('decrypt') ? decrypt($currentVpnRouter['pass_enc']) : base64_decode($currentVpnRouter['pass_enc']);
    }

    $isConnected = $API->connect($currentVpnRouter['host'], $currentVpnRouter['user'], $vPass);

    if ($isConnected) {
        // Handle actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // 1. Create PPP Secret
            if (isset($_POST['create_ppp_secret'])) {
                $sUser   = trim((string)$_POST['secret_user']);
                $sPass   = (string)$_POST['secret_pass'];
                $sServ   = trim((string)$_POST['secret_service']);
                $sLocal  = trim((string)$_POST['secret_local']);
                $sRemote = trim((string)$_POST['secret_remote']);
                $sGroup  = trim((string)$_POST['secret_group']);

                if ($sUser === '' || $sPass === '' || $sRemote === '') {
                    $vpnErr = 'Please enter Username, Password, and Static Remote Address.';
                } else {
                    $secretParams = array(
                        'name' => $sUser,
                        'password' => $sPass,
                        'service' => ($sServ !== '' ? $sServ : 'any'),
                        'remote-address' => $sRemote,
                        'comment' => $sGroup !== '' ? $sGroup : 'VPN-User',
                    );
                    if ($sLocal !== '') {
                        $secretParams['local-address'] = $sLocal;
                    }
                    $res = $API->comm('/ppp/secret/add', $secretParams);
                    $vpnMsg = "PPP Secret <b>" . htmlspecialchars($sUser) . "</b> created successfully.";
                }
            }

            // 2. Remove / Enable / Disable PPP Secret
            if (isset($_POST['action_secret'])) {
                $secId = trim((string)$_POST['secret_id']);
                $secAct = trim((string)$_POST['action_secret']);
                if ($secAct === 'remove') {
                    $API->comm('/ppp/secret/remove', array('.id' => $secId));
                    $vpnMsg = "PPP Secret removed.";
                } elseif ($secAct === 'disable') {
                    $API->comm('/ppp/secret/set', array('.id' => $secId, 'disabled' => 'yes'));
                    $vpnMsg = "PPP Secret disabled.";
                } elseif ($secAct === 'enable') {
                    $API->comm('/ppp/secret/set', array('.id' => $secId, 'disabled' => 'no'));
                    $vpnMsg = "PPP Secret enabled.";
                }
            }

            // 3. Port Forwarding (NAT 3-Port Forward: Winbox, API, Web)
            if (isset($_POST['apply_port_forward'])) {
                $fTargetIp = trim((string)$_POST['forward_ip']);
                $fGroup    = trim((string)$_POST['forward_group']);
                $fUser     = trim((string)$_POST['forward_user']);
                $pWinbox   = (int)$_POST['port_winbox'];
                $pApi      = (int)$_POST['port_api'];
                $pWeb      = (int)$_POST['port_web'];

                if ($fTargetIp === '' || $pWinbox <= 0 || $pApi <= 0 || $pWeb <= 0) {
                    $vpnErr = 'Invalid IP address or port numbers for port forwarding.';
                } else {
                    $labelBase = ($fGroup !== '' ? $fGroup : 'Client') . ' - ' . ($fUser !== '' ? $fUser : $fTargetIp);
                    
                    // Winbox (Internal 8291)
                    $API->comm('/ip/firewall/nat/add', array(
                        'chain' => 'dstnat',
                        'action' => 'dst-nat',
                        'to-addresses' => $fTargetIp,
                        'to-ports' => '8291',
                        'protocol' => 'tcp',
                        'dst-port' => (string)$pWinbox,
                        'comment' => $labelBase . ' - Winbox',
                    ));

                    // API (Internal 8728)
                    $API->comm('/ip/firewall/nat/add', array(
                        'chain' => 'dstnat',
                        'action' => 'dst-nat',
                        'to-addresses' => $fTargetIp,
                        'to-ports' => '8728',
                        'protocol' => 'tcp',
                        'dst-port' => (string)$pApi,
                        'comment' => $labelBase . ' - API',
                    ));

                    // Web (Internal 80)
                    $API->comm('/ip/firewall/nat/add', array(
                        'chain' => 'dstnat',
                        'action' => 'dst-nat',
                        'to-addresses' => $fTargetIp,
                        'to-ports' => '80',
                        'protocol' => 'tcp',
                        'dst-port' => (string)$pWeb,
                        'comment' => $labelBase . ' - Web',
                    ));

                    $vpnMsg = "Port Forwarding created for <b>" . htmlspecialchars($fTargetIp) . "</b> (Winbox: $pWinbox, API: $pApi, Web: $pWeb).";
                }
            }

            // 4. Edit or delete an individual DST-NAT rule.
            if (isset($_POST['edit_nat_rule'])) {
                $natId = trim((string)$_POST['nat_id']);
                $natDst = (int)$_POST['nat_dst_port'];
                $natAddr = trim((string)$_POST['nat_to_address']);
                $natTo = (int)$_POST['nat_to_port'];
                $natProto = strtolower(trim((string)$_POST['nat_protocol']));
                $natComment = trim((string)$_POST['nat_comment']);
                if ($natId === '' || $natDst < 1 || $natDst > 65535 || $natTo < 1 || $natTo > 65535 ||
                    !filter_var($natAddr, FILTER_VALIDATE_IP) || !in_array($natProto, array('tcp','udp','icmp'), true)) {
                    $vpnErr = vpnText('Enter a valid external port, target IP, internal port and protocol.', 'বৈধ External Port, Target IP, Internal Port ও Protocol দিন।');
                } else {
                    $natUpdate = array('.id' => $natId, 'dst-port' => (string)$natDst, 'to-addresses' => $natAddr, 'to-ports' => (string)$natTo, 'protocol' => $natProto, 'comment' => $natComment);
                    $natResult = $API->comm('/ip/firewall/nat/set', $natUpdate);
                    $vpnMsg = vpnText('Port-forward rule updated.', 'Port Forward Rule আপডেট হয়েছে।');
                }
            }
            if (isset($_POST['remove_nat_rule'])) {
                $natId = trim((string)$_POST['nat_id']);
                $API->comm('/ip/firewall/nat/remove', array('.id' => $natId));
                $vpnMsg = vpnText('Port-forward rule removed.', 'Port Forward Rule মুছে ফেলা হয়েছে।');
            }

            // 5. Push voice-call rules for the selected PPP secret only.
            if (isset($_POST['push_whatsapp_bypass'])) {
                $bpUser = trim((string)(isset($_POST['bypass_user']) ? $_POST['bypass_user'] : ''));
                $bpRosVer = isset($currentVpnRouter['ros_ver']) ? (int)$currentVpnRouter['ros_ver'] : 7;
                $allSecretsForPush = $API->comm('/ppp/secret/print');
                $bpSecret = null;
                if (is_array($allSecretsForPush)) {
                    foreach ($allSecretsForPush as $secretForPush) {
                        if (isset($secretForPush['name']) && $secretForPush['name'] === $bpUser) {
                            $bpSecret = $secretForPush;
                            break;
                        }
                    }
                }
                $bpTargetIp = ($bpSecret !== null && isset($bpSecret['remote-address'])) ? trim((string)$bpSecret['remote-address']) : '';
                $bpGroup = ($bpSecret !== null && isset($bpSecret['comment'])) ? trim((string)$bpSecret['comment']) : '';
                if ($bpUser === '' || $bpSecret === null || $bpTargetIp === '' || !filter_var($bpTargetIp, FILTER_VALIDATE_IP)) {
                    $vpnErr = vpnText('Select a PPP Secret with a valid static Remote Address first.', 'আগে বৈধ Static Remote Address-সহ একটি PPP Secret নির্বাচন করুন।');
                } else {
                    $bpLabel = ($bpGroup !== '' ? $bpGroup . ' - ' : '') . $bpUser . ' - Voice Call';
                    $bpPorts = '3478,3480,3481,34784,45395,50318,59234,5222,4244,5223,5228,5242,27526,48320';
                    $existingMangle = $API->comm('/ip/firewall/mangle/print');
                    $existingMangle = is_array($existingMangle) ? $existingMangle : array();
                    $tcpComment = $bpLabel . ' TCP';
                    $udpComment = $bpLabel . ' UDP';
                    $tcpExists = false; $udpExists = false;
                    foreach ($existingMangle as $existingRule) {
                        $comment = isset($existingRule['comment']) ? $existingRule['comment'] : '';
                        if ($comment === $tcpComment) $tcpExists = true;
                        if ($comment === $udpComment) $udpExists = true;
                    }
                    if (!$tcpExists) {
                        $API->comm('/ip/firewall/mangle/add', array(
                            'chain' => 'prerouting', 'action' => 'mark-routing',
                            'new-routing-mark' => 'voicecall', 'passthrough' => 'no',
                            'src-address' => $bpTargetIp, 'protocol' => 'tcp', 'dst-port' => $bpPorts,
                            'comment' => $tcpComment,
                        ));
                    }
                    if (!$udpExists) {
                        $API->comm('/ip/firewall/mangle/add', array(
                            'chain' => 'prerouting', 'action' => 'mark-routing',
                            'new-routing-mark' => 'voicecall', 'passthrough' => 'no',
                            'src-address' => $bpTargetIp, 'protocol' => 'udp', 'dst-port' => $bpPorts,
                            'comment' => $udpComment,
                        ));
                    }
                    $routeComment = $bpLabel . ' Route';
                    $routes = $API->comm('/ip/route/print');
                    $routeExists = false;
                    if (is_array($routes)) foreach ($routes as $route) {
                        if (isset($route['comment']) && $route['comment'] === $routeComment) $routeExists = true;
                    }
                    $routeOk = true;
                    if ($bpRosVer === 7) {
                        $tables = $API->comm('/routing/table/print');
                        $tableExists = false;
                        if (is_array($tables)) foreach ($tables as $table) {
                            if (isset($table['name']) && $table['name'] === 'voicecall') $tableExists = true;
                        }
                        if (!$tableExists) {
                            $tableResult = $API->comm('/routing/table/add', array('name' => 'voicecall', 'fib' => 'yes'));
                            if (isset($tableResult[0]) && $tableResult[0] === '!trap') $routeOk = false;
                        }
                        if ($routeOk && !$routeExists) {
                            $routeResult = $API->comm('/ip/route/add', array(
                                'dst-address' => '0.0.0.0/0', 'gateway' => $bpTargetIp,
                                'routing-table' => 'voicecall', 'comment' => $routeComment,
                            ));
                            if (isset($routeResult[0]) && $routeResult[0] === '!trap') $routeOk = false;
                        }
                    } elseif (!$routeExists) {
                        $routeResult = $API->comm('/ip/route/add', array(
                            'dst-address' => '0.0.0.0/0', 'gateway' => $bpTargetIp,
                            'routing-mark' => 'voicecall', 'comment' => $routeComment,
                        ));
                        if (isset($routeResult[0]) && $routeResult[0] === '!trap') $routeOk = false;
                    }
                    if ($routeOk) {
                        $vpnMsg = vpnText('Voice-call rules pushed for ', 'Voice Call Rule Push হয়েছে: ') . '<b>' . htmlspecialchars($bpUser) . '</b>.';
                    } else {
                        $vpnErr = vpnText('Mangle rules may exist, but the routing table/route failed. Check RouterOS version and permissions.', 'Mangle Rule তৈরি হতে পারে, কিন্তু Routing Table/Route ব্যর্থ হয়েছে। RouterOS Version ও Permission পরীক্ষা করুন।');
                    }
                }
            }

            // 6. Disable voice-call routing for one selected PPP Secret.
            if (isset($_POST['remove_whatsapp_bypass'])) {
                $disableUser = trim((string)(isset($_POST['bypass_user']) ? $_POST['bypass_user'] : ''));
                $secretsForDisable = $API->comm('/ppp/secret/print');
                $disableSecret = null;
                if (is_array($secretsForDisable)) foreach ($secretsForDisable as $ss) {
                    if (isset($ss['name']) && $ss['name'] === $disableUser) { $disableSecret = $ss; break; }
                }
                if ($disableSecret === null || $disableUser === '') {
                    $vpnErr = vpnText('Select a valid VPN user first.', 'আগে বৈধ VPN ইউজার নির্বাচন করুন।');
                } else {
                    $disableGroup = isset($disableSecret['comment']) ? trim((string)$disableSecret['comment']) : '';
                    $disableLabel = ($disableGroup !== '' ? $disableGroup . ' - ' : '') . $disableUser . ' - Voice Call';
                    $rulesToRemove = $API->comm('/ip/firewall/mangle/print');
                    if (is_array($rulesToRemove)) foreach ($rulesToRemove as $ruleToRemove) {
                        $ruleComment = isset($ruleToRemove['comment']) ? $ruleToRemove['comment'] : '';
                        if ($ruleComment === $disableLabel . ' TCP' || $ruleComment === $disableLabel . ' UDP') {
                            if (isset($ruleToRemove['.id'])) $API->comm('/ip/firewall/mangle/remove', array('.id' => $ruleToRemove['.id']));
                        }
                    }
                    $routesToRemove = $API->comm('/ip/route/print');
                    if (is_array($routesToRemove)) foreach ($routesToRemove as $routeToRemove) {
                        if (isset($routeToRemove['comment']) && $routeToRemove['comment'] === $disableLabel . ' Route' && isset($routeToRemove['.id'])) {
                            $API->comm('/ip/route/remove', array('.id' => $routeToRemove['.id']));
                        }
                    }
                    $vpnMsg = vpnText('Voice-call routing disabled for ', 'এই ইউজারের Voice Call Routing বন্ধ হয়েছে: ') . '<b>' . htmlspecialchars($disableUser) . '</b>.';
                }
            }

            // 7. Disconnect Active VPN
            if (isset($_POST['disconnect_active_vpn'])) {
                $actId = trim((string)$_POST['active_vpn_id']);
                $API->comm('/ppp/active/remove', array('.id' => $actId));
                $vpnMsg = "Active VPN session disconnected.";
            }
        }

        // Fetch fresh state from connected RouterOS
        $res = $API->comm('/system/resource/print');
        $routerResource = is_array($res) && isset($res[0]) ? $res[0] : array();
        
        $pools = $API->comm('/ip/pool/print');
        $routerPools = is_array($pools) ? $pools : array();

        $profs = $API->comm('/ppp/profile/print');
        $routerProfiles = is_array($profs) ? $profs : array();

        $secs = $API->comm('/ppp/secret/print');
        $routerPppSecrets = is_array($secs) ? $secs : array();

        $acts = $API->comm('/ppp/active/print');
        $routerPppActive = is_array($acts) ? $acts : array();

        $nats = $API->comm('/ip/firewall/nat/print', array('?chain' => 'dstnat'));
        $routerNatRules = is_array($nats) ? $nats : array();
        if ($tab === 'secrets' || $tab === 'bypass') {
            $mangles = $API->comm('/ip/firewall/mangle/print');
            $routerMangleRules = is_array($mangles) ? $mangles : array();
        }
    } else {
        $vpnErr = "Could not connect to VPN Router at <b>" . htmlspecialchars($currentVpnRouter['host']) . "</b>. Please verify IP, API port, and credentials.";
    }
}

// Calculate next available serial ports for Winbox/API/Web
$suggestedWinboxPort = 8010;
$usedDstPorts = array();
if (!empty($routerNatRules)) {
    foreach ($routerNatRules as $nr) {
        if (!empty($nr['dst-port']) && is_numeric($nr['dst-port'])) {
            $p = (int)$nr['dst-port'];
            $usedDstPorts[$p] = true;
        }
    }
}
while (isset($usedDstPorts[$suggestedWinboxPort]) || isset($usedDstPorts[$suggestedWinboxPort + 1]) || isset($usedDstPorts[$suggestedWinboxPort + 2])) {
    $suggestedWinboxPort += 3;
}
$suggestedApiPort = $suggestedWinboxPort + 1;
$suggestedWebPort = $suggestedWinboxPort + 2;

$baseVpnUrl = "./admin.php?id=vpn";
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <h3 style="margin:0;">
          <i class="fa fa-shield text-primary"></i> <?= isset($_vpn_hub) ? $_vpn_hub : 'VPN Hub'; ?>
          <?php if ($currentVpnRouter !== null) { ?>
            &nbsp; | &nbsp;
            <span style="font-size:14px; font-weight:normal;">
              Router: <b><?= htmlspecialchars($currentVpnRouter['name']); ?></b> (<?= htmlspecialchars($currentVpnRouter['host']); ?>)
              <?php if ($isConnected) { ?>
                <span class="text-green" style="font-size:12px; margin-left:6px;"><i class="fa fa-check-circle"></i> Connected</span>
              <?php } else { ?>
                <span class="text-red" style="font-size:12px; margin-left:6px;"><i class="fa fa-times-circle"></i> Disconnected</span>
              <?php } ?>
            </span>
          <?php } ?>
        </h3>
        <div>
          <?php if ($currentVpnRouter !== null) { ?>
            <a href="<?= $baseVpnUrl; ?>" class="btn bg-warning" style="margin-right:6px;"><i class="fa fa-arrow-left"></i> All VPN Routers</a>
          <?php } ?>
          <button type="button" class="btn bg-primary" onclick="document.getElementById('modalAddVpnRouter').style.display='block';"><i class="fa fa-plus"></i> Add VPN Router</button>
        </div>
      </div>
      <div class="card-body">
        <?php if ($vpnMsg !== '') { ?>
          <div class="bg-green pd-10 radius-3 mr-b-10"><i class="fa fa-check"></i> <?= $vpnMsg; ?></div>
        <?php } ?>
        <?php if ($vpnErr !== '') { ?>
          <div class="bg-danger pd-10 radius-3 mr-b-10"><i class="fa fa-ban"></i> <?= $vpnErr; ?></div>
        <?php } ?>

        <?php if ($currentVpnRouter === null) { ?>
          <!-- LIST OF CONFIGURED VPN ROUTERS -->
          <div class="card">
            <div class="card-header">
              <h4 style="margin:0;"><i class="fa fa-server"></i> Configured VPN Server Routers (<?= count($vpnRouters); ?>)</h4>
            </div>
            <div class="card-body">
              <?php if (empty($vpnRouters)) { ?>
                <div class="pd-20 text-center text-muted" style="border: 2px dashed rgba(150,150,150,0.3); border-radius:6px;">
                  <i class="fa fa-shield" style="font-size:40px; margin-bottom:10px; opacity:0.6;"></i>
                  <p style="font-size:15px; margin-bottom:15px;">No dedicated VPN Server router configured yet.</p>
                  <button type="button" class="btn bg-primary" onclick="document.getElementById('modalAddVpnRouter').style.display='block';">
                    <i class="fa fa-plus"></i> Add Your First VPN Router
                  </button>
                </div>
              <?php } else { ?>
                <div class="overflow box-bordered">
                  <table class="table table-bordered table-hover text-nowrap">
                    <thead>
                      <tr>
                        <th>Router Name</th>
                        <th>IP / Host</th>
                        <th>API Port</th>
                        <th>RouterOS</th>
                        <th>Created</th>
                        <th class="text-right">Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($vpnRouters as $vr) { ?>
                        <tr>
                          <td><b><?= htmlspecialchars($vr['name']); ?></b></td>
                          <td><code><?= htmlspecialchars($vr['host']); ?></code></td>
                          <td><?= htmlspecialchars($vr['port']); ?></td>
                          <td>v<?= htmlspecialchars(isset($vr['ros_ver']) ? $vr['ros_ver'] : '7'); ?></td>
                          <td><?= htmlspecialchars(isset($vr['created_at']) ? $vr['created_at'] : '-'); ?></td>
                          <td class="text-right" style="white-space:nowrap;">
                            <a href="<?= $baseVpnUrl; ?>&vpn_id=<?= urlencode($vr['id']); ?>" class="btn bg-green" style="padding:5px 9px;font-size:12px;"><i class="fa fa-external-link"></i> Open</a>
                            <button type="button" class="btn bg-primary" style="padding:5px 9px;font-size:12px;" onclick='openVpnRouterEdit(<?= json_encode($vr, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP); ?>)'><i class="fa fa-pencil"></i> Edit</button>
                            <form method="post" action="" style="display:inline;" onsubmit="return confirm('Remove VPN Router <?= htmlspecialchars($vr['name'], ENT_QUOTES); ?>?');">
                              <input type="hidden" name="del_id" value="<?= htmlspecialchars($vr['id']); ?>">
                              <button type="submit" name="delete_vpn_router" class="btn bg-danger" style="padding:5px 9px;font-size:12px;" title="Delete"><i class="fa fa-trash"></i></button>
                            </form>
                          </td>
                        </tr>
                      <?php } ?>
                    </tbody>
                  </table>
                </div>
              <?php } ?>
            </div>
          </div>
        <?php } else { ?>
          <!-- OPENED VPN ROUTER DASHBOARD -->
          <?php if (!$isConnected) { ?>
            <div class="bg-danger pd-15 radius-3 mr-b-15">
              <h4><i class="fa fa-plug"></i> Router Connection Failed</h4>
              <p>Cannot reach MikroTik RouterOS API on <b><?= htmlspecialchars($currentVpnRouter['host']); ?>:<?= htmlspecialchars($currentVpnRouter['port']); ?></b>. Please ensure the router is online and API service (port 8728) is enabled.</p>
              <a href="<?= $baseVpnUrl; ?>&vpn_id=<?= urlencode($currentVpnRouter['id']); ?>" class="btn bg-primary"><i class="fa fa-refresh"></i> Retry Connection</a>
            </div>
          <?php } else { ?>
            <!-- ROUTER INFO SUMMARY BAR -->
            <div class="row mr-b-10">
              <div class="col-3 col-box-6">
                <div class="box pd-10 bg-primary radius-3">
                  <div style="font-size:11px; opacity:0.8;">BOARD &amp; MODEL</div>
                  <div style="font-size:15px; font-weight:bold;"><?= htmlspecialchars(isset($routerResource['board-name']) ? $routerResource['board-name'] : 'MikroTik'); ?></div>
                </div>
              </div>
              <div class="col-3 col-box-6">
                <div class="box pd-10 bg-blue radius-3">
                  <div style="font-size:11px; opacity:0.8;">ROUTEROS VERSION</div>
                  <div style="font-size:15px; font-weight:bold;"><?= htmlspecialchars(isset($routerResource['version']) ? $routerResource['version'] : 'v7'); ?></div>
                </div>
              </div>
              <div class="col-3 col-box-6">
                <div class="box pd-10 bg-purple radius-3">
                  <div style="font-size:11px; opacity:0.8;">ACTIVE VPN CLIENTS</div>
                  <div style="font-size:15px; font-weight:bold;"><?= count($routerPppActive); ?> Active</div>
                </div>
              </div>
              <div class="col-3 col-box-6">
                <div class="box pd-10 bg-green radius-3">
                  <div style="font-size:11px; opacity:0.8;">TOTAL PPP SECRETS</div>
                  <div style="font-size:15px; font-weight:bold;"><?= count($routerPppSecrets); ?> Configured</div>
                </div>
              </div>
            </div>

            <!-- TABS NAVIGATION -->
            <div style="border-bottom: 2px solid rgba(150,150,150,0.2); margin-bottom:15px;">
              <a href="<?= $baseVpnUrl; ?>&vpn_id=<?= urlencode($activeVpnId); ?>&tab=secrets" class="btn <?= $tab === 'secrets' ? 'bg-primary' : 'bg-secondary'; ?>" style="border-radius:4px 4px 0 0; margin-right:4px;">
                <i class="fa fa-key"></i> <?= vpnText('Secret List', 'VPN তালিকা'); ?>
              </a>
              <a href="<?= $baseVpnUrl; ?>&vpn_id=<?= urlencode($activeVpnId); ?>&tab=nat" class="btn <?= $tab === 'nat' ? 'bg-primary' : 'bg-secondary'; ?>" style="border-radius:4px 4px 0 0; margin-right:4px;">
                <i class="fa fa-exchange"></i> <?= vpnText('Port Forwarding', 'Port Forwarding'); ?>
              </a>
              <a href="<?= $baseVpnUrl; ?>&vpn_id=<?= urlencode($activeVpnId); ?>&tab=bypass" class="btn <?= $tab === 'bypass' ? 'bg-primary' : 'bg-secondary'; ?>" style="border-radius:4px 4px 0 0; margin-right:4px;">
                <i class="fa fa-whatsapp"></i> <?= vpnText('Voice Call', 'ভয়েস কল'); ?>
              </a>
              <a href="<?= $baseVpnUrl; ?>&vpn_id=<?= urlencode($activeVpnId); ?>&tab=active" class="btn <?= $tab === 'active' ? 'bg-primary' : 'bg-secondary'; ?>" style="border-radius:4px 4px 0 0;">
                <i class="fa fa-wifi"></i> Active Connections (<?= count($routerPppActive); ?>)
              </a>
            </div>

            <!-- TAB 1: PPP SECRETS -->
            <?php if ($tab === 'secrets') { ?>
              <div class="row">
                <!-- FORM: CREATE PPP SECRET -->
                <div class="col-4 col-box-12">
                  <div class="card">
                    <div class="card-header">
                      <h4 style="margin:0;"><i class="fa fa-user-plus"></i> <?= vpnText('Add VPN User', 'VPN ইউজার যোগ করুন'); ?></h4>
                    </div>
                    <div class="card-body">
                      <form method="post" action="<?= $baseVpnUrl; ?>&vpn_id=<?= urlencode($activeVpnId); ?>&tab=secrets" autocomplete="off">
                        <input type="hidden" name="active_vpn_id" value="<?= htmlspecialchars($activeVpnId); ?>">
                        <input type="hidden" name="active_tab" value="secrets">

                        <div class="form-group mr-b-10">
                          <label style="font-weight:bold; font-size:12px;"><?= vpnText('VPN Username:', 'VPN ইউজারনেম:'); ?></label>
                          <input type="text" name="secret_user" class="form-control" required placeholder="e.g. vpn_user01">
                        </div>

                        <div class="form-group mr-b-10">
                          <label style="font-weight:bold; font-size:12px;"><?= vpnText('Password:', 'পাসওয়ার্ড:'); ?></label>
                          <input type="text" name="secret_pass" class="form-control" required placeholder="Password">
                        </div>

                        <div class="form-group mr-b-10">
                          <label style="font-weight:bold; font-size:12px;"><?= vpnText('Service:', 'Service:'); ?></label>
                          <select name="secret_service" class="form-control">
                            <option value="any">any (All protocols)</option>
                            <option value="l2tp">L2TP</option>
                            <option value="sstp" selected>SSTP</option>
                            <option value="pptp">PPTP</option>
                            <option value="ovpn">OpenVPN</option>
                          </select>
                        </div>

                        <div class="form-group mr-b-10">
                          <label style="font-weight:bold; font-size:12px;"><?= vpnText('Local Address / Pool:', 'Local Address / Pool:'); ?></label>
                          <select name="secret_local" class="form-control">
                            <option value="">Default Profile Gateway</option>
                            <?php foreach ($routerPools as $pl) { ?>
                              <option value="<?= htmlspecialchars($pl['name']); ?>">Pool: <?= htmlspecialchars($pl['name']); ?> (<?= htmlspecialchars($pl['ranges']); ?>)</option>
                            <?php } ?>
                          </select>
                        </div>

                        <div class="form-group mr-b-10">
                          <label style="font-weight:bold; font-size:12px;"><?= vpnText('Static Remote IP:', 'Static Remote IP:'); ?></label>
                          <input type="text" name="secret_remote" class="form-control" required placeholder="e.g. 10.10.10.50">
                          <small class="text-muted">Client will always receive this static IP upon connection.</small>
                        </div>

                        <div class="form-group mr-b-10">
                          <label style="font-weight:bold; font-size:12px;"><?= vpnText('Group / Customer Name:', 'Group / Customer Name:'); ?></label>
                          <input type="text" name="secret_group" class="form-control" required placeholder="e.g. শাহিন">
                          <small class="text-muted">Saved in comment so all user routers can be filtered together.</small>
                        </div>

                        <button type="submit" name="create_ppp_secret" class="btn bg-primary btn-block">
                          <i class="fa fa-save"></i> Create VPN Secret
                        </button>
                      </form>
                    </div>
                  </div>
                </div>

                <!-- TABLE: PPP SECRETS LIST -->
                <div class="col-8 col-box-12">
                  <div class="card">
                    <div class="card-header">
                      <h4 style="margin:0;"><i class="fa fa-list"></i> <?= vpnText('Secret List', 'VPN তালিকা'); ?> (<?= count($routerPppSecrets); ?>)</h4>
                    </div>
                    <div class="card-body">
                      <div class="overflow box-bordered vpn-secret-table-wrap" style="max-height:600px;">
                        <table class="table table-bordered table-hover vpn-secret-table" style="width:100%;table-layout:auto;">
                          <thead>
                            <tr>
                              <th>Username</th>
                              <th>Service</th>
                              <th>Remote IP</th>
                              <th>Group / Comment</th>
                              <th>Status</th>
                              <th class="text-right">Action</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php if (empty($routerPppSecrets)) { ?>
                              <tr><td colspan="6" class="text-center text-muted">No PPP secrets found on this router.</td></tr>
                            <?php } else {
                              foreach ($routerPppSecrets as $sec) {
                                $sId = isset($sec['.id']) ? $sec['.id'] : '';
                                $sName = isset($sec['name']) ? $sec['name'] : '';
                                $sServ = isset($sec['service']) ? $sec['service'] : 'any';
                                $sRem = isset($sec['remote-address']) ? $sec['remote-address'] : '-';
                                $sComm = isset($sec['comment']) ? $sec['comment'] : '-';
                                $sDis = (isset($sec['disabled']) && ($sec['disabled'] === 'true' || $sec['disabled'] === 'yes'));
                            ?>
                              <tr>
                                <?php
                                  $relatedNat = array();
                                  foreach ($routerNatRules as $rr) {
                                      $rc = isset($rr['comment']) ? (string)$rr['comment'] : '';
                                      if ($sName !== '' && strpos($rc, $sName) !== false) $relatedNat[] = $rr;
                                  }
                                  $hasVoice = false;
                                  foreach ($routerMangleRules as $mr) {
                                      $mc = isset($mr['comment']) ? (string)$mr['comment'] : '';
                                      if (stripos($mc, 'Voice Call') !== false && $sName !== '' && strpos($mc, $sName) !== false) $hasVoice = true;
                                  }
                                  $svc = in_array($sServ, array('sstp','l2tp','pptp','ovpn'), true) ? $sServ : 'sstp';
                                  $safeModalId = 'vpnDetail' . preg_replace('/[^A-Za-z0-9]/', '', $sId);
                                ?>
                                <td><b><?= htmlspecialchars($sName); ?></b></td>
                                <td><?= htmlspecialchars($sServ); ?></td>
                                <td><code><?= htmlspecialchars($sRem); ?></code></td>
                                <td><?= htmlspecialchars($sComm); ?></td>
                                <td>
                                  <?= $sDis ? "<span class='text-red'>Disabled</span>" : "<span class='text-green'>Active</span>"; ?>
                                </td>
                                <td class="text-right vpn-secret-actions" style="text-align:right;white-space:nowrap;min-width:90px;">
                                  <details class="vpn-secret-menu" style="position:relative;display:inline-block;">
                                    <summary class="btn bg-secondary" style="cursor:pointer;list-style:none;padding:5px 10px;min-width:38px;text-align:center;">&#8942;</summary>
                                    <div class="vpn-secret-menu-panel" style="position:fixed;right:auto;z-index:99999;min-width:155px;max-width:calc(100vw - 24px);background:var(--card-bg,#fff);border:1px solid #aaa;border-radius:6px;padding:7px;box-shadow:0 4px 16px rgba(0,0,0,.22);">
                                      <button type="button" class="btn bg-info btn-block" onclick="document.getElementById('<?= $safeModalId; ?>').style.display='block';"><?= vpnText('Details','বিস্তারিত'); ?></button>
                                      <button type="button" class="btn bg-primary btn-block" onclick="openVpnScriptModal(this)" data-user="<?= htmlspecialchars($sName, ENT_QUOTES); ?>" data-group="<?= htmlspecialchars($sComm, ENT_QUOTES); ?>" data-service="<?= htmlspecialchars($svc, ENT_QUOTES); ?>" data-voice="<?= $hasVoice ? '1' : '0'; ?>"><?= vpnText('Script','স্ক্রিপ্ট'); ?></button>
                                      <form method="post" action="">
                                        <input type="hidden" name="active_vpn_id" value="<?= htmlspecialchars($activeVpnId); ?>">
                                        <input type="hidden" name="active_tab" value="secrets">
                                        <input type="hidden" name="secret_id" value="<?= htmlspecialchars($sId); ?>">
                                        <button type="submit" name="action_secret" value="<?= $sDis ? 'enable' : 'disable'; ?>" class="btn <?= $sDis ? 'bg-green' : 'bg-warning'; ?> btn-block"><?= $sDis ? vpnText('Enable','চালু করুন') : vpnText('Disable','বন্ধ করুন'); ?></button>
                                        <button type="submit" name="action_secret" value="remove" class="btn bg-danger btn-block" onclick="return confirm('<?= vpnText('Delete this secret?','এই Secret মুছে ফেলবেন?'); ?>')"><?= vpnText('Delete','মুছুন'); ?></button>
                                      </form>
                                    </div>
                                  </details>
                                </td>
                              </tr>
                            <?php } } ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            <?php } ?>

<script>
(function(){
  function closeMenus(except){
    document.querySelectorAll('.vpn-secret-menu[open]').forEach(function(d){if(d!==except)d.removeAttribute('open');});
  }
  document.addEventListener('click',function(e){
    var summary=e.target.closest('.vpn-secret-menu > summary');
    if(summary){
      var details=summary.parentElement;
      setTimeout(function(){
        if(details.open){
          closeMenus(details);
          var panel=details.querySelector('.vpn-secret-menu-panel');
          var rect=summary.getBoundingClientRect();
          var width=Math.min(180,window.innerWidth-24);
          var left=Math.max(12,Math.min(rect.right-width,window.innerWidth-width-12));
          var top=rect.bottom+5;
          panel.style.width=width+'px';
          if(top+panel.offsetHeight>window.innerHeight-8)top=Math.max(8,rect.top-panel.offsetHeight-5);
          panel.style.left=left+'px';panel.style.top=top+'px';panel.style.right='auto';
        }else{closeMenus(null);}
      },0);
      return;
    }
    if(!e.target.closest('.vpn-secret-menu'))closeMenus(null);
  });
  window.addEventListener('scroll',function(){closeMenus(null);},true);
  window.addEventListener('resize',function(){closeMenus(null);});
})();
</script>
              <div id="vpnClientScriptModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:10000;padding:20px;overflow:auto;">
                <div class="card" style="max-width:850px;margin:20px auto;padding:15px;">
                  <button type="button" class="btn bg-secondary" style="float:right;" onclick="document.getElementById('vpnClientScriptModal').style.display='none'">&times;</button>
                  <h4><?= vpnText('VPN Client Script','VPN Client Script'); ?> — <span id="vpnScriptUserLabel"></span></h4>
                  <div class="row">
                    <div class="col-4 col-box-12"><label><?= vpnText('RouterOS Version','RouterOS Version'); ?></label><select id="vpnScriptRos" class="form-control"><option value="7">7</option><option value="6">6</option></select></div>
                    <div class="col-4 col-box-12"><label><?= vpnText('Service','Service'); ?></label><select id="vpnScriptService" class="form-control"><option value="sstp">SSTP</option><option value="l2tp">L2TP</option><option value="pptp">PPTP</option><option value="ovpn">OpenVPN</option></select></div>
                    <div class="col-4 col-box-12"><label><?= vpnText('VPN Password','VPN Password'); ?></label><input id="vpnScriptPass" type="password" class="form-control" autocomplete="new-password"></div>
                  </div>
                  <label style="display:block;margin:12px 0;"><input type="checkbox" id="vpnScriptVoice"> <?= vpnText('Include Voice Call routing','Voice Call routing যুক্ত করুন'); ?></label>
                  <button type="button" class="btn bg-primary" onclick="generateVpnClientScript()"><?= vpnText('Generate Script','Script তৈরি করুন'); ?></button>
                  <button type="button" class="btn bg-secondary" onclick="copyVpnClientScript()"><?= vpnText('Copy Script','Script কপি করুন'); ?></button>
                  <textarea id="vpnScriptOutput" class="form-control" rows="16" style="margin-top:10px;font-family:monospace;" readonly></textarea>
                  <small class="text-muted"><?= vpnText('SSTP endpoint: mehedi.xgenwifi.xyz:4520. Review the commands before running them.', 'SSTP endpoint: mehedi.xgenwifi.xyz:4520। চালানোর আগে Command যাচাই করুন।'); ?></small>
                </div>
              </div>
              <script>
              var vpnScriptData = {};
              function openVpnScriptModal(btn) {
                vpnScriptData = {user:btn.getAttribute('data-user')||'',group:btn.getAttribute('data-group')||'VPN',service:btn.getAttribute('data-service')||'sstp',voice:btn.getAttribute('data-voice')==='1'};
                document.getElementById('vpnScriptUserLabel').textContent = vpnScriptData.user;
                document.getElementById('vpnScriptService').value = vpnScriptData.service;
                document.getElementById('vpnScriptVoice').checked = vpnScriptData.voice;
                document.getElementById('vpnScriptPass').value = '';
                document.getElementById('vpnScriptOutput').value = '';
                document.getElementById('vpnClientScriptModal').style.display = 'block';
              }
              function quoteRos(v){return String(v).replace(/[\\\"\r\n]/g,'_');}
              function generateVpnClientScript() {
                var user=quoteRos(vpnScriptData.user), group=quoteRos(vpnScriptData.group), pass=quoteRos(document.getElementById('vpnScriptPass').value);
                var svc=document.getElementById('vpnScriptService').value, ros=document.getElementById('vpnScriptRos').value, voice=document.getElementById('vpnScriptVoice').checked;
                if(!pass){alert('Enter the VPN password to generate the client command.');return;}
                var safe=(group+'_'+user).replace(/[^A-Za-z0-9_-]+/g,'_').replace(/^_+|_+$/g,'').substring(0,30);
                if(!safe)safe='vpn_'+user.replace(/[^A-Za-z0-9_-]/g,'').substring(0,20);
                var name=(safe+(voice?'_voicecall':'')).substring(0,32), comment=group+' '+user+(voice?' Voice Call':'');
                var c=['# NextGan WiFi Solution | RouterOS '+ros+' | '+svc.toUpperCase()];
                if(svc==='sstp') c.push('/interface sstp-client add name="'+name+'" connect-to="mehedi.xgenwifi.xyz" port=4520 user="'+user+'" password="'+pass+'" profile=default-encryption add-default-route=no disabled=no comment="'+comment+'"');
                else if(svc==='l2tp') c.push('/interface l2tp-client add name="'+name+'" connect-to="mehedi.xgenwifi.xyz" user="'+user+'" password="'+pass+'" use-ipsec=no add-default-route=no disabled=no comment="'+comment+'"');
                else if(svc==='pptp') c.push('/interface pptp-client add name="'+name+'" connect-to="mehedi.xgenwifi.xyz" user="'+user+'" password="'+pass+'" add-default-route=no disabled=no comment="'+comment+'"');
                else c.push('/interface ovpn-client add name="'+name+'" connect-to="mehedi.xgenwifi.xyz" port=1194 mode=ip user="'+user+'" password="'+pass+'" add-default-route=no disabled=no comment="'+comment+'"');
                if(voice){
                  var ports='3478,3480,3481,34784,45395,50318,59234,5222,4244,5223,5228,5242,27526,48320';
                  if(ros==='7'){c.push('/routing/table/add name=voicecall fib');c.push('/ip/route/add dst-address=0.0.0.0/0 gateway="'+name+'" routing-table=voicecall comment="'+comment+' Route"');}
                  else c.push('/ip/route/add dst-address=0.0.0.0/0 gateway="'+name+'" routing-mark=voicecall comment="'+comment+' Route"');
                  c.push('/ip/firewall/mangle/add chain=prerouting action=mark-routing new-routing-mark=voicecall passthrough=no src-address=0.0.0.0/0 protocol=tcp dst-port='+ports+' comment="'+comment+' TCP"');
                  c.push('/ip/firewall/mangle/add chain=prerouting action=mark-routing new-routing-mark=voicecall passthrough=no src-address=0.0.0.0/0 protocol=udp dst-port='+ports+' comment="'+comment+' UDP"');
                }
                document.getElementById('vpnScriptOutput').value=c.join('\n');
              }
              function copyVpnClientScript(){var e=document.getElementById('vpnScriptOutput');if(!e.value){alert('Generate the script first.');return;}e.focus();e.select();try{document.execCommand('copy');}catch(err){}}
              </script>

            <!-- TAB 2: PORT FORWARDING (NAT) -->
            <?php if ($tab === 'nat') { ?>
              <div class="row">
                <!-- FORM: CREATE 3-PORT NAT FORWARD -->
                <div class="col-5 col-box-12">
                  <div class="card">
                    <div class="card-header">
                      <h4 style="margin:0;"><i class="fa fa-exchange"></i> <?= vpnText('Port Forwarding (Winbox, API, Web)', 'Port Forwarding (Winbox, API, Web)'); ?></h4>
                    </div>
                    <div class="card-body">
                      <form method="post" action="<?= $baseVpnUrl; ?>&vpn_id=<?= urlencode($activeVpnId); ?>&tab=nat" autocomplete="off" id="natForwardForm">
                        <input type="hidden" name="active_vpn_id" value="<?= htmlspecialchars($activeVpnId); ?>">
                        <input type="hidden" name="active_tab" value="nat">
                        <input type="hidden" name="nat_id" id="nat_edit_id" value="">

                        <div class="form-group mr-b-10">
                          <label style="font-weight:bold; font-size:12px;"><?= vpnText('Search / Select VPN User', 'VPN ইউজার খুঁজুন / নির্বাচন করুন'); ?></label>
                          <input id="searchVpnClient" class="form-control" list="vpnClientOptions" placeholder="<?= vpnText('Type username to search…', 'ইউজারনেম লিখে খুঁজুন…'); ?>" autocomplete="off" oninput="applySearchedVpnClient(this.value)">
                          <datalist id="vpnClientOptions">
                            <?php foreach ($routerPppSecrets as $s) {
                              $rem = isset($s['remote-address']) ? $s['remote-address'] : '';
                              $sName = isset($s['name']) ? $s['name'] : '';
                              $sComm = isset($s['comment']) ? $s['comment'] : '';
                              if ($sName === '') continue;
                            ?>
                              <option value="<?= htmlspecialchars($sName); ?>"></option>
                            <?php } ?>
                          </datalist>
                          <input type="hidden" id="selectVpnClient" value="">
                        </div>

                        <div class="form-group mr-b-10">
                          <label style="font-weight:bold; font-size:12px;"><?= vpnText('Client Static IP:', 'Client Static IP:'); ?></label>
                          <input type="text" id="forward_ip" name="forward_ip" class="form-control" readonly required placeholder="Auto-filled from PPP Secret">
                        </div>

                        <div class="row">
                          <div class="col-6">
                            <div class="form-group mr-b-10">
                              <label style="font-weight:bold; font-size:12px;"><?= vpnText('Customer / Group:', 'Customer / Group:'); ?></label>
                              <input type="text" id="forward_group" name="forward_group" class="form-control" readonly required placeholder="Auto-filled from PPP Secret">
                            </div>
                          </div>
                          <div class="col-6">
                            <div class="form-group mr-b-10">
                              <label style="font-weight:bold; font-size:12px;">VPN Username:</label>
                              <input type="text" id="forward_user" name="forward_user" class="form-control" readonly required placeholder="Auto-filled from PPP Secret">
                            </div>
                          </div>
                        </div>

                        <div style="background: rgba(150,150,150,0.1); border-radius:4px; padding:10px; margin-bottom:12px;">
                          <div style="font-size:12px; font-weight:bold; margin-bottom:8px;"><?= vpnText('Internal Port → External Port', 'Internal Port → External Port'); ?></div>
                          <div class="row">
                            <div class="col-4">
                              <label style="font-size:11px;">Winbox · Internal Port</label>
                              <input type="number" id="nat_winbox_external" name="port_winbox" class="form-control" min="1" max="65535" value="<?= $suggestedWinboxPort; ?>" required>
                              <input type="hidden" id="nat_winbox_internal" value="8291">
                            </div>
                            <div class="col-4">
                              <label style="font-size:11px;">API · Internal Port</label>
                              <input type="number" id="nat_api_external" name="port_api" class="form-control" min="1" max="65535" value="<?= $suggestedApiPort; ?>" required>
                              <input type="hidden" id="nat_api_internal" value="8728">
                            </div>
                            <div class="col-4">
                              <label style="font-size:11px;">Web · Internal Port</label>
                              <input type="number" id="nat_web_external" name="port_web" class="form-control" min="1" max="65535" value="<?= $suggestedWebPort; ?>" required>
                              <input type="hidden" id="nat_web_internal" value="80">
                            </div>
                          </div>
                        </div>

                        <div id="natEditFields" style="display:none;margin:10px 0;padding:10px;border:1px solid #bbb;border-radius:5px;">
                          <h5><?= vpnText('Edit selected NAT rule','নির্বাচিত NAT Rule সম্পাদনা'); ?></h5>
                          <div class="row">
                            <div class="col-6"><label><?= vpnText('External Port','External Port'); ?></label><input id="nat_dst_port" name="nat_dst_port" class="form-control" type="number" min="1" max="65535"></div>
                            <div class="col-6"><label><?= vpnText('Internal Port','Internal Port'); ?></label><input id="nat_to_port" name="nat_to_port" class="form-control" type="number" min="1" max="65535"></div>
                            <div class="col-6"><label><?= vpnText('Target IP','Target IP'); ?></label><input id="nat_to_address" name="nat_to_address" class="form-control"></div>
                            <div class="col-6"><label>Protocol</label><select id="nat_protocol" name="nat_protocol" class="form-control"><option>tcp</option><option>udp</option></select></div>
                            <div class="col-12"><label>Comment</label><input id="nat_comment" name="nat_comment" class="form-control"></div>
                          </div>
                          <button type="submit" name="edit_nat_rule" id="nat_edit_button" class="btn bg-primary"><?= vpnText('Save rule changes','Rule পরিবর্তন সংরক্ষণ'); ?></button>
                          <button type="button" class="btn bg-secondary" onclick="document.getElementById('natEditFields').style.display='none';document.getElementById('nat_apply_button').style.display='block';document.getElementById('nat_edit_button').style.display='none';"><?= vpnText('Cancel','বাতিল'); ?></button>
                        </div>
                        <button type="submit" name="apply_port_forward" id="nat_apply_button" class="btn bg-primary btn-block">
                          <i class="fa fa-check"></i> Apply Port Forwarding (3 Rules)
                        </button>
                      </form>
                    </div>
                  </div>
                </div>

                <!-- TABLE: ACTIVE NAT RULES -->
                <div class="col-7 col-box-12">
                  <div class="card">
                    <div class="card-header">
                      <h4 style="margin:0;"><i class="fa fa-list"></i> Active DST-NAT Port Forward Rules (<?= count($routerNatRules); ?>)</h4>
                    </div>
                    <div class="card-body">
                      <div class="overflow box-bordered" style="max-height:600px;">
                        <table class="table table-bordered table-hover text-nowrap">
                          <thead>
                            <tr>
                              <th>Action</th>
                              <th>Dst Port (Ext)</th>
                              <th>To Address</th>
                              <th>To Port (Int)</th>
                              <th>Comment</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php if (empty($routerNatRules)) { ?>
                              <tr><td colspan="5" class="text-center text-muted">No DST-NAT port forward rules found.</td></tr>
                            <?php } else {
                              foreach ($routerNatRules as $nr) {
                                $nId = isset($nr['.id']) ? $nr['.id'] : '';
                                $nDstPort = isset($nr['dst-port']) ? $nr['dst-port'] : '-';
                                $nToAddr = isset($nr['to-addresses']) ? $nr['to-addresses'] : '-';
                                $nToPort = isset($nr['to-ports']) ? $nr['to-ports'] : '-';
                                $nComm = isset($nr['comment']) ? $nr['comment'] : '-';
                            ?>
                              <tr>
                                <td>
                                  <form method="post" action="" style="display:inline;">
                                    <input type="hidden" name="active_vpn_id" value="<?= htmlspecialchars($activeVpnId); ?>">
                                    <input type="hidden" name="active_tab" value="nat">
                                    <input type="hidden" name="nat_id" value="<?= htmlspecialchars($nId); ?>">
                                    <button type="button" class="btn bg-primary" style="padding:2px 6px;" title="Edit rule" onclick='openNatEdit(<?= json_encode(array("id"=>$nId,"dst"=>$nDstPort,"ip"=>$nToAddr,"to"=>$nToPort,"comment"=>$nComm,"protocol"=>isset($nr["protocol"])?$nr["protocol"]:"tcp"), JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP); ?>)'><i class="fa fa-pencil"></i></button>
                                    <button type="submit" name="remove_nat_rule" class="btn bg-danger" style="padding:2px 6px;" title="Remove NAT Rule" onclick="return confirm('Remove NAT Rule for port <?= htmlspecialchars($nDstPort, ENT_QUOTES); ?>?');"><i class="fa fa-trash"></i></button>
                                  </form>
                                </td>
                                <td><b class="text-primary"><?= htmlspecialchars($nDstPort); ?></b></td>
                                <td><code><?= htmlspecialchars($nToAddr); ?></code></td>
                                <td><?= htmlspecialchars($nToPort); ?></td>
                                <td><?= htmlspecialchars($nComm); ?></td>
                              </tr>
                            <?php } } ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <script>
              var vpnSecretIndex = {};
              <?php foreach ($routerPppSecrets as $s) {
                $un = isset($s['name']) ? $s['name'] : '';
                if ($un === '') continue;
                $remote = isset($s['remote-address']) ? $s['remote-address'] : '';
                $group = isset($s['comment']) ? $s['comment'] : '';
              ?>
              vpnSecretIndex[<?= json_encode($un); ?>] = {ip:<?= json_encode($remote); ?>,user:<?= json_encode($un); ?>,group:<?= json_encode($group); ?>};
              <?php } ?>
              function openNatEdit(rule) {
                var id=document.getElementById('nat_edit_id');
                if(!id)return;
                id.value=rule.id||'';
                document.getElementById('nat_dst_port').value=rule.dst||'';
                document.getElementById('nat_to_address').value=rule.ip||'';
                document.getElementById('nat_to_port').value=rule.to||'';
                document.getElementById('nat_protocol').value=rule.protocol||'tcp';
                document.getElementById('nat_comment').value=rule.comment||'';
                document.getElementById('nat_edit_button').style.display='inline-block';
                document.getElementById('nat_apply_button').style.display='none';
                document.getElementById('natEditFields').style.display='block';
              }
              function applySearchedVpnUser(value) {
                var u=vpnSecretIndex[value];
                document.getElementById('selectVpnClient').value=value;
                document.getElementById('forward_ip').value=u?u.ip:'';
                document.getElementById('forward_user').value=u?u.user:'';
                document.getElementById('forward_group').value=u?u.group:'';
              }
              function applySearchedVpnClient(value){applySearchedVpnUser(value);}
              function applySearchedVoiceVpnUser(value) {
                var u=vpnSecretIndex[value];
                document.getElementById('selectVoiceVpnSecret').value=u?u.user:'';
                document.getElementById('bypass_client_ip').value=u?u.ip:'';
                document.getElementById('bypass_group').value=u?u.group:'';
              }
              </script>
            <?php } ?>

            <!-- TAB 3: WHATSAPP VOICE CALL BYPASS ("ভয়েস কল") -->
            <?php if ($tab === 'bypass') { ?>
              <div class="row">
                <div class="col-6 col-box-12">
                  <div class="card">
                    <div class="card-header">
                      <h4 style="margin:0;"><i class="fa fa-whatsapp text-green"></i> <?= vpnText('WhatsApp Voice Call Routing', 'WhatsApp Voice Call Routing'); ?></h4>
                    </div>
                    <div class="card-body">
                      <p style="font-size:13px;" class="text-muted">
                        Automatically pushes Mangle rules (TCP &amp; UDP WhatsApp Voice ports) and marks routing through the target VPN Gateway.
                      </p>
                      <form method="post" action="<?= $baseVpnUrl; ?>&vpn_id=<?= urlencode($activeVpnId); ?>&tab=bypass" autocomplete="off">
                        <input type="hidden" name="active_vpn_id" value="<?= htmlspecialchars($activeVpnId); ?>">
                        <input type="hidden" name="active_tab" value="bypass">

                        <div class="form-group mr-b-10">
                          <label style="font-weight:bold; font-size:12px;"><?= vpnText('Search / Select VPN User', 'VPN ইউজার খুঁজুন / নির্বাচন করুন'); ?></label>
                          <input id="searchVoiceVpnSecret" class="form-control" list="voiceVpnOptions" placeholder="<?= vpnText('Type username to search…', 'ইউজারনেম লিখে খুঁজুন…'); ?>" autocomplete="off" oninput="applySearchedVoiceVpnUser(this.value)" required>
                          <datalist id="voiceVpnOptions">
                            <?php foreach ($routerPppSecrets as $s) {
                              $sName = isset($s['name']) ? $s['name'] : '';
                              $sRem = isset($s['remote-address']) ? $s['remote-address'] : '';
                              $sComm = isset($s['comment']) ? $s['comment'] : '';
                              if ($sName === '') continue;
                            ?>
                              <option value="<?= htmlspecialchars($sName); ?>"></option>
                            <?php } ?>
                          </datalist>
                          <input type="hidden" id="selectVoiceVpnSecret" name="bypass_user" value="">
                          <input type="hidden" name="bypass_client_ip" id="bypass_client_ip" value="">
                          <input type="hidden" name="bypass_group" id="bypass_group" value="">
                          <small class="text-muted"><?= vpnText('Only usernames are shown; IP is read from the selected secret.', 'শুধু ইউজারনেম দেখাবে; নির্বাচিত Secret থেকে IP নেওয়া হবে।'); ?></small>
                        </div>

                        <button type="submit" name="push_whatsapp_bypass" class="btn bg-green btn-block" style="font-size:14px; font-weight:bold; padding:8px 15px;">
                          <i class="fa fa-cloud-upload"></i> <?= vpnText('Push Voice Call Rules', 'ভয়েস কল Rules Push করুন'); ?>
                        </button>
                      </form>
                    </div>
                  </div>
                </div>

                <div class="col-6 col-box-12">
                  <div class="card">
                    <div class="card-header"><h4 style="margin:0;"><i class="fa fa-terminal"></i> <?= vpnText('Generate VPN Client Script','VPN Client Script তৈরি করুন'); ?></h4></div>
                    <div class="card-body">
                      <div class="form-group"><label><?= vpnText('Search / Select VPN User','VPN ইউজার খুঁজুন / নির্বাচন করুন'); ?></label>
                        <input id="scriptVpnSearch" class="form-control" list="scriptVpnOptions" placeholder="<?= vpnText('Type username to search…','ইউজারনেম লিখে খুঁজুন…'); ?>" oninput="selectScriptVpnUser(this.value)">
                        <datalist id="scriptVpnOptions"><?php foreach ($routerPppSecrets as $ss) { $scriptUser=isset($ss['name'])?$ss['name']:''; if($scriptUser!==''){ ?><option value="<?= htmlspecialchars($scriptUser); ?>"></option><?php } } ?></datalist>
                      </div>
                      <input type="hidden" id="scriptVpnUser" value="">
                      <input type="hidden" id="scriptVpnGroup" value="">
                      <div class="row">
                        <div class="col-6"><label>RouterOS</label><select id="scriptVpnRos" class="form-control"><option value="6">RouterOS 6</option><option value="7" selected>RouterOS 7</option></select></div>
                        <div class="col-6"><label><?= vpnText('Service','Service'); ?></label><select id="scriptVpnService" class="form-control"><option value="sstp">SSTP</option><option value="l2tp">L2TP</option><option value="pptp">PPTP</option><option value="ovpn">OpenVPN</option></select></div>
                      </div>
                      <div class="form-group" style="margin-top:10px;"><label><?= vpnText('VPN Password','VPN Password'); ?></label><input type="password" id="scriptVpnPass" class="form-control" autocomplete="new-password"></div>
                      <label style="display:block;margin:10px 0;"><input type="checkbox" id="scriptVpnVoice"> <?= vpnText('Include WhatsApp Voice Call routing','WhatsApp Voice Call Routing যুক্ত করুন'); ?></label>
                      <button type="button" class="btn bg-primary" onclick="generateVpnClientInline()"><?= vpnText('Generate Script','Script তৈরি করুন'); ?></button>
                      <button type="button" class="btn bg-secondary" onclick="copyVpnInlineScript()"><?= vpnText('Copy Script','Script কপি করুন'); ?></button>
                      <textarea id="scriptVpnOutput" class="form-control" rows="12" readonly style="margin-top:10px;font-family:monospace;"></textarea>
                      <small class="text-muted"><?= vpnText('SSTP uses mehedi.xgenwifi.xyz on port 4520. Select RouterOS 6 or 7 before generating.', 'SSTP-এর জন্য mehedi.xgenwifi.xyz এবং Port 4520 ব্যবহার হবে। Generate করার আগে RouterOS 6 বা 7 নির্বাচন করুন।'); ?></small>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card mr-t-10">
                <div class="card-header"><h4 style="margin:0;"><i class="fa fa-list"></i> <?= vpnText('WhatsApp Voice Call User List','WhatsApp Voice Call User List'); ?></h4></div>
                <div class="card-body">
                  <div class="overflow box-bordered" style="max-height:420px;">
                    <table class="table table-bordered table-hover">
                      <thead><tr><th><?= vpnText('User','ইউজার'); ?></th><th><?= vpnText('Static IP','Static IP'); ?></th><th><?= vpnText('Voice Rules','Voice Rules'); ?></th><th class="text-right"><?= vpnText('Action','অ্যাকশন'); ?></th></tr></thead>
                      <tbody>
                        <?php
                          $voiceStatusRows=array();
                          foreach($routerPppSecrets as $vsec){
                            $vu=isset($vsec['name'])?$vsec['name']:'';
                            $vip=isset($vsec['remote-address'])?$vsec['remote-address']:'';
                            $vg=isset($vsec['comment'])?$vsec['comment']:'';
                            if($vu==='')continue;
                            $vl=($vg!==''?$vg.' - ':'').$vu.' - Voice Call';
                            $vOn=false;
                            foreach($routerMangleRules as $vmr){
                              $vcomment=isset($vmr['comment'])?$vmr['comment']:'';
                              if(($vcomment===$vl.' TCP'||$vcomment===$vl.' UDP')&&empty($vmr['disabled']))$vOn=true;
                            }
                            $voiceStatusRows[]=array('user'=>$vu,'ip'=>$vip,'on'=>$vOn,'group'=>$vg);
                          }
                        ?>
                        <?php if(empty($voiceStatusRows)){ ?><tr><td colspan="4" class="text-center">No PPP Secrets found.</td></tr><?php } else { foreach($voiceStatusRows as $vs){ ?>
                          <tr>
                            <td><?= htmlspecialchars($vs['user']); ?></td><td><code><?= htmlspecialchars($vs['ip']); ?></code></td>
                            <td><?= $vs['on']?'<span class="text-green">Enabled</span>':'<span class="text-muted">Not configured</span>'; ?></td>
                            <td class="text-right">
                              <?php if($vs['on']){ ?>
                              <form method="post" action="<?= $baseVpnUrl; ?>&amp;vpn_id=<?= urlencode($activeVpnId); ?>&amp;tab=bypass" style="display:inline;" onsubmit="return confirm('Disable voice-call routing for this user?')">
                                <input type="hidden" name="active_vpn_id" value="<?= htmlspecialchars($activeVpnId); ?>"><input type="hidden" name="active_tab" value="bypass"><input type="hidden" name="bypass_user" value="<?= htmlspecialchars($vs['user']); ?>">
                                <button type="submit" name="remove_whatsapp_bypass" class="btn bg-warning" style="padding:4px 8px;"><?= vpnText('Disable','বন্ধ করুন'); ?></button>
                              </form>
                              <?php } else { ?>
                                <button type="button" class="btn bg-primary" style="padding:4px 8px;" onclick="document.getElementById('searchVoiceVpnSecret').value=<?= htmlspecialchars(json_encode($vs['user']), ENT_QUOTES); ?>;applySearchedVoiceVpnUser(<?= htmlspecialchars(json_encode($vs['user']), ENT_QUOTES); ?>);"><?= vpnText('Select','নির্বাচন'); ?></button>
                              <?php } ?>
                            </td>
                          </tr>
                        <?php } } ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
              <script>
              var voiceSecretData={};
              <?php foreach($routerPppSecrets as $vsd){$vu=isset($vsd['name'])?$vsd['name']:'';if($vu==='')continue;$vip=isset($vsd['remote-address'])?$vsd['remote-address']:'';$vg=isset($vsd['comment'])?$vsd['comment']:''; ?>
              voiceSecretData[<?= json_encode($vu); ?>]={ip:<?= json_encode($vip); ?>,group:<?= json_encode($vg); ?>};
              <?php } ?>
              function applySearchedVoiceVpnUser(value){var d=voiceSecretData[value];document.getElementById('selectVoiceVpnSecret').value=d?value:'';document.getElementById('bypass_client_ip').value=d?d.ip:'';document.getElementById('bypass_group').value=d?d.group:'';}
              function selectScriptVpnUser(value){var d=voiceSecretData[value];document.getElementById('scriptVpnUser').value=d?value:'';document.getElementById('scriptVpnGroup').value=d?d.group:'';}
              function generateVpnClientInline(){
                var user=document.getElementById('scriptVpnUser').value, group=document.getElementById('scriptVpnGroup').value, pass=document.getElementById('scriptVpnPass').value;
                var ros=document.getElementById('scriptVpnRos').value, svc=document.getElementById('scriptVpnService').value, voice=document.getElementById('scriptVpnVoice').checked;
                if(!user){alert('Select a VPN user first.');return;} if(!pass){alert('Enter the PPP Secret password.');return;}
                function q(v){return String(v).replace(/["\\\r\n]/g,'_');}
                var safe=(group+'_'+user).replace(/[^A-Za-z0-9_-]+/g,'_').replace(/^_+|_+$/g,'').substring(0,28)||'vpnuser';
                var name=(safe+(voice?'_voicecall':'')).substring(0,32), u=q(user),g=q(group),p=q(pass),comment=g+' '+u+(voice?' Voice Call':'');
                var c=['# NextGan WiFi Solution | RouterOS '+ros+' | '+svc.toUpperCase()];
                if(svc==='sstp')c.push('/interface sstp-client add name="'+name+'" connect-to="mehedi.xgenwifi.xyz" port=4520 user="'+u+'" password="'+p+'" profile=default-encryption add-default-route=no disabled=no comment="'+comment+'"');
                else if(svc==='l2tp')c.push('/interface l2tp-client add name="'+name+'" connect-to="mehedi.xgenwifi.xyz" user="'+u+'" password="'+p+'" add-default-route=no disabled=no comment="'+comment+'"');
                else if(svc==='pptp')c.push('/interface pptp-client add name="'+name+'" connect-to="mehedi.xgenwifi.xyz" user="'+u+'" password="'+p+'" add-default-route=no disabled=no comment="'+comment+'"');
                else c.push('/interface ovpn-client add name="'+name+'" connect-to="mehedi.xgenwifi.xyz" port=1194 mode=ip user="'+u+'" password="'+p+'" add-default-route=no disabled=no comment="'+comment+'"');
                if(voice){var ports='3478,3480,3481,34784,45395,50318,59234,5222,4244,5223,5228,5242,27526,48320';if(ros==='7'){c.push('/routing/table/add name=voicecall fib');c.push('/ip/route/add dst-address=0.0.0.0/0 gateway="'+name+'" routing-table=voicecall comment="'+comment+' Route"');}else{c.push('/ip/route/add dst-address=0.0.0.0/0 gateway="'+name+'" routing-mark=voicecall comment="'+comment+' Route"');}c.push('/ip/firewall/mangle/add chain=prerouting action=mark-routing new-routing-mark=voicecall passthrough=no protocol=tcp dst-port='+ports+' comment="'+comment+' TCP"');c.push('/ip/firewall/mangle/add chain=prerouting action=mark-routing new-routing-mark=voicecall passthrough=no protocol=udp dst-port='+ports+' comment="'+comment+' UDP"');}
                document.getElementById('scriptVpnOutput').value=c.join('\n');
              }
              function copyVpnInlineScript(){var e=document.getElementById('scriptVpnOutput');if(!e.value){alert('Generate script first.');return;}e.focus();e.select();try{document.execCommand('copy');}catch(err){}}
              </script>
            <?php } ?>

            <!-- TAB 4: ACTIVE VPN CONNECTIONS -->
            <?php if ($tab === 'active') { ?>
              <div class="card">
                <div class="card-header">
                  <h4 style="margin:0;"><i class="fa fa-wifi text-green"></i> Active VPN Connections (<?= count($routerPppActive); ?>)</h4>
                </div>
                <div class="card-body">
                  <div class="overflow box-bordered">
                    <table class="table table-bordered table-hover text-nowrap">
                      <thead>
                        <tr>
                          <th>Action</th>
                          <th>Username</th>
                          <th>Service</th>
                          <th>Caller ID</th>
                          <th>Assigned IP</th>
                          <th>Uptime</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($routerPppActive)) { ?>
                          <tr><td colspan="6" class="text-center text-muted">No active VPN clients currently connected.</td></tr>
                        <?php } else {
                          foreach ($routerPppActive as $pa) {
                            $aId = isset($pa['.id']) ? $pa['.id'] : '';
                            $aName = isset($pa['name']) ? $pa['name'] : '-';
                            $aServ = isset($pa['service']) ? $pa['service'] : '-';
                            $aCaller = isset($pa['caller-id']) ? $pa['caller-id'] : '-';
                            $aAddr = isset($pa['address']) ? $pa['address'] : '-';
                            $aUp = isset($pa['uptime']) ? formatDTM($pa['uptime']) : '-';
                        ?>
                          <tr>
                            <td>
                              <form method="post" action="" style="display:inline;">
                                <input type="hidden" name="active_vpn_id" value="<?= htmlspecialchars($activeVpnId); ?>">
                                <input type="hidden" name="active_tab" value="active">
                                <input type="hidden" name="active_vpn_id" value="<?= htmlspecialchars($aId); ?>">
                                <button type="submit" name="disconnect_active_vpn" class="btn bg-danger" style="padding:2px 8px; font-size:12px;" onclick="return confirm('Disconnect active VPN user <?= htmlspecialchars($aName, ENT_QUOTES); ?>?');">
                                  <i class="fa fa-power-off"></i> Disconnect
                                </button>
                              </form>
                            </td>
                            <td><b><?= htmlspecialchars($aName); ?></b></td>
                            <td><?= htmlspecialchars($aServ); ?></td>
                            <td><?= htmlspecialchars($aCaller); ?></td>
                            <td><code><?= htmlspecialchars($aAddr); ?></code></td>
                            <td><?= htmlspecialchars($aUp); ?></td>
                          </tr>
                        <?php } } ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            <?php } ?>

          <?php } ?>
        <?php } ?>
      </div>
    </div>
  </div>
</div>

<script>
function openVpnRouterEdit(router) {
  var f=document.getElementById('vpnRouterForm');
  f.querySelector('[name="vpn_router_id"]').value=router.id||'';
  f.querySelector('[name="vpn_name"]').value=router.name||'';
  f.querySelector('[name="vpn_host"]').value=router.host||'';
  f.querySelector('[name="vpn_port"]').value=router.port||8728;
  f.querySelector('[name="vpn_ros_ver"]').value=String(router.ros_ver||7);
  f.querySelector('[name="vpn_user"]').value=router.user||'';
  f.querySelector('[name="vpn_pass"]').value='';
  f.querySelector('[name="vpn_pass"]').placeholder='Leave blank to keep current password';
  document.getElementById('vpnRouterModalTitle').textContent='Edit VPN Router';
  document.getElementById('vpnRouterSaveButton').name='save_vpn_router';
  document.getElementById('vpnRouterSaveButton').innerHTML='<i class="fa fa-save"></i> Update VPN Router';
  document.getElementById('modalAddVpnRouter').style.display='block';
}
document.addEventListener('DOMContentLoaded',function(){
  var addButtons=document.querySelectorAll('[onclick*="modalAddVpnRouter"].btn');
  addButtons.forEach(function(btn){btn.addEventListener('click',function(){
    if(this.textContent.toLowerCase().indexOf('edit')!==-1)return;
    var f=document.getElementById('vpnRouterForm');
    if(!f)return;
    f.reset();f.querySelector('[name="vpn_router_id"]').value='';
    document.getElementById('vpnRouterModalTitle').textContent='Add VPN Server Router';
    document.getElementById('vpnRouterSaveButton').name='save_vpn_router';
    document.getElementById('vpnRouterSaveButton').innerHTML='<i class="fa fa-save"></i> Save VPN Router';
    f.querySelector('[name="vpn_pass"]').placeholder='Router password';
  });});
});
</script>
<!-- MODAL: ADD VPN ROUTER -->
<div id="modalAddVpnRouter" class="ngw-modal-backdrop" onclick="if(event.target===this)this.style.display='none';" style="display:none;">
  <div class="card ngw-modal-box">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0;"><i class="fa fa-plus"></i> <span id="vpnRouterModalTitle">Add VPN Server Router</span></h3>
      <span class="pointer" onclick="document.getElementById('modalAddVpnRouter').style.display='none';"><i class="fa fa-close text-red"></i></span>
    </div>
    <div class="card-body">
      <form method="post" action="<?= $baseVpnUrl; ?>" autocomplete="off" id="vpnRouterForm">
        <input type="hidden" name="vpn_router_id" id="vpn_router_id" value="">
        <div class="form-group mr-b-10">
          <label style="font-weight:bold; font-size:12px;">Router Name / Session:</label>
          <input type="text" name="vpn_name" class="form-control" required placeholder="e.g. Central-VPN-Server">
        </div>

        <div class="form-group mr-b-10">
          <label style="font-weight:bold; font-size:12px;">IP Address / Domain:</label>
          <input type="text" name="vpn_host" class="form-control" required placeholder="e.g. 130.94.119.86">
        </div>

        <div class="row">
          <div class="col-6">
            <div class="form-group mr-b-10">
              <label style="font-weight:bold; font-size:12px;">API Port:</label>
              <input type="number" name="vpn_port" class="form-control" value="8728" required>
            </div>
          </div>
          <div class="col-6">
            <div class="form-group mr-b-10">
              <label style="font-weight:bold; font-size:12px;">RouterOS Version:</label>
              <select name="vpn_ros_ver" class="form-control">
                <option value="7" selected>RouterOS v7</option>
                <option value="6">RouterOS v6</option>
              </select>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-6">
            <div class="form-group mr-b-10">
              <label style="font-weight:bold; font-size:12px;">Username:</label>
              <input type="text" name="vpn_user" class="form-control" required placeholder="admin">
            </div>
          </div>
          <div class="col-6">
            <div class="form-group mr-b-10">
              <label style="font-weight:bold; font-size:12px;">Password:</label>
              <input type="password" name="vpn_pass" class="form-control" placeholder="Router password">
            </div>
          </div>
        </div>

        <button type="submit" name="save_vpn_router" id="vpnRouterSaveButton" class="btn bg-primary btn-block" style="margin-top:10px;">
          <i class="fa fa-save"></i> Save VPN Router
        </button>
      </form>
    </div>
  </div>
</div>
