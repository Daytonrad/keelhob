<?php
/**
 * Capability toggles. Groups every tool into a capability the owner can switch
 * on or off from the dashboard, so they decide exactly what the agent may touch
 * — especially sensitive areas like money (payments/orders), shipping, code,
 * files, users, and production deploys.
 *
 * When a capability is OFF: its tools are hidden from tools/list (so the agent
 * never sees them), removed from the autonomous agent's toolset, blocked if
 * somehow called, and named as off-limits in the session instructions.
 *
 * A short always-on read-only set (site info, dashboard, memory/skill/task
 * listing) is never gated, so the owner can always ask "what's going on".
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Capabilities
{
    const TIER_FREE = 'free';
    const TIER_PRO  = 'pro';

    /**
     * Read-only introspection that is always available regardless of toggles.
     *
     * `site_readiness` belongs here even though it touches SEO/security/store
     * data: it never writes anything, and it is the exit code the
     * `build-standard` skill checks against, so it must work even with every
     * other capability restricted.
     */
    public static function always_on(): array
    {
        return ['get_site_info', 'get_dashboard', 'memory_list', 'memory_get', 'skill_list', 'skill_get', 'task_list', 'agent_runs', 'site_readiness'];
    }

    /**
     * Capability group definitions.
     * @return array<string, array{label:string, desc:string, sensitive:bool, tools:string[]}>
     */
    public static function groups(): array
    {
        return [
            'content' => [
                'label' => 'Content', 'sensitive' => false, 'tier' => self::TIER_FREE,
                'desc' => 'Create/edit posts, pages, media, taxonomies.',
                'tools' => ['create_post', 'update_post', 'delete_post', 'get_post', 'list_posts', 'upload_media', 'manage_taxonomy'],
            ],
            // Building a store and running one are different jobs, and the
            // line between them is the cleanest paywall in the product.
            // Products, coupons and settings are what the ten-minute demo needs,
            // so they are free. Orders and reports only exist once real
            // customers are paying, which means the person who hits that wall is
            // by definition earning money from the plugin: it never blocks a
            // beginner and always catches a business.
            'store' => [
                'label' => 'Store (products & catalogue)', 'sensitive' => true, 'tier' => self::TIER_FREE,
                'desc' => 'WooCommerce products, coupons and store settings.',
                'tools' => ['wc_product', 'wc_coupon', 'wc_settings'],
            ],
            'store_orders' => [
                'label' => 'Store (orders & reports)', 'sensitive' => true, 'tier' => self::TIER_PRO,
                'desc' => 'Read and refund orders, and read sales reports.',
                'tools' => ['wc_order', 'wc_report'],
            ],
            'payments' => [
                'label' => 'Payments (money)', 'sensitive' => true, 'tier' => self::TIER_PRO,
                'desc' => 'Enable and configure payment gateways and keys.',
                'tools' => ['wc_payments'],
            ],
            'shipping' => [
                'label' => 'Shipping', 'sensitive' => true, 'tier' => self::TIER_PRO,
                'desc' => 'Shipping zones, methods, and rates.',
                'tools' => ['wc_shipping'],
            ],
            'tax' => [
                'label' => 'Tax', 'sensitive' => true, 'tier' => self::TIER_PRO,
                'desc' => 'Tax settings and rates.',
                'tools' => ['wc_tax'],
            ],
            // Setting a site up to be found and improving it afterwards are two
            // different jobs. Titles, descriptions, schema and a sitemap are part
            // of building the site, so they are free - a page nobody can index is
            // not finished. Scoring a page against a target keyword and managing
            // redirects is the loop you run forever after launch, which is what a
            // subscription is actually for.
            'seo' => [
                'label' => 'SEO setup', 'sensitive' => false, 'tier' => self::TIER_FREE,
                'desc' => 'Meta titles and descriptions, JSON-LD schema, and the sitemap.',
                'tools' => ['seo_meta', 'seo_schema', 'seo_sitemap'],
            ],
            'seo_optimize' => [
                'label' => 'SEO optimization', 'sensitive' => false, 'tier' => self::TIER_PRO,
                'desc' => 'Score pages against a target keyword, and manage redirects when URLs change.',
                'tools' => ['seo_analyze', 'seo_redirect'],
            ],
            'design' => [
                'label' => 'Design / Elementor', 'sensitive' => false, 'tier' => self::TIER_FREE,
                'desc' => 'Build and edit Elementor pages, kit, settings.',
                'tools' => ['elementor_page', 'elementor_settings', 'elementor_kit'],
            ],
            // Snippets and raw execution used to share one group. They are
            // different things with different blast radii: a snippet is stored,
            // named, syntax-checked before it can activate, and switched off
            // automatically if it fatals, whereas execute_php runs a string
            // once with none of that around it. An owner who wants the agent
            // maintaining snippets but never running loose PHP could not
            // express that before.
            'snippets' => [
                'label' => 'Snippets', 'sensitive' => true, 'tier' => self::TIER_FREE,
                'desc' => 'Create and manage live code snippets (PHP, CSS, JS, HTML).',
                'tools' => ['snippet_save', 'snippet_list', 'snippet_get', 'snippet_toggle', 'snippet_delete'],
            ],
            'code_exec' => [
                'label' => 'Raw code execution', 'sensitive' => true, 'tier' => self::TIER_FREE,
                'desc' => 'Run arbitrary PHP and SQL directly.',
                'tools' => ['execute_php', 'execute_sql'],
            ],
            'files' => [
                'label' => 'Filesystem', 'sensitive' => true, 'tier' => self::TIER_FREE,
                'desc' => 'Read/write/delete files and sandbox scripts.',
                'tools' => ['read_file', 'write_file', 'edit_file', 'delete_file', 'list_directory', 'write_sandbox_file'],
            ],
            'users' => [
                'label' => 'Users', 'sensitive' => true, 'tier' => self::TIER_PRO,
                'desc' => 'Create/edit/delete user accounts and roles.',
                'tools' => ['manage_user'],
            ],
            'site_admin' => [
                'label' => 'Site admin (plugins/themes/options)', 'sensitive' => true, 'tier' => self::TIER_FREE,
                'desc' => 'Options, plugins, themes, menus.',
                'tools' => ['manage_option', 'manage_plugin', 'manage_theme', 'manage_menu'],
            ],
            'updates' => [
                'label' => 'Updates', 'sensitive' => true, 'tier' => self::TIER_PRO,
                'desc' => 'Update WordPress core, plugins, and themes.',
                'tools' => ['manage_updates'],
            ],
            'images' => [
                'label' => 'Images', 'sensitive' => false, 'tier' => self::TIER_FREE,
                'desc' => 'Search (Openverse) and generate images.',
                'tools' => ['image_search', 'image_generate'],
            ],
            'comms' => [
                'label' => 'Email & notifications', 'sensitive' => true, 'tier' => self::TIER_PRO,
                'desc' => 'Send email and owner notifications.',
                'tools' => ['send_email', 'notify_owner'],
            ],
            'automation' => [
                'label' => 'Autonomy (agent & scheduled tasks)', 'sensitive' => true, 'tier' => self::TIER_PRO,
                'desc' => 'Run the autonomous agent and create/run scheduled tasks.',
                'tools' => ['run_agent', 'task_save', 'task_run', 'task_delete', 'agent_runs'],
            ],
            'memory' => [
                'label' => 'Memory (write)', 'sensitive' => false, 'tier' => self::TIER_FREE,
                'desc' => 'Save/delete cross-session memory.',
                'tools' => ['memory_save', 'memory_delete'],
            ],
            'skills' => [
                'label' => 'Skills (write)', 'sensitive' => false, 'tier' => self::TIER_FREE,
                'desc' => 'Create/delete agent skills.',
                'tools' => ['skill_save', 'skill_delete', 'skill_refresh'],
            ],
            'deploy' => [
                'label' => 'Deploy to production', 'sensitive' => true, 'tier' => self::TIER_PRO,
                'desc' => 'Export/import bundles and push to the production site.',
                'tools' => ['export_bundle', 'import_bundle', 'deploy_push'],
            ],
        ];
    }

    /** Reverse map tool → group key (built once). */
    public static function tool_group(string $tool): ?string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::groups() as $key => $g) {
                foreach ($g['tools'] as $t) {
                    $map[$t] = $key;
                }
            }
        }
        return $map[$tool] ?? null;
    }

    public static function is_group_enabled(string $group): bool
    {
        $caps = Claudpress::instance()->get('capabilities', []);
        if (!is_array($caps) || !array_key_exists($group, $caps)) {
            return true; // default on
        }
        return (bool) $caps[$group];
    }

    /**
     * Whether a tool may run.
     *
     * Two independent gates, and the order matters. The tier decides whether
     * this install has the feature at all; the owner's toggle decides whether
     * they want it switched on. A Pro tool on a free site fails the first gate
     * no matter what the toggles say.
     *
     * Ungrouped and always-on tools are allowed: third-party plugins register
     * tools through the same registry, and refusing anything unrecognised would
     * silently break them.
     */
    public static function tool_allowed(string $tool): bool
    {
        if (in_array($tool, self::always_on(), true)) {
            return true;
        }
        $group = self::tool_group($tool);
        if ($group === null) {
            return true;
        }
        return self::tier_allows($group) && self::is_group_enabled($group);
    }
    // ------------------------------------------------------------------------
    // Tiering
    //
    // Read this before relying on any of it:  is a USER-EXPERIENCE
    // gate, not a security boundary, and it must never be mistaken for one.
    //
    // The free plugin ships execute_php, execute_sql, the filesystem tools and
    // the snippet engine. Any one of those gives a connected agent the ability
    // to run arbitrary PHP on the site - which means it can also flip whatever
    // option this function reads. Nothing that lives on the user's own disk can
    // be defended from the user; that is not a flaw in the design, it follows
    // from what the product is.
    //
    // What actually separates the tiers is that Pro code is in a SEPARATE ZIP a
    // free user never receives, and that the features worth paying for - the
    // private library, cross-site sync, the Pro skill feed - are answered by our
    // server, where a local edit has no vote. So: use this to keep honest users
    // out of broken states, and never put anything in the free zip that would
    // matter if it were switched on.
    // ------------------------------------------------------------------------

    /** True when the paid add-on is installed and holds a valid licence. */
    public static function is_pro(): bool
    {
        /**
         * The add-on answers this filter. With the add-on absent - the normal
         * case - nothing hooks it and every Pro group stays off.
         */
        return (bool) apply_filters('claudpress_is_pro', false);
    }

    /** free | pro for a group; unknown groups are treated as free. */
    public static function group_tier(string $group): string
    {
        $groups = self::groups();
        return (string) ($groups[$group]['tier'] ?? self::TIER_FREE);
    }

    /** Whether this install may use the group at all, before the owner's toggle. */
    public static function tier_allows(string $group): bool
    {
        return self::group_tier($group) === self::TIER_FREE || self::is_pro();
    }

    /**
     * Pro groups this site does not have, described for a connected agent.
     *
     * Handing an agent a factual list of what is missing and why is cheaper for
     * everyone than letting it discover the same thing by trying: it stops the
     * retry loops, and it stops the agent telling the owner the site is broken
     * when it is simply not subscribed.
     *
     * @return array<int, array{name:string,label:string,what_it_does:string}>
     */
    public static function unavailable_groups(): array
    {
        if (self::is_pro()) {
            return [];
        }
        $out = [];
        foreach (self::groups() as $key => $group) {
            if (($group['tier'] ?? self::TIER_FREE) !== self::TIER_PRO) {
                continue;
            }
            $out[] = [
                'name'         => $key,
                'label'        => (string) $group['label'],
                'what_it_does' => (string) $group['desc'],
            ];
        }
        return $out;
    }

    /** Every tool belonging to a Pro group, for filtering the registry. */
    public static function pro_tools(): array
    {
        $out = [];
        foreach (self::groups() as $group) {
            if (($group['tier'] ?? self::TIER_FREE) === self::TIER_PRO) {
                $out = array_merge($out, $group['tools']);
            }
        }
        return $out;
    }


    /**
     * Tools a strictly read-only actor may use — the `viewer` Telegram role.
     *
     * Derived from each tool's own readOnlyHint annotation rather than a second
     * hand-maintained list that would drift, minus an explicit exclusion list.
     * Those exclusions are not oversights:
     *   - read_file / list_directory read *anything* under the filesystem base,
     *     and wp-config.php is under it. "Read-only" and "can read your database
     *     password" are not the same claim.
     *   - export_bundle copies the site's content out wholesale. It mutates
     *     nothing and is still not something to hand a viewer.
     *
     * @param array<int, array{name: string, annotations?: array}> $tools registry->list()
     * @return array<int, string>
     */
    public static function viewer_tools(array $tools): array
    {
        $never = ['read_file', 'list_directory', 'export_bundle'];
        $out   = [];

        foreach ($tools as $t) {
            $name = (string) ($t['name'] ?? '');
            if ($name === '' || in_array($name, $never, true)) {
                continue;
            }
            if (($t['annotations']['readOnlyHint'] ?? null) === true && self::tool_allowed($name)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** Human-readable labels of disabled groups, for the session instructions. */
    public static function disabled_labels(): array
    {
        $out = [];
        foreach (self::groups() as $key => $g) {
            if (!self::is_group_enabled($key)) {
                $out[] = $g['label'];
            }
        }
        return $out;
    }
}
