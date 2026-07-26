<?php
/**
 * Policy / guardrails. A single gate that both the interactive MCP dispatcher
 * and the autonomous agent runtime consult before executing a tool. Interactive
 * calls (a human is prompting) get light enforcement; autonomous calls (no human
 * in the loop) get strict enforcement with financial caps, so an unattended
 * agent can run the shop without being able to do something irreversible or
 * expensive without opt-in.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Policy
{
    /** Tools considered high-risk when nobody is watching. Blocked in autonomous runs unless the task opts in. */
    public static function autonomous_denylist(): array
    {
        return [
            'delete_file', 'delete_post', 'manage_user', 'manage_plugin', 'manage_theme',
            'execute_sql', 'wc_order', 'manage_updates', 'snippet_delete', 'run_agent',
        ];
    }

    public static function defaults(): array
    {
        return [
            'enabled'         => true,
            'blocked_tools'   => [],      // hard-blocked everywhere (even interactive)
            'max_refund'      => 0,       // 0 = refunds blocked in autonomous mode
            'require_optin'   => true,    // autonomous denylist requires task allow_risky=true
        ];
    }

    private static function policy(): array
    {
        $p = Claudpress::instance()->get('policy', []);
        return array_merge(self::defaults(), is_array($p) ? $p : []);
    }

    /**
     * @param string $context 'interactive' | 'autonomous'
     * @param array  $task    task options for autonomous runs (e.g. ['allow_risky'=>bool])
     * @return string|null    null = allowed; string = human-readable block reason
     */
    public static function check(string $tool, array $args, string $context = 'interactive', array $task = []): ?string
    {
        $policy = self::policy();
        if (empty($policy['enabled'])) {
            return null;
        }

        // Hard blocks apply everywhere.
        if (in_array($tool, (array) $policy['blocked_tools'], true)) {
            return sprintf('Tool "%s" is blocked by site policy.', $tool);
        }

        if ($context !== 'autonomous') {
            return null; // a human is driving; trust the interactive session
        }

        // ---- autonomous-only rules ----
        $allow_risky = !empty($task['allow_risky']);

        if (!empty($policy['require_optin']) && !$allow_risky && in_array($tool, self::autonomous_denylist(), true)) {
            // Allow the safe sub-actions of otherwise-risky multi-action tools.
            $safe = self::safe_action_for($tool, $args);
            if (!$safe) {
                return sprintf('Autonomous run: "%s" needs allow_risky=true on the task (it can make irreversible or destructive changes).', $tool);
            }
        }

        // Financial cap on refunds regardless of opt-in.
        if ($tool === 'wc_order' && ($args['action'] ?? '') === 'refund') {
            $cap = (float) $policy['max_refund'];
            $amount = (float) ($args['amount'] ?? PHP_INT_MAX);
            if ($cap <= 0) {
                return 'Autonomous run: refunds are disabled by policy (set policy max_refund to allow).';
            }
            if ($amount > $cap) {
                return sprintf('Autonomous run: refund %.2f exceeds the policy cap of %.2f.', $amount, $cap);
            }
        }

        return null;
    }

    /** True when a risky multi-action tool is being used for a clearly read-only/safe action. */
    private static function safe_action_for(string $tool, array $args): bool
    {
        $action = (string) ($args['action'] ?? '');
        $read_actions = [
            'wc_order'       => ['list', 'get'],
            'manage_user'    => ['list', 'get'],
            'manage_plugin'  => ['list'],
            'manage_theme'   => ['list'],
            'manage_updates' => ['check'],
        ];
        if (isset($read_actions[$tool]) && in_array($action, $read_actions[$tool], true)) {
            return true;
        }
        // execute_sql read-only (SELECT/SHOW) is safe.
        if ($tool === 'execute_sql') {
            return (bool) preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', (string) ($args['query'] ?? ''));
        }
        return false;
    }
}
