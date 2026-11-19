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

final class Keelhob_Studio
{
    const SLUG  = 'keelhob-studio';
    const NONCE = 'keelhob_studio';

    /** Tab slug => label. Order is the order they render in. */
    public static function tabs(): array
    {
        /*
         * THREE TABS. There was very nearly a fourth.
         *
         * A Library tab shipped here for a long time, shown only when
         * Keelhob_Studio_Library::has_items() said there was something to
         * show. It never said yes on any install, because has_items() asks
         * Keelhob_Snippet_Library - a class that was never written. So the
         * tab could not appear, and the 135-line screen behind it could not be
         * reached, and both shipped in every zip anyway.
         *
         * Hiding it was the right first move when it was found. Deleting it is
         * the right second one: a repo about to be read by strangers should not
         * contain a screen that cannot be opened.
         *
         * What was deliberately KEPT: `source = 'library'` in
         * Keelhob_Snippets::SOURCES and its badge below. That is stored
         * data, not UI. Sites that used the old private library still have rows
         * carrying it, and removing the badge would render their history wrong.
         *
         * `?tab=library` still lands on the list rather than erroring -
         * current_tab() falls back for anything it does not recognise - so an
         * old bookmark is not a dead end.
         */
        /*
         * The second tab is named for what it is about to do.
         *
         * It always said "Add Snippet", so opening an existing snippet gave you
         * a highlighted tab reading "Add Snippet" directly above a heading
         * reading "Edit Snippet". Two labels for one screen, disagreeing.
         */
        $editing = !empty($_GET['snippet']);

        return [
            'all'     => __('All Snippets', 'keelhob'),
            'edit'    => $editing ? __('Edit Snippet', 'keelhob') : __('Add Snippet', 'keelhob'),
            'compose' => __('AI Compose', 'keelhob'),
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
            'keelhob',
            __('Snippets', 'keelhob'),
            __('Snippets', 'keelhob'),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
            2
        );
    }

    /**
     * The Studio stylesheet.
     *
     * One file for all three tabs, registered once and enqueued by whichever
     * one renders. It replaced two separate wp_add_inline_style() blocks — one
     * in the list, one in the editor — that styled the same product in the same
     * screen and had already started to disagree with each other.
     *
     * Safe to call more than once: wp_enqueue_style() on an already-enqueued
     * handle is a no-op, which matters because the editor calls it from
     * enqueue() and the list from render().
     */
    public static function enqueue_styles(): void
    {
        wp_enqueue_style(
            'keelhob-studio',
            KEELHOB_URL . 'assets/css/studio.css',
            [],
            Keelhob::asset_version('assets/css/studio.css')
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage snippets.', 'keelhob'));
        }

        $current = self::current_tab();

        /*
         * THE STUDIO IS INSIDE THE PANEL SHELL, AND IT HAS TO BE.
         *
         * assets/css/studio.css no longer names its own colours: every one is a
         * var(--kh-*) reference, and those custom properties are declared on
         * `.kh-ui` in assets/css/admin.css. Without the root class the whole
         * stylesheet would resolve to nothing and the screen would render as
         * unstyled markup — so wrapping the Studio is not cosmetic, it is what
         * makes the retokenised stylesheet work at all.
         *
         * Keelhob_Admin::root_class() keeps `wrap`, which core JavaScript needs
         * in order to move admin notices onto the page, and the shell prints the
         * <h1>. The screen's own title is therefore an <h2>: two <h1>s would
         * hand core's notice relocation the wrong heading, and the plugin name
         * belongs in the header line once.
         *
         * `wp-header-end` stays. Core uses it as the marker for where notices
         * land, and the "Add Snippet" button is still a page-title-action beside
         * the heading it belongs to.
         */
        Keelhob_Admin::shell_open(self::SLUG);
        ?>
            <?php
            /*
             * NO SECOND HEADING, NO "ADD SNIPPET" BUTTON, NO wp-header-end, AND
             * THE REASON IS A MEASUREMENT RATHER THAN A PREFERENCE.
             *
             * This used to open with an <h1> "Snippets Studio", an "Add Snippet"
             * page-title-action beside it and core's wp-header-end rule. All
             * three are now redundant: the panel's tab strip marks this screen
             * "Snippets" with aria-current, and the sub-tab strip below has
             * "Add Snippet" as a tab, so the button was a second route to a
             * destination already on the page.
             *
             * Removing them also fixed a real bug. The shell's header line and
             * tab strip add 140px above everything, and on AI Compose that
             * pushed the transcript down until the Save button under a generated
             * code block fell below the fold on a 674px window —
             * check-compose.mjs chat-12, which is the same class of failure the
             * 58vh-to-380px fix in studio.css was written for. Shrinking the
             * transcript could not fix it: a whole message has to FIT in the box
             * for its Save row to be visible, so the chrome above had to come
             * down instead.
             *
             * wp-header-end is a marker core uses to place admin notices. With
             * it gone they land after the shell's <h1> instead, which is where
             * every other Keelhob screen now puts them.
             */
            self::notices();
            ?>

            <nav class="nav-tab-wrapper wp-clearfix kh-subnav" aria-label="<?php esc_attr_e('Snippets Studio tabs', 'keelhob'); ?>">
                <?php foreach (self::tabs() as $slug => $label) : ?>
                    <a href="<?php echo esc_url(self::url($slug)); ?>"
                       class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            switch ($current) {
                case 'edit':
                    Keelhob_Studio_Edit::render();
                    break;
                case 'compose':
                    Keelhob_Studio_Compose::render();
                    break;
                default:
                    Keelhob_Studio_List::render();
            }
        Keelhob_Admin::shell_close();
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
            'saved'      => ['success', __('Snippet saved.', 'keelhob')],
            'activated'  => ['success', __('Snippet saved and switched on.', 'keelhob')],
            'deactivated' => ['info', __('Snippet switched off.', 'keelhob')],
            'deleted'    => ['success', __('Snippet deleted.', 'keelhob')],
            'lint'       => ['error', __('That code does not parse, so it was saved switched OFF. The error is shown on the snippet.', 'keelhob')],
            'moved'      => ['warning', __('Saved, but moved to a location that can run it. CSS, JS and HTML are only output in the header, the footer, before or after content, or from a shortcode — at "everywhere", "admin only", "front end only" or "page template" nothing would have printed them.', 'keelhob')],
            // Nothing was written. The specific reason comes from save() and is
            // picked up below; this text is only the fallback.
            'failed'     => ['error', __('That snippet was not saved, and nothing was stored.', 'keelhob')],
        ];
        $key = isset($_GET['kh_msg']) ? sanitize_key(wp_unslash($_GET['kh_msg'])) : '';
        if ($key === '' || !isset($map[$key])) {
            return;
        }
        [$type, $text] = $map[$key];

        /*
         * The one case that cannot be a fixed string. A refusal has a reason -
         * which field is missing, which location cannot run that type - and
         * "it was not saved" without the reason leaves the owner guessing at
         * the exact moment they need telling. Parked by save's caller for 60
         * seconds, read once, deleted. The tab-leak argument above does not
         * apply: it is consumed by the very next page load.
         */
        if ($key === 'failed') {
            $why = get_transient('keelhob_studio_error_' . get_current_user_id());
            if (is_string($why) && $why !== '') {
                delete_transient('keelhob_studio_error_' . get_current_user_id());
                $text = $why;
            }
        }
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
        wp_safe_redirect(self::url($tab, array_merge($extra, ['kh_msg' => $msg])));
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
            'ai'      => ['#8c5e00', '#fff3d6', __('AI-generated', 'keelhob')],
            'library' => ['#1d4ed8', '#e6edff', __('Library', 'keelhob')],
            // Snippets pulled from the old private library on our server.
            // Nothing writes this any more, but rows carrying it still
            // exist and deserve a truthful badge rather than being shown
            // as hand-written.
            'cloud'   => ['#065f46', '#d9f5ec', __('Imported', 'keelhob')],
            'manual'  => ['#4b5563', '#f0f0f1', __('Hand-written', 'keelhob')],
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
