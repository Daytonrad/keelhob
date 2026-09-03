<?php
/**
 * Every tool that existed before the add-on was merged back in still exists.
 *
 * The merge folded four *-pro tool files into the originals they had been split
 * out of. The repo history records that the *split* nearly dropped three
 * always-on tools and nobody noticed until the zip was read by hand, so the
 * reverse operation gets a fixture rather than trust.
 *
 * tests/fixtures/tools-baseline.json was captured on the last commit where the
 * two halves still existed separately, by a script that has since been deleted
 * because there is nothing left for it to read. If a tool is deliberately added
 * or removed, edit the fixture in the same commit and say why - that edit is
 * the record that the change was intended.
 */

CP_Test::group('Tool surface — nothing was lost in the merge');

$baseline = json_decode(file_get_contents(__DIR__ . '/../fixtures/tools-baseline.json'), true);
CP_Test::ok(is_array($baseline) && count($baseline) === 68, 'the baseline fixture holds all 68 pre-merge tools');

// The WooCommerce groups refuse to register without WooCommerce, exactly as the
// snapshot run had to account for.
if (!class_exists('WooCommerce')) {
    class WooCommerce {}
}
foreach (glob(CLAUDPRESS_DIR . 'includes/tools/class-tools-*.php') as $file) {
    require_once $file;
}

$registry = new Claudpress_Tool_Registry();
foreach ([
    'Core', 'Filesystem', 'Content', 'Site', 'WooCommerce', 'SEO', 'Memory',
    'Skills', 'Snippets', 'Elementor', 'Ops', 'Automation', 'Payments',
    'Deploy', 'Readiness',
] as $group) {
    call_user_func(['Claudpress_Tools_' . $group, 'register'], $registry);
}

$now = [];
foreach ($registry->list() as $tool) {
    $props = array_keys((array) ($tool['inputSchema']['properties'] ?? []));
    sort($props);
    $now[$tool['name']] = [
        'required'   => array_values((array) ($tool['inputSchema']['required'] ?? [])),
        'properties' => $props,
    ];
}
ksort($now);

// One tool is gone on purpose. pro_status reported which licence a site held,
// and there are no licences. Anything else missing is a bug.
$expected = $baseline;
unset($expected['pro_status']);

CP_Test::same([], array_values(array_diff(array_keys($expected), array_keys($now))), 'no tool was lost in the merge');
CP_Test::same([], array_values(array_diff(array_keys($now), array_keys($expected))), 'no tool appeared unannounced');
CP_Test::ok(!isset($now['pro_status']), 'pro_status is gone, which is the one intended removal');

$changed = [];
foreach ($expected as $name => $shape) {
    if (isset($now[$name]) && $now[$name] !== $shape) {
        $changed[] = $name;
    }
}
CP_Test::same([], $changed, 'no tool changed its input schema during the merge');
