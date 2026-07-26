<?php
/**
 * Multi-channel notifier: email (wp_mail), a generic/Slack webhook, and
 * Telegram. Used by scheduled digests and autonomous-run summaries so the owner
 * hears about the site without logging in.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Notifier
{
    /**
     * Send a notification across every configured channel.
     * @return array<string,mixed> per-channel result
     */
    public static function send(string $subject, string $text, ?string $html = null): array
    {
        $s = Claudpress::instance()->settings();
        $results = [];

        // Email
        $to = trim((string) ($s['notify_email'] ?? ''));
        if ($to === '') {
            $to = get_option('admin_email');
        }
        if ($to) {
            $headers = $html ? ['Content-Type: text/html; charset=UTF-8'] : [];
            $results['email'] = (bool) wp_mail($to, $subject, $html ?: $text, $headers);
        }

        // Generic / Slack webhook (POST JSON {text})
        $webhook = trim((string) ($s['notify_webhook_url'] ?? ''));
        if ($webhook) {
            $resp = wp_remote_post($webhook, [
                'timeout' => 15,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode(['text' => $subject . "\n\n" . $text]),
            ]);
            $results['webhook'] = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp);
        }

        // Telegram
        $tg_token = trim((string) ($s['notify_telegram_token'] ?? ''));
        $tg_chat  = trim((string) ($s['notify_telegram_chat'] ?? ''));
        if ($tg_token && $tg_chat) {
            $url = 'https://api.telegram.org/bot' . $tg_token . '/sendMessage';
            $resp = wp_remote_post($url, [
                'timeout' => 15,
                'body'    => ['chat_id' => $tg_chat, 'text' => "*" . $subject . "*\n" . $text, 'parse_mode' => 'Markdown'],
            ]);
            $results['telegram'] = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_response_code($resp);
        }

        return $results;
    }
}
