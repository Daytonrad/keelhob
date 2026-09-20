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

KH_Test::group('Auth - the password oracle is closed (S4)');

$source = file_get_contents(KEELHOB_DIR . 'includes/class-auth.php');
KH_Test::ok(strpos($source, 'wp_authenticate($username') === false, 'the wp_authenticate() fallback is gone; Application Passwords only');

$record = new ReflectionMethod('Keelhob_Auth', 'record_failure');
@$record->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor
$clear  = new ReflectionMethod('Keelhob_Auth', 'clear_failures');
@$clear->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor

$GLOBALS['kh_test_transients'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

KH_Test::ok(!Keelhob_Auth::is_locked_out(), 'a fresh caller is not locked out');
for ($i = 0; $i < 9; $i++) { $record->invoke(null); }
KH_Test::ok(!Keelhob_Auth::is_locked_out(), 'nine failures are tolerated (fat fingers happen)');
$record->invoke(null);
KH_Test::ok(Keelhob_Auth::is_locked_out(), 'the tenth failure locks that IP out');

$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
KH_Test::ok(!Keelhob_Auth::is_locked_out(), 'an unrelated IP is not punished for it');

for ($i = 0; $i < 95; $i++) {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.' . ($i % 250);
    $record->invoke(null);
}
$_SERVER['REMOTE_ADDR'] = '192.0.2.55';
KH_Test::ok(Keelhob_Auth::is_locked_out(), 'the global counter still catches a spread-out attack from many IPs');

// The global lock used to be a flat tally of failures from anybody, so one
// machine sending a few hundred bad requests locked the real owner out for
// fifteen minutes — a denial of service that cost the attacker seconds. It
// counts distinct addresses now, so a single source can only ever lock itself.
$GLOBALS['kh_test_transients'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.200';
for ($i = 0; $i < 500; $i++) { $record->invoke(null); }
KH_Test::ok(Keelhob_Auth::is_locked_out(), 'the flooding IP locks itself out');
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
KH_Test::ok(!Keelhob_Auth::is_locked_out(), 'one flooding IP can no longer lock everybody else out');

// Twenty-five different people each mistyping a token is not an attack, but a
// success from one of them must take that address back out of the set.
$GLOBALS['kh_test_transients'] = [];
for ($i = 0; $i < 25; $i++) {
    $_SERVER['REMOTE_ADDR'] = '172.16.0.' . $i;
    $record->invoke(null);
}
KH_Test::ok(Keelhob_Auth::is_locked_out(), 'twenty-five failing addresses do trip the global lock');
$clear->invoke(null);
KH_Test::ok(!Keelhob_Auth::is_locked_out(), 'a success removes that address from the set, releasing the lock');

$GLOBALS['kh_test_transients'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
for ($i = 0; $i < 10; $i++) { $record->invoke(null); }
$clear->invoke(null);
KH_Test::ok(!Keelhob_Auth::is_locked_out(), 'a successful login clears that IP');

KH_Test::group('Auth - the bearer token is stored hashed (S5)');

$match = new ReflectionMethod('Keelhob_Auth', 'token_matches');
@$match->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor
$plain = 'kh_deadbeefdeadbeefdeadbeef';
$hash  = ['token_hash' => hash('sha256', $plain)];

KH_Test::ok($match->invoke(null, $plain, $hash), 'the right token is accepted');
KH_Test::ok(!$match->invoke(null, 'kh_wrong', $hash), 'a wrong token is refused');
KH_Test::ok(!$match->invoke(null, '', $hash), 'an empty token is refused');
KH_Test::ok(!$match->invoke(null, $plain, []), 'with no credential configured, nothing is accepted');

kh_test_reset_options();
KH_Test::ok($match->invoke(null, $plain, ['token' => $plain]), 'a pre-upgrade plaintext token still works, so existing connections survive');
$after = get_option('keelhob_settings');
KH_Test::same(hash('sha256', $plain), $after['token_hash'] ?? '', '...and is upgraded to a hash on that first use');
KH_Test::same('', $after['token'] ?? 'x', '...with the plaintext copy cleared');

$expired = new ReflectionMethod('Keelhob_Auth', 'token_expired');
@$expired->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor
KH_Test::ok(!$expired->invoke(null, []), 'no expiry set means the token does not expire');
KH_Test::ok(!$expired->invoke(null, ['token_expires_at' => time() + 3600]), 'a future expiry is still valid');
KH_Test::ok($expired->invoke(null, ['token_expires_at' => time() - 1]), 'a past expiry is refused');

KH_Test::group('Auth - a spoofed Host cannot disable HTTPS (S7)');

$is_local = new ReflectionMethod('Keelhob_Auth', 'is_local');
@$is_local->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor
$_SERVER['HTTP_HOST'] = 'attacker.local';

$GLOBALS['kh_test_home_url'] = 'https://shop.example.com';
KH_Test::ok(!$is_local->invoke(null), 'a live site stays non-local however the Host header is forged');

$GLOBALS['kh_test_home_url'] = 'http://mysite.test';
KH_Test::ok($is_local->invoke(null), 'a genuinely local .test site is recognised');

$GLOBALS['kh_test_home_url'] = 'http://localhost:8080';
KH_Test::ok($is_local->invoke(null), 'localhost with a port is recognised');

KH_Test::group('Auth - a new token clears the lock');

/*
 * THE TRAP THIS CLOSES, hit by the plugin's own author against a live site.
 *
 * is_locked_out() is checked BEFORE any credential work, so a brand-new,
 * perfectly valid token still gets 429 and regenerating appears to do nothing.
 * Worse, a client still holding the OLD token re-locks the site the instant the
 * window opens — so the wait never ends, and the only visible symptom is
 * "connection failed" forever. An hour was lost to it.
 */
$GLOBALS['kh_test_transients'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
for ($i = 0; $i < 10; $i++) { $record->invoke(null); }
KH_Test::ok(Keelhob_Auth::is_locked_out(), 'the caller is locked out to begin with');

Keelhob_Auth::unlock();
KH_Test::ok(!Keelhob_Auth::is_locked_out(), 'unlock() lifts it');

// EVERY address, not just the caller's. The lock is keyed on the MCP CLIENT's
// IP; unlock() runs from wp-admin, where the browser is usually a different
// address. Clearing only "my" IP would leave the client locked and the owner
// staring at a button that did nothing.
$GLOBALS['kh_test_transients'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
for ($i = 0; $i < 10; $i++) { $record->invoke(null); }
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';   // the admin's browser, not the client
Keelhob_Auth::unlock();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';    // back to the client
KH_Test::ok(!Keelhob_Auth::is_locked_out(), 'unlocking from a DIFFERENT address still frees the locked client');

// The wiring: minting a token must call it. Asserted from the source because
// set_token() writes options a unit run has no database for.
$kh_src = (string) file_get_contents(KEELHOB_DIR . 'includes/class-keelhob.php');
$kh_mint = substr($kh_src, strpos($kh_src, 'function set_token'), 1200);
KH_Test::contains('Keelhob_Auth::unlock()', $kh_mint, 'set_token() clears the lock, so regenerating actually helps');

KH_Test::group('Auth - the lockout message says how long');

// "Try again in a few minutes" is what sent somebody regenerating tokens and
// restarting clients for an hour: nothing on screen separated "wait" from
// "still broken".
KH_Test::ok(Keelhob_Auth::lock_seconds_left() > 0, 'a remaining time is reported');
KH_Test::ok(Keelhob_Auth::lock_seconds_left() <= Keelhob_Auth::LOCKOUT_WINDOW, 'and it never exceeds the window');

$auth_src = (string) file_get_contents(KEELHOB_DIR . 'includes/class-auth.php');
KH_Test::ok(strpos($auth_src, 'Try again in a few minutes') === false, 'the vague message is gone');
KH_Test::contains('retry_after', $auth_src, 'and the 429 carries a machine-readable retry hint');
KH_Test::contains('Keelhob > Connect clears this', $auth_src, 'and the message names the way out');
