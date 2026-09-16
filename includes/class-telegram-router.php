<?php
/**
 * What happens to a Telegram message once it has arrived.
 *
 * One inbound update comes in and this file decides what it is — a command, a
 * voice note, a photo, an Approve button, or something to think about — and
 * drives it to a reply. It is the only part of the Telegram front-end that
 * starts an agent run, and the only part that writes back to the conversation.
 *
 * Everything it needs is borrowed rather than owned: the allowlist, the roles
 * and the conversation store are Claudpress_Telegram's, and every byte on the
 * wire goes through Claudpress_Telegram_Api. That leaves this file as the
 * decisions, which is the part worth reading when the bot does something
 * unexpected.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Telegram_Router
{
    // ---- inbound ----------------------------------------------------------

    /**
     * Entry point from the REST route. Returns a small array for the HTTP
     * response; Telegram ignores the body but the shape helps debugging and
     * the live smoke test.
     */
    public static function handle_update(array $update): array
    {
        if (!Claudpress_Telegram::enabled()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }

        // Telegram retries a delivery it thinks failed, and an agent run is far
        // too slow to ack inside its patience. update_id is monotonic, so a
        // high-water mark makes a replay a no-op instead of doing the work twice.
        // The mark is per BOT, not global: update_id is monotonic within one
        // bot, so a mark left by a previous token makes every message from a
        // new one look like a replay. See Claudpress_Telegram::last_update_id().
        $update_id = (int) ($update['update_id'] ?? 0);
        $last      = Claudpress_Telegram::last_update_id();
        if ($update_id > 0 && $update_id <= $last) {
            return ['ok' => true, 'reason' => 'duplicate'];
        }
        if ($update_id > 0) {
            Claudpress_Telegram::remember_update_id($update_id);
        }

        if (is_array($update['callback_query'] ?? null)) {
            return self::handle_callback($update['callback_query']);
        }

        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $chat_id = (string) ($message['chat']['id'] ?? '');
        $text    = trim((string) ($message['text'] ?? ''));

        if ($chat_id === '') {
            return ['ok' => true, 'reason' => 'no chat'];
        }

        if (Claudpress_Telegram::role_for($chat_id) === null) {
            // Tell them their id and nothing else. The owner needs this exact
            // number to allowlist themselves, and it reveals nothing about the
            // site to anyone else who stumbles onto the bot.
            Claudpress_Telegram_Api::send($chat_id, "Not authorised for this site.\n\nYour chat id is " . $chat_id . " — the site owner can add it in WP Admin → Claudpress → Automation.");
            return ['ok' => true, 'reason' => 'not allowed'];
        }

        // Voice notes and photos become text (plus, for photos, an image the
        // model can see) before anything else looks at the message.
        $media = Claudpress_Telegram_Api::extract_media($message);
        $image = null;

        if ($media !== null) {
            $converted = self::absorb_media($chat_id, $media, trim((string) ($message['caption'] ?? '')));
            if (empty($converted['success'])) {
                Claudpress_Telegram_Api::send($chat_id, '⚠️ ' . (string) $converted['error']);
                return ['ok' => true, 'reason' => 'media failed'];
            }
            $text  = (string) $converted['text'];
            $image = $converted['image'] ?? null;
        }

        if ($text === '') {
            Claudpress_Telegram_Api::send($chat_id, 'I got that, but there was nothing in it for me to act on — send some text, a voice note, or a photo with a caption.');
            return ['ok' => true, 'reason' => 'no text'];
        }

        $parsed = Claudpress_Telegram::parse_command($text);
        if ($parsed['cmd'] !== '') {
            $reply = self::run_command($chat_id, $parsed['cmd'], $parsed['args']);
            if ($reply !== null) {
                Claudpress_Telegram_Api::send($chat_id, $reply);
                return ['ok' => true, 'reason' => 'command:' . $parsed['cmd']];
            }
            // Unknown command — fall through and let the agent read it as text.
        }

        return self::run_chat($chat_id, $text, $image);
    }

    /**
     * Convert an incoming voice note or photo into something the agent can work
     * with, telling the owner what we understood on the way.
     *
     * @return array{success: bool, text?: string, image?: array, error?: string}
     */
    private static function absorb_media(string $chat_id, array $media, string $caption): array
    {
        if ($media['kind'] === 'voice') {
            if (!Claudpress_Telegram::voice_enabled()) {
                return ['success' => false, 'error' => 'Voice notes are switched off (or have no transcription key). Send text instead.'];
            }
            Claudpress_Telegram_Api::typing($chat_id);
            $t = Claudpress_Telegram_Api::transcribe($media['file_id'], $media['mime']);
            if (empty($t['success'])) {
                return ['success' => false, 'error' => 'I could not transcribe that: ' . (string) $t['error']];
            }
            // Echo the transcript back: a wrong transcription that silently acts
            // on the site is much worse than one the owner can see and correct.
            Claudpress_Telegram_Api::send($chat_id, '🎙 ' . (string) $t['text']);
            return ['success' => true, 'text' => trim((string) $t['text'])];
        }

        if (!Claudpress_Telegram::photos_enabled()) {
            return ['success' => false, 'error' => 'Photos are switched off. Describe it in words, or enable photos in Claudpress → Automation → Chat.'];
        }

        $file = Claudpress_Telegram_Api::fetch_file($media['file_id']);
        if (empty($file['success'])) {
            return ['success' => false, 'error' => 'I could not download that image: ' . (string) $file['error']];
        }

        $filed = self::file_photo((string) $file['bytes'], $media['mime']);
        $text  = $caption !== '' ? $caption : 'Look at this image and tell me what you see, and what you would do about it on this site.';

        if ($filed !== null) {
            $text .= "\n\n[The image is attached to this message so you can see it. It is also now in the media library as attachment ID "
                . $filed['id'] . ' (' . $filed['url'] . "), so you can use it in a post or product without re-uploading.]";
        } else {
            $text .= "\n\n[The image is attached to this message so you can see it. It was not saved to the media library.]";
        }

        return [
            'success' => true,
            'text'    => $text,
            'image'   => ['mime' => $media['mime'], 'b64' => base64_encode((string) $file['bytes'])],
        ];
    }

    /**
     * The Approve / Leave it buttons.
     *
     * Approving re-runs the original instruction with the guardrail lowered for
     * that one run. It is a fresh run rather than a resumption because the
     * earlier run already ended — and re-running means the model sees current
     * state instead of acting on what was true a minute ago.
     */
    private static function handle_callback(array $cb): array
    {
        $chat_id = (string) ($cb['message']['chat']['id'] ?? '');
        $data    = (string) ($cb['data'] ?? '');
        $cb_id   = (string) ($cb['id'] ?? '');
        $msg_id  = (int) ($cb['message']['message_id'] ?? 0);

        $answer = static function (string $text) use ($cb_id): void {
            if ($cb_id !== '') {
                Claudpress_Telegram_Api::api('answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => $text]);
            }
        };

        if ($chat_id === '' || Claudpress_Telegram::role_for($chat_id) === null) {
            $answer('Not authorised.');
            return ['ok' => true, 'reason' => 'callback not allowed'];
        }
        if (!preg_match('/^cp:(ok|no):([a-f0-9]{1,32})$/', $data, $m)) {
            $answer('I do not recognise that button.');
            return ['ok' => true, 'reason' => 'callback unknown'];
        }

        [, $verdict, $token] = $m;
        $row = Claudpress_Telegram::claim_approval($token, $chat_id);

        if ($row === null) {
            $answer('That request has expired.');
            Claudpress_Telegram_Api::edit($chat_id, $msg_id, '⌛ That approval expired. Send the instruction again if you still want it.');
            return ['ok' => true, 'reason' => 'callback expired'];
        }

        if ($verdict === 'no') {
            $answer('Left alone.');
            Claudpress_Telegram_Api::edit($chat_id, $msg_id, '✋ Left alone. Nothing was changed.');
            return ['ok' => true, 'reason' => 'declined'];
        }

        if (!Claudpress_Telegram::may_escalate($chat_id)) {
            $answer('Not permitted.');
            Claudpress_Telegram_Api::edit($chat_id, $msg_id, '🔒 Approvals are locked for this chat, so I did not run it.');
            return ['ok' => true, 'reason' => 'escalation locked'];
        }

        $answer('Running it now.');
        Claudpress_Telegram_Api::edit($chat_id, $msg_id, '✅ Approved — running it now.');

        return self::run_chat($chat_id, (string) $row['instruction'], null, true);
    }

    /**
     * Hand a message to the agent and reply with what it did.
     *
     * @param array|null $image    vision input, when the owner sent a photo
     * @param bool       $approved true when this run came from an Approve press
     */
    private static function run_chat(string $chat_id, string $text, ?array $image = null, bool $approved = false): array
    {
        $lock = 'claudpress_tg_busy_' . md5($chat_id);
        if (get_transient($lock)) {
            Claudpress_Telegram_Api::send($chat_id, '⏳ Still working on your last message. Send this again once I reply.');
            return ['ok' => true, 'reason' => 'busy'];
        }
        set_transient($lock, 1, Claudpress_Telegram::BUSY_TTL);

        $role = (string) (Claudpress_Telegram::role_for($chat_id) ?? 'viewer');
        Claudpress_Telegram_Api::typing($chat_id);
        @set_time_limit(0);

        // One message that becomes the running commentary and then the answer.
        $progress_id = Claudpress_Telegram::progress_enabled() ? Claudpress_Telegram_Api::send($chat_id, '⚙️ Working…') : 0;
        $steps       = [];

        $opts = [
            'history'     => Claudpress_Telegram::history($chat_id, Claudpress_Telegram::history_turns()),
            'interactive' => true,
            'channel'     => 'Telegram',
            'context'     => 'telegram',
            'allow_risky' => $approved || Claudpress_Telegram::risky_allowed($chat_id),
        ];
        if ($image !== null) {
            $opts['image'] = $image;
        }
        if ($role === 'viewer') {
            $opts['allow_tools'] = Claudpress_Capabilities::viewer_tools(Claudpress::instance()->registry->list());
        }
        if ($progress_id > 0) {
            $opts['on_step'] = static function (array $step) use ($chat_id, $progress_id, &$steps): void {
                $steps[] = $step;
                Claudpress_Telegram_Api::progress($chat_id, $progress_id, Claudpress_Telegram::format_progress($steps));
            };
        }

        // Record content changes so /undo has something to put back. Viewers
        // cannot change anything, so there is nothing to record for them.
        if ($role !== 'viewer') {
            Claudpress_Undo::start($chat_id);
        }

        try {
            $run = Claudpress_Agent_Runtime::run($text, $opts);
        } catch (Throwable $e) {
            Claudpress_Undo::finish();
            delete_transient($lock);
            self::deliver($chat_id, $progress_id, '⚠️ Something broke while running that: ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'exception'];
        }

        $undoable = Claudpress_Undo::finish();
        delete_transient($lock);

        $reply = Claudpress_Telegram::format_reply($run);
        if ($undoable > 0) {
            $reply .= "\n" . '↩︎ /undo puts back ' . $undoable . ' ' . ($undoable === 1 ? 'item' : 'items') . '.';
        }

        // Only a successful exchange becomes history. Storing a failed turn
        // would feed the model an error transcript it then tries to explain.
        if (!empty($run['success'])) {
            Claudpress_Telegram::remember($chat_id, 'user', $text);
            Claudpress_Telegram::remember($chat_id, 'assistant', (string) ($run['summary'] ?? ''));
        }

        // If policy stopped something, offer the owner the one-tap escalation
        // rather than making them learn the /risky command.
        $blocked = Claudpress_Telegram::blocked_tools($run);
        $markup  = [];
        if (!empty($blocked) && !$approved && Claudpress_Telegram::may_escalate($chat_id)) {
            $token  = Claudpress_Telegram::stash_approval($chat_id, $text, $blocked);
            $reply .= "\n\n🔒 Blocked by your guardrails: " . implode(', ', $blocked) . '.';
            $markup = ['inline_keyboard' => [[
                ['text' => '✅ Approve & run', 'callback_data' => 'cp:ok:' . $token],
                ['text' => '✋ Leave it',      'callback_data' => 'cp:no:' . $token],
            ]]];
        } elseif (!empty($blocked)) {
            $reply .= "\n\n🔒 Blocked by your guardrails: " . implode(', ', $blocked)
                . '. Unlock approvals in WP Admin → Claudpress → Automation → Chat.';
        }

        self::deliver($chat_id, $progress_id, $reply, $markup);
        return ['ok' => true, 'reason' => 'ran', 'success' => !empty($run['success'])];
    }

    /**
     * Put the final answer on screen. The progress message becomes the reply
     * where it fits, so the chat isn't littered with "Working…" leftovers.
     */
    private static function deliver(string $chat_id, int $progress_id, string $text, array $markup = []): void
    {
        $chunks = Claudpress_Telegram::split_message($text);
        if (empty($chunks)) {
            return;
        }

        if ($progress_id > 0 && Claudpress_Telegram_Api::edit($chat_id, $progress_id, $chunks[0], count($chunks) === 1 ? $markup : [])) {
            $rest = array_slice($chunks, 1);
            if (!empty($rest)) {
                Claudpress_Telegram_Api::send($chat_id, implode("\n\n", $rest), $markup);
            }
            return;
        }

        Claudpress_Telegram_Api::send($chat_id, $text, $markup);
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
        $role = (string) (Claudpress_Telegram::role_for($chat_id) ?? 'viewer');

        switch ($cmd) {
            case 'start':
            case 'help':
                $lines = [
                    '👋 I run ' . get_bloginfo('name') . ' (' . home_url() . ').',
                    '',
                    'Just tell me what you want in plain English — "how did the store do this week?", "publish the draft about winter tyres", "the checkout page footer is broken, fix it". I use the site\'s own tools and report back.',
                ];
                if ($role === 'viewer') {
                    $lines[] = '';
                    $lines[] = '👀 This chat is read-only: I can look things up and answer questions, but I cannot change anything.';
                }
                if (Claudpress_Telegram::voice_enabled()) {
                    $lines[] = '';
                    $lines[] = '🎙 Send a voice note and I will transcribe it, show you what I heard, then act on it.';
                }
                if (Claudpress_Telegram::photos_enabled()) {
                    $lines[] = '📷 Send a photo or screenshot (with a caption if you like) and I will look at it and file it in the media library.';
                }
                $lines[] = '';
                $lines[] = 'Commands:';
                foreach (Claudpress_Telegram_Api::commands() as $c => $desc) {
                    $lines[] = '/' . $c . ' — ' . $desc;
                }
                $lines[] = '';
                $lines[] = 'I remember the last ' . Claudpress_Telegram::history_turns() . ' turns of this chat. /clear wipes them.';
                return implode("\n", $lines);

            case 'clear':
            case 'reset':
            case 'new':
                $n = Claudpress_Telegram::clear($chat_id);
                return $n > 0
                    ? '🧹 Cleared ' . $n . ' ' . ($n === 1 ? 'turn' : 'turns') . '. Fresh start — I no longer remember what we were doing.'
                    : '🧹 Nothing to clear; this conversation was already empty.';

            case 'undo':
                if ($role === 'viewer') {
                    return '👀 This chat is read-only, so there is nothing of mine to undo.';
                }
                $res = Claudpress_Undo::revert($chat_id);
                if (empty($res['success']) && empty($res['restored'])) {
                    return '↩︎ ' . (string) ($res['error'] ?? 'Nothing to undo.');
                }
                $out = ['↩︎ Undone.'];
                foreach ((array) $res['restored'] as $line) {
                    $out[] = '• ' . $line;
                }
                foreach ((array) $res['failed'] as $line) {
                    $out[] = '⚠️ could not undo ' . $line;
                }
                $out[] = '';
                $out[] = 'Note: /undo covers content — posts, pages, products. It does not cover settings, plugins, files or live code snippets.';
                return implode("\n", $out);

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
                    'Voice: ' . (Claudpress_Telegram::voice_enabled() ? 'on' : 'off') . ' · Photos: ' . (Claudpress_Telegram::photos_enabled() ? 'on' : 'off'),
                ]);

            case 'risky':
                $want = strtolower(trim($args));
                if ($role !== 'admin') {
                    return '🔒 Only an admin chat can unlock destructive tools. This chat is "' . $role . '".';
                }
                if (!Claudpress::instance()->get('telegram_allow_risky', false)) {
                    return '🔒 Destructive and financial tools are locked for Telegram. The owner can unlock them in WP Admin → Claudpress → Automation → Chat.';
                }
                if ($want === 'on') {
                    $mins = Claudpress_Telegram::set_risky($chat_id, true);
                    return '⚠️ Destructive/financial tools unlocked for ' . $mins . ' minutes. They re-lock on their own.';
                }
                if ($want === 'off') {
                    Claudpress_Telegram::set_risky($chat_id, false);
                    return '🔒 Re-locked. Destructive and financial tools are blocked again.';
                }
                return Claudpress_Telegram::risky_allowed($chat_id)
                    ? '⚠️ Currently unlocked. Send /risky off to re-lock.'
                    : '🔒 Currently locked. Send /risky on to unlock for ' . (int) (Claudpress_Telegram::RISKY_TTL / 60) . ' minutes.';

            case 'whoami':
            case 'id':
                $allowed = Claudpress_Telegram::role_for($chat_id) !== null;
                $what    = [
                    'admin'  => 'full access, may approve and unlock risky tools',
                    'editor' => 'may change the site, never destructive or financial tools',
                    'viewer' => 'read-only — can look, cannot touch',
                ][$role] ?? $role;
                return 'Chat id: ' . $chat_id . "\n"
                    . ($allowed ? '✅ allowlisted' : '⛔ not allowlisted') . "\n"
                    . 'Role: ' . $role . ' — ' . $what;

            case 'history':
                $n = Claudpress_Telegram::turn_count($chat_id);
                return '🧠 ' . $n . ' ' . ($n === 1 ? 'turn' : 'turns') . ' stored; I replay the last ' . Claudpress_Telegram::history_turns()
                    . '. /clear wipes them. Undo depth: ' . Claudpress_Undo::depth($chat_id) . '.';
        }

        return null;
    }

    /**
     * File an incoming photo in the media library so the agent can actually use
     * it (put it in a post, set it as a product image) rather than only look at
     * it. Failure here is not fatal — vision still works without the upload.
     *
     * @return array{id: int, url: string}|null
     */
    private static function file_photo(string $bytes, string $mime): ?array
    {
        if (!Claudpress_Capabilities::tool_allowed('upload_media')) {
            return null;
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? 'jpg';

        $res = Claudpress_Tools_Content::upload_media([
            'data'     => base64_encode($bytes),
            'filename' => 'telegram-' . gmdate('Ymd-His') . '.' . $ext,
            'title'    => 'Sent via Telegram ' . current_time('Y-m-d H:i'),
        ]);
        return empty($res['success']) ? null : ['id' => (int) $res['id'], 'url' => (string) $res['url']];
    }
}
