<?php
/**
 * The updater, and the exact shape of what it tells the world about a site.
 *
 * The payload assertion below is the important one, and it is written as an
 * exact match rather than a "contains" on purpose. PRIVACY.md promises a
 * closed list of four fields to people who are being asked to let an AI run
 * their site. A helpful future addition — the site URL "just for debugging",
 * the admin email "so we can warn them" — would break that promise silently,
 * and a permissive test would wave it through.
 *
 * If this test fails because you added a field: the fix is not to update the
 * test. It is to update PRIVACY.md, the readme, and the settings screen first,
 * and then decide whether you still want to.
 */

CP_Test::group('Updater — exactly four things are sent, and nothing else');

cp_test_reset_options();
delete_transient(Claudpress_Updater::TRANSIENT);
$GLOBALS['cp_test_http'] = [
    ['code' => 200, 'body' => '{"version":"2.1.0","package":"https://example.test/claudpress.zip"}', 'headers' => []],
];

Claudpress_Updater::latest(true);

$url = (string) ($GLOBALS['cp_test_http_last']['url'] ?? '');
CP_Test::contains('claudpress.com', $url, 'the check-in goes to the project endpoint');

$query = [];
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

CP_Test::same(['php', 'site_id', 'version', 'wp'], (function () use ($query) {
    $keys = array_keys($query);
    sort($keys);
    return $keys;
})(), 'the payload is exactly site_id, version, wp and php — nothing was added');

CP_Test::same(CLAUDPRESS_VERSION, $query['version'] ?? '', 'the plugin version is sent');
CP_Test::same(36, strlen((string) ($query['site_id'] ?? '')), 'site_id is a UUID');

// The point of the ID: it identifies an install for de-duplication and nothing
// more. Derived from the URL it would be a fingerprint, reversible by anyone
// with a list of WordPress sites — which is everyone.
$home = 'https://example.test';
$id   = (string) $query['site_id'];
CP_Test::ok(strpos($id, 'example') === false, 'site_id is not derived from the site address');
CP_Test::ok($id !== md5($home) && $id !== hash('sha256', $home), 'site_id is not a hash of the site address either');

CP_Test::group('Updater — the ID is stable, so a site is counted once');

$first  = Claudpress_Updater::site_id();
$second = Claudpress_Updater::site_id();
CP_Test::same($first, $second, 'the same site keeps the same ID across calls');

CP_Test::group('Updater — opting out costs you nothing');

cp_test_reset_options();
Claudpress::instance()->update(['skip_checkin' => true]);
delete_transient(Claudpress_Updater::TRANSIENT);
$GLOBALS['cp_test_http'] = [
    ['code' => 200, 'body' => '{"tag_name":"v2.1.0","html_url":"https://example.test/rel","assets":[{"name":"claudpress.zip","browser_download_url":"https://example.test/claudpress.zip"}]}', 'headers' => []],
];

$latest = Claudpress_Updater::latest(true);
$url    = (string) ($GLOBALS['cp_test_http_last']['url'] ?? '');

CP_Test::contains('api.github.com', $url, 'opting out talks to GitHub directly');
CP_Test::ok(strpos($url, 'site_id') === false, 'and sends no ID');
CP_Test::ok(strpos($url, 'claudpress.com') === false, 'and never touches the counting endpoint');

// The part that makes it an opt-out rather than a punishment.
CP_Test::same('2.1.0', $latest['version'] ?? '', 'an opted-out site still finds the update');
CP_Test::same('https://example.test/claudpress.zip', $latest['package'] ?? '', 'and still gets a download link');

CP_Test::group('Updater — reading both answer shapes');

CP_Test::same('2.1.0', Claudpress_Updater::parse(['version' => 'v2.1.0', 'package' => 'x.zip'])['version'] ?? '', 'a leading v is stripped');
CP_Test::same(null, Claudpress_Updater::parse(['version' => '2.1.0']), 'no package means no update offered');
CP_Test::same(null, Claudpress_Updater::parse([]), 'an empty answer is not an update');
CP_Test::same(null, Claudpress_Updater::parse(['tag_name' => 'v2.1.0', 'assets' => [['name' => 'notes.txt', 'browser_download_url' => 'x']]]), 'a release with no zip asset offers nothing');

CP_Test::group('Updater — failure is silent');

cp_test_reset_options();
delete_transient(Claudpress_Updater::TRANSIENT);
$GLOBALS['cp_test_http'] = [['code' => 503, 'body' => 'down', 'headers' => []]];
CP_Test::same(null, Claudpress_Updater::latest(true), 'a dead endpoint returns nothing rather than throwing');

// A site that cannot reach the internet must not get a broken update screen,
// and must certainly not get one because of a counter.
$transient = (object) ['response' => []];
$out = Claudpress_Updater::inject($transient);
CP_Test::same([], (array) $out->response, 'and no phantom update is offered');

CP_Test::group('Updater — an older release is not an update');

cp_test_reset_options();
delete_transient(Claudpress_Updater::TRANSIENT);
// 0.0.1 rather than a plausible-looking "1.9.0": the harness pins
// CLAUDPRESS_VERSION to its own fixture value, so any literal close to the real
// version would assert something about the fixture instead of about the
// comparison, and would silently start passing for the wrong reason.
$older = '0.0.1';
$GLOBALS['cp_test_http'] = [
    ['code' => 200, 'body' => '{"version":"' . $older . '","package":"https://example.test/old.zip"}', 'headers' => []],
];
Claudpress_Updater::latest(true);
$out = Claudpress_Updater::inject((object) ['response' => []]);
CP_Test::same([], (array) $out->response, 'a release older than what is installed is ignored');

CP_Test::group('Updater — a dead counting endpoint never costs you an update');

// The failure this guards against was real: claudpress.com answered 404 for
// the version route, so every site that had NOT opted out silently received no
// updates at all. Failure in this file is deliberately silent, which is correct
// for a counter and catastrophic for a security fix. The counter is the
// project's convenience; the update is the site owner's due.

cp_test_reset_options();
delete_transient(Claudpress_Updater::TRANSIENT);
$GLOBALS['cp_test_http'] = [
    ['code' => 404, 'body' => '{"code":"rest_no_route"}', 'headers' => []],
    ['code' => 200, 'body' => '{"tag_name":"v2.1.0","html_url":"https://example.test/rel","assets":[{"name":"claudpress.zip","browser_download_url":"https://example.test/claudpress.zip"}]}', 'headers' => []],
];

$latest = Claudpress_Updater::latest(true);

CP_Test::same('2.1.0', $latest['version'] ?? '', 'a 404 from the counting endpoint falls through to GitHub');
CP_Test::same('https://example.test/claudpress.zip', $latest['package'] ?? '', 'and the download link still arrives');
CP_Test::contains('api.github.com', (string) ($GLOBALS['cp_test_http_last']['url'] ?? ''), 'the second request went to GitHub');

CP_Test::group('Updater — the count is still tried first');

cp_test_reset_options();
delete_transient(Claudpress_Updater::TRANSIENT);
$seen = [];
$GLOBALS['cp_test_http'] = [
    ['code' => 500, 'body' => 'boom', 'headers' => []],
    ['code' => 200, 'body' => '{"tag_name":"v2.1.0","assets":[{"name":"claudpress.zip","browser_download_url":"https://example.test/c.zip"}]}', 'headers' => []],
];
Claudpress_Updater::latest(true);
// Only the fallback's URL survives in _last, so the proof that the count was
// attempted is that TWO responses were consumed from a queue of two.
CP_Test::same(0, count($GLOBALS['cp_test_http']), 'both endpoints were called, so the check-in was attempted before the fallback');

CP_Test::group('Updater — an unreadable 200 also falls through');

// The nastier shape of the same bug: the endpoint is alive and returns 200,
// but with a body that carries no release. Treating that as "no update
// available" would be indistinguishable from being up to date.
cp_test_reset_options();
delete_transient(Claudpress_Updater::TRANSIENT);
$GLOBALS['cp_test_http'] = [
    ['code' => 200, 'body' => '{"nonsense":true}', 'headers' => []],
    ['code' => 200, 'body' => '{"version":"2.1.0","package":"https://example.test/c.zip"}', 'headers' => []],
];
CP_Test::same('2.1.0', Claudpress_Updater::latest(true)['version'] ?? '', 'a 200 with no release in it is treated as a failure, not as "up to date"');

CP_Test::group('Updater — when both are down, it is still silent');

cp_test_reset_options();
delete_transient(Claudpress_Updater::TRANSIENT);
$GLOBALS['cp_test_http'] = [
    ['code' => 503, 'body' => 'down', 'headers' => []],
    ['code' => 503, 'body' => 'down', 'headers' => []],
];
CP_Test::same(null, Claudpress_Updater::latest(true), 'two dead endpoints return nothing rather than throwing');
CP_Test::same([], (array) Claudpress_Updater::inject((object) ['response' => []])->response, 'and offer no phantom update');

CP_Test::group('Updater — opting out makes exactly one request');

// An opted-out site must not be quietly re-counted by a fallback that runs the
// other way round.
cp_test_reset_options();
Claudpress::instance()->update(['skip_checkin' => true]);
delete_transient(Claudpress_Updater::TRANSIENT);
$GLOBALS['cp_test_http'] = [
    ['code' => 200, 'body' => '{"tag_name":"v2.1.0","assets":[{"name":"c.zip","browser_download_url":"https://example.test/c.zip"}]}', 'headers' => []],
    ['code' => 200, 'body' => '{"version":"9.9.9","package":"https://example.test/x.zip"}', 'headers' => []],
];
Claudpress_Updater::latest(true);
CP_Test::same(1, count($GLOBALS['cp_test_http']), 'only one request was made, so an opted-out site is never sent to the counter');
