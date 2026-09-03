<?php
/**
 * Snippet activity log — retention, paging maths, and who gets the blame.
 *
 * There is no database in this suite, so what is tested here is the logic that
 * decides things: how many rows the ring buffer sheds, what LIMIT/OFFSET the
 * paged readers actually ask for, and how an actor is classified. A spying
 * $wpdb captures the SQL, which is the closest a database-free suite can get to
 * "the query is right".
 */

CP_Test::group('Snippet log — retention');

CP_Test::same(200, Claudpress_Snippet_Log::MAX_ROWS, 'the snippet log is capped at 200 rows');
CP_Test::same(200, Claudpress_Logger::MAX_ROWS, 'the tool-call log was lowered from 500 to 200');

// The boundary, one row either side: a cap that fires at 200 throws away a row
// that is still inside the limit, and one that misses at 201 never trims.
CP_Test::same(0, Claudpress_Snippet_Log::trim_count(0, 200), 'an empty table sheds nothing');
CP_Test::same(0, Claudpress_Snippet_Log::trim_count(199, 200), '199 rows sheds nothing');
CP_Test::same(0, Claudpress_Snippet_Log::trim_count(200, 200), 'exactly 200 rows sheds nothing');
CP_Test::same(1, Claudpress_Snippet_Log::trim_count(201, 200), '201 rows sheds exactly one');
CP_Test::same(50, Claudpress_Snippet_Log::trim_count(250, 200), 'a burst of writes sheds the whole overflow at once');

CP_Test::group('Snippet log — memory is deliberately uncapped');

// Memory is the agent's long-term knowledge of the site. Trimming it makes the
// agent silently forget things a human told it, so this asserts the absence of
// a cap on purpose — if someone adds one, this test is the conversation.
CP_Test::ok(!defined('Claudpress_Memory::MAX_ROWS'), 'Claudpress_Memory has no row cap');
$mem_src = file_get_contents(CLAUDPRESS_DIR . 'includes/class-memory.php');
CP_Test::ok(strpos($mem_src, 'const MAX_ROWS') === false, 'the memory store declares no maximum row count');
// clear() exists and deletes everything, but that is the owner pressing a
// button. What must never appear is an automatic trim of the oldest rows.
CP_Test::ok(strpos($mem_src, 'ORDER BY id ASC LIMIT') === false, 'memory has no ring-buffer trim of its oldest rows');
CP_Test::contains('paged', $mem_src, 'memory is paginated instead');

CP_Test::group('Snippet log — actor detection');

// The agent authenticates as a real administrator, so a non-zero user id proves
// nothing on its own; the route it arrived on is what separates the two.
CP_Test::same('agent', Claudpress_Snippet_Log::actor_for(0, false), 'nobody signed in is the agent');
CP_Test::same('agent', Claudpress_Snippet_Log::actor_for(0, true), 'no user on a machine route is the agent');
CP_Test::same('agent', Claudpress_Snippet_Log::actor_for(1, true), 'an admin id over REST/MCP is still the agent');
CP_Test::same('user', Claudpress_Snippet_Log::actor_for(1, false), 'an admin clicking in wp-admin is the user');

CP_Test::ok(!Claudpress_Snippet_Log::is_machine_request(), 'a plain request is not a machine request');
$GLOBALS['cp_test_current_user'] = 7;
CP_Test::same('user', Claudpress_Snippet_Log::actor(), 'actor() reads the signed-in user in a normal request');
$GLOBALS['cp_test_current_user'] = 0;
CP_Test::same('agent', Claudpress_Snippet_Log::actor(), 'actor() falls to agent when no one is signed in');

CP_Test::group('Snippet log — events');

foreach (['created', 'updated', 'activated', 'deactivated', 'deleted', 'fatal_deactivated'] as $event) {
    CP_Test::ok(in_array($event, Claudpress_Snippet_Log::EVENTS, true), 'the log records "' . $event . '"');
    CP_Test::ok(Claudpress_Snippet_Log::label($event) !== '', '"' . $event . '" has a human label');
}

CP_Test::group('Snippet log — paged reads');

/** A $wpdb that fills in placeholders and remembers what it was asked. */
class CP_Paging_WPDB extends CP_Test_WPDB
{
    public array $queries = [];
    public int $rows      = 0;

    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%[ds]/', is_int($arg) ? (string) $arg : "'" . $arg . "'", $query, 1);
        }
        return $query;
    }

    public function get_results($query, $output = null)
    {
        $this->queries[] = $query;
        return [];
    }

    public function get_var($query, $column = 0, $row = 0)
    {
        $this->queries[] = $query;
        return $this->rows;
    }

    public function query($query)
    {
        $this->queries[] = $query;
        return 0;
    }

    public function insert($table, $data)
    {
        $this->queries[] = 'INSERT INTO ' . $table;
        return 1;
    }
}

$real_wpdb        = $GLOBALS['wpdb'];
$spy              = new CP_Paging_WPDB();
$spy->rows        = 43;
$GLOBALS['wpdb']  = $spy;

// Page 3 at ten a page starts at row 20, not 30 and not 21. This is the exact
// arithmetic the Activity screen hands in, and an off-by-one here silently
// hides or repeats a row on every page after the first.
Claudpress_Snippet_Log::recent(10, (3 - 1) * 10);
CP_Test::contains('LIMIT 10 OFFSET 20', end($spy->queries), 'snippet log page 3 asks for LIMIT 10 OFFSET 20');
CP_Test::contains('ORDER BY id DESC', end($spy->queries), 'snippet log reads newest first');

Claudpress_Snippet_Log::recent(10, 0);
CP_Test::contains('LIMIT 10 OFFSET 0', end($spy->queries), 'page 1 asks for offset 0');

// Negative or zero input can only come from a hand-edited query arg; it must
// clamp rather than produce SQL that errors.
Claudpress_Snippet_Log::recent(0, -5);
CP_Test::contains('LIMIT 1 OFFSET 0', end($spy->queries), 'a nonsense page arg clamps instead of breaking the query');

CP_Test::same(43, Claudpress_Snippet_Log::count_all(), 'count_all() returns the row count as an int');
CP_Test::contains('COUNT(*)', end($spy->queries), 'count_all() counts rather than fetching');

$logger = new Claudpress_Logger();
$logger->recent(10, 20);
CP_Test::contains('LIMIT 10 OFFSET 20', end($spy->queries), 'the tool log pages with the same maths');
CP_Test::same(43, $logger->count_all(), 'the tool log can be counted');

$memory = new Claudpress_Memory();
$memory->paged(10, 20);
CP_Test::contains('LIMIT 10 OFFSET 20', end($spy->queries), 'memory pages with the same maths');
CP_Test::contains('ORDER BY category, mem_key', end($spy->queries), 'memory keeps its grouped ordering when paged');
CP_Test::same(43, $memory->count_all(), 'memory can be counted');

CP_Test::group('Snippet log — writes');

$spy->queries = [];
$spy->rows    = 201;
Claudpress_Snippet_Log::record('cps_abc123', 'Sticky header', 'created');
CP_Test::ok(count($spy->queries) >= 2, 'a recorded event counts the table and trims it');
CP_Test::contains('DELETE FROM', end($spy->queries), 'the 201st row triggers the ring-buffer trim');

$spy->queries = [];
$spy->rows    = 10;
Claudpress_Snippet_Log::record('cps_abc123', 'Sticky header', 'created');
CP_Test::ok(strpos((string) end($spy->queries), 'DELETE FROM') === false, 'a small table is not trimmed');

// An unknown event name is a bug in the caller, not a row worth storing.
$spy->queries = [];
Claudpress_Snippet_Log::record('cps_abc123', 'Sticky header', 'exploded');
CP_Test::same(0, count($spy->queries), 'an event outside the vocabulary is dropped, not written');

$GLOBALS['wpdb'] = $real_wpdb;
unset($GLOBALS['cp_test_current_user']);

CP_Test::group('Snippet log — wired into the snippet lifecycle');

// The log is worthless if nobody calls it, and these are the four places where
// live code on the site changes.
$snip_src = file_get_contents(CLAUDPRESS_DIR . 'includes/class-snippets.php');
foreach ([
    "'created'"           => 'save() records a creation',
    "'updated'"           => 'save() records an edit',
    "'activated'"         => 'toggle() records an activation',
    "'deactivated'"       => 'toggle() records a deactivation',
    "'deleted'"           => 'delete() records a deletion',
    "'fatal_deactivated'" => 'the crash guard records an auto-disable',
] as $needle => $what) {
    CP_Test::contains($needle, $snip_src, $what);
}
CP_Test::ok(
    substr_count($snip_src, 'Claudpress_Snippet_Log::record(') >= 4,
    'every snippet lifecycle path writes to the log'
);

CP_Test::group('Snippet log — the table ships on both tiers');

// Free sites have no Snippets Studio but must still be able to see what the
// agent did to their site. A Pro gate on this page would be a trust problem.
$log_src   = file_get_contents(CLAUDPRESS_DIR . 'includes/class-snippet-log.php');
$admin_src = file_get_contents(CLAUDPRESS_DIR . 'includes/class-admin.php');
$activity  = substr($admin_src, (int) strpos($admin_src, 'public function render_activity'));
$activity  = substr($activity, 0, (int) strpos($activity, 'private static function secret_from_post'));
CP_Test::ok(strpos($log_src, 'is_pro') === false, 'the snippet log has no licence check');
CP_Test::ok(strpos($activity, 'is_pro') === false, 'the Activity page has no licence check');
foreach (['mem_page', 'snip_page', 'log_page'] as $arg) {
    CP_Test::contains($arg, $activity, 'the Activity page pages on ' . $arg);
}
CP_Test::contains('paginate_links', $activity, 'the Activity page uses core pagination');
