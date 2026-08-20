<?php
/**
 * Tiering - which capability groups this install may use at all.
 *
 * Two gates sit in front of every tool and they answer different questions:
 * the TIER asks whether this install has the feature, the owner's TOGGLE asks
 * whether they want it switched on. A Pro tool on a free site fails the first
 * regardless of the second.
 *
 * Note what is deliberately FREE here: execute_php, execute_sql, the filesystem
 * tools and the snippet engine. The direct competitor gives all of those away,
 * so charging for them would lose every feature comparison - and gating
 * execute_php while snippet_save stayed free never made sense anyway, because a
 * snippet is arbitrary PHP wearing a different hat.
 */

CP_Test::group('Tiers - the split itself');

$groups = Claudpress_Capabilities::groups();

CP_Test::ok(!isset($groups['code']), 'the overloaded "code" group is gone');
CP_Test::ok(isset($groups['store_orders']), 'the money side of the store is its own group');
CP_Test::same(['wc_product', 'wc_coupon', 'wc_settings'], $groups['store']['tools'], 'store holds only the catalogue tools the demo needs');
CP_Test::ok(isset($groups['snippets']), 'snippets is its own group');
CP_Test::ok(isset($groups['code_exec']), 'raw execution is its own group');
CP_Test::same(['snippet_save', 'snippet_list', 'snippet_get', 'snippet_toggle', 'snippet_delete'], $groups['snippets']['tools'], 'snippets holds only the snippet tools');
CP_Test::same(['execute_php', 'execute_sql'], $groups['code_exec']['tools'], 'code_exec holds only raw execution');

foreach ($groups as $key => $group) {
    CP_Test::ok(isset($group['tier']), $key . ' declares a tier (an untagged group would silently default to free)');
}

$expect_free = ['content', 'store', 'design', 'seo', 'images', 'site_admin', 'memory', 'skills', 'files', 'snippets', 'code_exec'];
foreach ($expect_free as $key) {
    CP_Test::same('free', Claudpress_Capabilities::group_tier($key), $key . ' is free (the build must work with no licence)');
}

$expect_pro = ['store_orders', 'seo_optimize', 'payments', 'shipping', 'tax', 'users', 'updates', 'comms', 'automation', 'deploy'];
foreach ($expect_pro as $key) {
    CP_Test::same('pro', Claudpress_Capabilities::group_tier($key), $key . ' is Pro');
}

CP_Test::group('Tiers - the gate on a free install');

cp_test_reset_options();
cp_test_reset_filters();

CP_Test::ok(!Claudpress_Capabilities::is_pro(), 'with no add-on present, nothing hooks the filter and the site is free');

CP_Test::ok(Claudpress_Capabilities::tool_allowed('create_post'), 'a free tool works');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('execute_php'), 'execute_php is free');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('snippet_save'), 'the agent can still write snippets on a free site');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('write_file'), 'the filesystem tools are free');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('wc_product'), 'building the store is free');

CP_Test::ok(!Claudpress_Capabilities::tool_allowed('wc_order'), 'reading orders is Pro (you only have orders once you are earning)');
CP_Test::ok(!Claudpress_Capabilities::tool_allowed('wc_payments'), 'checkout configuration is Pro');
CP_Test::ok(!Claudpress_Capabilities::tool_allowed('run_agent'), 'autonomy is Pro');
CP_Test::ok(!Claudpress_Capabilities::tool_allowed('deploy_push'), 'staging to production is Pro');
CP_Test::ok(!Claudpress_Capabilities::tool_allowed('send_email'), 'outbound comms are Pro');

CP_Test::ok(Claudpress_Capabilities::tool_allowed('site_readiness'), 'site_readiness stays available: it is the proof the build worked');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('get_dashboard'), 'always-on read tools ignore tiers entirely');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('some_third_party_tool'), 'a tool from another plugin is not refused just for being unrecognised');

CP_Test::group('Tiers - what an agent is told');

$missing = Claudpress_Capabilities::unavailable_groups();
CP_Test::same(count($expect_pro), count($missing), 'every Pro group is described to the agent');
foreach ($missing as $entry) {
    CP_Test::ok($entry['label'] !== '' && $entry['what_it_does'] !== '', $entry['name'] . ' is described, not just named');
}

$pro_tools = Claudpress_Capabilities::pro_tools();
CP_Test::ok(in_array('wc_order', $pro_tools, true), 'pro_tools lists wc_order for registry filtering');
CP_Test::ok(!in_array('create_post', $pro_tools, true), 'pro_tools does not list a free tool');

CP_Test::group('Tiers - with the add-on active');

add_filter('claudpress_is_pro', function () { return true; });

CP_Test::ok(Claudpress_Capabilities::is_pro(), 'the add-on turns Pro on through the filter');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('wc_order'), 'Pro tools become available');
CP_Test::ok(Claudpress_Capabilities::tool_allowed('run_agent'), '...including autonomy');
CP_Test::same([], Claudpress_Capabilities::unavailable_groups(), 'and nothing is reported missing');

CP_Test::group('Tiers - the owner toggle still wins');

// Being entitled to a feature is not the same as wanting it switched on.
cp_test_reset_options(['claudpress_settings' => ['capabilities' => ['automation' => false]]]);
CP_Test::ok(!Claudpress_Capabilities::tool_allowed('run_agent'), 'a Pro user who switched autonomy off does not get it back');

cp_test_reset_filters();
cp_test_reset_options();

CP_Test::group('Tiers — a downgrade must not break the site you already have');

// seo_redirect (creating a redirect) is Pro. do_redirects (SERVING one that
// already exists) is not, and must never become Pro. Otherwise a customer who
// lets their subscription lapse would find every redirect they ever made had
// turned into a 404 — losing traffic and rankings as a side effect of billing.
// Downgrading should cost you new features. It should never break what is live.
$seo_free = file_get_contents(CLAUDPRESS_DIR . 'includes/tools/class-tools-seo.php');

CP_Test::contains('function do_redirects', $seo_free, 'the redirect SERVER stays in the free plugin');
CP_Test::contains("add_action('template_redirect', [self::class, 'do_redirects']", $seo_free, '...and stays hooked on the front end');
CP_Test::ok(strpos($seo_free, "add('seo_redirect'") === false, 'but creating a redirect is not a free tool');

$pro_seo = CLAUDPRESS_DIR . 'claudpress-pro/includes/class-tools-seo-pro.php';
if (file_exists($pro_seo)) {
    $src = file_get_contents($pro_seo);
    CP_Test::contains("add('seo_redirect'", $src, 'creating a redirect moved to the add-on');
    CP_Test::ok(strpos($src, 'function do_redirects') === false, 'and the server did NOT go with it');
    CP_Test::contains('Claudpress_Tools_Seo::seo_meta', $src, 'seo_analyze reads back what the free meta tool wrote, rather than duplicating it');
}
