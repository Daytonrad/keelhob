<?php
/**
 * The receipt: which skills a run actually loaded, recorded by the plugin.
 *
 * Operating-protocol rule 13 asks the agent to name the skills it used and to
 * honour their declared verifiers. An agent's own account of what it did is the
 * least reliable evidence available — it can state, in good faith, that it
 * followed a playbook it never opened, and nothing in the transcript would
 * contradict it. Nobody notices, because the failure is silent: the work is
 * just worse.
 *
 * So the plugin keeps its own record, taken at Keelhob_Tool_Registry::call(),
 * the one place every tool call passes through. The run report can then say
 * "loaded web-design-master; declared verifier pre-launch-audit never ran",
 * which is the difference between knowing why a page shipped badly and guessing.
 */

require_once KEELHOB_DIR . 'includes/class-tool-registry.php';

KH_Test::group('Skill receipts — what the run actually loaded');

// call() hands each result to the logger when log_calls is on, and the logger
// is wired during boot(), which does not happen here. The receipt is taken
// before that, so switching the logging off keeps this test about the receipt.
kh_test_reset_options(['keelhob_settings' => ['log_calls' => false]]);

Keelhob_Tool_Registry::reset_skills_loaded();
KH_Test::same([], Keelhob_Tool_Registry::skills_loaded(), 'a fresh run starts with an empty receipt');

$registry = new Keelhob_Tool_Registry();
$registry->add('skill_get', 'stub', ['type' => 'object', 'properties' => (object) []], static fn(array $a): array => ['success' => true]);
$registry->add('get_site_info', 'stub', ['type' => 'object', 'properties' => (object) []], static fn(array $a): array => ['success' => true]);

$registry->call('get_site_info', []);
KH_Test::same([], Keelhob_Tool_Registry::skills_loaded(), 'an ordinary tool call is not a skill load');

$registry->call('skill_get', ['name' => 'web-design-master']);
$registry->call('skill_get', ['name' => 'motion-and-interaction']);
KH_Test::same(
    ['web-design-master', 'motion-and-interaction'],
    Keelhob_Tool_Registry::skills_loaded(),
    'skills are recorded in the order they were loaded'
);

$registry->call('skill_get', ['name' => 'web-design-master']);
KH_Test::same(
    ['web-design-master', 'motion-and-interaction'],
    Keelhob_Tool_Registry::skills_loaded(),
    'loading the same skill twice does not double it — the question is what was read, not how often'
);

// A second registry is built by unattended runs. The receipt belongs to the
// request, not to whichever registry happened to be constructed last.
$second = new Keelhob_Tool_Registry();
$second->add('skill_get', 'stub', ['type' => 'object', 'properties' => (object) []], static fn(array $a): array => ['success' => true]);
$second->call('skill_get', ['name' => 'site-safety']);
KH_Test::same(
    ['web-design-master', 'motion-and-interaction', 'site-safety'],
    Keelhob_Tool_Registry::skills_loaded(),
    'a second registry in the same request adds to the same receipt rather than starting its own'
);

$registry->call('skill_get', []);
KH_Test::same(
    ['web-design-master', 'motion-and-interaction', 'site-safety'],
    Keelhob_Tool_Registry::skills_loaded(),
    'a skill_get with no name adds nothing — an empty entry in the record would read as a skill called ""'
);

Keelhob_Tool_Registry::reset_skills_loaded();
KH_Test::same([], Keelhob_Tool_Registry::skills_loaded(), 'and a new run starts clean, so one run never inherits another\'s credit');

// ---- the column that carries it --------------------------------------------

require_once KEELHOB_DIR . 'includes/class-agent-runs.php';

$GLOBALS['kh_test_dbdelta'] = [];
Keelhob_Agent_Runs::install_table();

KH_Test::contains(
    'skills_used',
    implode("\n", (array) $GLOBALS['kh_test_dbdelta']),
    'the run table carries a skills_used column, so the receipt survives the request that produced it'
);
$GLOBALS['kh_test_dbdelta'] = [];

KH_Test::group('Skill receipts - durable, for sessions that are not agent runs');

// The gap this closes: over MCP every tool call is its own HTTP request, so the
// per-request static in the registry only ever held the one skill loaded in
// that same request, and only Agent_Runs::finish() read it - which runs for
// run_agent and scheduled tasks and nothing else. keelhob.com was built
// entirely over interactive MCP: 0 agent runs, 10 snippets, and no record
// anywhere that a single skill had ever been loaded.
$GLOBALS['kh_test_options'][Keelhob_Skills::LOADS_OPTION] = null;
delete_option(Keelhob_Skills::LOADS_OPTION);

KH_Test::same([], Keelhob_Skills::recent_loads(), 'a site that has loaded nothing reports nothing');

Keelhob_Skills::record_load('web-design-master');
Keelhob_Skills::record_load('code-snippets');
$loads = Keelhob_Skills::recent_loads();
KH_Test::same(2, count($loads), 'two loads are recorded');
KH_Test::same('code-snippets', $loads[0]['skill'] ?? null, 'newest first');
KH_Test::same('web-design-master', $loads[1]['skill'] ?? null, 'then the earlier one');
KH_Test::ok(!empty($loads[0]['at']), 'each carries when it happened');

// The same skill loaded twice is two events, not one. "Loaded it, went away,
// came back to it" is a different story from "loaded it once", and collapsing
// them throws that away.
Keelhob_Skills::record_load('code-snippets');
KH_Test::same(3, count(Keelhob_Skills::recent_loads()), 'a repeat load is its own event');

// Junk in must not become a row that reads like a real skill.
Keelhob_Skills::record_load('');
Keelhob_Skills::record_load('   ');
KH_Test::same(3, count(Keelhob_Skills::recent_loads()), 'empty names are ignored');

// Bounded, so one long session cannot grow an option without limit.
for ($i = 0; $i < Keelhob_Skills::LOADS_KEEP + 20; $i++) {
    Keelhob_Skills::record_load('filler-' . $i);
}
$all = Keelhob_Skills::recent_loads(Keelhob_Skills::LOADS_KEEP);
KH_Test::same(Keelhob_Skills::LOADS_KEEP, count($all), 'the record is capped');
KH_Test::same(
    'filler-' . (Keelhob_Skills::LOADS_KEEP + 19),
    $all[0]['skill'] ?? null,
    'and it keeps the NEWEST, not the first ones it happened to see'
);

delete_option(Keelhob_Skills::LOADS_OPTION);
