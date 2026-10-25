<?php
/**
 * Agent-written abilities: the parts that have to be true before any of it is
 * allowed anywhere near a live site.
 *
 * Everything asserted here is pure or option-backed on purpose. The engine was
 * written so that the four safety properties — pending code never runs, the
 * lint never runs it either, authoring is off until asked for, and the code that
 * runs is the code that was approved — can each be checked without a database,
 * because a property that can only be checked on a rig is a property nobody
 * checks.
 *
 * The approval gate itself is tests/unit/test-abilities-approval.php.
 */

KH_Test::group('Abilities — the name rules');

KH_Test::same('list_drafts', Keelhob_Abilities::normalize_name('List-Drafts'), 'uppercase is lowered and dashes become underscores');
KH_Test::same('list_my_drafts', Keelhob_Abilities::normalize_name('  list my drafts '), 'spaces become underscores and the edges are trimmed');
KH_Test::same('listdrafts', Keelhob_Abilities::normalize_name('list@#drafts'), 'anything else is dropped rather than carried into a tool name');

KH_Test::ok(Keelhob_Abilities::name_valid('list_drafts'), 'a sensible name passes');
KH_Test::ok(!Keelhob_Abilities::name_valid('2fast'), 'a name cannot start with a digit');
KH_Test::ok(!Keelhob_Abilities::name_valid('ab'), 'two characters is too short');
KH_Test::ok(!Keelhob_Abilities::name_valid(''), 'and empty is not a name');
KH_Test::ok(!Keelhob_Abilities::name_valid(str_repeat('a', 62)), '62 characters is too long for a VARCHAR(191) key shared with a registry');

KH_Test::group('Abilities — the lint parses the code and NEVER runs it');

/*
 * The one test in this file that would be a genuine security incident if it
 * failed. $GLOBALS is used rather than a file because a leaked write to a
 * global is visible here with no cleanup and no filesystem.
 */
unset($GLOBALS['kh_lint_escaped']);
$side_effect = '$GLOBALS["kh_lint_escaped"] = true; return [];';
KH_Test::same('', Keelhob_Abilities::lint_code($side_effect), 'code with a side effect still lints clean — linting is a parse, not a verdict on behaviour');
KH_Test::ok(!isset($GLOBALS['kh_lint_escaped']), 'and the side effect DID NOT HAPPEN: the lint compiled the code without running it');

// The escape the eval trick on its own is vulnerable to: close the closure, run
// at top level, reopen it. It is syntactically valid, so it has to be the
// wrapped parse that refuses it rather than the shape of the string.
unset($GLOBALS['kh_lint_escaped']);
$escape = '}; $GLOBALS["kh_lint_escaped"] = true; $unused = function( array $input ) { return [];';
Keelhob_Abilities::lint_code($escape);
KH_Test::ok(!isset($GLOBALS['kh_lint_escaped']), 'code that breaks out of its own braces does not execute either');

unset($GLOBALS['kh_lint_escaped']);
$declare = '}; class Kh_Lint_Boom {} $unused = function( array $input ) { return [];';
Keelhob_Abilities::lint_code($declare);
KH_Test::ok(!class_exists('Kh_Lint_Boom', false), 'and it cannot hoist a class declaration into this process, which an eval-only lint would have let through');

KH_Test::group('Abilities — the body has to stay inside its own braces');

KH_Test::ok(!Keelhob_Abilities::escapes_braces('return [];'), 'a plain body does not escape');
KH_Test::ok(!Keelhob_Abilities::escapes_braces('if ($input) { return []; } return [];'), 'nor does one with its own blocks');
KH_Test::ok(!Keelhob_Abilities::escapes_braces('return ["s" => "a } brace in a string"];'), 'a brace inside a string literal is not counted — it is part of one token');
KH_Test::ok(!Keelhob_Abilities::escapes_braces('// } in a comment' . "\n" . 'return [];'), 'nor is one in a comment');
KH_Test::ok(!Keelhob_Abilities::escapes_braces('$a = "x"; return ["v" => "{$a}"];'), 'and string interpolation opens and closes its own brace');
KH_Test::ok(Keelhob_Abilities::escapes_braces('}; return [];'), 'closing a brace it never opened IS an escape');
KH_Test::ok(Keelhob_Abilities::escapes_braces('if (true) { return [];'), 'and so is leaving one open');
KH_Test::contains('closes a brace', Keelhob_Abilities::lint_code('}; return [];'), 'the lint refuses it in words the model can act on');

KH_Test::group('Abilities — the lint rejects what it should');

KH_Test::ok(Keelhob_Abilities::lint_code('return [ ;') !== '', 'broken syntax is refused');
KH_Test::contains('Syntax error', Keelhob_Abilities::lint_code('return [ ;'), 'and the owner is told it is a syntax error');
KH_Test::contains('<?php', Keelhob_Abilities::lint_code('<?php return [];'), 'a leading <?php is named rather than reported as a mystery parse error');
KH_Test::contains('?>', Keelhob_Abilities::lint_code('return []; ?>'), 'and so is a closing tag');
KH_Test::contains('no code', Keelhob_Abilities::lint_code('   '), 'empty code is refused in words');
KH_Test::same('', Keelhob_Abilities::lint_code('return ["posts" => get_posts()];'), 'ordinary WordPress code in a function body parses');
KH_Test::same('', Keelhob_Abilities::lint_code('$n = (int) ($input["limit"] ?? 5); return ["n" => $n];'), 'and so does code that reads $input');

KH_Test::group('Abilities — one parser for every piece of model-written PHP');

KH_Test::same(
    '',
    Keelhob_Snippets::lint_body('return function( array $input ) { return []; };'),
    'Keelhob_Snippets::lint_body is the shared parse check the abilities lint goes through'
);
KH_Test::ok(Keelhob_Snippets::lint_body('function (') !== '', 'and it still reports a parse error');
KH_Test::same('', Keelhob_Snippets::lint_php('add_action("init", function () {});'), 'the snippets lint is unchanged and still goes through it');
KH_Test::ok(Keelhob_Snippets::lint_php('add_action("init", ') !== '', 'including for broken snippet PHP');

KH_Test::group('Abilities — the hash is what makes approved code identifiable');

$code = 'return ["ok" => true];';
$hash = Keelhob_Abilities::hash($code);
KH_Test::same(64, strlen($hash), 'the hash is sha256, which is what the CHAR(64) column holds');
KH_Test::same($hash, hash('sha256', $code), 'and it is sha256 of exactly the stored bytes, with no normalisation in between');

KH_Test::ok(Keelhob_Abilities::hash_ok(['code' => $code, 'code_hash' => $hash]), 'untouched code passes the guard');
KH_Test::ok(!Keelhob_Abilities::hash_ok(['code' => $code . ' ', 'code_hash' => $hash]), 'one added space fails it');
KH_Test::ok(!Keelhob_Abilities::hash_ok(['code' => 'unlink(ABSPATH);', 'code_hash' => $hash]), 'and replaced code fails it');

/*
 * The failure mode a careless implementation has: `$hash === '' || match` waves
 * through every row that was never approved, because an empty hash is exactly
 * what a pending row carries.
 */
KH_Test::ok(!Keelhob_Abilities::hash_ok(['code' => $code, 'code_hash' => '']), 'an EMPTY hash fails — that is the state of every row nobody has approved');
KH_Test::ok(!Keelhob_Abilities::hash_ok(['code' => $code]), 'and a row with no hash column at all fails');
KH_Test::ok(!Keelhob_Abilities::hash_ok([]), 'as does an empty row');

KH_Test::group('Abilities — authoring is OFF on a fresh install');

kh_test_reset_options([]);
KH_Test::ok(!Keelhob_Abilities::authoring_enabled(), 'with nothing stored, a model may NOT write code for this site');
KH_Test::ok(
    in_array(Keelhob_Abilities::CAP_GROUP, Keelhob_Capabilities::DISABLED_BY_DEFAULT, true),
    'and the capability group it reads is named in DISABLED_BY_DEFAULT, so the two gates agree'
);
KH_Test::ok(
    !Keelhob_Capabilities::tool_allowed('ability_create') && !Keelhob_Capabilities::tool_allowed('ability_improve'),
    'the MCP server would refuse both tools on that install as well'
);

kh_test_reset_options(['keelhob_settings' => ['capabilities' => ['abilities' => false]]]);
KH_Test::ok(!Keelhob_Abilities::authoring_enabled(), 'an explicit off is off');

kh_test_reset_options(['keelhob_settings' => ['capabilities' => ['abilities' => true]]]);
KH_Test::ok(Keelhob_Abilities::authoring_enabled(), 'and an explicit on is the only thing that switches it on');

kh_test_reset_options([]);

KH_Test::group('Abilities — proposing refuses before it writes anything');

$off = Keelhob_Abilities::propose(['name' => 'x_tool', 'label' => 'X', 'description' => 'd', 'code' => 'return [];'], true);
KH_Test::same(false, $off['success'], 'with authoring off, ability_create refuses');
KH_Test::contains('switched OFF', $off['error'], 'and says so');
KH_Test::contains('execute_php', $off['error'], 'and names the workarounds it must not reach for, which is the whole point of telling it');

kh_test_reset_options(['keelhob_settings' => ['capabilities' => ['abilities' => true]]]);

$bad_name = Keelhob_Abilities::propose(['name' => '9', 'label' => 'X', 'description' => 'd', 'code' => 'return [];'], true);
KH_Test::same(false, $bad_name['success'], 'an invalid name is refused');
KH_Test::contains('Invalid input', $bad_name['error'], 'in the reference validator\'s wording');

$no_label = Keelhob_Abilities::propose(['name' => 'x_tool', 'description' => 'd', 'code' => 'return [];'], true);
KH_Test::same(false, $no_label['success'], 'a missing label is refused — it is what the owner reads on the review card');

$broken = Keelhob_Abilities::propose(['name' => 'x_tool', 'label' => 'X', 'description' => 'd', 'code' => 'return [ ;'], true);
KH_Test::same(false, $broken['success'], 'code that does not parse is refused and NOT stored');
KH_Test::contains('Syntax error', $broken['error'], 'with the parse error handed back so the model can fix it');

KH_Test::group('Abilities — a built-in tool can never be rewritten');

/*
 * A registry built here holds no built-in tools — Keelhob::boot() is what
 * registers those, and it does not run in this suite — so one stands in for
 * them. The thing under test is the rule, not the inventory: is_builtin() asks
 * the registry, so anything already in it is off limits to a proposal.
 */
$registry = new Keelhob_Tool_Registry();
$registry->add('kh_stand_in_builtin', 'A tool that is already registered.', [], static function (array $args): array {
    return ['ok' => true];
});
Keelhob::instance()->registry = $registry;

KH_Test::ok(Keelhob_Abilities::is_builtin('kh_stand_in_builtin'), 'a name already in the registry is recognised as built-in');
KH_Test::ok(!Keelhob_Abilities::is_builtin('kh_never_registered'), 'and a name that is not in it is not');
$shadow = Keelhob_Abilities::propose(['name' => 'kh_stand_in_builtin', 'label' => 'X', 'description' => 'd', 'code' => 'return [];'], true);
KH_Test::same(false, $shadow['success'], 'so a proposal that would shadow it is refused');
KH_Test::contains('built-in', $shadow['error'], 'and the model is pointed at lesson_save instead');
KH_Test::contains('lesson_save', $shadow['error'], 'by name');

KH_Test::group('Abilities — the input schema the model sends is normalised, never trusted');

KH_Test::same('', Keelhob_Abilities::normalize_schema(null), 'nothing in, nothing stored');
KH_Test::same('', Keelhob_Abilities::normalize_schema('   '), 'an empty string is nothing too');
KH_Test::same('', Keelhob_Abilities::normalize_schema('not json'), 'and so is a string that is not JSON');

$decoded = json_decode(Keelhob_Abilities::normalize_schema('{"type":"object","properties":{"limit":{"type":"integer"}}}'), true);
KH_Test::same('object', $decoded['type'], 'a JSON STRING is decoded rather than stored as a quoted string — which would make every tools/list for that ability malformed');
KH_Test::same('integer', $decoded['properties']['limit']['type'], 'and its properties survive');

$wrapped = json_decode(Keelhob_Abilities::normalize_schema(['limit' => ['type' => 'integer']]), true);
KH_Test::same('object', $wrapped['type'], 'a bare property bag is wrapped in {type:object}');
KH_Test::same('integer', $wrapped['properties']['limit']['type'], 'with the properties moved where a JSON Schema expects them');

KH_Test::group('Abilities — the inputs the owner reads on the card');

KH_Test::same([], Keelhob_Admin_Abilities::input_lines(''), 'no schema reads as no inputs');
KH_Test::same([], Keelhob_Admin_Abilities::input_lines('garbage'), 'and so does a schema that will not decode');
KH_Test::same(
    ['limit (integer) *', 'status (string)'],
    Keelhob_Admin_Abilities::input_lines('{"type":"object","properties":{"limit":{"type":"integer"},"status":{"type":"string"}},"required":["limit"]}'),
    'each input is named with its type, and the required ones are starred'
);

KH_Test::group('Abilities — versions can never shadow each other');

$rows = [
    ['name' => 'a_tool', 'version' => 1, 'status' => 'retired'],
    ['name' => 'a_tool', 'version' => 3, 'status' => 'active'],
    ['name' => 'a_tool', 'version' => 2, 'status' => 'retired'],
    ['name' => 'b_tool', 'version' => 1, 'status' => 'active'],
    ['name' => '', 'version' => 9, 'status' => 'active'],
];
$newest = Keelhob_Abilities::newest_per_name($rows);
KH_Test::same(2, count($newest), 'one row survives per name');
KH_Test::same('a_tool', $newest[0]['name'], 'sorted by name, so the screen does not reorder itself between loads');
KH_Test::same(3, (int) $newest[0]['version'], 'and it is the highest version');
KH_Test::same(1, (int) $newest[1]['version'], 'for each name independently');

KH_Test::group('Abilities — the rating is kept per VERSION');

KH_Test::same('ability:a_tool:v2', Keelhob_Abilities::stats_key('a_tool', 2), 'the key names the version, because "is the new one better?" is the question');
KH_Test::ok(
    Keelhob_Abilities::stats_key('a_tool', 1) !== Keelhob_Abilities::stats_key('a_tool', 2),
    'so two versions of one ability cannot share a score'
);
KH_Test::same('create_post', Keelhob_Tool_Registry::stats_key('create_post'), 'a built-in tool is not aliased, so nothing about its rating changes');

Keelhob_Tool_Registry::set_stats_key('kh_probe_tool', 'ability:kh_probe_tool:v7');
KH_Test::same('ability:kh_probe_tool:v7', Keelhob_Tool_Registry::stats_key('kh_probe_tool'), 'an aliased tool tallies under its version key');

KH_Test::group('Abilities — the emergency kill switch covers these too');

// KEELHOB_SNIPPETS_OFF is a constant and cannot be defined mid-suite without
// poisoning every later test, so the branch is asserted through the predicate
// the branch reads. The live proof is on the rig.
KH_Test::same(
    defined('KEELHOB_SNIPPETS_OFF') && KEELHOB_SNIPPETS_OFF,
    Keelhob_Abilities::kill_switch_on(),
    'abilities read the SAME wp-config constant snippets do — one switch for every piece of agent-written code'
);

kh_test_reset_options([]);
