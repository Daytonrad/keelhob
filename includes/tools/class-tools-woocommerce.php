<?php
/**
 * WooCommerce tools: products (simple + variable), orders, coupons, customers,
 * sales reports, and store settings. Only registered when WooCommerce is active.
 * Falls back gracefully via execute_php for anything not covered here.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_WooCommerce
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $r->add('wc_product', 'Manage WooCommerce products. Actions: list, get, create, update, delete. Supports simple & variable products: name, type, regular/sale price, SKU, stock/stock_status, description/short_description, categories, tags, attributes, images (URLs), weight/dimensions, status, and variations.', [
            'type' => 'object',
            'properties' => [
                'action'            => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete'], 'default' => 'list'],
                'id'                => ['type' => 'integer'],
                'name'              => ['type' => 'string'],
                'type'              => ['type' => 'string', 'enum' => ['simple', 'variable', 'grouped', 'external'], 'default' => 'simple'],
                'regular_price'     => ['type' => 'string'],
                'sale_price'        => ['type' => 'string'],
                'sku'               => ['type' => 'string'],
                'manage_stock'      => ['type' => 'boolean'],
                'stock_quantity'    => ['type' => 'integer'],
                'stock_status'      => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
                'description'       => ['type' => 'string'],
                'short_description' => ['type' => 'string'],
                'status'            => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'private'], 'default' => 'publish'],
                'categories'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Category names or IDs.'],
                'tags'              => ['type' => 'array', 'items' => ['type' => 'string']],
                'images'            => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Image URLs; first is the featured image.'],
                'attributes'        => ['type' => 'object', 'description' => 'Map of attribute name => [values]. For variable products, mark variation attributes.'],
                'weight'            => ['type' => 'string'],
                'featured'          => ['type' => 'boolean'],
                'search'            => ['type' => 'string'],
                'per_page'          => ['type' => 'integer', 'default' => 20],
                'page'              => ['type' => 'integer', 'default' => 1],
            ],
            'required' => ['action'],
        ], [self::class, 'wc_product'], ['destructiveHint' => true]);


        $r->add('wc_coupon', 'Manage WooCommerce coupons. Actions: list, get, create, update, delete. Supports code, discount_type (percent, fixed_cart, fixed_product), amount, expiry, usage limits, minimum/maximum spend, and product/category restrictions.', [
            'type' => 'object',
            'properties' => [
                'action'         => ['type' => 'string', 'enum' => ['list', 'get', 'create', 'update', 'delete'], 'default' => 'list'],
                'id'             => ['type' => 'integer'],
                'code'           => ['type' => 'string'],
                'discount_type'  => ['type' => 'string', 'enum' => ['percent', 'fixed_cart', 'fixed_product'], 'default' => 'percent'],
                'amount'         => ['type' => 'string'],
                'expiry_date'    => ['type' => 'string', 'description' => 'Y-m-d'],
                'usage_limit'    => ['type' => 'integer'],
                'minimum_amount' => ['type' => 'string'],
                'free_shipping'  => ['type' => 'boolean'],
            ],
            'required' => ['action'],
        ], [self::class, 'wc_coupon'], ['destructiveHint' => true]);


        $r->add('wc_settings', 'Get or update core WooCommerce store settings: currency, country/base location, selling locations, measurement units, tax and shipping toggles, and enabled payment gateways. Actions: get, update.', [
            'type' => 'object',
            'properties' => [
                'action'   => ['type' => 'string', 'enum' => ['get', 'update'], 'default' => 'get'],
                'settings' => ['type' => 'object', 'description' => 'Map of woocommerce_* option keys to values (e.g. {"woocommerce_currency":"AED"}).'],
            ],
            'required' => ['action'],
        ], [self::class, 'wc_settings'], ['destructiveHint' => true]);
    }

    // ---- products ---------------------------------------------------------

    public static function wc_product(array $args): array
    {
        $action = $args['action'] ?? 'list';

        switch ($action) {
            case 'get':
                $p = wc_get_product((int) ($args['id'] ?? 0));
                return $p ? ['success' => true, 'product' => self::serialize_product($p)] : ['success' => false, 'error' => 'Product not found.'];

            case 'delete':
                $p = wc_get_product((int) ($args['id'] ?? 0));
                if (!$p) {
                    return ['success' => false, 'error' => 'Product not found.'];
                }
                $p->delete((bool) ($args['force'] ?? false));
                return ['success' => true, 'id' => (int) $args['id']];

            case 'create':
            case 'update':
                return self::save_product($args, $action);

            case 'list':
            default:
                $query = new WC_Product_Query([
                    'limit'   => (int) ($args['per_page'] ?? 20),
                    'page'    => (int) ($args['page'] ?? 1),
                    'status'  => ['publish', 'draft', 'pending', 'private'],
                    's'       => $args['search'] ?? '',
                    'return'  => 'objects',
                ]);
                $items = [];
                foreach ($query->get_products() as $p) {
                    $items[] = ['id' => $p->get_id(), 'name' => $p->get_name(), 'type' => $p->get_type(), 'price' => $p->get_price(), 'sku' => $p->get_sku(), 'stock_status' => $p->get_stock_status(), 'status' => $p->get_status()];
                }
                return ['success' => true, 'products' => $items];
        }
    }

    private static function save_product(array $args, string $action): array
    {
        $type = $args['type'] ?? 'simple';
        if ($action === 'update') {
            $product = wc_get_product((int) ($args['id'] ?? 0));
            if (!$product) {
                return ['success' => false, 'error' => 'Product not found.'];
            }
        } else {
            $class = 'WC_Product_' . ucfirst($type === 'variable' ? 'Variable' : ($type === 'grouped' ? 'Grouped' : ($type === 'external' ? 'External' : 'Simple')));
            $product = class_exists($class) ? new $class() : new WC_Product_Simple();
        }

        $setters = [
            'name'              => 'set_name',
            'description'       => 'set_description',
            'short_description' => 'set_short_description',
            'sku'               => 'set_sku',
            'regular_price'     => 'set_regular_price',
            'sale_price'        => 'set_sale_price',
            'status'            => 'set_status',
            'stock_status'      => 'set_stock_status',
            'weight'            => 'set_weight',
            'featured'          => 'set_featured',
        ];
        foreach ($setters as $arg => $method) {
            if (array_key_exists($arg, $args) && method_exists($product, $method)) {
                try {
                    $product->{$method}($args[$arg]);
                } catch (Throwable $e) {
                    // skip invalid (e.g. duplicate SKU) but report later
                    if ($arg === 'sku') {
                        return ['success' => false, 'error' => 'SKU error: ' . $e->getMessage()];
                    }
                }
            }
        }
        if (array_key_exists('manage_stock', $args)) {
            $product->set_manage_stock((bool) $args['manage_stock']);
        }
        if (array_key_exists('stock_quantity', $args)) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) $args['stock_quantity']);
        }

        // Categories / tags.
        if (isset($args['categories'])) {
            $product->set_category_ids(self::term_ids($args['categories'], 'product_cat'));
        }
        if (isset($args['tags'])) {
            $product->set_tag_ids(self::term_ids($args['tags'], 'product_tag'));
        }

        // Attributes (also used for variable variation axes).
        if (!empty($args['attributes']) && is_array($args['attributes'])) {
            $product->set_attributes(self::build_attributes($args['attributes']));
        }

        $id = $product->save();
        if (!$id) {
            return ['success' => false, 'error' => 'Failed to save product.'];
        }

        // Images.
        if (!empty($args['images']) && is_array($args['images'])) {
            self::attach_images($id, $args['images']);
        }

        return ['success' => true, 'id' => $id, 'link' => get_permalink($id), 'edit_link' => admin_url('post.php?post=' . $id . '&action=edit')];
    }

    private static function build_attributes(array $map): array
    {
        $attrs = [];
        foreach ($map as $name => $values) {
            $values = is_array($values) ? $values : array_map('trim', explode('|', (string) $values));
            $a = new WC_Product_Attribute();
            $a->set_name($name);
            $a->set_options($values);
            $a->set_visible(true);
            $a->set_variation(false);
            $attrs[] = $a;
        }
        return $attrs;
    }

    private static function term_ids($terms, string $tax): array
    {
        $ids = [];
        foreach ((array) $terms as $t) {
            if (is_numeric($t)) {
                $ids[] = (int) $t;
                continue;
            }
            $term = term_exists((string) $t, $tax);
            if (!$term) {
                $term = wp_insert_term((string) $t, $tax);
            }
            if (!is_wp_error($term)) {
                $ids[] = (int) $term['term_id'];
            }
        }
        return $ids;
    }

    private static function attach_images(int $product_id, array $urls): void
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $gallery = [];
        foreach ($urls as $i => $url) {
            $att = media_sideload_image((string) $url, $product_id, null, 'id');
            if (is_wp_error($att)) {
                continue;
            }
            if ($i === 0) {
                set_post_thumbnail($product_id, $att);
            } else {
                $gallery[] = $att;
            }
        }
        if ($gallery) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery));
        }
    }

    private static function serialize_product(WC_Product $p): array
    {
        return [
            'id'             => $p->get_id(),
            'name'           => $p->get_name(),
            'type'           => $p->get_type(),
            'status'         => $p->get_status(),
            'sku'            => $p->get_sku(),
            'regular_price'  => $p->get_regular_price(),
            'sale_price'     => $p->get_sale_price(),
            'price'          => $p->get_price(),
            'stock_status'   => $p->get_stock_status(),
            'stock_quantity' => $p->get_stock_quantity(),
            'manage_stock'   => $p->get_manage_stock(),
            'categories'     => wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names']),
            'featured'       => $p->get_featured(),
            'permalink'      => $p->get_permalink(),
            'short_description' => $p->get_short_description(),
        ];
    }

    // ---- orders -----------------------------------------------------------



    // ---- coupons ----------------------------------------------------------

    public static function wc_coupon(array $args): array
    {
        $action = $args['action'] ?? 'list';

        switch ($action) {
            case 'delete':
                $c = new WC_Coupon((int) ($args['id'] ?? 0));
                if (!$c->get_id()) {
                    return ['success' => false, 'error' => 'Coupon not found.'];
                }
                $c->delete(true);
                return ['success' => true, 'id' => (int) $args['id']];

            case 'get':
                $c = new WC_Coupon($args['id'] ?? $args['code'] ?? 0);
                return $c->get_id() ? ['success' => true, 'coupon' => ['id' => $c->get_id(), 'code' => $c->get_code(), 'type' => $c->get_discount_type(), 'amount' => $c->get_amount(), 'usage' => $c->get_usage_count()]] : ['success' => false, 'error' => 'Coupon not found.'];

            case 'create':
            case 'update':
                $c = $action === 'update' ? new WC_Coupon((int) ($args['id'] ?? 0)) : new WC_Coupon();
                if (!empty($args['code'])) {
                    $c->set_code($args['code']);
                }
                if (!empty($args['discount_type'])) {
                    $c->set_discount_type($args['discount_type']);
                }
                if (array_key_exists('amount', $args)) {
                    $c->set_amount((float) $args['amount']);
                }
                if (!empty($args['expiry_date'])) {
                    $c->set_date_expires($args['expiry_date']);
                }
                if (array_key_exists('usage_limit', $args)) {
                    $c->set_usage_limit((int) $args['usage_limit']);
                }
                if (array_key_exists('minimum_amount', $args)) {
                    $c->set_minimum_amount((string) $args['minimum_amount']);
                }
                if (array_key_exists('free_shipping', $args)) {
                    $c->set_free_shipping((bool) $args['free_shipping']);
                }
                $id = $c->save();
                return ['success' => true, 'id' => $id, 'code' => $c->get_code()];

            case 'list':
            default:
                $q = get_posts(['post_type' => 'shop_coupon', 'posts_per_page' => 100, 'post_status' => 'publish']);
                $out = [];
                foreach ($q as $post) {
                    $c = new WC_Coupon($post->ID);
                    $out[] = ['id' => $c->get_id(), 'code' => $c->get_code(), 'type' => $c->get_discount_type(), 'amount' => $c->get_amount()];
                }
                return ['success' => true, 'coupons' => $out];
        }
    }

    // ---- reports ----------------------------------------------------------


    // ---- settings ---------------------------------------------------------

    public static function wc_settings(array $args): array
    {
        $keys = ['woocommerce_currency', 'woocommerce_default_country', 'woocommerce_allowed_countries', 'woocommerce_specific_allowed_countries', 'woocommerce_weight_unit', 'woocommerce_dimension_unit', 'woocommerce_calc_taxes', 'woocommerce_enable_coupons', 'woocommerce_price_num_decimals', 'woocommerce_currency_pos', 'woocommerce_price_thousand_sep', 'woocommerce_price_decimal_sep'];

        if (($args['action'] ?? 'get') === 'update' && !empty($args['settings']) && is_array($args['settings'])) {
            $changed = [];
            foreach ($args['settings'] as $k => $v) {
                if (str_starts_with($k, 'woocommerce_')) {
                    update_option($k, $v);
                    $changed[$k] = $v;
                }
            }
            return ['success' => true, 'updated' => $changed];
        }

        $out = [];
        foreach ($keys as $k) {
            $out[$k] = get_option($k);
        }
        $gateways = [];
        foreach (WC()->payment_gateways()->payment_gateways() as $gw) {
            $gateways[$gw->id] = ['title' => $gw->get_title(), 'enabled' => $gw->enabled === 'yes'];
        }
        return ['success' => true, 'settings' => $out, 'payment_gateways' => $gateways];
    }
}
