<?php
/**
 * Lessons: short facts about THIS site that get pushed into the tool
 * descriptions the model reads.
 *
 * "Rank Math is the SEO plugin here, not Yoast." "The brand red is #E11D48."
 * Keelhob already has memory_save/memory_get, and this is deliberately not
 * that. Memory is a store the agent has to remember to read; a lesson is
 * stapled to the description of the tool it concerns, so it arrives whether the
 * agent went looking or not. That is the whole value, and it is also the whole
 * risk.
 *
 * ------------------------------------------------------------------------
 * THIS IS A SAFETY SURFACE. READ BEFORE CHANGING ANYTHING BELOW.
 * ------------------------------------------------------------------------
 *
 * A lesson is a standing instruction to an agent that can change a live site.
 * Injected into a tool description it is read on every single call, by
 * something that acts on it, with no human in the loop. One wrong or hostile
 * sentence here — "always delete the old page first", "the staging database is
 * safe to drop" — is worse than a wrong memory entry, because nothing has to
 * choose to read it.
 *
 * Four guards, and none of them is decoration:
 *
 *   1. LENGTH CAP. MAX_LEN characters, enforced on write, after tags are
 *      stripped. A cap is what stops a lesson from becoming a prompt: you
 *      cannot fit a new operating procedure, a role-play frame or a long list
 *      of overrides into 300 characters.
 *   2. HTML AND CONTROL CHARACTERS ARE STRIPPED, always. No markup, no
 *      newlines, no zero-width or bidi characters — a lesson is one line of
 *      plain text or it is not stored.
 *   3. THE OWNER CAN EDIT AND DELETE EVERY ONE, from Keelhob > Activity. An
 *      instruction the owner cannot see and revoke is not something this
 *      plugin gets to hand an agent.
 *   4. EVERY LESSON CARRIES ITS SOURCE — owner or agent — and that is shown in
 *      the admin and is NOT settable by the agent. A lesson saved over MCP is
 *      always recorded as 'agent', because the question "who told the agent
 *      this?" has to have a truthful answer.
 *
 * Caps on volume matter too: MAX_PER_TOPIC rows are kept per topic and only
 * MAX_INJECT of them are ever injected, so a loop that saves a lesson on every
 * failure cannot inflate a description without bound.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Lessons
{
    const SCHEMA = 1;

    const OPTION_SCHEMA = 'keelhob_lessons_schema';

    /** Hard cap on one lesson, in characters. Guard 1 above. */
    const MAX_LEN = 300;

    /** Rows kept per topic. Older ones are dropped on write. */
    const MAX_PER_TOPIC = 50;

    /** How many of a topic's lessons ever reach a tool description. */
    const MAX_INJECT = 6;

    /**
     * The reserved topic whose lessons go into the server instructions rather
     * than into one tool's description — things true of the site as a whole.
     */
    const TOPIC_GENERAL = 'general';

    /** @return string[] */
    public static function sources(): array
    {
        return ['owner', 'agent'];
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'keelhob_lessons';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        /*
         * `topic` holds a tool name or a free-text subject, and it is a string.
         * Not `object_id`: $wpdb casts that name to an integer and would store
         * every topic as 0. Gotcha 1 in the reference notes.
         */
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            topic VARCHAR(191) NOT NULL,
            lesson VARCHAR(400) NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'agent',
            author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY topic (topic)
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

    // ---- sanitising -------------------------------------------------------

    /**
     * Reduce whatever arrived to one line of plain text, at most MAX_LEN
     * characters. Guards 1 and 2. Pure, so it is testable without a database
     * and so the cap cannot be bypassed by reaching a different writer.
     *
     * wp_strip_all_tags() is run before the length cap, not after: capping
     * first would let 300 characters of markup survive as 40 characters of
     * text, and would also let a truncated tag through.
     */
    public static function clean(string $text): string
    {
        // Script and style bodies go first, element and all. wp_strip_all_tags()
        // removes the tags but keeps what was between them, so "<script>do the
        // thing</script>" survives as "do the thing" — which on this surface is
        // a sentence an agent then reads as an instruction.
        $text = (string) preg_replace('@<(script|style)\b[^>]*>.*?</\1>@is', ' ', $text);
        $text = wp_strip_all_tags($text, true);
        // Zero-width, bidi overrides and other invisible steering characters.
        // A lesson is read by a model AND shown to the owner in the admin; a
        // string that renders differently in those two places is exactly the
        // shape of a hidden instruction.
        $text = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $text);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > self::MAX_LEN) {
            $text = rtrim(mb_substr($text, 0, self::MAX_LEN - 1)) . "\u{2026}";
        }
        return $text;
    }

    /** A topic is a tool name or a short subject. Lowercased, punctuation out. */
    public static function clean_topic(string $topic): string
    {
        $topic = strtolower(trim(wp_strip_all_tags($topic, true)));
        $topic = (string) preg_replace('/[^a-z0-9_\- ]+/', '', $topic);
        $topic = trim((string) preg_replace('/\s+/', ' ', $topic));
        return substr($topic, 0, 191);
    }

    // ---- writing ----------------------------------------------------------

    /**
     * Save a lesson.
     *
     * $source is NOT taken from the caller's own claim about itself when that
     * caller is a tool: Keelhob_Tools_* passes 'agent' and nothing else can.
     * Guard 4.
     *
     * @return array{success:bool,error?:string,id?:int,lesson?:string,topic?:string}
     */
    public static function save(string $topic, string $lesson, string $source = 'agent', int $author_id = 0): array
    {
        global $wpdb;
        $topic  = self::clean_topic($topic);
        $lesson = self::clean($lesson);
        $source = in_array($source, self::sources(), true) ? $source : 'agent';

        if ($topic === '') {
            return ['success' => false, 'error' => 'A lesson needs a topic — a tool name, or a short subject like "seo".'];
        }
        if ($lesson === '') {
            return ['success' => false, 'error' => 'The lesson is empty once tags and whitespace are stripped.'];
        }

        // No duplicates. A failing agent that saves the same conclusion on
        // every retry would otherwise fill the whole injected budget with one
        // sentence repeated six times.
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table() . " WHERE topic = %s AND lesson = %s",
            $topic,
            $lesson
        ));
        if ($existing) {
            return ['success' => true, 'id' => (int) $existing, 'topic' => $topic, 'lesson' => $lesson, 'duplicate' => true];
        }

        $now = current_time('mysql');
        $wpdb->insert(self::table(), [
            'topic'      => $topic,
            'lesson'     => $lesson,
            'source'     => $source,
            'author_id'  => max(0, $author_id ?: (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $wpdb->insert_id;

        self::trim_topic($topic);

        return ['success' => true, 'id' => $id, 'topic' => $topic, 'lesson' => $lesson];
    }

    /**
     * Owner edit. The text is re-cleaned through exactly the same path as a
     * save, and the row is re-stamped as the owner's: once a person has
     * rewritten a sentence, "the agent said this" is no longer true, and the
     * admin must not keep claiming it.
     */
    public static function update(int $id, string $lesson): array
    {
        global $wpdb;
        $lesson = self::clean($lesson);
        if ($id <= 0) {
            return ['success' => false, 'error' => 'No lesson id.'];
        }
        if ($lesson === '') {
            return ['success' => false, 'error' => 'The lesson is empty once tags and whitespace are stripped.'];
        }
        $wpdb->update(self::table(), [
            'lesson'     => $lesson,
            'source'     => 'owner',
            'author_id'  => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
        return ['success' => true, 'id' => $id, 'lesson' => $lesson];
    }

    public static function delete(int $id): array
    {
        global $wpdb;
        if ($id <= 0) {
            return ['success' => false, 'error' => 'No lesson id.'];
        }
        $wpdb->delete(self::table(), ['id' => $id]);
        return ['success' => true, 'id' => $id];
    }

    /** Keep only the newest MAX_PER_TOPIC rows for a topic. */
    private static function trim_topic(string $topic): void
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM " . self::table() . " WHERE topic = %s ORDER BY id DESC",
            $topic
        ));
        $ids = array_map('intval', (array) $ids);
        if (count($ids) <= self::MAX_PER_TOPIC) {
            return;
        }
        foreach (array_slice($ids, self::MAX_PER_TOPIC) as $old) {
            $wpdb->delete(self::table(), ['id' => $old]);
        }
    }

    // ---- reading ----------------------------------------------------------

    /** @return array<int, array> newest first */
    public static function for_topic(string $topic, int $limit = self::MAX_INJECT): array
    {
        global $wpdb;
        $topic = self::clean_topic($topic);
        if ($topic === '') {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE topic = %s ORDER BY id DESC LIMIT %d",
            $topic,
            max(1, min(self::MAX_PER_TOPIC, $limit))
        ), ARRAY_A);
        return $rows ?: [];
    }

    /** @return array<int, array> every lesson, newest first — the admin list */
    public static function all(int $limit = 200): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT %d",
            max(1, min(500, $limit))
        ), ARRAY_A);
        return $rows ?: [];
    }

    public static function find(int $id): ?array
    {
        global $wpdb;
        if ($id <= 0) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function count_all(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table());
    }

    /**
     * The block that goes into a tool description, or '' when there is nothing
     * to say.
     *
     * Each line is prefixed with who said it. The agent reading this should
     * weight "the owner told us" above "we worked this out once", and more
     * importantly: if a lesson is wrong, the attribution is what lets a human
     * reading a transcript work out where it came from.
     */
    public static function description_block(string $topic): string
    {
        $rows = self::for_topic($topic, self::MAX_INJECT);
        if (!$rows) {
            return '';
        }
        $lines = [];
        foreach ($rows as $row) {
            $who = ((string) ($row['source'] ?? 'agent')) === 'owner' ? 'owner' : 'learned here';
            $lines[] = sprintf('- %s (%s)', (string) $row['lesson'], $who);
        }
        return "Lessons saved on this site:\n" . implode("\n", $lines);
    }
}
