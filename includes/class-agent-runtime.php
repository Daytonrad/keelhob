<?php
/**
 * Autonomous agent runtime. Given a plain-language instruction and an LLM API
 * key (Anthropic Messages API by default), this runs a real agentic loop
 * *inside* WordPress: the model plans, calls Claudpress tools, reads the
 * results, and continues until the task is done — then returns a summary. This
 * is what lets the site run itself on a schedule with no external client open.
 *
 * Every tool call passes through Claudpress_Policy in 'autonomous' context, so
 * an unattended run cannot do something irreversible or costly without opt-in.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Agent_Runtime
{
    const DEFAULT_MODEL    = 'claude-sonnet-5';
    const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const MAX_STEPS        = 14;

    /**
     * @param array $opts allow_risky(bool), max_steps(int), model(string), context(string label)
     * @return array {success, summary, steps, tool_calls, error?}
     */
    public static function run(string $instruction, array $opts = []): array
    {
        $s   = Claudpress::instance()->settings();
        $key = (string) ($s['agent_api_key'] ?? '');
        if ($key === '') {
            return ['success' => false, 'error' => 'No agent API key configured. Add an Anthropic API key in WP Admin > Claudpress > Automation to enable autonomous runs.'];
        }
        if (!Claudpress::instance()->abilities_enabled()) {
            return ['success' => false, 'error' => 'AI abilities are disabled; autonomous runs are paused. Enable abilities in Claudpress settings.'];
        }

        $endpoint = (string) ($s['agent_api_endpoint'] ?? self::DEFAULT_ENDPOINT);
        $model    = (string) ($opts['model'] ?? ($s['agent_model'] ?? self::DEFAULT_MODEL));
        $max_steps = (int) ($opts['max_steps'] ?? self::MAX_STEPS);
        $task_opts = ['allow_risky' => !empty($opts['allow_risky'])];

        $registry = Claudpress::instance()->registry;
        $tools    = self::tools_for_api($registry);

        $messages = [[
            'role'    => 'user',
            'content' => $instruction,
        ]];

        $tool_calls = [];
        $final_text = '';

        for ($step = 0; $step < $max_steps; $step++) {
            $resp = self::call_api($endpoint, $key, [
                'model'      => $model,
                'max_tokens' => 4096,
                'system'     => self::system_prompt(),
                'tools'      => $tools,
                'messages'   => $messages,
            ]);

            if (!empty($resp['error'])) {
                return ['success' => false, 'error' => 'LLM API error: ' . $resp['error'], 'steps' => $step, 'tool_calls' => $tool_calls];
            }

            $content     = $resp['content'] ?? [];
            $stop_reason = $resp['stop_reason'] ?? 'end_turn';

            // Collect any assistant text.
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $final_text .= $block['text'];
                }
            }

            if ($stop_reason !== 'tool_use') {
                // Done.
                return ['success' => true, 'summary' => trim($final_text), 'steps' => $step + 1, 'tool_calls' => $tool_calls];
            }

            // Record assistant turn, then execute each tool_use and feed results back.
            $messages[] = ['role' => 'assistant', 'content' => $content];
            $tool_results = [];

            foreach ($content as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }
                $name = (string) ($block['name'] ?? '');
                $args = is_array($block['input'] ?? null) ? $block['input'] : [];

                $blocked = Claudpress_Policy::check($name, $args, 'autonomous', $task_opts);
                if ($blocked !== null) {
                    $result = ['success' => false, 'error' => $blocked, 'policy_blocked' => true];
                } elseif (!$registry->has($name)) {
                    $result = ['success' => false, 'error' => 'Unknown tool: ' . $name];
                } else {
                    try {
                        $result = $registry->call($name, $args);
                    } catch (Throwable $e) {
                        $result = ['success' => false, 'error' => $e->getMessage()];
                    }
                }

                $tool_calls[] = ['tool' => $name, 'blocked' => $blocked !== null, 'ok' => !(isset($result['success']) && $result['success'] === false)];
                $tool_results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $block['id'] ?? '',
                    'content'     => wp_json_encode($result, JSON_UNESCAPED_SLASHES),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $tool_results];
        }

        return ['success' => true, 'summary' => trim($final_text) ?: 'Reached the step limit before finishing.', 'steps' => $max_steps, 'tool_calls' => $tool_calls, 'truncated' => true];
    }

    private static function system_prompt(): string
    {
        $mem = [];
        foreach (Claudpress::instance()->memory->all() as $m) {
            $mem[] = '- ' . $m['key'] . ': ' . $m['value'];
        }
        $memory_block = $mem ? "\n\nSite memory (conventions & context):\n" . implode("\n", array_slice($mem, 0, 40)) : '';

        return implode("\n", [
            'You are the Claudpress autonomous operator for the WordPress/WooCommerce site "' . get_bloginfo('name') . '" (' . home_url() . ').',
            'You run UNATTENDED on a schedule — no human will answer questions mid-run, so never ask; make sound decisions and act, then report.',
            'Use the provided tools to accomplish the instruction. Read before you write. Verify important changes by reading them back.',
            'Some destructive/financial tools are blocked by policy unless the task allows them; if a tool is policy_blocked, adapt or note it in your summary rather than retrying.',
            'Keep going until the task is complete or truly blocked, then stop and write a concise plain-text summary of what you did, what changed, and anything the owner should review.',
        ]) . $memory_block;
    }

    /** Convert the registry into Anthropic tool definitions. */
    private static function tools_for_api(Claudpress_Tool_Registry $registry): array
    {
        $tools = [];
        foreach ($registry->list() as $t) {
            $schema = $t['inputSchema'];
            if (empty($schema['type'])) {
                $schema = ['type' => 'object', 'properties' => (object) []];
            }
            $tools[] = [
                'name'         => $t['name'],
                'description'  => mb_substr((string) $t['description'], 0, 1000),
                'input_schema' => $schema,
            ];
        }
        return $tools;
    }

    private static function call_api(string $endpoint, string $key, array $body): array
    {
        $resp = wp_remote_post($endpoint, [
            'timeout' => 120,
            'headers' => [
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($resp)) {
            return ['error' => $resp->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300) {
            $msg = $data['error']['message'] ?? wp_remote_retrieve_body($resp);
            return ['error' => 'HTTP ' . $code . ': ' . mb_substr((string) $msg, 0, 400)];
        }
        return is_array($data) ? $data : ['error' => 'Malformed API response'];
    }
}
