<?php
/**
 * site_readiness can present a credential — to this site, and nowhere else.
 *
 * The gap it closes: a site behind a coming-soon gate, maintenance mode or an
 * HTTP-auth wall answers every URL with one holding page, so an audit sees one
 * page nine times. Since 1.0.3 it says so honestly instead of describing the
 * holding page as if it were the site — but honest and blind is still blind,
 * and most sites are in that state for most of their build.
 *
 * THE ORIGIN CHECK IS THE WHOLE SECURITY ARGUMENT, so it is the thing pinned
 * hardest here.
 *
 * `urls` already accepts arbitrary absolute URLs, so this tool can already be
 * aimed at an address on the host's internal network. Attaching
 * caller-supplied headers to those requests would turn a read-only auditor
 * into something that presents credentials to an internal service — a real
 * escalation of a weakness that already exists. Restricted to this site's own
 * origin it grants nothing new: the caller is asking us to show the site its
 * own cookie, which is precisely what their browser does.
 */

// Files load alphabetically, so this one runs before test-site-readiness.php
// and cannot rely on that file having loaded the class.
require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-readiness.php';

CP_Test::group('site_readiness — request headers reach this site only');

$home = 'https://example.test';
// home_url() reads this global, not an option. An earlier test file in the
// same process will have set it to something else, and the origin check below
// is meaningless if "our own host" is whatever the last test left behind.
$GLOBALS['cp_test_home_url'] = $home;
cp_test_reset_options([]);

// request_args() is public for the same reason resolve_legal_pages() and
// Snippets::resolve_target() are: the decision it makes is the thing worth
// pinning, and reaching it through reflection would test the same logic with
// more machinery and less clarity.
$args_for = static function (string $url): array {
    return Claudpress_Tools_Readiness::request_args($url);
};

Claudpress_Tools_Readiness::set_request_headers(['Cookie' => 'cp_preview=secret-value']);

$ours = $args_for($home . '/some-page');
CP_Test::same('cp_preview=secret-value', $ours['headers']['Cookie'] ?? null, 'a header is sent when the URL is on this site, which is what lets a gated site be audited at all');

$theirs = $args_for('https://internal.example.invalid/admin');
CP_Test::same([], (array) ($theirs['headers'] ?? []), 'the same header is NOT sent to another host — `urls` already accepts any absolute URL, and this must not become a way to hand credentials to it');

$other_port_same_host = $args_for('https://example.test:8443/x');
CP_Test::same('cp_preview=secret-value', $other_port_same_host['headers']['Cookie'] ?? null, 'the check is on host, not the whole URL, so a port or a path does not defeat it');

$scheme = $args_for('http://example.test/x');
CP_Test::same('cp_preview=secret-value', $scheme['headers']['Cookie'] ?? null, 'and http vs https on our own host is still our own host — a site mid-migration must still be auditable');

CP_Test::group('site_readiness — request headers are sanitised, not trusted');

// A value carrying a newline turns one header into several. Rejected outright
// rather than stripped: quietly sending something other than what was asked
// for is worse than refusing to send it.
Claudpress_Tools_Readiness::set_request_headers([
    'Cookie'      => "good=1\r\nX-Injected: yes",
    'X-Also-Bad'  => "line\nbreak",
    'Bad Name'    => 'value',
    'X-Fine'      => 'ok',
    'X-Number'    => 42,
    'X-Array'     => ['not', 'scalar'],
]);
$sent = (array) ($args_for($home . '/x')['headers'] ?? []);

CP_Test::ok(!array_key_exists('Cookie', $sent), 'a header value containing CRLF is dropped, not stripped — request splitting is not something to half-fix');
CP_Test::ok(!array_key_exists('X-Also-Bad', $sent), 'a bare newline is dropped too');
CP_Test::ok(!array_key_exists('Bad Name', $sent), 'a header name with a space is not a token and is dropped');
CP_Test::ok(!array_key_exists('X-Array', $sent), 'a non-scalar value is dropped rather than coerced into "Array"');
CP_Test::same('ok', $sent['X-Fine'] ?? null, 'a valid header still goes through');
CP_Test::same('42', $sent['X-Number'] ?? null, 'a numeric value is accepted and sent as a string');

CP_Test::group('site_readiness — headers do not leak between runs');

// A header left over from a previous call would silently change what a later
// audit saw, and an audit that quietly used a credential nobody passed is a
// worse failure than one that could not see.
Claudpress_Tools_Readiness::set_request_headers(['Cookie' => 'cp_preview=one']);
CP_Test::same('cp_preview=one', $args_for($home . '/x')['headers']['Cookie'] ?? null, 'set once, sent');

Claudpress_Tools_Readiness::set_request_headers(null);
CP_Test::same([], (array) ($args_for($home . '/x')['headers'] ?? []), 'passing nothing clears what the previous run set');

Claudpress_Tools_Readiness::set_request_headers('not an array');
CP_Test::same([], (array) ($args_for($home . '/x')['headers'] ?? []), 'a non-array argument clears rather than throwing');

CP_Test::group('site_readiness — the timeout survives all of it');

Claudpress_Tools_Readiness::set_request_headers(['Cookie' => 'x=1']);
CP_Test::same(15, $args_for($home . '/x')['timeout'] ?? null, 'the request timeout is still set — merging headers must not replace the args');
Claudpress_Tools_Readiness::set_request_headers(null);
