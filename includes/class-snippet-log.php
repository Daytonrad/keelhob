<?php
/**
 * A record of what happened to this site's live snippets, and who did it.
 *
 * Snippets are the one thing an agent installs that keeps running on every page
 * load long after the conversation ended. The owner is entitled to a plain,
 * readable account of that — which is why this table ships on both tiers, even
 * though a free site never sees the Snippets Studio. Being able to see what was
 * done to your site is not a feature, it is the condition for trusting any of
 * this at all.
 *
 * Ring-buffered like Claudpress_Logger: nobody audits a snippet edit from eight
 * months ago, and an unbounded table on a shared host is a bill, not a service.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Snippet_Log
{
    const MAX_ROWS = 200;

    /** The vocabulary. Anything not in here is not written. */
    const EVENTS = ['created', 'updated', 'activated', 'deactivated', 'deleted', 'fatal_deactivated'];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'claudpress_snippet_log';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            snippet_uid VARCHAR(40) NOT NULL DEFAULT '',
            snippet_name VARCHAR(191) NOT NULL DEFAULT '',
            event VARCHAR(32) NOT NULL,
            actor VARCHAR(20) NOT NULL DEFAULT 'user',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            detail TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) {$charset};");
    }

    /**
     * Write one event. Never throws and never blocks the caller: this is called
     * from save/toggle/delete and from the fatal-error shutdown handler, and a
     * logging failure must not be the reason a snippet operation — or the whole
     * request — falls over.
     */
    public static function record(string $uid, string $name, string $event, ?string $detail = null): void
    {
        if (!in_array($event, self::EVENTS, true)) {
            return;
        }
        global $wpdb;
        if (!isset($wpdb)) {
            return;
        }

        $wpdb->insert(self::table(), [
            'snippet_uid'  => mb_substr($uid, 0, 40),
            'snippet_name' => mb_substr($name !== '' ? $name : __('Untitled snippet', 'claudpress'), 0, 191),
            'event'        => $event,
            'actor'        => self::actor(),
            'user_id'      => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'detail'       => $detail === null ? null : mb_substr($detail, 0, 500),
            'created_at'   => current_time('mysql'),
        ]);

        $cut = self::trim_count((int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table()), self::MAX_ROWS);
        if ($cut > 0) {
            $wpdb->query($wpdb->prepare("DELETE FROM " . self::table() . " ORDER BY id ASC LIMIT %d", $cut));
        }
    }

    /**
     * How many rows the ring buffer has to drop. Split out from record() only so
     * the boundary — 200 rows is fine, 201 sheds exactly one — is testable
     * without a database.
     */
    public static function trim_count(int $rows, int $max): int
    {
        return $rows > $max ? $rows - $max : 0;
    }

    /**
     * Was this the owner clicking a button, or the agent working on its own?
     *
     * The user id cannot answer that by itself: Claudpress_Auth signs the agent
     * in as a real administrator (wp_set_current_user), so an agent-driven
     * request looks exactly like the owner's. What does separate them is the
     * door they came through — the agent only ever arrives over the REST/MCP
     * route, on a scheduled run, or with nobody signed in at all.
     */
    public static function actor(): string
    {
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        return self::actor_for($user_id, self::is_machine_request());
    }

    /** The pure rule behind actor(), so it can be tested without a request. */
    public static function actor_for(int $user_id, bool $machine_request): string
    {
        return ($user_id === 0 || $machine_request) ? 'agent' : 'user';
    }

    public static function is_machine_request(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }
        return false;
    }

    /** @return array<int, array<string, mixed>> */
    public static function recent(int $limit = 10, int $offset = 0): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT %d OFFSET %d",
            max(1, $limit),
            max(0, $offset)
        ), ARRAY_A);
        return $rows ?: [];
    }

    public static function count_all(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table());
    }

    /** Human wording for the admin table. Raw event names are for the database. */
    public static function label(string $event): string
    {
        switch ($event) {
            case 'created':
                return __('Created', 'claudpress');
            case 'updated':
                return __('Edited', 'claudpress');
            case 'activated':
                return __('Activated', 'claudpress');
            case 'deactivated':
                return __('Deactivated', 'claudpress');
            case 'deleted':
                return __('Deleted', 'claudpress');
            case 'fatal_deactivated':
                return __('Auto-disabled after a fatal error', 'claudpress');
        }
        return $event;
    }
}
