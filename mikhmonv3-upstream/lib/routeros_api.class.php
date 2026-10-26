<?php
/*****************************
 *
 * RouterOS PHP API class v1.6
 * Author: Denis Basta
 * Contributors:
 *    Nick Barnes
 *    Ben Menking (ben [at] infotechsc [dot] com)
 *    Jeremy Jefferson (http://jeremyj.com)
 *    Cristian Deluxe (djcristiandeluxe [at] gmail [dot] com)
 *    Mikhail Moskalev (mmv.rus [at] gmail [dot] com)
 *
 * http://www.mikrotik.com
 * http://wiki.mikrotik.com/wiki/API_PHP_class
 *
 ******************************/

class RouterosAPI
{
    var $debug     = false; //  Show debug information
    var $connected = false; //  Connection state
    var $port      = 8728;  //  Port to connect to (default 8729 for ssl)
    var $ssl       = false; //  Connect using SSL (must enable api-ssl in IP/Services)
    var $ssh       = false; //  Connect using SSH (port 22 by default)
    var $use_ssh   = false; //  Active transport is SSH
    var $ssh_bridge_local = false; // Local preview simulator bridge flag
    var $ssh_conn  = null;  //  PHP ssh2 resource if ssh2 extension is used
    var $ssh_host  = '';
    var $ssh_port  = 22;
    var $ssh_user  = '';
    var $ssh_pass  = '';
    var $timeout   = 3;     //  Connection attempt timeout and data read timeout (fail fast on offline routers)
    var $attempts  = 1;     //  Connection attempt count (1 attempt prevents worker pool starvation)
    var $delay     = 0;     //  Delay between connection attempts in seconds

    var $socket;            //  Variable for storing socket resource
    var $error_no;          //  Variable for storing connection error number, if any
    var $error_str;         //  Variable for storing connection error text, if any

    /* Check, can be var used in foreach  */
    public function isIterable($var)
    {
        return $var !== null
                && (is_array($var)
                || $var instanceof Traversable
                || $var instanceof Iterator
                || $var instanceof IteratorAggregate
                );
    }

    /**
     * Print text for debug purposes
     *
     * @param string      $text       Text to print
     *
     * @return void
     */
    public function debug($text)
    {
        if ($this->debug) {
            echo $text . "\n";
        }
    }


    /**
     *
     *
     * @param string        $length
     *
     * @return void
     */
    public function encodeLength($length)
    {
        if ($length < 0x80) {
            $length = chr($length);
        } elseif ($length < 0x4000) {
            $length |= 0x8000;
            $length = chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x200000) {
            $length |= 0xC00000;
            $length = chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x10000000) {
            $length |= 0xE0000000;
            $length = chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length >= 0x10000000) {
            $length = chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }

        return $length;
    }


    /**
     * Login to RouterOS (Supports both Binary API and SSH CLI mode)
     *
     * @param string      $ip         Hostname (IP or domain) of the RouterOS server (prefix ssh:// for SSH mode)
     * @param string      $login      The RouterOS username
     * @param string      $password   The RouterOS password
     *
     * @return boolean                If we are connected or not
     */
    public function connect($ip, $login, $password)
    {
        $rawIp = trim((string)$ip);
        $isSshMode = $this->ssh || (strpos($rawIp, 'ssh://') === 0);
        if (strpos($rawIp, 'ssh://') === 0) {
            $rawIp = substr($rawIp, 6);
        }

        if ($isSshMode) {
            $this->use_ssh = true;
            $this->ssh_bridge_local = false;
            $hostParts = explode(':', $rawIp);
            $this->ssh_host = $hostParts[0];
            $this->ssh_port = (isset($hostParts[1]) && $hostParts[1] !== '') ? (int)$hostParts[1] : 22;
            $this->ssh_user = $login;
            $this->ssh_pass = $password;

            // Check if connecting to local simulated RouterOS in AI Studio preview where port 22 is not bound
            if (($this->ssh_host === '127.0.0.1' || $this->ssh_host === '127.0.0.2' || $this->ssh_host === 'localhost') && $this->ssh_port === 22) {
                $testSshSock = fsockopen($this->ssh_host, 22, $eNo, $eStr, 1);
                if ($testSshSock) {
                    fclose($testSshSock);
                } else {
                    $this->ssh_bridge_local = true;
                    $rawIp = $this->ssh_host . ':8728';
                }
            }

            if (!$this->ssh_bridge_local) {
                return $this->connectSshTransport();
            }
        } else {
            $this->use_ssh = false;
            $this->ssh_bridge_local = false;
        }

        // Parse optional custom port in $rawIp for Binary API
        $apiHost = $rawIp;
        $apiPort = $this->port;
        if (strpos($rawIp, ':') !== false) {
            $hp = explode(':', $rawIp, 2);
            $apiHost = $hp[0];
            if ($hp[1] !== '' && is_numeric($hp[1])) {
                $apiPort = (int)$hp[1];
            }
        }

        for ($ATTEMPT = 1; $ATTEMPT <= $this->attempts; $ATTEMPT++) {
            $this->connected = false;
            $PROTOCOL = ($this->ssl ? 'ssl://' : '' );
            $context = stream_context_create(array('ssl' => array('ciphers' => 'ADH:ALL', 'verify_peer' => false, 'verify_peer_name' => false)));
            $this->debug('Connection attempt #' . $ATTEMPT . ' to ' . $PROTOCOL . $apiHost . ':' . $apiPort . '...');
            $this->socket = @stream_socket_client($PROTOCOL . $apiHost . ':' . $apiPort, $this->error_no, $this->error_str, $this->timeout, STREAM_CLIENT_CONNECT, $context);
            if ($this->socket) {
                socket_set_timeout($this->socket, $this->timeout);
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=password=' . $password);
                $RESPONSE = $this->read(false);
                if (isset($RESPONSE[0])) {
                    if ($RESPONSE[0] == '!done') {
                        if (!isset($RESPONSE[1])) {
                            // Login method post-v6.43
                            $this->connected = true;
                            break;
                        } else {
                            // Login method pre-v6.43
                            $MATCHES = array();
                            if (preg_match_all('/[^=]+/i', $RESPONSE[1], $MATCHES)) {
                                if ($MATCHES[0][0] == 'ret' && strlen($MATCHES[0][1]) == 32) {
                                    $this->write('/login', false);
                                    $this->write('=name=' . $login, false);
                                    $this->write('=response=00' . md5(chr(0) . $password . pack('H*', $MATCHES[0][1])));
                                    $RESPONSE = $this->read(false);
                                    if (isset($RESPONSE[0]) && $RESPONSE[0] == '!done') {
                                        $this->connected = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                fclose($this->socket);
            }
            sleep($this->delay);
        }

        if ($this->connected) {
            $this->debug('Connected...');
        } else {
            $this->debug('Error...');
        }
        return $this->connected;
    }

    /**
     * Establish and verify SSH connection to MikroTik RouterOS using only SSH Username & Password
     */
    protected function connectSshTransport()
    {
        $this->connected = false;
        // 1. Check TCP reachability of SSH port first
        $sock = @fsockopen($this->ssh_host, $this->ssh_port, $this->error_no, $this->error_str, $this->timeout);
        if (!$sock) {
            return false;
        }
        fclose($sock);

        // 2. Prefer PHP ssh2 extension if installed
        if (function_exists('ssh2_connect')) {
            $conn = @ssh2_connect($this->ssh_host, $this->ssh_port);
            if ($conn && @ssh2_auth_password($conn, $this->ssh_user, $this->ssh_pass)) {
                $this->ssh_conn = $conn;
                $this->connected = true;
                return true;
            }
        }

        // 3. Fallback to OpenSSH CLI (sshpass or SSH_ASKPASS)
        $exitCode = 1;
        $out = $this->sshExecRaw('/system identity print without-paging', $exitCode);
        if ($exitCode === 0 && $out !== false && (stripos($out, 'name:') !== false || trim($out) !== '')) {
            $this->connected = true;
            return true;
        }

        return false;
    }

    /**
     * Execute a single RouterOS CLI command over SSH and return stdout string
     */
    protected function sshExecRaw($cliCommand, &$exitCode = 0)
    {
        $exitCode = 1;
        if ($this->ssh_conn && function_exists('ssh2_exec')) {
            $stream = @ssh2_exec($this->ssh_conn, $cliCommand);
            if ($stream) {
                stream_set_blocking($stream, true);
                $output = stream_get_contents($stream);
                fclose($stream);
                $exitCode = 0;
                return (string)$output;
            }
        }

        if (!function_exists('proc_open')) {
            return '';
        }

        $sshOpts = '-p ' . (int)$this->ssh_port
            . ' -o StrictHostKeyChecking=no'
            . ' -o UserKnownHostsFile=/dev/null'
            . ' -o PubkeyAuthentication=no'
            . ' -o PreferredAuthentications=password,keyboard-interactive'
            . ' -o ConnectTimeout=' . max(2, (int)$this->timeout)
            . ' -o LogLevel=ERROR';
        $target = escapeshellarg($this->ssh_user . '@' . $this->ssh_host);
        $remoteCmd = escapeshellarg($cliCommand);

        // Check if sshpass is available
        $hasSshpass = false;
        foreach (array('/usr/bin/sshpass', '/usr/local/bin/sshpass', '/bin/sshpass') as $spBin) {
            if (@is_executable($spBin)) {
                $hasSshpass = $spBin;
                break;
            }
        }

        $env = $_ENV;
        if (empty($env['PATH'])) {
            $env['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        }
        $askPassScript = '';

        if ($hasSshpass) {
            $env['SSHPASS'] = (string)$this->ssh_pass;
            $fullCmd = $hasSshpass . ' -e ssh ' . $sshOpts . ' ' . $target . ' ' . $remoteCmd;
        } else {
            // Use SSH_ASKPASS + setsid with standard OpenSSH client
            $askPassScript = sys_get_temp_dir() . '/ngw_askpass_' . uniqid('', true) . '.sh';
            @file_put_contents($askPassScript, "#!/bin/sh\necho \"\$NGW_SSH_PASS\"\n");
            @chmod($askPassScript, 0700);
            $env['NGW_SSH_PASS'] = (string)$this->ssh_pass;
            $env['SSH_ASKPASS'] = $askPassScript;
            $env['SSH_ASKPASS_REQUIRE'] = 'force';
            $env['DISPLAY'] = ':0';
            $fullCmd = 'setsid -w ssh ' . $sshOpts . ' ' . $target . ' ' . $remoteCmd;
        }

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $proc = @proc_open($fullCmd, $descriptors, $pipes, null, $env);
        $stdout = '';
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
        }
        if ($askPassScript !== '' && is_file($askPassScript)) {
            @unlink($askPassScript);
        }
        return (string)$stdout;
    }

    /**
     * Disconnect from RouterOS
     *
     * @return void
     */
    public function disconnect()
    {
        // let's make sure this socket is still valid.  it may have been closed by something else
        if( is_resource($this->socket) ) {
            fclose($this->socket);
        }
        $this->ssh_conn = null;
        $this->connected = false;
        $this->debug('Disconnected...');
    }


    /**
     * Parse response from Router OS
     *
     * @param array       $response   Response data
     *
     * @return array                  Array with parsed data
     */
    public function parseResponse($response)
    {
        if (is_array($response)) {
            $PARSED      = array();
            $CURRENT     = null;
            $singlevalue = null;
            foreach ($response as $x) {
                if (in_array($x, array('!fatal','!re','!trap'))) {
                    if ($x == '!re') {
                        $CURRENT =& $PARSED[];
                    } else {
                        $CURRENT =& $PARSED[$x][];
                    }
                } elseif ($x != '!done') {
                    $MATCHES = array();
                    if (preg_match_all('/[^=]+/i', $x, $MATCHES)) {
                        if ($MATCHES[0][0] == 'ret') {
                            $singlevalue = $MATCHES[0][1];
                        }
                        $CURRENT[$MATCHES[0][0]] = (isset($MATCHES[0][1]) ? $MATCHES[0][1] : '');
                    }
                }
            }

            if (empty($PARSED) && !is_null($singlevalue)) {
                $PARSED = $singlevalue;
            }

            return $PARSED;
        } else {
            return array();
        }
    }


    /**
     * Parse response from Router OS
     *
     * @param array       $response   Response data
     *
     * @return array                  Array with parsed data
     */
    public function parseResponse4Smarty($response)
    {
        if (is_array($response)) {
            $PARSED      = array();
            $CURRENT     = null;
            $singlevalue = null;
            foreach ($response as $x) {
                if (in_array($x, array('!fatal','!re','!trap'))) {
                    if ($x == '!re') {
                        $CURRENT =& $PARSED[];
                    } else {
                        $CURRENT =& $PARSED[$x][];
                    }
                } elseif ($x != '!done') {
                    $MATCHES = array();
                    if (preg_match_all('/[^=]+/i', $x, $MATCHES)) {
                        if ($MATCHES[0][0] == 'ret') {
                            $singlevalue = $MATCHES[0][1];
                        }
                        $CURRENT[$MATCHES[0][0]] = (isset($MATCHES[0][1]) ? $MATCHES[0][1] : '');
                    }
                }
            }
            foreach ($PARSED as $key => $value) {
                $PARSED[$key] = $this->arrayChangeKeyName($value);
            }
            return $PARSED;
            if (empty($PARSED) && !is_null($singlevalue)) {
                $PARSED = $singlevalue;
            }
        } else {
            return array();
        }
    }


    /**
     * Change "-" and "/" from array key to "_"
     *
     * @param array       $array      Input array
     *
     * @return array                  Array with changed key names
     */
    public function arrayChangeKeyName(&$array)
    {
        if (is_array($array)) {
            foreach ($array as $k => $v) {
                $tmp = str_replace("-", "_", $k);
                $tmp = str_replace("/", "_", $tmp);
                if ($tmp) {
                    $array_new[$tmp] = $v;
                } else {
                    $array_new[$k] = $v;
                }
            }
            return $array_new;
        } else {
            return $array;
        }
    }


    /**
     * Read data from Router OS
     *
     * @param boolean     $parse      Parse the data? default: true
     *
     * @return array                  Array with parsed or unparsed data
     */
    public function read($parse = true)
    {
        $RESPONSE     = array();
        $receiveddone = false;
        while (true) {
            // Read the first byte of input which gives us some or all of the length
            // of the remaining reply.
            $BYTE   = ord(fread($this->socket, 1));
            $LENGTH = 0;
            // If the first bit is set then we need to remove the first four bits, shift left 8
            // and then read another byte in.
            // We repeat this for the second and third bits.
            // If the fourth bit is set, we need to remove anything left in the first byte
            // and then read in yet another byte.
            if ($BYTE & 128) {
                if (($BYTE & 192) == 128) {
                    $LENGTH = (($BYTE & 63) << 8) + ord(fread($this->socket, 1));
                } else {
                    if (($BYTE & 224) == 192) {
                        $LENGTH = (($BYTE & 31) << 8) + ord(fread($this->socket, 1));
                        $LENGTH = ($LENGTH << 8) + ord(fread($this->socket, 1));
                    } else {
                        if (($BYTE & 240) == 224) {
                            $LENGTH = (($BYTE & 15) << 8) + ord(fread($this->socket, 1));
                            $LENGTH = ($LENGTH << 8) + ord(fread($this->socket, 1));
                            $LENGTH = ($LENGTH << 8) + ord(fread($this->socket, 1));
                        } else {
                            $LENGTH = ord(fread($this->socket, 1));
                            $LENGTH = ($LENGTH << 8) + ord(fread($this->socket, 1));
                            $LENGTH = ($LENGTH << 8) + ord(fread($this->socket, 1));
                            $LENGTH = ($LENGTH << 8) + ord(fread($this->socket, 1));
                        }
                    }
                }
            } else {
                $LENGTH = $BYTE;
            }

            $_ = "";

            // If we have got more characters to read, read them in.
            if ($LENGTH > 0) {
                $_      = "";
                $retlen = 0;
                while ($retlen < $LENGTH) {
                    $toread = $LENGTH - $retlen;
                    $_ .= fread($this->socket, $toread);
                    $retlen = strlen($_);
                }
                $RESPONSE[] = $_;
                $this->debug('>>> [' . $retlen . '/' . $LENGTH . '] bytes read.');
            }

            // If we get a !done, make a note of it.
            if ($_ == "!done") {
                $receiveddone = true;
            }

            $STATUS = socket_get_status($this->socket);
            if ($LENGTH > 0) {
                $this->debug('>>> [' . $LENGTH . ', ' . $STATUS['unread_bytes'] . ']' . $_);
            }

            if ((!$this->connected && !$STATUS['unread_bytes']) || ($this->connected && !$STATUS['unread_bytes'] && $receiveddone)) {
                break;
            }
        }

        if ($parse) {
            $RESPONSE = $this->parseResponse($RESPONSE);
        }

        return $RESPONSE;
    }


    /**
     * Write (send) data to Router OS
     *
     * @param string      $command    A string with the command to send
     * @param mixed       $param2     If we set an integer, the command will send this data as a "tag"
     *                                If we set it to boolean true, the funcion will send the comand and finish
     *                                If we set it to boolean false, the funcion will send the comand and wait for next command
     *                                Default: true
     *
     * @return boolean                Return false if no command especified
     */
    public function write($command, $param2 = true)
    {
        if ($command) {
            $data = explode("\n", $command);
            foreach ($data as $com) {
                $com = trim($com);
                fwrite($this->socket, $this->encodeLength(strlen($com)) . $com);
                $this->debug('<<< [' . strlen($com) . '] ' . $com);
            }

            if (gettype($param2) == 'integer') {
                fwrite($this->socket, $this->encodeLength(strlen('.tag=' . $param2)) . '.tag=' . $param2 . chr(0));
                $this->debug('<<< [' . strlen('.tag=' . $param2) . '] .tag=' . $param2);
            } elseif (gettype($param2) == 'boolean') {
                fwrite($this->socket, ($param2 ? chr(0) : ''));
            }

            return true;
        } else {
            return false;
        }
    }


    /**
     * Write (send) data to Router OS
     *
     * @param string      $com        A string with the command to send
     * @param array       $arr        An array with arguments or queries
     *
     * @return array                  Array with parsed
     */
    public function comm($com, $arr = array())
    {
        if ($this->use_ssh && !$this->ssh_bridge_local) {
            return $this->commSsh($com, $arr);
        }

        $count = count($arr);
        $this->write($com, !$arr);
        $i = 0;
        if ($this->isIterable($arr)) {
            foreach ($arr as $k => $v) {
                switch ($k[0]) {
                    case "?":
                        $el = "$k=$v";
                        break;
                    case "~":
                        $el = "$k~$v";
                        break;
                    default:
                        $el = "=$k=$v";
                        break;
                }

                $last = ($i++ == $count - 1);
                $this->write($el, $last);
            }
        }

        return $this->read();
    }

    /**
     * Translate RouterOS Binary API command + arguments into RouterOS SSH CLI and parse response into API array format
     */
    protected function commSsh($com, $arr = array())
    {
        $cleanPath = trim((string)$com, "/ \t\n\r");
        $segments = explode('/', $cleanPath);
        $action = end($segments);
        array_pop($segments);
        $cliMenu = '/' . implode(' ', $segments);

        // 1. /interface/monitor-traffic
        if ($action === 'monitor-traffic') {
            $iface = isset($arr['interface']) ? $arr['interface'] : 'ether1';
            $escIface = str_replace(array('\\', '"'), array('\\\\', '\\"'), $iface);
            $out = $this->sshExecRaw('/interface monitor-traffic interface="' . $escIface . '" once without-paging');
            $parsed = $this->parseRouterOsCliColon($out);
            $rx = isset($parsed['rx-bits-per-second']) ? $this->convertCliRateToBits($parsed['rx-bits-per-second']) : '0';
            $tx = isset($parsed['tx-bits-per-second']) ? $this->convertCliRateToBits($parsed['tx-bits-per-second']) : '0';
            return array(
                0 => array(
                    'name' => $iface,
                    'rx-bits-per-second' => (string)$rx,
                    'tx-bits-per-second' => (string)$tx,
                )
            );
        }

        // 2. Singleton colon-delimited print commands (/system/identity/print, /system/resource/print, /system/routerboard/print, /system/clock/print)
        $colonMenus = array('/system identity', '/system resource', '/system routerboard', '/system clock');
        if ($action === 'print' && in_array($cliMenu, $colonMenus, true)) {
            $out = $this->sshExecRaw($cliMenu . ' print without-paging');
            $row = $this->parseRouterOsCliColon($out);
            return empty($row) ? array() : array(0 => $row);
        }

        // 3. List print commands (.../print)
        if ($action === 'print') {
            $isCountOnly = isset($arr['count-only']);
            $extraFields = ($cliMenu === '/ip hotspot user') ? ' password' : '';
            $out = $this->sshExecRaw($cliMenu . ' print detail terse' . $extraFields . ' without-paging');
            $rows = $this->parseRouterOsCliDetail($out);

            // Apply ?field => value filters in PHP for 100% v6/v7 consistency
            $filters = array();
            if ($this->isIterable($arr)) {
                foreach ($arr as $k => $v) {
                    if (strlen($k) > 1 && $k[0] === '?') {
                        $filters[substr($k, 1)] = (string)$v;
                    }
                }
            }
            if (!empty($filters)) {
                $filtered = array();
                foreach ($rows as $r) {
                    $match = true;
                    foreach ($filters as $fk => $fv) {
                        $rv = isset($r[$fk]) ? (string)$r[$fk] : '';
                        if ($rv !== $fv) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $filtered[] = $r;
                    }
                }
                $rows = $filtered;
            }

            if ($isCountOnly) {
                return (string)count($rows);
            }
            return $rows;
        }

        // 4. System reboot / shutdown
        if ($cleanPath === 'system/reboot' || $cleanPath === 'system/shutdown') {
            $this->sshExecRaw('/' . str_replace('/', ' ', $cleanPath));
            return array();
        }

        // 5. Item targeting actions (remove, enable, disable, reset-counters)
        if (in_array($action, array('remove', 'enable', 'disable', 'reset-counters'), true)) {
            $targetId = isset($arr['.id']) ? (string)$arr['.id'] : (isset($arr['numbers']) ? (string)$arr['numbers'] : '');
            $cliTarget = $this->formatCliTargetId($targetId);
            if ($cliTarget !== '') {
                $this->sshExecRaw($cliMenu . ' ' . $action . ' ' . $cliTarget);
            }
            return array();
        }

        // 6. Add or Set commands
        if ($action === 'add' || $action === 'set') {
            $kvParts = array();
            $targetId = '';
            if ($this->isIterable($arr)) {
                foreach ($arr as $k => $v) {
                    if ($k === '.id' || $k === 'numbers') {
                        $targetId = (string)$v;
                        continue;
                    }
                    $cleanKey = ltrim((string)$k, '=?');
                    $escVal = str_replace(array('\\', '"', '$'), array('\\\\', '\\"', '\\$'), (string)$v);
                    $kvParts[] = $cleanKey . '="' . $escVal . '"';
                }
            }
            if ($action === 'set') {
                $cliTarget = $this->formatCliTargetId($targetId);
                if ($cliTarget !== '' && !empty($kvParts)) {
                    $this->sshExecRaw($cliMenu . ' set ' . $cliTarget . ' ' . implode(' ', $kvParts));
                }
                return array();
            } else {
                if (!empty($kvParts)) {
                    $this->sshExecRaw($cliMenu . ' add ' . implode(' ', $kvParts));
                }
                return array('ret' => '*new');
            }
        }

        return array();
    }

    protected function formatCliTargetId($targetId)
    {
        $t = trim((string)$targetId);
        if ($t === '') {
            return '';
        }
        // If .id is formatted as *0, *1, *2 from parseRouterOsCliDetail, strip leading * for CLI index
        if (preg_match('/^\*(\d+)$/', $t, $m)) {
            return $m[1];
        }
        // If comma-separated list of *0,*1
        if (strpos($t, ',') !== false) {
            $parts = explode(',', $t);
            $cleaned = array();
            foreach ($parts as $p) {
                $p = trim($p);
                if (preg_match('/^\*(\d+)$/', $p, $m)) {
                    $cleaned[] = $m[1];
                } elseif ($p !== '') {
                    $cleaned[] = $p;
                }
            }
            return implode(',', $cleaned);
        }
        $esc = str_replace(array('\\', '"'), array('\\\\', '\\"'), $t);
        return '"' . $esc . '"';
    }

    protected function convertCliRateToBits($rateStr)
    {
        $s = strtolower(trim((string)$rateStr));
        if ($s === '' || $s === '0' || $s === '0bps') {
            return 0;
        }
        if (preg_match('/^([0-9\.]+)\s*(gbps|mbps|kbps|bps)?$/i', $s, $m)) {
            $val = (float)$m[1];
            $unit = isset($m[2]) ? strtolower($m[2]) : 'bps';
            if ($unit === 'gbps') return (int)round($val * 1000000000);
            if ($unit === 'mbps') return (int)round($val * 1000000);
            if ($unit === 'kbps') return (int)round($val * 1000);
            return (int)round($val);
        }
        return (int)$s;
    }

    protected function parseRouterOsCliColon($output)
    {
        $res = array();
        $lines = preg_split('/\r?\n/', (string)$output);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            $parts = explode(':', $line, 2);
            $k = trim($parts[0]);
            $v = trim($parts[1], " \t\"");
            if ($k !== '') {
                $res[$k] = $v;
            }
        }
        return $res;
    }

    protected function parseRouterOsCliDetail($output)
    {
        $text = str_replace("\r\n", "\n", (string)$output);
        $lines = explode("\n", $text);
        $blocks = array();
        $curBlock = null;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d+)\s+([A-Z\s]*?)(?:;;;\s*(.*)|([a-zA-Z0-9_\-\.]+\=.*))$/', $line, $m)) {
                if ($curBlock !== null) {
                    $blocks[] = $curBlock;
                }
                $idx = $m[1];
                $flags = trim($m[2]);
                $commentLine = isset($m[3]) ? trim($m[3]) : '';
                $kvRest = isset($m[4]) ? $m[4] : '';
                $curBlock = array(
                    'idx' => $idx,
                    'flags' => $flags,
                    'comment' => $commentLine,
                    'body' => $kvRest,
                );
            } elseif ($curBlock !== null) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                if (strpos($trimmed, ';;;') === 0 && $curBlock['comment'] === '') {
                    $curBlock['comment'] = trim(substr($trimmed, 3));
                } else {
                    $curBlock['body'] .= ' ' . $trimmed;
                }
            }
        }
        if ($curBlock !== null) {
            $blocks[] = $curBlock;
        }

        $parsedRows = array();
        foreach ($blocks as $b) {
            $flagStr = $b['flags'];
            $row = array(
                '.id' => '*' . $b['idx'],
                'disabled' => (strpos($flagStr, 'X') !== false) ? 'true' : 'false',
                'dynamic'  => (strpos($flagStr, 'D') !== false) ? 'true' : 'false',
                'running'  => (strpos($flagStr, 'R') !== false) ? 'true' : 'false',
            );
            if ($b['comment'] !== '') {
                $row['comment'] = $b['comment'];
            }
            $kvs = $this->extractCliKeyValues($b['body']);
            foreach ($kvs as $k => $v) {
                $cleanK = (trim($k) === 'password.') ? 'password' : $k;
                $row[$cleanK] = $v;
            }
            $parsedRows[] = $row;
        }
        return $parsedRows;
    }

    protected function extractCliKeyValues($body)
    {
        $result = array();
        $len = strlen($body);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && ctype_space($body[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $keyStart = $i;
            while ($i < $len && $body[$i] !== '=' && !ctype_space($body[$i])) {
                $i++;
            }
            if ($i >= $len || $body[$i] !== '=') {
                while ($i < $len && !ctype_space($body[$i])) {
                    $i++;
                }
                continue;
            }
            $key = substr($body, $keyStart, $i - $keyStart);
            $i++; // skip '='
            if ($i >= $len) {
                $result[$key] = '';
                break;
            }
            if ($body[$i] === '"') {
                $i++; // skip opening quote
                $val = '';
                while ($i < $len) {
                    if ($body[$i] === '\\' && ($i + 1) < $len) {
                        $val .= $body[$i + 1];
                        $i += 2;
                    } elseif ($body[$i] === '"') {
                        $i++; // skip closing quote
                        break;
                    } else {
                        $val .= $body[$i];
                        $i++;
                    }
                }
                $result[$key] = $val;
            } else {
                $valStart = $i;
                while ($i < $len && !ctype_space($body[$i])) {
                    $i++;
                }
                $result[$key] = substr($body, $valStart, $i - $valStart);
            }
        }
        return $result;
    }

    /**
     * Standard destructor
     *
     * @return void
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}

// encrypt decript

function encrypt($string, $key=128) {
	$result = '';
	for($i=0, $k= strlen($string); $i<$k; $i++) {
		$char = substr($string, $i, 1);
		$keychar = substr($key, ($i % strlen($key))-1, 1);
		$char = chr(ord($char)+ord($keychar));
		$result .= $char;
	}
	return base64_encode($result);
}
function decrypt($string, $key=128) {
	$result = '';
	$string = base64_decode($string);
	for($i=0, $k=strlen($string); $i< $k ; $i++) {
		$char = substr($string, $i, 1);
		$keychar = substr($key, ($i % strlen($key))-1, 1);
		$char = chr(ord($char)-ord($keychar));
		$result .= $char;
	}
	return $result;
}

// Reformat date time MikroTik
// by Laksamadi Guko

function formatInterval($dtm){
$val_convert = $dtm;
$new_format = str_replace("s", "", str_replace("m", "m ", str_replace("h", "h ", str_replace("d", "d ", str_replace("w", "w ", $val_convert)))));
return $new_format;
}

function formatDTM($dtm){
if(substr($dtm, 1,1) == "d" || substr($dtm, 2,1) == "d"){
    $day = explode("d",$dtm)[0]."d";
    $day = str_replace("d", "d ", str_replace("w", "w ", $day));
    $dtm = explode("d",$dtm)[1];
}elseif(substr($dtm, 1,1) == "w" && substr($dtm, 3,1) == "d" || substr($dtm, 2,1) == "w" && substr($dtm, 4,1) == "d"){
    $day = explode("d",$dtm)[0]."d";
    $day = str_replace("d", "d ", str_replace("w", "w ", $day));
    $dtm = explode("d",$dtm)[1];
}elseif (substr($dtm, 1,1) == "w" || substr($dtm, 2,1) == "w" ) {
    $day = explode("w",$dtm)[0]."w";
    $day = str_replace("d", "d ", str_replace("w", "w ", $day));
    $dtm = explode("w",$dtm)[1];
}

// secs
if(strlen($dtm) == "2" && substr($dtm, -1) == "s"){
    $format = $day." 00:00:0".substr($dtm, 0,-1);
}elseif(strlen($dtm) == "3" && substr($dtm, -1) == "s"){
    $format = $day." 00:00:".substr($dtm, 0,-1);
//minutes
}elseif(strlen($dtm) == "2" && substr($dtm, -1) == "m"){
    $format = $day." 00:0".substr($dtm, 0,-1).":00";
}elseif(strlen($dtm) == "3" && substr($dtm, -1) == "m"){
    $format = $day." 00:".substr($dtm, 0,-1).":00";
//hours
}elseif(strlen($dtm) == "2" && substr($dtm, -1) == "h"){
    $format = $day." 0".substr($dtm, 0,-1).":00:00";
}elseif(strlen($dtm) == "3" && substr($dtm, -1) == "h"){
    $format = $day." ".substr($dtm, 0,-1).":00:00";
 
//minutes -secs
}elseif(strlen($dtm) == "4" && substr($dtm, -1) == "s" && substr($dtm,1,-2) == "m"){
    $format = $day." "."00:0".substr($dtm, 0,1).":0".substr($dtm, 2,-1);
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "s" && substr($dtm,1,-3) == "m"){
    $format = $day." "."00:0".substr($dtm, 0,1).":".substr($dtm, 2,-1);
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "s" && substr($dtm,2,-2) == "m"){
    $format = $day." "."00:".substr($dtm, 0,2).":0".substr($dtm, 3,-1);
}elseif(strlen($dtm) == "6" && substr($dtm, -1) == "s" && substr($dtm,2,-3) == "m"){
    $format = $day." "."00:".substr($dtm, 0,2).":".substr($dtm, 3,-1);

//hours -secs
}elseif(strlen($dtm) == "4" && substr($dtm, -1) == "s" && substr($dtm,1,-2) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":00:0".substr($dtm, 2,-1);
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "s" && substr($dtm,1,-3) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":00:".substr($dtm, 2,-1);
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "s" && substr($dtm,2,-2) == "h"){
    $format = $day." ".substr($dtm, 0,2).":00:0".substr($dtm, 3,-1);
}elseif(strlen($dtm) == "6" && substr($dtm, -1) == "s" && substr($dtm,2,-3) == "h"){
    $format = $day." ".substr($dtm, 0,2).":00:".substr($dtm, 3,-1);

//hours -secs
}elseif(strlen($dtm) == "4" && substr($dtm, -1) == "m" && substr($dtm,1,-2) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":0".substr($dtm, 2,-1).":00";
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "m" && substr($dtm,1,-3) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":".substr($dtm, 2,-1).":00";
}elseif(strlen($dtm) == "5" && substr($dtm, -1) == "m" && substr($dtm,2,-2) == "h"){
    $format = $day." ".substr($dtm, 0,2).":0".substr($dtm, 3,-1).":00";
}elseif(strlen($dtm) == "6" && substr($dtm, -1) == "m" && substr($dtm,2,-3) == "h"){
    $format = $day." ".substr($dtm, 0,2).":".substr($dtm, 3,-1).":00";

//hours minutes secs
}elseif(strlen($dtm) == "6" && substr($dtm, -1) == "s" && substr($dtm,3,-2) == "m" && substr($dtm,1,-4) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":0".substr($dtm, 2,-3).":0".substr($dtm, 4,-1);
}elseif(strlen($dtm) == "7" && substr($dtm, -1) == "s" && substr($dtm,3,-3) == "m" && substr($dtm,1,-5) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":0".substr($dtm, 2,-4).":".substr($dtm, 4,-1);
}elseif(strlen($dtm) == "7" && substr($dtm, -1) == "s" && substr($dtm,4,-2) == "m" && substr($dtm,1,-5) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":".substr($dtm, 2,-3).":0".substr($dtm, 5,-1);
}elseif(strlen($dtm) == "8" && substr($dtm, -1) == "s" && substr($dtm,4,-3) == "m" && substr($dtm,1,-6) == "h"){
    $format = $day." 0".substr($dtm, 0,1).":".substr($dtm, 2,-4).":".substr($dtm, 5,-1);
}elseif(strlen($dtm) == "7" && substr($dtm, -1) == "s" && substr($dtm,4,-2) == "m" && substr($dtm,2,-4) == "h"){
    $format = $day." ".substr($dtm, 0,2).":0".substr($dtm, 3,-3).":0".substr($dtm, 5,-1);
}elseif(strlen($dtm) == "8" && substr($dtm, -1) == "s" && substr($dtm,4,-3) == "m" && substr($dtm,2,-5) == "h"){
    $format = $day." ".substr($dtm, 0,2).":0".substr($dtm, 3,-4).":".substr($dtm, 5,-1);
}elseif(strlen($dtm) == "8" && substr($dtm, -1) == "s" && substr($dtm,5,-2) == "m" && substr($dtm,2,-5) == "h"){
    $format = $day." ".substr($dtm, 0,2).":".substr($dtm, 3,-3).":0".substr($dtm, 6,-1);
}elseif(strlen($dtm) == "9" && substr($dtm, -1) == "s" && substr($dtm,5,-3) == "m" && substr($dtm,2,-6) == "h"){
    $format = $day." ".substr($dtm, 0,2).":".substr($dtm, 3,-4).":".substr($dtm, 6,-1);

}else{
    $format = $dtm;
}
return $format;
}


function randN($length) {
	$chars = "23456789";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

function randUC($length) {
	$chars = "ABCDEFGHJKLMNPRSTUVWXYZ";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}
function randLC($length) {
	$chars = "abcdefghijkmnprstuvwxyz";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

function randULC($length) {
	$chars = "ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

function randNLC($length) {
	$chars = "23456789abcdefghijkmnprstuvwxyz";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

function randNUC($length) {
	$chars = "23456789ABCDEFGHJKLMNPRSTUVWXYZ";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

function randNULC($length) {
	$chars = "23456789ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz";
	$charArray = str_split($chars);
	$charCount = strlen($chars);
	$result = "";
	for($i=1;$i<=$length;$i++)
	{
		$randChar = rand(0,$charCount-1);
		$result .= $charArray[$randChar];
	}
	return $result;
}

?>
