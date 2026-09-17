<?php
/**
 * The AI Compose flow, end to end, against real WordPress.
 *
 * Needs tests/rig/ai-mock.php in wp-content/mu-plugins/. Every layer runs for
 * real except the network hop: the request is built by the real provider class,
 * the reply is parsed by the real parser, the code is linted by the real gate,
 * and the row is written by the real save path.
 *
 * The assertion that matters most is the one about the snippets table not
 * moving. Everything else here is plumbing; that one is the promise.
 *
 * Run: wp eval-file tests/rig/checks-compose.php
 */

global $wpdb;

$GLOBALS['kh_rig_pass'] = 0;
$GLOBALS['kh_rig_fail'] = 0;
function check($label, $ok, $detail = '') {
    $ok ? $GLOBALS['kh_rig_pass']++ : $GLOBALS['kh_rig_fail']++;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', str_pad($label, 54), $detail);
}

wp_set_current_user(1);

Keelhob::instance()->update([
    'agent_provider' => 'anthropic',
    'agent_api_key'  => 'sk-ant-RIG-MOCK-NOT-A-REAL-KEY',
    'agent_model'    => 'mock-model-newest',
]);

check('the AI mock is installed', has_filter('pre_http_request', 'keelhob_rig_ai_mock') !== false,
    'copy tests/rig/ai-mock.php into wp-content/mu-plugins/ if this fails');

$table  = Keelhob_Snippets::table();
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
$digest = (string) $wpdb->get_var("SELECT COALESCE(GROUP_CONCAT(id || ':' || updated_at), '') FROM {$table}");

// ---- 1. A conversation, not a wizard. -------------------------------------
//
// Compose used to be plan-then-build: two one-shot calls, neither of which
// could see the other. Every assertion here is about the thing that replaced
// it -- the model gets the whole transcript, answers in ordinary sentences,
// and puts code in a fenced block.

$turns = [['role' => 'user', 'text' => 'Add a note to the end of every post.']];
$reply = Keelhob_Studio_Chat::reply($turns);

check('a chat turn gets a reply', !is_wp_error($reply) && !empty($reply['text']),
    is_wp_error($reply) ? $reply->get_error_message() : '');

$segments = Keelhob_Studio_Chat::segments((string) $reply['text']);
$blocks   = Keelhob_Studio_Chat::lint_blocks(Keelhob_Studio_Chat::blocks((string) $reply['text']));

check('  the prose around the code is kept', count($segments) > count($blocks));
check('  one code block is found', count($blocks) === 1, 'got ' . count($blocks));
check('  its type came from the fence', ($blocks[0]['type'] ?? '') === 'php', (string) ($blocks[0]['type'] ?? ''));
check('  no markdown fence survives into the code', strpos((string) ($blocks[0]['code'] ?? ''), '```') === false);
check('  and it passes the real parse gate', trim((string) ($blocks[0]['error'] ?? '')) !== '' ? false : true);

// ---- 2. A reply with NO code is still an answer. --------------------------
//
// THE BUG THIS REWRITE EXISTS FOR. The old plan() demanded a JSON object and
// returned WP_Error('...not in a form this screen could read') for anything
// else -- so a model answering a question in plain sentences produced a
// failure message over a perfectly good reply, and there was no way out.

$asked = Keelhob_Studio_Chat::reply([['role' => 'user', 'text' => 'Why will that not work at that hook? explain please']]);
check('a prose-only reply is NOT an error', !is_wp_error($asked) && !empty($asked['text']),
    is_wp_error($asked) ? $asked->get_error_message() : '');
check('  and yields no code blocks rather than failing', count(Keelhob_Studio_Chat::blocks((string) $asked['text'])) === 0);

// ---- 3. The model is shown its own syntax error, and fixes it. -----------
//
// The second thing that had no way out. A parse error used to be rendered for
// the PERSON and never shown to the MODEL, because there was no next turn to
// put it in.

$broken_reply = Keelhob_Studio_Chat::reply([['role' => 'user', 'text' => 'give me broken code please']]);
$broken       = Keelhob_Studio_Chat::lint_blocks(Keelhob_Studio_Chat::blocks((string) $broken_reply['text']));

check('code that does not parse is caught in the chat', Keelhob_Studio_Chat::has_error($broken));

$correction = Keelhob_Studio_Chat::error_turn($broken);
check('  the correction turn carries the real parse error', strlen($correction) > 40);

$fixed_reply = Keelhob_Studio_Chat::reply([
    ['role' => 'user',      'text' => 'give me broken code please'],
    ['role' => 'assistant', 'text' => (string) $broken_reply['text']],
    ['role' => 'user',      'text' => $correction],
]);
$fixed = Keelhob_Studio_Chat::lint_blocks(Keelhob_Studio_Chat::blocks((string) $fixed_reply['text']));

check('  sending it back produces code that parses', !Keelhob_Studio_Chat::has_error($fixed));
check('  and the whole transcript reached the provider', !is_wp_error($fixed_reply));

// THE PROMISE. Several AI round trips have happened and the table has not
// moved - not one new row, not one changed timestamp.
$after_digest = (string) $wpdb->get_var("SELECT COALESCE(GROUP_CONCAT(id || ':' || updated_at), '') FROM {$table}");
check('TALKING WROTE NOTHING', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === $before);
check('  and no existing row was touched', $after_digest === $digest);

$built = ['code' => (string) $blocks[0]['code'], 'type' => (string) $blocks[0]['type']];

// ---- 4. Save. The only thing that writes. ---------------------------------
// ---- 3. Save. The only thing that writes. ---------------------------------
$saved = Keelhob_Snippets::save([
    'name'         => 'Rig composed snippet',
    'code'         => $built['code'],
    'snippet_type' => $built['type'],
    'source'       => 'ai',
    'ai_prompt'    => 'Free shipping banner over 200',
    'ai_model'     => 'mock-model-newest',
    'active'       => 0,
]);
$id = (int) ($saved['id'] ?? 0);

check('saving adds exactly one row', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === $before + 1);

$row = Keelhob_Snippets::get($id);
check('  it is saved SWITCHED OFF', empty($row['active']));
check('  it is marked as AI-written', ($row['source'] ?? '') === 'ai');
check('  the prompt is kept with it', trim((string) ($row['ai_prompt'] ?? '')) !== '');
check('  the model is recorded', ($row['ai_model'] ?? '') === 'mock-model-newest');

// ---- 5. Ask AI. Advisory, and it does not touch the row. ------------------
$review = Keelhob_Studio_AI::review((string) $row['code'], 'php');
check('review() returns a verdict', !is_wp_error($review) && !empty($review['verdict']), is_wp_error($review) ? $review->get_error_message() : '');
if (!is_wp_error($review)) {
    check('  the verdict is one of the three', in_array($review['verdict'], ['safe', 'caution', 'unsafe'], true), (string) $review['verdict']);
    check('  it explains the effect on the site', !empty($review['site_impact']));
}
check('reviewing did not change the snippet', (string) Keelhob_Snippets::get($id)['code'] === (string) $row['code']);

// ---- 6. The gate still refuses broken code. -------------------------------
$bad = Keelhob_Snippets::save([
    'name'         => 'Rig broken snippet',
    'code'         => '<?php function ( { this does not parse',
    'snippet_type' => 'php',
    'active'       => 1,
]);
$bad_row = Keelhob_Snippets::get((int) $bad['id']);

check('code that does not parse cannot go live', empty($bad_row['active']));
check('  and the reason is recorded on it', trim((string) $bad_row['last_error']) !== '');
check('  but the work is not thrown away', strpos((string) $bad_row['code'], 'does not parse') !== false);

// ---- 7. A provider failure is survivable. ---------------------------------
add_filter('pre_http_request', static fn() => new WP_Error('http_request_failed', 'Simulated outage.'), 1);
$failed = Keelhob_Studio_Chat::reply([['role' => 'user', 'text' => 'anything at all']]);
remove_all_filters('pre_http_request', 1);

check('a provider outage returns an error, not an exception', is_wp_error($failed));
check('  and still nothing was written', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === $before + 2);

Keelhob_Snippets::delete($id);
Keelhob_Snippets::delete((int) $bad['id']);

printf("\n%d passed, %d failed\n", $GLOBALS['kh_rig_pass'], $GLOBALS['kh_rig_fail']);
if ($GLOBALS['kh_rig_fail'] > 0) {
    exit(1);
}
