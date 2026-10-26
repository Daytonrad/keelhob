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

    /**
     * The admin colour schemes the panel goes dark for.
     *
     * VERIFIED AGAINST CORE, not guessed. WordPress 7.1.2 registers NINE
     * schemes in register_admin_color_schemes() (wp-includes/general-template.php):
     * modern, fresh, light, blue, midnight, sunrise, ectoplasm, ocean, coffee.
     * The first colour of each is the menu background, and measuring the
     * relative luminance of all nine says EIGHT of them are dark — only `light`
     * (#e5e5e5, luminance .78) is not. Every other one sits between .013 and
     * .050, which is why class-brand.php draws the menu mark in currentColor
     * rather than shipping a white PNG.
     *
     * So "dark menu background" is the wrong test for this list, because it
     * selects almost everything. Two schemes are excluded deliberately:
     *
     *   modern — the DEFAULT. A site that has never opened Users → Profile is
     *            on it, which means it expresses no preference at all.
     *   fresh  — the previous default, on every site installed before 6.9, for
     *            the same reason.
     *
     * A site that chose nothing therefore gets the light panel: the warm paper
     * palette is the primary design, every one of its contrast ratios was
     * measured against it, and flipping every install in the world to a dark
     * admin panel is not a decision a restyle gets to make quietly. The six
     * below are schemes somebody went and picked, and all six are dark.
     *
     * `light` is absent for the obvious reason. The original guess for this
     * constant was [midnight, ectoplasm, coffee, modern]; that is recorded in
     * BUILD-NOTES.md along with why it changed.
     */
    const DARK_SCHEMES = ['midnight', 'ectoplasm', 'coffee', 'ocean', 'blue', 'sunrise'];

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        // Decoration only, and admin-only: the front end never loads it.
        Keelhob_Brand::hooks();
        // The design-system stylesheet. Keelhob's own screens only — see
        // enqueue_ui() for what "only" is measured against.
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_ui']);
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

    // --- The panel shell ----------------------------------------------------
    //
    // Everything from here to the next divider draws the chrome that is common
    // to every Keelhob screen: the stylesheet, the header line and the tab
    // strip. It lives on this class rather than on a screen class because five
    // screens across four files render it, and the one thing a design system
    // cannot survive is five copies of its own header.
    //
    // All of it is public and static so the satellite screens can call it
    // without constructing anything.

    /**
     * Is this one of Keelhob's own admin screens?
     *
     * The same test Keelhob_Admin::notice() has always used — substring match on
     * the screen id — kept identical on purpose, so the stylesheet appears on
     * exactly the set of screens the abilities banner already appears on and
     * there is no third opinion about what "a Keelhob screen" means.
     *
     * Both the hook suffix and the screen id are checked because neither alone
     * is reliable at every point in the request: `admin_enqueue_scripts` fires
     * before some screens finish registering, and get_current_screen() returns
     * null outright on a few. Either one matching is enough.
     *
     * The front end is not considered at all: this is only ever reached from
     * `admin_enqueue_scripts`, which does not fire there.
     */
    public static function is_keelhob_screen(string $hook_suffix = ''): bool
    {
        if ($hook_suffix !== '' && strpos($hook_suffix, 'keelhob') !== false) {
            return true;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        return $screen && isset($screen->id) && strpos((string) $screen->id, 'keelhob') !== false;
    }

    /**
     * Enqueue assets/css/admin.css, on Keelhob screens and nowhere else.
     *
     * Versioned with Keelhob::asset_version(), which appends the file's mtime to
     * the plugin version — the same thing Keelhob_Studio::enqueue_styles() does.
     * Without it a site behind a CDN serves the old stylesheet against the new
     * markup for as long as the edge cache lasts, and on this project that is
     * four hours.
     *
     * Registered on this class rather than on Keelhob_Brand because the brand
     * class is decoration that must never gate a screen, and because its
     * styles() helper writes an inline style against core's `wp-admin` handle
     * rather than enqueueing a file of its own.
     */
    public static function enqueue_ui($hook_suffix = ''): void
    {
        if (!self::is_keelhob_screen((string) $hook_suffix)) {
            return;
        }
        wp_enqueue_style(
            'keelhob-admin',
            KEELHOB_URL . 'assets/css/admin.css',
            [],
            Keelhob::asset_version('assets/css/admin.css')
        );
    }

    /**
     * True when the owner's admin colour scheme is one of DARK_SCHEMES.
     *
     * get_user_option() falls back to the site default when the user has never
     * chosen, and to '' when there is no user at all (WP-CLI, cron), both of
     * which land on light. Light is the safe default: it is the palette whose
     * contrast ratios were measured first.
     */
    public static function is_dark_scheme(): bool
    {
        $scheme = (string) get_user_option('admin_color');

        return in_array($scheme, self::DARK_SCHEMES, true);
    }

    /**
     * The class list for the panel root.
     *
     * Includes `wrap` because WordPress's own admin notices are moved into the
     * first `.wrap` on the page by core JavaScript; a panel that drops the class
     * loses every notice, including the "AI abilities are ON" one, which is not
     * a trade this redesign is allowed to make.
     */
    public static function root_class(): string
    {
        return 'wrap kh-ui' . (self::is_dark_scheme() ? ' is-dark' : '');
    }

    /**
     * The five screens in the tab strip, slug => label.
     *
     * Deliberately the five page slugs that already exist. Nothing is renamed
     * and nothing is merged: `page=keelhob` and the four `keelhob-*` slugs are
     * referenced 21 times in this repo and 19 more in DOCUMENTATION.md, which is
     * mirrored to the live site's /docs, and Telegram and notifier links point
     * at them too.
     *
     * Snippets is here as the sixth, by its own constant rather than by a typed
     * string. It was nearly left out — it is the screen another lane is
     * migrating in parallel — but leaving it out would make the one screen a
     * person can use with no AI connected and no abilities switched on the only
     * one with no way back to the rest of the panel, and the browser check at
     * tests/rig/ui/check-admin.mjs requires all six slugs to be reachable
     * from the strip. The link works whether or not that migration has landed:
     * it is a real page slug today.
     */
    public static function tabs(): array
    {
        return [
            'keelhob'            => __('Connect', 'keelhob'),
            Keelhob_Studio::SLUG => __('Snippets', 'keelhob'),
            'keelhob-skills'     => __('Skills', 'keelhob'),
            'keelhob-automation' => __('Automation', 'keelhob'),
            'keelhob-guide'      => __('Guide', 'keelhob'),
            'keelhob-activity'   => __('Activity', 'keelhob'),
        ];
    }

    /**
     * The header line: mark · Keelhob · site name · state pill · connection pill.
     *
     * THESE PILLS ARE IN ADDITION TO THE BANNER AND THE CHIP, NEVER INSTEAD.
     * Read notice() and chip_watchdog() above before touching any of the three.
     * The banner is dismissible only because the toolbar chip cannot be, and the
     * chip is re-armed by a watchdog at PHP_INT_MAX if something removes it.
     * These pills are not part of that guard and cannot stand in for it: they
     * appear on Keelhob's own screens only, which is the one place a second
     * administrator who has never seen this plugin is least likely to be.
     *
     * The <h1> is here rather than in each screen so core's "notices move into
     * the first .wrap after the first heading" behaviour has something to find.
     */
    public static function header_bar(): void
    {
        $on      = Keelhob::instance()->abilities_enabled();
        $label   = Keelhob::site_label();
        /*
         * "Has anything ever connected?", not "is something connected now".
         *
         * There is no live connection to report: MCP is request/response over
         * HTTP, so the honest question this pill can answer is whether the token
         * has ever been used. Claiming a live link from a timestamp would be the
         * kind of reassuring-but-wrong signal the capability screens exist to
         * avoid. The words say which one it is; the dot only decorates them.
         */
        $last_used = (int) Keelhob::instance()->get('token_last_used', 0);
        ?>
        <div class="kh-u-head">
            <div class="kh-u-head-id">
                <?php echo Keelhob_Brand::panel_mark(); ?>
                <div class="kh-u-head-text">
                    <h1 class="kh-u-name">
                        <?php esc_html_e('Keelhob', 'keelhob'); ?>
                        <span class="kh-u-name-version">v<?php echo esc_html(KEELHOB_VERSION); ?></span>
                    </h1>
                    <span class="kh-u-site"><?php echo esc_html($label); ?></span>
                </div>
            </div>
            <div class="kh-u-pills">
                <span class="kh-u-pill<?php echo $on ? ' is-on' : ''; ?>">
                    <span class="kh-u-dot <?php echo $on ? 'is-good' : 'is-off'; ?>" aria-hidden="true"></span>
                    <?php echo $on
                        ? esc_html__('AI abilities on', 'keelhob')
                        : esc_html__('AI abilities off', 'keelhob'); ?>
                </span>
                <span class="kh-u-pill">
                    <span class="kh-u-dot <?php echo $last_used > 0 ? 'is-good' : 'is-idle'; ?>" aria-hidden="true"></span>
                    <?php
                    if ($last_used > 0) {
                        echo esc_html(sprintf(
                            /* translators: %s: human-readable time difference, e.g. "3 hours". */
                            __('AI app connected · %s ago', 'keelhob'),
                            human_time_diff($last_used, time())
                        ));
                    } else {
                        esc_html_e('No AI app has connected yet', 'keelhob');
                    }
                    ?>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * The tab strip. Real links, and that is the whole point.
     *
     * Every tab is an <a href> to a page slug that already resolves on its own.
     * No JavaScript, no show/hide, no `display:none` panels. It therefore keeps
     * working with JS off or blocked, every tab is bookmarkable, and the browser
     * Back button works inside the panel.
     *
     * The strip at class-admin-screens.php:295 was the counter-example — a row
     * of <button> elements with inline styles and a script that hid the panels
     * you did not pick. It has been reworked rather than copied; see the comment
     * on the Connect screen's app picker for the one reason a button survives
     * there (the bearer token is flashed exactly once and a reload destroys it).
     *
     * aria-current="page" is the ONLY marker of the active tab: the CSS styles
     * that attribute rather than a class, so what a screen reader announces and
     * what the eye sees come from one source and cannot drift.
     *
     * @param string $current The page slug of the screen being rendered.
     */
    public static function tab_strip(string $current): void
    {
        echo '<ul class="kh-u-nav">';
        foreach (self::tabs() as $slug => $label) {
            printf(
                '<li><a class="kh-u-nav-link" href="%s"%s>%s</a></li>',
                esc_url(admin_url('admin.php?page=' . $slug)),
                $slug === $current ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }
        echo '</ul>';
    }

    /**
     * Open the panel: root div, header line, tab strip.
     *
     * Paired with shell_close(). Two calls rather than one wrapper taking a
     * callback, because every screen here renders by dropping out of PHP into
     * literal HTML and a callback would mean buffering all of it.
     *
     * @param string $current The page slug of the screen being rendered.
     */
    public static function shell_open(string $current): void
    {
        printf('<div class="%s">', esc_attr(self::root_class()));
        self::header_bar();
        self::tab_strip($current);
    }

    /** Close the panel opened by shell_open(). */
    public static function shell_close(): void
    {
        echo '</div>';
    }

    // --- The capability band: a VIEW over the stored groups, never a control -
    //
    // THIS READS. IT MUST NEVER WRITE, AND THE REASON IS NOT TIDINESS.
    //
    // The original design had three cards the owner could CLICK to pick a mode.
    // Three separate things made that dangerous on this plugin specifically, and
    // all three are still true:
    //
    //   (a) Keelhob's real default is already more permissive than any sane
    //       "Build" preset. Only the eight groups in
    //       Keelhob_Capabilities::DISABLED_BY_DEFAULT ship off, so `snippets`,
    //       `files` and `code_exec` are ON today. A preset that set "building"
    //       would switch off live snippets the site is currently running and the
    //       file access a skill depends on. That is an outage, not a safeguard.
    //
    //   (b) A "Full access" preset would grant payments, orders, users, customer
    //       email and production deploys in a single click. WP-Connect can offer
    //       that card safely because WP-Connect has no money tools. Keelhob has.
    //
    //   (c) Writing a preset destroys the "the owner never said either way"
    //       state. Keelhob_Capabilities::default_enabled() is consulted ONLY
    //       when a group is absent from the stored array. A preset writes an
    //       explicit true/false for all of them, which pins every group forever:
    //       if a later version decides a group is risky and adds it to
    //       DISABLED_BY_DEFAULT, the stored `true` wins and the new default
    //       never reaches that site. Nothing in the codebase would catch it.
    //
    // So these methods take no arguments, add no option, and return strings and
    // arrays. The only control over capabilities anywhere in this plugin remains
    // the `cap[...]` checkbox form that posts to admin-post.php.

    /** No capability group is on at all. */
    const BAND_READING = 'reading';
    /** Something is on, but none of the eight gated groups. */
    const BAND_BUILDING = 'building';
    /** At least one of Keelhob_Capabilities::DISABLED_BY_DEFAULT is on. */
    const BAND_FULL = 'full';

    /**
     * What is currently switched on, counted once.
     *
     * One pass over Keelhob_Capabilities::groups() so the band, the cards and
     * the stat tiles cannot disagree with each other about the same stored
     * array — which is exactly how a reassuring-but-wrong safety summary gets
     * built.
     *
     * @return array{
     *   on:string[], off:string[], sensitive_on:string[], gated_on:string[],
     *   total:int, band:string
     * }
     */
    public static function capability_state(): array
    {
        $on = $off = $sensitive_on = $gated_on = [];

        foreach (Keelhob_Capabilities::groups() as $key => $group) {
            if (!Keelhob_Capabilities::is_group_enabled($key)) {
                $off[] = $key;
                continue;
            }
            $on[] = $key;
            if (!empty($group['sensitive'])) {
                $sensitive_on[] = $key;
            }
            // Intersected against the real group list rather than read straight
            // off the constant: is_group_enabled() returns the default for a
            // name it does not know, so a key that existed only in the constant
            // would report itself as on and show "Full access" on a site where
            // nothing is.
            if (in_array($key, Keelhob_Capabilities::DISABLED_BY_DEFAULT, true)) {
                $gated_on[] = $key;
            }
        }

        /*
         * THE BAND, AND THE ONE DEFINITION THAT NEEDED PICKING.
         *
         * "Full access" and "Building" are given: the first is any of the eight
         * gated groups being on, the second is everything else that has
         * something on.
         *
         * "Reading only" is specified as "no writing group is on", and every one
         * of the groups grants at least one tool that writes — there is no
         * read-only group in the list, because the always-on read set
         * (Keelhob_Capabilities::always_on(): site info, dashboard, and the
         * memory/skill/task listings) is deliberately not a group at all and is
         * never gated. So "no writing group on" and "no group on" describe the
         * same set, and the stricter reading is the one implemented: every
         * single group off. A site in that state can still be asked "what is
         * going on", which is what Reading only means.
         */
        $band = self::BAND_BUILDING;
        if ($gated_on !== []) {
            $band = self::BAND_FULL;
        } elseif ($on === []) {
            $band = self::BAND_READING;
        }

        return [
            'on'           => $on,
            'off'          => $off,
            'sensitive_on' => $sensitive_on,
            'gated_on'     => $gated_on,
            'total'        => count($on) + count($off),
            'band'         => $band,
        ];
    }

    /** Which of the three bands this site is in right now. */
    public static function capability_band(): string
    {
        return self::capability_state()['band'];
    }

    /**
     * The three bands, in order, as the cards read them out.
     *
     * `change` is what would have to be true for a site to be in that band. It
     * is phrased as a fact about the switches, never as an instruction and never
     * as a link that would do it — see the comment at the top of this section.
     *
     * @return array<string, array{label:string, blurb:string, change:string}>
     */
    public static function bands(): array
    {
        return [
            self::BAND_READING => [
                'label'  => __('Reading only', 'keelhob'),
                'blurb'  => __('Every area is switched off. The agent can still be asked what is on this site, and can change nothing at all.', 'keelhob'),
                'change' => __('Every area below would have to be off.', 'keelhob'),
            ],
            self::BAND_BUILDING => [
                'label'  => __('Building', 'keelhob'),
                'blurb'  => __('The agent can build and change this site — content, design, SEO, media and code — but not the areas that are off by default.', 'keelhob'),
                'change' => __('At least one area on, and none of the eight that ship switched off.', 'keelhob'),
            ],
            self::BAND_FULL => [
                'label'  => __('Full access', 'keelhob'),
                'blurb'  => __('At least one of the eight areas that ship switched off is on: orders, payments, shipping, tax, users, updates, customer email or deploys.', 'keelhob'),
                'change' => __('One of those eight areas being on puts the site here.', 'keelhob'),
            ],
        ];
    }

    /**
     * Things that genuinely want the owner, as facts rather than advice.
     *
     * Every entry is derived from state already on the screen somewhere else —
     * nothing here runs a request, hits a vendor or writes anything. An empty
     * array is the normal, good answer, and the screen says so in words.
     *
     * `level` is 'bad' or 'mid', and it only ever picks the border and the dot.
     * The words carry the meaning: --kh-bad on --kh-bad-soft measures 4.41:1 and
     * fails AA, so the status colours are never the text. See admin.css.
     *
     * @return array<int, array{level:string, text:string}>
     */
    public static function needs_attention(): array
    {
        $out  = [];
        $kh   = Keelhob::instance();
        $caps = self::capability_state();

        if (Keelhob_Auth::is_locked_out()) {
            $out[] = [
                'level' => 'bad',
                'text'  => __('This site is refusing AI connections after too many failed attempts. Regenerate the token below to clear it.', 'keelhob'),
            ];
        }

        if ($kh->abilities_enabled() && $caps['gated_on'] !== []) {
            $out[] = [
                'level' => 'mid',
                'text'  => sprintf(
                    /* translators: %d: how many of the eight normally-off areas are switched on. */
                    _n(
                        '%d area that ships switched off is on. Check below that you meant it.',
                        '%d areas that ship switched off are on. Check below that you meant them.',
                        count($caps['gated_on']),
                        'keelhob'
                    ),
                    count($caps['gated_on'])
                ),
            ];
        }

        /*
         * An alert that cannot be delivered is worse than no alert, because the
         * silence reads as "nothing happened". Keelhob_Notifier separates what
         * the owner CHOSE from what currently works, and this reports the gap.
         */
        if ($kh->abilities_enabled() && Keelhob_Notifier::effective_channels() === []) {
            $out[] = [
                'level' => 'mid',
                'text'  => __('No notification channel is selected, so a digest or an alert about this site would not reach you anywhere.', 'keelhob'),
            ];
        } elseif ($kh->abilities_enabled() && Keelhob_Notifier::healthy_channels() === []) {
            $out[] = [
                'level' => 'bad',
                'text'  => __('Every selected notification channel failed on its last try, so an alert about this site would not reach you.', 'keelhob'),
            ];
        }

        if ($kh->abilities_enabled() && empty($kh->settings()['log_calls'])) {
            $out[] = [
                'level' => 'mid',
                'text'  => __('Tool-call logging is off, so the Activity screen cannot show you what the agent did.', 'keelhob'),
            ];
        }

        return $out;
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
