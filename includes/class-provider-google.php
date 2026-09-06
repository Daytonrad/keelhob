<?php
/**
 * The Google Gemini chat adapter.
 *
 * Gemini departs from the base contract in more places than any other supported
 * vendor — the model goes in the URL path, the roles are named differently, and
 * every piece of a turn is a `parts` entry — so this file overrides the most.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Gemini generateContent. The model goes in the URL path, roles are
 * user/model rather than user/assistant, and every part of a turn (text, calls,
 * results) is a `parts` entry.
 */
final class Claudpress_Provider_Google extends Claudpress_Provider
{
    public static function id(): string { return 'google'; }
    public static function label(): string { return 'Google (Gemini)'; }
    public static function default_endpoint(): string { return 'https://generativelanguage.googleapis.com/v1beta/models'; }
    public static function default_model(): string { return 'gemini-3-pro'; }
    public static function key_url(): string { return 'https://aistudio.google.com/apikey'; }
    public static function models_url(): string { return 'https://ai.google.dev/gemini-api/docs/models'; }
    public static function key_hint(): string { return 'AIza...'; }

    public static function models(): array
    {
        return [
            ['id' => 'gemini-3-pro',   'note' => 'Most capable Gemini. Best for complex site work.'],
            ['id' => 'gemini-3-flash', 'note' => 'Fast and cheap; good for routine scheduled tasks.'],
            ['id' => 'gemini-2.5-pro', 'note' => 'Previous flagship.'],
        ];
    }

    public static function models_list_url(string $endpoint): string
    {
        // The chat endpoint already ends at .../models; the list is that URL as-is.
        return rtrim((string) preg_replace('#/[^/]*:generateContent$#', '', $endpoint), '/') . '?pageSize=200';
    }

    /**
     * Gemini states outright which methods each model supports, so the filter is
     * the vendor's own answer rather than a guess.
     *
     * The rows carry no date, so the ordering is reverse-alphabetical — an
     * approximation, but one that puts gemini-3 above gemini-2.5, which is the
     * question anyone reading the top of the list is actually asking.
     */
    public static function parse_models(array $data): array
    {
        // Gemini's speech and image models answer generateContent too but return
        // audio or pictures. Picking one fails on the first request with an error
        // about response modalities that means nothing to a site owner.
        $not_text = ['-tts', 'image', 'embedding', 'aqa', 'veo-', 'imagen-'];
        $rows     = (isset($data['models']) && is_array($data['models'])) ? $data['models'] : [];

        $out = [];
        foreach ($rows as $m) {
            $methods = isset($m['supportedGenerationMethods']) ? (array) $m['supportedGenerationMethods'] : [];
            if ($methods && !in_array('generateContent', $methods, true)) {
                continue;
            }
            // The API returns "models/gemini-3-pro"; requests take the bare id.
            $id = (string) preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
            if ($id === '') {
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

    public static function url(string $endpoint, string $model): string
    {
        // Owners who paste a full :generateContent URL keep it as-is.
        if (strpos($endpoint, ':generateContent') !== false) {
            return $endpoint;
        }
        return rtrim($endpoint, '/') . '/' . rawurlencode($model) . ':generateContent';
    }

    public static function headers(string $key): array
    {
        return [
            'x-goog-api-key' => $key,
            'content-type'   => 'application/json',
        ];
    }

    public static function body(string $model, string $system, array $tools, array $messages): array
    {
        $decls = [];
        foreach ($tools as $t) {
            $decls[] = [
                'name'        => $t['name'],
                'description' => $t['description'],
                'parameters'  => self::clean_schema($t['schema']),
            ];
        }
        return [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $messages,
            'tools'             => [['functionDeclarations' => $decls]],
        ];
    }

    public static function user_message(string $text): array
    {
        return ['role' => 'user', 'parts' => [['text' => $text]]];
    }

    public static function parse(array $data): array
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $text  = '';
        $calls = [];
        $i     = 0;

        foreach ((array) $parts as $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }
            if (isset($part['functionCall'])) {
                $name = (string) ($part['functionCall']['name'] ?? '');
                // Gemini has no call ids; results are matched by name, so we
                // synthesize a stable one for the runtime's bookkeeping.
                $calls[] = [
                    'id'   => $name . '_' . $i,
                    'name' => $name,
                    'args' => (array) ($part['functionCall']['args'] ?? []),
                ];
                $i++;
            }
        }

        $u = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];
        return [
            'text'        => $text,
            'tool_calls'  => $calls,
            'wants_tools' => !empty($calls),
            'usage'       => [
                'input'  => (int) ($u['promptTokenCount'] ?? 0),
                'output' => (int) ($u['candidatesTokenCount'] ?? 0),
            ],
            'raw'         => ['parts' => $parts],
        ];
    }

    public static function assistant_message(array $parsed): array
    {
        return ['role' => 'model', 'parts' => $parsed['raw']['parts']];
    }

    public static function assistant_text_message(string $text): array
    {
        return ['role' => 'model', 'parts' => [['text' => $text]]];
    }

    public static function user_image_message(string $text, string $mime, string $b64): array
    {
        return ['role' => 'user', 'parts' => [
            ['inlineData' => ['mimeType' => $mime, 'data' => $b64]],
            ['text' => $text],
        ]];
    }

    public static function tool_result_message(array $results): array
    {
        $parts = [];
        foreach ($results as $r) {
            $parts[] = [
                'functionResponse' => [
                    'name'     => $r['name'],
                    'response' => is_array($r['result']) ? $r['result'] : ['result' => $r['result']],
                ],
            ];
        }
        return [['role' => 'user', 'parts' => $parts]];
    }

    public static function error_message(array $data): string
    {
        return (string) ($data['error']['message'] ?? '');
    }
}
