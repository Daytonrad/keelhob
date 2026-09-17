<?php
/** Autonomy reliability: retry classification, backoff, and budget caps. */

KH_Test::group('Agent runtime — retry classification');

KH_Test::ok(Keelhob_Agent_Runtime::should_retry(429), '429 rate limits are retried');
KH_Test::ok(Keelhob_Agent_Runtime::should_retry(408), '408 timeouts are retried');
KH_Test::ok(Keelhob_Agent_Runtime::should_retry(500), '500s are retried');
KH_Test::ok(Keelhob_Agent_Runtime::should_retry(529), '529 overloaded is retried');
KH_Test::ok(Keelhob_Agent_Runtime::should_retry(null), 'transport failures (DNS/timeout) are retried');
KH_Test::ok(!Keelhob_Agent_Runtime::should_retry(401), 'a bad API key is NOT retried — it would fail identically forever');
KH_Test::ok(!Keelhob_Agent_Runtime::should_retry(400), 'a malformed request is not retried');
KH_Test::ok(!Keelhob_Agent_Runtime::should_retry(404), 'a wrong endpoint is not retried');
KH_Test::ok(!Keelhob_Agent_Runtime::should_retry(200), 'success is not retried');

KH_Test::group('Agent runtime — backoff');

// Jitter pinned so the window, not the dice roll, is under test.
KH_Test::same(1000, Keelhob_Agent_Runtime::backoff_delay_ms(0, null, 1.0), 'first retry waits the full base second');
KH_Test::same(2000, Keelhob_Agent_Runtime::backoff_delay_ms(1, null, 1.0), 'the wait doubles each attempt');
KH_Test::same(4000, Keelhob_Agent_Runtime::backoff_delay_ms(2, null, 1.0), 'and doubles again');
KH_Test::same(500, Keelhob_Agent_Runtime::backoff_delay_ms(0, null, 0.0), 'jitter can pull a wait down to half the window');
KH_Test::same(Keelhob_Agent_Runtime::BACKOFF_CAP_MS, Keelhob_Agent_Runtime::backoff_delay_ms(20, null, 1.0), 'exponential growth is capped');
KH_Test::same(3000, Keelhob_Agent_Runtime::backoff_delay_ms(0, 3, 1.0), 'a server Retry-After header wins over the computed backoff');
KH_Test::same(Keelhob_Agent_Runtime::BACKOFF_CAP_MS, Keelhob_Agent_Runtime::backoff_delay_ms(0, 99999, 1.0), 'an absurd Retry-After cannot stall a cron run');

$random = Keelhob_Agent_Runtime::backoff_delay_ms(1);
KH_Test::between(1000, 2000, (float) $random, 'unpinned jitter stays inside the attempt window');

KH_Test::group('Agent runtime — pricing');

$sonnet = Keelhob_Agent_Runtime::price_for_model('claude-sonnet-5');
KH_Test::same(3.0, $sonnet['in'], 'sonnet input price resolves by prefix');
$opus = Keelhob_Agent_Runtime::price_for_model('claude-opus-5');
KH_Test::same(75.0, $opus['out'], 'opus output price resolves by prefix');
$haiku3 = Keelhob_Agent_Runtime::price_for_model('claude-3-haiku-20240307');
KH_Test::same(0.25, $haiku3['in'], 'the longest matching prefix wins (claude-3-haiku over claude-haiku)');
$unknown = Keelhob_Agent_Runtime::price_for_model('some-future-model');
KH_Test::same(3.0, $unknown['in'], 'an unknown model falls back to sonnet-class pricing rather than free');

KH_Test::same(3.0, Keelhob_Agent_Runtime::estimate_cost('claude-sonnet-5', 1000000, 0), 'one million input tokens on sonnet costs $3.00');
KH_Test::same(75.0, Keelhob_Agent_Runtime::estimate_cost('claude-opus-5', 0, 1000000), 'one million output tokens on opus costs $75.00');
KH_Test::same(0.018, Keelhob_Agent_Runtime::estimate_cost('claude-sonnet-5', 1000, 1000), '1k in + 1k out on sonnet is $0.018');
KH_Test::same(0.0, Keelhob_Agent_Runtime::estimate_cost('claude-sonnet-5', 0, 0), 'zero tokens costs nothing');

KH_Test::group('Agent runtime — budget caps');

$caps = ['max_usd_per_run' => 0.50, 'max_tokens_per_run' => 300000, 'max_usd_per_day' => 5.00, 'max_runs_per_day' => 48];
$fresh = ['cost' => 0.0, 'tokens' => 0];
$quiet = ['cost' => 0.0, 'runs' => 0];

KH_Test::same(null, Keelhob_Agent_Runtime::cap_exceeded($caps, $fresh, $quiet, true), 'a fresh run under a quiet day is allowed to start');
KH_Test::ok(Keelhob_Agent_Runtime::cap_exceeded($caps, $fresh, ['cost' => 0.0, 'runs' => 48], true) !== null, 'the daily run cap blocks a new run');
KH_Test::ok(Keelhob_Agent_Runtime::cap_exceeded($caps, $fresh, ['cost' => 5.0, 'runs' => 1], true) !== null, 'the daily spend cap blocks a new run');
KH_Test::same(null, Keelhob_Agent_Runtime::cap_exceeded($caps, $fresh, ['cost' => 4.99, 'runs' => 47], true), 'just under both daily caps still starts');

KH_Test::same(null, Keelhob_Agent_Runtime::cap_exceeded($caps, ['cost' => 0.49, 'tokens' => 1000], $quiet, false), 'a run under its per-run cap continues');
KH_Test::ok(Keelhob_Agent_Runtime::cap_exceeded($caps, ['cost' => 0.50, 'tokens' => 1000], $quiet, false) !== null, 'reaching the per-run spend cap stops the loop');
KH_Test::ok(Keelhob_Agent_Runtime::cap_exceeded($caps, ['cost' => 0.01, 'tokens' => 300000], $quiet, false) !== null, 'reaching the per-run token cap stops the loop');
KH_Test::ok(Keelhob_Agent_Runtime::cap_exceeded($caps, ['cost' => 0.10, 'tokens' => 10], ['cost' => 4.95, 'runs' => 3], false) !== null, 'this run plus today\'s spend crossing the daily cap stops the loop');

$reason = Keelhob_Agent_Runtime::cap_exceeded($caps, ['cost' => 0.60, 'tokens' => 10], $quiet, false);
KH_Test::contains('Run spend cap', (string) $reason, 'the stop reason names which cap tripped');

$unlimited = ['max_usd_per_run' => 0, 'max_tokens_per_run' => 0, 'max_usd_per_day' => 0, 'max_runs_per_day' => 0];
KH_Test::same(null, Keelhob_Agent_Runtime::cap_exceeded($unlimited, ['cost' => 999.0, 'tokens' => 99999999], ['cost' => 999.0, 'runs' => 9999], false), '0 means unlimited on every cap');
KH_Test::same(null, Keelhob_Agent_Runtime::cap_exceeded($unlimited, $fresh, ['cost' => 999.0, 'runs' => 9999], true), '0 means unlimited at start-of-run too');

KH_Test::group('Agent runtime — cap settings');

kh_test_reset_options();
$defaults = Keelhob_Agent_Runtime::caps();
KH_Test::ok($defaults['max_usd_per_run'] > 0, 'caps ship switched ON by default — an unattended agent is budgeted out of the box');
KH_Test::ok($defaults['max_usd_per_day'] >= $defaults['max_usd_per_run'], 'the daily cap is at least one full run');

kh_test_reset_options(['keelhob_settings' => ['agent_max_usd_per_run' => 2.5, 'agent_max_runs_per_day' => 10]]);
$custom = Keelhob_Agent_Runtime::caps();
KH_Test::same(2.5, $custom['max_usd_per_run'], 'saved cap settings are honoured');
KH_Test::same(10, $custom['max_runs_per_day'], 'saved run cap is honoured');

kh_test_reset_options();

KH_Test::group('Operating protocol — guardrails reach the model');

// The protocol is the only thing standing between a capable agent and an
// unrecoverable site, so assert it is actually present and shared rather than
// trusting that two call sites stayed in sync.
$protocol = Keelhob_MCP_Server::operating_protocol();

KH_Test::contains('Look before you touch', $protocol, 'read-before-write rule is present');
KH_Test::contains('Smallest change', $protocol, 'minimal-change rule is present');
KH_Test::contains('Verify what you changed', $protocol, 'verification rule is present');
KH_Test::contains('Report honestly', $protocol, 'honest-reporting rule is present');
KH_Test::contains('execute_sql', $protocol, 'the sharp tools are named explicitly');
KH_Test::contains('capability the owner switched off', $protocol, 'working around disabled capabilities is forbidden');
KH_Test::contains('pre-launch-audit', $protocol, 'the definition-of-done rule points at the audit skill');
KH_Test::contains('Never invent facts', $protocol, 'the no-invented-facts rule is present');
KH_Test::contains('A store with no orders has no customers', $protocol, 'the invented-facts rule names the store-with-no-orders case');
KH_Test::contains('build-standard', $protocol, 'the protocol tells the agent to load the build-standard skill');

// Tier 0 is only real if both injection paths carry it, not just the getter.
// system_prompt() is intentionally private, so reach it via reflection like
// the filesystem-safety tests do for php_syntax_error().
$system_prompt_method = new ReflectionMethod(Keelhob_Agent_Runtime::class, 'system_prompt');
@$system_prompt_method->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor

$unattended_prompt = $system_prompt_method->invoke(null, false, '');
KH_Test::contains('pre-launch-audit', $unattended_prompt, 'the definition-of-done rule reaches unattended runs');
KH_Test::contains('Never invent facts', $unattended_prompt, 'the no-invented-facts rule reaches unattended runs');
KH_Test::contains('build-standard', $unattended_prompt, 'the build-standard load rule reaches unattended runs');

$interactive_prompt = $system_prompt_method->invoke(null, true, '');
KH_Test::contains('pre-launch-audit', $interactive_prompt, 'the definition-of-done rule reaches interactive sessions');
KH_Test::contains('Never invent facts', $interactive_prompt, 'the no-invented-facts rule reaches interactive sessions');
KH_Test::contains('build-standard', $interactive_prompt, 'the build-standard load rule reaches interactive sessions');

// --- rule 16: precision, and not wasting the owner's tokens -----------------
//
// Added at the owner's request, adapted from Andrej Karpathy's CLAUDE.md. It
// lives in the protocol rather than in a skill for the same reason as rules
// 9-15: a skill can be switched off, and this one applies to EVERY task rather
// than to building. It must therefore reach agents on both pipes, which is what
// the three blocks below check.
KH_Test::contains('16. Be precise', $protocol, 'the precision rule is present');
KH_Test::contains('minimum that solves it', $protocol, 'and says to write the minimum');
KH_Test::contains('every page load for real visitors', $protocol, 'and names snippets as the sharpest case');
KH_Test::contains('Clean up only your own mess', $protocol, 'and forbids tidying code it was not asked to touch');
KH_Test::contains('Decide the check before you start', $protocol, 'and demands a success criterion up front');
KH_Test::contains('Spend context like money', $protocol, 'and treats context as a cost');

KH_Test::contains('16. Be precise', $unattended_prompt, 'the precision rule reaches unattended runs');
KH_Test::contains('16. Be precise', $interactive_prompt, 'and interactive sessions');

// It is a rule about waste, so it has to be worth its own weight. Every session
// pays for these bytes, whether or not the agent ever writes a line of code.
KH_Test::ok(strlen($protocol) < 12000, 'the whole protocol stays under 12 KB (~3,000 tokens per session)');

// A filter lets a site tighten the rules; it must not be able to vanish silently.
add_filter('keelhob_operating_protocol', static fn($p) => $p . "\n9. House rule.");
KH_Test::contains('House rule', Keelhob_MCP_Server::operating_protocol(), 'sites can extend the protocol via filter');

kh_test_reset_filters();

KH_Test::group('A provider rejection reaches the feedback log through a REAL run');

/*
 * The recorder being correct is not the same as the recorder being CALLED.
 * This drives Keelhob_Agent_Runtime::run() with a real HTTP 400 queued, so
 * the wiring is what is under test -- not observe_llm_error() in isolation.
 */

/** A $wpdb that remembers the feedback rows a run wrote. */
class KH_Runtime_Feedback_WPDB extends KH_Test_WPDB
{
    /** @var array<int, array<string, mixed>> */
    public array $feedback = [];

    public function insert($table, $data)
    {
        if (strpos((string) $table, 'keelhob_feedback') !== false) {
            $this->feedback[] = $data;
        }
        return 1;
    }
}

$kh_real_wpdb    = $GLOBALS['wpdb'];
$fdb             = new KH_Runtime_Feedback_WPDB();
$GLOBALS['wpdb'] = $fdb;

// test-admin-render.php swaps in a stub registry and leaves it there. Put a
// real one back, or run() dies on a type hint before it ever makes the call.
$kh_real_registry                 = Keelhob::instance()->registry;
Keelhob::instance()->registry  = new Keelhob_Tool_Registry();

kh_test_reset_options();
Keelhob::instance()->update([
    'abilities_enabled' => true,
    'agent_api_key'     => 'sk-test',
    'agent_provider'    => 'google',
    'feedback_log'      => true,
]);

// The owner's actual error, word for word.
$fdb->feedback = [];
kh_test_http_queue(400, ['error' => ['message' => 'Invalid JSON payload received. Unknown name "args" at \'contents[3].parts[0].function_call\': Proto field is not repeating, cannot start list.']]);
$run = Keelhob_Agent_Runtime::run('How many snippets are there?');

KH_Test::ok(!empty($run['error']), 'the run still reports the failure to the caller');
KH_Test::same(1, count($fdb->feedback), 'and the run wrote exactly one feedback row on its way out');
KH_Test::same('llm_rejected', (string) ($fdb->feedback[0]['friction'] ?? ''), 'marked as a provider rejection');
KH_Test::same('llm_google', (string) ($fdb->feedback[0]['tool'] ?? ''), 'naming the provider that refused');
KH_Test::contains('Proto field is not repeating', (string) ($fdb->feedback[0]['note'] ?? ''), 'carrying the vendor wording that makes it searchable');

// A bad API key is the owner's problem, not a plugin bug, and must not land here.
$fdb->feedback = [];
kh_test_http_queue(401, ['error' => ['message' => 'API key not valid']]);
Keelhob_Agent_Runtime::run('anything');
KH_Test::same(0, count($fdb->feedback), 'a 401 writes nothing — a wrong key is not something to report to the project');

kh_test_reset_options();
Keelhob::instance()->registry = $kh_real_registry;
$GLOBALS['wpdb'] = $kh_real_wpdb;
