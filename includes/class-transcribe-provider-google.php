<?php
/**
 * The Google Gemini transcription adapter.
 *
 * Gemini has no transcription endpoint either: the audio goes in as an inline
 * part of a generateContent call, with an instruction to write down what it
 * hears.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gemini transcribes as a side effect of generateContent: the audio goes in as
 * an inline part with an instruction to write down what it hears.
 */
final class Keelhob_Transcribe_Provider_Google extends Keelhob_Transcribe_Provider
{
    public static function id(): string { return 'google'; }
    public static function label(): string { return 'Google (Gemini audio)'; }
    public static function default_endpoint(): string { return 'https://generativelanguage.googleapis.com/v1beta/models'; }
    public static function default_model(): string { return 'gemini-flash-latest'; }
    public static function key_url(): string { return 'https://aistudio.google.com/apikey'; }
    public static function models_url(): string { return 'https://ai.google.dev/gemini-api/docs/audio'; }
    public static function key_hint(): string { return 'AIza...'; }

    public static function models(): array
    {
        return [
            ['id' => 'gemini-flash-latest', 'note' => 'Recommended. An alias that always points at the current Flash model.'],
            ['id' => 'gemini-2.5-flash',    'note' => 'A pinned version, so an upgrade cannot change your results.'],
            ['id' => 'gemini-2.5-pro',      'note' => 'Slower and dearer. Better on poor audio and unusual vocabulary.'],
        ];
    }

    public static function auth_headers(string $key): array
    {
        return ['x-goog-api-key' => $key];
    }

    public static function verify_url(string $endpoint, string $model): string
    {
        $base = $endpoint !== '' ? $endpoint : self::default_endpoint();
        $base = (string) preg_replace('#/[^/]*:generateContent/?$#', '', $base);
        return rtrim($base, '/') . '/' . rawurlencode($model);
    }

    public static function models_list_url(string $endpoint): string
    {
        $base = $endpoint !== '' ? $endpoint : self::default_endpoint();
        return (string) preg_replace('#/[^/]*:generateContent/?$#', '', $base);
    }

    public static function parse_models(array $data): array
    {
        // Gemini transcribes as a side effect of generateContent, so the
        // candidates are the ordinary text models. Everything excluded here
        // still answers generateContent - and returns speech, or a picture, or
        // a vector - which is why the filter cannot simply trust that method.
        $not_text = ['-tts', 'image', 'embedding', 'aqa', 'veo-', 'imagen-'];
        $rows     = (isset($data['models']) && is_array($data['models'])) ? $data['models'] : [];

        $out = [];
        foreach ($rows as $m) {
            $id = (string) preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
            if ($id === '' || strpos($id, 'gemini') !== 0) {
                continue;
            }
            $methods = isset($m['supportedGenerationMethods']) ? (array) $m['supportedGenerationMethods'] : [];
            if ($methods && !in_array('generateContent', $methods, true)) {
                continue;
            }
            foreach ($not_text as $marker) {
                if (strpos($id, $marker) !== false) {
                    continue 2;
                }
            }
            $out[] = $id;
        }
        $out = array_values(array_unique($out));
        rsort($out, SORT_NATURAL);
        return $out;
    }

    public static function transcribe(string $key, string $model, string $endpoint, string $bytes, string $mime, string $filename): array
    {
        $model = $model ?: self::default_model();
        $base  = $endpoint ?: self::default_endpoint();
        $url   = strpos($base, ':generateContent') !== false
            ? $base
            : rtrim($base, '/') . '/' . rawurlencode($model) . ':generateContent';

        $resp = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => ['x-goog-api-key' => $key, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'contents' => [[
                    'role'  => 'user',
                    'parts' => [
                        ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($bytes)]],
                        ['text' => 'Transcribe this audio verbatim. Reply with the transcript only — no preamble, no commentary, no quotation marks.'],
                    ],
                ]],
            ]),
        ]);

        if (is_wp_error($resp)) {
            return ['success' => false, 'error' => $resp->get_error_message()];
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $text = '';
        foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $p) {
            $text .= (string) ($p['text'] ?? '');
        }
        $text = trim($text);
        if ($text === '') {
            $err = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            return ['success' => false, 'error' => $err ?: 'HTTP ' . wp_remote_retrieve_response_code($resp) . ' with no transcript.'];
        }
        return ['success' => true, 'text' => $text];
    }
}
