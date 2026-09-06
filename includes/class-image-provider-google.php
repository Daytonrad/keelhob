<?php
/**
 * The Google Gemini image adapter — the models marketed as "Nano Banana".
 *
 * Gemini has no dedicated images endpoint. Generating a picture is an ordinary
 * generateContent call with an image modality asked for, which is why this
 * adapter overrides the URL and digs the bytes out of inline response parts.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Gemini image generation — the models marketed as "Nano Banana".
 * Images come back as inline base64 parts of a normal generateContent response,
 * not as a dedicated images endpoint.
 */
final class Claudpress_Image_Provider_Google extends Claudpress_Image_Provider
{
    public static function id(): string { return 'google'; }
    public static function label(): string { return 'Google (Gemini image / "Nano Banana")'; }
    public static function default_endpoint(): string { return 'https://generativelanguage.googleapis.com/v1beta/models'; }
    public static function default_model(): string { return 'gemini-3-pro-image'; }
    public static function key_url(): string { return 'https://aistudio.google.com/apikey'; }
    public static function models_url(): string { return 'https://ai.google.dev/gemini-api/docs/image-generation'; }
    public static function key_hint(): string { return 'AIza...'; }

    public static function models(): array
    {
        return [
            ['id' => 'gemini-3-pro-image',    'note' => 'The model marketed as "Nano Banana Pro". Highest quality.'],
            ['id' => 'gemini-2.5-flash-image', 'note' => 'The original "Nano Banana". Fast and cheap.'],
        ];
    }

    public static function url(string $endpoint, string $model): string
    {
        if (strpos($endpoint, ':generateContent') !== false) {
            return $endpoint;
        }
        return rtrim($endpoint, '/') . '/' . rawurlencode($model) . ':generateContent';
    }

    public static function headers(string $key): array
    {
        return ['x-goog-api-key' => $key, 'Content-Type' => 'application/json'];
    }

    public static function auth_headers(string $key): array
    {
        return ['x-goog-api-key' => $key];
    }

    public static function verify_url(string $endpoint, string $model): string
    {
        $base = $endpoint !== '' ? $endpoint : self::default_endpoint();
        // An override may already be a full ".../models/<id>:generateContent".
        // Strip the action back off rather than appending a second model id.
        $base = (string) preg_replace('#/[^/]*:generateContent/?$#', '', $base);
        return rtrim($base, '/') . '/' . rawurlencode($model);
    }

    public static function models_list_url(string $endpoint): string
    {
        // For Gemini the endpoint IS the collection: ".../v1beta/models".
        $base = $endpoint !== '' ? $endpoint : self::default_endpoint();
        return (string) preg_replace('#/[^/]*:generateContent/?$#', '', $base);
    }

    public static function parse_models(array $data): array
    {
        $rows = (isset($data['models']) && is_array($data['models'])) ? $data['models'] : [];

        $out = [];
        foreach ($rows as $m) {
            $id = (string) preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
            if ($id === '' || strpos($id, 'gemini') !== 0 || strpos($id, 'image') === false) {
                continue;
            }
            // Imagen and Veo make pictures and video too, but through :predict,
            // which this adapter does not speak. They are excluded by the
            // gemini prefix above rather than by a blocklist that would need
            // updating every time Google ships another one.
            $methods = isset($m['supportedGenerationMethods']) ? (array) $m['supportedGenerationMethods'] : [];
            if ($methods && !in_array('generateContent', $methods, true)) {
                continue;
            }
            $out[] = $id;
        }
        $out = array_values(array_unique($out));
        rsort($out, SORT_NATURAL);
        return $out;
    }

    public static function body(string $model, string $prompt, string $size): array
    {
        // Gemini has no size parameter; the aspect ratio is expressed in the prompt.
        return [
            'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseModalities' => ['IMAGE']],
        ];
    }

    public static function extract_bytes(array $data): ?string
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach ((array) $parts as $part) {
            $b64 = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? '';
            if (is_string($b64) && $b64 !== '') {
                $bytes = base64_decode($b64, true);
                if ($bytes !== false) {
                    return $bytes;
                }
            }
        }
        return null;
    }
}
