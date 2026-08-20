<?php
/**
 * Automation & autonomy tools: an on-demand status dashboard, scheduled task
 * management, a manual autonomous-run trigger, and a notification sender.
 * These let the owner ask "what's going on?" and set the site to run itself.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Automation
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('get_dashboard', 'Get a full status snapshot of the site right now — store sales (today / 7d / 30d), open orders needing attention, out-of-stock products, content counts, pending comments, and available updates, plus a plain-language summary. Use this to answer "what\'s going on / how is the store doing / what needs attention" without the owner logging in. Read-only; works even when abilities are disabled.', [
            'type' => 'object', 'properties' => (object) [],
        ], [self::class, 'get_dashboard'], ['readOnlyHint' => true, 'title' => 'Get Dashboard']);


        $r->add('task_list', 'List all scheduled automations with mode, schedule, enabled state, and last run status/summary. Read-only.', [
            'type' => 'object', 'properties' => (object) [],
        ], [self::class, 'task_list'], ['readOnlyHint' => true]);




        $r->add('agent_runs', 'Review the history of autonomous runs: when each ran, what it was asked, how many steps and tool calls it took, how it ended (ok / capped / truncated / error), and its token usage and estimated USD cost. Also returns the trailing-24h spend against the configured budget caps. Use to answer "what has the site been doing on its own / what is autonomy costing me". Read-only.', [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'default' => 20, 'description' => 'How many recent runs to return (max 200).'],
            ],
        ], [self::class, 'agent_runs'], ['readOnlyHint' => true, 'title' => 'Agent Run History']);

    }

    public static function get_dashboard(array $a): array
    {
        return ['success' => true, 'dashboard' => Claudpress_Digest::snapshot()];
    }


    public static function task_list(array $a): array
    {
        $tasks = array_map(fn($t) => [
            'id' => (int) $t['id'], 'name' => $t['name'], 'mode' => $t['mode'], 'schedule' => $t['schedule'],
            'run_hour' => isset($t['run_hour']) && $t['run_hour'] !== null ? (int) $t['run_hour'] : null,
            'enabled' => (bool) $t['enabled'], 'allow_risky' => (bool) $t['allow_risky'],
            'last_run' => $t['last_run'], 'last_status' => $t['last_status'],
            'last_summary' => mb_substr((string) $t['last_summary'], 0, 400),
        ], Claudpress_Tasks::all());
        return ['success' => true, 'tasks' => $tasks];
    }




    public static function agent_runs(array $a): array
    {
        $runs = array_map(static fn($r) => [
            'id'          => (int) $r['id'],
            'started_at'  => $r['started_at'],
            'finished_at' => $r['finished_at'],
            'context'     => $r['context'],
            'task_id'     => $r['task_id'] ? (int) $r['task_id'] : null,
            'model'       => $r['model'],
            'status'      => $r['status'],
            'steps'       => (int) $r['steps'],
            'tool_calls'  => (int) $r['tool_calls'],
            'retries'     => (int) $r['retries'],
            'tokens'      => (int) $r['input_tokens'] + (int) $r['output_tokens'],
            'cost_usd'    => (float) $r['cost_usd'],
            'instruction' => mb_substr((string) $r['instruction'], 0, 300),
            'summary'     => mb_substr((string) $r['summary'], 0, 600),
        ], Claudpress_Agent_Runs::recent((int) ($a['limit'] ?? 20)));

        return [
            'success'   => true,
            'runs'      => $runs,
            'usage_24h' => Claudpress_Agent_Runs::usage_since(24),
            'caps'      => Claudpress_Agent_Runtime::caps(),
        ];
    }

}
