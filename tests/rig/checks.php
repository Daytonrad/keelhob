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

// ------------------------------------------------------ capabilities ------
//
// This section used to prove a free install could not use the paid tools, and
// its other half lived in checks-pro.php, which proved they came back under a
// licence. There is one plugin now, so both halves collapse into one question
// that is worth more than either: is every tool this plugin claims to have
// actually here and actually callable on a real WordPress?
//
// The tool list below is exactly the set checks-pro.php guarded. They are the
// ones that moved during the merge, which makes them the ones most likely to
// have been dropped on the way.
$all   = Claudpress::instance()->registry->names();
$usable = static function (string $tool) use ($all) {
    return in_array($tool, $all, true) && Claudpress_Capabilities::tool_allowed($tool);
};
$present = static function (string $tool) use ($all) {
    return in_array($tool, $all, true);
};

$merged = [
    'deploy_push', 'export_bundle', 'import_bundle',
    'manage_updates', 'send_email',
    'task_save', 'task_run', 'task_delete', 'run_agent', 'notify_owner',
    'seo_analyze', 'seo_redirect',
];
if (class_exists('WooCommerce')) {
    $merged = array_merge($merged, ['wc_payments', 'wc_shipping', 'wc_tax', 'wc_order', 'wc_report']);
} else {
    echo "NOTE  WooCommerce is not installed on this rig, so wc_* tools are skipped." . PHP_EOL;
}

$missing = array_values(array_filter($merged, static fn($t) => !$present($t)));
check('every formerly-paid tool is present', $missing === [], $missing ? 'missing: ' . implode(', ', $missing) : count($merged) . ' checked');

// Names must not have drifted. A renamed tool is a broken integration for
// anyone who scripted against it, and the merge touched every one of these.
foreach ($all as $name) {
    check('  ' . $name . ' carries no tier suffix', strpos($name, '_pro') === false && strpos($name, 'pro_') !== 0);
}

// The eight high-blast-radius groups ship OFF. On a fresh rig that means these
// are present but refused - which is the whole safety story of 2.0.0, and the
// thing most likely to be broken by a careless default.
$fenced = ['deploy_push', 'send_email', 'manage_updates'];
if (class_exists('WooCommerce')) {
    // Same guard as the two lists around this one. Without WooCommerce the
    // tool is never registered, so an unguarded assertion here fails on every
    // rig that does not happen to have WooCommerce installed - which is every
    // CI runner, and was the rig's only red check on 1.0.1.
    $fenced[] = 'wc_payments';
}
foreach ($fenced as $tool) {
    check('  ' . $tool . ' is present but refused by default', $present($tool) && !$usable($tool));
}

// Everything the build journey needs works out of the box, with no setup.
$build_tools = ['execute_php', 'write_file', 'snippet_save', 'site_readiness', 'image_search', 'seo_meta', 'create_post'];
if (class_exists('WooCommerce')) {
    $build_tools[] = 'wc_product';
    $build_tools[] = 'wc_coupon';
}
foreach ($build_tools as $tool) {
    check('  ' . $tool . ' is usable on a fresh install', $usable($tool));
}

// The always-on promise: these work whatever else is restricted.
foreach (Claudpress_Capabilities::always_on() as $tool) {
    check('  always-on: ' . $tool, $usable($tool));
}

// And the agent is told what is off, by name, with the switch that fixes it -
// so it stops instead of retry-looping and calling the site broken.
$info = Claudpress_Tools_Core::get_site_info([]);
check('get_site_info reports no plan or tier', !isset($info['claudpress']['plan']));
check('get_site_info lists what is switched off', count($info['claudpress']['unavailable_capabilities'] ?? []) > 0,
    count($info['claudpress']['unavailable_capabilities']) . ' groups');
$first = $info['claudpress']['unavailable_capabilities'][0] ?? [];
check('...each with why and how', !empty($first['why_unavailable']) && !empty($first['how_to_enable']));
check('...and "how" names a setting, not a purchase', strpos((string) ($first['how_to_enable'] ?? ''), 'Settings') !== false);

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

