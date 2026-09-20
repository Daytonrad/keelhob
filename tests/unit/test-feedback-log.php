<?php
/**
 * The local friction log — what is written, and what is emphatically not.
 *
 * The promise on the settings screen is one sentence: "switch this off and
 * nothing is recorded at all." Not "recorded but not sent" — a promise with a
 * "but" in it is one nobody believes. This file is what holds the promise to
 * one sentence.
 */

/** A $wpdb that remembers what it was asked to write. */
class KH_Test_WPDB_Feedback extends KH_Test_WPDB
{
    /** @var array<int, array{table:string,data:array}> */
    public array $inserts = [];
    /** @var string[] */
    public array $queries = [];
    public int $row_count = 0;

    public function insert($table, $data)
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return 1;
    }

    public function query($query)
    {
        $this->queries[] = (string) $query;
        return 1;
    }

    public function get_var($query, $column = 0, $row = 0)
    {
        return $this->row_count;
    }
}

$kh_real_wpdb        = $GLOBALS['wpdb'];
$db                  = new KH_Test_WPDB_Feedback();
$GLOBALS['wpdb']     = $db;

KH_Test::group('Feedback log — off means nothing is recorded, not "recorded quietly"');

Keelhob::instance()->update(['feedback_log' => false]);
KH_Test::ok(!Keelhob_Feedback::enabled(), 'the setting reads as off');

$db->inserts = [];
Keelhob_Feedback::record(['friction' => 'tool_failed', 'tool' => 'upload_media', 'note' => 'timed out']);
KH_Test::same(0, count($db->inserts), 'record() wrote nothing while the setting is off');

Keelhob_Feedback::observe_call('upload_media', ['a' => 1], ['success' => false, 'error' => 'timed out']);
KH_Test::same(0, count($db->inserts), 'automatic capture wrote nothing either');

KH_Test::group('Feedback log — on, and only the agreed vocabulary is written');

Keelhob::instance()->update(['feedback_log' => true]);
KH_Test::ok(Keelhob_Feedback::enabled(), 'the setting reads as on');

$db->inserts = [];
KH_Test::ok(Keelhob_Feedback::record(['friction' => 'tool_failed', 'tool' => 'upload_media']), 'a known friction type is written');
KH_Test::same(1, count($db->inserts), 'exactly one row');

$db->inserts = [];
KH_Test::ok(!Keelhob_Feedback::record(['friction' => 'user_seemed_annoyed']), 'an invented friction type is refused');
KH_Test::same(0, count($db->inserts), 'and nothing was written for it');

// A free-text friction type would become a free-text field in a public
// aggregate count, which is exactly the thing this design does not have.
foreach (Keelhob_Feedback::FRICTIONS as $known) {
    $db->inserts = [];
    Keelhob_Feedback::record(['friction' => $known]);
    KH_Test::same(1, count($db->inserts), 'the shipped type "' . $known . '" is accepted');
}

KH_Test::group('Feedback log — a note is redacted on the way in, not only on the way out');

$db->inserts = [];
Keelhob_Feedback::record([
    'friction' => 'wrong_visual_result',
    'note'     => 'the hero on https://acme-dental.com was wrong, email sam@acme-dental.com',
    'source'   => 'agent',
]);
$row = $db->inserts[0]['data'];
KH_Test::ok(strpos($row['note'], 'acme-dental.com') === false, 'the domain never reaches the owner\'s own database');
KH_Test::ok(strpos($row['note'], 'sam@') === false, 'nor does the email address');
KH_Test::same(1, (int) $row['redacted'], 'and the row records that something was stripped');
KH_Test::same('agent', $row['source'], 'an agent-written note is marked as such, so automatic facts stay distinguishable');

$db->inserts = [];
Keelhob_Feedback::record(['friction' => 'docs_gap', 'note' => str_repeat('x', 500)]);
KH_Test::ok(mb_strlen($db->inserts[0]['data']['note']) <= Keelhob_Feedback::NOTE_MAX, 'a long note is cut to the cap');

$db->inserts = [];
Keelhob_Feedback::record(['friction' => 'docs_gap', 'source' => 'whatever-it-likes']);
KH_Test::same('auto', $db->inserts[0]['data']['source'], 'an unknown source falls back to auto rather than being stored');

KH_Test::group('Feedback log — automatic capture reads tool results honestly');

KH_Test::same('', Keelhob_Feedback::error_text(['success' => true, 'id' => 5]), 'a successful result is not friction');
KH_Test::same('timed out', Keelhob_Feedback::error_text(['success' => false, 'error' => 'timed out']), 'an explicit failure is read');
KH_Test::same('bad path', Keelhob_Feedback::error_text(['error' => 'bad path']), 'an error key with no success flag still counts');

// Telling these apart matters: the first is a bug in the plugin, the second is
// the capability fence working as designed. Rolled together, the fence takes
// the blame for bugs it did not cause and gets switched off.
KH_Test::same('tool_failed', Keelhob_Feedback::classify_error('connection reset by peer'), 'a broken tool is a failure');
KH_Test::same('policy_blocked', Keelhob_Feedback::classify_error('execute_sql is read-only here'), 'a policy refusal is not a failure');
KH_Test::same('policy_blocked', Keelhob_Feedback::classify_error('that capability is disabled'), 'a switched-off capability is not a failure');

KH_Test::group('Feedback log — the "wrong again" loop is one row, not five');

Keelhob_Feedback::reset_repeats();
$db->inserts = [];
for ($i = 0; $i < 5; $i++) {
    Keelhob_Feedback::observe_call('elementor_page', ['page' => 12, 'section' => 'hero'], ['success' => true]);
}
KH_Test::same(1, count($db->inserts), 'five near-identical calls produce exactly one repeated_correction row');
KH_Test::same('repeated_correction', $db->inserts[0]['data']['friction'], 'and it is recorded as a repeated correction');
KH_Test::ok((int) $db->inserts[0]['data']['attempts'] >= 3, 'the attempt count is carried, so severity is visible');

Keelhob_Feedback::reset_repeats();
$db->inserts = [];
Keelhob_Feedback::observe_call('elementor_page', ['page' => 12], ['success' => true]);
Keelhob_Feedback::observe_call('elementor_page', ['page' => 12], ['success' => true]);
KH_Test::same(0, count($db->inserts), 'two attempts is ordinary work, not friction');

Keelhob_Feedback::reset_repeats();
$db->inserts = [];
for ($i = 0; $i < 4; $i++) {
    Keelhob_Feedback::observe_call('create_post', ['title' => 'Post ' . $i], ['success' => true]);
}
KH_Test::same(0, count($db->inserts), 'four DIFFERENT calls to the same tool are not a loop');

KH_Test::group('Feedback log — retention');

KH_Test::same(0, Keelhob_Feedback::trim_count(50, 50), 'exactly at the cap sheds nothing');
KH_Test::same(1, Keelhob_Feedback::trim_count(51, 50), 'one over the cap sheds exactly one');
KH_Test::same(10, Keelhob_Feedback::trim_count(60, 50), 'ten over sheds ten');
KH_Test::same(0, Keelhob_Feedback::trim_count(3, 50), 'an almost-empty log sheds nothing');

$db->queries   = [];
$db->row_count = 0;
Keelhob_Feedback::prune();
$joined = implode(' | ', $db->queries);
KH_Test::contains('DELETE FROM', $joined, 'pruning deletes');
KH_Test::contains('created_at <', $joined, 'and it prunes by age as well as by count');

KH_Test::same(30, Keelhob_Feedback::MAX_AGE_DAYS, 'the age window is 30 days — tighter than the 90 the server keeps, because this sits on somebody else\'s site');
KH_Test::same(50, Keelhob_Feedback::MAX_ROWS, 'and the row cap is 50');

KH_Test::group('Feedback log — a provider that refuses OUR request is recorded');

/*
 * WHY THIS EXISTS.
 *
 * Three separate Gemini bugs -- an integer enum, an array with no items, and an
 * empty args list -- each broke EVERY conversation on that provider, and this
 * log saw none of them. observe_call() is fed by the tool registry, and an LLM
 * HTTP 400 never touches a tool. The owner found all three by hand, in a live
 * chat, one at a time.
 */
Keelhob::instance()->update(['feedback_log' => true]);

$db->inserts = [];
Keelhob_Feedback::observe_llm_error('google', 400, 'Unknown name "args" at contents[3]: Proto field is not repeating, cannot start list.');
KH_Test::same(1, count($db->inserts), 'a 400 from the provider is recorded');
KH_Test::same('llm_rejected', (string) ($db->inserts[0]['data']['friction'] ?? ''), 'under its own friction type, not lumped in with tool failures');
KH_Test::same('llm_google', (string) ($db->inserts[0]['data']['tool'] ?? ''), 'naming which provider refused');
KH_Test::contains('Proto field is not repeating', (string) ($db->inserts[0]['data']['note'] ?? ''), "and keeping the vendor's own words, which are what makes it findable");

// The codes that are NOT our bug. Logging these would bury the one that is.
foreach ([[401, 'wrong key'], [403, 'forbidden'], [429, 'quota'], [500, 'vendor outage'], [null, 'network down']] as $case) {
    [$code, $what] = $case;
    $db->inserts = [];
    Keelhob_Feedback::observe_llm_error('google', $code, $what);
    KH_Test::same(0, count($db->inserts), 'a ' . ($code === null ? 'connection' : $code) . ' failure is not recorded — ' . $what . ' is not a plugin bug');
}

$db->inserts = [];
Keelhob_Feedback::observe_llm_error('anthropic', 422, 'unprocessable');
KH_Test::same(1, count($db->inserts), '422 counts too — it also means the request we built was wrong');

// The promise still holds: off means nothing at all.
Keelhob::instance()->update(['feedback_log' => false]);
$db->inserts = [];
Keelhob_Feedback::observe_llm_error('google', 400, 'refused');
KH_Test::same(0, count($db->inserts), 'and with the log off, not even this is written');
Keelhob::instance()->update(['feedback_log' => true]);

$GLOBALS['wpdb'] = $kh_real_wpdb;
