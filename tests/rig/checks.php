<?php
/**
 * Live verification of the 1.10.1 fixes against a real WordPress install.
 *
 * The unit suite runs against hand-written stubs. This runs against actual
 * WordPress, actual $wpdb, actual options — which is the only way to catch a
 * fix that is correct in isolation and wrong in situ.
 *
 * Run: wp eval-file rig-checks.php
 */

global $wpdb;

$GLOBALS['cp_rig_pass'] = 0;
$GLOBALS['cp_rig_fail'] = 0;
function check($label, $ok, $detail = '') {
    $ok ? $GLOBALS['cp_rig_pass']++ : $GLOBALS['cp_rig_fail']++;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', str_pad($label, 48), $detail);
}

Claudpress::instance()->update(['abilities_enabled' => true, 'log_calls' => true]);

// ---------------------------------------------------------------- S1 ------
$r = Claudpress_Tools_Filesystem::write_file([
    'path'    => 'wp-content/../../claudpress-pwn.txt',
    'content' => '<?php echo 1;',
]);
check('S1 traversal refused', !$r['success'], $r['error'] ?? '');
check('S1 no file outside the base', !file_exists(dirname(dirname(ABSPATH)) . '/claudpress-pwn.txt'));

$ok = Claudpress_Tools_Filesystem::write_file([
    'path'    => 'wp-content/uploads/cp-rig/deep/nested/ok.txt',
    'content' => 'hello',
]);
check('S1 legitimate nested write still works', !empty($ok['success']), $ok['error'] ?? '');

// ---------------------------------------------------------------- S2 ------
$key = 'sk-ant-api03-LIVE-RIG-KEY-9999';
Claudpress::instance()->update(['agent_api_key' => $key]);
$raw = get_option('claudpress_settings');

check('S2 key absent from the stored option', strpos(wp_json_encode($raw), 'sk-ant-api03-LIVE') === false);
check('S2 stored value is ciphertext', strpos((string) $raw['agent_api_key'], 'cpenc:v1:') === 0, substr((string) $raw['agent_api_key'], 0, 24) . '...');
check('S2 reads back as plaintext for callers', Claudpress::instance()->get('agent_api_key') === $key);

$autoload = $wpdb->get_var($wpdb->prepare(
    "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
    'claudpress_settings'
));
check('S2 settings option does not autoload', in_array($autoload, ['no', 'off', '0'], true), 'autoload=' . var_export($autoload, true));

// ---------------------------------------------------------------- S3 ------
Claudpress::instance()->logger->record(
    'wc_payments',
    ['gateway' => 'stripe', 'secret_key' => 'sk_live_LIVERIGSECRET123', 'title' => 'Card'],
    ['success' => true]
);
$logged = (string) $wpdb->get_var("SELECT args_json FROM {$wpdb->prefix}claudpress_log ORDER BY id DESC LIMIT 1");
check('S3 gateway secret never reaches the log', strpos($logged, 'sk_live_LIVERIG') === false, $logged);
check('S3 non-secret args survive', strpos($logged, 'Card') !== false);

// ---------------------------------------------------------------- S5 ------
$s = Claudpress::instance()->settings();
check('S5 token stored as a hash', !empty($s['token_hash']) && strlen($s['token_hash']) === 64);
check('S5 no plaintext token stored', ($s['token'] ?? '') === '');

// ------------------------------------------------------------ tiering -----
$all     = Claudpress::instance()->registry->names();
$visible = array_values(array_filter($all, static fn($n) => Claudpress_Capabilities::tool_allowed($n)));
$hidden  = array_values(array_diff($all, $visible));

check('Tier hides Pro tools from the registry', count($hidden) > 0, count($visible) . ' visible / ' . count($hidden) . ' hidden');
check('Tier keeps execute_php free', in_array('execute_php', $visible, true));
check('Tier keeps snippet_save free', in_array('snippet_save', $visible, true));
check('Tier keeps write_file free', in_array('write_file', $visible, true));
check('Tier keeps site_readiness free', in_array('site_readiness', $visible, true));
check('Tier hides run_agent', in_array('run_agent', $hidden, true));
check('Tier hides deploy_push', in_array('deploy_push', $hidden, true));

$info = Claudpress_Tools_Core::get_site_info([]);
check('get_site_info reports the plan', ($info['claudpress']['plan'] ?? '') === 'free');
check('get_site_info lists what is unavailable', count($info['claudpress']['unavailable_capabilities'] ?? []) > 0,
    count($info['claudpress']['unavailable_capabilities']) . ' groups');
$first = $info['claudpress']['unavailable_capabilities'][0] ?? [];
check('...each with why and how', !empty($first['why_unavailable']) && !empty($first['how_to_enable']));

// -------------------------------------------------------- snippet log -----
$snip = Claudpress_Snippets::save(['name' => 'Rig test snippet', 'code' => '<?php add_filter("the_content", fn($c) => $c);', 'snippet_type' => 'php']);
$events = $wpdb->get_col("SELECT event FROM {$wpdb->prefix}claudpress_snippet_log ORDER BY id DESC LIMIT 3");
check('Snippet activity is recorded', in_array('created', $events, true), implode(',', $events));

// --------------------------------------------------------- safety loop ----
$bad = Claudpress_Snippets::save(['name' => 'Broken snippet', 'code' => '<?php function ( { syntax error', 'snippet_type' => 'php', 'active' => 1]);
check('A snippet that does not parse cannot activate', empty($bad['active']) || !empty($bad['error']) || !empty($bad['lint']), wp_json_encode(array_intersect_key($bad, array_flip(['active', 'error', 'lint']))));

printf("\n%d passed, %d failed\n", $GLOBALS['cp_rig_pass'], $GLOBALS['cp_rig_fail']);
if ($GLOBALS['cp_rig_fail'] > 0) {
    exit(1);
}

