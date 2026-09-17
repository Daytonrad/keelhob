<?php
/**
 * Conditional logic: should this snippet run for THIS request?
 *
 * ---------------------------------------------------------------------------
 * TWO STORED SHAPES, ONE ENGINE
 *
 * The shape written from 1.2.0 onwards:
 *
 *   { "enabled": true,
 *     "relation": "AND" | "OR",
 *     "rules": [ { "type": "...", "operator": "...", "value": "..." }, ... ] }
 *
 * The shape written before it, which agents wrote through `snippet_save` and
 * which is sitting in `conditions_json` on real sites right now:
 *
 *   { "logged_in": true, "front_page": false,
 *     "post_types": ["post"], "post_ids": [12, 44] }
 *
 * Both are read. `normalize()` lifts the old one into the new one every time it
 * is loaded, so there is **no database migration**: nothing is rewritten, no
 * upgrade step can half-run, and a site that rolls back to 1.1.0 still works
 * because its rows were never touched. `snippet_save` also still accepts the
 * old shape, so an agent working from older notes is not broken.
 *
 * ---------------------------------------------------------------------------
 * THE ONE PLACE THE OLD SHAPE MEANT SOMETHING SUBTLE
 *
 * The old `post_types` and `post_ids` checks were wrapped in `is_singular()`:
 *
 *     if (!empty($cond['post_types']) && is_singular()) { ... }
 *
 * So on an archive, a search page or the 404, those rules did not fail — they
 * did not apply at all, and the snippet ran. A plain "post type is post" rule
 * in the new shape fails there instead.
 *
 * Silently flipping that would change behaviour on live sites that never asked
 * for a new version of anything. So legacy rules are lifted with
 * `only_singular` set, and the engine skips them off a singular view exactly as
 * before. Rules built in the new editor do not carry the flag and behave the
 * obvious way.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Keelhob_Snippet_Conditions
{
    /** Rule types this engine understands. */
    public const TYPES = [
        'user_status',
        'user_role',
        'page_type',
        'post_type',
        'post_id',
        'url_path',
        'device',
    ];

    /** Operators, per type. Anything not listed uses DEFAULT_OPS. */
    private const DEFAULT_OPS = ['is', 'is_not'];
    private const PATH_OPS    = ['contains', 'equals', 'starts_with'];

    // ---- reading ----------------------------------------------------------

    /**
     * Decode whatever is stored into an array, or null.
     *
     * @param string|array|null $value
     * @return array<string, mixed>|null
     */
    public static function decode($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Lift any accepted shape into the current one.
     *
     * Always returns the full structure, so callers never branch on shape.
     *
     * @param string|array|null $value
     * @return array{enabled: bool, relation: string, rules: array<int, array<string, mixed>>}
     */
    public static function normalize($value): array
    {
        $empty = ['enabled' => false, 'relation' => 'AND', 'rules' => []];

        $c = self::decode($value);
        if (!$c) {
            return $empty;
        }

        // Current shape.
        if (array_key_exists('rules', $c) || array_key_exists('enabled', $c)) {
            $rules = [];
            foreach ((array) ($c['rules'] ?? []) as $rule) {
                $clean = self::clean_rule((array) $rule);
                if ($clean !== null) {
                    $rules[] = $clean;
                }
            }
            return [
                // A stored `enabled` wins. When the key is absent but rules are
                // present the intent is obvious, so do not make the caller
                // wonder why their rules are being ignored.
                'enabled'  => array_key_exists('enabled', $c) ? (bool) $c['enabled'] : ($rules !== []),
                'relation' => self::relation($c['relation'] ?? 'AND'),
                'rules'    => $rules,
            ];
        }

        return self::from_legacy($c);
    }

    /**
     * The pre-1.2.0 flat shape, lifted.
     *
     * Relation is AND, because every clause in the old engine had to pass.
     *
     * @param array<string, mixed> $c
     * @return array{enabled: bool, relation: string, rules: array<int, array<string, mixed>>}
     */
    private static function from_legacy(array $c): array
    {
        $rules = [];

        if (array_key_exists('logged_in', $c)) {
            $rules[] = [
                'type'     => 'user_status',
                'operator' => 'is',
                'value'    => $c['logged_in'] ? 'logged_in' : 'logged_out',
            ];
        }

        if (array_key_exists('front_page', $c)) {
            $rules[] = [
                'type'     => 'page_type',
                'operator' => $c['front_page'] ? 'is' : 'is_not',
                'value'    => 'front_page',
            ];
        }

        // See the file header: these two only ever applied on a singular view.
        if (!empty($c['post_types'])) {
            $rules[] = [
                'type'          => 'post_type',
                'operator'      => 'is',
                'value'         => array_values(array_map('strval', (array) $c['post_types'])),
                'only_singular' => true,
            ];
        }

        if (!empty($c['post_ids'])) {
            $rules[] = [
                'type'          => 'post_id',
                'operator'      => 'is',
                'value'         => array_values(array_map('intval', (array) $c['post_ids'])),
                'only_singular' => true,
            ];
        }

        return ['enabled' => $rules !== [], 'relation' => 'AND', 'rules' => $rules];
    }

    /**
     * Validate and tidy one rule. Returns null for a rule that cannot mean anything.
     *
     * @param array<string, mixed> $rule
     * @return array<string, mixed>|null
     */
    private static function clean_rule(array $rule): ?array
    {
        $type = isset($rule['type']) ? (string) $rule['type'] : '';
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }

        $ops = self::operators_for($type);
        $op  = isset($rule['operator']) ? (string) $rule['operator'] : $ops[0];
        if (!in_array($op, $ops, true)) {
            $op = $ops[0];
        }

        $value = $rule['value'] ?? '';
        if (is_array($value)) {
            // An array value means "any of these". The editor never writes one;
            // legacy data and agents do.
            $value = array_values(array_map('strval', $value));
            if ($value === []) {
                return null;
            }
        } else {
            $value = (string) $value;
            // A rule with no value is a half-finished row in the UI, not a rule
            // that matches nothing. Dropping it is the only safe reading: kept,
            // it would silently stop the snippet running everywhere.
            if ($value === '') {
                return null;
            }
        }

        $out = ['type' => $type, 'operator' => $op, 'value' => $value];
        if (!empty($rule['only_singular'])) {
            $out['only_singular'] = true;
        }
        return $out;
    }

    /** @return string[] */
    public static function operators_for(string $type): array
    {
        return $type === 'url_path' ? self::PATH_OPS : self::DEFAULT_OPS;
    }

    private static function relation($value): string
    {
        return strtoupper((string) $value) === 'OR' ? 'OR' : 'AND';
    }

    /** Are there conditions that will actually be applied? */
    public static function has($value): bool
    {
        $c = self::normalize($value);
        return $c['enabled'] && $c['rules'] !== [];
    }

    // ---- evaluating -------------------------------------------------------

    /**
     * Should the snippet run on this request?
     *
     * Returns true whenever there is nothing to decide, so a snippet with no
     * conditions, broken conditions, or conditions switched off runs exactly as
     * it did before conditions existed.
     *
     * @param string|array|null $value
     */
    public static function passes($value): bool
    {
        $c = self::normalize($value);
        if (!$c['enabled'] || $c['rules'] === []) {
            return true;
        }

        $results = [];
        foreach ($c['rules'] as $rule) {
            $r = self::eval_rule($rule);
            if ($r === null) {
                continue; // did not apply to this request at all
            }
            $results[] = $r;
        }

        if ($results === []) {
            return true;
        }
        return $c['relation'] === 'OR'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    /**
     * One rule. Null means "this rule does not apply here" — see `only_singular`.
     *
     * @param array<string, mixed> $rule
     */
    private static function eval_rule(array $rule): ?bool
    {
        $type = (string) ($rule['type'] ?? '');
        $op   = (string) ($rule['operator'] ?? 'is');
        $val  = $rule['value'] ?? '';

        if (!empty($rule['only_singular']) && !is_singular()) {
            return null;
        }

        if ($type === 'url_path') {
            return self::match_path($op, (string) (is_array($val) ? reset($val) : $val));
        }

        $match = false;
        foreach ((array) $val as $one) {
            if (self::matches($type, (string) $one)) {
                $match = true;
                break;
            }
        }
        return $op === 'is_not' ? !$match : $match;
    }

    private static function match_path(string $op, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }
        $uri  = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        if ($op === 'equals') {
            return untrailingslashit($path) === untrailingslashit($needle);
        }
        if ($op === 'starts_with') {
            return strpos($path, $needle) === 0;
        }
        return strpos($path, $needle) !== false;
    }

    private static function matches(string $type, string $val): bool
    {
        switch ($type) {
            case 'user_status':
                return $val === 'logged_in' ? is_user_logged_in() : !is_user_logged_in();

            case 'user_role':
                if (!is_user_logged_in()) {
                    return false;
                }
                $user = wp_get_current_user();
                return in_array($val, (array) ($user->roles ?? []), true);

            case 'device':
                return $val === 'mobile' ? wp_is_mobile() : !wp_is_mobile();

            case 'page_type':
                return self::is_page_type($val);

            case 'post_type':
                $pt = get_post_type(get_queried_object_id());
                if (!$pt && isset($GLOBALS['post'])) {
                    $pt = get_post_type($GLOBALS['post']);
                }
                return $pt === $val;

            case 'post_id':
                return (int) get_queried_object_id() === (int) $val;
        }
        return false;
    }

    private static function is_page_type(string $val): bool
    {
        switch ($val) {
            case 'front_page':
                return is_front_page();
            case 'home':
                return is_home();
            case 'single':
                return is_single();
            case 'page':
                return is_page();
            case 'archive':
                return is_archive();
            case 'search':
                return is_search();
            case '404':
                return is_404();
        }
        return false;
    }

    // ---- the builder UI ---------------------------------------------------

    /**
     * Everything the rule builder needs, in one array.
     *
     * Roles and post types are read here rather than passed in, because there
     * is exactly one caller and making it fetch them only moved the work.
     *
     * @return array<string, mixed>
     */
    public static function ui_config(): array
    {
        return [
            'types'     => [
                'user_status' => __('User is', 'keelhob'),
                'user_role'   => __('User role', 'keelhob'),
                'page_type'   => __('Page type', 'keelhob'),
                'post_type'   => __('Post type', 'keelhob'),
                'post_id'     => __('Post ID', 'keelhob'),
                'url_path'    => __('URL path', 'keelhob'),
                'device'      => __('Device', 'keelhob'),
            ],
            'operators' => [
                'default'  => [
                    ['is', __('is', 'keelhob')],
                    ['is_not', __('is not', 'keelhob')],
                ],
                'url_path' => [
                    ['contains', __('contains', 'keelhob')],
                    ['equals', __('equals', 'keelhob')],
                    ['starts_with', __('starts with', 'keelhob')],
                ],
            ],
            'values'    => [
                'user_status' => [
                    ['logged_in', __('Logged in', 'keelhob')],
                    ['logged_out', __('Logged out', 'keelhob')],
                ],
                'device'      => [
                    ['mobile', __('Mobile', 'keelhob')],
                    ['desktop', __('Desktop', 'keelhob')],
                ],
                'page_type'   => [
                    ['front_page', __('Front page', 'keelhob')],
                    ['home', __('Blog posts index', 'keelhob')],
                    ['single', __('Single post', 'keelhob')],
                    ['page', __('Page', 'keelhob')],
                    ['archive', __('Archive', 'keelhob')],
                    ['search', __('Search results', 'keelhob')],
                    ['404', __('404 not found', 'keelhob')],
                ],
                'user_role'   => self::role_pairs(),
                'post_type'   => self::post_type_pairs(),
                // null means "a free-text box, not a dropdown".
                'url_path'    => null,
                'post_id'     => null,
            ],
        ];
    }

    /** @return array<int, array{0: string, 1: string}> */
    private static function role_pairs(): array
    {
        $out   = [];
        $roles = function_exists('wp_roles') ? wp_roles()->get_names() : [];
        foreach ((array) $roles as $slug => $label) {
            $out[] = [(string) $slug, (string) $label];
        }
        return $out;
    }

    /** @return array<int, array{0: string, 1: string}> */
    private static function post_type_pairs(): array
    {
        $out = [];
        foreach (get_post_types(['public' => true], 'objects') as $pt) {
            $out[] = [(string) $pt->name, (string) ($pt->labels->singular_name ?? $pt->name)];
        }
        return $out;
    }

    // ---- writing ----------------------------------------------------------

    /**
     * Build the stored structure from a submitted admin form.
     *
     * Returns null when there is nothing worth storing, which is what clears a
     * snippet's conditions.
     *
     * @param array<string, mixed> $post Raw $_POST.
     * @return array<string, mixed>|null
     */
    public static function from_form(array $post): ?array
    {
        $enabled = !empty($post['conditions_enabled']);

        $types  = (array) ($post['cond_type'] ?? []);
        $ops    = (array) ($post['cond_operator'] ?? []);
        $values = (array) ($post['cond_value'] ?? []);

        $rules = [];
        foreach ($types as $i => $type) {
            $clean = self::clean_rule([
                'type'     => sanitize_key((string) $type),
                'operator' => sanitize_key((string) ($ops[$i] ?? '')),
                'value'    => sanitize_text_field((string) ($values[$i] ?? '')),
            ]);
            if ($clean !== null) {
                $rules[] = $clean;
            }
        }

        // Switched off with no rules is the same as never having had any. Keep
        // the rules when it is merely switched off, though, so unticking the box
        // and saving does not throw away work somebody spent time on.
        if (!$enabled && $rules === []) {
            return null;
        }

        return [
            'enabled'  => $enabled,
            'relation' => self::relation($post['conditions_relation'] ?? 'AND'),
            'rules'    => $rules,
        ];
    }

    /**
     * A short human sentence for the list screen, e.g. "Logged in + 2 more".
     *
     * @param string|array|null $value
     */
    public static function summary($value): string
    {
        $c = self::normalize($value);
        if (!$c['enabled'] || $c['rules'] === []) {
            return '';
        }

        $cfg   = self::ui_config();
        $first = $c['rules'][0];
        $label = $cfg['types'][$first['type']] ?? $first['type'];

        $val  = is_array($first['value']) ? implode(', ', $first['value']) : (string) $first['value'];
        $opts = $cfg['values'][$first['type']] ?? null;
        if (is_array($opts)) {
            foreach ($opts as $pair) {
                if ($pair[0] === $val) {
                    $val = $pair[1];
                    break;
                }
            }
        }

        $ops     = self::operators_for($first['type']);
        $op_word = $first['operator'] === $ops[0] ? '' : str_replace('_', ' ', $first['operator']) . ' ';
        $text    = trim($label . ' ' . $op_word . $val);

        $more = count($c['rules']) - 1;
        if ($more > 0) {
            /* translators: %1$s: first rule, %2$d: how many other rules. */
            $text = sprintf(_n('%1$s + %2$d more', '%1$s + %2$d more', $more, 'keelhob'), $text, $more);
        }
        return $text;
    }
}
