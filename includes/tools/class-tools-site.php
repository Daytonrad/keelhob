<?php
/**
 * Site administration tools: options, users, plugins, themes, menus.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Site
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('manage_option', 'Get or update a WordPress option (site settings like blogname, blogdescription, timezone_string, permalink structure, etc.). Actions: get, update, list_common.', [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['get', 'update', 'list_common'], 'default' => 'get'],
                'name'   => ['type' => 'string'],
                'value'  => ['description' => 'New value (for update). Any JSON type.'],
            ],
            'required' => ['action'],
        ], [self::class, 'manage_option'], ['destructiveHint' => true]);

        $r->add('manage_user', 'Manage WordPress users. Actions: list, get, create, update, delete. Handles role, email, password, display name, and meta.', [
            'type' => 'object',
            'properties' => [
                'action'       => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete'], 'default' => 'list'],
                'id'           => ['type' => 'integer'],
                'username'     => ['type' => 'string'],
                'email'        => ['type' => 'string'],
                'password'     => ['type' => 'string'],
                'role'         => ['type' => 'string'],
                'display_name' => ['type' => 'string'],
                'reassign'     => ['type' => 'integer', 'description' => 'On delete, reassign this user\'s posts to this user ID.'],
                'per_page'     => ['type' => 'integer', 'default' => 50],
            ],
            'required' => ['action'],
        ], [self::class, 'manage_user'], ['destructiveHint' => true]);

        $r->add('manage_plugin', 'Manage plugins. Actions: list, activate, deactivate, install (from a wordpress.org slug), delete. Use install then activate to add a new plugin from the WP.org directory.', [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'activate', 'deactivate', 'install', 'delete'], 'default' => 'list'],
                'plugin' => ['type' => 'string', 'description' => 'Plugin file (e.g. woocommerce/woocommerce.php) or, for install, the WP.org slug (e.g. woocommerce).'],
            ],
            'required' => ['action'],
        ], [self::class, 'manage_plugin'], ['destructiveHint' => true]);

        $r->add('manage_theme', 'Manage themes. Actions: list, activate, install (from a wordpress.org slug).', [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'activate', 'install'], 'default' => 'list'],
                'theme'  => ['type' => 'string', 'description' => 'Theme stylesheet/slug.'],
            ],
            'required' => ['action'],
        ], [self::class, 'manage_theme'], ['destructiveHint' => true]);

        $r->add('manage_menu', 'Manage navigation menus. Actions: list, create, add_item, assign_location. Add pages/posts/custom links to a menu and assign it to a theme location.', [
            'type' => 'object',
            'properties' => [
                'action'    => ['type' => 'string', 'enum' => ['list', 'create', 'add_item', 'assign_location'], 'default' => 'list'],
                'menu'      => ['type' => 'string', 'description' => 'Menu name or ID.'],
                'title'     => ['type' => 'string'],
                'url'       => ['type' => 'string'],
                'object_id' => ['type' => 'integer', 'description' => 'Post/page ID for a page/post menu item.'],
                'object'    => ['type' => 'string', 'description' => 'menu-item-object: page, post, custom, category.'],
                'location'  => ['type' => 'string', 'description' => 'Theme menu location slug.'],
            ],
            'required' => ['action'],
        ], [self::class, 'manage_menu'], ['destructiveHint' => true]);
    }

    public static function manage_option(array $args): array
    {
        $action = $args['action'] ?? 'get';
        if ($action === 'list_common') {
            $keys = ['blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'timezone_string', 'date_format', 'time_format', 'start_of_week', 'permalink_structure', 'posts_per_page', 'default_category', 'page_on_front', 'show_on_front', 'template', 'stylesheet'];
            $out = [];
            foreach ($keys as $k) {
                $out[$k] = get_option($k);
            }
            return ['success' => true, 'options' => $out];
        }
        $name = (string) ($args['name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'error' => 'Option name required.'];
        }
        if ($action === 'update') {
            $ok = update_option($name, $args['value'] ?? null);
            return ['success' => true, 'name' => $name, 'changed' => $ok, 'value' => get_option($name)];
        }
        return ['success' => true, 'name' => $name, 'value' => get_option($name)];
    }

    public static function manage_user(array $args): array
    {
        $action = $args['action'] ?? 'list';
        switch ($action) {
            case 'create':
                $id = wp_insert_user([
                    'user_login'   => sanitize_user((string) ($args['username'] ?? '')),
                    'user_email'   => sanitize_email((string) ($args['email'] ?? '')),
                    'user_pass'    => (string) ($args['password'] ?? wp_generate_password(16)),
                    'role'         => sanitize_key($args['role'] ?? 'subscriber'),
                    'display_name' => $args['display_name'] ?? null,
                ]);
                return is_wp_error($id) ? ['success' => false, 'error' => $id->get_error_message()] : ['success' => true, 'id' => $id];

            case 'update':
                $data = ['ID' => (int) ($args['id'] ?? 0)];
                foreach (['email' => 'user_email', 'password' => 'user_pass', 'display_name' => 'display_name', 'role' => 'role'] as $a => $f) {
                    if (!empty($args[$a])) {
                        $data[$f] = $args[$a];
                    }
                }
                $res = wp_update_user($data);
                return is_wp_error($res) ? ['success' => false, 'error' => $res->get_error_message()] : ['success' => true, 'id' => (int) $data['ID']];

            case 'delete':
                require_once ABSPATH . 'wp-admin/includes/user.php';
                $ok = wp_delete_user((int) ($args['id'] ?? 0), $args['reassign'] ?? null);
                return ['success' => (bool) $ok];

            case 'get':
                $u = get_userdata((int) ($args['id'] ?? 0));
                if (!$u) {
                    return ['success' => false, 'error' => 'User not found.'];
                }
                return ['success' => true, 'user' => ['id' => $u->ID, 'login' => $u->user_login, 'email' => $u->user_email, 'display_name' => $u->display_name, 'roles' => $u->roles]];

            case 'list':
            default:
                $users = get_users(['number' => (int) ($args['per_page'] ?? 50)]);
                return ['success' => true, 'users' => array_map(fn($u) => ['id' => $u->ID, 'login' => $u->user_login, 'email' => $u->user_email, 'roles' => $u->roles], $users)];
        }
    }

    public static function manage_plugin(array $args): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $action = $args['action'] ?? 'list';
        $plugin = (string) ($args['plugin'] ?? '');

        switch ($action) {
            case 'activate':
                $res = activate_plugin($plugin);
                if (is_wp_error($res)) {
                    return ['success' => false, 'error' => $res->get_error_message()];
                }
                $result = ['success' => true, 'plugin' => $plugin, 'active' => true];
                $result = Claudpress_Health::guard($result, 'activating this plugin', static function () use ($plugin) {
                    deactivate_plugins($plugin);
                });
                // guard() ran the revert (deactivate) at most once, as its own
                // control probe — see the CONTRACT in class-health.php. Decide
                // what that means for THIS field, because guard() won't:
                if (($result['front_end_check'] ?? null) === 'FAILED') {
                    // Deactivating fixed the site, so it stays deactivated —
                    // and the result must say so, not claim active:true next
                    // to a note saying it was switched off.
                    $result['active'] = false;
                } elseif (($result['front_end_check'] ?? null) === 'passed') {
                    // The revert ran and did NOT help — this plugin was not
                    // the cause, so re-activate it or an innocent activation
                    // is left reverted while claiming success (same shape as
                    // Claudpress_Snippets::verify_front_end() restoring active=1).
                    activate_plugin($plugin);
                }
                return $result;

            case 'deactivate':
                // A deactivation cannot break a working front end — nothing to guard.
                deactivate_plugins($plugin);
                return ['success' => true, 'plugin' => $plugin, 'active' => false];

            case 'install':
                // Writes files but activates nothing, so it cannot break a running site.
                return self::install_package('plugin', $plugin);

            case 'delete':
                // WordPress refuses to delete an active plugin (delete_plugins()
                // errors out rather than removing its files), so the case a
                // guard would exist for — deleting an ACTIVE plugin's files and
                // fataling the site — is not reachable through this branch.
                $res = delete_plugins([$plugin]);
                return is_wp_error($res) ? ['success' => false, 'error' => $res->get_error_message()] : ['success' => true, 'deleted' => $plugin];

            case 'list':
            default:
                // No write, nothing to guard.
                $all    = get_plugins();
                $active = (array) get_option('active_plugins', []);
                $out = [];
                foreach ($all as $file => $meta) {
                    $out[] = ['file' => $file, 'name' => $meta['Name'], 'version' => $meta['Version'], 'active' => in_array($file, $active, true)];
                }
                return ['success' => true, 'plugins' => $out];
        }
    }

    public static function manage_theme(array $args): array
    {
        $action = $args['action'] ?? 'list';
        switch ($action) {
            case 'activate':
                // MUST be captured before switch_theme() runs. Capturing it
                // after gives the NEW theme, so the revert below would switch
                // to the broken theme again — a revert that does nothing,
                // silently, in exactly the situation where it matters.
                $previous = get_stylesheet();
                switch_theme((string) ($args['theme'] ?? ''));
                $result = ['success' => true, 'active_theme' => get_stylesheet()];
                $result = Claudpress_Health::guard($result, 'activating this theme', static function () use ($previous) {
                    switch_theme($previous);
                });
                // Same decision as manage_plugin's activate branch — see the
                // comments there and the CONTRACT in class-health.php.
                if (($result['front_end_check'] ?? null) === 'FAILED') {
                    // Switching back fixed the site; reflect the reverted theme.
                    $result['active_theme'] = get_stylesheet();
                } elseif (($result['front_end_check'] ?? null) === 'passed') {
                    // The revert ran and did NOT help — this theme was not the
                    // cause, so switch back to it rather than leaving an
                    // innocent activation reverted.
                    switch_theme((string) ($args['theme'] ?? ''));
                    $result['active_theme'] = get_stylesheet();
                }
                return $result;
            case 'install':
                // Writes files but activates nothing, so it cannot break a running site.
                return self::install_package('theme', (string) ($args['theme'] ?? ''));
            case 'list':
            default:
                // No write, nothing to guard.
                $themes = wp_get_themes();
                $out = [];
                foreach ($themes as $slug => $t) {
                    $out[] = ['slug' => $slug, 'name' => $t->get('Name'), 'version' => $t->get('Version'), 'active' => $slug === get_stylesheet()];
                }
                return ['success' => true, 'themes' => $out];
        }
    }

    /** Install a plugin/theme from the WordPress.org directory by slug. */
    private static function install_package(string $type, string $slug): array
    {
        if ($slug === '') {
            return ['success' => false, 'error' => 'Provide a WordPress.org slug.'];
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        if ($type === 'plugin') {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        } else {
            require_once ABSPATH . 'wp-admin/includes/theme-install.php';
            require_once ABSPATH . 'wp-admin/includes/theme.php';
            $api = themes_api('theme_information', ['slug' => $slug]);
        }
        if (is_wp_error($api)) {
            return ['success' => false, 'error' => $api->get_error_message()];
        }

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = $type === 'plugin' ? new Plugin_Upgrader($skin) : new Theme_Upgrader($skin);
        $result   = $upgrader->install($api->download_link);

        if (is_wp_error($result)) {
            return ['success' => false, 'error' => $result->get_error_message()];
        }
        if ($skin->get_errors()->has_errors()) {
            return ['success' => false, 'error' => $skin->get_error_messages()];
        }
        return ['success' => true, 'installed' => $slug, 'type' => $type, 'note' => $type === 'plugin' ? 'Now call manage_plugin action=activate with the plugin file.' : 'Now call manage_theme action=activate.'];
    }

    public static function manage_menu(array $args): array
    {
        $action = $args['action'] ?? 'list';
        switch ($action) {
            case 'create':
                $id = wp_create_nav_menu((string) ($args['title'] ?? $args['menu'] ?? 'Menu'));
                return is_wp_error($id) ? ['success' => false, 'error' => $id->get_error_message()] : ['success' => true, 'menu_id' => $id];

            case 'add_item':
                $menu = self::resolve_menu($args['menu'] ?? '');
                if (!$menu) {
                    return ['success' => false, 'error' => 'Menu not found.'];
                }
                $item = wp_update_nav_menu_item($menu->term_id, 0, [
                    'menu-item-title'     => $args['title'] ?? '',
                    'menu-item-url'       => $args['url'] ?? '',
                    'menu-item-object-id' => $args['object_id'] ?? 0,
                    'menu-item-object'    => $args['object'] ?? 'custom',
                    'menu-item-type'      => !empty($args['object_id']) ? 'post_type' : 'custom',
                    'menu-item-status'    => 'publish',
                ]);
                return is_wp_error($item) ? ['success' => false, 'error' => $item->get_error_message()] : ['success' => true, 'item_id' => $item];

            case 'assign_location':
                $menu = self::resolve_menu($args['menu'] ?? '');
                if (!$menu) {
                    return ['success' => false, 'error' => 'Menu not found.'];
                }
                $locations = get_theme_mod('nav_menu_locations', []);
                $locations[(string) ($args['location'] ?? '')] = $menu->term_id;
                set_theme_mod('nav_menu_locations', $locations);
                return ['success' => true, 'assigned' => $args['location'] ?? ''];

            case 'list':
            default:
                $menus = wp_get_nav_menus();
                return ['success' => true, 'menus' => array_map(fn($m) => ['id' => $m->term_id, 'name' => $m->name, 'count' => $m->count], $menus), 'locations' => get_registered_nav_menus()];
        }
    }

    private static function resolve_menu($ref)
    {
        if (is_numeric($ref)) {
            return wp_get_nav_menu_object((int) $ref);
        }
        return wp_get_nav_menu_object((string) $ref);
    }
}
