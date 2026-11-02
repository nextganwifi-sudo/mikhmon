<?php
function transferUser($row, $profile, $server = 'all') {
    foreach (array('name', 'password', 'comment') as $key) {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new InvalidArgumentException('Missing ' . $key . '; export requires password access.');
        }
    }
    if ($row['name'] === '' || strlen($row['name']) > 255) {
        throw new InvalidArgumentException('Invalid username.');
    }
    $out = array('name' => $row['name'], 'password' => $row['password'],
        'profile' => $profile, 'comment' => $row['comment']);
    if (isset($row['mac-address']) && is_string($row['mac-address']) && $row['mac-address'] !== ''
        && $row['mac-address'] !== '00:00:00:00:00:00') {
        $out['mac-address'] = $row['mac-address'];
    }
    return $out;
}
