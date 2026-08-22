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


CP_Test::group('Admin — image and voice pickers swap with the provider');

/**
 * The bug these cover.
 *
 * Both blocks used to render ONE key box, ONE free-text model box and ONE
 * "get a key" link, all belonging to whichever provider happened to be saved.
 * Choosing a different provider changed the dropdown and nothing else, so the
 * screen told a Google user to go and fetch an OpenAI key, and offered them a
 * placeholder naming an OpenAI model. The model was typed by hand, so a typo
 * surfaced days later as an opaque 404 inside a scheduled run.
 *
 * Now each provider has its own row, and the rows nobody is looking at are
 * inside a disabled fieldset - which is what stops them posting their empty
 * key over the one the owner actually filled in.
 */

/** Pull one provider's row out of a page so assertions cannot match a neighbour. */
$cp_row = static function (string $html, string $kind, string $pid): string {
    $block = strpos($html, 'data-kind="' . $kind . '"');
    CP_Test::ok($block !== false, $kind . ' block is on the page');
    if ($block === false) {
        return '';
    }
    $from = strpos($html, 'data-provider="' . $pid . '"', $block);
    if ($from === false) {
        return '';
    }
    $next = strpos($html, '<div class="claudpress-provider-row"', $from);
    return substr($html, $from, $next === false ? 6000 : $next - $from);
};

foreach ([
    ['image', $connect,    Claudpress_Image_Provider::providers(),      'openai'],
    ['voice', $automation, Claudpress_Transcribe_Provider::providers(), 'openai'],
] as [$kind, $page, $providers, $current]) {

    foreach ($providers as $pid => $cls) {
        $row = $cp_row($page, $kind, $pid);

        CP_Test::ok($row !== '', $kind . ': ' . $pid . ' has its own row');

        // The reported bug, directly: the "get a key" link must belong to the
        // provider whose row it sits in, not to whichever one was saved.
        CP_Test::contains($cls::key_url(), $row, $kind . ': ' . $pid . " row links to that vendor's key page");

        // ...and the placeholder must be that vendor's key shape.
        CP_Test::contains('placeholder="' . $cls::key_hint() . '"', $row, $kind . ': ' . $pid . ' row hints that vendor\'s key format');

        // Every model this provider offers is a real option, so nothing is typed.
        foreach ($cls::models() as $m) {
            CP_Test::contains('value="' . $m['id'] . '"', $row, $kind . ': ' . $pid . ' offers ' . $m['id']);
        }

        // No free-text model box anywhere - that was the typo source.
        CP_Test::ok(
            strpos($row, 'name="' . ($kind === 'image' ? 'image_api_model' : 'voice_model') . '" class="claudpress-model-select"') !== false
            || strpos($row, 'claudpress-model-select') !== false,
            $kind . ': ' . $pid . ' model is a dropdown'
        );
        CP_Test::ok(
            preg_match('/<input[^>]*name="(?:image_api_model|voice_model)"/', $row) === 0,
            $kind . ': ' . $pid . ' has no typeable model input'
        );

        // Test connection has to be inside the row, or it would send the wrong
        // row's key.
        CP_Test::contains('claudpress-media-test', $row, $kind . ': ' . $pid . ' has a Test connection button');

        // The trap that makes the whole thing work. Without the disabled
        // fieldset, all three rows post at once and the last one in the
        // document wins - silently wiping the key that was just entered.
        $disabled = strpos($row, 'class="claudpress-provider-fields" style="border:0;padding:0;margin:0;" disabled') !== false;
        if ($pid === $current) {
            CP_Test::ok(!$disabled, $kind . ': the selected provider\'s fields are live');
            CP_Test::ok(strpos($row, 'hidden') === false || strpos(substr($row, 0, 60), 'hidden') === false, $kind . ': the selected provider\'s row is visible');
        } else {
            CP_Test::ok($disabled, $kind . ': ' . $pid . ' fields are disabled so they cannot overwrite the saved provider');
        }
    }
}

// Each vendor's key page must appear on the page at all - the old code only
// ever emitted the saved provider's, which is precisely what was reported.
foreach (Claudpress_Transcribe_Provider::providers() as $pid => $cls) {
    CP_Test::contains($cls::key_url(), $automation, 'Automation carries the ' . $pid . ' transcription key link');
}

CP_Test::group('Provider adapters — the test path costs nothing');

/**
 * Testing an image or transcription key must never generate an image or
 * transcribe audio. Someone debugging a bad key presses Test repeatedly; if the
 * button billed them each time, the button would be the bug.
 */
foreach ([
    Claudpress_Image_Provider::providers(),
    Claudpress_Transcribe_Provider::providers(),
] as $family) {
    foreach ($family as $pid => $cls) {
        $model = $cls::default_model();
        $url   = $cls::verify_url('', $model);

        CP_Test::ok(strpos($url, 'http') === 0, $pid . ': verify_url is an absolute URL (' . $url . ')');
        CP_Test::contains(rawurlencode($model), $url, $pid . ': verify_url names the model being tested');

        // The generate/transcribe routes must not be what we call.
        CP_Test::ok(strpos($url, ':generateContent') === false, $pid . ': verify_url does not call generateContent');
        CP_Test::ok(strpos($url, '/images/generations') === false, $pid . ': verify_url does not call image generation');
        CP_Test::ok(strpos($url, '/audio/transcriptions') === false, $pid . ': verify_url does not call transcription');

        // An override has to be honoured, or a gateway user tests the wrong host.
        $over = $cls::verify_url('https://gw.example.com/v1/images/generations', $model);
        if (strpos($cls::default_endpoint(), 'openai') !== false) {
            CP_Test::contains('gw.example.com', $over, $pid . ': an endpoint override is tested at that host');
        }

        // Auth headers must carry the key and nothing else - a GET has no body,
        // and a stray Content-Type on a GET trips some gateways.
        $h = $cls::auth_headers('KEYVALUE');
        CP_Test::ok(!isset($h['Content-Type']), $pid . ': auth headers carry no Content-Type');
        CP_Test::ok(in_array('KEYVALUE', array_map(static fn($v) => str_replace('Bearer ', '', (string) $v), $h), true), $pid . ': auth headers carry the key');
    }
}

// A double-append bug: an override that is already a full Gemini model URL must
// not become ".../models/x:generateContent/gemini-3-pro-image".
$g = Claudpress_Image_Provider_Google::verify_url(
    'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent',
    'gemini-3-pro-image'
);
CP_Test::same(
    'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image',
    $g,
    'a full Gemini generate URL is reduced back to the model being tested'
);

CP_Test::group('Provider adapters — every model in the list is offerable');

foreach ([
    'image' => Claudpress_Image_Provider::providers(),
    'voice' => Claudpress_Transcribe_Provider::providers(),
] as $kind => $family) {
    foreach ($family as $pid => $cls) {
        $models = $cls::models();
        CP_Test::ok(count($models) >= 2, $kind . '/' . $pid . ' offers more than one model to choose between');

        $ids = array_column($models, 'id');
        CP_Test::ok(
            in_array($cls::default_model(), $ids, true),
            $kind . '/' . $pid . ': the default model is one of the options, so the dropdown opens on a real choice'
        );
        CP_Test::same(count($ids), count(array_unique($ids)), $kind . '/' . $pid . ': no duplicate model ids');

        foreach ($models as $m) {
            CP_Test::ok(trim((string) $m['note']) !== '', $kind . '/' . $pid . ': ' . $m['id'] . ' explains itself in the dropdown');
        }
    }
}


CP_Test::group('Admin — Test connection for image and voice keys');

/**
 * The button people press when something is already wrong.
 *
 * Two things it must never do. It must never generate an image or transcribe
 * audio, because someone debugging a bad key presses it repeatedly and the
 * button would become the bill. And it must never answer "HTTP 401", which
 * tells a person staring at a settings screen nothing about which box to go
 * and look at.
 */

$cp_admin_test = new Claudpress_Admin();

/**
 * Drive ajax_test_media() the way admin-ajax does and catch the JSON it sends.
 *
 * @return array{success: bool, message: string, url: string, headers: array}
 */
$cp_call_test = static function (array $post) use ($cp_admin_test): array {
    $_POST = $post;
    $GLOBALS['cp_test_http_last'] = null;

    try {
        $cp_admin_test->ajax_test_media();
        $sent = new CP_Test_Json_Sent(false, ['message' => 'nothing was sent'], 0);
    } catch (CP_Test_Json_Sent $e) {
        $sent = $e;
    }

    $last = $GLOBALS['cp_test_http_last'] ?? null;
    $_POST = [];

    return [
        'success' => $sent->success,
        'message' => (string) ($sent->data['message'] ?? ''),
        'url'     => (string) ($last['url'] ?? ''),
        'headers' => (array) ($last['args']['headers'] ?? []),
    ];
};

cp_test_reset_options([
    'claudpress_settings' => [
        'image_provider' => 'openai',
        'image_api_key'  => 'sk-STOREDIMAGE',
        'voice_provider' => 'openai',
        'voice_api_key'  => 'sk-STOREDVOICE',
    ],
]);

// ---- the happy path, and what it must NOT have called ----------------------

cp_test_reset_ajax();
cp_test_http_queue(200, ['id' => 'gpt-image-1', 'object' => 'model']);
$r = $cp_call_test(['kind' => 'image', 'provider' => 'openai', 'api_key' => 'sk-typed', 'model' => 'gpt-image-1']);

CP_Test::ok($r['success'], 'image: a good key reports success');
CP_Test::contains('gpt-image-1', $r['message'], 'image: the success message names the model that was tested');
CP_Test::ok(
    strpos($r['url'], '/images/generations') === false,
    'image: testing a key does NOT call the image-generation route, so it cannot bill the owner'
);
CP_Test::contains('/models/gpt-image-1', $r['url'], 'image: it asks the vendor to describe the model instead');
CP_Test::contains('sk-typed', (string) ($r['headers']['Authorization'] ?? ''), 'image: the key typed on screen is the key tested, not the saved one');

cp_test_reset_ajax();
cp_test_http_queue(200, ['name' => 'models/gemini-flash-latest']);
$r = $cp_call_test(['kind' => 'voice', 'provider' => 'google', 'api_key' => 'AIza-typed', 'model' => 'gemini-flash-latest']);

CP_Test::ok($r['success'], 'voice: a good Google key reports success');
CP_Test::ok(
    strpos($r['url'], ':generateContent') === false,
    'voice: testing a key does NOT call generateContent, so it transcribes nothing'
);
CP_Test::same('AIza-typed', (string) ($r['headers']['x-goog-api-key'] ?? ''), 'voice: Google is tested with its own header, not a bearer token');

// ---- the failures, each of which must say what to do about it --------------

cp_test_reset_ajax();
cp_test_http_queue(401, ['error' => ['message' => 'Incorrect API key provided']]);
$r = $cp_call_test(['kind' => 'image', 'provider' => 'openai', 'api_key' => 'sk-bad', 'model' => 'gpt-image-1']);
CP_Test::ok(!$r['success'], 'image: a rejected key fails');
CP_Test::contains('rejected', $r['message'], 'image: a 401 says the key was rejected, not "HTTP 401"');

cp_test_reset_ajax();
cp_test_http_queue(404, ['error' => ['message' => 'model not found']]);
$r = $cp_call_test(['kind' => 'image', 'provider' => 'openai', 'api_key' => 'sk-good', 'model' => 'gpt-image-1']);
CP_Test::ok(!$r['success'], 'image: an unreachable model fails');
CP_Test::contains('gpt-image-1', $r['message'], 'image: a 404 names the model the account cannot reach');
CP_Test::ok(
    stripos($r['message'], 'key works') !== false,
    'image: a 404 says the key is fine, so nobody goes hunting for a new one'
);

// ---- the mask trap ---------------------------------------------------------

// An untouched password field posts eight bullets back. Sending those to a
// vendor tests nothing and would report a broken key on a setup that works.
cp_test_reset_ajax();
cp_test_http_queue(200, ['id' => 'gpt-image-1']);
$r = $cp_call_test([
    'kind' => 'image', 'provider' => 'openai',
    'api_key' => Claudpress_Secrets::mask('sk-STOREDIMAGE'),
    'model' => 'gpt-image-1',
]);
CP_Test::ok($r['success'], 'image: an untouched key field tests the SAVED key rather than the mask');
CP_Test::contains('sk-STOREDIMAGE', (string) ($r['headers']['Authorization'] ?? ''), 'image: the mask never reaches the vendor');

// ---- no key at all ---------------------------------------------------------

cp_test_reset_options(['claudpress_settings' => ['image_provider' => 'openai']]);
cp_test_reset_ajax();
$r = $cp_call_test(['kind' => 'image', 'provider' => 'openai', 'api_key' => '', 'model' => 'gpt-image-1']);
CP_Test::ok(!$r['success'], 'image: testing with no key anywhere fails');
CP_Test::same('', $r['url'], 'image: ...without making a request at all');
CP_Test::contains('Add an API key', $r['message'], 'image: and it says to add one');

// ---- voice reuses a same-vendor key, and the test has to know that ---------

// Voice deliberately falls back to another key for the same vendor. A test that
// ignored the fallback would report "no key" on a configuration that works.
cp_test_reset_options([
    'claudpress_settings' => [
        'voice_provider' => 'openai',
        'voice_api_key'  => '',
        'image_provider' => 'openai',
        'image_api_key'  => 'sk-BORROWEDFROMIMAGE',
    ],
]);
cp_test_reset_ajax();
cp_test_http_queue(200, ['id' => 'whisper-1']);
$r = $cp_call_test(['kind' => 'voice', 'provider' => 'openai', 'api_key' => '', 'model' => 'whisper-1']);
CP_Test::ok($r['success'], 'voice: a blank key falls back to the same vendor\'s image key, exactly as transcription itself does');
CP_Test::contains('sk-BORROWEDFROMIMAGE', (string) ($r['headers']['Authorization'] ?? ''), 'voice: the borrowed key is the one tested');

// ---- the guard -------------------------------------------------------------

// This endpoint accepts an API key in the body so an unsaved one can be tested.
// That makes it a credential path, not a settings path: capability AND nonce.
$GLOBALS['cp_test_ajax'] = ['caps' => [], 'nonces' => []];
cp_test_reset_ajax();
cp_test_http_queue(200, ['id' => 'gpt-image-1']);
$cp_call_test(['kind' => 'image', 'provider' => 'openai', 'api_key' => 'sk-x', 'model' => 'gpt-image-1']);
CP_Test::ok(in_array('manage_options', $GLOBALS['cp_test_ajax']['caps'] ?? [], true), 'the media test checks manage_options');
CP_Test::ok(!empty($GLOBALS['cp_test_ajax']['nonces']), 'the media test checks a nonce');

// An unknown kind or provider must not fall through to a default and quietly
// test the wrong vendor with somebody's key.
cp_test_reset_ajax();
$r = $cp_call_test(['kind' => 'nonsense', 'provider' => 'openai', 'api_key' => 'sk-x', 'model' => 'm']);
CP_Test::ok(!$r['success'], 'an unknown provider type is refused');
CP_Test::same('', $r['url'], '...without sending the key anywhere');

cp_test_reset_ajax();
$r = $cp_call_test(['kind' => 'image', 'provider' => 'notavendor', 'api_key' => 'sk-x', 'model' => 'm']);
CP_Test::ok(!$r['success'], 'an unknown provider is refused rather than defaulting to OpenAI');
CP_Test::same('', $r['url'], '...without sending the key anywhere');

cp_test_reset_ajax();
cp_test_reset_options();

CP_Test::group('Admin — strings are internationalized');

// Catch a future edit that drops a raw English string back into a template.
$src   = file_get_contents(CLAUDPRESS_DIR . 'includes/class-admin.php');
$raw   = preg_match_all('/<(?:p|h2|h3|li|th|strong|em|label|summary|button)[^>]*>[A-Z][a-z]+ [a-z]+/', $src, $m);
CP_Test::same(0, $raw, 'no untranslated sentence-like strings left in admin templates');
CP_Test::ok(
    substr_count($src, "'claudpress'") > 150,
    'admin strings carry the claudpress text domain (' . substr_count($src, "'claudpress'") . ' occurrences)'
);
