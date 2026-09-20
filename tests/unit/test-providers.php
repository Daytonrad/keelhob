<?php
/**
 * Provider adapter tests.
 *
 * Three vendors, three incompatible wire formats, one runtime loop. The loop is
 * only correct if every adapter agrees on the normalized shape, so these tests
 * feed each adapter a realistic response body and assert the same normalized
 * result comes out — plus the round-trip back into that vendor's own message
 * format, which is what actually breaks a multi-turn tool-calling run.
 */

KH_Test::group('Providers — registry');

KH_Test::same(
    Keelhob_Provider_Anthropic::class,
    Keelhob_Provider::get('anthropic'),
    'anthropic resolves to its adapter'
);
KH_Test::same(
    Keelhob_Provider_OpenAI::class,
    Keelhob_Provider::get('openai'),
    'openai resolves to its adapter'
);
KH_Test::same(
    Keelhob_Provider_Google::class,
    Keelhob_Provider::get('google'),
    'google resolves to its adapter'
);
KH_Test::same(
    Keelhob_Provider_Anthropic::class,
    Keelhob_Provider::get('does-not-exist'),
    'an unknown provider id falls back to Anthropic rather than fataling'
);

foreach (Keelhob_Provider::providers() as $pid => $cls) {
    KH_Test::ok($cls::default_model() !== '', $pid . ' declares a default model');
    KH_Test::ok($cls::default_endpoint() !== '', $pid . ' declares a default endpoint');
    KH_Test::ok(count($cls::models()) > 0, $pid . ' lists model IDs for the admin guide');
    KH_Test::contains('https://', $cls::key_url(), $pid . ' links somewhere to get a key');
    KH_Test::contains('https://', $cls::models_url(), $pid . ' links to the vendor model list');
}

// A model ID an owner is told to type must be one the price table can score,
// or the budget caps silently fall back to Sonnet pricing and mis-meter spend.
foreach (Keelhob_Provider::providers() as $pid => $cls) {
    foreach ($cls::models() as $m) {
        $price = Keelhob_Agent_Runtime::price_for_model($m['id']);
        KH_Test::ok(
            $price['in'] > 0 && $price['out'] > 0,
            'listed model ' . $m['id'] . ' has a price entry for budget metering'
        );
    }
}

KH_Test::group('Providers — URL construction');

KH_Test::same(
    'https://api.anthropic.com/v1/messages',
    Keelhob_Provider_Anthropic::url('https://api.anthropic.com/v1/messages', 'claude-sonnet-5'),
    'Anthropic ignores the model when building the URL'
);
KH_Test::same(
    'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-pro:generateContent',
    Keelhob_Provider_Google::url('https://generativelanguage.googleapis.com/v1beta/models', 'gemini-3-pro'),
    'Google puts the model in the path'
);
KH_Test::same(
    'https://proxy.test/v1beta/models/x:generateContent',
    Keelhob_Provider_Google::url('https://proxy.test/v1beta/models/x:generateContent', 'gemini-3-pro'),
    'a full :generateContent override is left untouched'
);

KH_Test::group('Providers — auth headers');

KH_Test::same(
    'sk-ant-abc',
    Keelhob_Provider_Anthropic::headers('sk-ant-abc')['x-api-key'],
    'Anthropic authenticates with x-api-key'
);
KH_Test::same(
    'Bearer sk-abc',
    Keelhob_Provider_OpenAI::headers('sk-abc')['Authorization'],
    'OpenAI authenticates with a bearer token'
);
KH_Test::same(
    'AIza-abc',
    Keelhob_Provider_Google::headers('AIza-abc')['x-goog-api-key'],
    'Google authenticates with x-goog-api-key'
);

KH_Test::group('Providers — parsing a plain text answer');

$tools = [['name' => 'get_site_info', 'description' => 'Info', 'schema' => ['type' => 'object', 'properties' => (object) []]]];

$a = Keelhob_Provider_Anthropic::parse([
    'content'     => [['type' => 'text', 'text' => 'All done.']],
    'stop_reason' => 'end_turn',
    'usage'       => ['input_tokens' => 10, 'output_tokens' => 4],
]);
KH_Test::same('All done.', $a['text'], 'Anthropic text is extracted');
KH_Test::same(false, $a['wants_tools'], 'end_turn means the run is finished');
KH_Test::same(10, $a['usage']['input'], 'Anthropic input tokens are read');
KH_Test::same(4, $a['usage']['output'], 'Anthropic output tokens are read');

$o = Keelhob_Provider_OpenAI::parse([
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'All done.'], 'finish_reason' => 'stop']],
    'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 4],
]);
KH_Test::same('All done.', $o['text'], 'OpenAI text is extracted');
KH_Test::same(false, $o['wants_tools'], 'no tool_calls means the run is finished');
KH_Test::same(10, $o['usage']['input'], 'OpenAI prompt_tokens map to input');
KH_Test::same(4, $o['usage']['output'], 'OpenAI completion_tokens map to output');

$g = Keelhob_Provider_Google::parse([
    'candidates'    => [['content' => ['parts' => [['text' => 'All done.']]]]],
    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 4],
]);
KH_Test::same('All done.', $g['text'], 'Google text is extracted');
KH_Test::same(false, $g['wants_tools'], 'no functionCall means the run is finished');
KH_Test::same(10, $g['usage']['input'], 'Google promptTokenCount maps to input');
KH_Test::same(4, $g['usage']['output'], 'Google candidatesTokenCount maps to output');

KH_Test::group('Providers — parsing a tool call');

$a = Keelhob_Provider_Anthropic::parse([
    'content' => [
        ['type' => 'text', 'text' => 'Checking.'],
        ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'wc_report', 'input' => ['period' => 'week']],
    ],
    'stop_reason' => 'tool_use',
    'usage'       => ['input_tokens' => 1, 'output_tokens' => 1],
]);
KH_Test::same(true, $a['wants_tools'], 'Anthropic stop_reason tool_use requests tools');
KH_Test::same(1, count($a['tool_calls']), 'one Anthropic tool call parsed');
KH_Test::same('wc_report', $a['tool_calls'][0]['name'], 'Anthropic tool name parsed');
KH_Test::same('week', $a['tool_calls'][0]['args']['period'], 'Anthropic tool args parsed');
KH_Test::same('tu_1', $a['tool_calls'][0]['id'], 'Anthropic tool_use id preserved for the result');

$o = Keelhob_Provider_OpenAI::parse([
    'choices' => [['message' => [
        'role'       => 'assistant',
        'content'    => null,
        'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => [
            'name'      => 'wc_report',
            'arguments' => '{"period":"week"}',
        ]]],
    ], 'finish_reason' => 'tool_calls']],
    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
]);
KH_Test::same(true, $o['wants_tools'], 'OpenAI tool_calls request tools');
KH_Test::same('wc_report', $o['tool_calls'][0]['name'], 'OpenAI tool name parsed');
KH_Test::same('week', $o['tool_calls'][0]['args']['period'], 'OpenAI JSON-string arguments are decoded');
KH_Test::same('call_1', $o['tool_calls'][0]['id'], 'OpenAI call id preserved');

$g = Keelhob_Provider_Google::parse([
    'candidates' => [['content' => ['parts' => [
        ['text' => 'Checking.'],
        ['functionCall' => ['name' => 'wc_report', 'args' => ['period' => 'week']]],
    ]]]],
    'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
]);
KH_Test::same(true, $g['wants_tools'], 'Google functionCall requests tools');
KH_Test::same('wc_report', $g['tool_calls'][0]['name'], 'Google function name parsed');
KH_Test::same('week', $g['tool_calls'][0]['args']['period'], 'Google function args parsed');
KH_Test::ok($g['tool_calls'][0]['id'] !== '', 'Google gets a synthesized call id (the API sends none)');

KH_Test::group('Providers — malformed / empty responses');

foreach (Keelhob_Provider::providers() as $pid => $cls) {
    $empty = $cls::parse([]);
    KH_Test::same('', $empty['text'], $pid . ' survives an empty body without fataling');
    KH_Test::same(false, $empty['wants_tools'], $pid . ' treats an empty body as "no tools requested"');
    KH_Test::same(0, $empty['usage']['input'], $pid . ' reports zero input tokens for an empty body');
}

KH_Test::group('Providers — tool results round-trip');

$results = [['id' => 'tu_1', 'name' => 'wc_report', 'result' => ['success' => true, 'orders' => 3]]];

$msgs = Keelhob_Provider_Anthropic::tool_result_message($results);
KH_Test::same(1, count($msgs), 'Anthropic batches every result into one user message');
KH_Test::same('user', $msgs[0]['role'], 'Anthropic tool results come back as a user turn');
KH_Test::same('tool_result', $msgs[0]['content'][0]['type'], 'Anthropic uses tool_result blocks');
KH_Test::same('tu_1', $msgs[0]['content'][0]['tool_use_id'], 'Anthropic result is keyed to the call id');

$msgs = Keelhob_Provider_OpenAI::tool_result_message($results);
KH_Test::same(1, count($msgs), 'OpenAI emits one message per result');
KH_Test::same('tool', $msgs[0]['role'], 'OpenAI tool results use the tool role');
KH_Test::same('tu_1', $msgs[0]['tool_call_id'], 'OpenAI result is keyed to the call id');

$msgs = Keelhob_Provider_Google::tool_result_message($results);
KH_Test::same(1, count($msgs), 'Google batches results into one user turn');
KH_Test::same('user', $msgs[0]['role'], 'Google tool results come back as a user turn');
KH_Test::same('wc_report', $msgs[0]['parts'][0]['functionResponse']['name'], 'Google matches results by function name, not id');

KH_Test::group('Providers — request bodies');

$body = Keelhob_Provider_Anthropic::body('claude-sonnet-5', 'SYS', $tools, [['role' => 'user', 'content' => 'hi']]);
KH_Test::same('SYS', $body['system'], 'Anthropic carries the system prompt in its own field');
KH_Test::same('get_site_info', $body['tools'][0]['name'], 'Anthropic declares tools by name');
KH_Test::ok(isset($body['tools'][0]['input_schema']), 'Anthropic uses input_schema');

$body = Keelhob_Provider_OpenAI::body('gpt-5.1', 'SYS', $tools, [['role' => 'user', 'content' => 'hi']]);
KH_Test::same('system', $body['messages'][0]['role'], 'OpenAI prepends the system prompt as a message');
KH_Test::same('SYS', $body['messages'][0]['content'], 'OpenAI system content is the system prompt');
KH_Test::same('function', $body['tools'][0]['type'], 'OpenAI declares tools as functions');
KH_Test::same('get_site_info', $body['tools'][0]['function']['name'], 'OpenAI nests the tool name under function');

$body = Keelhob_Provider_Google::body('gemini-3-pro', 'SYS', $tools, [['role' => 'user', 'parts' => [['text' => 'hi']]]]);
KH_Test::same('SYS', $body['systemInstruction']['parts'][0]['text'], 'Google uses systemInstruction');
KH_Test::same('get_site_info', $body['tools'][0]['functionDeclarations'][0]['name'], 'Google declares functionDeclarations');

KH_Test::group('Providers — schema sanitising for Google');

// Google's function-calling parser rejects the wider JSON Schema vocabulary the
// other vendors accept, so unsupported keywords must be stripped, not forwarded.
$dirty = [
    'type'                 => 'object',
    '$schema'              => 'http://json-schema.org/draft-07/schema#',
    'additionalProperties' => false,
    'properties'           => [
        'mode'  => ['type' => 'string', 'enum' => ['a', 'b'], 'const' => 'a'],
        'items' => ['type' => 'array', 'items' => ['type' => 'string', 'additionalProperties' => true]],
    ],
    'required'             => ['mode'],
];
$decl  = Keelhob_Provider_Google::body('gemini-3-pro', 'SYS', [
    ['name' => 't', 'description' => 'd', 'schema' => $dirty],
], [])['tools'][0]['functionDeclarations'][0]['parameters'];

KH_Test::ok(!isset($decl['$schema']), '$schema is stripped');
KH_Test::ok(!isset($decl['additionalProperties']), 'additionalProperties is stripped');
KH_Test::ok(!isset($decl['properties']['mode']['const']), 'const is stripped from nested properties');
KH_Test::ok(!isset($decl['properties']['items']['items']['additionalProperties']), 'array item schemas are cleaned recursively');
KH_Test::same(['a', 'b'], $decl['properties']['mode']['enum'], 'enum survives cleaning');
KH_Test::same(['mode'], $decl['required'], 'required survives cleaning');
KH_Test::same('string', $decl['properties']['mode']['type'], 'types survive cleaning');

KH_Test::group('Image providers');

KH_Test::same(
    Keelhob_Image_Provider_OpenAI::class,
    Keelhob_Image_Provider::get('nope'),
    'an unknown image provider falls back to OpenAI'
);

foreach (Keelhob_Image_Provider::providers() as $pid => $cls) {
    KH_Test::ok($cls::default_model() !== '', 'image provider ' . $pid . ' declares a default model');
    KH_Test::ok(count($cls::models()) > 0, 'image provider ' . $pid . ' lists model IDs for the guide');
}

$png = base64_encode('PNGBYTES');
KH_Test::same(
    'PNGBYTES',
    Keelhob_Image_Provider_OpenAI::extract_bytes(['data' => [['b64_json' => $png]]]),
    'OpenAI inline base64 image is decoded'
);
KH_Test::same(
    'https://img.test/a.png',
    Keelhob_Image_Provider_OpenAI::extract_url(['data' => [['url' => 'https://img.test/a.png']]]),
    'OpenAI URL-style response is detected'
);
KH_Test::same(
    null,
    Keelhob_Image_Provider_OpenAI::extract_bytes(['data' => [[]]]),
    'a response with no image yields null rather than junk bytes'
);
KH_Test::same(
    'PNGBYTES',
    Keelhob_Image_Provider_Google::extract_bytes([
        'candidates' => [['content' => ['parts' => [['inlineData' => ['data' => $png]]]]]],
    ]),
    'Gemini inlineData image is decoded'
);
KH_Test::same(
    'PNGBYTES',
    Keelhob_Image_Provider_Google::extract_bytes([
        'candidates' => [['content' => ['parts' => [['inline_data' => ['data' => $png]]]]]],
    ]),
    'Gemini snake_case inline_data is also accepted'
);
KH_Test::same(
    null,
    Keelhob_Image_Provider_Google::extract_bytes(['candidates' => [['content' => ['parts' => [['text' => 'refused']]]]]]),
    'a Gemini text-only refusal yields null, not a broken image'
);

/*
 * ---------------------------------------------------------------------------
 * The settings-screen half of the adapters: the model list, the two round trips
 * behind Refresh and Test, and the two AJAX endpoints in front of them.
 *
 * The endpoints matter most. They take an API key in the request body so an
 * unsaved one can be tested, which makes them a credential path rather than a
 * settings path — so the tests below assert that both the capability check and
 * the nonce check are really there, and that refusing actually stops.
 * ---------------------------------------------------------------------------
 */

/** A vendor with no model-list endpoint, so the fallback wording can be tested. */
final class KH_Test_Provider_NoList extends Keelhob_Provider
{
    public static function id(): string { return 'nolist'; }
    public static function label(): string { return 'Gateway with no catalogue'; }
    public static function default_endpoint(): string { return 'https://gateway.test/v1/chat'; }
    public static function default_model(): string { return 'house-model'; }
    public static function key_url(): string { return ''; }
    public static function models_url(): string { return ''; }
    public static function key_hint(): string { return 'key-...'; }
    public static function models(): array { return [['id' => 'house-model', 'note' => 'Whatever the gateway offers.']]; }
    public static function headers(string $key): array { return ['Authorization' => 'Bearer ' . $key]; }
    public static function body(string $model, string $system, array $tools, array $messages): array
    {
        return ['model' => $model, 'messages' => $messages, 'tools' => []];
    }
    public static function user_message(string $text): array { return ['role' => 'user', 'content' => $text]; }
    public static function parse(array $data): array
    {
        return ['text' => '', 'tool_calls' => [], 'wants_tools' => false, 'usage' => ['input' => 0, 'output' => 0], 'raw' => $data];
    }
    public static function assistant_message(array $parsed): array { return ['role' => 'assistant', 'content' => '']; }
    public static function tool_result_message(array $results): array { return []; }
}

KH_Test::group('Providers — parsing a vendor model list');

KH_Test::same(
    ['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5'],
    Keelhob_Provider_Anthropic::parse_models(['data' => [
        ['id' => 'claude-haiku-4-5', 'created_at' => '2026-01-04T00:00:00Z'],
        ['id' => 'claude-opus-5',    'created_at' => '2026-06-01T00:00:00Z'],
        ['id' => 'claude-sonnet-5',  'created_at' => '2026-05-01T00:00:00Z'],
    ]]),
    'Anthropic models come back newest first, by created_at'
);
KH_Test::same(
    [],
    Keelhob_Provider_Anthropic::parse_models(['error' => ['message' => 'nope']]),
    'a body with no data array yields no models rather than a notice'
);

$openai = Keelhob_Provider_OpenAI::parse_models(['data' => [
    ['id' => 'text-embedding-3-large', 'created' => 1800000000],
    ['id' => 'gpt-5.1',                'created' => 1700000000],
    ['id' => 'gpt-4o-audio-preview',   'created' => 1690000000],
    ['id' => 'gpt-5.2',                'created' => 1750000000],
    ['id' => 'dall-e-3',               'created' => 1600000000],
    ['id' => 'o4-mini',                'created' => 1650000000],
]]);
KH_Test::same(['gpt-5.2', 'gpt-5.1', 'o4-mini'], $openai, 'OpenAI keeps the chat models, newest first');
KH_Test::ok(!in_array('text-embedding-3-large', $openai, true), 'an embedding model is not offered as a brain');
KH_Test::ok(!in_array('gpt-4o-audio-preview', $openai, true), 'a speech variant of a chat family is filtered out too');

$google = Keelhob_Provider_Google::parse_models(['models' => [
    ['name' => 'models/gemini-2.5-pro', 'supportedGenerationMethods' => ['generateContent']],
    ['name' => 'models/gemini-3-pro',   'supportedGenerationMethods' => ['generateContent']],
    ['name' => 'models/text-embedding-004', 'supportedGenerationMethods' => ['embedContent']],
    ['name' => 'models/gemini-3-pro-image', 'supportedGenerationMethods' => ['generateContent']],
]]);
KH_Test::same(['gemini-3-pro', 'gemini-2.5-pro'], $google, 'Gemini strips the models/ prefix and sorts newest-looking first');

KH_Test::group('Providers — where the model list is fetched from');

KH_Test::same(
    'https://api.anthropic.com/v1/models?limit=100',
    Keelhob_Provider_Anthropic::models_list_url('https://api.anthropic.com/v1/messages'),
    'Anthropic derives its list URL from the chat endpoint'
);
KH_Test::same(
    'https://api.openai.com/v1/models',
    Keelhob_Provider_OpenAI::models_list_url('https://api.openai.com/v1/chat/completions'),
    'OpenAI derives its list URL from the chat endpoint'
);
KH_Test::same(
    'https://gateway.test/v1/models',
    Keelhob_Provider_OpenAI::models_list_url('https://gateway.test/v1/chat/completions'),
    'an OpenAI-compatible gateway is asked for its own catalogue, not OpenAI\'s'
);
KH_Test::same(
    '',
    KH_Test_Provider_NoList::models_list_url('https://gateway.test/v1/chat'),
    'a provider that publishes no list says so by returning an empty URL'
);

KH_Test::group('Providers — the model dropdown');

kh_test_reset_options();
$GLOBALS['kh_test_transients'] = [];

KH_Test::same(
    ['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5'],
    Keelhob_Provider::cached_models('anthropic'),
    'with nothing cached the dropdown falls back to the built-in list'
);

set_transient('keelhob_models_anthropic', array_map(
    static function ($n) { return 'claude-' . $n; },
    range(1, 25)
), WEEK_IN_SECONDS);

$capped = Keelhob_Provider::cached_models('anthropic');
KH_Test::same(Keelhob_Provider::MODELS_SHOWN, count($capped), 'a long vendor list is capped rather than dumped into a dropdown');
KH_Test::same('claude-1', $capped[0], 'the cap keeps the front of the list, which is the newest end');
KH_Test::ok(Keelhob_Provider::has_fetched_models('anthropic'), 'a fetched list is remembered');

$kept = Keelhob_Provider::cached_models('anthropic', 'claude-retired-9');
KH_Test::same('claude-retired-9', $kept[0], 'the saved model stays on the list even after the vendor drops it');

Keelhob_Provider::forget_models();
KH_Test::ok(!Keelhob_Provider::has_fetched_models('anthropic'), 'forget_models clears the cache');

KH_Test::group('Providers — fetching the model list');

kh_test_reset_options();
kh_test_reset_ajax();
$GLOBALS['kh_test_transients'] = [];

KH_Test::contains(
    'Add an API key first',
    Keelhob_Provider::fetch_models(['provider' => 'anthropic'])->get_error_message(),
    'with no key anywhere, the answer names the missing key rather than an HTTP code'
);

add_filter('keelhob_llm_providers', static function ($all) {
    $all['nolist'] = KH_Test_Provider_NoList::class;
    return $all;
});

KH_Test::contains(
    'does not publish a model list',
    Keelhob_Provider::fetch_models(['provider' => 'nolist', 'api_key' => 'k'])->get_error_message(),
    'a provider with no catalogue explains itself and points at the text box'
);

kh_test_http_queue(401, ['error' => ['message' => 'invalid x-api-key']]);
KH_Test::same(
    'Your API key was rejected. Check it above.',
    Keelhob_Provider::fetch_models(['provider' => 'anthropic', 'api_key' => 'sk-bad'])->get_error_message(),
    'a 401 becomes something the owner can act on, not "HTTP 401"'
);

kh_test_http_queue(200, ['data' => array_map(
    static function ($n) { return ['id' => 'claude-' . $n, 'created_at' => sprintf('2026-%02d-01T00:00:00Z', $n)]; },
    range(1, 12)
)]);
$fetched = Keelhob_Provider::fetch_models(['provider' => 'anthropic', 'api_key' => 'sk-good']);
KH_Test::ok(!is_wp_error($fetched), 'a 200 with models is not an error');
KH_Test::same(Keelhob_Provider::MODELS_SHOWN, count($fetched), 'the returned list is capped at ten');
KH_Test::same('claude-12', $fetched[0], 'newest first survives the round trip');
KH_Test::ok(
    is_array(get_transient('keelhob_models_anthropic')),
    'the list is cached, so the dropdown does not call the vendor on every page load'
);
KH_Test::contains(
    'api.anthropic.com/v1/models',
    (string) $GLOBALS['kh_test_http_last']['url'],
    'the request went to the vendor model list, not the chat endpoint'
);

// An unsaved endpoint has to be honoured, or "Refresh" on a gateway would ask
// the wrong host what it can do.
$GLOBALS['kh_test_transients'] = [];
kh_test_http_queue(200, ['data' => [['id' => 'gpt-9', 'created' => 1]]]);
Keelhob_Provider::fetch_models([
    'provider' => 'openai',
    'api_key'  => 'sk-x',
    'endpoint' => 'https://gateway.test/v1/chat/completions',
]);
KH_Test::same(
    'https://gateway.test/v1/models',
    (string) $GLOBALS['kh_test_http_last']['url'],
    'a typed-but-unsaved endpoint is the one that gets asked'
);

KH_Test::group('Providers — testing a connection');

kh_test_reset_options();
kh_test_reset_ajax();

KH_Test::contains(
    'Add an API key first',
    Keelhob_Provider::test_connection(['provider' => 'openai'])->get_error_message(),
    'testing with no key says which field is empty'
);

kh_test_http_queue(200, ['choices' => [['message' => ['content' => 'OK']]]]);
$tested = Keelhob_Provider::test_connection(['provider' => 'openai', 'api_key' => 'sk-x', 'model' => 'gpt-5.1']);
KH_Test::same('openai', $tested['provider'], 'the result names the provider that actually answered');
KH_Test::same('gpt-5.1', $tested['model'], '...and the model it answered with');
KH_Test::ok(
    strpos((string) $GLOBALS['kh_test_http_last']['args']['body'], '"tools"') === false,
    'the ping declares no tools — OpenAI and Gemini both reject an empty tools array'
);

kh_test_http_queue(403, ['error' => ['message' => 'forbidden']]);
KH_Test::same(
    'Your API key was rejected. Check it above.',
    Keelhob_Provider::test_connection(['provider' => 'openai', 'api_key' => 'sk-bad'])->get_error_message(),
    'a 403 gets the same human answer as a 401'
);

kh_test_http_queue(500, ['error' => ['message' => 'upstream exploded']]);
KH_Test::same(
    'upstream exploded',
    Keelhob_Provider::test_connection(['provider' => 'openai', 'api_key' => 'sk-x'])->get_error_message(),
    'anything else prefers the vendor\'s own wording, which is usually specific'
);

KH_Test::group('Admin — the provider endpoints are a credential path');

$admin = new Keelhob_Admin();

/**
 * Call one endpoint and report what it sent back.
 *
 * @return array{success: bool, status: int, data: array}
 */
$call = static function (string $method) use ($admin): array {
    try {
        $admin->$method();
    } catch (KH_Test_Json_Sent $sent) {
        return ['success' => $sent->success, 'status' => $sent->status, 'data' => $sent->data];
    }
    return ['success' => false, 'status' => 0, 'data' => ['message' => 'the handler returned instead of answering']];
};

foreach (['ajax_fetch_models', 'ajax_test_connection'] as $method) {
    kh_test_reset_options();
    kh_test_reset_ajax();

    // Refused: an unauthorised caller must be stopped before the nonce check
    // tells it anything, and must not reach the provider at all.
    $GLOBALS['kh_test_cap_allow'] = false;
    $_POST = ['provider' => 'anthropic', 'api_key' => 'sk-attacker', 'nonce' => 'whatever'];
    $refused = $call($method);

    KH_Test::ok(!$refused['success'], $method . ' refuses a caller without manage_options');
    KH_Test::same(403, $refused['status'], $method . ' answers 403, not a generic failure');
    KH_Test::same(
        ['manage_options'],
        $GLOBALS['kh_test_ajax']['caps'],
        $method . ' checks manage_options'
    );
    KH_Test::same(
        [],
        $GLOBALS['kh_test_ajax']['nonces'],
        $method . ' stops at the capability check rather than carrying on'
    );

    // Allowed: the nonce is still checked, every time.
    kh_test_reset_ajax();
    $GLOBALS['kh_test_cap_allow'] = true;
    kh_test_http_queue(401, ['error' => ['message' => 'nope']]);
    $allowed = $call($method);

    KH_Test::same(
        ['keelhob_provider'],
        $GLOBALS['kh_test_ajax']['nonces'],
        $method . ' checks the nonce as well as the capability'
    );
    KH_Test::ok(!$allowed['success'], $method . ' reports a rejected key as a failure');
    KH_Test::same(
        'Your API key was rejected. Check it above.',
        (string) ($allowed['data']['message'] ?? ''),
        $method . ' passes the human error message through to the screen'
    );
}

// A masked key field is not a credential: it must fall back to the stored key
// rather than sending eight bullet characters to the vendor.
kh_test_reset_options();
kh_test_reset_ajax();
Keelhob::instance()->update(['agent_provider' => 'anthropic', 'agent_api_key' => 'sk-ant-STORED']);
$_POST = [
    'provider' => 'anthropic',
    'api_key'  => Keelhob_Secrets::mask('sk-ant-STORED'),
    'nonce'    => 'n',
];
kh_test_http_queue(200, ['content' => [['type' => 'text', 'text' => 'OK']]]);
$call('ajax_test_connection');
KH_Test::same(
    'sk-ant-STORED',
    (string) ($GLOBALS['kh_test_http_last']['args']['headers']['x-api-key'] ?? ''),
    'an untouched key field tests the stored key, not the mask'
);

$_POST = [];
kh_test_reset_options();
kh_test_reset_ajax();
kh_test_reset_filters();
$GLOBALS['kh_test_transients'] = [];

KH_Test::group('Google — a no-argument tool call survives the next turn');

/*
 * THE BUG THIS PINS, reported from a live Telegram chat:
 *
 *   HTTP 400: Invalid JSON payload received. Unknown name "args" at
 *   'contents[3].parts[0].function_call': Proto field is not repeating,
 *   cannot start list.
 *
 * Gemini answered with `functionCall: {name: "snippet_list", args: {}}`.
 * json_decode turns `{}` into an empty PHP array; json_encode writes an empty
 * PHP array back out as `[]`. Google's args is a Struct, never a list, so the
 * conversation died on the turn AFTER the call -- the tool ran, the answer was
 * never delivered. Only a tool called with NO arguments can trigger it, which
 * is why it survived every earlier schema fix.
 */
$reply = Keelhob_Provider_Google::parse([
    'candidates' => [['content' => ['parts' => [
        ['functionCall' => ['name' => 'snippet_list', 'args' => (array) []]],
    ]]]],
]);
KH_Test::same('snippet_list', $reply['tool_calls'][0]['name'] ?? '', 'the call is read back');

// The SAME value the runtime replays into the next request.
$echo = Keelhob_Provider_Google::assistant_message($reply);
$json = json_encode(['contents' => [$echo]]);
KH_Test::ok(strpos((string) $json, '"args":{}') !== false, 'empty args are sent as an object');
KH_Test::ok(strpos((string) $json, '"args":[]') === false, 'and never as a list, which Google rejects');

// A call WITH arguments must be left exactly as it was.
$with = Keelhob_Provider_Google::assistant_message(Keelhob_Provider_Google::parse([
    'candidates' => [['content' => ['parts' => [
        ['functionCall' => ['name' => 'snippet_get', 'args' => ['id' => 7]]],
    ]]]],
]));
KH_Test::same(['id' => 7], (array) ($with['parts'][0]['functionCall']['args'] ?? []), 'real arguments are untouched');

// A tool that returns nothing must not produce an empty response either.
$res = Keelhob_Provider_Google::tool_result_message([['name' => 'snippet_list', 'result' => []]]);
KH_Test::ok(strpos((string) json_encode($res), '"response":[]') === false, 'an empty tool result is wrapped, not sent as a list');
