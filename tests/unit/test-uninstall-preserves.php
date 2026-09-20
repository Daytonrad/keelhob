<?php
/**
 * U1 - uninstall must preserve data unless the owner opted in,
 *      and must remove ALL of it when they did.
 *
 * Deleting a plugin is routinely how someone troubleshoots a conflict: turn it
 * off, delete it, see if the problem goes away, put it back. Before 1.10.1 that
 * ordinary act dropped every table and option - every snippet, memory, skill
 * and scheduled task - permanently, with no warning and no undo. The first half
 * of this file guards that.
 *
 * The second half guards the opposite failure, which is the one that was live
 * in 1.2.0. uninstall.php named six tables in a hand-written list while the
 * plugin created ten, so `keelhob_snippet_revisions`, `keelhob_chat`,
 * `keelhob_agent_runs` and `keelhob_feedback` survived a delete the owner
 * had explicitly asked to be total - and the revisions table still held every
 * version of every snippet they believed they had destroyed. Nine options were
 * in the same position, plus every transient and every user meta key.
 *
 * The fix was to stop listing and start sweeping by prefix, so the file cannot
 * drift again. These checks assert the SWEEP, not a list: the fake database is
 * loaded with tables and options that this test never names in its assertions
 * individually, and the guarantee is that nothing prefixed survives.
 */

KH_Test::group('Uninstall - keeps everything by default');

if (!class_exists('KH_Fake_Wpdb')) {
    /**
     * Enough $wpdb to run uninstall.php, and nothing more.
     *
     * It has to answer SHOW TABLES and a SELECT over wp_options now, because
     * the sweep asks the database what exists rather than being told. The
     * matching is deliberately crude - substring, not real SQL - since the only
     * job here is to hand back the rows the sweep asked about and record what
     * it did with them.
     */
    class KH_Fake_Wpdb
    {
        public $prefix = 'wp_';
        public $options = 'wp_options';
        public $usermeta = 'wp_usermeta';
        public $sitemeta = 'wp_sitemeta';

        /** @var string[] every statement run */
        public $queries = [];
        /** @var string[] tables the fake server reports as existing */
        public $tables = [];
        /** @var string[] option_names the fake server reports as existing */
        public $option_names = [];
        /** @var string[] user meta keys the fake server reports as existing */
        public $meta_keys = [];

        public function query($sql)
        {
            $this->queries[] = $sql;
            return true;
        }

        public function esc_like($text)
        {
            return addcslashes((string) $text, '_%\\');
        }

        /**
         * Substitute %s placeholders, then UNESCAPE the LIKE escaping, so the
         * crude substring matching in get_col() still sees a plain prefix.
         * A real server would treat `keelhob\_` as a literal underscore;
         * here it only has to compare.
         */
        public function prepare($sql, ...$args)
        {
            foreach ($args as $a) {
                $sql = preg_replace('/%s/', stripslashes((string) $a), $sql, 1);
            }
            return $sql;
        }

        public function get_col($sql)
        {
            $hay = static function (array $pool, string $sql) {
                $out = [];
                foreach ($pool as $name) {
                    // Every pattern uninstall.php builds is `<prefix>%`.
                    foreach (self::patterns($sql) as $p) {
                        if (strpos($name, $p) === 0) {
                            $out[] = $name;
                            break;
                        }
                    }
                }
                return $out;
            };

            if (stripos($sql, 'SHOW TABLES') === 0) {
                return $hay($this->tables, $sql);
            }
            if (stripos($sql, 'SELECT option_name') !== false) {
                return $hay($this->option_names, $sql);
            }
            if (stripos($sql, 'usermeta') !== false) {
                return $hay($this->meta_keys, $sql);
            }
            return [];
        }

        /** Pull every `something%` literal out of a prepared statement. */
        private static function patterns(string $sql): array
        {
            preg_match_all('/([A-Za-z_]+)%/', $sql, $m);
            return $m[1] ?? [];
        }
    }
}

/**
 * uninstall.php returns early, so it can be required more than once only if the
 * include cache is bypassed. Reading and eval-ing keeps each run independent.
 *
 * KH_Fake_Wpdb is deliberately minimal, so it must not outlive this file. The
 * suite runs every test in one process, and files are required in alphabetical
 * order: leaving the fake in $GLOBALS['wpdb'] silently handed a crippled
 * database object to every test sorted after "uninstall". That is exactly what
 * happened to test-upgrade-paid-tier.php, whose install_table() calls fatalled
 * on the get_charset_collate() this fake does not have. So the real $wpdb goes
 * back before each run returns, the same way test-snippet-log.php does it.
 */
$run_uninstall = function ($settings, array $world = []) {
    $opts = $settings === null ? [] : ['keelhob_settings' => $settings];
    foreach (($world['options'] ?? []) as $name) {
        $opts[$name] = 'something';
    }
    kh_test_reset_options($opts);
    $GLOBALS['kh_test_transients'] = [];
    $GLOBALS['kh_test_cron']       = $world['cron'] ?? [];

    $real_wpdb            = $GLOBALS['wpdb'];
    $fake                 = new KH_Fake_Wpdb();
    $fake->tables         = $world['tables'] ?? [];
    $fake->option_names   = array_merge(
        $settings === null ? [] : ['keelhob_settings'],
        $world['options'] ?? []
    );
    $fake->meta_keys      = $world['meta'] ?? [];
    $GLOBALS['wpdb']      = $fake;

    try {
        $src = file_get_contents(KEELHOB_DIR . 'uninstall.php');
        $src = preg_replace('/^<\?php/', '', $src, 1);
        $src = str_replace('return;', 'return true;', $src);
        (static function () use ($src) { eval($src); })();
    } finally {
        $GLOBALS['wpdb'] = $real_wpdb;
    }

    return $fake;
};

if (!defined('WP_UNINSTALL_PLUGIN')) { define('WP_UNINSTALL_PLUGIN', true); }

/*
 * Every table the plugin creates, as of 1.2.0. Listed HERE, in the test, rather
 * than in uninstall.php - a list is the right thing in a test (it is the thing
 * being asserted) and the wrong thing in the shipped file (it is a thing to
 * forget).
 */
$KH_ALL_TABLES = [
    'wp_keelhob_memory',
    'wp_keelhob_skills',
    'wp_keelhob_snippets',
    'wp_keelhob_snippet_revisions',
    'wp_keelhob_snippet_log',
    'wp_keelhob_tasks',
    'wp_keelhob_log',
    'wp_keelhob_chat',
    'wp_keelhob_agent_runs',
    'wp_keelhob_feedback',
];

$KH_NEIGHBOURS = ['wp_posts', 'wp_options', 'wp_vibesnip_snippets'];

$db = $run_uninstall(['token_hash' => 'abc', 'agent_api_key' => 'sk-secret'], ['tables' => $KH_ALL_TABLES]);
KH_Test::same(0, count($db->queries), 'a normal install drops no tables');
KH_Test::ok(get_option('keelhob_settings') !== false, '...and keeps its settings, so reinstalling restores the site');

$db = $run_uninstall(['delete_data_on_uninstall' => false], ['tables' => $KH_ALL_TABLES]);
KH_Test::same(0, count($db->queries), 'an explicit "false" also drops nothing');

$db = $run_uninstall(null, ['tables' => $KH_ALL_TABLES]);
KH_Test::same(0, count($db->queries), 'a missing or corrupt settings row is treated as "do not delete"');

KH_Test::group('Uninstall - removes everything when asked');

$db = $run_uninstall(
    ['delete_data_on_uninstall' => true],
    [
        'tables'  => array_merge($KH_ALL_TABLES, $KH_NEIGHBOURS),
        'options' => [
            'keelhob_db_version',
            'keelhob_site_id',
            'keelhob_redirects',
            'keelhob_undo_stack',
            'keelhob_skills_cache',
            'keelhob_tg_pending',
            'keelhob_feedback_retry_after',
            '_transient_keelhob_update_check',
            '_transient_timeout_keelhob_update_check',
        ],
        'meta'    => ['keelhob_hide_abilities_notice'],
        'cron'    => [
            1700000000 => [
                'keelhob_run_tasks' => [['args' => []]],
                'wp_version_check'     => [['args' => []]],
            ],
        ],
    ]
);

$all = implode("\n", $db->queries);

// The four that used to survive are named individually, because they are the
// regression. A green line here is the whole point of the change.
foreach (['snippet_revisions', 'chat', 'agent_runs', 'feedback'] as $was_missed) {
    KH_Test::contains(
        'wp_keelhob_' . $was_missed,
        $all,
        'wp_keelhob_' . $was_missed . ' is dropped (it survived the hand-written list)'
    );
}

// ...and then the guarantee, over the whole set.
foreach ($KH_ALL_TABLES as $table) {
    KH_Test::contains($table, $all, $table . ' is dropped');
}

KH_Test::same(
    count($KH_ALL_TABLES),
    substr_count($all, 'DROP TABLE'),
    'exactly the plugin tables are dropped - no more, no fewer'
);

foreach ($KH_NEIGHBOURS as $other) {
    KH_Test::ok(strpos($all, '`' . $other . '`') === false, $other . ' is left alone');
}

KH_Test::same(false, get_option('keelhob_db_version'), 'a listed option is deleted');
KH_Test::same(false, get_option('keelhob_skills_cache'), 'an option nobody remembered to list is deleted too');
KH_Test::same(false, get_option('_transient_keelhob_update_check'), 'transients go with them');
KH_Test::same(false, get_option('keelhob_settings'), 'the settings row is deleted last');

KH_Test::same(
    [],
    $GLOBALS['kh_test_cron'][1700000000]['keelhob_run_tasks'] ?? [],
    'the plugin unschedules its own cron jobs'
);
KH_Test::ok(
    !empty($GLOBALS['kh_test_cron'][1700000000]['wp_version_check']),
    "...and does not touch anybody else's"
);
