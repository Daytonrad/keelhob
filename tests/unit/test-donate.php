<?php
/**
 * The donate button exists, on every screen, and never nags.
 *
 * Both halves matter. A free project with no visible way to fund it quietly
 * dies; a free project that interrupts you dies faster and takes its goodwill
 * with it. So this asserts the button is present everywhere AND that it is
 * still a button — no modal, no dismissal state, no timer.
 */

CP_Test::group('Donate — the button is on every Claudpress screen');

// Every admin screen this plugin renders. If a new one is added without the
// button, this list is where you find that out.
$screens = [
    'includes/class-admin.php'         => 4,  // Connect, Automation, Guide, Activity
    'includes/class-admin-skills.php'  => 1,
    'includes/class-admin-snippets.php' => 1,
    'includes/class-studio.php'        => 1,
];

$total = 0;
foreach ($screens as $file => $expected) {
    $src   = file_get_contents(CLAUDPRESS_DIR . $file);
    $found = substr_count($src, 'Claudpress_Donate::button()');
    $heads = preg_match_all('/<h1[^>]*>/', $src);
    CP_Test::same($expected, $found, basename($file) . ' renders the donate button on each of its screens');
    CP_Test::same($heads, $found, basename($file) . ' has a button for every page title, so a new screen cannot miss one');
    $total += $found;
}
CP_Test::same(7, $total, 'seven screens, seven buttons');

CP_Test::group('Donate — it is a button, not a nag');

$src = file_get_contents(CLAUDPRESS_DIR . 'includes/class-donate.php');

// Comments stripped, because the file explains at length WHY it does not nag,
// and a naive scan of the whole file finds those words and fails. The question
// is what the code does, not what the prose says about it.
$code = php_strip_whitespace(CLAUDPRESS_DIR . 'includes/class-donate.php');

// The specific failure modes this project has decided against. Each of these
// buys one donation and costs the goodwill that was the entire point.
foreach ([
    'set_transient'  => 'no timed re-nag',
    'dismiss'        => 'nothing to dismiss, because nothing interrupts',
    'admin_notices'  => 'the ask is never a notice',
    'wp_die'         => 'nothing blocks',
    'add_days'       => 'no "ask again in N days" counter',
] as $needle => $why) {
    CP_Test::ok(stripos($code, $needle) === false, $why);
}

CP_Test::group('Donate — an unset link renders nothing rather than a dead one');

// button() returns early on an empty URL. A Donate button that 404s is worse
// than no Donate button: it reads as abandonment.
CP_Test::contains("if (CLAUDPRESS_DONATE_URL === '')", $src, 'the button is skipped when no link is configured');
CP_Test::contains('is_array($links) || CLAUDPRESS_DONATE_URL', $src, 'and so is the plugins-row link');

CP_Test::group('Donate — the link is configurable in one place');

CP_Test::ok(defined('CLAUDPRESS_DONATE_URL'), 'the donate URL is a constant');
CP_Test::ok(defined('CLAUDPRESS_REPO_URL'), 'the repo URL is a constant');
CP_Test::ok(defined('CLAUDPRESS_SERVICES_URL'), 'the services URL is a constant');

$plugin = file_get_contents(CLAUDPRESS_DIR . 'claudpress.php');
CP_Test::contains("if (!defined('CLAUDPRESS_DONATE_URL'))", $plugin, 'and can be overridden before load, so a fork does not have to patch a file');

// The one thing a donate link must never do: leak who is asking.
CP_Test::ok(strpos($src, 'site_id') === false, 'the donate link carries no identifiers');
CP_Test::ok(strpos($src, 'home_url') === false, 'and does not attach the site address');

CP_Test::group('Donate — the plugins row gets Donate and Settings');

$links = Claudpress_Donate::action_link(['deactivate' => '<a href="#">Deactivate</a>']);
CP_Test::same(3, count($links), 'Donate and Settings are added alongside Deactivate');
CP_Test::contains('ko-fi.com', $links[0], 'Donate comes first');
CP_Test::contains('page=claudpress', $links[1], 'Settings second');

// Never rewrite somebody else's plugin row.
$other = Claudpress_Donate::row_meta(['x'], 'some-other-plugin/other.php');
CP_Test::same(['x'], $other, 'another plugin\'s row meta is left completely alone');
