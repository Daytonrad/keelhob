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
 * hooked to Keelhob_Admin before the split can have quietly stopped working
 * after it. render_skills() has delegated this way since the Skills screen was
 * added; the rest now follow it.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Admin
{
    /** Bump when what the owner is agreeing to materially changes. */
    const CONSENT_VERSION = 1;

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        // Decoration only, and admin-only: the front end never loads it.
        Keelhob_Brand::hooks();
        add_action('admin_post_keelhob_save', [$this, 'handle_save']);
        add_action('admin_bar_menu', [$this, 'admin_bar'], 100);
        // Runs LAST, after anything that could remove the node. See chip_watchdog().
        add_action('admin_bar_menu', [$this, 'chip_watchdog'], PHP_INT_MAX);
        add_action('admin_notices', [$this, 'notice']);
        add_action('admin_notices', [$this, 'upgrade_notice']);
        // Both of these accept an API key so an unsaved one can be tested;
        // see guard_provider_ajax() for why that changes what they must check.
        add_action('wp_ajax_keelhob_fetch_models', [$this, 'ajax_fetch_models']);
        add_action('wp_ajax_keelhob_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_keelhob_test_media', [$this, 'ajax_test_media']);
        add_action('wp_ajax_keelhob_fetch_media_models', [$this, 'ajax_fetch_media_models']);
    }

    public function menu(): void
    {
        // The brand mark, not a dashicon. Keelhob_Brand::styles() undims it:
        // WordPress assumes a menu image is a monochrome glyph and fades it.
        add_menu_page('Keelhob', 'Keelhob', 'manage_options', 'keelhob', [$this, 'render'], Keelhob_Brand::menu_icon(), 58);
        add_submenu_page('keelhob', __('Connect', 'keelhob'), __('Connect', 'keelhob'), 'manage_options', 'keelhob', [$this, 'render']);
        // Directly after Connect: this is the primary "what has the AI done to
        // my site" surface, and it must be easy to find in a hurry.
        // Skills sit next to Snippets for the same reason: both answer "what is
        // this agent actually working from?", and the Pro list on that screen is
        // the only honest place to say what expertise exists but is not here.
        add_submenu_page('keelhob', __('Skills', 'keelhob'), __('Skills', 'keelhob'), 'manage_options', 'keelhob-skills', [$this, 'render_skills']);
        add_submenu_page('keelhob', __('Automation', 'keelhob'), __('Automation', 'keelhob'), 'manage_options', 'keelhob-automation', [$this, 'render_automation']);
        add_submenu_page('keelhob', __('Guide', 'keelhob'), __('Guide', 'keelhob'), 'manage_options', 'keelhob-guide', [$this, 'render_guide']);
        add_submenu_page('keelhob', __('Activity', 'keelhob'), __('Activity', 'keelhob'), 'manage_options', 'keelhob-activity', [$this, 'render_activity']);

        // Last in the list on purpose: it is an ask, not a feature, and it
        // should never sit above something someone came here to do.
        Keelhob_Donate::menu();
    }

    /**
     * The meta key that remembers one administrator's dismissal.
     *
     * PER USER, NOT PER SITE, and that is not a detail. One administrator
     * hiding a safety warning must not hide it from a colleague who has never
     * seen it and does not know an AI agent is attached to this site.
     */
    const HIDE_NOTICE_META = 'keelhob_hide_abilities_notice';

    /**
     * "AI abilities are ON", on Keelhob screens, and now dismissible.
     *
     * WHY IT IS SAFE TO LET SOMEBODY HIDE THIS. Because there is a second
     * indicator that cannot be hidden: admin_bar() puts `Keelhob: ON` in the
     * WordPress toolbar, and that is a better warning than this banner - always
     * present, never dismissible, and on screens this banner never reaches.
     *
     * The obvious objection is that a snippet can remove the toolbar. It was
     * measured rather than argued: a live snippet running
     * add_filter('show_admin_bar', '__return_false') removed the toolbar on the
     * FRONT END and left it completely intact inside wp-admin, because
     * WordPress forces it there and show_admin_bar does not apply. This banner
     * only ever appears inside wp-admin, so the chip is guaranteed exactly
     * where the banner lives.
     *
     * That measurement is the whole argument. Without the chip, the answer to
     * "can I hide this?" is no.
     *
     * THE HOLE THE MEASUREMENT LEFT. A snippet could still call
     * remove_node('keelhob'), or inject admin CSS hiding #wpadminbar.
     *
     * The intended guard was to ask the toolbar, here, whether our node exists.
     * That does not work, and the browser layer is how we found out: at
     * admin_notices the toolbar has not been built, so `get_node('keelhob')`
     * returns null on EVERY request. A probe on the rig reported
     * `nodes=0 keelhob=no` on a page whose toolbar rendered correctly
     * moments later. Shipped as written, it would have removed the dismiss
     * option entirely while reading like a careful safety check.
     *
     * So the hole is closed one page load later instead, by chip_watchdog() -
     * which runs on admin_bar_menu at PHP_INT_MAX, after anything that could
     * remove the node, and clears the dismissal when the node is gone. Stated
     * plainly: a removed chip costs one more banner-free page, not every
     * subsequent one.
     */
    public function notice(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'keelhob') === false) {
            return;
        }
        if (!Keelhob::instance()->abilities_enabled()) {
            return;
        }

        if (!empty($_GET['keelhob_hide_abilities']) && check_admin_referer('keelhob_hide_abilities')) {
            update_user_meta(get_current_user_id(), self::HIDE_NOTICE_META, self::notice_fingerprint());
        }

        $hideable = self::chip_present();
        if ($hideable && (string) get_user_meta(get_current_user_id(), self::HIDE_NOTICE_META, true) === self::notice_fingerprint()) {
            return;
        }

        printf(
            '<div class="notice notice-warning keelhob-abilities-notice"><p><strong>%s</strong> %s%s</p></div>',
            esc_html__('Keelhob AI abilities are ON.', 'keelhob'),
            esc_html__('A connected AI agent can read and modify everything on this site. Use development/staging sites only.', 'keelhob'),
            $hideable ? self::hide_link() : ''
        );
    }

    /** The "hide this" link. A GET with a nonce; it changes nothing but one user's preference. */
    private static function hide_link(): string
    {
        return sprintf(
            ' <a href="%s" id="kh-hide-abilities-notice" style="margin-left:8px;">%s</a><br><span style="color:#646970;font-size:12px;">%s</span>',
            esc_url(wp_nonce_url(add_query_arg('keelhob_hide_abilities', '1'), 'keelhob_hide_abilities')),
            esc_html__('Hide this notice', 'keelhob'),
            esc_html__('The toolbar will still show "Keelhob: ON" on every screen, so the site never stops telling you.', 'keelhob')
        );
    }

    /**
     * What this dismissal was about.
     *
     * A warning silenced about one situation must not stay silent about a
     * different one. The fingerprint covers the three things that change what
     * an agent is actually allowed to do here:
     *
     *   - whether abilities are on at all (so off-and-on-again re-warns)
     *   - which capability groups are enabled (so switching on Payments re-warns)
     *   - the plugin version (so an update that changes the rules re-warns)
     *
     * Public because tests/rig/ui/check-snippets.mjs sets the meta directly to
     * assert the banner returns; a private fingerprint could only be tested by
     * reimplementing it, which would test the copy rather than the code.
     */
    public static function notice_fingerprint(): string
    {
        $enabled = [];
        foreach (array_keys(Keelhob_Capabilities::groups()) as $group) {
            if (Keelhob_Capabilities::is_group_enabled($group)) {
                $enabled[] = $group;
            }
        }
        sort($enabled);

        return substr(md5((string) wp_json_encode([
            'on'      => Keelhob::instance()->abilities_enabled(),
            'groups'  => $enabled,
            'version' => KEELHOB_VERSION,
        ])), 0, 16);
    }

    /**
     * A change to what an agent may do cancels every dismissal, for everybody.
     *
     * WHY THE FINGERPRINT WAS NOT ENOUGH, and the browser layer is how this was
     * found. notice_fingerprint() hashes the current state, so switching
     * abilities OFF and back ON returns the hash to the value it had before -
     * and a dismissal recorded before the cycle still matched afterwards. The
     * banner stayed hidden through exactly the event it most needs to reappear
     * for. A unit test asserting "off then on returns to the first value" had
     * been written, passed, and described that bug as if it were the feature.
     *
     * So the clearing is driven by the CHANGE, not by the resulting state. Any
     * write to the settings option that alters abilities or the capability
     * groups deletes every stored dismissal.
     *
     * EVERY USER, not just the one who made the change. The person who turns
     * abilities on is rarely the only administrator, and the colleague who
     * dismissed this warning last month agreed to a different site.
     *
     * REGISTERED FROM Keelhob::boot(), NOT from hooks(), and static for that
     * reason. hooks() only runs behind is_admin(), so hooking it there meant a
     * change made over WP-CLI, over the REST route, or by an agent through
     * manage_option left every dismissal standing - which is to say it did not
     * fire for any of the callers most likely to switch abilities on. Found by
     * the browser checks, which toggle abilities through WP-CLI.
     */
    public static function rewarn_on_change($old, $new): void
    {
        $old = is_array($old) ? $old : [];
        $new = is_array($new) ? $new : [];

        $shape = static function (array $s): string {
            $caps = (array) ($s['capabilities'] ?? []);
            ksort($caps);
            return (string) wp_json_encode([!empty($s['abilities_enabled']), $caps]);
        };

        if ($shape($old) === $shape($new)) {
            return;
        }

        // $delete_all = true. One query, and the alternative - looping every
        // administrator - is slower and misses anyone whose role changed since.
        delete_metadata('user', 0, self::HIDE_NOTICE_META, '', true);
    }

    /**
     * Will the unhideable indicator be on this page?
     *
     * THE OBVIOUS IMPLEMENTATION DOES NOT WORK, and it was measured rather than
     * reasoned about. Asking `$GLOBALS['wp_admin_bar']->get_node('keelhob')`
     * from `admin_notices` returns null on every request: the toolbar has not
     * been built yet at that point. A probe printed at admin_notices on the rig
     * reported `nodes=0 keelhob=no` on a page whose toolbar rendered
     * perfectly a few lines later.
     *
     * Shipped as written, that guard would have permanently removed the dismiss
     * option while looking like a careful safety check. It is exactly the class
     * of bug the browser layer exists for.
     *
     * So this asks the two conditions admin_bar() itself checks, which ARE
     * knowable here. What it cannot see is a snippet that removes the node
     * later in the same request - and node_removed() below covers that, one
     * page load afterwards.
     */
    private static function chip_present(): bool
    {
        return is_admin_bar_showing() && current_user_can('manage_options');
    }

    /**
     * If something removed the chip, un-dismiss the banner.
     *
     * Registered on `admin_bar_menu` at a very late priority, so it runs after
     * anything that might call remove_node('keelhob') - a snippet, another
     * plugin, a theme. By then the banner for this request has already printed,
     * which is why this cannot prevent one bad page; it makes sure there is not
     * a second one.
     *
     * Deleting the meta rather than storing a "chip missing" flag keeps this
     * self-healing in both directions: whatever removed the chip goes away, the
     * owner simply dismisses the banner again.
     */
    public function chip_watchdog($bar): void
    {
        if (!current_user_can('manage_options') || !is_object($bar) || !method_exists($bar, 'get_node')) {
            return;
        }
        if ($bar->get_node('keelhob') === null) {
            delete_user_meta(get_current_user_id(), self::HIDE_NOTICE_META);
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
     * Deliberately not limited to Keelhob screens, and deliberately not
     * auto-dismissed on view: someone who never opens the plugin's own pages is
     * exactly the person who most needs to read this.
     */
    public function upgrade_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $reset = get_option('keelhob_paid_tier_reset', '');
        if ($reset === '' || $reset === 'dismissed' || $reset === 'none') {
            return;
        }

        if (!empty($_GET['keelhob_ack_paid_tier']) && check_admin_referer('keelhob_ack_paid_tier')) {
            update_option('keelhob_paid_tier_reset', 'dismissed');
            return;
        }

        $groups = array_filter(array_map('trim', explode(',', (string) $reset)));
        $labels = [];
        $all    = Keelhob_Capabilities::groups();
        foreach ($groups as $group) {
            $labels[] = (string) ($all[$group]['label'] ?? $group);
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong></p><p>%s</p><p><strong>%s</strong></p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p></div>',
            esc_html__('Keelhob is free now, and some capabilities were switched off.', 'keelhob'),
            esc_html__('Every paid feature is included at no cost. Because the old paid tier was also the only thing keeping the riskiest tools out of reach, these areas now start switched OFF, and you decide which to turn back on:', 'keelhob'),
            esc_html(implode(' · ', $labels)),
            esc_url(admin_url('admin.php?page=keelhob&tab=settings')),
            esc_html__('Review capabilities', 'keelhob'),
            esc_url(wp_nonce_url(add_query_arg('keelhob_ack_paid_tier', '1'), 'keelhob_ack_paid_tier')),
            esc_html__('Got it', 'keelhob')
        );
    }

    public function admin_bar($bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $on = Keelhob::instance()->abilities_enabled();
        $bar->add_node([
            'id'    => 'keelhob',
            // The mark carries its own inline sizing, so this node stays correct
            // if it is ever registered outside is_admin().
            'title' => Keelhob_Brand::bar_mark()
                . 'Keelhob: ' . ($on ? __('🟢 ON', 'keelhob') : __('⚪ OFF', 'keelhob')),
            'href'  => admin_url('admin.php?page=keelhob'),
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
        (new Keelhob_Admin_Skills())->render();
    }

    public function render(): void
    {
        (new Keelhob_Admin_Screens())->render();
    }

    public function render_automation(): void
    {
        (new Keelhob_Admin_Automation())->render_automation();
    }

    public function render_guide(): void
    {
        (new Keelhob_Admin_Screens())->render_guide();
    }

    public function render_activity(): void
    {
        (new Keelhob_Admin_Screens())->render_activity();
    }

    public function handle_save(): void
    {
        (new Keelhob_Admin_Settings())->handle_save();
    }

    public function ajax_fetch_models(): void
    {
        (new Keelhob_Admin_Providers())->ajax_fetch_models();
    }

    public function ajax_test_connection(): void
    {
        (new Keelhob_Admin_Providers())->ajax_test_connection();
    }

    public function ajax_test_media(): void
    {
        (new Keelhob_Admin_Providers())->ajax_test_media();
    }

    public function ajax_fetch_media_models(): void
    {
        (new Keelhob_Admin_Providers())->ajax_fetch_media_models();
    }

    /**
     * True when the owner has not accepted the CURRENT terms.
     *
     * Used to re-show the consent block prominently after an upgrade that
     * widened what the agent may do.
     */
    public static function consent_is_stale(): bool
    {
        $s = Keelhob::instance()->settings();
        if (empty($s['abilities_enabled'])) {
            return false;
        }
        return (int) ($s['consent_version'] ?? 0) < self::CONSENT_VERSION;
    }
}
