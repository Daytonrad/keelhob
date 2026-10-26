<?php
/**
 * PKCE verification, and the rules the token endpoint enforces.
 *
 * WHY THIS IS A TEST.
 *
 * `verifier_matches()` is the function that makes "PKCE required" mean
 * anything. If it can be satisfied without possessing the verifier, every other
 * protection in the OAuth feature is decoration — an attacker who intercepts an
 * authorization code redeems it and gets a token that can run PHP on the site.
 *
 * The length floor is the part implementations leave out, and the reason it
 * gets left out is that it looks redundant: a one-character verifier hashes
 * perfectly well and compares perfectly well, so the code "works". It proves
 * nothing, which is why the spec sets a floor of 43 characters and why this
 * file asserts it.
 *
 * The replay behaviour — a code dying on first use whatever the outcome — is
 * sequencing inside the request handler rather than a pure function, so it is
 * asserted in tests/rig/check-oauth.mjs against the real endpoint. The comment
 * in class-oauth-token.php says why that ordering is what it is.
 */

KH_Test::group('OAuth token — PKCE: the verifier must really hash to the challenge');

/** Make a matching verifier/challenge pair the way a real client does. */
$kh_pair = static function (string $verifier): array {
    return [
        $verifier,
        rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
    ];
};

[$kh_v, $kh_c] = $kh_pair(str_repeat('a', 43));
KH_Test::ok(Keelhob_OAuth_Token::verifier_matches($kh_v, $kh_c), 'a matching pair at the minimum length verifies');

[$kh_v128, $kh_c128] = $kh_pair(str_repeat('b', 128));
KH_Test::ok(Keelhob_OAuth_Token::verifier_matches($kh_v128, $kh_c128), 'and at the maximum length');

/* A realistic client verifier: base64url of 32 random bytes. */
$kh_real = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
[$kh_rv, $kh_rc] = $kh_pair($kh_real);
KH_Test::ok(Keelhob_OAuth_Token::verifier_matches($kh_rv, $kh_rc), 'and for a real 43-character random verifier');

KH_Test::group('OAuth token — PKCE failures');

KH_Test::ok(
    !Keelhob_OAuth_Token::verifier_matches(str_repeat('a', 43), $kh_c128),
    'a verifier that does not hash to the challenge is refused'
);
KH_Test::ok(
    !Keelhob_OAuth_Token::verifier_matches($kh_v . 'x', $kh_c),
    'one extra character breaks the match'
);
KH_Test::ok(
    !Keelhob_OAuth_Token::verifier_matches('', $kh_c),
    'an empty verifier never matches'
);

/*
 * THE CASE THAT MAKES THE WHOLE THING REAL.
 *
 * An empty challenge must never verify. If it did, a code stored without a
 * challenge — which is what a bug in the authorize step would produce — would
 * be redeemable by anyone with no verifier at all. "No challenge stored" has to
 * be a refusal, not a free pass.
 */
[$kh_ev, $kh_ec] = $kh_pair(str_repeat('z', 43));
KH_Test::ok(!Keelhob_OAuth_Token::verifier_matches($kh_ev, ''), 'an EMPTY stored challenge refuses even a well-formed verifier');
KH_Test::ok(!Keelhob_OAuth_Token::verifier_matches('', ''), 'and two empties are not a match');

/*
 * The length floor. Each of these hashes fine and compares fine — the only
 * thing wrong with them is that they prove nothing. Without the floor, every
 * one of these would PASS.
 */
foreach ([1, 10, 42] as $kh_short) {
    [$kh_sv, $kh_sc] = $kh_pair(str_repeat('a', $kh_short));
    KH_Test::ok(
        !Keelhob_OAuth_Token::verifier_matches($kh_sv, $kh_sc),
        sprintf('a %d-character verifier is refused even though it hashes to its own challenge', $kh_short)
    );
}
[$kh_lv, $kh_lc] = $kh_pair(str_repeat('a', 129));
KH_Test::ok(!Keelhob_OAuth_Token::verifier_matches($kh_lv, $kh_lc), 'and a 129-character verifier is over the ceiling');

/* The spec's character set. Anything outside it is a client doing something
 * unexpected, and unexpected is not what you want at an auth endpoint. */
foreach (['+', '/', '=', ' ', "\n", '%', '#'] as $kh_bad_char) {
    $kh_bv = str_repeat('a', 42) . $kh_bad_char;
    [$kh_x, $kh_bc] = $kh_pair($kh_bv);
    KH_Test::ok(
        !Keelhob_OAuth_Token::verifier_matches($kh_bv, $kh_bc),
        sprintf('a verifier containing %s is refused', $kh_bad_char === "\n" ? 'a newline' : '"' . $kh_bad_char . '"')
    );
}

KH_Test::group('OAuth token — the TTLs are what the plan says');

KH_Test::same(3600, Keelhob_OAuth_Token::ACCESS_TTL, 'access tokens last one hour');
KH_Test::same(7776000, Keelhob_OAuth_Token::REFRESH_TTL, 'refresh tokens last ninety days');
KH_Test::same(600, Keelhob_OAuth_Authorize::CODE_TTL, 'authorization codes last ten minutes');
KH_Test::ok(
    Keelhob_OAuth_Authorize::CODE_TTL < Keelhob_OAuth_Token::ACCESS_TTL,
    'and a code is shorter-lived than the token it buys'
);

KH_Test::group('OAuth token — every stored name is a hash, never the credential');

/*
 * Rule 3, asserted against the actual transient names. The reference
 * implementation puts the authorization code INTO the transient name, which
 * writes the secret to the options table in plaintext.
 */
$kh_code  = bin2hex(random_bytes(32));
$kh_name  = Keelhob_OAuth_Authorize::CODE_PREFIX . Keelhob_OAuth::hash($kh_code);
KH_Test::ok(strpos($kh_name, $kh_code) === false, 'the code transient name does NOT contain the code');
KH_Test::contains(Keelhob_OAuth::hash($kh_code), $kh_name, 'it contains the hash of it');
KH_Test::ok(strpos($kh_name, 'keelhob_') === 0, 'and is prefixed keelhob_ so uninstall.php collects it');

$kh_at = Keelhob_OAuth::mint(Keelhob_OAuth_Token::ACCESS_TOKEN_PREFIX);
$kh_an = Keelhob_OAuth_Token::ACCESS_PREFIX . Keelhob_OAuth::hash($kh_at);
KH_Test::ok(strpos($kh_an, $kh_at) === false, 'the access-token transient name does not contain the token');
KH_Test::ok(strpos($kh_an, 'keelhob_') === 0, 'and is also prefixed keelhob_');

/* WordPress option names are capped at 191 characters, and a transient name
 * gains `_transient_timeout_` in front of it. A name that overflows is a token
 * that silently never stores. */
KH_Test::ok(
    strlen('_transient_timeout_' . $kh_an) <= 191,
    'the longest derived option name still fits in wp_options.option_name'
);

KH_Test::group('OAuth token — an access token is only accepted while everything still holds');

kh_test_reset_options(['keelhob_settings' => [Keelhob_OAuth::SETTING => true]]);

KH_Test::same(0, Keelhob_OAuth_Token::user_for_access_token(''), 'an empty token belongs to nobody');
KH_Test::same(0, Keelhob_OAuth_Token::user_for_access_token('kh_not_an_oauth_token'), 'the Connect screen bearer token is not an OAuth token');
KH_Test::same(0, Keelhob_OAuth_Token::user_for_access_token('kha_never_issued'), 'a token that was never issued belongs to nobody');

/*
 * "Off" has to mean off. A token minted while OAuth was on must stop working
 * the moment the owner switches it off — otherwise switching it off does
 * nothing for an hour, which is exactly when an owner switches it off.
 */
kh_test_reset_options();
KH_Test::same(
    0,
    Keelhob_OAuth_Token::user_for_access_token(Keelhob_OAuth::mint(Keelhob_OAuth_Token::ACCESS_TOKEN_PREFIX)),
    'with OAuth switched off, no access token is accepted at all'
);

KH_Test::group('OAuth token — rule 4 is re-checked, not remembered');

kh_test_reset_options(['keelhob_settings' => [Keelhob_OAuth::SETTING => true]]);

KH_Test::ok(!Keelhob_OAuth_Token::is_still_admin(0), 'user 0 is not an administrator');
KH_Test::ok(!Keelhob_OAuth_Token::is_still_admin(-1), 'nor is a negative id');
KH_Test::ok(!Keelhob_OAuth_Token::is_still_admin(999999), 'nor is a user that does not exist');

KH_Test::group('OAuth token — the lockout hook exists and is narrow');

/*
 * Rule 5. The token endpoint has to be able to report a failure into the same
 * counter the MCP route uses, or it is an unlimited guessing oracle sitting
 * next to a carefully rate-limited endpoint.
 *
 * It must be able to report one and nothing else: a public reset would let the
 * endpoint that is being attacked clear its own lockout.
 */
KH_Test::ok(
    method_exists('Keelhob_Auth', 'note_oauth_failure'),
    'Keelhob_Auth exposes a way for the token endpoint to record a failure'
);
KH_Test::ok(
    !method_exists('Keelhob_Auth', 'record_failure')
        || !(new ReflectionMethod('Keelhob_Auth', 'record_failure'))->isPublic(),
    'but record_failure() itself stays private'
);
