<?php
/**
 * Dump every registered tool to tests/fixtures/tools-baseline.json.
 *
 * Run this ONCE, before the Pro add-on is merged back into the plugin, to
 * record what the full tool surface looks like while the two halves are still
 * separate. Everything after the merge is checked against that record.
 *
 * The merge folds four files that were split out of their originals back into
 * them, and the repo history already shows that *splitting* them nearly dropped
 * three always-on tools without anybody noticing. A name-and-schema snapshot is
 * the only cheap way to prove the reverse operation lost nothing.
 *
 *   php tests/snapshot-tools.php
 */
require_once __DIR__ . '/bootstrap.php';

// The add-on's own bootstrap is not loaded in tests, so require its tool files
// and licence-backed registrar the way claudpress-pro.php would.
$pro = CLAUDPRESS_DIR . 'claudpress-pro/includes/';
foreach ([
    'class-claudpress-pro-licence.php',
    'class-tools-payments.php',
    'class-tools-deploy.php',
    'class-tools-ops-pro.php',
    'class-tools-automation-pro.php',
    'class-tools-woocommerce-pro.php',
    'class-tools-seo-pro.php',
    'class-claudpress-pro-tools.php',
] as $file) {
    if (!file_exists($pro . $file)) {
        fwrite(STDERR, "Missing $pro$file — run this before the merge, not after.\n");
        exit(1);
    }
    require_once $pro . $file;
}

// The only way past the licence gate: the same override the rig uses. Nothing
// in production ever writes a valid licence, so this is not a shortcut around
// a check that would otherwise pass — it is the only door there is.
add_filter('claudpress_pro_licence_override', static function () { return true; });
add_filter('claudpress_is_pro', static function () { return true; });

// The WooCommerce tool groups refuse to register when WooCommerce is absent,
// which would quietly leave every wc_* tool out of the baseline — exactly the
// kind of hole this snapshot exists to catch. A bare class declaration is
// enough: registration only asks whether WooCommerce exists.
if (!class_exists('WooCommerce')) {
    class WooCommerce {}
}

// The harness loads only a couple of tool files, so pull in the rest.
foreach (glob(CLAUDPRESS_DIR . 'includes/tools/class-tools-*.php') as $file) {
    require_once $file;
}

// Register onto a bare registry rather than calling boot(). boot() installs
// database tables and wants more of WordPress than the harness fakes, and none
// of that changes which tools exist.
$registry = new Claudpress_Tool_Registry();
Claudpress_Tools_Core::register($registry);
Claudpress_Tools_Filesystem::register($registry);
Claudpress_Tools_Content::register($registry);
Claudpress_Tools_Site::register($registry);
Claudpress_Tools_WooCommerce::register($registry);
Claudpress_Tools_SEO::register($registry);
Claudpress_Tools_Memory::register($registry);
Claudpress_Tools_Skills::register($registry);
Claudpress_Tools_Snippets::register($registry);
Claudpress_Tools_Elementor::register($registry);
Claudpress_Tools_Ops::register($registry);
Claudpress_Tools_Automation::register($registry);
Claudpress_Tools_Readiness::register($registry);
Claudpress_Pro_Tools::register($registry);
$out      = [];
foreach ($registry->list() as $tool) {
    $name = $tool['name'] ?? '';
    if ($name === '') {
        continue;
    }
    // Names and schema shape only. Descriptions get reworded constantly and a
    // snapshot that fails on prose is a snapshot people delete.
    $props = array_keys((array) ($tool['inputSchema']['properties'] ?? []));
    sort($props);
    $out[$name] = [
        'required'   => array_values((array) ($tool['inputSchema']['required'] ?? [])),
        'properties' => $props,
    ];
}
ksort($out);

$path = __DIR__ . '/fixtures/tools-baseline.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Wrote " . count($out) . " tools to $path\n";
