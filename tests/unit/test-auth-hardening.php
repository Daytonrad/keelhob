<?php
/**
 * S4 / S5 / S7 - authentication hardening.
 *
 *   S4  There was no rate limiting at all, and the Basic path fell back to
 *       wp_authenticate(), which accepts a real account password. Together that
 *       made the MCP route an unlimited password-guessing oracle - and one that
 *       no login-page hardening or 2FA plugin could see, because those hook
 *       wp-login.php and never run for a custom REST route.
 *   S5  The bearer token was stored in wp_options in clear text.
 *   S7  is_local() trusted $_SERVER['HTTP_HOST'], so sending a Host header
 *       ending in .local switched off the HTTPS requirement on a live site.
 */

CP_Test::group('Auth - the password oracle is closed (S4)');

$source = file_get_contents(CLAUDPRESS_DIR . 'includes/class-auth.php');
CP_Test::ok(strpos($source, 'wp_authenticate($username') === false, 'the wp_authenticate() fallback is gone; Application Passwords only');

$record = new ReflectionMethod('Claudpress_Auth', 'record_failure');
$clear  = new ReflectionMethod('Claudpress_Auth', 'clear_failures');

$GLOBALS['cp_test_transients'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

CP_Test::ok(!Claudpress_Auth::is_locked_out(), 'a fresh caller is not locked out');
for ($i = 0; $i < 9; $i++) { $record->invoke(null); }
CP_Test::ok(!Claudpress_Auth::is_locked_out(), 'nine failures are tolerated (fat fingers happen)');
$record->invoke(null);
CP_Test::ok(Claudpress_Auth::is_locked_out(), 'the tenth failure locks that IP out');

$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
CP_Test::ok(!Claudpress_Auth::is_locked_out(), 'an unrelated IP is not punished for it');

for ($i = 0; $i < 95; $i++) {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.' . ($i % 250);
    $record->invoke(null);
}
$_SERVER['REMOTE_ADDR'] = '192.0.2.55';
CP_Test::ok(Claudpress_Auth::is_locked_out(), 'the global counter still catches a spread-out attack from many IPs');

$GLOBALS['cp_test_transients'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
for ($i = 0; $i < 10; $i++) { $record->invoke(null); }
$clear->invoke(null);
CP_Test::ok(!Claudpress_Auth::is_locked_out(), 'a successful login clears that IP');

CP_Test::group('Auth - the bearer token is stored hashed (S5)');

$match = new ReflectionMethod('Claudpress_Auth', 'token_matches');
$plain = 'cp_deadbeefdeadbeefdeadbeef';
$hash  = ['token_hash' => hash('sha256', $plain)];

CP_Test::ok($match->invoke(null, $plain, $hash), 'the right token is accepted');
CP_Test::ok(!$match->invoke(null, 'cp_wrong', $hash), 'a wrong token is refused');
CP_Test::ok(!$match->invoke(null, '', $hash), 'an empty token is refused');
CP_Test::ok(!$match->invoke(null, $plain, []), 'with no credential configured, nothing is accepted');

cp_test_reset_options();
CP_Test::ok($match->invoke(null, $plain, ['token' => $plain]), 'a pre-upgrade plaintext token still works, so existing connections survive');
$after = get_option('claudpress_settings');
CP_Test::same(hash('sha256', $plain), $after['token_hash'] ?? '', '...and is upgraded to a hash on that first use');
CP_Test::same('', $after['token'] ?? 'x', '...with the plaintext copy cleared');

$expired = new ReflectionMethod('Claudpress_Auth', 'token_expired');
CP_Test::ok(!$expired->invoke(null, []), 'no expiry set means the token does not expire');
CP_Test::ok(!$expired->invoke(null, ['token_expires_at' => time() + 3600]), 'a future expiry is still valid');
CP_Test::ok($expired->invoke(null, ['token_expires_at' => time() - 1]), 'a past expiry is refused');

CP_Test::group('Auth - a spoofed Host cannot disable HTTPS (S7)');

$is_local = new ReflectionMethod('Claudpress_Auth', 'is_local');
$_SERVER['HTTP_HOST'] = 'attacker.local';

$GLOBALS['cp_test_home_url'] = 'https://shop.example.com';
CP_Test::ok(!$is_local->invoke(null), 'a live site stays non-local however the Host header is forged');

$GLOBALS['cp_test_home_url'] = 'http://mysite.test';
CP_Test::ok($is_local->invoke(null), 'a genuinely local .test site is recognised');

$GLOBALS['cp_test_home_url'] = 'http://localhost:8080';
CP_Test::ok($is_local->invoke(null), 'localhost with a port is recognised');
