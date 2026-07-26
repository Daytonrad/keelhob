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
        } elseif ($action === 'automation_settings') {
            Claudpress::instance()->update([
                'agent_api_key'         => sanitize_text_field(wp_unslash($_POST['agent_api_key'] ?? '')),
                'agent_api_endpoint'    => esc_url_raw(wp_unslash($_POST['agent_api_endpoint'] ?? '')) ?: 'https://api.anthropic.com/v1/messages',
                'agent_model'           => sanitize_text_field(wp_unslash($_POST['agent_model'] ?? 'claude-sonnet-5')),
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
                        <p style="color:#666;">Create tasks by asking your AI: <em>"Create a daily digest task"</em> or <em>"Create an autonomous task that fulfils paid orders and publishes due drafts every morning."</em> Then manage them here.</p>
                        <table class="widefat striped">
                            <thead><tr><th>Name</th><th>Mode</th><th>Schedule</th><th>On</th><th>Last run</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($tasks as $t) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($t['name']); ?></strong><?php echo (int) $t['allow_risky'] === 1 ? ' <span title="allow_risky">⚠️</span>' : ''; ?></td>
                                    <td><?php echo esc_html($t['mode']); ?></td>
                                    <td><?php echo esc_html($t['schedule']); ?></td>
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
