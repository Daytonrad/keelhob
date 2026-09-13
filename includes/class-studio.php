<?php
/**
 * The Snippets Studio — one page, three tabs.
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

final class Claudpress_Studio
{
    const SLUG  = 'claudpress-studio';
    const NONCE = 'claudpress_studio';

    /** Tab slug => label. Order is the order they render in. */
    public static function tabs(): array
    {
        /*
         * THREE TABS. There was very nearly a fourth.
         *
         * A Library tab shipped here for a long time, shown only when
         * Claudpress_Studio_Library::has_items() said there was something to
         * show. It never said yes on any install, because has_items() asks
         * Claudpress_Snippet_Library - a class that was never written. So the
         * tab could not appear, and the 135-line screen behind it could not be
         * reached, and both shipped in every zip anyway.
         *
         * Hiding it was the right first move when it was found. Deleting it is
         * the right second one: a repo about to be read by strangers should not
         * contain a screen that cannot be opened.
         *
         * What was deliberately KEPT: `source = 'library'` in
         * Claudpress_Snippets::SOURCES and its badge below. That is stored
         * data, not UI. Sites that used the old private library still have rows
         * carrying it, and removing the badge would render their history wrong.
         *
         * `?tab=library` still lands on the list rather than erroring -
         * current_tab() falls back for anything it does not recognise - so an
         * old bookmark is not a dead end.
         */
        return [
            'all'     => __('All Snippets', 'claudpress'),
            'edit'    => __('Add Snippet', 'claudpress'),
            'compose' => __('AI Compose', 'claudpress'),
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
            __('Snippets', 'claudpress'),
            __('Snippets', 'claudpress'),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
            2
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage snippets.', 'claudpress'));
        }

        $current = self::current_tab();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo Claudpress_Brand::heading_mark(); ?><?php esc_html_e('Snippets Studio', 'claudpress'); ?></h1>
            <?php if ($current !== 'edit') : ?>
                <a href="<?php echo esc_url(self::url('edit')); ?>" class="page-title-action"><?php esc_html_e('Add Snippet', 'claudpress'); ?></a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php self::notices(); ?>

            <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e('Snippets Studio tabs', 'claudpress'); ?>">
                <?php foreach (self::tabs() as $slug => $label) : ?>
                    <a href="<?php echo esc_url(self::url($slug)); ?>"
                       class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            switch ($current) {
                case 'edit':
                    Claudpress_Studio_Edit::render();
                    break;
                case 'compose':
                    Claudpress_Studio_Compose::render();
                    break;
                default:
                    Claudpress_Studio_List::render();
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
            'saved'      => ['success', __('Snippet saved.', 'claudpress')],
            'activated'  => ['success', __('Snippet saved and switched on.', 'claudpress')],
            'deactivated' => ['info', __('Snippet switched off.', 'claudpress')],
            'deleted'    => ['success', __('Snippet deleted.', 'claudpress')],
            'lint'       => ['error', __('That code does not parse, so it was saved switched OFF. The error is shown on the snippet.', 'claudpress')],
            'moved'      => ['warning', __('Saved, but moved to a location that can run it. CSS, JS and HTML are only output in the header, the footer, before or after content, or from a shortcode — at "everywhere", "admin only", "front end only" or "page template" nothing would have printed them.', 'claudpress')],
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

    /**
     * Where to send the browser after a write, with a message to show.
     *
     * This never returns — it ends in exit. That matters at the call sites,
     * where a guard clause calls it instead of returning and the code below
     * is unreachable by design. A native never type would say so, but that is
     * PHP 8.1 and the floor here is 8.0, so it is said in a docblock instead.
     *
     * @phpstan-return never
     */
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
            'ai'      => ['#8c5e00', '#fff3d6', __('AI-generated', 'claudpress')],
            'library' => ['#1d4ed8', '#e6edff', __('Library', 'claudpress')],
            // Snippets pulled from the old private library on our server.
            // Nothing writes this any more, but rows carrying it still
            // exist and deserve a truthful badge rather than being shown
            // as hand-written.
            'cloud'   => ['#065f46', '#d9f5ec', __('Imported', 'claudpress')],
            'manual'  => ['#4b5563', '#f0f0f1', __('Hand-written', 'claudpress')],
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
