<?php
/**
 * The 2.0.0 upgrade: eight capability groups are forced OFF.
 *
 * This is the most consequential few lines in the 2.0.0 release, so it gets a
 * test that states the failure in full rather than a happy path.
 *
 * Until 2.0.0, payments/shipping/tax/orders/users/updates/comms/deploy were
 * unreachable because they sat behind a paywall. Their own on/off toggle was
 * never consulted, so on a real site it can be sitting at "on" and mean
 * nothing. 2.0.0 removes the paywall. If the upgrade only set defaults for
 * groups with NO stored value, every one of those sites would silently gain the
 * ability to email its customers, edit its users, change its payment gateways
 * and deploy to production - shipped by an automatic update.
 *
 * So: forced off, regardless of what is stored. If this test ever goes green
 * after being weakened to "only if unset", that is the bug it exists to stop.
 */

CP_Test::group('Upgrade to 2.0.0 — the paywall was a safety fence');

$upgrade = new ReflectionMethod('Claudpress', 'upgrade_to_2_0_0');
$upgrade->setAccessible(true);

// A site that had every one of them explicitly stored as ENABLED. This is the
// realistic case, not a contrived one: the settings screen wrote these toggles
// whether or not the tier gate ever let them matter.
$stored_on = [];
foreach (Claudpress_Capabilities::DISABLED_BY_DEFAULT as $group) {
    $stored_on[$group] = true;
}
cp_test_reset_options([
    Claudpress::OPTION_SETTINGS => [
        'capabilities'      => array_merge($stored_on, ['content' => true, 'snippets' => true]),
        'disclose_pro'      => true,
        'hide_paid_notices' => false,
        'skills_token'      => 'cp_live_secret',
        'token_hash'        => 'unrelated',
    ],
    'claudpress_pro_licence' => ['key' => 'CP-LIVE-0001', 'status' => 'valid'],
]);

$upgrade->invoke(null, '1.10.1');

$after = get_option(Claudpress::OPTION_SETTINGS, []);
$caps  = $after['capabilities'];

foreach (Claudpress_Capabilities::DISABLED_BY_DEFAULT as $group) {
    CP_Test::ok($caps[$group] === false, $group . ' is forced off even though it was stored as ON');
}

// And the groups the build journey needs are left exactly as the owner had them.
CP_Test::ok($caps['content'] === true, 'content is left alone — the upgrade is not a reset button');
CP_Test::ok($caps['snippets'] === true, 'snippets is left alone');
CP_Test::ok(($after['token_hash'] ?? '') === 'unrelated', 'unrelated settings survive untouched');

// Dead keys from a tier that no longer exists.
CP_Test::ok(!array_key_exists('disclose_pro', $after), 'disclose_pro is deleted');
CP_Test::ok(!array_key_exists('hide_paid_notices', $after), 'hide_paid_notices is deleted');
CP_Test::ok(!array_key_exists('skills_token', $after), 'skills_token is deleted');
CP_Test::ok(get_option('claudpress_pro_licence', null) === null, 'the stored licence is deleted');

// The owner must be told. A silent switch-off is its own kind of bug.
$flag = get_option('claudpress_2_0_0_capabilities_reset', '');
CP_Test::ok($flag !== '' && $flag !== 'none', 'the admin notice is armed, naming what was switched off');
CP_Test::ok(strpos($flag, 'payments') !== false, 'the notice names payments among them');

CP_Test::group('Upgrade to 2.0.0 — it runs once, and not on a new install');

// Running twice must not re-disable something the owner has since turned on.
// An upgrade that fights the owner every time the version option is touched is
// worse than no upgrade at all.
$after['capabilities']['payments'] = true;
update_option(Claudpress::OPTION_SETTINGS, $after);
$upgrade->invoke(null, '1.10.1');
$twice = get_option(Claudpress::OPTION_SETTINGS, []);
CP_Test::ok($twice['capabilities']['payments'] === true, 'a second run leaves the owner\'s later choice alone');

// A fresh install has no old version to upgrade from, and default_enabled()
// already covers it.
cp_test_reset_options();
$upgrade->invoke(null, '');
CP_Test::same([], get_option(Claudpress::OPTION_SETTINGS, []), 'a brand-new install is not touched');
CP_Test::same('', get_option('claudpress_2_0_0_capabilities_reset', ''), 'and gets no upgrade notice');
