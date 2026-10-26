<?php
/**
 * The read-only Site-Care / Optimize tools.
 *
 * These five tools are the first thing an owner points at a site they did not
 * build, and their whole value is that the numbers are right. A speed score
 * that is wrong by one severity band, a link scanner that silently skips
 * Elementor pages, or a plugin check that calls a maintained plugin abandoned
 * all produce the same failure: the agent tells the owner something false,
 * confidently, and the owner acts on it.
 *
 * So every threshold is pinned at its boundary (the value that must NOT fire
 * and the next one up, which must), not somewhere comfortably past it.
 */

// tests/bootstrap.php loads includes/, not includes/tools/ — each test file
// pulls in the tool files it needs, so a filtered run still works.
require_once KEELHOB_DIR . 'includes/tools/class-tools-sitecare.php';

KH_Test::group('Site care — the score arithmetic');

KH_Test::same(100, Keelhob_Tools_Sitecare::score_findings([]), 'a site with nothing wrong scores 100');
KH_Test::same(85, Keelhob_Tools_Sitecare::score_findings([['severity' => 'high']]), 'one high costs 15');
KH_Test::same(93, Keelhob_Tools_Sitecare::score_findings([['severity' => 'medium']]), 'one medium costs 7');
KH_Test::same(97, Keelhob_Tools_Sitecare::score_findings([['severity' => 'low']]), 'one low costs 3');
KH_Test::same(68, Keelhob_Tools_Sitecare::score_findings([
    ['severity' => 'high'], ['severity' => 'medium'], ['severity' => 'low'], ['severity' => 'medium'],
]), 'and they add up (15 + 7 + 3 + 7 = 32 off)');
KH_Test::same(100, Keelhob_Tools_Sitecare::score_findings([['severity' => 'nonsense']]), 'an unknown severity costs nothing rather than guessing');

// The floor is 1, not 0. A 0 reads as "could not measure"; a site that scores
// 1 was very much measured, and the difference matters to whoever reads it.
$kh_sc_many = array_fill(0, 20, ['severity' => 'high']);
KH_Test::same(1, Keelhob_Tools_Sitecare::score_findings($kh_sc_many), 'twenty highs floor at 1, never 0 or negative');

KH_Test::group('Site care — speed_audit thresholds, at the boundary');

/** A site with nothing wrong, as facts. */
$kh_sc_clean = [
    'load_ms' => 400, 'bytes' => 50000, 'scripts' => 10, 'styles' => 5,
    'compressed' => true, 'cache_plugin' => 'wp-rocket', 'object_cache' => true,
    'autoload_bytes' => 200 * 1024, 'revisions' => 10, 'expired_transients' => 5,
    'drafts_trash' => 3, 'spam' => 2, 'big_images' => [], 'plugins' => 12,
    'php' => '8.3.0',
];

$kh_sc_findings = Keelhob_Tools_Sitecare::analyze_speed($kh_sc_clean);
KH_Test::same([], $kh_sc_findings, 'a healthy site produces no findings at all');
KH_Test::same(100, Keelhob_Tools_Sitecare::score_findings($kh_sc_findings), 'and therefore scores 100');

/**
 * One fact changed, and what must come out of it.
 *
 * @var array<int, array{0:array<string,mixed>,1:?string,2:string}> patch, expected severity (null = no finding), name
 */
$kh_sc_cases = [
    [['load_ms' => 800], null, 'a homepage at exactly 800 ms is not flagged'],
    [['load_ms' => 801], 'medium', 'a homepage at 801 ms is a medium'],
    [['load_ms' => 1500], 'medium', 'a homepage at exactly 1500 ms is still only a medium'],
    [['load_ms' => 1501], 'high', 'a homepage at 1501 ms is a high'],
    [['scripts' => 25], null, '25 scripts is allowed'],
    [['scripts' => 26], 'medium', '26 scripts is a medium'],
    [['styles' => 15], null, '15 stylesheets is allowed'],
    [['styles' => 16], 'medium', '16 stylesheets is a medium'],
    [['compressed' => false], 'medium', 'no compression is a medium'],
    [['cache_plugin' => ''], 'high', 'no caching plugin is a high'],
    [['object_cache' => false], 'low', 'no persistent object cache is only a low'],
    [['autoload_bytes' => 800 * 1024], null, 'autoload at exactly 800 KB is allowed'],
    [['autoload_bytes' => 801 * 1024], 'medium', 'autoload over 800 KB is a medium'],
    [['autoload_bytes' => 2000 * 1024], 'medium', 'autoload at exactly 2000 KB is still a medium'],
    [['autoload_bytes' => 2001 * 1024], 'high', 'autoload over 2000 KB is a high'],
    [['revisions' => 200], null, '200 revisions is allowed'],
    [['revisions' => 201], 'low', '201 revisions is a low'],
    [['expired_transients' => 100], null, '100 expired transients is allowed'],
    [['expired_transients' => 101], 'low', '101 expired transients is a low'],
    [['drafts_trash' => 50], null, '50 auto-drafts and trashed posts is allowed'],
    [['drafts_trash' => 51], 'low', '51 is a low'],
    [['spam' => 100], null, '100 spam comments is allowed'],
    [['spam' => 101], 'low', '101 spam comments is a low'],
    [['plugins' => 30], null, '30 plugins is allowed'],
    [['plugins' => 31], 'low', '31 plugins is a low'],
    [['php' => '8.1.0'], null, 'PHP 8.1 is the floor and passes it'],
    [['php' => '8.0.30'], 'medium', 'PHP 8.0 is a medium'],
    [['php' => '7.4.33'], 'medium', 'PHP 7.4 is a medium too'],
];

foreach ($kh_sc_cases as [$kh_patch, $kh_want, $kh_label]) {
    $kh_out = Keelhob_Tools_Sitecare::analyze_speed(array_merge($kh_sc_clean, $kh_patch));
    if ($kh_want === null) {
        KH_Test::same([], $kh_out, $kh_label);
        continue;
    }
    KH_Test::same(1, count($kh_out), $kh_label . ' (one finding)');
    KH_Test::same($kh_want, $kh_out[0]['severity'] ?? '', $kh_label);
}

// Images are the one finding whose severity depends on how many there are.
$kh_sc_one_image = Keelhob_Tools_Sitecare::analyze_speed(array_merge($kh_sc_clean, [
    'big_images' => [['id' => 1, 'file' => 'a.jpg', 'kb' => 900, 'width' => 3000]],
]));
KH_Test::same('medium', $kh_sc_one_image[0]['severity'] ?? '', 'one oversized image is a medium');
KH_Test::contains('1 oversized image', $kh_sc_one_image[0]['title'] ?? '', 'and the title is singular');

$kh_sc_ten = [];
for ($kh_i = 1; $kh_i <= 10; $kh_i++) {
    $kh_sc_ten[] = ['id' => $kh_i, 'file' => $kh_i . '.jpg', 'kb' => 400, 'width' => 1000];
}
KH_Test::same('medium', (Keelhob_Tools_Sitecare::analyze_speed(array_merge($kh_sc_clean, ['big_images' => $kh_sc_ten]))[0]['severity'] ?? ''), 'exactly ten oversized images is still a medium');
$kh_sc_ten[] = ['id' => 11, 'file' => '11.jpg', 'kb' => 400, 'width' => 1000];
KH_Test::same('high', (Keelhob_Tools_Sitecare::analyze_speed(array_merge($kh_sc_clean, ['big_images' => $kh_sc_ten]))[0]['severity'] ?? ''), 'eleven makes it a high');

// A genuinely bad site, scored end to end, because the per-finding tests above
// could all pass while the sum came out wrong.
$kh_sc_bad = Keelhob_Tools_Sitecare::analyze_speed([
    'load_ms' => 4000, 'bytes' => 900000, 'scripts' => 60, 'styles' => 30,
    'compressed' => false, 'cache_plugin' => '', 'object_cache' => false,
    'autoload_bytes' => 3000 * 1024, 'revisions' => 5000, 'expired_transients' => 900,
    'drafts_trash' => 400, 'spam' => 2000, 'big_images' => $kh_sc_ten, 'plugins' => 48,
    'php' => '7.4.0',
]);
KH_Test::same(14, count($kh_sc_bad), 'the worst case produces all fourteen findings');
KH_Test::same(1, Keelhob_Tools_Sitecare::score_findings($kh_sc_bad), 'and it floors the score at 1');

KH_Test::group('Site care — every finding names a tool that actually ships');

/*
 * THE POINT OF THE WHOLE FAMILY. A report an agent cannot act on gets
 * summarised and dropped, so each finding carries the name of the tool that
 * fixes it — and that name has to be a tool that EXISTS. "Run image_compress"
 * is worse than no advice at all while image_compress is unbuilt: the agent
 * tries it, gets "Unknown tool", and tells the owner the plugin is broken.
 */
if (!class_exists('WooCommerce')) {
    class WooCommerce {}
}
foreach (glob(KEELHOB_DIR . 'includes/tools/class-tools-*.php') ?: [] as $kh_file) {
    require_once $kh_file;
}
$kh_sc_registry = new Keelhob_Tool_Registry();
foreach ([
    'Core', 'Filesystem', 'Content', 'Site', 'WooCommerce', 'SEO', 'Memory',
    'Skills', 'Snippets', 'Elementor', 'Ops', 'Automation', 'Payments',
    'Deploy', 'Readiness', 'Sitecare', 'Sitecare_Write',
] as $kh_group) {
    call_user_func(['Keelhob_Tools_' . $kh_group, 'register'], $kh_sc_registry);
}
$kh_sc_real = $kh_sc_registry->names();

$kh_sc_all = $kh_sc_bad;
$kh_sc_all = array_merge($kh_sc_all, $kh_sc_one_image);
$kh_sc_all = array_merge($kh_sc_all, Keelhob_Tools_Sitecare::analyze_wporg('ghost-plugin', ['error' => 'closed', 'closed' => true, 'description' => 'Reason: Security Issue.'], time()));
$kh_sc_all = array_merge($kh_sc_all, Keelhob_Tools_Sitecare::analyze_wporg('old-plugin', ['slug' => 'old-plugin', 'last_updated' => '2019-01-01 10:00am GMT'], time()));
$kh_sc_all = array_merge($kh_sc_all, Keelhob_Tools_Sitecare::analyze_wpscan('leaky', ['leaky' => ['vulnerabilities' => [
    ['title' => 'Stored XSS', 'fixed_in' => '9.9.9'],
    ['title' => 'Unauthenticated RCE', 'fixed_in' => null],
]]], '1.0.0'));

$kh_sc_unreal = [];
$kh_sc_nulls  = 0;
foreach ($kh_sc_all as $kh_f) {
    foreach (['severity', 'title', 'evidence', 'fix', 'fix_tool'] as $kh_key) {
        if (!array_key_exists($kh_key, $kh_f)) {
            $kh_sc_unreal[] = 'a finding is missing "' . $kh_key . '"';
        }
    }
    $kh_tool = $kh_f['fix_tool'] ?? null;
    if ($kh_tool === null) {
        $kh_sc_nulls++;
        continue;
    }
    if (!in_array($kh_tool, $kh_sc_real, true)) {
        $kh_sc_unreal[] = $kh_f['title'] . ' -> ' . $kh_tool;
    }
}
KH_Test::ok(count($kh_sc_all) > 15, 'a representative pile of findings was built (' . count($kh_sc_all) . ')');
KH_Test::same([], $kh_sc_unreal, 'every fix_tool is a tool the plugin actually registers');
KH_Test::ok(count($kh_sc_all) - $kh_sc_nulls > 0, 'and most findings do name one');

// Exactly one finding is allowed to name no tool: a PHP version is the host's
// to change, and pointing the agent at manage_updates for it would be a lie it
// would then act on. Any OTHER tool-less finding is a gap, not a decision.
$kh_sc_toolless = [];
foreach ($kh_sc_all as $kh_f) {
    if (($kh_f['fix_tool'] ?? null) === null) {
        $kh_sc_toolless[] = (string) $kh_f['title'];
    }
}
KH_Test::same(1, count($kh_sc_toolless), 'only one kind of finding names no tool');
KH_Test::contains('PHP 7.4', $kh_sc_toolless[0] ?? '', 'and it is the PHP version, which no tool can fix');
KH_Test::contains('the host', (string) ($kh_sc_bad[13]['fix'] ?? ''), 'its advice says plainly whose job it is');

KH_Test::group('Site care — link extraction, including Elementor');

$kh_sc_links = Keelhob_Tools_Sitecare::extract_links(
    '<a href="https://example.com/ok">ok</a>'
    . '<a href="/relative">root relative</a>'
    . '<a href="//cdn.example.net/x.js">protocol relative</a>'
    . '<a href="https://example.com/ok#section">same page, fragment</a>'
    . '<a href="mailto:a@b.c">mail</a><a href="tel:+971">call</a>'
    . "<a href='#top'>anchor</a><a href=\"javascript:void(0)\">js</a>"
    . '<a href="sms:123">sms</a>',
    '',
    'https://mysite.test/'
);
sort($kh_sc_links);
KH_Test::same([
    'https://cdn.example.net/x.js',
    'https://example.com/ok',
    'https://mysite.test/relative',
], $kh_sc_links, 'hrefs are absolute, de-duplicated, fragment-stripped, and mailto/tel/sms/anchor/javascript are skipped');

/*
 * Elementor stores the layout as JSON with every slash escaped. A scanner that
 * reads only post_content reports a clean bill of health on an Elementor site,
 * which is the worst answer available: confidently wrong. Keelhob BUILDS
 * Elementor pages, so this is the normal case here, not the edge case.
 */
$kh_sc_el = Keelhob_Tools_Sitecare::extract_links(
    '',
    '[{"settings":{"link":{"url":"https:\\/\\/example.com\\/elementor-page"}},"editor":"<a href=\\"https:\\/\\/example.com\\/inside-editor\\">x<\\/a>"}]',
    'https://mysite.test/'
);
sort($kh_sc_el);
KH_Test::same([
    'https://example.com/elementor-page',
    'https://example.com/inside-editor',
], $kh_sc_el, 'Elementor url values and escaped hrefs are both found, with \\/ unescaped');

KH_Test::same([], Keelhob_Tools_Sitecare::extract_links('<a href="">empty</a><a>no href</a>', '', 'https://mysite.test/'), 'nothing to check means nothing returned');

KH_Test::group('Site care — what counts as broken');

foreach ([0 => true, 200 => false, 301 => false, 302 => false, 399 => false, 400 => true, 403 => true, 404 => true, 500 => true, 503 => true] as $kh_code => $kh_broken) {
    KH_Test::same($kh_broken, Keelhob_Tools_Sitecare::is_broken_status((int) $kh_code), 'HTTP ' . $kh_code . ' is ' . ($kh_broken ? 'broken' : 'fine'));
}
foreach ([0, 403, 405, 501] as $kh_code) {
    KH_Test::ok(Keelhob_Tools_Sitecare::should_retry_with_get($kh_code), 'a HEAD answered ' . $kh_code . ' is retried with GET, because plenty of servers refuse HEAD');
}
foreach ([200, 404, 500] as $kh_code) {
    KH_Test::ok(!Keelhob_Tools_Sitecare::should_retry_with_get($kh_code), 'but ' . $kh_code . ' is taken at its word');
}

KH_Test::group('Site care — plugin overlap groups');

KH_Test::same(16, count(Keelhob_Tools_Sitecare::CACHE_SLUGS), 'all sixteen caching plugins are listed');
KH_Test::same(6, count(Keelhob_Tools_Sitecare::OVERLAP_GROUPS), 'six overlap groups: SEO, caching, security, image optimization, page builders, SMTP');

KH_Test::same([], Keelhob_Tools_Sitecare::find_overlaps(['wordpress-seo', 'wp-rocket', 'elementor']), 'one plugin per job is not a conflict');

$kh_sc_over = Keelhob_Tools_Sitecare::find_overlaps(['wordpress-seo', 'seo-by-rank-math', 'wp-rocket', 'w3-total-cache', 'elementor']);
KH_Test::same(2, count($kh_sc_over), 'two SEO plugins and two caching plugins is two conflicts');
KH_Test::same('SEO', $kh_sc_over[0]['group'] ?? '', 'named by the job they share');
KH_Test::same(['wordpress-seo', 'seo-by-rank-math'], $kh_sc_over[0]['plugins'] ?? [], 'and both culprits are named');

KH_Test::group('Site care — the debug log, read per plugin');

$kh_sc_log = Keelhob_Tools_Sitecare::parse_debug_log(implode("\n", [
    '[01-Jan-2026 10:00:00 UTC] PHP Warning:  Undefined array key "x" in /var/www/wp-content/plugins/noisy-plugin/inc/thing.php on line 12',
    '[01-Jan-2026 10:00:01 UTC] PHP Warning:  Undefined array key "y" in /var/www/wp-content/plugins/noisy-plugin/inc/thing.php on line 14',
    '[01-Jan-2026 10:00:02 UTC] PHP Fatal error:  Uncaught Error in /var/www/wp-content/plugins/noisy-plugin/inc/boom.php on line 3',
    '[01-Jan-2026 10:00:03 UTC] PHP Notice:  Something in C:\\sites\\wp-content\\plugins\\windows-plugin\\a.php on line 9',
    '[01-Jan-2026 10:00:04 UTC] PHP Warning:  In the theme, not a plugin, /var/www/wp-content/themes/twenty/x.php on line 1',
    '[01-Jan-2026 10:00:05 UTC] Some ordinary log line nobody cares about',
]));
KH_Test::same(2, count($kh_sc_log), 'only the two plugins with PHP errors are reported');
KH_Test::same('noisy-plugin', $kh_sc_log[0]['slug'] ?? '', 'busiest first');
KH_Test::same(3, $kh_sc_log[0]['errors'] ?? 0, 'with every one of its lines counted');
// The example kept is the most severe line, not the first one seen: a fatal
// error is what the agent needs to read, and it is usually preceded by the
// warnings that led to it.
KH_Test::contains('boom.php', (string) ($kh_sc_log[0]['example'] ?? ''), 'and the FATAL is the example kept, not the first warning');
KH_Test::same('windows-plugin', $kh_sc_log[1]['slug'] ?? '', 'a Windows path with backslashes is attributed too');

// A 20 KB stack trace on one line is exactly what the 400 KB tail limit exists
// to avoid shipping, so the example is trimmed as well.
$kh_sc_long = Keelhob_Tools_Sitecare::parse_debug_log('PHP Fatal error: ' . str_repeat('x', 5000) . ' /wp-content/plugins/huge/a.php');
KH_Test::ok(strlen((string) ($kh_sc_long[0]['example'] ?? '')) <= 300, 'an enormous log line is trimmed, not shipped whole');

KH_Test::group('Site care — plugins that need a newer PHP or WordPress');

$kh_sc_req = Keelhob_Tools_Sitecare::requires_mismatches([
    'modern/modern.php' => ['Name' => 'Modern', 'RequiresPHP' => '8.2', 'RequiresWP' => '6.5'],
    'fine/fine.php'     => ['Name' => 'Fine', 'RequiresPHP' => '7.4', 'RequiresWP' => '5.0'],
    'quiet/quiet.php'   => ['Name' => 'Quiet'],
], '8.0.30', '6.4');
KH_Test::same(2, count($kh_sc_req), 'one plugin, two mismatches (PHP and WordPress), and nothing from the other two');
KH_Test::same('PHP', $kh_sc_req[0]['what'] ?? '', 'the PHP one is named as such');
KH_Test::same('8.2', $kh_sc_req[0]['needs'] ?? '', 'with what it needs');
KH_Test::same('6.4', $kh_sc_req[1]['has'] ?? '', 'and what the site actually has');

KH_Test::group('Site care — what WordPress.org and WPScan are allowed to conclude');

$kh_sc_now = strtotime('2026-10-24 12:00:00');

KH_Test::same([], Keelhob_Tools_Sitecare::analyze_wporg('x', null, $kh_sc_now), 'a lookup that failed concludes nothing — silence is not evidence of health');
KH_Test::same([], Keelhob_Tools_Sitecare::analyze_wporg('x', [], $kh_sc_now), 'and neither does an empty answer');
KH_Test::same([], Keelhob_Tools_Sitecare::analyze_wporg('x', ['slug' => 'x', 'last_updated' => '2026-09-01 10:00am GMT'], $kh_sc_now), 'a plugin updated last month is fine');
KH_Test::same([], Keelhob_Tools_Sitecare::analyze_wporg('x', ['slug' => 'x', 'last_updated' => '2024-11-01 10:00am GMT'], $kh_sc_now), 'and so is one updated 23 months ago — the line is two years, not "a while"');

$kh_sc_old = Keelhob_Tools_Sitecare::analyze_wporg('x', ['slug' => 'x', 'last_updated' => '2024-09-01 10:00am GMT'], $kh_sc_now);
KH_Test::same('medium', $kh_sc_old[0]['severity'] ?? '', 'past two years it is abandoned, a medium');

/*
 * CLOSED IS NOT THE SAME AS NOT LISTED, and the first real run against the rig
 * is what proved it. The plugins API answers both with HTTP 404 and only the
 * body tells them apart:
 *
 *   closed  -> {"error":"closed","closed":true,"description":"… Reason: Security Issue."}
 *   unknown -> {"error":"Plugin not found."}
 *
 * Conflating them made the rig report that each of its three self-hosted
 * plugins — Keelhob among them — had "been removed from WordPress.org, usually
 * for a security problem": three false security alarms about three plugins that
 * were never in the directory at all. Keelhob itself ships from GitHub. A
 * report that cries wolf about that gets ignored wholesale, taking the true
 * findings with it.
 *
 * Verified against the live API: display-widgets (closed 2021, Security Issue)
 * and pipdig-power-pack (gone entirely).
 */
KH_Test::same('closed', Keelhob_Tools_Sitecare::wporg_state(['error' => 'closed', 'closed' => true, 'description' => 'Reason: Security Issue.']), 'a closed plugin reads as closed');
KH_Test::same('unknown', Keelhob_Tools_Sitecare::wporg_state(['error' => 'Plugin not found.']), 'a plugin that was never in the directory reads as unknown, NOT as closed');
KH_Test::same('listed', Keelhob_Tools_Sitecare::wporg_state(['slug' => 'akismet', 'last_updated' => '2026-10-01 10:00am GMT', 'version' => '5.7.2']), 'a normal answer reads as listed');
KH_Test::same('unreachable', Keelhob_Tools_Sitecare::wporg_state(null), 'and a failed lookup reads as unreachable');
KH_Test::same('unreachable', Keelhob_Tools_Sitecare::wporg_state([]), 'as does an empty one');

KH_Test::same([], Keelhob_Tools_Sitecare::analyze_wporg('keelhob', ['error' => 'Plugin not found.'], $kh_sc_now), 'so a self-hosted plugin produces NO finding at all — shipping from GitHub is not a defect');

$kh_sc_closed = Keelhob_Tools_Sitecare::analyze_wporg('x', ['error' => 'closed', 'closed' => true, 'description' => 'This plugin has been closed as of January 30, 2021. Reason: Security Issue.'], $kh_sc_now);
KH_Test::same(1, count($kh_sc_closed), 'a closed plugin is reported once, not twice');
KH_Test::same('high', $kh_sc_closed[0]['severity'] ?? '', 'and a removal is a high — plugins are usually pulled for a security problem');
KH_Test::contains('removed', strtolower((string) ($kh_sc_closed[0]['title'] ?? '')), 'it says so in those words');
KH_Test::contains('Security Issue', (string) ($kh_sc_closed[0]['evidence'] ?? ''), 'with the reason WordPress.org gave, which lives in `description`');

// "There is a patch" is not the same as "this site has it".
$kh_sc_vulns = ['p' => ['vulnerabilities' => [
    ['title' => 'Fixed ages ago', 'fixed_in' => '1.0.0'],
    ['title' => 'Fixed in the next release', 'fixed_in' => '2.0.0'],
    ['title' => 'No patch exists', 'fixed_in' => null],
]]];
$kh_sc_scan = Keelhob_Tools_Sitecare::analyze_wpscan('p', $kh_sc_vulns, '1.5.0');
KH_Test::same(2, count($kh_sc_scan), 'only the vulnerabilities this installed version is still exposed to are reported');
KH_Test::same('high', $kh_sc_scan[0]['severity'] ?? '', 'a live vulnerability is always a high');
KH_Test::contains('2.0.0', (string) ($kh_sc_scan[0]['evidence'] ?? ''), 'naming the version that fixes it');
KH_Test::same('manage_updates', $kh_sc_scan[0]['fix_tool'] ?? '', 'so the fix is an update');
KH_Test::same('manage_plugin', $kh_sc_scan[1]['fix_tool'] ?? '', 'but an unpatched one can only be replaced');
$kh_sc_patched = Keelhob_Tools_Sitecare::analyze_wpscan('p', $kh_sc_vulns, '2.0.0');
KH_Test::same(1, count($kh_sc_patched), 'on the newest version only the vulnerability with no patch at all remains');
KH_Test::contains('No patch exists', (string) ($kh_sc_patched[0]['evidence'] ?? ''), 'and it is that one');
KH_Test::same([], Keelhob_Tools_Sitecare::analyze_wpscan('p', ['p' => ['vulnerabilities' => [['title' => 'Old', 'fixed_in' => '1.0.0']]]], '2.0.0'), 'a fully patched install is reported as clean');
KH_Test::same([], Keelhob_Tools_Sitecare::analyze_wpscan('p', [], '1.0.0'), 'and WPScan\'s 404 (nothing known) is clean too');

KH_Test::same([0, 1, 2, 3], [
    Keelhob_Tools_Sitecare::severity_rank('high'),
    Keelhob_Tools_Sitecare::severity_rank('medium'),
    Keelhob_Tools_Sitecare::severity_rank('low'),
    Keelhob_Tools_Sitecare::severity_rank('anything else'),
], 'riskiest sorts first');

KH_Test::group('Site care — a plugin slug is never trusted into a URL');

KH_Test::same('akismet', Keelhob_Tools_Sitecare::plugin_slug('akismet/akismet.php'), 'the directory name is the slug');
KH_Test::same('akismet', Keelhob_Tools_Sitecare::plugin_slug('akismet\\akismet.php'), 'on Windows too');
KH_Test::same('', Keelhob_Tools_Sitecare::plugin_slug('hello.php'), 'a single-file plugin has no WordPress.org slug, and a guess is not supplied');
KH_Test::same('', Keelhob_Tools_Sitecare::plugin_slug('../../etc/passwd/x.php'), 'and anything that is not a plain slug is refused outright');
KH_Test::same('', Keelhob_Tools_Sitecare::plugin_slug('a b/c.php'), 'spaces included');

KH_Test::group('Site care — the tools are registered, gated and read-only');

$kh_sc_list = [];
foreach ($kh_sc_registry->list() as $kh_t) {
    $kh_sc_list[$kh_t['name']] = $kh_t;
}

foreach (['speed_audit', 'db_cleanup_preview', 'plugin_security_check', 'plugin_conflicts', 'broken_links'] as $kh_name) {
    KH_Test::ok(isset($kh_sc_list[$kh_name]), $kh_name . ' is registered');
    KH_Test::same('sitecare', Keelhob_Capabilities::tool_group($kh_name), 'and sits in the sitecare capability group');
    KH_Test::ok(Keelhob_Capabilities::tool_allowed($kh_name), 'and is allowed on a default install');
    KH_Test::same(true, $kh_sc_list[$kh_name]['annotations']['readOnlyHint'] ?? null, 'and is annotated read-only, so a Telegram viewer may run it');
}

// The whole reason this group ships ON is that nothing in it writes. The day
// one of these grows a write, this assertion is what says so.
$kh_sc_src = (string) file_get_contents(KEELHOB_DIR . 'includes/tools/class-tools-sitecare.php');
$kh_sc_writes = [];
foreach ([
    'wp_delete_post', 'wp_trash_post', 'wp_update_post', 'wp_insert_post',
    'wp_delete_post_revision', 'wp_delete_comment', 'delete_option',
    'update_option', 'update_post_meta', 'delete_post_meta', 'file_put_contents',
    'unlink(', 'rename(', '$wpdb->query', '$wpdb->delete', '$wpdb->update',
    '$wpdb->insert', 'activate_plugin', 'deactivate_plugins',
] as $kh_write) {
    if (strpos($kh_sc_src, $kh_write) !== false) {
        $kh_sc_writes[] = $kh_write;
    }
}
KH_Test::same([], $kh_sc_writes, 'not one of the five tools can write anything: no post, comment, option, meta, file or plugin state');

// Never a column called object_id: $wpdb casts it to int and silently stores a
// string as 0. These tools only read, and they still never name it.
KH_Test::ok(strpos($kh_sc_src, 'object_id') === false, 'and no query names a column "object_id"');

// Orphaned meta is found with NOT IN (SELECT ID …) rather than a LEFT JOIN,
// because that is the form that works on MySQL and on an SQLite-backed site.
KH_Test::contains('post_id NOT IN (SELECT ID', $kh_sc_src, 'orphaned post meta is counted with NOT IN (SELECT ID …), which works on SQLite as well as MySQL');

// The autoload column has held four different truthy values across WordPress
// versions. Counting only 'yes' on a 6.6+ install reports a fraction of the
// real weight and tells the owner their site is fine.
KH_Test::contains("autoload IN ('yes','on','auto-on','auto')", $kh_sc_src, 'autoloaded options are counted across all four spellings of "yes"');

// Every outbound call has a timeout AND a cache. One tool that hangs blocks
// the entire MCP request.
foreach (['wporg_info', 'wpscan_info', 'url_status'] as $kh_fn) {
    if (preg_match('/function ' . $kh_fn . '\(.*?\n    \}/s', $kh_sc_src, $kh_m)) {
        KH_Test::contains('timeout', $kh_m[0], $kh_fn . ' gives its HTTP call a timeout');
        KH_Test::contains('transient', $kh_m[0], 'and caches the answer');
    } else {
        KH_Test::ok(false, 'could not read ' . $kh_fn . ' out of the source');
    }
}

// Each tool states its own count, because a model once miscounted 44 rows as 28.
foreach ([
    'speed_audit' => "'count'",
    'db_cleanup_preview' => "'count'",
    'plugin_security_check' => "'count'",
    'plugin_conflicts' => "'count'",
    'broken_links' => "'count'",
] as $kh_fn => $kh_needle) {
    if (preg_match('/public static function ' . $kh_fn . '\(array \$args\).*?\n    \}/s', $kh_sc_src, $kh_m)) {
        KH_Test::contains($kh_needle, $kh_m[0], $kh_fn . ' states its own count in its result');
    } else {
        KH_Test::ok(false, 'could not read ' . $kh_fn . ' out of the source');
    }
}

// broken_links is the only one that paginates, and the three keys an agent
// needs to keep going must all be there.
if (preg_match('/public static function broken_links\(array \$args\).*?\n    \}/s', $kh_sc_src, $kh_m)) {
    foreach (["'next_offset'", "'done'", "'progress'"] as $kh_key) {
        KH_Test::contains($kh_key, $kh_m[0], 'broken_links returns ' . $kh_key);
    }
}

// A rejected WPScan token, or an exhausted quota, will not fix itself between
// two plugins. The first live run asked sixty-plugin-sites' worth of questions
// after a 403 and got the same 403 each time; on a big site that is what times
// the MCP request out.
KH_Test::contains('$wpscan_stop = true', $kh_sc_src, 'a fatal WPScan answer stops the rest of the lookups this run');
KH_Test::contains("'fatal' => true", $kh_sc_src, 'and the fatal answers say so themselves');
KH_Test::contains('WPScan daily limit reached', $kh_sc_src, 'a 429 is reported in the words the owner needs: the daily limit');
KH_Test::contains('Token token=', $kh_sc_src, 'and the token is sent in WPScan\'s own header format');
