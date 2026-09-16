<?php
/**
 * Core plugin orchestrator. Holds settings and wires subsystems together.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress
{
    const OPTION_SETTINGS = 'claudpress_settings';

    /** @var Claudpress|null */
    private static $instance = null;

    /** @var Claudpress_Tool_Registry */
    public $registry;

    /** @var Claudpress_Memory */
    public $memory;

    /** @var Claudpress_Skills */
    public $skills;

    /** @var Claudpress_Logger */
    public $logger;

    public static function instance(): Claudpress
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * The name an agent uses for this site when it is connected to several.
     *
     * Resolved at read time, never frozen into the option: if the owner renames
     * the site, the handle follows. Falls back site name → host.
     */
    public static function site_label(): string
    {
        $set = trim((string) self::instance()->get('site_label', ''));
        if ($set !== '') {
            return $set;
        }
        $name = trim((string) get_bloginfo('name'));
        if ($name !== '') {
            return $name;
        }
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    /**
     * Default settings. `abilities_enabled` is the master safety switch — when
     * false, the MCP endpoint authenticates but exposes no destructive tools.
     */
    public static function default_settings(): array
    {
        return [
            'abilities_enabled' => false,
            // Kept only to carry a pre-1.10.1 plaintext token through the
            // upgrade; Claudpress_Auth swaps it for a hash on first use.
            'token'             => '',
            'token_hash'        => '',
            'token_expires_at'  => 0,
            'token_last_used'   => 0,

            // The anonymous check-in that produces the public "sites running
            // now" figure. Off means the updater talks straight to GitHub
            // instead, so opting out never costs anyone an update. See
            // class-updater.php for exactly what is sent.
            'skip_checkin'             => false,

            // Off by default on purpose: deleting the plugin must not
            // destroy the owner's snippets, memory and skills. See uninstall.php.
            'delete_data_on_uninstall' => false,

            // Who accepted the AI-abilities warning, when, and which version of
            // it. Kept even after abilities are switched off again: it is a
            // historical fact, and it is the only evidence that it happened.
            'consent_by'               => 0,
            'consent_at'               => 0,
            'consent_version'          => 0,
            'allow_app_passwords' => true,
            // Filesystem base: operations are confined at or below this path.
            'fs_base'           => ABSPATH,
            'require_https'     => false,
            'log_calls'         => true,
            // The feedback log. ON by default, which is only defensible because
            // nothing is recorded when it is off and nothing is ever sent
            // without a person reading the whole draft first. See
            // Claudpress_Feedback and docs/plans/feedback-log.md.
            'feedback_log'      => true,
            // Snippet engine kill-switch (active snippets run for real visitors).
            'run_snippets'      => true,
            // Short handle an agent uses to tell this site apart from the other
            // sites it is connected to ("copy that snippet from Pranknologia").
            // Blank = derive it from the site name at read time — see site_label().
            'site_label'        => '',
            // Which page builder the site uses; loads matching guidance/skill.
            'builder'           => 'auto', // auto | gutenberg | elementor_free | elementor_pro | bricks | custom_php
            // Optional AI image generation. Blank key = use free image_search.
            'image_provider'    => 'openai',
            'image_api_key'     => '',
            'image_api_endpoint' => '', // blank = the provider's default endpoint
            'image_api_model'   => '',  // blank = the provider's default model
            // Autonomous agent runtime. Blank key = autonomy off.
            // See Claudpress_Provider for the supported vendors.
            'agent_provider'     => 'anthropic',
            'agent_api_key'      => '',
            'agent_api_endpoint' => '', // blank = the provider's default endpoint
            'agent_model'        => '', // blank = the provider's default model
            // Autonomy budget caps (0 = unlimited). Estimated from token usage.
            'agent_max_usd_per_run'    => 0.50,
            'agent_max_tokens_per_run' => 300000,
            'agent_max_usd_per_day'    => 5.00,
            'agent_max_runs_per_day'   => 48,
            // Notifications.
            'notify_email'          => '',
            'notify_webhook_url'    => '',

            // Which channels a digest goes to. NULL, not [], is the
            // unconfigured state: an empty array is a real choice meaning
            // 'send nowhere', and defaulting to it would silently stop
            // every existing digest the moment this shipped.
            'notify_channels'       => null,
            'notify_telegram_token' => '',
            'notify_telegram_chat'  => '',
            // Telegram chat front-end (inbound). Off until the owner connects a
            // bot — the same token above is reused, this only opens the return
            // direction. See Claudpress_Telegram.
            'telegram_bot_enabled'   => false,
            'telegram_secret'        => '',  // webhook shared secret, auto-generated
            'telegram_allowed_chats' => '',  // blank = fall back to notify_telegram_chat
            'telegram_history_turns' => 12,
            'telegram_allow_risky'   => false,
            'telegram_voice'         => false, // transcribe voice notes
            'telegram_photos'        => false, // accept photos as vision input
            'telegram_progress'      => true,  // edit one message as the run works
            // Speech-to-text for voice notes. Anthropic has no audio endpoint,
            // so this is its own provider choice; a blank key falls back to any
            // other key already configured for the same vendor.
            'voice_provider'     => 'openai',
            'voice_api_key'      => '',
            'voice_api_endpoint' => '',
            'voice_model'        => '',
            // Event notifications — the site messaging the owner first.
            'events_enabled'   => false,
            'events_order'     => true,
            'events_order_min' => 0,      // only orders at/above this total
            'events_stock'     => true,
            'events_snippet'   => true,
            'events_updates'   => false,
            // Policy / guardrails.
            'policy' => ['enabled' => true, 'blocked_tools' => [], 'max_refund' => 0, 'require_optin' => true],
            // Staging → production deploy target.
            'deploy_target_url'   => '',
            'deploy_target_token' => '',
            // Per-capability toggles (empty = every capability enabled). See Claudpress_Capabilities.
            'capabilities' => [],
        ];
    }

    public function settings(): array
    {
        $saved = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $merged = array_merge(self::default_settings(), $saved);

        // Decrypt on the way out so every existing caller keeps working with
        // plain values and none of them has to know secrets are encrypted at
        // rest. Values written before 1.10.1 pass through untouched.
        foreach (Claudpress_Secrets::fields() as $field) {
            if (isset($merged[$field]) && is_string($merged[$field]) && $merged[$field] !== '') {
                $merged[$field] = Claudpress_Secrets::decrypt($merged[$field]);
            }
        }
        return $merged;
    }

    public function get(string $key, $default = null)
    {
        $s = $this->settings();
        return $s[$key] ?? $default;
    }

    public function update(array $patch): void
    {
        $all = array_merge($this->settings(), $patch);

        // Encrypt on the way in. settings() decrypted them, so re-encrypting
        // the whole merged array keeps untouched fields correct too.
        foreach (Claudpress_Secrets::fields() as $field) {
            if (isset($all[$field]) && is_string($all[$field]) && $all[$field] !== '') {
                $all[$field] = Claudpress_Secrets::encrypt($all[$field]);
            }
        }

        // autoload=false: this option holds credentials, and there is no reason
        // for it to be loaded into memory on every single front-end request
        // where any other plugin could read it out of the options cache.
        update_option(self::OPTION_SETTINGS, $all, false);
    }

    public function abilities_enabled(): bool
    {
        return (bool) $this->get('abilities_enabled', false);
    }

    public function boot(): void
    {
        $this->maybe_upgrade();

        $this->logger   = new Claudpress_Logger();
        $this->memory   = new Claudpress_Memory();
        $this->skills   = new Claudpress_Skills();
        $this->registry = new Claudpress_Tool_Registry();

        // Register every tool group. Each group decides internally whether it
        // is applicable (e.g. WooCommerce tools only register when WC is active).
        Claudpress_Tools_Core::register($this->registry);
        Claudpress_Tools_Filesystem::register($this->registry);
        Claudpress_Tools_Content::register($this->registry);
        Claudpress_Tools_Site::register($this->registry);
        Claudpress_Tools_WooCommerce::register($this->registry);
        Claudpress_Tools_SEO::register($this->registry);
        Claudpress_Tools_Memory::register($this->registry);
        Claudpress_Tools_Skills::register($this->registry);
        Claudpress_Tools_Snippets::register($this->registry);
        Claudpress_Tools_Elementor::register($this->registry);
        Claudpress_Tools_Ops::register($this->registry);
        Claudpress_Tools_Automation::register($this->registry);
        Claudpress_Tools_Payments::register($this->registry);
        Claudpress_Tools_Deploy::register($this->registry);
        Claudpress_Tools_Readiness::register($this->registry);
        Claudpress_Tools_Feedback::register($this->registry);

        /**
         * The extension seam for tools that do not live in this plugin.
         *
         * Fires once per request, after every core group has registered and
         * before anything reads the registry, so an add-on or a third-party
         * plugin can contribute tools without this file having to know they
         * exist. Nothing in this plugin uses it any more — it is kept because
         * an extension point that costs one line is worth more than the line.
         *
         * Add tools with $registry->add(); registration fails soft, so a bad
         * callback costs one tool rather than the request.
         *
         * @param Claudpress_Tool_Registry $registry
         */
        do_action('claudpress_register_tools', $this->registry);

        // Code-snippets engine — active snippets run on every request.
        Claudpress_Snippets::init();

        // Scheduled automations (WP-Cron tick).
        Claudpress_Tasks::init();

        // Push notifications for things the owner should hear about unprompted.
        Claudpress_Events::init();

        // Front-end SEO injection (schema, redirects, fallback meta, robots.txt).
        Claudpress_Tools_SEO::hooks();

        // REST / MCP endpoint.
        (new Claudpress_Rest())->hooks();

        // Crash-recovery sandbox loader (runs AI-written persistent PHP safely).
        Claudpress_Sandbox::init();

        // A change to what an agent may do cancels every "hide that warning"
        // dismissal. OUTSIDE is_admin() on purpose: abilities get switched on
        // over WP-CLI, over the REST route and by agents through manage_option
        // at least as often as through the settings screen, and a safety
        // warning that only re-arms for one of those paths is not a safety
        // warning. See Claudpress_Admin::rewarn_on_change().
        add_action('update_option_' . self::OPTION_SETTINGS, ['Claudpress_Admin', 'rewarn_on_change'], 10, 2);

        // Admin UI.
        if (is_admin()) {
            (new Claudpress_Admin())->hooks();
        }
    }

    /**
     * Runs on every boot but only does work when the stored DB version differs
     * from the plugin version — so updating the plugin (without reactivating)
     * still creates new tables and seeds new defaults.
     */
    private function maybe_upgrade(): void
    {
        $stored = get_option('claudpress_db_version', '');
        if ($stored === CLAUDPRESS_VERSION) {
            return;
        }
        Claudpress_Memory::install_table();
        Claudpress_Skills::install_table();
        Claudpress_Snippets::install_table();
        Claudpress_Tasks::install_table();
        Claudpress_Agent_Runs::install_table();
        Claudpress_Telegram::install_table();
        Claudpress_Logger::install_table();
        Claudpress_Snippet_Log::install_table();
        Claudpress_Feedback::install_table();
        // After install_table() has added the columns: give pre-1.9 snippets a
        // uid and a created_at so the Snippets page and cross-site copy work.
        Claudpress_Snippets::backfill();
        // Rows written before the source column existed have to be classified
        // before anything can refresh them; see Claudpress_Skills::backfill_sources().
        Claudpress_Skills::backfill_sources();
        Claudpress_Skills::seed_bundled_skills();
        self::seed_default_memory();
        self::upgrade_from_paid_tier();
        if (!file_exists(CLAUDPRESS_SANDBOX_DIR)) {
            wp_mkdir_p(CLAUDPRESS_SANDBOX_DIR);
            @file_put_contents(CLAUDPRESS_SANDBOX_DIR . '/index.php', "<?php // Silence is golden.\n");
            @file_put_contents(CLAUDPRESS_SANDBOX_DIR . '/.htaccess', "Deny from all\n");
        }
        update_option('claudpress_db_version', CLAUDPRESS_VERSION);
    }

    /**
     * The one-time paid-tier upgrade, and the reason it is not optional.
     *
     * Before the free/paid merge, eight capability groups - payments, shipping,
     * tax, orders, users, updates, comms and deploy - were unreachable because
     * they sat behind a paywall. Their own on/off toggle was never consulted,
     * so on a long-running site it may well be sitting at "on", meaning nothing
     * at all.
     *
     * The merge deletes the paywall. Left alone, that would have silently
     * granted every upgrading site the ability to email its customers, edit its
     * users, change its payment gateways and push to production - delivered by
     * an automatic update, to an owner who asked for nothing and was told
     * nothing.
     *
     * So these are forced OFF, not defaulted off. "Only if no value is stored"
     * is exactly the version of this that does not work, because the stored
     * value is the meaningless leftover. The owner turns back on whatever they
     * actually want, and an admin notice tells them that is waiting for them -
     * switching something off silently is its own kind of bug.
     *
     * This guards on EVIDENCE, not on a version number. A version guard says
     * "older than X" and is therefore only ever true relative to whatever the
     * numbering happens to be that week; renumber the plugin and the same code
     * starts firing at brand-new installs, wiping capability choices the owner
     * made deliberately. The population that needs this migration is not "old
     * versions" - it is "sites still carrying pre-merge free/paid settings",
     * and those sites are identifiable by what they hold. Renumbering cannot
     * change that, so neither can it break this.
     */
    private static function upgrade_from_paid_tier(): void
    {
        $settings = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        // The evidence: any leftover of the tier that no longer exists. A site
        // that has none of these never had a paywall standing in for a safety
        // fence, so there is nothing here to correct and nothing to announce.
        $is_pre_merge = array_key_exists('disclose_pro', $settings)
            || array_key_exists('hide_paid_notices', $settings)
            || array_key_exists('skills_token', $settings)
            || get_option('claudpress_pro_licence', null) !== null;

        if (!$is_pre_merge) {
            return;
        }
        // Run once. Having run, it must never fight the owner's later choices.
        if (get_option('claudpress_paid_tier_reset', '') !== '') {
            return;
        }

        $caps = isset($settings['capabilities']) && is_array($settings['capabilities']) ? $settings['capabilities'] : [];

        $turned_off = [];
        foreach (Claudpress_Capabilities::DISABLED_BY_DEFAULT as $group) {
            if (!array_key_exists($group, $caps) || !empty($caps[$group])) {
                $turned_off[] = $group;
            }
            $caps[$group] = false;
        }
        $settings['capabilities'] = $caps;

        // Dead keys from the tier that no longer exists. Left behind they are
        // harmless, but they are also a lie about what the plugin reads.
        unset($settings['disclose_pro'], $settings['hide_paid_notices'], $settings['skills_token']);

        update_option(self::OPTION_SETTINGS, $settings);
        delete_option('claudpress_pro_licence');
        delete_transient('claudpress_pro_licence_valid');

        update_option('claudpress_paid_tier_reset', $turned_off ? implode(',', $turned_off) : 'none');
    }

    public static function on_activate(): void
    {
        $existing = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        // Generate a token on first activation so the user has something to copy.
        if (empty($existing['token']) && empty($existing['token_hash'])) {
            $plain                    = self::generate_token();
            $existing['token_hash']   = hash('sha256', $plain);
            $existing['token']        = '';
            self::flash_token($plain);
        }
        update_option(self::OPTION_SETTINGS, array_merge(self::default_settings(), $existing));

        if (!file_exists(CLAUDPRESS_SANDBOX_DIR)) {
            wp_mkdir_p(CLAUDPRESS_SANDBOX_DIR);
            // Harden: no direct web access, no directory listing.
            @file_put_contents(CLAUDPRESS_SANDBOX_DIR . '/index.php', "<?php // Silence is golden.\n");
            @file_put_contents(CLAUDPRESS_SANDBOX_DIR . '/.htaccess', "Deny from all\n");
        }

        Claudpress_Memory::install_table();
        Claudpress_Skills::install_table();
        Claudpress_Snippets::install_table();
        Claudpress_Tasks::install_table();
        Claudpress_Agent_Runs::install_table();
        Claudpress_Telegram::install_table();
        Claudpress_Logger::install_table();
        Claudpress_Snippet_Log::install_table();
        Claudpress_Feedback::install_table();
        Claudpress_Snippets::backfill();
        // Rows written before the source column existed have to be classified
        // before anything can refresh them; see Claudpress_Skills::backfill_sources().
        Claudpress_Skills::backfill_sources();
        Claudpress_Skills::seed_bundled_skills();
        self::seed_default_memory();
        update_option('claudpress_db_version', CLAUDPRESS_VERSION);
    }

    /**
     * Seed a small set of default memories so any connected agent starts with
     * the vital operating context for this plugin, even before the owner adds
     * their own. Only writes a key when it does not already exist.
     */
    public static function seed_default_memory(): void
    {
        $memory = new Claudpress_Memory();
        $defaults = [
            'claudpress.capabilities' => 'This site runs Claudpress: you can execute PHP/SQL, manage content, run the WooCommerce store, do SEO, edit files, write live code snippets (snippet_save), build Elementor pages (elementor_page), update plugins, email the owner (send_email), and add images (image_search / image_generate). Prefer dedicated tools; fall back to execute_php.',
            'build.page_modes'        => 'Two ways to build pages: (1) pixel-perfect custom-coded page = snippet_save with location=page_template + target_id, writing full responsive HTML/CSS; editable only via the agent. (2) editable page = Gutenberg blocks (create_post) or Elementor (elementor_page). Ask the owner which they prefer if unclear.',
            'build.elementor'         => 'For Elementor building: run elementor_settings action=prepare_for_ai first (enables Container/flexbox, disables the Atomic editor). Build layouts with flexbox Containers (elType "container"), never deprecated sections/columns. Xpro Theme Builder (free) makes custom headers/footers without Elementor Pro.',
            'content.images'          => 'Images: prefer image_search (Openverse, free, no key) to fetch a real licensed image and attach it; use image_generate only if an image API key is configured in Claudpress settings. Always set descriptive alt text.',
            'design.reference'        => 'For ANY visual/build work, follow the web-design-master skill (skill_get name=web-design-master): design tokens, mobile-first responsive, component patterns, WordPress "style with the theme not against it" rules, and the anti-slop quality audit. Prefer a CSS snippet or page_template over inline <style> (security plugins strip inline styles).',
            'safety.workflow'         => 'This should be a dev/staging site. Read before you write, confirm destructive/financial actions, verify after changing, and save durable decisions with memory_save. Snippets that fatal auto-deactivate.',
            'chat.telegram'           => 'The owner may be talking to you from Telegram (context "telegram") rather than an MCP client. That is a live chat on a phone: keep replies short and plain-text, ask at most one clarifying question, and remember they cannot see tool output — only what you write. Built-in commands (/status, /usage, /clear, /tasks, /undo) are answered by the plugin without you. A voice note arrives already transcribed; a photo arrives as an image you can see plus a media-library attachment ID you can use directly in a post or product. If a tool comes back policy_blocked, say which one — the owner gets an Approve button to allow it, so name it plainly instead of working around it. Content changes you make in a turn are undoable via /undo, but only content: settings, plugins, files and snippets are not, so be correspondingly careful with those.',
            'autonomy.usage'          => 'The site can run itself: get_dashboard answers "what is going on / how is the store doing" on demand; task_save creates scheduled digest or autonomous tasks; run_agent executes a one-off instruction now. Autonomous runs need an agent API key (Claudpress > Automation) and obey policy guardrails — pass allow_risky on a task only when destructive/financial actions are intended.',
        ];
        foreach ($defaults as $key => $value) {
            if (!$memory->get($key)) {
                $memory->save($key, $value, 'claudpress');
            }
        }
    }

    public static function on_deactivate(): void
    {
        // Safety: turn abilities off when the plugin is deactivated.
        $s = get_option(self::OPTION_SETTINGS, []);
        if (is_array($s)) {
            $s['abilities_enabled'] = false;
            update_option(self::OPTION_SETTINGS, $s);
        }
        // Stop the automation cron so nothing runs while deactivated.
        Claudpress_Tasks::unschedule();
    }

    public static function generate_token(): string
    {
        return 'cp_' . bin2hex(random_bytes(24));
    }

    /**
     * Mint a new bearer token: store only its hash, hand back the plaintext.
     *
     * The plaintext is never written to the options table. It goes into a
     * short-lived transient purely so the Connect screen can render it once, on
     * the very next page load, and is deleted the moment it is read. If the
     * owner misses that window their only option is to regenerate - which is
     * the correct trade for a credential that grants full administrative
     * control of the site.
     */
    /**
     * The cache-busting version for a bundled CSS or JS file.
     *
     * THE BUG THIS EXISTS TO PREVENT, which shipped once and was reported from
     * a real browser: every asset was registered with CLAUDPRESS_VERSION. That
     * only changes when the RELEASE number changes, so editing a script without
     * bumping the release leaves its URL byte-identical —
     * `studio-compose.js?ver=1.2.0` before and after. Browsers reuse what they
     * already have under that URL.
     *
     * AI Compose was rewritten from a wizard into a chat inside one version.
     * Anyone who had opened the old screen got the NEW markup with the OLD
     * cached script, which looks for `#cp-compose-plan`, does not find it, and
     * returns without wiring anything. Send did nothing. Enter typed a newline.
     * No console error, nothing in the page source, and nothing any server-side
     * test can see — the files on disk were all correct.
     *
     * filemtime() changes whenever the file does, which is the actual question
     * being asked. It falls back to the release version if the file cannot be
     * stat'd, so a hardened host that blocks filemtime degrades to the old
     * behaviour rather than to no version at all.
     *
     * @param string $relative e.g. 'assets/js/studio-compose.js'
     */
    public static function asset_version(string $relative): string
    {
        $path = CLAUDPRESS_DIR . ltrim($relative, '/');
        $time = @filemtime($path);

        return $time ? CLAUDPRESS_VERSION . '.' . $time : CLAUDPRESS_VERSION;
    }

    public static function set_token(): string
    {
        $plain = self::generate_token();
        self::instance()->update([
            'token'            => '',
            'token_hash'       => hash('sha256', $plain),
            'token_last_used'  => 0,
        ]);
        // Minting a new token clears the failed-attempt lock.
        //
        // THE TRAP THIS CLOSES, hit by the plugin's own author. The lock is
        // checked BEFORE the credential, so a brand-new, perfectly valid token
        // still gets 429 — regenerating appeared to do nothing. Worse, a client
        // still holding the OLD token re-locks the site the instant the window
        // opens, so the wait never ends and the only visible symptom is
        // "connection failed" forever.
        //
        // Reaching here means somebody minted a token, which needs
        // manage_options. That is stronger proof of ownership than surviving a
        // fifteen-minute wait, so there is nothing left for the lock to defend.
        Claudpress_Auth::unlock();

        self::flash_token($plain);
        return $plain;
    }

    /** Stash a freshly minted token for one render. */
    public static function flash_token(string $plain): void
    {
        set_transient('claudpress_token_flash', $plain, 5 * MINUTE_IN_SECONDS);
    }

    /** Read and immediately burn the flashed token. '' when there is none. */
    public static function take_flashed_token(): string
    {
        $plain = (string) get_transient('claudpress_token_flash');
        if ($plain !== '') {
            delete_transient('claudpress_token_flash');
        }
        return $plain;
    }
}
