<?php
/**
 * Elementor tools. Read and write the Elementor page model (`_elementor_data`,
 * a JSON tree of elements), create Elementor-editable pages, and configure the
 * two experiments that matter for AI building: Container (flexbox) ON and the
 * Atomic editor OFF — exactly the setup recommended for agent-built sites.
 *
 * Registered always; each callback reports clearly when Elementor is not active
 * so the agent knows to install it (manage_plugin action=install plugin=elementor).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Elementor
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        $r->add('elementor_page', 'Read or write an Elementor page. Actions: get (return the _elementor_data element tree + template type), set (replace _elementor_data for a page — pass the element tree as `data`, an array of sections/containers), create (make a new Elementor-editable page with optional data). Elementor stores layout as a JSON tree of elements; build with flexbox Containers (elType "container"), not deprecated sections. After set/create, Elementor CSS is regenerated automatically.', [
            'type' => 'object',
            'properties' => [
                'action'   => ['type' => 'string', 'enum' => ['get', 'set', 'create'], 'default' => 'get'],
                'post_id'  => ['type' => 'integer'],
                'title'    => ['type' => 'string', 'description' => 'For create.'],
                'status'   => ['type' => 'string', 'default' => 'publish'],
                'data'     => ['type' => 'array', 'description' => 'The Elementor element tree (array of top-level elements). Each element: {id, elType, settings, elements}.'],
                'template_type' => ['type' => 'string', 'description' => 'e.g. wp-page, wp-post, header, footer, single, archive.'],
            ],
            'required' => ['action'],
        ], [self::class, 'page'], ['destructiveHint' => true]);

        $r->add('elementor_settings', 'Configure Elementor for AI building. Actions: get (report Elementor version, active experiments, active kit id), prepare_for_ai (turn Container/flexbox ON and the Atomic editor OFF — the recommended setup so the agent builds with modern containers), set_experiment (set any experiment on/off by key). Run prepare_for_ai once before building Elementor pages.', [
            'type' => 'object',
            'properties' => [
                'action'     => ['type' => 'string', 'enum' => ['get', 'prepare_for_ai', 'set_experiment'], 'default' => 'get'],
                'experiment' => ['type' => 'string', 'description' => 'Experiment key for set_experiment, e.g. container, e_atomic_elements.'],
                'state'      => ['type' => 'string', 'enum' => ['active', 'inactive', 'default'], 'default' => 'active'],
            ],
            'required' => ['action'],
        ], [self::class, 'settings'], ['destructiveHint' => true]);

        $r->add('elementor_kit', 'Read or update the Elementor global design kit (site-wide colors, fonts, spacing). Actions: get, set. Use this to apply a brand kit across every Elementor page at once. `settings` is merged into the kit\'s _elementor_page_settings (e.g. system_colors, custom_colors, system_typography).', [
            'type' => 'object',
            'properties' => [
                'action'   => ['type' => 'string', 'enum' => ['get', 'set'], 'default' => 'get'],
                'settings' => ['type' => 'object'],
            ],
            'required' => ['action'],
        ], [self::class, 'kit'], ['destructiveHint' => true]);
    }

    private static function active(): bool
    {
        return did_action('elementor/loaded') || class_exists('\Elementor\Plugin');
    }

    private static function not_active(): array
    {
        return ['success' => false, 'error' => 'Elementor is not active. Install & activate it first: manage_plugin action=install plugin=elementor, then manage_plugin action=activate plugin=elementor/elementor.php'];
    }

    public static function page(array $a): array
    {
        if (!self::active()) {
            return self::not_active();
        }
        $action = $a['action'] ?? 'get';

        if ($action === 'get') {
            $id = (int) ($a['post_id'] ?? 0);
            if (!$id || !get_post($id)) {
                return ['success' => false, 'error' => 'Post not found.'];
            }
            $raw = get_post_meta($id, '_elementor_data', true);
            $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            return [
                'success'       => true,
                'post_id'       => $id,
                'edit_mode'     => get_post_meta($id, '_elementor_edit_mode', true),
                'template_type' => get_post_meta($id, '_elementor_template_type', true),
                'data'          => $data,
            ];
        }

        if ($action === 'create') {
            $id = wp_insert_post([
                'post_title'   => (string) ($a['title'] ?? 'Untitled'),
                'post_status'  => (string) ($a['status'] ?? 'publish'),
                'post_type'    => 'page',
                'post_content' => '',
            ], true);
            if (is_wp_error($id)) {
                return ['success' => false, 'error' => $id->get_error_message()];
            }
            self::write_data($id, $a['data'] ?? [], $a['template_type'] ?? 'wp-page');
            return ['success' => true, 'post_id' => $id, 'link' => get_permalink($id), 'edit_link' => admin_url('post.php?post=' . $id . '&action=elementor')];
        }

        // set
        $id = (int) ($a['post_id'] ?? 0);
        if (!$id || !get_post($id)) {
            return ['success' => false, 'error' => 'Post not found.'];
        }
        self::write_data($id, $a['data'] ?? [], $a['template_type'] ?? get_post_meta($id, '_elementor_template_type', true) ?: 'wp-page');
        return ['success' => true, 'post_id' => $id, 'link' => get_permalink($id), 'edit_link' => admin_url('post.php?post=' . $id . '&action=elementor')];
    }

    private static function write_data(int $id, $data, string $template_type): void
    {
        // Elementor expects _elementor_data stored as slash-escaped JSON string.
        $json = wp_json_encode($data);
        update_post_meta($id, '_elementor_data', wp_slash($json));
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_template_type', $template_type);
        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($id, '_elementor_version', ELEMENTOR_VERSION);
        }
        self::flush_css($id);
    }

    private static function flush_css(int $id): void
    {
        delete_post_meta($id, '_elementor_css');
        if (class_exists('\Elementor\Plugin')) {
            try {
                $plugin = \Elementor\Plugin::$instance;
                if (isset($plugin->files_manager)) {
                    $plugin->files_manager->clear_cache();
                }
            } catch (\Throwable $e) {
                // best-effort; CSS regenerates on next view anyway
            }
        }
    }

    public static function settings(array $a): array
    {
        if (!self::active()) {
            return self::not_active();
        }
        $action = $a['action'] ?? 'get';

        if ($action === 'prepare_for_ai') {
            self::set_experiment('container', 'active');
            self::set_experiment('e_atomic_elements', 'inactive'); // Atomic / editor v4
            self::set_experiment('editor_v2', 'inactive');
            return ['success' => true, 'applied' => ['container' => 'active', 'e_atomic_elements' => 'inactive', 'editor_v2' => 'inactive'], 'note' => 'Container (flexbox) enabled, Atomic editor disabled. Elementor is ready for AI-built pages.'];
        }

        if ($action === 'set_experiment') {
            $key = sanitize_key((string) ($a['experiment'] ?? ''));
            if ($key === '') {
                return ['success' => false, 'error' => 'experiment key required.'];
            }
            self::set_experiment($key, (string) ($a['state'] ?? 'active'));
            return ['success' => true, 'experiment' => $key, 'state' => $a['state'] ?? 'active'];
        }

        // get
        $experiments = [];
        foreach (['container', 'e_atomic_elements', 'editor_v2', 'nested-elements'] as $k) {
            $experiments[$k] = get_option('elementor_experiment-' . $k, 'default');
        }
        return [
            'success'     => true,
            'version'     => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
            'pro_active'  => defined('ELEMENTOR_PRO_VERSION'),
            'active_kit'  => (int) get_option('elementor_active_kit'),
            'experiments' => $experiments,
        ];
    }

    private static function set_experiment(string $key, string $state): void
    {
        // Elementor reads experiment state from option elementor_experiment-<key>.
        update_option('elementor_experiment-' . $key, $state);
    }

    public static function kit(array $a): array
    {
        if (!self::active()) {
            return self::not_active();
        }
        $kit_id = (int) get_option('elementor_active_kit');
        if (!$kit_id) {
            return ['success' => false, 'error' => 'No active Elementor kit found.'];
        }
        $settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        $settings = is_array($settings) ? $settings : [];

        if (($a['action'] ?? 'get') === 'set') {
            $new = is_array($a['settings'] ?? null) ? $a['settings'] : [];
            $merged = array_merge($settings, $new);
            update_post_meta($kit_id, '_elementor_page_settings', $merged);
            delete_post_meta($kit_id, '_elementor_css');
            self::flush_css($kit_id);
            return ['success' => true, 'kit_id' => $kit_id, 'settings' => $merged];
        }
        return ['success' => true, 'kit_id' => $kit_id, 'settings' => $settings];
    }
}
