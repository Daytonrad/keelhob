<?php
/**
 * The Pro add-on: the licence gate, and what it does and does not protect.
 *
 * Read the tiering comment in includes/class-capabilities.php before changing
 * anything here. `is_pro()` is a user-experience gate, not a security boundary:
 * the free plugin ships execute_php, so a connected agent can flip any option
 * this code reads. What actually separates the tiers is that the Pro code is in
 * a zip a free user never receives, and that the features worth paying for are
 * answered by our server.
 *
 * So these tests are about the gate behaving predictably and generously, not
 * about it being unpickable.
 */

CP_Test::group('Pro add-on — a plain install is free');

cp_test_reset_options();
cp_test_reset_filters();

CP_Test::ok(!Claudpress_Capabilities::is_pro(), 'with nothing hooked, the site is free');
CP_Test::ok(count(Claudpress_Capabilities::unavailable_groups()) > 0, 'and Pro groups are reported as unavailable');

CP_Test::group('Pro add-on — the licence decision');

$decide = ['Claudpress_Pro_Licence', 'decide'];
$now    = 1000000000;
$day    = 86400;

$valid = [
    'key'              => 'CP-LIVE-0001',
    'status'           => 'valid',
    'expires_at'       => $now + (365 * $day),
    'last_verified_at' => $now - 60,
];

CP_Test::ok(call_user_func($decide, $valid, $now), 'a freshly verified licence is valid');

$no_key = $valid;
$no_key['key'] = '';
CP_Test::ok(!call_user_func($decide, $no_key, $now), 'no key means no Pro');

$refused = $valid;
$refused['status'] = 'cancelled';
CP_Test::ok(!call_user_func($decide, $refused, $now), 'a cancelled licence is refused immediately — grace covers silence, not a "no"');

$never = $valid;
$never['last_verified_at'] = 0;
CP_Test::ok(!call_user_func($decide, $never, $now), 'a licence never successfully verified gets no grace: there is nothing to be generous about');

CP_Test::group('Pro add-on — grace covers an outage, not an expiry');

// The case this exists for: our licence server has a bad week while the
// customer is on holiday. Their paid features must not evaporate.
$quiet = $valid;
$quiet['last_verified_at'] = $now - (7 * $day);
CP_Test::ok(call_user_func($decide, $quiet, $now), 'a week of silence from the server does not cost the customer their features');

$edge = $valid;
$edge['last_verified_at'] = $now - Claudpress_Pro_Licence::GRACE_SECONDS + 60;
CP_Test::ok(call_user_func($decide, $edge, $now), 'still valid just inside the grace window');

$past = $valid;
$past['last_verified_at'] = $now - Claudpress_Pro_Licence::GRACE_SECONDS - 60;
CP_Test::ok(!call_user_func($decide, $past, $now), 'past the grace window we stop assuming');

CP_Test::same(14 * $day, Claudpress_Pro_Licence::GRACE_SECONDS, 'the grace window is 14 days');

// And the other direction: an outage must never become free Pro. The expiry
// date came from the server, so it is honoured even when the server is silent.
$expired = [
    'key'              => 'CP-LIVE-0001',
    'status'           => 'valid',
    'expires_at'       => $now - $day,
    'last_verified_at' => $now - 60,
];
CP_Test::ok(!call_user_func($decide, $expired, $now), 'a subscription that has run out has run out, however recently we checked');

$expired_and_quiet = $expired;
$expired_and_quiet['last_verified_at'] = $now - (2 * $day);
CP_Test::ok(!call_user_func($decide, $expired_and_quiet, $now), 'grace does not extend an expiry — that would turn an outage into free Pro');

$no_expiry = $valid;
$no_expiry['expires_at'] = 0;
CP_Test::ok(call_user_func($decide, $no_expiry, $now), 'a licence with no end date does not expire');

CP_Test::group('Pro add-on — turning it on unlocks the Pro groups');

add_filter('claudpress_is_pro', static function () { return true; });

CP_Test::ok(Claudpress_Capabilities::is_pro(), 'the filter is what switches the tier');
CP_Test::same([], Claudpress_Capabilities::unavailable_groups(), 'nothing is reported missing');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('run_agent'), 'a Pro tool becomes available');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('deploy_push'), 'and so does deploy');

cp_test_reset_filters();
CP_Test::ok(!Claudpress_Capabilities::tool_allowed('run_agent'), 'removing it locks them again');

CP_Test::group('Pro add-on — the free zip must not contain Pro code');

// If Pro code ever ships inside the free zip, the entire reason for having two
// zips is void: a free user could simply switch it on. The build script is what
// enforces that, so a guard that can be quietly deleted is not a guard.
$build = '';
foreach (glob(CLAUDPRESS_DIR . 'bin/build*.sh') as $script) {
    $build .= file_get_contents($script);
}

CP_Test::ok($build !== '', 'a build script exists');
CP_Test::contains('Claudpress_Pro_', $build, 'the build checks the free zip for the Pro class prefix');
CP_Test::contains('claudpress-pro', $build, 'the build checks the free zip for Pro files');
CP_Test::ok(
    stripos($build, 'exit 1') !== false,
    'and a contaminated free zip fails the build rather than warning about it'
);

cp_test_reset_options();
cp_test_reset_filters();
