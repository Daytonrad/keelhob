<?php
/**
 * MCP tools contributed by the Pro add-on.
 *
 * The add-on hangs these off the free core's 'claudpress_register_tools'
 * action, which fires once per request after core registration. That is the
 * whole extension seam — the add-on never edits, wraps or re-declares anything
 * in the free plugin, which is what lets the two ship and update as separate
 * zips.
 *
 * Registration is skipped outright when the licence is invalid, rather than
 * registering a tool that would refuse when called. A listed tool that always
 * fails is worse than an absent one: agents retry what they can see, and each
 * retry costs the owner tokens to be told "no" again. Absent means the agent
 * plans around it the first time.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Pro_Tools
{
    /**
     * @param Claudpress_Tool_Registry $registry
     */
    public static function register($registry): void
    {
        if (!Claudpress_Pro_Licence::is_valid()) {
            return;
        }

        $registry->add(
            'pro_status',
            __('Report the Claudpress Pro licence state for this site. Read-only.', 'claudpress-pro'),
            ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            [self::class, 'pro_status'],
            ['readOnlyHint' => true]
        );
    }

    /**
     * @param array $args
     * @return array
     */
    public static function pro_status(array $args): array
    {
        $summary = Claudpress_Pro_Licence::summary();

        return [
            'success'     => true,
            'plan'        => 'pro',
            'licence'     => [
                'valid'      => $summary['valid'],
                'status'     => $summary['status'],
                // Dates as ISO strings: a raw Unix timestamp is a number an
                // agent will happily report to the owner verbatim.
                'expires_at' => $summary['expires_at'] > 0 ? gmdate('Y-m-d', $summary['expires_at']) : null,
                'grace_days' => $summary['grace_days'],
            ],
            'add_on'      => [
                'version' => CLAUDPRESS_PRO_VERSION,
            ],
        ];
    }
}
