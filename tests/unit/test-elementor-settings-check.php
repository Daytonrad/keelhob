<?php
/**
 * Unknown-settings detection for elementor_page set/create — the fix for a
 * wrong control name (e.g. `menu`/`menu_color` instead of `nav_menu`/
 * `menu_text_color`) being written into _elementor_data and reported
 * success while doing nothing on the front end.
 *
 * The tree-walking half needs a live Elementor (widgets_manager,
 * elements_manager) to resolve real control lists, so it isn't unit-tested
 * here — it's verified against the real keelhob.com Home page tree
 * instead. What IS unit-testable, and is the part that decides whether the
 * check cries wolf on correct pages, is the pure classification: given a
 * settings-key list and a known-controls list, which keys are unknown.
 */

require_once KEELHOB_DIR . 'includes/tools/class-tools-elementor.php';

KH_Test::group('Elementor settings check — classify_unknown_keys()');

KH_Test::same(
    [],
    Keelhob_Tools_Elementor::classify_unknown_keys(['title', 'link'], ['title', 'link', 'icon']),
    'keys present in the known-controls list are not flagged'
);

KH_Test::same(
    ['menu', 'menu_color'],
    Keelhob_Tools_Elementor::classify_unknown_keys(['menu', 'menu_color'], ['nav_menu', 'menu_text_color']),
    'the real keelhob.com bug is caught: menu/menu_color against nav_menu/menu_text_color controls'
);

KH_Test::same(
    [],
    Keelhob_Tools_Elementor::classify_unknown_keys(
        ['typography_font_size_mobile', 'title_tablet', 'width_widescreen', 'height_laptop', 'gap_tablet_extra', 'padding_mobile_extra'],
        ['typography_font_size', 'title', 'width', 'height', 'gap', 'padding']
    ),
    'every responsive suffix is stripped before comparing: _mobile, _tablet, _widescreen, _laptop, _tablet_extra, _mobile_extra'
);

KH_Test::same(
    ['size_mobile_extra'],
    Keelhob_Tools_Elementor::classify_unknown_keys(['size_mobile_extra'], ['size_mobile']),
    'a suffix is stripped whole, not partially — size_mobile_extra does not fall back to matching a size_mobile control'
);

KH_Test::same(
    [],
    Keelhob_Tools_Elementor::classify_unknown_keys(
        ['__globals__', '__dynamic__', '_element_id', '_css_classes', '_flex_size', '_margin', '_padding'],
        [] // none of these come from the widget's own get_controls() at all
    ),
    'Elementor\'s own meta keys and the whole _-prefixed common/advanced stack are always allowed, even against an empty control list'
);

KH_Test::same(
    ['totally_made_up_key'],
    Keelhob_Tools_Elementor::classify_unknown_keys(['title', 'totally_made_up_key'], ['title', 'link']),
    'a genuinely unknown key is still caught alongside legitimate ones'
);

KH_Test::same(
    [],
    Keelhob_Tools_Elementor::classify_unknown_keys([], ['title', 'link']),
    'no settings keys means no warnings'
);
