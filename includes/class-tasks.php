<?php
/**
 * Scheduled automation engine. Stores recurring tasks and runs them via WP-Cron.
 * Two task modes:
 *   - digest:     build the status snapshot and notify the owner (no LLM needed).
 *   - autonomous: hand the instruction to the agent runtime, then notify a summary.
 *
 * A single hourly cron tick drives everything; each task fires when its own
 * interval has elapsed, so we don't register a WP-Cron event per task.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tasks
{
    const HOOK = 'claudpress_cron_tick';

    const INTERVALS = [
        'hourly'     => 3600,
        'twicedaily' => 43200,
        'daily'      => 86400,
        'weekly'     => 604800,
    ];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'claudpress_tasks';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            instruction LONGTEXT NULL,
            mode VARCHAR(20) NOT NULL DEFAULT 'digest',
            schedule VARCHAR(20) NOT NULL DEFAULT 'daily',
            allow_risky TINYINT(1) NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_run DATETIME NULL,
            last_status VARCHAR(20) NULL,
            last_summary LONGTEXT NULL,
            notify TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY enabled (enabled)
        ) {$charset};");
    }

    // ---- scheduling -------------------------------------------------------

    public static function init(): void
    {
        add_action(self::HOOK, [self::class, 'run_due']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $ts = wp_next_scheduled(self::HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::HOOK);
        }
    }

    /** WP-Cron callback: run every enabled task whose interval has elapsed. */
    public static function run_due(): void
    {
        foreach (self::all() as $task) {
            if ((int) $task['enabled'] !== 1) {
                continue;
            }
            $interval = self::INTERVALS[$task['schedule']] ?? 86400;
            $last     = $task['last_run'] ? strtotime($task['last_run']) : 0;
            if ((time() - $last) < ($interval - 120)) {
                continue; // not due yet (2-min tolerance)
            }
            self::run_task((int) $task['id']);
        }
    }

    // ---- execution --------------------------------------------------------

    public static function run_task(int $id): array
    {
        $task = self::get($id);
        if (!$task) {
            return ['success' => false, 'error' => 'Task not found.'];
        }
        @set_time_limit(0);

        if ($task['mode'] === 'autonomous') {
            $res = Claudpress_Agent_Runtime::run((string) $task['instruction'], [
                'allow_risky' => (bool) $task['allow_risky'],
            ]);
            $ok      = !empty($res['success']);
            $summary = $ok ? (string) ($res['summary'] ?? '') : ('ERROR: ' . ($res['error'] ?? 'unknown'));
            $subject = sprintf('[%s] Automation: %s', get_bloginfo('name'), $task['name']);
        } else {
            $snapshot = Claudpress_Digest::snapshot();
            $summary  = Claudpress_Digest::format_text($snapshot);
            $ok       = true;
            $subject  = sprintf('[%s] Status digest', get_bloginfo('name'));
            $res      = ['success' => true];
        }

        self::record_run($id, $ok ? 'ok' : 'error', $summary);

        if ((int) $task['notify'] === 1) {
            Claudpress_Notifier::send($subject, $summary);
        }

        return ['success' => $ok, 'task' => $task['name'], 'summary' => $summary, 'detail' => $res];
    }

    private static function record_run(int $id, string $status, string $summary): void
    {
        global $wpdb;
        $wpdb->update(self::table(), [
            'last_run'     => current_time('mysql'),
            'last_status'  => $status,
            'last_summary' => mb_substr($summary, 0, 8000),
        ], ['id' => $id]);
    }

    // ---- CRUD -------------------------------------------------------------

    public static function save(array $a): array
    {
        global $wpdb;
        $schedule = array_key_exists($a['schedule'] ?? '', self::INTERVALS) ? $a['schedule'] : 'daily';
        $mode     = in_array($a['mode'] ?? 'digest', ['digest', 'autonomous'], true) ? $a['mode'] : 'digest';

        if ($mode === 'autonomous' && trim((string) ($a['instruction'] ?? '')) === '') {
            return ['success' => false, 'error' => 'Autonomous tasks need an instruction.'];
        }

        $row = [
            'name'        => sanitize_text_field((string) ($a['name'] ?? 'Task')),
            'instruction' => (string) ($a['instruction'] ?? ''),
            'mode'        => $mode,
            'schedule'    => $schedule,
            'allow_risky' => (int) (bool) ($a['allow_risky'] ?? false),
            'enabled'     => array_key_exists('enabled', $a) ? (int) (bool) $a['enabled'] : 1,
            'notify'      => array_key_exists('notify', $a) ? (int) (bool) $a['notify'] : 1,
            'updated_at'  => current_time('mysql'),
        ];

        $id = (int) ($a['id'] ?? 0);
        if ($id && self::get($id)) {
            $wpdb->update(self::table(), $row, ['id' => $id]);
        } else {
            $wpdb->insert(self::table(), $row);
            $id = (int) $wpdb->insert_id;
        }
        return ['success' => true, 'id' => $id, 'mode' => $mode, 'schedule' => $schedule];
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    public static function all(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY id DESC", ARRAY_A);
        return $rows ?: [];
    }

    public static function delete(int $id): array
    {
        global $wpdb;
        return ['success' => (bool) $wpdb->delete(self::table(), ['id' => $id]), 'id' => $id];
    }

    public static function toggle(int $id, bool $enabled): array
    {
        global $wpdb;
        if (!self::get($id)) {
            return ['success' => false, 'error' => 'Task not found.'];
        }
        $wpdb->update(self::table(), ['enabled' => $enabled ? 1 : 0], ['id' => $id]);
        return ['success' => true, 'id' => $id, 'enabled' => $enabled];
    }
}
