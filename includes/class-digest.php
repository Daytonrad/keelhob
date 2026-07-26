<?php
/**
 * Dashboard / digest engine. Builds a single status snapshot of the whole site
 * — store sales, orders, stock, content, comments, and available updates — so
 * the owner (or the agent) can answer "what's going on?" without logging in.
 * Powers the get_dashboard tool and scheduled email digests.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Digest
{
    /** @return array structured snapshot */
    public static function snapshot(): array
    {
        $data = [
            'generated_at' => current_time('mysql'),
            'site'         => ['name' => get_bloginfo('name'), 'url' => home_url()],
            'content'      => [
                'posts_published' => (int) wp_count_posts('post')->publish,
                'pages'           => (int) wp_count_posts('page')->publish,
                'drafts'          => (int) wp_count_posts('post')->draft,
                'pending_comments' => (int) wp_count_comments()->moderated,
            ],
            'updates'      => self::updates(),
        ];

        if (class_exists('WooCommerce')) {
            $data['store'] = self::store();
        }
        $data['summary'] = self::summarize($data);
        return $data;
    }

    private static function updates(): array
    {
        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = function_exists('get_plugin_updates') ? count(get_plugin_updates()) : 0;
        $themes  = function_exists('get_theme_updates') ? count(get_theme_updates()) : 0;
        return ['plugins' => $plugins, 'themes' => $themes];
    }

    private static function store(): array
    {
        $currency = get_woocommerce_currency();

        $revenue = static function (string $after) {
            $orders = wc_get_orders([
                'limit'        => -1,
                'status'       => ['wc-completed', 'wc-processing'],
                'date_created' => '>=' . strtotime($after),
                'return'       => 'objects',
            ]);
            $sum = 0.0;
            foreach ($orders as $o) {
                $sum += (float) $o->get_total();
            }
            return ['orders' => count($orders), 'revenue' => round($sum, 2)];
        };

        // Order status breakdown (open work).
        $statuses = [];
        foreach (['pending', 'processing', 'on-hold'] as $st) {
            $statuses[$st] = count(wc_get_orders(['limit' => -1, 'status' => 'wc-' . $st, 'return' => 'ids']));
        }

        // Low / out of stock.
        $low = wc_get_products(['limit' => 20, 'stock_status' => 'outofstock', 'return' => 'objects']);
        $low_list = [];
        foreach ($low as $p) {
            $low_list[] = ['id' => $p->get_id(), 'name' => $p->get_name(), 'stock' => $p->get_stock_quantity()];
        }

        return [
            'currency'      => $currency,
            'today'         => $revenue('today midnight'),
            'last_7_days'   => $revenue('-7 days'),
            'last_30_days'  => $revenue('-30 days'),
            'open_orders'   => $statuses,
            'needs_attention' => [
                'processing' => $statuses['processing'] ?? 0,
                'on_hold'    => $statuses['on-hold'] ?? 0,
            ],
            'out_of_stock'  => ['count' => count($low_list), 'items' => $low_list],
            'products'      => (int) wp_count_posts('product')->publish,
        ];
    }

    private static function summarize(array $d): array
    {
        $lines = [];
        if (isset($d['store'])) {
            $s = $d['store'];
            $cur = $s['currency'];
            $lines[] = sprintf('Today: %s %s from %d orders. Last 7 days: %s %s.', $cur, $s['today']['revenue'], $s['today']['orders'], $cur, $s['last_7_days']['revenue']);
            if (($s['open_orders']['processing'] ?? 0) > 0) {
                $lines[] = sprintf('%d order(s) awaiting fulfilment (processing).', $s['open_orders']['processing']);
            }
            if (($s['out_of_stock']['count'] ?? 0) > 0) {
                $lines[] = sprintf('%d product(s) out of stock.', $s['out_of_stock']['count']);
            }
        }
        if ($d['content']['pending_comments'] > 0) {
            $lines[] = sprintf('%d comment(s) awaiting moderation.', $d['content']['pending_comments']);
        }
        $upd = $d['updates']['plugins'] + $d['updates']['themes'];
        if ($upd > 0) {
            $lines[] = sprintf('%d update(s) available (%d plugins, %d themes).', $upd, $d['updates']['plugins'], $d['updates']['themes']);
        }
        if (empty($lines)) {
            $lines[] = 'All quiet — no orders needing attention, no stock issues, nothing pending.';
        }
        return $lines;
    }

    public static function format_text(array $d): string
    {
        $out = [];
        $out[] = $d['site']['name'] . ' — status ' . $d['generated_at'];
        $out[] = str_repeat('-', 40);
        foreach ($d['summary'] as $line) {
            $out[] = '• ' . $line;
        }
        if (isset($d['store'])) {
            $s = $d['store'];
            $out[] = '';
            $out[] = 'STORE (' . $s['currency'] . ')';
            $out[] = sprintf('  Today: %s (%d orders) | 7d: %s | 30d: %s', $s['today']['revenue'], $s['today']['orders'], $s['last_7_days']['revenue'], $s['last_30_days']['revenue']);
            $out[] = sprintf('  Open orders: %d pending, %d processing, %d on-hold', $s['open_orders']['pending'] ?? 0, $s['open_orders']['processing'] ?? 0, $s['open_orders']['on-hold'] ?? 0);
            if ($s['out_of_stock']['count'] > 0) {
                $names = array_map(fn($i) => $i['name'], array_slice($s['out_of_stock']['items'], 0, 5));
                $out[] = '  Out of stock: ' . implode(', ', $names);
            }
        }
        $out[] = '';
        $out[] = sprintf('Content: %d posts, %d pages, %d drafts, %d comments pending', $d['content']['posts_published'], $d['content']['pages'], $d['content']['drafts'], $d['content']['pending_comments']);
        $out[] = sprintf('Updates available: %d plugins, %d themes', $d['updates']['plugins'], $d['updates']['themes']);
        return implode("\n", $out);
    }
}
