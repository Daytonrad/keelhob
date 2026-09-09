<?php
/**
 * The brand mark — mostly a check that the files the code names actually exist.
 *
 * This is decoration, so nothing here is about correctness of behaviour. It is
 * about one specific failure, which is quiet and embarrassing: Claudpress_Brand
 * builds URLs into assets/img/, and if a file is renamed, dropped from the zip,
 * or resized, the plugin still installs, still passes every other test, and
 * shows a broken image in the WordPress sidebar on every site that has it.
 *
 * Nobody would notice that from a green suite. So the assertions below name
 * each file, open it, and check it really is a PNG of the size its name claims.
 */

CP_Test::group('Brand — the files the admin points at');

/** Turn a public URL back into the path on disk it must be served from. */
$to_path = static function (string $url): string {
    CP_Test::ok(
        strpos($url, CLAUDPRESS_URL) === 0,
        'brand URL is built from CLAUDPRESS_URL, so a renamed plugin directory still resolves'
    );
    return CLAUDPRESS_DIR . substr($url, strlen(CLAUDPRESS_URL));
};

/** Width and height straight out of the PNG IHDR chunk. No GD required. */
$png_size = static function (string $file): array {
    $head = (string) file_get_contents($file, false, null, 0, 24);
    CP_Test::same("\x89PNG\r\n\x1a\n", substr($head, 0, 8), basename($file) . ' really is a PNG');
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
    $path = $to_path(Claudpress_Brand::url($name));
    CP_Test::ok(is_file($path) && filesize($path) > 0, $name . ' exists and is not empty');
    CP_Test::same($size, $png_size($path), $name . ' is ' . $size[0] . 'x' . $size[1]);
}

// The one that must NOT be square. Cropping the mark to fit a square is what
// made the first attempt look like a broken image in the sidebar, so the shape
// is asserted rather than trusted.
$mono = $to_path(Claudpress_Brand::url('mark-mono-40.png'));
CP_Test::ok(is_file($mono) && filesize($mono) > 0, 'mark-mono-40.png exists and is not empty');
[$mono_w, $mono_h] = $png_size($mono);
CP_Test::same(40, $mono_w, 'the menu mark is 40px wide for a 20px slot, so it is sharp on a retina screen');
CP_Test::ok(
    $mono_h < $mono_w && $mono_h > $mono_w / 2.5,
    'the menu mark keeps the mark its own proportions — not squashed into a square, and not cropped to part of the logo'
);

$logo = $to_path(Claudpress_Brand::url('logo.png'));
CP_Test::ok(is_file($logo) && filesize($logo) > 0, 'logo.png exists and is not empty');
[$logo_w, $logo_h] = $png_size($logo);
CP_Test::same(Claudpress_Brand::HEADING_PX * 2, $logo_h, 'logo.png is double the heading height, so it is crisp on a retina screen');
CP_Test::ok($logo_w > $logo_h, 'logo.png is the mark at its natural width, which is wider than it is tall');

// --- the sidebar -------------------------------------------------------------

// 'none' tells WordPress not to render an <img> at all, so styles() can draw
// the mark as a mask instead. An <img> cannot follow the admin colour scheme;
// a mask filled with currentColor does it with no rules about which scheme,
// which state, or which of the nine palettes is in use.
$menu = Claudpress_Brand::menu_icon();
CP_Test::same('none', $menu, 'the menu icon is drawn by CSS, so it can take the menu text colour in every admin scheme');
CP_Test::ok(strpos($menu, 'dashicons') === false, 'and it is still not a dashicon');

// The CSS is the icon here, so it is worth reading rather than assuming.
$GLOBALS['cp_test_inline_styles'] = [];
Claudpress_Brand::styles();
$css = $GLOBALS['cp_test_inline_styles']['wp-admin'] ?? '';

CP_Test::contains('mark-mono-40.png', $css, 'the mask points at the mark file');
CP_Test::contains('currentColor', $css, 'the mark is filled with the menu text colour, not a fixed one');
CP_Test::contains('-webkit-mask:', $css, 'the prefixed property is there too — Safari still needs it');
CP_Test::contains('@supports', $css, 'the mask is behind @supports: without one, background-color alone paints a solid rectangle');
CP_Test::contains('width:20px;height:12px', $css, 'the sidebar box keeps the mark proportions (20 wide, 12 tall), so nothing is squashed');
CP_Test::ok(
    strpos($css, 'admin-color-') === false,
    'no rule names a colour scheme — that is the whole point of using currentColor'
);
CP_Test::ok(
    strpos($css, 'invert(') === false,
    'and nothing is inverted: inverting broke the current menu item, whose background is already dark in the light scheme'
);

// --- the update screen -------------------------------------------------------

$icons = Claudpress_Brand::icons();
CP_Test::same(['1x', '2x', 'default'], array_keys($icons), 'icons() returns the three keys WordPress reads');
CP_Test::contains('icon-128.png', $icons['1x'], '1x is the 128px file WordPress asks for');
CP_Test::contains('icon-256.png', $icons['2x'], '2x is the 256px file');
CP_Test::same($icons['1x'], $icons['default'], 'default falls back to 1x rather than to nothing');

// --- the markup --------------------------------------------------------------

$heading = Claudpress_Brand::heading_mark();
CP_Test::contains('logo.png', $heading, 'the heading uses the wide mark');
CP_Test::contains('alt=""', $heading, 'the heading mark is decorative: the <h1> beside it already says Claudpress');
CP_Test::contains('height:28px', $heading, 'the heading mark is sized, so it cannot render at its natural 56px');

$bar = Claudpress_Brand::bar_mark();
CP_Test::contains('claudpress-bar-mark', $bar, 'the toolbar mark is a span the CSS fills, for the same reason as the sidebar');
CP_Test::contains('aria-hidden="true"', $bar, 'the toolbar mark is decorative: the node beside it already says Claudpress');
CP_Test::ok(strpos($bar, '<img') === false, 'it is not an <img> — an image cannot follow the toolbar colour, which is light in one scheme and dark in eight');
CP_Test::contains('.claudpress-bar-mark', $css, 'and the CSS that fills it is actually shipped');
