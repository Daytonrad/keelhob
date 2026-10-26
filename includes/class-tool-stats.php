<?php
/**
 * How well each tool actually works on THIS site: a 1-100 rating earned from
 * real use, not a number anybody typed in.
 *
 * WHY THIS IS A SEPARATE TABLE AND NOT A QUERY OVER THE LOG
 *
 * The obvious implementation is `SELECT ... FROM wp_keelhob_log GROUP BY tool`.
 * It does not work here. Keelhob_Logger is a 200-row ring buffer on purpose —
 * see the long comment on its MAX_ROWS — so on a site doing real agent work a
 * tool's history is deleted within a day or two. A rating that resets itself
 * every other day is worse than no rating, because it reads as information.
 *
 * So the log stays the audit trail (what happened, with its arguments) and this
 * table is the tally (how it has gone, cumulatively). One row per tool, counters
 * only, no payloads. It survives the trim.
 *
 * WHY THE COUNTERS ARE HALVED AT A WINDOW
 *
 * The reference implementation scores from the last 50 runs. Counters that only
 * ever grow would mean a tool that failed forty times in March can never climb
 * back, which punishes a fixed bug forever. Halving every counter when the run
 * count reaches WINDOW gives the same "recent behaviour dominates" property
 * without storing fifty rows per tool: after a halving, the forty old failures
 * weigh twenty, then ten, then five. Old evidence fades; it is never erased in
 * one step.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Tool_Stats
{
    const SCHEMA = 1;

    const OPTION_SCHEMA = 'keelhob_tool_stats_schema';

    /** Runs after which every counter is halved. See the note above. */
    const WINDOW = 50;

    /**
     * The prior: what a tool with no history is assumed to be worth, and how
     * many runs of real evidence it takes to outweigh that assumption.
     *
     * 0.75 with a weight of 3 means one successful run scores 81 rather than
     * 100, and one failure scores 56 rather than 1. A tool is not "perfect"
     * because it worked once, and it is not "broken" because it failed once —
     * and the agent reads this number, so an overconfident 100 would be a lie
     * told to something that acts on it.
     */
    const PRIOR = 0.75;
    const PRIOR_WEIGHT = 3;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'keelhob_tool_stats';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        /*
         * `tool` is the natural key, and it is a string. It is NOT named
         * `object_id`, `item_id` or anything else ending in `_id`: $wpdb casts
         * a column called `object_id` to an integer and silently stores
         * 'create_post' as 0, which is gotcha 1 in the reference notes and
         * produces a table that looks populated and means nothing.
         */
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tool VARCHAR(191) NOT NULL,
            runs INT UNSIGNED NOT NULL DEFAULT 0,
            ok_runs INT UNSIGNED NOT NULL DEFAULT 0,
            thumbs_up INT UNSIGNED NOT NULL DEFAULT 0,
            thumbs_down INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            last_used_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY tool (tool)
        ) {$charset};");
    }

    public static function maybe_install(): void
    {
        if ((int) get_option(self::OPTION_SCHEMA, 0) >= self::SCHEMA) {
            return;
        }
        self::install_table();
        update_option(self::OPTION_SCHEMA, self::SCHEMA);
    }

    // ---- writing ----------------------------------------------------------

    /**
     * One completed tool call. Called from the registry's single choke point,
     * so there is no path a tool can take that skips it.
     */
    public static function observe(string $tool, bool $ok, string $error = ''): void
    {
        global $wpdb;
        $tool = substr($tool, 0, 191);
        if ($tool === '') {
            return;
        }
        $row = self::row($tool);
        $now = current_time('mysql');

        if ($row === null) {
            $wpdb->insert(self::table(), [
                'tool'         => $tool,
                'runs'         => 1,
                'ok_runs'      => $ok ? 1 : 0,
                'thumbs_up'    => 0,
                'thumbs_down'  => 0,
                'last_error'   => $ok ? null : mb_substr($error, 0, 500),
                'last_used_at' => $now,
                'updated_at'   => $now,
            ]);
            return;
        }

        $next = self::decay([
            'runs'        => (int) $row['runs'] + 1,
            'ok_runs'     => (int) $row['ok_runs'] + ($ok ? 1 : 0),
            'thumbs_up'   => (int) $row['thumbs_up'],
            'thumbs_down' => (int) $row['thumbs_down'],
        ]);

        $wpdb->update(self::table(), $next + [
            // A success does not erase the last failure: "it works now, and
            // here is what went wrong last time" is more useful to the model
            // than either half on its own.
            'last_error'   => $ok ? ($row['last_error'] ?? null) : mb_substr($error, 0, 500),
            'last_used_at' => $now,
            'updated_at'   => $now,
        ], ['tool' => $tool]);
    }

    /**
     * Move a thumbs vote from $was to $now for one tool.
     *
     * Both values are passed so changing a vote adjusts the counter it came
     * from. Without that, flipping thumbs-up to thumbs-down would leave the up
     * vote standing and record two opinions from one person about one call.
     */
    public static function vote(string $tool, int $was, int $now): void
    {
        global $wpdb;
        $tool = substr($tool, 0, 191);
        $row  = self::row($tool);
        if ($row === null) {
            // A vote on a call that was never tallied (logging was off when it
            // ran). Start the row from the vote alone rather than dropping it.
            $wpdb->insert(self::table(), [
                'tool'        => $tool,
                'runs'        => 0,
                'ok_runs'     => 0,
                'thumbs_up'   => $now === 1 ? 1 : 0,
                'thumbs_down' => $now === -1 ? 1 : 0,
                'updated_at'  => current_time('mysql'),
            ]);
            return;
        }

        $up   = (int) $row['thumbs_up'];
        $down = (int) $row['thumbs_down'];
        if ($was === 1) {
            $up = max(0, $up - 1);
        } elseif ($was === -1) {
            $down = max(0, $down - 1);
        }
        if ($now === 1) {
            $up++;
        } elseif ($now === -1) {
            $down++;
        }

        $wpdb->update(self::table(), [
            'thumbs_up'   => $up,
            'thumbs_down' => $down,
            'updated_at'  => current_time('mysql'),
        ], ['tool' => $tool]);
    }

    /**
     * Halve every counter once the run count reaches WINDOW.
     *
     * Pure, so the behaviour is testable without a database. Rounding is
     * intdiv, which can only ever lose half a run — and loses it from the
     * larger of the two sides, so it cannot invent a failure.
     *
     * @param array{runs:int,ok_runs:int,thumbs_up:int,thumbs_down:int} $c
     * @return array{runs:int,ok_runs:int,thumbs_up:int,thumbs_down:int}
     */
    public static function decay(array $c): array
    {
        if ((int) $c['runs'] < self::WINDOW) {
            return $c;
        }
        return [
            'runs'        => intdiv((int) $c['runs'], 2),
            'ok_runs'     => intdiv((int) $c['ok_runs'], 2),
            'thumbs_up'   => intdiv((int) $c['thumbs_up'], 2),
            'thumbs_down' => intdiv((int) $c['thumbs_down'], 2),
        ];
    }

    // ---- reading ----------------------------------------------------------

    /** @return array|null raw counter row */
    public static function row(string $tool): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE tool = %s",
            substr($tool, 0, 191)
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * The scored view of one tool: score, label, runs, votes, last failure.
     * Always returns an array — a tool nobody has used yet scores null and
     * reads "New".
     */
    public static function get(string $tool): array
    {
        return self::score(self::row($tool) ?? []) + ['tool' => $tool];
    }

    /**
     * Every tool with a tally, best first.
     *
     * @return array<int, array>
     */
    public static function all(int $limit = 200): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " ORDER BY last_used_at DESC, id DESC LIMIT %d",
            max(1, min(500, $limit))
        ), ARRAY_A);
        $out = [];
        foreach (($rows ?: []) as $row) {
            $out[] = self::score($row) + [
                'tool'         => (string) ($row['tool'] ?? ''),
                'last_used_at' => (string) ($row['last_used_at'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * THE RATING. Pure: counters in, 1-100 out. No database, no clock.
     *
     *   success  = ok_runs / runs
     *   feedback = up / (up + down), or success when nobody has voted
     *   raw      = 0.75 * success + 0.25 * feedback
     *   smoothed = (raw * n + PRIOR * PRIOR_WEIGHT) / (n + PRIOR_WEIGHT)
     *   score    = round(smoothed * 100), clamped to 1-100
     *
     * The reference implementation splits the weight three ways — success,
     * a post-write page check, and the owner's thumbs. Keelhob's post-write
     * verification lives in Keelhob_Health and Keelhob_Media_Check, neither of
     * which runs per tool call, and this track was explicitly not to build a
     * new verification subsystem. So that 0.25 is folded into success rather
     * than invented: the two signals here are "did it work" and "did the owner
     * say it was any good", and the second is weighted a quarter because one
     * annoyed click should move the number without owning it.
     *
     * @param array $row counter row, or [] for a tool with no history
     * @return array{score:int|null,label:string,runs:int,ok_runs:int,thumbs_up:int,thumbs_down:int,last_error:string}
     */
    public static function score(array $row): array
    {
        $runs = max(0, (int) ($row['runs'] ?? 0));
        $ok   = max(0, (int) ($row['ok_runs'] ?? 0));
        $up   = max(0, (int) ($row['thumbs_up'] ?? 0));
        $down = max(0, (int) ($row['thumbs_down'] ?? 0));
        $ok   = min($ok, $runs);

        $base = [
            'runs'        => $runs,
            'ok_runs'     => $ok,
            'thumbs_up'   => $up,
            'thumbs_down' => $down,
            'last_error'  => (string) ($row['last_error'] ?? ''),
        ];

        $votes = $up + $down;
        if ($runs === 0 && $votes === 0) {
            return ['score' => null, 'label' => 'New'] + $base;
        }

        $success  = $runs > 0 ? $ok / $runs : self::PRIOR;
        $feedback = $votes > 0 ? $up / $votes : $success;
        $raw      = (0.75 * $success) + (0.25 * $feedback);

        // n is the weight of real evidence: runs, plus votes for a tool that
        // was voted on but never tallied a run.
        $n        = $runs > 0 ? $runs : $votes;
        $smoothed = (($raw * $n) + (self::PRIOR * self::PRIOR_WEIGHT)) / ($n + self::PRIOR_WEIGHT);
        $score    = (int) max(1, min(100, (int) round($smoothed * 100)));

        return ['score' => $score, 'label' => self::label($score)] + $base;
    }

    /** Words next to the number. A ring is never the only signal. */
    public static function label(?int $score): string
    {
        if ($score === null) {
            return 'New';
        }
        if ($score >= 90) {
            return 'Excellent';
        }
        if ($score >= 75) {
            return 'Good';
        }
        if ($score >= 50) {
            return 'Needs work';
        }
        return 'Poor';
    }

    /**
     * The one line the model reads, or '' when there is nothing to say.
     *
     * Appended to the tool's own description by Keelhob_Tool_Registry, so the
     * agent knows how this tool has actually gone on this site before it picks
     * it — not after.
     */
    public static function description_line(string $tool): string
    {
        $s = self::get($tool);
        if ($s['score'] === null) {
            return '';
        }
        $line = sprintf(
            'Current rating on this site: %d/100 over %d run%s.',
            (int) $s['score'],
            (int) $s['runs'],
            ((int) $s['runs']) === 1 ? '' : 's'
        );
        if ((int) $s['score'] < 60 && $s['last_error'] !== '') {
            $line .= ' Last failure: ' . mb_substr($s['last_error'], 0, 200);
        }
        return $line;
    }

    /** Forget one tool's history. Used by the admin's reset control. */
    public static function reset(string $tool): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['tool' => substr($tool, 0, 191)]);
    }
}
