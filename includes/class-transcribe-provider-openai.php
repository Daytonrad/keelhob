<?php
/**
 * The OpenAI transcription adapter — Whisper and gpt-4o-transcribe.
 *
 * A plain multipart upload to audio/transcriptions, so any gateway that mirrors
 * that endpoint works here unchanged.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** OpenAI's audio/transcriptions endpoint, and any gateway that mirrors it. */
final class Claudpress_Transcribe_Provider_OpenAI extends Claudpress_Transcribe_Provider
{
    public static function id(): string { return 'openai'; }
    public static function label(): string { return 'OpenAI (Whisper / gpt-4o-transcribe)'; }
    public static function default_endpoint(): string { return 'https://api.openai.com/v1/audio/transcriptions'; }
    public static function default_model(): string { return 'gpt-4o-mini-transcribe'; }
    public static function key_url(): string { return 'https://platform.openai.com/api-keys'; }
    public static function models_url(): string { return 'https://platform.openai.com/docs/guides/speech-to-text'; }
    public static function key_hint(): string { return 'sk-...'; }

    public static function models(): array
    {
        return [
            ['id' => 'gpt-4o-mini-transcribe', 'note' => 'Recommended. Fast and cheap, and accurate enough for voice notes.'],
            ['id' => 'gpt-4o-transcribe',      'note' => 'More accurate on accents, noise and technical words. Costs more.'],
            ['id' => 'whisper-1',              'note' => 'The older Whisper model. Still solid, and the widest language coverage.'],
        ];
    }

    public static function auth_headers(string $key): array
    {
        return ['Authorization' => 'Bearer ' . $key];
    }

    public static function verify_url(string $endpoint, string $model): string
    {
        $base = $endpoint !== '' ? $endpoint : self::default_endpoint();
        $base = (string) preg_replace('#/audio/transcriptions/?$#', '', $base);
        return rtrim($base, '/') . '/models/' . rawurlencode($model);
    }

    public static function models_list_url(string $endpoint): string
    {
        $base = $endpoint !== '' ? $endpoint : self::default_endpoint();
        $base = (string) preg_replace('#/audio/transcriptions/?$#', '', $base);
        return rtrim($base, '/') . '/models';
    }

    public static function parse_models(array $data): array
    {
        // A model that merely ACCEPTS audio is not a transcription model:
        // gpt-4o-audio answers in speech and would return no transcript at all.
        // Only these two markers name models whose job is writing down words.
        $markers = ['transcribe', 'whisper'];

        $rows = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : [];
        usort($rows, static function ($a, $b) {
            return ((int) ($b['created'] ?? 0)) <=> ((int) ($a['created'] ?? 0));
        });

        $out = [];
        foreach ($rows as $m) {
            $id = (string) ($m['id'] ?? '');
            foreach ($markers as $marker) {
                if ($id !== '' && strpos($id, $marker) !== false) {
                    $out[] = $id;
                    break;
                }
            }
        }
        return array_values(array_unique($out));
    }

    public static function transcribe(string $key, string $model, string $endpoint, string $bytes, string $mime, string $filename): array
    {
        $part = self::multipart(['model' => $model ?: self::default_model()], 'file', $filename, $mime, $bytes);

        $resp = wp_remote_post($endpoint ?: self::default_endpoint(), [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => $part['content_type'],
            ],
            'body'    => $part['body'],
        ]);

        if (is_wp_error($resp)) {
            return ['success' => false, 'error' => $resp->get_error_message()];
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $text = is_array($data) ? trim((string) ($data['text'] ?? '')) : '';
        if ($text === '') {
            $err = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            return ['success' => false, 'error' => $err ?: 'HTTP ' . wp_remote_retrieve_response_code($resp) . ' with no transcript.'];
        }
        return ['success' => true, 'text' => $text];
    }
}
