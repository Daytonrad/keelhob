<?php
/**
 * The Donate screen is inside the panel, and the gate can see it.
 *
 * WHY THIS IS A TEST.
 *
 * Donate was the seventh Keelhob screen and the only one the redesign never
 * reached. It rendered a bare `<div class="wrap">`, so none of the `--kh-*`
 * tokens — which are declared on `.kh-ui` and nowhere else — resolved on it,
 * `is-dark` could not apply, and `assets/css/admin.css`'s fix for core's own
 * `.button` colour was unreachable. The browser gate measured one AA failure in
 * every one of its eight cells: "See what that involves" at **4.02:1** under
 * `fresh` (core's #007cba) and **4.08:1** under `midnight` (core's #cf4339), on
 * core's #f0f0f1 button fill.
 *
 * It survived the whole build for one reason: `tests/rig/ui/check-admin.mjs`
 * listed six screens, and this was not one of them. Every "all screens pass"
 * result in this branch was therefore true of six screens out of seven. So this
 * file guards BOTH halves — the screen being on the shell, and the screen being
 * in the gate's list — because fixing the first without pinning the second is
 * how the same defect comes back silently.
 *
 * A new file rather than an edit to test-admin-render.php or test-admin-shell.php:
 * same reason as test-guide-contrast.php, a shared file is where parallel work
 * collides.
 */

KH_Test::group('Admin — the Donate screen is on the panel shell');

$kh_donate_src = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/class-donate.php');
KH_Test::ok($kh_donate_src !== '', 'class-donate.php is readable');

/* Isolate render(). The rest of the file is the plugin-row links and the small
 * ♥ Donate button, which are not screens and are not under contract here. */
$kh_d_start = strpos($kh_donate_src, 'public static function render(');
KH_Test::ok($kh_d_start !== false, 'Keelhob_Donate::render() is found');
$kh_render = substr($kh_donate_src, (int) $kh_d_start);

/* The comments quote the literals and the ratios they replaced; that record has
 * to survive a scan that is looking for literals. Strip them first. */
$kh_code = (string) preg_replace('~/\*.*?\*/~s', '', $kh_render);
$kh_code = (string) preg_replace('~//[^\n]*~', '', $kh_code);

/* --- 1. On the shell, not on a bare wrap. ---------------------------- */

KH_Test::contains(
    'Keelhob_Admin::shell_open(self::PAGE)',
    $kh_code,
    'render() opens the panel shell, and does it with its own page slug'
);
KH_Test::contains(
    'Keelhob_Admin::shell_close()',
    $kh_code,
    'and closes it — an unclosed shell swallows the rest of the admin page'
);
KH_Test::same(
    0,
    preg_match('~<div\s+class="wrap"~', $kh_code),
    'no bare <div class="wrap"> survives: that div is what kept every token out of this screen'
);

/* The shell prints the panel's one <h1>. A second one would give core's
 * notice-relocation the wrong heading to insert after — the same reason Guide
 * and Activity dropped theirs. */
KH_Test::same(
    0,
    preg_match('~<h1~i', $kh_code),
    'render() declares no <h1> of its own; the shell prints the only one'
);
KH_Test::contains('<h2>', $kh_code, 'its old heading is an <h2> instead, so the words are still on the screen');

/* --- 2. Donate is NOT a tab, which is why no tab is current. --------- */
//
// The strip is rendered as the way back out of the ask, but Donate is a submenu
// item and deliberately last, and Keelhob_Admin::tabs() is the list of peers.
// shell_open() is handed a slug that matches no tab, so `aria-current="page"`
// is absent rather than sitting on a tab the owner is not looking at. If anyone
// later adds Donate to tabs(), this test says so and check-admin.mjs's
// `inStrip: false` entry needs revisiting in the same breath.
$kh_tab_slugs = array_keys(Keelhob_Admin::tabs());
KH_Test::ok(
    !in_array(Keelhob_Donate::PAGE, $kh_tab_slugs, true),
    'the Donate slug is not one of the tab strip\'s screens (' . implode(', ', $kh_tab_slugs) . ')'
);

/* --- 3. The one colour this screen states, and why. ----------------- */
//
// Ko-fi pink is the donate identity and it stays. But `.kh-ui .button-primary`
// paints near-black words on copper — the case hard rule 7 permits — and an
// inline `background` beats the stylesheet while the inherited `color` does
// not. Dropping this screen into the shell without stating a colour would have
// put #121517 on #c2185b at 3.12:1: a brand-new AA failure, on the donate
// button, introduced by the fix. White on #c2185b is 5.87:1.
KH_Test::contains(
    'color:#fff',
    $kh_code,
    'the Ko-fi button states its text colour (white on #c2185b, 5.87:1) instead of inheriting near-black (3.12:1)'
);

/* Every other declared colour must be a token or the measured pink pair. A
 * third literal arriving here is a dark-mode bug an inline style makes
 * unreachable by any stylesheet rule. */
$kh_allowed_hex = ['#c2185b', '#a01449', '#fff'];
$kh_hex = [];
if (preg_match_all('~style\s*=\s*"[^"]*"~', $kh_code, $kh_attrs)) {
    foreach ($kh_attrs[0] as $kh_attr) {
        if (preg_match_all('~#[0-9a-fA-F]{3,8}~', $kh_attr, $kh_found)) {
            foreach ($kh_found[0] as $kh_one) {
                if (!in_array(strtolower($kh_one), $kh_allowed_hex, true)) {
                    $kh_hex[] = $kh_one;
                }
            }
        }
    }
}
KH_Test::same([], array_values(array_unique($kh_hex)), 'the only hex colours on the Donate screen are the measured Ko-fi pair plus #fff');

/* A one-sided margin indents from the wrong edge under a real `ar` install,
 * which is a cell the gate now actually runs. */
KH_Test::same(
    0,
    preg_match('~margin-left\s*:~i', $kh_code),
    'no direction-blind margin-left: the list indent is logical, so it mirrors under RTL'
);

/* --- 4. The ask is not shortened while being restyled. -------------- */
//
// A restyle is exactly when prose gets trimmed to fit a nicer box. This screen
// is the whole funding model of a GPL plugin with no paid tier, and the "free
// forever" promise and the "this is optional" disclaimer are the two sentences
// a reader is entitled to find in full.
foreach (
    [
        'Keelhob is free, GPL, and stays that way. There is no paid version, no locked feature, no trial, and nothing held back.',
        'that is a paid service and it is entirely optional — the plugin does not behave differently either way',
        'Donations are the only thing that decides whether it keeps being built',
    ] as $kh_sentence
) {
    KH_Test::contains($kh_sentence, $kh_render, 'Donate copy kept in full: "' . substr($kh_sentence, 0, 48) . '…"');
}

/* --- 5. The gate must keep looking at this screen. ------------------ */
//
// This is the assertion that would have caught the original defect. The browser
// check is the only thing that measures this screen, and it measures exactly
// the slugs in its own SCREENS list; a slug quietly dropped from that array is
// a screen that goes unmeasured while the run still prints green.
$kh_gate = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/rig/ui/check-admin.mjs');
KH_Test::ok($kh_gate !== '', 'tests/rig/ui/check-admin.mjs is readable');
KH_Test::contains(
    "slug: '" . Keelhob_Donate::PAGE . "'",
    $kh_gate,
    'check-admin.mjs still lists the Donate screen, so "all screens pass" means all seven'
);
foreach (array_merge($kh_tab_slugs, [Keelhob_Donate::PAGE]) as $kh_slug) {
    KH_Test::contains("slug: '" . $kh_slug . "'", $kh_gate, 'check-admin.mjs covers ' . $kh_slug);
}
