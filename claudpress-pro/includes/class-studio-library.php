<?php
/**
 * Studio tab 4 — Library.
 *
 * Two sub-tabs. The bundled library ships with the plugin and is the same on
 * every site. The private library is the owner's own locker on our server, and
 * it is the thing that makes a second site worth paying for.
 *
 * Everything taken from either library lands SWITCHED OFF. A one-click "add and
 * run" would be a nicer demo and a worse product: code arriving from elsewhere
 * is exactly the code somebody should read before it executes.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Pro_Studio_Library
{
    public static function render(): void
    {
        $sub = isset($_GET['lib']) ? sanitize_key(wp_unslash($_GET['lib'])) : 'bundled';
        $sub = in_array($sub, ['bundled', 'private'], true) ? $sub : 'bundled';
        ?>
        <ul class="subsubsub" style="margin-bottom:12px;">
            <li><a href="<?php echo esc_url(Claudpress_Pro_Studio::url('library', ['lib' => 'bundled'])); ?>"
                   class="<?php echo $sub === 'bundled' ? 'current' : ''; ?>"><?php esc_html_e('Claudpress library', 'claudpress-pro'); ?></a> | </li>
            <li><a href="<?php echo esc_url(Claudpress_Pro_Studio::url('library', ['lib' => 'private'])); ?>"
                   class="<?php echo $sub === 'private' ? 'current' : ''; ?>"><?php esc_html_e('Private library', 'claudpress-pro'); ?></a></li>
        </ul>
        <div style="clear:both;"></div>
        <?php

        if ($sub === 'private') {
            self::private_library();
            return;
        }
        self::bundled_library();
    }

    // ---- the bundled library ---------------------------------------------

    private static function bundled_library(): void
    {
        $items = self::bundled_items();
        if (!$items) {
            echo '<p>' . esc_html__('The bundled library is empty in this build.', 'claudpress-pro') . '</p>';
            return;
        }

        $installed = self::installed_uids();

        foreach (self::group($items) as $category => $group) {
            echo '<h3>' . esc_html($category) . '</h3>';
            echo '<table class="wp-list-table widefat striped" style="margin-bottom:18px;"><tbody>';
            foreach ($group as $key => $item) {
                self::bundled_row($key, $item, isset($installed[$key]));
            }
            echo '</tbody></table>';
        }
    }

    private static function bundled_row(string $key, array $item, bool $already): void
    {
        ?>
        <tr>
            <td style="width:30%;">
                <strong><?php echo esc_html((string) ($item['title'] ?? $key)); ?></strong><br>
                <code style="font-size:11px;color:#777;"><?php echo esc_html((string) ($item['type'] ?? 'php')); ?></code>
            </td>
            <td><?php echo esc_html((string) ($item['description'] ?? '')); ?></td>
            <td style="width:140px;text-align:right;">
                <?php if ($already) : ?>
                    <span style="color:#00794b;"><?php esc_html_e('Already added', 'claudpress-pro'); ?></span>
                <?php else : ?>
                    <?php self::action_button('use_library', __('Add to this site', 'claudpress-pro'), ['library_key' => $key]); ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    // ---- the private library ---------------------------------------------

    /**
     * Two states: not linked, and linked. Both are honest about what they can
     * and cannot do, because the one thing people need to trust about a library
     * is that it will not lose their work.
     */
    private static function private_library(): void
    {
        if (!class_exists('Claudpress_Pro_Cloud') || !Claudpress_Pro_Cloud::is_linked()) {
            self::pairing_screen();
            return;
        }

        $result = Claudpress_Pro_Cloud_Library::pull();

        if (empty($result['ok'])) {
            ?>
            <div class="notice notice-warning">
                <p><?php echo esc_html((string) ($result['error'] ?? '')); ?></p>
                <p><?php esc_html_e('The snippets on this site are unaffected.', 'claudpress-pro'); ?></p>
            </div>
            <?php
            return;
        }

        $snippets = (array) $result['snippets'];
        $live     = array_values(array_filter($snippets, static fn($s) => empty($s['deleted'])));
        $orphaned = array_values(array_filter(
            $snippets,
            static fn($s) => !empty($s['deleted']) && !empty($s['still_active_here'])
        ));

        self::linked_header(count($live));

        // Above everything else: something was deleted from the library and is
        // STILL RUNNING here. The site has not been changed and will not be.
        // Whether to remove it is the owner's call, made with the facts visible.
        if ($orphaned) {
            ?>
            <div class="notice notice-info" style="margin:12px 0;">
                <p><strong><?php esc_html_e('Removed from your library, still running here', 'claudpress-pro'); ?></strong></p>
                <p><?php esc_html_e('These were deleted from your library elsewhere. They are still switched on here and have not been touched. Removing them from this site is up to you.', 'claudpress-pro'); ?></p>
                <ul style="margin-left:18px;list-style:disc;">
                    <?php foreach ($orphaned as $item) : ?>
                        <li><?php echo esc_html((string) $item['title']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
        }

        if (!$live) {
            echo '<p>' . esc_html__('Your library is empty. Push a snippet up from All Snippets to start it.', 'claudpress-pro') . '</p>';
            return;
        }

        // Grouped by tag, exactly as the bundled library groups by category, so
        // the two sub-tabs behave identically instead of feeling like two
        // different products.
        $grouped = [];
        foreach ($live as $item) {
            $tag = $item['tags'][0] ?? __('Ungrouped', 'claudpress-pro');
            $grouped[(string) $tag][] = $item;
        }
        ksort($grouped);

        foreach ($grouped as $tag => $items) {
            echo '<h3>' . esc_html((string) $tag) . '</h3>';
            echo '<table class="wp-list-table widefat striped" style="margin-bottom:18px;"><tbody>';
            foreach ($items as $item) {
                self::private_row($item);
            }
            echo '</tbody></table>';
        }
    }

    private static function private_row(array $item): void
    {
        $states = [
            'up-to-date'    => ['#00794b', __('In sync', 'claudpress-pro')],
            'local-changed' => ['#8c5e00', __('Changed on this site', 'claudpress-pro')],
            'cloud-changed' => ['#1d4ed8', __('Newer in your library', 'claudpress-pro')],
            'conflict'      => ['#d63638', __('Changed in both places', 'claudpress-pro')],
            'new-in-cloud'  => ['#4b5563', __('Not on this site yet', 'claudpress-pro')],
            'new-locally'   => ['#4b5563', __('Only on this site', 'claudpress-pro')],
        ];

        $state = (string) ($item['state'] ?? 'up-to-date');
        [$colour, $label] = $states[$state] ?? $states['up-to-date'];
        ?>
        <tr>
            <td style="width:32%;">
                <strong><?php echo esc_html((string) $item['title']); ?></strong>
                <?php if (!empty($item['description'])) : ?>
                    <br><span style="color:#666;font-size:12px;"><?php echo esc_html(wp_trim_words((string) $item['description'], 14)); ?></span>
                <?php endif; ?>
            </td>
            <td><span style="color:<?php echo esc_attr($colour); ?>;"><?php echo esc_html($label); ?></span></td>
            <td style="width:180px;text-align:right;">
                <?php if (empty($item['on_this_site'])) : ?>
                    <?php self::action_button('cloud_import', __('Use on this site', 'claudpress-pro'), ['cloud_uid' => (string) $item['uid']]); ?>
                <?php elseif ($state === 'conflict') : ?>
                    <span style="color:#d63638;font-size:12px;"><?php esc_html_e('Open it and decide', 'claudpress-pro'); ?></span>
                <?php else : ?>
                    <span style="color:#999;font-size:12px;"><?php esc_html_e('Already here', 'claudpress-pro'); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function linked_header(int $count): void
    {
        ?>
        <p style="color:#666;">
            <?php
            echo esc_html(sprintf(
                /* translators: %d: number of snippets. */
                _n('%d snippet in your library.', '%d snippets in your library.', $count, 'claudpress-pro'),
                $count
            ));
            ?>
            &nbsp;
            <a href="https://claudpress.com/account/library" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Manage it on claudpress.com', 'claudpress-pro'); ?>
            </a>
        </p>
        <p style="color:#666;font-size:12px;max-width:640px;">
            <?php esc_html_e('Snippets can only be deleted from your account page, never from WordPress. That way a mistake here, or a site that gets hacked, can never destroy your library.', 'claudpress-pro'); ?>
        </p>
        <?php
    }

    /**
     * The pairing screen.
     *
     * A code and a link, and no password field anywhere. Nobody should type
     * their claudpress.com password into a WordPress install — possibly one
     * they do not own, possibly one that is already compromised.
     */
    private static function pairing_screen(): void
    {
        $pending = class_exists('Claudpress_Pro_Cloud') ? Claudpress_Pro_Cloud::pending_code() : null;
        ?>
        <div class="card" style="max-width:none;padding:18px 22px;">
            <h3 style="margin-top:0;"><?php esc_html_e('Your private library', 'claudpress-pro'); ?></h3>
            <p style="max-width:640px;"><?php esc_html_e('A locker for your own snippets, kept on our server and shared across every site you link. Build something once, use it on the next site.', 'claudpress-pro'); ?></p>

            <?php if ($pending) : ?>
                <p><?php esc_html_e('Open the link below, sign in, and enter this code:', 'claudpress-pro'); ?></p>
                <p style="font-size:32px;font-family:monospace;letter-spacing:4px;margin:8px 0;">
                    <?php echo esc_html((string) ($pending['display'] ?? $pending['code'] ?? '')); ?>
                </p>
                <p>
                    <a class="button button-primary" target="_blank" rel="noopener noreferrer"
                       href="<?php echo esc_url((string) ($pending['approve_url'] ?? 'https://claudpress.com/link-site')); ?>">
                        <?php esc_html_e('Open claudpress.com to approve', 'claudpress-pro'); ?>
                    </a>
                    <?php self::action_button('cloud_cancel_pairing', __('Cancel', 'claudpress-pro')); ?>
                </p>
                <p style="color:#666;font-size:12px;">
                    <?php esc_html_e('The code lasts ten minutes and can be used once. This page checks for approval every few seconds.', 'claudpress-pro'); ?>
                </p>
                <?php
                // A plain reload rather than a fetch poll. The whole screen is
                // server-rendered, the wait is measured in seconds, and a
                // reload cannot get out of step with the page it is updating.
                ?>
                <script>setTimeout(function () { window.location.reload(); }, 5000);</script>
            <?php else : ?>
                <p><?php self::action_button('cloud_start_pairing', __('Link this site', 'claudpress-pro'), [], 'button-primary'); ?></p>
                <p style="color:#666;font-size:12px;max-width:640px;">
                    <?php esc_html_e('You will get a short code to enter on claudpress.com. Your password is never typed into WordPress.', 'claudpress-pro'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    // ---- shared ----------------------------------------------------------

    /**
     * Every action is a POST with a nonce, never a link.
     *
     * A GET that links a site or installs code would be fired by anything that
     * prefetches — a browser, a scanner, an email client following a link in a
     * notification.
     */
    private static function action_button(
        string $action,
        string $label,
        array $fields = [],
        string $class = 'button-small'
    ): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <?php wp_nonce_field(Claudpress_Pro_Studio::NONCE); ?>
            <input type="hidden" name="action" value="claudpress_studio">
            <input type="hidden" name="studio_action" value="<?php echo esc_attr($action); ?>">
            <?php foreach ($fields as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>">
            <?php endforeach; ?>
            <button class="button <?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    /** @return array<string, array> */
    private static function bundled_items(): array
    {
        if (class_exists('Claudpress_Snippet_Library') && method_exists('Claudpress_Snippet_Library', 'all')) {
            return (array) Claudpress_Snippet_Library::all();
        }
        return [];
    }

    private static function group(array $items): array
    {
        $out = [];
        foreach ($items as $key => $item) {
            $category = (string) ($item['category'] ?? __('Ungrouped', 'claudpress-pro'));
            $out[$category][$key] = $item;
        }
        ksort($out);
        return $out;
    }

    /** Library keys already on this site, so the button can say so. */
    private static function installed_uids(): array
    {
        $out = [];
        foreach (Claudpress_Snippets::query([]) as $row) {
            $origin = trim((string) ($row['origin_uid'] ?? ''));
            if ($origin !== '') {
                $out[$origin] = true;
            }
        }
        return $out;
    }
}
