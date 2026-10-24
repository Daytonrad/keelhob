<?php
/**
 * The admin panel shell: the token layer's enqueue, the header line, the tab
 * strip, and the collision that used to leave a live screen unstyled.
 *
 * A SEPARATE FILE FROM test-admin-render.php ON PURPOSE. That file pins the
 * markup contract of the settings forms — field names, nonces, provider rows —
 * and two people were editing this branch at once. Adding a file cannot
 * conflict with another lane's edit; adding assertions to theirs can.
 *
 * What is asserted here is the part of a design system that is easy to get
 * wrong silently:
 *
 *   - the stylesheet appears on Keelhob screens and NOWHERE else
 *   - every tab is a real <a href> to a slug that already resolves
 *   - the active tab is marked with aria-current="page", exactly once
 *   - the shell needs no JavaScript to show its navigation
 *   - `kh-tabs` / `kh-tab` / `kh-panel` are gone from the Connect screen, so
 *     the studio.css collision cannot come back
 *
 * It does NOT assert that anything looks right. Computed styles are the browser
 * layer's job (tests/rig/ui/) and the house rule is that UI is never claimed to
 * work from reading the HTML. What this file proves is structural: the links are
 * links, the attribute is on the right one, and the CSS is attached.
 */

KH_Test::group('Admin shell — the stylesheet loads on Keelhob screens only');

/*
 * is_keelhob_screen() is given the hook suffix WordPress would pass. The
 * bootstrap's get_current_screen() returns null, which is the honest case here:
 * at admin_enqueue_scripts the screen is not always built yet, so the hook
 * suffix has to be enough on its own.
 */
$kh_on = [
    'toplevel_page_keelhob',
    'keelhob_page_keelhob-skills',
    'keelhob_page_keelhob-automation',
    'keelhob_page_keelhob-guide',
    'keelhob_page_keelhob-activity',
    'keelhob_page_keelhob-studio',
];
foreach ($kh_on as $kh_hook) {
    KH_Test::ok(
        Keelhob_Admin::is_keelhob_screen($kh_hook),
        'recognised as a Keelhob screen: ' . $kh_hook
    );
}

/*
 * The screens it must stay off. "Never the front end" is structural — this only
 * ever runs on admin_enqueue_scripts — but "never other admin pages" is a real
 * risk, because a stylesheet that sets a negative margin and a full-viewport
 * background on .kh-ui would be harmless on a page with no .kh-ui and still a
 * wasted request on every admin screen in the install.
 */
$kh_off = ['index.php', 'options-general.php', 'edit.php', 'plugins.php', 'post.php', 'profile.php', 'woocommerce_page_wc-settings', ''];
foreach ($kh_off as $kh_hook) {
    KH_Test::ok(
        !Keelhob_Admin::is_keelhob_screen($kh_hook),
        'left alone, not a Keelhob screen: ' . ($kh_hook === '' ? '(no hook suffix)' : $kh_hook)
    );
}

// And the enqueue itself, through the real callback WordPress would call.
$GLOBALS['kh_test_styles'] = [];
Keelhob_Admin::enqueue_ui('plugins.php');
KH_Test::ok(
    !isset($GLOBALS['kh_test_styles']['keelhob-admin']),
    'no stylesheet is enqueued on somebody else admin page'
);

$GLOBALS['kh_test_styles'] = [];
Keelhob_Admin::enqueue_ui('toplevel_page_keelhob');
KH_Test::ok(
    isset($GLOBALS['kh_test_styles']['keelhob-admin']),
    'the stylesheet is enqueued on the Connect screen'
);
KH_Test::contains(
    'assets/css/admin.css',
    (string) ($GLOBALS['kh_test_styles']['keelhob-admin']['src'] ?? ''),
    'and it is assets/css/admin.css'
);
/*
 * Cache busting is not cosmetic here. This project serves plugin assets through
 * a CDN that caches them for four hours, so a version string that does not
 * change when the file changes means the old stylesheet is served against the
 * new markup for most of an afternoon.
 */
KH_Test::ok(
    (string) ($GLOBALS['kh_test_styles']['keelhob-admin']['ver'] ?? '') !== '',
    'and it carries a version string, so a CDN cannot serve yesterday CSS'
);
KH_Test::ok(
    file_exists(KEELHOB_DIR . 'assets/css/admin.css'),
    'and the file it points at actually exists'
);

KH_Test::group('Admin shell — dark is decided by the admin colour scheme');

/*
 * Verified against WordPress 7.1.2's register_admin_color_schemes(): nine
 * schemes exist, and eight of the nine have a dark menu background — only
 * `light` does not. So "dark menu" cannot be the test, or every install would
 * flip to a dark panel. The two defaults, `modern` and `fresh`, mean the owner
 * expressed no preference, so they stay light. See the constant comment.
 */
KH_Test::same(
    ['midnight', 'ectoplasm', 'coffee', 'ocean', 'blue', 'sunrise'],
    Keelhob_Admin::DARK_SCHEMES,
    'the dark list is the six schemes somebody deliberately chose'
);

$GLOBALS['kh_test_user_options'] = [];
KH_Test::ok(!Keelhob_Admin::is_dark_scheme(), 'no scheme set at all means light');
KH_Test::contains('kh-ui', Keelhob_Admin::root_class(), 'the root class is always kh-ui');
KH_Test::ok(
    strpos(Keelhob_Admin::root_class(), 'is-dark') === false,
    'and carries no is-dark when the scheme is light'
);
/*
 * `wrap` must survive. Core JavaScript relocates admin notices into the first
 * .wrap on the page, and one of the notices on these screens is the "AI
 * abilities are ON" warning. Dropping the class to get a cleaner panel would
 * silently delete a safety banner.
 */
KH_Test::contains('wrap', Keelhob_Admin::root_class(), 'and keeps `wrap`, which is where core puts admin notices');

foreach (['modern', 'fresh', 'light'] as $kh_scheme) {
    $GLOBALS['kh_test_user_options'] = ['admin_color' => $kh_scheme];
    KH_Test::ok(
        !Keelhob_Admin::is_dark_scheme(),
        'the panel stays light on the ' . $kh_scheme . ' scheme'
    );
}
foreach (Keelhob_Admin::DARK_SCHEMES as $kh_scheme) {
    $GLOBALS['kh_test_user_options'] = ['admin_color' => $kh_scheme];
    KH_Test::ok(Keelhob_Admin::is_dark_scheme(), 'the panel goes dark on the ' . $kh_scheme . ' scheme');
    KH_Test::contains('is-dark', Keelhob_Admin::root_class(), 'and the root class says so: ' . $kh_scheme);
}
$GLOBALS['kh_test_user_options'] = [];

// A scheme that does not exist, or one a plugin registered, must not be guessed at.
$GLOBALS['kh_test_user_options'] = ['admin_color' => 'some-third-party-scheme'];
KH_Test::ok(!Keelhob_Admin::is_dark_scheme(), 'an unknown scheme falls back to light rather than guessing');
$GLOBALS['kh_test_user_options'] = [];

KH_Test::group('Admin shell — every tab is a real link');

$kh_strip = '';
ob_start();
Keelhob_Admin::tab_strip('keelhob-guide');
$kh_strip = (string) ob_get_clean();

$kh_slugs = array_keys(Keelhob_Admin::tabs());
KH_Test::same(
    ['keelhob', 'keelhob-studio', 'keelhob-skills', 'keelhob-automation', 'keelhob-guide', 'keelhob-activity'],
    $kh_slugs,
    'the six existing page slugs, unrenamed and unmerged'
);
// Taken from the constant, never typed twice. A strip that linked to a slug
// Keelhob_Studio had renamed would 404 while this test still passed.
KH_Test::contains(
    'page=' . Keelhob_Studio::SLUG,
    $kh_strip,
    'the Snippets slug comes from Keelhob_Studio::SLUG, not a copy of it'
);

foreach ($kh_slugs as $kh_slug) {
    KH_Test::contains(
        'admin.php?page=' . $kh_slug,
        $kh_strip,
        'the strip links to page=' . $kh_slug
    );
}

/*
 * The anti-pattern this replaces was a row of <button> elements with a JS
 * show/hide. These assertions are the ones that would have failed on it.
 */
KH_Test::same(
    count($kh_slugs),
    substr_count($kh_strip, '<a class="kh-u-nav-link"'),
    'every tab is an <a>, one each'
);
KH_Test::ok(strpos($kh_strip, '<button') === false, 'and not one of them is a <button>');
KH_Test::ok(strpos($kh_strip, 'onclick') === false, 'no inline handler — the strip works with JavaScript off');
KH_Test::ok(strpos($kh_strip, 'display:none') === false, 'and nothing is hidden waiting for a script to reveal it');

KH_Test::same(
    1,
    substr_count($kh_strip, 'aria-current="page"'),
    'exactly one tab is marked as the current page'
);
KH_Test::contains(
    'href="https://example.test/wp-admin/admin.php?page=keelhob-guide" aria-current="page"',
    $kh_strip,
    'and it is the one whose slug was passed in'
);

// A slug that is not in the strip must mark nothing rather than default to the
// first tab — a wrong aria-current is worse than none.
ob_start();
Keelhob_Admin::tab_strip('keelhob-donate');
$kh_other = (string) ob_get_clean();
KH_Test::same(0, substr_count($kh_other, 'aria-current'), 'a screen outside the strip marks no tab as current');

KH_Test::group('Admin shell — the header line');

ob_start();
Keelhob_Admin::header_bar();
$kh_head = (string) ob_get_clean();

KH_Test::contains('kh-u-head', $kh_head, 'the header renders');
KH_Test::contains('<h1 class="kh-u-name"', $kh_head, 'with an <h1>, which is where core looks for the page heading');
KH_Test::contains('Keelhob', $kh_head, 'naming the plugin');
KH_Test::contains('kh-u-site', $kh_head, 'with the site name underneath');
// `<span class="kh-u-pill`, not `kh-u-pill` — the flex container around them is
// `kh-u-pills`, and a bare substring count would score it as a third pill.
KH_Test::same(2, substr_count($kh_head, '<span class="kh-u-pill'), 'and exactly two pills: state, and connection');
KH_Test::contains('kh-u-dot', $kh_head, 'each with a status dot');
/*
 * The dot is decoration and the words carry the meaning. A pill reporting
 * whether an AI agent may change this site cannot say it in colour alone.
 */
KH_Test::same(
    2,
    substr_count($kh_head, 'aria-hidden="true"'),
    'both dots are hidden from screen readers, because the words beside them say it'
);
KH_Test::ok(
    strpos($kh_head, 'AI abilities on') !== false || strpos($kh_head, 'AI abilities off') !== false,
    'the state pill says on or off in words, not just in colour'
);
KH_Test::contains('class="kh-u-mark"', $kh_head, 'the brand mark uses the panel class, not the inline-styled heading one');
KH_Test::ok(strpos($kh_head, 'style=') === false, 'and the header carries no inline styles at all');

/*
 * The mark must not be squashed. panel_mark() derives its width from the real
 * pixel ratio in Keelhob_Brand rather than writing a number down, which is the
 * mistake that once shipped a sidebar logo at 23/40 while every test passed.
 */
$kh_expect_w = (int) round(Keelhob_Brand::PANEL_PX * Keelhob_Brand::MARK_W / Keelhob_Brand::MARK_H);
KH_Test::contains(
    'width="' . $kh_expect_w . '" height="' . Keelhob_Brand::PANEL_PX . '"',
    Keelhob_Brand::panel_mark(),
    'the panel mark is sized from the real mark ratio, so it cannot render squashed'
);

KH_Test::group('Admin shell — the .kh-panel collision is gone from Connect');

/*
 * THE LIVE BUG THIS CLOSES. `.kh-panel` is styled in assets/css/studio.css,
 * which Keelhob_Studio::enqueue_styles() attaches to the Studio and Compose
 * screens and nowhere else. The Connect screen used the same class for its app
 * config blocks, so they rendered with no styling at all — on a screen that is
 * the first one an owner opens.
 *
 * Asserted against the TEMPLATE rather than the rendered page, because the
 * failure mode is somebody reintroducing the class name while editing, and the
 * rendered output would look fine in a test either way.
 */
$kh_connect_src = (string) file_get_contents(KEELHOB_DIR . 'includes/class-admin-screens.php');

KH_Test::ok(
    strpos($kh_connect_src, 'class="kh-panel"') === false,
    'the Connect screen no longer uses .kh-panel, which studio.css owns'
);
KH_Test::ok(
    strpos($kh_connect_src, 'class="kh-tabs"') === false,
    'nor .kh-tabs'
);
KH_Test::ok(
    strpos($kh_connect_src, 'kh-tab"') === false,
    'nor .kh-tab'
);
KH_Test::contains('kh-u-client', $kh_connect_src, 'the app picker blocks have their own prefixed name');

/*
 * And the name it moved to must actually be styled, or the bug has only been
 * renamed. This is a source-level check that the rule exists; whether it paints
 * is the browser layer's job.
 */
$kh_css = (string) file_get_contents(KEELHOB_DIR . 'assets/css/admin.css');
foreach (['.kh-u-client', '.kh-u-client-btn', '.kh-u-clients', '.kh-u-nav-link', '.kh-u-pill', '.kh-u-head'] as $kh_sel) {
    KH_Test::contains($kh_sel . ' {', $kh_css, 'admin.css styles ' . $kh_sel);
}

/*
 * The three names the design system is forbidden from reusing. studio.css means
 * something different by each of them, and a new component that borrowed one
 * would reintroduce exactly the drift that produced the unstyled Connect panel.
 */
// Matched as a RULE, not as a substring: the file's own header comment discusses
// `.kh-panel` at length, and a naive substring check would fail on the
// explanation of why the name is avoided.
foreach (['.kh-tabs', '.kh-tab', '.kh-panel'] as $kh_taken) {
    KH_Test::ok(
        !preg_match('/^\s*' . preg_quote($kh_taken, '/') . '\b[^{]*\{/m', $kh_css),
        'admin.css declares no rule for the already-taken name ' . $kh_taken
    );
}

KH_Test::group('Admin shell — the layout escape hack is mirrored and collapsed');

/*
 * B9 in the risk review: `margin: 0 0 0 -20px` pulls the panel out of
 * WordPress's content box, which assumes core's current wrapper padding, breaks
 * under RTL, and assumes a 32px toolbar that is 46px on a narrow screen. All
 * three are handled in CSS, and all three are easy to delete by accident.
 */
KH_Test::contains('body.rtl .kh-ui', $kh_css, 'the negative margin is mirrored for RTL');
KH_Test::contains('max-width: 782px', $kh_css, 'and dropped at WordPress own admin breakpoint');
KH_Test::contains('calc(100vh - 46px)', $kh_css, 'where the toolbar is 46px tall, not 32px');

/*
 * The accent split is a rule, not a preference: copper and teal measure 3.35:1
 * on white and fail AA as text at every size. The tokens that exist to carry a
 * word must therefore exist.
 */
foreach (['--kh-accent-ink:', '--kh-accent-2-ink:', '--kh-muted:'] as $kh_token) {
    KH_Test::contains($kh_token, $kh_css, 'the measured token ' . trim($kh_token, ':') . ' is defined');
}
KH_Test::contains('.kh-ui.is-dark {', $kh_css, 'and the dark token set is defined too');
