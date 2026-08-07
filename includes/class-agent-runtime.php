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
 *
 * Unattended means nobody is there to retry a blip or notice a runaway bill, so
 * the loop also: retries transient API failures with exponential backoff, meters
 * token spend against per-run and per-day caps, and records every run to
 * Claudpress_Agent_Runs for the owner to review.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Agent_Runtime
{
    const DEFAULT_MODEL    = 'claude-sonnet-5';
    const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    const MAX_STEPS        = 14;

    /** Retry policy for transient API failures. */
    const MAX_ATTEMPTS     = 4;     // per step: 1 try + 3 retries
    const BACKOFF_BASE_MS  = 1000;  // doubles each attempt
    const BACKOFF_CAP_MS   = 30000; // never sleep longer than this on one wait

    /**
     * Approximate list prices in USD per million tokens, matched by model-name
     * prefix (longest match wins). Used only to estimate spend for the caps and
     * the run log — filter 'claudpress_model_prices' to correct or extend it.
     *
     * @return array<string, array{in: float, out: float}>
     */
    public static function model_prices(): array
    {
        $prices = [
            'claude-opus'    => ['in' => 15.0, 'out' => 75.0],
            'claude-sonnet'  => ['in' => 3.0,  'out' => 15.0],
            'claude-haiku'   => ['in' => 1.0,  'out' => 5.0],
            'claude-fable'   => ['in' => 3.0,  'out' => 15.0],
            'claude-3-opus'  => ['in' => 15.0, 'out' => 75.0],
            'claude-3-haiku' => ['in' => 0.25, 'out' => 1.25],
        ];
        return (array) apply_filters('claudpress_model_prices', $prices);
    }

    /** Longest-prefix price lookup; falls back to Sonnet-class pricing. */
    public static function price_for_model(string $model): array
    {
        $model = strtolower(trim($model));
        $best  = null;
        $len   = -1;
        foreach (self::model_prices() as $prefix => $price) {
            if (strpos($model, $prefix) === 0 && strlen($prefix) > $len) {
                $best = $price;
                $len  = strlen($prefix);
            }
        }
        return $best ?: ['in' => 3.0, 'out' => 15.0];
    }

    /** Estimated USD for a token count on a given model. */
    public static function estimate_cost(string $model, int $input_tokens, int $output_tokens): float
    {
        $p = self::price_for_model($model);
        return round((($input_tokens / 1000000) * $p['in']) + (($output_tokens / 1000000) * $p['out']), 6);
    }

    /**
     * Which failures are worth trying again. Rate limits, overload, and 5xx are
     * transient; 4xx (bad key, bad request) will fail identically forever.
     *
     * @param int|null $http_code null = transport-level failure (DNS, timeout)
     */
    public static function should_retry(?int $http_code): bool
    {
        if ($http_code === null) {
            return true; // network/transport error
        }
        if ($http_code === 408 || $http_code === 429) {
            return true;
        }
        return $http_code >= 500 && $http_code < 600;
    }

    /**
     * Backoff in milliseconds for a given attempt (0-indexed), with jitter.
     * A server-sent Retry-After always wins, capped so a bad header cannot
     * stall a cron run indefinitely.
     */
    public static function backoff_delay_ms(int $attempt, ?int $retry_after_seconds = null, ?float $jitter = null): int
    {
        if ($retry_after_seconds !== null && $retry_after_seconds > 0) {
            return (int) min($retry_after_seconds * 1000, self::BACKOFF_CAP_MS);
        }
        $base = self::BACKOFF_BASE_MS * (2 ** max(0, $attempt));
        $base = (int) min($base, self::BACKOFF_CAP_MS);
        // Full jitter: sleep somewhere in [50%, 100%] of the window.
        $j = $jitter === null ? (mt_rand(500, 1000) / 1000) : max(0.0, min(1.0, $jitter));
        return (int) max(1, round($base * (0.5 + ($j * 0.5))));
    }

    /** Cap settings, merged over defaults. 0 = unlimited. */
    public static function caps(): array
    {
        $s = Claudpress::instance()->settings();
        return [
            'max_usd_per_run'    => (float) ($s['agent_max_usd_per_run'] ?? 0.50),
            'max_tokens_per_run' => (int) ($s['agent_max_tokens_per_run'] ?? 300000),
            'max_usd_per_day'    => (float) ($s['agent_max_usd_per_day'] ?? 5.00),
            'max_runs_per_day'   => (int) ($s['agent_max_runs_per_day'] ?? 48),
        ];
    }

    /**
     * Pure cap evaluation — given caps, the spend so far in this run, and the
     * trailing-24h usage, return a human-readable stop reason or null.
     *
     * @param array $caps     from caps()
     * @param array $run      ['cost'=>float,'tokens'=>int]
     * @param array $day      ['cost'=>float,'runs'=>int] trailing 24h (excluding this run)
     * @param bool  $starting true when checked before the first API call
     */
    public static function cap_exceeded(array $caps, array $run, array $day, bool $starting = false): ?string
    {
        if ($starting) {
            if (!empty($caps['max_runs_per_day']) && ($day['runs'] ?? 0) >= $caps['max_runs_per_day']) {
                return sprintf('Daily run cap reached (%d runs in the last 24h). Raise it in Claudpress > Automation.', (int) $caps['max_runs_per_day']);
            }
            if (!empty($caps['max_usd_per_day']) && ($day['cost'] ?? 0) >= $caps['max_usd_per_day']) {
                return sprintf('Daily spend cap reached ($%.2f of $%.2f in the last 24h). Raise it in Claudpress > Automation.', (float) ($day['cost'] ?? 0), (float) $caps['max_usd_per_day']);
            }
            return null;
        }
        if (!empty($caps['max_usd_per_run']) && ($run['cost'] ?? 0) >= $caps['max_usd_per_run']) {
            return sprintf('Run spend cap reached ($%.2f of $%.2f).', (float) ($run['cost'] ?? 0), (float) $caps['max_usd_per_run']);
        }
        if (!empty($caps['max_tokens_per_run']) && ($run['tokens'] ?? 0) >= $caps['max_tokens_per_run']) {
            return sprintf('Run token cap reached (%s of %s tokens).', number_format((int) ($run['tokens'] ?? 0)), number_format((int) $caps['max_tokens_per_run']));
        }
        if (!empty($caps['max_usd_per_day']) && (($day['cost'] ?? 0) + ($run['cost'] ?? 0)) >= $caps['max_usd_per_day']) {
            return sprintf('Daily spend cap reached ($%.2f of $%.2f).', (float) (($day['cost'] ?? 0) + ($run['cost'] ?? 0)), (float) $caps['max_usd_per_day']);
        }
        return null;
    }

    /**
     * @param array $opts allow_risky(bool), max_steps(int), model(string), context(string label), task_id(int)
     * @return array {success, summary, steps, tool_calls, usage, error?}
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

        $endpoint  = (string) ($s['agent_api_endpoint'] ?? self::DEFAULT_ENDPOINT);
        $model     = (string) ($opts['model'] ?? ($s['agent_model'] ?? self::DEFAULT_MODEL));
        $max_steps = (int) ($opts['max_steps'] ?? self::MAX_STEPS);
        $context   = (string) ($opts['context'] ?? 'manual');
        $task_opts = ['allow_risky' => !empty($opts['allow_risky'])];

        // ---- budget gate (before we spend a cent) ----
        $caps = self::caps();
        $day  = Claudpress_Agent_Runs::usage_since(24);
        $stop = self::cap_exceeded($caps, ['cost' => 0, 'tokens' => 0], $day, true);
        if ($stop !== null) {
            return ['success' => false, 'error' => $stop, 'cost_capped' => true, 'usage_24h' => $day];
        }

        $run_id   = Claudpress_Agent_Runs::start($instruction, $context, $model, (int) ($opts['task_id'] ?? 0) ?: null);
        $registry = Claudpress::instance()->registry;
        $tools    = self::tools_for_api($registry);

        $messages   = [['role' => 'user', 'content' => $instruction]];
        $tool_calls = [];
        $final_text = '';
        $usage      = ['input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0, 'tokens' => 0, 'retries' => 0];
        $capped     = null;
        $step       = 0;

        for ($step = 0; $step < $max_steps; $step++) {
            $resp = self::call_api($endpoint, $key, [
                'model'      => $model,
                'max_tokens' => 4096,
                'system'     => self::system_prompt(),
                'tools'      => $tools,
                'messages'   => $messages,
            ]);
            $usage['retries'] += (int) ($resp['_retries'] ?? 0);

            if (!empty($resp['error'])) {
                $out = ['success' => false, 'error' => 'LLM API error: ' . $resp['error'], 'steps' => $step, 'tool_calls' => $tool_calls, 'usage' => self::public_usage($usage)];
                self::record($run_id, 'error', $usage, $step, $tool_calls, (string) $resp['error'], $out);
                return $out;
            }

            // Meter this step's tokens before deciding whether to continue.
            $u = is_array($resp['usage'] ?? null) ? $resp['usage'] : [];
            $in  = (int) ($u['input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0) + (int) ($u['cache_creation_input_tokens'] ?? 0);
            $out_t = (int) ($u['output_tokens'] ?? 0);
            $usage['input_tokens']  += $in;
            $usage['output_tokens'] += $out_t;
            $usage['tokens']         = $usage['input_tokens'] + $usage['output_tokens'];
            $usage['cost']           = self::estimate_cost($model, $usage['input_tokens'], $usage['output_tokens']);

            $content     = $resp['content'] ?? [];
            $stop_reason = $resp['stop_reason'] ?? 'end_turn';

            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $final_text .= $block['text'];
                }
            }

            if ($stop_reason !== 'tool_use') {
                $out = ['success' => true, 'summary' => trim($final_text), 'steps' => $step + 1, 'tool_calls' => $tool_calls, 'usage' => self::public_usage($usage)];
                self::record($run_id, 'ok', $usage, $step + 1, $tool_calls, trim($final_text), $out);
                return $out;
            }

            // Anthropic requires tool_use.input to be a JSON object. A tool call
            // with no arguments decodes to an empty PHP array [], which re-encodes
            // as [] (an array) and gets rejected — coerce those back to an object.
            foreach ($content as $i => $block) {
                if (($block['type'] ?? '') === 'tool_use' && (!isset($block['input']) || $block['input'] === [] || $block['input'] === null)) {
                    $content[$i]['input'] = new stdClass();
                }
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

                if (!Claudpress_Capabilities::tool_allowed($name)) {
                    $result = ['success' => false, 'error' => 'That capability is turned off by the site owner.', 'policy_blocked' => true];
                    $tool_calls[] = ['tool' => $name, 'blocked' => true, 'ok' => false];
                    $tool_results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'] ?? '', 'content' => wp_json_encode($result)];
                    continue;
                }

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

            // Budget check between steps: stop cleanly rather than mid-tool-call.
            $capped = self::cap_exceeded($caps, $usage, $day, false);
            if ($capped !== null) {
                break;
            }
        }

        if ($capped !== null) {
            $summary = trim($final_text) ?: 'Stopped before finishing.';
            $out = [
                'success' => true, 'summary' => $summary . "\n\n[Stopped early: " . $capped . ']',
                'steps' => $step + 1, 'tool_calls' => $tool_calls, 'usage' => self::public_usage($usage),
                'cost_capped' => true, 'cap_reason' => $capped,
            ];
            self::record($run_id, 'capped', $usage, $step + 1, $tool_calls, $out['summary'], $out);
            return $out;
        }

        $out = [
            'success' => true, 'summary' => trim($final_text) ?: 'Reached the step limit before finishing.',
            'steps' => $max_steps, 'tool_calls' => $tool_calls, 'usage' => self::public_usage($usage), 'truncated' => true,
        ];
        self::record($run_id, 'truncated', $usage, $max_steps, $tool_calls, $out['summary'], $out);
        return $out;
    }

    private static function public_usage(array $u): array
    {
        return [
            'input_tokens'  => (int) $u['input_tokens'],
            'output_tokens' => (int) $u['output_tokens'],
            'total_tokens'  => (int) $u['tokens'],
            'cost_usd'      => round((float) $u['cost'], 5),
            'retries'       => (int) $u['retries'],
        ];
    }

    private static function record(int $run_id, string $status, array $usage, int $steps, array $tool_calls, string $summary, array $detail): void
    {
        Claudpress_Agent_Runs::finish($run_id, [
            'status'        => $status,
            'steps'         => $steps,
            'tool_calls'    => count($tool_calls),
            'retries'       => (int) $usage['retries'],
            'input_tokens'  => (int) $usage['input_tokens'],
            'output_tokens' => (int) $usage['output_tokens'],
            'cost_usd'      => (float) $usage['cost'],
            'summary'       => $summary,
            'detail'        => ['tools' => $tool_calls] + array_intersect_key($detail, ['error' => 1, 'cap_reason' => 1, 'truncated' => 1]),
        ]);
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
            'This run has a spend budget. Be efficient: batch your work, avoid redundant reads, and do not re-fetch data you already have.',
            'Keep going until the task is complete or truly blocked, then stop and write a concise plain-text summary of what you did, what changed, and anything the owner should review.',
        ]) . $memory_block;
    }

    /** Convert the registry into Anthropic tool definitions. */
    private static function tools_for_api(Claudpress_Tool_Registry $registry): array
    {
        $tools = [];
        foreach ($registry->list() as $t) {
            if (!Claudpress_Capabilities::tool_allowed($t['name'])) {
                continue; // owner switched this capability off
            }
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

    /**
     * One Messages API call, with retry/backoff around transient failures.
     * Adds a '_retries' key so the caller can account for them.
     */
    private static function call_api(string $endpoint, string $key, array $body): array
    {
        $attempts = (int) apply_filters('claudpress_agent_max_attempts', self::MAX_ATTEMPTS);
        $attempts = max(1, min(8, $attempts));
        $retries  = 0;
        $last     = 'unknown error';

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                $retries++;
            }

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
                $last = $resp->get_error_message();
                $code = null;
            } else {
                $code = (int) wp_remote_retrieve_response_code($resp);
                if ($code >= 200 && $code < 300) {
                    $data = json_decode(wp_remote_retrieve_body($resp), true);
                    if (!is_array($data)) {
                        return ['error' => 'Malformed API response', '_retries' => $retries];
                    }
                    $data['_retries'] = $retries;
                    return $data;
                }
                $data = json_decode(wp_remote_retrieve_body($resp), true);
                $msg  = $data['error']['message'] ?? wp_remote_retrieve_body($resp);
                $last = 'HTTP ' . $code . ': ' . mb_substr((string) $msg, 0, 400);
            }

            $is_last = ($attempt === $attempts - 1);
            if ($is_last || !self::should_retry($code)) {
                break;
            }

            $retry_after = null;
            if (!is_wp_error($resp)) {
                $hdr = wp_remote_retrieve_header($resp, 'retry-after');
                if (is_string($hdr) && is_numeric($hdr)) {
                    $retry_after = (int) $hdr;
                }
            }
            $wait_ms = self::backoff_delay_ms($attempt, $retry_after);
            Claudpress::instance()->logger->record('agent_retry', ['attempt' => $attempt + 1, 'wait_ms' => $wait_ms], ['success' => false, 'error' => $last]);
            usleep($wait_ms * 1000);
        }

        return ['error' => $last . ($retries ? sprintf(' (after %d %s)', $retries, $retries === 1 ? 'retry' : 'retries') : ''), '_retries' => $retries];
    }
}
