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

final class Claudpress_Admin_Screens
{
    public function render(): void
    {
        $s        = Claudpress::instance()->settings();
        $endpoint = rest_url(CLAUDPRESS_REST_NAMESPACE . '/mcp');
        // S5: the token is stored hashed, so the plaintext exists only in the
        // one page load straight after it is minted. After that the screen shows
        // a placeholder, and the owner regenerates if they need a new one.
        $token_plain = Claudpress::take_flashed_token();
        $have_token  = ($token_plain !== '');
        $token       = $have_token ? $token_plain : 'YOUR_TOKEN_HERE';
        $host     = wp_parse_url(home_url(), PHP_URL_HOST);
        // The MCP server name is the string the agent sees for this site, so it
        // should be the same handle the owner uses — that is what makes
        // "copy that snippet to the other shop" resolve to the right server.
        $server_name = 'claudpress-' . sanitize_title(Claudpress::site_label() ?: ($host ?: 'site'));

        $claude_code_cmd = sprintf(
            "claude mcp add %s --transport http %s --header \"Authorization: Bearer %s\"",
            $server_name,
            esc_html($endpoint),
            esc_html($token)
        );

        $json_config = wp_json_encode([
            'mcpServers' => [
                $server_name => [
                    'type'    => 'http',
                    'url'     => $endpoint,
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $enabled = Claudpress::instance()->abilities_enabled();
        ?>
        <div class="wrap">
            <h1>Claudpress <span style="font-size:13px;color:#888;">v<?php echo esc_html(CLAUDPRESS_VERSION); ?></span></h1><?php Claudpress_Donate::button(); ?>
            <p style="max-width:760px;"><?php esc_html_e('Give an AI agent full, programmatic control of this WordPress & WooCommerce site over MCP — content, store, SEO, files, PHP — with cross-session memory and a skills engine. Self-contained, no external dependencies.', 'claudpress'); ?></p>

            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:#217296;background:radial-gradient(45% 200% at 100% 50%,#d37355 0%,rgba(211,115,85,.9) 25%,rgba(211,115,85,0) 62%),linear-gradient(104deg,#1b6183 0%,#217296 55%,#2a83aa 100%);color:#fff;padding:14px 20px;border-radius:10px;margin:6px 0 18px;max-width:980px;box-shadow:0 6px 20px rgba(33,114,150,.22);">
                <span style="font-size:22px;">📖</span>
                <div style="flex:1;min-width:240px;">
                    <strong style="font-size:15px;"><?php esc_html_e('New to Claudpress? Start with the Guide.', 'claudpress'); ?></strong><br>
                    <span style="opacity:.9;font-size:13px;"><?php esc_html_e('A plain-English walkthrough of every feature, what the AI can do, which switches to leave off, and how to prompt it.', 'claudpress'); ?></span>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=claudpress-guide')); ?>" class="button button-primary" style="background:#fff;color:#a2503a;border-color:#fff;font-weight:600;"><?php esc_html_e('Open the master guide →', 'claudpress'); ?></a>
            </div>

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'claudpress'); ?></p></div>
            <?php endif; ?>

            <div style="display:flex;gap:24px;flex-wrap:wrap;">
                <div style="flex:1;min-width:420px;">
                    <div class="card" style="max-width:none;padding:16px 20px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('1 · Turn on AI abilities', 'claudpress'); ?></h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('claudpress_save'); ?>
                            <input type="hidden" name="action" value="claudpress_save">
                            <input type="hidden" name="claudpress_action" value="settings">
                            <div style="border:1px solid #dcdcde;border-left:4px solid #d63638;background:#fff;padding:14px 16px;margin-bottom:14px;">
                                <p style="margin-top:0;font-size:14px;"><strong><?php esc_html_e('Before you switch this on', 'claudpress'); ?></strong></p>
                                <p><?php esc_html_e('Claudpress lets an AI agent make real changes to this live site. It can write code, edit pages and products, change settings, install plugins, and touch your store. It can be wrong, and wrong changes can break pages, checkout, or the admin area.', 'claudpress'); ?></p>
                                <p><?php esc_html_e('Your instructions, the content and code involved, and a short description of this site (WordPress and PHP versions, theme, active well-known plugins) are sent to the AI provider you choose, under that provider terms.', 'claudpress'); ?></p>
                                <p><?php esc_html_e('You pay that provider directly for what you use. Claudpress cannot see or cap that spend — only your provider can. Set a spend limit on your provider account before you switch this on.', 'claudpress'); ?></p>
                                <p><?php esc_html_e('There are real safeguards. Code is saved switched off, PHP is syntax-checked before it can go live, a snippet that crashes the site is switched back off automatically, recent changes can be undone, and every action is written to the Activity log. These are safeguards, not a guarantee.', 'claudpress'); ?></p>
                                <p style="margin-bottom:0;"><?php esc_html_e('You stay responsible for reviewing what the agent does, for keeping the capability switches set to what you actually want, and for having a backup.', 'claudpress'); ?></p>
                            </div>
                            <p>
                                <label style="font-size:15px;">
                                    <input type="checkbox" name="abilities_enabled" value="1" <?php checked($enabled); ?>>
                                    <strong><?php esc_html_e('Turn on AI abilities', 'claudpress'); ?></strong>
                                </label><br>
                                <span style="color:#555;margin-left:24px;"><?php esc_html_e('I have read the points above and accept that AI-driven changes run on my site at my own risk.', 'claudpress'); ?></span>
                            </p>
                            <?php
                            // Show who accepted and when. If this is ever
                            // disputed, the answer should be on the screen
                            // rather than reconstructed from memory.
                            $consent_at = (int) ($s['consent_at'] ?? 0);
                            if ($enabled && $consent_at > 0) :
                                $who = get_userdata((int) ($s['consent_by'] ?? 0));
                                ?>
                                <p style="color:#666;font-size:12px;">
                                    <?php
                                    echo esc_html(sprintf(
                                        /* translators: 1: user login, 2: date and time. */
                                        __('Accepted by %1$s on %2$s.', 'claudpress'),
                                        $who ? $who->user_login : __('an unknown user', 'claudpress'),
                                        date_i18n(get_option('date_format') . ' H:i', $consent_at)
                                    ));
                                    ?>
                                </p>
                            <?php endif; ?>
                            <hr>
                            <p><label><input type="checkbox" name="allow_app_passwords" value="1" <?php checked(!empty($s['allow_app_passwords'])); ?>> <?php esc_html_e('Allow Application Password (Basic) auth in addition to the bearer token', 'claudpress'); ?></label></p>
                            <p><label><input type="checkbox" name="require_https" value="1" <?php checked(!empty($s['require_https'])); ?>> <?php esc_html_e('Require HTTPS (recommended for remote sites)', 'claudpress'); ?></label></p>
                            <p><label><input type="checkbox" name="log_calls" value="1" <?php checked(!empty($s['log_calls'])); ?>> <?php esc_html_e('Log tool calls (Activity tab)', 'claudpress'); ?></label></p>
                            <hr>
                            <p style="margin-bottom:6px;"><strong><?php esc_html_e('Notifications', 'claudpress'); ?></strong></p>
                            <?php
                            // Preference and connectivity are two different
                            // things. A disabled checkbox posts nothing, so the
                            // stored choice must never be derived from this
                            // form - otherwise losing a Telegram token would
                            // silently erase the choice and reconnecting would
                            // not bring it back. See Claudpress_Notifier.
                            $availability = Claudpress_Notifier::availability();
                            $chosen       = Claudpress_Notifier::chosen_channels();
                            foreach (Claudpress_Notifier::labels() as $channel => $label) :
                                $connected = !empty($availability[$channel]['connected']);
                                $state     = $availability[$channel]['state'] ?? 'untested';
                                $wanted    = in_array($channel, $chosen, true);
                                ?>
                                <p style="margin:4px 0;">
                                    <label style="<?php echo $connected ? '' : 'color:#888;'; ?>">
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
                                        <span style="color:#a00;font-size:12px;">— <?php echo esc_html($availability[$channel]['reason']); ?></span>
                                        <?php if ($wanted) : ?>
                                            <span style="color:#666;font-size:12px;">
                                                <?php esc_html_e('(your choice is remembered and comes back when this is connected)', 'claudpress'); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php elseif ($state === 'failing') : ?>
                                        <span style="color:#a00;font-size:12px;">— <?php echo esc_html($availability[$channel]['warning']); ?></span>
                                    <?php elseif ($state === 'working') : ?>
                                        <span style="color:#1a7f37;font-size:12px;">
                                            <?php
                                            printf(
                                                /* translators: %s: time ago, e.g. "5 mins" */
                                                esc_html__('— delivered %s ago', 'claudpress'),
                                                esc_html(human_time_diff((int) $availability[$channel]['last_at']))
                                            );
                                            ?>
                                        </span>
                                    <?php else : ?>
                                        <span style="color:#666;font-size:12px;">
                                            <?php esc_html_e('— set up, but nothing has been sent through it yet', 'claudpress'); ?>
                                        </span>
                                    <?php endif; ?>
                                </p>
                                <?php
                            endforeach;

                            if (empty(Claudpress_Notifier::effective_channels())) :
                                ?>
                                <p style="color:#a00;font-size:12px;margin-top:2px;">
                                    <?php esc_html_e('Nothing is selected, so digests and alerts will not be sent anywhere.', 'claudpress'); ?>
                                </p>
                            <?php elseif (empty(Claudpress_Notifier::healthy_channels())) : ?>
                                <p style="color:#a00;font-size:12px;margin-top:2px;">
                                    <?php esc_html_e('Every selected channel failed on its last try, so an alert about this site would not reach you. Send a test below to check whether it is fixed.', 'claudpress'); ?>
                                </p>
                            <?php endif; ?>

                            <hr>
                            <p style="margin-bottom:6px;"><strong><?php esc_html_e('Updates and the public count', 'claudpress'); ?></strong></p>
                            <p>
                                <label>
                                    <input type="checkbox" name="skip_checkin" value="1" <?php checked(!empty($s['skip_checkin'])); ?>>
                                    <?php esc_html_e('Do not include this site in the public count', 'claudpress'); ?>
                                </label><br>
                                <span style="color:#666;font-size:12px;margin-left:24px;">
                                    <?php esc_html_e('When Claudpress checks for a new version, it sends a random ID made on this site, the plugin version, and your WordPress and PHP versions. Nothing else — no site address, no email, no content, and the address it talks to does not keep your IP. That count is the only measure of how many people use this, and it decides what gets built next. Tick this and Claudpress checks GitHub directly instead: you still get every update, this site just is not counted.', 'claudpress'); ?>
                                </span>
                            </p>

                            <hr>
                            <p style="margin-bottom:6px;"><strong><?php esc_html_e('Uninstall', 'claudpress'); ?></strong></p>
                            <p>
                                <label>
                                    <input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked(!empty($s['delete_data_on_uninstall'])); ?>>
                                    <?php esc_html_e('Delete all Claudpress data when the plugin is deleted', 'claudpress'); ?>
                                </label><br>
                                <span style="color:#666;font-size:12px;margin-left:24px;">
                                    <?php esc_html_e('Off by default: deleting the plugin leaves your snippets, memory, skills and history in the database, so reinstalling restores them. There is no undo once this is on.', 'claudpress'); ?>
                                </span>
                            </p>
                            <p>
                                <label><?php esc_html_e('Filesystem base directory (tools are confined at/below this path):', 'claudpress'); ?><br>
                                <input type="text" name="fs_base" value="<?php echo esc_attr($s['fs_base']); ?>" class="regular-text" style="width:100%;max-width:520px;"></label>
                            </p>
                            <p>
                                <label><?php esc_html_e('Site label (what your agent calls this site):', 'claudpress'); ?><br>
                                <input type="text" name="site_label" value="<?php echo esc_attr((string) ($s['site_label'] ?? '')); ?>" placeholder="<?php echo esc_attr(Claudpress::site_label()); ?>" class="regular-text" style="width:100%;max-width:520px;"></label>
                                <br><span style="color:#787c82;"><?php esc_html_e('Leave blank to use the site name. Matters when one agent is connected to several sites — this is the handle you say out loud, as in "copy that snippet to the other shop".', 'claudpress'); ?></span>
                            </p>
                            <hr>
                            <p><label><input type="checkbox" name="run_snippets" value="1" <?php checked(!empty($s['run_snippets'])); ?>> <strong><?php esc_html_e('Run code snippets', 'claudpress'); ?></strong> <?php esc_html_e('(active PHP/CSS/JS/HTML snippets execute on the site; uncheck to kill all at once)', 'claudpress'); ?></label></p>
                            <p>
                                <label><strong><?php esc_html_e('Page builder', 'claudpress'); ?></strong> <?php esc_html_e('(loads matching guidance for the agent):', 'claudpress'); ?><br>
                                <select name="builder">
                                    <?php
                                    $builders = [
                                        'auto'           => __('Auto-detect', 'claudpress'),
                                        'gutenberg'      => __('Gutenberg (block editor)', 'claudpress'),
                                        'elementor_free' => __('Elementor (free) + Xpro Addons', 'claudpress'),
                                        'elementor_pro'  => __('Elementor Pro', 'claudpress'),
                                        'bricks'         => __('Bricks', 'claudpress'),
                                        'custom_php'     => __('Custom-coded (pixel-perfect PHP pages)', 'claudpress'),
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
                            <p style="color:#666;margin-top:22px;"><strong><?php esc_html_e('AI image generation', 'claudpress'); ?></strong> — <?php esc_html_e('optional. Leave the key blank to use free Openverse stock images via image_search instead.', 'claudpress'); ?></p>
                            <?php Claudpress_Admin_Providers::render_media_provider([
                                'kind'           => 'image',
                                'providers'      => Claudpress_Image_Provider::providers(),
                                'current'        => $img_id,
                                'provider_field' => 'image_provider',
                                'key_field'      => 'image_api_key',
                                'model_field'    => 'image_api_model',
                                'endpoint_field' => 'image_api_endpoint',
                                'provider_label' => __('Image provider', 'claudpress'),
                                'key_label'      => __('Image API key', 'claudpress'),
                                'model_label'    => __('Image model', 'claudpress'),
                                'key_value'      => Claudpress_Secrets::mask((string) ($s['image_api_key'] ?? '')),
                                'model_value'    => (string) ($s['image_api_model'] ?? ''),
                                'endpoint_value' => (string) ($s['image_api_endpoint'] ?? ''),
                            ]); ?>

                            <p style="color:#666;margin-top:16px;"><strong><?php esc_html_e('Staging → production deploy', 'claudpress'); ?></strong> <?php esc_html_e('(optional — target for deploy_push: your live site\'s Claudpress endpoint)', 'claudpress'); ?></p>
                            <p><label><?php esc_html_e('Production site URL:', 'claudpress'); ?> <input type="text" name="deploy_target_url" value="<?php echo esc_attr($s['deploy_target_url'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" placeholder="https://your-live-site.com"></label></p>
                            <p><label><?php esc_html_e('Production bearer token:', 'claudpress'); ?> <input type="password" name="deploy_target_token" value="<?php echo esc_attr(Claudpress_Secrets::mask((string) ($s['deploy_target_token'] ?? ''))); ?>" class="regular-text" style="width:100%;max-width:520px;" autocomplete="off" placeholder="<?php esc_attr_e('cp_... (the live site\'s Claudpress token)', 'claudpress'); ?>"></label></p>
                            <p><button class="button button-primary"><?php esc_html_e('Save settings', 'claudpress'); ?></button></p>
                        </form>
                    </div>

                    <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('2 · Connect Claude Code', 'claudpress'); ?></h2>
                        <p><?php esc_html_e('Run this in your terminal (Claude Code will then list the Claudpress tools):', 'claudpress'); ?></p>
                        <textarea readonly rows="3" style="width:100%;font-family:monospace;font-size:12px;" onclick="this.select()"><?php echo esc_textarea($claude_code_cmd); ?></textarea>

                        <p style="margin-top:16px;"><strong><?php esc_html_e('Endpoint URL', 'claudpress'); ?></strong></p>
                        <input readonly value="<?php echo esc_attr($endpoint); ?>" class="regular-text" style="width:100%;max-width:520px;font-family:monospace;" onclick="this.select()">

                        <p style="margin-top:16px;"><strong><?php esc_html_e('Bearer token', 'claudpress'); ?></strong> <?php esc_html_e('(keep secret — it grants full site access)', 'claudpress'); ?></p>
                        <?php if ($have_token) : ?>
                            <div style="padding:10px 12px;border-left:4px solid #d63638;background:#fcf0f1;margin-bottom:8px;">
                                <strong><?php esc_html_e('Copy this now, it is not shown again.', 'claudpress'); ?></strong><br>
                                <span style="color:#555;"><?php esc_html_e('Claudpress stores only a hash of this token, so it cannot be displayed a second time. If you lose it, regenerate below.', 'claudpress'); ?></span>
                            </div>
                            <input readonly value="<?php echo esc_attr($token); ?>" class="regular-text" style="width:100%;max-width:520px;font-family:monospace;" onclick="this.select()">
                        <?php else : ?>
                            <input readonly value="••••••••••••" class="regular-text" style="width:100%;max-width:520px;font-family:monospace;color:#888;">
                            <p style="color:#666;margin-top:4px;">
                                <?php esc_html_e('Stored hashed and cannot be shown again. Regenerate below to get a new one; existing clients will need updating.', 'claudpress'); ?>
                                <?php
                                $last_used = (int) Claudpress::instance()->get('token_last_used', 0);
                                if ($last_used > 0) {
                                    /* translators: %s: human-readable time difference, e.g. 3 hours. */
                                    echo '<br>' . esc_html(sprintf(__('Last used %s ago.', 'claudpress'), human_time_diff($last_used, time())));
                                } else {
                                    echo '<br>' . esc_html__('Never used yet.', 'claudpress');
                                }
                                ?>
                            </p>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;" onsubmit="return confirm('<?php echo esc_js(__('Regenerating the token will disconnect existing clients. Continue?', 'claudpress')); ?>');">
                            <?php wp_nonce_field('claudpress_save'); ?>
                            <input type="hidden" name="action" value="claudpress_save">
                            <input type="hidden" name="claudpress_action" value="regenerate_token">
                            <button class="button"><?php esc_html_e('Regenerate token', 'claudpress'); ?></button>
                        </form>

                        <details style="margin-top:16px;">
                            <summary style="cursor:pointer;"><?php esc_html_e('JSON config for Claude Desktop / Cursor / Codex / other clients', 'claudpress'); ?></summary>
                            <textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:12px;margin-top:8px;" onclick="this.select()"><?php echo esc_textarea($json_config); ?></textarea>
                            <p style="color:#666;"><?php esc_html_e('Application Password alternative: use Authorization: Basic base64(username:app_password) against the same endpoint. Generate an Application Password under Users → Profile.', 'claudpress'); ?></p>
                        </details>
                    </div>
                </div>

                <div style="flex:1;min-width:300px;">
                    <div class="card" style="max-width:none;padding:16px 20px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('Status', 'claudpress'); ?></h2>
                        <p><?php esc_html_e('Abilities:', 'claudpress'); ?> <strong style="color:<?php echo $enabled ? '#16a34a' : '#888'; ?>;"><?php echo $enabled ? esc_html__('ENABLED', 'claudpress') : esc_html__('DISABLED', 'claudpress'); ?></strong></p>
                        <p><?php esc_html_e('Registered tools:', 'claudpress'); ?> <strong><?php echo count(Claudpress::instance()->registry->names()); ?></strong></p>
                        <p><?php esc_html_e('WooCommerce:', 'claudpress'); ?> <strong><?php echo class_exists('WooCommerce') ? esc_html__('detected ✓', 'claudpress') : esc_html__('not active', 'claudpress'); ?></strong></p>
                        <p><?php esc_html_e('Skills:', 'claudpress'); ?> <strong><?php echo count(Claudpress::instance()->skills->all()); ?></strong> · <?php esc_html_e('Memory:', 'claudpress'); ?> <strong><?php echo count(Claudpress::instance()->memory->all()); ?></strong> · <?php esc_html_e('Snippets:', 'claudpress'); ?> <strong><?php echo count(Claudpress_Snippets::all()); ?></strong></p>
                        <?php
                        /*
                         * The MCP endpoint only answers authenticated POSTs, so linking to it
                         * just showed the browser a 401 and read as a broken feature. Show the
                         * URL to copy instead, and keep the link only for the health check,
                         * which is the one route a browser can actually load.
                         */
                        ?>
                        <p style="margin-bottom:4px;"><?php esc_html_e('MCP endpoint (needs a token — not viewable in a browser):', 'claudpress'); ?></p>
                        <p><input type="text" readonly value="<?php echo esc_attr($endpoint); ?>" class="regular-text" style="width:100%;font-family:monospace;font-size:11px;" onclick="this.select();"></p>
                        <p><a href="<?php echo esc_url(rest_url(CLAUDPRESS_REST_NAMESPACE . '/status')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open health check ↗', 'claudpress'); ?></a></p>
                    </div>

                    <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('Tool catalog', 'claudpress'); ?></h2>
                        <div style="max-height:320px;overflow:auto;font-family:monospace;font-size:12px;line-height:1.8;">
                            <?php foreach (Claudpress::instance()->registry->names() as $name) : ?>
                                <div><?php echo esc_html($name); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="max-width:none;padding:16px 20px;margin-top:24px;">
                <h2 style="margin-top:0;"><?php esc_html_e('Capabilities — what the agent may touch', 'claudpress'); ?></h2>
                <p style="max-width:820px;"><?php esc_html_e('Turn any area off to make it off-limits. Disabled tools are hidden from the agent, refused if called, and named as off-limits in every session — for both interactive and autonomous use. Sensitive areas (money, shipping, code, files, users, deploy) are marked ●.', 'claudpress'); ?></p>
                <p style="color:#b45309;"><strong><?php esc_html_e('Note:', 'claudpress'); ?></strong> <?php esc_html_e('to truly wall off a sensitive area, also disable Code execution — otherwise the agent could reach it via raw PHP/SQL.', 'claudpress'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('claudpress_save'); ?>
                    <input type="hidden" name="action" value="claudpress_save">
                    <input type="hidden" name="claudpress_action" value="capabilities">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:10px 24px;">
                        <?php foreach (Claudpress_Capabilities::groups() as $key => $g) :
                            $on  = Claudpress_Capabilities::is_group_enabled($key);
                            $tip = sprintf(
                                /* translators: 1: what the capability lets the agent do, 2: comma-separated tool names */
                                __('WHEN ON — the agent can: %1$s Tools: %2$s.', 'claudpress'),
                                $g['desc'],
                                implode(', ', $g['tools'])
                            )
                                 . ' ' . __('WHEN OFF — these tools are hidden from the agent and refused if called.', 'claudpress')
                                 . (!empty($g['sensitive']) ? ' ' . __('This is a SENSITIVE area (money/data/code) — leave it off until you trust the agent.', 'claudpress') : ''); ?>
                            <label style="display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border:1px solid #e2e2e2;border-radius:8px;background:<?php echo $on ? '#fff' : '#fbfbfb'; ?>;">
                                <input type="checkbox" name="cap[<?php echo esc_attr($key); ?>]" value="1" <?php checked($on); ?> style="margin-top:3px;">
                                <span>
                                    <strong><?php echo esc_html($g['label']); ?></strong>
                                    <?php if (!empty($g['sensitive'])) : ?>
                                        <span style="color:#b91c1c;" title="<?php esc_attr_e('Sensitive area', 'claudpress'); ?>">●</span>
                                    <?php endif; ?>
                                    <span title="<?php echo esc_attr($tip); ?>" style="cursor:help;color:#217296;font-weight:700;border:1px solid #b9d3e0;border-radius:50%;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;">i</span>
                                    <br><span style="color:#666;font-size:12px;"><?php echo esc_html($g['desc']); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p style="margin-top:14px;"><button class="button button-primary"><?php esc_html_e('Save capabilities', 'claudpress'); ?></button></p>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_guide(): void
    {
        // Map tool name → description straight from the live registry so this
        // guide always documents exactly what's installed.
        $desc = [];
        foreach (Claudpress::instance()->registry->list() as $t) {
            $desc[$t['name']] = $t['description'];
        }
        $short = static function (string $s): string {
            $s = preg_replace('/\s+/', ' ', $s);
            return mb_strlen($s) > 200 ? mb_substr($s, 0, 200) . '…' : $s;
        };
        $enabled = Claudpress::instance()->abilities_enabled();
        // Style value only, not a whole attribute — so it can be escaped at output.
        $h = 'margin:28px 0 10px;';
        ?>
        <div class="wrap" style="max-width:1000px;">
            <h1><?php esc_html_e('Claudpress — Master Guide', 'claudpress'); ?></h1><?php Claudpress_Donate::button(); ?>
            <p style="font-size:15px;max-width:800px;"><?php esc_html_e('Everything this plugin lets your AI do, in plain English. Read this once and you\'ll know what to turn on, what to leave off, and how to get the best out of it — no coding required.', 'claudpress'); ?></p>

            <div class="notice notice-warning" style="max-width:800px;"><p><strong><?php esc_html_e('Golden rule:', 'claudpress'); ?></strong> <?php esc_html_e('use this on a development or staging site with backups. When AI abilities are ON, a connected agent can change anything. Don\'t point it at a live store you can\'t afford to have edited without review.', 'claudpress'); ?></p></div>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('What is Claudpress?', 'claudpress'); ?></h2>
            <p><?php esc_html_e('Claudpress turns your WordPress site into something an AI assistant (Claude, ChatGPT/Codex, Gemini, Cursor…) can operate directly. Instead of you clicking around the dashboard, you chat with your AI — "add this product", "write a blog post", "how are sales?" — and it does the work on your site through a set of tools this plugin provides. It speaks a standard called MCP (Model Context Protocol), so it works with most AI apps, not just one.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Seeing what the AI installed', 'claudpress'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %s: link to the Snippets page */
                    esc_html__('When your AI writes code onto this site, every piece of it is listed on the %s page — what it is, why it was written, what it changes, and a switch to turn it off. That page is the first place to look if the site starts behaving oddly.', 'claudpress'),
                    esc_html__('Snippets', 'claudpress')
                );
                ?>
            </p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Get started in 3 steps', 'claudpress'); ?></h2>
            <ol style="font-size:14px;line-height:1.9;max-width:800px;">
                <li>
                    <strong><?php esc_html_e('Turn on AI abilities', 'claudpress'); ?></strong>
                    <?php
                    printf(
                        /* translators: %s: link to the Connect page */
                        esc_html__('on the %s page (master switch). Currently:', 'claudpress'),
                        '<a href="' . esc_url(admin_url('admin.php?page=claudpress')) . '">' . esc_html__('Connect', 'claudpress') . '</a>'
                    );
                    ?>
                    <strong style="color:<?php echo $enabled ? '#16a34a' : '#b91c1c'; ?>;"><?php echo $enabled ? esc_html__('ON', 'claudpress') : esc_html__('OFF', 'claudpress'); ?></strong>.
                </li>
                <li><strong><?php esc_html_e('Connect your AI', 'claudpress'); ?></strong> — <?php esc_html_e('copy the ready-made command on the Connect page into your AI app (e.g. Claude Code). It links your AI to this site securely with a token.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Start chatting.', 'claudpress'); ?></strong> <?php esc_html_e('Try: "List the plugins on this site" or "Show me this month\'s sales." If it answers, you\'re set.', 'claudpress'); ?></li>
            </ol>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Your two safety controls', 'claudpress'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('1. The master switch (Connect page) turns all abilities on or off in one click. 2. Capability toggles (Connect page, lower down) let you allow some areas and forbid others — so you can let the AI write blog posts but never touch payments, for example. A forbidden area is hidden from the AI entirely and refused if it tries.', 'claudpress'); ?></p>

            <h3 style="margin-top:18px;"><?php esc_html_e('Recommended starting point for newbies', 'claudpress'); ?></h3>
            <p><?php esc_html_e('When you\'re still learning to trust it, keep the safe creative areas on and the sensitive ones off:', 'claudpress'); ?></p>
            <table class="widefat striped" style="max-width:800px;">
                <thead><tr>
                    <th><?php esc_html_e('Leave ON (low risk)', 'claudpress'); ?></th>
                    <th><?php esc_html_e('Turn OFF until you trust it (sensitive)', 'claudpress'); ?></th>
                </tr></thead>
                <tbody><tr>
                    <td style="vertical-align:top;"><?php esc_html_e('Content · SEO · Design/Elementor · Images · Memory · Skills', 'claudpress'); ?></td>
                    <td style="vertical-align:top;"><?php esc_html_e('Payments (money) · Store & orders · Shipping · Tax · Code execution · Filesystem · Users · Site admin · Updates · Autonomy · Deploy · Email', 'claudpress'); ?></td>
                </tr></tbody>
            </table>
            <p style="color:#b45309;max-width:800px;"><strong><?php esc_html_e('Important:', 'claudpress'); ?></strong> <?php esc_html_e('"Code execution" is the master key — with raw PHP the AI can reach any area. To truly wall off something sensitive, turn Code execution OFF too.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('What the AI can do — every area & tool', 'claudpress'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Each area below is one capability toggle. When it\'s ON, the AI can use the listed tools.', 'claudpress'); ?></p>
            <?php foreach (Claudpress_Capabilities::groups() as $key => $g) :
                $on = Claudpress_Capabilities::is_group_enabled($key); ?>
                <div class="card" style="max-width:none;padding:12px 18px;margin:10px 0;">
                    <h3 style="margin:.2em 0;">
                        <?php echo esc_html($g['label']); ?>
                        <?php if (!empty($g['sensitive'])) : ?>
                            <span style="color:#b91c1c;font-size:12px;"><?php esc_html_e('● sensitive', 'claudpress'); ?></span>
                        <?php endif; ?>
                        <span style="float:right;font-size:12px;color:<?php echo $on ? '#16a34a' : '#888'; ?>;"><?php echo $on ? esc_html__('ENABLED', 'claudpress') : esc_html__('OFF', 'claudpress'); ?></span>
                    </h3>
                    <p style="color:#555;margin:.2em 0 .6em;"><?php echo esc_html($g['desc']); ?></p>
                    <table class="widefat" style="font-size:12px;"><tbody>
                        <?php foreach ($g['tools'] as $tool) : ?>
                            <tr><td style="width:180px;"><code><?php echo esc_html($tool); ?></code></td><td><?php echo esc_html(isset($desc[$tool]) ? $short($desc[$tool]) : '—'); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody></table>
                </div>
            <?php endforeach; ?>

            <h3 style="margin-top:18px;"><?php esc_html_e('Always available (read-only)', 'claudpress'); ?></h3>
            <p style="max-width:800px;"><?php esc_html_e('These never turn off, so you can always ask what\'s going on: get_site_info, get_dashboard, memory_list, skill_list, task_list.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Run the shop on autopilot (Autonomy)', 'claudpress'); ?></h2>
            <p style="max-width:800px;">
                <?php
                printf(
                    /* translators: %s: link to the Automation tab */
                    esc_html__('On the %s tab you can:', 'claudpress'),
                    '<a href="' . esc_url(admin_url('admin.php?page=claudpress-automation')) . '">' . esc_html__('Automation', 'claudpress') . '</a>'
                );
                ?>
            </p>
            <ul style="font-size:14px;line-height:1.8;max-width:800px;">
                <li><strong><?php esc_html_e('Ask "what\'s going on"', 'claudpress'); ?></strong> <?php esc_html_e('anytime — sales, orders needing attention, low stock, updates — via the live dashboard (no login needed once your AI is connected).', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Schedule a daily digest', 'claudpress'); ?></strong> <?php esc_html_e('emailed/Telegram\'d to you — no API key required.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Let it act on a schedule', 'claudpress'); ?></strong> — <?php esc_html_e('e.g. "every morning, fulfil paid orders and publish due drafts." This needs an API key from Anthropic, OpenAI or Google (you pick the provider and add the key there) because the site runs its own AI to do the work. Guardrails cap refunds and require you to opt in before it does anything destructive.', 'claudpress'); ?></li>
            </ul>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Run your site from your phone (Telegram)', 'claudpress'); ?></h2>
            <p style="max-width:800px;">
                <?php esc_html_e('You don\'t need Claude Code, a terminal or a laptop to use any of this. Connect a Telegram bot and you can just message your site: "how did the store do this week?", "publish the draft about winter tyres", "the checkout footer is broken, fix it." The site\'s own AI key does the work and reports back.', 'claudpress'); ?>
            </p>
            <ul style="font-size:14px;line-height:1.8;max-width:800px;">
                <li><strong><?php esc_html_e('Set it up in two minutes', 'claudpress'); ?></strong> — <?php esc_html_e('message @BotFather on Telegram, send /newbot, paste the token into Automation → Notifications, then tick "Accept messages from Telegram" and press Connect.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('It remembers the conversation', 'claudpress'); ?></strong> <?php esc_html_e('so follow-ups work. Send /clear to make it forget and start fresh.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Talk or show, not just type', 'claudpress'); ?></strong> — <?php esc_html_e('send a voice note and it transcribes what you said (showing you the transcript first), or send a screenshot of something broken and it looks at it.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('It shows its work', 'claudpress'); ?></strong> — <?php esc_html_e('one message updates as it goes, so you can see which tools it is using instead of waiting in silence.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Changed its mind? /undo', 'claudpress'); ?></strong> — <?php esc_html_e('puts back the posts, pages and products it changed on its last turn. It does not cover settings, plugins, files or code snippets, and it tells you so.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Free commands', 'claudpress'); ?></strong> — <?php esc_html_e('/status, /usage, /tasks and /model are answered by the plugin itself and cost nothing.', 'claudpress'); ?></li>
                <li><strong><?php esc_html_e('Only you can drive it', 'claudpress'); ?></strong> — <?php esc_html_e('only chat IDs you allowlist get answered, each with a role (admin, editor or read-only viewer), and destructive or financial actions stay locked until you approve them with a button.', 'claudpress'); ?></li>
            </ul>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Let the site tell you first', 'claudpress'); ?></h2>
            <p style="max-width:800px;">
                <?php esc_html_e('Turn on event notifications and the site messages you when something happens: a paid order (above a value you choose), stock running low or selling out, a piece of live code that crashed and switched itself off, or new updates waiting. Every event is individually switchable, and nothing is announced twice.', 'claudpress'); ?>
            </p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Build websites & content', 'claudpress'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Ask for a page, homepage, or blog post and the AI follows a built-in designer\'s playbook (modern layout, responsive on phone/tablet/desktop, real images via free Openverse search). It can build pixel-perfect custom pages or editable Gutenberg/Elementor pages — just say which you prefer.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Go live: staging → production', 'claudpress'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Build safely on a staging site, then ship to your live site in one step with deploy_push (set the production URL + token in Connect settings), or export a bundle and import it on the live site. Re-deploying updates instead of duplicating.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Skills & memory (why it gets smarter)', 'claudpress'); ?></h2>
            <p style="max-width:800px;"><?php esc_html_e('Memory = facts it remembers between chats ("our brand color is #f8ca50"). Skills = playbooks it follows automatically (this plugin ships expert skills for WooCommerce, SEO, blogging, design, and deploys). Both are shared by every AI you connect, so your whole team\'s AI stays consistent.', 'claudpress'); ?></p>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('Copy-paste starter prompts', 'claudpress'); ?></h2>
            <pre style="white-space:pre-wrap;background:#f6f7f7;padding:14px;border-radius:8px;max-width:800px;font-size:12px;"><?php
            echo esc_html(implode("\n", [
                __('• "Give me a full status report on the store and site."', 'claudpress'),
                __('• "Write and publish a 900-word blog post about <topic>, add a hero image, and optimize its SEO."', 'claudpress'),
                __('• "Create a product called <name> at <price>, add an image, and put it in the <category> category."', 'claudpress'),
                __('• "Set up Cash on Delivery and a flat-rate shipping zone for the UAE at 15 AED."', 'claudpress'),
                __('• "Build a modern, responsive landing page for <offer> and show me the link."', 'claudpress'),
                __('• "Set up a daily digest email of sales and low-stock items."', 'claudpress'),
            ]));
            ?></pre>

            <h2 style="<?php echo esc_attr($h); ?>"><?php esc_html_e('FAQ', 'claudpress'); ?></h2>
            <p style="max-width:800px;">
                <strong><?php esc_html_e('Is it safe on my live store?', 'claudpress'); ?></strong> <?php esc_html_e('Install it anywhere, but only turn abilities ON where you\'re comfortable with changes. Best practice: build on staging, deploy to production.', 'claudpress'); ?><br>
                <strong><?php esc_html_e('What if the AI breaks something?', 'claudpress'); ?></strong> <?php esc_html_e('Code snippets that error auto-disable themselves; you have an Activity log; and destructive actions can be toggled off entirely.', 'claudpress'); ?><br>
                <strong><?php esc_html_e('Does it cost money?', 'claudpress'); ?></strong> <?php esc_html_e('The plugin is free. Your AI app\'s usage and (optionally) the autonomy API key are billed by those providers.', 'claudpress'); ?><br>
                <strong><?php esc_html_e('Does it work with ChatGPT/Codex/Gemini?', 'claudpress'); ?></strong> <?php esc_html_e('Yes — any MCP-compatible AI app.', 'claudpress'); ?><br>
                <strong><?php esc_html_e('Which switches should I leave off?', 'claudpress'); ?></strong> <?php esc_html_e('See the recommended table above; when unsure, off is safer — you can always turn it on later.', 'claudpress'); ?>
            </p>

            <p style="margin-top:24px;"><a href="<?php echo esc_url(admin_url('admin.php?page=claudpress')); ?>" class="button button-primary"><?php esc_html_e('← Back to Connect', 'claudpress'); ?></a></p>
        </div>
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

        $memory = Claudpress::instance()->memory;
        $logger = Claudpress::instance()->logger;

        $mem_total  = (int) $memory->count_all();
        $snip_total = Claudpress_Snippet_Log::count_all();
        $log_total  = (int) $logger->count_all();

        $mem_rows  = $memory->paged($per, ($mem_page - 1) * $per);
        $snip_rows = Claudpress_Snippet_Log::recent($per, ($snip_page - 1) * $per);
        $log_rows  = $logger->recent($per, ($log_page - 1) * $per);

        // Each section's links carry the other two sections' current pages, so
        // following one never silently resets a table the reader was not
        // touching. That is the whole point of three separate query args.
        $pager = static function (string $arg, int $total, int $current) use ($per, $mem_page, $snip_page, $log_page): void {
            if ($total <= $per) {
                return;
            }
            $args       = [
                'page'      => 'claudpress-activity',
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
                _n('%s entry', '%s entries', $total, 'claudpress'),
                number_format_i18n($total)
            )) . '</span> ';
            echo wp_kses_post((string) $links);
            echo '</div></div>';
        };
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Claudpress · Activity', 'claudpress'); ?></h1><?php Claudpress_Donate::button(); ?>

            <h2><?php esc_html_e('Memory', 'claudpress'); ?></h2>
            <p class="description"><?php esc_html_e('What the agent has been told to remember about this site. Kept in full — it is never trimmed automatically.', 'claudpress'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Wipe all memory?', 'claudpress')); ?>');" style="margin-bottom:8px;">
                <?php wp_nonce_field('claudpress_save'); ?>
                <input type="hidden" name="action" value="claudpress_save">
                <input type="hidden" name="claudpress_action" value="clear_memory">
                <button class="button"><?php esc_html_e('Clear all memory', 'claudpress'); ?></button>
            </form>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('Key', 'claudpress'); ?></th>
                <th><?php esc_html_e('Category', 'claudpress'); ?></th>
                <th><?php esc_html_e('Value', 'claudpress'); ?></th>
                <th><?php esc_html_e('Updated', 'claudpress'); ?></th>
            </tr></thead><tbody>
                <?php foreach ($mem_rows as $m) : ?>
                    <tr><td><code><?php echo esc_html((string) $m['key']); ?></code></td><td><?php echo esc_html((string) $m['category']); ?></td><td><?php echo esc_html(wp_trim_words((string) $m['value'], 30)); ?></td><td><?php echo esc_html((string) $m['updated_at']); ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($mem_rows)) : ?><tr><td colspan="4"><em><?php esc_html_e('No memory yet.', 'claudpress'); ?></em></td></tr><?php endif; ?>
            </tbody></table>
            <?php $pager('mem_page', $mem_total, $mem_page); ?>

            <h2 style="margin-top:32px;"><?php esc_html_e('Snippet activity', 'claudpress'); ?></h2>
            <p class="description"><?php esc_html_e('Every change to the live code running on this site, and whether you or the agent made it.', 'claudpress'); ?></p>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('When', 'claudpress'); ?></th>
                <th><?php esc_html_e('Snippet', 'claudpress'); ?></th>
                <th><?php esc_html_e('Event', 'claudpress'); ?></th>
                <th><?php esc_html_e('By', 'claudpress'); ?></th>
                <th><?php esc_html_e('Detail', 'claudpress'); ?></th>
            </tr></thead><tbody>
                <?php foreach ($snip_rows as $s) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $s['created_at']); ?></td>
                        <td><?php echo esc_html((string) $s['snippet_name']); ?><?php if (!empty($s['snippet_uid'])) : ?><br><code style="font-size:11px;"><?php echo esc_html((string) $s['snippet_uid']); ?></code><?php endif; ?></td>
                        <td><?php echo esc_html(Claudpress_Snippet_Log::label((string) $s['event'])); ?></td>
                        <td><?php echo esc_html(($s['actor'] ?? '') === 'agent' ? __('Agent', 'claudpress') : __('You', 'claudpress')); ?></td>
                        <td style="max-width:360px;"><?php echo esc_html(wp_trim_words((string) ($s['detail'] ?? ''), 25)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($snip_rows)) : ?><tr><td colspan="5"><em><?php esc_html_e('No snippet changes recorded yet.', 'claudpress'); ?></em></td></tr><?php endif; ?>
            </tbody></table>
            <?php $pager('snip_page', $snip_total, $snip_page); ?>

            <h2 style="margin-top:32px;"><?php esc_html_e('Recent tool calls', 'claudpress'); ?></h2>
            <table class="widefat striped"><thead><tr>
                <th><?php esc_html_e('When', 'claudpress'); ?></th>
                <th><?php esc_html_e('Tool', 'claudpress'); ?></th>
                <th><?php esc_html_e('OK', 'claudpress'); ?></th>
                <th><?php esc_html_e('Args', 'claudpress'); ?></th>
                <th><?php esc_html_e('Note', 'claudpress'); ?></th>
            </tr></thead><tbody>
                <?php foreach ($log_rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $row['created_at']); ?></td>
                        <td><code><?php echo esc_html((string) $row['tool']); ?></code></td>
                        <td><?php echo $row['ok'] ? '✓' : '✗'; ?></td>
                        <td style="max-width:360px;overflow:hidden;"><code style="font-size:11px;"><?php echo esc_html(wp_trim_words((string) $row['args_json'], 30)); ?></code></td>
                        <td><?php echo esc_html((string) $row['summary']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($log_rows)) : ?><tr><td colspan="5"><em><?php esc_html_e('No activity logged yet.', 'claudpress'); ?></em></td></tr><?php endif; ?>
            </tbody></table>
            <?php $pager('log_page', $log_total, $log_page); ?>
        </div>
        <?php
    }
}
