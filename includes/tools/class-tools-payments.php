<?php
/**
 * Commerce configuration tools: payment gateways, shipping (zones/methods/
 * classes), and tax rates. Completes the "set up a working checkout" story so
 * an agent can take a store from empty to able-to-take-real-orders.
 * Registered only when WooCommerce is active.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Payments
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $r->add('wc_payments', 'Configure WooCommerce payment gateways. Actions: list (all gateways, enabled state, settings), enable, disable, configure (set a gateway\'s settings — e.g. Stripe publishable/secret keys, PayPal email, BACS bank account, title/description, testmode). Common gateway ids: cod (cash on delivery), bacs (direct bank transfer), cheque, stripe, ppcp-gateway/paypal.', [
            'type' => 'object',
            'properties' => [
                'action'   => ['type' => 'string', 'enum' => ['list', 'enable', 'disable', 'configure'], 'default' => 'list'],
                'gateway'  => ['type' => 'string', 'description' => 'Gateway id, e.g. cod, bacs, stripe.'],
                'settings' => ['type' => 'object', 'description' => 'Key/value settings to merge for configure (e.g. {"title":"Card","testmode":"yes","publishable_key":"pk_..."}).'],
            ],
            'required' => ['action'],
        ], [self::class, 'wc_payments'], ['destructiveHint' => true]);

        $r->add('wc_shipping', 'Configure WooCommerce shipping. Actions: list_zones, create_zone, add_method (flat_rate|free_shipping|local_pickup to a zone, with cost/settings), list_classes, create_class. Build shipping by creating a zone (region) then adding methods to it.', [
            'type' => 'object',
            'properties' => [
                'action'    => ['type' => 'string', 'enum' => ['list_zones', 'create_zone', 'add_method', 'list_classes', 'create_class'], 'default' => 'list_zones'],
                'zone_name' => ['type' => 'string'],
                'zone_id'   => ['type' => 'integer'],
                'regions'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Region codes for create_zone, e.g. ["US","GB","AE"] or continent codes.'],
                'method'    => ['type' => 'string', 'enum' => ['flat_rate', 'free_shipping', 'local_pickup'], 'description' => 'For add_method.'],
                'cost'      => ['type' => 'string', 'description' => 'Cost for flat_rate/local_pickup (e.g. "15").'],
                'title'     => ['type' => 'string'],
                'class_name' => ['type' => 'string', 'description' => 'For create_class.'],
            ],
            'required' => ['action'],
        ], [self::class, 'wc_shipping'], ['destructiveHint' => true]);

        $r->add('wc_tax', 'Configure WooCommerce tax. Actions: enable (turn taxes on), disable, list_rates, add_rate (country/state/rate percent/name), delete_rate. Use to set up VAT/sales tax.', [
            'type' => 'object',
            'properties' => [
                'action'  => ['type' => 'string', 'enum' => ['enable', 'disable', 'list_rates', 'add_rate', 'delete_rate'], 'default' => 'list_rates'],
                'country' => ['type' => 'string', 'description' => '2-letter country code, or blank for all.'],
                'state'   => ['type' => 'string'],
                'rate'    => ['type' => 'string', 'description' => 'Percentage, e.g. "5.0" or "20.0".'],
                'name'    => ['type' => 'string', 'description' => 'Tax name, e.g. "VAT".'],
                'rate_id' => ['type' => 'integer', 'description' => 'For delete_rate.'],
            ],
            'required' => ['action'],
        ], [self::class, 'wc_tax'], ['destructiveHint' => true]);
    }

    // ---- payments ---------------------------------------------------------

    public static function wc_payments(array $a): array
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $action   = $a['action'] ?? 'list';
        $id       = (string) ($a['gateway'] ?? '');

        if ($action === 'list') {
            $out = [];
            foreach ($gateways as $gw) {
                $out[] = [
                    'id'          => $gw->id,
                    'title'       => $gw->get_title(),
                    'enabled'     => $gw->enabled === 'yes',
                    'method_title' => $gw->get_method_title(),
                    'setting_keys' => array_keys($gw->settings ?? []),
                ];
            }
            return ['success' => true, 'gateways' => $out];
        }

        if (!isset($gateways[$id])) {
            return ['success' => false, 'error' => 'Unknown gateway: ' . $id . '. Use action=list to see ids.'];
        }
        $option_key = 'woocommerce_' . $id . '_settings';
        $settings   = get_option($option_key, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        if ($action === 'enable' || $action === 'disable') {
            $settings['enabled'] = $action === 'enable' ? 'yes' : 'no';
            update_option($option_key, $settings);
            return ['success' => true, 'gateway' => $id, 'enabled' => $action === 'enable'];
        }

        if ($action === 'configure') {
            $new = is_array($a['settings'] ?? null) ? $a['settings'] : [];
            $settings = array_merge($settings, $new);
            update_option($option_key, $settings);
            // Redact secrets in the echo.
            $safe = $settings;
            foreach ($safe as $k => $v) {
                if (preg_match('/(secret|private|password|key)/i', $k) && is_string($v) && $v !== '') {
                    $safe[$k] = '••••';
                }
            }
            return ['success' => true, 'gateway' => $id, 'settings' => $safe];
        }

        return ['success' => false, 'error' => 'Unknown action.'];
    }

    // ---- shipping ---------------------------------------------------------

    public static function wc_shipping(array $a): array
    {
        if (!class_exists('WC_Shipping_Zones')) {
            return ['success' => false, 'error' => 'WooCommerce shipping not available.'];
        }
        $action = $a['action'] ?? 'list_zones';

        switch ($action) {
            case 'create_zone':
                $zone = new WC_Shipping_Zone();
                $zone->set_zone_name((string) ($a['zone_name'] ?? 'Zone'));
                $order = 0;
                foreach ((array) ($a['regions'] ?? []) as $code) {
                    // Accept "US" (country) or "US:CA" (state) or continent codes.
                    $type = strpos((string) $code, ':') !== false ? 'state' : (strlen((string) $code) === 2 ? 'country' : 'continent');
                    $zone->add_location($code, $type);
                }
                $zone->save();
                return ['success' => true, 'zone_id' => $zone->get_id(), 'name' => $zone->get_zone_name()];

            case 'add_method':
                $zone = new WC_Shipping_Zone((int) ($a['zone_id'] ?? 0));
                if (!$zone->get_id() && (int) ($a['zone_id'] ?? 0) !== 0) {
                    return ['success' => false, 'error' => 'Zone not found.'];
                }
                $method = (string) ($a['method'] ?? 'flat_rate');
                $instance_id = $zone->add_shipping_method($method);
                // Configure cost/title.
                $opt_key = 'woocommerce_' . $method . '_' . $instance_id . '_settings';
                $settings = get_option($opt_key, []);
                if (!is_array($settings)) {
                    $settings = [];
                }
                if (!empty($a['title'])) {
                    $settings['title'] = $a['title'];
                }
                if (isset($a['cost']) && in_array($method, ['flat_rate', 'local_pickup'], true)) {
                    $settings['cost'] = (string) $a['cost'];
                }
                update_option($opt_key, $settings);
                $zone->save();
                return ['success' => true, 'zone_id' => $zone->get_id(), 'instance_id' => $instance_id, 'method' => $method];

            case 'list_classes':
                $classes = WC()->shipping()->get_shipping_classes();
                return ['success' => true, 'classes' => array_map(fn($c) => ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug], $classes)];

            case 'create_class':
                $res = wp_insert_term((string) ($a['class_name'] ?? ''), 'product_shipping_class');
                return is_wp_error($res) ? ['success' => false, 'error' => $res->get_error_message()] : ['success' => true, 'term_id' => (int) $res['term_id']];

            case 'list_zones':
            default:
                $zones = WC_Shipping_Zones::get_zones();
                $out = [];
                foreach ($zones as $z) {
                    $out[] = ['id' => $z['id'], 'name' => $z['zone_name'], 'regions' => $z['formatted_zone_location'] ?? '', 'methods' => array_map(fn($m) => ['id' => $m->id, 'title' => $m->get_title()], $z['shipping_methods'])];
                }
                // Include the "rest of the world" zone 0.
                $rest = new WC_Shipping_Zone(0);
                $out[] = ['id' => 0, 'name' => $rest->get_zone_name(), 'methods' => array_map(fn($m) => ['id' => $m->id, 'title' => $m->get_title()], $rest->get_shipping_methods())];
                return ['success' => true, 'zones' => $out];
        }
    }

    // ---- tax --------------------------------------------------------------

    public static function wc_tax(array $a): array
    {
        global $wpdb;
        $action = $a['action'] ?? 'list_rates';

        if ($action === 'enable' || $action === 'disable') {
            update_option('woocommerce_calc_taxes', $action === 'enable' ? 'yes' : 'no');
            return ['success' => true, 'taxes_enabled' => $action === 'enable'];
        }

        if ($action === 'add_rate') {
            $rate = [
                'tax_rate_country'  => strtoupper((string) ($a['country'] ?? '')),
                'tax_rate_state'    => strtoupper((string) ($a['state'] ?? '')),
                'tax_rate'          => (string) ($a['rate'] ?? '0'),
                'tax_rate_name'     => (string) ($a['name'] ?? 'Tax'),
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => 1,
                'tax_rate_class'    => '',
            ];
            $id = WC_Tax::_insert_tax_rate($rate);
            return ['success' => true, 'rate_id' => $id];
        }

        if ($action === 'delete_rate') {
            WC_Tax::_delete_tax_rate((int) ($a['rate_id'] ?? 0));
            return ['success' => true, 'deleted' => (int) ($a['rate_id'] ?? 0)];
        }

        // list_rates
        $rows = $wpdb->get_results("SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate, tax_rate_name FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_id", ARRAY_A);
        return ['success' => true, 'enabled' => get_option('woocommerce_calc_taxes') === 'yes', 'rates' => $rows ?: []];
    }
}
