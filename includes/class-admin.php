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
            ]);
        } elseif ($action === 'clear_memory') {
            Claudpress::instance()->memory->clear();
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
