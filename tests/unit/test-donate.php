<?php
/**
 * The donate button exists, on every screen, and never nags.
 *
 * Both halves matter. A free project with no visible way to fund it quietly
 * dies; a free project that interrupts you dies faster and takes its goodwill
 * with it. So this asserts the button is present everywhere AND that it is
 * still a button — no modal, no dismissal state, no timer.
 */

CP_Test::group('Donate — the ask appears twice, not on every screen');

/*
 * THIS TEST USED TO ASSERT THE OPPOSITE, and the reversal is the point.
 *
 * It required a donate button beside EVERY <h1> - seven of them, across five
 * files - and it enforced that with `$heads === $found`, so adding a screen
 * without a button failed the build. The reasoning was that a free project
 * with no visible way to fund it quietly dies.
 *
 * That is true, and it does not follow that the ask belongs on every screen.
 * Seven asks do not raise seven times the money; they change what the plugin
 * looks like. A tool that asks for money at the top of the Activity log, the
 * Guide, the Skills list and the Automation screen reads as a tool that is
 * about the money, and that impression costs more than the extra placements
 * can earn.
 *
 * Two placements, both at a moment that makes sense:
 *   1. Connect - somebody has just set the thing up and watched it work.
 *   2. The Donate page itself.
 *
 * The Plugins-row link stays. It is WordPress's own convention, it is where
 * people look, and it interrupts nothing.
 */

$allowed = [
    'includes/class-admin-screens.php' => 1,  // Connect only. Guide and Activity no longer ask.
];

$screens = [
    'includes/class-admin-screens.php',
    'includes/class-admin-automation.php',
    'includes/class-admin-skills.php',
    'includes/class-studio.php',
    'includes/class-studio-list.php',
    'includes/class-studio-edit.php',
    'includes/class-studio-compose.php',
];

$total = 0;
foreach ($screens as $file) {
    $src      = file_get_contents(CLAUDPRESS_DIR . $file);
    $found    = substr_count($src, 'Claudpress_Donate::button()');
    $expected = $allowed[$file] ?? 0;
    CP_Test::same($expected, $found, basename($file) . ' asks for money exactly ' . $expected . ' time(s)');
    $total += $found;
}
CP_Test::same(1, $total, 'one button across every admin screen, on Connect');

// The button itself is untouched. Removing call sites must not quietly remove
// the ability to ask at all - that is a different decision, and not this one.
CP_Test::ok(
    method_exists('Claudpress_Donate', 'button'),
    'the button still exists as a method, so this is reversible in one line per screen'
);
CP_Test::ok(
    method_exists('Claudpress_Donate', 'action_link'),
    'and the Plugins-row link, which is convention rather than a plea, is untouched'
);

// The Donate page is the second placement and must actually be reachable.
CP_Test::ok(method_exists('Claudpress_Donate', 'menu'), 'the Donate page is still in the menu');
CP_Test::ok(method_exists('Claudpress_Donate', 'render'), 'and still renders');

CP_Test::group('Donate — no screen was missed');

// A file-by-file list goes stale the moment somebody adds a screen. This asks
// the whole includes/ directory instead, so a new screen with a button has to
// be a deliberate decision rather than an oversight in either direction.
$stray = [];
foreach (glob(CLAUDPRESS_DIR . 'includes/*.php') as $file) {
    $rel = 'includes/' . basename($file);
    if (($allowed[$rel] ?? 0) > 0 || basename($file) === 'class-donate.php') {
        continue;
    }
    if (strpos((string) file_get_contents($file), 'Claudpress_Donate::button()') !== false) {
        $stray[] = basename($file);
    }
}
CP_Test::same([], $stray, 'no file outside the two agreed placements asks for money');

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

CP_Test::group('Donate — the plugins row gets Donate, Settings and Snippets');

$links = Claudpress_Donate::action_link(['deactivate' => '<a href="#">Deactivate</a>']);
CP_Test::same(4, count($links), 'three links are added alongside Deactivate');
CP_Test::contains('ko-fi.com', $links[0], 'Donate comes first');
CP_Test::contains('page=claudpress', $links[1], 'Settings second');

/*
 * Snippets on the Plugins row, and ORDER MATTERS here.
 *
 * It is the one screen in this plugin a person uses without an agent: a real
 * code editor, an on/off switch per snippet, and a syntax check that refuses
 * to let broken PHP run. None of it needs a connection or abilities switched
 * on, so somebody who installed this for the snippets alone should not have to
 * find it through a settings page about MCP.
 *
 * Third, though. Settings is what most people came for, and a row of links
 * that buries it has made itself worse.
 */
CP_Test::contains('Snippets', $links[2], 'Snippets third');
CP_Test::contains('page=' . Claudpress_Studio::SLUG, $links[2], 'and it points at the real Studio slug');

// Built from the constant, not typed out. Renaming the page must not leave a
// dead link on the Plugins screen - the one place nobody thinks to re-check.
CP_Test::ok(
    strpos(php_strip_whitespace(CLAUDPRESS_DIR . 'includes/class-donate.php'), "page=claudpress-studio") === false,
    'the Snippets slug is taken from Claudpress_Studio::SLUG, never hard-coded'
);

// Never rewrite somebody else's plugin row.
$other = Claudpress_Donate::row_meta(['x'], 'some-other-plugin/other.php');
CP_Test::same(['x'], $other, 'another plugin\'s row meta is left completely alone');
