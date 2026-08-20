<?php
/**
 * Admin page render tests.
 *
 * The settings screens are ~700 lines of PHP interleaved with HTML. `php -l`
 * only proves that file parses — it cannot tell you a conditional left a <div>
 * unclosed, a template dropped out of PHP mode mid-attribute, or that a helper
 * an edit introduced does not exist on the render path. Every one of those ships
 * a visibly broken admin page while the linter stays green.
 *
 * So: render each page for real and assert on the HTML that comes out.
 *
 * Claudpress_Digest is faked here because its snapshot() require_once's
 * wp-admin/includes/update.php, which only exists in a real WordPress install.
 * Everything else runs the production code path.
 */

CP_Test::group('Admin — pages render');

/** Digest needs a real wp-admin to enumerate updates; the pages only read summary. */
class Claudpress_Digest
{
    public static function snapshot(): array
    {
        return [
            'summary' => ['3 orders today', '1 plugin update available'],
            'site'    => ['name' => 'Test Site'],
        ];
    }
}

/** The admin pages only ever read from these collaborators. */
class CP_Test_Registry
{
    public function names(): array
    {
        return ['get_site_info', 'create_post', 'wc_product', 'execute_php'];
    }

    public function list(): array
    {
        return [
            ['name' => 'get_site_info', 'description' => 'Return site info.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'create_post', 'description' => 'Create a post.', 'inputSchema' => ['type' => 'object']],
        ];
    }
}

class CP_Test_Bag
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;

    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
    }

    public function all(): array
    {
        return $this->rows;
    }

    public function recent($limit = 100, $offset = 0): array
    {
        return array_slice($this->rows, (int) $offset, (int) $limit);
    }

    public function paged($limit, $offset): array
    {
        return array_slice($this->rows, (int) $offset, (int) $limit);
    }

    public function count_all(): int
    {
        return count($this->rows);
    }
}

$cp           = Claudpress::instance();
$cp->registry = new CP_Test_Registry();
$cp->skills   = new CP_Test_Bag();
$cp->logger   = new CP_Test_Bag([
    ['created_at' => '2026-08-08 10:00:00', 'tool' => 'create_post', 'ok' => 1, 'args_json' => '{"title":"Hi"}', 'summary' => 'Created post 12'],
]);
$cp->memory = new CP_Test_Bag([
    ['key' => 'brand.accent', 'category' => 'design', 'value' => '#f8ca50', 'updated_at' => '2026-08-08 09:00:00'],
]);

// The Snippets page reads rows from the database, which the bootstrap does not
// have. Feed it fixtures instead, so the row + detail markup is exercised for
// real — an empty table would render but prove nothing about either.
Claudpress_Admin_Snippets::$source = static function (array $filters): array {
    return [
        [
            'id' => 12, 'uid' => 'cps_abc123', 'name' => 'Sticky header', 'description' => 'Keeps the header pinned while scrolling.',
            'tags' => ',header,design,', 'purpose' => 'The client asked for a persistent nav.', 'impact' => 'Header stays on screen on every page.',
            'code' => "if (true) { echo '<b>ok</b>'; }", 'snippet_type' => 'php', 'location' => 'everywhere', 'target_id' => 0,
            'priority' => 10, 'active' => 1, 'last_error' => null, 'origin_site' => '', 'origin_uid' => '',
            'created_at' => '2026-08-01 10:00:00', 'updated_at' => '2026-08-02 11:00:00',
        ],
        [
            // A crashed copy from another site, with no notes — the legacy path.
            'id' => 3, 'uid' => 'cps_def456', 'name' => 'Promo banner', 'description' => '',
            'tags' => '', 'purpose' => '', 'impact' => '',
            'code' => '.promo { color: red; }', 'snippet_type' => 'css', 'location' => 'header', 'target_id' => 0,
            'priority' => 10, 'active' => 0, 'last_error' => 'Call to undefined function foo()', 'origin_site' => 'Pranknologia', 'origin_uid' => 'cps_src999',
            'created_at' => null, 'updated_at' => '2026-08-03 09:00:00',
        ],
    ];
};

$admin = new Claudpress_Admin();
$pages = [
    'render'            => 'Connect',
    'render_automation' => 'Automation',
    'render_guide'      => 'Guide',
    'render_activity'   => 'Activity',
];

/** @var array<string, string> $html Rendered markup per page, for later assertions. */
$html = [];

// The Snippets screen moved to the Pro add-on, so it is no longer a method on
// Claudpress_Admin. It is still rendered here - the assertions below are about
// the markup, and the markup did not move.
if (class_exists('Claudpress_Admin_Snippets')) {
    ob_start();
    (new Claudpress_Admin_Snippets())->render();
    $html['Snippets'] = (string) ob_get_clean();
    CP_Test::ok($html['Snippets'] !== '', 'Snippets renders from the add-on');
}

foreach ($pages as $method => $label) {
    $out    = '';
    $fatal  = '';
    try {
        ob_start();
        $admin->$method();
        $out = (string) ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        $fatal = $e->getMessage();
    }

    CP_Test::ok($fatal === '', $label . ' renders without throwing' . ($fatal ? ' — ' . $fatal : ''));
    if ($fatal !== '') {
        continue;
    }

    $html[$label] = $out;

    CP_Test::ok(strlen($out) > 500, $label . ' produces a real page, not an empty shell');
    CP_Test::same(
        substr_count($out, '<div'),
        substr_count($out, '</div>'),
        $label . ' has balanced <div> tags'
    );
    CP_Test::same(
        substr_count($out, '<table'),
        substr_count($out, '</table>'),
        $label . ' has balanced <table> tags'
    );
    CP_Test::same(
        substr_count($out, '<form'),
        substr_count($out, '</form>'),
        $label . ' has balanced <form> tags'
    );
    CP_Test::same(
        substr_count($out, '<details'),
        substr_count($out, '</details>'),
        $label . ' has balanced <details> tags'
    );
    // A template that falls out of PHP mode leaks its own source to the browser.
    CP_Test::ok(strpos($out, '<?php') === false, $label . ' leaks no raw PHP');
    CP_Test::ok(strpos($out, '<?=') === false, $label . ' leaks no short-echo tags');
}

CP_Test::group('Admin — the Snippets page shows what the AI installed');

$snips = $html['Snippets'] ?? '';
foreach ([
    'Sticky header'                       => 'the snippet name',
    'The client asked for a persistent nav.' => 'the purpose the agent recorded',
    'Header stays on screen on every page.'  => 'the impact the agent recorded',
    'cps_abc123'                          => 'the portable uid',
    'Pranknologia'                        => 'the site a snippet was copied from',
    'Call to undefined function foo()'    => 'the error that switched a snippet off',
    'Auto-disabled'                       => 'the auto-disabled status',
    'value="snippet_toggle"'              => 'the per-row toggle control',
    'value="snippet_update"'              => 'the edit form',
    'value="snippet_delete"'              => 'the delete control',
] as $needle => $what) {
    CP_Test::contains($needle, $snips, 'Snippets page shows ' . $what);
}

// Legacy rows have no notes; the page must say so rather than render blank.
CP_Test::contains('Legacy snippet', $snips, 'Snippets page explains a snippet with no recorded notes');

// The code must reach the textarea intact — a sanitiser here would corrupt it.
CP_Test::contains('&lt;b&gt;ok&lt;/b&gt;', $snips, 'snippet code is HTML-escaped for display, not stripped');

CP_Test::group('Admin — every form is nonce-protected');

// A settings form without a nonce is a CSRF hole that renders perfectly.
foreach ($html as $label => $out) {
    $forms  = substr_count($out, '<form');
    $nonces = substr_count($out, 'name="_wpnonce"');
    if ($forms === 0) {
        continue;
    }
    CP_Test::ok($nonces >= $forms, $label . ': all ' . $forms . ' form(s) carry a nonce field (' . $nonces . ' found)');
}

CP_Test::group('Admin — secrets are not echoed in plain text');

// API keys and tokens must render into password inputs, never as page text.
$cp->update([
    'agent_api_key'       => 'sk-ant-SECRETAGENTKEY',
    'image_api_key'       => 'sk-SECRETIMAGEKEY',
    'deploy_target_token' => 'cp_SECRETDEPLOYTOKEN',
]);

ob_start();
$admin->render();
$connect = (string) ob_get_clean();

ob_start();
$admin->render_automation();
$automation = (string) ob_get_clean();

// Changed in 1.10.1 (S2). This block used to assert the opposite - that each
// key WAS rendered into its input - on the reasoning that a password input is
// a safe enough home for a secret. It is not: type="password" only hides the
// value on screen, while the real key still sits in the page source, where
// admin-side XSS, a browser extension, a screen share or view-source picks it
// straight back up. The screen now renders a mask instead, and the stored value
// is only ever replaced when someone types a genuinely new one.
foreach ([
    'image key on Connect'      => ['sk-SECRETIMAGEKEY', $connect],
    'deploy token on Connect'   => ['cp_SECRETDEPLOYTOKEN', $connect],
    'agent key on Automation'   => ['sk-ant-SECRETAGENTKEY', $automation],
] as $what => [$secret, $page]) {
    CP_Test::ok(
        strpos($page, $secret) === false,
        $what . ' never appears in the page source'
    );
    CP_Test::ok(
        strpos($page, substr($secret, -4)) !== false,
        $what . ' still shows its last four characters, so the owner can tell which key is installed'
    );
}

cp_test_reset_options();

CP_Test::group('Admin — provider UI reaches the page');

// The model guide is the fix for "I typed a model name that does not exist".
// If it silently stops rendering, the plugin is back to being a guessing game.
foreach (Claudpress_Provider::providers() as $pid => $cls) {
    CP_Test::contains($cls::default_model(), $automation, 'Automation lists the ' . $pid . ' default model');
}
CP_Test::contains('name="agent_provider"', $automation, 'Automation has the provider selector');
CP_Test::contains('name="image_provider"', $connect, 'Connect has the image provider selector');

foreach (Claudpress_Image_Provider::providers() as $pid => $cls) {
    CP_Test::contains($cls::default_model(), $connect, 'Connect lists the ' . $pid . ' default image model');
}

CP_Test::group('Admin — strings are internationalized');

// Catch a future edit that drops a raw English string back into a template.
$src   = file_get_contents(CLAUDPRESS_DIR . 'includes/class-admin.php');
$raw   = preg_match_all('/<(?:p|h2|h3|li|th|strong|em|label|summary|button)[^>]*>[A-Z][a-z]+ [a-z]+/', $src, $m);
CP_Test::same(0, $raw, 'no untranslated sentence-like strings left in admin templates');
CP_Test::ok(
    substr_count($src, "'claudpress'") > 150,
    'admin strings carry the claudpress text domain (' . substr_count($src, "'claudpress'") . ' occurrences)'
);
