<?php
/**
 * Claudpress uninstall — remove options and custom tables. Runs only on delete.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$options = [
    'claudpress_settings',
    'claudpress_redirects',
    'claudpress_site_schema',
    'claudpress_robots_extra',
    'claudpress_db_version',
];
foreach ($options as $opt) {
    delete_option($opt);
}

$tables = [
    $wpdb->prefix . 'claudpress_memory',
    $wpdb->prefix . 'claudpress_skills',
    $wpdb->prefix . 'claudpress_snippets',
    $wpdb->prefix . 'claudpress_log',
];
foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Note: the wp-content/claudpress-sandbox directory and any AI-written meta
// (_claudpress_*) are intentionally left in place, since they may hold work the
// user still wants. Delete them manually if desired.
