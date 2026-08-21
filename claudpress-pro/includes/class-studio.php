<?php
/**
 * The Snippets Studio — one page, four tabs.
 *
 * The snippet ENGINE is free: an agent can write, lint, activate and run
 * snippets on any install. What Pro adds is the ability to see and manage them
 * by hand. That split is deliberate — a free user is never left with code
 * running on their site that they cannot find out about (the Activity page is
 * free and lists every snippet event), they simply have no editor for it.
 *
 * Tabs use WordPress's own nav-tab-wrapper rather than anything custom, so the
 * screen looks like the rest of wp-admin instead of like a plugin trying to be
 * an app. That is the same pattern WooCommerce → Settings uses.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Pro_Studio
{
    const SLUG  = 'claudpress-studio';
    const NONCE = 'claudpress_studio';

    /** Tab slug => label. Order is the order they render in. */
    public static function tabs(): array
    {
        return [
            'all'     => __('All Snippets', 'claudpress-pro'),
            'edit'    => __('Add Snippet', 'claudpress-pro'),
            'compose' => __('AI Compose', 'claudpress-pro'),
            'library' => __('Library', 'claudpress-pro'),
        ];
    }

    /**
     * Which tab to show.
     *
     * Anything unrecognised falls back to the list rather than erroring: a stale
     * bookmark or a hand-edited URL should land somewhere useful, not on a blank
     * screen.
     */
    public static function current_tab(): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'all';

        // Editing an existing snippet arrives with an id but no tab; send it to
        // the editor rather than the list it was clicked from.
        if ($tab === 'all' && !empty($_GET['snippet'])) {
            $tab = 'edit';
        }
        return array_key_exists($tab, self::tabs()) ? $tab : 'all';
    }

    public static function url(string $tab = 'all', array $extra = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::SLUG, 'tab' => $tab], $extra),
            admin_url('admin.php')
        );
    }

    public function menu(): void
    {
        add_submenu_page(
            'claudpress',
            __('Snippets', 'claudpress-pro'),
            __('Snippets', 'claudpress-pro'),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
            2
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage snippets.', 'claudpress-pro'));
        }

        $current = self::current_tab();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Snippets Studio', 'claudpress-pro'); ?></h1>
            <?php if ($current !== 'edit') : ?>
                <a href="<?php echo esc_url(self::url('edit')); ?>" class="page-title-action"><?php esc_html_e('Add Snippet', 'claudpress-pro'); ?></a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php self::notices(); ?>

            <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e('Snippets Studio tabs', 'claudpress-pro'); ?>">
                <?php foreach (self::tabs() as $slug => $label) : ?>
                    <a href="<?php echo esc_url(self::url($slug)); ?>"
                       class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            switch ($current) {
                case 'edit':
                    Claudpress_Pro_Studio_Edit::render();
                    break;
                case 'compose':
                    Claudpress_Pro_Studio_Compose::render();
                    break;
                case 'library':
                    Claudpress_Pro_Studio_Library::render();
                    break;
                default:
                    Claudpress_Pro_Studio_List::render();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Flash messages, driven by a query arg rather than a transient.
     *
     * A transient would leak across tabs and browser windows — save in one tab,
     * see the notice appear in another. The query arg belongs to the redirect
     * that caused it, which is what people actually expect.
     */
    private static function notices(): void
    {
        $map = [
            'saved'      => ['success', __('Snippet saved.', 'claudpress-pro')],
            'activated'  => ['success', __('Snippet saved and switched on.', 'claudpress-pro')],
            'deactivated' => ['info', __('Snippet switched off.', 'claudpress-pro')],
            'deleted'    => ['success', __('Snippet deleted.', 'claudpress-pro')],
            'imported'   => ['success', __('Added to this site, switched off. Review it, then activate.', 'claudpress-pro')],
            'lint'       => ['error', __('That code does not parse, so it was saved switched OFF. The error is shown on the snippet.', 'claudpress-pro')],
            'cloud_error' => ['error', __('That did not work. Your snippets on this site are unaffected.', 'claudpress-pro')],
        ];
        $key = isset($_GET['cp_msg']) ? sanitize_key(wp_unslash($_GET['cp_msg'])) : '';
        if ($key === '' || !isset($map[$key])) {
            return;
        }
        [$type, $text] = $map[$key];
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($text)
        );
    }

    /** Where to send the browser after a write, with a message to show. */
    public static function redirect(string $tab, string $msg, array $extra = []): void
    {
        wp_safe_redirect(self::url($tab, array_merge($extra, ['cp_msg' => $msg])));
        exit;
    }

    /**
     * The source badge shown against every snippet.
     *
     * The AI one is not decoration. EU AI transparency rules have applied since
     * August 2026, and "which of these did a model write" is a question an owner
     * will eventually be asked. Recording it costs nothing; reconstructing it
     * later is impossible.
     */
    public static function source_badge(string $source): string
    {
        $labels = [
            'ai'      => ['#8c5e00', '#fff3d6', __('AI-generated', 'claudpress-pro')],
            'library' => ['#1d4ed8', '#e6edff', __('Library', 'claudpress-pro')],
            'cloud'   => ['#065f46', '#d9f5ec', __('Private library', 'claudpress-pro')],
            'manual'  => ['#4b5563', '#f0f0f1', __('Hand-written', 'claudpress-pro')],
        ];
        [$fg, $bg, $label] = $labels[$source] ?? $labels['manual'];

        return sprintf(
            '<span style="display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;color:%s;background:%s;">%s</span>',
            esc_attr($fg),
            esc_attr($bg),
            esc_html($label)
        );
    }
}
