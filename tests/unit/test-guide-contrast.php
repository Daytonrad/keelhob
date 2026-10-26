<?php
/**
 * The Guide screen must not carry a colour of its own.
 *
 * WHY THIS IS A TEST.
 *
 * The Guide was the one screen that got the redesign's panel shell and NOT the
 * redesign's token layer. It kept rendering inline `color:#16a34a` for thirteen
 * "ENABLED" labels, `color:#888` for nine "OFF" ones, `color:#b45309` on the two
 * safety paragraphs and `background:#f6f7f7` on the starter-prompt block —
 * literals that predate the redesign and were therefore invisible to every diff
 * of it. An independent audit measured the result at **26 AA failures, worst
 * 2.85:1**, on a screen whose whole job is telling an owner which switches
 * control who may change their site. Every other screen measured 4.86:1 or
 * better.
 *
 * Two facts make a source-level test the right guard here:
 *
 *   1. An inline `style` attribute beats any stylesheet rule, including the
 *      `.kh-ui.is-dark` overrides. So one literal re-introduced into this
 *      template silently un-does the dark mode of whatever it lands on, and
 *      neither `php -l`, PHPStan, nor the render tests can see it.
 *   2. The browser layer only measures what the rig happens to render. A
 *      literal added to a branch that needs a WooCommerce install, a stored
 *      error or a connected provider is a failure that ships green.
 *
 * This test is the cheap half: it proves no hardcoded colour is in the template
 * at all. The measurement itself stays the browser layer's job — the ratios are
 * recorded in comments next to each construction in `render_guide()`, taken from
 * `getComputedStyle` in Chromium at 1440px, in light and in dark.
 *
 * It is deliberately a NEW file rather than an edit to test-admin-render.php:
 * two lanes work this branch at once and a shared file is where their work
 * collides.
 */

KH_Test::group('Admin — the Guide carries no colour of its own');

$src = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/class-admin-screens.php');

KH_Test::ok($src !== '', 'class-admin-screens.php is readable');

/* Isolate render_guide() — the screens file holds several render methods and
 * only this one is under contract here. */
$start = strpos($src, 'public function render_guide(');
$end   = strpos($src, 'public function render_activity(');
KH_Test::ok($start !== false && $end !== false && $end > $start, 'render_guide() and the method after it are both found');
$guide = substr($src, (int) $start, (int) $end - (int) $start);

/*
 * Strip PHP comments before scanning.
 *
 * The comments in this method QUOTE the literals they replaced — "#16a34a
 * measured 2.85:1" is the record of the fix and must survive. A scanner that
 * cannot tell a comment from code would force the one explanation of the defect
 * to be deleted in order to keep its own guard green, which is the wrong trade.
 */
$code = (string) preg_replace('~/\*.*?\*/~s', '', $guide);
$code = (string) preg_replace('~//[^\n]*~', '', $code);

/*
 * Any `color:` or `background[-color]:` declaration must resolve through a
 * custom property. `var(--kh-…)` is the whole allowlist: it follows the dark
 * variant for free and it cannot drift from the palette, which a literal can.
 */
$decls = [];
if (preg_match_all('~\b(?:background-color|background|color)\s*:\s*([^;"\']+)~i', $code, $m)) {
    $decls = $m[1];
}
KH_Test::ok($decls !== [], 'the Guide does declare at least one colour (so this scan is looking at something)');

$literals = [];
foreach ($decls as $value) {
    $value = trim($value);
    if (stripos($value, 'var(--kh-') !== false) {
        continue;
    }
    $literals[] = $value;
}
KH_Test::same(
    [],
    $literals,
    'every colour the Guide declares comes from a --kh-* token, not a literal'
);

/* Belt and braces: no hex literal survives anywhere in the executable half of
 * the method, in any property. A literal in `border-left` or `box-shadow` is
 * the same dark-mode bug as one in `color`. */
$hex = [];
if (preg_match_all('~style\s*=\s*"[^"]*(#[0-9a-fA-F]{3,8})~', $code, $m2)) {
    $hex = array_values(array_unique($m2[1]));
}
KH_Test::same([], $hex, 'no style attribute in the Guide contains a hex colour');

/*
 * The AMENDMENT A1.1 construction, asserted positively.
 *
 * Removing the literals is not enough on its own: the failing pairing the token
 * names invite is `--kh-good` as TEXT on `--kh-good-soft`, which measures
 * 3.12:1 and fails just as hard as the literal did. So the status words must be
 * built as tint-fill + coloured-dot + ink-words, and that is what these assert.
 */
KH_Test::contains('kh-u-pill', $guide, 'the status words are a kh-u-pill (ink words on the pill\'s own fill)');
KH_Test::contains('kh-u-dot', $guide, 'the pill carries a kh-u-dot, so the state is in the dot and not in the text colour');
KH_Test::contains('aria-hidden="true"', $guide, 'the dot is hidden from assistive tech — the word beside it carries the meaning');
KH_Test::contains('kh-u-note is-mid', $guide, 'the two safety paragraphs are kh-u-note callouts, not coloured text');
KH_Test::contains('kh-u-badge', $guide, 'the sensitive marker is the copper-FILL badge, never copper text on light');

/*
 * The capability cards must NOT be core's `.card`. Core paints `.card`
 * `#fff` unconditionally and knows nothing about `.kh-ui`, so under a dark admin
 * scheme twenty-one white cards sat inside a near-black panel and the panel's
 * light ink landed on them. Same reason the satellite screens dropped
 * `widefat striped`.
 */
KH_Test::contains('class="kh-u-card"', $guide, 'the per-group cards are kh-u-card, whose surface follows the mode');
KH_Test::same(
    0,
    preg_match('~class="card"~', $code),
    'no core .card survives in the Guide'
);

/* Not one word of the safety text was cut while restyling it. PLAN §2.7 names
 * these as do-not-shorten, and a restyle is exactly when they get quietly
 * trimmed to fit a nicer box. */
foreach (
    [
        'which holds your database password and security keys',
        'is the master key',
        'turn Code execution OFF too',
        'use this on a development or staging site with backups',
    ] as $sentence
) {
    KH_Test::contains($sentence, $guide, 'safety text kept in full: "' . $sentence . '"');
}
