<?php

/**
 * The security baseline — the automatic half of skills/security-standard.md,
 * moved into site_readiness so rule 9 actually runs it.
 *
 * Every case here is a real finding from a real site, not an invented one. The
 * headline is A4: on keelhob.com an agent closed /?author=N and the REST users
 * route, then stopped — and left /wp-sitemap-users-1.xml serving the admin
 * login, with robots.txt advertising the sitemap index that links to it. Two
 * doors shut, one open, and the fact leaked anyway. So each door is asserted
 * independently, and "two of three closed" is asserted to still be a finding.
 */

declare(strict_types=1);

KH_Test::group('Security baseline — A1 file editor');

KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_file_editor(true, false),
    'DISALLOW_FILE_EDIT alone is enough'
);
KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_file_editor(false, true),
    'so is a host that blocks file mods'
);
$f = Keelhob_Tools_Readiness::analyze_file_editor(false, false);
KH_Test::same(1, count($f), 'neither one set is a finding');
KH_Test::same('file-editor-enabled', $f[0]['id'], 'and it is the file editor finding');

KH_Test::group('Security baseline — A3 guessable administrator logins');

$admin_row = fn(string $login, string $role = 'administrator') => ['login' => $login, 'roles' => [$role]];

KH_Test::same(
    1,
    count(Keelhob_Tools_Readiness::analyze_admin_usernames([$admin_row('admin')], 'example.com')),
    '"admin" is reported'
);
KH_Test::same(
    1,
    count(Keelhob_Tools_Readiness::analyze_admin_usernames([$admin_row('example')], 'example.com')),
    'so is the site name itself — the second guess every scanner makes'
);
KH_Test::same(
    1,
    count(Keelhob_Tools_Readiness::analyze_admin_usernames([$admin_row('example')], 'www.example.com')),
    'and the www. prefix does not hide it'
);
KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_admin_usernames([$admin_row('rhiannon-p')], 'example.com'),
    'a real name is not a finding'
);
// A subscriber called "admin" cannot install anything, so it is not this risk.
KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_admin_usernames([$admin_row('admin', 'subscriber')], 'example.com'),
    'only administrators count — a subscriber named admin is not this finding'
);
// A two-letter domain would ban a common word for everyone on it.
KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_admin_usernames([$admin_row('me')], 'me.com'),
    'a very short domain word is not treated as a banned login'
);

KH_Test::group('Security baseline — A4 user enumeration, all three doors');

$users_json = '[{"id":1,"name":"Caleb","slug":"calflint"}]';

$all_open = Keelhob_Tools_Readiness::analyze_user_enumeration(
    'https://example.com/author/calflint/',
    200,
    $users_json,
    200
);
KH_Test::same(3, count($all_open), 'all three doors open is three separate findings');
KH_Test::same(
    ['user-enumeration-author-archive', 'user-enumeration-rest-users', 'user-enumeration-users-sitemap'],
    array_column($all_open, 'id'),
    'named so a partial fix cannot be mistaken for a whole one'
);

// THE REGRESSION. This is the exact state keelhob.com was left in: the two
// obvious doors closed, the sitemap still serving the login. If this ever
// returns [] again, the bug is back.
$sitemap_only = Keelhob_Tools_Readiness::analyze_user_enumeration(null, 404, '', 200);
KH_Test::same(1, count($sitemap_only), 'closing ?author=N and the REST route is NOT enough');
KH_Test::same(
    'user-enumeration-users-sitemap',
    $sitemap_only[0]['id'],
    'the users sitemap is still reported on its own'
);
KH_Test::same('high', $sitemap_only[0]['severity'], 'and at the same severity as the other two');

KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_user_enumeration(null, 404, '', 404),
    'all three closed is clean'
);
KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_user_enumeration(null, 401, $users_json, 404),
    'a 401 on the users route is closed, whatever the body says'
);
// An empty collection is what a correctly filtered route returns.
KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_user_enumeration(null, 200, '[]', 404),
    'a 200 with no users in it is not a leak'
);
// A themed 404 page can be long; length must never be what decides.
KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_user_enumeration(null, 404, str_repeat('x', 5000), 404),
    'a large themed 404 body is not mistaken for user data'
);

KH_Test::group('Security baseline — A5 HTTPS enforced');

KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_https('https://example.com', 'https://example.com', 301, 'https://example.com/'),
    'https everywhere, http redirects: clean'
);
$http_site = Keelhob_Tools_Readiness::analyze_https('http://example.com', 'http://example.com', null, null);
KH_Test::same(2, count($http_site), 'both siteurl and home are reported');
KH_Test::same('blocker', $http_site[0]['severity'], 'and a plain-http site address is a blocker');

$not_redirected = Keelhob_Tools_Readiness::analyze_https('https://example.com', 'https://example.com', 200, 'http://example.com/');
KH_Test::same(1, count($not_redirected), 'answering on http without redirecting is a finding');
KH_Test::same('http-not-redirected', $not_redirected[0]['id'], 'named for what it is');

KH_Test::group('Security baseline — A8 keys and salts');

$good = [];
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $i => $c) {
    $good[$c] = str_repeat((string) chr(97 + $i), 64);
}
KH_Test::same([], Keelhob_Tools_Readiness::analyze_salts($good), 'unique long salts are clean');
KH_Test::same([], Keelhob_Tools_Readiness::analyze_salts([]), 'no constants defined is skipped, not failed');

$placeholder = $good;
$placeholder['AUTH_KEY'] = 'put your unique phrase here';
KH_Test::same(
    1,
    count(Keelhob_Tools_Readiness::analyze_salts($placeholder)),
    'the sample-file placeholder is caught'
);
KH_Test::same('blocker', Keelhob_Tools_Readiness::analyze_salts($placeholder)[0]['severity'], 'as a blocker');

// The case a per-value length check passes and should not: all set, all long,
// all the same string. Every cookie on the site is forgeable.
$identical = array_fill_keys(array_keys($good), str_repeat('z', 64));
KH_Test::same(
    1,
    count(Keelhob_Tools_Readiness::analyze_salts($identical)),
    'eight long salts that are all the same string is still broken'
);

KH_Test::group('Security baseline — A9 debug exposure');

KH_Test::same([], Keelhob_Tools_Readiness::analyze_debug_exposure(false, false, 404), 'debug off, no log: clean');
KH_Test::same(
    'debug-display-on',
    Keelhob_Tools_Readiness::analyze_debug_exposure(true, true, 404)[0]['id'],
    'errors printed to visitors is the worse of the two'
);
KH_Test::same(
    'debug-mode-on',
    Keelhob_Tools_Readiness::analyze_debug_exposure(true, false, 404)[0]['id'],
    'debug on but not displayed is the milder one'
);
$log = Keelhob_Tools_Readiness::analyze_debug_exposure(false, false, 200);
KH_Test::same('debug-log-public', $log[0]['id'], 'a downloadable debug.log is reported');
KH_Test::same('blocker', $log[0]['severity'], 'and it is a blocker — those logs carry paths and queries');

KH_Test::group('Security baseline — A11/A18 directory listing');

KH_Test::same(
    1,
    count(Keelhob_Tools_Readiness::analyze_directory_listing([
        '/wp-content/uploads/' => [200, '<html><head><title>Index of /wp-content/uploads</title></head>'],
    ])),
    'a server-generated index is reported'
);
// A themed index.php full of links is the false positive to avoid.
KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_directory_listing([
        '/wp-content/uploads/' => [200, '<html><title>Our Shop</title><a href="/a">a</a><a href="/b">b</a>'],
    ]),
    'a real page that happens to have links is not a directory listing'
);
KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_directory_listing(['/wp-content/uploads/' => [403, '']]),
    'a 403 is the directory being closed'
);

KH_Test::group('Security baseline — A13 version disclosure');

KH_Test::same([], Keelhob_Tools_Readiness::analyze_version_disclosure(403, 403, '<html></html>'), 'both blocked, no meta: clean');
$disclosed = Keelhob_Tools_Readiness::analyze_version_disclosure(
    200,
    200,
    '<html><head><meta name="generator" content="WordPress 6.9.1"></head></html>'
);
KH_Test::same(3, count($disclosed), 'readme.html, license.txt and the generator tag are three findings');
KH_Test::same(
    ['low', 'low', 'low'],
    array_column($disclosed, 'severity'),
    'all low — this is scanner bait, not a hole, and calling it worse would train people to ignore the list'
);

KH_Test::group('Security baseline — A17 XML-RPC is for the owner to decide');

// 405 is what a LIVE xmlrpc.php answers a GET with: "POST only". Reading 4xx
// as "closed" is how this check passes on a wide-open site, and the first cut
// of this analyzer did exactly that — caught here, not on someone's site.
$xr = Keelhob_Tools_Readiness::analyze_xmlrpc(405, true);
KH_Test::same(1, count($xr), '405 means the endpoint is ALIVE and answering, not blocked');
KH_Test::same('observation', $xr[0]['severity'], 'reported, but it does not block a launch');
KH_Test::ok($xr[0]['owner_decision'], 'and flagged as the owner decision, not something to switch off uninvited');

KH_Test::same(1, count(Keelhob_Tools_Readiness::analyze_xmlrpc(200, true)), '200 is alive too');
KH_Test::same([], Keelhob_Tools_Readiness::analyze_xmlrpc(403, true), 'a 403 is the server blocking it');
KH_Test::same([], Keelhob_Tools_Readiness::analyze_xmlrpc(404, true), 'a 404 is the file being gone');
KH_Test::same([], Keelhob_Tools_Readiness::analyze_xmlrpc(null, true), 'unreachable is not a claim either way');

// The common real case: a hardening snippet turns the filter off while the
// file keeps answering 405. Reporting that would be a false positive.
KH_Test::same(
    [],
    Keelhob_Tools_Readiness::analyze_xmlrpc(405, false),
    'the file answering while xmlrpc_enabled is false is hardened, not open'
);

KH_Test::group('Security baseline — it does not block a launch on its own');

// An owner_decision observation must never be what fails a site, or agents
// will start switching XML-RPC off to make the gate go green.
$summary = Keelhob_Tools_Readiness::summarize(Keelhob_Tools_Readiness::analyze_xmlrpc(405, true), [], []);
KH_Test::ok($summary['pass'], 'a site whose only finding is open XML-RPC still passes');
