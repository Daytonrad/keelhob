<?php
/**
 * OAuth clients: the redirect-URI rules, and the eviction policy.
 *
 * WHY THIS IS A TEST, AND WHY IT IS MOSTLY A LIST OF REJECTIONS.
 *
 * `is_valid_redirect_uri()` is the open-redirect surface of the whole OAuth
 * feature. Every entry in the rejection list below is a real bypass that looks
 * like a URL to a casual reader, and a validator that accepts any one of them
 * is a validator that will send a signed-in administrator to an attacker with
 * an authorization code in the query string.
 *
 * A test that only checks the happy path would pass against a function that
 * returns `true` for everything. So the bulk of this file asserts what must be
 * refused, each case named after the trick it is.
 */

KH_Test::group('OAuth clients — redirect URIs that must be ACCEPTED');

kh_test_reset_options(['keelhob_settings' => [Keelhob_OAuth::SETTING => true]]);

foreach (
    [
        'https://claude.ai/api/mcp/auth_callback'  => 'a normal https callback',
        'https://example.test/cb?x=1'              => 'with a query string',
        'https://example.test:8443/cb'             => 'on a non-standard port',
        'http://127.0.0.1:41234/callback'          => 'http on 127.0.0.1 — how every desktop AI client works',
        'http://localhost:3000/oauth'              => 'http on localhost',
        'http://[::1]:8080/cb'                     => 'http on IPv6 loopback',

        /*
         * This one was written into the rejection list below and failed, and
         * the TEST was wrong. `localhost.evil.test` is only a trick against the
         * http exemption — over https it is an ordinary public host, the code
         * is encrypted in transit, and refusing it would refuse any customer
         * whose callback host happens to start with those nine letters. The
         * http spelling of it IS refused, and is asserted below.
         */
        'https://localhost.evil.test/cb'           => 'a public https host that happens to begin with "localhost"',
    ] as $kh_uri => $kh_why
) {
    KH_Test::ok(Keelhob_OAuth_Clients::is_valid_redirect_uri($kh_uri), $kh_why . ' is accepted');
}

KH_Test::group('OAuth clients — redirect URIs that must be REFUSED');

foreach (
    [
        // The userinfo trick. parse_url() says the host is good.test; a browser
        // sends the user to evil.test. This one is the reason the function
        // checks the authority against the raw string as well.
        'https://evil.test@good.test/cb'
            => 'userinfo before the host (parse_url reports the WRONG host)',
        'https://user:pass@good.test/cb'
            => 'userinfo with a password',

        // A fragment. The code is appended as a query parameter, and fragments
        // are read by script in the page rather than sent to a server.
        'https://good.test/cb#frag'      => 'a fragment',
        'https://good.test/cb#'          => 'an empty fragment',

        // Schemes that are URLs, parse cleanly, and execute.
        'javascript:alert(1)'            => 'the javascript: scheme',
        'data:text/html,<script>1</script>' => 'the data: scheme',
        'vbscript:msgbox'                => 'the vbscript: scheme',
        'file:///etc/passwd'             => 'the file: scheme',
        'myapp://callback'               => 'a custom scheme (legitimate elsewhere, no caller here)',

        // http anywhere but loopback. The authorization code travels in this URL.
        'http://example.test/cb'         => 'http on a public host',
        'http://192.168.1.10/cb'         => 'http on a LAN address that is not loopback',

        // "localhost" as a substring of a public domain.
        'http://localhost.evil.test/cb'  => 'a public host that merely CONTAINS localhost',

        // Malformed authorities that still parse.
        'https:///cb'                    => 'no host at all',
        'https:/evil.test/cb'            => 'one slash instead of two',
        'https:evil.test/cb'             => 'no slashes at all',
        '//evil.test/cb'                 => 'a protocol-relative URL with no scheme',
        '/cb'                            => 'a bare path',
        'cb'                             => 'not a URL',
        ''                               => 'an empty string',
        '   '                            => 'whitespace only',
    ] as $kh_bad => $kh_trick
) {
    KH_Test::ok(
        !Keelhob_OAuth_Clients::is_valid_redirect_uri($kh_bad),
        $kh_trick . ' is refused'
    );
}

/*
 * Control characters, separately, because the failure they cause is not an open
 * redirect but response splitting: a newline inside a value that reaches a
 * Location header ends the header and starts writing the body.
 */
foreach (["\n", "\r", "\t", "\0", ' '] as $kh_ctrl) {
    KH_Test::ok(
        !Keelhob_OAuth_Clients::is_valid_redirect_uri('https://good.test/cb' . $kh_ctrl . 'x'),
        sprintf('a URI containing chr(%d) is refused', ord($kh_ctrl))
    );
}

KH_Test::ok(
    !Keelhob_OAuth_Clients::is_valid_redirect_uri('https://good.test/' . str_repeat('a', 3000)),
    'an absurdly long URI is refused rather than stored'
);

KH_Test::group('OAuth clients — loopback is matched exactly, never by substring');

foreach (['localhost', '127.0.0.1', '::1', '[::1]'] as $kh_host) {
    KH_Test::ok(Keelhob_OAuth_Clients::is_loopback_host($kh_host), $kh_host . ' is loopback');
}
foreach (['localhost.evil.test', 'notlocalhost', '127.0.0.1.evil.test', 'example.test', ''] as $kh_host) {
    KH_Test::ok(!Keelhob_OAuth_Clients::is_loopback_host($kh_host), ($kh_host === '' ? '(empty)' : $kh_host) . ' is NOT loopback');
}

KH_Test::group('OAuth clients — rule 2: the registered URI matches exactly');

$kh_client = [
    'client_id'     => 'khc_test',
    'redirect_uris' => ['https://good.test/cb', 'http://127.0.0.1:9000/cb'],
];

KH_Test::ok(Keelhob_OAuth_Clients::redirect_uri_allowed($kh_client, 'https://good.test/cb'), 'the exact registered URI is allowed');
KH_Test::ok(Keelhob_OAuth_Clients::redirect_uri_allowed($kh_client, 'http://127.0.0.1:9000/cb'), 'and so is the second one');

/* Every one of these would be allowed by prefix matching, normalising, or
 * ignoring a trailing slash. All three are how an open redirect gets shipped. */
foreach (
    [
        'https://good.test/cb.evil.test' => 'a URI that merely STARTS WITH a registered one',
        'https://good.test/cb/'          => 'a trailing slash',
        'https://good.test/cb?x=1'       => 'an extra query string',
        'https://good.test/CB'           => 'a different case',
        'https://good.test:443/cb'       => 'an explicit default port',
        'https://evil.test/cb'           => 'a different host entirely',
        'http://good.test/cb'            => 'the same URI downgraded to http',
    ] as $kh_near => $kh_why
) {
    KH_Test::ok(
        !Keelhob_OAuth_Clients::redirect_uri_allowed($kh_client, $kh_near),
        $kh_why . ' is refused'
    );
}

/* A client with two registered URIs may not omit the parameter. */
KH_Test::ok(
    !Keelhob_OAuth_Clients::redirect_uri_allowed($kh_client, ''),
    'omitting redirect_uri is refused when the client registered more than one'
);
KH_Test::ok(
    Keelhob_OAuth_Clients::redirect_uri_allowed(['redirect_uris' => ['https://only.test/cb']], ''),
    'and allowed when it registered exactly one — the value still comes from the registration'
);
KH_Test::ok(
    !Keelhob_OAuth_Clients::redirect_uri_allowed(['redirect_uris' => []], 'https://good.test/cb'),
    'a client with no registered URIs matches nothing'
);

/* resolve_redirect_uri() must hand back the REGISTERED string, so that what is
 * sent to a browser is never the attacker-supplied spelling of it. */
KH_Test::same(
    'https://good.test/cb',
    Keelhob_OAuth_Clients::resolve_redirect_uri($kh_client, 'https://good.test/cb'),
    'the resolved URI is the registered one'
);
KH_Test::same('', Keelhob_OAuth_Clients::resolve_redirect_uri($kh_client, 'https://evil.test/cb'), 'and is empty when nothing matches');
KH_Test::same(
    'https://only.test/cb',
    Keelhob_OAuth_Clients::resolve_redirect_uri(['redirect_uris' => ['https://only.test/cb']], ''),
    'an omitted parameter resolves to the single registered URI'
);

KH_Test::group('OAuth clients — registering');

kh_test_reset_options(['keelhob_settings' => [Keelhob_OAuth::SETTING => true]]);

$kh_reg = Keelhob_OAuth_Clients::register([
    'client_name'   => 'Claude',
    'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
]);
KH_Test::ok(!is_wp_error($kh_reg), 'a well-formed registration succeeds');
KH_Test::ok(strpos((string) $kh_reg['client_id'], Keelhob_OAuth_Clients::ID_PREFIX) === 0, 'the client_id carries its prefix');
KH_Test::same('none', $kh_reg['token_endpoint_auth_method'], 'a client that asks for nothing is a public client');
KH_Test::ok(!isset($kh_reg['client_secret']), 'and gets no secret, because it could not keep one');

$kh_stored = Keelhob_OAuth_Clients::get((string) $kh_reg['client_id']);
KH_Test::ok(is_array($kh_stored), 'the client is stored');
KH_Test::same('', (string) $kh_stored['secret_hash'], 'with no secret hash');
KH_Test::same(0, (int) $kh_stored['last_used'], 'and last_used at 0 until a token is issued');

/* A confidential client gets a secret exactly once, and only the hash is kept. */
$kh_conf = Keelhob_OAuth_Clients::register([
    'client_name'                => 'A server',
    'redirect_uris'              => ['https://server.test/cb'],
    'token_endpoint_auth_method' => 'client_secret_post',
]);
KH_Test::ok(isset($kh_conf['client_secret']), 'a confidential client is issued a secret');
$kh_conf_row = Keelhob_OAuth_Clients::get((string) $kh_conf['client_id']);
KH_Test::ok((string) $kh_conf_row['secret_hash'] !== '', 'a hash is stored');
KH_Test::ok(
    strpos(wp_json_encode($kh_conf_row), (string) $kh_conf['client_secret']) === false,
    'and the secret itself appears NOWHERE in the stored row'
);
KH_Test::ok(
    Keelhob_OAuth::hash_matches((string) $kh_conf['client_secret'], (string) $kh_conf_row['secret_hash']),
    'but the hash does verify the secret that was handed out'
);

/* The name is shown to the owner on the consent screen, so it is sanitised. */
$kh_evil = Keelhob_OAuth_Clients::register([
    'client_name'   => '<script>alert(1)</script>Nice App',
    'redirect_uris' => ['https://nice.test/cb'],
]);
KH_Test::ok(
    strpos((string) $kh_evil['client_name'], '<script') === false,
    'a client name carrying a script tag is sanitised before it is stored'
);

$kh_unnamed = Keelhob_OAuth_Clients::register(['redirect_uris' => ['https://unnamed.test/cb']]);
KH_Test::same('An AI client', (string) $kh_unnamed['client_name'], 'a client with no name gets a readable placeholder, not an empty pill');

KH_Test::group('OAuth clients — registrations that must be refused');

foreach (
    [
        ['body' => [],                                               'why' => 'no redirect_uris at all'],
        ['body' => ['redirect_uris' => []],                          'why' => 'an empty redirect_uris array'],
        ['body' => ['redirect_uris' => 'https://good.test/cb'],       'why' => 'redirect_uris as a string instead of an array'],
        ['body' => ['redirect_uris' => [123]],                       'why' => 'a non-string inside the array'],
        ['body' => ['redirect_uris' => ['http://evil.test/cb']],      'why' => 'an http URI on a public host'],
        ['body' => ['redirect_uris' => ['https://good.test/cb', 'javascript:1']], 'why' => 'one bad URI among several good ones'],
        ['body' => ['redirect_uris' => ['https://a.test/1', 'https://a.test/2', 'https://a.test/3', 'https://a.test/4', 'https://a.test/5', 'https://a.test/6']], 'why' => 'more than five redirect_uris'],
        ['body' => ['redirect_uris' => ['https://good.test/cb'], 'token_endpoint_auth_method' => 'private_key_jwt'], 'why' => 'an auth method this server does not implement'],
    ] as $kh_case
) {
    $kh_res = Keelhob_OAuth_Clients::register($kh_case['body']);
    KH_Test::ok(is_wp_error($kh_res), $kh_case['why'] . ' is refused');
}

KH_Test::group('OAuth clients — registration is refused entirely while OAuth is off');

kh_test_reset_options();
$kh_off = Keelhob_OAuth_Clients::register(['redirect_uris' => ['https://good.test/cb']]);
KH_Test::ok(is_wp_error($kh_off), 'with the switch off, nothing registers');
KH_Test::same([], Keelhob_OAuth_Clients::all(), 'and nothing is written to the option');

KH_Test::group('OAuth clients — the cap never evicts a client that is in use');

/*
 * THE REFERENCE IMPLEMENTATION DROPS THE OLDEST CLIENT. Registration needs no
 * credential, so that is a denial of service: fill the list and the owner's
 * real client is evicted, their AI stops connecting, and nothing on screen
 * explains it. These assertions are the whole reason make_room() exists.
 */
$kh_full = [];
for ($kh_i = 0; $kh_i < Keelhob_OAuth_Clients::MAX_CLIENTS; $kh_i++) {
    $kh_full['khc_spam' . $kh_i] = [
        'client_id'  => 'khc_spam' . $kh_i,
        'created_at' => 1000 + $kh_i,
        'last_used'  => 0,
    ];
}
/* The owner's real client: oldest of all, and in use. */
$kh_full['khc_real'] = ['client_id' => 'khc_real', 'created_at' => 1, 'last_used' => 2000];
unset($kh_full['khc_spam0']); // keep the count at MAX

$kh_room = Keelhob_OAuth_Clients::make_room($kh_full);
KH_Test::ok(!is_wp_error($kh_room), 'room is made when unused clients exist');
KH_Test::ok(isset($kh_room['khc_real']), 'and the OLDEST client survives, because it is in use');
KH_Test::ok(!isset($kh_room['khc_spam1']), 'the oldest UNUSED client is the one dropped');
KH_Test::same(Keelhob_OAuth_Clients::MAX_CLIENTS - 1, count($kh_room), 'leaving room for exactly one more');

/* And when every client is in use, registration is refused rather than one of
 * them being broken. */
$kh_all_used = [];
for ($kh_i = 0; $kh_i < Keelhob_OAuth_Clients::MAX_CLIENTS; $kh_i++) {
    $kh_all_used['khc_u' . $kh_i] = ['client_id' => 'khc_u' . $kh_i, 'created_at' => 1000 + $kh_i, 'last_used' => 5000];
}
KH_Test::ok(
    is_wp_error(Keelhob_OAuth_Clients::make_room($kh_all_used)),
    'when every client is in use, a new registration is REFUSED rather than evicting a working one'
);

/* Below the cap, nothing is touched at all. */
$kh_two = ['a' => ['created_at' => 1, 'last_used' => 0], 'b' => ['created_at' => 2, 'last_used' => 0]];
KH_Test::same($kh_two, Keelhob_OAuth_Clients::make_room($kh_two), 'below the cap the list is returned unchanged');
