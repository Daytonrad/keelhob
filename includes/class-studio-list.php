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

final class Claudpress_Studio_List
{
    const PER_PAGE = 20;

    public static function render(): void
    {
        $filters = self::filters();

        // Claudpress_Snippets::query() names the column, not the query arg. Passing
        // 'type' would silently return everything, which looks like a working
        // filter right up until someone relies on it.
        //
        // 'error' is NOT a value that column understands - a snippet that
        // crashed is stored as inactive with last_error set - so it is filtered
        // here instead of being pushed into a query that would answer the wrong
        // question.
        $rows = Claudpress_Snippets::query([
            'search'       => $filters['search'],
            'tag'          => $filters['tag'],
            'snippet_type' => $filters['type'],
            'active'       => $filters['active'] === 'error' ? '' : $filters['active'],
        ]);
        if ($filters['active'] === 'error') {
            $rows = array_values(array_filter($rows, static fn($row) => trim((string) ($row['last_error'] ?? '')) !== ''));
        }

        $counts = self::counts($rows);
        $rows   = self::sort($rows, $filters['orderby'], $filters['order']);

        $page  = max(1, (int) ($_GET['paged'] ?? 1));
        $total = count($rows);
        $rows  = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        // Priority is shown only once it means something. A column of identical
        // 10s teaches nobody anything and costs a column of width on the one
        // screen that has none to spare.
        $show_priority = false;
        foreach (Claudpress_Snippets::query([]) as $row) {
            if ((int) ($row['priority'] ?? 10) !== 10) {
                $show_priority = true;
                break;
            }
        }

        self::styles();
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
                    <?php self::column_header('name', __('Name', 'claudpress'), $filters, '26%'); ?>
                    <th scope="col" style="width:7%;"><?php esc_html_e('Type', 'claudpress'); ?></th>
                    <th scope="col" style="width:13%;"><?php esc_html_e('Runs where', 'claudpress'); ?></th>
                    <th scope="col" style="width:12%;"><?php esc_html_e('Source', 'claudpress'); ?></th>
                    <th scope="col" style="width:13%;"><?php esc_html_e('Tags', 'claudpress'); ?></th>
                    <?php if ($show_priority) : ?>
                        <?php self::column_header('priority', __('Priority', 'claudpress'), $filters, '8%'); ?>
                    <?php endif; ?>
                    <th scope="col" style="width:12%;"><?php esc_html_e('Status', 'claudpress'); ?></th>
                    <?php self::column_header('updated', __('Updated', 'claudpress'), $filters, '9%'); ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <?php self::row($row, $show_priority); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php

        self::pagination($total, $page, $filters);
    }

    /**
     * A sortable column heading, in WordPress's own markup.
     *
     * The sorting itself has worked since this screen was written - sort()
     * handles name, priority and updated, and filters() reads ?orderby and
     * ?order. There was simply no way to reach any of it: the table shipped
     * with zero sortable headers, so the whole feature was unreachable from the
     * screen it belonged to.
     */
    private static function column_header(string $key, string $label, array $filters, string $width): void
    {
        $current = $filters['orderby'] === $key;
        $order   = $current && $filters['order'] === 'asc' ? 'desc' : 'asc';
        $classes = 'manage-column sortable ' . ($current ? 'sorted ' . esc_attr($filters['order']) : 'desc');

        $url = Claudpress_Studio::url('all', array_filter([
            'orderby' => $key,
            'order'   => $order,
            's'       => $filters['search'],
            'tag'     => $filters['tag'],
            'type'    => $filters['type'],
            'active'  => $filters['active'],
        ], static fn($v) => $v !== ''));

        printf(
            '<th scope="col" class="%s" style="width:%s;"><a href="%s"><span>%s</span><span class="sorting-indicator"></span></a></th>',
            esc_attr($classes),
            esc_attr($width),
            esc_url($url),
            esc_html($label)
        );
    }

        /** @return array{search:string,tag:string,type:string,active:string,orderby:string,order:string} */
    private static function filters(): array
    {
        return [
            'search'  => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'tag'     => isset($_GET['tag']) ? sanitize_text_field(wp_unslash($_GET['tag'])) : '',
            'type'    => isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '',
            // 'error' is a real, non-numeric value here. Casting it to int the
            // way the on/off values are cast would turn it into 0 - "inactive" -
            // which is a filter that quietly returns the wrong rows rather than
            // failing.
            'active'  => self::active_filter(),
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
        $counts = ['' => count($all), 'error' => 0];
        foreach ($all as $row) {
            $type = (string) ($row['snippet_type'] ?? 'php');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            if (trim((string) ($row['last_error'] ?? '')) !== '') {
                $counts['error']++;
            }
        }
        return $counts;
    }

    /**
     * The status filter, which has one non-numeric value.
     *
     * 'error' means "switched itself off after crashing", and until now there
     * was no way to ask for it. A snippet somebody deliberately turned off and
     * a snippet that fell over looked identical on this screen - at exactly the
     * moment somebody most needs to tell them apart.
     */
    private static function active_filter(): string
    {
        if (!isset($_GET['active']) || $_GET['active'] === '') {
            return '';
        }
        $raw = sanitize_key(wp_unslash($_GET['active']));
        return $raw === 'error' ? 'error' : (string) (int) $raw;
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
            ''     => __('All', 'claudpress'),
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
            $url = Claudpress_Studio::url('all', array_filter([
                'type'   => $slug,
                's'      => $filters['search'],
                'tag'    => $filters['tag'],
                'active' => $filters['active'],
            ], static fn($v) => $v !== ''));

            printf(
                '%s<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a></li>',
                $i++ ? ' | ' : '',
                esc_url($url),
                $filters['type'] === $slug && $filters['active'] !== 'error' ? 'current' : '',
                esc_html($label),
                $count
            );
        }

        // Errored sits with the type tabs rather than in the status dropdown,
        // because it is the one people are hunting for when something has gone
        // wrong and a dropdown is a worse place to hunt. Same rule as the types:
        // nothing has errored, so no tab.
        if ((int) ($counts['error'] ?? 0) > 0) {
            printf(
                '%s<li><a href="%s" class="%s" style="color:#d63638;">%s <span class="count">(%d)</span></a></li>',
                $i++ ? ' | ' : '',
                esc_url(Claudpress_Studio::url('all', ['active' => 'error'])),
                $filters['active'] === 'error' ? 'current' : '',
                esc_html__('Errored', 'claudpress'),
                (int) $counts['error']
            );
        }

        echo '</ul>';
    }

    private static function search_form(array $filters): void
    {
        ?>
        <form method="get" style="float:right;margin:0 0 8px;">
            <input type="hidden" name="page" value="<?php echo esc_attr(Claudpress_Studio::SLUG); ?>">
            <input type="hidden" name="tab" value="all">
            <?php if ($filters['type'] !== '') : ?>
                <input type="hidden" name="type" value="<?php echo esc_attr($filters['type']); ?>">
            <?php endif; ?>
            <label class="screen-reader-text" for="cp-snippet-search"><?php esc_html_e('Search snippets', 'claudpress'); ?></label>
            <input type="search" id="cp-snippet-search" name="s" value="<?php echo esc_attr($filters['search']); ?>"
                   placeholder="<?php esc_attr_e('Search name or code', 'claudpress'); ?>">
            <select name="active">
                <option value=""><?php esc_html_e('Any status', 'claudpress'); ?></option>
                <option value="1" <?php selected($filters['active'], '1'); ?>><?php esc_html_e('On', 'claudpress'); ?></option>
                <option value="0" <?php selected($filters['active'], '0'); ?>><?php esc_html_e('Off', 'claudpress'); ?></option>
            </select>
            <?php submit_button(__('Filter', 'claudpress'), '', '', false); ?>
        </form>
        <div style="clear:both;"></div>
        <?php
    }

    private static function row(array $row, bool $show_priority = false): void
    {
        $id     = (int) $row['id'];
        $active = !empty($row['active']);
        $error  = trim((string) ($row['last_error'] ?? ''));
        $edit   = Claudpress_Studio::url('edit', ['snippet' => $id]);
        ?>
        <tr>
            <td>
                <strong><a href="<?php echo esc_url($edit); ?>"><?php echo esc_html($row['name'] !== '' ? $row['name'] : __('(untitled)', 'claudpress')); ?></a></strong>
                <?php if ($error !== '') : ?>
                    <br><span style="color:#d63638;font-size:12px;">&#9888; <?php echo esc_html(mb_substr($error, 0, 140)); ?></span>
                <?php elseif (trim((string) ($row['description'] ?? '')) !== '') : ?>
                    <br><span style="color:#666;font-size:12px;"><?php echo esc_html(wp_trim_words((string) $row['description'], 18)); ?></span>
                <?php endif; ?>
                <div class="row-actions">
                    <span><a href="<?php echo esc_url($edit); ?>"><?php esc_html_e('Edit', 'claudpress'); ?></a> | </span>
                    <span><?php self::action_button($id, 'duplicate', __('Duplicate', 'claudpress')); ?> | </span>
                    <span class="trash"><?php self::action_button($id, 'delete', __('Delete', 'claudpress'), __('Delete this snippet? This cannot be undone.', 'claudpress')); ?></span>
                </div>
                <?php
                /*
                 * SWITCH ON / SWITCH OFF USED TO LIVE IN .row-actions, with Edit
                 * and Delete, and that was wrong.
                 *
                 * WordPress positions .row-actions at left:-129987px until the
                 * row is hovered. Measured, not guessed. That is the correct
                 * convention for Edit and Delete - occasional actions, and
                 * hiding them keeps the table readable - but it made the most
                 * used control on the screen invisible to anyone who had not
                 * already discovered that hovering a table row reveals things.
                 *
                 * It is in the Status column now, visible at rest. See below.
                 */
                ?>
            </td>
            <td><code><?php echo esc_html((string) $row['snippet_type']); ?></code></td>
            <td><?php echo esc_html(str_replace('_', ' ', (string) $row['location'])); ?></td>
            <td><?php echo wp_kses_post(Claudpress_Studio::source_badge((string) ($row['source'] ?? 'manual'))); ?></td>
            <td>
                <?php
                $tags = Claudpress_Snippets::tags_array($row['tags'] ?? '');
                echo $tags ? esc_html(implode(', ', $tags)) : '<span style="color:#999;">&mdash;</span>';
                ?>
            </td>
            <?php if ($show_priority) : ?>
                <td><?php echo esc_html((string) ($row['priority'] ?? 10)); ?></td>
            <?php endif; ?>
            <td class="cp-status-toggle">
                <?php self::toggle($id, $active, $error); ?>
            </td>
            <td><?php echo esc_html(self::ago((string) ($row['updated_at'] ?? ''))); ?></td>
        </tr>
        <?php
    }

    /**
     * The on/off control, visible without hovering anything.
     *
     * Still a POST in a form with a nonce - see action_button() below for why
     * that is not negotiable. What changed is only where it sits and what it
     * looks like, not what it is.
     *
     * An errored snippet gets "Switch on" like any other. Refusing would be
     * paternalistic: the parse check in save() and the runtime crash guard
     * already decide what may actually run, and the person may well have just
     * fixed the code.
     */
    private static function toggle(int $id, bool $active, string $error): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cp-toggle-form">
            <?php wp_nonce_field(Claudpress_Studio::NONCE); ?>
            <input type="hidden" name="action" value="claudpress_studio">
            <input type="hidden" name="studio_action" value="<?php echo $active ? 'deactivate' : 'activate'; ?>">
            <input type="hidden" name="snippet_id" value="<?php echo esc_attr((string) $id); ?>">
            <button type="submit" data-cp-toggle="<?php echo esc_attr((string) $id); ?>"
                    class="cp-switch <?php echo $active ? 'cp-switch-on' : 'cp-switch-off'; ?>"
                    aria-pressed="<?php echo $active ? 'true' : 'false'; ?>">
                <span class="cp-switch-track" aria-hidden="true"><span class="cp-switch-knob"></span></span>
                <span class="cp-switch-label"><?php
                    if ($error !== '') {
                        esc_html_e('Errored', 'claudpress');
                    } else {
                        echo $active ? esc_html__('On', 'claudpress') : esc_html__('Off', 'claudpress');
                    }
                ?></span>
            </button>
        </form>
        <?php
    }

    /** The handful of rules the switch needs. Inline: one screen, under 30 lines. */
    private static function styles(): void
    {
        wp_register_style('claudpress-studio-list', false, [], CLAUDPRESS_VERSION);
        wp_enqueue_style('claudpress-studio-list');
        wp_add_inline_style('claudpress-studio-list', '
            .cp-toggle-form { margin: 0; }
            .cp-switch { display: inline-flex; align-items: center; gap: 7px; background: none; border: 0; padding: 2px 0; cursor: pointer; font-size: 13px; }
            .cp-switch-track { width: 30px; height: 16px; border-radius: 8px; background: #c3c4c7; position: relative; transition: background .12s ease; flex: none; }
            .cp-switch-knob { position: absolute; top: 2px; left: 2px; width: 12px; height: 12px; border-radius: 50%; background: #fff; transition: left .12s ease; }
            .cp-switch-on .cp-switch-track { background: #00794b; }
            .cp-switch-on .cp-switch-knob { left: 16px; }
            .cp-switch-label { color: #50575e; }
            .cp-switch-on .cp-switch-label { color: #00794b; font-weight: 600; }
            .cp-switch:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; border-radius: 4px; }
            .cp-switch:hover .cp-switch-track { background: #8c8f94; }
            .cp-switch-on:hover .cp-switch-track { background: #00603b; }
        ');
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
            <?php wp_nonce_field(Claudpress_Studio::NONCE); ?>
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
                <p><?php esc_html_e('No snippets match that filter.', 'claudpress'); ?>
                   <a href="<?php echo esc_url(Claudpress_Studio::url('all')); ?>"><?php esc_html_e('Clear filters', 'claudpress'); ?></a></p>
            <?php else : ?>
                <p><strong><?php esc_html_e('No snippets yet.', 'claudpress'); ?></strong></p>
                <p><?php esc_html_e('Snippets are small pieces of PHP, CSS, JS or HTML that change how this site works or looks, without editing a theme.', 'claudpress'); ?></p>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url(Claudpress_Studio::url('compose')); ?>"><?php esc_html_e('Describe one to the AI', 'claudpress'); ?></a>
                    <a class="button" href="<?php echo esc_url(Claudpress_Studio::url('edit')); ?>"><?php esc_html_e('Write one yourself', 'claudpress'); ?></a>
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
        return sprintf(__('%s ago', 'claudpress'), human_time_diff($ts, current_time('timestamp')));
    }
}
