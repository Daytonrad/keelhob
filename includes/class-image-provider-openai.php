<?php
/**
 * The OpenAI image adapter, and every gateway that mirrors the Images API.
 *
 * The bytes normally come back base64-encoded in the response body; a hosted
 * URL is the fallback for endpoints that hand back a link instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** OpenAI Images API, and any gateway that mirrors it. */
final class Keelhob_Image_Provider_OpenAI extends Keelhob_Image_Provider
{
    public static function id(): string { return 'openai'; }
    public static function label(): string { return 'OpenAI (GPT Image / DALL·E) & compatible'; }
    public static function default_endpoint(): string { return 'https://api.openai.com/v1/images/generations'; }
    public static function default_model(): string { return 'gpt-image-1'; }
    public static function key_url(): string { return 'https://platform.openai.com/api-keys'; }
    public static function models_url(): string { return 'https://platform.openai.com/docs/models'; }
    public static function key_hint(): string { return 'sk-...'; }

    public static function models(): array
    {
        return [
            ['id' => 'gpt-image-1',      'note' => 'Recommended. Best prompt-following and text rendering.'],
            ['id' => 'gpt-image-1-mini', 'note' => 'Cheaper and faster, slightly lower fidelity.'],
            ['id' => 'dall-e-3',         'note' => 'Older model. Still good for illustrative styles.'],
        ];
    }

    public static function headers(string $key): array
    {
        return ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'];
    }

    public static function auth_headers(string $key): array
    {
        return ['Authorization' => 'Bearer ' . $key];
    }

    public static function verify_url(string $endpoint, string $model): string
    {
        $base = $endpoint !== '' ? $endpoint : self::default_endpoint();
        // .../v1/images/generations is the generate route; .../v1/models/<id>
        // is its describe route. Derived rather than hard-coded so a gateway
        // that mirrors OpenAI under its own host is tested at that host.
        $base = (string) preg_replace('#/images/generations/?$#', '', $base);
        return rtrim($base, '/') . '/models/' . rawurlencode($model);
    }

    public static function models_list_url(string $endpoint): string
    {
        $base = $endpoint !== '' ? $endpoint : self::default_endpoint();
        $base = (string) preg_replace('#/images/generations/?$#', '', $base);
        return rtrim($base, '/') . '/models';
    }

    public static function parse_models(array $data): array
    {
        // OpenAI's list is every model the account can reach, so the image ones
        // have to be picked out by name. Both families are prefixes, which is
        // narrow enough that a chat or audio model cannot slip through.
        $families = ['gpt-image', 'dall-e'];

        $rows = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : [];
        // 'created' is a unix timestamp: newest first, so a new model shows up
        // at the top rather than below three retired ones.
        usort($rows, static function ($a, $b) {
            return ((int) ($b['created'] ?? 0)) <=> ((int) ($a['created'] ?? 0));
        });

        $out = [];
        foreach ($rows as $m) {
            $id = (string) ($m['id'] ?? '');
            foreach ($families as $family) {
                if ($id !== '' && strpos($id, $family) === 0) {
                    $out[] = $id;
                    break;
                }
            }
        }
        return array_values(array_unique($out));
    }

    public static function body(string $model, string $prompt, string $size): array
    {
        return ['model' => $model, 'prompt' => $prompt, 'size' => $size, 'n' => 1];
    }

    public static function extract_bytes(array $data): ?string
    {
        $b64 = $data['data'][0]['b64_json'] ?? '';
        if (!is_string($b64) || $b64 === '') {
            return null;
        }
        $bytes = base64_decode($b64, true);
        return $bytes === false ? null : $bytes;
    }

    public static function extract_url(array $data): ?string
    {
        $url = $data['data'][0]['url'] ?? '';
        return is_string($url) && $url !== '' ? $url : null;
    }
}
