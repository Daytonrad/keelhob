<?php
/**
 * The snippets list must not carry a colour of its own.
 *
 * WHY THIS IS A TEST.
 *
 * `includes/class-studio-list.php` inlined five literal colours, and an inline
 * `style` attribute beats every stylesheet rule — including the panel's
 * `.kh-ui.is-dark` overrides. So each literal silently opted its own span out
 * of dark mode, and nothing in `php -l`, PHPStan or `php tests/run.php` could
 * see it. Measured in Chromium on the rig:
 *
 *   #999      2.85:1 on a white row · 2.65:1 on the #f6f7f7 stripe
 *   #666      3.19:1 on --kh-card in dark
 *   #d63638   4.09:1 on --kh-bg in light · 3.59:1 on --kh-soft in dark
 *   #2271b1   4.47:1 on --kh-bg in light
 *
 * Every one of those is below AA's 4.5:1 for text.
 *
 * Two of them were caught by the browser layer and two were not, which is the
 * argument for scanning the source as well: the `#d63638` error line only
 * renders for a snippet with a stored `last_error`, and `#666` only for one
 * with a description, so a rig whose fixtures happen not to set those fields
 * reports a clean run. `assets/css/admin.css` carried `!important` overrides
 * keyed to the exact literal strings as a stop-gap; this file is what makes
 * them unnecessary, and they fail safe (they stop matching) rather than
 * fighting the tokens.
 *
 * Deliberately a NEW file rather than an edit to test-admin-render.php or
 * test-guide-contrast.php: two lanes work this branch at once and a shared
 * file is where their work collides.
 */

KH_Test::group('Studio — the snippets list carries no colour of its own');

$path = dirname(__DIR__, 2) . '/includes/class-studio-list.php';
$src  = (string) file_get_contents($path);

KH_Test::ok($src !== '', 'class-studio-list.php is readable');

/*
 * Strip PHP comments before scanning.
 *
 * The header comment QUOTES the literals it replaced, because the measured
 * ratios are the record of the defect and must survive. A scanner that cannot
 * tell a comment from code would force that explanation to be deleted in order
 * to keep its own guard green, which is the wrong trade. Same construction as
 * test-guide-contrast.php, for the same reason.
 */
$code = (string) preg_replace('~/\*.*?\*/~s', '', $src);
$code = (string) preg_replace('~^\s*//[^\n]*~m', '', $code);

/*
 * Any `color:` or `background[-color]:` declaration must resolve through a
 * custom property. `var(--kh-…)` is the whole allowlist: it follows the dark
 * variant for free and it cannot drift from the palette, which a literal can.
 */
/*
 * The value runs to the `;` or the closing `"` — NOT to the first `'`.
 *
 * Stopping at a single quote looks harmless and silently defeats the PHP
 * branch below: `style="color:<?php echo $action === 'delete' ? …"` captures
 * only `<?php echo $action ===`, which carries no `var(--kh-` for the guard to
 * find, so a line whose every branch IS a token gets reported as a literal.
 * That is a false failure on correct code, which is worse than no test.
 */
$decls = [];
if (preg_match_all('~\b(?:background-color|background|color)\s*:\s*([^;"]+)~i', $code, $m)) {
    $decls = $m[1];
}
KH_Test::ok($decls !== [], 'the list does declare at least one colour (so this scan is looking at something)');

$literals = [];
foreach ($decls as $value) {
    $value = trim($value);
    if (stripos($value, 'var(--kh-') !== false) {
        continue;
    }
    /* A PHP ternary that chooses between two var() references is still two
     * tokens; the scan sees the whole expression, so allow it when every
     * branch is a token. */
    if (stripos($value, '<?php') !== false && substr_count(strtolower($value), 'var(--kh-') >= 1) {
        continue;
    }
    $literals[] = $value;
}
KH_Test::same(
    [],
    $literals,
    'every colour the snippets list declares comes from a --kh-* token, not a literal'
);

/* Belt and braces: no hex literal survives anywhere in the executable half of
 * the file, in any property. A literal in `border-color` or `box-shadow` is the
 * same dark-mode bug as one in `color`. */
$hex = [];
if (preg_match_all('~(#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?)\b~', $code, $m2)) {
    foreach ($m2[1] as $h) {
        /* `#<?php echo (int) $id; ?>` and `&#9888;` are not colours. */
        if (preg_match('~^#[0-9a-fA-F]{3}$~', $h) || preg_match('~^#[0-9a-fA-F]{6}$~', $h)) {
            $hex[$h] = true;
        }
    }
}
KH_Test::same(
    [],
    array_keys($hex),
    'no hex colour survives anywhere in the executable half of class-studio-list.php'
);

/* The four literals this file is named for, asserted individually so a
 * regression says WHICH one came back rather than just "a literal". */
foreach (['#999', '#666', '#d63638', '#b32d2e', '#2271b1'] as $gone) {
    KH_Test::same(
        0,
        substr_count($code, $gone),
        sprintf('%s is gone from the snippets list', $gone)
    );
}

/* The tokens that replaced them, asserted positively — removing a literal and
 * leaving the span with no colour at all would also pass the scan above, and
 * would inherit a colour nobody measured. */
foreach (
    [
        'var(--kh-muted)'   => 'the description line and the empty-tags em dash use --kh-muted',
        'var(--kh-bad-ink)' => 'the error line and the Errored filter use --kh-bad-ink, not the raw status red',
        'var(--kh-ink-2)'   => 'the Duplicate row action uses --kh-ink-2, not core scheme blue',
    ] as $token => $why
) {
    KH_Test::contains($token, $src, $why);
}

/*
 * The markup contract, unchanged. Hard rule 1: never change a form field
 * `name`, a nonce action or an admin-post.php target. The row actions post a
 * real form, so a restyle of the button must leave the form alone — and a
 * `button-link` that lost its `type="submit"` would look identical and do
 * nothing.
 */
KH_Test::contains('name="studio_action"', $src, 'the row-action form still posts studio_action');
KH_Test::contains('name="snippet_id"', $src, 'the row-action form still posts snippet_id');
KH_Test::contains('Keelhob_Studio::NONCE', $src, 'the row-action form is still nonced with the Studio nonce');
KH_Test::contains('admin_url(\'admin-post.php\')', $src, 'the row-action form still targets admin-post.php');
KH_Test::contains('type="submit"', $src, 'the row-action control is still a submit button');
