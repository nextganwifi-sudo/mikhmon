<?php
/*
 * NextGan WiFi Solution - Authentic MikroTik Hardware Model Resolver & Cache
 *
 * Captures RouterOS hardware model/board-name automatically on first login
 * (using the already-fetched /system/resource & /system/routerboard responses,
 * requiring ZERO extra RouterOS API calls) and caches it in router_meta.json
 * so every UI theme displays an unmistakable, model-accurate studio MikroTik
 * hardware photo for each distinct router series.
 */

if (!function_exists('ngwGetRouterModelAssetVersion')) {
  function ngwGetRouterModelAssetVersion() {
    return '20261122r2';
  }
}

if (!function_exists('ngwGetAllowedRouterModelImages')) {
  function ngwGetAllowedRouterModelImages() {
    return array(
      'img/routers/ccr2004.png',
      'img/routers/ccr1009.png',
      'img/routers/l009.png',
      'img/routers/rb5009.png',
      'img/routers/rb4011.png',
      'img/routers/rb1100.png',
      'img/routers/rb3011.png',
      'img/routers/rb2011.png',
      'img/routers/crs.png',
      'img/routers/hex_s.png',
      'img/routers/hex.png',
      'img/routers/hap_ant.png',
      'img/routers/hap_tower.png',
      'img/routers/rb951.png',
      'img/routers/chr.png',
      // Legacy SVG fallbacks kept in allowlist for graceful compatibility
      'img/routers/ccr.svg',
      'img/routers/rb4011.svg',
      'img/routers/rb2011.svg',
      'img/routers/hex.svg',
      'img/routers/hap.svg',
      'img/routers/crs.svg',
      'img/routers/chr.svg',
      'img/routers/mikrotik_generic.svg',
    );
  }
}

if (!function_exists('ngwResolveMikrotikModelAsset')) {
  function ngwResolveMikrotikModelAsset($model, $boardName = '') {
    $modelStr = trim((string)$model);
    $boardStr = trim((string)$boardName);
    $combined = strtolower(trim($modelStr . ' ' . $boardStr));

    if ($combined === '' || $combined === 'n/a' || $combined === 'unknown') {
      return null;
    }

    // Also inspect a compact alphanumeric-normalized copy (handles spaces like "750G r3", "hEX S", "RB 2011")
    $compact = preg_replace('/[^a-z0-9]+/', '', $combined);

    // Pick best display label (prefer concrete routerboard model unless empty/generic)
    $label = $modelStr;
    if ($label === '' || strtolower($label) === 'n/a' || strtolower($label) === 'routerboard') {
      $label = $boardStr;
    }
    if ($label === '') {
      $label = $modelStr;
    }
    if (strlen($label) > 36) {
      $label = substr($label, 0, 36);
    }

    // 1. Modern Cloud Core Router 2xxx series (CCR2004, CCR2116, CCR2216 — silver/white 1U, no LCD)
    if (preg_match('/ccr\s*2\d{3}/i', $combined) || preg_match('/ccr2\d{3}/', $compact)) {
      return array(
        'image' => 'img/routers/ccr2004.png',
        'family' => 'CCR2000',
        'label' => $label,
      );
    }

    // 2. Classic Cloud Core Router 1xxx series (CCR1009, CCR1016, CCR1036, CCR1072 — white 1U with blue LCD)
    if (preg_match('/\bccr\s*\d|\bcloud\s*core/i', $combined) || strpos($compact, 'cloudcore') !== false) {
      return array(
        'image' => 'img/routers/ccr1009.png',
        'family' => 'CCR',
        'label' => $label,
      );
    }

    // 3. L009 Red Finned Desktop/RM series (L009UiGS-RM, L009UiGS-2HaxD)
    if (preg_match('/\bl009/i', $combined) || strpos($compact, 'l009') !== false) {
      return array(
        'image' => 'img/routers/l009.png',
        'family' => 'L009',
        'label' => $label,
      );
    }

    // 4. RB5009 Heavy Finned Half-Width Pro series (RB5009UG+S+, RB5009UPr+S+, 5009UG)
    if (preg_match('/(?:^|[^0-9a-z])(?:rb)?\s*5009/i', $combined)) {
      return array(
        'image' => 'img/routers/rb5009.png',
        'family' => 'RB5009',
        'label' => $label,
      );
    }

    // 5. RB4011 Matte Black 10-Port + 10G SFP+ series (RB4011iGS+, 4011iGS+)
    if (preg_match('/(?:^|[^0-9a-z])(?:rb)?\s*4011/i', $combined)) {
      return array(
        'image' => 'img/routers/rb4011.png',
        'family' => 'RB4011',
        'label' => $label,
      );
    }

    // 6. RB1100 / RB1200 Matte Black 1U 13-Port Rackmount (RB1100AHx4, RB1100Dx4, 1100AHx2, RB1200)
    if (preg_match('/(?:^|[^0-9a-z])(?:rb)?\s*(?:1100|1200)/i', $combined)) {
      return array(
        'image' => 'img/routers/rb1100.png',
        'family' => 'RB1100',
        'label' => $label,
      );
    }

    // 7. RB3011 Matte Black 1U Rackmount with LCD (RB3011UiAS-RM, 3011UiAS)
    if (preg_match('/(?:^|[^0-9a-z])(?:rb)?\s*3011/i', $combined)) {
      return array(
        'image' => 'img/routers/rb3011.png',
        'family' => 'RB3011',
        'label' => $label,
      );
    }

    // 8. RB2011 Iconic Red Metal Desktop Router (RB2011UiAS-2HnD, RB2011iL, 2011UiAS)
    if (preg_match('/(?:^|[^0-9a-z])(?:rb)?\s*2011/i', $combined)) {
      return array(
        'image' => 'img/routers/rb2011.png',
        'family' => 'RB2011',
        'label' => $label,
      );
    }

    // 9. Cloud Router Switch / Cloud Smart Switch (CRS1xx, CRS2xx, CRS3xx, CRS4xx, CRS5xx, CSS)
    if (preg_match('/\bcrs\s*\d|\bcss\s*\d|\bcloud\s*router\s*switch/i', $combined)) {
      return array(
        'image' => 'img/routers/crs.png',
        'family' => 'CRS',
        'label' => $label,
      );
    }

    // 10. hEX S / hEX PoE (RB760iGS, 760iGS, RB960PGS, 960PGS — compact matte black with SFP)
    if (
      preg_match('/(?:^|[^0-9a-z])(?:rb)?\s*(?:760|960)/i', $combined) ||
      preg_match('/\bhex\s*(?:s|poe)\b/i', $combined) ||
      strpos($compact, 'hexs') !== false ||
      strpos($compact, 'hexpoe') !== false
    ) {
      return array(
        'image' => 'img/routers/hex_s.png',
        'family' => 'hEX S',
        'label' => $label,
      );
    }

    // 11. hEX / RB750Gr3 / RB750r2 / hEX refresh (E50UG) / RB450 / RB850 / PowerBox (iconic white compact hEX)
    if (
      preg_match('/(?:^|[^0-9a-z])(?:rb)?\s*(?:750|450|850)/i', $combined) ||
      preg_match('/\bhex\b|\bhex\s|\be50ug|\bpowerbox|\bgr3\b/i', $combined)
    ) {
      return array(
        'image' => 'img/routers/hex.png',
        'family' => 'hEX',
        'label' => $label,
      );
    }

    // 12. hAP ac3 / hAP ax3 / Dual-Antenna Wireless Routers (RBD53, C53, hAP ac3, hAP ax3, RB951-ext)
    if (
      preg_match('/\bhap\s*(?:ac|ax)\s*3|\brbd53|\bc53|\bac3\b|\bax3\b/i', $combined) ||
      strpos($compact, 'hapac3') !== false ||
      strpos($compact, 'hapax3') !== false
    ) {
      return array(
        'image' => 'img/routers/hap_ant.png',
        'family' => 'hAP Pro',
        'label' => $label,
      );
    }

    // 13. hAP ac2 / hAP ax2 / hAP lite TC / Chateau / Audience (vertical matte-black standing tower)
    if (
      preg_match('/\bhap\s*(?:ac|ax)\s*2|\brbd52|\bc52|\b5hacd|\b5haxd|\bac2\b|\bax2\b|\bchateau|\baudience|\b2nd-tc|\blite\s*tc\b/i', $combined) ||
      strpos($compact, 'hapac2') !== false ||
      strpos($compact, 'hapax2') !== false ||
      strpos($compact, 'rbd52') !== false ||
      strpos($compact, 'c52ig') !== false
    ) {
      return array(
        'image' => 'img/routers/hap_tower.png',
        'family' => 'hAP Tower',
        'label' => $label,
      );
    }

    // 14. RB951 / RB941 / RB952 / RB962 / RB751 / hAP / hAP lite / cAP / wAP (classic glossy white WiFi router)
    if (
      preg_match('/(?:^|[^0-9a-z])(?:rb)?\s*(?:951|941|952|962|751|931|911|912|921|922)/i', $combined) ||
      preg_match('/\bhap\b|\bhap\s|\bcap\b|\bcap\s|\bwap\b|\b2hnd|\b2nd\b|\b5ac2nd/i', $combined)
    ) {
      return array(
        'image' => 'img/routers/rb951.png',
        'family' => 'RB951/hAP',
        'label' => $label,
      );
    }

    // 15. Cloud Hosted Router / x86 / Virtual / Demo / KVM / VMware
    if (preg_match('/\bchr\b|\bx86\b|\bvirtual|\bdemo\b|\bkvm\b|\bvmware|\bhyper-v|\bcloud\s*hosted/i', $combined)) {
      return array(
        'image' => 'img/routers/chr.png',
        'family' => 'CHR',
        'label' => $label,
      );
    }

    // 16. Fallback for any other valid detected MikroTik RouterBoard hardware
    return array(
      'image' => 'img/routers/hex.png',
      'family' => 'RouterBoard',
      'label' => $label,
    );
  }
}

if (!function_exists('ngwGetRouterMetaCached')) {
  function ngwGetRouterMetaCached($forceReload = false) {
    static $cachedMeta = null;
    if ($forceReload || $cachedMeta === null) {
      if (!function_exists('loadRouterMeta') && is_file(__DIR__ . '/reseller_acl.php')) {
        include_once(__DIR__ . '/reseller_acl.php');
      }
      $cachedMeta = function_exists('loadRouterMeta') ? loadRouterMeta() : array();
      if (!is_array($cachedMeta)) {
        $cachedMeta = array();
      }
    }
    return $cachedMeta;
  }
}

if (!function_exists('ngwSaveRouterHardwareInfo')) {
  function ngwSaveRouterHardwareInfo($sessionKey, $model, $boardName = '') {
    $sessionKey = trim((string)$sessionKey);
    if ($sessionKey === '' || $sessionKey === 'mikhmon') {
      return false;
    }
    $resolved = ngwResolveMikrotikModelAsset($model, $boardName);
    if (!is_array($resolved)) {
      return false;
    }

    if (!function_exists('loadRouterMeta') && is_file(__DIR__ . '/reseller_acl.php')) {
      include_once(__DIR__ . '/reseller_acl.php');
    }
    if (!function_exists('loadRouterMeta') || !function_exists('saveRouterMeta')) {
      return false;
    }

    $meta = loadRouterMeta();
    if (!is_array($meta)) {
      $meta = array();
    }
    $existing = (isset($meta[$sessionKey]) && is_array($meta[$sessionKey])) ? $meta[$sessionKey] : array();

    $modelClean = trim((string)$model);
    $boardClean = trim((string)$boardName);

    // Zero disk write if model info is already stored and unchanged
    if (
      isset($existing['hw_image']) && $existing['hw_image'] === $resolved['image'] &&
      isset($existing['hw_label']) && $existing['hw_label'] === $resolved['label'] &&
      isset($existing['hw_model']) && $existing['hw_model'] === $modelClean &&
      isset($existing['hw_board']) && $existing['hw_board'] === $boardClean
    ) {
      return true;
    }

    $existing['hw_model'] = $modelClean;
    $existing['hw_board'] = $boardClean;
    $existing['hw_label'] = $resolved['label'];
    $existing['hw_family'] = $resolved['family'];
    $existing['hw_image'] = $resolved['image'];
    $existing['hw_updated_at'] = gmdate('c');

    $meta[$sessionKey] = $existing;
    $saved = saveRouterMeta($meta);
    ngwGetRouterMetaCached(true);
    return $saved;
  }
}

if (!function_exists('ngwGetRouterHardwareInfo')) {
  function ngwGetRouterHardwareInfo($sessionKey) {
    $sessionKey = trim((string)$sessionKey);
    if ($sessionKey === '') {
      return null;
    }
    $meta = ngwGetRouterMetaCached(false);
    if (!isset($meta[$sessionKey]) || !is_array($meta[$sessionKey])) {
      return null;
    }
    $entry = $meta[$sessionKey];
    $m = isset($entry['hw_model']) ? trim((string)$entry['hw_model']) : '';
    $b = isset($entry['hw_board']) ? trim((string)$entry['hw_board']) : '';
    $lblFallback = isset($entry['hw_label']) ? trim((string)$entry['hw_label']) : '';

    // Always resolve dynamically from captured model/board/label so improved model photos
    // apply instantly to every already-cached router without requiring re-login.
    if ($m !== '' || $b !== '' || $lblFallback !== '') {
      $resolved = ngwResolveMikrotikModelAsset($m !== '' ? $m : $lblFallback, $b);
      if (is_array($resolved)) {
        return array(
          'model' => $m,
          'board' => $b,
          'label' => $lblFallback !== '' ? $lblFallback : $resolved['label'],
          'family' => $resolved['family'],
          'image' => $resolved['image'] . '?v=' . ngwGetRouterModelAssetVersion(),
        );
      }
    }

    $img = isset($entry['hw_image']) ? trim((string)$entry['hw_image']) : '';
    if ($img === '' || !in_array($img, ngwGetAllowedRouterModelImages(), true)) {
      return null;
    }

    return array(
      'model' => $m,
      'board' => $b,
      'label' => $lblFallback !== '' ? $lblFallback : $m,
      'family' => isset($entry['hw_family']) ? (string)$entry['hw_family'] : 'RouterBoard',
      'image' => $img . '?v=' . ngwGetRouterModelAssetVersion(),
    );
  }
}
