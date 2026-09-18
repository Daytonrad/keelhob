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

    /**
     * Which class owns each table's schema.
     *
     * Only needed by the copy fallback, which has to create the destination
     * with real keys and AUTO_INCREMENT before moving rows into it. Writing the
     * CREATE TABLE out again here would be a second copy of the schema that
     * nobody would remember to update.
     */
    private const INSTALLERS = [
        'agent_runs'        => 'Keelhob_Agent_Runs',
        'chat'              => 'Keelhob_Telegram',
        'feedback'          => 'Keelhob_Feedback',
        'log'               => 'Keelhob_Logger',
        'memory'            => 'Keelhob_Memory',
        'skills'            => 'Keelhob_Skills',
        'snippet_log'       => 'Keelhob_Snippet_Log',
        'snippet_revisions' => 'Keelhob_Snippets',
        'snippets'          => 'Keelhob_Snippets',
        'tasks'             => 'Keelhob_Tasks',
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

            if (!self::table_exists($old)) {
                continue;
            }
            // An EMPTY destination is not data, it is a shell. Keelhob_Snippets
            // ::install_table() creates snippets AND snippet_revisions, so the
            // earlier iteration leaves an empty keelhob_snippets behind and the
            // real rows would be abandoned to protect nothing. The same applies
            // to the tables activation creates before adoption gets a turn.
            if (self::table_exists($new) && self::row_count($new) > 0) {
                continue;
            }
            // TWO DIALECTS. MySQL takes RENAME TABLE. SQLite, which the test
            // rig runs on through a drop-in, only understands
            // ALTER TABLE ... RENAME TO and silently does nothing with the
            // first form. Try the standard one, then fall back, then verify --
            // never assume a statement worked because it did not error.
            // phpcs:ignore WordPress.DB.PreparedSQL -- identifiers, not values;
            // both names are built from this class's own constants.
            $wpdb->query("RENAME TABLE `{$old}` TO `{$new}`");
            if (!self::table_exists($new)) {
                // phpcs:ignore WordPress.DB.PreparedSQL
                $wpdb->query("ALTER TABLE `{$old}` RENAME TO `{$new}`");
            }
            if (!self::table_exists($new)) {
                // Neither rename worked. Measured on the test rig: the SQLite
                // drop-in errors on RENAME TABLE and accepts
                // ALTER TABLE ... RENAME TO while silently doing nothing. Copy
                // the rows instead.
                self::copy_table($base, $old, $new);
                $report['copied'][] = $base;
            }
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

        /*
         * ONLY DECLARE VICTORY IF THERE IS NOTHING LEFT TO MOVE.
         *
         * The first version set this flag unconditionally. On the rig the
         * renames all silently failed (wrong SQL dialect), the flag was written
         * anyway, and every later boot returned "already adopted" while the
         * real rows sat in tables nothing would ever read again. A migration
         * that records success it did not achieve is worse than one that
         * crashes, because nobody goes looking.
         *
         * Leaving the flag unset simply means the next page load tries again.
         * Every step above is conditional, so retrying costs nothing.
         */
        $stragglers = [];
        $orphaned   = [];
        foreach (self::TABLES as $base) {
            if (!self::table_exists($wpdb->prefix . self::LEGACY . '_' . $base)) {
                continue;
            }
            // An old table left behind BECAUSE a new one already existed is a
            // deliberate refusal, not a failure. Retrying would never change
            // it. Anything else genuinely did not move.
            if (self::table_exists($wpdb->prefix . 'keelhob_' . $base)) {
                $orphaned[] = $base;
            } else {
                $stragglers[] = $base;
            }
        }
        if ($orphaned !== []) {
            $report['orphaned'] = $orphaned;
        }
        if ($stragglers !== []) {
            $report['incomplete'] = $stragglers;
            return $report;
        }

        update_option(self::DONE_FLAG, gmdate('c'), false);
        return $report;
    }

    /**
     * Build the destination properly, move the rows, and only then let go of
     * the original.
     *
     * The order is the whole point. A copy that drops the source before proving
     * the rows landed is not a migration, it is a deletion with extra steps. So
     * the old table survives until the new one is provably holding at least as
     * many rows, and if anything goes wrong the site still has its data under
     * the old name and run() will decline to mark itself finished.
     *
     * Columns are matched BY NAME, never by position. A site upgrading from an
     * older release can have fewer columns than the current schema, and
     * "INSERT INTO new SELECT * FROM old" would either fail outright or, worse,
     * shift every value one column to the left.
     */
    private static function copy_table(string $base, string $old, string $new): void
    {
        global $wpdb;

        $class = self::INSTALLERS[$base] ?? '';
        if ($class === '' || !is_callable([$class, 'install_table'])) {
            return;
        }
        // The plugin's own CREATE TABLE, so the new table gets its keys and its
        // AUTO_INCREMENT rather than the shapeless thing a bare copy produces.
        call_user_func([$class, 'install_table']);
        if (!self::table_exists($new)) {
            return;
        }

        $shared = array_values(array_intersect(self::columns($old), self::columns($new)));
        if ($shared === []) {
            return;
        }
        $list = '`' . implode('`, `', $shared) . '`';

        // phpcs:ignore WordPress.DB.PreparedSQL -- identifiers only.
        $wpdb->query("INSERT INTO `{$new}` ({$list}) SELECT {$list} FROM `{$old}`");

        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$old}`");
        $after  = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$new}`");
        if ($after >= $before) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("DROP TABLE `{$old}`");
        }
    }

    /** Rows in a table, or 0 if it cannot be read. */
    private static function row_count(string $table): int
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.PreparedSQL -- an identifier, not a value.
        $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        $wpdb->suppress_errors($previous);
        return (int) $count;
    }

    /**
     * Column names, by name.
     *
     * SHOW COLUMNS rather than information_schema: it is what the SQLite
     * drop-in on the test rig actually implements, and it is cheap on MySQL.
     *
     * @return string[]
     */
    private static function columns(string $table): array
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.PreparedSQL -- an identifier, not a value.
        $cols = (array) $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
        $wpdb->suppress_errors($previous);

        return array_values(array_filter(array_map('strval', $cols), static fn(string $c): bool => $c !== ''));
    }

    /**
     * Can we actually read this table?
     *
     * Deliberately not SHOW TABLES or information_schema. Both ask a CATALOG
     * whether a table exists, and a catalog can disagree with the disk: the
     * SQLite drop-in used by the test rig kept listing tables that had already
     * been dropped, so SHOW TABLES said yes and every SELECT said no. The
     * adoption then declined to rename anything, because as far as it could
     * tell the destination was already there.
     *
     * The question that actually matters is "can I read it", so that is the
     * question asked. An empty table returns null with no error, which is
     * still an existing table.
     */
    private static function table_exists(string $table): bool
    {
        global $wpdb;
        $previous          = $wpdb->suppress_errors(true);
        $wpdb->last_error  = '';
        // phpcs:ignore WordPress.DB.PreparedSQL -- an identifier, not a value.
        $wpdb->get_var("SELECT 1 FROM `{$table}` LIMIT 1");
        $error = (string) $wpdb->last_error;
        $wpdb->suppress_errors($previous);
        return $error === '';
    }
}
