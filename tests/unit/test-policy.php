<?php
/** Guardrails: what an unattended run may and may not do. */

KH_Test::group('Policy — interactive vs autonomous');

kh_test_reset_options();

KH_Test::same(null, Keelhob_Policy::check('delete_post', [], 'interactive'), 'interactive sessions are trusted (a human is driving)');
KH_Test::ok(Keelhob_Policy::check('delete_post', [], 'autonomous') !== null, 'autonomous runs block deletes without opt-in');
KH_Test::same(null, Keelhob_Policy::check('delete_post', [], 'autonomous', ['allow_risky' => true]), 'allow_risky unblocks the denylist');
KH_Test::same(null, Keelhob_Policy::check('create_post', [], 'autonomous'), 'ordinary tools are never blocked');

KH_Test::group('Policy — safe sub-actions of risky tools');

KH_Test::same(null, Keelhob_Policy::check('wc_order', ['action' => 'list'], 'autonomous'), 'listing orders is safe without opt-in');
KH_Test::ok(Keelhob_Policy::check('wc_order', ['action' => 'delete'], 'autonomous') !== null, 'deleting an order still needs opt-in');
KH_Test::same(null, Keelhob_Policy::check('execute_sql', ['query' => 'SELECT * FROM wp_posts'], 'autonomous'), 'read-only SQL is safe');
KH_Test::same(null, Keelhob_Policy::check('execute_sql', ['query' => "  \n show tables"], 'autonomous'), 'SHOW is safe regardless of whitespace/case');
KH_Test::ok(Keelhob_Policy::check('execute_sql', ['query' => 'DELETE FROM wp_posts'], 'autonomous') !== null, 'destructive SQL needs opt-in');
KH_Test::ok(Keelhob_Policy::check('execute_sql', ['query' => 'DROP TABLE wp_posts'], 'autonomous') !== null, 'DROP needs opt-in');

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
    $blocked = Keelhob_Policy::check('execute_sql', ['query' => $query], 'autonomous') !== null;
    // The last case is the inverse: the text appears only inside a comment, so
    // the query really is read-only and must stay allowed. Anything else here
    // must be blocked.
    $expected = strpos($why, 'false positive') === false;
    KH_Test::same($expected, $blocked, $why);
}
KH_Test::ok(Keelhob_Policy::check('manage_updates', ['action' => 'check'], 'autonomous') === null, 'checking for updates is safe');
KH_Test::ok(Keelhob_Policy::check('manage_updates', ['action' => 'update'], 'autonomous') !== null, 'applying updates needs opt-in');

KH_Test::group('Policy — refund cap');

kh_test_reset_options(['keelhob_settings' => ['policy' => ['enabled' => true, 'require_optin' => true, 'max_refund' => 0, 'blocked_tools' => []]]]);
KH_Test::ok(Keelhob_Policy::check('wc_order', ['action' => 'refund', 'amount' => 5], 'autonomous', ['allow_risky' => true]) !== null, 'max_refund=0 blocks refunds even with allow_risky');

kh_test_reset_options(['keelhob_settings' => ['policy' => ['enabled' => true, 'require_optin' => true, 'max_refund' => 100, 'blocked_tools' => []]]]);
KH_Test::same(null, Keelhob_Policy::check('wc_order', ['action' => 'refund', 'amount' => 50], 'autonomous', ['allow_risky' => true]), 'a refund under the cap is allowed');
KH_Test::ok(Keelhob_Policy::check('wc_order', ['action' => 'refund', 'amount' => 150], 'autonomous', ['allow_risky' => true]) !== null, 'a refund over the cap is blocked');
KH_Test::ok(Keelhob_Policy::check('wc_order', ['action' => 'refund'], 'autonomous', ['allow_risky' => true]) !== null, 'a refund with no amount is treated as unbounded and blocked');

KH_Test::group('Policy — hard blocks and the master switch');

kh_test_reset_options(['keelhob_settings' => ['policy' => ['enabled' => true, 'blocked_tools' => ['execute_php'], 'max_refund' => 0, 'require_optin' => true]]]);
KH_Test::ok(Keelhob_Policy::check('execute_php', [], 'interactive') !== null, 'blocked_tools applies even to interactive sessions');

kh_test_reset_options(['keelhob_settings' => ['policy' => ['enabled' => false, 'blocked_tools' => ['execute_php'], 'max_refund' => 0, 'require_optin' => true]]]);
KH_Test::same(null, Keelhob_Policy::check('execute_php', [], 'autonomous'), 'disabling policy disables every rule');
KH_Test::same(null, Keelhob_Policy::check('delete_post', [], 'autonomous'), 'disabling policy also drops the autonomous denylist');

kh_test_reset_options();
