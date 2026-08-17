<?php
/** Memory key normalization and the tool registry contract. */

CP_Test::group('Memory — key normalization');

CP_Test::same('build.elementor', Claudpress_Memory::clean_key('build.elementor'), 'dots survive (hierarchical keys are the convention)');
CP_Test::same('brand:primary', Claudpress_Memory::clean_key('brand:primary'), 'colons survive');
CP_Test::same('my-key', Claudpress_Memory::clean_key('my-key'), 'hyphens survive');
CP_Test::same('build.elementor', Claudpress_Memory::clean_key('  Build.Elementor  '), 'keys are trimmed and lowercased');
CP_Test::same('abc', Claudpress_Memory::clean_key('a b c'), 'spaces are stripped');
CP_Test::same('key', Claudpress_Memory::clean_key('key!@#$%^&*()'), 'punctuation that would break lookups is stripped');
CP_Test::same('', Claudpress_Memory::clean_key('!!!'), 'a key with nothing usable normalizes to empty (and is rejected on save)');
CP_Test::same(191, strlen(Claudpress_Memory::clean_key(str_repeat('a', 300))), 'keys are truncated to the column width');

// This is the regression that shipped in v1.1.1 — sanitize_key() ate the dots
// and silently split one memory into two.
CP_Test::ok(Claudpress_Memory::clean_key('claudpress.capabilities') !== sanitize_key('claudpress.capabilities'), 'clean_key deliberately differs from sanitize_key on dotted keys');

CP_Test::group('Tool registry — registration');

cp_test_reset_options(['claudpress_settings' => ['log_calls' => false]]);
$registry = new Claudpress_Tool_Registry();

CP_Test::ok(!$registry->has('demo'), 'an unregistered tool is not present');
$registry->add('demo', 'A demo tool.', ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]], static fn(array $a) => ['success' => true, 'echo' => $a]);
CP_Test::ok($registry->has('demo'), 'a registered tool is present');
CP_Test::same(['demo'], $registry->names(), 'names() lists registered tools');

$listed = $registry->list();
CP_Test::same('demo', $listed[0]['name'], 'list() exposes the name');
CP_Test::same('A demo tool.', $listed[0]['description'], 'list() exposes the description');
CP_Test::ok(isset($listed[0]['inputSchema']['type']), 'list() exposes a JSON-Schema object as inputSchema');
CP_Test::ok(!isset($listed[0]['annotations']), 'annotations are omitted when empty rather than sent as null');

$registry->add('noschema', 'No schema given.', [], static fn(array $a) => ['success' => true]);
$listed = $registry->list();
$noschema = array_values(array_filter($listed, static fn($t) => $t['name'] === 'noschema'))[0];
CP_Test::same('object', $noschema['inputSchema']['type'], 'a tool registered without a schema still advertises an object schema');

CP_Test::group('Tool registry — a bad callback degrades one tool, not the site');

// This is the regression that took pranknologia.shop down on 2026-08-17: a
// tool was registered with a callback array pointing at a method that did not
// exist yet. add() used to type-hint `callable`, which PHP validates the
// instant the array is bound to the parameter — so registering ANY tool with
// a not-yet-defined callback threw an uncaught TypeError on every single
// request (register() runs on plugins_loaded), fatally crashing the whole
// site rather than just that one tool.
$safe_registry = new Claudpress_Tool_Registry();
CP_Test::ok(
    !method_exists('CP_Test_Missing_Callback_Target', 'not_a_real_method'),
    'sanity check: the target method genuinely does not exist'
);
try {
    $safe_registry->add('broken', 'Points at a method that does not exist.', [], ['CP_Test_Missing_Callback_Target', 'not_a_real_method']);
    CP_Test::ok(true, 'registering a tool with an uncallable target does not throw');
} catch (\Throwable $e) {
    CP_Test::ok(false, 'registering a tool with an uncallable target does not throw — got: ' . $e->getMessage());
}
CP_Test::ok(!$safe_registry->has('broken'), 'the tool with the bad callback is simply not registered');

$safe_registry->add('fine', 'A working tool registered right after the broken one.', [], static fn(array $a) => ['success' => true]);
CP_Test::ok($safe_registry->has('fine'), 'a tool registered after a broken one is unaffected — the bootstrap keeps going');
CP_Test::same(['fine'], $safe_registry->names(), 'the broken tool never appears in the tool list');

class CP_Test_Missing_Callback_Target
{
}

CP_Test::group('Tool registry — calling');

$result = $registry->call('demo', ['x' => 1]);
CP_Test::ok($result['success'] === true, 'call() returns the callback result');
CP_Test::same(['x' => 1], $result['echo'], 'call() passes the arguments through');
CP_Test::ok(isset($result['_meta']['execution_time_ms']), 'call() attaches timing metadata');

$registry->add('scalar', 'Returns a scalar.', [], static fn(array $a) => 'plain string');
$scalar = $registry->call('scalar', []);
CP_Test::same('plain string', $scalar['result'], 'a non-array return value is normalized into a result key');

CP_Test::throws(static fn() => $registry->call('does_not_exist', []), 'calling an unknown tool throws rather than returning a silent success');

$registry->add('boom', 'Throws.', [], static function (array $a) {
    throw new RuntimeException('kaboom');
});
CP_Test::throws(static fn() => $registry->call('boom', []), 'an exception inside a tool propagates to the MCP layer');

CP_Test::group('Settings — defaults');

cp_test_reset_options();
$defaults = Claudpress::default_settings();
CP_Test::same(false, $defaults['abilities_enabled'], 'abilities are OFF by default — the master safety switch fails closed');
CP_Test::ok(isset($defaults['policy']['enabled']) && $defaults['policy']['enabled'], 'policy is enforced by default');
CP_Test::same(0, (int) $defaults['policy']['max_refund'], 'refunds are blocked by default');
CP_Test::same([], $defaults['capabilities'], 'no capability overrides by default (all on)');
CP_Test::same('', $defaults['agent_api_key'], 'no agent key ships in the defaults');
CP_Test::same('', $defaults['deploy_target_token'], 'no deploy token ships in the defaults');

cp_test_reset_options(['claudpress_settings' => ['abilities_enabled' => true]]);
CP_Test::ok(Claudpress::instance()->abilities_enabled(), 'saved settings override defaults');
CP_Test::same('anthropic', Claudpress::instance()->get('agent_provider'), 'unsaved keys still fall back to their default');
// Model and endpoint intentionally default to '' — blank means "use whatever the
// selected provider recommends", so switching provider cannot strand a stale value.
CP_Test::same('', Claudpress::instance()->get('agent_model'), 'model defaults to blank so the provider default applies');

cp_test_reset_options();
