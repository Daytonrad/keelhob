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
 * So the plugin keeps its own record, taken at Claudpress_Tool_Registry::call(),
 * the one place every tool call passes through. The run report can then say
 * "loaded web-design-master; declared verifier pre-launch-audit never ran",
 * which is the difference between knowing why a page shipped badly and guessing.
 */

require_once CLAUDPRESS_DIR . 'includes/class-tool-registry.php';

CP_Test::group('Skill receipts — what the run actually loaded');

// call() hands each result to the logger when log_calls is on, and the logger
// is wired during boot(), which does not happen here. The receipt is taken
// before that, so switching the logging off keeps this test about the receipt.
cp_test_reset_options(['claudpress_settings' => ['log_calls' => false]]);

Claudpress_Tool_Registry::reset_skills_loaded();
CP_Test::same([], Claudpress_Tool_Registry::skills_loaded(), 'a fresh run starts with an empty receipt');

$registry = new Claudpress_Tool_Registry();
$registry->add('skill_get', 'stub', ['type' => 'object', 'properties' => (object) []], static fn(array $a): array => ['success' => true]);
$registry->add('get_site_info', 'stub', ['type' => 'object', 'properties' => (object) []], static fn(array $a): array => ['success' => true]);

$registry->call('get_site_info', []);
CP_Test::same([], Claudpress_Tool_Registry::skills_loaded(), 'an ordinary tool call is not a skill load');

$registry->call('skill_get', ['name' => 'web-design-master']);
$registry->call('skill_get', ['name' => 'motion-and-interaction']);
CP_Test::same(
    ['web-design-master', 'motion-and-interaction'],
    Claudpress_Tool_Registry::skills_loaded(),
    'skills are recorded in the order they were loaded'
);

$registry->call('skill_get', ['name' => 'web-design-master']);
CP_Test::same(
    ['web-design-master', 'motion-and-interaction'],
    Claudpress_Tool_Registry::skills_loaded(),
    'loading the same skill twice does not double it — the question is what was read, not how often'
);

// A second registry is built by unattended runs. The receipt belongs to the
// request, not to whichever registry happened to be constructed last.
$second = new Claudpress_Tool_Registry();
$second->add('skill_get', 'stub', ['type' => 'object', 'properties' => (object) []], static fn(array $a): array => ['success' => true]);
$second->call('skill_get', ['name' => 'site-safety']);
CP_Test::same(
    ['web-design-master', 'motion-and-interaction', 'site-safety'],
    Claudpress_Tool_Registry::skills_loaded(),
    'a second registry in the same request adds to the same receipt rather than starting its own'
);

$registry->call('skill_get', []);
CP_Test::same(
    ['web-design-master', 'motion-and-interaction', 'site-safety'],
    Claudpress_Tool_Registry::skills_loaded(),
    'a skill_get with no name adds nothing — an empty entry in the record would read as a skill called ""'
);

Claudpress_Tool_Registry::reset_skills_loaded();
CP_Test::same([], Claudpress_Tool_Registry::skills_loaded(), 'and a new run starts clean, so one run never inherits another\'s credit');

// ---- the column that carries it --------------------------------------------

require_once CLAUDPRESS_DIR . 'includes/class-agent-runs.php';

$GLOBALS['cp_test_dbdelta'] = [];
Claudpress_Agent_Runs::install_table();

CP_Test::contains(
    'skills_used',
    implode("\n", (array) $GLOBALS['cp_test_dbdelta']),
    'the run table carries a skills_used column, so the receipt survives the request that produced it'
);
$GLOBALS['cp_test_dbdelta'] = [];
