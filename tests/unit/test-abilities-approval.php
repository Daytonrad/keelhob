<?php
/**
 * The approval gate: the two claims the whole feature rests on.
 *
 *   1. A PENDING ABILITY CANNOT BE CALLED. Not through the registry, and not
 *      through the real MCP endpoint's tools/call path.
 *   2. THE MODEL CANNOT APPROVE ITS OWN CODE BY ANY PATH. No tool, no REST
 *      route and no AJAX handler reaches approve(), rollback() or reject();
 *      the only callers are the three admin-post handlers, each of which
 *      checks `manage_options` and a nonce.
 *
 * The second claim is asserted STRUCTURALLY — by reading the shipped source for
 * callers — rather than by exercising the one path we know about. A test that
 * only drives the path it was told about cannot see the second door that gets
 * added next year, and the second door is the whole risk.
 */

KH_Test::group('Approval gate — a pending ability never becomes a callable tool');

kh_test_reset_options(['keelhob_settings' => [
    'abilities_enabled' => true,
    // The logger is only built by Keelhob::boot(), which this suite does not run,
    // so the registry's audit-log write would be a call on null. Off here; the
    // thing under test is the refusal, not the log.
    'log_calls'         => false,
    'capabilities'      => ['abilities' => true],
]]);

$pending_code = '$GLOBALS["kh_pending_ran"] = true; update_option("kh_pending_side_effect", "yes"); return ["ran" => true];';

$rows = [
    // Waiting for review. Its body would set an option if it ever ran.
    [
        'id' => 1, 'name' => 'kh_pending_probe', 'version' => 1,
        'label' => 'Pending probe', 'description' => 'Should never be callable.',
        'input_schema' => '', 'code' => $pending_code, 'code_hash' => '',
        'writes' => 1, 'risky' => 1, 'status' => 'pending',
    ],
    // Approved, and hashed against its own bytes.
    [
        'id' => 2, 'name' => 'kh_live_probe', 'version' => 1,
        'label' => 'Live probe', 'description' => 'Approved and live.',
        'input_schema' => '', 'code' => 'return ["ok" => true];',
        'code_hash' => Keelhob_Abilities::hash('return ["ok" => true];'),
        'writes' => 0, 'risky' => 0, 'status' => 'active',
    ],
    // Previously approved, since replaced.
    [
        'id' => 3, 'name' => 'kh_old_probe', 'version' => 1,
        'label' => 'Old probe', 'description' => 'Retired.',
        'input_schema' => '', 'code' => 'return ["old" => true];',
        'code_hash' => Keelhob_Abilities::hash('return ["old" => true];'),
        'writes' => 0, 'risky' => 0, 'status' => 'retired',
    ],
    // Rejected outright.
    [
        'id' => 4, 'name' => 'kh_rejected_probe', 'version' => 1,
        'label' => 'Rejected probe', 'description' => 'Rejected.',
        'input_schema' => '', 'code' => 'return ["no" => true];', 'code_hash' => '',
        'writes' => 0, 'risky' => 0, 'status' => 'rejected',
    ],
];

unset($GLOBALS['kh_pending_ran']);
delete_option('kh_pending_side_effect');

$registry = new Keelhob_Tool_Registry();
Keelhob_Abilities::register_custom($registry, $rows);
$names = $registry->names();

KH_Test::ok(in_array('kh_live_probe', $names, true), 'an APPROVED ability is registered as a tool');
KH_Test::ok(!in_array('kh_pending_probe', $names, true), 'a PENDING one is not — the model cannot even see it in tools/list');
KH_Test::ok(!in_array('kh_old_probe', $names, true), 'nor is a retired one');
KH_Test::ok(!in_array('kh_rejected_probe', $names, true), 'nor a rejected one');
KH_Test::ok(!$registry->has('kh_pending_probe'), 'and has() agrees, which is what tools/call checks');

KH_Test::ok(!isset($GLOBALS['kh_pending_ran']), 'registering the rows did not run the pending body');
KH_Test::same(false, get_option('kh_pending_side_effect', false), 'and its side effect never happened');

KH_Test::group('Approval gate — the real MCP endpoint refuses it');

/*
 * Driven through Keelhob_MCP_Server::handle(), the same method the REST route
 * calls, rather than through the registry directly — the point being that the
 * refusal is in the path a connected model actually takes.
 */
Keelhob::instance()->registry = $registry;
$server = new Keelhob_MCP_Server($registry);

$call = static function (string $name) use ($server): array {
    return (array) $server->handle([
        'jsonrpc' => '2.0',
        'id'      => 7,
        'method'  => 'tools/call',
        'params'  => ['name' => $name, 'arguments' => []],
    ]);
};

$pending_res = $call('kh_pending_probe');
$result = $pending_res['result'] ?? [];
$text   = (string) ($result['content'][0]['text'] ?? '');

KH_Test::ok(!empty($result['isError']), 'tools/call on the pending ability is an error');
KH_Test::contains('Unknown tool', $text, 'and the reason is that there is no such tool, because it was never registered');
KH_Test::ok(!isset($GLOBALS['kh_pending_ran']), 'the pending body still did not run');
KH_Test::same(false, get_option('kh_pending_side_effect', false), 'and the option it would have written is still unset');

/*
 * The approved ability IS registered and IS reachable — asserted above through
 * $registry->names(). What it cannot do here is execute, because run() re-reads
 * the live row from the database on every call (deliberately: the owner may have
 * rejected or rolled it back since the request started) and this suite's $wpdb
 * is a stub with no rows. So the execution half is asserted through run_row()
 * below, against the same fixture, and proved end to end on the rig.
 */
$live_res = $call('kh_live_probe');
$live     = $live_res['result'] ?? [];
KH_Test::contains('no approved version', (string) ($live['content'][0]['text'] ?? ''), 'with no row in the database there is nothing to run, which is the correct answer rather than a crash');

$live_run = Keelhob_Abilities::run_row($rows[1], []);
KH_Test::same(true, (bool) ($live_run['ok'] ?? false), 'run_row on the APPROVED, hash-matching row returns what its code returns');
KH_Test::same(1, (int) ($live_run['_ability']['version'] ?? 0), 'tagged with the version that ran, so a run can be attributed to a version');

$refused_pending = Keelhob_Abilities::run_row($rows[0], []);
KH_Test::same(false, $refused_pending['success'], 'run_row on the PENDING row refuses');
KH_Test::contains('never runs', $refused_pending['error'], 'and says a version waiting for review never runs');
KH_Test::ok(!isset($GLOBALS['kh_pending_ran']), 'and the pending body STILL did not run, even handed straight to the runner');
KH_Test::same(false, get_option('kh_pending_side_effect', false), 'and its option was never written');

$refused_retired = Keelhob_Abilities::run_row($rows[2], []);
KH_Test::same(false, $refused_retired['success'], 'a retired version does not run either');

KH_Test::group('Approval gate — the hash guard');

$tampered = [
    'id' => 9, 'name' => 'kh_tampered_probe', 'version' => 1,
    'label' => 'Tampered', 'description' => 'Edited after approval.',
    'input_schema' => '', 'status' => 'active', 'writes' => 0, 'risky' => 0,
    // Hashed against one body, storing another — a database edit, in other words.
    'code' => '$GLOBALS["kh_tampered_ran"] = true; return ["ran" => true];',
    'code_hash' => Keelhob_Abilities::hash('return ["ok" => true];'),
];

unset($GLOBALS['kh_tampered_ran']);
KH_Test::ok(!Keelhob_Abilities::hash_ok($tampered), 'code that no longer matches its hash fails the guard');
KH_Test::ok(!isset($GLOBALS['kh_tampered_ran']), 'and checking it did not run it');

$blocked = Keelhob_Abilities::run_row($tampered, []);
KH_Test::same(false, $blocked['success'], 'so the runner refuses it even though the row says "active"');
KH_Test::contains('changed after it was approved', $blocked['error'], 'in the words the owner and the model both need');
KH_Test::contains('review it again', $blocked['error'], 'and it says what to do about it');
KH_Test::ok(!isset($GLOBALS['kh_tampered_ran']), 'and the edited body never executed');

KH_Test::group('Approval gate — no tool, route or AJAX handler can approve');

foreach ($registry->names() as $tool) {
    KH_Test::ok(
        !preg_match('/approve|reject|rollback|roll_back/i', (string) $tool),
        sprintf('no registered tool is named like an approval control ("%s")', $tool)
    );
}

$tool_src = (string) file_get_contents(KEELHOB_DIR . 'includes/tools/class-tools-abilities.php');
KH_Test::ok(strpos($tool_src, 'ability_create') !== false, 'the tool file registers ability_create');
KH_Test::ok(strpos($tool_src, 'ability_improve') !== false, 'and ability_improve');
foreach (['approve', 'reject', 'rollback'] as $verb) {
    KH_Test::ok(
        strpos($tool_src, 'Keelhob_Abilities::' . $verb) === false,
        sprintf('and it never calls Keelhob_Abilities::%s() — the model has no tool that reaches it', $verb)
    );
}

/*
 * Every caller of the three owner-only methods, read off the shipped source.
 * If a second door is ever added, this is the assertion that names the file it
 * was added to.
 */
$owner_only = ['approve', 'reject', 'rollback'];
$callers    = [];
$kh_src_files = array_merge(
    glob(KEELHOB_DIR . 'includes/*.php') ?: [],
    glob(KEELHOB_DIR . 'includes/tools/*.php') ?: []
);
foreach ($kh_src_files as $kh_src_file) {
    $src = (string) file_get_contents($kh_src_file);
    foreach ($owner_only as $verb) {
        if (strpos($src, 'Keelhob_Abilities::' . $verb . '(') !== false) {
            $callers[basename($kh_src_file)][] = $verb;
        }
    }
}
ksort($callers);
KH_Test::same(
    ['class-admin-abilities.php'],
    array_keys($callers),
    'approve/reject/rollback are called from exactly ONE file in the whole plugin: the admin screen behind manage_options'
);
KH_Test::same(
    ['approve', 'reject', 'rollback'],
    $callers['class-admin-abilities.php'] ?? [],
    'and it is that file which calls all three'
);

$admin_src = (string) file_get_contents(KEELHOB_DIR . 'includes/class-admin-abilities.php');
KH_Test::contains('admin_post_keelhob_ability_approve', $admin_src, 'approval is an admin-post action');
KH_Test::contains("current_user_can('manage_options')", $admin_src, 'guarded by manage_options');
KH_Test::contains('check_admin_referer', $admin_src, 'and by a nonce');
KH_Test::ok(strpos($admin_src, 'wp_ajax_') === false, 'with no AJAX handler anywhere in it — a thumbs vote may be enhanced by script; an approval may not');
KH_Test::ok(strpos($admin_src, 'register_rest_route') === false, 'and no REST route');
KH_Test::ok(strpos($admin_src, 'kh_ability_confirm') !== false, 'the owner has to tick a confirmation box, checked server-side');
KH_Test::contains('type="checkbox" name="kh_ability_confirm" value="1" required', $admin_src, 'which is a real required checkbox, so it works with JavaScript off');

$rest_src = (string) file_get_contents(KEELHOB_DIR . 'includes/class-rest.php');
KH_Test::ok(strpos($rest_src, 'Keelhob_Abilities') === false, 'the REST layer does not know this engine exists');

KH_Test::group('Approval gate — rollback is not a second approval path');

/*
 * The dangerous shortcut: letting "Roll back to this" accept a pending row.
 * That would be approval without the confirm, without the code on screen, and
 * without a hash written against bytes a human saw. These assertions are
 * against the stub $wpdb, so the row lookup returns null and the method cannot
 * reach its write — what is being asserted is the guard's ORDER: the status
 * check sits in front of every write, which the source confirms.
 */
$abilities_src = (string) file_get_contents(KEELHOB_DIR . 'includes/class-abilities.php');
KH_Test::contains("(string) \$row['status'] !== 'retired'", $abilities_src, 'rollback accepts only a retired version');
KH_Test::contains("(string) \$row['status'] !== 'pending'", $abilities_src, 'and approve accepts only a pending one');
KH_Test::ok(
    strpos($abilities_src, "'code_hash'    => '',") !== false,
    'propose() writes an EMPTY code_hash, so nothing it produces can pass the run guard'
);
KH_Test::ok(
    strpos($abilities_src, "'status'       => 'pending',") !== false,
    'and writes status pending, which is the only status it can produce'
);
KH_Test::ok(
    substr_count($abilities_src, "'status'      => 'active'") === 2,
    'exactly two places set a version live: approve() and rollback(), both owner-only'
);

KH_Test::group('Approval gate — the confirm wording the owner reads');

KH_Test::same(
    'Approve this code? It will run on your site whenever the AI uses this ability.',
    Keelhob_Admin_Abilities::CONFIRM,
    'the sentence is fixed, and it says the code will run on their site'
);
KH_Test::ok(
    substr_count($admin_src, 'self::CONFIRM') >= 2,
    'and it is the same sentence in the checkbox and in the confirm dialog, from one constant'
);

kh_test_reset_options([]);
unset($GLOBALS['kh_pending_ran'], $GLOBALS['kh_tampered_ran']);
delete_option('kh_pending_side_effect');
