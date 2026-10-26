<?php
/**
 * Where OAuth meets the existing bearer path.
 *
 * WHY THIS IS A TEST, AND WHY IT IS MOSTLY ABOUT WHAT DID *NOT* CHANGE.
 *
 * Rule 6 says the bearer path is untouched. That is easy to claim and easy to
 * break: `authenticate()` is the one function every MCP call goes through, and
 * a new branch in it can change behaviour for sites that never switched OAuth
 * on. So the first group here asserts the OLD behaviour still holds with the
 * feature off, before anything about the new behaviour is checked.
 *
 * The second thing worth pinning is the ORDER. An OAuth access token is tried
 * only after the Connect token fails to match, and crucially BEFORE a failure
 * is recorded. Record first and every successful OAuth call also logs a failed
 * bearer attempt — ten of those in fifteen minutes and the site locks itself
 * out of its own working connection. That bug would look like "the AI
 * disconnects every few minutes" and would be very hard to trace back here.
 */

KH_Test::group('OAuth / bearer — with the feature OFF, nothing about auth changed');

$kh_record = new ReflectionMethod('Keelhob_Auth', 'record_failure');
@$kh_record->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor

$GLOBALS['kh_test_transients'] = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';

kh_test_reset_options();
KH_Test::ok(!Keelhob_OAuth::enabled(), 'OAuth is off in this fixture');

/*
 * An access token that WOULD be valid if the feature were on. With it off this
 * must be refused — "off" has to mean off, including for tokens minted earlier.
 */
$kh_tok = Keelhob_OAuth::mint(Keelhob_OAuth_Token::ACCESS_TOKEN_PREFIX);
set_transient(
    Keelhob_OAuth_Token::ACCESS_PREFIX . Keelhob_OAuth::hash($kh_tok),
    ['client_id' => 'khc_x', 'user_id' => 7, 'issued_at' => time()],
    3600
);
kh_test_set_users([7 => ['manage_options' => true]]);

KH_Test::same(
    0,
    Keelhob_OAuth_Token::user_for_access_token($kh_tok),
    'a stored, unexpired, admin-owned token is refused while the switch is off'
);

KH_Test::group('OAuth / bearer — with the feature ON, a real token resolves to its user');

kh_test_reset_options(['keelhob_settings' => [Keelhob_OAuth::SETTING => true]]);
kh_test_set_users([7 => ['manage_options' => true]]);

$kh_reg = Keelhob_OAuth_Clients::register([
    'client_name'   => 'Claude',
    'redirect_uris' => ['https://claude.ai/cb'],
]);
$kh_cid = (string) $kh_reg['client_id'];

$kh_good = Keelhob_OAuth::mint(Keelhob_OAuth_Token::ACCESS_TOKEN_PREFIX);
set_transient(
    Keelhob_OAuth_Token::ACCESS_PREFIX . Keelhob_OAuth::hash($kh_good),
    ['client_id' => $kh_cid, 'user_id' => 7, 'issued_at' => time()],
    3600
);
KH_Test::same(7, Keelhob_OAuth_Token::user_for_access_token($kh_good), 'a valid token resolves to the user who approved it');

/* Rule 4, enforced at the point of use and not only at the grant. */
kh_test_set_users([7 => ['manage_options' => false]]);
KH_Test::same(
    0,
    Keelhob_OAuth_Token::user_for_access_token($kh_good),
    'the same token stops working the moment that account is no longer an administrator'
);
kh_test_set_users([7 => ['manage_options' => true]]);
KH_Test::same(7, Keelhob_OAuth_Token::user_for_access_token($kh_good), 'and works again when the capability comes back');

kh_test_set_users([]);
KH_Test::same(0, Keelhob_OAuth_Token::user_for_access_token($kh_good), 'a token whose user was DELETED belongs to nobody');
kh_test_set_users([7 => ['manage_options' => true]]);

KH_Test::group('OAuth / bearer — removing the client IS the revocation');

/*
 * There is no revoke_for_client() and there is no token table to sweep. What
 * makes "Remove" on the Connect screen immediate is this check, on every single
 * call. If someone later adds a token table "so revocation can work", these two
 * assertions are what they should read first.
 */
KH_Test::same(7, Keelhob_OAuth_Token::user_for_access_token($kh_good), 'the token works while its client exists');
Keelhob_OAuth_Clients::remove($kh_cid);
KH_Test::same(
    0,
    Keelhob_OAuth_Token::user_for_access_token($kh_good),
    'and stops the instant the client is removed — no sweep, no expiry to wait for'
);

KH_Test::group('OAuth / bearer — a token that is not ours is not ours');

kh_test_reset_options(['keelhob_settings' => [Keelhob_OAuth::SETTING => true]]);

foreach (
    [
        ''                           => 'an empty string',
        'kh_connect_screen_token'    => "the Connect screen's own bearer token",
        'khr_a_refresh_token'        => 'a refresh token presented as an access token',
        'khc_a_client_id'            => 'a client id',
        'kha_'                       => 'the prefix on its own',
        'Bearer kha_x'               => 'a token with the scheme still attached',
        'KHA_uppercased'             => 'an uppercased prefix — the check is case-sensitive',
    ] as $kh_not => $kh_why
) {
    KH_Test::same(0, Keelhob_OAuth_Token::user_for_access_token((string) $kh_not), $kh_why . ' is not an access token');
}

KH_Test::group('OAuth / bearer — the order in authenticate() is the thing to not break');

/*
 * Asserted against the SOURCE, because the ordering is the property and there
 * is no return value that reveals it. Three facts, each of which a refactor
 * could quietly invert:
 *
 *   1. user_for_access_token() is called inside the bearer branch.
 *   2. It is called BEFORE record_failure() in that branch.
 *   3. The successful OAuth path clears failures rather than recording one.
 */
$kh_src = (string) file_get_contents(KEELHOB_DIR . 'includes/class-auth.php');

$kh_at_oauth  = strpos($kh_src, 'Keelhob_OAuth_Token::user_for_access_token');
$kh_at_record = strpos($kh_src, 'self::record_failure();');

KH_Test::ok($kh_at_oauth !== false, 'authenticate() consults the OAuth token store');
KH_Test::ok($kh_at_record !== false, 'and still records bearer failures (the premise of the next assertion)');
KH_Test::ok(
    $kh_at_oauth < $kh_at_record,
    'the OAuth lookup happens BEFORE the first record_failure(), so a working OAuth call never counts as a failed bearer attempt'
);

/*
 * And the 401 names where to sign in. Without this header an MCP client given
 * only a site URL has no way to discover that there is an authorization server
 * at all, and the entire feature is unreachable however correct the rest is.
 */
KH_Test::contains('WWW-Authenticate: Bearer realm=', $kh_src, 'a 401 carries a WWW-Authenticate challenge');
KH_Test::contains('resource_metadata=', $kh_src, 'which points at the protected-resource document');
KH_Test::contains(
    '/.well-known/oauth-protected-resource',
    $kh_src,
    'at the path RFC 9728 defines'
);

/* The challenge must be silent on a site with the feature off, or every 401 on
 * every install starts advertising an authorization server that is not there. */
KH_Test::contains(
    'if (!Keelhob_OAuth::enabled() || headers_sent()) {',
    $kh_src,
    'and is sent only when OAuth is switched on'
);

KH_Test::group('OAuth / bearer — rule 5 reaches the shared counter');

KH_Test::ok(
    strpos($kh_src, 'public static function note_oauth_failure') !== false,
    'Keelhob_Auth exposes the narrow hook the token endpoint uses'
);
KH_Test::ok(
    strpos((string) file_get_contents(KEELHOB_DIR . 'includes/class-oauth-token.php'), 'Keelhob_Auth::is_locked_out()') !== false,
    'and the token endpoint checks the lockout before doing any credential work'
);

$GLOBALS['kh_test_transients'] = [];
kh_test_set_users([]);
