<?php
require __DIR__ . '/../mikhmonv3-upstream/include/security.php';

function ngwSecurityAssert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

session_start();
$token = ngwCsrfToken('test_csrf');
ngwSecurityAssert(strlen($token) === 64, 'CSRF token must be 32 random bytes encoded as hex.');
ngwSecurityAssert(ngwValidateCsrf($token, 'test_csrf'), 'Valid CSRF token was rejected.');
ngwSecurityAssert(!ngwValidateCsrf('invalid', 'test_csrf'), 'Invalid CSRF token was accepted.');

$_SERVER['REMOTE_ADDR'] = '127.0.0.77';
$username = 'security-test-' . bin2hex(random_bytes(6));
ngwSecurityAssert(!ngwLoginIsThrottled($username), 'New login identity should not be throttled.');
for ($i = 0; $i < 10; $i++) {
    ngwSecurityAssert(ngwRecordLoginFailure($username), 'Failed login counter could not be persisted.');
}
ngwSecurityAssert(ngwLoginIsThrottled($username), 'Ten failed attempts should trigger the throttle.');
ngwSecurityAssert(ngwLoginIsThrottled('different-username'), 'Changing usernames must not bypass the IP throttle.');
ngwClearLoginFailures($username);
ngwSecurityAssert(!ngwLoginIsThrottled($username), 'Successful-login cleanup did not clear the throttle.');

$_SESSION = array();
session_destroy();
echo "PASS: CSRF token validation and file-backed login throttling\n";
