<?php
/** Memory key normalization and the tool registry contract. */

KH_Test::group('Memory — key normalization');

KH_Test::same('build.elementor', Keelhob_Memory::clean_key('build.elementor'), 'dots survive (hierarchical keys are the convention)');
KH_Test::same('brand:primary', Keelhob_Memory::clean_key('brand:primary'), 'colons survive');
KH_Test::same('my-key', Keelhob_Memory::clean_key('my-key'), 'hyphens survive');
KH_Test::same('build.elementor', Keelhob_Memory::clean_key('  Build.Elementor  '), 'keys are trimmed and lowercased');
KH_Test::same('abc', Keelhob_Memory::clean_key('a b c'), 'spaces are stripped');
KH_Test::same('key', Keelhob_Memory::clean_key('key!@#$%^&*()'), 'punctuation that would break lookups is stripped');
KH_Test::same('', Keelhob_Memory::clean_key('!!!'), 'a key with nothing usable normalizes to empty (and is rejected on save)');
KH_Test::same(191, strlen(Keelhob_Memory::clean_key(str_repeat('a', 300))), 'keys are truncated to the column width');

// This is the regression that shipped in v1.1.1 — sanitize_key() ate the dots
// and silently split one memory into two.
KH_Test::ok(Keelhob_Memory::clean_key('keelhob.capabilities') !== sanitize_key('keelhob.capabilities'), 'clean_key deliberately differs from sanitize_key on dotted keys');

KH_Test::group('Tool registry — registration');

kh_test_reset_options(['keelhob_settings' => ['log_calls' => false]]);
$registry = new Keelhob_Tool_Registry();

KH_Test::ok(!$registry->has('demo'), 'an unregistered tool is not present');
$registry->add('demo', 'A demo tool.', ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]], static fn(array $a) => ['success' => true, 'echo' => $a]);
KH_Test::ok($registry->has('demo'), 'a registered tool is present');
KH_Test::same(['demo'], $registry->names(), 'names() lists registered tools');

$listed = $registry->list();
KH_Test::same('demo', $listed[0]['name'], 'list() exposes the name');
KH_Test::same('A demo tool.', $listed[0]['description'], 'list() exposes the description');
KH_Test::ok(isset($listed[0]['inputSchema']['type']), 'list() exposes a JSON-Schema object as inputSchema');
KH_Test::ok(!isset($listed[0]['annotations']), 'annotations are omitted when empty rather than sent as null');

$registry->add('noschema', 'No schema given.', [], static fn(array $a) => ['success' => true]);
$listed = $registry->list();
$noschema = array_values(array_filter($listed, static fn($t) => $t['name'] === 'noschema'))[0];
KH_Test::same('object', $noschema['inputSchema']['type'], 'a tool registered without a schema still advertises an object schema');

KH_Test::group('Tool registry — a bad callback degrades one tool, not the site');

// This is the regression that took pranknologia.shop down on 2026-08-17: a
// tool was registered with a callback array pointing at a method that did not
// exist yet. add() used to type-hint `callable`, which PHP validates the
// instant the array is bound to the parameter — so registering ANY tool with
// a not-yet-defined callback threw an uncaught TypeError on every single
// request (register() runs on plugins_loaded), fatally crashing the whole
// site rather than just that one tool.
$safe_registry = new Keelhob_Tool_Registry();
KH_Test::ok(
    !method_exists('KH_Test_Missing_Callback_Target', 'not_a_real_method'),
    'sanity check: the target method genuinely does not exist'
);
try {
    $safe_registry->add('broken', 'Points at a method that does not exist.', [], ['KH_Test_Missing_Callback_Target', 'not_a_real_method']);
    KH_Test::ok(true, 'registering a tool with an uncallable target does not throw');
} catch (\Throwable $e) {
    KH_Test::ok(false, 'registering a tool with an uncallable target does not throw — got: ' . $e->getMessage());
}
KH_Test::ok(!$safe_registry->has('broken'), 'the tool with the bad callback is simply not registered');

$safe_registry->add('fine', 'A working tool registered right after the broken one.', [], static fn(array $a) => ['success' => true]);
KH_Test::ok($safe_registry->has('fine'), 'a tool registered after a broken one is unaffected — the bootstrap keeps going');
KH_Test::same(['fine'], $safe_registry->names(), 'the broken tool never appears in the tool list');

class KH_Test_Missing_Callback_Target
{
}

KH_Test::group('Tool registry — calling');

$result = $registry->call('demo', ['x' => 1]);
KH_Test::ok($result['success'] === true, 'call() returns the callback result');
KH_Test::same(['x' => 1], $result['echo'], 'call() passes the arguments through');
KH_Test::ok(isset($result['_meta']['execution_time_ms']), 'call() attaches timing metadata');

$registry->add('scalar', 'Returns a scalar.', [], static fn(array $a) => 'plain string');
$scalar = $registry->call('scalar', []);
KH_Test::same('plain string', $scalar['result'], 'a non-array return value is normalized into a result key');

KH_Test::throws(static fn() => $registry->call('does_not_exist', []), 'calling an unknown tool throws rather than returning a silent success');

$registry->add('boom', 'Throws.', [], static function (array $a) {
    throw new RuntimeException('kaboom');
});
KH_Test::throws(static fn() => $registry->call('boom', []), 'an exception inside a tool propagates to the MCP layer');

KH_Test::group('Settings — defaults');

kh_test_reset_options();
$defaults = Keelhob::default_settings();
KH_Test::same(false, $defaults['abilities_enabled'], 'abilities are OFF by default — the master safety switch fails closed');
KH_Test::ok(isset($defaults['policy']['enabled']) && $defaults['policy']['enabled'], 'policy is enforced by default');
KH_Test::same(0, (int) $defaults['policy']['max_refund'], 'refunds are blocked by default');
KH_Test::same([], $defaults['capabilities'], 'no capability overrides by default (all on)');
KH_Test::same('', $defaults['agent_api_key'], 'no agent key ships in the defaults');
KH_Test::same('', $defaults['deploy_target_token'], 'no deploy token ships in the defaults');

kh_test_reset_options(['keelhob_settings' => ['abilities_enabled' => true]]);
KH_Test::ok(Keelhob::instance()->abilities_enabled(), 'saved settings override defaults');
KH_Test::same('anthropic', Keelhob::instance()->get('agent_provider'), 'unsaved keys still fall back to their default');
// Model and endpoint intentionally default to '' — blank means "use whatever the
// selected provider recommends", so switching provider cannot strand a stale value.
KH_Test::same('', Keelhob::instance()->get('agent_model'), 'model defaults to blank so the provider default applies');

kh_test_reset_options();
