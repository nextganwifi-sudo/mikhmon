<?php
require __DIR__ . '/../mikhmonv3-upstream/include/vpn_api_normalize.php';

function vpnNormalizeAssert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$secrets = vpnNormalizeApiRows(array(array(
    'name' => 'client01',
    'remote-address' => '10.10.10.2',
    'caller-id' => 'AA:BB:CC:DD:EE:FF',
    'service' => 'sstp'
)));
vpnNormalizeAssert($secrets[0]['remote_address'] === '10.10.10.2', 'PPP secret remote-address was not normalized.');
vpnNormalizeAssert($secrets[0]['caller_id'] === 'AA:BB:CC:DD:EE:FF', 'PPP active caller-id was not normalized.');
vpnNormalizeAssert($secrets[0]['name'] === 'client01', 'Existing keys must be preserved.');

$nat = vpnNormalizeApiRows(array(array(
    'dst-port' => '8010',
    'to-addresses' => '10.10.10.2',
    'to-ports' => '8291',
    'protocol' => 'tcp'
)));
vpnNormalizeAssert($nat[0]['dst_port'] === '8010', 'NAT dst-port was not normalized.');
vpnNormalizeAssert($nat[0]['to_addresses'] === '10.10.10.2', 'NAT to-addresses was not normalized.');
vpnNormalizeAssert($nat[0]['to_ports'] === '8291', 'NAT to-ports was not normalized.');

$alreadyNormalized = vpnNormalizeApiRows(array(array('remote_address' => '10.0.0.1')));
vpnNormalizeAssert($alreadyNormalized[0]['remote_address'] === '10.0.0.1', 'Already-normalized fields must remain intact.');

vpnNormalizeAssert(vpnNormalizeApiRows(false) === array(), 'Non-array API responses must safely return an empty array.');
echo "PASS: VPN RouterOS API field normalization (PPP secrets, active sessions and NAT rules)\n";
