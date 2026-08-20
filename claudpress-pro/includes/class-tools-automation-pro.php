<?php
/**
 * Pro automation tools: scheduled tasks, the one-off autonomous agent, and
 * owner notifications.
 *
 * Autonomy is the one feature that produces value while the owner is asleep,
 * which is exactly what justifies a recurring price rather than a one-off one.
 * So the code lives here, in a zip a free user never receives, rather than in
 * the free plugin behind a check somebody could delete.
 *
 * THREE TOOLS DELIBERATELY STAYED BEHIND in the free plugin, and moving them
 * here would break the site:
 *
 *   get_dashboard, task_list and agent_runs are in
 *   Claudpress_Capabilities::always_on(). Always-on tools bypass the tier gate
 *   entirely - that is the point of the list, so an owner can always ask "what
 *   is going on" no matter what else is restricted. Moving one into the add-on
 *   would make an always-on tool disappear on a free site, which is precisely
 *   the guarantee that list exists to make.
 *
 * On a free site task_list and agent_runs simply return nothing, because
 * nothing can have created a task or a run. Empty is the correct answer there,
 * and a much better one than a missing tool.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Automation_Pro
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('task_save', 'Create or update a scheduled automation. mode="digest" emails a status snapshot on a schedule (no API key needed). mode="autonomous" runs a plain-language instruction through the on-site AI agent on a schedule (requires an agent API key in settings) — e.g. "fulfil paid orders, restock flagged items, and publish any due draft posts". schedule: hourly|twicedaily|daily|weekly|monthly, with an optional run_hour (0-23, site time) to pin it to a time of day. Set allow_risky=true only if the task may perform destructive/financial actions (refunds, deletes, updates).', [
            'type' => 'object',
            'properties' => [
                'id'          => ['type' => 'integer'],
                'name'        => ['type' => 'string'],
                'mode'        => ['type' => 'string', 'enum' => ['digest', 'autonomous'], 'default' => 'digest'],
                'instruction' => ['type' => 'string', 'description' => 'For autonomous mode: what the agent should do each run.'],
                'schedule'    => ['type' => 'string', 'enum' => ['hourly', 'twicedaily', 'daily', 'weekly', 'monthly'], 'default' => 'daily'],
                'run_hour'    => ['type' => 'integer', 'minimum' => 0, 'maximum' => 23, 'description' => 'Preferred hour of day (0-23, site timezone) for daily/weekly/monthly tasks — e.g. 7 for a 7am digest. Omit to run whenever the interval elapses.'],
                'allow_risky' => ['type' => 'boolean', 'default' => false],
                'enabled'     => ['type' => 'boolean', 'default' => true],
                'notify'      => ['type' => 'boolean', 'default' => true],
            ],
            'required' => ['name', 'mode'],
        ], [self::class, 'task_save'], ['destructiveHint' => true]);

        $r->add('task_run', 'Run a scheduled task immediately (does not change its schedule). Returns the run result/summary.', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ], [self::class, 'task_run'], ['destructiveHint' => true]);

        $r->add('task_delete', 'Delete a scheduled task by ID.', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ], [self::class, 'task_delete'], ['destructiveHint' => true]);

        $r->add('run_agent', 'Run a one-off autonomous task NOW: hand a plain-language instruction to the on-site AI agent, which plans and executes it using Claudpress tools (with policy guardrails), then returns a summary. Requires an agent API key in settings. Use for "just handle X" automations you don\'t want to schedule.', [
            'type' => 'object',
            'properties' => [
                'instruction' => ['type' => 'string'],
                'allow_risky' => ['type' => 'boolean', 'default' => false],
                'max_steps'   => ['type' => 'integer', 'default' => 14],
            ],
            'required' => ['instruction'],
        ], [self::class, 'run_agent'], ['destructiveHint' => true]);

        $r->add('notify_owner', 'Send a notification to the site owner across every configured channel (email, webhook/Slack, Telegram). Use to proactively flag something that needs a human.', [
            'type' => 'object',
            'properties' => [
                'subject' => ['type' => 'string'],
                'message' => ['type' => 'string'],
            ],
            'required' => ['subject', 'message'],
        ], [self::class, 'notify_owner'], ['destructiveHint' => true]);
    }

    public static function task_save(array $a): array
    {
        return Claudpress_Tasks::save($a);
    }

    public static function task_run(array $a): array
    {
        return Claudpress_Tasks::run_task((int) ($a['id'] ?? 0));
    }

    public static function task_delete(array $a): array
    {
        return Claudpress_Tasks::delete((int) ($a['id'] ?? 0));
    }

    public static function run_agent(array $a): array
    {
        return Claudpress_Agent_Runtime::run((string) ($a['instruction'] ?? ''), [
            'allow_risky' => (bool) ($a['allow_risky'] ?? false),
            'max_steps'   => (int) ($a['max_steps'] ?? 14),
            'context'     => 'run_agent',
        ]);
    }

    public static function notify_owner(array $a): array
    {
        $res = Claudpress_Notifier::send((string) ($a['subject'] ?? ''), (string) ($a['message'] ?? ''));
        return ['success' => true, 'channels' => $res];
    }
}
