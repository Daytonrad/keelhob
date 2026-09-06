<?php
/**
 * The admin surface's front door: menus, notices, and the callbacks WordPress
 * holds a reference to.
 *
 * Every screen this plugin draws used to live in this file. They were moved out
 * — Connect, Guide and Activity to class-admin-screens.php, Automation to
 * class-admin-automation.php, the save handler to class-admin-settings.php, and
 * the provider pickers and their endpoints to class-admin-providers.php — but
 * the hook registrations did not move with them, on purpose.
 *
 * What WordPress stores when a page or an action is registered is a callable,
 * and that callable is what a site owner or another plugin later passes to
 * remove_action() or checks against. Keeping every one of them as a method on
 * this class means splitting the UI up changed no callable, so nothing that was
 * hooked to Claudpress_Admin before the split can have quietly stopped working
 * after it. render_skills() has delegated this way since the Skills screen was
 * added; the rest now follow it.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Admin
{
    /** Bump when what the owner is agreeing to materially changes. */
    const CONSENT_VERSION = 1;

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_claudpress_save', [$this, 'handle_save']);
        add_action('admin_bar_menu', [$this, 'admin_bar'], 100);
        add_action('admin_notices', [$this, 'notice']);
        add_action('admin_notices', [$this, 'upgrade_notice']);
        // Both of these accept an API key so an unsaved one can be tested;
        // see guard_provider_ajax() for why that changes what they must check.
        add_action('wp_ajax_claudpress_fetch_models', [$this, 'ajax_fetch_models']);
        add_action('wp_ajax_claudpress_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_claudpress_test_media', [$this, 'ajax_test_media']);
        add_action('wp_ajax_claudpress_fetch_media_models', [$this, 'ajax_fetch_media_models']);
    }

    public function menu(): void
    {
        add_menu_page('Claudpress', 'Claudpress', 'manage_options', 'claudpress', [$this, 'render'], 'dashicons-superhero', 58);
        add_submenu_page('claudpress', __('Connect', 'claudpress'), __('Connect', 'claudpress'), 'manage_options', 'claudpress', [$this, 'render']);
        // Directly after Connect: this is the primary "what has the AI done to
        // my site" surface, and it must be easy to find in a hurry.
        // Skills sit next to Snippets for the same reason: both answer "what is
        // this agent actually working from?", and the Pro list on that screen is
        // the only honest place to say what expertise exists but is not here.
        add_submenu_page('claudpress', __('Skills', 'claudpress'), __('Skills', 'claudpress'), 'manage_options', 'claudpress-skills', [$this, 'render_skills']);
        add_submenu_page('claudpress', __('Automation', 'claudpress'), __('Automation', 'claudpress'), 'manage_options', 'claudpress-automation', [$this, 'render_automation']);
        add_submenu_page('claudpress', __('Guide', 'claudpress'), __('Guide', 'claudpress'), 'manage_options', 'claudpress-guide', [$this, 'render_guide']);
        add_submenu_page('claudpress', __('Activity', 'claudpress'), __('Activity', 'claudpress'), 'manage_options', 'claudpress-activity', [$this, 'render_activity']);

        // Last in the list on purpose: it is an ask, not a feature, and it
        // should never sit above something someone came here to do.
        Claudpress_Donate::menu();
    }

    public function notice(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'claudpress') === false) {
            return;
        }
        if (Claudpress::instance()->abilities_enabled()) {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Claudpress AI abilities are ON.', 'claudpress'),
                esc_html__('A connected AI agent can read and modify everything on this site. Use development/staging sites only.', 'claudpress')
            );
        }
    }

    /**
     * Tell the owner what the paid-tier merge switched off, once, on any
     * admin screen.
     *
     * The upgrade forces eight capability groups off, because until the merge a
     * paywall was the only thing standing between a connected agent and this
     * site's users, gateways, outbound email and production deploys. That is
     * the right default, but doing it silently would mean an owner discovering
     * by accident that automations they relied on had stopped.
     *
     * Deliberately not limited to Claudpress screens, and deliberately not
     * auto-dismissed on view: someone who never opens the plugin's own pages is
     * exactly the person who most needs to read this.
     */
    public function upgrade_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $reset = get_option('claudpress_paid_tier_reset', '');
        if ($reset === '' || $reset === 'dismissed' || $reset === 'none') {
            return;
        }

        if (!empty($_GET['claudpress_ack_paid_tier']) && check_admin_referer('claudpress_ack_paid_tier')) {
            update_option('claudpress_paid_tier_reset', 'dismissed');
            return;
        }

        $groups = array_filter(array_map('trim', explode(',', (string) $reset)));
        $labels = [];
        $all    = Claudpress_Capabilities::groups();
        foreach ($groups as $group) {
            $labels[] = (string) ($all[$group]['label'] ?? $group);
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong></p><p>%s</p><p><strong>%s</strong></p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p></div>',
            esc_html__('Claudpress is free now, and some capabilities were switched off.', 'claudpress'),
            esc_html__('Every paid feature is included at no cost. Because the old paid tier was also the only thing keeping the riskiest tools out of reach, these areas now start switched OFF, and you decide which to turn back on:', 'claudpress'),
            esc_html(implode(' · ', $labels)),
            esc_url(admin_url('admin.php?page=claudpress&tab=settings')),
            esc_html__('Review capabilities', 'claudpress'),
            esc_url(wp_nonce_url(add_query_arg('claudpress_ack_paid_tier', '1'), 'claudpress_ack_paid_tier')),
            esc_html__('Got it', 'claudpress')
        );
    }

    public function admin_bar($bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $on = Claudpress::instance()->abilities_enabled();
        $bar->add_node([
            'id'    => 'claudpress',
            'title' => 'Claudpress: ' . ($on ? __('🟢 ON', 'claudpress') : __('⚪ OFF', 'claudpress')),
            'href'  => admin_url('admin.php?page=claudpress'),
        ]);
    }

    // --- Screen and endpoint callbacks --------------------------------------
    //
    // Each one is the callable registered above, and each one does nothing but
    // hand off to the class that owns the code. They are instantiated here
    // rather than in hooks() so that loading the admin does not construct four
    // screens to render one; the autoloader does not even open the file for a
    // screen nobody asked for.

    public function render_skills(): void
    {
        (new Claudpress_Admin_Skills())->render();
    }

    public function render(): void
    {
        (new Claudpress_Admin_Screens())->render();
    }

    public function render_automation(): void
    {
        (new Claudpress_Admin_Automation())->render_automation();
    }

    public function render_guide(): void
    {
        (new Claudpress_Admin_Screens())->render_guide();
    }

    public function render_activity(): void
    {
        (new Claudpress_Admin_Screens())->render_activity();
    }

    public function handle_save(): void
    {
        (new Claudpress_Admin_Settings())->handle_save();
    }

    public function ajax_fetch_models(): void
    {
        (new Claudpress_Admin_Providers())->ajax_fetch_models();
    }

    public function ajax_test_connection(): void
    {
        (new Claudpress_Admin_Providers())->ajax_test_connection();
    }

    public function ajax_test_media(): void
    {
        (new Claudpress_Admin_Providers())->ajax_test_media();
    }

    public function ajax_fetch_media_models(): void
    {
        (new Claudpress_Admin_Providers())->ajax_fetch_media_models();
    }

    /**
     * True when the owner has not accepted the CURRENT terms.
     *
     * Used to re-show the consent block prominently after an upgrade that
     * widened what the agent may do.
     */
    public static function consent_is_stale(): bool
    {
        $s = Claudpress::instance()->settings();
        if (empty($s['abilities_enabled'])) {
            return false;
        }
        return (int) ($s['consent_version'] ?? 0) < self::CONSENT_VERSION;
    }
}
