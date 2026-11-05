<?php
/**
 * Normalize RouterOS API row keys for VPN Hub consumers.
 *
 * RouterOS returns keys such as remote-address, dst-port and to-addresses.
 * The legacy UI consumes underscore keys. The generic API parser intentionally
 * preserves original RouterOS keys, so normalize only this module's read data.
 */
function vpnNormalizeApiRows($rows) {
    if (!is_array($rows)) {
        return array();
    }
    $normalized = array();
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            $normalized[$index] = $row;
            continue;
        }
        $mapped = $row;
        foreach ($row as $key => $value) {
            if (strpos((string)$key, '-') !== false) {
                $underscoreKey = str_replace('-', '_', (string)$key);
                if (!array_key_exists($underscoreKey, $mapped)) {
                    $mapped[$underscoreKey] = $value;
                }
            }
        }
        $normalized[$index] = $mapped;
    }
    return $normalized;
}
