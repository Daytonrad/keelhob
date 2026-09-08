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
 *
 * A third idea joined them, because CONNECTED was quietly answering a question
 * it cannot answer:
 *
 *   HEALTH    - did the last real send actually arrive? Recorded from sends
 *               that were going to happen anyway, never probed.
 *
 * CONNECTED only ever meant "there is an address or a token here, so an attempt
 * is possible". On a host with no sendmail binary that is true and useless:
 * wp_mail() exists, wp_mail() always fails, and the screen said "Email" with a
 * tick while every alert vanished. Silence on the channel that carries "a
 * snippet just took your site down" is the worst failure this plugin has.
 *
 * So health is a separate axis with four states - unconfigured, untested,
 * working, failing - and CONNECTED deliberately stays true for a failing
 * channel. That is not an oversight. Only a successful send can clear a
 * failure, so a channel that stopped being attempted could never recover: it
 * keeps being tried, and the screen tells the truth about what happened last
 * time instead of guessing about next time.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Notifier
{
    const CHANNELS = ['email', 'telegram', 'webhook'];

    /**
     * Where the outcome of the last real send per channel is remembered.
     *
     * An option rather than a transient: a transient can be dropped by an
     * object cache at any moment, and "we forgot that email is broken" restores
     * exactly the false confidence this exists to remove. It is three small
     * rows, it is not autoloaded, and it should outlive a cache flush.
     */
    const OPTION_HEALTH = 'claudpress_channel_health';

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

        $out = [
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

        // Overlay what actually happened last time. This never touches
        // 'connected' - see the class docblock for why a failing channel must
        // still be attempted - it only adds the state nobody could see before.
        $health = self::health();

        foreach ($out as $channel => $row) {
            $out[$channel]['last_ok'] = null;
            $out[$channel]['last_at'] = 0;
            $out[$channel]['warning'] = '';

            if (empty($row['connected'])) {
                $out[$channel]['state'] = 'unconfigured';
                continue;
            }

            $last = isset($health[$channel]) && is_array($health[$channel]) ? $health[$channel] : null;
            if ($last === null) {
                // Nothing is wrong. It has simply never been used, and saying
                // so is more honest than a tick that implies it was checked.
                $out[$channel]['state'] = 'untested';
                continue;
            }

            $ok                       = !empty($last['ok']);
            $out[$channel]['state']   = $ok ? 'working' : 'failing';
            $out[$channel]['last_ok'] = $ok;
            $out[$channel]['last_at'] = (int) ($last['at'] ?? 0);

            if (!$ok) {
                $out[$channel]['warning'] = sprintf(
                    /* translators: 1: time ago, e.g. "5 mins"; 2: the delivery error */
                    __('The last message could not be delivered (%1$s ago): %2$s', 'claudpress'),
                    human_time_diff((int) ($last['at'] ?? time())),
                    (string) ($last['error'] ?? __('no reason was given', 'claudpress'))
                );
            }
        }

        return $out;
    }

    /**
     * Configured channels whose last send worked, plus those never yet tried.
     *
     * What the owner can reasonably rely on. Distinct from
     * effective_channels(), which answers "what would a send attempt right
     * now" - a failing channel is still attempted, and still counted there.
     *
     * @return string[]
     */
    public static function healthy_channels(): array
    {
        $available = self::availability();

        return array_values(array_filter(
            self::CHANNELS,
            static function ($channel) use ($available) {
                return in_array($available[$channel]['state'], ['working', 'untested'], true);
            }
        ));
    }

    /**
     * The stored per-channel outcome of the last real send.
     *
     * @return array<string, array{ok:bool, at:int, error:string}>
     */
    public static function health(): array
    {
        $stored = get_option(self::OPTION_HEALTH, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * Remember how a send went.
     *
     * Only ever called with the outcome of a message somebody actually wanted.
     * There is no probe and no silent test email: a channel's health is the
     * history of its real traffic, which is the only evidence that cannot be
     * wrong about itself.
     */
    public static function record_outcome(string $channel, bool $ok, string $error = ''): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            return;
        }

        $health           = self::health();
        $health[$channel] = [
            'ok' => $ok,
            'at' => time(),
            // A success carries no error. Keeping a stale one is how a channel
            // that was fixed goes on looking broken.
            'error' => $ok ? '' : self::trim_error($error),
        ];

        update_option(self::OPTION_HEALTH, $health, false);
    }

    /** Forget every recorded outcome. For tests, and for a clean slate. */
    public static function reset_health(): void
    {
        delete_option(self::OPTION_HEALTH);
    }

    /**
     * Keep a recorded error readable and bounded.
     *
     * A webhook that answers a POST with a 200KB HTML error page must not put
     * 200KB into an option that the settings screen reads on every render.
     */
    private static function trim_error(string $error): string
    {
        $flat = preg_replace('/\s+/', ' ', wp_strip_all_tags($error));
        $flat = trim((string) $flat);

        if ($flat === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($flat, 0, 200) : substr($flat, 0, 200);
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
     * Every channel attempted here records its outcome, so the settings screen
     * can say what happened rather than what is configured. Note the filter is
     * still on 'connected' and not on health: a failing channel is tried again
     * every time, because a success is the only thing that can clear it.
     *
     * @param string[]|null $channels Explicit channels, or null for the owner's
     *                                stored preference. Unconnected channels are
     *                                dropped either way.
     * @param string|null   $email_to Send this one message to this address
     *                                instead of the stored notification
     *                                address. Not persisted: it exists so the
     *                                "send a test" button can prove delivery to
     *                                somewhere the owner is actually watching.
     * @return array<string,mixed> per-channel result; empty when nothing was sent
     */
    public static function send(
        string $subject,
        string $text,
        ?string $html = null,
        ?array $channels = null,
        ?string $email_to = null
    ): array {
        $s       = Claudpress::instance()->settings();
        $results = [];

        $wanted    = $channels === null ? self::chosen_channels() : $channels;
        $available = self::availability();
        $send_to   = array_filter($wanted, static function ($c) use ($available) {
            return !empty($available[$c]['connected']);
        });

        if (in_array('email', $send_to, true)) {
            $to = $email_to === null ? '' : trim($email_to);
            if ($to === '') {
                $to = trim((string) ($s['notify_email'] ?? ''));
            }
            if ($to === '') {
                $to = (string) get_option('admin_email');
            }
            $headers = $html ? ['Content-Type: text/html; charset=UTF-8'] : [];

            // wp_mail() returns a bare false and puts the reason in an action.
            // Without this the recorded error is always "it did not work",
            // which is the least useful half of what the host just told us -
            // "Could not instantiate mail function" is the whole diagnosis.
            $mail_error = '';
            $capture    = static function ($wp_error) use (&$mail_error) {
                if (is_wp_error($wp_error)) {
                    $mail_error = (string) $wp_error->get_error_message();
                }
            };
            add_action('wp_mail_failed', $capture);
            $sent = (bool) wp_mail($to, $subject, $html ?: $text, $headers);
            remove_action('wp_mail_failed', $capture);

            $results['email'] = $sent;
            self::record_outcome(
                'email',
                $sent,
                $mail_error !== '' ? $mail_error : __('wp_mail() returned false and gave no reason.', 'claudpress')
            );
        }

        if (in_array('webhook', $send_to, true)) {
            $resp = wp_remote_post(trim((string) $s['notify_webhook_url']), [
                'timeout' => 15,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode(['text' => $subject . "\n\n" . $text]),
            ]);
            $results['webhook'] = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp);
            self::record_http_outcome('webhook', $resp);
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
            self::record_http_outcome('telegram', $resp);
        }

        return $results;
    }

    /**
     * Grade an HTTP send and remember it.
     *
     * Anything outside 2xx counts as a failure, including the 3xx a
     * misconfigured Slack URL returns - a redirect is not a delivery, and
     * treating it as one is how a dead webhook keeps looking healthy.
     *
     * @param array|WP_Error $resp
     */
    private static function record_http_outcome(string $channel, $resp): void
    {
        if (is_wp_error($resp)) {
            self::record_outcome($channel, false, (string) $resp->get_error_message());
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code >= 200 && $code < 300) {
            self::record_outcome($channel, true);
            return;
        }

        // Telegram puts the real diagnosis in the body ("chat not found",
        // "bot was blocked by the user"), and that is the sentence the owner
        // needs. Show the code alone only when there is nothing better.
        $detail = '';
        $body   = (string) wp_remote_retrieve_body($resp);
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && !empty($decoded['description'])) {
                $detail = (string) $decoded['description'];
            } elseif (is_array($decoded) && !empty($decoded['error'])) {
                $detail = is_string($decoded['error']) ? $decoded['error'] : '';
            } else {
                $detail = $body;
            }
        }

        self::record_outcome(
            $channel,
            false,
            $detail === ''
                ? sprintf(/* translators: %d: HTTP status code */ __('HTTP %d', 'claudpress'), $code)
                : sprintf(/* translators: 1: HTTP status code, 2: server message */ __('HTTP %1$d - %2$s', 'claudpress'), $code, $detail)
        );
    }
}
