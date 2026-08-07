<?php
/**
 * Test bootstrap: just enough WordPress to load and exercise Claudpress's pure
 * logic outside a WordPress install.
 *
 * This is deliberately NOT a WordPress test harness. It covers the decision
 * logic — policy, capability gating, budget caps, retry/backoff, scheduling,
 * key normalization — which is where the bugs that matter live and which needs
 * no database. Anything that genuinely talks to WordPress or WooCommerce is
 * covered by tests/live-smoke.mjs against a real site instead.
 */

define('ABSPATH', __DIR__ . '/fake-wp/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('CLAUDPRESS_VERSION', '1.4.0-test');
define('CLAUDPRESS_FILE', dirname(__DIR__) . '/claudpress.php');
define('CLAUDPRESS_DIR', dirname(__DIR__) . '/');
define('CLAUDPRESS_URL', 'http://example.test/wp-content/plugins/claudpress/');
define('CLAUDPRESS_BASENAME', 'claudpress/claudpress.php');
define('CLAUDPRESS_REST_NAMESPACE', 'claudpress/v1');
define('CLAUDPRESS_SANDBOX_DIR', WP_CONTENT_DIR . '/claudpress-sandbox');
define('CLAUDPRESS_MAX_EXECUTION_TIME', 120);

// ---- in-memory option store ------------------------------------------------

$GLOBALS['cp_test_options'] = [];

function cp_test_reset_options(array $options = []): void
{
    $GLOBALS['cp_test_options'] = $options;
}

function get_option(string $name, $default = false)
{
    return array_key_exists($name, $GLOBALS['cp_test_options']) ? $GLOBALS['cp_test_options'][$name] : $default;
}

function update_option(string $name, $value): bool
{
    $GLOBALS['cp_test_options'][$name] = $value;
    return true;
}

function delete_option(string $name): bool
{
    unset($GLOBALS['cp_test_options'][$name]);
    return true;
}

// ---- WordPress helpers used by the code under test -------------------------

function apply_filters(string $hook, $value, ...$args)
{
    return $value;
}

function add_filter(string $hook, $cb, int $priority = 10, int $args = 1): bool
{
    return true;
}

function add_action(string $hook, $cb, int $priority = 10, int $args = 1): bool
{
    return true;
}

function do_action(string $hook, ...$args): void
{
}

function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function sanitize_text_field($str): string
{
    return trim(strip_tags((string) $str));
}

function sanitize_title(string $s): string
{
    return trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', strtolower($s))), '-');
}

function wp_json_encode($data, int $flags = 0)
{
    return json_encode($data, $flags);
}

function current_time(string $type = 'mysql', $gmt = 0)
{
    if ($type === 'timestamp' || $type === 'U') {
        return time();
    }
    if ($type === 'G') {
        return (int) gmdate('G');
    }
    return gmdate('Y-m-d H:i:s');
}

function get_bloginfo(string $show = 'name'): string
{
    return 'Test Site';
}

function home_url(string $path = ''): string
{
    return 'https://example.test' . $path;
}

function number_format_i18n($number, $decimals = 0): string
{
    return number_format((float) $number, (int) $decimals);
}

function wp_strip_all_tags($text): string
{
    return strip_tags((string) $text);
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

class WP_Error
{
    private string $message;

    public function __construct(string $code = '', string $message = '')
    {
        $this->message = $message;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

// A minimal $wpdb so classes that reference it at load time don't explode.
class CP_Test_WPDB
{
    public string $prefix = 'wp_';

    public function get_charset_collate(): string
    {
        return '';
    }

    public function prepare(string $query, ...$args): string
    {
        return $query;
    }

    public function get_row($query, $output = null)
    {
        return null;
    }

    public function get_results($query, $output = null)
    {
        return [];
    }

    public function query($query)
    {
        return 0;
    }

    public function insert($table, $data)
    {
        return 0;
    }

    public function update($table, $data, $where)
    {
        return 0;
    }

    public function delete($table, $where)
    {
        return 0;
    }
}

$GLOBALS['wpdb'] = new CP_Test_WPDB();

// ---- the code under test ---------------------------------------------------

require_once CLAUDPRESS_DIR . 'includes/class-claudpress.php';
require_once CLAUDPRESS_DIR . 'includes/class-tool-registry.php';
require_once CLAUDPRESS_DIR . 'includes/class-capabilities.php';
require_once CLAUDPRESS_DIR . 'includes/class-memory.php';
require_once CLAUDPRESS_DIR . 'includes/class-policy.php';
require_once CLAUDPRESS_DIR . 'includes/class-agent-runs.php';
require_once CLAUDPRESS_DIR . 'includes/class-agent-runtime.php';
require_once CLAUDPRESS_DIR . 'includes/class-tasks.php';
require_once CLAUDPRESS_DIR . 'includes/class-migration.php';
