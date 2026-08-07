<?php
/**
 * Admin UI: the Claudpress settings page. Flip abilities on/off, manage the
 * bearer token, and copy ready-to-paste connection commands for Claude Code and
 * other MCP clients.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Admin
{
    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_claudpress_save', [$this, 'handle_save']);
        add_action('admin_bar_menu', [$this, 'admin_bar'], 100);
        add_action('admin_notices', [$this, 'notice']);
    }

    public function menu(): void
    {
        add_menu_page('Claudpress', 'Claudpress', 'manage_options', 'claudpress', [$this, 'render'], 'dashicons-superhero', 58);
        add_submenu_page('claudpress', 'Connect', 'Connect', 'manage_options', 'claudpress', [$this, 'render']);
        add_submenu_page('claudpress', 'Automation', 'Automation', 'manage_options', 'claudpress-automation', [$this, 'render_automation']);
        add_submenu_page('claudpress', 'Guide', 'Guide', 'manage_options', 'claudpress-guide', [$this, 'render_guide']);
        add_submenu_page('claudpress', 'Activity', 'Activity', 'manage_options', 'claudpress-activity', [$this, 'render_activity']);
    }

    public function notice(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'claudpress') === false) {
            return;
        }
        if (Claudpress::instance()->abilities_enabled()) {
            echo '<div class="notice notice-warning"><p><strong>Claudpress AI abilities are ON.</strong> A connected AI agent can read and modify everything on this site. Use development/staging sites only.</p></div>';
        }
    }

    public function admin_bar($bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $on = Claudpress::instance()->abilities_enabled();
        $bar->add_node([
            'id'    => 'claudpress',
            'title' => 'Claudpress: ' . ($on ? '🟢 ON' : '⚪ OFF'),
            'href'  => admin_url('admin.php?page=claudpress'),
        ]);
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('claudpress_save')) {
            wp_die('Unauthorized');
        }
        $action = $_POST['claudpress_action'] ?? 'settings';

        if ($action === 'regenerate_token') {
            Claudpress::instance()->update(['token' => Claudpress::generate_token()]);
        } elseif ($action === 'settings') {
            Claudpress::instance()->update([
                'abilities_enabled'   => !empty($_POST['abilities_enabled']),
                'allow_app_passwords' => !empty($_POST['allow_app_passwords']),
                'require_https'       => !empty($_POST['require_https']),
                'log_calls'           => !empty($_POST['log_calls']),
                'fs_base'             => !empty($_POST['fs_base']) ? sanitize_text_field(wp_unslash($_POST['fs_base'])) : ABSPATH,
                'run_snippets'        => !empty($_POST['run_snippets']),
                'builder'             => sanitize_key($_POST['builder'] ?? 'auto'),
                'image_api_key'       => sanitize_text_field(wp_unslash($_POST['image_api_key'] ?? '')),
                'image_api_endpoint'  => esc_url_raw(wp_unslash($_POST['image_api_endpoint'] ?? '')) ?: 'https://api.openai.com/v1/images/generations',
                'image_api_model'     => sanitize_text_field(wp_unslash($_POST['image_api_model'] ?? 'gpt-image-1')),
                'deploy_target_url'   => esc_url_raw(wp_unslash($_POST['deploy_target_url'] ?? '')),
                'deploy_target_token' => sanitize_text_field(wp_unslash($_POST['deploy_target_token'] ?? '')),
            ]);
        } elseif ($action === 'clear_memory') {
            Claudpress::instance()->memory->clear();
        } elseif ($action === 'capabilities') {
            $posted = isset($_POST['cap']) && is_array($_POST['cap']) ? array_map('sanitize_key', array_keys($_POST['cap'])) : [];
            $caps = [];
            foreach (array_keys(Claudpress_Capabilities::groups()) as $group) {
                $caps[$group] = in_array($group, $posted, true);
            }
            Claudpress::instance()->update(['capabilities' => $caps]);
        } elseif ($action === 'automation_settings') {
            Claudpress::instance()->update([
                'agent_api_key'         => sanitize_text_field(wp_unslash($_POST['agent_api_key'] ?? '')),
                'agent_api_endpoint'    => esc_url_raw(wp_unslash($_POST['agent_api_endpoint'] ?? '')) ?: 'https://api.anthropic.com/v1/messages',
                'agent_model'           => sanitize_text_field(wp_unslash($_POST['agent_model'] ?? 'claude-sonnet-5')),
                'agent_max_usd_per_run'    => max(0, (float) ($_POST['agent_max_usd_per_run'] ?? 0)),
                'agent_max_tokens_per_run' => max(0, (int) ($_POST['agent_max_tokens_per_run'] ?? 0)),
                'agent_max_usd_per_day'    => max(0, (float) ($_POST['agent_max_usd_per_day'] ?? 0)),
                'agent_max_runs_per_day'   => max(0, (int) ($_POST['agent_max_runs_per_day'] ?? 0)),
                'notify_email'          => sanitize_text_field(wp_unslash($_POST['notify_email'] ?? '')),
                'notify_webhook_url'    => esc_url_raw(wp_unslash($_POST['notify_webhook_url'] ?? '')),
                'notify_telegram_token' => sanitize_text_field(wp_unslash($_POST['notify_telegram_token'] ?? '')),
                'notify_telegram_chat'  => sanitize_text_field(wp_unslash($_POST['notify_telegram_chat'] ?? '')),
                'policy' => [
                    'enabled'       => !empty($_POST['policy_enabled']),
                    'blocked_tools' => array_filter(array_map('trim', explode(',', (string) wp_unslash($_POST['policy_blocked_tools'] ?? '')))),
                    'max_refund'    => (float) ($_POST['policy_max_refund'] ?? 0),
                    'require_optin' => !empty($_POST['policy_require_optin']),
                ],
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'saved' => 1], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_create') {
            Claudpress_Tasks::save([
                'name'     => sanitize_text_field(wp_unslash($_POST['task_name'] ?? 'Status digest')),
                'mode'     => 'digest',
                'schedule' => sanitize_key($_POST['task_schedule'] ?? 'daily'),
                'run_hour' => ($_POST['task_run_hour'] ?? '') === '' ? null : (int) $_POST['task_run_hour'],
                'notify'   => !empty($_POST['task_notify']),
                'enabled'  => true,
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'saved' => 1], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_run') {
            Claudpress_Tasks::run_task((int) ($_POST['task_id'] ?? 0));
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'ran' => 1], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_toggle') {
            $t = Claudpress_Tasks::get((int) ($_POST['task_id'] ?? 0));
            if ($t) {
                Claudpress_Tasks::toggle((int) $t['id'], (int) $t['enabled'] !== 1);
            }
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation'], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_delete') {
            Claudpress_Tasks::delete((int) ($_POST['task_id'] ?? 0));
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation'], admin_url('admin.php')));
            exit;
        } elseif ($action === 'test_notify') {
            Claudpress_Notifier::send('[' . get_bloginfo('name') . '] Claudpress test', 'This is a test notification from Claudpress. Channels are working.');
            wp_safe_redirect(add_query_arg(['page' => 'claudpress-automation', 'tested' => 1], admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg(['page' => 'claudpress', 'saved' => 1], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        $s        = Claudpress::instance()->settings();
        $endpoint = rest_url(CLAUDPRESS_REST_NAMESPACE . '/mcp');
        $token    = (string) $s['token'];
        $host     = wp_parse_url(home_url(), PHP_URL_HOST);
        $server_name = 'claudpress-' . sanitize_title($host ?: 'site');

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
            <h1>Claudpress <span style="font-size:13px;color:#888;">v<?php echo esc_html(CLAUDPRESS_VERSION); ?></span></h1>
            <p style="max-width:760px;">Give an AI agent full, programmatic control of this WordPress &amp; WooCommerce site over MCP — content, store, SEO, files, PHP — with cross-session memory and a skills engine. Self-contained, no external dependencies.</p>

            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:linear-gradient(90deg,#4f46e5,#7c3aed);color:#fff;padding:14px 20px;border-radius:10px;margin:6px 0 18px;max-width:980px;">
                <span style="font-size:22px;">📖</span>
                <div style="flex:1;min-width:240px;">
                    <strong style="font-size:15px;">New to Claudpress? Start with the Guide.</strong><br>
                    <span style="opacity:.9;font-size:13px;">A plain-English walkthrough of every feature, what the AI can do, which switches to leave off, and how to prompt it.</span>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=claudpress-guide')); ?>" class="button button-primary" style="background:#fff;color:#4f46e5;border-color:#fff;font-weight:600;">Open the master guide →</a>
            </div>

            <?php if (isset($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Saved.</p></div>
            <?php endif; ?>

            <div style="display:flex;gap:24px;flex-wrap:wrap;">
                <div style="flex:1;min-width:420px;">
                    <div class="card" style="max-width:none;padding:16px 20px;">
                        <h2 style="margin-top:0;">1 · Turn on AI abilities</h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('claudpress_save'); ?>
                            <input type="hidden" name="action" value="claudpress_save">
                            <input type="hidden" name="claudpress_action" value="settings">
                            <p>
                                <label style="font-size:15px;">
                                    <input type="checkbox" name="abilities_enabled" value="1" <?php checked($enabled); ?>>
                                    <strong>Enable AI abilities</strong> (master switch)
                                </label>
                            </p>
                            <p style="color:#a00;">While ON, a connected agent can modify everything. Keep this to dev/staging sites with backups.</p>
                            <hr>
                            <p><label><input type="checkbox" name="allow_app_passwords" value="1" <?php checked(!empty($s['allow_app_passwords'])); ?>> Allow Application Password (Basic) auth in addition to the bearer token</label></p>
                            <p><label><input type="checkbox" name="require_https" value="1" <?php checked(!empty($s['require_https'])); ?>> Require HTTPS (recommended for remote sites)</label></p>
                            <p><label><input type="checkbox" name="log_calls" value="1" <?php checked(!empty($s['log_calls'])); ?>> Log tool calls (Activity tab)</label></p>
                            <p>
                                <label>Filesystem base directory (tools are confined at/below this path):<br>
                                <input type="text" name="fs_base" value="<?php echo esc_attr($s['fs_base']); ?>" class="regular-text" style="width:100%;max-width:520px;"></label>
                            </p>
                            <hr>
                            <p><label><input type="checkbox" name="run_snippets" value="1" <?php checked(!empty($s['run_snippets'])); ?>> <strong>Run code snippets</strong> (active PHP/CSS/JS/HTML snippets execute on the site; uncheck to kill all at once)</label></p>
                            <p>
                                <label><strong>Page builder</strong> (loads matching guidance for the agent):<br>
                                <select name="builder">
                                    <?php
                                    $builders = [
                                        'auto'           => 'Auto-detect',
                                        'gutenberg'      => 'Gutenberg (block editor)',
                                        'elementor_free' => 'Elementor (free) + Xpro Addons',
                                        'elementor_pro'  => 'Elementor Pro',
                                        'bricks'         => 'Bricks',
                                        'custom_php'     => 'Custom-coded (pixel-perfect PHP pages)',
                                    ];
                                    foreach ($builders as $val => $label) {
                                        printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($s['builder'] ?? 'auto', $val, false), esc_html($label));
                                    }
                                    ?>
                                </select></label>
                            </p>
                            <p style="color:#666;margin-top:16px;"><strong>AI image generation</strong> (optional — leave the key blank to use free Openverse stock images via <code>image_search</code>)</p>
                            <p><label>Image API key: <input type="password" name="image_api_key" value="<?php echo esc_attr($s['image_api_key'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" autocomplete="off" placeholder="sk-... (OpenAI-compatible)"></label></p>
                            <p><label>Image API endpoint: <input type="text" name="image_api_endpoint" value="<?php echo esc_attr($s['image_api_endpoint'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;"></label></p>
                            <p><label>Image model: <input type="text" name="image_api_model" value="<?php echo esc_attr($s['image_api_model'] ?? 'gpt-image-1'); ?>" class="regular-text" style="max-width:240px;"></label></p>
                            <p style="color:#666;margin-top:16px;"><strong>Staging → production deploy</strong> (optional — target for <code>deploy_push</code>: your live site's Claudpress endpoint)</p>
                            <p><label>Production site URL: <input type="text" name="deploy_target_url" value="<?php echo esc_attr($s['deploy_target_url'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" placeholder="https://your-live-site.com"></label></p>
                            <p><label>Production bearer token: <input type="password" name="deploy_target_token" value="<?php echo esc_attr($s['deploy_target_token'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" autocomplete="off" placeholder="cp_... (the live site's Claudpress token)"></label></p>
                            <p><button class="button button-primary">Save settings</button></p>
                        </form>
                    </div>

                    <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
                        <h2 style="margin-top:0;">2 · Connect Claude Code</h2>
                        <p>Run this in your terminal (Claude Code will then list the Claudpress tools):</p>
                        <textarea readonly rows="3" style="width:100%;font-family:monospace;font-size:12px;" onclick="this.select()"><?php echo esc_textarea($claude_code_cmd); ?></textarea>

                        <p style="margin-top:16px;"><strong>Endpoint URL</strong></p>
                        <input readonly value="<?php echo esc_attr($endpoint); ?>" class="regular-text" style="width:100%;max-width:520px;font-family:monospace;" onclick="this.select()">

                        <p style="margin-top:16px;"><strong>Bearer token</strong> (keep secret — it grants full site access)</p>
                        <input readonly value="<?php echo esc_attr($token); ?>" class="regular-text" style="width:100%;max-width:520px;font-family:monospace;" onclick="this.select()">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;" onsubmit="return confirm('Regenerating the token will disconnect existing clients. Continue?');">
                            <?php wp_nonce_field('claudpress_save'); ?>
                            <input type="hidden" name="action" value="claudpress_save">
                            <input type="hidden" name="claudpress_action" value="regenerate_token">
                            <button class="button">Regenerate token</button>
                        </form>

                        <details style="margin-top:16px;">
                            <summary style="cursor:pointer;">JSON config for Claude Desktop / Cursor / Codex / other clients</summary>
                            <textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:12px;margin-top:8px;" onclick="this.select()"><?php echo esc_textarea($json_config); ?></textarea>
                            <p style="color:#666;">Application Password alternative: use <code>Authorization: Basic base64(username:app_password)</code> against the same endpoint. Generate an Application Password under Users → Profile.</p>
                        </details>
                    </div>
                </div>

                <div style="flex:1;min-width:300px;">
                    <div class="card" style="max-width:none;padding:16px 20px;">
                        <h2 style="margin-top:0;">Status</h2>
                        <p>Abilities: <strong style="color:<?php echo $enabled ? '#16a34a' : '#888'; ?>;"><?php echo $enabled ? 'ENABLED' : 'DISABLED'; ?></strong></p>
                        <p>Registered tools: <strong><?php echo count(Claudpress::instance()->registry->names()); ?></strong></p>
                        <p>WooCommerce: <strong><?php echo class_exists('WooCommerce') ? 'detected ✓' : 'not active'; ?></strong></p>
                        <p>Skills: <strong><?php echo count(Claudpress::instance()->skills->all()); ?></strong> · Memory: <strong><?php echo count(Claudpress::instance()->memory->all()); ?></strong> · Snippets: <strong><?php echo count(Claudpress_Snippets::all()); ?></strong></p>
                        <p><a href="<?php echo esc_url($endpoint); ?>" target="_blank" rel="noopener">Open endpoint</a> · <a href="<?php echo esc_url(rest_url(CLAUDPRESS_REST_NAMESPACE . '/status')); ?>" target="_blank" rel="noopener">Status JSON</a></p>
                    </div>

                    <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
                        <h2 style="margin-top:0;">Tool catalog</h2>
                        <div style="max-height:320px;overflow:auto;font-family:monospace;font-size:12px;line-height:1.8;">
                            <?php foreach (Claudpress::instance()->registry->names() as $name) : ?>
                                <div><?php echo esc_html($name); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" style="max-width:none;padding:16px 20px;margin-top:24px;">
                <h2 style="margin-top:0;">Capabilities — what the agent may touch</h2>
                <p style="max-width:820px;">Turn any area off to make it <strong>off-limits</strong>. Disabled tools are hidden from the agent, refused if called, and named as off-limits in every session — for both interactive and autonomous use. Sensitive areas (money, shipping, code, files, users, deploy) are marked <span style="color:#b91c1c;">●</span>.</p>
                <p style="color:#b45309;"><strong>Note:</strong> to truly wall off a sensitive area, also disable <em>Code execution</em> — otherwise the agent could reach it via raw PHP/SQL.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('claudpress_save'); ?>
                    <input type="hidden" name="action" value="claudpress_save">
                    <input type="hidden" name="claudpress_action" value="capabilities">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:10px 24px;">
                        <?php foreach (Claudpress_Capabilities::groups() as $key => $g) :
                            $on  = Claudpress_Capabilities::is_group_enabled($key);
                            $tip = 'WHEN ON — the agent can: ' . $g['desc'] . ' Tools: ' . implode(', ', $g['tools']) . '.'
                                 . ' WHEN OFF — these tools are hidden from the agent and refused if called.'
                                 . (!empty($g['sensitive']) ? ' This is a SENSITIVE area (money/data/code) — leave it off until you trust the agent.' : ''); ?>
                            <label style="display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border:1px solid #e2e2e2;border-radius:8px;background:<?php echo $on ? '#fff' : '#fbfbfb'; ?>;">
                                <input type="checkbox" name="cap[<?php echo esc_attr($key); ?>]" value="1" <?php checked($on); ?> style="margin-top:3px;">
                                <span>
                                    <strong><?php echo esc_html($g['label']); ?></strong>
                                    <?php echo !empty($g['sensitive']) ? ' <span style="color:#b91c1c;" title="Sensitive area">●</span>' : ''; ?>
                                    <span title="<?php echo esc_attr($tip); ?>" style="cursor:help;color:#4f46e5;font-weight:700;border:1px solid #c7d2fe;border-radius:50%;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;">i</span>
                                    <br><span style="color:#666;font-size:12px;"><?php echo esc_html($g['desc']); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p style="margin-top:14px;"><button class="button button-primary">Save capabilities</button></p>
                </form>
            </div>
        </div>
        <?php
    }

    public function render_automation(): void
    {
        $s       = Claudpress::instance()->settings();
        $policy  = is_array($s['policy'] ?? null) ? $s['policy'] : [];
        $tasks   = Claudpress_Tasks::all();
        $next    = wp_next_scheduled(Claudpress_Tasks::HOOK);
        $has_key = !empty($s['agent_api_key']);
        ?>
        <div class="wrap">
            <h1>Claudpress · Automation</h1>
            <p style="max-width:760px;">Let the site run itself. <strong>Digest</strong> tasks email you a status snapshot on a schedule. <strong>Autonomous</strong> tasks hand a plain-language instruction to the on-site AI agent, which does the work and reports back — no client open. Add an Anthropic API key below to enable autonomy.</p>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['ran'])) : ?><div class="notice notice-success is-dismissible"><p>Task ran. See its last summary below.</p></div><?php endif; ?>
            <?php if (isset($_GET['tested'])) : ?><div class="notice notice-success is-dismissible"><p>Test notification sent.</p></div><?php endif; ?>

            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:2;min-width:460px;">
                    <div class="card" style="max-width:none;padding:16px 20px;">
                        <h2 style="margin-top:0;">Autonomy engine <?php echo $has_key ? '<span style="color:#16a34a;">● ready</span>' : '<span style="color:#888;">○ needs API key</span>'; ?></h2>
                        <p>Cron heartbeat: <strong><?php echo $next ? human_time_diff(time(), $next) . ' from now' : 'not scheduled'; ?></strong> (WP-Cron fires when your site gets traffic).</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('claudpress_save'); ?>
                            <input type="hidden" name="action" value="claudpress_save">
                            <input type="hidden" name="claudpress_action" value="automation_settings">
                            <h3>Agent brain (Anthropic API)</h3>
                            <p><label>API key: <input type="password" name="agent_api_key" value="<?php echo esc_attr($s['agent_api_key'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" autocomplete="off" placeholder="sk-ant-..."></label></p>
                            <p><label>Model: <input type="text" name="agent_model" value="<?php echo esc_attr($s['agent_model'] ?? 'claude-sonnet-5'); ?>" class="regular-text" style="max-width:260px;"></label> <span style="color:#888;">e.g. claude-sonnet-5, claude-opus-4-8</span></p>
                            <p><label>Endpoint: <input type="text" name="agent_api_endpoint" value="<?php echo esc_attr($s['agent_api_endpoint'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;"></label></p>

                            <h3>Budget caps</h3>
                            <p style="color:#666;max-width:620px;">The agent bills your API key on every run. These caps stop a runaway loop from spending real money — a run halts cleanly and reports what it managed to finish. Cost is <em>estimated</em> from token usage at list prices. <strong>0 = unlimited.</strong></p>
                            <p>
                                <label>Max spend per run (USD): <input type="number" step="0.01" min="0" name="agent_max_usd_per_run" value="<?php echo esc_attr($s['agent_max_usd_per_run'] ?? 0.5); ?>" style="max-width:120px;"></label>
                                <label style="margin-left:14px;">Max tokens per run: <input type="number" step="1000" min="0" name="agent_max_tokens_per_run" value="<?php echo esc_attr($s['agent_max_tokens_per_run'] ?? 300000); ?>" style="max-width:140px;"></label>
                            </p>
                            <p>
                                <label>Max spend per day (USD): <input type="number" step="0.01" min="0" name="agent_max_usd_per_day" value="<?php echo esc_attr($s['agent_max_usd_per_day'] ?? 5); ?>" style="max-width:120px;"></label>
                                <label style="margin-left:14px;">Max runs per day: <input type="number" step="1" min="0" name="agent_max_runs_per_day" value="<?php echo esc_attr($s['agent_max_runs_per_day'] ?? 48); ?>" style="max-width:120px;"></label>
                            </p>
                            <?php $u24 = Claudpress_Agent_Runs::usage_since(24); ?>
                            <p style="color:#555;">Last 24h: <strong><?php echo (int) $u24['runs']; ?></strong> runs · <strong><?php echo number_format((int) $u24['tokens']); ?></strong> tokens · <strong>$<?php echo number_format((float) $u24['cost'], 4); ?></strong> estimated.</p>

                            <h3>Notifications</h3>
                            <p><label>Email(s), comma-separated (blank = admin email): <input type="text" name="notify_email" value="<?php echo esc_attr($s['notify_email'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;"></label></p>
                            <p><label>Webhook URL (Slack/generic, optional): <input type="text" name="notify_webhook_url" value="<?php echo esc_attr($s['notify_webhook_url'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;"></label></p>
                            <p><label>Telegram bot token: <input type="text" name="notify_telegram_token" value="<?php echo esc_attr($s['notify_telegram_token'] ?? ''); ?>" class="regular-text" style="max-width:340px;"></label>
                               <label style="margin-left:8px;">chat id: <input type="text" name="notify_telegram_chat" value="<?php echo esc_attr($s['notify_telegram_chat'] ?? ''); ?>" style="max-width:160px;"></label></p>

                            <h3>Guardrails (policy)</h3>
                            <p><label><input type="checkbox" name="policy_enabled" value="1" <?php checked(!empty($policy['enabled'])); ?>> Enforce policy</label></p>
                            <p><label><input type="checkbox" name="policy_require_optin" value="1" <?php checked(!empty($policy['require_optin'])); ?>> In autonomous runs, require a task to opt in (allow_risky) before destructive/financial tools</label></p>
                            <p><label>Max autonomous refund (0 = block refunds): <input type="number" step="0.01" name="policy_max_refund" value="<?php echo esc_attr($policy['max_refund'] ?? 0); ?>" style="max-width:140px;"></label></p>
                            <p><label>Always-blocked tools (comma-separated, optional): <input type="text" name="policy_blocked_tools" value="<?php echo esc_attr(implode(',', (array) ($policy['blocked_tools'] ?? []))); ?>" class="regular-text" style="width:100%;max-width:520px;"></label></p>

                            <p><button class="button button-primary">Save automation settings</button></p>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('claudpress_save'); ?>
                            <input type="hidden" name="action" value="claudpress_save">
                            <input type="hidden" name="claudpress_action" value="test_notify">
                            <button class="button">Send test notification</button>
                        </form>
                    </div>

                    <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
                        <h2 style="margin-top:0;">Scheduled tasks</h2>

                        <div style="background:#f6f7f7;border:1px solid #e2e4e7;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
                            <strong>Schedule a status digest</strong>
                            <p style="color:#666;margin:.4em 0 .8em;">Get the store snapshot sent to you on a cadence — no API key needed. Times use the site timezone (<code><?php echo esc_html(wp_timezone_string()); ?></code>, currently <?php echo esc_html(current_time('H:i')); ?>).</p>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                                <?php wp_nonce_field('claudpress_save'); ?>
                                <input type="hidden" name="action" value="claudpress_save">
                                <input type="hidden" name="claudpress_action" value="task_create">
                                <label>Name<br><input type="text" name="task_name" value="Status digest" style="max-width:200px;"></label>
                                <label>Cadence<br>
                                    <select name="task_schedule" id="cp-digest-schedule">
                                        <?php foreach (['hourly' => 'Every hour', 'twicedaily' => 'Twice a day', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $val => $label) : ?>
                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($val, 'daily'); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>At hour<br>
                                    <select name="task_run_hour" id="cp-digest-hour">
                                        <option value="">any time</option>
                                        <?php for ($hr = 0; $hr < 24; $hr++) : ?>
                                            <option value="<?php echo (int) $hr; ?>" <?php selected($hr, 8); ?>><?php echo esc_html(sprintf('%02d:00', $hr)); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </label>
                                <label style="padding-bottom:6px;"><input type="checkbox" name="task_notify" value="1" checked> Notify me</label>
                                <button class="button button-primary">Add digest</button>
                            </form>
                            <p style="color:#888;font-size:12px;margin:.8em 0 0;">Hour-of-day applies to daily, weekly and monthly cadences. WP-Cron fires on site traffic, so a digest lands on the first visit at or after the chosen hour.</p>
                        </div>

                        <p style="color:#666;">You can also create tasks by asking your AI: <em>"Create an autonomous task that fulfils paid orders and publishes due drafts every morning at 7."</em></p>
                        <table class="widefat striped">
                            <thead><tr><th>Name</th><th>Mode</th><th>Schedule</th><th>On</th><th>Last run</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($tasks as $t) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($t['name']); ?></strong><?php echo (int) $t['allow_risky'] === 1 ? ' <span title="allow_risky">⚠️</span>' : ''; ?></td>
                                    <td><?php echo esc_html($t['mode']); ?></td>
                                    <td><?php
                                        echo esc_html($t['schedule']);
                                        if (isset($t['run_hour']) && $t['run_hour'] !== null && $t['run_hour'] !== '') {
                                            echo ' <span style="color:#666;">@ ' . esc_html(sprintf('%02d:00', (int) $t['run_hour'])) . '</span>';
                                        }
                                    ?></td>
                                    <td><?php echo (int) $t['enabled'] === 1 ? '✓' : '—'; ?></td>
                                    <td><?php echo $t['last_run'] ? esc_html($t['last_run']) . ' (' . esc_html((string) $t['last_status']) . ')' : '<em>never</em>'; ?></td>
                                    <td style="white-space:nowrap;">
                                        <?php foreach (['task_run' => 'Run now', 'task_toggle' => ((int) $t['enabled'] === 1 ? 'Disable' : 'Enable'), 'task_delete' => 'Delete'] as $act => $label) : ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" <?php echo $act === 'task_delete' ? 'onsubmit="return confirm(\'Delete this task?\');"' : ''; ?>>
                                                <?php wp_nonce_field('claudpress_save'); ?>
                                                <input type="hidden" name="action" value="claudpress_save">
                                                <input type="hidden" name="claudpress_action" value="<?php echo esc_attr($act); ?>">
                                                <input type="hidden" name="task_id" value="<?php echo (int) $t['id']; ?>">
                                                <button class="button button-small"><?php echo esc_html($label); ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <?php if (!empty($t['last_summary'])) : ?>
                                    <tr><td colspan="6" style="background:#fafafa;"><small><strong>Last summary:</strong> <?php echo esc_html(wp_trim_words((string) $t['last_summary'], 60)); ?></small></td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty($tasks)) : ?><tr><td colspan="6"><em>No tasks yet.</em></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php $runs = Claudpress_Agent_Runs::recent(25); ?>
                    <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
                        <h2 style="margin-top:0;">Agent run history</h2>
                        <p style="color:#666;">Every autonomous run — scheduled or one-off — with what it cost. <em>capped</em> means a budget cap stopped it early; <em>truncated</em> means it hit the step limit.</p>
                        <table class="widefat striped">
                            <thead><tr><th>Started</th><th>Source</th><th>Status</th><th>Steps</th><th>Tools</th><th>Retries</th><th>Tokens</th><th>Est. cost</th></tr></thead>
                            <tbody>
                            <?php foreach ($runs as $r) :
                                $colors = ['ok' => '#16a34a', 'error' => '#b91c1c', 'capped' => '#b45309', 'truncated' => '#b45309', 'running' => '#888'];
                                $color  = $colors[$r['status']] ?? '#555'; ?>
                                <tr>
                                    <td><?php echo esc_html($r['started_at']); ?></td>
                                    <td><?php echo esc_html($r['context']); ?><?php echo $r['task_id'] ? ' #' . (int) $r['task_id'] : ''; ?></td>
                                    <td style="color:<?php echo esc_attr($color); ?>;font-weight:600;"><?php echo esc_html($r['status']); ?></td>
                                    <td><?php echo (int) $r['steps']; ?></td>
                                    <td><?php echo (int) $r['tool_calls']; ?></td>
                                    <td><?php echo (int) $r['retries'] ?: '—'; ?></td>
                                    <td><?php echo esc_html(number_format((int) $r['input_tokens'] + (int) $r['output_tokens'])); ?></td>
                                    <td>$<?php echo esc_html(number_format((float) $r['cost_usd'], 4)); ?></td>
                                </tr>
                                <?php if (!empty($r['summary'])) : ?>
                                    <tr><td colspan="8" style="background:#fafafa;"><small><strong><?php echo esc_html(wp_trim_words((string) $r['instruction'], 18)); ?></strong> → <?php echo esc_html(wp_trim_words((string) $r['summary'], 50)); ?></small></td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty($runs)) : ?><tr><td colspan="8"><em>No autonomous runs yet.</em></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="flex:1;min-width:300px;">
                    <div class="card" style="max-width:none;padding:16px 20px;">
                        <h2 style="margin-top:0;">Live status</h2>
                        <?php $d = Claudpress_Digest::snapshot(); ?>
                        <ul style="line-height:1.7;">
                            <?php foreach ($d['summary'] as $line) : ?><li><?php echo esc_html($line); ?></li><?php endforeach; ?>
                        </ul>
                        <details><summary style="cursor:pointer;">Full snapshot JSON</summary>
                        <pre style="white-space:pre-wrap;font-size:11px;max-height:320px;overflow:auto;"><?php echo esc_html(wp_json_encode($d, JSON_PRETTY_PRINT)); ?></pre></details>
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
        foreach (Claudpress::instance()->registry->list() as $t) {
            $desc[$t['name']] = $t['description'];
        }
        $short = static function (string $s): string {
            $s = preg_replace('/\s+/', ' ', $s);
            return mb_strlen($s) > 200 ? mb_substr($s, 0, 200) . '…' : $s;
        };
        $enabled = Claudpress::instance()->abilities_enabled();
        $h = 'style="margin:28px 0 10px;"';
        ?>
        <div class="wrap" style="max-width:1000px;">
            <h1>Claudpress — Master Guide</h1>
            <p style="font-size:15px;max-width:800px;">Everything this plugin lets your AI do, in plain English. Read this once and you'll know what to turn on, what to leave off, and how to get the best out of it — no coding required.</p>

            <div class="notice notice-warning" style="max-width:800px;"><p><strong>Golden rule:</strong> use this on a <strong>development or staging site</strong> with backups. When AI abilities are ON, a connected agent can change anything. Don't point it at a live store you can't afford to have edited without review.</p></div>

            <h2 <?php echo $h; ?>>What is Claudpress?</h2>
            <p>Claudpress turns your WordPress site into something an AI assistant (Claude, ChatGPT/Codex, Gemini, Cursor…) can operate directly. Instead of you clicking around the dashboard, you <em>chat</em> with your AI — "add this product", "write a blog post", "how are sales?" — and it does the work on your site through a set of <strong>tools</strong> this plugin provides. It speaks a standard called <strong>MCP</strong> (Model Context Protocol), so it works with most AI apps, not just one.</p>

            <h2 <?php echo $h; ?>>Get started in 3 steps</h2>
            <ol style="font-size:14px;line-height:1.9;max-width:800px;">
                <li><strong>Turn on AI abilities</strong> on the <a href="<?php echo esc_url(admin_url('admin.php?page=claudpress')); ?>">Connect</a> page (master switch). Currently: <strong style="color:<?php echo $enabled ? '#16a34a' : '#b91c1c'; ?>;"><?php echo $enabled ? 'ON' : 'OFF'; ?></strong>.</li>
                <li><strong>Connect your AI</strong> — copy the ready-made command on the Connect page into your AI app (e.g. Claude Code). It links your AI to this site securely with a token.</li>
                <li><strong>Start chatting.</strong> Try: <em>"List the plugins on this site"</em> or <em>"Show me this month's sales."</em> If it answers, you're set.</li>
            </ol>

            <h2 <?php echo $h; ?>>Your two safety controls</h2>
            <p style="max-width:800px;"><strong>1. The master switch</strong> (Connect page) turns <em>all</em> abilities on or off in one click. <strong>2. Capability toggles</strong> (Connect page, lower down) let you allow some areas and forbid others — so you can let the AI write blog posts but never touch payments, for example. A forbidden area is hidden from the AI entirely and refused if it tries.</p>

            <h3 style="margin-top:18px;">Recommended starting point for newbies</h3>
            <p>When you're still learning to trust it, keep the safe creative areas on and the sensitive ones off:</p>
            <table class="widefat striped" style="max-width:800px;">
                <thead><tr><th>Leave ON (low risk)</th><th>Turn OFF until you trust it (sensitive)</th></tr></thead>
                <tbody><tr>
                    <td style="vertical-align:top;">Content · SEO · Design/Elementor · Images · Memory · Skills</td>
                    <td style="vertical-align:top;">Payments (money) · Store &amp; orders · Shipping · Tax · Code execution · Filesystem · Users · Site admin · Updates · Autonomy · Deploy · Email</td>
                </tr></tbody>
            </table>
            <p style="color:#b45309;max-width:800px;"><strong>Important:</strong> "Code execution" is the master key — with raw PHP the AI can reach any area. To <em>truly</em> wall off something sensitive, turn Code execution OFF too.</p>

            <h2 <?php echo $h; ?>>What the AI can do — every area &amp; tool</h2>
            <p style="max-width:800px;">Each area below is one capability toggle. When it's ON, the AI can use the listed tools.</p>
            <?php foreach (Claudpress_Capabilities::groups() as $key => $g) :
                $on = Claudpress_Capabilities::is_group_enabled($key); ?>
                <div class="card" style="max-width:none;padding:12px 18px;margin:10px 0;">
                    <h3 style="margin:.2em 0;">
                        <?php echo esc_html($g['label']); ?>
                        <?php echo !empty($g['sensitive']) ? ' <span style="color:#b91c1c;font-size:12px;">● sensitive</span>' : ''; ?>
                        <span style="float:right;font-size:12px;color:<?php echo $on ? '#16a34a' : '#888'; ?>;"><?php echo $on ? 'ENABLED' : 'OFF'; ?></span>
                    </h3>
                    <p style="color:#555;margin:.2em 0 .6em;"><?php echo esc_html($g['desc']); ?></p>
                    <table class="widefat" style="font-size:12px;"><tbody>
                        <?php foreach ($g['tools'] as $tool) : ?>
                            <tr><td style="width:180px;"><code><?php echo esc_html($tool); ?></code></td><td><?php echo esc_html(isset($desc[$tool]) ? $short($desc[$tool]) : '—'); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody></table>
                </div>
            <?php endforeach; ?>

            <h3 style="margin-top:18px;">Always available (read-only)</h3>
            <p style="max-width:800px;">These never turn off, so you can always ask what's going on: <code>get_site_info</code>, <code>get_dashboard</code>, <code>memory_list</code>, <code>skill_list</code>, <code>task_list</code>.</p>

            <h2 <?php echo $h; ?>>Run the shop on autopilot (Autonomy)</h2>
            <p style="max-width:800px;">On the <a href="<?php echo esc_url(admin_url('admin.php?page=claudpress-automation')); ?>">Automation</a> tab you can:</p>
            <ul style="font-size:14px;line-height:1.8;max-width:800px;">
                <li><strong>Ask "what's going on"</strong> anytime — sales, orders needing attention, low stock, updates — via the live dashboard (no login needed once your AI is connected).</li>
                <li><strong>Schedule a daily digest</strong> emailed/Telegram'd to you — no API key required.</li>
                <li><strong>Let it act on a schedule</strong> — e.g. "every morning, fulfil paid orders and publish due drafts." This needs an <strong>Anthropic API key</strong> (you add it there) because the site runs its own AI to do the work. Guardrails cap refunds and require you to opt in before it does anything destructive.</li>
            </ul>

            <h2 <?php echo $h; ?>>Build websites &amp; content</h2>
            <p style="max-width:800px;">Ask for a page, homepage, or blog post and the AI follows a built-in <strong>designer's playbook</strong> (modern layout, responsive on phone/tablet/desktop, real images via free Openverse search). It can build <em>pixel-perfect custom pages</em> or <em>editable Gutenberg/Elementor pages</em> — just say which you prefer.</p>

            <h2 <?php echo $h; ?>>Go live: staging → production</h2>
            <p style="max-width:800px;">Build safely on a staging site, then ship to your live site in one step with <code>deploy_push</code> (set the production URL + token in Connect settings), or export a bundle and import it on the live site. Re-deploying updates instead of duplicating.</p>

            <h2 <?php echo $h; ?>>Skills &amp; memory (why it gets smarter)</h2>
            <p style="max-width:800px;"><strong>Memory</strong> = facts it remembers between chats ("our brand color is #f8ca50"). <strong>Skills</strong> = playbooks it follows automatically (this plugin ships expert skills for WooCommerce, SEO, blogging, design, and deploys). Both are shared by every AI you connect, so your whole team's AI stays consistent.</p>

            <h2 <?php echo $h; ?>>Copy-paste starter prompts</h2>
            <pre style="white-space:pre-wrap;background:#f6f7f7;padding:14px;border-radius:8px;max-width:800px;font-size:12px;">• "Give me a full status report on the store and site."
• "Write and publish a 900-word blog post about &lt;topic&gt;, add a hero image, and optimize its SEO."
• "Create a product called &lt;name&gt; at &lt;price&gt;, add an image, and put it in the &lt;category&gt; category."
• "Set up Cash on Delivery and a flat-rate shipping zone for the UAE at 15 AED."
• "Build a modern, responsive landing page for &lt;offer&gt; and show me the link."
• "Set up a daily digest email of sales and low-stock items."</pre>

            <h2 <?php echo $h; ?>>FAQ</h2>
            <p style="max-width:800px;"><strong>Is it safe on my live store?</strong> Install it anywhere, but only turn abilities ON where you're comfortable with changes. Best practice: build on staging, deploy to production.<br>
            <strong>What if the AI breaks something?</strong> Code snippets that error auto-disable themselves; you have an Activity log; and destructive actions can be toggled off entirely.<br>
            <strong>Does it cost money?</strong> The plugin is free. Your AI app's usage and (optionally) the autonomy API key are billed by those providers.<br>
            <strong>Does it work with ChatGPT/Codex/Gemini?</strong> Yes — any MCP-compatible AI app.<br>
            <strong>Which switches should I leave off?</strong> See the recommended table above; when unsure, off is safer — you can always turn it on later.</p>

            <p style="margin-top:24px;"><a href="<?php echo esc_url(admin_url('admin.php?page=claudpress')); ?>" class="button button-primary">← Back to Connect</a></p>
        </div>
        <?php
    }

    public function render_activity(): void
    {
        $log = Claudpress::instance()->logger->recent(100);
        ?>
        <div class="wrap">
            <h1>Claudpress · Activity</h1>
            <h2>Memory</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Wipe all memory?');" style="margin-bottom:8px;">
                <?php wp_nonce_field('claudpress_save'); ?>
                <input type="hidden" name="action" value="claudpress_save">
                <input type="hidden" name="claudpress_action" value="clear_memory">
                <button class="button">Clear all memory</button>
            </form>
            <table class="widefat striped"><thead><tr><th>Key</th><th>Category</th><th>Value</th><th>Updated</th></tr></thead><tbody>
                <?php foreach (Claudpress::instance()->memory->all() as $m) : ?>
                    <tr><td><code><?php echo esc_html($m['key']); ?></code></td><td><?php echo esc_html($m['category']); ?></td><td><?php echo esc_html(wp_trim_words($m['value'], 30)); ?></td><td><?php echo esc_html($m['updated_at']); ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty(Claudpress::instance()->memory->all())) : ?><tr><td colspan="4"><em>No memory yet.</em></td></tr><?php endif; ?>
            </tbody></table>

            <h2 style="margin-top:32px;">Recent tool calls</h2>
            <table class="widefat striped"><thead><tr><th>When</th><th>Tool</th><th>OK</th><th>Args</th><th>Note</th></tr></thead><tbody>
                <?php foreach ($log as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['created_at']); ?></td>
                        <td><code><?php echo esc_html($row['tool']); ?></code></td>
                        <td><?php echo $row['ok'] ? '✓' : '✗'; ?></td>
                        <td style="max-width:360px;overflow:hidden;"><code style="font-size:11px;"><?php echo esc_html(wp_trim_words((string) $row['args_json'], 30)); ?></code></td>
                        <td><?php echo esc_html($row['summary']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($log)) : ?><tr><td colspan="5"><em>No activity logged yet.</em></td></tr><?php endif; ?>
            </tbody></table>
        </div>
        <?php
    }
}
