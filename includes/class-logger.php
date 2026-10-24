<?php
/**
 * Lightweight activity log of tool calls, for auditing what the agent did.
 * Ring-buffered to the most recent N rows to avoid unbounded growth.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Logger
{
    /**
     * Lowered from 500 in 1.11: the Activity screen renders this table ten rows
     * at a time now, and nobody has ever audited the 400th-most-recent tool
     * call. Two hundred is a couple of days of heavy agent work — enough to
     * answer "what did it just do to my site", which is the only question this
     * log is asked.
     */
    const MAX_ROWS = 200;

    /**
     * Schema generation, independent of KEELHOB_VERSION.
     *
     * Keelhob::maybe_upgrade() only runs install_table() when the stored
     * db_version differs from the plugin version, so on any site already
     * recorded at the current version — which is every development rig, and
     * every site that updated before this column set existed — the new columns
     * would never be created. This number is bumped instead, and checked on
     * its own.
     */
    const SCHEMA = 2;

    const OPTION_SCHEMA = 'keelhob_log_schema';

    /** Thumbs values stored in the `feedback` column. */
    const NO_VOTE = 0;
    const THUMBS_UP = 1;
    const THUMBS_DOWN = -1;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'keelhob_log';
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
            source VARCHAR(32) NOT NULL DEFAULT 'chat',
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            output_json LONGTEXT NULL,
            error TEXT NULL,
            feedback TINYINT NOT NULL DEFAULT 0,
            agent_run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY tool (tool)
        ) {$charset};");
    }

    /**
     * Create or widen the table when this schema generation has not been
     * applied here yet. Cheap: one autoloaded option read, then nothing.
     */
    public static function maybe_upgrade_schema(): void
    {
        if ((int) get_option(self::OPTION_SCHEMA, 0) >= self::SCHEMA) {
            return;
        }
        self::install_table();
        update_option(self::OPTION_SCHEMA, self::SCHEMA);
    }

    /**
     * Write one tool call to the log, and return the row id (0 on failure).
     *
     * $meta is optional so every existing three-argument caller — the browser
     * gate seeds a row that way — keeps working unchanged. Recognised keys:
     * `source` (chat | the agent run's context), `duration_ms`, `agent_run_id`.
     *
     * The returned id is what the Activity screen's thumbs controls address, so
     * a vote can be attached to one specific call rather than to a tool in
     * general.
     */
    public function record(string $tool, array $args, array $result, array $meta = []): int
    {
        global $wpdb;
        $ok = !(isset($result['success']) && $result['success'] === false);

        // Trim obviously large fields from the stored args.
        $safe_args = $args;
        foreach (['code', 'content', 'data', 'body'] as $big) {
            if (isset($safe_args[$big]) && is_string($safe_args[$big]) && strlen($safe_args[$big]) > 500) {
                $safe_args[$big] = '[' . strlen($safe_args[$big]) . ' chars omitted]';
            }
        }

        // Then redact secrets, at any depth. This is not optional politeness:
        // wc_payments takes live gateway credentials as ordinary arguments, so
        // without this a Stripe secret key is written to this table in clear
        // text and rendered on the Activity screen. The same goes for any tool
        // that carries an API key, a bearer token, or a password.
        $safe_args = self::redact($safe_args);

        $error = (string) ($result['error'] ?? '');

        // The output, redacted on exactly the same path as the arguments and
        // clipped hard. A tool result can be a whole page of HTML or a 2 MB
        // media listing, and this table is read on an admin screen.
        $output = self::redact(is_array($result) ? $result : ['result' => $result]);
        unset($output['_meta']);
        $output_json = (string) wp_json_encode($output);
        if (strlen($output_json) > 20000) {
            $output_json = substr($output_json, 0, 20000);
        }

        $inserted = $wpdb->insert(self::table(), [
            'tool'         => $tool,
            'args_json'    => wp_json_encode($safe_args),
            'ok'           => $ok ? 1 : 0,
            'summary'      => mb_substr((string) ($result['error'] ?? ($result['message'] ?? '')), 0, 500),
            'user_id'      => get_current_user_id(),
            'created_at'   => current_time('mysql'),
            'source'       => substr((string) ($meta['source'] ?? 'chat'), 0, 32),
            'duration_ms'  => max(0, (int) ($meta['duration_ms'] ?? 0)),
            'output_json'  => $output_json,
            'error'        => mb_substr($error, 0, 1000),
            'feedback'     => self::NO_VOTE,
            'agent_run_id' => max(0, (int) ($meta['agent_run_id'] ?? 0)),
        ]);

        $id = $inserted ? (int) $wpdb->insert_id : 0;

        // Trim.
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table());
        if ($count > self::MAX_ROWS) {
            $cut = $count - self::MAX_ROWS;
            $wpdb->query($wpdb->prepare("DELETE FROM " . self::table() . " ORDER BY id ASC LIMIT %d", $cut));
        }

        return $id;
    }

    /** One row by id, or null. */
    public function find(int $id): ?array
    {
        global $wpdb;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d",
            $id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Record the owner's thumbs on one logged call.
     *
     * Returns the stats row the vote landed on, or null when the row is gone —
     * the log is a 200-row ring buffer, so a vote on a screen left open for a
     * busy week can arrive after its row was trimmed. That has to be a visible
     * failure rather than a silently dropped vote, which is why the caller gets
     * null instead of true.
     *
     * The previous vote is passed to the stats tally so changing a mind adjusts
     * both counters rather than adding a second vote.
     */
    public function vote(int $id, int $vote): ?array
    {
        global $wpdb;
        $vote = $vote > 0 ? self::THUMBS_UP : ($vote < 0 ? self::THUMBS_DOWN : self::NO_VOTE);
        $row  = $this->find($id);
        if ($row === null) {
            return null;
        }
        $was = (int) ($row['feedback'] ?? 0);
        if ($was !== $vote) {
            $wpdb->update(self::table(), ['feedback' => $vote], ['id' => $id]);
            Keelhob_Tool_Stats::vote((string) $row['tool'], $was, $vote);
        }
        return Keelhob_Tool_Stats::get((string) $row['tool']);
    }

    public function recent(int $limit = 50, int $offset = 0): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT %d OFFSET %d",
            max(1, $limit),
            max(0, $offset)
        ), ARRAY_A);
        return $rows ?: [];
    }

    public function count_all(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table());
    }

    /**
     * Field names that mean "this is a credential", matched as whole words.
     *
     * Whole words, not substrings: a substring match on 'key' also masks
     * 'monkey', 'turkey' and 'keyword', which quietly destroys the log's
     * usefulness. The concatenated forms below exist because 'apikey' does not
     * split into words on its own.
     */
    const SECRET_WORDS = [
        'key', 'keys', 'secret', 'secrets', 'token', 'tokens', 'password',
        'passwd', 'pwd', 'auth', 'credential', 'credentials', 'bearer',
        'signature', 'sig', 'nonce', 'salt',
    ];

    /** Names that carry no word boundary to split on. */
    const SECRET_WHOLE = [
        'apikey', 'apisecret', 'secretkey', 'privatekey', 'publickey',
        'accesstoken', 'refreshtoken', 'authtoken', 'bearertoken',
        'clientsecret', 'clientid', 'webhooksecret', 'apppassword',
    ];

    /** Value prefixes that are a credential whatever the field is called. */
    const SECRET_VALUE_PREFIXES = ['sk_live_', 'sk_test_', 'rk_live_', 'sk-ant-', 'sk-', 'ghp_', 'gho_', 'xoxb-', 'xoxp-', 'Bearer ', 'Basic '];

    /**
     * Recursively mask anything that looks like a credential.
     *
     * Matching on the field name rather than the value is the main strategy: a
     * secret has no reliable shape (Stripe keys, bearer tokens, application
     * passwords and webhook URLs look nothing alike), but the field it arrives
     * in is nearly always named for what it is. Known value prefixes are checked
     * as well, so a credential in an oddly-named field is still caught.
     *
     * Masking a harmless field called 'public_key' costs nothing. A single miss
     * writes a live credential into the database and onto the Activity screen.
     *
     * The last four characters survive so a human can still tell two keys apart,
     * which is the only reason the value was ever worth keeping.
     */
    private static function redact($value, int $depth = 0)
    {
        if ($depth > 6 || !is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $out[$k] = self::redact($v, $depth + 1);
                continue;
            }
            if (is_string($k) && self::is_secret_name($k)) {
                $out[$k] = self::mask(is_scalar($v) ? (string) $v : '');
                continue;
            }
            if (is_string($v) && self::looks_like_secret($v)) {
                $out[$k] = self::mask($v);
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** Split on separators and camelCase, then look for a whole-word match. */
    private static function is_secret_name(string $name): bool
    {
        $flat = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
        if (in_array($flat, self::SECRET_WHOLE, true)) {
            return true;
        }
        $words = preg_split('/[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])/', $name) ?: [];
        foreach ($words as $word) {
            if (in_array(strtolower($word), self::SECRET_WORDS, true)) {
                return true;
            }
        }
        return false;
    }

    private static function looks_like_secret(string $value): bool
    {
        foreach (self::SECRET_VALUE_PREFIXES as $prefix) {
            if (strncmp($value, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Four dots plus the last four characters, or just dots when too short. */
    private static function mask(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        return strlen($secret) > 8
            ? str_repeat("\u{2022}", 4) . substr($secret, -4)
            : str_repeat("\u{2022}", 4);
    }
}
