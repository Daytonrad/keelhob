<?php
/**
 * The OpenAI chat adapter — and, in practice, the compatibility adapter.
 *
 * Most third-party gateways speak Chat Completions, so this file is what an
 * owner is really running whenever they point the endpoint override at Azure
 * OpenAI, OpenRouter, Groq, Together, or a local llama.cpp/Ollama server.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI Chat Completions. Also covers the many OpenAI-compatible gateways
 * (Azure OpenAI, OpenRouter, Groq, Together, local llama.cpp/Ollama) — point the
 * endpoint override at those and the wire format is identical.
 */
final class Claudpress_Provider_OpenAI extends Claudpress_Provider
{
    public static function id(): string { return 'openai'; }
    public static function label(): string { return 'OpenAI (GPT) & compatible'; }
    public static function default_endpoint(): string { return 'https://api.openai.com/v1/chat/completions'; }
    public static function default_model(): string { return 'gpt-5.1'; }
    public static function key_url(): string { return 'https://platform.openai.com/api-keys'; }
    public static function models_url(): string { return 'https://platform.openai.com/docs/models'; }
    public static function key_hint(): string { return 'sk-...'; }

    public static function models(): array
    {
        return [
            ['id' => 'gpt-5.1',      'note' => 'Most capable general model.'],
            ['id' => 'gpt-5.1-mini', 'note' => 'Cheaper and faster; fine for routine scheduled tasks.'],
            ['id' => 'gpt-5',        'note' => 'Previous flagship.'],
            ['id' => 'o4-mini',      'note' => 'Reasoning-focused, economical.'],
        ];
    }

    public static function models_list_url(string $endpoint): string
    {
        return str_replace('/chat/completions', '/models', $endpoint);
    }

    /**
     * `{"data":[{"id":...,"created":...}]}` — everything on the account, with no
     * capability flags to sort by: embeddings, speech, images and moderation all
     * come back alongside the chat models. So the chat families are picked out by
     * name. Anything genuinely new that this misses is still reachable through
     * "Type a custom model", which is why the filter can afford to be strict.
     */
    public static function parse_models(array $data): array
    {
        $families = ['gpt-', 'chatgpt-', 'o1', 'o3', 'o4'];
        $not_chat = ['embedding', 'audio', 'realtime', 'tts', 'whisper', 'image', 'dall-e', 'moderation', 'transcribe', 'search', 'sora'];

        $rows = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : [];
        // 'created' is a unix timestamp: newest first, so the model someone wants
        // is at the top rather than buried under years of retired ones.
        usort($rows, static function ($a, $b) {
            return ((int) ($b['created'] ?? 0)) <=> ((int) ($a['created'] ?? 0));
        });

        $out = [];
        foreach ($rows as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $chat = false;
            foreach ($families as $family) {
                if (strpos($id, $family) === 0) {
                    $chat = true;
                    break;
                }
            }
            if (!$chat) {
                continue;
            }
            foreach ($not_chat as $marker) {
                if (strpos($id, $marker) !== false) {
                    continue 2;
                }
            }
            $out[] = $id;
        }
        return array_values(array_unique($out));
    }

    public static function headers(string $key): array
    {
        return [
            'Authorization' => 'Bearer ' . $key,
            'content-type'  => 'application/json',
        ];
    }

    public static function body(string $model, string $system, array $tools, array $messages): array
    {
        $defs = [];
        foreach ($tools as $t) {
            $defs[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $t['name'],
                    'description' => $t['description'],
                    'parameters'  => $t['schema'],
                ],
            ];
        }
        // OpenAI carries the system prompt as the first message, not a field.
        return [
            'model'    => $model,
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
            'tools'    => $defs,
        ];
    }

    public static function user_message(string $text): array
    {
        return ['role' => 'user', 'content' => $text];
    }

    public static function parse(array $data): array
    {
        $msg   = $data['choices'][0]['message'] ?? [];
        $calls = [];

        foreach ((array) ($msg['tool_calls'] ?? []) as $c) {
            // Arguments arrive as a JSON *string*, unlike the other two vendors.
            $args = json_decode((string) ($c['function']['arguments'] ?? '{}'), true);
            $calls[] = [
                'id'   => (string) ($c['id'] ?? ''),
                'name' => (string) ($c['function']['name'] ?? ''),
                'args' => is_array($args) ? $args : [],
            ];
        }

        $u = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        return [
            'text'        => (string) ($msg['content'] ?? ''),
            'tool_calls'  => $calls,
            'wants_tools' => !empty($calls),
            'usage'       => [
                'input'  => (int) ($u['prompt_tokens'] ?? 0),
                'output' => (int) ($u['completion_tokens'] ?? 0),
            ],
            'raw'         => ['message' => $msg],
        ];
    }

    public static function assistant_message(array $parsed): array
    {
        return $parsed['raw']['message'];
    }

    public static function user_image_message(string $text, string $mime, string $b64): array
    {
        return ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $text],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $b64]],
        ]];
    }

    public static function tool_result_message(array $results): array
    {
        // One message per result, each keyed back to its call id.
        $out = [];
        foreach ($results as $r) {
            $out[] = [
                'role'         => 'tool',
                'tool_call_id' => $r['id'],
                'content'      => wp_json_encode($r['result'], JSON_UNESCAPED_SLASHES),
            ];
        }
        return $out;
    }
}
