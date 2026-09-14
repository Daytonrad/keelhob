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
 *
 * ---------------------------------------------------------------------------
 * WHY THIS SWEEPS BY PREFIX RATHER THAN BY LIST
 *
 * It used to be two hand-written lists. They drifted, exactly the way
 * hand-written lists do: by 1.2.0 the plugin created TEN tables and the list
 * named six, so `claudpress_snippet_revisions`, `claudpress_chat`,
 * `claudpress_agent_runs` and `claudpress_feedback` survived a delete that the
 * owner had explicitly asked to be total. Nine options and every transient were
 * in the same position.
 *
 * That is worse than either honest answer. Someone who ticks "delete all data"
 * and then hands the site over believes the snippets are gone, and the
 * revisions table still holds every version of every one of them.
 *
 * A prefix sweep cannot drift, because nothing has to be remembered. Every
 * table this plugin makes is `{$wpdb->prefix}claudpress_…`, every option and
 * transient is `claudpress_…`, and every user meta key is `claudpress_…`.
 * A new feature that follows those conventions is cleaned up on the day it is
 * written, by a file nobody had to remember to edit.
 *
 * The one thing the sweep must not do is reach outside this plugin, so every
 * pattern below is anchored and escaped, and the table sweep is additionally
 * fenced to this site's own table prefix.
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

/*
 * ---------------------------------------------------------------------- tables
 *
 * SHOW TABLES LIKE, not a list. `_` is a single-character wildcard in SQL LIKE,
 * so it is escaped — unescaped, `claudpress_%` would also match a table called
 * `claudpressXfoo`, which is not ours to drop.
 */
$like   = $wpdb->esc_like($wpdb->prefix . 'claudpress_') . '%';
$tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

foreach ((array) $tables as $table) {
    // Belt and braces. get_col() returns whatever the server says; only drop a
    // name this file could itself have produced.
    if (strpos((string) $table, $wpdb->prefix . 'claudpress_') !== 0) {
        continue;
    }
    $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
}

/*
 * --------------------------------------------------------------------- options
 *
 * Options and transients live in the same table, so one sweep covers
 * `claudpress_*`, `_transient_claudpress_*` and `_transient_timeout_claudpress_*`.
 *
 * `claudpress_settings` is held back and deleted last, because it carries the
 * flag that authorised everything here.
 */
$opt_like = $wpdb->esc_like('claudpress_') . '%';
$names    = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name LIKE %s
            OR option_name LIKE %s
            OR option_name LIKE %s",
        $opt_like,
        $wpdb->esc_like('_transient_claudpress_') . '%',
        $wpdb->esc_like('_transient_timeout_claudpress_') . '%'
    )
);

foreach ((array) $names as $name) {
    if ($name === 'claudpress_settings') {
        continue;
    }
    // delete_option() rather than a DELETE, so the object cache is invalidated
    // too. A dropped row that is still cached comes back on the next request.
    delete_option($name);
}

// Site transients, on a multisite network. These are not in wp_options.
if (is_multisite()) {
    $site_names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT meta_key FROM {$wpdb->sitemeta}
             WHERE meta_key LIKE %s OR meta_key LIKE %s",
            $wpdb->esc_like('_site_transient_claudpress_') . '%',
            $wpdb->esc_like('_site_transient_timeout_claudpress_') . '%'
        )
    );
    foreach ((array) $site_names as $name) {
        delete_site_option($name);
    }
}

/*
 * ------------------------------------------------------------------- user meta
 *
 * Per-user preferences the plugin sets, such as the dismissal fingerprint for
 * the abilities notice. Deleted by key so the object cache for each user is
 * cleared with it.
 *
 * Note the leading-underscore keys that AI writes onto POSTS (`_claudpress_*`)
 * are deliberately NOT touched — see the closing note.
 */
$meta_keys = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like('claudpress_') . '%'
    )
);
foreach ((array) $meta_keys as $key) {
    delete_metadata('user', 0, $key, '', true);
}

/*
 * -------------------------------------------------------------- scheduled jobs
 *
 * A cron event whose callback no longer exists is a warning in every request
 * until WordPress gives up on it. Clearing them is part of leaving cleanly, and
 * unlike the data above it is right to do it whether or not the owner asked for
 * a wipe — but this file only runs for a wipe, and deactivation already clears
 * them on the normal path.
 */
$cron = _get_cron_array();
if (is_array($cron)) {
    foreach ($cron as $timestamp => $hooks) {
        foreach ((array) $hooks as $hook => $events) {
            if (strpos((string) $hook, 'claudpress_') !== 0) {
                continue;
            }
            foreach ((array) $events as $event) {
                wp_unschedule_event($timestamp, $hook, $event['args'] ?? []);
            }
        }
    }
}

// Last, because it held the flag that authorised everything above.
delete_option('claudpress_settings');

/*
 * Deliberately left in place, even here:
 *
 * - `wp-content/claudpress-sandbox/` and anything else on disk. A filesystem
 *   wipe is not something to do from an uninstall hook, and it can hold work
 *   the owner still wants.
 * - `_claudpress_*` post meta. That is AI-written metadata ATTACHED TO POSTS the
 *   owner is keeping — SEO titles, schema, redirect notes. Removing the plugin
 *   should not silently strip metadata off content that stays on the site.
 *
 * Delete either by hand if you want them gone.
 */
