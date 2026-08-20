<?php
/**
 * Tier disclosure - what a connected agent is told about the free/Pro line.
 *
 * This exists to stop two specific failures. An agent that does not know a
 * capability is missing burns tokens discovering it by trial and then tells the
 * owner their site is broken. And an agent that hits a refusal without knowing
 * why will cheerfully rebuild the feature with execute_php, which works on the
 * free tier and is squarely against the owner interest.
 */

CP_Test::group('Tier disclosure - on a free site');

cp_test_reset_options();
cp_test_reset_filters();

$notice = new ReflectionMethod('Claudpress_MCP_Server', 'tier_notice');
$lines  = $notice->invoke(null);
$text   = implode("\n", $lines);

CP_Test::ok(!empty($lines), 'a free site says something about the tier');
CP_Test::contains('free version', $text, 'it states which tier this is');
CP_Test::contains('absent from tools/list', $text, 'it explains that the tools are not merely refused, they are not there');
CP_Test::contains('execute_php', $text, 'it names the workaround it is forbidding, because a vague warning is no warning');
CP_Test::contains('orders & reports', $text, 'a specific Pro capability is named, not just gestured at');
CP_Test::contains('Autonomy', $text, 'so is autonomy');

foreach ($lines as $line) {
    CP_Test::ok(stripos($line, 'buy') === false && stripos($line, 'upgrade now') === false, 'the notice stays factual and never reads as an advert: ' . substr($line, 0, 40));
}

CP_Test::group('Tier disclosure - it can be silenced');

cp_test_reset_options(['claudpress_settings' => ['disclose_pro' => false]]);
CP_Test::same([], $notice->invoke(null), 'the owner can switch the disclosure off in Settings');

cp_test_reset_options();
add_filter('claudpress_tier_notice', static function () { return []; });
CP_Test::same([], $notice->invoke(null), 'a developer can remove it entirely with the filter');
cp_test_reset_filters();

CP_Test::group('Tier disclosure - silent when Pro');

add_filter('claudpress_is_pro', static function () { return true; });
CP_Test::same([], $notice->invoke(null), 'a Pro site is told nothing, because nothing is missing');
cp_test_reset_filters();

CP_Test::group('Tier disclosure - the instructions do not oversell');

// Read the shipped source rather than calling server_instructions(): that
// method needs a booted skills store, and what is being asserted here is a
// property of the STRING we ship, not of any runtime state.
$src = file_get_contents(CLAUDPRESS_DIR . 'includes/class-mcp-server.php');
$powers_start = strpos($src, 'Powers include:');
CP_Test::ok($powers_start !== false, 'the powers sentence is still there');
$powers = substr($src, $powers_start, 1200);

// This sentence used to advertise wc_order, run_agent, send_email, wc_payments
// and deploy_push as if every site had them. On a free site that is untrue, and
// it is the first thing an agent reads.
foreach (['wc_order', 'run_agent', 'send_email', 'wc_payments', 'deploy_push', 'manage_updates'] as $tool) {
    CP_Test::ok(strpos($powers, $tool) === false, $tool . ' is no longer advertised in the powers sentence');
}
foreach (['create_post', 'wc_product', 'snippet_save', 'execute_php', 'site_readiness'] as $tool) {
    CP_Test::contains($tool, $powers, $tool . ' is advertised, because it really is free');
}
CP_Test::contains('whole truth about what you can do here', $powers, 'the agent is told to trust tools/list over its own assumptions about WordPress');

cp_test_reset_options();
cp_test_reset_filters();

CP_Test::group('Tier disclosure - get_site_info carries the shape of the install');

// Prose is for the session preamble; this is the machine-readable half. An
// agent can branch on a list of names. It cannot reliably branch on a
// paragraph, and it should not have to infer the shape of the install from
// what tools/list happens to contain.
cp_test_reset_options();
cp_test_reset_filters();

$src = file_get_contents(CLAUDPRESS_DIR . 'includes/tools/class-tools-core.php');
foreach (['plan', 'available_capabilities', 'unavailable_capabilities'] as $field) {
    CP_Test::contains($field, $src, 'get_site_info reports ' . $field);
}
foreach (['why_unavailable', 'how_to_enable'] as $field) {
    CP_Test::contains($field, $src, 'each missing capability explains ' . $field . ', so the agent can tell the owner something useful');
}
CP_Test::contains('not a setting', $src, 'the agent is told this is not a switch the owner can flip, so it stops hunting for one');
