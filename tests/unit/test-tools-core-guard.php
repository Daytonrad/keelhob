<?php
/**
 * Claudpress_Tools_Core wiring Claudpress_Health::guard() into execute_php and
 * execute_sql — see HARDENING-PLAN.md task 5.
 *
 * execute_php can do anything and none of it is undoable, so it is guarded on
 * every call that actually ran, report-only ($revert = null). execute_sql is
 * guarded only on its write branch: $is_read is already computed there, and a
 * SELECT/SHOW/DESCRIBE/EXPLAIN/PRAGMA can't take the front end down, so probing
 * one would just be a wasted request on every read.
 */

CP_Test::group('Tools Core — execute_sql guard wiring');

// A $wpdb stub whose query()/get_results() return whatever the test sets, so
// the SQL branch (read vs write vs errored) can be driven directly without a
// real database. Extends the shared CP_Test_WPDB so everything execute_sql
// doesn't touch (prepare(), get_charset_collate(), ...) still behaves.
class CP_Test_WPDB_SQL extends CP_Test_WPDB
{
    public string $last_error = '';
    public int $insert_id = 0;
    public $query_result = 0;
    public array $rows_result = [];

    public function suppress_errors($suppress = true)
    {
        return true;
    }

    public function get_results($query, $output = null)
    {
        return $this->rows_result;
    }

    public function query($query)
    {
        return $this->query_result;
    }
}

$original_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new CP_Test_WPDB_SQL();

// --- a SELECT performs no probe at all --------------------------------------

cp_test_reset_ajax();
cp_test_http_queue(200, ''); // if execute_sql wrongly probes, this gets consumed
$GLOBALS['wpdb']->rows_result = [['ID' => 1]];
$GLOBALS['wpdb']->last_error  = '';

$result = Claudpress_Tools_Core::execute_sql(['query' => 'SELECT * FROM {prefix}posts']);

CP_Test::ok($result['success'], 'the SELECT itself still succeeds');
CP_Test::same(1, count($GLOBALS['cp_test_http']), 'a SELECT leaves the queued HTTP response untouched — no probe, not merely a normal-looking result');
CP_Test::ok(!isset($result['front_end_check']), 'and no front_end_check is added to a read');

// --- an UPDATE/INSERT/DELETE does probe -------------------------------------

cp_test_reset_ajax();
cp_test_http_queue(200, ''); // the probe itself, healthy
$GLOBALS['wpdb']->query_result = 3;
$GLOBALS['wpdb']->last_error   = '';

$result = Claudpress_Tools_Core::execute_sql(['query' => 'UPDATE {prefix}posts SET post_title = "x"']);

CP_Test::ok($result['success'], 'the write itself still succeeds');
CP_Test::same(3, $result['rows_affected'], 'and reports the affected row count');
CP_Test::same(0, count($GLOBALS['cp_test_http']), 'a write consumes the queued probe response');

// --- a failed query does not probe ------------------------------------------

cp_test_reset_ajax();
cp_test_http_queue(200, ''); // must stay untouched — the query errored, nothing happened
$GLOBALS['wpdb']->query_result = false;
$GLOBALS['wpdb']->last_error   = 'You have an error in your SQL syntax';

$result = Claudpress_Tools_Core::execute_sql(['query' => 'UPDATE {prefix}posts SET nope']);

CP_Test::ok(!$result['success'], 'the errored write reports failure');
CP_Test::same(1, count($GLOBALS['cp_test_http']), 'a failed write leaves the queued HTTP response untouched — nothing happened, nothing to check');

$GLOBALS['wpdb'] = $original_wpdb;
cp_test_reset_ajax();

CP_Test::group('Tools Core — execute_php guard wiring');

// --- a healthy site: the result shape is completely unchanged --------------

cp_test_http_queue(200, '');
$result = Claudpress_Tools_Core::execute_php(['code' => 'return 1 + 1;']);

CP_Test::ok($result['success'], 'the code still runs and succeeds');
CP_Test::same(2, $result['return_value'], 'and still returns its value');
CP_Test::ok(!array_key_exists('front_end_check', $result), 'a healthy site adds no front_end_check key');
CP_Test::ok(!array_key_exists('front_end_status', $result), 'nor a front_end_status key');
CP_Test::ok(!array_key_exists('note', $result), 'nor a note — an agent parsing return_value must not see new keys on the happy path');

cp_test_reset_ajax();

// --- a 503 front end: reported, naming the subject --------------------------

cp_test_http_queue(503, '');
$result = Claudpress_Tools_Core::execute_php(['code' => 'return 1;']);

CP_Test::same('FAILED', $result['front_end_check'] ?? null, 'a 503 after running the code is reported as FAILED');
CP_Test::same(503, $result['front_end_status'] ?? null, 'with the status it actually saw');
CP_Test::contains('running that PHP', (string) ($result['note'] ?? ''), 'and the note names the subject responsible');

cp_test_reset_ajax();

// --- code that never ran is not guarded: no code provided -------------------

cp_test_http_queue(503, ''); // must stay untouched — nothing ran
$result = Claudpress_Tools_Core::execute_php(['code' => '   ']);

CP_Test::ok(!$result['success'], 'no code provided is still a failure');
CP_Test::same(1, count($GLOBALS['cp_test_http']), 'and is not guarded — there was nothing to check, so no probe is wasted');

cp_test_reset_ajax();

// --- code that threw is not guarded either: success is false ---------------

cp_test_http_queue(503, ''); // must stay untouched — the eval failed, nothing to attribute
$result = Claudpress_Tools_Core::execute_php(['code' => 'throw new Exception("boom");']);

CP_Test::ok(!$result['success'], 'a thrown exception is still reported as a failure');
CP_Test::same(1, count($GLOBALS['cp_test_http']), 'a fatal inside eval() is not guarded — success:false means there is nothing to check');

cp_test_reset_ajax();
