<?php
$source = file_get_contents(__DIR__ . '/../mikhmonv3-upstream/hotspot/adduser.php');
function checkAddUserFlow($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}
checkAddUserFlow(is_string($source) && $source !== '', 'Single Add User source is readable');
checkAddUserFlow(strpos($source, "'/ip/hotspot/user/add'") !== false, 'Uses existing RouterOS user-add API command');
checkAddUserFlow(strpos($source, "'/ip/hotspot/user/print'") !== false, 'Verifies the user on the router after creation');
checkAddUserFlow(strpos($source, "isset(\$existingUser['name'])") !== false, 'Checks duplicate usernames before creation');
checkAddUserFlow(strpos($source, "ngwRecordActivity('hotspot_user_created'") !== false, 'Records activity only after verified creation');
checkAddUserFlow(strpos($source, "hotspot-user=add&session=") !== false, 'Returns to the same Add User screen');
checkAddUserFlow(strpos($source, "hotspot-user=\" . \$uid") === false, 'Does not redirect to Edit User after creation');
checkAddUserFlow(strpos($source, "ngw_adduser_success") !== false, 'Shows a one-time success message');
checkAddUserFlow(strpos($source, "ngw_adduser_error") === false, 'No unsupported error-flash key is referenced');
echo "PASS: single Add User stays on Add User, verifies router result, and records confirmed creation\n";
