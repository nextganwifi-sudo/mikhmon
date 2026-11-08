<?php
function checkProfileFeature($ok, $message) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}
$profile = file_get_contents(__DIR__ . '/../mikhmonv3-upstream/settings/my_profile.php');
$menu = file_get_contents(__DIR__ . '/../mikhmonv3-upstream/include/menu.php');
$admin = file_get_contents(__DIR__ . '/../mikhmonv3-upstream/admin.php');
$index = file_get_contents(__DIR__ . '/../mikhmonv3-upstream/index.php');
$resellers = file_get_contents(__DIR__ . '/../mikhmonv3-upstream/settings/resellers.php');
checkProfileFeature(is_string($profile) && strpos($profile, 'change_my_password') !== false, 'Profile password form exists');
checkProfileFeature(strpos($profile, 'hash_equals($profileCsrf') !== false, 'Profile password changes validate CSRF');
checkProfileFeature(strpos($profile, 'verifyResellerPassword') !== false, 'Reseller must verify current password');
checkProfileFeature(strpos($profile, 'password_hash($newPassword') !== false, 'New passwords are hashed');
checkProfileFeature(strpos($profile, '$newConfig = preg_replace(') !== false, 'Primary admin config update uses a bounded config-line replacement');
checkProfileFeature(strpos($menu, 'Intl.DateTimeFormat().resolvedOptions().timeZone') !== false, 'Clock uses browser local time zone');
checkProfileFeature(substr_count($menu, 'id="ngw-local-datetime"') === 2, 'Clock is present in both admin and reseller navigation');
checkProfileFeature(strpos($menu, 'admin.php?id=my-profile') === false && strpos($menu, 'hotspot=my-profile') === false && strpos($menu, 'ngw-profile-nav') === false, 'Profile navigation is not shown in either role');
checkProfileFeature(strpos($admin, '$id == "my-profile"') !== false, 'Admin profile route exists');
checkProfileFeature(strpos($index, '$hotspot == "my-profile"') !== false && strpos($index, "'my-profile'") !== false, 'Reseller profile route avoids router connection');
checkProfileFeature(strpos($resellers, "'password_reveal'") === false && strpos($resellers, 'det_password_toggle') === false, 'Admin reseller details do not expose password reveal');
checkProfileFeature(strpos($resellers, 'JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT') !== false, 'Reseller JSON is HTML-safe encoded');
checkProfileFeature(strpos($resellers, 'function toggleActionMenu(ev, id, trigger)') !== false && strpos($resellers, 'onclick="toggleActionMenu(event,') !== false, 'Reseller action menu toggles directly from its button');
checkProfileFeature(strpos($resellers, '.ngw-action-wrap { position: relative;') !== false && strpos($resellers, 'document.querySelectorAll(\'.ngw-action-menu\')') !== false, 'Reseller action menu is anchored to its own action cell');
checkProfileFeature(substr_count($menu, 'class="ngw-profile-nav"') === 2 && strpos($menu, 'id="timer"') < strpos($menu, 'class="ngw-profile-nav"'), 'Each role has one profile shortcut placed after the navbar controls');

echo "PASS: profile, password-change, local clock, and admin reseller credential-detail regressions\n";
