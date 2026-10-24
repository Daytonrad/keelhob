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

final class Keelhob_Capabilities
{
    /**
     * Capability groups that ship SWITCHED OFF, and why that list exists.
     *
     * These eight used to be unreachable for a different reason: they were
     * behind a paywall, and the paywall happened to be acting as a safety
     * fence. Removing the paywall without replacing the fence would have handed
     * every fresh install - and worse, every site that merely *upgraded* - the
     * ability to email customers, edit users, change payment gateways and push
     * to production, silently, on an auto-update.
     *
     * So the fence is explicit now, and it is here rather than in the settings
     * defaults because two lists drift, and the way this one would drift is by
     * switching send_email on for everybody.
     *
     * Everything not named here is on: content, design, snippets, SEO, files,
     * images, memory, skills and code execution are what "build me a site"
     * needs, and asking permission for those would make the product pointless.
     */
    const DISABLED_BY_DEFAULT = [
        'store_orders',
        'payments',
        'shipping',
        'tax',
        'users',
        'updates',
        'comms',
        'deploy',
    ];

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
        return ['get_site_info', 'get_dashboard', 'memory_list', 'memory_get', 'skill_list', 'skill_get', 'skill_lint', 'task_list', 'agent_runs', 'site_readiness', 'feedback_status', 'feedback_send', 'lesson_list'];
    }

    /**
     * Capability group definitions.
     * @return array<string, array{label:string, desc:string, sensitive:bool, tools:string[]}>
     */
    public static function groups(): array
    {
        return [
            'content' => [
                'label' => 'Content', 'sensitive' => false,
                'desc' => 'Create, edit and DELETE posts, pages, media and taxonomies.',
                'tools' => ['create_post', 'update_post', 'delete_post', 'get_post', 'list_posts', 'upload_media', 'manage_taxonomy'],
            ],
            // Building a store and running one are different jobs. Products,
            // coupons and settings are what the ten-minute build needs, so they
            // are on. Orders, reports and money are what a live business
            // depends on, so those start switched off - see
            // DISABLED_BY_DEFAULT.
            'store' => [
                'label' => 'Store (products & catalogue)', 'sensitive' => true,
                'desc' => 'WooCommerce products, coupons and store settings.',
                'tools' => ['wc_product', 'wc_coupon', 'wc_settings'],
            ],
            'store_orders' => [
                'label' => 'Store (orders & reports)', 'sensitive' => true,
                'desc' => 'Read and refund orders, and read sales reports.',
                'tools' => ['wc_order', 'wc_report'],
            ],
            'payments' => [
                'label' => 'Payments (money)', 'sensitive' => true,
                'desc' => 'Enable and configure payment gateways and keys.',
                'tools' => ['wc_payments'],
            ],
            'shipping' => [
                'label' => 'Shipping', 'sensitive' => true,
                'desc' => 'Shipping zones, methods, and rates.',
                'tools' => ['wc_shipping'],
            ],
            'tax' => [
                'label' => 'Tax', 'sensitive' => true,
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
                'label' => 'SEO setup', 'sensitive' => false,
                'desc' => 'Meta titles and descriptions, JSON-LD schema, the sitemap, robots.txt, llms.txt, and which AI crawlers may read this site.',
                'tools' => ['seo_meta', 'seo_schema', 'seo_sitemap'],
            ],
            'seo_optimize' => [
                'label' => 'SEO optimization', 'sensitive' => false,
                'desc' => 'Score pages against a target keyword, and manage redirects when URLs change.',
                'tools' => ['seo_analyze', 'seo_redirect'],
            ],
            // Site care is the looking-after half of the product, and every
            // tool in it is a report: it reads, counts, ranks and changes
            // nothing. That is why it is not sensitive and ships on - an owner
            // who cannot ask "why is my site slow" without first finding a
            // switch has a worse product, and there is nothing here to switch
            // off for safety. The tools that ACT on these findings
            // (image_compress, db_cleanup, link_fix, media_set_alt,
            // plugin_install) are a separate group and a separate decision,
            // because db_cleanup in particular cannot be undone.
            'sitecare' => [
                'label' => 'Site care (read-only reports)', 'sensitive' => false,
                'desc' => 'Speed audit, database-junk counts, plugin security and conflict reports, and a broken-link scan. Reads only — none of these changes anything on the site.',
                'tools' => ['speed_audit', 'db_cleanup_preview', 'plugin_security_check', 'plugin_conflicts', 'broken_links'],
            ],
            'design' => [
                'label' => 'Design / Elementor', 'sensitive' => false,
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
                'label' => 'Snippets', 'sensitive' => true,
                'desc' => 'Create, edit, switch on and delete live code snippets (PHP, CSS, JS, HTML) that run on your site.',
                'tools' => ['snippet_save', 'snippet_list', 'snippet_get', 'snippet_revisions', 'snippet_toggle', 'snippet_delete'],
            ],
            'code_exec' => [
                'label' => 'Raw code execution', 'sensitive' => true,
                'desc' => 'Run arbitrary PHP and SQL directly.',
                'tools' => ['execute_php', 'execute_sql'],
            ],
            'files' => [
                'label' => 'Filesystem', 'sensitive' => true,
                'desc' => 'Browse, read, write and delete files anywhere under the site root, and add PHP that runs on every request.',
                'tools' => ['read_file', 'write_file', 'edit_file', 'delete_file', 'list_directory', 'write_sandbox_file'],
            ],
            'users' => [
                'label' => 'Users', 'sensitive' => true,
                'desc' => 'Create/edit/delete user accounts and roles.',
                'tools' => ['manage_user'],
            ],
            'site_admin' => [
                'label' => 'Site admin (plugins/themes/options)', 'sensitive' => true,
                'desc' => 'Options, plugins, themes, menus.',
                'tools' => ['manage_option', 'manage_plugin', 'manage_theme', 'manage_menu'],
            ],
            'updates' => [
                'label' => 'Updates', 'sensitive' => true,
                'desc' => 'Update WordPress core, plugins, and themes.',
                'tools' => ['manage_updates'],
            ],
            'images' => [
                'label' => 'Images', 'sensitive' => false,
                'desc' => 'Search (Openverse) and generate images.',
                'tools' => ['image_search', 'image_generate'],
            ],
            'comms' => [
                'label' => 'Email & notifications', 'sensitive' => true,
                'desc' => 'Send email and owner notifications.',
                'tools' => ['send_email', 'notify_owner'],
            ],
            'automation' => [
                'label' => 'Autonomy (agent & scheduled tasks)', 'sensitive' => true,
                'desc' => 'Run the agent unattended, and create, run and delete scheduled tasks that act with no human watching.',
                'tools' => ['run_agent', 'task_save', 'task_run', 'task_delete'],
            ],
            'memory' => [
                'label' => 'Memory & lessons (write)', 'sensitive' => false,
                'desc' => 'Save/delete cross-session memory, and save lessons that are injected into the tool descriptions the agent reads. The owner can read, edit and delete every lesson on Keelhob > Activity.',
                'tools' => ['memory_save', 'memory_delete', 'lesson_save'],
            ],
            'skills' => [
                'label' => 'Skills (write)', 'sensitive' => false,
                'desc' => 'Create and delete agent skills, and download the bundled ones from this project over the internet — which can overwrite skills you have edited.',
                'tools' => ['skill_save', 'skill_delete', 'skill_refresh'],
            ],
            'deploy' => [
                'label' => 'Deploy to production', 'sensitive' => true,
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
        $caps = Keelhob::instance()->get('capabilities', []);
        if (!is_array($caps) || !array_key_exists($group, $caps)) {
            return self::default_enabled($group);
        }
        return (bool) $caps[$group];
    }

    /**
     * Whether a tool may run.
     *
     * One gate: the owner's toggle. There used to be a second one in front of
     * it deciding whether this install had the feature at all; every install
     * has every feature now.
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
        return self::is_group_enabled($group);
    }
    /** Whether a group is on when the owner has never said either way. */
    public static function default_enabled(string $group): bool
    {
        return !in_array($group, self::DISABLED_BY_DEFAULT, true);
    }

    /**
     * Groups that are switched off, described for a connected agent.
     *
     * Handing an agent a factual list of what it cannot do, and the name of the
     * switch that would fix it, is cheaper for everyone than letting it find
     * out by trying: it stops the retry loops, and it stops the agent telling
     * the owner their site is broken when they simply left something off.
     *
     * This used to report which features were missing because they were part of
     * a paid add-on. The reason changed; the courtesy did not.
     *
     * @return array<int, array{name:string,label:string,what_it_does:string}>
     */
    public static function disabled_groups(): array
    {
        $out = [];
        foreach (self::groups() as $key => $group) {
            if (self::is_group_enabled($key)) {
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
