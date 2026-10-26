<?php
/**
 * The authorize endpoint's validation, and what the consent screen promises.
 *
 * WHY THIS IS A TEST.
 *
 * `read_request()` is the gate in front of a human decision. Everything it
 * refuses, it refuses BEFORE anyone is asked to approve anything, and each
 * refusal is one of the four rules:
 *
 *   - an unregistered client                → there is nothing to approve
 *   - a redirect_uri that is not registered  → rule 2
 *   - a missing or non-S256 PKCE challenge   → rule 1
 *   - (rule 4 is current_user_can, which the handler checks before this runs)
 *
 * Every one of these returns a WP_Error that the handler RENDERS. None of them
 * is handed back to the client as a redirect, because the thing we would be
 * redirecting to is the value we have just decided not to trust. A test that
 * only checked "the right error code comes back" would pass against a version
 * that redirected it, so the surrounding comments say which half matters.
 *
 * The render paths are not covered here — they end in `exit` and need a
 * browser. tests/rig/check-oauth.mjs drives those against the real rig.
 */

KH_Test::group('OAuth authorize — the PKCE challenge is pinned to a real digest');

/*
 * Rule 1. A base64url sha256 digest is exactly 43 characters with no padding.
 * Anything shorter cannot be the digest of anything, and accepting it is how a
 * weak challenge gets through — so this is a length check, not a "looks
 * base64-ish" check.
 */
$kh_good_challenge = rtrim(strtr(base64_encode(hash('sha256', 'a-real-verifier', true)), '+/', '-_'), '=');
KH_Test::same(43, strlen($kh_good_challenge), 'a real S256 challenge is 43 characters (the premise of the next assertion)');
KH_Test::ok(Keelhob_OAuth_Authorize::is_valid_challenge($kh_good_challenge), 'and is accepted');

foreach (
    [
        ''                                  => 'an empty challenge',
        str_repeat('a', 42)                 => 'one character short',
        str_repeat('a', 44)                 => 'one character long',
        str_repeat('a', 43) . '='           => 'a padded digest',
        str_repeat('+', 43)                 => 'standard base64 characters instead of base64url',
        str_repeat('/', 43)                 => 'a slash, which is not base64url either',
        'short'                             => 'something that is not a digest at all',
        str_repeat('a', 42) . ' '           => 'a trailing space',
    ] as $kh_bad => $kh_why
) {
    KH_Test::ok(!Keelhob_OAuth_Authorize::is_valid_challenge((string) $kh_bad), $kh_why . ' is refused');
}

KH_Test::group('OAuth authorize — a valid request validates');

kh_test_reset_options(['keelhob_settings' => [Keelhob_OAuth::SETTING => true]]);

$kh_reg = Keelhob_OAuth_Clients::register([
    'client_name'   => 'Claude',
    'redirect_uris' => ['https://claude.ai/cb', 'http://127.0.0.1:7777/cb'],
]);
$kh_cid = (string) $kh_reg['client_id'];

/** Build a query for read_request() to read. */
$kh_query = static function (array $over = []) use ($kh_cid, $kh_good_challenge): void {
    $_GET = array_merge([
        'client_id'             => $kh_cid,
        'response_type'         => 'code',
        'redirect_uri'          => 'https://claude.ai/cb',
        'code_challenge'        => $kh_good_challenge,
        'code_challenge_method' => 'S256',
        'state'                 => 'abc123',
    ], $over);
};

$kh_query();
$kh_ok = Keelhob_OAuth_Authorize::read_request();
KH_Test::ok(!is_wp_error($kh_ok), 'a complete, correct request validates');
KH_Test::same($kh_cid, (string) $kh_ok['client_id'], 'and carries the client id through');
KH_Test::same('https://claude.ai/cb', (string) $kh_ok['redirect_uri'], 'and the redirect URI');
KH_Test::same($kh_good_challenge, (string) $kh_ok['challenge'], 'and the challenge');
KH_Test::same('abc123', (string) $kh_ok['state'], 'and the state, which the client uses to match its own request');

KH_Test::group('OAuth authorize — requests that must be refused before a human is asked');

/*
 * Each case names the rule it is testing. `$expect` is the error code; the
 * thing that matters as much is that a WP_Error is returned AT ALL, because
 * the handler renders those and redirects nothing.
 */
foreach (
    [
        ['over' => ['client_id' => 'khc_does_not_exist'], 'expect' => 'unknown_client',
         'why'  => 'an unregistered client — there is nothing to approve'],
        ['over' => ['client_id' => ''], 'expect' => 'unknown_client',
         'why'  => 'an empty client_id'],

        ['over' => ['response_type' => 'token'], 'expect' => 'unsupported_response_type',
         'why'  => 'the implicit flow, which would put a token in a URL'],
        ['over' => ['response_type' => ''], 'expect' => 'unsupported_response_type',
         'why'  => 'a missing response_type'],

        ['over' => ['redirect_uri' => 'https://evil.test/cb'], 'expect' => 'invalid_redirect_uri',
         'why'  => 'rule 2: a redirect_uri the client never registered'],
        ['over' => ['redirect_uri' => 'https://claude.ai/cb/'], 'expect' => 'invalid_redirect_uri',
         'why'  => 'rule 2: a registered URI with a trailing slash added'],
        ['over' => ['redirect_uri' => 'https://claude.ai/cb.evil.test'], 'expect' => 'invalid_redirect_uri',
         'why'  => 'rule 2: a URI that merely starts with a registered one'],
        ['over' => ['redirect_uri' => ''], 'expect' => 'invalid_redirect_uri',
         'why'  => 'rule 2: an omitted redirect_uri when the client registered two'],

        ['over' => ['code_challenge_method' => 'plain'], 'expect' => 'invalid_request',
         'why'  => 'rule 1: PKCE downgraded to plain'],
        ['over' => ['code_challenge_method' => ''], 'expect' => 'invalid_request',
         'why'  => 'rule 1: no challenge method at all'],
        ['over' => ['code_challenge_method' => 's256'], 'expect' => 'invalid_request',
         'why'  => 'rule 1: the method is matched case-sensitively, as the spec writes it'],
        ['over' => ['code_challenge' => ''], 'expect' => 'invalid_request',
         'why'  => 'rule 1: S256 claimed with no challenge supplied'],
        ['over' => ['code_challenge' => 'tooshort'], 'expect' => 'invalid_request',
         'why'  => 'rule 1: a challenge that cannot be a digest'],
    ] as $kh_case
) {
    $kh_query($kh_case['over']);
    $kh_res = Keelhob_OAuth_Authorize::read_request();
    KH_Test::ok(is_wp_error($kh_res), $kh_case['why'] . ' is refused');
    if (is_wp_error($kh_res)) {
        KH_Test::same($kh_case['expect'], $kh_res->get_error_code(), '  ... with code ' . $kh_case['expect']);
    }
}

/*
 * The refusal message is shown to the SITE OWNER, on screen, so it has to read
 * as an explanation rather than as an OAuth error code. This asserts the
 * redirect_uri case specifically, because that is the one where the owner most
 * needs to understand that nothing happened.
 */
$kh_query(['redirect_uri' => 'https://evil.test/cb']);
$kh_msg = (string) Keelhob_OAuth_Authorize::read_request()->get_error_message();
KH_Test::contains('never registered', $kh_msg, 'the redirect_uri refusal says what is wrong in words');
KH_Test::contains('you have not been redirected anywhere', $kh_msg, 'and says explicitly that nobody was sent anywhere');

KH_Test::group('OAuth authorize — a client with ONE registered URI may omit it');

$kh_single = Keelhob_OAuth_Clients::register([
    'client_name'   => 'Single',
    'redirect_uris' => ['https://single.test/cb'],
]);
$_GET = [
    'client_id'             => (string) $kh_single['client_id'],
    'response_type'         => 'code',
    'redirect_uri'          => '',
    'code_challenge'        => $kh_good_challenge,
    'code_challenge_method' => 'S256',
];
$kh_one = Keelhob_OAuth_Authorize::read_request();
KH_Test::ok(!is_wp_error($kh_one), 'omitting redirect_uri validates when exactly one is registered');
KH_Test::same(
    'https://single.test/cb',
    (string) $kh_one['redirect_uri'],
    'and the value used comes from the REGISTRATION, never from the request'
);

KH_Test::group('OAuth authorize — the consent screen says what it is really granting');

/*
 * THE SENTENCE THE WHOLE FEATURE RESTS ON.
 *
 * Keelhob can run arbitrary PHP. A consent screen that says "this app would
 * like to access your site" has told the owner nothing they can act on, so the
 * screen has to say whether code execution is on, and say it differently when
 * it is.
 */
kh_test_reset_options([
    'keelhob_settings' => [
        Keelhob_OAuth::SETTING => true,
        'capabilities'         => ['code_exec' => true],
    ],
]);
$kh_alarm = Keelhob_OAuth_Authorize::consent_summary();
KH_Test::same('alarm', $kh_alarm['tone'], 'with code execution ON the summary is an alarm, not a reassurance');
KH_Test::contains('any PHP or SQL', $kh_alarm['text'], 'and says in plain words that the app can run any code');
KH_Test::contains('delete anything', $kh_alarm['text'], 'including that it can delete things');

kh_test_reset_options([
    'keelhob_settings' => [
        Keelhob_OAuth::SETTING => true,
        'capabilities'         => ['code_exec' => false],
    ],
]);
$kh_calm = Keelhob_OAuth_Authorize::consent_summary();
KH_Test::same('calm', $kh_calm['tone'], 'with code execution OFF the summary is calm');
KH_Test::ok(
    strpos($kh_calm['text'], 'any PHP') === false,
    'and does NOT claim the app can run any code'
);
KH_Test::contains('and nothing else', $kh_calm['text'], 'it bounds what the app may use');

/*
 * The count in the calm sentence has to be the real number of groups that are
 * on. A hardcoded number would read as true and drift the first time a group
 * is added — which is exactly how test-doc-counts.php came to confirm 81 tools
 * against a registry holding 85.
 */
$kh_expected_on = 0;
foreach (array_keys(Keelhob_Capabilities::groups()) as $kh_group) {
    if (Keelhob_Capabilities::is_group_enabled($kh_group)) {
        $kh_expected_on++;
    }
}
KH_Test::ok($kh_expected_on > 0, 'some groups are on (the premise of the next assertion)');
KH_Test::contains(
    (string) $kh_expected_on,
    $kh_calm['text'],
    'the summary states the real number of switched-on areas, counted from the fence'
);

/* And on a site where code execution is off, that number must exclude it. */
KH_Test::ok(
    !Keelhob_Capabilities::is_group_enabled('code_exec'),
    'code_exec really is off in this fixture'
);

$_GET = [];
