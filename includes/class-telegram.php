<?php
/**
 * Telegram chat front-end for the on-site agent.
 *
 * Claudpress could already *talk to* Telegram (digests and run summaries go out
 * through Claudpress_Notifier). This is the other direction: the owner messages
 * the bot, Telegram POSTs the message to a REST route here, and the text is
 * handed to Claudpress_Agent_Runtime — the same agentic loop, the same tools,
 * the same policy guardrails that a scheduled task gets. The reply goes back as
 * a Telegram message.
 *
 * The point is that no MCP client is involved. The owner does not need Claude
 * Code, a terminal, or even a laptop; the site's own API key is the brain and
 * the phone is the interface.
 *
 * It accepts text, voice notes (transcribed first) and photos (sent to the model
 * as vision input and filed in the media library), shows its work by editing one
 * message as the run progresses, offers an inline Approve button when policy
 * blocks something, and can undo the content changes of its last turn.
 *
 * Four things make that safe enough to expose on a public URL:
 *   1. Telegram signs every delivery with a secret header we generate and store,
 *      compared in constant time. A request without it never reaches the agent.
 *   2. Only chat ids on the owner's allowlist are answered at all, each with a
 *      role — admin, editor or viewer — that decides what it may do.
 *   3. Destructive/financial tools stay behind Claudpress_Policy, and a chat can
 *      only escalate when the owner has armed that switch in WP admin.
 *   4. Escalation is either a self-expiring /risky window or a per-action
 *      Approve button; nothing is ever silently permitted.
 *
 * This file is the front door, not the whole house. The Bot API client lives in
 * class-telegram-api.php and the inbound decision-making in
 * class-telegram-router.php; what stays here is what the rest of the plugin
 * asks Telegram about — is it on, who is allowed, what was said before, and
 * what has been escalated — plus the settings and constants both of the others
 * read.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Telegram
{
    const API_BASE  = 'https://api.telegram.org/bot';
    const FILE_BASE = 'https://api.telegram.org/file/bot';

    /** Telegram rejects anything longer; we split rather than truncate. */
    const MAX_MESSAGE = 4096;

    /** How long a chat's /risky opt-in survives before lapsing (seconds). */
    const RISKY_TTL = 1800;

    /** A single chat may not have two agent runs in flight (seconds). */
    const BUSY_TTL = 600;

    /** How long an unanswered Approve button stays valid (seconds). */
    const APPROVAL_TTL = 3600;

    /** Telegram rate-limits edits; don't rewrite the progress line faster. */
    const PROGRESS_THROTTLE = 2;

    /** Biggest media we will pull down from Telegram (bytes). */
    const MAX_FILE_BYTES = 20971520; // 20 MB, Telegram's own bot download ceiling

    const OPTION_LAST_UPDATE = 'claudpress_tg_last_update';
    const OPTION_RISKY       = 'claudpress_tg_risky';
    const OPTION_PENDING     = 'claudpress_tg_pending';

    /**
     * Chat roles, most to least privileged.
     *
     *   admin  — everything, may use /risky and press Approve. The default, so
     *            an allowlist written before roles existed keeps working.
     *   editor — may change the site, but can never escalate to destructive or
     *            financial tools, and cannot approve.
     *   viewer — read-only tools only. For someone who should be able to ask
     *            "how are sales?" without being able to touch anything.
     */
    const ROLES = ['admin', 'editor', 'viewer'];

    // ---- storage ----------------------------------------------------------

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'claudpress_chat';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id VARCHAR(64) NOT NULL,
            role VARCHAR(16) NOT NULL DEFAULT 'user',
            content LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY chat_id (chat_id),
            KEY created_at (created_at)
        ) {$charset};");
    }

    // ---- configuration ----------------------------------------------------

    public static function token(): string
    {
        return trim((string) Claudpress::instance()->get('notify_telegram_token', ''));
    }

    /**
     * Which bot the replay high-water mark belongs to.
     *
     * A hash, never the token: this is stored in wp_options.
     */
    private static function bot_fingerprint(): string
    {
        $token = self::token();
        return $token === '' ? '' : substr(hash('sha256', $token), 0, 16);
    }

    /**
     * The highest update_id already handled, FOR THE CURRENT BOT.
     *
     * THE BUG THIS FIXES, reported from a live site: a freshly created bot was
     * completely silent. Configuration was perfect, the webhook was registered,
     * Telegram reported zero pending deliveries and no errors — and no message
     * ever got a reply.
     *
     * update_id is monotonic PER BOT, and the mark was stored globally. The old
     * bot had reached 412,002,449. A new bot starts its own sequence far below
     * that, so every message it delivered looked like a replay of something
     * already handled and was dropped. The endpoint answered 200, Telegram was
     * satisfied, and nothing downstream ever ran — which is why changing the AI
     * model made no difference: the code never reached the model.
     *
     * Tying the mark to the bot makes swapping tokens self-healing, with no
     * button to press and nothing for anyone to know.
     *
     * A legacy bare integer is treated as belonging to SOMEBODY ELSE'S bot and
     * discarded, because the two failures are not equal: a stale mark is total,
     * permanent silence that looks like a broken product, while a missing mark
     * risks at most one duplicated message if Telegram happens to retry right
     * then. Take the mild, visible failure over the catastrophic, invisible one.
     */
    public static function last_update_id(): int
    {
        $stored = get_option(self::OPTION_LAST_UPDATE, null);
        if (!is_array($stored)) {
            return 0;
        }
        return (string) ($stored['bot'] ?? '') === self::bot_fingerprint()
            ? (int) ($stored['id'] ?? 0)
            : 0;
    }

    public static function remember_update_id(int $update_id): void
    {
        update_option(self::OPTION_LAST_UPDATE, [
            'bot' => self::bot_fingerprint(),
            'id'  => $update_id,
        ]);
    }

    /** Inbound chat is off unless the owner switched it on and set a token. */
    public static function enabled(): bool
    {
        return (bool) Claudpress::instance()->get('telegram_bot_enabled', false) && self::token() !== '';
    }

    /**
     * The shared secret Telegram echoes back on every delivery. Generated on
     * first use so an owner never has to invent one, and rotated by the admin
     * button (which re-registers the webhook).
     */
    public static function secret(): string
    {
        $secret = trim((string) Claudpress::instance()->get('telegram_secret', ''));
        if ($secret === '') {
            $secret = self::generate_secret();
            Claudpress::instance()->update(['telegram_secret' => $secret]);
        }
        return $secret;
    }

    public static function generate_secret(): string
    {
        // Telegram allows A-Z a-z 0-9 _ - only, 1-256 chars.
        return bin2hex(random_bytes(24));
    }

    /**
     * Chat ids allowed to drive the site. Falls back to the notification chat
     * id, so an owner who already configured outbound Telegram is allowlisted
     * without configuring the same number twice.
     *
     * @return array<int, string>
     */
    public static function allowed_chats(): array
    {
        // strval because PHP silently turns a numeric string array key into an
        // int, and every chat id comparison downstream is a strict string one.
        return array_map('strval', array_keys(self::chat_roles()));
    }

    /**
     * The allowlist as id => role.
     *
     * @return array<string, string>
     */
    public static function chat_roles(): array
    {
        $s   = Claudpress::instance()->settings();
        $raw = trim((string) ($s['telegram_allowed_chats'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($s['notify_telegram_chat'] ?? ''));
        }
        return self::parse_chat_roles($raw);
    }

    /**
     * Parse "123, 456:viewer, -100987:editor" into id => role.
     *
     * A bare id means admin: the allowlist predates roles, and silently
     * demoting an existing owner's own chat on upgrade would look like a bug.
     * An unrecognised role also means admin rather than being dropped — losing
     * access over a typo is worse than the typo.
     *
     * @return array<string, string>
     */
    public static function parse_chat_roles(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $bits = explode(':', $part, 2);
            $id   = trim($bits[0]);
            if ($id === '') {
                continue;
            }
            $role = strtolower(trim((string) ($bits[1] ?? '')));
            $out[$id] = in_array($role, self::ROLES, true) ? $role : 'admin';
        }
        return $out;
    }

    /** @return array<int, string> ids only, for the plain-list callers */
    public static function parse_chat_list(string $raw): array
    {
        return array_map('strval', array_keys(self::parse_chat_roles($raw)));
    }

    /**
     * An empty allowlist denies everyone. That is deliberate: the alternative
     * reading — "not configured means open" — would hand the whole site to the
     * first stranger who found the bot.
     */
    public static function is_allowed(string $chat_id, array $allowed): bool
    {
        return $chat_id !== '' && in_array($chat_id, $allowed, true);
    }

    /** @return string|null the chat's role, or null when it is not allowlisted */
    public static function role_for(string $chat_id): ?string
    {
        if ($chat_id === '') {
            return null;
        }
        // A numeric chat id is an int key in the parsed map (PHP's doing), and a
        // string lookup coerces to match it — but '0'/'' must not, so the blank
        // check above stands guard.
        $roles = self::chat_roles();
        return isset($roles[$chat_id]) ? (string) $roles[$chat_id] : null;
    }

    public static function webhook_url(): string
    {
        return rest_url(CLAUDPRESS_REST_NAMESPACE . '/telegram');
    }

    public static function history_turns(): int
    {
        $n = (int) Claudpress::instance()->get('telegram_history_turns', 12);
        return max(0, min(60, $n));
    }

    public static function voice_enabled(): bool
    {
        return (bool) Claudpress::instance()->get('telegram_voice', false)
            && Claudpress_Transcribe_Provider::key() !== '';
    }

    public static function photos_enabled(): bool
    {
        return (bool) Claudpress::instance()->get('telegram_photos', false);
    }

    public static function progress_enabled(): bool
    {
        return (bool) Claudpress::instance()->get('telegram_progress', true);
    }

    // ---- pure helpers -----------------------------------------------------

    /**
     * Split "/clear@MyBot some args" into its command and the rest. Telegram
     * appends @botname when several bots share a group, and it is not part of
     * the command the owner typed.
     *
     * @return array{cmd: string, args: string} cmd is '' for ordinary messages
     */
    public static function parse_command(string $text): array
    {
        $text = trim($text);
        if ($text === '' || $text[0] !== '/') {
            return ['cmd' => '', 'args' => $text];
        }
        $parts = preg_split('/\s+/', $text, 2);
        $cmd   = strtolower(ltrim((string) $parts[0], '/'));
        if (strpos($cmd, '@') !== false) {
            $cmd = substr($cmd, 0, strpos($cmd, '@'));
        }
        // Telegram commands start with a letter, so "/2 orders came in?" is a
        // sentence the owner typed, not a command we should fail to recognise.
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $cmd)) {
            return ['cmd' => '', 'args' => $text];
        }
        return ['cmd' => $cmd, 'args' => trim((string) ($parts[1] ?? ''))];
    }

    /**
     * Break a reply into Telegram-sized chunks, preferring paragraph then line
     * boundaries so a split never lands mid-sentence when it doesn't have to.
     *
     * @return array<int, string>
     */
    public static function split_message(string $text, int $limit = self::MAX_MESSAGE): array
    {
        $text  = trim($text);
        $limit = max(64, $limit);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        while (mb_strlen($text) > $limit) {
            $window = mb_substr($text, 0, $limit);
            $cut    = mb_strrpos($window, "\n\n");
            if ($cut === false || $cut < (int) ($limit * 0.5)) {
                $cut = mb_strrpos($window, "\n");
            }
            if ($cut === false || $cut < (int) ($limit * 0.5)) {
                $cut = mb_strrpos($window, ' ');
            }
            if ($cut === false || $cut < 1) {
                $cut = $limit;
            }
            $chunks[] = trim(mb_substr($text, 0, $cut));
            $text     = ltrim(mb_substr($text, $cut));
        }
        if (trim($text) !== '') {
            $chunks[] = trim($text);
        }
        return array_values(array_filter($chunks, static fn($c) => $c !== ''));
    }

    /**
     * Keep the last N turns, and never open the replay on an assistant turn —
     * a conversation that starts mid-answer reads as though the model invented
     * it, and some vendors reject a leading assistant message outright.
     *
     * @param array<int, array{role: string, text: string}> $turns oldest first
     */
    public static function trim_history(array $turns, int $max_turns): array
    {
        if ($max_turns <= 0) {
            return [];
        }
        $turns = array_slice(array_values($turns), -$max_turns);
        while (!empty($turns) && ($turns[0]['role'] ?? 'user') !== 'user') {
            array_shift($turns);
        }
        return array_values($turns);
    }

    /**
     * The footer that turns "it says it did something" into something the owner
     * can audit: how much work it did and roughly what it cost.
     */
    public static function format_reply(array $run): string
    {
        if (empty($run['success'])) {
            return '⚠️ ' . (string) ($run['error'] ?? 'The run failed.');
        }

        $body  = trim((string) ($run['summary'] ?? '')) ?: '(no reply)';
        $usage = is_array($run['usage'] ?? null) ? $run['usage'] : [];
        $bits  = [];

        $tools = count((array) ($run['tool_calls'] ?? []));
        if ($tools > 0) {
            $bits[] = $tools . ($tools === 1 ? ' tool' : ' tools');
        }
        if (!empty($usage['cost_usd'])) {
            $bits[] = '~$' . number_format((float) $usage['cost_usd'], 4);
        }

        return $bits ? $body . "\n\n— " . implode(' · ', $bits) : $body;
    }

    /**
     * The tools a run wanted but policy refused, deduplicated.
     *
     * This is what the Approve button is offered for. A capability the owner
     * switched off is excluded on purpose: that is a settled decision made in
     * WP admin, not a question to re-ask on every message.
     *
     * @return array<int, string>
     */
    public static function blocked_tools(array $run): array
    {
        $out = [];
        foreach ((array) ($run['tool_calls'] ?? []) as $call) {
            if (empty($call['blocked'])) {
                continue;
            }
            $reason = (string) ($call['reason'] ?? '');
            if ($reason === 'capability off' || $reason === 'read-only conversation') {
                continue;
            }
            $out[(string) $call['tool']] = true;
        }
        return array_keys($out);
    }

    /** One line of progress: what step we're on and what it just used. */
    public static function format_progress(array $steps): string
    {
        $lines = ['⚙️ Working…'];
        foreach (array_slice($steps, -6) as $s) {
            $tools = array_unique((array) ($s['tools'] ?? []));
            $lines[] = '• ' . ($tools ? implode(', ', $tools) : 'thinking');
        }
        return implode("\n", $lines);
    }

    // ---- conversation history --------------------------------------------

    /** @return array<int, array{role: string, text: string}> oldest first */
    public static function history(string $chat_id, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT role, content FROM " . self::table() . " WHERE chat_id = %s ORDER BY id DESC LIMIT %d",
            $chat_id,
            $limit
        ), ARRAY_A);

        $turns = [];
        foreach (array_reverse($rows ?: []) as $row) {
            $turns[] = ['role' => (string) $row['role'], 'text' => (string) $row['content']];
        }
        return self::trim_history($turns, $limit);
    }

    public static function remember(string $chat_id, string $role, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        global $wpdb;
        $wpdb->insert(self::table(), [
            'chat_id'    => substr($chat_id, 0, 64),
            'role'       => $role === 'assistant' ? 'assistant' : 'user',
            'content'    => mb_substr($text, 0, 12000),
            'created_at' => current_time('mysql'),
        ]);
    }

    /** Wipe one conversation. Returns how many turns were dropped. */
    public static function clear(string $chat_id): int
    {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE chat_id = %s",
            $chat_id
        ));
    }

    /** Wipe every conversation (the admin button). */
    public static function clear_all(): int
    {
        global $wpdb;
        return (int) $wpdb->query("DELETE FROM " . self::table());
    }

    public static function turn_count(string $chat_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE chat_id = %s",
            $chat_id
        ));
    }

    /** Housekeeping: drop conversation turns older than N days. */
    public static function prune(int $keep_days = 30): int
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($keep_days * 86400));
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::table() . " WHERE created_at < %s",
            $cutoff
        ));
    }

    // ---- per-chat risky opt-in -------------------------------------------

    /**
     * Two switches have to agree before a chat may run destructive tools: the
     * owner arms it in WP admin, and the chat opts in with /risky on. The
     * opt-in then lapses by itself, so a forgotten "on" cannot sit armed for
     * weeks.
     */
    public static function risky_allowed(string $chat_id): bool
    {
        if (!self::may_escalate($chat_id)) {
            return false;
        }
        $map   = (array) get_option(self::OPTION_RISKY, []);
        $until = (int) ($map[$chat_id] ?? 0);
        return $until > time();
    }

    /**
     * Whether this chat is even permitted to escalate — by /risky or by pressing
     * Approve. Requires the owner's master switch and the admin role.
     */
    public static function may_escalate(string $chat_id): bool
    {
        return (bool) Claudpress::instance()->get('telegram_allow_risky', false)
            && self::role_for($chat_id) === 'admin';
    }

    public static function set_risky(string $chat_id, bool $on): int
    {
        $map = (array) get_option(self::OPTION_RISKY, []);
        if ($on) {
            $map[$chat_id] = time() + self::RISKY_TTL;
        } else {
            unset($map[$chat_id]);
        }
        // Drop lapsed entries while we are here.
        foreach ($map as $id => $until) {
            if ((int) $until <= time()) {
                unset($map[$id]);
            }
        }
        update_option(self::OPTION_RISKY, $map);
        return $on ? (int) (self::RISKY_TTL / 60) : 0;
    }

    // ---- pending approvals ------------------------------------------------

    /**
     * Park an instruction behind an Approve button and return its token.
     *
     * The instruction is stored server-side and the button carries only the
     * token, because callback_data is 64 bytes and, more importantly, is
     * attacker-supplied on the way back in — it must not be something we
     * execute.
     */
    public static function stash_approval(string $chat_id, string $instruction, array $blocked): string
    {
        $token   = substr(bin2hex(random_bytes(8)), 0, 16);
        $pending = self::pending();

        $pending[$token] = [
            'chat'        => $chat_id,
            'instruction' => mb_substr($instruction, 0, 4000),
            'blocked'     => array_values($blocked),
            'at'          => time(),
        ];
        self::save_pending($pending);
        return $token;
    }

    /** @return array<string, array>|array the live (unexpired) approvals */
    public static function pending(): array
    {
        $pending = get_option(self::OPTION_PENDING, []);
        $pending = is_array($pending) ? $pending : [];
        $cutoff  = time() - self::APPROVAL_TTL;

        foreach ($pending as $token => $row) {
            if ((int) ($row['at'] ?? 0) < $cutoff) {
                unset($pending[$token]);
            }
        }
        return $pending;
    }

    private static function save_pending(array $pending): void
    {
        // Keep the store small; an owner who ignored 20 buttons does not need
        // the 21st to be remembered either.
        if (count($pending) > 20) {
            uasort($pending, static fn($a, $b) => (int) ($b['at'] ?? 0) <=> (int) ($a['at'] ?? 0));
            $pending = array_slice($pending, 0, 20, true);
        }
        update_option(self::OPTION_PENDING, $pending);
    }

    /**
     * Redeem a token. Single-use: it is deleted whether approved or declined,
     * so a button cannot be pressed twice to run something twice.
     *
     * @return array|null the stashed row, or null when unknown/expired/foreign
     */
    public static function claim_approval(string $token, string $chat_id): ?array
    {
        $pending = self::pending();
        $row     = $pending[$token] ?? null;
        if (!is_array($row)) {
            self::save_pending($pending);
            return null;
        }
        unset($pending[$token]);
        self::save_pending($pending);

        // A token belongs to the chat it was offered to.
        return ((string) ($row['chat'] ?? '') === $chat_id) ? $row : null;
    }

    // ---- Telegram API and inbound routing ---------------------------------
    //
    // The wire protocol moved to Claudpress_Telegram_Api and the inbound
    // decisions to Claudpress_Telegram_Router. These stay here because they are
    // what the rest of the plugin calls, and a class name in a caller is as much
    // a contract as a hook is. Both new classes may be called directly; nothing
    // has to come through this door.

    public static function handle_update(array $update): array
    {
        return Claudpress_Telegram_Router::handle_update($update);
    }

    public static function run_command(string $chat_id, string $cmd, string $args): ?string
    {
        return Claudpress_Telegram_Router::run_command($chat_id, $cmd, $args);
    }

    public static function commands(): array
    {
        return Claudpress_Telegram_Api::commands();
    }

    public static function extract_media(array $message): ?array
    {
        return Claudpress_Telegram_Api::extract_media($message);
    }

    public static function webhook_info(): array
    {
        return Claudpress_Telegram_Api::webhook_info();
    }

    public static function set_webhook(): array
    {
        return Claudpress_Telegram_Api::set_webhook();
    }

    public static function delete_webhook(): array
    {
        return Claudpress_Telegram_Api::delete_webhook();
    }
}
