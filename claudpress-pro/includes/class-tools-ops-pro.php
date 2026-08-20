<?php
/**
 * Pro operations tools: core/plugin/theme updates, and outbound email.
 *
 * These moved out of the free plugin rather than being gated inside it. Both
 * are maintenance work - the kind of thing people are paid to do on other
 * people's sites - and both are things a free install has no way to switch on,
 * so shipping the code and refusing to run it would only invite someone to
 * delete the refusal.
 *
 * The class name and both tool names are unchanged from the free plugin, so an
 * agent that worked against a Pro site before the split sees no difference.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Ops_Pro
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('manage_updates', 'Check for and apply WordPress core, plugin, and theme updates. Actions: check (list everything with an available update), update_plugins (update given plugin files, or all), update_themes (update given theme slugs, or all), update_core. Use this to keep the site secure and current.', [
            'type' => 'object',
            'properties' => [
                'action'  => ['type' => 'string', 'enum' => ['check', 'update_plugins', 'update_themes', 'update_core'], 'default' => 'check'],
                'items'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Plugin files or theme slugs to update. Omit or pass ["all"] to update everything of that kind.'],
            ],
            'required' => ['action'],
        ], [self::class, 'manage_updates'], ['destructiveHint' => true]);

        $r->add('send_email', 'Send an email from the site (via wp_mail). Use to notify the site owner — e.g. "log in and review pending plugin updates", a change summary, or an approval request. Defaults to the site admin email.', [
            'type' => 'object',
            'properties' => [
                'to'      => ['type' => 'string', 'description' => 'Recipient. Defaults to the admin email.'],
                'subject' => ['type' => 'string'],
                'message' => ['type' => 'string', 'description' => 'Plain text or HTML.'],
                'html'    => ['type' => 'boolean', 'default' => false],
            ],
            'required' => ['subject', 'message'],
        ], [self::class, 'send_email'], ['destructiveHint' => true]);
    }

    public static function manage_updates(array $a): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $action = $a['action'] ?? 'check';
        $items  = array_map('strval', (array) ($a['items'] ?? []));
        $all    = empty($items) || in_array('all', $items, true);

        if ($action === 'check') {
            wp_update_plugins();
            wp_update_themes();
            wp_version_check();

            $plugin_updates = function_exists('get_plugin_updates') ? get_plugin_updates() : [];
            $theme_updates  = function_exists('get_theme_updates') ? get_theme_updates() : [];
            $core           = get_core_updates();

            $plugins = [];
            foreach ($plugin_updates as $file => $data) {
                $plugins[] = ['file' => $file, 'name' => $data->Name, 'current' => $data->Version, 'new' => $data->update->new_version ?? null];
            }
            $themes = [];
            foreach ($theme_updates as $slug => $data) {
                $themes[] = ['slug' => $slug, 'name' => $data->get('Name'), 'current' => $data->get('Version'), 'new' => $data->update['new_version'] ?? null];
            }
            $core_new = (is_array($core) && !empty($core) && isset($core[0]->response) && $core[0]->response === 'upgrade') ? $core[0]->current : null;

            return ['success' => true, 'plugins' => $plugins, 'themes' => $themes, 'core_update' => $core_new, 'counts' => ['plugins' => count($plugins), 'themes' => count($themes)]];
        }

        $skin     = new WP_Ajax_Upgrader_Skin();

        if ($action === 'update_plugins') {
            wp_update_plugins();
            $targets = $all ? array_keys(get_plugin_updates()) : $items;
            if (empty($targets)) {
                return ['success' => true, 'updated' => [], 'note' => 'No plugin updates available.'];
            }
            $upgrader = new Plugin_Upgrader($skin);
            $result   = $upgrader->bulk_upgrade($targets);
            return ['success' => true, 'updated' => array_keys(array_filter((array) $result)), 'requested' => $targets];
        }

        if ($action === 'update_themes') {
            wp_update_themes();
            $targets = $all ? array_keys(get_theme_updates()) : $items;
            if (empty($targets)) {
                return ['success' => true, 'updated' => [], 'note' => 'No theme updates available.'];
            }
            $upgrader = new Theme_Upgrader($skin);
            $result   = $upgrader->bulk_upgrade($targets);
            return ['success' => true, 'updated' => array_keys(array_filter((array) $result)), 'requested' => $targets];
        }

        if ($action === 'update_core') {
            $core = get_core_updates();
            if (empty($core) || $core[0]->response !== 'upgrade') {
                return ['success' => true, 'note' => 'WordPress core is up to date.'];
            }
            $upgrader = new Core_Upgrader($skin);
            $result   = $upgrader->upgrade($core[0]);
            return is_wp_error($result)
                ? ['success' => false, 'error' => $result->get_error_message()]
                : ['success' => true, 'updated_core_to' => $core[0]->current];
        }

        return ['success' => false, 'error' => 'Unknown action.'];
    }

    // ---- email ------------------------------------------------------------

    public static function send_email(array $a): array
    {
        $to      = !empty($a['to']) ? sanitize_email($a['to']) : get_option('admin_email');
        $subject = (string) ($a['subject'] ?? '');
        $message = (string) ($a['message'] ?? '');
        $headers = !empty($a['html']) ? ['Content-Type: text/html; charset=UTF-8'] : [];

        $sent = wp_mail($to, $subject, $message, $headers);
        return ['success' => (bool) $sent, 'to' => $to, 'sent' => (bool) $sent, 'note' => $sent ? null : 'wp_mail returned false — the site may lack a configured mailer (SMTP).'];
    }
}
