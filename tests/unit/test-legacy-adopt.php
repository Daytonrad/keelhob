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

    public function get_var($query, $column = 0, $row = 0)
    {
        foreach ($this->existing as $table) {
            if (strpos((string) $query, $table) !== false) {
                return $table;
            }
        }
        return null;
    }

    public function query($query)
    {
        $this->queries[] = (string) $query;
        // A RENAME makes the new table start existing and the old one stop.
        if (preg_match('/RENAME TABLE `([^`]+)` TO `([^`]+)`/', (string) $query, $m)) {
            $this->existing = array_values(array_diff($this->existing, [$m[1]]));
            $this->existing[] = $m[2];
        }
        return 1;
    }
}

$kh_real_wpdb = $GLOBALS['wpdb'];

/** Ten legacy tables, nothing yet under the new name. A site mid-rename. */
$fresh_legacy = static function (): KH_Adopt_WPDB {
    $db = new KH_Adopt_WPDB();
    foreach (['agent_runs', 'chat', 'feedback', 'log', 'memory', 'skills',
              'snippet_log', 'snippet_revisions', 'snippets', 'tasks'] as $t) {
        $db->existing[] = 'wp_' . 'claud' . 'press_' . $t;
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
// This site has ALREADY been written to under the new name. Older data must
// not be allowed to overwrite it: that is a regression dressed as a migration.
$db->existing[] = 'wp_keelhob_snippets';
update_option($legacy_opt . 'settings', ['agent_api_key' => 'sk-OLD']);
update_option('keelhob_settings', ['agent_api_key' => 'sk-CURRENT']);

$report = Keelhob_Legacy_Adopt::run();

KH_Test::ok(!in_array('snippets', $report['tables'], true), 'the snippets table was left alone, because a new one already existed');
KH_Test::same(9, count($report['tables']), 'the other nine still moved');
KH_Test::same(['agent_api_key' => 'sk-CURRENT'], get_option('keelhob_settings'), 'the current settings survived');
KH_Test::same(null, get_option($legacy_opt . 'settings', null), 'and the superseded copy was cleaned up');

// ---------------------------------------------------------------------------
KH_Test::group('Adoption runs exactly once');

$db->queries = [];
$second = Keelhob_Legacy_Adopt::run();
KH_Test::same('already adopted', $second['skipped'] ?? '', 'a second call declines');
KH_Test::same([], $db->queries, 'and touches nothing');

kh_test_reset_options();
$GLOBALS['wpdb'] = $kh_real_wpdb;
