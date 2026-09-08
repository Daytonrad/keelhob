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

// Fixed test salts. Claudpress_Secrets derives its encryption key from
// these, so they must be defined before any settings are read.
define('AUTH_KEY', 'cp-test-auth-key-0000000000000001');
define('SECURE_AUTH_KEY', 'cp-test-secure-auth-key-000000002');
define('AUTH_SALT', 'cp-test-auth-salt-000000000000003');

// ---- in-memory option store ------------------------------------------------

$GLOBALS['cp_test_options']  = [];
$GLOBALS['cp_test_autoload']   = [];
$GLOBALS['cp_test_transients'] = [];

function cp_test_reset_options(array $options = []): void
{
    $GLOBALS['cp_test_options'] = $options;
}

function get_option(string $name, $default = false)
{
    return array_key_exists($name, $GLOBALS['cp_test_options']) ? $GLOBALS['cp_test_options'][$name] : $default;
}

function update_option(string $name, $value, $autoload = null): bool
{
    $GLOBALS['cp_test_autoload'][$name] = $autoload;
    $GLOBALS['cp_test_options'][$name] = $value;
    return true;
}

function delete_option(string $name): bool
{
    unset($GLOBALS['cp_test_options'][$name]);
    return true;
}

// ---- WordPress helpers used by the code under test -------------------------

/**
 * Real (if minimal) filter dispatch.
 *
 * Filters are part of the plugin's public extension contract — 'claudpress_
 * model_prices', 'claudpress_operating_protocol' and friends are documented for
 * site owners to override. A pass-through stub would let those break silently,
 * so the suite runs callbacks for real, in priority order.
 *
 * @var array<string, array<int, array<int, callable>>> $GLOBALS['cp_test_filters']
 */
$GLOBALS['cp_test_filters'] = [];

function apply_filters(string $hook, $value, ...$args)
{
    if (empty($GLOBALS['cp_test_filters'][$hook])) {
        return $value;
    }
    $by_priority = $GLOBALS['cp_test_filters'][$hook];
    ksort($by_priority);
    foreach ($by_priority as $callbacks) {
        foreach ($callbacks as $cb) {
            $value = $cb($value, ...$args);
        }
    }
    return $value;
}

function add_filter(string $hook, $cb, int $priority = 10, int $args = 1): bool
{
    $GLOBALS['cp_test_filters'][$hook][$priority][] = $cb;
    return true;
}

/** Drop registered filters so one test cannot leak into the next. */
function cp_test_reset_filters(): void
{
    $GLOBALS['cp_test_filters'] = [];
}

function add_action(string $hook, $cb, int $priority = 10, int $args = 1): bool
{
    return true;
}

function remove_action(string $hook, $cb, int $priority = 10): bool
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

function sanitize_email($email): string
{
    $email = trim((string) $email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
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
    return ($GLOBALS['cp_test_home_url'] ?? 'https://example.test') . $path;
}

function number_format_i18n($number, $decimals = 0): string
{
    return number_format((float) $number, (int) $decimals);
}

function wp_strip_all_tags($text): string
{
    return strip_tags((string) $text);
}

// ---- Escaping / i18n, so admin markup can be rendered and asserted ----------
// Deliberately real (if simplified) rather than pass-through: a test that
// renders admin HTML should fail if output stops being escaped.

function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
function esc_url_raw($u) { return (string) $u; }
function __($t, $d = null) { return (string) $t; }
function esc_html__($t, $d = null) { return esc_html($t); }
function esc_attr__($t, $d = null) { return esc_attr($t); }
function esc_html_e($t, $d = null) { echo esc_html($t); }
function esc_attr_e($t, $d = null) { echo esc_attr($t); }
function _e($t, $d = null) { echo (string) $t; }
function untrailingslashit($s) { return rtrim((string) $s, '/\\'); }
function esc_js($t) { return str_replace(["'", '"', "\n"], ["\\'", '&quot;', ''], (string) $t); }
function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }

function selected($a, $b = true, $echo = true)
{
    $out = ((string) $a === (string) $b) ? " selected='selected'" : '';
    if ($echo) {
        echo $out;
    }
    return $out;
}

function checked($a, $b = true, $echo = true)
{
    $out = ((string) $a === (string) $b) ? " checked='checked'" : '';
    if ($echo) {
        echo $out;
    }
    return $out;
}

function disabled($a, $b = true, $echo = true)
{
    $out = ((string) $a === (string) $b) ? " disabled='disabled'" : '';
    if ($echo) {
        echo $out;
    }
    return $out;
}

function wp_kses_post($content) { return (string) $content; }

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
        // What a modern WordPress install actually returns. Returning '' would
        // let a CREATE TABLE that forgot the charset still look correct here.
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
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

    public function get_var($query, $column = 0, $row = 0)
    {
        return null;
    }

    public function get_col($query, $column = 0)
    {
        return [];
    }

    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
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

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// ---- Schema installation: dbDelta() ----------------------------------------
// Every install_table() in the plugin does the WordPress-standard two-step:
//
//     require_once ABSPATH . 'wp-admin/includes/upgrade.php';
//     dbDelta("CREATE TABLE ...");
//
// Both halves have to be answered here or Claudpress::maybe_upgrade() — the
// real production upgrade entry point — cannot be called at all, which is why
// it went untested for so long. The require_once is the harder half: a missing
// file is a fatal error, not a warning, so defining dbDelta() alone is not
// enough. The stub file below is written into the fake ABSPATH at the exact
// path WordPress uses, and removed again at shutdown so a test run never
// leaves anything behind in the working tree.

/** @var array<int, string> Every CREATE TABLE dbDelta() was handed, in order. */
$GLOBALS['cp_test_dbdelta'] = [];

function cp_test_reset_dbdelta(): void
{
    $GLOBALS['cp_test_dbdelta'] = [];
}

if (!function_exists('dbDelta')) {
    /**
     * Record the schema instead of applying it.
     *
     * The real dbDelta() returns a map of "what I changed" keyed by table or
     * column; nothing in this plugin reads the return value, so an empty array
     * is a faithful "nothing needed changing". The recorded queries are the
     * useful part: a test can assert that maybe_upgrade() installed the table
     * it claims to, without a database.
     *
     * @return array<string, string>
     */
    function dbDelta($queries = '', $execute = true): array
    {
        foreach ((array) $queries as $query) {
            $GLOBALS['cp_test_dbdelta'][] = (string) $query;
        }
        return [];
    }
}

/**
 * Paths this bootstrap (or a test that runs maybe_upgrade()) materialises
 * inside the fake WordPress tree. Removed at shutdown, deepest first, so
 * `git status` is as clean after a test run as it was before one.
 */
$GLOBALS['cp_test_scratch_paths'] = [
    ABSPATH . 'wp-admin/includes/upgrade.php',
    ABSPATH . 'wp-admin/includes',
    ABSPATH . 'wp-admin',
    CLAUDPRESS_SANDBOX_DIR . '/index.php',
    CLAUDPRESS_SANDBOX_DIR . '/.htaccess',
    CLAUDPRESS_SANDBOX_DIR,
];

if (!is_dir(ABSPATH . 'wp-admin/includes')) {
    @mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
}
// Written unconditionally, so a stub left behind by a run that died before its
// shutdown handler cannot poison the next one.
@file_put_contents(
    ABSPATH . 'wp-admin/includes/upgrade.php',
    "<?php\n"
    . "// Test scratch file, rewritten by tests/bootstrap.php on every run and\n"
    . "// removed at shutdown. dbDelta() itself is defined in the bootstrap; this\n"
    . "// exists only so the plugin's require_once of this path resolves.\n"
);

register_shutdown_function(static function (): void {
    foreach ($GLOBALS['cp_test_scratch_paths'] as $path) {
        if (is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            // Fails harmlessly if a test left something else in there.
            @rmdir($path);
        }
    }
});

// ---- Admin-render helpers --------------------------------------------------
// Enough of the admin surface for the settings pages to render end to end.
// Rendering them is the only way to catch broken markup in the interleaved
// PHP/HTML templates — `php -l` only proves the file parses.

function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function rest_url($path = '') { return 'https://example.test/wp-json/' . $path; }
function wp_nonce_field($action = '') { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_timezone_string() { return 'UTC'; }
function wp_next_scheduled($hook) { return time() + 3600; }
function human_time_diff($from, $to = 0) { return '1 hour'; }
function get_current_screen() { return null; }
function get_permalink($id = 0) { return 'https://example.test/?p=' . (int) $id; }
function set_transient($key, $value, $ttl = 0)
{
    $GLOBALS['cp_test_transients'][$key] = $value;
    return true;
}

function get_transient($key)
{
    return $GLOBALS['cp_test_transients'][$key] ?? false;
}

function delete_transient($key) { unset($GLOBALS['cp_test_transients'][$key]); return true; }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }

function wp_trim_words($text, $num_words = 55, $more = null)
{
    $words = preg_split('/\s+/', trim((string) $text));
    return implode(' ', array_slice($words, 0, $num_words));
}

function wp_set_current_user($id) { $GLOBALS['cp_test_current_user'] = (int) $id; return true; }
function get_users($args = []) { return [1]; }
function user_can($user, $cap) { return true; }
function wp_authenticate_application_password($input, $username, $password) { return new WP_Error('bad', 'no'); }
function is_ssl() { return false; }

if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('WEEK_IN_SECONDS')) { define('WEEK_IN_SECONDS', 604800); }

function wp_normalize_path($path)
{
    $path = str_replace(chr(92), '/', (string) $path);
    $path = preg_replace('|(?<=.)/+|', '/', $path);
    if (':' === substr($path, 1, 1)) { $path = ucfirst($path); }
    return $path;
}

function wp_mkdir_p($dir) { return is_dir($dir) ? true : @mkdir($dir, 0777, true); }

function trailingslashit($s) { return rtrim((string) $s, '/') . '/'; }

// ---- AJAX + HTTP, so the provider endpoints can be exercised ---------------
// The two provider endpoints accept an API key, so their nonce and capability
// checks are the thing most worth asserting. These stubs record every check
// rather than performing one, and wp_send_json_* unwinds the way wp_die() does
// in production, so a handler that "returns" after refusing cannot be mistaken
// for one that stopped.

$GLOBALS['cp_test_ajax'] = ['nonces' => [], 'caps' => []];
$GLOBALS['cp_test_http'] = [];

/** Queue one canned HTTP response for the next wp_remote_* call. */
function cp_test_http_queue(int $code, $body, array $headers = []): void
{
    $GLOBALS['cp_test_http'][] = [
        'code'    => $code,
        'body'    => is_string($body) ? $body : json_encode($body),
        'headers' => $headers,
    ];
}

function cp_test_reset_ajax(): void
{
    $GLOBALS['cp_test_ajax'] = ['nonces' => [], 'caps' => []];
    $GLOBALS['cp_test_http'] = [];
    unset($GLOBALS['cp_test_cap_allow']);
}

/** Thrown in place of the wp_die() that ends a real wp_send_json_* response. */
class CP_Test_Json_Sent extends Exception
{
    public bool $success;
    public array $data;
    public int $status;

    public function __construct(bool $success, array $data, int $status)
    {
        parent::__construct($success ? 'success' : 'error');
        $this->success = $success;
        $this->data    = $data;
        $this->status  = $status;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($cap, ...$args)
    {
        $GLOBALS['cp_test_ajax']['caps'][] = (string) $cap;
        return $GLOBALS['cp_test_cap_allow'] ?? true;
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $stop = true)
    {
        $GLOBALS['cp_test_ajax']['nonces'][] = (string) $action;
        return 1;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) { return 'nonce-' . md5((string) $action); }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) { return 1; }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status = 200)
    {
        throw new CP_Test_Json_Sent(true, (array) $data, (int) $status);
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status = 400)
    {
        throw new CP_Test_Json_Sent(false, (array) $data, (int) $status);
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = null) { return ((int) $number === 1) ? $single : $plural; }
}

/** The next queued response, or a 200 with an empty body when nothing is queued. */
function cp_test_http_next(string $url, array $args)
{
    $GLOBALS['cp_test_http_last'] = ['url' => $url, 'args' => $args];
    if (!$GLOBALS['cp_test_http']) {
        return ['response' => ['code' => 200], 'body' => '{}', 'headers' => []];
    }
    $next = array_shift($GLOBALS['cp_test_http']);
    if ($next instanceof WP_Error) {
        return $next;
    }
    return ['response' => ['code' => $next['code']], 'body' => $next['body'], 'headers' => $next['headers']];
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) { return cp_test_http_next((string) $url, (array) $args); }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = []) { return cp_test_http_next((string) $url, (array) $args); }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($resp) { return is_array($resp) ? ($resp['response']['code'] ?? 0) : 0; }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($resp) { return is_array($resp) ? (string) ($resp['body'] ?? '') : ''; }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($resp, $header) { return is_array($resp) ? ($resp['headers'][$header] ?? '') : ''; }
}

// ---- Pagination + current-user stubs ---------------------------------------
// Guarded: these are generic enough that a future bootstrap edit (or a test
// file that needs them earlier) could define them too, and a redeclare is fatal.

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return (int) ($GLOBALS['cp_test_current_user'] ?? 0); }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = null) { return (int) $number === 1 ? (string) $single : (string) $plural; }
}

if (!function_exists('add_query_arg')) {
    // Mirrors the real one in the way that matters here: values are NOT
    // urlencoded, which is what lets paginate_links() find its '%#%' token.
    function add_query_arg($args, $url = '') {
        $parts = [];
        foreach ((array) $args as $k => $v) { $parts[] = $k . '=' . $v; }
        return $url . (strpos((string) $url, '?') === false ? '?' : '&') . implode('&', $parts);
    }
}

if (!function_exists('paginate_links')) {
    function paginate_links($args = []) {
        $total   = max(1, (int) ($args['total'] ?? 1));
        $current = max(1, (int) ($args['current'] ?? 1));
        $base    = (string) ($args['base'] ?? '');
        $out     = '';
        for ($i = 1; $i <= $total; $i++) {
            $href = str_replace('%#%', (string) $i, $base);
            $out .= $i === $current
                ? '<span class="page-numbers current">' . $i . '</span>'
                : '<a class="page-numbers" href="' . esc_url($href) . '">' . $i . '</a>';
        }
        return $out;
    }
}

require_once CLAUDPRESS_DIR . 'includes/class-secrets.php';
require_once CLAUDPRESS_DIR . 'includes/class-claudpress.php';
require_once CLAUDPRESS_DIR . 'includes/class-auth.php';
require_once CLAUDPRESS_DIR . 'includes/class-logger.php';
require_once CLAUDPRESS_DIR . 'includes/class-snippet-log.php';
require_once CLAUDPRESS_DIR . 'includes/class-tool-registry.php';
require_once CLAUDPRESS_DIR . 'includes/class-capabilities.php';
require_once CLAUDPRESS_DIR . 'includes/class-skill-catalog.php';
require_once CLAUDPRESS_DIR . 'includes/class-memory.php';
require_once CLAUDPRESS_DIR . 'includes/class-policy.php';
require_once CLAUDPRESS_DIR . 'includes/class-agent-runs.php';
require_once CLAUDPRESS_DIR . 'includes/class-mcp-server.php';
require_once CLAUDPRESS_DIR . 'includes/class-provider.php';
require_once CLAUDPRESS_DIR . 'includes/class-provider-anthropic.php';
require_once CLAUDPRESS_DIR . 'includes/class-provider-openai.php';
require_once CLAUDPRESS_DIR . 'includes/class-provider-google.php';
require_once CLAUDPRESS_DIR . 'includes/class-image-provider.php';
require_once CLAUDPRESS_DIR . 'includes/class-image-provider-openai.php';
require_once CLAUDPRESS_DIR . 'includes/class-image-provider-google.php';
require_once CLAUDPRESS_DIR . 'includes/class-media-check.php';
require_once CLAUDPRESS_DIR . 'includes/class-transcribe-provider.php';
require_once CLAUDPRESS_DIR . 'includes/class-transcribe-provider-openai.php';
require_once CLAUDPRESS_DIR . 'includes/class-transcribe-provider-google.php';
require_once CLAUDPRESS_DIR . 'includes/class-agent-runtime.php';
require_once CLAUDPRESS_DIR . 'includes/class-undo.php';
require_once CLAUDPRESS_DIR . 'includes/class-events.php';
require_once CLAUDPRESS_DIR . 'includes/class-telegram.php';
require_once CLAUDPRESS_DIR . 'includes/class-telegram-api.php';
require_once CLAUDPRESS_DIR . 'includes/class-telegram-router.php';
require_once CLAUDPRESS_DIR . 'includes/class-rest.php';
require_once CLAUDPRESS_DIR . 'includes/class-tasks.php';
require_once CLAUDPRESS_DIR . 'includes/class-migration.php';
require_once CLAUDPRESS_DIR . 'includes/class-health.php';
require_once CLAUDPRESS_DIR . 'includes/class-snippets.php';
require_once CLAUDPRESS_DIR . 'includes/class-skills.php';
require_once CLAUDPRESS_DIR . 'includes/class-skills-remote.php';
// Project links, matching the defaults in claudpress.php.
if (!defined('CLAUDPRESS_DONATE_URL')) {
    define('CLAUDPRESS_DONATE_URL', 'https://ko-fi.com/claudpress');
}
if (!defined('CLAUDPRESS_SERVICES_URL')) {
    define('CLAUDPRESS_SERVICES_URL', 'https://claudpress.com/services');
}
if (!defined('CLAUDPRESS_REPO_URL')) {
    define('CLAUDPRESS_REPO_URL', 'https://github.com/calflint/claudpress');
}

require_once CLAUDPRESS_DIR . 'includes/class-updater.php';
require_once CLAUDPRESS_DIR . 'includes/class-donate.php';
require_once CLAUDPRESS_DIR . 'includes/class-admin.php';
require_once CLAUDPRESS_DIR . 'includes/class-admin-providers.php';
require_once CLAUDPRESS_DIR . 'includes/class-admin-settings.php';
require_once CLAUDPRESS_DIR . 'includes/class-admin-screens.php';
require_once CLAUDPRESS_DIR . 'includes/class-admin-automation.php';
require_once CLAUDPRESS_DIR . 'includes/class-admin-snippets.php';
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-filesystem.php';

// --- notifier -------------------------------------------------------------
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = [], $attachments = [])
    {
        $GLOBALS['cp_test_mail'][] = compact('to', 'subject', 'message');

        // Hosts where mail cannot leave the box are the whole reason the
        // notifier records outcomes, so the stub has to be able to be one.
        return empty($GLOBALS['cp_test_mail_fails']);
    }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = [])
    {
        $GLOBALS['cp_test_posts'][] = ['url' => $url, 'args' => $args];
        return ['response' => ['code' => 200], 'body' => '{}'];
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $code;
        private $message;
        public function __construct($code = '', $message = '', $data = []) { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}
$GLOBALS['cp_test_mail']  = [];
$GLOBALS['cp_test_posts'] = [];

require_once CLAUDPRESS_DIR . 'includes/class-notifier.php';


// Studio helpers are pure functions, so they belong in the fast suite
// rather than only in the rig.
require_once CLAUDPRESS_DIR . 'includes/class-studio-ai.php';
