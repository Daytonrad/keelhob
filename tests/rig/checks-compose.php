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

$GLOBALS['cp_rig_pass'] = 0;
$GLOBALS['cp_rig_fail'] = 0;
function check($label, $ok, $detail = '') {
    $ok ? $GLOBALS['cp_rig_pass']++ : $GLOBALS['cp_rig_fail']++;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', str_pad($label, 54), $detail);
}

add_filter('claudpress_pro_licence_override', static fn() => true, 99);
delete_transient(Claudpress_Pro_Licence::TRANSIENT);
wp_set_current_user(1);

Claudpress::instance()->update([
    'agent_provider' => 'anthropic',
    'agent_api_key'  => 'sk-ant-RIG-MOCK-NOT-A-REAL-KEY',
    'agent_model'    => 'mock-model-newest',
]);

check('the AI mock is installed', has_filter('pre_http_request', 'claudpress_rig_ai_mock') !== false,
    'copy tests/rig/ai-mock.php into wp-content/mu-plugins/ if this fails');

$table  = Claudpress_Snippets::table();
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
$digest = (string) $wpdb->get_var("SELECT COALESCE(GROUP_CONCAT(id || ':' || updated_at), '') FROM {$table}");

// ---- 1. Plan. Pure. -------------------------------------------------------
$plan = Claudpress_Studio_AI::plan('Add a free shipping banner to the cart when the total is over 200.');

check('plan() returns a plan', !is_wp_error($plan) && is_array($plan), is_wp_error($plan) ? $plan->get_error_message() : '');
if (!is_wp_error($plan)) {
    check('  the plan has a summary a person can read', !empty($plan['summary']));
    check('  the plan has steps', !empty($plan['steps']) && is_array($plan['steps']));
    check('  the plan names risks', array_key_exists('risks', $plan));
    check('  the plan contains NO code', empty($plan['code']) && strpos(wp_json_encode($plan), '<?php') === false);
}

check('planning wrote nothing', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === $before);

// ---- 2. Build. Also pure. -------------------------------------------------
$built = Claudpress_Studio_AI::build('Free shipping banner over 200', wp_json_encode($plan));

check('build() returns code', !is_wp_error($built) && !empty($built['code']), is_wp_error($built) ? $built->get_error_message() : '');
if (!is_wp_error($built)) {
    check('  the type was inferred from the code', $built['type'] === 'php', 'got ' . $built['type']);
    check('  the code has no markdown fences left', strpos($built['code'], '```') === false);
    check('  the code passes the real parse gate', Claudpress_Snippets::lint_php($built['code'], $built['type']) === '');
}

// THE PROMISE. Two AI round trips have happened and the table has not moved -
// not one new row, not one changed timestamp.
$after_digest = (string) $wpdb->get_var("SELECT COALESCE(GROUP_CONCAT(id || ':' || updated_at), '') FROM {$table}");
check('BUILDING WROTE NOTHING EITHER', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === $before);
check('  and no existing row was touched', $after_digest === $digest);

// ---- 3. Save. The only thing that writes. ---------------------------------
$saved = Claudpress_Snippets::save([
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

$row = Claudpress_Snippets::get($id);
check('  it is saved SWITCHED OFF', empty($row['active']));
check('  it is marked as AI-written', ($row['source'] ?? '') === 'ai');
check('  the prompt is kept with it', trim((string) ($row['ai_prompt'] ?? '')) !== '');
check('  the model is recorded', ($row['ai_model'] ?? '') === 'mock-model-newest');

// ---- 4. Ask AI. Advisory, and it does not touch the row. ------------------
$review = Claudpress_Studio_AI::review((string) $row['code'], 'php');
check('review() returns a verdict', !is_wp_error($review) && !empty($review['verdict']), is_wp_error($review) ? $review->get_error_message() : '');
if (!is_wp_error($review)) {
    check('  the verdict is one of the three', in_array($review['verdict'], ['safe', 'caution', 'unsafe'], true), (string) $review['verdict']);
    check('  it explains the effect on the site', !empty($review['site_impact']));
}
check('reviewing did not change the snippet', (string) Claudpress_Snippets::get($id)['code'] === (string) $row['code']);

// ---- 5. The gate still refuses broken code. -------------------------------
$bad = Claudpress_Snippets::save([
    'name'         => 'Rig broken snippet',
    'code'         => '<?php function ( { this does not parse',
    'snippet_type' => 'php',
    'active'       => 1,
]);
$bad_row = Claudpress_Snippets::get((int) $bad['id']);

check('code that does not parse cannot go live', empty($bad_row['active']));
check('  and the reason is recorded on it', trim((string) $bad_row['last_error']) !== '');
check('  but the work is not thrown away', strpos((string) $bad_row['code'], 'does not parse') !== false);

// ---- 6. A provider failure is survivable. ---------------------------------
add_filter('pre_http_request', static fn() => new WP_Error('http_request_failed', 'Simulated outage.'), 1);
$failed = Claudpress_Studio_AI::plan('anything at all');
remove_all_filters('pre_http_request', 1);

check('a provider outage returns an error, not an exception', is_wp_error($failed));
check('  and still nothing was written', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") === $before + 2);

Claudpress_Snippets::delete($id);
Claudpress_Snippets::delete((int) $bad['id']);

printf("\n%d passed, %d failed\n", $GLOBALS['cp_rig_pass'], $GLOBALS['cp_rig_fail']);
if ($GLOBALS['cp_rig_fail'] > 0) {
    exit(1);
}
