<?php
/**
 * The Telegram Bot API client, and the media that comes down it.
 *
 * Everything in this file is one side of an HTTP call to api.telegram.org:
 * sending and editing messages, the typing indicator, registering the webhook
 * and the command list, and pulling a voice note or a photo back down so the
 * agent can hear or see it.
 *
 * It knows nothing about who is allowed to talk to the bot or what any message
 * means. Those are Keelhob_Telegram's job and Keelhob_Telegram_Router's
 * respectively, and keeping the wire protocol out of both of them is what makes
 * this file readable on its own.
 *
 * The constants and settings it needs — the API base, the message ceiling, the
 * bot token — stay on Keelhob_Telegram, which is where the rest of the
 * plugin already reads them from.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Telegram_Api
{
    /** @var array<string, int> last progress-edit timestamp, keyed by message */
    private static $last_progress = [];

    // ---- Telegram API -----------------------------------------------------

    /** @return array decoded response, or ['ok' => false, 'description' => …] */
    public static function api(string $method, array $params = []): array
    {
        $token = Keelhob_Telegram::token();
        if ($token === '') {
            return ['ok' => false, 'description' => 'No Telegram bot token configured.'];
        }
        $resp = wp_remote_post(Keelhob_Telegram::API_BASE . $token . '/' . $method, [
            'timeout' => 20,
            'body'    => $params,
        ]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'description' => $resp->get_error_message()];
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        return is_array($data) ? $data : ['ok' => false, 'description' => 'Malformed Telegram response.'];
    }

    /**
     * Send a reply, split across messages when it exceeds Telegram's limit.
     *
     * @param array $markup optional reply_markup (inline keyboard)
     * @return int the message_id of the first message sent, 0 on failure
     */
    public static function send(string $chat_id, string $text, array $markup = []): int
    {
        $first  = 0;
        $chunks = Keelhob_Telegram::split_message($text);
        $last   = count($chunks) - 1;

        foreach ($chunks as $i => $chunk) {
            $params = [
                'chat_id'                  => $chat_id,
                'text'                     => $chunk,
                'disable_web_page_preview' => 'true',
            ];
            // A keyboard belongs on the last message, where the reader ends up.
            if ($markup && $i === $last) {
                $params['reply_markup'] = wp_json_encode($markup);
            }
            $res = self::api('sendMessage', $params);
            if ($first === 0) {
                $first = (int) ($res['result']['message_id'] ?? 0);
            }
        }
        return $first;
    }

    /** Rewrite a message already on screen. */
    public static function edit(string $chat_id, int $message_id, string $text, array $markup = []): bool
    {
        if ($message_id <= 0) {
            return false;
        }
        $params = [
            'chat_id'                  => $chat_id,
            'message_id'               => $message_id,
            'text'                     => mb_substr($text, 0, Keelhob_Telegram::MAX_MESSAGE),
            'disable_web_page_preview' => 'true',
        ];
        if ($markup) {
            $params['reply_markup'] = wp_json_encode($markup);
        }
        $res = self::api('editMessageText', $params);
        return !empty($res['ok']);
    }

    /**
     * Edit the progress message, but not more often than the throttle allows.
     * Telegram rate-limits edits, and a run that calls six tools in four seconds
     * would otherwise spend its time being told to slow down.
     */
    public static function progress(string $chat_id, int $message_id, string $text): void
    {
        if ($message_id <= 0) {
            return;
        }
        $key  = $chat_id . ':' . $message_id;
        $last = self::$last_progress[$key] ?? 0;
        if (time() - $last < Keelhob_Telegram::PROGRESS_THROTTLE) {
            return;
        }
        self::$last_progress[$key] = time();
        self::edit($chat_id, $message_id, $text);
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
            'url'                  => Keelhob_Telegram::webhook_url(),
            'secret_token'         => Keelhob_Telegram::secret(),
            'allowed_updates'      => wp_json_encode(['message', 'callback_query']),
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
            'undo'   => 'Put back the content I changed last turn',
            'status' => 'Site & store snapshot (free, no AI spend)',
            'usage'  => 'Runs, tokens and estimated spend in the last 24h',
            'tasks'  => 'Scheduled automations and when they last ran',
            'model'  => 'Which provider and model I am running on',
            'risky'  => 'on|off — allow destructive/financial tools for a while',
            'whoami' => 'Your chat id, role and whether you are allowlisted',
        ];
    }

    // ---- media ------------------------------------------------------------

    /**
     * Download a file the user sent. Telegram gives out a path first, then the
     * bytes from a different host.
     *
     * @return array{success: bool, bytes?: string, name?: string, error?: string}
     */
    public static function fetch_file(string $file_id): array
    {
        $info = self::api('getFile', ['file_id' => $file_id]);
        $path = (string) ($info['result']['file_path'] ?? '');
        if ($path === '') {
            return ['success' => false, 'error' => (string) ($info['description'] ?? 'Telegram would not hand over that file.')];
        }
        if ((int) ($info['result']['file_size'] ?? 0) > Keelhob_Telegram::MAX_FILE_BYTES) {
            return ['success' => false, 'error' => 'That file is too large for me to fetch.'];
        }

        $resp = wp_remote_get(Keelhob_Telegram::FILE_BASE . Keelhob_Telegram::token() . '/' . $path, ['timeout' => 60]);
        if (is_wp_error($resp)) {
            return ['success' => false, 'error' => $resp->get_error_message()];
        }
        if ((int) wp_remote_retrieve_response_code($resp) !== 200) {
            return ['success' => false, 'error' => 'Download failed (HTTP ' . wp_remote_retrieve_response_code($resp) . ').'];
        }

        $bytes = (string) wp_remote_retrieve_body($resp);
        if ($bytes === '') {
            return ['success' => false, 'error' => 'The download came back empty.'];
        }
        return ['success' => true, 'bytes' => $bytes, 'name' => basename($path)];
    }

    /**
     * Turn a voice note into text.
     *
     * @return array{success: bool, text?: string, error?: string}
     */
    public static function transcribe(string $file_id, string $mime): array
    {
        $key = Keelhob_Transcribe_Provider::key();
        if ($key === '') {
            return ['success' => false, 'error' => 'Voice needs a transcription API key (Keelhob → Automation → Chat).'];
        }

        $file = self::fetch_file($file_id);
        if (empty($file['success'])) {
            return ['success' => false, 'error' => (string) $file['error']];
        }

        $s = Keelhob::instance()->settings();
        /** @var class-string<Keelhob_Transcribe_Provider> $p */
        $p = Keelhob_Transcribe_Provider::current();

        return $p::transcribe(
            $key,
            (string) ($s['voice_model'] ?? ''),
            (string) ($s['voice_api_endpoint'] ?? ''),
            (string) $file['bytes'],
            $mime ?: 'audio/ogg',
            (string) ($file['name'] ?? 'voice.ogg')
        );
    }

    /**
     * Which piece of an incoming message is the media we care about, and how to
     * treat it.
     *
     * Telegram sends a photo as an array of rescaled copies, smallest first; the
     * last one is the original, which is the only one worth showing a model.
     *
     * @return array{kind: string, file_id: string, mime: string}|null
     */
    public static function extract_media(array $message): ?array
    {
        if (!empty($message['voice']['file_id'])) {
            return [
                'kind'    => 'voice',
                'file_id' => (string) $message['voice']['file_id'],
                'mime'    => (string) ($message['voice']['mime_type'] ?? 'audio/ogg'),
            ];
        }
        if (!empty($message['audio']['file_id'])) {
            return [
                'kind'    => 'voice',
                'file_id' => (string) $message['audio']['file_id'],
                'mime'    => (string) ($message['audio']['mime_type'] ?? 'audio/mpeg'),
            ];
        }
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $largest = end($message['photo']);
            if (!empty($largest['file_id'])) {
                return ['kind' => 'photo', 'file_id' => (string) $largest['file_id'], 'mime' => 'image/jpeg'];
            }
        }
        // A screenshot sent as a file rather than a photo, so it keeps full size.
        $doc_mime = (string) ($message['document']['mime_type'] ?? '');
        if (!empty($message['document']['file_id']) && strpos($doc_mime, 'image/') === 0) {
            return ['kind' => 'photo', 'file_id' => (string) $message['document']['file_id'], 'mime' => $doc_mime];
        }
        return null;
    }
}
