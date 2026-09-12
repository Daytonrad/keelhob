<?php
/**
 * The feedback log — friction, recorded locally, sent only when a human says so.
 *
 * The problem this exists for: somebody asks for a hero section, the agent gets
 * it wrong, they correct it, it gets it wrong differently, three rounds later it
 * lands and they are tired. That loop happens on every install and none of it
 * reaches the project. One person shrugs. Twenty people is the most valuable
 * thing this project could know, and today it evaporates when the window closes.
 *
 * WHAT THIS IS NOT, stated first because the difference is the whole feature:
 *
 *   - Not analytics. No page views, no sessions, no usage tracking.
 *   - Not error reporting. Nothing is ever sent automatically.
 *   - Not a transcript. The conversation is never sent and the user's own words
 *     are never stored verbatim — only a characterisation of what went wrong.
 *
 * PRIVACY.md says, in writing, that Claudpress has no analytics and no error
 * reporting. PostHog was rejected for the plugin on that exact sentence, and
 * this sends more sensitive data than PostHog would have. So the bar is higher,
 * not lower, and everything here follows from one idea:
 *
 *   THE ONLY VERSION OF "NOTHING SENSITIVE IS SENT" THAT SURVIVES SOMEBODY
 *   CHECKING IS "A HUMAN READ IT BEFORE IT LEFT."
 *
 * Hence: redaction in PHP rather than in a skill (a skill can be switched off or
 * rewritten by a well-meaning agent), an approval loop the agent cannot skip
 * because the rule lives in the operating protocol, and a local log the owner
 * can read in full and wipe with one button.
 *
 * The plan of record is docs/plans/feedback-log.md.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Feedback
{
    /** The one setting. Default ON — see the docblock on default_enabled(). */
    const SETTING = 'feedback_log';

    /** When the agent last offered to send, whatever the answer was. */
    const OPTION_LAST_OFFER = 'claudpress_feedback_last_offer';

    const ENDPOINT = 'https://api.claudpress.com/v1/feedback';

    /**
     * Retention. Deliberately tighter than the 90 days the server keeps,
     * because this table sits on somebody else's site rather than on ours.
     * Long enough to catch friction spread across weeks of separate chats,
     * short enough that nothing lingers.
     */
    const MAX_AGE_DAYS = 30;
    const MAX_ROWS     = 50;

    /** At most one offer a week. A nag is what gets the setting switched off. */
    const OFFER_INTERVAL = 604800;

    /**
     * Report shape. Five events of 200 characters, not one 600-character blob:
     * the blob loses which problem was which, and the counting that makes this
     * useful works per problem rather than per report. And not unlimited — a
     * forty-line approval prompt is not read, and an approval nobody reads is
     * not consent.
     */
    const MAX_EVENTS = 5;
    const NOTE_MAX   = 200;

    /** The vocabulary. Anything not in here is not written and not sent. */
    const FRICTIONS = [
        'repeated_correction',  // the agent got it wrong more than once in a row
        'tool_failed',          // a tool errored or returned something unusable
        'tool_missing',         // the job needed something the plugin does not have
        'wrong_visual_result',  // it did what was asked and the result looked wrong
        'docs_gap',             // the skill or docs did not say the thing that mattered
        'skill_gap',            // no skill covered this job
        'policy_blocked',       // a capability or policy stopped legitimate work
    ];

    const SOURCES = ['auto', 'agent'];

    /** What a redacted span is replaced with. Visible on purpose. */
    const MASK = '[removed]';

    /**
     * How many times the same tool has been called with near-identical
     * arguments during this PHP request, keyed by a hash of name + args.
     *
     * Static rather than stored: the "wrong again" loop is a property of one
     * burst of work, and carrying it across requests would turn two unrelated
     * retries a week apart into a fake pattern.
     *
     * @var array<string, int>
     */
    private static $repeats = [];

    /** Hashes already recorded, so one loop produces one row and not five. */
    private static $repeats_logged = [];

    // ---------------------------------------------------------------- storage

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'claudpress_feedback';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            friction VARCHAR(32) NOT NULL,
            tool VARCHAR(64) NOT NULL DEFAULT '',
            skill VARCHAR(191) NOT NULL DEFAULT '',
            attempts INT UNSIGNED NOT NULL DEFAULT 1,
            note VARCHAR(255) NOT NULL DEFAULT '',
            source VARCHAR(10) NOT NULL DEFAULT 'auto',
            redacted TINYINT(1) NOT NULL DEFAULT 0,
            sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) {$charset};");
    }

    // ---------------------------------------------------------------- consent

    /**
     * Default ON.
     *
     * This is defensible only because of the two rules that sit underneath it:
     * nothing is recorded at all when it is off, and nothing is ever sent
     * without a person reading the whole draft and saying yes. Default-ON
     * *sending* would not be defensible and is not what this is.
     */
    public static function default_enabled(): bool
    {
        return true;
    }

    public static function enabled(): bool
    {
        if (!class_exists('Claudpress')) {
            return false;
        }
        $v = Claudpress::instance()->get(self::SETTING, self::default_enabled());
        return (bool) $v;
    }

    // -------------------------------------------------------------- redaction

    /**
     * Strip anything that could identify the site, its owner, or its secrets.
     *
     * Applied twice: on write to the local log, and again on the way out. Twice
     * because the first pass is the one that keeps the owner's own database
     * clean, and the second is the one that still holds if a future code path
     * writes a row some other way.
     *
     * OVER-REDACTION IS THE CORRECT FAILURE. A note that lost a word is a
     * slightly worse bug report. A note that kept an API key is an incident.
     * Every judgement call below is therefore resolved toward removing more.
     */
    public static function redact(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $m = self::MASK;

        // 1. Anything that announces itself as a secret, first — before the
        //    generic patterns get a chance to only partly eat the value.
        $text = (string) preg_replace(
            '/\b(api[_-]?key|key|token|secret|password|passwd|pwd|bearer|auth)\b\s*[:=]?\s*\S+/i',
            '$1 ' . $m,
            $text
        );

        // 2. Email before domains: an address contains a domain, and matching
        //    the domain first would leave the local part sitting there.
        $text = (string) preg_replace('/[^\s@<>()\[\]]+@[^\s@<>()\[\]]+\.[a-z]{2,}/i', $m, $text);

        // 3. Absolute paths. Anchored to the shapes that actually leak a
        //    username or the host's layout, rather than to "starts with a
        //    slash" — which would eat /v1/feedback and every REST route an
        //    agent might usefully mention.
        $text = (string) preg_replace('#\b[A-Za-z]:\\\\[^\s"\']*#', $m, $text);           // C:\Users\...
        $text = (string) preg_replace('#/(?:home|var|usr|srv|opt|tmp|www|Users|private)/[^\s"\']*#i', $m, $text);

        // 4. URLs, then bare hostnames.
        $text = (string) preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', $m, $text);
        $text = (string) preg_replace('#\bwww\.[^\s/"\']+#i', $m, $text);
        $text = (string) preg_replace(
            // A restricted TLD list rather than "two or more letters". The wide
            // version redacts post.title and index.php, which costs real
            // meaning in a bug report and protects nobody — a domain somebody
            // actually owns ends in one of these.
            '/\b(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:com|net|org|io|co|dev|app|ai|me|info|biz|shop|store|site|online|xyz|blog|cloud|tech|agency|studio|design|uk|us|ca|au|de|fr|es|it|nl|se|no|ch|in|jp|cn|br|za|ae|gh|ng|ke)\b/i',
            $m,
            $text
        );

        // 5. Addresses.
        $text = (string) preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', $m, $text);
        $text = (string) preg_replace('/\b(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{1,4}\b/i', $m, $text);

        // 6. Key-shaped runs. Two patterns rather than one: vendor prefixes use
        //    hyphens (sk-ant-api03-...), and a generic run must require a digit
        //    or it starts eating long ordinary words.
        $text = (string) preg_replace('/\b(?:sk|pk|rk|ghp|gho|ghs|xox[baprs])[-_][A-Za-z0-9_-]{10,}/', $m, $text);
        $text = (string) preg_replace_callback(
            '/\b[A-Za-z0-9+\/=]{20,}\b/',
            static function (array $hit) use ($m) {
                return preg_match('/\d/', $hit[0]) ? $m : $hit[0];
            },
            $text
        );

        // 7. This site's own name and host, last, because by now the obvious
        //    shapes are gone and what is left is the likeliest accidental leak:
        //    somebody typing their site's name into a note by hand.
        foreach (self::own_identifiers() as $needle) {
            if ($needle !== '' && mb_strlen($needle) >= 4) {
                $text = (string) str_ireplace($needle, $m, $text);
            }
        }

        return trim((string) preg_replace('/\s{2,}/', ' ', $text));
    }

    /**
     * The strings that name this particular site.
     *
     * Split out so a test can prove the site's own domain does not survive
     * redaction without needing a live WordPress to say what that domain is.
     *
     * @return string[]
     */
    public static function own_identifiers(): array
    {
        $out = [];
        if (function_exists('home_url')) {
            $host = (string) wp_parse_url((string) home_url(), PHP_URL_HOST);
            if ($host !== '') {
                $out[] = $host;
                $out[] = preg_replace('/^www\./i', '', $host);
            }
        }
        if (function_exists('get_bloginfo')) {
            $name = trim((string) get_bloginfo('name'));
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    /** Did redaction actually remove anything? Recorded so heavy redaction is visible. */
    public static function was_redacted(string $before, string $after): bool
    {
        return $before !== '' && strpos($after, self::MASK) !== false;
    }

    // ----------------------------------------------------------------- record

    /**
     * Write one friction event.
     *
     * Never throws. This is called from inside the tool call path, and a
     * logging failure must never be the reason somebody's build stops.
     *
     * @param array{friction:string,tool?:string,skill?:string,attempts?:int,note?:string,source?:string} $event
     */
    public static function record(array $event): bool
    {
        // Not "logged but not sent" — not logged. It makes the promise one
        // sentence instead of two, and a sentence with no "but" in it is the
        // only kind anybody believes.
        if (!self::enabled()) {
            return false;
        }

        $friction = isset($event['friction']) ? (string) $event['friction'] : '';
        if (!in_array($friction, self::FRICTIONS, true)) {
            return false;
        }

        $raw   = isset($event['note']) ? (string) $event['note'] : '';
        $note  = mb_substr(self::redact($raw), 0, self::NOTE_MAX);
        $source = isset($event['source']) && in_array($event['source'], self::SOURCES, true)
            ? (string) $event['source']
            : 'auto';

        global $wpdb;
        if (!isset($wpdb)) {
            return false;
        }

        $ok = $wpdb->insert(self::table(), [
            'friction'   => $friction,
            'tool'       => mb_substr(preg_replace('/[^a-z0-9_]/i', '', (string) ($event['tool'] ?? '')) ?? '', 0, 64),
            'skill'      => mb_substr(preg_replace('/[^a-z0-9_-]/i', '', (string) ($event['skill'] ?? '')) ?? '', 0, 191),
            'attempts'   => max(1, (int) ($event['attempts'] ?? 1)),
            'note'       => $note,
            'source'     => $source,
            'redacted'   => self::was_redacted($raw, $note) ? 1 : 0,
            'sent'       => 0,
            'created_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
        ]);

        self::prune();
        return (bool) $ok;
    }

    /**
     * Automatic capture, from the one place every tool call passes through.
     *
     * Zero tokens, zero conversation content, and it cannot be skipped by an
     * agent that does not feel like cooperating. That last property matters
     * more than it looks: a feature that depends on a model choosing to be
     * honest about its own failures is a feature that reports whatever the
     * model finds flattering.
     */
    public static function observe_call(string $tool, array $args, array $result): void
    {
        if (!self::enabled()) {
            return;
        }

        $error = self::error_text($result);
        if ($error !== '') {
            self::record([
                'friction' => self::classify_error($error),
                'tool'     => $tool,
                'note'     => $error,
                'source'   => 'auto',
            ]);
            return;
        }

        // The "wrong again" loop, expressed as machine fact rather than as an
        // opinion: the same tool, with near-identical arguments, three times.
        $hash = md5($tool . '|' . wp_json_encode($args));
        self::$repeats[$hash] = (self::$repeats[$hash] ?? 0) + 1;
        if (self::$repeats[$hash] >= 3 && empty(self::$repeats_logged[$hash])) {
            self::$repeats_logged[$hash] = true;
            self::record([
                'friction' => 'repeated_correction',
                'tool'     => $tool,
                'attempts' => self::$repeats[$hash],
                'source'   => 'auto',
            ]);
        }
    }

    /** A tool that was never registered is a capability the owner switched off. */
    public static function observe_unknown_tool(string $tool): void
    {
        self::record([
            'friction' => 'tool_missing',
            'tool'     => $tool,
            'source'   => 'auto',
        ]);
    }

    /** The error a tool result is reporting, or '' if it succeeded. */
    public static function error_text(array $result): string
    {
        if (isset($result['success']) && $result['success'] === false) {
            return (string) ($result['error'] ?? $result['message'] ?? 'failed');
        }
        if (!empty($result['error']) && is_string($result['error'])) {
            return $result['error'];
        }
        return '';
    }

    /**
     * Is this a tool that broke, or a tool that was not allowed to run?
     *
     * Worth telling apart: the first is a bug in the plugin, the second is the
     * safety fence working as designed but possibly in the wrong place. Rolled
     * together they would read as one big pile of failures and the fence would
     * take the blame for bugs it did not cause.
     */
    public static function classify_error(string $error): string
    {
        $blocked = ['not permitted', 'not allowed', 'disabled', 'switched off', 'capability', 'read-only', 'refused', 'blocked', 'permission'];
        foreach ($blocked as $needle) {
            if (stripos($error, $needle) !== false) {
                return 'policy_blocked';
            }
        }
        return 'tool_failed';
    }

    /** Reset the per-request repeat counters. An unattended run calls this before it begins. */
    public static function reset_repeats(): void
    {
        self::$repeats        = [];
        self::$repeats_logged = [];
    }

    // -------------------------------------------------------------- retention

    /** Drop anything older than the window, then anything over the row cap. */
    public static function prune(): void
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return;
        }
        $table = self::table();

        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::MAX_AGE_DAYS * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));

        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $cut  = self::trim_count($rows, self::MAX_ROWS);
        if ($cut > 0) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $cut));
        }
    }

    /** The pure rule behind the row cap, so the boundary is testable without a database. */
    public static function trim_count(int $rows, int $max): int
    {
        return $rows > $max ? $rows - $max : 0;
    }

    /** Everything in the log, newest first. The owner is entitled to see all of it. */
    public static function all(int $limit = 100): array
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT %d",
            max(1, $limit)
        ), ARRAY_A);
        return $rows ?: [];
    }

    public static function count_unsent(): int
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table() . " WHERE sent = 0");
    }

    /** Wipe the lot. A log you cannot clear is a log you cannot trust. */
    public static function clear(): int
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }
        $n = self::count_unsent();
        $wpdb->query("DELETE FROM " . self::table());
        return $n;
    }

    // ------------------------------------------------------------------ offer

    /**
     * May the agent offer to send a report right now?
     *
     * v1 of the plan said "at the end of the session". MCP has no end-of-session
     * event, so v1 had no trigger at all. Both conditions below are load-bearing:
     * without the first this becomes a prompt on every session, which is a nag,
     * which is what gets the setting switched off — costing more reports than
     * the nagging ever gained.
     */
    public static function should_offer(?int $now = null): bool
    {
        if (!self::enabled()) {
            return false;
        }
        if (self::count_unsent() < 1) {
            return false;
        }
        return self::offer_due(self::last_offer(), $now ?? time());
    }

    /** The pure half of should_offer(), so the clock can be tested without a clock. */
    public static function offer_due(int $last_offer, int $now): bool
    {
        return ($now - $last_offer) >= self::OFFER_INTERVAL;
    }

    public static function last_offer(): int
    {
        return (int) get_option(self::OPTION_LAST_OFFER, 0);
    }

    /**
     * Start the clock again.
     *
     * Called whether the answer was yes or no. A refusal resets it for the same
     * reason an acceptance does — the person has now been asked, and asking
     * again next session because they said no is exactly the behaviour that
     * makes people switch the whole thing off.
     */
    public static function mark_offered(?int $now = null): void
    {
        update_option(self::OPTION_LAST_OFFER, $now ?? time(), false);
    }

    // ---------------------------------------------------------------- payload

    /**
     * The draft, exactly as it will be sent.
     *
     * Every field, nothing folded away. If more events are in the log than fit,
     * the ones with the highest attempt counts go and the report says how many
     * were left out — silently dropping them would make the report a lie about
     * its own completeness.
     *
     * @return array{events:array<int,array<string,mixed>>,omitted:int}
     */
    public static function build_report(?array $rows = null): array
    {
        $rows = $rows ?? self::all(self::MAX_ROWS);
        $rows = array_values(array_filter($rows, static function ($r) {
            return empty($r['sent']);
        }));

        usort($rows, static function ($a, $b) {
            return ((int) $b['attempts']) <=> ((int) $a['attempts']);
        });

        $omitted = max(0, count($rows) - self::MAX_EVENTS);
        $events  = [];
        foreach (array_slice($rows, 0, self::MAX_EVENTS) as $r) {
            $note = mb_substr(self::redact((string) ($r['note'] ?? '')), 0, self::NOTE_MAX);
            $events[] = [
                'friction' => (string) $r['friction'],
                'tool'     => (string) ($r['tool'] ?? ''),
                'skill'    => (string) ($r['skill'] ?? ''),
                'attempts' => max(1, (int) ($r['attempts'] ?? 1)),
                'note'     => $note,
            ];
        }

        return ['events' => $events, 'omitted' => $omitted];
    }

    /** The body posted to the endpoint. Named separately so a test can read it. */
    public static function payload(array $report): array
    {
        return [
            'site_id'        => Claudpress_Updater::site_id(),
            'plugin_version' => CLAUDPRESS_VERSION,
            'events'         => $report['events'],
            'omitted'        => (int) ($report['omitted'] ?? 0),
        ];
    }

    /**
     * Send it. Only ever reached after a person said yes.
     *
     * Failure is swallowed on purpose. No retry, no queue: a failed report is
     * worth far less than a session interrupted by an error about a report.
     */
    public static function send(array $report): bool
    {
        if (empty($report['events'])) {
            return false;
        }
        $res = wp_remote_post(self::ENDPOINT, [
            'timeout'  => 5,
            'blocking' => true,
            'headers'  => ['Content-Type' => 'application/json'],
            'body'     => wp_json_encode(self::payload($report)),
        ]);
        $ok = !is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200;

        if ($ok) {
            global $wpdb;
            $wpdb->query("UPDATE " . self::table() . " SET sent = 1");
        }
        return $ok;
    }

    /**
     * Ask the server to delete everything it holds for this site.
     *
     * Anonymous data normally cannot honour "delete what you hold about me",
     * because nothing can find it. Here the site knows its own UUID, so it can
     * ask — which is what turns the anonymity from a dodge into a property.
     */
    public static function forget_remote(): bool
    {
        $res = wp_remote_request(self::ENDPOINT . '?site_id=' . rawurlencode(Claudpress_Updater::site_id()), [
            'method'  => 'DELETE',
            'timeout' => 5,
        ]);
        return !is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200;
    }

    // ------------------------------------------------------------------ words

    /** Human wording for the admin table and the agent's draft. */
    public static function label(string $friction): string
    {
        switch ($friction) {
            case 'repeated_correction':
                return __('Got it wrong more than once', 'claudpress');
            case 'tool_failed':
                return __('A tool failed', 'claudpress');
            case 'tool_missing':
                return __('Something the plugin cannot do', 'claudpress');
            case 'wrong_visual_result':
                return __('Did as asked, looked wrong', 'claudpress');
            case 'docs_gap':
                return __('The docs did not say', 'claudpress');
            case 'skill_gap':
                return __('No skill covered this', 'claudpress');
            case 'policy_blocked':
                return __('A permission blocked real work', 'claudpress');
        }
        return $friction;
    }
}
