<?php
/*
 *  NextGan WiFi Solution — Built-in Virtual MikroTik Router Simulator
 *  Allows full testing of Dashboard, Hotspot Vouchers, PPPoE Secrets, Profiles,
 *  Active Sessions, Traffic Monitor & Reset Tools without a physical router.
 */

class VirtualRouterOS {
    protected $sessionId;
    protected $dataFile;
    protected $data;

    public function __construct($sessionId = 'Local') {
        $this->sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        if ($this->sessionId === '') $this->sessionId = 'Local';
        $dataDir = __DIR__ . '/../data';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0777, true);
        }
        $this->dataFile = $dataDir . '/virtual_router_' . $this->sessionId . '.json';
        $this->loadData();
    }

    protected function getInitialState() {
        return array(
            'identity' => 'NextGan-Virtual-Lab',
            'resource' => array(
                'uptime' => '5d 14:22:10',
                'version' => '7.15.2 (stable)',
                'free-memory' => 198451200,
                'total-memory' => 268435456,
                'cpu' => 'MIPS 1004Kc V2.15',
                'cpu-count' => 4,
                'cpu-frequency' => 880,
                'cpu-load' => 8,
                'free-hdd-space' => 92147712,
                'total-hdd-space' => 134217728,
                'write-sect-since-reboot' => 12450,
                'write-sect-total' => 89450,
                'bad-blocks' => 0,
                'architecture-name' => 'mmips',
                'board-name' => 'RB750Gr3 (Virtual Test Router)',
                'platform' => 'MikroTik'
            ),
            'routerboard' => array(
                'routerboard' => 'true',
                'model' => 'RB750Gr3',
                'serial-number' => 'NGW-TEST-LAB-01',
                'current-firmware' => '7.15.2',
                'upgrade-firmware' => '7.15.2'
            ),
            'interfaces' => array(
                array('.id' => '*1', 'name' => 'ether1-WAN', 'type' => 'ether', 'running' => 'true', 'disabled' => 'false'),
                array('.id' => '*2', 'name' => 'ether2-LAN', 'type' => 'ether', 'running' => 'true', 'disabled' => 'false'),
                array('.id' => '*3', 'name' => 'bridge1', 'type' => 'bridge', 'running' => 'true', 'disabled' => 'false'),
                array('.id' => '*4', 'name' => 'wlan1', 'type' => 'wlan', 'running' => 'true', 'disabled' => 'false')
            ),
            'pools' => array(
                array('.id' => '*1', 'name' => 'hs-pool-1', 'ranges' => '192.168.10.10-192.168.10.254'),
                array('.id' => '*2', 'name' => 'pppoe-pool-1', 'ranges' => '10.10.20.2-10.10.20.254')
            ),
            'hotspot_servers' => array(
                array('.id' => '*1', 'name' => 'all', 'interface' => 'bridge1', 'address-pool' => 'hs-pool-1', 'profile' => 'hsprof1', 'disabled' => 'false'),
                array('.id' => '*2', 'name' => 'hs-main', 'interface' => 'bridge1', 'address-pool' => 'hs-pool-1', 'profile' => 'hsprof1', 'disabled' => 'false')
            ),
            'hotspot_profiles' => array(
                array('.id' => '*1', 'name' => 'default', 'shared-users' => '1', 'rate-limit' => '', 'status-autorefresh' => '1m'),
                array('.id' => '*2', 'name' => '1-Hour-10Tk', 'shared-users' => '1', 'rate-limit' => '5M/5M', 'on-login' => ':put (",rem,10,1h,10,10,Disable")', 'status-autorefresh' => '1m'),
                array('.id' => '*3', 'name' => '1-Day-30Tk', 'shared-users' => '1', 'rate-limit' => '10M/10M', 'on-login' => ':put (",rem,30,1d,30,30,Disable")', 'status-autorefresh' => '1m'),
                array('.id' => '*4', 'name' => 'Monthly-300Tk', 'shared-users' => '1', 'rate-limit' => '15M/15M', 'on-login' => ':put (",rem,300,30d,300,300,Disable")', 'status-autorefresh' => '1m')
            ),
            'hotspot_users' => array(
                array('.id' => '*1', 'server' => 'all', 'name' => 'test01', 'password' => '1234', 'profile' => '1-Day-30Tk', 'mac-address' => '48:8F:5A:11:22:33', 'uptime' => '01:23:45', 'bytes-in' => '14589200', 'bytes-out' => '85412000', 'disabled' => 'false', 'comment' => 'vc-demo-voucher'),
                array('.id' => '*2', 'server' => 'all', 'name' => 'demo_guest', 'password' => 'demo_guest', 'profile' => '1-Hour-10Tk', 'mac-address' => 'A4:C3:F0:88:99:AA', 'uptime' => '00:15:30', 'bytes-in' => '5120000', 'bytes-out' => '18400000', 'disabled' => 'false', 'comment' => 'up-demo-guest'),
                array('.id' => '*3', 'server' => 'all', 'name' => 'expired_user', 'password' => 'pass123', 'profile' => '1-Day-30Tk', 'mac-address' => 'B2:11:22:33:44:55', 'uptime' => '1d00:00:00', 'bytes-in' => '120000000', 'bytes-out' => '550000000', 'disabled' => 'true', 'comment' => 'exp:nov/08/2026 12:00:00')
            ),
            'hotspot_active' => array(
                array('.id' => '*1', 'server' => 'hs-main', 'user' => 'test01', 'address' => '192.168.10.45', 'mac-address' => '48:8F:5A:11:22:33', 'uptime' => '01:23:45', 'bytes-in' => '14589200', 'bytes-out' => '85412000', 'login-by' => 'http-chap')
            ),
            'hotspot_hosts' => array(
                array('.id' => '*1', 'mac-address' => '48:8F:5A:11:22:33', 'address' => '192.168.10.45', 'to-address' => '192.168.10.45', 'server' => 'hs-main', 'authorized' => 'true', 'bypassed' => 'false'),
                array('.id' => '*2', 'mac-address' => 'A4:C3:F0:88:99:AA', 'address' => '192.168.10.78', 'to-address' => '192.168.10.78', 'server' => 'hs-main', 'authorized' => 'false', 'bypassed' => 'false')
            ),
            'hotspot_bindings' => array(
                array('.id' => '*1', 'mac-address' => 'C8:D7:19:AA:BB:CC', 'address' => '192.168.10.20', 'to-address' => '192.168.10.20', 'type' => 'bypassed', 'disabled' => 'false', 'comment' => 'Office Printer')
            ),
            'pppoe_profiles' => array(
                array('.id' => '*1', 'name' => 'default', 'local-address' => '10.10.20.1', 'remote-address' => 'pppoe-pool-1', 'rate-limit' => '10M/10M', 'only-one' => 'yes', 'dns-server' => '8.8.8.8,1.1.1.1'),
                array('.id' => '*2', 'name' => '5Mbps-Monthly', 'local-address' => '10.10.20.1', 'remote-address' => 'pppoe-pool-1', 'rate-limit' => '5M/5M', 'only-one' => 'yes', 'dns-server' => '8.8.8.8,1.1.1.1'),
                array('.id' => '*3', 'name' => '10Mbps-Monthly', 'local-address' => '10.10.20.1', 'remote-address' => 'pppoe-pool-1', 'rate-limit' => '10M/10M', 'only-one' => 'yes', 'dns-server' => '8.8.8.8,1.1.1.1'),
                array('.id' => '*4', 'name' => '20Mbps-Gaming', 'local-address' => '10.10.20.1', 'remote-address' => 'pppoe-pool-1', 'rate-limit' => '20M/20M', 'only-one' => 'yes', 'dns-server' => '8.8.8.8,1.1.1.1')
            ),
            'pppoe_secrets' => array(
                array('.id' => '*1', 'name' => 'ppp_test01', 'password' => 'ppp123', 'service' => 'pppoe', 'profile' => '10Mbps-Monthly', 'local-address' => '', 'remote-address' => '10.10.20.15', 'caller-id' => '00:1A:2B:3C:4D:5E', 'disabled' => 'false', 'comment' => 'exp:2026-11-30 23:59:59|val:1m|by:admin|VIP Customer'),
                array('.id' => '*2', 'name' => 'ppp_demo02', 'password' => 'pass456', 'service' => 'pppoe', 'profile' => '5Mbps-Monthly', 'local-address' => '', 'remote-address' => '', 'caller-id' => '', 'disabled' => 'false', 'comment' => 'exp:2026-11-15 18:00:00|val:5d|by:admin|Test Home Fiber'),
                array('.id' => '*3', 'name' => 'ppp_expired', 'password' => 'oldpass', 'service' => 'pppoe', 'profile' => '5Mbps-Monthly', 'local-address' => '', 'remote-address' => '', 'caller-id' => '', 'disabled' => 'true', 'comment' => 'exp:2026-11-05 10:00:00|val:1d|by:admin|Overdue')
            ),
            'pppoe_active' => array(
                array('.id' => '*1', 'name' => 'ppp_test01', 'service' => 'pppoe', 'caller-id' => '00:1A:2B:3C:4D:5E', 'address' => '10.10.20.15', 'uptime' => '2d04:12:45', 'encoding' => '', 'session-id' => '0x81000001')
            ),
            'simple_queues' => array(
                array('.id' => '*1', 'name' => 'Total-Bandwidth', 'target' => '192.168.10.0/24', 'max-limit' => '50M/50M', 'dynamic' => 'false')
            ),
            'schedulers' => array(
                array('.id' => '*1', 'name' => 'NGW-PPPoE-Monitor', 'start-time' => '00:00:00', 'interval' => '00:02:00', 'on-event' => ':log info "Virtual Monitor OK"', 'disabled' => 'false')
            ),
            'scripts' => array(
                array('.id' => '*1', 'name' => 'NGW-PPPoE-Monitor', 'source' => '# Virtual Monitor Script', 'run-count' => 54)
            ),
            'logs' => array(
                array('.id' => '*1', 'time' => date('H:i:s', time() - 3600), 'topics' => 'system,info', 'message' => 'Virtual RouterOS initialized in demo simulation mode'),
                array('.id' => '*2', 'time' => date('H:i:s', time() - 1800), 'topics' => 'hotspot,info', 'message' => 'test01 (192.168.10.45): logged in'),
                array('.id' => '*3', 'time' => date('H:i:s', time() - 600), 'topics' => 'pppoe,info', 'message' => '<pppoe-ppp_test01>: authenticated, assigned IP 10.10.20.15'),
                array('.id' => '*4', 'time' => date('H:i:s'), 'topics' => 'system,info', 'message' => 'device status: healthy, zero packet loss')
            )
        );
    }

    protected function loadData() {
        if (file_exists($this->dataFile)) {
            $json = @file_get_contents($this->dataFile);
            $parsed = @json_decode($json, true);
            if (is_array($parsed) && isset($parsed['resource'])) {
                $this->data = $parsed;
                return;
            }
        }
        $this->data = $this->getInitialState();
        $this->saveData();
    }

    protected function saveData() {
        @file_put_contents($this->dataFile, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function execute($command, $params = array()) {
        $cmd = trim((string)$command);
        $normCmd = strtolower(str_replace(' ', '', $cmd));

        // 1. System Resources
        if ($normCmd === '/system/resource/print') {
            $res = $this->data['resource'];
            // Randomize cpu load slightly for realism
            $res['cpu-load'] = rand(5, 18);
            return array($res);
        }

        // 2. System Routerboard
        if ($normCmd === '/system/routerboard/print') {
            return array($this->data['routerboard']);
        }

        // 3. System Identity
        if ($normCmd === '/system/identity/print') {
            return array(array('name' => $this->data['identity']));
        }

        // 4. System Clock
        if ($normCmd === '/system/clock/print') {
            return array(array(
                'time' => date('H:i:s'),
                'date' => strtolower(date('M/d/Y')),
                'time-zone-name' => 'Asia/Dhaka',
                'gmt-offset' => '+06:00'
            ));
        }

        // 5. Traffic Monitor
        if (strpos($normCmd, '/interface/monitor-traffic') !== false) {
            $rx = rand(1500000, 8500000); // 1.5 - 8.5 Mbps
            $tx = rand(3000000, 18000000); // 3 - 18 Mbps
            return array(array(
                'name' => isset($params['interface']) ? $params['interface'] : 'ether1-WAN',
                'rx-bits-per-second' => (string)$rx,
                'tx-bits-per-second' => (string)$tx,
                'rx-packets-per-second' => (string)rand(300, 1200),
                'tx-packets-per-second' => (string)rand(500, 2400)
            ));
        }

        // 6. Interfaces
        if ($normCmd === '/interface/print') {
            return $this->data['interfaces'];
        }

        // 7. IP Pools
        if ($normCmd === '/ip/pool/print') {
            return $this->data['pools'];
        }

        // 8. Hotspot Server / Profiles / Users / Active / Hosts / Bindings
        if ($normCmd === '/ip/hotspot/print') {
            return $this->data['hotspot_servers'];
        }

        if ($normCmd === '/ip/hotspot/user/profile/print') {
            return $this->filterArray($this->data['hotspot_profiles'], $params);
        }

        if ($normCmd === '/ip/hotspot/user/profile/add') {
            $new = $params;
            $new['.id'] = '*' . strtoupper(dechex(rand(100, 9999)));
            $this->data['hotspot_profiles'][] = $new;
            $this->saveData();
            return array(array('ret' => $new['.id']));
        }

        if ($normCmd === '/ip/hotspot/user/profile/set') {
            $id = isset($params['.id']) ? $params['.id'] : '';
            foreach ($this->data['hotspot_profiles'] as &$pr) {
                if ($pr['.id'] === $id || (isset($params['numbers']) && $pr['.id'] === $params['numbers'])) {
                    foreach ($params as $k => $v) {
                        if ($k !== '.id' && $k !== 'numbers') $pr[$k] = $v;
                    }
                }
            }
            $this->saveData();
            return array();
        }

        if ($normCmd === '/ip/hotspot/user/profile/remove') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            $this->data['hotspot_profiles'] = array_values(array_filter($this->data['hotspot_profiles'], function($item) use ($id) {
                return $item['.id'] !== $id;
            }));
            $this->saveData();
            return array();
        }

        if ($normCmd === '/ip/hotspot/user/print') {
            return $this->filterArray($this->data['hotspot_users'], $params);
        }

        if ($normCmd === '/ip/hotspot/user/add') {
            $new = $params;
            $new['.id'] = '*' . strtoupper(dechex(rand(100, 9999)));
            $new['uptime'] = '00:00:00';
            $new['bytes-in'] = '0';
            $new['bytes-out'] = '0';
            $new['disabled'] = isset($params['disabled']) ? $params['disabled'] : 'false';
            $this->data['hotspot_users'][] = $new;
            $this->saveData();
            return array(array('ret' => $new['.id']));
        }

        if ($normCmd === '/ip/hotspot/user/set') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            foreach ($this->data['hotspot_users'] as &$u) {
                if ($u['.id'] === $id || (isset($params['name']) && isset($u['name']) && $u['name'] === $params['name'])) {
                    foreach ($params as $k => $v) {
                        if ($k !== '.id' && $k !== 'numbers') $u[$k] = $v;
                    }
                }
            }
            $this->saveData();
            return array();
        }

        if ($normCmd === '/ip/hotspot/user/remove') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            $this->data['hotspot_users'] = array_values(array_filter($this->data['hotspot_users'], function($item) use ($id) {
                return $item['.id'] !== $id;
            }));
            $this->saveData();
            return array();
        }

        if ($normCmd === '/ip/hotspot/user/reset-counters') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            foreach ($this->data['hotspot_users'] as &$u) {
                if ($u['.id'] === $id) {
                    $u['uptime'] = '00:00:00';
                    $u['bytes-in'] = '0';
                    $u['bytes-out'] = '0';
                }
            }
            $this->saveData();
            return array();
        }

        if ($normCmd === '/ip/hotspot/active/print') {
            return $this->filterArray($this->data['hotspot_active'], $params);
        }

        if ($normCmd === '/ip/hotspot/active/remove') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            $this->data['hotspot_active'] = array_values(array_filter($this->data['hotspot_active'], function($item) use ($id) {
                return $item['.id'] !== $id;
            }));
            $this->saveData();
            return array();
        }

        if ($normCmd === '/ip/hotspot/host/print') {
            return $this->filterArray($this->data['hotspot_hosts'], $params);
        }

        if ($normCmd === '/ip/hotspot/ip-binding/print') {
            return $this->filterArray(isset($this->data['hotspot_bindings']) ? $this->data['hotspot_bindings'] : array(), $params);
        }

        if ($normCmd === '/ip/hotspot/ip-binding/add') {
            $new = $params;
            $new['.id'] = '*' . strtoupper(dechex(rand(100, 9999)));
            $new['disabled'] = isset($params['disabled']) ? $params['disabled'] : 'false';
            if (!isset($this->data['hotspot_bindings']) || !is_array($this->data['hotspot_bindings'])) {
                $this->data['hotspot_bindings'] = array();
            }
            $this->data['hotspot_bindings'][] = $new;
            $this->saveData();
            return array(array('ret' => $new['.id']));
        }

        if ($normCmd === '/ip/hotspot/ip-binding/set') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            if (isset($this->data['hotspot_bindings']) && is_array($this->data['hotspot_bindings'])) {
                foreach ($this->data['hotspot_bindings'] as &$b) {
                    if ($b['.id'] === $id) {
                        foreach ($params as $k => $v) {
                            if ($k !== '.id' && $k !== 'numbers') $b[$k] = $v;
                        }
                    }
                }
                $this->saveData();
            }
            return array();
        }

        if ($normCmd === '/ip/hotspot/ip-binding/remove') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            if (isset($this->data['hotspot_bindings']) && is_array($this->data['hotspot_bindings'])) {
                $this->data['hotspot_bindings'] = array_values(array_filter($this->data['hotspot_bindings'], function($item) use ($id) {
                    return $item['.id'] !== $id;
                }));
                $this->saveData();
            }
            return array();
        }

        if ($normCmd === '/ip/hotspot/cookie/print') {
            $cookies = isset($this->data['hotspot_cookies']) && is_array($this->data['hotspot_cookies']) ? $this->data['hotspot_cookies'] : array(
                array('.id' => '*1', 'user' => 'test01', 'mac-address' => '48:8F:5A:11:22:33', 'expires-in' => '22:36:15')
            );
            return $this->filterArray($cookies, $params);
        }

        // 9. PPPoE Profiles / Secrets / Active
        if ($normCmd === '/ppp/profile/print') {
            return $this->filterArray($this->data['pppoe_profiles'], $params);
        }

        if ($normCmd === '/ppp/profile/add') {
            $new = $params;
            $new['.id'] = '*' . strtoupper(dechex(rand(100, 9999)));
            $this->data['pppoe_profiles'][] = $new;
            $this->saveData();
            return array(array('ret' => $new['.id']));
        }

        if ($normCmd === '/ppp/profile/set') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            foreach ($this->data['pppoe_profiles'] as &$pr) {
                if ($pr['.id'] === $id) {
                    foreach ($params as $k => $v) {
                        if ($k !== '.id' && $k !== 'numbers') $pr[$k] = $v;
                    }
                }
            }
            $this->saveData();
            return array();
        }

        if ($normCmd === '/ppp/profile/remove') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            $this->data['pppoe_profiles'] = array_values(array_filter($this->data['pppoe_profiles'], function($item) use ($id) {
                return $item['.id'] !== $id;
            }));
            $this->saveData();
            return array();
        }

        if ($normCmd === '/ppp/secret/print') {
            return $this->filterArray($this->data['pppoe_secrets'], $params);
        }

        if ($normCmd === '/ppp/secret/add') {
            $new = $params;
            $new['.id'] = '*' . strtoupper(dechex(rand(100, 9999)));
            $new['disabled'] = isset($params['disabled']) ? $params['disabled'] : 'false';
            $this->data['pppoe_secrets'][] = $new;
            $this->saveData();
            return array(array('ret' => $new['.id']));
        }

        if ($normCmd === '/ppp/secret/set') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            foreach ($this->data['pppoe_secrets'] as &$sec) {
                if ($sec['.id'] === $id || (isset($params['name']) && isset($sec['name']) && $sec['name'] === $params['name'])) {
                    foreach ($params as $k => $v) {
                        if ($k !== '.id' && $k !== 'numbers') $sec[$k] = $v;
                    }
                }
            }
            $this->saveData();
            return array();
        }

        if ($normCmd === '/ppp/secret/remove') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            $this->data['pppoe_secrets'] = array_values(array_filter($this->data['pppoe_secrets'], function($item) use ($id) {
                return $item['.id'] !== $id;
            }));
            $this->saveData();
            return array();
        }

        if ($normCmd === '/ppp/active/print') {
            return $this->filterArray($this->data['pppoe_active'], $params);
        }

        if ($normCmd === '/ppp/active/remove') {
            $id = isset($params['.id']) ? $params['.id'] : (isset($params['numbers']) ? $params['numbers'] : '');
            $this->data['pppoe_active'] = array_values(array_filter($this->data['pppoe_active'], function($item) use ($id) {
                return $item['.id'] !== $id;
            }));
            $this->saveData();
            return array();
        }

        // 10. Queue / Simple
        if ($normCmd === '/queue/simple/print') {
            return $this->filterArray($this->data['simple_queues'], $params);
        }

        // 11. Schedulers & Scripts
        if ($normCmd === '/system/scheduler/print') {
            return $this->filterArray($this->data['schedulers'], $params);
        }

        if ($normCmd === '/system/scheduler/add') {
            $new = $params;
            $new['.id'] = '*' . strtoupper(dechex(rand(100, 9999)));
            $this->data['schedulers'][] = $new;
            $this->saveData();
            return array(array('ret' => $new['.id']));
        }

        if ($normCmd === '/system/script/print') {
            return $this->filterArray($this->data['scripts'], $params);
        }

        if ($normCmd === '/system/script/add') {
            $new = $params;
            $new['.id'] = '*' . strtoupper(dechex(rand(100, 9999)));
            $this->data['scripts'][] = $new;
            $this->saveData();
            return array(array('ret' => $new['.id']));
        }

        // 12. Logs
        if ($normCmd === '/log/print') {
            return array_reverse($this->data['logs']);
        }

        // Default empty array for any unmatched commands
        return array();
    }

    protected function filterArray($items, $params) {
        if (!is_array($items) || empty($items)) return array();
        if (empty($params)) return $items;

        $filtered = array();
        foreach ($items as $item) {
            $match = true;
            foreach ($params as $k => $v) {
                if ($k[0] === '?') {
                    $prop = substr($k, 1);
                    if (!isset($item[$prop]) || (string)$item[$prop] !== (string)$v) {
                        $match = false;
                        break;
                    }
                } elseif ($k[0] === '~') {
                    $prop = substr($k, 1);
                    if (!isset($item[$prop]) || stripos((string)$item[$prop], (string)$v) === false) {
                        $match = false;
                        break;
                    }
                }
            }
            if ($match) {
                $filtered[] = $item;
            }
        }
        return $filtered;
    }
}
