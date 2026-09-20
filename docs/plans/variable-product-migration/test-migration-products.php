<?php
/**
 * PARKED — not part of tests/run.php. The implementation these tests drive was
 * pulled from the plugin; see ../variable-product-migration.md for why and for
 * how to reinstate them. They will fatal if run as is.
 *
 * Migration engine — WooCommerce attributes & variable-product variations.
 *
 * These are the edge features of the migration bundle: a variable product is
 * the only entity whose identity lives in *two* places (term IDs on the parent,
 * term slugs on each child) and neither survives a move between sites by
 * itself. The tests drive the private export/import helpers directly against
 * tests/fake-woocommerce.php, so a full round trip runs with no WP install.
 */

require_once __DIR__ . '/../fake-woocommerce.php';

/** Clear the per-bundle attribute slug map, as import() does between bundles. */
function kh_mig_reset(): void
{
    $p = new ReflectionProperty('Keelhob_Migration', 'slug_map');
    $p->setAccessible(true);
    $p->setValue(null, []);
}

/** Invoke a private static on the migration engine. */
function kh_mig(string $method, ...$args)
{
    static $cache = [];
    if (!isset($cache[$method])) {
        $m = new ReflectionMethod('Keelhob_Migration', $method);
        $m->setAccessible(true);
        $cache[$method] = $m;
    }
    return $cache[$method]->invokeArgs(null, $args);
}

/**
 * Build a source site: one variable product, one taxonomy attribute whose term
 * slugs deliberately differ from what sanitize_title() would produce from the
 * names ("Large" → "lg"), which is exactly what a real store looks like after
 * years of editing.
 */
function kh_seed_source_site(): array
{
    kh_wc_reset();
    $large = kh_wc_seed_term('pa_size', 'Large', 'lg');
    $small = kh_wc_seed_term('pa_size', 'Small', 'sm');

    $attr = new WC_Product_Attribute();
    $attr->set_id(1);
    $attr->set_name('pa_size');
    $attr->set_options([$large, $small]);
    $attr->set_position(0);
    $attr->set_visible(true);
    $attr->set_variation(true);

    $custom = new WC_Product_Attribute();
    $custom->set_id(0);
    $custom->set_name('Engraving');
    $custom->set_options(['None', 'Initials']);
    $custom->set_visible(true);
    $custom->set_variation(false);

    $parent = kh_wc_make(WC_Product_Variable::class, ['sku' => 'TEE'], 10);
    $parent->set_attributes([$attr, $custom]);
    $parent->save();

    $v1 = new WC_Product_Variation(11);
    $v1->set_id(11);
    $v1->set_parent_id(10);
    $v1->set_attributes(['pa_size' => 'lg']);
    $v1->set_sku('TEE-LG');
    $v1->set_regular_price('29.00');
    $v1->set_sale_price('24.00');
    $v1->set_manage_stock(true);
    $v1->set_stock_quantity(7);
    $v1->set_weight('0.4');
    $v1->set_description('Roomy fit');
    $v1->set_image_id(31);
    $v1->save();

    $v2 = new WC_Product_Variation(12);
    $v2->set_id(12);
    $v2->set_parent_id(10);
    $v2->set_attributes(['pa_size' => 'sm']);
    $v2->set_sku('TEE-SM');
    $v2->set_regular_price('29.00');
    $v2->save();

    return ['parent' => wc_get_product(10), 'large' => $large, 'small' => $small];
}

// ---- export ----------------------------------------------------------------

KH_Test::group('Migration — attribute export');

$src   = kh_seed_source_site();
$attrs = kh_mig('export_attributes', $src['parent']);

KH_Test::same(2, count($attrs), 'both attributes are exported');
KH_Test::same('pa_size', $attrs[0]['taxonomy'], 'taxonomy attribute records its taxonomy');
KH_Test::same(['Large', 'Small'], $attrs[0]['options'], 'taxonomy options export as names, not term IDs');
KH_Test::same(true, $attrs[0]['variation'], 'the "used for variations" flag survives');
KH_Test::same('', $attrs[1]['taxonomy'], 'a custom attribute has no taxonomy');
KH_Test::same(['None', 'Initials'], $attrs[1]['options'], 'custom attribute options pass through verbatim');

// The parent stores term IDs, each variation stores a term *slug*. Names alone
// cannot rebuild that link on another site, so the slugs must travel too.
KH_Test::ok(
    isset($attrs[0]['option_slugs']) && $attrs[0]['option_slugs'] === ['lg', 'sm'],
    'taxonomy options also export their term slugs (variations select by slug)'
);

KH_Test::group('Migration — variation export');

$vars = kh_mig('export_variations', $src['parent']);
KH_Test::same(2, count($vars), 'every child variation is exported');
KH_Test::same(['pa_size' => 'lg'], $vars[0]['attributes'], 'the attribute combination that selects the variation is kept');
KH_Test::same('TEE-LG', $vars[0]['sku'], 'variation SKU is exported');
KH_Test::same('24.00', $vars[0]['sale_price'], 'variation sale price is exported');
KH_Test::same(7, $vars[0]['stock_quantity'], 'per-variation stock is exported');
KH_Test::same('0.4', $vars[0]['weight'], 'per-variation weight is exported');
KH_Test::contains('att-31', (string) $vars[0]['image'], 'the variation image travels as a URL');
KH_Test::same(null, $vars[1]['image'], 'a variation with no image exports null');

// ---- import onto a clean destination ---------------------------------------

KH_Test::group('Migration — attribute import onto a fresh site');

$exported_attrs = $attrs;
$exported_vars  = $vars;

kh_wc_reset(); // destination site: nothing exists yet
kh_mig_reset();
$built = kh_mig('build_attributes', $exported_attrs);

KH_Test::same(2, count($built), 'both attributes are rebuilt');
KH_Test::ok(taxonomy_exists('pa_size'), 'the global attribute taxonomy is created on the destination');
KH_Test::same('pa_size', $built[0]->get_name(), 'the taxonomy attribute keeps its name');
KH_Test::same(2, count($built[0]->get_options()), 'both terms are created and mapped to destination IDs');
KH_Test::same(true, $built[0]->get_variation(), 'the "used for variations" flag is rebuilt');
KH_Test::same(['None', 'Initials'], $built[1]->get_options(), 'custom attribute options are rebuilt verbatim');

$slugs = array_map(fn($id) => kh_wc_term_by_id((int) $id)['slug'] ?? '', $built[0]->get_options());
KH_Test::same(['lg', 'sm'], $slugs, 'destination terms keep the SOURCE slugs, so variations still resolve');

KH_Test::group('Migration — variation import');

$parent = kh_wc_make(WC_Product_Variable::class, [], 10);
$parent->set_attributes($built);
$parent->save();

kh_mig('apply_variations', 10, $exported_vars);
$children = wc_get_product(10)->get_children();
KH_Test::same(2, count($children), 'each exported variation is created on the destination');

$imported = wc_get_product($children[0]);
KH_Test::same('variation', $imported->get_type(), 'children are real variations');
KH_Test::same(10, $imported->get_parent_id(), 'children are attached to the imported parent');
KH_Test::same('29.00', $imported->get_regular_price(), 'variation price survives the trip');
KH_Test::same(7, $imported->get_stock_quantity(), 'variation stock survives the trip');
KH_Test::same('publish', $imported->get_status(), 'imported variations are published, not draft');

// The invariant that decides whether the product is actually buyable: every
// attribute value on a variation must name a term that exists on this site.
$resolvable = true;
foreach ($children as $child_id) {
    foreach (wc_get_product($child_id)->get_attributes() as $taxonomy => $value) {
        if ($value !== '' && !term_exists((string) $value, (string) $taxonomy)) {
            $resolvable = false;
        }
    }
}
KH_Test::ok($resolvable, 'every imported variation selects a term that exists on the destination');

KH_Test::same(1, count($GLOBALS['kh_wc']['thumbs']), 'the variation image is sideloaded once');

// Re-importing the same bundle must update, not duplicate.
$exported_vars[0]['regular_price'] = '31.00';
kh_mig('apply_variations', 10, $exported_vars);
$children2 = wc_get_product(10)->get_children();
KH_Test::same(2, count($children2), 're-importing the same bundle does not stack duplicate variations');
KH_Test::same('31.00', wc_get_product($children2[0])->get_regular_price(), 're-import updates the matched variation in place');
KH_Test::same(1, count($GLOBALS['kh_wc']['thumbs']), 're-import does not sideload the image again');

KH_Test::group('Migration — import onto a site that already has the terms');

// The destination already sells "Large", but under its own slug. Reusing that
// term is right; leaving the variations pointing at the source slug is not.
kh_wc_reset();
kh_mig_reset();
kh_wc_seed_term('pa_size', 'Large', 'large');
kh_wc_seed_term('pa_size', 'Small', 'small');

$built2 = kh_mig('build_attributes', $exported_attrs);
$slugs2 = array_map(fn($id) => kh_wc_term_by_id((int) $id)['slug'] ?? '', $built2[0]->get_options());
KH_Test::same(['large', 'small'], $slugs2, 'an existing same-named term is reused rather than duplicated');
KH_Test::same(2, count(array_filter($GLOBALS['kh_wc']['terms'], fn($t) => $t['taxonomy'] === 'pa_size')), 'no duplicate terms are created');

$parent2 = kh_wc_make(WC_Product_Variable::class, [], 40);
$parent2->set_attributes($built2);
$parent2->save();
kh_mig('apply_variations', 40, $exported_vars);

$values = [];
foreach (wc_get_product(40)->get_children() as $child_id) {
    $values[] = wc_get_product($child_id)->get_attributes()['pa_size'] ?? '';
}
sort($values);
KH_Test::same(['large', 'small'], $values, 'variation attributes are remapped to the destination slugs');

KH_Test::group('Migration — variation import safety');

// A SKU that already belongs to something else on the destination must never be
// re-parented into this product: that would silently destroy an unrelated item.
kh_wc_reset();
kh_mig_reset();
taxonomy_exists('pa_size') || register_taxonomy('pa_size');
kh_wc_seed_term('pa_size', 'Large', 'lg');
$bystander = kh_wc_make(WC_Product_Simple::class, ['sku' => 'TEE-LG', 'regular_price' => '9.00'], 1);
kh_wc_make(WC_Product_Variable::class, [], 10);

kh_mig('apply_variations', 10, [[
    'sku'           => 'TEE-LG',
    'attributes'    => ['pa_size' => 'lg'],
    'regular_price' => '29.00',
]]);

$bystander = wc_get_product(1);
KH_Test::same('simple', $bystander->get_type(), 'a same-SKU simple product is not converted into a variation');
KH_Test::same(0, $bystander->get_parent_id(), 'a same-SKU simple product is not re-parented');
KH_Test::same('9.00', $bystander->get_regular_price(), 'a same-SKU simple product keeps its own price');
KH_Test::same(1, count(wc_get_product(10)->get_children()), 'the incoming variation is created as a new child');

// A variation belonging to a *different* parent is equally off limits.
kh_wc_reset();
kh_mig_reset();
kh_wc_seed_term('pa_size', 'Large', 'lg');
kh_wc_make(WC_Product_Variable::class, [], 20);
kh_wc_make(WC_Product_Variable::class, [], 21);
$foreign = new WC_Product_Variation();
$foreign->set_parent_id(20);
$foreign->set_attributes(['pa_size' => 'lg']);
$foreign->set_sku('SHARED');
$foreign->save();
$foreign_id = $foreign->get_id();

kh_mig('apply_variations', 21, [[
    'sku'        => 'SHARED',
    'attributes' => ['pa_size' => 'lg'],
]]);

KH_Test::same(20, wc_get_product($foreign_id)->get_parent_id(), "another parent's variation is not stolen by SKU match");
KH_Test::same(1, count(wc_get_product(20)->get_children()), 'the original parent keeps its variation');

KH_Test::group('Migration — variation matching key');

KH_Test::same(
    kh_mig('variation_key', ['pa_size' => 'lg', 'pa_color' => 'red']),
    kh_mig('variation_key', ['pa_color' => 'red', 'pa_size' => 'lg']),
    'the matching key is independent of attribute order'
);
KH_Test::ok(
    kh_mig('variation_key', ['pa_size' => 'lg']) !== kh_mig('variation_key', ['pa_size' => 'sm']),
    'different combinations produce different keys'
);
KH_Test::same(
    kh_mig('variation_key', ['pa_size' => '']),
    kh_mig('variation_key', ['pa_size' => '']),
    'an "any" (empty) attribute value is a stable key'
);

kh_wc_reset();
kh_mig_reset();
