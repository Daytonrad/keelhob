<?php
/**
 * The updater, and the exact shape of what it tells the world about a site.
 *
 * The payload assertion below is the important one, and it is written as an
 * exact match rather than a "contains" on purpose. PRIVACY.md promises a
 * CLOSED list to people who are being asked to let an AI run their site. A
 * helpful future addition — the site URL "just for debugging", the admin email
 * "so we can warn them" — would break that promise silently, and a permissive
 * test would wave it through.
 *
 * If this test fails because you added a field: the fix is not to update the
 * test. It is to update PRIVACY.md, the readme, and the settings screen first,
 * and then decide whether you still want to.
 *
 * The list was four fields until the project needed to answer more than "how
 * many installs" — whether anyone returns after week one, which of the eight
 * risky capability groups owners actually enable, whether the PHP floor can
 * move. It grew to eleven, and it grew through the front door: the three
 * documents above were rewritten first and this test last, in that order,
 * exactly as the paragraph above demands. The alternative on the table was
 * putting an analytics service inside a plugin that already has more access to
 * a site than almost anything else installed on it, which is a trade this
 * project is not willing to make.
 *
 * What did NOT change is the rule every field obeys: it has to be true of a
 * thousand other sites. The assertions below name, one by one, the fields a
 * future change is most likely to reach for — the URL, the admin email, an API
 * key, the owner's spend — and refuse each of them explicitly, so that adding
 * one is a deliberate act rather than an oversight.
 */

KH_Test::group('Updater — exactly four things are sent, and nothing else');

kh_test_reset_options();
delete_transient(Keelhob_Updater::TRANSIENT);
$GLOBALS['kh_test_http'] = [
    ['code' => 200, 'body' => '{"version":"2.1.0","package":"https://example.test/keelhob.zip"}', 'headers' => []],
];

Keelhob_Updater::latest(true);

$url = (string) ($GLOBALS['kh_test_http_last']['url'] ?? '');
KH_Test::contains('api.keelhob.com/v1/version', $url, 'the check-in goes to the project endpoint');
KH_Test::ok(strpos($url, '/wp-json/') === false, 'and not to the marketing site, which never had this route');

$query = [];
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

KH_Test::same(['ai', 'builder', 'caps', 'locale', 'multisite', 'php', 'runs', 'site_id', 'version', 'woo', 'wp'], (function () use ($query) {
    $keys = array_keys($query);
    sort($keys);
    return $keys;
})(), 'the payload is exactly this closed list — nothing was added');

// Every field is a fact about the software, never about the site. These are the
// ones a "helpful" future change would reach for first, so each is named.
foreach (['url', 'home', 'domain', 'site_url', 'email', 'admin_email', 'user', 'name', 'title', 'key', 'api_key', 'token', 'ip'] as $forbidden) {
    KH_Test::ok(!array_key_exists($forbidden, $query), 'the payload does not carry ' . $forbidden);
}

// Spend is the owner's business. `runs` says something happened; how much it
// cost them and how many tokens it burned is not the project's to collect.
foreach (['cost', 'cost_usd', 'tokens', 'spend'] as $forbidden) {
    KH_Test::ok(!array_key_exists($forbidden, $query), 'the payload does not carry ' . $forbidden);
}

// caps carries group NAMES so the project can see which risky groups owners
// actually switch on. It must never carry the tools inside them, which would
// start describing what one specific site can do.
KH_Test::ok(
    preg_match('/^[a-z0-9_,.-]*$/', (string) ($query['caps'] ?? '')) === 1,
    'caps is a plain list of group keys'
);
KH_Test::ok(strpos((string) ($query['caps'] ?? ''), 'execute_php') === false, 'caps names groups, never individual tools');

// Booleans stay booleans. A field that can carry free text is a field that can
// one day carry something identifying without the shape of the payload changing.
foreach (['multisite', 'woo', 'ai'] as $flag) {
    KH_Test::ok(in_array((string) ($query[$flag] ?? ''), ['0', '1'], true), $flag . ' is 1 or 0, never free text');
}
KH_Test::ok(ctype_digit((string) ($query['runs'] ?? '')), 'runs is a plain count');

KH_Test::same(KEELHOB_VERSION, $query['version'] ?? '', 'the plugin version is sent');
KH_Test::same(36, strlen((string) ($query['site_id'] ?? '')), 'site_id is a UUID');

// The point of the ID: it identifies an install for de-duplication and nothing
// more. Derived from the URL it would be a fingerprint, reversible by anyone
// with a list of WordPress sites — which is everyone.
$home = 'https://example.test';
$id   = (string) $query['site_id'];
KH_Test::ok(strpos($id, 'example') === false, 'site_id is not derived from the site address');
KH_Test::ok($id !== md5($home) && $id !== hash('sha256', $home), 'site_id is not a hash of the site address either');

KH_Test::group('Updater — the ID is stable, so a site is counted once');

$first  = Keelhob_Updater::site_id();
$second = Keelhob_Updater::site_id();
KH_Test::same($first, $second, 'the same site keeps the same ID across calls');

KH_Test::group('Updater — opting out costs you nothing');

kh_test_reset_options();
Keelhob::instance()->update(['skip_checkin' => true]);
delete_transient(Keelhob_Updater::TRANSIENT);
$GLOBALS['kh_test_http'] = [
    ['code' => 200, 'body' => '{"tag_name":"v2.1.0","html_url":"https://example.test/rel","assets":[{"name":"keelhob.zip","browser_download_url":"https://example.test/keelhob.zip"}]}', 'headers' => []],
];

$latest = Keelhob_Updater::latest(true);
$url    = (string) ($GLOBALS['kh_test_http_last']['url'] ?? '');

KH_Test::contains('api.github.com', $url, 'opting out talks to GitHub directly');
KH_Test::ok(strpos($url, 'site_id') === false, 'and sends no ID');
KH_Test::ok(strpos($url, 'keelhob.com') === false, 'and never touches the counting endpoint');

// The part that makes it an opt-out rather than a punishment.
KH_Test::same('2.1.0', $latest['version'] ?? '', 'an opted-out site still finds the update');
KH_Test::same('https://example.test/keelhob.zip', $latest['package'] ?? '', 'and still gets a download link');

KH_Test::group('Updater — reading both answer shapes');

KH_Test::same('2.1.0', Keelhob_Updater::parse(['version' => 'v2.1.0', 'package' => 'x.zip'])['version'] ?? '', 'a leading v is stripped');
KH_Test::same(null, Keelhob_Updater::parse(['version' => '2.1.0']), 'no package means no update offered');
KH_Test::same(null, Keelhob_Updater::parse([]), 'an empty answer is not an update');
KH_Test::same(null, Keelhob_Updater::parse(['tag_name' => 'v2.1.0', 'assets' => [['name' => 'notes.txt', 'browser_download_url' => 'x']]]), 'a release with no zip asset offers nothing');

KH_Test::group('Updater — failure is silent');

kh_test_reset_options();
delete_transient(Keelhob_Updater::TRANSIENT);
$GLOBALS['kh_test_http'] = [['code' => 503, 'body' => 'down', 'headers' => []]];
KH_Test::same(null, Keelhob_Updater::latest(true), 'a dead endpoint returns nothing rather than throwing');

// A site that cannot reach the internet must not get a broken update screen,
// and must certainly not get one because of a counter.
$transient = (object) ['response' => []];
$out = Keelhob_Updater::inject($transient);
KH_Test::same([], (array) $out->response, 'and no phantom update is offered');

KH_Test::group('Updater — an older release is not an update');

kh_test_reset_options();
delete_transient(Keelhob_Updater::TRANSIENT);
// 0.0.1 rather than a plausible-looking "1.9.0": the harness pins
// KEELHOB_VERSION to its own fixture value, so any literal close to the real
// version would assert something about the fixture instead of about the
// comparison, and would silently start passing for the wrong reason.
$older = '0.0.1';
$GLOBALS['kh_test_http'] = [
    ['code' => 200, 'body' => '{"version":"' . $older . '","package":"https://example.test/old.zip"}', 'headers' => []],
];
Keelhob_Updater::latest(true);
$out = Keelhob_Updater::inject((object) ['response' => []]);
KH_Test::same([], (array) $out->response, 'a release older than what is installed is ignored');

KH_Test::group('Updater — a dead counting endpoint never costs you an update');

// The failure this guards against was real: keelhob.com answered 404 for
// the version route, so every site that had NOT opted out silently received no
// updates at all. Failure in this file is deliberately silent, which is correct
// for a counter and catastrophic for a security fix. The counter is the
// project's convenience; the update is the site owner's due.

kh_test_reset_options();
delete_transient(Keelhob_Updater::TRANSIENT);
$GLOBALS['kh_test_http'] = [
    ['code' => 404, 'body' => '{"code":"rest_no_route"}', 'headers' => []],
    ['code' => 200, 'body' => '{"tag_name":"v2.1.0","html_url":"https://example.test/rel","assets":[{"name":"keelhob.zip","browser_download_url":"https://example.test/keelhob.zip"}]}', 'headers' => []],
];

$latest = Keelhob_Updater::latest(true);

KH_Test::same('2.1.0', $latest['version'] ?? '', 'a 404 from the counting endpoint falls through to GitHub');
KH_Test::same('https://example.test/keelhob.zip', $latest['package'] ?? '', 'and the download link still arrives');
KH_Test::contains('api.github.com', (string) ($GLOBALS['kh_test_http_last']['url'] ?? ''), 'the second request went to GitHub');

KH_Test::group('Updater — the count is still tried first');

kh_test_reset_options();
delete_transient(Keelhob_Updater::TRANSIENT);
$seen = [];
$GLOBALS['kh_test_http'] = [
    ['code' => 500, 'body' => 'boom', 'headers' => []],
    ['code' => 200, 'body' => '{"tag_name":"v2.1.0","assets":[{"name":"keelhob.zip","browser_download_url":"https://example.test/c.zip"}]}', 'headers' => []],
];
Keelhob_Updater::latest(true);
// Only the fallback's URL survives in _last, so the proof that the count was
// attempted is that TWO responses were consumed from a queue of two.
KH_Test::same(0, count($GLOBALS['kh_test_http']), 'both endpoints were called, so the check-in was attempted before the fallback');

KH_Test::group('Updater — an unreadable 200 also falls through');

// The nastier shape of the same bug: the endpoint is alive and returns 200,
// but with a body that carries no release. Treating that as "no update
// available" would be indistinguishable from being up to date.
kh_test_reset_options();
delete_transient(Keelhob_Updater::TRANSIENT);
$GLOBALS['kh_test_http'] = [
    ['code' => 200, 'body' => '{"nonsense":true}', 'headers' => []],
    ['code' => 200, 'body' => '{"version":"2.1.0","package":"https://example.test/c.zip"}', 'headers' => []],
];
KH_Test::same('2.1.0', Keelhob_Updater::latest(true)['version'] ?? '', 'a 200 with no release in it is treated as a failure, not as "up to date"');

KH_Test::group('Updater — when both are down, it is still silent');

kh_test_reset_options();
delete_transient(Keelhob_Updater::TRANSIENT);
$GLOBALS['kh_test_http'] = [
    ['code' => 503, 'body' => 'down', 'headers' => []],
    ['code' => 503, 'body' => 'down', 'headers' => []],
];
KH_Test::same(null, Keelhob_Updater::latest(true), 'two dead endpoints return nothing rather than throwing');
KH_Test::same([], (array) Keelhob_Updater::inject((object) ['response' => []])->response, 'and offer no phantom update');

KH_Test::group('Updater — opting out makes exactly one request');

// An opted-out site must not be quietly re-counted by a fallback that runs the
// other way round.
kh_test_reset_options();
Keelhob::instance()->update(['skip_checkin' => true]);
delete_transient(Keelhob_Updater::TRANSIENT);
$GLOBALS['kh_test_http'] = [
    ['code' => 200, 'body' => '{"tag_name":"v2.1.0","assets":[{"name":"c.zip","browser_download_url":"https://example.test/c.zip"}]}', 'headers' => []],
    ['code' => 200, 'body' => '{"version":"9.9.9","package":"https://example.test/x.zip"}', 'headers' => []],
];
Keelhob_Updater::latest(true);
KH_Test::same(1, count($GLOBALS['kh_test_http']), 'only one request was made, so an opted-out site is never sent to the counter');

KH_Test::group('Updater — a current site still tells WordPress it supports updates');

// The bug this guards against was found on keelhob.com, which had Keelhob 2.1.1
// installed and 2.1.1 available. inject() returned the transient untouched, and
// that silence has a meaning in core that is not "nothing to do":
//
//   class-wp-plugins-list-table.php:215  update-supported => true   if in response[]
//   class-wp-plugins-list-table.php:217  update-supported => true   if in no_update[]
//   class-wp-plugins-list-table.php:219  update-supported => false  otherwise
//   class-wp-plugins-list-table.php:294  $enabled = ... && $plugin_data['update-supported']
//
// A plugin in neither list is read as one that cannot be updated at all, so the
// "Enable auto-updates" link is not rendered for it. On the live site Keelhob
// was the ONLY plugin without that toggle while two others had it.
//
// Updates themselves were never broken: a newer version still populated
// response[]. What was impossible was UNATTENDED updating, on a plugin whose
// whole job is handing an AI full control of a site. That is the wrong thing to
// make people remember to do by hand.

kh_test_reset_options();
delete_transient(Keelhob_Updater::TRANSIENT);
// Exactly the installed version: the "nothing to do" case.
$GLOBALS['kh_test_http'] = [
    ['code' => 200, 'body' => '{"version":"' . KEELHOB_VERSION . '","package":"https://example.test/same.zip","url":"https://example.test/rel"}', 'headers' => []],
];
Keelhob_Updater::latest(true);
$out = Keelhob_Updater::inject((object) ['response' => [], 'no_update' => []]);

KH_Test::same([], (array) $out->response, 'an identical version is not offered as an update');
KH_Test::ok(
    isset($out->no_update[KEELHOB_BASENAME]),
    'but the plugin IS listed in no_update, which is what keeps the auto-update toggle on screen'
);
KH_Test::same(
    KEELHOB_VERSION,
    $out->no_update[KEELHOB_BASENAME]->new_version ?? '',
    'and it reports the version it actually has'
);
KH_Test::same(
    KEELHOB_BASENAME,
    $out->no_update[KEELHOB_BASENAME]->plugin ?? '',
    'keyed and labelled by basename, because core looks it up by that'
);

// An older release is the same situation from core's point of view: there is
// nothing to install, and the plugin still supports updating.
kh_test_reset_options();
delete_transient(Keelhob_Updater::TRANSIENT);
$GLOBALS['kh_test_http'] = [
    ['code' => 200, 'body' => '{"version":"0.0.1","package":"https://example.test/old.zip"}', 'headers' => []],
];
Keelhob_Updater::latest(true);
$out = Keelhob_Updater::inject((object) ['response' => [], 'no_update' => []]);
KH_Test::ok(
    isset($out->no_update[KEELHOB_BASENAME]),
    'an older release upstream also leaves the plugin marked as update-supported'
);

// Unreachable is different and must stay silent. A site with no network must
// not be told anything about its update state, in either direction.
kh_test_reset_options();
delete_transient(Keelhob_Updater::TRANSIENT);
$GLOBALS['kh_test_http'] = [
    ['code' => 503, 'body' => 'down', 'headers' => []],
    ['code' => 503, 'body' => 'down', 'headers' => []],
];
$out = Keelhob_Updater::inject((object) ['response' => [], 'no_update' => []]);
KH_Test::same([], (array) $out->response, 'two dead endpoints offer no update');
KH_Test::same([], (array) $out->no_update, 'and claim nothing about no_update either, because nothing is known');
