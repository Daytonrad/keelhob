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
 * Keelhob_Digest is faked here because its snapshot() require_once's
 * wp-admin/includes/update.php, which only exists in a real WordPress install.
 * Everything else runs the production code path.
 */

KH_Test::group('Admin — pages render');

/** Digest needs a real wp-admin to enumerate updates; the pages only read summary. */
class Keelhob_Digest
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
class KH_Test_Registry
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

class KH_Test_Bag
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

$kh           = Keelhob::instance();
$kh->registry = new KH_Test_Registry();
$kh->skills   = new KH_Test_Bag();
$kh->logger   = new KH_Test_Bag([
    ['created_at' => '2026-08-08 10:00:00', 'tool' => 'create_post', 'ok' => 1, 'args_json' => '{"title":"Hi"}', 'summary' => 'Created post 12'],
]);
$kh->memory = new KH_Test_Bag([
    ['key' => 'brand.accent', 'category' => 'design', 'value' => '#f8ca50', 'updated_at' => '2026-08-08 09:00:00'],
]);

$admin = new Keelhob_Admin();
$pages = [
    'render'            => 'Connect',
    'render_automation' => 'Automation',
    'render_guide'      => 'Guide',
    'render_activity'   => 'Activity',
];

/** @var array<string, string> $html Rendered markup per page, for later assertions. */
$html = [];

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

    KH_Test::ok($fatal === '', $label . ' renders without throwing' . ($fatal ? ' — ' . $fatal : ''));
    if ($fatal !== '') {
        continue;
    }

    $html[$label] = $out;

    KH_Test::ok(strlen($out) > 500, $label . ' produces a real page, not an empty shell');
    KH_Test::same(
        substr_count($out, '<div'),
        substr_count($out, '</div>'),
        $label . ' has balanced <div> tags'
    );
    KH_Test::same(
        substr_count($out, '<table'),
        substr_count($out, '</table>'),
        $label . ' has balanced <table> tags'
    );
    KH_Test::same(
        substr_count($out, '<form'),
        substr_count($out, '</form>'),
        $label . ' has balanced <form> tags'
    );
    KH_Test::same(
        substr_count($out, '<details'),
        substr_count($out, '</details>'),
        $label . ' has balanced <details> tags'
    );
    // A template that falls out of PHP mode leaks its own source to the browser.
    KH_Test::ok(strpos($out, '<?php') === false, $label . ' leaks no raw PHP');
    KH_Test::ok(strpos($out, '<?=') === false, $label . ' leaks no short-echo tags');
}

KH_Test::group('Admin — the Snippets screen is the Studio, and only the Studio');

/*
 * WHAT USED TO BE HERE, and why it is gone.
 *
 * This group rendered includes/class-admin-snippets.php and asserted twenty
 * things about its markup. That file was a complete second Snippets screen -
 * filters, a table, a details panel, an editor, delete forms - that NOTHING
 * instantiated and NO menu item pointed at. `grep -rn Keelhob_Admin_Snippets`
 * outside tests/ returned the class declaration and nothing else.
 *
 * It shipped in the zip regardless, so the first person to read this repo after
 * it goes public would have found two full implementations of one screen and
 * had to work out which one runs. This test was the only thing keeping it
 * alive, which is the worst reason for code to exist.
 *
 * The file is deleted. Keelhob_Studio is the Snippets screen, and it is
 * asserted in two places that are stronger than this one was:
 *
 *   tests/rig/checks-studio.php      - against real WordPress and a real $wpdb
 *   tests/rig/ui/check-snippets.mjs  - in a real browser, computed styles and all
 *
 * The browser layer matters here specifically. This test passed for the whole
 * life of a screen whose code editor never once loaded.
 */

KH_Test::ok(
    !class_exists('Keelhob_Admin_Snippets'),
    'there is exactly one Snippets screen, and the dead duplicate is gone'
);
KH_Test::ok(
    !file_exists(KEELHOB_DIR . 'includes/class-admin-snippets.php'),
    'and its file is not shipped'
);

/*
 * One guarantee from the deleted group is worth keeping at source level,
 * because it is the one that corrupts somebody's work when it breaks.
 *
 * Snippet code must reach the textarea through esc_textarea and NOTHING else.
 * sanitize_textarea_field turns && into &amp;&amp; and eats angle brackets,
 * handing the owner a silently broken snippet. No rendering test catches that
 * reliably - the output still looks like code - so it is asserted against the
 * template.
 */
$edit = (string) file_get_contents(KEELHOB_DIR . 'includes/class-studio-edit.php');
KH_Test::contains(
    "echo esc_textarea((string) \$s['code']);",
    $edit,
    'the editor escapes snippet code for display and does not filter it'
);
// Comments stripped, for the same reason test-donate.php strips them: the
// template explains AT LENGTH why it must not call these, and a naive scan of
// the whole file finds those words in the warning and fails. The question is
// what the code does, not what the prose says about it. (This test failed
// exactly that way on its first run.)
$edit_code = php_strip_whitespace(KEELHOB_DIR . 'includes/class-studio-edit.php');
foreach (['sanitize_textarea_field', 'wp_kses', 'sanitize_text_field('] as $forbidden) {
    KH_Test::ok(
        strpos($edit_code, $forbidden) === false,
        'the editor never runs ' . $forbidden . ' anywhere near the code field'
    );
}

KH_Test::group('Admin — every form is nonce-protected');

// A settings form without a nonce is a CSRF hole that renders perfectly.
foreach ($html as $label => $out) {
    $forms  = substr_count($out, '<form');
    $nonces = substr_count($out, 'name="_wpnonce"');
    if ($forms === 0) {
        continue;
    }
    KH_Test::ok($nonces >= $forms, $label . ': all ' . $forms . ' form(s) carry a nonce field (' . $nonces . ' found)');
}

KH_Test::group('Admin — secrets are not echoed in plain text');

// API keys and tokens must render into password inputs, never as page text.
$kh->update([
    'agent_api_key'       => 'sk-ant-SECRETAGENTKEY',
    'image_api_key'       => 'sk-SECRETIMAGEKEY',
    'deploy_target_token' => 'kh_SECRETDEPLOYTOKEN',
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
    'deploy token on Connect'   => ['kh_SECRETDEPLOYTOKEN', $connect],
    'agent key on Automation'   => ['sk-ant-SECRETAGENTKEY', $automation],
] as $what => [$secret, $page]) {
    KH_Test::ok(
        strpos($page, $secret) === false,
        $what . ' never appears in the page source'
    );
    KH_Test::ok(
        strpos($page, substr($secret, -4)) !== false,
        $what . ' still shows its last four characters, so the owner can tell which key is installed'
    );
}

kh_test_reset_options();

KH_Test::group('Admin — provider UI reaches the page');

// The model guide is the fix for "I typed a model name that does not exist".
// If it silently stops rendering, the plugin is back to being a guessing game.
foreach (Keelhob_Provider::providers() as $pid => $cls) {
    KH_Test::contains($cls::default_model(), $automation, 'Automation lists the ' . $pid . ' default model');
}
KH_Test::contains('name="agent_provider"', $automation, 'Automation has the provider selector');
KH_Test::contains('name="image_provider"', $connect, 'Connect has the image provider selector');

foreach (Keelhob_Image_Provider::providers() as $pid => $cls) {
    KH_Test::contains($cls::default_model(), $connect, 'Connect lists the ' . $pid . ' default image model');
}


KH_Test::group('Admin — image and voice pickers swap with the provider');

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
$kh_row = static function (string $html, string $kind, string $pid): string {
    $block = strpos($html, 'data-kind="' . $kind . '"');
    KH_Test::ok($block !== false, $kind . ' block is on the page');
    if ($block === false) {
        return '';
    }
    $from = strpos($html, 'data-provider="' . $pid . '"', $block);
    if ($from === false) {
        return '';
    }
    $next = strpos($html, '<div class="keelhob-provider-row"', $from);
    return substr($html, $from, $next === false ? 6000 : $next - $from);
};

foreach ([
    ['image', $connect,    Keelhob_Image_Provider::providers(),      'openai'],
    ['voice', $automation, Keelhob_Transcribe_Provider::providers(), 'openai'],
] as [$kind, $page, $providers, $current]) {

    foreach ($providers as $pid => $cls) {
        $row = $kh_row($page, $kind, $pid);

        KH_Test::ok($row !== '', $kind . ': ' . $pid . ' has its own row');

        // The reported bug, directly: the "get a key" link must belong to the
        // provider whose row it sits in, not to whichever one was saved.
        KH_Test::contains($cls::key_url(), $row, $kind . ': ' . $pid . " row links to that vendor's key page");

        // ...and the placeholder must be that vendor's key shape.
        KH_Test::contains('placeholder="' . $cls::key_hint() . '"', $row, $kind . ': ' . $pid . ' row hints that vendor\'s key format');

        // Every model this provider offers is a real option, so nothing is typed.
        foreach ($cls::models() as $m) {
            KH_Test::contains('value="' . $m['id'] . '"', $row, $kind . ': ' . $pid . ' offers ' . $m['id']);
        }

        // No free-text model box anywhere - that was the typo source.
        KH_Test::ok(
            strpos($row, 'name="' . ($kind === 'image' ? 'image_api_model' : 'voice_model') . '" class="keelhob-model-select"') !== false
            || strpos($row, 'keelhob-model-select') !== false,
            $kind . ': ' . $pid . ' model is a dropdown'
        );
        KH_Test::ok(
            preg_match('/<input[^>]*name="(?:image_api_model|voice_model)"/', $row) === 0,
            $kind . ': ' . $pid . ' has no typeable model input'
        );

        // Test connection has to be inside the row, or it would send the wrong
        // row's key.
        KH_Test::contains('keelhob-media-test', $row, $kind . ': ' . $pid . ' has a Test connection button');

        // The trap that makes the whole thing work. Without the disabled
        // fieldset, all three rows post at once and the last one in the
        // document wins - silently wiping the key that was just entered.
        $disabled = strpos($row, 'class="keelhob-provider-fields" style="border:0;padding:0;margin:0;" disabled') !== false;
        if ($pid === $current) {
            KH_Test::ok(!$disabled, $kind . ': the selected provider\'s fields are live');
            KH_Test::ok(strpos($row, 'hidden') === false || strpos(substr($row, 0, 60), 'hidden') === false, $kind . ': the selected provider\'s row is visible');
        } else {
            KH_Test::ok($disabled, $kind . ': ' . $pid . ' fields are disabled so they cannot overwrite the saved provider');
        }
    }
}

// Each vendor's key page must appear on the page at all - the old code only
// ever emitted the saved provider's, which is precisely what was reported.
foreach (Keelhob_Transcribe_Provider::providers() as $pid => $cls) {
    KH_Test::contains($cls::key_url(), $automation, 'Automation carries the ' . $pid . ' transcription key link');
}

KH_Test::group('Provider adapters — the test path costs nothing');

/**
 * Testing an image or transcription key must never generate an image or
 * transcribe audio. Someone debugging a bad key presses Test repeatedly; if the
 * button billed them each time, the button would be the bug.
 */
foreach ([
    Keelhob_Image_Provider::providers(),
    Keelhob_Transcribe_Provider::providers(),
] as $family) {
    foreach ($family as $pid => $cls) {
        $model = $cls::default_model();
        $url   = $cls::verify_url('', $model);

        KH_Test::ok(strpos($url, 'http') === 0, $pid . ': verify_url is an absolute URL (' . $url . ')');
        KH_Test::contains(rawurlencode($model), $url, $pid . ': verify_url names the model being tested');

        // The generate/transcribe routes must not be what we call.
        KH_Test::ok(strpos($url, ':generateContent') === false, $pid . ': verify_url does not call generateContent');
        KH_Test::ok(strpos($url, '/images/generations') === false, $pid . ': verify_url does not call image generation');
        KH_Test::ok(strpos($url, '/audio/transcriptions') === false, $pid . ': verify_url does not call transcription');

        // An override has to be honoured, or a gateway user tests the wrong host.
        $over = $cls::verify_url('https://gw.example.com/v1/images/generations', $model);
        if (strpos($cls::default_endpoint(), 'openai') !== false) {
            KH_Test::contains('gw.example.com', $over, $pid . ': an endpoint override is tested at that host');
        }

        // Auth headers must carry the key and nothing else - a GET has no body,
        // and a stray Content-Type on a GET trips some gateways.
        $h = $cls::auth_headers('KEYVALUE');
        KH_Test::ok(!isset($h['Content-Type']), $pid . ': auth headers carry no Content-Type');
        KH_Test::ok(in_array('KEYVALUE', array_map(static fn($v) => str_replace('Bearer ', '', (string) $v), $h), true), $pid . ': auth headers carry the key');
    }
}

// A double-append bug: an override that is already a full Gemini model URL must
// not become ".../models/x:generateContent/gemini-3-pro-image".
$g = Keelhob_Image_Provider_Google::verify_url(
    'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent',
    'gemini-3-pro-image'
);
KH_Test::same(
    'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro-image',
    $g,
    'a full Gemini generate URL is reduced back to the model being tested'
);

KH_Test::group('Provider adapters — every model in the list is offerable');

foreach ([
    'image' => Keelhob_Image_Provider::providers(),
    'voice' => Keelhob_Transcribe_Provider::providers(),
] as $kind => $family) {
    foreach ($family as $pid => $cls) {
        $models = $cls::models();
        KH_Test::ok(count($models) >= 2, $kind . '/' . $pid . ' offers more than one model to choose between');

        $ids = array_column($models, 'id');
        KH_Test::ok(
            in_array($cls::default_model(), $ids, true),
            $kind . '/' . $pid . ': the default model is one of the options, so the dropdown opens on a real choice'
        );
        KH_Test::same(count($ids), count(array_unique($ids)), $kind . '/' . $pid . ': no duplicate model ids');

        foreach ($models as $m) {
            KH_Test::ok(trim((string) $m['note']) !== '', $kind . '/' . $pid . ': ' . $m['id'] . ' explains itself in the dropdown');
        }
    }
}


KH_Test::group('Admin — Test connection for image and voice keys');

/**
 * The button people press when something is already wrong.
 *
 * Two things it must never do. It must never generate an image or transcribe
 * audio, because someone debugging a bad key presses it repeatedly and the
 * button would become the bill. And it must never answer "HTTP 401", which
 * tells a person staring at a settings screen nothing about which box to go
 * and look at.
 */

$kh_admin_test = new Keelhob_Admin();

/**
 * Drive ajax_test_media() the way admin-ajax does and catch the JSON it sends.
 *
 * @return array{success: bool, message: string, url: string, headers: array}
 */
$kh_call_test = static function (array $post) use ($kh_admin_test): array {
    $_POST = $post;
    $GLOBALS['kh_test_http_last'] = null;

    try {
        $kh_admin_test->ajax_test_media();
        $sent = new KH_Test_Json_Sent(false, ['message' => 'nothing was sent'], 0);
    } catch (KH_Test_Json_Sent $e) {
        $sent = $e;
    }

    $last = $GLOBALS['kh_test_http_last'] ?? null;
    $_POST = [];

    return [
        'success' => $sent->success,
        'message' => (string) ($sent->data['message'] ?? ''),
        'url'     => (string) ($last['url'] ?? ''),
        'headers' => (array) ($last['args']['headers'] ?? []),
    ];
};

kh_test_reset_options([
    'keelhob_settings' => [
        'image_provider' => 'openai',
        'image_api_key'  => 'sk-STOREDIMAGE',
        'voice_provider' => 'openai',
        'voice_api_key'  => 'sk-STOREDVOICE',
    ],
]);

// ---- the happy path, and what it must NOT have called ----------------------

kh_test_reset_ajax();
kh_test_http_queue(200, ['id' => 'gpt-image-1', 'object' => 'model']);
$r = $kh_call_test(['kind' => 'image', 'provider' => 'openai', 'api_key' => 'sk-typed', 'model' => 'gpt-image-1']);

KH_Test::ok($r['success'], 'image: a good key reports success');
KH_Test::contains('gpt-image-1', $r['message'], 'image: the success message names the model that was tested');
KH_Test::ok(
    strpos($r['url'], '/images/generations') === false,
    'image: testing a key does NOT call the image-generation route, so it cannot bill the owner'
);
KH_Test::contains('/models/gpt-image-1', $r['url'], 'image: it asks the vendor to describe the model instead');
KH_Test::contains('sk-typed', (string) ($r['headers']['Authorization'] ?? ''), 'image: the key typed on screen is the key tested, not the saved one');

kh_test_reset_ajax();
kh_test_http_queue(200, ['name' => 'models/gemini-flash-latest']);
$r = $kh_call_test(['kind' => 'voice', 'provider' => 'google', 'api_key' => 'AIza-typed', 'model' => 'gemini-flash-latest']);

KH_Test::ok($r['success'], 'voice: a good Google key reports success');
KH_Test::ok(
    strpos($r['url'], ':generateContent') === false,
    'voice: testing a key does NOT call generateContent, so it transcribes nothing'
);
KH_Test::same('AIza-typed', (string) ($r['headers']['x-goog-api-key'] ?? ''), 'voice: Google is tested with its own header, not a bearer token');

// ---- the failures, each of which must say what to do about it --------------

kh_test_reset_ajax();
kh_test_http_queue(401, ['error' => ['message' => 'Incorrect API key provided']]);
$r = $kh_call_test(['kind' => 'image', 'provider' => 'openai', 'api_key' => 'sk-bad', 'model' => 'gpt-image-1']);
KH_Test::ok(!$r['success'], 'image: a rejected key fails');
KH_Test::contains('rejected', $r['message'], 'image: a 401 says the key was rejected, not "HTTP 401"');

kh_test_reset_ajax();
kh_test_http_queue(404, ['error' => ['message' => 'model not found']]);
$r = $kh_call_test(['kind' => 'image', 'provider' => 'openai', 'api_key' => 'sk-good', 'model' => 'gpt-image-1']);
KH_Test::ok(!$r['success'], 'image: an unreachable model fails');
KH_Test::contains('gpt-image-1', $r['message'], 'image: a 404 names the model the account cannot reach');
KH_Test::ok(
    stripos($r['message'], 'key works') !== false,
    'image: a 404 says the key is fine, so nobody goes hunting for a new one'
);

// ---- the mask trap ---------------------------------------------------------

// An untouched password field posts eight bullets back. Sending those to a
// vendor tests nothing and would report a broken key on a setup that works.
kh_test_reset_ajax();
kh_test_http_queue(200, ['id' => 'gpt-image-1']);
$r = $kh_call_test([
    'kind' => 'image', 'provider' => 'openai',
    'api_key' => Keelhob_Secrets::mask('sk-STOREDIMAGE'),
    'model' => 'gpt-image-1',
]);
KH_Test::ok($r['success'], 'image: an untouched key field tests the SAVED key rather than the mask');
KH_Test::contains('sk-STOREDIMAGE', (string) ($r['headers']['Authorization'] ?? ''), 'image: the mask never reaches the vendor');

// ---- no key at all ---------------------------------------------------------

kh_test_reset_options(['keelhob_settings' => ['image_provider' => 'openai']]);
kh_test_reset_ajax();
$r = $kh_call_test(['kind' => 'image', 'provider' => 'openai', 'api_key' => '', 'model' => 'gpt-image-1']);
KH_Test::ok(!$r['success'], 'image: testing with no key anywhere fails');
KH_Test::same('', $r['url'], 'image: ...without making a request at all');
KH_Test::contains('Add an API key', $r['message'], 'image: and it says to add one');

// ---- voice reuses a same-vendor key, and the test has to know that ---------

// Voice deliberately falls back to another key for the same vendor. A test that
// ignored the fallback would report "no key" on a configuration that works.
kh_test_reset_options([
    'keelhob_settings' => [
        'voice_provider' => 'openai',
        'voice_api_key'  => '',
        'image_provider' => 'openai',
        'image_api_key'  => 'sk-BORROWEDFROMIMAGE',
    ],
]);
kh_test_reset_ajax();
kh_test_http_queue(200, ['id' => 'whisper-1']);
$r = $kh_call_test(['kind' => 'voice', 'provider' => 'openai', 'api_key' => '', 'model' => 'whisper-1']);
KH_Test::ok($r['success'], 'voice: a blank key falls back to the same vendor\'s image key, exactly as transcription itself does');
KH_Test::contains('sk-BORROWEDFROMIMAGE', (string) ($r['headers']['Authorization'] ?? ''), 'voice: the borrowed key is the one tested');

// ---- the guard -------------------------------------------------------------

// This endpoint accepts an API key in the body so an unsaved one can be tested.
// That makes it a credential path, not a settings path: capability AND nonce.
$GLOBALS['kh_test_ajax'] = ['caps' => [], 'nonces' => []];
kh_test_reset_ajax();
kh_test_http_queue(200, ['id' => 'gpt-image-1']);
$kh_call_test(['kind' => 'image', 'provider' => 'openai', 'api_key' => 'sk-x', 'model' => 'gpt-image-1']);
KH_Test::ok(in_array('manage_options', $GLOBALS['kh_test_ajax']['caps'] ?? [], true), 'the media test checks manage_options');
KH_Test::ok(!empty($GLOBALS['kh_test_ajax']['nonces']), 'the media test checks a nonce');

// An unknown kind or provider must not fall through to a default and quietly
// test the wrong vendor with somebody's key.
kh_test_reset_ajax();
$r = $kh_call_test(['kind' => 'nonsense', 'provider' => 'openai', 'api_key' => 'sk-x', 'model' => 'm']);
KH_Test::ok(!$r['success'], 'an unknown provider type is refused');
KH_Test::same('', $r['url'], '...without sending the key anywhere');

kh_test_reset_ajax();
$r = $kh_call_test(['kind' => 'image', 'provider' => 'notavendor', 'api_key' => 'sk-x', 'model' => 'm']);
KH_Test::ok(!$r['success'], 'an unknown provider is refused rather than defaulting to OpenAI');
KH_Test::same('', $r['url'], '...without sending the key anywhere');

kh_test_reset_ajax();
kh_test_reset_options();


KH_Test::group('Media providers — Refresh adds, never replaces');

/**
 * A vendor's model list is one flat catalogue of everything the account can
 * touch, with nothing in it that says which models draw pictures or write down
 * speech. Picking them out means matching on names, and a name match is a guess
 * that goes stale.
 *
 * So the rule these cover: a refresh may only ADD. If it were allowed to
 * replace the list, one rename at the vendor would empty the dropdown — or
 * quietly drop the model a working site is already using.
 */

// What OpenAI really returns: everything, in one list.
$openai_catalogue = ['data' => [
    ['id' => 'gpt-image-2',            'created' => 900],
    ['id' => 'gpt-image-1',            'created' => 800],
    ['id' => 'dall-e-3',               'created' => 300],
    ['id' => 'gpt-5',                  'created' => 950],
    ['id' => 'gpt-4o-audio-preview',   'created' => 700],
    ['id' => 'gpt-4o-transcribe',      'created' => 750],
    ['id' => 'gpt-4o-mini-transcribe', 'created' => 760],
    ['id' => 'whisper-1',              'created' => 200],
    ['id' => 'text-embedding-3-large', 'created' => 500],
    ['id' => 'tts-1',                  'created' => 400],
    ['id' => 'omni-moderation-latest', 'created' => 600],
]];

$img = Keelhob_Image_Provider_OpenAI::parse_models($openai_catalogue);
KH_Test::same(['gpt-image-2', 'gpt-image-1', 'dall-e-3'], $img, 'OpenAI images: only image models, newest first');
foreach (['gpt-5', 'whisper-1', 'tts-1', 'text-embedding-3-large', 'omni-moderation-latest'] as $wrong) {
    KH_Test::ok(!in_array($wrong, $img, true), 'OpenAI images: ' . $wrong . ' is not offered as an image model');
}

$voice = Keelhob_Transcribe_Provider_OpenAI::parse_models($openai_catalogue);
KH_Test::same(['gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1'], $voice, 'OpenAI voice: only transcription models, newest first');

// The trap worth naming: gpt-4o-audio ACCEPTS audio, and answers in speech. It
// would return no transcript at all, and the failure would look like a bad key.
KH_Test::ok(!in_array('gpt-4o-audio-preview', $voice, true), 'OpenAI voice: an audio model that SPEAKS is not offered for transcription');
KH_Test::ok(!in_array('gpt-5', $voice, true), 'OpenAI voice: a plain chat model is not offered for transcription');

// Gemini's list, with the shapes that actually appear in it.
$google_catalogue = ['models' => [
    ['name' => 'models/gemini-3-pro-image',     'supportedGenerationMethods' => ['generateContent']],
    ['name' => 'models/gemini-2.5-flash-image', 'supportedGenerationMethods' => ['generateContent']],
    ['name' => 'models/gemini-2.5-flash',       'supportedGenerationMethods' => ['generateContent']],
    ['name' => 'models/gemini-2.5-pro',         'supportedGenerationMethods' => ['generateContent']],
    ['name' => 'models/gemini-2.5-flash-tts',   'supportedGenerationMethods' => ['generateContent']],
    ['name' => 'models/imagen-4.0-generate',    'supportedGenerationMethods' => ['predict']],
    ['name' => 'models/veo-3.0-generate',       'supportedGenerationMethods' => ['predictLongRunning']],
    ['name' => 'models/text-embedding-004',     'supportedGenerationMethods' => ['embedContent']],
]];

$gimg = Keelhob_Image_Provider_Google::parse_models($google_catalogue);
KH_Test::ok(in_array('gemini-3-pro-image', $gimg, true), 'Gemini images: the image models are found');
KH_Test::ok(in_array('gemini-2.5-flash-image', $gimg, true), 'Gemini images: both image models are found');
KH_Test::ok(!in_array('gemini-2.5-flash', $gimg, true), 'Gemini images: a text model is not offered');
// Imagen and Veo do make pictures and video - through :predict, which this
// adapter does not speak. Offering one would fail on the first request.
KH_Test::ok(!in_array('imagen-4.0-generate', $gimg, true), 'Gemini images: imagen is excluded, because this adapter speaks generateContent');
KH_Test::ok(!in_array('veo-3.0-generate', $gimg, true), 'Gemini images: veo is excluded for the same reason');

$gvoice = Keelhob_Transcribe_Provider_Google::parse_models($google_catalogue);
KH_Test::ok(in_array('gemini-2.5-flash', $gvoice, true), 'Gemini voice: text models are the transcription candidates');
KH_Test::ok(!in_array('gemini-3-pro-image', $gvoice, true), 'Gemini voice: an image model is not offered');
// Every one of these answers generateContent and returns something that is not
// a transcript, which is why the filter cannot simply trust that method.
KH_Test::ok(!in_array('gemini-2.5-flash-tts', $gvoice, true), 'Gemini voice: a speech model is not offered, though it answers generateContent');
KH_Test::ok(!in_array('text-embedding-004', $gvoice, true), 'Gemini voice: an embedding model is not offered');

KH_Test::group('Media providers — the merged dropdown');

kh_test_reset_options();
kh_test_reset_ajax();

// With no refresh ever run, the dropdown is exactly the curated list.
$fresh = Keelhob_Media_Check::models(Keelhob_Image_Provider_OpenAI::class, 'image', 'openai');
KH_Test::same(
    array_column(Keelhob_Image_Provider_OpenAI::models(), 'id'),
    array_column($fresh, 'id'),
    'a site that has never refreshed sees the curated list, so an offline site still has a working dropdown'
);

// Now a refresh finds a model this release has never heard of.
kh_test_http_queue(200, $openai_catalogue);
$after = Keelhob_Media_Check::refresh(Keelhob_Image_Provider_OpenAI::class, 'image', 'openai', 'sk-key', '');
KH_Test::ok(!is_wp_error($after), 'a refresh with a good key succeeds');

$ids = array_column(is_array($after) ? $after : [], 'id');
KH_Test::ok(in_array('gpt-image-2', $ids, true), 'the newly discovered model is added to the dropdown');
foreach (array_column(Keelhob_Image_Provider_OpenAI::models(), 'id') as $curated) {
    KH_Test::ok(in_array($curated, $ids, true), 'refresh keeps the curated model ' . $curated);
}

// And the failure that must not happen: a vendor that answers with nothing
// useful must not be able to empty the list.
kh_test_reset_ajax();
kh_test_http_queue(200, ['data' => []]);
$empty = Keelhob_Media_Check::refresh(Keelhob_Image_Provider_OpenAI::class, 'image', 'openai', 'sk-key', '');
KH_Test::ok(!is_wp_error($empty), 'a vendor with nothing new is not an error - the key worked');
KH_Test::ok(
    count(is_array($empty) ? $empty : []) >= count(Keelhob_Image_Provider_OpenAI::models()),
    'an empty vendor answer cannot empty the dropdown'
);

// A model already saved stays offered even when the vendor stops listing it.
kh_test_reset_ajax();
kh_test_http_queue(200, ['data' => []]);
$kept = Keelhob_Media_Check::refresh(Keelhob_Image_Provider_OpenAI::class, 'image', 'openai', 'sk-key', '', 'dall-e-2-retired');
KH_Test::ok(
    in_array('dall-e-2-retired', array_column(is_array($kept) ? $kept : [], 'id'), true),
    'the model this site is USING stays on the list even after the vendor drops it'
);

// A bad key says so, and does not poison the cache.
kh_test_reset_ajax();
kh_test_http_queue(401, ['error' => ['message' => 'Incorrect API key provided']]);
$bad = Keelhob_Media_Check::refresh(Keelhob_Image_Provider_OpenAI::class, 'image', 'openai', 'sk-bad', '');
KH_Test::ok(is_wp_error($bad), 'a rejected key fails the refresh');
KH_Test::contains('rejected', $bad->get_error_message(), 'and says the key was rejected, not "HTTP 401"');

kh_test_reset_ajax();
$nokey = Keelhob_Media_Check::refresh(Keelhob_Image_Provider_OpenAI::class, 'image', 'openai', '', '');
KH_Test::ok(is_wp_error($nokey), 'refreshing with no key fails');
KH_Test::contains('Add an API key', $nokey->get_error_message(), 'and says to add one');

KH_Test::group('Media providers — the refresh URL is the catalogue, not the work');

foreach ([
    'image' => Keelhob_Image_Provider::providers(),
    'voice' => Keelhob_Transcribe_Provider::providers(),
] as $kind => $family) {
    foreach ($family as $pid => $cls) {
        $url = $cls::models_list_url($cls::default_endpoint());
        KH_Test::ok($url !== '', $kind . '/' . $pid . ' publishes a model list');
        KH_Test::ok(strpos($url, '/images/generations') === false, $kind . '/' . $pid . ': listing models does not call image generation');
        KH_Test::ok(strpos($url, '/audio/transcriptions') === false, $kind . '/' . $pid . ': listing models does not call transcription');
        KH_Test::ok(strpos($url, ':generateContent') === false, $kind . '/' . $pid . ': listing models does not call generateContent');
    }
}

kh_test_reset_ajax();
kh_test_reset_options();

KH_Test::group('Admin — strings are internationalized');

// Catch a future edit that drops a raw English string back into a template.
$src   = '';
foreach (['class-admin.php', 'class-admin-screens.php', 'class-admin-automation.php', 'class-admin-providers.php', 'class-admin-settings.php'] as $kh_admin_file) {
    $src .= (string) file_get_contents(KEELHOB_DIR . 'includes/' . $kh_admin_file);
}
$raw   = preg_match_all('/<(?:p|h2|h3|li|th|strong|em|label|summary|button)[^>]*>[A-Z][a-z]+ [a-z]+/', $src, $m);
KH_Test::same(0, $raw, 'no untranslated sentence-like strings left in admin templates');
KH_Test::ok(
    substr_count($src, "'keelhob'") > 150,
    'admin strings carry the keelhob text domain (' . substr_count($src, "'keelhob'") . ' occurrences)'
);
