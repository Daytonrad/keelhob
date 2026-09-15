<?php
/**
 * Step 1 of tests/rig/upgrade-check.sh — make the rig a site that has never
 * seen the new version.
 *
 * WHY A WIPE AND NOT JUST A DOWNGRADE. maybe_upgrade() returns early when
 * `claudpress_db_version` already matches the running version. Copying old
 * files over a new database therefore proves nothing: the migration under test
 * never runs, and every assertion afterwards passes for the wrong reason.
 *
 * SCOPED TO claudpress_. The rig also carries VibeSnip and a WordPress install
 * the owner does not want rebuilt, so this sweeps by prefix and by nothing
 * else — the same approach uninstall.php uses, for the same reason: a
 * hand-written list of table names goes stale the moment somebody adds a table.
 *
 * It also records what the NEIGHBOURING plugin holds, so step 4 can prove the
 * wipe stayed inside its own lane.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// --- what else lives here, so we can prove we did not touch it -------------
$neighbour = [
    'tables' => (int) count((array) $wpdb->get_col(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'vibesnip_') . '%')
    )),
    'posts'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),
    'users'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
];
update_option('cp_upgrade_neighbour', $neighbour, false);

// --- tables ----------------------------------------------------------------
$like   = $wpdb->esc_like($wpdb->prefix . 'claudpress_') . '%';
$tables = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
$dropped = 0;
foreach ($tables as $table) {
    if (strpos((string) $table, $wpdb->prefix . 'claudpress_') !== 0) {
        continue;
    }
    $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table) . '`');
    $dropped++;
}

// --- options and transients ------------------------------------------------
// cp_upgrade_* is this harness's own scratch space and must survive the sweep,
// which is why it is not named claudpress_*.
$opts = (array) $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options}
      WHERE option_name LIKE 'claudpress%'
         OR option_name LIKE '_transient_claudpress%'
         OR option_name LIKE '_transient_timeout_claudpress%'"
);
foreach ($opts as $name) {
    delete_option($name);
}

// --- user meta and cron ----------------------------------------------------
$metas = (array) $wpdb->get_col("SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE 'claudpress%'");
foreach ($metas as $key) {
    delete_metadata('user', 0, $key, '', true);
}

foreach ((array) _get_cron_array() as $ts => $hooks) {
    foreach (array_keys((array) $hooks) as $hook) {
        if (strpos((string) $hook, 'claudpress') === 0) {
            wp_clear_scheduled_hook($hook);
        }
    }
}

$left = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

printf(
    "      dropped %d table(s), %d option(s), %d meta key(s); %d claudpress table(s) left\n",
    $dropped,
    count($opts),
    count($metas),
    count($left)
);
printf(
    "      neighbour before: %d vibesnip table(s), %d post(s), %d user(s)\n",
    $neighbour['tables'],
    $neighbour['posts'],
    $neighbour['users']
);

if (count($left) > 0) {
    echo "      FAILED: claudpress tables survived the wipe\n";
    exit(1);
}
