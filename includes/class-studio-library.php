<?php
/**
 * Studio tab 4 — Library: the snippets that ship with the plugin.
 *
 * There used to be a second sub-tab here, a private library held on our server
 * and synced per site. That server is gone, and rather than leave a tab with
 * nothing behind it, the whole sub-tab navigation went with it. Snippets that
 * live on this site are already listed on the Studio's own list screen, so a
 * "your snippets" library would only have duplicated it.
 *
 * Everything taken from the library lands SWITCHED OFF. A one-click "add and
 * run" would be a nicer demo and a worse product: code arriving from elsewhere
 * is exactly the code somebody should read before it executes.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Studio_Library
{
    public static function render(): void
    {
        self::bundled_library();
    }

    // ---- the bundled library ---------------------------------------------

    private static function bundled_library(): void
    {
        $items = self::bundled_items();
        if (!$items) {
            echo '<p>' . esc_html__('The bundled library is empty in this build.', 'claudpress') . '</p>';
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
                    <span style="color:#00794b;"><?php esc_html_e('Already added', 'claudpress'); ?></span>
                <?php else : ?>
                    <?php self::action_button('use_library', __('Add to this site', 'claudpress'), ['library_key' => $key]); ?>
                <?php endif; ?>
            </td>
        </tr>
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
            <?php wp_nonce_field(Claudpress_Studio::NONCE); ?>
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
            $category = (string) ($item['category'] ?? __('Ungrouped', 'claudpress'));
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
