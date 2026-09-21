<?php
/**
 * The brand mark — mostly a check that the files the code names actually exist.
 *
 * This is decoration, so nothing here is about correctness of behaviour. It is
 * about one specific failure, which is quiet and embarrassing: Keelhob_Brand
 * builds URLs into assets/img/, and if a file is renamed, dropped from the zip,
 * or resized, the plugin still installs, still passes every other test, and
 * shows a broken image in the WordPress sidebar on every site that has it.
 *
 * Nobody would notice that from a green suite. So the assertions below name
 * each file, open it, and check it really is a PNG of the size its name claims.
 */

KH_Test::group('Brand — the files the admin points at');

/** Turn a public URL back into the path on disk it must be served from. */
$to_path = static function (string $url): string {
    KH_Test::ok(
        strpos($url, KEELHOB_URL) === 0,
        'brand URL is built from KEELHOB_URL, so a renamed plugin directory still resolves'
    );
    return KEELHOB_DIR . substr($url, strlen(KEELHOB_URL));
};

/** Width and height straight out of the PNG IHDR chunk. No GD required. */
$png_size = static function (string $file): array {
    $head = (string) file_get_contents($file, false, null, 0, 24);
    KH_Test::same("\x89PNG\r\n\x1a\n", substr($head, 0, 8), basename($file) . ' really is a PNG');
    $dim = unpack('Nw/Nh', substr($head, 16, 8));
    return [(int) $dim['w'], (int) $dim['h']];
};

// --- every file the class names is on disk -----------------------------------
//
// Listed with the size each one is used at, because that is the part a future
// re-export gets wrong. A 128px file where a 40px one is expected still renders
// — just heavier and softer — so a mere existence check would pass it.

$expected = [
    'icon-128.png' => [128, 128],
    'icon-256.png' => [256, 256],
];

foreach ($expected as $name => $size) {
    $path = $to_path(Keelhob_Brand::url($name));
    KH_Test::ok(is_file($path) && filesize($path) > 0, $name . ' exists and is not empty');
    KH_Test::same($size, $png_size($path), $name . ' is ' . $size[0] . 'x' . $size[1]);
}

// The one that must NOT be square. Cropping the mark to fit a square is what
// made the first attempt look like a broken image in the sidebar, so the shape
// is asserted rather than trusted.
$mono = $to_path(Keelhob_Brand::url('mark-mono-40.png'));
KH_Test::ok(is_file($mono) && filesize($mono) > 0, 'mark-mono-40.png exists and is not empty');
[$mono_w, $mono_h] = $png_size($mono);
KH_Test::same(40, $mono_w, 'the menu mark is 40px wide for a 20px slot, so it is sharp on a retina screen');

// The constants ARE the ratio every height on screen is derived from. If the
// mark is redrawn and these are not updated with it, the sidebar renders a
// squashed or stretched logo and nothing else would notice -- the previous
// version hardcoded 23/40 inside styles(), so the file and the CSS could
// disagree forever while this suite stayed green.
KH_Test::same($mono_w, Keelhob_Brand::MARK_W, 'MARK_W matches the real width of the mark file');
KH_Test::same($mono_h, Keelhob_Brand::MARK_H, 'MARK_H matches the real height of the mark file');
KH_Test::ok(
    $mono_h < $mono_w && $mono_h > $mono_w / 2.5,
    'the menu mark keeps the mark its own proportions — not squashed into a square, and not cropped to part of the logo'
);

$logo = $to_path(Keelhob_Brand::url('logo.png'));
KH_Test::ok(is_file($logo) && filesize($logo) > 0, 'logo.png exists and is not empty');
[$logo_w, $logo_h] = $png_size($logo);
KH_Test::same(Keelhob_Brand::HEADING_PX * 2, $logo_h, 'logo.png is double the heading height, so it is crisp on a retina screen');
KH_Test::ok($logo_w > $logo_h, 'logo.png is the mark at its natural width, which is wider than it is tall');

// --- the sidebar -------------------------------------------------------------

// 'none' tells WordPress not to render an <img> at all, so styles() can draw
// the mark as a mask instead. An <img> cannot follow the admin colour scheme;
// a mask filled with currentColor does it with no rules about which scheme,
// which state, or which of the nine palettes is in use.
$menu = Keelhob_Brand::menu_icon();
KH_Test::same('none', $menu, 'the menu icon is drawn by CSS, so it can take the menu text colour in every admin scheme');
KH_Test::ok(strpos($menu, 'dashicons') === false, 'and it is still not a dashicon');

// The CSS is the icon here, so it is worth reading rather than assuming.
$GLOBALS['kh_test_inline_styles'] = [];
Keelhob_Brand::styles();
$css = $GLOBALS['kh_test_inline_styles']['wp-admin'] ?? '';

KH_Test::contains('mark-mono-40.png', $css, 'the mask points at the mark file');
KH_Test::contains('currentColor', $css, 'the mark is filled with the menu text colour, not a fixed one');
KH_Test::contains('-webkit-mask:', $css, 'the prefixed property is there too — Safari still needs it');
KH_Test::contains('@supports', $css, 'the mask is behind @supports: without one, background-color alone paints a solid rectangle');
/*
 * THE SIDEBAR BOX IS THE WHOLE ROW, ON PURPOSE.
 *
 * Measured in a browser: WordPress ships `padding: 7px 0` on
 * #adminmenu .wp-menu-image::before for its own dashicons. This rule did not
 * reset it, so the painted box was 17px of content plus 14px of inherited
 * padding, and a margin-top computed as if that padding did not exist put the
 * mark 7.5px below the centre of the row.
 *
 * So the box is now ROW_PX tall and `center` does the centring. These three
 * assertions are what stop the arithmetic version coming back.
 */
KH_Test::contains(
    'width:' . Keelhob_Brand::MENU_PX . 'px;height:' . Keelhob_Brand::ROW_PX . 'px',
    $css,
    'the sidebar box fills the whole ' . Keelhob_Brand::ROW_PX . 'px row, so the mask centres itself in it'
);
KH_Test::contains('padding:0', $css, 'and it resets the padding WordPress adds for dashicons — the bug that pushed the mark low');
KH_Test::contains(
    'center / ' . Keelhob_Brand::MENU_PX . 'px auto',
    $css,
    'the mask height is auto, so the mark takes its own proportions and cannot be squashed'
);
KH_Test::ok(
    strpos($css, 'margin:0 auto') !== false,
    'no vertical offset is calculated at all — an offset is the thing that was wrong'
);
KH_Test::ok(
    strpos($css, 'admin-color-') === false,
    'no rule names a colour scheme — that is the whole point of using currentColor'
);
KH_Test::ok(
    strpos($css, 'invert(') === false,
    'and nothing is inverted: inverting broke the current menu item, whose background is already dark in the light scheme'
);

// --- the update screen -------------------------------------------------------

$icons = Keelhob_Brand::icons();
KH_Test::same(['1x', '2x', 'default'], array_keys($icons), 'icons() returns the three keys WordPress reads');
KH_Test::contains('icon-128.png', $icons['1x'], '1x is the 128px file WordPress asks for');
KH_Test::contains('icon-256.png', $icons['2x'], '2x is the 256px file');
KH_Test::same($icons['1x'], $icons['default'], 'default falls back to 1x rather than to nothing');

// --- the markup --------------------------------------------------------------

$heading = Keelhob_Brand::heading_mark();
KH_Test::contains('logo.png', $heading, 'the heading uses the wide mark');
KH_Test::contains('alt=""', $heading, 'the heading mark is decorative: the <h1> beside it already says Keelhob');
KH_Test::contains('height:28px', $heading, 'the heading mark is sized, so it cannot render at its natural 56px');

$bar = Keelhob_Brand::bar_mark();
KH_Test::contains('keelhob-bar-mark', $bar, 'the toolbar mark is a span the CSS fills, for the same reason as the sidebar');
KH_Test::contains('aria-hidden="true"', $bar, 'the toolbar mark is decorative: the node beside it already says Keelhob');
KH_Test::ok(strpos($bar, '<img') === false, 'it is not an <img> — an image cannot follow the toolbar colour, which is light in one scheme and dark in eight');
KH_Test::contains('.keelhob-bar-mark', $css, 'and the CSS that fills it is actually shipped');
