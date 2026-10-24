<?php
/**
 * The run log: what every tool call records, and the two properties it cannot
 * lose.
 *
 * Keelhob_Logger already existed as an audit trail. This track widened it
 * rather than adding a second table beside it — one row per tool call was
 * already the right shape, and a parallel log would have meant two answers to
 * "what did the agent just do to my site".
 *
 * Source reading is not a nicety: "the owner asked for this in a chat" and
 * "the site did this to itself at 4am" are different facts about the same call,
 * and the second is the one an owner most wants to be able to read back.
 */

KH_Test::group('Run log — the schema records everything a run has to carry');

$src = (string) file_get_contents(KEELHOB_DIR . 'includes/class-logger.php');

foreach ([
    'tool VARCHAR(191)'  => 'the tool name',
    'args_json LONGTEXT' => 'the input',
    'output_json LONGTEXT' => 'the output',
    'ok TINYINT(1)'      => 'whether it worked',
    'error TEXT'         => 'the error',
    'duration_ms INT'    => 'how long it took',
    "source VARCHAR(32) NOT NULL DEFAULT 'chat'" => 'what asked for it',
    'feedback TINYINT'   => "the owner's verdict",
    'created_at DATETIME' => 'when',
] as $needle => $what) {
    KH_Test::contains($needle, $src, 'the log stores ' . $what);
}

KH_Test::ok(strpos($src, 'object_id') === false, 'and no column is named object_id');

KH_Test::group('Run log — the new columns reach a site already on this version');

// Keelhob::maybe_upgrade() returns early when the stored db_version equals
// KEELHOB_VERSION, so on any site already recorded at this version — every
// development rig included — install_table() would never run and the columns
// would silently not exist. The schema counter is what fixes that, and it is
// checked BEFORE that early return.
KH_Test::ok(Keelhob_Logger::SCHEMA >= 2, 'the log declares a schema generation of its own');
$boot = (string) file_get_contents(KEELHOB_DIR . 'includes/class-keelhob.php');
KH_Test::ok(
    strpos($boot, 'Keelhob_Logger::maybe_upgrade_schema();') < strpos($boot, "\$stored = get_option('keelhob_db_version'"),
    'and the schema check runs before the version-keyed early return'
);
foreach (['Keelhob_Tool_Stats::maybe_install();', 'Keelhob_Lessons::maybe_install();'] as $call) {
    KH_Test::contains($call, $boot, $call . ' runs on boot');
}
foreach (['Keelhob_Tool_Stats::install_table();', 'Keelhob_Lessons::install_table();'] as $call) {
    KH_Test::same(2, substr_count($boot, $call), $call . ' is in both the upgrade and the activation path');
}

KH_Test::group('Run log — thumbs values');

KH_Test::same(1, Keelhob_Logger::THUMBS_UP, 'thumbs up is 1');
KH_Test::same(-1, Keelhob_Logger::THUMBS_DOWN, 'thumbs down is -1');
KH_Test::same(0, Keelhob_Logger::NO_VOTE, 'and no vote is 0, so an unvoted row is not a vote');

KH_Test::group('Run log — record() still accepts three arguments');

// The browser gate seeds a row with `logger->record($tool, $args, $result)`.
// Breaking that signature would break the only check that proves the Activity
// screen renders rows at all.
$record = new ReflectionMethod('Keelhob_Logger', 'record');
@$record->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor
KH_Test::same(4, $record->getNumberOfParameters(), 'record() takes four parameters');
KH_Test::same(3, $record->getNumberOfRequiredParameters(), 'but only three are required');
KH_Test::same('int', (string) $record->getReturnType(), 'and it returns the new row id, which is what a vote addresses');

KH_Test::group('Run log — the output is redacted on the same path as the input');

// A tool result can echo back what it was given. Redacting arguments and not
// results would put a live credential in output_json instead of args_json,
// which is the same leak through a different column.
KH_Test::ok(
    (bool) preg_match('/\$output\s*=\s*self::redact\(/', $src),
    'the stored output goes through redact() too'
);
KH_Test::ok(
    (bool) preg_match('/strlen\(\$output_json\)\s*>\s*20000/', $src),
    'and is clipped, because this table is read on an admin screen'
);

KH_Test::group('Run log — source is set by the agent runtime, not guessed');

$registry = (string) file_get_contents(KEELHOB_DIR . 'includes/class-tool-registry.php');
KH_Test::contains("private static \$source = 'chat';", $registry, 'the default source is an interactive chat');
KH_Test::contains('Keelhob_Tool_Stats::observe(', $registry, 'the registry tallies every call it dispatches');

$runtime = (string) file_get_contents(KEELHOB_DIR . 'includes/class-agent-runtime.php');
KH_Test::contains('Keelhob_Tool_Registry::set_source($context, $run_id);', $runtime, 'an unattended run declares itself');
KH_Test::ok(
    strpos($runtime, 'Keelhob_Agent_Runs::start(') < strpos($runtime, 'Keelhob_Tool_Registry::set_source('),
    'and does so AFTER start(), which resets the per-request receipt'
);

// Round-trip only. The DEFAULT is asserted from the source text above rather
// than by reading source() here: it is request-scoped static state, another
// test file in the same process may already have run an agent, and an
// assertion whose answer depends on file ordering is worse than none.
Keelhob_Tool_Registry::set_source('task', 7);
KH_Test::same('task', Keelhob_Tool_Registry::source(), 'a declared source is kept');
Keelhob_Tool_Registry::set_source('chat', 0);
KH_Test::same('chat', Keelhob_Tool_Registry::source(), 'and can be put back');
Keelhob_Tool_Registry::set_source('', 0);
KH_Test::same('chat', Keelhob_Tool_Registry::source(), 'and an empty source falls back to "chat" rather than to nothing');

KH_Test::group('Run log — the rating tally survives the log being trimmed');

// The log is a 200-row ring buffer on purpose. A rating computed by grouping
// over it would reset itself every day or two on a busy site, which is worse
// than no rating because it reads as information.
KH_Test::same(200, Keelhob_Logger::MAX_ROWS, 'the log keeps 200 rows');
KH_Test::ok(
    Keelhob_Tool_Stats::table() !== Keelhob_Logger::table(),
    'so the tally lives in its own table, which the trim does not touch'
);
KH_Test::ok(
    (bool) preg_match('/Keelhob_Tool_Stats::observe\([^;]*\);/', $registry)
    && strpos($registry, "if (Keelhob::instance()->get('log_calls'))") < strpos($registry, 'Keelhob_Tool_Stats::observe('),
    'and is updated even when the owner has switched the audit log off — switching off a record of arguments should not blind the agent'
);

KH_Test::group('Run log — a vote on a trimmed row fails visibly');

$vote = new ReflectionMethod('Keelhob_Logger', 'vote');
@$vote->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor
$type = $vote->getReturnType();
KH_Test::ok($type !== null && $type->allowsNull(), 'vote() can return null');
KH_Test::ok(
    (bool) preg_match('/function vote\(.*?\$row === null\) \{\s*return null;/s', $src),
    'and does so when the row is gone, rather than dropping the vote silently'
);
$ratings = (string) file_get_contents(KEELHOB_DIR . 'includes/class-admin-ratings.php');
KH_Test::contains('no longer in the log', $ratings, 'the owner is told the vote was not saved');
