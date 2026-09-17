<?php
/**
 * Adopt the data left behind by the previous product name.
 *
 * WHY THIS FILE STILL SAYS THE OLD NAME
 *
 * It has to. Every other file in the project was renamed, and a test
 * (tests/unit/test-no-legacy-brand.php) fails the build if the old name
 * appears anywhere outside this file and docs/why-the-name-changed.md. But the
 * data on an existing site is still sitting in tables and options named for
 * the old product, and the only way to move it is to say where it is. Delete
 * the old strings from this file and every existing install silently loses its
 * snippets, skills, memory, tasks, logs and connection token.
 *
 * WHY RENAME TABLE AND NOT COPY
 *
 * RENAME TABLE is atomic and moves the rows without reading them. A copy would
 * double the disk use, take time proportional to the data, and leave a window
 * where a crash means two half-tables and no way to tell which is authoritative.
 *
 * ORDERING MATTERS, AND IT IS EASY TO GET WRONG
 *
 * This must run BEFORE the install_table() calls in Keelhob::maybe_upgrade().
 * Those use dbDelta against the NEW table names. Let them run first and they
 * cheerfully create ten empty tables, after which this class sees the new name
 * already present, declines to rename, and the real data is orphaned. The site
 * then looks like a fresh install with everything gone.
 *
 * Every step is conditional, so running twice is harmless.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Legacy_Adopt
{
    /** The previous table/option/meta prefix. */
    private const LEGACY = 'claudpress';

    /** Set once adoption has completed, so the work is never repeated. */
    private const DONE_FLAG = 'keelhob_adopted_legacy';

    /** Table basenames, without the $wpdb prefix. */
    private const TABLES = [
        'agent_runs', 'chat', 'feedback', 'log', 'memory',
        'skills', 'snippet_log', 'snippet_revisions', 'snippets', 'tasks',
    ];

    /**
     * Options that carry real content. `settings` is the important one: it
     * holds the provider keys, the hashed connection token and every capability
     * switch the owner has set.
     */
    private const OPTIONS = [
        'settings', 'db_version', 'site_id', 'site_schema', 'redirects',
        'robots_extra', 'ai_crawlers', 'llms_txt', 'paid_tier_reset',
        'pro_licence', 'pro_licence_valid',
    ];

    /** The scheduled hook the old name registered. */
    private const CRON_HOOK = 'claudpress_cron_tick';

    /**
     * Move everything, once.
     *
     * Returns a short report of what was moved, so the rig check and the
     * upgrade test can assert on it rather than guessing from side effects.
     *
     * @return array{skipped?:string, tables:string[], options:string[], meta:int, cron:bool}
     */
    public static function run(): array
    {
        $report = ['tables' => [], 'options' => [], 'meta' => 0, 'cron' => false];

        if (get_option(self::DONE_FLAG, '') !== '') {
            return ['skipped' => 'already adopted'] + $report;
        }

        /*
         * REFUSE TO RUN WHILE THE OLD PLUGIN IS STILL SWITCHED ON.
         *
         * Renaming tables out from under a running plugin does not produce a
         * warning, it produces a site that throws a database error on every
         * request. The old plugin has to be deactivated first, and until it is
         * this does nothing at all. docs/why-the-name-changed.md spells out the
         * order for a site owner.
         */
        $active = (array) get_option('active_plugins', []);
        foreach ($active as $entry) {
            if (strpos((string) $entry, self::LEGACY . '/') === 0) {
                return ['skipped' => 'the previous plugin is still active'] + $report;
            }
        }

        global $wpdb;
        if (!isset($wpdb)) {
            return ['skipped' => 'no database handle'] + $report;
        }

        // ------------------------------------------------------------ tables
        foreach (self::TABLES as $base) {
            $old = $wpdb->prefix . self::LEGACY . '_' . $base;
            $new = $wpdb->prefix . 'keelhob_' . $base;

            if (!self::table_exists($old) || self::table_exists($new)) {
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL -- identifiers, not values;
            // both names are built from this class's own constants.
            $wpdb->query("RENAME TABLE `{$old}` TO `{$new}`");
            if (self::table_exists($new)) {
                $report['tables'][] = $base;
            }
        }

        // ----------------------------------------------------------- options
        foreach (self::OPTIONS as $name) {
            $old = self::LEGACY . '_' . $name;
            $new = 'keelhob_' . $name;

            $value = get_option($old, null);
            if ($value === null) {
                continue;
            }
            // An existing new-name option wins. It means this site has already
            // been written to under the new name, and overwriting it with older
            // data would be a regression dressed up as a migration.
            if (get_option($new, null) === null) {
                update_option($new, $value, false);
                $report['options'][] = $name;
            }
            delete_option($old);
        }

        // --------------------------------------------------------- post meta
        // The SEO and schema fields the plugin wrote onto posts and products.
        $like = $wpdb->esc_like('_' . self::LEGACY . '_') . '%';
        $moved = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta}
                    SET meta_key = REPLACE(meta_key, %s, %s)
                  WHERE meta_key LIKE %s",
                '_' . self::LEGACY . '_',
                '_keelhob_',
                $like
            )
        );
        $report['meta'] = max(0, (int) $moved);

        // ---------------------------------------------------------- schedule
        // Not renamed: cleared. Keelhob_Tasks::schedule() puts the new hook
        // back on the next boot, and a stale hook pointing at a callback that
        // no longer exists is a warning in the log every hour forever.
        if (wp_next_scheduled(self::CRON_HOOK)) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            $report['cron'] = true;
        }

        // -------------------------------------------------------- transients
        // Caches and lockout counters. Nothing here is worth carrying over, and
        // leaving them behind would keep an auth lockout alive under a name
        // nothing can clear any more.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_' . self::LEGACY . '_') . '%',
                $wpdb->esc_like('_transient_timeout_' . self::LEGACY . '_') . '%'
            )
        );

        update_option(self::DONE_FLAG, gmdate('c'), false);
        return $report;
    }

    /** Does a table exist? SHOW TABLES, because information_schema is slow on shared hosts. */
    private static function table_exists(string $table): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
