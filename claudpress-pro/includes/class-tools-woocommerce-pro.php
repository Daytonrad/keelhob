<?php
/**
 * Woocommerce tools that belong to Claudpress Pro.
 *
 * Carved out of the free plugin rather than gated inside it. A licence check
 * living in a file the user already has is a five-minute edit away from being
 * deleted, so the code is simply not in the free zip.
 *
 * Tool names and shapes are unchanged, so an agent that worked against a Pro
 * site before the split sees no difference.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Tools_Woocommerce_Pro
{
    public static function register(Claudpress_Tool_Registry $r): void
    {
        // The free class carried this guard and the carve left it behind, so
        // for a while wc_order and wc_report registered on sites with no
        // WooCommerce at all - tools an agent could see, call, and get an error
        // from, for a store that does not exist. Caught by running the add-on
        // against a real WordPress without WooCommerce installed.
        if (!class_exists('WooCommerce')) {
            return;
        }

        $r->add('wc_order', 'Manage WooCommerce orders. Actions: list, get, update_status, add_note, refund, create. Filter list by status, customer, and date. update_status moves an order (e.g. processing → completed).', [
            'type' => 'object',
            'properties' => [
                'action'      => ['type' => 'string', 'enum' => ['list', 'get', 'update_status', 'add_note', 'refund', 'create'], 'default' => 'list'],
                'id'          => ['type' => 'integer'],
                'status'      => ['type' => 'string', 'description' => 'For list: filter. For update_status: new status (pending, processing, on-hold, completed, cancelled, refunded, failed).'],
                'note'        => ['type' => 'string'],
                'note_to_customer' => ['type' => 'boolean', 'default' => false],
                'amount'      => ['type' => 'string', 'description' => 'Refund amount.'],
                'customer_id' => ['type' => 'integer'],
                'line_items'  => ['type' => 'array', 'description' => 'For create: [{product_id, quantity}].'],
                'per_page'    => ['type' => 'integer', 'default' => 20],
                'page'        => ['type' => 'integer', 'default' => 1],
            ],
            'required' => ['action'],
        ], [self::class, 'wc_order'], ['destructiveHint' => true]);

        $r->add('wc_report', 'WooCommerce sales analytics. Returns revenue, order counts, average order value, and top sellers for a date range (default last 30 days). Use this to understand how the shop is performing.', [
            'type' => 'object',
            'properties' => [
                'date_from' => ['type' => 'string', 'description' => 'Y-m-d (default 30 days ago).'],
                'date_to'   => ['type' => 'string', 'description' => 'Y-m-d (default today).'],
                'top_limit' => ['type' => 'integer', 'default' => 10],
            ],
        ], [self::class, 'wc_report'], ['readOnlyHint' => true]);
    }

    public static function wc_order(array $args): array
    {
        $action = $args['action'] ?? 'list';

        switch ($action) {
            case 'get':
                $o = wc_get_order((int) ($args['id'] ?? 0));
                return $o ? ['success' => true, 'order' => self::serialize_order($o)] : ['success' => false, 'error' => 'Order not found.'];

            case 'update_status':
                $o = wc_get_order((int) ($args['id'] ?? 0));
                if (!$o) {
                    return ['success' => false, 'error' => 'Order not found.'];
                }
                $o->update_status(sanitize_key((string) ($args['status'] ?? '')), 'Via Claudpress. ');
                return ['success' => true, 'id' => $o->get_id(), 'status' => $o->get_status()];

            case 'add_note':
                $o = wc_get_order((int) ($args['id'] ?? 0));
                if (!$o) {
                    return ['success' => false, 'error' => 'Order not found.'];
                }
                $o->add_order_note((string) ($args['note'] ?? ''), (bool) ($args['note_to_customer'] ?? false));
                return ['success' => true, 'id' => $o->get_id()];

            case 'refund':
                $o = wc_get_order((int) ($args['id'] ?? 0));
                if (!$o) {
                    return ['success' => false, 'error' => 'Order not found.'];
                }
                $refund = wc_create_refund([
                    'order_id' => $o->get_id(),
                    'amount'   => $args['amount'] ?? $o->get_total(),
                    'reason'   => $args['note'] ?? 'Refunded via Claudpress',
                ]);
                return is_wp_error($refund) ? ['success' => false, 'error' => $refund->get_error_message()] : ['success' => true, 'refund_id' => $refund->get_id()];

            case 'create':
                $o = wc_create_order(['customer_id' => (int) ($args['customer_id'] ?? 0)]);
                foreach ((array) ($args['line_items'] ?? []) as $li) {
                    $prod = wc_get_product((int) ($li['product_id'] ?? 0));
                    if ($prod) {
                        $o->add_product($prod, (int) ($li['quantity'] ?? 1));
                    }
                }
                $o->calculate_totals();
                if (!empty($args['status'])) {
                    $o->set_status(sanitize_key($args['status']));
                }
                $o->save();
                return ['success' => true, 'id' => $o->get_id(), 'total' => $o->get_total()];

            case 'list':
            default:
                $orders = wc_get_orders([
                    'limit'    => (int) ($args['per_page'] ?? 20),
                    'page'     => (int) ($args['page'] ?? 1),
                    'status'   => !empty($args['status']) ? sanitize_key($args['status']) : array_keys(wc_get_order_statuses()),
                    'customer' => $args['customer_id'] ?? null,
                    'orderby'  => 'date',
                    'order'    => 'DESC',
                ]);
                $items = [];
                foreach ($orders as $o) {
                    $items[] = ['id' => $o->get_id(), 'status' => $o->get_status(), 'total' => $o->get_total(), 'currency' => $o->get_currency(), 'customer' => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name(), 'email' => $o->get_billing_email(), 'date' => $o->get_date_created() ? $o->get_date_created()->date('Y-m-d H:i') : null];
                }
                return ['success' => true, 'orders' => $items];
        }
    }

    public static function wc_report(array $args): array
    {
        $to   = !empty($args['date_to']) ? $args['date_to'] : date('Y-m-d');
        $from = !empty($args['date_from']) ? $args['date_from'] : date('Y-m-d', strtotime('-30 days'));

        $orders = wc_get_orders([
            'limit'        => -1,
            'status'       => ['wc-completed', 'wc-processing', 'wc-on-hold'],
            'date_created' => strtotime($from) . '...' . (strtotime($to) + DAY_IN_SECONDS - 1),
            'return'       => 'objects',
        ]);

        $revenue = 0.0;
        $count   = 0;
        $sellers = [];
        foreach ($orders as $o) {
            $revenue += (float) $o->get_total();
            $count++;
            foreach ($o->get_items() as $item) {
                $pid = $item->get_product_id();
                if (!isset($sellers[$pid])) {
                    $sellers[$pid] = ['name' => $item->get_name(), 'qty' => 0, 'revenue' => 0.0];
                }
                $sellers[$pid]['qty']     += $item->get_quantity();
                $sellers[$pid]['revenue'] += (float) $item->get_total();
            }
        }
        usort($sellers, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        $sellers = array_slice(array_values($sellers), 0, (int) ($args['top_limit'] ?? 10));

        return [
            'success'   => true,
            'date_from' => $from,
            'date_to'   => $to,
            'currency'  => get_woocommerce_currency(),
            'orders'    => $count,
            'revenue'   => round($revenue, 2),
            'average_order_value' => $count ? round($revenue / $count, 2) : 0,
            'top_sellers' => $sellers,
        ];
    }

    private static function serialize_order(WC_Order $o): array
    {
        $items = [];
        foreach ($o->get_items() as $item) {
            $items[] = ['name' => $item->get_name(), 'quantity' => $item->get_quantity(), 'total' => $item->get_total(), 'product_id' => $item->get_product_id()];
        }
        return [
            'id'       => $o->get_id(),
            'status'   => $o->get_status(),
            'total'    => $o->get_total(),
            'currency' => $o->get_currency(),
            'payment_method' => $o->get_payment_method_title(),
            'customer' => ['name' => trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()), 'email' => $o->get_billing_email(), 'phone' => $o->get_billing_phone()],
            'shipping_address' => $o->get_formatted_shipping_address(),
            'items'    => $items,
            'date'     => $o->get_date_created() ? $o->get_date_created()->date('Y-m-d H:i') : null,
            'notes'    => wc_get_order_notes(['order_id' => $o->get_id(), 'limit' => 10]),
        ];
    }
}
