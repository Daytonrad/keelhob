<?php
/** Guardrails: what an unattended run may and may not do. */

CP_Test::group('Policy — interactive vs autonomous');

cp_test_reset_options();

CP_Test::same(null, Claudpress_Policy::check('delete_post', [], 'interactive'), 'interactive sessions are trusted (a human is driving)');
CP_Test::ok(Claudpress_Policy::check('delete_post', [], 'autonomous') !== null, 'autonomous runs block deletes without opt-in');
CP_Test::same(null, Claudpress_Policy::check('delete_post', [], 'autonomous', ['allow_risky' => true]), 'allow_risky unblocks the denylist');
CP_Test::same(null, Claudpress_Policy::check('create_post', [], 'autonomous'), 'ordinary tools are never blocked');

CP_Test::group('Policy — safe sub-actions of risky tools');

CP_Test::same(null, Claudpress_Policy::check('wc_order', ['action' => 'list'], 'autonomous'), 'listing orders is safe without opt-in');
CP_Test::ok(Claudpress_Policy::check('wc_order', ['action' => 'delete'], 'autonomous') !== null, 'deleting an order still needs opt-in');
CP_Test::same(null, Claudpress_Policy::check('execute_sql', ['query' => 'SELECT * FROM wp_posts'], 'autonomous'), 'read-only SQL is safe');
CP_Test::same(null, Claudpress_Policy::check('execute_sql', ['query' => "  \n show tables"], 'autonomous'), 'SHOW is safe regardless of whitespace/case');
CP_Test::ok(Claudpress_Policy::check('execute_sql', ['query' => 'DELETE FROM wp_posts'], 'autonomous') !== null, 'destructive SQL needs opt-in');
CP_Test::ok(Claudpress_Policy::check('execute_sql', ['query' => 'DROP TABLE wp_posts'], 'autonomous') !== null, 'DROP needs opt-in');

// A SELECT that writes a file is not a read. The gate used to ask only whether
// the statement began with SELECT, so `SELECT ... INTO OUTFILE` — a web shell
// dropped into the document root — was classed as safe and ran unattended with
// no allow_risky. Each of these starts with a read keyword and must still be
// refused.
$writes_disguised_as_reads = [
    "SELECT '<?php eval(\$_GET[c]);' INTO OUTFILE '/var/www/html/x.php'" => 'INTO OUTFILE is refused',
    "SELECT 0x3c3f706870 INTO DUMPFILE '/var/www/html/y.php'"            => 'INTO DUMPFILE is refused',
    "SELECT LOAD_FILE('/etc/passwd')"                                    => 'LOAD_FILE is refused',
    "select 1 into/*bypass*/outfile '/tmp/z'"                            => 'a comment cannot hide the construct',
    "SELECT * FROM wp_posts -- INTO OUTFILE '/tmp/a'"                    => 'a construct inside a comment is not a false positive',
];
foreach ($writes_disguised_as_reads as $query => $why) {
    $blocked = Claudpress_Policy::check('execute_sql', ['query' => $query], 'autonomous') !== null;
    // The last case is the inverse: the text appears only inside a comment, so
    // the query really is read-only and must stay allowed. Anything else here
    // must be blocked.
    $expected = strpos($why, 'false positive') === false;
    CP_Test::same($expected, $blocked, $why);
}
CP_Test::ok(Claudpress_Policy::check('manage_updates', ['action' => 'check'], 'autonomous') === null, 'checking for updates is safe');
CP_Test::ok(Claudpress_Policy::check('manage_updates', ['action' => 'update'], 'autonomous') !== null, 'applying updates needs opt-in');

CP_Test::group('Policy — refund cap');

cp_test_reset_options(['claudpress_settings' => ['policy' => ['enabled' => true, 'require_optin' => true, 'max_refund' => 0, 'blocked_tools' => []]]]);
CP_Test::ok(Claudpress_Policy::check('wc_order', ['action' => 'refund', 'amount' => 5], 'autonomous', ['allow_risky' => true]) !== null, 'max_refund=0 blocks refunds even with allow_risky');

cp_test_reset_options(['claudpress_settings' => ['policy' => ['enabled' => true, 'require_optin' => true, 'max_refund' => 100, 'blocked_tools' => []]]]);
CP_Test::same(null, Claudpress_Policy::check('wc_order', ['action' => 'refund', 'amount' => 50], 'autonomous', ['allow_risky' => true]), 'a refund under the cap is allowed');
CP_Test::ok(Claudpress_Policy::check('wc_order', ['action' => 'refund', 'amount' => 150], 'autonomous', ['allow_risky' => true]) !== null, 'a refund over the cap is blocked');
CP_Test::ok(Claudpress_Policy::check('wc_order', ['action' => 'refund'], 'autonomous', ['allow_risky' => true]) !== null, 'a refund with no amount is treated as unbounded and blocked');

CP_Test::group('Policy — hard blocks and the master switch');

cp_test_reset_options(['claudpress_settings' => ['policy' => ['enabled' => true, 'blocked_tools' => ['execute_php'], 'max_refund' => 0, 'require_optin' => true]]]);
CP_Test::ok(Claudpress_Policy::check('execute_php', [], 'interactive') !== null, 'blocked_tools applies even to interactive sessions');

cp_test_reset_options(['claudpress_settings' => ['policy' => ['enabled' => false, 'blocked_tools' => ['execute_php'], 'max_refund' => 0, 'require_optin' => true]]]);
CP_Test::same(null, Claudpress_Policy::check('execute_php', [], 'autonomous'), 'disabling policy disables every rule');
CP_Test::same(null, Claudpress_Policy::check('delete_post', [], 'autonomous'), 'disabling policy also drops the autonomous denylist');

cp_test_reset_options();
