<?php
/**
 * Studio tab 1 — All Snippets.
 *
 * Everything on the site, whoever wrote it: the agent over MCP, AI Compose, the
 * bundled library, the private library, or a person. Showing them in one list
 * with a source badge is the point — an owner should never have to wonder where
 * a piece of running code came from.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Pro_Studio_List
{
    const PER_PAGE = 20;

    public static function render(): void
    {
        $filters = self::filters();
        // Claudpress_Snippets::query() names the column, not the query arg. Passing
        // 'type' would silently return everything, which looks like a working
        // filter right up until someone relies on it.
        $rows = Claudpress_Snippets::query([
            'search'       => $filters['search'],
            'tag'          => $filters['tag'],
            'snippet_type' => $filters['type'],
            'active'       => $filters['active'],
        ]);
        $counts  = self::counts($rows);
        $rows    = self::sort($rows, $filters['orderby'], $filters['order']);

        $page  = max(1, (int) ($_GET['paged'] ?? 1));
        $total = count($rows);
        $rows  = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        self::type_tabs($filters, $counts);
        self::search_form($filters);

        if (!$rows) {
            self::empty_state($filters);
            return;
        }

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width:28%;"><?php esc_html_e('Name', 'claudpress-pro'); ?></th>
                    <th scope="col" style="width:8%;"><?php esc_html_e('Type', 'claudpress-pro'); ?></th>
                    <th scope="col" style="width:14%;"><?php esc_html_e('Runs where', 'claudpress-pro'); ?></th>
                    <th scope="col" style="width:14%;"><?php esc_html_e('Source', 'claudpress-pro'); ?></th>
                    <th scope="col" style="width:16%;"><?php esc_html_e('Tags', 'claudpress-pro'); ?></th>
                    <th scope="col" style="width:10%;"><?php esc_html_e('Status', 'claudpress-pro'); ?></th>
                    <th scope="col" style="width:10%;"><?php esc_html_e('Updated', 'claudpress-pro'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php self::row($row); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php

        self::pagination($total, $page, $filters);
    }

    /** @return array{search:string,tag:string,type:string,active:string,orderby:string,order:string} */
    private static function filters(): array
    {
        return [
            'search'  => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'tag'     => isset($_GET['tag']) ? sanitize_text_field(wp_unslash($_GET['tag'])) : '',
            'type'    => isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '',
            'active'  => isset($_GET['active']) && $_GET['active'] !== '' ? (string) (int) $_GET['active'] : '',
            'orderby' => isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'updated',
            'order'   => (isset($_GET['order']) && strtolower((string) $_GET['order']) === 'asc') ? 'asc' : 'desc',
        ];
    }

    /**
     * Counts are taken from an UNFILTERED query on purpose.
     *
     * A type tab showing "PHP (0)" because you happen to be filtered to
     * inactive CSS is worse than no count at all — it reads as "you have no PHP
     * snippets", which is a different and alarming claim.
     */
    private static function counts(array $_ignored): array
    {
        $all    = Claudpress_Snippets::query([]);
        $counts = ['' => count($all)];
        foreach ($all as $row) {
            $type = (string) ($row['snippet_type'] ?? 'php');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        return $counts;
    }

    private static function sort(array $rows, string $orderby, string $order): array
    {
        usort($rows, static function ($a, $b) use ($orderby) {
            switch ($orderby) {
                case 'name':
                    return strcasecmp((string) $a['name'], (string) $b['name']);
                case 'priority':
                    return (int) $a['priority'] <=> (int) $b['priority'];
                default:
                    return strcmp((string) ($a['updated_at'] ?? ''), (string) ($b['updated_at'] ?? ''));
            }
        });
        return $order === 'asc' ? $rows : array_reverse($rows);
    }

    private static function type_tabs(array $filters, array $counts): void
    {
        $types = [
            ''     => __('All', 'claudpress-pro'),
            'php'  => 'PHP',
            'css'  => 'CSS',
            'js'   => 'JS',
            'html' => 'HTML',
        ];
        echo '<ul class="subsubsub">';
        $i = 0;
        foreach ($types as $slug => $label) {
            $count = (int) ($counts[$slug] ?? 0);
            if ($slug !== '' && $count === 0) {
                continue; // Do not advertise a filter that can only return nothing.
            }
            $url = Claudpress_Pro_Studio::url('all', array_filter([
                'type'   => $slug,
                's'      => $filters['search'],
                'tag'    => $filters['tag'],
                'active' => $filters['active'],
            ], static fn($v) => $v !== ''));

            printf(
                '%s<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a></li>',
                $i++ ? ' | ' : '',
                esc_url($url),
                $filters['type'] === $slug ? 'current' : '',
                esc_html($label),
                $count
            );
        }
        echo '</ul>';
    }

    private static function search_form(array $filters): void
    {
        ?>
        <form method="get" style="float:right;margin:0 0 8px;">
            <input type="hidden" name="page" value="<?php echo esc_attr(Claudpress_Pro_Studio::SLUG); ?>">
            <input type="hidden" name="tab" value="all">
            <?php if ($filters['type'] !== '') : ?>
                <input type="hidden" name="type" value="<?php echo esc_attr($filters['type']); ?>">
            <?php endif; ?>
            <label class="screen-reader-text" for="cp-snippet-search"><?php esc_html_e('Search snippets', 'claudpress-pro'); ?></label>
            <input type="search" id="cp-snippet-search" name="s" value="<?php echo esc_attr($filters['search']); ?>"
                   placeholder="<?php esc_attr_e('Search name or code', 'claudpress-pro'); ?>">
            <select name="active">
                <option value=""><?php esc_html_e('Any status', 'claudpress-pro'); ?></option>
                <option value="1" <?php selected($filters['active'], '1'); ?>><?php esc_html_e('On', 'claudpress-pro'); ?></option>
                <option value="0" <?php selected($filters['active'], '0'); ?>><?php esc_html_e('Off', 'claudpress-pro'); ?></option>
            </select>
            <?php submit_button(__('Filter', 'claudpress-pro'), '', '', false); ?>
        </form>
        <div style="clear:both;"></div>
        <?php
    }

    private static function row(array $row): void
    {
        $id     = (int) $row['id'];
        $active = !empty($row['active']);
        $error  = trim((string) ($row['last_error'] ?? ''));
        $edit   = Claudpress_Pro_Studio::url('edit', ['snippet' => $id]);
        ?>
        <tr>
            <td>
                <strong><a href="<?php echo esc_url($edit); ?>"><?php echo esc_html($row['name'] !== '' ? $row['name'] : __('(untitled)', 'claudpress-pro')); ?></a></strong>
                <?php if ($error !== '') : ?>
                    <br><span style="color:#d63638;font-size:12px;">⚠ <?php echo esc_html(mb_substr($error, 0, 140)); ?></span>
                <?php elseif (trim((string) ($row['description'] ?? '')) !== '') : ?>
                    <br><span style="color:#666;font-size:12px;"><?php echo esc_html(wp_trim_words((string) $row['description'], 18)); ?></span>
                <?php endif; ?>
                <div class="row-actions">
                    <span><a href="<?php echo esc_url($edit); ?>"><?php esc_html_e('Edit', 'claudpress-pro'); ?></a> | </span>
                    <span><?php self::action_button($id, $active ? 'deactivate' : 'activate', $active ? __('Switch off', 'claudpress-pro') : __('Switch on', 'claudpress-pro')); ?> | </span>
                    <span><?php self::action_button($id, 'duplicate', __('Duplicate', 'claudpress-pro')); ?> | </span>
                    <span class="trash"><?php self::action_button($id, 'delete', __('Delete', 'claudpress-pro'), __('Delete this snippet? This cannot be undone.', 'claudpress-pro')); ?></span>
                </div>
            </td>
            <td><code><?php echo esc_html((string) $row['snippet_type']); ?></code></td>
            <td><?php echo esc_html(str_replace('_', ' ', (string) $row['location'])); ?></td>
            <td><?php echo wp_kses_post(Claudpress_Pro_Studio::source_badge((string) ($row['source'] ?? 'manual'))); ?></td>
            <td>
                <?php
                $tags = Claudpress_Snippets::tags_array($row['tags'] ?? '');
                echo $tags ? esc_html(implode(', ', $tags)) : '<span style="color:#999;">—</span>';
                ?>
            </td>
            <td>
                <?php if ($active) : ?>
                    <span style="color:#00794b;font-weight:600;"><?php esc_html_e('On', 'claudpress-pro'); ?></span>
                <?php else : ?>
                    <span style="color:#777;"><?php esc_html_e('Off', 'claudpress-pro'); ?></span>
                <?php endif; ?>
            </td>
            <td><?php echo esc_html(self::ago((string) ($row['updated_at'] ?? ''))); ?></td>
        </tr>
        <?php
    }

    /**
     * State changes are POSTs with a nonce, never links.
     *
     * A GET link that switches code on would be triggered by anything that
     * prefetches a page — a browser, a security scanner, an email client
     * following links in a notification. Activating a snippet is running code
     * on a live site, so it needs an actual decision behind it.
     */
    private static function action_button(int $id, string $action, string $label, string $confirm = ''): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;"
            <?php if ($confirm !== '') : ?>onsubmit="return confirm('<?php echo esc_js($confirm); ?>');"<?php endif; ?>>
            <?php wp_nonce_field(Claudpress_Pro_Studio::NONCE); ?>
            <input type="hidden" name="action" value="claudpress_studio">
            <input type="hidden" name="studio_action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="snippet_id" value="<?php echo esc_attr((string) $id); ?>">
            <button type="submit" class="button-link" style="color:<?php echo $action === 'delete' ? '#b32d2e' : '#2271b1'; ?>;"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private static function empty_state(array $filters): void
    {
        $filtered = $filters['search'] !== '' || $filters['tag'] !== '' || $filters['type'] !== '' || $filters['active'] !== '';
        ?>
        <div class="card" style="max-width:none;padding:20px;margin-top:12px;">
            <?php if ($filtered) : ?>
                <p><?php esc_html_e('No snippets match that filter.', 'claudpress-pro'); ?>
                   <a href="<?php echo esc_url(Claudpress_Pro_Studio::url('all')); ?>"><?php esc_html_e('Clear filters', 'claudpress-pro'); ?></a></p>
            <?php else : ?>
                <p><strong><?php esc_html_e('No snippets yet.', 'claudpress-pro'); ?></strong></p>
                <p><?php esc_html_e('Snippets are small pieces of PHP, CSS, JS or HTML that change how this site works or looks, without editing a theme.', 'claudpress-pro'); ?></p>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url(Claudpress_Pro_Studio::url('compose')); ?>"><?php esc_html_e('Describe one to the AI', 'claudpress-pro'); ?></a>
                    <a class="button" href="<?php echo esc_url(Claudpress_Pro_Studio::url('edit')); ?>"><?php esc_html_e('Write one yourself', 'claudpress-pro'); ?></a>
                    <a class="button" href="<?php echo esc_url(Claudpress_Pro_Studio::url('library')); ?>"><?php esc_html_e('Browse the library', 'claudpress-pro'); ?></a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function pagination(int $total, int $page, array $filters): void
    {
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages < 2) {
            return;
        }
        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        echo wp_kses_post(paginate_links([
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'current'   => $page,
            'total'     => $pages,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ]));
        echo '</div></div>';
    }

    private static function ago(string $mysql_date): string
    {
        $ts = strtotime($mysql_date);
        if (!$ts) {
            return '—';
        }
        /* translators: %s: human-readable time difference, e.g. "3 hours". */
        return sprintf(__('%s ago', 'claudpress-pro'), human_time_diff($ts, current_time('timestamp')));
    }
}
