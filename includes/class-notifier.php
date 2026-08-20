<?php
/**
 * Multi-channel notifier: email (wp_mail), a generic/Slack webhook, and
 * Telegram. Used by scheduled digests and autonomous-run summaries so the owner
 * hears about the site without logging in.
 *
 * Two ideas are kept deliberately separate here, because collapsing them is the
 * bug this class used to have:
 *
 *   CONNECTED - is this channel usable at all? Does it have a token, a chat id,
 *               an address? Derived from settings, never stored.
 *   CHOSEN    - does the owner want this notification sent there? A stored
 *               preference, and one that must survive a channel going away.
 *
 * send() used to fan out to every connected channel with no way to say
 * "Telegram only". Worse, if preferences were ever derived from the connection
 * state, disconnecting Telegram would silently erase the owner's choice and
 * reconnecting would not bring it back. So the preference is stored on its own
 * and intersected with connectivity at send time.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Notifier
{
    const CHANNELS = ['email', 'telegram', 'webhook'];

    /** Human labels, for the settings screen and for error messages. */
    public static function labels(): array
    {
        return [
            'email'    => __('Email', 'claudpress'),
            'telegram' => __('Telegram', 'claudpress'),
            'webhook'  => __('Webhook / Slack', 'claudpress'),
        ];
    }

    /**
     * Which channels could deliver right now, and why not when they cannot.
     *
     * The reason string is half the point: a checkbox greyed out with no
     * explanation is the worst version of this feature. Each one names the thing
     * that is missing so the screen can link straight to it.
     *
     * @return array<string, array{connected:bool, reason:string}>
     */
    public static function availability(): array
    {
        $s = Claudpress::instance()->settings();

        $email = trim((string) ($s['notify_email'] ?? ''));
        if ($email === '') {
            $email = (string) get_option('admin_email');
        }

        $tg_token = trim((string) ($s['notify_telegram_token'] ?? ''));
        $tg_chat  = trim((string) ($s['notify_telegram_chat'] ?? ''));

        return [
            'email' => [
                'connected' => $email !== '',
                'reason'    => $email !== '' ? '' : __('Add a notification email address first.', 'claudpress'),
            ],
            'telegram' => [
                'connected' => $tg_token !== '' && $tg_chat !== '',
                'reason'    => ($tg_token !== '' && $tg_chat !== '')
                    ? ''
                    : ($tg_token === ''
                        ? __('Connect the Telegram bot first.', 'claudpress')
                        : __('Telegram is connected but has no chat ID yet. Message the bot once.', 'claudpress')),
            ],
            'webhook' => [
                'connected' => trim((string) ($s['notify_webhook_url'] ?? '')) !== '',
                'reason'    => trim((string) ($s['notify_webhook_url'] ?? '')) !== ''
                    ? ''
                    : __('Add a webhook or Slack URL first.', 'claudpress'),
            ],
        ];
    }

    /** The owner's stored preference, independent of whether it can deliver today. */
    public static function chosen_channels(): array
    {
        $stored = Claudpress::instance()->get('notify_channels', null);

        // Never configured: fall back to everything that is connected, which is
        // exactly how this behaved before the preference existed. Upgrading must
        // not silently stop someone's digests.
        if (!is_array($stored)) {
            return self::CHANNELS;
        }
        return array_values(array_intersect(self::CHANNELS, $stored));
    }

    /** Chosen AND connected - what a send would actually reach. */
    public static function effective_channels(): array
    {
        $available = self::availability();

        return array_values(array_filter(
            self::chosen_channels(),
            static function ($channel) use ($available) {
                return !empty($available[$channel]['connected']);
            }
        ));
    }

    /**
     * Send a notification.
     *
     * @param string[]|null $channels Explicit channels, or null for the owner's
     *                                stored preference. Unconnected channels are
     *                                dropped either way.
     * @return array<string,mixed> per-channel result; empty when nothing was sent
     */
    public static function send(string $subject, string $text, ?string $html = null, ?array $channels = null): array
    {
        $s       = Claudpress::instance()->settings();
        $results = [];

        $wanted    = $channels === null ? self::chosen_channels() : $channels;
        $available = self::availability();
        $send_to   = array_filter($wanted, static function ($c) use ($available) {
            return !empty($available[$c]['connected']);
        });

        if (in_array('email', $send_to, true)) {
            $to = trim((string) ($s['notify_email'] ?? ''));
            if ($to === '') {
                $to = (string) get_option('admin_email');
            }
            $headers          = $html ? ['Content-Type: text/html; charset=UTF-8'] : [];
            $results['email'] = (bool) wp_mail($to, $subject, $html ?: $text, $headers);
        }

        if (in_array('webhook', $send_to, true)) {
            $resp = wp_remote_post(trim((string) $s['notify_webhook_url']), [
                'timeout' => 15,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode(['text' => $subject . "\n\n" . $text]),
            ]);
            $results['webhook'] = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp);
        }

        if (in_array('telegram', $send_to, true)) {
            $url  = 'https://api.telegram.org/bot' . trim((string) $s['notify_telegram_token']) . '/sendMessage';
            $resp = wp_remote_post($url, [
                'timeout' => 15,
                'body'    => [
                    'chat_id'    => trim((string) $s['notify_telegram_chat']),
                    'text'       => "*" . $subject . "*\n" . $text,
                    'parse_mode' => 'Markdown',
                ],
            ]);
            $results['telegram'] = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp);
        }

        return $results;
    }
}
