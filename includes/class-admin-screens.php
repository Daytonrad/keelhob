<?php
/**
 * The three read-and-connect screens: Connect, Guide and Activity.
 *
 * Connect is the one an owner opens first — flip the abilities on or off,
 * manage the bearer token, and copy a ready-to-paste connection command for
 * Claude Code and other MCP clients. Guide is the manual. Activity is the
 * record of what the AI has actually done.
 *
 * The Automation screen is a fourth, but it is long enough to own a file:
 * class-admin-automation.php.
 *
 * Asset-free, like every other screen here: native WordPress markup, no CSS or
 * JS files, inline <style>/<script> only where a page genuinely needs it.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Admin_Screens
{
    public function render(): void
    {
        $s        = Keelhob::instance()->settings();
        $endpoint = rest_url(KEELHOB_REST_NAMESPACE . '/mcp');
        // S5: the token is stored hashed, so the plaintext exists only in the
        // one page load straight after it is minted. After that the screen shows
        // a placeholder, and the owner regenerates if they need a new one.
        $token_plain = Keelhob::take_flashed_token();
        $have_token  = ($token_plain !== '');
        $token       = $have_token ? $token_plain : 'YOUR_TOKEN_HERE';
        $host     = wp_parse_url(home_url(), PHP_URL_HOST);
        // The MCP server name is the string the agent sees for this site, so it
        // should be the same handle the owner uses — that is what makes
        // "copy that snippet to the other shop" resolve to the right server.
        $server_name = 'keelhob-' . sanitize_title(Keelhob::site_label() ?: ($host ?: 'site'));

        // One block per app, because they genuinely differ. The old screen
        // printed a single {type,url,headers} JSON labelled "Claude Desktop /
        // Cursor / Codex", and Claude Desktop cannot read that at all — it has
        // no HTTP transport. Someone followed this screen and lost an evening.
        //
        // esc_html() is NOT applied here any more. It used to be, inside the
        // sprintf, and then esc_textarea() ran over the result as well: an
        // ampersand in a URL came out as &amp;amp;. Escaping belongs at the
        // point of output, once.
        $is_http     = !str_starts_with(strtolower((string) $endpoint), 'https://');
        $client_list = Keelhob_Connect::clients();
        $local_notes = Keelhob_Connect::local_site_notes($is_http, (string) $host);

        $enabled = Keelhob::instance()->abilities_enabled();
        ?>
        <?php
        // The shell — root class, header line, tab strip — is drawn by
        // Keelhob_Admin so that five screens across four files cannot drift into
        // five different headers. It prints the <h1> too, which is why one no
        // longer appears here: core moves admin notices into the first .wrap
        // after the first heading, and two <h1>s would give it the wrong one.
        Keelhob_Admin::shell_open('keelhob');
        ?>
            <?php Keelhob_Donate::button(); ?>
            <p class="kh-u-measure"><?php esc_html_e('Give an AI agent full, programmatic control of this WordPress & WooCommerce site over MCP — content, store, SEO, files, PHP — with cross-session memory and a skills engine. Self-contained, no external dependencies.', 'keelhob'); ?></p>

            <?php
            /*
             * The Guide pointer. It used to be a hand-rolled blue/copper
             * gradient with eight inline styles and a white-on-copper button;
             * the copper was #d37355 as a BACKGROUND, which is fine, but the
             * button inverted it into #a2503a text on white at 4.59:1 and the
             * sub-line was `opacity:.9` on a gradient, which cannot be measured
             * at all. It is a callout now: one token fill, one coloured border,
             * ink words. The link is unchanged.
             */
            ?>
            <div class="kh-u-note is-info kh-u-measure">
                <p><strong><?php esc_html_e('New to Keelhob? Start with the Guide.', 'keelhob'); ?></strong></p>
                <p><?php esc_html_e('A plain-English walkthrough of every feature, what the AI can do, which switches to leave off, and how to prompt it.', 'keelhob'); ?></p>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=keelhob-guide')); ?>" class="button"><?php esc_html_e('Open the master guide', 'keelhob'); ?></a></p>
            </div>

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'keelhob'); ?></p></div>
            <?php endif; ?>

            <?php $this->overview($s, $enabled); ?>

            <div class="kh-u-cols">
                <div class="kh-u-col kh-u-stack">
                    <div class="kh-u-card">
                        <h2><?php esc_html_e('1 · Turn on AI abilities', 'keelhob'); ?></h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('keelhob_save'); ?>
                            <input type="hidden" name="action" value="keelhob_save">
                            <input type="hidden" name="keelhob_action" value="settings">
                            <?php
                            /*
                             * NOT SHORTENED, NOT COLLAPSED INTO A DETAILS PANEL.
                             * PLAN §2.7 names three things that may never be cut
                             * and this is the first of them: it is the record
                             * that the owner was warned before an AI agent was
                             * given write access to a live site. The restyle
                             * moves it onto the tokens and nothing else.
                             */
                            ?>
                            <div class="kh-u-note is-bad">
                                <p><strong><?php esc_html_e('Before you switch this on', 'keelhob'); ?></strong></p>
                                <p><?php esc_html_e('Keelhob lets an AI agent make real changes to this live site. It can write code, edit pages and products, change settings, install plugins, and touch your store. It can be wrong, and wrong changes can break pages, checkout, or the admin area.', 'keelhob'); ?></p>
                                <p><?php esc_html_e('Your instructions, the content and code involved, and a short description of this site (WordPress and PHP versions, theme, active well-known plugins) are sent to the AI provider you choose, under that provider terms.', 'keelhob'); ?></p>
                                <p><?php esc_html_e('You pay that provider directly for what you use. Keelhob cannot see or cap that spend — only your provider can. Set a spend limit on your provider account before you switch this on.', 'keelhob'); ?></p>
                                <p><?php esc_html_e('There are real safeguards. Code is saved switched off, PHP is syntax-checked before it can go live, a snippet that crashes the site is switched back off automatically, recent changes can be undone, and every action is written to the Activity log. These are safeguards, not a guarantee.', 'keelhob'); ?></p>
                                <p><?php esc_html_e('You stay responsible for reviewing what the agent does, for keeping the capability switches set to what you actually want, and for having a backup.', 'keelhob'); ?></p>
                            </div>
                            <p>
                                <label class="kh-u-check">
                                    <input type="checkbox" name="abilities_enabled" value="1" <?php checked($enabled); ?>>
                                    <strong><?php esc_html_e('Turn on AI abilities', 'keelhob'); ?></strong>
                                </label><br>
                                <span class="kh-u-under"><?php esc_html_e('I have read the points above and accept that AI-driven changes run on my site at my own risk.', 'keelhob'); ?></span>
                            </p>
                            <?php
                            // Show who accepted and when. If this is ever
                            // disputed, the answer should be on the screen
                            // rather than reconstructed from memory.
                            $consent_at = (int) ($s['consent_at'] ?? 0);
                            if ($enabled && $consent_at > 0) :
                                $who = get_userdata((int) ($s['consent_by'] ?? 0));
                                ?>
                                <p class="kh-u-sub">
                                    <?php
                                    echo esc_html(sprintf(
                                        /* translators: 1: user login, 2: date and time. */
                                        __('Accepted by %1$s on %2$s.', 'keelhob'),
                                        $who ? $who->user_login : __('an unknown user', 'keelhob'),
                                        date_i18n(get_option('date_format') . ' H:i', $consent_at)
                                    ));
                                    ?>
                                </p>
                            <?php endif; ?>
                            <hr>
                            <p><label><input type="checkbox" name="allow_app_passwords" value="1" <?php checked(!empty($s['allow_app_passwords'])); ?>> <?php esc_html_e('Allow Application Password (Basic) auth in addition to the bearer token', 'keelhob'); ?></label></p>
                            <p><label><input type="checkbox" name="require_https" value="1" <?php checked(!empty($s['require_https'])); ?>> <?php esc_html_e('Require HTTPS (recommended for remote sites)', 'keelhob'); ?></label></p>
                            <p><label><input type="checkbox" name="log_calls" value="1" <?php checked(!empty($s['log_calls'])); ?>> <?php esc_html_e('Log tool calls (Activity tab)', 'keelhob'); ?></label></p>
                            </p>
                            <hr>
                            <p><strong><?php esc_html_e('Notifications', 'keelhob'); ?></strong></p>
                            <?php
                            // Preference and connectivity are two different
                            // things. A disabled checkbox posts nothing, so the
                            // stored choice must never be derived from this
                            // form - otherwise losing a Telegram token would
                            // silently erase the choice and reconnecting would
                            // not bring it back. See Keelhob_Notifier.
                            $availability = Keelhob_Notifier::availability();
                            $chosen       = Keelhob_Notifier::chosen_channels();
                            foreach (Keelhob_Notifier::labels() as $channel => $label) :
                                $connected = !empty($availability[$channel]['connected']);
                                $state     = $availability[$channel]['state'] ?? 'untested';
                                $wanted    = in_array($channel, $chosen, true);
                                ?>
                                <p>
                                    <label class="<?php echo $connected ? '' : 'kh-u-dim'; ?>">
                                        <?php
                                        // Tick only what will actually be used. The preference
                                        // itself survives in settings either way - see
                                        // channels_from_post() - but a tick beside "connect the
                                        // bot first" reads as a claim that it is working, and a
                                        // reassuring mark next to a dead channel is the exact
                                        // bug this screen is here to avoid.
                                        ?>
                                        <input type="checkbox" name="notify_channels[]" value="<?php echo esc_attr($channel); ?>"
                                            <?php checked($wanted && $connected); ?>
                                            <?php disabled(!$connected); ?>>
                                        <?php echo esc_html($label); ?>
                                    </label>
                                    <?php if (!$connected) : ?>
                                        <span class="kh-u-warn">— <?php echo esc_html($availability[$channel]['reason']); ?></span>
                                        <?php if ($wanted) : ?>
                                            <span class="kh-u-sub">
                                                <?php esc_html_e('(your choice is remembered and comes back when this is connected)', 'keelhob'); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php elseif ($state === 'failing') : ?>
                                        <span class="kh-u-warn">— <?php echo esc_html($availability[$channel]['warning']); ?></span>
                                    <?php elseif ($state === 'working') : ?>
                                        <span class="kh-u-okay">
                                            <?php
                                            printf(
                                                /* translators: %s: time ago, e.g. "5 mins" */
                                                esc_html__('— delivered %s ago', 'keelhob'),
                                                esc_html(human_time_diff((int) $availability[$channel]['last_at']))
                                            );
                                            ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="kh-u-sub">
                                            <?php esc_html_e('— set up, but nothing has been sent through it yet', 'keelhob'); ?>
                                        </span>
                                    <?php endif; ?>
                                </p>
                                <?php
                            endforeach;

                            if (empty(Keelhob_Notifier::effective_channels())) :
                                ?>
                                <p class="kh-u-warn">
                                    <?php esc_html_e('Nothing is selected, so digests and alerts will not be sent anywhere.', 'keelhob'); ?>
                                </p>
                            <?php elseif (empty(Keelhob_Notifier::healthy_channels())) : ?>
                                <p class="kh-u-warn">
                                    <?php esc_html_e('Every selected channel failed on its last try, so an alert about this site would not reach you. Send a test below to check whether it is fixed.', 'keelhob'); ?>
                                </p>
                            <?php endif; ?>

                            <hr>
                            <p><strong><?php esc_html_e('Updates and the public count', 'keelhob'); ?></strong></p>
                            <p>
                                <label>
                                    <input type="checkbox" name="skip_checkin" value="1" <?php checked(!empty($s['skip_checkin'])); ?>>
                                    <?php esc_html_e('Do not include this site in the public count', 'keelhob'); ?>
                                </label><br>
                                <span class="kh-u-under">
                                    <?php esc_html_e('The update check sends a random ID, version numbers, your language, and which features are on. Every one of those is true of a thousand other sites. It never sends your address, email, content, keys or costs, and your IP is not kept. It is the only count of who uses this, and it decides what gets built next. Tick this to check GitHub directly instead: same updates, not counted.', 'keelhob'); ?>
                                </span>
                            </p>

                            <hr>
                            <?php
                            // The wording is the consent. It is spelled out in
                            // full rather than linked to, because a promise
                            // nobody read is not one anybody agreed to - and
                            // this is the only setting in the plugin that lets
                            // anything at all leave the server other than the
                            // update check. See Keelhob_Feedback.
                            ?>
                            <p><label><input type="checkbox" name="feedback_log" value="1" <?php checked(!empty($s['feedback_log'])); ?>> <strong><?php esc_html_e('Help improve Keelhob', 'keelhob'); ?></strong></label></p>
                            <p class="description kh-u-under">
                                <?php esc_html_e('When something goes wrong, note it locally. About once a week the agent will offer to send a short, anonymous summary — you see exactly what it says and approve it first. No site address, no page content, no keys. Switch this off and nothing is recorded at all.', 'keelhob'); ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=keelhob-activity')); ?>"><?php esc_html_e('See what has been noted', 'keelhob'); ?></a>
                            <hr>
                            <p><strong><?php esc_html_e('Uninstall', 'keelhob'); ?></strong></p>
                            <p>
                                <label>
                                    <input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked(!empty($s['delete_data_on_uninstall'])); ?>>
                                    <?php esc_html_e('Delete all Keelhob data when the plugin is deleted', 'keelhob'); ?>
                                </label><br>
                                <span class="kh-u-under">
                                    <?php esc_html_e('Off by default: deleting the plugin leaves your snippets, memory, skills and history in the database, so reinstalling restores them. There is no undo once this is on.', 'keelhob'); ?>
                                </span>
                            </p>
                            <p>
                                <label><?php esc_html_e('Filesystem base directory (tools are confined at/below this path):', 'keelhob'); ?><br>
                                <input type="text" name="fs_base" value="<?php echo esc_attr($s['fs_base']); ?>" class="regular-text kh-u-mono"></label>
                            </p>
                            <p>
                                <label><?php esc_html_e('Site label (what your agent calls this site):', 'keelhob'); ?><br>
                                <input type="text" name="site_label" value="<?php echo esc_attr((string) ($s['site_label'] ?? '')); ?>" placeholder="<?php echo esc_attr(Keelhob::site_label()); ?>" class="regular-text kh-u-mono"></label>
                                <br><span class="kh-u-sub"><?php esc_html_e('Leave blank to use the site name. Matters when one agent is connected to several sites — this is the handle you say out loud, as in "copy that snippet to the other shop".', 'keelhob'); ?></span>
                            </p>
                            <hr>
                            <p><label><input type="checkbox" name="run_snippets" value="1" <?php checked(!empty($s['run_snippets'])); ?>> <strong><?php esc_html_e('Run code snippets', 'keelhob'); ?></strong> <?php esc_html_e('(active PHP/CSS/JS/HTML snippets execute on the site; uncheck to kill all at once)', 'keelhob'); ?></label></p>
                            <p>
                                <label><strong><?php esc_html_e('Page builder', 'keelhob'); ?></strong> <?php esc_html_e('(loads matching guidance for the agent):', 'keelhob'); ?><br>
                                <select name="builder">
                                    <?php
                                    $builders = [
                                        'auto'           => __('Auto-detect', 'keelhob'),
                                        'gutenberg'      => __('Gutenberg (block editor)', 'keelhob'),
                                        'elementor_free' => __('Elementor (free) + Xpro Addons', 'keelhob'),
                                        'elementor_pro'  => __('Elementor Pro', 'keelhob'),
                                        'bricks'         => __('Bricks', 'keelhob'),
                                        'custom_php'     => __('Custom-coded (pixel-perfect PHP pages)', 'keelhob'),
                                    ];
                                    foreach ($builders as $val => $label) {
                                        printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($s['builder'] ?? 'auto', $val, false), esc_html($label));
                                    }
                                    ?>
                                </select></label>
                            </p>
                            <?php
                            $img_id = (string) ($s['image_provider'] ?? 'openai');
                            ?>
                            <p class="kh-u-sub"><strong><?php esc_html_e('AI image generation', 'keelhob'); ?></strong> — <?php esc_html_e('optional. Leave the key blank to use free Openverse stock images via image_search instead.', 'keelhob'); ?></p>
                            <?php Keelhob_Admin_Providers::render_media_provider([
                                'kind'           => 'image',
                                'providers'      => Keelhob_Image_Provider::providers(),
                                'current'        => $img_id,
                                'provider_field' => 'image_provider',
                                'key_field'      => 'image_api_key',
                                'model_field'    => 'image_api_model',
                                'endpoint_field' => 'image_api_endpoint',
                                'provider_label' => __('Image provider', 'keelhob'),
                                'key_label'      => __('Image API key', 'keelhob'),
                                'model_label'    => __('Image model', 'keelhob'),
                                'key_value'      => Keelhob_Secrets::mask((string) ($s['image_api_key'] ?? '')),
                                'model_value'    => (string) ($s['image_api_model'] ?? ''),
                                'endpoint_value' => (string) ($s['image_api_endpoint'] ?? ''),
                            ]); ?>

                            <p class="kh-u-sub"><strong><?php esc_html_e('Staging → production deploy', 'keelhob'); ?></strong> <?php esc_html_e('(optional — target for deploy_push: your live site\'s Keelhob endpoint)', 'keelhob'); ?></p>
                            <p><label><?php esc_html_e('Production site URL:', 'keelhob'); ?> <input type="text" name="deploy_target_url" value="<?php echo esc_attr($s['deploy_target_url'] ?? ''); ?>" class="regular-text kh-u-mono" placeholder="https://your-live-site.com"></label></p>
                            <p><label><?php esc_html_e('Production bearer token:', 'keelhob'); ?> <input type="password" name="deploy_target_token" value="<?php echo esc_attr(Keelhob_Secrets::mask((string) ($s['deploy_target_token'] ?? ''))); ?>" class="regular-text kh-u-mono" autocomplete="off" placeholder="<?php esc_attr_e('kh_... (the live site\'s Keelhob token)', 'keelhob'); ?>"></label></p>
                            <p><button class="button button-primary"><?php esc_html_e('Save settings', 'keelhob'); ?></button></p>
                        </form>
                    </div>

                    <div class="kh-u-card">
                        <h2><?php esc_html_e('2 · Connect your AI app', 'keelhob'); ?></h2>
                        <p><?php esc_html_e('Pick your app. Each one needs something different.', 'keelhob'); ?></p>

                        <?php if ($local_notes) : ?>
                            <div class="kh-u-note is-mid">
                                <strong><?php esc_html_e('This site is on your own computer.', 'keelhob'); ?></strong>
                                <ul class="kh-u-bullets">
                                    <?php foreach ($local_notes as $note) : ?>
                                        <li><?php echo esc_html($note); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php
                        /*
                         * THIS IS NOT THE PAGE TAB STRIP, and the two must not be
                         * confused — the page tabs are real <a href> links drawn by
                         * Keelhob_Admin::tab_strip(). This picks which AI app's
                         * config block to show, and it stays a button for one
                         * reason, stated in full in the comment below: the bearer
                         * token is flashed exactly once, so a reload destroys it.
                         *
                         * What HAS changed is the thing that was actually wrong.
                         * The old markup used the class names `kh-tabs`, `kh-tab`
                         * and `kh-panel` with inline styles. `.kh-panel` is styled
                         * in studio.css, which is enqueued on the Studio and Compose
                         * screens and nowhere else — so these blocks rendered
                         * completely unstyled on the one screen that used them,
                         * while looking styled to anyone reading the CSS. The names
                         * now carry the kh-u- prefix, which cannot collide with the
                         * forty-odd bare kh-* names the Studio already owns, and
                         * assets/css/admin.css styles them on this screen.
                         *
                         * role="tablist" is deliberately NOT used. A real tablist
                         * owes the reader arrow-key navigation, roving tabindex and
                         * aria-controls, and a half-implemented one is worse than
                         * plain buttons: it announces an interaction model the page
                         * does not honour. aria-selected on a button is enough to
                         * say which block is showing.
                         */
                        ?>
                        <div class="kh-u-clients">
                            <?php foreach ($client_list as $key => $client) : ?>
                                <button type="button" class="kh-u-client-btn" data-kh-client="<?php echo esc_attr($key); ?>" aria-selected="false">
                                    <?php echo esc_html($client['label']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <?php
                        /*
                         * Every panel is rendered, and all of them are visible until
                         * the script below hides the ones you did not pick.
                         *
                         * Two reasons it is built this way round.
                         *
                         * The token is flashed ONCE — Keelhob::take_flashed_token()
                         * hands it over and it is gone. A picker that reloaded the
                         * page to switch apps would wipe it and show the placeholder
                         * instead, which is a worse bug than the one being fixed here.
                         * So switching apps never talks to the server.
                         *
                         * And if the script does not run at all, the reader sees all
                         * four panels rather than an empty box. Clearly labelled, so
                         * that degrades to "a bit long" rather than "broken".
                         */
                        foreach ($client_list as $key => $client) :
                            $requirement = Keelhob_Connect::requirement($key, $is_http);
                            $snippet     = Keelhob_Connect::config($key, $server_name, $endpoint, $token, $is_http);
                            ?>
                            <div class="kh-u-client" data-kh-client-panel="<?php echo esc_attr($key); ?>">
                                <h3><?php echo esc_html($client['label']); ?></h3>

                                <?php if ($requirement !== '') : ?>
                                    <p class="kh-u-sub"><?php echo esc_html($requirement); ?></p>
                                <?php endif; ?>

                                <?php if ($key === 'claude-code') : ?>
                                    <p><strong><?php esc_html_e('Run this in a terminal:', 'keelhob'); ?></strong></p>
                                <?php else : ?>
                                    <p><strong><?php esc_html_e('Paste this into:', 'keelhob'); ?></strong></p>
                                    <ul class="kh-u-bullets">
                                        <?php foreach ($client['file'] as $path) : ?>
                                            <li><code><?php echo esc_html($path); ?></code></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>

                                <textarea readonly rows="<?php echo $key === 'claude-code' ? 3 : 12; ?>"
                                    class="kh-u-mono is-wide"
                                    onclick="this.select()"><?php echo esc_textarea($snippet); ?></textarea>

                                <?php if ($key === 'claude-desktop') : ?>
                                    <p class="kh-u-sub"><?php esc_html_e('Then fully quit Claude Desktop and reopen it. Closing the window is not enough.', 'keelhob'); ?></p>
                                    <p class="kh-u-sub"><?php esc_html_e('On a Microsoft Store install the second path exists and is ignored. Try the first.', 'keelhob'); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <p class="kh-u-sub"><?php esc_html_e('Using something else? Any MCP app can connect with the endpoint and token below.', 'keelhob'); ?></p>

                        <script>
                        (function () {
                            var btns   = document.querySelectorAll('.kh-u-client-btn');
                            var panels = document.querySelectorAll('[data-kh-client-panel]');
                            if (!btns.length) { return; }
                            function show(key) {
                                panels.forEach(function (p) {
                                    p.style.display = p.dataset.khClientPanel === key ? '' : 'none';
                                });
                                btns.forEach(function (b) {
                                    // The attribute, not a class. admin.css styles
                                    // [aria-selected="true"], so what a screen
                                    // reader is told and what the eye sees come
                                    // from one source and cannot drift apart.
                                    b.setAttribute('aria-selected', b.dataset.khClient === key ? 'true' : 'false');
                                });
                                // Remembered per browser, so switching screens does
                                // not send you back to an app you do not use. It is
                                // a convenience, never state the page depends on.
                                try { localStorage.setItem('keelhobConnectClient', key); } catch (e) {}
                            }
                            var saved = null;
                            try { saved = localStorage.getItem('keelhobConnectClient'); } catch (e) {}
                            var valid = Array.prototype.some.call(btns, function (b) { return b.dataset.khClient === saved; });
                            show(valid ? saved : btns[0].dataset.khClient);
                            btns.forEach(function (b) {
                                b.addEventListener('click', function () { show(b.dataset.khClient); });
                            });
                        })();
                        </script>

                        <p><strong><?php esc_html_e('Endpoint URL', 'keelhob'); ?></strong></p>
                        <input readonly value="<?php echo esc_attr($endpoint); ?>" class="regular-text kh-u-mono" onclick="this.select()">

                        <p><strong><?php esc_html_e('Bearer token', 'keelhob'); ?></strong> <?php esc_html_e('(keep secret — it grants full site access)', 'keelhob'); ?></p>
                        <?php if ($have_token) : ?>
                            <div class="kh-u-note is-bad">
                                <strong><?php esc_html_e('Copy this now, it is not shown again.', 'keelhob'); ?></strong><br>
                                <span class="kh-u-sub"><?php esc_html_e('Keelhob stores only a hash of this token, so it cannot be displayed a second time. If you lose it, regenerate below.', 'keelhob'); ?></span>
                            </div>
                            <input readonly value="<?php echo esc_attr($token); ?>" class="regular-text kh-u-mono" onclick="this.select()">
                        <?php else : ?>
                            <input readonly value="••••••••••••" class="regular-text kh-u-mono">
                            <p class="kh-u-sub">
                                <?php esc_html_e('Stored hashed and cannot be shown again. Regenerate below to get a new one; existing clients will need updating.', 'keelhob'); ?>
                                <?php
                                $last_used = (int) Keelhob::instance()->get('token_last_used', 0);
                                if ($last_used > 0) {
                                    /* translators: %s: human-readable time difference, e.g. 3 hours. */
                                    echo '<br>' . esc_html(sprintf(__('Last used %s ago.', 'keelhob'), human_time_diff($last_used, time())));
                                } else {
                                    echo '<br>' . esc_html__('Never used yet.', 'keelhob');
                                }
                                ?>
                            </p>
                        <?php endif; ?>
                        <?php
                        /*
                         * SAY IT ON THE PAGE, where somebody who is stuck is looking.
                         *
                         * The lock is checked BEFORE the token, so a new token still
                         * gets 429 and regenerating looks like it did nothing. Worse,
                         * a client still holding the OLD token re-locks the site the
                         * moment the window opens, so the wait never ends.
                         *
                         * No separate unlock button: Regenerate below already clears
                         * it, and two controls for one job is how people pick the
                         * wrong one.
                         */
                        if (Keelhob_Auth::is_locked_out()) :
                            $mins = (int) max(1, ceil(Keelhob_Auth::lock_seconds_left() / 60));
                            ?>
                            <div class="kh-u-note is-mid">
                                <p><strong><?php
                                    /* translators: %d: minutes remaining. */
                                    echo esc_html(sprintf(__('This site is refusing connections for %d more minute(s) — too many failed attempts.', 'keelhob'), $mins));
                                ?></strong></p>
                                <p><?php esc_html_e('Regenerating below clears it straight away. First close every AI client, because one still holding the old token will lock the site again the moment it clears.', 'keelhob'); ?></p>
                            </div>
                        <?php endif; ?>

                        <p class="kh-u-sub">
                            <?php esc_html_e('Update EVERY app that connects here, and delete any duplicate entry. An app left on the old token fails on every start, and ten failures in fifteen minutes locks the site — including for the one you just fixed.', 'keelhob'); ?>
                        </p>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kh-u-actions" onsubmit="return confirm('<?php echo esc_js(__('Regenerating the token will disconnect existing clients. Continue?', 'keelhob')); ?>');">
                            <?php wp_nonce_field('keelhob_save'); ?>
                            <input type="hidden" name="action" value="keelhob_save">
                            <input type="hidden" name="keelhob_action" value="regenerate_token">
                            <button class="button"><?php esc_html_e('Regenerate token', 'keelhob'); ?></button>
                        </form>

                        <p class="kh-u-sub"><?php esc_html_e('Application Password instead of a token: send Authorization: Basic base64(username:app_password) to the same endpoint. Make one under Users → Profile.', 'keelhob'); ?></p>
                    </div>
                </div>

                <div class="kh-u-col is-side kh-u-stack">
                    <div class="kh-u-card">
                        <h2><?php esc_html_e('Status', 'keelhob'); ?></h2>
                        <?php
                        /*
                         * The word, not the colour. #16a34a as text measured
                         * 2.84:1 on --kh-card in dark mode, and "#888" for OFF
                         * was 3.54:1 even on white — so the one line on this
                         * card that says whether an AI agent may change the site
                         * was the hardest thing on it to read. It is --kh-ink
                         * now, with the state in the dot beside it.
                         */
                        ?>
                        <p><?php esc_html_e('Abilities:', 'keelhob'); ?>
                            <span class="kh-u-pill<?php echo $enabled ? ' is-on' : ''; ?>">
                                <span class="kh-u-dot <?php echo $enabled ? 'is-good' : 'is-off'; ?>" aria-hidden="true"></span>
                                <?php echo $enabled ? esc_html__('ENABLED', 'keelhob') : esc_html__('DISABLED', 'keelhob'); ?>
                            </span>
                        </p>
                        <p><?php esc_html_e('Registered tools:', 'keelhob'); ?> <strong><?php echo count(Keelhob::instance()->registry->names()); ?></strong></p>
                        <p><?php esc_html_e('WooCommerce:', 'keelhob'); ?> <strong><?php echo class_exists('WooCommerce') ? esc_html__('detected ✓', 'keelhob') : esc_html__('not active', 'keelhob'); ?></strong></p>
                        <p><?php esc_html_e('Skills:', 'keelhob'); ?> <strong><?php echo count(Keelhob::instance()->skills->all()); ?></strong> · <?php esc_html_e('Memory:', 'keelhob'); ?> <strong><?php echo count(Keelhob::instance()->memory->all()); ?></strong> · <?php esc_html_e('Snippets:', 'keelhob'); ?> <strong><?php echo count(Keelhob_Snippets::all()); ?></strong></p>
                        <?php
                        /*
                         * The MCP endpoint only answers authenticated POSTs, so linking to it
                         * just showed the browser a 401 and read as a broken feature. Show the
                         * URL to copy instead, and keep the link only for the health check,
                         * which is the one route a browser can actually load.
                         */
                        ?>
                        <p><?php esc_html_e('MCP endpoint (needs a token — not viewable in a browser):', 'keelhob'); ?></p>
                        <p><input type="text" readonly value="<?php echo esc_attr($endpoint); ?>" class="regular-text kh-u-mono is-wide" onclick="this.select();"></p>
                        <p><a href="<?php echo esc_url(rest_url(KEELHOB_REST_NAMESPACE . '/status')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open health check ↗', 'keelhob'); ?></a></p>
                    </div>

                    <div class="kh-u-card">
                        <h2><?php esc_html_e('Tool catalog', 'keelhob'); ?></h2>
                        <div class="kh-u-catalog">
                            <?php foreach (Keelhob::instance()->registry->names() as $name) : ?>
                                <div><?php echo esc_html($name); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kh-u-sec">
                <div class="kh-u-sec-head">
                    <h2><?php esc_html_e('Capabilities — what the agent may touch', 'keelhob'); ?></h2>
                    <p class="kh-u-measure"><?php esc_html_e('Turn an area off and it is off-limits: hidden from the agent, refused if called, named as blocked in every session. The areas that can cost you money, expose customers or run code carry a Sensitive badge.', 'keelhob'); ?></p>
                </div>
                <div class="kh-u-note is-mid kh-u-measure">
                    <p><strong><?php esc_html_e('Note:', 'keelhob'); ?></strong> <?php esc_html_e('to truly wall off a sensitive area, also disable Code execution — otherwise the agent could reach it via raw PHP/SQL.', 'keelhob'); ?></p>
                </div>
                <?php
                /*
                 * THE SAVE PATH IS UNTOUCHED. Same `cap[<key>]` field names,
                 * same `keelhob_save` nonce, same admin-post.php target, same
                 * `keelhob_action=capabilities`, same real checkboxes. Only the
                 * markup around them changed, and the checkbox is restyled by
                 * CSS rather than replaced: no div with role="switch", no
                 * JavaScript anywhere near a permission, and the whole grid
                 * still works with this stylesheet missing.
                 *
                 * ONE CARD PER GROUP, NEVER PER TOOL. The tools are named inside
                 * the card as read-only chips so the owner can see what a group
                 * contains, but there is no second switch beside them:
                 * class-capabilities.php warns in its own comment that two
                 * permission lists drift, and the way this one would drift is by
                 * switching something sensitive on for everybody.
                 */
                ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('keelhob_save'); ?>
                    <input type="hidden" name="action" value="keelhob_save">
                    <input type="hidden" name="keelhob_action" value="capabilities">
                    <div class="kh-u-caps">
                        <?php foreach (Keelhob_Capabilities::groups() as $key => $g) :
                            $on  = Keelhob_Capabilities::is_group_enabled($key);
                            /*
                             * The old screen hung this text off a `title` on a
                             * small "i" bubble: not reachable by keyboard, not
                             * announced on touch, and drawn in #217296, which is
                             * 2.9:1 on the dark panel. Every word of it survives
                             * — in a <details> that is closed by default, which
                             * is PLAN §2.7's own prescription for moving the
                             * "why" into the component without deleting it.
                             */
                            $tip = sprintf(
                                /* translators: 1: what the capability lets the agent do, 2: comma-separated tool names */
                                __('WHEN ON — the agent can: %1$s Tools: %2$s.', 'keelhob'),
                                $g['desc'],
                                implode(', ', $g['tools'])
                            )
                                 . ' ' . __('WHEN OFF — these tools are hidden from the agent and refused if called.', 'keelhob')
                                 . (!empty($g['sensitive']) ? ' ' . __('This is a SENSITIVE area (money/data/code) — leave it off until you trust the agent.', 'keelhob') : ''); ?>
                            <div class="kh-u-cap<?php echo $on ? '' : ' is-off'; ?>">
                                <div class="kh-u-cap-top">
                                    <span class="kh-u-icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr(Keelhob_Brand::group_icon((string) $key)); ?>"></span></span>
                                    <span class="kh-u-cap-id">
                                        <label class="kh-u-cap-label" for="kh-cap-<?php echo esc_attr($key); ?>"><?php echo esc_html($g['label']); ?></label>
                                        <?php if (!empty($g['sensitive'])) : ?>
                                            <span class="kh-u-badge"><?php esc_html_e('Sensitive', 'keelhob'); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <input type="checkbox" class="kh-u-switch" id="kh-cap-<?php echo esc_attr($key); ?>" name="cap[<?php echo esc_attr($key); ?>]" value="1" <?php checked($on); ?>>
                                </div>
                                <p class="kh-u-cap-desc"><?php echo esc_html($g['desc']); ?></p>
                                <span class="kh-u-chips">
                                    <?php foreach ($g['tools'] as $kh_tool) : ?>
                                        <span class="kh-u-chip"><?php echo esc_html($kh_tool); ?></span>
                                    <?php endforeach; ?>
                                </span>
                                <details class="kh-u-more">
                                    <summary><?php esc_html_e('What this covers', 'keelhob'); ?></summary>
                                    <p><?php echo esc_html($tip); ?></p>
                                </details>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="kh-u-actions"><button class="button button-primary"><?php esc_html_e('Save capabilities', 'keelhob'); ?></button></p>
                </form>
            </div>
        <?php Keelhob_Admin::shell_close(); ?>
        <?php
    }

    /**
     * The Overview block at the top of the Connect screen.
     *
     * WHY IT LIVES HERE AND NOT ON A SEVENTH TAB. The brief left the choice
     * open, with "fewer new URLs" as the tie-breaker, and this is zero: no new
     * page slug, no new menu entry, no change to Keelhob_Admin::tabs(), nothing
     * new to add to DOCUMENTATION.md (which is mirrored to the live site's
     * /docs), and nothing for a bookmark, a Telegram link or a notifier link to
     * miss. It is also the honest shape of the screen: Connect is already the
     * page an owner opens first, and an overview that is one click away from
     * the first screen is an overview nobody reads.
     *
     * Four blocks, in the order the questions get asked:
     *   1. How much can the AI do?  — the three band cards, a VIEW ONLY.
     *   2. Four numbers.
     *   3. What has it been doing?  — recent activity, with an empty state.
     *   4. What wants me?           — needs attention, with an empty state.
     *
     * @param array<string, mixed> $settings The settings array render() already read.
     * @param bool                 $enabled  Whether AI abilities are switched on.
     */
    private function overview(array $settings, bool $enabled): void
    {
        $caps  = Keelhob_Admin::capability_state();
        $bands = Keelhob_Admin::bands();
        $flags = Keelhob_Admin::needs_attention();

        $logger = Keelhob::instance()->logger;
        $recent = $logger->recent(5);

        $tools   = Keelhob::instance()->registry->names();
        $allowed = 0;
        foreach ($tools as $kh_tool) {
            if (Keelhob_Capabilities::tool_allowed((string) $kh_tool)) {
                $allowed++;
            }
        }

        $snippets     = Keelhob_Snippets::all();
        $snippets_on  = 0;
        foreach ($snippets as $kh_snip) {
            if ((int) ($kh_snip['active'] ?? 0) === 1) {
                $snippets_on++;
            }
        }
        ?>
        <div class="kh-u-sec">
            <div class="kh-u-sec-head">
                <h2><?php esc_html_e('How much can your AI do?', 'keelhob'); ?></h2>
                <?php
                /*
                 * "Where the switches below have left it", not "pick one".
                 *
                 * These three cards READ the stored capability groups. They are
                 * not a control and there is deliberately no button on them —
                 * the reasons are set out at length above
                 * Keelhob_Admin::capability_state(), and the short version is
                 * that this plugin's real default already has snippets, files
                 * and raw PHP ON, so a one-click "Building" preset would switch
                 * off code a live site is running, a one-click "Full access"
                 * would hand over payments, orders, users and deploys, and
                 * writing a value for every group would permanently pin groups
                 * the owner has never expressed an opinion about.
                 */
                ?>
                <p class="kh-u-sub"><?php esc_html_e('This is where the switches further down have left it. Change it there.', 'keelhob'); ?></p>
            </div>
            <div class="kh-u-modes">
                <?php foreach ($bands as $kh_key => $kh_band) :
                    $is_current = ($kh_key === $caps['band']); ?>
                    <div class="kh-u-mode<?php echo $is_current ? ' is-current' : ''; ?>"<?php echo $is_current ? ' aria-current="true"' : ''; ?>>
                        <div class="kh-u-mode-top">
                            <span class="kh-u-mode-name"><?php echo esc_html($kh_band['label']); ?></span>
                            <?php if ($is_current) : ?>
                                <span class="kh-u-mode-pill"><?php esc_html_e('Current', 'keelhob'); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="kh-u-mode-blurb"><?php echo esc_html($kh_band['blurb']); ?></p>
                        <?php
                        /*
                         * The current card says what IS on. The other two say
                         * what would have to be true to be in that band — a
                         * fact about the switches, not an instruction and not a
                         * link that would do it.
                         */
                        ?>
                        <?php if ($is_current) : ?>
                            <p class="kh-u-mode-change">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: 1: how many capability areas are on, 2: how many there are in total. */
                                    __('%1$d of %2$d areas are on right now.', 'keelhob'),
                                    count($caps['on']),
                                    $caps['total']
                                ));
                                ?>
                                <?php
                                /*
                                 * Said out loud on the Building card, because
                                 * "Building" must not be read as "nothing
                                 * sensitive is on". Keelhob ships with
                                 * snippets, files and raw code execution
                                 * switched on, and those are sensitive groups
                                 * that simply are not among the eight that ship
                                 * off. The band is defined by those eight; this
                                 * line stops the label overstating the case.
                                 */
                                ?>
                                <?php if ($caps['sensitive_on'] !== []) : ?>
                                    <?php
                                    echo esc_html(sprintf(
                                        /* translators: %d: how many of the areas that are on are marked sensitive. */
                                        _n(
                                            '%d of them is marked sensitive.',
                                            '%d of them are marked sensitive.',
                                            count($caps['sensitive_on']),
                                            'keelhob'
                                        ),
                                        count($caps['sensitive_on'])
                                    ));
                                    ?>
                                <?php endif; ?>
                            </p>
                        <?php else : ?>
                            <p class="kh-u-mode-change"><?php echo esc_html($kh_band['change']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php
        /*
         * FOUR NUMBERS THAT ARE TRUE, rather than the four the design sketch
         * asked for. "Actions this week" and "Success 100%" are not in it, and
         * that is on purpose: Keelhob_Logger exposes recent() and count_all()
         * and nothing date-ranged, the table is trimmed to a row cap, and
         * counting a week out of the most recent hundred rows would print a
         * number that silently understates on exactly the busy site where it
         * matters. class-logger.php is another lane's file this round, so the
         * tiles report what can be counted exactly. The missing two are noted in
         * BUILD-NOTES.md for a later pass that can add a counting query.
         */
        ?>
        <div class="kh-u-sec">
            <div class="kh-u-tiles">
                <div class="kh-u-tile">
                    <span class="kh-u-tile-value"><?php echo esc_html(number_format_i18n(count($caps['on'])) . ' / ' . number_format_i18n($caps['total'])); ?></span>
                    <span class="kh-u-tile-label"><?php esc_html_e('Areas switched on', 'keelhob'); ?></span>
                </div>
                <div class="kh-u-tile">
                    <span class="kh-u-tile-value"><?php echo esc_html(number_format_i18n($allowed) . ' / ' . number_format_i18n(count($tools))); ?></span>
                    <span class="kh-u-tile-label"><?php esc_html_e('Tools the agent may call', 'keelhob'); ?></span>
                </div>
                <div class="kh-u-tile">
                    <span class="kh-u-tile-value"><?php echo esc_html(number_format_i18n($snippets_on) . ' / ' . number_format_i18n(count($snippets))); ?></span>
                    <span class="kh-u-tile-label"><?php esc_html_e('Snippets running', 'keelhob'); ?></span>
                </div>
                <div class="kh-u-tile">
                    <span class="kh-u-tile-value"><?php echo esc_html(number_format_i18n($logger->count_all())); ?></span>
                    <span class="kh-u-tile-label">
                        <?php
                        echo empty($settings['log_calls'])
                            ? esc_html__('Tool calls logged — logging is off', 'keelhob')
                            : esc_html__('Tool calls logged', 'keelhob');
                        ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="kh-u-sec">
            <div class="kh-u-cols">
                <div class="kh-u-col">
                    <div class="kh-u-card">
                        <h2><?php esc_html_e('Recent activity', 'keelhob'); ?></h2>
                        <?php if ($recent === []) : ?>
                            <p class="kh-u-empty">
                                <?php
                                echo $enabled
                                    ? esc_html__('Nothing yet. What the agent does will appear here.', 'keelhob')
                                    : esc_html__('Nothing yet — AI abilities are switched off.', 'keelhob');
                                ?>
                            </p>
                        <?php else : ?>
                            <ul class="kh-u-feed">
                                <?php foreach ($recent as $kh_row) :
                                    $kh_ok = !isset($kh_row['ok']) || (int) $kh_row['ok'] === 1; ?>
                                    <li>
                                        <span class="kh-u-dot <?php echo $kh_ok ? 'is-good' : 'is-off'; ?>" aria-hidden="true"></span>
                                        <span class="kh-u-feed-name"><?php echo esc_html((string) ($kh_row['tool'] ?? '')); ?></span>
                                        <?php
                                        // The words, never the dot alone: a
                                        // failed call that only differs by a
                                        // ring colour says nothing to a screen
                                        // reader or a colour-blind reader.
                                        ?>
                                        <span class="kh-u-feed-meta">
                                            <?php echo $kh_ok ? esc_html__('worked', 'keelhob') : esc_html__('failed', 'keelhob'); ?>
                                            <?php
                                            $kh_at = strtotime((string) ($kh_row['created_at'] ?? ''));
                                            if ($kh_at) {
                                                echo ' · ' . esc_html(sprintf(
                                                    /* translators: %s: human-readable time difference, e.g. "2 hours". */
                                                    __('%s ago', 'keelhob'),
                                                    human_time_diff($kh_at, time())
                                                ));
                                            }
                                            ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="kh-u-actions"><a href="<?php echo esc_url(admin_url('admin.php?page=keelhob-activity')); ?>"><?php esc_html_e('See all activity', 'keelhob'); ?></a></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="kh-u-col">
                    <div class="kh-u-card">
                        <h2><?php esc_html_e('Needs attention', 'keelhob'); ?></h2>
                        <?php if ($flags === []) : ?>
                            <?php
                            /*
                             * The empty state is the whole point of this card.
                             * A dashboard that only ever has something to say
                             * when it is broken is a dashboard nobody opens; one
                             * that says "all good" is one somebody checks.
                             */
                            ?>
                            <p class="kh-u-empty"><?php esc_html_e('All good. Nothing needs you right now.', 'keelhob'); ?></p>
                        <?php else : ?>
                            <ul class="kh-u-flags">
                                <?php foreach ($flags as $kh_flag) : ?>
                                    <li class="is-<?php echo esc_attr($kh_flag['level']); ?>">
                                        <span class="kh-u-flag-tag">
                                            <?php
                                            echo $kh_flag['level'] === 'bad'
                                                ? esc_html__('Fix', 'keelhob')
                                                : esc_html__('Check', 'keelhob');
                                            ?>
                                        </span>
                                        <span><?php echo esc_html($kh_flag['text']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_guide(): void
    {
        // Map tool name → description straight from the live registry so this
        // guide always documents exactly what's installed.
        $desc = [];
        foreach (Keelhob::instance()->registry->list() as $t) {
            $desc[$t['name']] = $t['description'];
        }
        $short = static function (string $s): string {
            $s = preg_replace('/\s+/', ' ', $s);
            return mb_strlen($s) > 200 ? mb_substr($s, 0, 200) . '…' : $s;
        };
        $enabled = Keelhob::instance()->abilities_enabled();
        // Style value only, not a whole attribute — so it can be escaped at output.
        $h = 'margin:28px 0 10px;';
        ?>
        <?php Keelhob_Admin::shell_open('keelhob-guide'); ?>
            <div class="kh-u-prose">
            <h2><?php esc_html_e('Master Guide', 'keelhob'); ?></h2>
            <p style="font-size:15px;max-width:800px;"><?php esc_html_e('Everything this plugin lets your AI do, in plain English. Read this once and you\'ll know what to turn on, what to leave off, and how to get the best out of it — no coding required.', 'keelhob'); ?></p>

            <div class="notice notice-warning" style="max-width:800px;"><p><strong><?php esc_html_e('Golden rule:', 'keelhob'); ?></strong> <?php esc_html_e('use this on a development or staging site with backups. When AI abilities are ON, a connected agent can change anything. Don\'t point it at a live store you can\'t afford to have edited without review.', 'keelhob'); ?></p></div>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('What is Keelhob?', 'keelhob'); ?></h2>
            <p><?php esc_html_e('Keelhob turns your WordPress site into something an AI assistant (Claude, ChatGPT/Codex, Gemini, Cursor…) can operate directly. Instead of you clicking around the dashboard, you chat with your AI — "add this product", "write a blog post", "how are sales?" — and it does the work on your site through a set of tools this plugin provides. It speaks a standard called MCP (Model Context Protocol), so it works with most AI apps, not just one.', 'keelhob'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Seeing what the AI installed', 'keelhob'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %s: link to the Snippets page */
                    esc_html__('When your AI writes code onto this site, every piece of it is listed on the %s page — what it is, why it was written, what it changes, and a switch to turn it off. That page is the first place to look if the site starts behaving oddly.', 'keelhob'),
                    esc_html__('Snippets', 'keelhob')
                );
                ?>
            </p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Get started in 3 steps', 'keelhob'); ?></h2>
            <ol style="font-size:14px;line-height:1.9;max-width:800px;">
                <li>
                    <strong><?php esc_html_e('Turn on AI abilities', 'keelhob'); ?></strong>
                    <?php
                    printf(
                        /* translators: %s: link to the Connect page */
                        esc_html__('on the %s page (master switch). Currently:', 'keelhob'),
                        '<a href="' . esc_url(admin_url('admin.php?page=keelhob')) . '">' . esc_html__('Connect', 'keelhob') . '</a>'
                    );
                    ?>
                    <?php
                    /*
                     * A1.1 construction: the state is in the DOT, the word is in
                     * --kh-ink-2 on the pill's own --kh-card fill. The old inline
                     * #16a34a measured 2.85:1 on the Guide's panel background --
                     * the single word telling an owner whether an AI may change
                     * their site was the least readable thing on the screen.
                     * Measured in Chromium on the rig: 13.69:1 light, 12.09:1
                     * dark. The pill
                     * paints its own background, so it does not depend on an
                     * ancestor core component.
                     */
                    ?>
                    <span class="kh-u-pill"><span class="kh-u-dot <?php echo $enabled ? 'is-good' : 'is-off'; ?>" aria-hidden="true"></span><strong><?php echo $enabled ? esc_html__('ON', 'keelhob') : esc_html__('OFF', 'keelhob'); ?></strong></span>.
                </li>
                <li><strong><?php esc_html_e('Connect your AI', 'keelhob'); ?></strong> — <?php esc_html_e('copy the ready-made command on the Connect page into your AI app (e.g. Claude Code). It links your AI to this site securely with a token.', 'keelhob'); ?></li>
                <li><strong><?php esc_html_e('Start chatting.', 'keelhob'); ?></strong> <?php esc_html_e('Try: "List the plugins on this site" or "Show me this month\'s sales." If it answers, you\'re set.', 'keelhob'); ?></li>
            </ol>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Your two safety controls', 'keelhob'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('1. The master switch (Connect page) turns all abilities on or off in one click. 2. Capability toggles (Connect page, lower down) let you allow some areas and forbid others — so you can let the AI write blog posts but never touch payments, for example. A forbidden area is hidden from the AI entirely and refused if it tries.', 'keelhob'); ?></p>

            <?php
            /*
             * THIS TABLE USED TO LIE, and it is worth saying how.
             *
             * It listed "Code execution · Filesystem · Site admin · Autonomy"
             * under "turn OFF until you trust it", which reads as a description
             * of what the plugin ships. It is not. Those four, plus Store and
             * Snippets, are ON the moment abilities are switched on — only
             * eight groups start off (payments, store orders, shipping, tax,
             * users, updates, email, deploy).
             *
             * So the guide was telling somebody to leave off six switches that
             * were already on behind them. Somebody following it exactly would
             * believe their site was walled off when it was not.
             *
             * The table is now split by what the DEFAULTS actually are, read
             * from the live capability list rather than typed here — a
             * hand-written list is what drifted in the first place.
             */
            $on_by_default  = [];
            $off_by_default = [];
            foreach (Keelhob_Capabilities::groups() as $gkey => $ginfo) {
                $label = $ginfo['label'] . (!empty($ginfo['sensitive']) ? ' ●' : '');
                if (Keelhob_Capabilities::default_enabled($gkey)) {
                    $on_by_default[] = $label;
                } else {
                    $off_by_default[] = $label;
                }
            }
            ?>
            <h3 style="margin-top:18px;"><?php esc_html_e('What is on before you change anything', 'keelhob'); ?></h3>
            <p style="max-width:800px;">
                <?php esc_html_e('Switching on AI abilities switches on the left-hand column too. A ● marks a sensitive area. If you want a quieter start, turn off anything in the left column you do not need — especially Raw code execution and Filesystem.', 'keelhob'); ?>
            </p>
            <table class="widefat striped" style="max-width:800px;">
                <thead><tr>
                    <th><?php esc_html_e('ON as soon as abilities are on', 'keelhob'); ?></th>
                    <th><?php esc_html_e('OFF until you switch them on', 'keelhob'); ?></th>
                </tr></thead>
                <tbody><tr>
                    <td style="vertical-align:top;"><?php echo esc_html(implode(' · ', $on_by_default)); ?></td>
                    <td style="vertical-align:top;"><?php echo esc_html(implode(' · ', $off_by_default)); ?></td>
                </tr></tbody>
            </table>
            <?php
            /*
             * These two are safety text and not one word of either is cut.
             * They were drawn in inline #b45309, which fails AA on the panel
             * background in both modes -- on the paragraph that tells an owner
             * the filesystem tool can read their database password.
             *
             * Rebuilt as the A1.1 callout: --kh-mid-soft as the FILL, --kh-mid
             * as the left BORDER only, --kh-ink for the words. Measured in
             * Chromium on the rig: 17.20:1 on #fff7e6 in light, 14.34:1 on
             * #2a1c08 in dark. The border is a component
             * boundary and needs 3:1, not 4.5:1.
             */
            ?>
            <div class="kh-u-note is-mid kh-u-measure">
                <p>
                    <strong><?php esc_html_e('If you only want the AI to write content:', 'keelhob'); ?></strong>
                    <?php esc_html_e('turn off Raw code execution, Filesystem, Site admin and Autonomy. Filesystem can read wp-config.php, which holds your database password and security keys.', 'keelhob'); ?>
                </p>
            </div>
            <div class="kh-u-note is-mid kh-u-measure">
                <p><strong><?php esc_html_e('Important:', 'keelhob'); ?></strong> <?php esc_html_e('"Code execution" is the master key — with raw PHP the AI can reach any area. To truly wall off something sensitive, turn Code execution OFF too.', 'keelhob'); ?></p>
            </div>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('What the AI can do — every area & tool', 'keelhob'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Each area below is one capability toggle. When it\'s ON, the AI can use the listed tools.', 'keelhob'); ?></p>
            <?php foreach (Keelhob_Capabilities::groups() as $key => $g) :
                $on = Keelhob_Capabilities::is_group_enabled($key); ?>
                <?php
                /*
                 * WHY THIS IS kh-u-card AND NOT CORE'S .card. Core paints .card
                 * #fff unconditionally and knows nothing about .kh-ui, so under
                 * a dark admin scheme these twenty-one cards stayed white while
                 * the panel around them went near-black. Same reason the
                 * satellite screens dropped `widefat striped` at 12:40.
                 *
                 * The "ENABLED" / "OFF" word was the bulk of the defect: 13
                 * inline #16a34a labels at 2.85:1 and 9 inline #888 ones. Both
                 * are now kh-u-pill -- state in the dot, word in --kh-ink-2 on
                 * the pill's own fill. Measured in Chromium on the rig:
                 * 13.69:1 light, 12.09:1 dark, for both states.
                 *
                 * The sensitive marker keeps its exact words and becomes the
                 * copper-FILL badge; #b91c1c as text failed on the dark card.
                 * Copper is never text on light -- hard rule 7 -- so the badge
                 * is a fill with near-black words on it. Measured:
                 * 5.47:1 light, 6.96:1 dark.
                 *
                 * The group description drops `color:#555` and inherits
                 * --kh-ink-2. Measured: 13.69:1 light, 12.09:1 dark.
                 */
                ?>
                <div class="kh-u-card" style="margin:10px 0;">
                    <h3 style="margin:.2em 0;">
                        <?php echo esc_html($g['label']); ?>
                        <?php if (!empty($g['sensitive'])) : ?>
                            <span class="kh-u-badge"><?php esc_html_e('● sensitive', 'keelhob'); ?></span>
                        <?php endif; ?>
                        <span class="kh-u-pill" style="float:right;"><span class="kh-u-dot <?php echo $on ? 'is-good' : 'is-off'; ?>" aria-hidden="true"></span><?php echo $on ? esc_html__('ENABLED', 'keelhob') : esc_html__('OFF', 'keelhob'); ?></span>
                    </h3>
                    <p style="margin:.2em 0 .6em;"><?php echo esc_html($g['desc']); ?></p>
                    <table class="widefat" style="font-size:12px;"><tbody>
                        <?php foreach ($g['tools'] as $tool) : ?>
                            <tr><td style="width:180px;"><code><?php echo esc_html($tool); ?></code></td><td><?php echo esc_html(isset($desc[$tool]) ? $short($desc[$tool]) : '—'); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody></table>
                </div>
            <?php endforeach; ?>

            <h3 style="margin-top:18px;"><?php esc_html_e('Always available (read-only)', 'keelhob'); ?></h3>
            <p style="max-width:800px;"><?php esc_html_e('These never turn off, so you can always ask what\'s going on: get_site_info, get_dashboard, memory_list, skill_list, task_list.', 'keelhob'); ?></p>

            <?php
            /*
             * The snippets engine had no section here at all.
             *
             * That is a gap with a shape we have seen before on this product:
             * the feature shipped, works, and is the ONE part of the plugin a
             * person uses with no AI connected and no abilities switched on —
             * and the guide that claims to cover "everything this plugin lets
             * you do" never mentioned it. Conditional logic and revisions were
             * likewise absent, which is exactly why both were reported as
             * missing features by somebody who had them installed.
             */
            ?>
            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Code snippets — the part that needs no AI', 'keelhob'); ?></h2>
            <p style="max-width:800px;">
                <?php
                printf(
                    /* translators: %s: link to the Snippets screen. */
                    esc_html__('%s is a full code editor inside WordPress. It works the moment you activate the plugin: no AI connected, no abilities switched on, no account. If you installed Keelhob only for this, you never have to touch the rest.', 'keelhob'),
                    '<a href="' . esc_url(admin_url('admin.php?page=' . Keelhob_Studio::SLUG)) . '"><strong>' . esc_html__('Keelhob → Snippets', 'keelhob') . '</strong></a>'
                );
                ?>
            </p>
            <table class="widefat striped" style="max-width:800px;">
                <tbody>
                    <tr>
                        <td style="width:190px;"><strong><?php esc_html_e('Write PHP, CSS, JS or HTML', 'keelhob'); ?></strong></td>
                        <td><?php esc_html_e('A real editor with line numbers, syntax colouring and bracket matching. Do NOT paste an opening <?php tag — start at your first line of code.', 'keelhob'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('An on/off switch per snippet', 'keelhob'); ?></strong></td>
                        <td><?php esc_html_e('Off means the code does nothing. On means it runs on your site. Flip it back any time from the list.', 'keelhob'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('It will not let broken PHP go live', 'keelhob'); ?></strong></td>
                        <td><?php esc_html_e('Every PHP snippet is really parsed before it can activate. If it does not parse, it is saved switched off with the error attached — your work is never thrown away. And if a live snippet crashes the front page anyway, Keelhob switches it off by itself.', 'keelhob'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Smart conditional logic', 'keelhob'); ?></strong></td>
                        <td><?php esc_html_e('Tick the box under the code box to say WHEN a snippet may run: logged in or out, a user role, a page type, a post type, one post ID, a URL path, or mobile versus desktop. Match ALL of your rules or ANY of them. With no rules it simply runs wherever "Where it runs" says.', 'keelhob'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Revisions', 'keelhob'); ?></strong></td>
                        <td><?php esc_html_e('Every change to the code keeps a copy of what was there before, listed in the panel on the right with a Restore button. Restoring saves your current code to the history first, and leaves the snippet switched off so you can read it before it runs.', 'keelhob'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Write one by talking to an AI', 'keelhob'); ?></strong></td>
                        <td><?php esc_html_e('The AI Compose tab is a chat. Say what you want in plain English and keep talking — ask for changes, point out a mistake, ask why it did something. It remembers the whole conversation. Every block of code it sends is syntax-checked on the spot, and if the check fails the error is handed straight back to the AI to fix. Nothing is saved until you click Save on a block, and it always saves switched off. Leaving the tab clears the chat. This one needs an AI key under Automation.', 'keelhob'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Your agent can use it too', 'keelhob'); ?></strong></td>
                        <td><?php esc_html_e('If you do connect an AI, the same snippets are the snippet_save, snippet_list and snippet_toggle tools — so "add a banner for logged-out visitors on mobile" and the rule builder are two doors into one thing. Say "tell me about snippet 7" to have it explain one.', 'keelhob'); ?></td>
                    </tr>
                </tbody>
            </table>
            <p style="max-width:800px;">
                <?php esc_html_e('Deleting a snippet deletes its revisions with it. Deleting the whole plugin keeps everything, so reinstalling puts your snippets back — unless you tick "Delete all Keelhob data" in Settings first.', 'keelhob'); ?>
            </p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Run the shop on autopilot (Autonomy)', 'keelhob'); ?></h2>
            <p style="max-width:800px;">
                <?php
                printf(
                    /* translators: %s: link to the Automation tab */
                    esc_html__('On the %s tab you can:', 'keelhob'),
                    '<a href="' . esc_url(admin_url('admin.php?page=keelhob-automation')) . '">' . esc_html__('Automation', 'keelhob') . '</a>'
                );
                ?>
            </p>
            <ul style="font-size:14px;line-height:1.8;max-width:800px;">
                <li><strong><?php esc_html_e('Ask "what\'s going on"', 'keelhob'); ?></strong> <?php esc_html_e('anytime — sales, orders needing attention, low stock, updates — via the live dashboard (no login needed once your AI is connected).', 'keelhob'); ?></li>
                <li><strong><?php esc_html_e('Schedule a daily digest', 'keelhob'); ?></strong> <?php esc_html_e('emailed/Telegram\'d to you — no API key required.', 'keelhob'); ?></li>
                <li><strong><?php esc_html_e('Let it act on a schedule', 'keelhob'); ?></strong> — <?php esc_html_e('e.g. "every morning, fulfil paid orders and publish due drafts." This needs an API key from Anthropic, OpenAI or Google (you pick the provider and add the key there) because the site runs its own AI to do the work. Guardrails cap refunds and require you to opt in before it does anything destructive.', 'keelhob'); ?></li>
            </ul>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Run your site from your phone (Telegram)', 'keelhob'); ?></h2>
            <p style="max-width:800px;">
                <?php esc_html_e('You don\'t need Claude Code, a terminal or a laptop to use any of this. Connect a Telegram bot and you can just message your site: "how did the store do this week?", "publish the draft about winter tyres", "the checkout footer is broken, fix it." The site\'s own AI key does the work and reports back.', 'keelhob'); ?>
            </p>
            <ul style="font-size:14px;line-height:1.8;max-width:800px;">
                <li><strong><?php esc_html_e('Set it up in two minutes', 'keelhob'); ?></strong> — <?php esc_html_e('message @BotFather on Telegram, send /newbot, paste the token into Automation → Notifications, then tick "Accept messages from Telegram" and press Connect.', 'keelhob'); ?></li>
                <li><strong><?php esc_html_e('It remembers the conversation', 'keelhob'); ?></strong> <?php esc_html_e('so follow-ups work. Send /clear to make it forget and start fresh.', 'keelhob'); ?></li>
                <li><strong><?php esc_html_e('Talk or show, not just type', 'keelhob'); ?></strong> — <?php esc_html_e('send a voice note and it transcribes what you said (showing you the transcript first), or send a screenshot of something broken and it looks at it.', 'keelhob'); ?></li>
                <li><strong><?php esc_html_e('It shows its work', 'keelhob'); ?></strong> — <?php esc_html_e('one message updates as it goes, so you can see which tools it is using instead of waiting in silence.', 'keelhob'); ?></li>
                <li><strong><?php esc_html_e('Changed its mind? /undo', 'keelhob'); ?></strong> — <?php esc_html_e('puts back the posts, pages and products it changed on its last turn. It does not cover settings, plugins, files or code snippets, and it tells you so.', 'keelhob'); ?></li>
                <li><strong><?php esc_html_e('Free commands', 'keelhob'); ?></strong> — <?php esc_html_e('/status, /usage, /tasks and /model are answered by the plugin itself and cost nothing.', 'keelhob'); ?></li>
                <li><strong><?php esc_html_e('Only you can drive it', 'keelhob'); ?></strong> — <?php esc_html_e('only chat IDs you allowlist get answered, each with a role (admin, editor or read-only viewer), and destructive or financial actions stay locked until you approve them with a button.', 'keelhob'); ?></li>
            </ul>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Let the site tell you first', 'keelhob'); ?></h2>
            <p style="max-width:800px;">
                <?php esc_html_e('Turn on event notifications and the site messages you when something happens: a paid order (above a value you choose), stock running low or selling out, a piece of live code that crashed and switched itself off, or new updates waiting. Every event is individually switchable, and nothing is announced twice.', 'keelhob'); ?>
            </p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Build websites & content', 'keelhob'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Ask for a page, homepage, or blog post and the AI follows a built-in designer\'s playbook (modern layout, responsive on phone/tablet/desktop, real images via free Openverse search). It can build pixel-perfect custom pages or editable Gutenberg/Elementor pages — just say which you prefer.', 'keelhob'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Go live: staging → production', 'keelhob'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Build safely on a staging site, then ship to your live site in one step with deploy_push (set the production URL + token in Connect settings), or export a bundle and import it on the live site. Re-deploying updates instead of duplicating.', 'keelhob'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Skills & memory (why it gets smarter)', 'keelhob'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Memory = facts it remembers between chats ("our brand color is #f8ca50"). Skills = playbooks it follows automatically (this plugin ships expert skills for WooCommerce, SEO, blogging, design, and deploys). Both are shared by every AI you connect, so your whole team\'s AI stays consistent.', 'keelhob'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Copy-paste starter prompts', 'keelhob'); ?></h2>
            <?php
            /*
             * The fill is --kh-soft, not the literal #f6f7f7 it used to be. An
             * inline literal beats any stylesheet rule, so under a dark scheme
             * this block stayed near-white and the panel ink landed on it at
             * 1.08:1 — the whole starter-prompt list was unreadable. The token
             * follows the mode. Measured in Chromium on the rig: 16.32:1 in
             * light, 14.67:1 in dark.
             */
            ?>
            <pre style="white-space:pre-wrap;background:var(--kh-soft);padding:14px;border-radius:8px;max-width:800px;font-size:12px;"><?php
            echo esc_html(implode("\n", [
                __('• "Give me a full status report on the store and site."', 'keelhob'),
                __('• "Write and publish a 900-word blog post about <topic>, add a hero image, and optimize its SEO."', 'keelhob'),
                __('• "Create a product called <name> at <price>, add an image, and put it in the <category> category."', 'keelhob'),
                __('• "Set up Cash on Delivery and a flat-rate shipping zone for the UAE at 15 AED."', 'keelhob'),
                __('• "Build a modern, responsive landing page for <offer> and show me the link."', 'keelhob'),
                __('• "Set up a daily digest email of sales and low-stock items."', 'keelhob'),
            ]));
            ?></pre>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('FAQ', 'keelhob'); ?></h2>
            <p style="max-width:800px;">
                <strong><?php esc_html_e('Is it safe on my live store?', 'keelhob'); ?></strong> <?php esc_html_e('Install it anywhere, but only turn abilities ON where you\'re comfortable with changes. Best practice: build on staging, deploy to production.', 'keelhob'); ?><br>
                <strong><?php esc_html_e('What if the AI breaks something?', 'keelhob'); ?></strong> <?php esc_html_e('Code snippets that error auto-disable themselves; you have an Activity log; and destructive actions can be toggled off entirely.', 'keelhob'); ?><br>
                <strong><?php esc_html_e('Does it cost money?', 'keelhob'); ?></strong> <?php esc_html_e('The plugin is free. Your AI app\'s usage and (optionally) the autonomy API key are billed by those providers.', 'keelhob'); ?><br>
                <strong><?php esc_html_e('Does it work with ChatGPT/Codex/Gemini?', 'keelhob'); ?></strong> <?php esc_html_e('Yes — any MCP-compatible AI app.', 'keelhob'); ?><br>
                <strong><?php esc_html_e('Which switches should I leave off?', 'keelhob'); ?></strong> <?php esc_html_e('See the recommended table above; when unsure, off is safer — you can always turn it on later.', 'keelhob'); ?>
            </p>

            <p style="margin-top:24px;"><a href="<?php echo esc_url(admin_url('admin.php?page=keelhob')); ?>" class="button button-primary"><?php esc_html_e('← Back to Connect', 'keelhob'); ?></a></p>
            </div>
        <?php Keelhob_Admin::shell_close(); ?>
        <?php
    }

    /**
     * The Activity screen: memory, snippet history, tool calls.
     *
     * Everything here is free on both tiers. A free site has no Snippets Studio,
     * but it must still be able to see what the agent did to it — that is the
     * condition for trusting the plugin at all, not a feature to sell back.
     *
     * Three tables, ten rows each, and each one pages on its own query arg so
     * stepping through the tool log does not throw the reader back to page one
     * of memory. Before this, the page rendered every memory row and 100 log
     * rows in one go, which on a site with real history is a wall nobody reads.
     */
    public function render_activity(): void
    {
        $per = 10;

        $mem_page  = max(1, (int) ($_GET['mem_page'] ?? 0));
        $snip_page = max(1, (int) ($_GET['snip_page'] ?? 0));
        $log_page  = max(1, (int) ($_GET['log_page'] ?? 0));

        $memory = Keelhob::instance()->memory;
        $logger = Keelhob::instance()->logger;

        $mem_total  = (int) $memory->count_all();
        $snip_total = Keelhob_Snippet_Log::count_all();
        $log_total  = (int) $logger->count_all();

        $mem_rows  = $memory->paged($per, ($mem_page - 1) * $per);
        $snip_rows = Keelhob_Snippet_Log::recent($per, ($snip_page - 1) * $per);
        $log_rows  = $logger->recent($per, ($log_page - 1) * $per);

        // Each section's links carry the other two sections' current pages, so
        // following one never silently resets a table the reader was not
        // touching. That is the whole point of three separate query args.
        $pager = static function (string $arg, int $total, int $current) use ($per, $mem_page, $snip_page, $log_page): void {
            if ($total <= $per) {
                return;
            }
            $args       = [
                'page'      => 'keelhob-activity',
                'mem_page'  => $mem_page,
                'snip_page' => $snip_page,
                'log_page'  => $log_page,
            ];
            $args[$arg] = '%#%';

            $links = paginate_links([
                'base'      => add_query_arg($args, admin_url('admin.php')),
                'format'    => '',
                'current'   => $current,
                'total'     => (int) ceil($total / $per),
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]);
            if (!$links) {
                return;
            }
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo '<span class="displaying-num">' . esc_html(sprintf(
                /* translators: %s: number of rows in this section. */
                _n('%s entry', '%s entries', $total, 'keelhob'),
                number_format_i18n($total)
            )) . '</span> ';
            echo wp_kses_post((string) $links);
            echo '</div></div>';
        };
        ?>
        <?php Keelhob_Admin::shell_open('keelhob-activity'); ?>
            <h2><?php esc_html_e('Activity', 'keelhob'); ?></h2>

            <?php
            // The feedback log, shown in full and first.
            //
            // This is the only thing in the plugin that can send anything other
            // than the four-field update check, so the owner gets to read every
            // row of it before any of it goes anywhere, and gets two buttons:
            // one that wipes what is here, and one that also asks the server to
            // drop what it already holds for this site.
            $kh_feedback_on   = Keelhob_Feedback::enabled();
            $kh_feedback_rows = $kh_feedback_on ? Keelhob_Feedback::all(50) : [];
            ?>
            <h2><?php esc_html_e('Feedback log', 'keelhob'); ?></h2>
            <?php if (!$kh_feedback_on) : ?>
                <p class="description"><?php esc_html_e('Switched off. Nothing is being recorded.', 'keelhob'); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=keelhob')); ?>"><?php esc_html_e('Settings', 'keelhob'); ?></a></p>
            <?php else : ?>
                <p class="description" style="max-width:720px;"><?php esc_html_e('What went wrong while the agent worked here. Kept for 30 days or 50 entries, whichever comes first. Nothing leaves this site until you have read a summary of it and said yes.', 'keelhob'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px;display:inline-block;" onsubmit="return confirm('<?php echo esc_js(__('Delete everything in the local feedback log?', 'keelhob')); ?>');">
                    <?php wp_nonce_field('keelhob_save'); ?>
                    <input type="hidden" name="action" value="keelhob_save">
                    <input type="hidden" name="keelhob_action" value="clear_feedback">
                    <button class="button"><?php esc_html_e('Clear the log', 'keelhob'); ?></button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px;display:inline-block;margin-left:6px;" onsubmit="return confirm('<?php echo esc_js(__('Also ask the Keelhob project to delete every report it holds for this site?', 'keelhob')); ?>');">
                    <?php wp_nonce_field('keelhob_save'); ?>
                    <input type="hidden" name="action" value="keelhob_save">
                    <input type="hidden" name="keelhob_action" value="forget_feedback">
                    <button class="button"><?php esc_html_e('Clear, and delete what was sent', 'keelhob'); ?></button>
                </form>
                <table class="widefat striped" style="margin-bottom:24px;">
                    <thead><tr>
                        <th><?php esc_html_e('What happened', 'keelhob'); ?></th>
                        <th><?php esc_html_e('Tool', 'keelhob'); ?></th>
                        <th><?php esc_html_e('Note', 'keelhob'); ?></th>
                        <th><?php esc_html_e('When', 'keelhob'); ?></th>
                        <th><?php esc_html_e('Sent', 'keelhob'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$kh_feedback_rows) : ?>
                        <tr><td colspan="5"><?php esc_html_e('Nothing noted. That is the good outcome.', 'keelhob'); ?></td></tr>
                    <?php else : foreach ($kh_feedback_rows as $kh_row) : ?>
                        <tr>
                            <td><?php echo esc_html(Keelhob_Feedback::label((string) $kh_row['friction'])); ?><?php echo ((int) $kh_row['attempts']) > 1 ? ' <span class="kh-u-dim">×' . (int) $kh_row['attempts'] . '</span>' : ''; ?></td>
                            <td><code><?php echo esc_html($kh_row['tool'] !== '' ? (string) $kh_row['tool'] : '—'); ?></code></td>
                            <td><?php echo esc_html((string) $kh_row['note']); ?><?php if (!empty($kh_row['redacted'])) : ?> <span class="kh-u-dim">(<?php esc_html_e('redacted', 'keelhob'); ?>)</span><?php endif; ?></td>
                            <td><?php echo esc_html((string) $kh_row['created_at']); ?></td>
                            <td><?php echo empty($kh_row['sent']) ? esc_html__('no', 'keelhob') : esc_html__('yes', 'keelhob'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php esc_html_e('Memory', 'keelhob'); ?></h2>
            <p class="description"><?php esc_html_e('What the agent has been told to remember about this site. Kept in full — it is never trimmed automatically.', 'keelhob'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Wipe all memory?', 'keelhob')); ?>');" style="margin-bottom:8px;">
                <?php wp_nonce_field('keelhob_save'); ?>
                <input type="hidden" name="action" value="keelhob_save">
                <input type="hidden" name="keelhob_action" value="clear_memory">
                <button class="button"><?php esc_html_e('Clear all memory', 'keelhob'); ?></button>
            </form>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('Key', 'keelhob'); ?></th>
                <th><?php esc_html_e('Category', 'keelhob'); ?></th>
                <th><?php esc_html_e('Value', 'keelhob'); ?></th>
                <th><?php esc_html_e('Updated', 'keelhob'); ?></th>
            </tr></thead><tbody>
                <?php foreach ($mem_rows as $m) : ?>
                    <tr><td><code><?php echo esc_html((string) $m['key']); ?></code></td><td><?php echo esc_html((string) $m['category']); ?></td><td><?php echo esc_html(wp_trim_words((string) $m['value'], 30)); ?></td><td><?php echo esc_html((string) $m['updated_at']); ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($mem_rows)) : ?><tr><td colspan="4"><em><?php esc_html_e('No memory yet.', 'keelhob'); ?></em></td></tr><?php endif; ?>
            </tbody></table>
            <?php $pager('mem_page', $mem_total, $mem_page); ?>

            <h2 style="margin-top:32px;"><?php esc_html_e('Snippet activity', 'keelhob'); ?></h2>
            <p class="description"><?php esc_html_e('Every change to the live code running on this site, and whether you or the agent made it.', 'keelhob'); ?></p>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('When', 'keelhob'); ?></th>
                <th><?php esc_html_e('Snippet', 'keelhob'); ?></th>
                <th><?php esc_html_e('Event', 'keelhob'); ?></th>
                <th><?php esc_html_e('By', 'keelhob'); ?></th>
                <th><?php esc_html_e('Detail', 'keelhob'); ?></th>
            </tr></thead><tbody>
                <?php foreach ($snip_rows as $s) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $s['created_at']); ?></td>
                        <td><?php echo esc_html((string) $s['snippet_name']); ?><?php if (!empty($s['snippet_uid'])) : ?><br><code style="font-size:11px;"><?php echo esc_html((string) $s['snippet_uid']); ?></code><?php endif; ?></td>
                        <td><?php echo esc_html(Keelhob_Snippet_Log::label((string) $s['event'])); ?></td>
                        <td><?php echo esc_html(($s['actor'] ?? '') === 'agent' ? __('Agent', 'keelhob') : __('You', 'keelhob')); ?></td>
                        <td style="max-width:360px;"><?php echo esc_html(wp_trim_words((string) ($s['detail'] ?? ''), 25)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($snip_rows)) : ?><tr><td colspan="5"><em><?php esc_html_e('No snippet changes recorded yet.', 'keelhob'); ?></em></td></tr><?php endif; ?>
            </tbody></table>
            <?php $pager('snip_page', $snip_total, $snip_page); ?>

            <?php $this->lessons_section(); ?>
            <?php $this->ratings_section(); ?>

            <h2 id="kh-runs" style="margin-top:32px;"><?php esc_html_e('Recent tool calls', 'keelhob'); ?></h2>
            <p class="description" style="max-width:720px;"><?php esc_html_e('Every tool call, what asked for it, how long it took and whether it worked. Thumbs up or down here is what moves that tool\'s rating — and the rating is written into the description the agent reads before it picks the tool.', 'keelhob'); ?></p>
            <?php if (isset($_GET['rate_failed'])) : ?>
                <div class="kh-u-note is-bad"><p><?php esc_html_e('That entry is no longer in the log, so the vote was not saved. This log keeps the most recent 200 calls.', 'keelhob'); ?></p></div>
            <?php endif; ?>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('When', 'keelhob'); ?></th>
                <th><?php esc_html_e('Tool', 'keelhob'); ?></th>
                <th><?php esc_html_e('Asked by', 'keelhob'); ?></th>
                <th><?php esc_html_e('Took', 'keelhob'); ?></th>
                <th><?php esc_html_e('OK', 'keelhob'); ?></th>
                <th><?php esc_html_e('Args', 'keelhob'); ?></th>
                <th><?php esc_html_e('Note', 'keelhob'); ?></th>
                <th><?php esc_html_e('Any good?', 'keelhob'); ?></th>
            </tr></thead><tbody>
                <?php foreach ($log_rows as $row) : ?>
                    <?php
                    $run_id = (int) ($row['id'] ?? 0);
                    $vote   = (int) ($row['feedback'] ?? 0);
                    $took   = (int) ($row['duration_ms'] ?? 0);
                    ?>
                    <tr>
                        <td><?php echo esc_html((string) $row['created_at']); ?></td>
                        <td><code><?php echo esc_html((string) $row['tool']); ?></code></td>
                        <td><?php echo esc_html(self::source_label((string) ($row['source'] ?? 'chat'))); ?></td>
                        <td><?php echo $took > 0 ? esc_html(self::took_label($took)) : '<span class="kh-u-dim">&mdash;</span>'; ?></td>
                        <td><?php echo $row['ok'] ? '✓' : '✗'; ?></td>
                        <td style="max-width:300px;overflow:hidden;"><code style="font-size:11px;"><?php echo esc_html(wp_trim_words((string) $row['args_json'], 24)); ?></code></td>
                        <td><?php echo esc_html((string) $row['summary']); ?></td>
                        <td>
                            <?php
                            /*
                             * A real form posting to admin-post.php, nonce and
                             * all. assets/js/admin-ratings.js intercepts it so
                             * a vote does not reload a long table, and waits
                             * for the server's answer before changing anything
                             * on screen. With JavaScript off, blocked or
                             * broken, this posts and redirects back — which is
                             * the whole reason it is a form and not a link.
                             */
                            ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  class="kh-u-thumbs"
                                  data-kh-vote="<?php echo esc_attr((string) $run_id); ?>"
                                  data-kh-tool="<?php echo esc_attr((string) $row['tool']); ?>">
                                <?php wp_nonce_field(Keelhob_Admin_Ratings::NONCE_VOTE); ?>
                                <input type="hidden" name="action" value="keelhob_rate_run">
                                <input type="hidden" name="run_id" value="<?php echo esc_attr((string) $run_id); ?>">
                                <input type="hidden" name="log_page" value="<?php echo esc_attr((string) $log_page); ?>">
                                <button class="button" name="vote" value="up" aria-pressed="<?php echo $vote === 1 ? 'true' : 'false'; ?>">
                                    <span aria-hidden="true">&#9650;</span>
                                    <span class="kh-u-sr"><?php echo esc_html(sprintf(/* translators: %s: tool name. */ __('This %s call was good', 'keelhob'), (string) $row['tool'])); ?></span>
                                </button>
                                <button class="button" name="vote" value="down" aria-pressed="<?php echo $vote === -1 ? 'true' : 'false'; ?>">
                                    <span aria-hidden="true">&#9660;</span>
                                    <span class="kh-u-sr"><?php echo esc_html(sprintf(/* translators: %s: tool name. */ __('This %s call was wrong', 'keelhob'), (string) $row['tool'])); ?></span>
                                </button>
                                <span class="kh-u-thumbs-msg" data-kh-vote-status></span>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($log_rows)) : ?><tr><td colspan="8"><em><?php esc_html_e('No activity logged yet.', 'keelhob'); ?></em></td></tr><?php endif; ?>
            </tbody></table>
            <?php $pager('log_page', $log_total, $log_page); ?>
        <?php Keelhob_Admin::shell_close(); ?>
        <?php
    }

    /** Plain English for the stored `source` of a logged call. */
    private static function source_label(string $source): string
    {
        switch ($source) {
            case 'chat':
                return __('You, in a chat', 'keelhob');
            case 'task':
                return __('A scheduled task', 'keelhob');
            case 'telegram':
                return __('Telegram', 'keelhob');
            case 'run_agent':
            case 'manual':
                return __('An unattended run', 'keelhob');
        }
        return $source;
    }

    private static function took_label(int $ms): string
    {
        return $ms >= 1000
            ? sprintf(/* translators: %s: seconds, one decimal place. */ __('%ss', 'keelhob'), number_format_i18n($ms / 1000, 1))
            : sprintf(/* translators: %s: milliseconds. */ __('%sms', 'keelhob'), number_format_i18n($ms));
    }

    /**
     * Lessons — the owner's control over what gets pushed into the tool
     * descriptions an agent reads.
     *
     * This screen IS the safety guard, not a convenience. A lesson is a
     * standing instruction to something that can change a live site, so the
     * owner has to be able to see every one, see who wrote it, rewrite it and
     * delete it. The length cap and the stripping happen in
     * Keelhob_Lessons::clean(); this is the half a person can act on.
     */
    private function lessons_section(): void
    {
        $rows = Keelhob_Lessons::all(200);
        ?>
        <h2 id="kh-lessons" style="margin-top:32px;"><?php esc_html_e('Lessons the agent reads', 'keelhob'); ?></h2>
        <div class="kh-u-note is-info">
            <p><strong><?php esc_html_e('These are instructions, not notes.', 'keelhob'); ?></strong>
            <?php esc_html_e('Every lesson below is written into the description of the tool it names, so an agent reads it on every connection whether it looks for it or not. That is what makes them useful and it is why they are worth checking. Each one is capped at 300 characters of plain text, you can rewrite or delete any of them here, and the table says whether you or an agent wrote it.', 'keelhob'); ?></p>
            <p><?php esc_html_e('Use the topic "general" for something true of the whole site; anything else should be a tool name, such as seo_meta or create_post.', 'keelhob'); ?></p>
        </div>

        <?php if (isset($_GET['lesson_error'])) : ?>
            <div class="kh-u-note is-bad"><p><?php esc_html_e('That lesson was not saved — it needs a topic and some text that survives having markup stripped out.', 'keelhob'); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kh-u-lesson-add">
            <?php wp_nonce_field(Keelhob_Admin_Ratings::NONCE_LESSON); ?>
            <input type="hidden" name="action" value="keelhob_lesson_save">
            <p>
                <label for="kh-lesson-topic"><?php esc_html_e('Tool name, or "general"', 'keelhob'); ?></label><br>
                <input type="text" id="kh-lesson-topic" name="topic" class="regular-text" maxlength="191" placeholder="seo_meta" required>
            </p>
            <p>
                <label for="kh-lesson-text"><?php echo esc_html(sprintf(/* translators: %d: character limit. */ __('The lesson (%d characters max)', 'keelhob'), Keelhob_Lessons::MAX_LEN)); ?></label><br>
                <textarea id="kh-lesson-text" name="lesson" rows="2" class="large-text" maxlength="<?php echo esc_attr((string) Keelhob_Lessons::MAX_LEN); ?>" required placeholder="<?php esc_attr_e('Rank Math is the SEO plugin here, not Yoast.', 'keelhob'); ?>"></textarea>
            </p>
            <p><button class="button button-primary"><?php esc_html_e('Add lesson', 'keelhob'); ?></button></p>
        </form>

        <table class="widefat striped"><thead><tr>
            <th><?php esc_html_e('Topic', 'keelhob'); ?></th>
            <th><?php esc_html_e('What the agent will read', 'keelhob'); ?></th>
            <th><?php esc_html_e('Written by', 'keelhob'); ?></th>
            <th><?php esc_html_e('When', 'keelhob'); ?></th>
            <th><?php esc_html_e('Remove', 'keelhob'); ?></th>
        </tr></thead><tbody>
            <?php foreach ($rows as $lesson) : ?>
                <?php $lid = (int) $lesson['id']; ?>
                <tr>
                    <td><code><?php echo esc_html((string) $lesson['topic']); ?></code></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field(Keelhob_Admin_Ratings::NONCE_LESSON); ?>
                            <input type="hidden" name="action" value="keelhob_lesson_save">
                            <input type="hidden" name="lesson_id" value="<?php echo esc_attr((string) $lid); ?>">
                            <label class="kh-u-sr" for="kh-lesson-<?php echo esc_attr((string) $lid); ?>"><?php esc_html_e('Lesson text', 'keelhob'); ?></label>
                            <textarea id="kh-lesson-<?php echo esc_attr((string) $lid); ?>" name="lesson" rows="2" class="large-text" maxlength="<?php echo esc_attr((string) Keelhob_Lessons::MAX_LEN); ?>"><?php echo esc_textarea((string) $lesson['lesson']); ?></textarea>
                            <button class="button"><?php esc_html_e('Save', 'keelhob'); ?></button>
                        </form>
                    </td>
                    <td>
                        <?php
                        // Attribution, and it is not settable by the agent —
                        // lesson_save always records 'agent'. "Who told the
                        // agent this?" has to have a truthful answer.
                        $by_owner = ((string) $lesson['source']) === 'owner';
                        ?>
                        <span class="kh-u-pill<?php echo $by_owner ? ' is-on' : ''; ?>">
                            <?php echo esc_html($by_owner ? __('You', 'keelhob') : __('The agent', 'keelhob')); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html((string) ($lesson['updated_at'] ?: $lesson['created_at'])); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                              onsubmit="return confirm('<?php echo esc_js(__('Delete this lesson? The agent will stop reading it.', 'keelhob')); ?>');">
                            <?php wp_nonce_field(Keelhob_Admin_Ratings::NONCE_LESSON); ?>
                            <input type="hidden" name="action" value="keelhob_lesson_delete">
                            <input type="hidden" name="lesson_id" value="<?php echo esc_attr((string) $lid); ?>">
                            <button class="button button-link-delete"><?php esc_html_e('Delete', 'keelhob'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="5"><em><?php esc_html_e('No lessons yet. Nothing extra is being added to any tool description.', 'keelhob'); ?></em></td></tr>
            <?php endif; ?>
        </tbody></table>
        <?php
    }

    /**
     * The ratings table: 1-100 per tool, earned from real use.
     *
     * The number is never the only signal — every row carries the word next to
     * it ("Good", "Needs work") and the run count it was computed from, because
     * 78/100 over two runs and 78/100 over forty are not the same claim.
     */
    private function ratings_section(): void
    {
        $rows = Keelhob_Tool_Stats::all(100);
        ?>
        <h2 id="kh-ratings" style="margin-top:32px;"><?php esc_html_e('Tool ratings', 'keelhob'); ?></h2>
        <p class="description" style="max-width:720px;"><?php esc_html_e('How each tool has actually gone on this site: how often it worked, and what you said about it. The agent reads this score in the tool\'s own description, so a tool you keep marking wrong gets used less.', 'keelhob'); ?></p>
        <table class="widefat striped"><thead><tr>
            <th><?php esc_html_e('Tool', 'keelhob'); ?></th>
            <th><?php esc_html_e('Rating', 'keelhob'); ?></th>
            <th><?php esc_html_e('Worked', 'keelhob'); ?></th>
            <th><?php esc_html_e('Your verdicts', 'keelhob'); ?></th>
            <th><?php esc_html_e('Last used', 'keelhob'); ?></th>
        </tr></thead><tbody>
            <?php foreach ($rows as $stat) : ?>
                <?php
                $score = $stat['score'];
                $tone  = $score === null ? '' : ($score >= 75 ? ' is-good' : ($score >= 50 ? ' is-mid' : ' is-bad'));
                ?>
                <tr>
                    <td><code><?php echo esc_html((string) $stat['tool']); ?></code></td>
                    <td>
                        <?php
                        /*
                         * Soft tint as the fill, the status colour as the
                         * border, --kh-ink for the words. The status colours
                         * measure 2.99-4.41:1 as text on their own tints and
                         * fail AA at every size; see BUILD-BRIEF A1.1.
                         */
                        ?>
                        <span class="kh-u-score<?php echo esc_attr($tone); ?>" data-kh-rating="<?php echo esc_attr((string) $stat['tool']); ?>">
                            <?php
                            echo $score === null
                                ? esc_html__('New', 'keelhob')
                                : esc_html(sprintf(
                                    /* translators: 1: score out of 100, 2: word for the score, 3: number of runs. */
                                    __('%1$d/100 · %2$s · %3$d runs', 'keelhob'),
                                    (int) $score,
                                    (string) $stat['label'],
                                    (int) $stat['runs']
                                ));
                            ?>
                        </span>
                    </td>
                    <td><?php echo esc_html(sprintf(/* translators: 1: successful runs, 2: total runs. */ __('%1$d of %2$d', 'keelhob'), (int) $stat['ok_runs'], (int) $stat['runs'])); ?></td>
                    <td>
                        <?php if ((int) $stat['thumbs_up'] + (int) $stat['thumbs_down'] === 0) : ?>
                            <span class="kh-u-dim"><?php esc_html_e('none yet', 'keelhob'); ?></span>
                        <?php else : ?>
                            <?php echo esc_html(sprintf(/* translators: 1: thumbs up, 2: thumbs down. */ __('%1$d good, %2$d wrong', 'keelhob'), (int) $stat['thumbs_up'], (int) $stat['thumbs_down'])); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html((string) ($stat['last_used_at'] ?: '—')); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="5"><em><?php esc_html_e('No tool has been used here yet, so nothing has a rating.', 'keelhob'); ?></em></td></tr>
            <?php endif; ?>
        </tbody></table>
        <?php
    }
}
