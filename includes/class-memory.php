<?php
/**
 * Cross-session memory store. Per-site key/value knowledge the agent chooses to
 * persist ("remember: primary color is #f8ca50"). Backed by a custom table.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Memory
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'claudpress_memory';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            mem_key VARCHAR(191) NOT NULL,
            mem_value LONGTEXT NOT NULL,
            category VARCHAR(64) NOT NULL DEFAULT 'general',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY mem_key (mem_key)
        ) {$charset};");
    }

    /**
     * Normalize a memory key. Unlike sanitize_key(), this keeps dots and colons
     * so hierarchical keys like "build.elementor" or "brand:primary" survive.
     */
    public static function clean_key(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9._:\-]/', '', $key);
        return substr($key, 0, 191);
    }

    public function save(string $key, string $value, string $category = 'general'): array
    {
        global $wpdb;
        $key = self::clean_key($key);
        if ($key === '') {
            return ['success' => false, 'error' => 'Empty memory key.'];
        }
        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::table() . " (mem_key, mem_value, category, updated_at)
             VALUES (%s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE mem_value = VALUES(mem_value), category = VALUES(category), updated_at = VALUES(updated_at)",
            $key,
            $value,
            sanitize_key($category),
            current_time('mysql')
        ));
        return ['success' => true, 'key' => $key, 'category' => $category];
    }

    public function get(string $key): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT mem_key AS `key`, mem_value AS `value`, category, updated_at FROM " . self::table() . " WHERE mem_key = %s",
            self::clean_key($key)
        ), ARRAY_A);
        return $row ?: null;
    }

    /** @return array<int, array> */
    public function all(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT mem_key AS `key`, mem_value AS `value`, category, updated_at FROM " . self::table() . " ORDER BY category, mem_key",
            ARRAY_A
        );
        return $rows ?: [];
    }

    public function delete(string $key): array
    {
        global $wpdb;
        $deleted = $wpdb->delete(self::table(), ['mem_key' => self::clean_key($key)]);
        return ['success' => (bool) $deleted, 'key' => self::clean_key($key)];
    }

    public function clear(): int
    {
        global $wpdb;
        return (int) $wpdb->query("DELETE FROM " . self::table());
    }
}
