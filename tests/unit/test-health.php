<?php
/**
 * Keelhob_Health — the front-end reachability check shared by every risky
 * write, and guard(), the entry point future tools call.
 *
 * The reachability logic (probe_home / healthy_status / verdict) used to live
 * on Keelhob_Snippets and these tests used to live in test-snippets.php —
 * moved here verbatim (only the class name and the new $subject argument
 * changed) when the check was extracted so execute_php, execute_sql,
 * manage_plugin and manage_theme could use it too.
 */

KH_Test::group('Health — front-end reachability check');

// The bug this exists for: a snippet returned 503 from the front end. It parsed,
// it ran, it never errored, and it took the site off the internet because the
// host reads a 5xx on the home page as "this server is dead". The fatal-error
// guard cannot see that. Only a real request can.
//
// These test the decision, not the request. The interesting cases are all about
// what the check is NOT allowed to conclude — a wrong deactivation disables
// working code and hides the real cause, which is worse than the bug it catches.

$ok   = fn(int $s) => ['status' => $s, 'transport_error' => ''];
$dead = fn(string $e) => ['status' => 0, 'transport_error' => $e];

// --- a healthy site needs no control request -----------------------------

foreach ([200, 201, 301, 302, 308, 399] as $status) {
    $v = Keelhob_Health::verdict($ok($status), null, 'this snippet');
    KH_Test::ok($v['ok'], "HTTP $status is a working site");
    KH_Test::ok(!$v['needs_control'], "HTTP $status needs no second request");
}

// A redirect is not a fault. Sites redirect to https, to a trailing slash, to a
// locale. Treating 301 as broken would deactivate a snippet on most of them.
$v = Keelhob_Health::verdict($ok(301), null, 'this snippet');
KH_Test::ok($v['ok'], 'a redirect is not treated as a broken site');

// --- a broken site asks for a control before blaming anyone ---------------

foreach ([500, 502, 503, 401, 403, 404] as $status) {
    $v = Keelhob_Health::verdict($ok($status), null, 'this snippet');
    KH_Test::ok(!$v['ok'], "HTTP $status is not a working site");
    KH_Test::ok($v['needs_control'], "HTTP $status triggers a control request before deactivating");
}

// --- attribution: the snippet is only blamed when switching it off helps --

$guilty = Keelhob_Health::verdict($ok(503), $ok(200), 'this snippet');
KH_Test::ok(!$guilty['ok'], 'broken with it on, fine with it off -> the snippet is the cause');
KH_Test::contains('Undone automatically', (string) $guilty['reason'], 'and the reason says so');
KH_Test::contains('503', (string) $guilty['reason'], 'the reason names the status it actually saw');
KH_Test::contains('noindex', (string) $guilty['reason'], 'and tells the agent what to do instead');

$innocent = Keelhob_Health::verdict($ok(503), $ok(503), 'this snippet');
KH_Test::ok($innocent['ok'], 'broken with it off TOO -> the snippet is not the cause, leave it alone');
KH_Test::ok(empty($innocent['reason']), 'an innocent snippet gets no deactivation reason');
KH_Test::contains('not the cause', (string) $innocent['note'], 'and the note says the site is broken by something else');

// The site being down for an unrelated reason is the case that would otherwise
// disable every snippet saved during an outage, one at a time.
$outage = Keelhob_Health::verdict($ok(502), $ok(502), 'this snippet');
KH_Test::ok($outage['ok'], 'a site down for an unrelated reason does not get its snippets disabled');

// --- inconclusive is not broken -------------------------------------------

// Plenty of hosts block loopback requests outright. If that counted as failure,
// this check would disable working snippets on every one of them — a far worse
// bug than the one it is here to catch.
$v = Keelhob_Health::verdict($dead('cURL error 7: Failed to connect'), null, 'this snippet');
KH_Test::ok($v['ok'], 'a transport error does NOT deactivate the snippet');
KH_Test::ok(!$v['needs_control'], 'and does not waste a second request');
KH_Test::contains('proves nothing', (string) $v['note'], 'and says plainly that it proved nothing');
KH_Test::contains('loopback', (string) $v['note'], 'naming the usual cause so the owner can judge it');

// A control that itself cannot connect still leaves the first failure standing:
// we saw a real 503 with the snippet on, and learned nothing with it off.
$v = Keelhob_Health::verdict($ok(503), $dead('timed out'), 'this snippet');
KH_Test::ok(!$v['ok'], 'a 503 with the snippet on is still acted on when the control cannot connect');

// --- the $subject argument reads naturally for other callers too ----------

// Callers other than snippets pass a different subject — a gerund phrase
// ("activating this plugin") rather than a noun phrase ("this snippet"). Both
// have to read naturally in the same templates.
$plugin_guilty = Keelhob_Health::verdict($ok(503), $ok(200), 'activating this plugin');
KH_Test::contains('activating this plugin caused it', (string) $plugin_guilty['reason'], 'the guilty reason names a non-snippet subject naturally');

$plugin_innocent = Keelhob_Health::verdict($ok(503), $ok(503), 'activating this plugin');
KH_Test::contains('activating this plugin is not the cause', (string) $plugin_innocent['note'], 'the innocent note names a non-snippet subject naturally');

KH_Test::group('Health — guard()');

// guard() is the entry point later tools (execute_php, execute_sql,
// manage_plugin, manage_theme) will call. Nothing wires it up yet — these
// tests cover guard() itself in isolation, the same way reachability_verdict
// was tested before verify_front_end() existed.

kh_test_reset_ajax(); // also clears the queued HTTP responses

// --- the filter switches it off entirely -----------------------------------

add_filter('keelhob_verify_health', static fn($v, $subject) => false, 10, 2);
$result = Keelhob_Health::guard(['success' => true], 'this snippet', static function () {
    KH_Test::ok(false, 'revert must not run when the filter disables the check');
});
KH_Test::same(['success' => true], $result, 'the filter switches the whole check off, no probe and no revert');
kh_test_reset_filters();

// --- a healthy probe returns the result completely untouched ---------------

kh_test_http_queue(200, '');
$result = Keelhob_Health::guard(['success' => true, 'id' => 5], 'this snippet', static function () {
    KH_Test::ok(false, 'revert must not run when the site is healthy');
});
KH_Test::same(['success' => true, 'id' => 5], $result, 'a healthy probe leaves the result exactly as it was, no annotation added');

// --- a transport error changes nothing but explains why ---------------------

$GLOBALS['kh_test_http'] = [new WP_Error('http_request_failed', 'cURL error 7: Failed to connect')];
$result = Keelhob_Health::guard(['success' => true], 'this snippet', static function () {
    KH_Test::ok(false, 'revert must not run when the probe cannot even connect');
});
KH_Test::ok(!isset($result['front_end_check']), 'a transport error does not mark the check FAILED');
KH_Test::contains('proves nothing', (string) ($result['note'] ?? ''), 'and the result explains why nothing was concluded');

// --- unhealthy with no revert: annotate and name the subject, attribute nothing --

kh_test_http_queue(503, '');
$result = Keelhob_Health::guard(['success' => true], 'that PHP', null);
KH_Test::same('FAILED', $result['front_end_check'], 'no revert available -> reported as failed');
KH_Test::same(503, $result['front_end_status'], 'the status it actually saw is included');
KH_Test::contains('HTTP 503', (string) $result['note'], 'the note names the status');
KH_Test::contains('that PHP', (string) $result['note'], 'the note names the subject responsible');
KH_Test::contains('cannot be undone automatically', (string) $result['note'], 'and says plainly that nothing was attempted');

// --- unhealthy with a revert that fixes it: stays reverted, reason given ----

kh_test_http_queue(503, ''); // first probe, broken
kh_test_http_queue(200, ''); // control, healthy once reverted
$reverted = false;
$result = Keelhob_Health::guard(['success' => true], 'activating this plugin', static function () use (&$reverted) {
    $reverted = true;
});
KH_Test::ok($reverted, 'the revert is actually called when the site is unhealthy');
KH_Test::same('FAILED', $result['front_end_check'], 'the revert fixed it, so this was the cause');
KH_Test::same(503, $result['front_end_status'], 'the status from the first probe is reported');
KH_Test::contains('Undone automatically', (string) $result['note'], 'the reason is folded into the note');
KH_Test::contains('activating this plugin caused it', (string) $result['note'], 'and names the subject');

// --- unhealthy with a revert that does NOT help: caller must restore -------

kh_test_http_queue(503, ''); // first probe, broken
kh_test_http_queue(503, ''); // control, still broken with the revert applied
$result = Keelhob_Health::guard(['success' => true, 'note' => 'Saved.'], 'this snippet', static function () {});
KH_Test::same('passed', $result['front_end_check'], 'reverting did not help, so the subject was not the cause');
KH_Test::contains('not the cause', (string) $result['note'], 'the innocent note is appended');
KH_Test::contains('Saved.', (string) $result['note'], 'appended, not overwritten — the caller\'s own note survives');
// The whole point of this field: 'passed' means "not your fault", and on its
// own an agent reads that as all clear and moves on. The site is still down.
KH_Test::same(true, $result['site_down'] ?? null, 'and site_down says the site is still broken, whoever broke it');

kh_test_http_queue(503, '');
kh_test_http_queue(200, '');
$result = Keelhob_Health::guard(['success' => true], 'this snippet', static function () {});
KH_Test::ok(!isset($result['site_down']), 'but site_down is absent when the revert actually fixed the site');

kh_test_reset_ajax();
