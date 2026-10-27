<?php
/**
 * OAuth discovery — off by default, and honest about what it supports.
 *
 * WHY THIS IS A TEST.
 *
 * Discovery is the only part of the OAuth server that answers an
 * unauthenticated stranger. Two things about it can be wrong in a way that no
 * other test would notice:
 *
 *   1. It could be ON when the owner never switched it on. That advertises an
 *      authorization server on every Keelhob site in the world.
 *   2. It could advertise something the server does not actually enforce. A
 *      client is entitled to use anything listed in this document, so listing
 *      `plain` next to `S256`, or `token` next to `code`, turns "PKCE is
 *      required" into "PKCE is available". The advertisement IS the policy.
 *
 * Both are silent. Neither shows up on a screen.
 */

KH_Test::group('OAuth — the switch, and what absent means');

kh_test_reset_options();

KH_Test::ok(!Keelhob_OAuth::enabled(), 'OAuth is OFF when the owner has never said anything');

/*
 * Every falsy stored value means off. Unlike a capability group there is no
 * "never said" state worth preserving here, so this does NOT use
 * array_key_exists and these all have to agree.
 */
foreach ([false, 0, '', '0', null] as $kh_i => $kh_falsy) {
    kh_test_reset_options(['keelhob_settings' => [Keelhob_OAuth::SETTING => $kh_falsy]]);
    KH_Test::ok(
        !Keelhob_OAuth::enabled(),
        sprintf('a stored %s means off', var_export($kh_falsy, true))
    );
}

kh_test_reset_options(['keelhob_settings' => [Keelhob_OAuth::SETTING => true]]);
KH_Test::ok(Keelhob_OAuth::enabled(), 'and only an explicit true means on');

KH_Test::group('OAuth — the authorization server document');

$kh_as = Keelhob_OAuth::authorization_server_metadata();

/* Rule 1, as advertised. This is the assertion that keeps PKCE required. */
KH_Test::same(
    ['S256'],
    $kh_as['code_challenge_methods_supported'],
    'S256 is the ONLY challenge method advertised — listing plain would make PKCE optional'
);
KH_Test::ok(
    !in_array('plain', $kh_as['code_challenge_methods_supported'], true),
    'and plain is explicitly not there'
);

/* The implicit flow is gone in OAuth 2.1 and returns a token in a URL. */
KH_Test::same(['code'], $kh_as['response_types_supported'], 'code is the only response type');
KH_Test::ok(
    !in_array('token', $kh_as['response_types_supported'], true),
    'the implicit flow is not offered'
);
KH_Test::same(
    ['authorization_code', 'refresh_token'],
    $kh_as['grant_types_supported'],
    'and only those two grants'
);
KH_Test::ok(
    !in_array('password', $kh_as['grant_types_supported'], true),
    'the password grant is not offered — it would put the owner\'s WordPress password in a client'
);

/* The issuer is compared byte for byte by a client, so a stray slash is a bug. */
KH_Test::same(
    rtrim(Keelhob_OAuth::issuer(), '/'),
    Keelhob_OAuth::issuer(),
    'the issuer carries no trailing slash'
);
KH_Test::same(Keelhob_OAuth::issuer(), $kh_as['issuer'], 'and the document uses it unchanged');

/* Every endpoint it names has to be a real absolute URL. A relative one looks
 * fine in the document and fails in the client. */
foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint'] as $kh_ep) {
    KH_Test::ok(
        strpos((string) $kh_as[$kh_ep], 'https://') === 0 || strpos((string) $kh_as[$kh_ep], 'http://') === 0,
        $kh_ep . ' is an absolute URL'
    );
}
KH_Test::contains('action=keelhob_authorize', (string) $kh_as['authorization_endpoint'], 'authorize goes through admin-post.php, so WordPress owns the login round-trip');
KH_Test::contains('/oauth/token', (string) $kh_as['token_endpoint'], 'the token endpoint is the REST route');
KH_Test::contains('/oauth/register', (string) $kh_as['registration_endpoint'], 'and so is registration');

KH_Test::group('OAuth — the protected resource document');

$kh_pr = Keelhob_OAuth::protected_resource_metadata();

KH_Test::contains('/mcp', (string) $kh_pr['resource'], 'the protected resource is the MCP endpoint itself');
KH_Test::same([Keelhob_OAuth::issuer()], $kh_pr['authorization_servers'], 'which points back at this site as its own authorization server');
KH_Test::same(['header'], $kh_pr['bearer_methods_supported'], 'the token travels in a header, never in a query string where it would land in access logs');
KH_Test::same([Keelhob_OAuth::SCOPE], $kh_pr['scopes_supported'], 'one scope');
KH_Test::same([Keelhob_OAuth::SCOPE], $kh_as['scopes_supported'], 'and both documents agree on it');

KH_Test::group('OAuth — path matching tolerates the suffix clients really send');

/*
 * RFC 9728 tells a client to append the resource path to the well-known path,
 * and real clients do. Matching on equality would 404 every one of them, which
 * is the difference between connecting and not — so this pins "contains".
 */
$kh_paths = [
    '/.well-known/oauth-protected-resource'                        => true,
    '/.well-known/oauth-protected-resource/wp-json/keelhob/v1/mcp' => true,
    '/.well-known/oauth-authorization-server'                      => true,
    '/.well-known/oauth-authorization-server/wp-json/keelhob/v1'   => true,
    '/.well-known/openid-configuration'                            => true,
    '/wp-json/keelhob/v1/mcp'                                      => false,
    '/wp-admin/admin.php'                                          => false,
    '/'                                                            => false,
];
foreach ($kh_paths as $kh_path => $kh_should) {
    $kh_hit = strpos($kh_path, '/.well-known/') !== false;
    KH_Test::ok(
        $kh_hit === $kh_should,
        sprintf('%s %s a discovery path', $kh_path, $kh_should ? 'is' : 'is not')
    );
}

/* request_path() drops the query string. A client that appends `?foo=1` must
 * still be recognised, and the value must never carry the query into a
 * comparison. */
$_SERVER['REQUEST_URI'] = '/.well-known/oauth-protected-resource?probe=1';
KH_Test::same('/.well-known/oauth-protected-resource', Keelhob_OAuth::request_path(), 'request_path() strips the query string');
$_SERVER['REQUEST_URI'] = '/.well-known/oauth-protected-resource';
KH_Test::same('/.well-known/oauth-protected-resource', Keelhob_OAuth::request_path(), 'and leaves a bare path alone');
unset($_SERVER['REQUEST_URI']);
KH_Test::same('', Keelhob_OAuth::request_path(), 'and is empty when there is no request at all (WP-CLI, cron)');

KH_Test::group('OAuth — hashing and minting');

/* Rule 3: only hashes are stored, and the comparison is constant-time. */
$kh_secret = Keelhob_OAuth::mint('kha_');
KH_Test::ok(strpos($kh_secret, 'kha_') === 0, 'a minted token carries its prefix');
KH_Test::same(4 + 48, strlen($kh_secret), 'and 48 hex characters after it');
KH_Test::ok(ctype_xdigit(substr($kh_secret, 4)), 'which really are hex');

KH_Test::same(
    Keelhob_OAuth::mint('kha_') === Keelhob_OAuth::mint('kha_'),
    false,
    'two mints are never the same value'
);

$kh_stored = Keelhob_OAuth::hash($kh_secret);
KH_Test::same(64, strlen($kh_stored), 'the stored form is a 64-character sha256 hex digest');
KH_Test::ok($kh_stored !== $kh_secret, 'and is not the secret itself');
KH_Test::ok(strpos($kh_stored, $kh_secret) === false, 'nor does it contain it');

KH_Test::ok(Keelhob_OAuth::hash_matches($kh_secret, $kh_stored), 'the real secret matches its hash');
KH_Test::ok(!Keelhob_OAuth::hash_matches($kh_secret . 'x', $kh_stored), 'a secret with one extra character does not');
KH_Test::ok(!Keelhob_OAuth::hash_matches(substr($kh_secret, 0, -1), $kh_stored), 'nor one with a character missing');

/*
 * The empty-string cases, which are the ones that turn a comparison into a
 * skipped check. A client presenting no secret against a client with no stored
 * secret must NOT come out as a match.
 */
KH_Test::ok(!Keelhob_OAuth::hash_matches('', $kh_stored), 'an empty presented secret never matches');
KH_Test::ok(!Keelhob_OAuth::hash_matches($kh_secret, ''), 'and an empty stored hash never matches either');
KH_Test::ok(!Keelhob_OAuth::hash_matches('', ''), 'including when both are empty');
