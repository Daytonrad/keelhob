<?php
/**
 * Studio tab 4 — Library.
 *
 * Two sub-tabs. The bundled library ships with the plugin and is the same on
 * every site. The private library is the owner's own locker on our server, and
 * is the thing that makes a second site worth paying for.
 *
 * Everything taken from either library lands SWITCHED OFF. A one-click "add and
 * run" would be a nicer demo and a worse product: code arriving from elsewhere
 * is exactly the code someone should read before it executes.
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

    private static function bundled_library(): void
    {
        $items = self::bundled_items();
        if (!$items) {
            echo '<p>' . esc_html__('The bundled library is empty in this build.', 'claudpress-pro') . '</p>';
            return;
        }

        // Grouped by category, the same way the private library groups by tag,
        // so the two sub-tabs behave identically. Two libraries that organise
        // themselves differently make the second one feel like a different
        // product.
        $installed = self::installed_uids();

        foreach (self::group($items) as $category => $group) {
            echo '<h3>' . esc_html($category) . '</h3>';
            echo '<table class="wp-list-table widefat striped" style="margin-bottom:18px;"><tbody>';
            foreach ($group as $key => $item) {
                self::item_row($key, $item, isset($installed[$key]));
            }
            echo '</tbody></table>';
        }
    }

    private static function item_row(string $key, array $item, bool $already): void
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
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(Claudpress_Pro_Studio::NONCE); ?>
                        <input type="hidden" name="action" value="claudpress_studio">
                        <input type="hidden" name="studio_action" value="use_library">
                        <input type="hidden" name="library_key" value="<?php echo esc_attr($key); ?>">
                        <button class="button button-small"><?php esc_html_e('Add to this site', 'claudpress-pro'); ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * The private library.
     *
     * Not built yet — the backend it talks to does not exist. This says so
     * plainly rather than showing a dead button, because a control that looks
     * live and does nothing costs more trust than an honest "not yet".
     */
    private static function private_library(): void
    {
        ?>
        <div class="card" style="max-width:none;padding:18px 22px;">
            <h3 style="margin-top:0;"><?php esc_html_e('Your private library', 'claudpress-pro'); ?></h3>
            <p><?php esc_html_e('A locker for your own snippets, kept on our server and shared across every site you link. Build something once on one site, use it on the next.', 'claudpress-pro'); ?></p>
            <p><strong><?php esc_html_e('Not connected yet.', 'claudpress-pro'); ?></strong>
               <?php esc_html_e('This site has not been linked to an account.', 'claudpress-pro'); ?></p>
            <p style="color:#666;">
                <?php esc_html_e('Two rules it will follow, worth knowing before you rely on it: nothing in your library can ever be deleted from inside WordPress — only from your account page — and pulling never switches off or removes a snippet that is running here.', 'claudpress-pro'); ?>
            </p>
        </div>
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
