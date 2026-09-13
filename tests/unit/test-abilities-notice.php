<?php
/**
 * The "AI abilities are ON" banner, and the three rules that make hiding it safe.
 *
 * This banner is a safety warning. Letting somebody switch a safety warning off
 * is normally a bad idea, and it is allowed here for exactly one reason: there
 * is a second indicator that cannot be switched off. Claudpress_Admin::admin_bar()
 * puts `Claudpress: ON` in the WordPress toolbar, and that is strictly the better
 * warning — always present, never dismissible, on screens the banner never reaches.
 *
 * That was measured, not assumed. On the rig, a live snippet running
 * add_filter('show_admin_bar', '__return_false') removed the toolbar on the front
 * end and left it fully intact inside wp-admin, because WordPress forces it there.
 * The banner only ever appears inside wp-admin, so the chip is guaranteed exactly
 * where the banner lives.
 *
 * Everything below protects that argument. If any of it stops being true, the
 * dismissal stops being defensible and should be removed rather than patched.
 */

CP_Test::group('Abilities banner — the dismissal is per user, never per site');

// A site-wide option would mean one administrator hiding the warning from a
// colleague who has never seen it and does not know an agent is attached.
$src = php_strip_whitespace(CLAUDPRESS_DIR . 'includes/class-admin.php');
CP_Test::contains('update_user_meta', $src, 'the dismissal is stored in user meta');
CP_Test::ok(
    strpos($src, "update_option('claudpress_hide_abilities") === false,
    'and never in a site option, which would hide it from everyone'
);
CP_Test::same(
    'claudpress_hide_abilities_notice',
    Claudpress_Admin::HIDE_NOTICE_META,
    'the meta key is stable, so an update does not silently un-hide or re-hide'
);

CP_Test::group('Abilities banner — it comes back when the risk changes');

/*
 * The fingerprint is the whole mechanism. A dismissal is not "never show me
 * this again", it is "I have read this, about THIS situation". Change the
 * situation and the warning is owed again.
 */
$cp = Claudpress::instance();

$cp->update(['abilities_enabled' => true]);
$on = Claudpress_Admin::notice_fingerprint();
CP_Test::ok($on !== '', 'a fingerprint is produced');
CP_Test::same($on, Claudpress_Admin::notice_fingerprint(), 'and it is stable while nothing changes');

$cp->update(['abilities_enabled' => false]);
$off = Claudpress_Admin::notice_fingerprint();
CP_Test::ok($on !== $off, 'switching abilities off changes it');

/*
 * THE FINGERPRINT ALONE IS NOT ENOUGH, and this assertion used to claim the
 * opposite. It read:
 *
 *   'and switching them back on returns to the first value — so an off/on
 *    cycle re-warns anyone who dismissed'
 *
 * which is the exact reverse of what the same value means. A dismissal recorded
 * before the cycle still matches afterwards, so the banner stayed hidden through
 * precisely the event it most needs to reappear for. The test passed. It
 * described the bug as if it were the feature.
 *
 * The browser layer caught it — check-snippets.mjs toggles abilities for real
 * and then looks at the page.
 *
 * The fix is that the clearing is driven by the CHANGE rather than the
 * resulting state; see rewarn_on_change(). So the hash returning to its old
 * value is correct and harmless, and is asserted here as a fact about the hash
 * rather than as a safety property it cannot provide.
 */
$cp->update(['abilities_enabled' => true]);
CP_Test::same(
    $on,
    Claudpress_Admin::notice_fingerprint(),
    'the hash is a function of state, so an off/on cycle returns to its old value — which is why it cannot be what re-warns'
);

CP_Test::group('Abilities banner — a change of powers clears every dismissal');

// Same settings in and out: nothing changed, so nothing is cleared. Without
// this, any unrelated save would nag every administrator on the site.
$settings = ['abilities_enabled' => true, 'capabilities' => ['content' => true]];
update_user_meta(1, Claudpress_Admin::HIDE_NOTICE_META, 'marker');
Claudpress_Admin::rewarn_on_change($settings, $settings);
CP_Test::same('marker', get_user_meta(1, Claudpress_Admin::HIDE_NOTICE_META, true), 'an unrelated save leaves dismissals alone');

// Abilities switched off: a real change, so it clears.
update_user_meta(1, Claudpress_Admin::HIDE_NOTICE_META, 'marker');
Claudpress_Admin::rewarn_on_change($settings, array_merge($settings, ['abilities_enabled' => false]));
CP_Test::same('', get_user_meta(1, Claudpress_Admin::HIDE_NOTICE_META, true), 'switching abilities off clears them');

// A widened capability group: also a real change. Somebody who dismissed the
// warning when only Content was on has not agreed to Payments.
update_user_meta(1, Claudpress_Admin::HIDE_NOTICE_META, 'marker');
Claudpress_Admin::rewarn_on_change($settings, ['abilities_enabled' => true, 'capabilities' => ['content' => true, 'payments' => true]]);
CP_Test::same('', get_user_meta(1, Claudpress_Admin::HIDE_NOTICE_META, true), 'enabling a capability group clears them');

// Key order must not count as a change, or every save looks like one.
update_user_meta(1, Claudpress_Admin::HIDE_NOTICE_META, 'marker');
Claudpress_Admin::rewarn_on_change(
    ['abilities_enabled' => true, 'capabilities' => ['store' => true, 'content' => true]],
    ['abilities_enabled' => true, 'capabilities' => ['content' => true, 'store' => true]]
);
CP_Test::same('marker', get_user_meta(1, Claudpress_Admin::HIDE_NOTICE_META, true), 'reordered keys are not a change');

delete_user_meta(1, Claudpress_Admin::HIDE_NOTICE_META);

// Enabling a capability group widens what an agent may do. Somebody who
// dismissed the warning when only Content was on has not agreed to Payments.
$groups = array_keys(Claudpress_Capabilities::groups());
$victim = 'payments';
CP_Test::ok(in_array($victim, $groups, true), 'the payments group exists to test against');

$before = Claudpress_Admin::notice_fingerprint();
$caps   = (array) $cp->get('capabilities', []);
$cp->update(['capabilities' => array_merge($caps, [$victim => !Claudpress_Capabilities::is_group_enabled($victim)])]);
CP_Test::ok(
    $before !== Claudpress_Admin::notice_fingerprint(),
    'changing a capability group changes it, so a dismissal does not carry over to wider powers'
);
$cp->update(['capabilities' => $caps]);
CP_Test::same($before, Claudpress_Admin::notice_fingerprint(), 'and restoring the groups restores the fingerprint');

// A version bump can change what the rules mean. Cheap to include, and the
// alternative is a dismissal from 2026 silencing a warning about a feature
// added in 2027.
CP_Test::contains(
    'CLAUDPRESS_VERSION',
    (string) file_get_contents(CLAUDPRESS_DIR . 'includes/class-admin.php'),
    'the plugin version is part of the fingerprint'
);

CP_Test::group('Abilities banner — the toolbar chip is never hideable');

/*
 * If this ever stops being true, the dismissal must go with it. There is no
 * version of this feature where both indicators can be switched off.
 */
$admin = new Claudpress_Admin();
$bar   = new CP_Test_Admin_Bar();
$cp->update(['abilities_enabled' => true]);
$admin->admin_bar($bar);

CP_Test::ok(isset($bar->nodes['claudpress']), 'the chip is registered while abilities are on');
CP_Test::contains('ON', (string) ($bar->nodes['claudpress']['title'] ?? ''), 'and it says ON');

$cp->update(['abilities_enabled' => false]);
$bar2 = new CP_Test_Admin_Bar();
$admin->admin_bar($bar2);
CP_Test::contains('OFF', (string) ($bar2->nodes['claudpress']['title'] ?? ''), 'and OFF when they are off');

// There must be no setting, filter or meta key that suppresses the chip.
CP_Test::ok(
    strpos($src, 'hide_admin_bar') === false && strpos($src, 'remove_node') === false,
    'nothing in the admin class can remove the chip'
);

CP_Test::group('Abilities banner — dismissal is only honoured while the chip renders');

/*
 * The hole the rig measurement left open: show_admin_bar cannot remove the
 * toolbar inside wp-admin, but a snippet CAN call remove_node('claudpress'),
 * and admin CSS can hide #wpadminbar. Either way the unhideable warning is gone,
 * and with it the reason this one may be hidden.
 */
CP_Test::contains('chip_present', $src, 'the notice checks the chip is really there');
CP_Test::contains('is_admin_bar_showing', $src, 'including whether the bar is showing at all');
CP_Test::contains("get_node('claudpress')", $src, 'and whether our specific node survived');

$cp->update(['abilities_enabled' => false]);

CP_Test::group('Abilities banner — the re-warn fires however abilities are changed');

/*
 * This was registered inside Claudpress_Admin::hooks(), which only runs behind
 * is_admin(). Abilities are switched on over WP-CLI, over the REST route and by
 * agents through manage_option at least as often as through the settings
 * screen, and for every one of those the dismissal stayed put. A safety warning
 * that only re-arms for one caller is not a safety warning.
 *
 * Found by the browser checks, which toggle abilities through WP-CLI and then
 * looked at the page.
 */
$boot = php_strip_whitespace(CLAUDPRESS_DIR . 'includes/class-claudpress.php');
CP_Test::contains(
    "add_action('update_option_'.self::OPTION_SETTINGS,['Claudpress_Admin','rewarn_on_change']",
    preg_replace('/\s+/', '', $boot),
    'the re-warn is registered from boot(), for every request type'
);
$admin_src = preg_replace('/\s+/', '', php_strip_whitespace(CLAUDPRESS_DIR . 'includes/class-admin.php'));
CP_Test::ok(
    strpos($admin_src, "add_action('update_option_'") === false,
    'and not from hooks(), which is admin-only'
);
