<?php
/**
 * manage_plugin/manage_theme `activate` guarded with Claudpress_Health::guard().
 *
 * Neither manage_plugin() nor manage_theme() is wired into tests/bootstrap.php
 * (they're registered at runtime via Claudpress_Tools_Site::register(), the
 * same way class-tools-elementor.php and class-tools-filesystem.php are only
 * pulled in by the test files that need them), so this file requires the
 * class directly and stubs the handful of WordPress plugin/theme functions it
 * calls, in the same guarded-function-exists style as tests/bootstrap.php.
 *
 * manage_plugin() unconditionally does
 *     require_once ABSPATH . 'wp-admin/includes/plugin.php';
 * so a scratch stub is written into the fake ABSPATH the same way bootstrap.php
 * writes wp-admin/includes/upgrade.php for dbDelta() — and unshifted onto the
 * shared cp_test_scratch_paths cleanup list (ahead of the wp-admin/includes
 * directory already queued there) so shutdown removes the file before it tries
 * to rmdir() the directory it lives in.
 *
 * THE BUG THIS PINS: manage_theme()'s revert must be built from the stylesheet
 * captured BEFORE switch_theme() runs. Capturing it after would capture the
 * NEW theme, so "reverting" would switch to the broken theme again — a revert
 * that does nothing, silently, in exactly the situation where it matters. Test
 * group 1 below asserts switch_theme() is called a second time with the
 * original stylesheet, not the new one.
 */

require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-site.php';

// ---- stub the WordPress plugin/theme functions manage_plugin/manage_theme call --

if (!function_exists('activate_plugin')) {
    function activate_plugin($plugin)
    {
        $GLOBALS['cp_test_activate_calls'][] = $plugin;
        $GLOBALS['cp_test_active_plugin'] = $plugin;
        return null; // real activate_plugin() returns null on success, WP_Error on failure
    }
}

if (!function_exists('deactivate_plugins')) {
    function deactivate_plugins($plugin)
    {
        $GLOBALS['cp_test_deactivate_calls'][] = $plugin;
        if (($GLOBALS['cp_test_active_plugin'] ?? null) === $plugin) {
            $GLOBALS['cp_test_active_plugin'] = null;
        }
    }
}

if (!function_exists('get_plugins')) {
    function get_plugins()
    {
        return $GLOBALS['cp_test_plugins'] ?? [];
    }
}

if (!function_exists('get_stylesheet')) {
    function get_stylesheet()
    {
        return $GLOBALS['cp_test_stylesheet'] ?? 'starter-theme';
    }
}

if (!function_exists('switch_theme')) {
    function switch_theme($stylesheet)
    {
        $GLOBALS['cp_test_switch_theme_calls'][] = $stylesheet;
        $GLOBALS['cp_test_stylesheet'] = $stylesheet;
    }
}

// manage_plugin() requires this path unconditionally, for every action.
if (!is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
    @file_put_contents(
        ABSPATH . 'wp-admin/includes/plugin.php',
        "<?php\n// Test scratch stub, written by test-tools-site-guard.php and removed at\n"
        . "// shutdown. The real activate_plugin()/deactivate_plugins()/get_plugins()\n"
        . "// are stubbed as globals by that test file; this only needs to exist so\n"
        . "// manage_plugin()'s require_once resolves.\n"
    );
    array_unshift($GLOBALS['cp_test_scratch_paths'], ABSPATH . 'wp-admin/includes/plugin.php');
}

function cp_test_site_guard_reset(): void
{
    $GLOBALS['cp_test_activate_calls']      = [];
    $GLOBALS['cp_test_deactivate_calls']    = [];
    $GLOBALS['cp_test_switch_theme_calls']  = [];
    $GLOBALS['cp_test_active_plugin']       = null;
    $GLOBALS['cp_test_stylesheet']          = 'starter-theme';
    cp_test_reset_ajax(); // also clears the queued HTTP responses
}

// =============================================================================
// 1. The pre-switch capture — the bug worth pinning
// =============================================================================

CP_Test::group('manage_theme activate — stylesheet captured before switch_theme()');

cp_test_site_guard_reset();
cp_test_http_queue(503, ''); // probe with the new theme in place: broken
cp_test_http_queue(200, ''); // control with it reverted: healthy

$result = Claudpress_Tools_Site::manage_theme(['action' => 'activate', 'theme' => 'broken-theme']);

CP_Test::same(
    ['broken-theme', 'starter-theme'],
    $GLOBALS['cp_test_switch_theme_calls'],
    'switch_theme() is called with the new theme, then a second time with the ORIGINAL stylesheet — ' .
    'capturing get_stylesheet() after switch_theme() would have logged ["broken-theme", "broken-theme"] here, ' .
    'a revert that silently re-activates the broken theme'
);
CP_Test::same('FAILED', $result['front_end_check'], 'reverting to the original theme fixed the site');
CP_Test::same('starter-theme', $result['active_theme'], 'the result reports the reverted (original) theme as active');

// =============================================================================
// 2. Healthy activation — no annotations
// =============================================================================

CP_Test::group('manage_plugin activate — healthy site, result untouched');

cp_test_site_guard_reset();
cp_test_http_queue(200, '');

$result = Claudpress_Tools_Site::manage_plugin(['action' => 'activate', 'plugin' => 'good/good.php']);

CP_Test::same(
    ['success' => true, 'plugin' => 'good/good.php', 'active' => true],
    $result,
    'a healthy probe leaves the normal activation result exactly as it was, no annotation added'
);
CP_Test::same([], $GLOBALS['cp_test_deactivate_calls'], 'the revert never runs when the site is healthy');

// =============================================================================
// 3. Activation that breaks the site — reverted, and reported as not active
// =============================================================================

CP_Test::group('manage_plugin activate — breaks the site, reverted');

cp_test_site_guard_reset();
cp_test_http_queue(503, ''); // probe: broken with the plugin active
cp_test_http_queue(200, ''); // control: healthy once deactivated

$result = Claudpress_Tools_Site::manage_plugin(['action' => 'activate', 'plugin' => 'bad/bad.php']);

CP_Test::same(['bad/bad.php'], $GLOBALS['cp_test_deactivate_calls'], 'the plugin is deactivated as the revert');
CP_Test::same('FAILED', $result['front_end_check'], 'deactivating fixed the site, so this plugin caused it');
CP_Test::same(false, $result['active'], 'the result honestly reports the plugin as NOT active — active:true next to a note saying it was deactivated would be worse than either alone');
CP_Test::ok(null === $GLOBALS['cp_test_active_plugin'], 'the plugin is actually left deactivated');

// =============================================================================
// 4. Activation whose revert does NOT help — re-activated, site reported down
// =============================================================================

CP_Test::group('manage_plugin activate — revert does not help, so it is put back');

cp_test_site_guard_reset();
cp_test_http_queue(503, ''); // probe: broken with the plugin active
cp_test_http_queue(503, ''); // control: still broken once deactivated -> not this plugin's fault

$result = Claudpress_Tools_Site::manage_plugin(['action' => 'activate', 'plugin' => 'innocent/innocent.php']);

CP_Test::same(['innocent/innocent.php'], $GLOBALS['cp_test_deactivate_calls'], 'guard() still runs the revert once, as its control probe');
CP_Test::same('passed', $result['front_end_check'], 'the site is still broken with it off too, so this plugin was not the cause');
CP_Test::same(true, $result['site_down'] ?? null, 'and the result says the site is down, whoever broke it');
CP_Test::contains('not the cause', (string) $result['note'], 'the note explains this plugin is innocent');
CP_Test::same(
    ['innocent/innocent.php', 'innocent/innocent.php'],
    $GLOBALS['cp_test_activate_calls'],
    'the plugin is re-activated by manage_plugin() itself — an innocent activation must not stay reverted'
);
CP_Test::same(true, $result['active'], 'the result reports it as active again, matching reality');
CP_Test::same('innocent/innocent.php', $GLOBALS['cp_test_active_plugin'], 'and it really is active again, not just claimed to be');

// =============================================================================
// 5. list performs no probe
// =============================================================================

CP_Test::group('manage_plugin list — no probe, queued responses untouched');

cp_test_site_guard_reset();
cp_test_http_queue(503, ''); // if list probed, this would be consumed and/or trip a false alarm
$GLOBALS['cp_test_plugins'] = ['x/x.php' => ['Name' => 'X', 'Version' => '1.0']];

$result = Claudpress_Tools_Site::manage_plugin(['action' => 'list']);

CP_Test::ok($result['success'], 'list still succeeds');
CP_Test::same(1, count($GLOBALS['cp_test_http']), 'the queued HTTP response is untouched — list never calls the probe');
unset($GLOBALS['cp_test_plugins']);

cp_test_site_guard_reset();
