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
        $r->add('elementor_page', 'Read or write an Elementor page. Actions: get (return the _elementor_data element tree + template type), set (replace _elementor_data for a page — pass the element tree as `data`, an array of sections/containers), create (make a new Elementor-editable page with optional data). Elementor stores layout as a JSON tree of elements; build with flexbox Containers (elType "container"), not deprecated sections. After set/create, Elementor CSS is regenerated automatically. set/create return `settings_warnings` ONLY when something looked wrong: a diagnostic (non-blocking) list of settings keys that no control on that widget/element recognises — a likely wrong control name (e.g. `menu` instead of `nav_menu`), worth checking against get_controls() but never a reason to assume the write failed.', [
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
            $tree = $a['data'] ?? [];
            $warnings = self::settings_warnings(is_array($tree) ? $tree : []);
            $res = ['success' => true, 'post_id' => $id, 'link' => get_permalink($id), 'edit_link' => admin_url('post.php?post=' . $id . '&action=elementor')];
            self::write_data($id, $tree, $a['template_type'] ?? 'wp-page');
            // Only when there is something to say. An empty array on every
            // write is noise in the response an agent reads dozens of times
            // during a build, and noise is how a real warning gets skimmed.
            return $warnings ? $res + ['settings_warnings' => $warnings] : $res;
        }

        // set
        $id = (int) ($a['post_id'] ?? 0);
        if (!$id || !get_post($id)) {
            return ['success' => false, 'error' => 'Post not found.'];
        }
        $tree = $a['data'] ?? [];
        $warnings = self::settings_warnings(is_array($tree) ? $tree : []);
        $res = ['success' => true, 'post_id' => $id, 'link' => get_permalink($id), 'edit_link' => admin_url('post.php?post=' . $id . '&action=elementor')];
        self::write_data($id, $tree, $a['template_type'] ?? get_post_meta($id, '_elementor_template_type', true) ?: 'wp-page');
        return $warnings ? $res + ['settings_warnings' => $warnings] : $res;
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

    /**
     * Walk an Elementor element tree and collect settings keys that no control
     * accounts for, e.g. `menu`/`menu_color` where the real controls are
     * `nav_menu`/`menu_text_color` — a wrong name is written and saved without
     * error, and the only symptom is a widget that silently ignores it.
     *
     * A warning, never a refusal: Elementor's control stacks are not a closed
     * set (third-party widgets, dynamic tags, global references), so treating
     * an unrecognised key as fatal would block correct work. This is purely
     * diagnostic, so a failure anywhere inside it must never fail the write —
     * Elementor's internals are not a stable API to build a gate on.
     *
     * @param array $elements Top-level (or nested) array of Elementor elements.
     * @return string[] Human-readable warnings, empty when nothing looked wrong.
     */
    private static function settings_warnings(array $elements): array
    {
        try {
            if (!self::active()) {
                return [];
            }
            $warnings = [];
            self::walk_elements($elements, $warnings);
            return $warnings;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function walk_elements(array $elements, array &$warnings): void
    {
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $elType      = (string) ($el['elType'] ?? '');
            $widgetType  = isset($el['widgetType']) ? (string) $el['widgetType'] : null;
            $settings    = is_array($el['settings'] ?? null) ? $el['settings'] : [];
            $id          = (string) ($el['id'] ?? '?');
            $label       = $widgetType ?: $elType;

            $controls = self::get_known_controls($elType, $widgetType);
            if ($controls === null) {
                $warnings[] = "Element {$id}: unknown widget type \"{$widgetType}\".";
            } elseif ($controls !== false) {
                foreach (self::classify_unknown_keys(array_keys($settings), $controls) as $key) {
                    $warnings[] = "Element {$id} ({$label}): unknown setting \"{$key}\".";
                }
            }

            if (!empty($el['elements']) && is_array($el['elements'])) {
                self::walk_elements($el['elements'], $warnings);
            }
        }
    }

    /**
     * Resolve the known control names for one element, or a sentinel:
     *   null  — widgetType does not exist at all (its own, more useful warning)
     *   false — nothing to check against (non-widget/container element type,
     *           or Elementor didn't hand back a controls list) — skip silently
     *
     * @return string[]|null|false
     */
    private static function get_known_controls(string $elType, ?string $widgetType)
    {
        if ($elType === 'widget') {
            if (!$widgetType || !class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->widgets_manager)) {
                return false;
            }
            $widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types($widgetType);
            if (!$widget) {
                return null;
            }
            return array_keys($widget->get_controls());
        }

        if (in_array($elType, ['container', 'section', 'column'], true)) {
            if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->elements_manager)) {
                return false;
            }
            $element = \Elementor\Plugin::$instance->elements_manager->get_element_types($elType);
            if (!$element) {
                return false;
            }
            return array_keys($element->get_controls());
        }

        return false;
    }

    /**
     * The pure part: which of $settingsKeys has no match in $knownControls,
     * once responsive suffixes are stripped and Elementor's own meta keys
     * (__globals__, __dynamic__, and the whole `_`-prefixed common/advanced
     * control stack — a separate stack a widget's own get_controls() never
     * returns) are allowed through unconditionally.
     *
     * @param string[] $settingsKeys
     * @param string[] $knownControls
     * @return string[]
     */
    public static function classify_unknown_keys(array $settingsKeys, array $knownControls): array
    {
        static $responsive_suffixes = ['_tablet_extra', '_mobile_extra', '_widescreen', '_laptop', '_tablet', '_mobile'];

        $known = array_flip($knownControls);
        $unknown = [];
        foreach ($settingsKeys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if ($key[0] === '_') { // covers __globals__, __dynamic__, and _element_id etc.
                continue;
            }
            $base = $key;
            foreach ($responsive_suffixes as $suffix) {
                if (substr($base, -strlen($suffix)) === $suffix) {
                    $base = substr($base, 0, -strlen($suffix));
                    break;
                }
            }
            if (!isset($known[$base])) {
                $unknown[] = $key;
            }
        }
        return $unknown;
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
