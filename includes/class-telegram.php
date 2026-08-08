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
 * Three things make that safe enough to expose on a public URL:
 *   1. Telegram signs every delivery with a secret header we generate and store,
 *      compared in constant time. A request without it never reaches the agent.
 *   2. Only chat ids on the owner's allowlist are answered at all.
 *   3. Destructive/financial tools stay behind Claudpress_Policy, and the chat
 *      can only opt in when the owner has armed that switch in WP admin — and
 *      the opt-in expires on its own.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Telegram
{
    const API_BASE = 'https://api.telegram.org/bot';

    /** Telegram rejects anything longer; we split rather than truncate. */
    const MAX_MESSAGE = 4096;

    /** How long a chat's /risky opt-in survives before lapsing (seconds). */
    const RISKY_TTL = 1800;

    /** A single chat may not have two agent runs in flight (seconds). */
    const BUSY_TTL = 600;

    const OPTION_LAST_UPDATE = 'claudpress_tg_last_update';
    const OPTION_RISKY       = 'claudpress_tg_risky';

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
        $s   = Claudpress::instance()->settings();
        $raw = trim((string) ($s['telegram_allowed_chats'] ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($s['notify_telegram_chat'] ?? ''));
        }
        return self::parse_chat_list($raw);
    }

    /** @return array<int, string> */
    public static function parse_chat_list(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return array_values(array_unique($out));
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

    public static function webhook_url(): string
    {
        return rest_url(CLAUDPRESS_REST_NAMESPACE . '/telegram');
    }

    public static function history_turns(): int
    {
        $n = (int) Claudpress::instance()->get('telegram_history_turns', 12);
        return max(0, min(60, $n));
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
        if (!Claudpress::instance()->get('telegram_allow_risky', false)) {
            return false;
        }
        $map   = (array) get_option(self::OPTION_RISKY, []);
        $until = (int) ($map[$chat_id] ?? 0);
        return $until > time();
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

    // ---- Telegram API -----------------------------------------------------

    /** @return array decoded response, or ['ok' => false, 'description' => …] */
    public static function api(string $method, array $params = []): array
    {
        $token = self::token();
        if ($token === '') {
            return ['ok' => false, 'description' => 'No Telegram bot token configured.'];
        }
        $resp = wp_remote_post(self::API_BASE . $token . '/' . $method, [
            'timeout' => 20,
            'body'    => $params,
        ]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'description' => $resp->get_error_message()];
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        return is_array($data) ? $data : ['ok' => false, 'description' => 'Malformed Telegram response.'];
    }

    /** Send a reply, split across messages when it exceeds Telegram's limit. */
    public static function send(string $chat_id, string $text): void
    {
        foreach (self::split_message($text) as $chunk) {
            self::api('sendMessage', [
                'chat_id'                  => $chat_id,
                'text'                     => $chunk,
                'disable_web_page_preview' => 'true',
            ]);
        }
    }

    /** The "…is typing" indicator, so a 40-second run doesn't look dead. */
    public static function typing(string $chat_id): void
    {
        self::api('sendChatAction', ['chat_id' => $chat_id, 'action' => 'typing']);
    }

    public static function get_me(): array
    {
        return self::api('getMe');
    }

    public static function webhook_info(): array
    {
        return self::api('getWebhookInfo');
    }

    /**
     * Point the bot at this site. Registering the command menu at the same time
     * means the owner gets the slash-command list in Telegram's own UI instead
     * of having to remember it.
     */
    public static function set_webhook(): array
    {
        $res = self::api('setWebhook', [
            'url'             => self::webhook_url(),
            'secret_token'    => self::secret(),
            'allowed_updates' => wp_json_encode(['message']),
            'drop_pending_updates' => 'true',
        ]);
        if (!empty($res['ok'])) {
            self::register_commands();
        }
        return $res;
    }

    public static function delete_webhook(): array
    {
        return self::api('deleteWebhook', ['drop_pending_updates' => 'true']);
    }

    public static function register_commands(): array
    {
        $commands = [];
        foreach (self::commands() as $cmd => $desc) {
            $commands[] = ['command' => $cmd, 'description' => $desc];
        }
        return self::api('setMyCommands', ['commands' => wp_json_encode($commands)]);
    }

    /** @return array<string, string> command => description */
    public static function commands(): array
    {
        return [
            'help'   => 'What I can do and how to talk to me',
            'clear'  => 'Forget this conversation and start fresh',
            'status' => 'Site & store snapshot (free, no AI spend)',
            'usage'  => 'Runs, tokens and estimated spend in the last 24h',
            'tasks'  => 'Scheduled automations and when they last ran',
            'model'  => 'Which provider and model I am running on',
            'risky'  => 'on|off — allow destructive/financial tools for a while',
            'whoami' => 'Your chat id and whether it is allowlisted',
        ];
    }

    // ---- inbound ----------------------------------------------------------

    /**
     * Entry point from the REST route. Returns a small array for the HTTP
     * response; Telegram ignores the body but the shape helps debugging and
     * the live smoke test.
     */
    public static function handle_update(array $update): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }

        // Telegram retries a delivery it thinks failed, and an agent run is far
        // too slow to ack inside its patience. update_id is monotonic, so a
        // high-water mark makes a replay a no-op instead of doing the work twice.
        $update_id = (int) ($update['update_id'] ?? 0);
        $last      = (int) get_option(self::OPTION_LAST_UPDATE, 0);
        if ($update_id > 0 && $update_id <= $last) {
            return ['ok' => true, 'reason' => 'duplicate'];
        }
        if ($update_id > 0) {
            update_option(self::OPTION_LAST_UPDATE, $update_id);
        }

        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $chat_id = (string) ($message['chat']['id'] ?? '');
        $text    = trim((string) ($message['text'] ?? ''));

        if ($chat_id === '') {
            return ['ok' => true, 'reason' => 'no chat'];
        }

        if (!self::is_allowed($chat_id, self::allowed_chats())) {
            // Tell them their id and nothing else. The owner needs this exact
            // number to allowlist themselves, and it reveals nothing about the
            // site to anyone else who stumbles onto the bot.
            self::send($chat_id, "Not authorised for this site.\n\nYour chat id is " . $chat_id . " — the site owner can add it in WP Admin → Claudpress → Automation.");
            return ['ok' => true, 'reason' => 'not allowed'];
        }

        if ($text === '') {
            self::send($chat_id, 'I can only read text messages for now — no photos, voice notes or files yet.');
            return ['ok' => true, 'reason' => 'no text'];
        }

        $parsed = self::parse_command($text);
        if ($parsed['cmd'] !== '') {
            $reply = self::run_command($chat_id, $parsed['cmd'], $parsed['args']);
            if ($reply !== null) {
                self::send($chat_id, $reply);
                return ['ok' => true, 'reason' => 'command:' . $parsed['cmd']];
            }
            // Unknown command — fall through and let the agent read it as text.
        }

        return self::run_chat($chat_id, $text);
    }

    /** Hand a message to the agent and reply with what it did. */
    private static function run_chat(string $chat_id, string $text): array
    {
        $lock = 'claudpress_tg_busy_' . md5($chat_id);
        if (get_transient($lock)) {
            self::send($chat_id, '⏳ Still working on your last message. Send this again once I reply.');
            return ['ok' => true, 'reason' => 'busy'];
        }
        set_transient($lock, 1, self::BUSY_TTL);

        self::typing($chat_id);
        @set_time_limit(0);

        try {
            $run = Claudpress_Agent_Runtime::run($text, [
                'history'     => self::history($chat_id, self::history_turns()),
                'interactive' => true,
                'channel'     => 'Telegram',
                'context'     => 'telegram',
                'allow_risky' => self::risky_allowed($chat_id),
            ]);
        } catch (Throwable $e) {
            delete_transient($lock);
            self::send($chat_id, '⚠️ Something broke while running that: ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'exception'];
        }

        delete_transient($lock);

        $reply = self::format_reply($run);

        // Only a successful exchange becomes history. Storing a failed turn
        // would feed the model an error transcript it then tries to explain.
        if (!empty($run['success'])) {
            self::remember($chat_id, 'user', $text);
            self::remember($chat_id, 'assistant', (string) ($run['summary'] ?? ''));
        }

        self::send($chat_id, $reply);
        return ['ok' => true, 'reason' => 'ran', 'success' => !empty($run['success'])];
    }

    /**
     * Built-in commands. These are answered by the plugin directly, without an
     * API call — /status and /usage in particular exist so the owner can check
     * on the site all day without spending a cent of their key.
     *
     * @return string|null null when the text is not a command we know
     */
    public static function run_command(string $chat_id, string $cmd, string $args): ?string
    {
        switch ($cmd) {
            case 'start':
            case 'help':
                $lines = [
                    '👋 I run ' . get_bloginfo('name') . ' (' . home_url() . ').',
                    '',
                    'Just tell me what you want in plain English — "how did the store do this week?", "publish the draft about winter tyres", "the checkout page footer is broken, fix it". I use the site\'s own tools and report back.',
                    '',
                    'Commands:',
                ];
                foreach (self::commands() as $c => $desc) {
                    $lines[] = '/' . $c . ' — ' . $desc;
                }
                $lines[] = '';
                $lines[] = 'I remember the last ' . self::history_turns() . ' turns of this chat. /clear wipes them.';
                return implode("\n", $lines);

            case 'clear':
            case 'reset':
            case 'new':
                $n = self::clear($chat_id);
                return $n > 0
                    ? '🧹 Cleared ' . $n . ' ' . ($n === 1 ? 'turn' : 'turns') . '. Fresh start — I no longer remember what we were doing.'
                    : '🧹 Nothing to clear; this conversation was already empty.';

            case 'status':
                $snapshot = Claudpress_Digest::snapshot();
                return "📊 " . get_bloginfo('name') . "\n\n" . Claudpress_Digest::format_text($snapshot);

            case 'usage':
                $u    = Claudpress_Agent_Runs::usage_since(24);
                $caps = Claudpress_Agent_Runtime::caps();
                $cap  = static fn($v, $fmt) => $v ? sprintf($fmt, $v) : 'unlimited';
                return implode("\n", [
                    '💳 Last 24 hours',
                    'Runs: ' . (int) $u['runs'] . ' of ' . $cap((int) $caps['max_runs_per_day'], '%d'),
                    'Tokens: ' . number_format((int) $u['tokens']),
                    'Estimated spend: $' . number_format((float) $u['cost'], 4) . ' of ' . $cap((float) $caps['max_usd_per_day'], '$%.2f'),
                    '',
                    'Per message: ' . $cap((float) $caps['max_usd_per_run'], '$%.2f') . ' / ' . $cap((int) $caps['max_tokens_per_run'], '%d tokens'),
                    'Costs are estimates from token counts, not your provider\'s bill.',
                ]);

            case 'tasks':
                $tasks = Claudpress_Tasks::all();
                if (empty($tasks)) {
                    return '🗓 No scheduled automations yet. Ask me to create one — "every morning at 7, publish due drafts and email me a summary".';
                }
                $lines = ['🗓 Scheduled automations:'];
                foreach ($tasks as $t) {
                    $when  = (string) $t['schedule'];
                    if ($t['run_hour'] !== null && $t['run_hour'] !== '') {
                        $when .= sprintf(' @ %02d:00', (int) $t['run_hour']);
                    }
                    $lines[] = ((int) $t['enabled'] === 1 ? '✅' : '⏸') . ' ' . $t['name']
                        . ' (' . $t['mode'] . ', ' . $when . ')'
                        . ' — last: ' . ($t['last_run'] ? $t['last_run'] . ' ' . (string) $t['last_status'] : 'never');
                }
                return implode("\n", $lines);

            case 'model':
                $s = Claudpress::instance()->settings();
                /** @var class-string<Claudpress_Provider> $p */
                $p = Claudpress_Provider::current();
                return implode("\n", [
                    '🧠 Provider: ' . $p::label(),
                    'Model: ' . ((string) ($s['agent_model'] ?? '') ?: $p::default_model() . ' (default)'),
                    'API key: ' . (trim((string) ($s['agent_api_key'] ?? '')) !== '' ? 'set' : 'MISSING — I cannot think without one'),
                    'AI abilities: ' . (Claudpress::instance()->abilities_enabled() ? 'ON' : 'OFF — I can read settings but not act'),
                ]);

            case 'risky':
                $want = strtolower(trim($args));
                if (!Claudpress::instance()->get('telegram_allow_risky', false)) {
                    return '🔒 Destructive and financial tools are locked for Telegram. The owner can unlock them in WP Admin → Claudpress → Automation → Chat.';
                }
                if ($want === 'on') {
                    $mins = self::set_risky($chat_id, true);
                    return '⚠️ Destructive/financial tools unlocked for ' . $mins . ' minutes. They re-lock on their own.';
                }
                if ($want === 'off') {
                    self::set_risky($chat_id, false);
                    return '🔒 Re-locked. Destructive and financial tools are blocked again.';
                }
                return self::risky_allowed($chat_id)
                    ? '⚠️ Currently unlocked. Send /risky off to re-lock.'
                    : '🔒 Currently locked. Send /risky on to unlock for ' . (int) (self::RISKY_TTL / 60) . ' minutes.';

            case 'whoami':
            case 'id':
                $allowed = self::is_allowed($chat_id, self::allowed_chats());
                return 'Chat id: ' . $chat_id . "\n" . ($allowed ? '✅ allowlisted for this site' : '⛔ not allowlisted');

            case 'history':
                $n = self::turn_count($chat_id);
                return '🧠 ' . $n . ' ' . ($n === 1 ? 'turn' : 'turns') . ' stored; I replay the last ' . self::history_turns() . '. /clear wipes them.';
        }

        return null;
    }
}
