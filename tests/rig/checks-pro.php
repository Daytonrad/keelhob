<?php
/**
 * Live verification of the Pro add-on, against a real WordPress with BOTH
 * plugins active.
 *
 * checks.php proves Pro tools are gone from a free install. This proves the
 * other half — that they come back when licensed, under their original names,
 * and that an invalid licence really does withhold them. A split that only ever
 * gets tested in the "off" direction is half tested.
 *
 * Run: wp eval-file tests/rig/checks-pro.php
 */

$GLOBALS['cp_rig_pass'] = 0;
$GLOBALS['cp_rig_fail'] = 0;
function check($label, $ok, $detail = '') {
    $ok ? $GLOBALS['cp_rig_pass']++ : $GLOBALS['cp_rig_fail']++;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', str_pad($label, 50), $detail);
}

check('the add-on is active', class_exists('Claudpress_Pro_Licence'));
check('its tool registrar loaded', class_exists('Claudpress_Pro_Tools'));

// The moved classes must exist HERE and not in the free plugin.
foreach ([
    'Claudpress_Tools_Deploy',
    'Claudpress_Tools_Payments',
    'Claudpress_Tools_Ops_Pro',
    'Claudpress_Tools_Automation_Pro',
    'Claudpress_Tools_Woocommerce_Pro',
    'Claudpress_Tools_Seo_Pro',
] as $class) {
    check('  ' . $class . ' loaded from the add-on', class_exists($class));
}

// ---------------------------------------------------------- licence off ----
add_filter('claudpress_pro_licence_override', static function () { return false; }, 99);
delete_transient(Claudpress_Pro_Licence::TRANSIENT);

check('an invalid licence means not Pro', !Claudpress_Capabilities::is_pro());

$registry_off = new Claudpress_Tool_Registry();
do_action('claudpress_register_tools', $registry_off);
$off = $registry_off->names();

check('  no Pro tools register without a licence', count($off) === 0, count($off) . ' registered');

remove_all_filters('claudpress_pro_licence_override');
delete_transient(Claudpress_Pro_Licence::TRANSIENT);

// ----------------------------------------------------------- licence on ----
add_filter('claudpress_pro_licence_override', static function () { return true; }, 99);
delete_transient(Claudpress_Pro_Licence::TRANSIENT);

check('a valid licence means Pro', Claudpress_Capabilities::is_pro());
check('  nothing is reported unavailable', Claudpress_Capabilities::unavailable_groups() === []);

$registry_on = new Claudpress_Tool_Registry();
do_action('claudpress_register_tools', $registry_on);
$on = $registry_on->names();

// Every Pro tool must come back, under its ORIGINAL name. An agent that worked
// against a Pro site before the split has to see no difference.
// wc_* tools register only when WooCommerce is active — in both the free and
// the Pro classes. Asserting them unconditionally would fail for a reason that
// has nothing to do with licensing.
$expected = [
    'deploy_push', 'export_bundle', 'import_bundle',
    'manage_updates', 'send_email',
    'task_save', 'task_run', 'task_delete', 'run_agent', 'notify_owner',
    'seo_analyze', 'seo_redirect',
];
if (class_exists('WooCommerce')) {
    $expected = array_merge($expected, ['wc_payments', 'wc_shipping', 'wc_tax', 'wc_order', 'wc_report']);
} else {
    echo "NOTE  WooCommerce is not installed, so wc_* tools are skipped." . PHP_EOL;
    check('wc_order does NOT register without WooCommerce', !in_array('wc_order', $on, true));
    check('wc_report does NOT register without WooCommerce', !in_array('wc_report', $on, true));
}
$missing = array_values(array_diff($expected, $on));
check('every Pro tool returns with the add-on', $missing === [], $missing ? 'missing: ' . implode(', ', $missing) : count($on) . ' registered');

foreach ($expected as $tool) {
    check('  ' . $tool . ' allowed when licensed', Claudpress_Capabilities::tool_allowed($tool));
}

// Names must not have drifted — a renamed tool is a broken integration.
foreach ($on as $name) {
    check('  ' . $name . ' has no Pro-ish suffix', strpos($name, '_pro') === false && strpos($name, 'pro_') !== 0 || $name === 'pro_status');
}

remove_all_filters('claudpress_pro_licence_override');
delete_transient(Claudpress_Pro_Licence::TRANSIENT);

printf("\n%d passed, %d failed\n", $GLOBALS['cp_rig_pass'], $GLOBALS['cp_rig_fail']);
if ($GLOBALS['cp_rig_fail'] > 0) {
    exit(1);
}
