<?php
/**
 * Adopting the data left behind by the previous product name.
 *
 * This is the only part of the rename that can destroy something. Everything
 * else either compiles or does not. Here a wrong condition means a site owner
 * opens their Snippets screen after updating and finds it empty, with the rows
 * still on disk under a name nothing reads any more.
 *
 * So the cases tested are the ones that lose data, not the happy path:
 *
 *   - it must not run while the old plugin is still switched on
 *   - it must not overwrite a table that already exists under the new name
 *   - it must not overwrite an option already written under the new name
 *   - it must not run twice
 *
 * The happy path is checked too, but it is the least interesting assertion in
 * the file.
 */

/** A $wpdb that pretends the old tables exist and records what it was asked to do. */
class KH_Adopt_WPDB extends KH_Test_WPDB
{
    /** @var string[] tables that "exist" */
    public array $existing = [];
    /** @var string[] every query that ran */
    public array $queries = [];

    public string $postmeta = 'wp_postmeta';
    public string $options  = 'wp_options';

    /**
     * The shared double's prepare() returns the query with the placeholders
     * still in it, which threw the table name away and made every table look
     * absent. Substituting for real is both closer to $wpdb and the only way
     * this file can assert on what was actually asked.
     */
    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace(
                '/%[sd]/',
                is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'",
                $query,
                1
            ) ?? $query;
        }
        return $query;
    }

    public string $last_error = '';

    public function suppress_errors($suppress = true)
    {
        return false;
    }

    /**
     * Existence is now asked as "SELECT 1 FROM `x` LIMIT 1", and answered by
     * whether last_error was set. The double mirrors that: a table it does not
     * hold produces an error, exactly as the database would.
     */
    /** @var array<string,int> rows per table, defaulting to 0 */
    public array $counts = [];

    public function get_var($query, $column = 0, $row = 0)
    {
        if (preg_match('/SELECT 1 FROM `([^`]+)` LIMIT 1/', (string) $query, $m)) {
            $this->last_error = in_array($m[1], $this->existing, true) ? '' : "Table '{$m[1]}' doesn't exist";
            return null;
        }
        if (preg_match('/SELECT COUNT\(\*\) FROM `([^`]+)`/', (string) $query, $m)) {
            return (string) ($this->counts[$m[1]] ?? 0);
        }
        return null;
    }

    /**
     * Columns, so the copy fallback can be exercised here and not only on the
     * rig. Every table answers the same shape unless a test says otherwise:
     * what the copy needs is a non-empty intersection, not a real schema.
     *
     * @var array<string,string[]>
     */
    public array $columns = [];

    /** @var string[] default columns for any table with no entry above */
    public array $default_columns = ['id', 'name', 'body'];

    public function get_col($query, $column = 0)
    {
        if (preg_match('/SHOW COLUMNS FROM `([^`]+)`/', (string) $query, $m)) {
            if (!in_array($m[1], $this->existing, true)) {
                return [];
            }
            return $this->columns[$m[1]] ?? $this->default_columns;
        }
        return [];
    }

    public function query($query)
    {
        $q             = (string) $query;
        $this->queries[] = $q;

        // A RENAME makes the new table start existing and the old one stop.
        // Both dialects, because the production code tries both.
        if (preg_match('/RENAME TABLE `([^`]+)` TO `([^`]+)`/', $q, $m)
            || preg_match('/ALTER TABLE `([^`]+)` RENAME TO `([^`]+)`/', $q, $m)) {
            if (in_array($m[1], $this->existing, true)) {
                $this->existing        = array_values(array_diff($this->existing, [$m[1]]));
                $this->existing[]      = $m[2];
                $this->counts[$m[2]]   = $this->counts[$m[1]] ?? 0;
                unset($this->counts[$m[1]]);
            }
            return 1;
        }

        // The copy fallback: rows land in the destination.
        if (preg_match('/INSERT INTO `([^`]+)` \([^)]*\) SELECT .* FROM `([^`]+)`/s', $q, $m)) {
            $this->counts[$m[1]] = ($this->counts[$m[1]] ?? 0) + ($this->counts[$m[2]] ?? 0);
            return $this->counts[$m[2]] ?? 0;
        }

        // And only then does the original go.
        if (preg_match('/DROP TABLE `([^`]+)`/', $q, $m)) {
            $this->existing = array_values(array_diff($this->existing, [$m[1]]));
            unset($this->counts[$m[1]]);
            return 1;
        }

        // install_table() creating the destination during a copy.
        if (preg_match('/CREATE TABLE `?([A-Za-z0-9_]+)`?\s*\(/i', $q, $m)
            && !in_array($m[1], $this->existing, true)) {
            $this->existing[] = $m[1];
        }

        return 1;
    }
}

$kh_real_wpdb = $GLOBALS['wpdb'];

/** Ten legacy tables, nothing yet under the new name. A site mid-rename. */
$fresh_legacy = static function (): KH_Adopt_WPDB {
    $db = new KH_Adopt_WPDB();
    foreach (['agent_runs', 'chat', 'feedback', 'log', 'memory', 'skills',
              'snippet_log', 'snippet_revisions', 'snippets', 'tasks'] as $i => $t) {
        $name = 'wp_' . 'claud' . 'press_' . $t;
        $db->existing[] = $name;
        // A distinct count each, so a table that ends up holding another
        // table's rows is visible rather than plausible.
        $db->counts[$name] = $i + 1;
    }
    return $db;
};

$legacy_opt = 'claud' . 'press_';

// ---------------------------------------------------------------------------
KH_Test::group('Adoption refuses to run while the old plugin is still active');

/*
 * THE OUTAGE THIS PREVENTS. Renaming tables out from under a running plugin
 * does not warn: it throws a database error on every single request, front end
 * included. If both plugins are installed and the old one is still on, the
 * only safe action is to do nothing.
 */
kh_test_reset_options();
$GLOBALS['wpdb'] = $db = $fresh_legacy();
update_option('active_plugins', [$legacy_opt === '' ? '' : 'claud' . 'press/claud' . 'press.php', 'woocommerce/woocommerce.php']);
update_option($legacy_opt . 'settings', ['agent_api_key' => 'sk-keep-me']);

$report = Keelhob_Legacy_Adopt::run();
KH_Test::same('the previous plugin is still active', $report['skipped'] ?? '', 'it declines, and says why');
KH_Test::same([], $report['tables'], 'no table was renamed');
KH_Test::same([], $db->queries, 'not a single query ran');
KH_Test::same(['agent_api_key' => 'sk-keep-me'], get_option($legacy_opt . 'settings'), 'the old settings are untouched');

// ---------------------------------------------------------------------------
KH_Test::group('Adoption moves everything once the old plugin is off');

kh_test_reset_options();
$GLOBALS['wpdb'] = $db = $fresh_legacy();
update_option('active_plugins', ['woocommerce/woocommerce.php']);
update_option($legacy_opt . 'settings', ['agent_api_key' => 'sk-real', 'abilities_enabled' => true]);
update_option($legacy_opt . 'site_id', 'site-abc');

$report = Keelhob_Legacy_Adopt::run();

KH_Test::same(10, count($report['tables']), 'all ten tables were renamed');
KH_Test::ok(!isset($report['skipped']), 'and it did not skip');

$renames = 0;
foreach ($db->queries as $q) {
    if (strpos($q, 'RENAME TABLE') === 0) {
        $renames++;
        KH_Test::ok(strpos($q, 'wp_keelhob_') !== false, 'a rename targets a keelhob table: ' . substr($q, 0, 60));
    }
}
KH_Test::same(10, $renames, 'exactly ten RENAME statements, no more');

// The settings option is the one that matters: it carries the provider keys,
// the hashed connection token and every capability switch.
KH_Test::same(
    ['agent_api_key' => 'sk-real', 'abilities_enabled' => true],
    get_option('keelhob_settings'),
    'the settings came across intact, keys and capability switches included'
);
KH_Test::same('site-abc', get_option('keelhob_site_id'), 'and so did the site id');
KH_Test::same(null, get_option($legacy_opt . 'settings', null), 'the old settings option is gone, not left as a stale twin');
KH_Test::ok(in_array('settings', $report['options'], true), 'the report names what it moved');

// Post meta and the stale schedule.
$joined = implode(' | ', $db->queries);
KH_Test::contains('UPDATE wp_postmeta', $joined, 'post meta keys were rewritten');
KH_Test::contains('REPLACE(meta_key', $joined, 'and by replacing the prefix, not by listing every key');
KH_Test::contains('DELETE FROM wp_options', $joined, 'legacy transients were dropped');
KH_Test::ok($report['cron'], 'the old scheduled hook was cleared rather than left pointing at nothing');

// ---------------------------------------------------------------------------
KH_Test::group('Adoption never clobbers data already under the new name');

kh_test_reset_options();
$GLOBALS['wpdb'] = $db = $fresh_legacy();
update_option('active_plugins', []);
// This site has ALREADY been written to under the new name, AND the table holds
// rows. Older data must not be allowed to overwrite it: that is a regression
// dressed as a migration.
//
// The row count matters. An EMPTY table under the new name is a shell, not
// data: Keelhob_Snippets::install_table() creates snippets and
// snippet_revisions together, so one iteration leaves an empty sibling behind,
// and treating that as "already migrated" abandoned the real rows to protect
// nothing.
$db->existing[] = 'wp_keelhob_snippets';
$db->counts['wp_keelhob_snippets'] = 5;
update_option($legacy_opt . 'settings', ['agent_api_key' => 'sk-OLD']);
update_option('keelhob_settings', ['agent_api_key' => 'sk-CURRENT']);

$report = Keelhob_Legacy_Adopt::run();

KH_Test::ok(!in_array('snippets', $report['tables'], true), 'the snippets table was left alone, because a new one already existed');
KH_Test::same(9, count($report['tables']), 'the other nine still moved');
KH_Test::same(['agent_api_key' => 'sk-CURRENT'], get_option('keelhob_settings'), 'the current settings survived');
KH_Test::same(null, get_option($legacy_opt . 'settings', null), 'and the superseded copy was cleaned up');

// ---------------------------------------------------------------------------
KH_Test::group('An empty table under the new name does not block the move');

/*
 * THE BUG THIS PINS, found on the rig.
 *
 * Keelhob_Snippets::install_table() creates BOTH snippets and
 * snippet_revisions. The loop reaches snippet_revisions first, its installer
 * leaves an empty keelhob_snippets behind, and the next iteration then saw a
 * destination that "already existed" and walked away from the owner's real
 * snippets to protect a table with nothing in it.
 */
kh_test_reset_options();
$GLOBALS['wpdb'] = $db = $fresh_legacy();
update_option('active_plugins', []);
$db->existing[] = 'wp_keelhob_snippets';   // exists
$db->counts['wp_keelhob_snippets'] = 0;    // and is empty

$report = Keelhob_Legacy_Adopt::run();
KH_Test::ok(in_array('snippets', $report['tables'], true), 'the snippets rows still moved into the empty shell');
KH_Test::same(10, count($report['tables']), 'and every table moved, not nine');

// ---------------------------------------------------------------------------
KH_Test::group('Adoption runs exactly once');

$db->queries = [];
$second = Keelhob_Legacy_Adopt::run();
KH_Test::same('already adopted', $second['skipped'] ?? '', 'a second call declines');
KH_Test::same([], $db->queries, 'and touches nothing');

kh_test_reset_options();
$GLOBALS['wpdb'] = $kh_real_wpdb;

// ---------------------------------------------------------------------------
KH_Test::group('Adoption never records a success it did not achieve');

/*
 * THE BUG THIS PINS, found on the rig and not by any earlier test.
 *
 * The first version wrote the "done" flag unconditionally. On SQLite every
 * RENAME TABLE silently did nothing, the flag was written anyway, and from then
 * on every boot returned "already adopted" while the real rows sat in tables
 * nothing would ever read again. A migration that reports a success it did not
 * achieve is worse than one that crashes, because nobody goes looking.
 */

/** A database where RENAME is not supported at all, so nothing actually moves. */
class KH_Stubborn_WPDB extends KH_Adopt_WPDB
{
    public function query($query)
    {
        $this->queries[] = (string) $query;
        return 0; // accepted, and did nothing. Exactly what SQLite did.
    }
}

kh_test_reset_options();
$GLOBALS['wpdb'] = $db = new KH_Stubborn_WPDB();
foreach (['agent_runs', 'chat', 'feedback', 'log', 'memory', 'skills',
          'snippet_log', 'snippet_revisions', 'snippets', 'tasks'] as $t) {
    $db->existing[] = 'wp_' . 'claud' . 'press_' . $t;
}
update_option('active_plugins', []);

$report = Keelhob_Legacy_Adopt::run();
KH_Test::same([], $report['tables'], 'nothing moved, and it does not pretend otherwise');
KH_Test::same(10, count($report['incomplete'] ?? []), 'it reports all ten as still to do');
KH_Test::same('', (string) get_option('keelhob_adopted_legacy', ''), 'and it does NOT mark itself done');

// Which means the next boot tries again instead of giving up forever.
$again = Keelhob_Legacy_Adopt::run();
KH_Test::ok(!isset($again['skipped']), 'a later boot retries rather than reporting "already adopted"');

// Both dialects are attempted before giving up: MySQL first, then SQLite's form.
$joined = implode(' | ', $db->queries);
KH_Test::contains('RENAME TABLE', $joined, 'the MySQL form is tried');
KH_Test::contains('ALTER TABLE', $joined, 'and the SQLite form as a fallback');

kh_test_reset_options();
$GLOBALS['wpdb'] = $kh_real_wpdb;
