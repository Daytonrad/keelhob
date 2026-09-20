<?php
/**
 * The Anthropic chat adapter.
 *
 * Anthropic is the closest of the three to the runtime's own normalized shape —
 * the format in class-provider.php was drawn from the Messages API — so this
 * adapter is the one that translates least, and the one to read first when
 * learning what an adapter has to do.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Anthropic Messages API. Tool calls arrive as `tool_use` content blocks and
 * results go back as `tool_result` blocks inside a single user message.
 */
final class Keelhob_Provider_Anthropic extends Keelhob_Provider
{
    public static function id(): string { return 'anthropic'; }
    public static function label(): string { return 'Anthropic (Claude)'; }
    public static function default_endpoint(): string { return 'https://api.anthropic.com/v1/messages'; }
    public static function default_model(): string { return 'claude-sonnet-5'; }
    public static function key_url(): string { return 'https://console.anthropic.com/settings/keys'; }
    public static function models_url(): string { return 'https://docs.anthropic.com/en/docs/about-claude/models'; }
    public static function key_hint(): string { return 'sk-ant-...'; }

    public static function models(): array
    {
        return [
            ['id' => 'claude-opus-5',           'note' => 'Most capable. Best for complex, multi-step site work.'],
            ['id' => 'claude-sonnet-5',         'note' => 'Recommended default — strong reasoning at moderate cost.'],
            ['id' => 'claude-haiku-4-5',        'note' => 'Fastest and cheapest. Good for routine scheduled tasks.'],
        ];
    }

    public static function models_list_url(string $endpoint): string
    {
        return str_replace('/v1/messages', '/v1/models', $endpoint) . '?limit=100';
    }

    /**
     * `{"data":[{"id":...,"created_at":...}]}`. The list already arrives newest
     * first, but it is sorted on created_at rather than trusted to stay that way.
     */
    public static function parse_models(array $data): array
    {
        $rows = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : [];
        // ISO-8601 timestamps sort correctly as plain strings, so no date parsing.
        usort($rows, static function ($a, $b) {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        $out = [];
        foreach ($rows as $m) {
            if (!empty($m['id'])) {
                $out[] = (string) $m['id'];
            }
        }
        return array_values(array_unique($out));
    }

    public static function headers(string $key): array
    {
        return [
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ];
    }

    public static function body(string $model, string $system, array $tools, array $messages): array
    {
        $defs = [];
        foreach ($tools as $t) {
            $defs[] = [
                'name'         => $t['name'],
                'description'  => $t['description'],
                'input_schema' => $t['schema'],
            ];
        }
        return [
            'model'      => $model,
            'max_tokens' => 4096,
            'system'     => $system,
            'tools'      => $defs,
            'messages'   => $messages,
        ];
    }

    public static function user_message(string $text): array
    {
        return ['role' => 'user', 'content' => $text];
    }

    public static function parse(array $data): array
    {
        $text  = '';
        $calls = [];
        $blocks = is_array($data['content'] ?? null) ? $data['content'] : [];

        foreach ($blocks as $i => $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif ($type === 'tool_use') {
                // A no-argument call decodes to [] and re-encodes as a JSON array,
                // which the API rejects — it requires an object.
                if (!isset($block['input']) || $block['input'] === [] || $block['input'] === null) {
                    $blocks[$i]['input'] = new stdClass();
                }
                $calls[] = [
                    'id'   => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'args' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
            }
        }

        $u = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        return [
            'text'        => $text,
            'tool_calls'  => $calls,
            'wants_tools' => ($data['stop_reason'] ?? '') === 'tool_use',
            'usage'       => [
                'input'  => (int) ($u['input_tokens'] ?? 0)
                            + (int) ($u['cache_read_input_tokens'] ?? 0)
                            + (int) ($u['cache_creation_input_tokens'] ?? 0),
                'output' => (int) ($u['output_tokens'] ?? 0),
            ],
            'raw'         => ['content' => $blocks],
        ];
    }

    public static function assistant_message(array $parsed): array
    {
        return ['role' => 'assistant', 'content' => $parsed['raw']['content']];
    }

    public static function tool_result_message(array $results): array
    {
        $blocks = [];
        foreach ($results as $r) {
            $blocks[] = [
                'type'        => 'tool_result',
                'tool_use_id' => $r['id'],
                'content'     => wp_json_encode($r['result'], JSON_UNESCAPED_SLASHES),
            ];
        }
        return [['role' => 'user', 'content' => $blocks]];
    }
}
