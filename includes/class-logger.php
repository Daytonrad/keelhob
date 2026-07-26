<?php
/**
 * Lightweight activity log of tool calls, for auditing what the agent did.
 * Ring-buffered to the most recent N rows to avoid unbounded growth.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Logger
{
    const MAX_ROWS = 500;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'claudpress_log';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tool VARCHAR(191) NOT NULL,
            args_json LONGTEXT NULL,
            ok TINYINT(1) NOT NULL DEFAULT 1,
            summary TEXT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) {$charset};");
    }

    public function record(string $tool, array $args, array $result): void
    {
        global $wpdb;
        $ok = !(isset($result['success']) && $result['success'] === false);

        // Redact obviously large / sensitive fields from the stored args.
        $safe_args = $args;
        foreach (['code', 'content', 'data', 'body'] as $big) {
            if (isset($safe_args[$big]) && is_string($safe_args[$big]) && strlen($safe_args[$big]) > 500) {
                $safe_args[$big] = '[' . strlen($safe_args[$big]) . ' chars omitted]';
            }
        }

        $wpdb->insert(self::table(), [
            'tool'       => $tool,
            'args_json'  => wp_json_encode($safe_args),
            'ok'         => $ok ? 1 : 0,
            'summary'    => mb_substr((string) ($result['error'] ?? ($result['message'] ?? '')), 0, 500),
            'user_id'    => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);

        // Trim.
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table());
        if ($count > self::MAX_ROWS) {
            $cut = $count - self::MAX_ROWS;
            $wpdb->query($wpdb->prepare("DELETE FROM " . self::table() . " ORDER BY id ASC LIMIT %d", $cut));
        }
    }

    public function recent(int $limit = 50): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A);
        return $rows ?: [];
    }
}
