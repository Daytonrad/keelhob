<?php
/**
 * The one-time paid-tier upgrade: eight capability groups are forced OFF.
 *
 * This is the most consequential few lines in the free/paid merge, so it gets a
 * test that states the failure in full rather than a happy path.
 *
 * Until the merge, payments/shipping/tax/orders/users/updates/comms/deploy were
 * unreachable because they sat behind a paywall. Their own on/off toggle was
 * never consulted, so on a real site it can be sitting at "on" and mean
 * nothing. The merge removes the paywall. If the upgrade only set defaults for
 * groups with NO stored value, every one of those sites would silently gain the
 * ability to email its customers, edit its users, change its payment gateways
 * and deploy to production - shipped by an automatic update.
 *
 * So: forced off, regardless of what is stored. If this test ever goes green
 * after being weakened to "only if unset", that is the bug it exists to stop.
 *
 * The second half of this file guards the other direction, which a version
 * check got wrong: the migration must fire ONLY at sites carrying pre-merge
 * paid-tier settings. It is guarded on that evidence, not on a version number,
 * because version numbers get renumbered and evidence does not.
 */

KH_Test::group('Paid-tier upgrade — the paywall was a safety fence');

$upgrade = new ReflectionMethod('Keelhob', 'upgrade_from_paid_tier');
$upgrade->setAccessible(true);

// A site that had every one of them explicitly stored as ENABLED. This is the
// realistic case, not a contrived one: the settings screen wrote these toggles
// whether or not the tier gate ever let them matter.
$stored_on = [];
foreach (Keelhob_Capabilities::DISABLED_BY_DEFAULT as $group) {
    $stored_on[$group] = true;
}
kh_test_reset_options([
    Keelhob::OPTION_SETTINGS => [
        'capabilities'      => array_merge($stored_on, ['content' => true, 'snippets' => true]),
        'disclose_pro'      => true,
        'hide_paid_notices' => false,
        'skills_token'      => 'kh_live_secret',
        'token_hash'        => 'unrelated',
    ],
    'keelhob_pro_licence' => ['key' => 'CP-LIVE-0001', 'status' => 'valid'],
]);

$upgrade->invoke(null);

$after = get_option(Keelhob::OPTION_SETTINGS, []);
$caps  = $after['capabilities'];

foreach (Keelhob_Capabilities::DISABLED_BY_DEFAULT as $group) {
    KH_Test::ok($caps[$group] === false, $group . ' is forced off even though it was stored as ON');
}

// And the groups the build journey needs are left exactly as the owner had them.
KH_Test::ok($caps['content'] === true, 'content is left alone — the upgrade is not a reset button');
KH_Test::ok($caps['snippets'] === true, 'snippets is left alone');
KH_Test::ok(($after['token_hash'] ?? '') === 'unrelated', 'unrelated settings survive untouched');

// Dead keys from a tier that no longer exists.
KH_Test::ok(!array_key_exists('disclose_pro', $after), 'disclose_pro is deleted');
KH_Test::ok(!array_key_exists('hide_paid_notices', $after), 'hide_paid_notices is deleted');
KH_Test::ok(!array_key_exists('skills_token', $after), 'skills_token is deleted');
KH_Test::ok(get_option('keelhob_pro_licence', null) === null, 'the stored licence is deleted');

// The owner must be told. A silent switch-off is its own kind of bug.
$flag = get_option('keelhob_paid_tier_reset', '');
KH_Test::ok($flag !== '' && $flag !== 'none', 'the admin notice is armed, naming what was switched off');
KH_Test::ok(strpos($flag, 'payments') !== false, 'the notice names payments among them');

KH_Test::group('Paid-tier upgrade — it runs once, and not on a new install');

// Running twice must not re-disable something the owner has since turned on.
// An upgrade that fights the owner every time the version option is touched is
// worse than no upgrade at all.
$after['capabilities']['payments'] = true;
update_option(Keelhob::OPTION_SETTINGS, $after);
$upgrade->invoke(null);
$twice = get_option(Keelhob::OPTION_SETTINGS, []);
KH_Test::ok($twice['capabilities']['payments'] === true, 'a second run leaves the owner\'s later choice alone');

// Even if the dead keys somehow came back — a restored backup, a half-finished
// import — the run-once flag still stands between the migration and the
// owner's settings.
$twice['disclose_pro'] = true;
update_option(Keelhob::OPTION_SETTINGS, $twice);
$upgrade->invoke(null);
$thrice = get_option(Keelhob::OPTION_SETTINGS, []);
KH_Test::ok($thrice['capabilities']['payments'] === true, 'the run-once flag holds even if a dead key reappears');

// A fresh install has no paid tier to upgrade from, and default_enabled()
// already covers it.
kh_test_reset_options();
$upgrade->invoke(null);
KH_Test::same([], get_option(Keelhob::OPTION_SETTINGS, []), 'a brand-new install is not touched');
KH_Test::same('', get_option('keelhob_paid_tier_reset', ''), 'and gets no upgrade notice');

KH_Test::group('Paid-tier upgrade — a fresh install that later updates keeps its own choices');

/*
 * The bug this exists to stop, in full.
 *
 * The old guard was `$stored === '' || version_compare($stored, '2.0.0', '>=')`.
 * Renumber the plugin down and that guard inverts:
 *
 *   1. Fresh install. $stored is '', so the migration returned early and the
 *      run-once flag was NEVER written.
 *   2. The owner deliberately switches on a risky group — comms, say.
 *   3. First update. $stored is now a real version BELOW the old milestone, so
 *      the version guard no longer catches it, and the flag is still empty so
 *      neither does the run-once guard.
 *   4. The migration runs and force-disables all eight groups, on a site that
 *      never had a paywall and never had anything to correct.
 *
 * Every user silently loses their capability configuration on their first
 * update. Guarding on the pre-merge evidence instead of on a version number is
 * what makes that impossible, and this asserts it directly: no pre-merge keys
 * means nothing is touched, whatever the version numbers say.
 */

// 1. A fresh install: real settings, none of them from the paid tier.
kh_test_reset_options([
    Keelhob::OPTION_SETTINGS => array_merge(
        Keelhob::default_settings(),
        ['token_hash' => 'fresh-install', 'abilities_enabled' => true]
    ),
    'keelhob_db_version' => '1.0.0',
]);

// 2. The owner deliberately turns on a DISABLED_BY_DEFAULT group.
$settings                            = get_option(Keelhob::OPTION_SETTINGS, []);
$settings['capabilities']['comms']   = true;
$settings['capabilities']['content'] = true;
update_option(Keelhob::OPTION_SETTINGS, $settings);

// 3. The site updates. maybe_upgrade() runs the boot-time migrations with
//    keelhob_db_version still at 1.0.0 — below every old milestone, which is
//    precisely the case the version guard let through. The rest of
//    maybe_upgrade() is table installs that need a real $wpdb, so this drives
//    the one migration under test the same way maybe_upgrade() does.
KH_Test::same(
    0,
    (new ReflectionMethod('Keelhob', 'upgrade_from_paid_tier'))->getNumberOfParameters(),
    'the migration takes no version argument, so no numbering change can reach it'
);
$upgrade->invoke(null);

// 4. Nothing the owner chose may have moved.
$post = get_option(Keelhob::OPTION_SETTINGS, []);
KH_Test::ok(
    ($post['capabilities']['comms'] ?? null) === true,
    'a group the owner deliberately switched ON is STILL on after an update'
);
KH_Test::ok(($post['capabilities']['content'] ?? null) === true, 'and so is every other capability choice');
KH_Test::ok(($post['token_hash'] ?? '') === 'fresh-install', 'unrelated settings are untouched by the update');
KH_Test::same(
    '',
    get_option('keelhob_paid_tier_reset', ''),
    'and no upgrade notice is armed — there was nothing to switch off'
);

KH_Test::group('Paid-tier upgrade — driven through the real maybe_upgrade()');

/*
 * Everything above calls the private upgrade_from_paid_tier() directly, which
 * proves the migration but not the wiring: maybe_upgrade() is what actually
 * runs on a live site, and until now it could not be called here at all.
 *
 * It fatalled for two reasons, both in the harness rather than the plugin.
 * Each install_table() does the WordPress-standard `require_once ABSPATH .
 * 'wp-admin/includes/upgrade.php'` before calling dbDelta(), and neither the
 * file nor the function existed in the fake WordPress — a missing require is a
 * fatal, so a dbDelta() stub alone would not have been enough. On top of that,
 * test-uninstall-preserves.php sorts before this file and used to leave its
 * minimal KH_Fake_Wpdb in $GLOBALS['wpdb'], so get_charset_collate() was gone
 * by the time this file ran. Both are fixed; this is the test that was waiting
 * for them.
 */

kh_test_reset_options([
    Keelhob::OPTION_SETTINGS => array_merge(
        Keelhob::default_settings(),
        ['token_hash' => 'real-upgrade', 'abilities_enabled' => true]
    ),
    'keelhob_db_version' => '1.0.0',
]);

// The owner deliberately switches on a risky group.
$live                          = get_option(Keelhob::OPTION_SETTINGS, []);
$live['capabilities']['comms'] = true;
update_option(Keelhob::OPTION_SETTINGS, $live);

kh_test_reset_dbdelta();

$boot_upgrade = new ReflectionMethod('Keelhob', 'maybe_upgrade');
@$boot_upgrade->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor
$boot_upgrade->invoke(Keelhob::instance());

$live_after = get_option(Keelhob::OPTION_SETTINGS, []);

KH_Test::ok(
    ($live_after['capabilities']['comms'] ?? null) === true,
    'comms survives a real maybe_upgrade() run, not just a direct call to the migration'
);
KH_Test::same(
    '',
    get_option('keelhob_paid_tier_reset', ''),
    'and maybe_upgrade() arms no upgrade notice on a site with no paid-tier evidence'
);
KH_Test::same(
    KEELHOB_VERSION,
    get_option('keelhob_db_version', ''),
    'maybe_upgrade() records the version it upgraded to, so it does not run again next request'
);

// The table installs really ran; they were not skipped past on the way to the
// migration. Without this the test above would still pass if maybe_upgrade()
// silently returned early.
$schema = implode("\n", $GLOBALS['kh_test_dbdelta']);
KH_Test::contains('wp_keelhob_memory', $schema, 'the memory table is installed on the way through');
KH_Test::contains('utf8mb4', $schema, 'and the CREATE TABLE carries the charset from $wpdb->get_charset_collate()');
