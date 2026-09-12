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
class CP_Test_WPDB_Feedback extends CP_Test_WPDB
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

$cp_real_wpdb        = $GLOBALS['wpdb'];
$db                  = new CP_Test_WPDB_Feedback();
$GLOBALS['wpdb']     = $db;

CP_Test::group('Feedback log — off means nothing is recorded, not "recorded quietly"');

Claudpress::instance()->update(['feedback_log' => false]);
CP_Test::ok(!Claudpress_Feedback::enabled(), 'the setting reads as off');

$db->inserts = [];
Claudpress_Feedback::record(['friction' => 'tool_failed', 'tool' => 'upload_media', 'note' => 'timed out']);
CP_Test::same(0, count($db->inserts), 'record() wrote nothing while the setting is off');

Claudpress_Feedback::observe_call('upload_media', ['a' => 1], ['success' => false, 'error' => 'timed out']);
CP_Test::same(0, count($db->inserts), 'automatic capture wrote nothing either');

CP_Test::group('Feedback log — on, and only the agreed vocabulary is written');

Claudpress::instance()->update(['feedback_log' => true]);
CP_Test::ok(Claudpress_Feedback::enabled(), 'the setting reads as on');

$db->inserts = [];
CP_Test::ok(Claudpress_Feedback::record(['friction' => 'tool_failed', 'tool' => 'upload_media']), 'a known friction type is written');
CP_Test::same(1, count($db->inserts), 'exactly one row');

$db->inserts = [];
CP_Test::ok(!Claudpress_Feedback::record(['friction' => 'user_seemed_annoyed']), 'an invented friction type is refused');
CP_Test::same(0, count($db->inserts), 'and nothing was written for it');

// A free-text friction type would become a free-text field in a public
// aggregate count, which is exactly the thing this design does not have.
foreach (Claudpress_Feedback::FRICTIONS as $known) {
    $db->inserts = [];
    Claudpress_Feedback::record(['friction' => $known]);
    CP_Test::same(1, count($db->inserts), 'the shipped type "' . $known . '" is accepted');
}

CP_Test::group('Feedback log — a note is redacted on the way in, not only on the way out');

$db->inserts = [];
Claudpress_Feedback::record([
    'friction' => 'wrong_visual_result',
    'note'     => 'the hero on https://acme-dental.com was wrong, email sam@acme-dental.com',
    'source'   => 'agent',
]);
$row = $db->inserts[0]['data'];
CP_Test::ok(strpos($row['note'], 'acme-dental.com') === false, 'the domain never reaches the owner\'s own database');
CP_Test::ok(strpos($row['note'], 'sam@') === false, 'nor does the email address');
CP_Test::same(1, (int) $row['redacted'], 'and the row records that something was stripped');
CP_Test::same('agent', $row['source'], 'an agent-written note is marked as such, so automatic facts stay distinguishable');

$db->inserts = [];
Claudpress_Feedback::record(['friction' => 'docs_gap', 'note' => str_repeat('x', 500)]);
CP_Test::ok(mb_strlen($db->inserts[0]['data']['note']) <= Claudpress_Feedback::NOTE_MAX, 'a long note is cut to the cap');

$db->inserts = [];
Claudpress_Feedback::record(['friction' => 'docs_gap', 'source' => 'whatever-it-likes']);
CP_Test::same('auto', $db->inserts[0]['data']['source'], 'an unknown source falls back to auto rather than being stored');

CP_Test::group('Feedback log — automatic capture reads tool results honestly');

CP_Test::same('', Claudpress_Feedback::error_text(['success' => true, 'id' => 5]), 'a successful result is not friction');
CP_Test::same('timed out', Claudpress_Feedback::error_text(['success' => false, 'error' => 'timed out']), 'an explicit failure is read');
CP_Test::same('bad path', Claudpress_Feedback::error_text(['error' => 'bad path']), 'an error key with no success flag still counts');

// Telling these apart matters: the first is a bug in the plugin, the second is
// the capability fence working as designed. Rolled together, the fence takes
// the blame for bugs it did not cause and gets switched off.
CP_Test::same('tool_failed', Claudpress_Feedback::classify_error('connection reset by peer'), 'a broken tool is a failure');
CP_Test::same('policy_blocked', Claudpress_Feedback::classify_error('execute_sql is read-only here'), 'a policy refusal is not a failure');
CP_Test::same('policy_blocked', Claudpress_Feedback::classify_error('that capability is disabled'), 'a switched-off capability is not a failure');

CP_Test::group('Feedback log — the "wrong again" loop is one row, not five');

Claudpress_Feedback::reset_repeats();
$db->inserts = [];
for ($i = 0; $i < 5; $i++) {
    Claudpress_Feedback::observe_call('elementor_page', ['page' => 12, 'section' => 'hero'], ['success' => true]);
}
CP_Test::same(1, count($db->inserts), 'five near-identical calls produce exactly one repeated_correction row');
CP_Test::same('repeated_correction', $db->inserts[0]['data']['friction'], 'and it is recorded as a repeated correction');
CP_Test::ok((int) $db->inserts[0]['data']['attempts'] >= 3, 'the attempt count is carried, so severity is visible');

Claudpress_Feedback::reset_repeats();
$db->inserts = [];
Claudpress_Feedback::observe_call('elementor_page', ['page' => 12], ['success' => true]);
Claudpress_Feedback::observe_call('elementor_page', ['page' => 12], ['success' => true]);
CP_Test::same(0, count($db->inserts), 'two attempts is ordinary work, not friction');

Claudpress_Feedback::reset_repeats();
$db->inserts = [];
for ($i = 0; $i < 4; $i++) {
    Claudpress_Feedback::observe_call('create_post', ['title' => 'Post ' . $i], ['success' => true]);
}
CP_Test::same(0, count($db->inserts), 'four DIFFERENT calls to the same tool are not a loop');

CP_Test::group('Feedback log — retention');

CP_Test::same(0, Claudpress_Feedback::trim_count(50, 50), 'exactly at the cap sheds nothing');
CP_Test::same(1, Claudpress_Feedback::trim_count(51, 50), 'one over the cap sheds exactly one');
CP_Test::same(10, Claudpress_Feedback::trim_count(60, 50), 'ten over sheds ten');
CP_Test::same(0, Claudpress_Feedback::trim_count(3, 50), 'an almost-empty log sheds nothing');

$db->queries   = [];
$db->row_count = 0;
Claudpress_Feedback::prune();
$joined = implode(' | ', $db->queries);
CP_Test::contains('DELETE FROM', $joined, 'pruning deletes');
CP_Test::contains('created_at <', $joined, 'and it prunes by age as well as by count');

CP_Test::same(30, Claudpress_Feedback::MAX_AGE_DAYS, 'the age window is 30 days — tighter than the 90 the server keeps, because this sits on somebody else\'s site');
CP_Test::same(50, Claudpress_Feedback::MAX_ROWS, 'and the row cap is 50');

$GLOBALS['wpdb'] = $cp_real_wpdb;
