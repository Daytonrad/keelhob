<?php
/**
 * U1 - uninstall must preserve data unless the owner opted in.
 *
 * Deleting a plugin is routinely how someone troubleshoots a conflict: turn it
 * off, delete it, see if the problem goes away, put it back. Before 1.10.1 that
 * ordinary act dropped every table and option - every snippet, memory, skill
 * and scheduled task - permanently, with no warning and no undo.
 */

CP_Test::group('Uninstall - keeps everything by default');

if (!class_exists('CP_Fake_Wpdb')) {
    class CP_Fake_Wpdb
    {
        public $prefix = 'wp_';
        public $queries = [];
        public function query($sql) { $this->queries[] = $sql; return true; }
    }
}

/**
 * uninstall.php returns early, so it can be required more than once only if the
 * include cache is bypassed. Reading and eval-ing keeps each run independent.
 *
 * CP_Fake_Wpdb is deliberately minimal — uninstall.php only ever calls
 * query() — so it must not outlive this file. The suite runs every test in one
 * process, and files are required in alphabetical order: leaving the fake in
 * $GLOBALS['wpdb'] silently handed a crippled database object to every test
 * sorted after "uninstall". That is exactly what happened to
 * test-upgrade-paid-tier.php, whose install_table() calls fatalled on the
 * get_charset_collate() this fake does not have. So the real $wpdb goes back
 * before each run returns, the same way test-snippet-log.php does it.
 */
$run_uninstall = function ($settings) {
    cp_test_reset_options($settings === null ? [] : ['claudpress_settings' => $settings]);
    $GLOBALS['cp_test_transients'] = [];

    $real_wpdb       = $GLOBALS['wpdb'];
    $fake            = new CP_Fake_Wpdb();
    $GLOBALS['wpdb'] = $fake;

    try {
        $src = file_get_contents(CLAUDPRESS_DIR . 'uninstall.php');
        $src = preg_replace('/^<\?php/', '', $src, 1);
        $src = str_replace('return;', 'return true;', $src);
        (static function () use ($src) { eval($src); })();
    } finally {
        $GLOBALS['wpdb'] = $real_wpdb;
    }

    return $fake;
};

if (!defined('WP_UNINSTALL_PLUGIN')) { define('WP_UNINSTALL_PLUGIN', true); }

$db = $run_uninstall(['token_hash' => 'abc', 'agent_api_key' => 'sk-secret']);
CP_Test::same(0, count($db->queries), 'a normal install drops no tables');
CP_Test::ok(get_option('claudpress_settings') !== false, '...and keeps its settings, so reinstalling restores the site');

$db = $run_uninstall(['delete_data_on_uninstall' => false]);
CP_Test::same(0, count($db->queries), 'an explicit "false" also drops nothing');

$db = $run_uninstall(null);
CP_Test::same(0, count($db->queries), 'a missing or corrupt settings row is treated as "do not delete"');

CP_Test::group('Uninstall - removes everything when asked');

$db  = $run_uninstall(['delete_data_on_uninstall' => true]);
$all = implode(' ', $db->queries);
foreach (['snippets', 'memory', 'skills', 'tasks', 'log'] as $table) {
    CP_Test::contains('wp_claudpress_' . $table, $all, 'wp_claudpress_' . $table . ' is dropped');
}
CP_Test::same(false, get_option('claudpress_settings'), 'the settings row is deleted too');
