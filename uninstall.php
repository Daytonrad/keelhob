<?php
/**
 * Claudpress uninstall.
 *
 * Deleting a plugin is routinely how someone troubleshoots a conflict: turn it
 * off, delete it, see if the problem goes away, put it back. Before 1.10.1 that
 * ordinary act destroyed every snippet, every memory, every skill and every
 * scheduled task on the site, permanently, with no warning and no undo.
 *
 * So the default is now to KEEP everything. Reinstalling restores the site to
 * exactly where it was. Data is only destroyed when the owner has explicitly
 * ticked "Delete all data on uninstall" in Settings, which carries its own
 * warning.
 *
 * Ordering matters here: the flag lives inside the settings option, so it must
 * be read BEFORE anything is deleted, and the settings option itself must be
 * deleted LAST. Delete it first and the check has destroyed its own answer.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Read the flag first, while the option still exists.
$settings   = get_option('claudpress_settings');
$delete_all = is_array($settings) && !empty($settings['delete_data_on_uninstall']);

if (!$delete_all) {
    // Nothing to do. Snippets, memory, skills, tasks, logs and settings all stay
    // exactly as they are, ready for a reinstall.
    return;
}

$tables = [
    $wpdb->prefix . 'claudpress_memory',
    $wpdb->prefix . 'claudpress_skills',
    $wpdb->prefix . 'claudpress_snippets',
    $wpdb->prefix . 'claudpress_snippet_log',
    $wpdb->prefix . 'claudpress_tasks',
    $wpdb->prefix . 'claudpress_log',
];
foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

$options = [
    'claudpress_redirects',
    'claudpress_site_schema',
    'claudpress_robots_extra',
    'claudpress_db_version',
    'claudpress_undo_stack',
    // claudpress_settings is deliberately absent here and deleted last, below.
];
foreach ($options as $opt) {
    delete_option($opt);
}

delete_transient('claudpress_token_flash');

// Last, because it held the flag that authorised everything above.
delete_option('claudpress_settings');

// The wp-content/claudpress-sandbox directory and any AI-written meta
// (_claudpress_*) are left in place even here: they can hold work the owner
// still wants, and a filesystem wipe is not something to do from an uninstall
// hook. Delete them manually if desired.
