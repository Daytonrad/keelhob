<?php
/**
 * The donate button, and the page behind it.
 *
 * Keelhob is free and stays free. There is no paid tier to upsell to, which
 * removes the usual reason a plugin nags you — but it also means donations are
 * the only thing standing between this project and being abandoned when its
 * author runs out of evenings.
 *
 * So the ask is visible on every screen and never dismissible, and it is a
 * BUTTON rather than a banner: a banner is something you learn to scroll past,
 * and one you can dismiss is one you dismiss once and never see again. A button
 * beside the page title is there when you happen to feel like clicking it,
 * which is the only moment that ever matters.
 *
 * What it must never become: a modal, a countdown, a "maybe later", a nag after
 * N days, or anything that interrupts work. Those buy one donation and cost the
 * goodwill that was the whole point.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Donate
{
    const PAGE = 'keelhob-donate';

    public static function init(): void
    {
        add_filter('plugin_action_links_' . KEELHOB_BASENAME, [__CLASS__, 'action_link']);
        add_filter('plugin_row_meta', [__CLASS__, 'row_meta'], 10, 2);
    }

    /** The Donate item in the Keelhob menu. Registered from the admin menu. */
    public static function menu(): void
    {
        add_submenu_page(
            'keelhob',
            __('Support Keelhob', 'keelhob'),
            '♥ ' . __('Donate', 'keelhob'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render']
        );
    }

    /**
     * The small button that sits beside a page title.
     *
     * Echoed immediately after the <h1> on every Keelhob screen. Goes
     * straight to Ko-fi rather than to the Donate page, because a button
     * labelled Donate that leads to another button labelled Donate is a button
     * that wasted your click.
     */
    public static function button(): void
    {
        if (KEELHOB_DONATE_URL === '') {
            return;
        }
        printf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" class="button" style="margin-left:12px;vertical-align:middle;border-color:#c2185b;color:#c2185b;font-weight:600;" title="%s">%s</a>',
            esc_url(KEELHOB_DONATE_URL),
            esc_attr__('Keelhob is free forever. Donations are the only thing funding it.', 'keelhob'),
            esc_html__('♥ Donate', 'keelhob')
        );
    }

    /** A Donate link on the Plugins screen row, where people look for settings. */
    public static function action_link($links)
    {
        if (!is_array($links) || KEELHOB_DONATE_URL === '') {
            return $links;
        }
        array_unshift(
            $links,
            sprintf('<a href="%s" target="_blank" rel="noopener noreferrer" style="color:#c2185b;font-weight:600;">%s</a>',
                esc_url(KEELHOB_DONATE_URL),
                esc_html__('♥ Donate', 'keelhob')
            ),
            sprintf('<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=keelhob')),
                esc_html__('Settings', 'keelhob')
            ),
            /*
             * Snippets, on the Plugins row.
             *
             * The one screen here a person uses without an agent: a real code
             * editor, an on/off switch per snippet, and a syntax check that
             * refuses to let broken PHP run. It needs no connection and no
             * abilities, so somebody who installed this for the snippets alone
             * should not have to find it through a settings page about MCP.
             *
             * Third, not first. Settings is what most people came for, and a
             * row of links that buries it has made itself worse.
             */
            sprintf('<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=' . Keelhob_Studio::SLUG)),
                esc_html__('Snippets', 'keelhob')
            )
        );
        return $links;
    }

    /** Repo and roadmap links under the plugin description. */
    public static function row_meta($links, $file)
    {
        if ($file !== KEELHOB_BASENAME || !is_array($links)) {
            return $links;
        }
        $links[] = sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url(KEELHOB_REPO_URL), esc_html__('GitHub', 'keelhob'));
        $links[] = sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url(KEELHOB_REPO_URL . '/blob/main/ROADMAP.md'), esc_html__('Roadmap', 'keelhob'));
        return $links;
    }

    /** The Donate screen: what the money is for, stated plainly. */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo Keelhob_Brand::heading_mark(); ?><?php esc_html_e('Support Keelhob', 'keelhob'); ?></h1>

            <div style="max-width:760px;">
                <p style="font-size:15px;line-height:1.7;">
                    <?php esc_html_e('Keelhob is free, GPL, and stays that way. There is no paid version, no locked feature, no trial, and nothing held back. Everything that was going to be sold is in the plugin you already have.', 'keelhob'); ?>
                </p>
                <p style="font-size:15px;line-height:1.7;">
                    <?php esc_html_e('It is also built in evenings, around a full-time job. Donations are the only thing that decides whether it keeps being built — not as a nice-to-have, but literally: enough support means more hours, and eventually only this.', 'keelhob'); ?>
                </p>

                <p style="margin:24px 0;">
                    <a href="<?php echo esc_url(KEELHOB_DONATE_URL); ?>" target="_blank" rel="noopener noreferrer"
                       class="button button-primary button-hero"
                       style="background:#c2185b;border-color:#a01449;box-shadow:none;text-shadow:none;">
                        <?php esc_html_e('♥ Donate on Ko-fi', 'keelhob'); ?>
                    </a>
                </p>

                <h2><?php esc_html_e('Other ways that help just as much', 'keelhob'); ?></h2>
                <ul style="list-style:disc;margin-left:20px;line-height:1.9;">
                    <li><?php esc_html_e('Star the repository. It is the first thing anyone deciding whether to trust this looks at.', 'keelhob'); ?></li>
                    <li><?php esc_html_e('Report a bug you hit. A reproducible bug report is worth more than most donations.', 'keelhob'); ?></li>
                    <li><?php esc_html_e('Write a skill and open a pull request. Skills are plain markdown — no PHP needed.', 'keelhob'); ?></li>
                    <li><?php esc_html_e('Tell someone. Nothing here has a marketing budget.', 'keelhob'); ?></li>
                    <li><?php esc_html_e('Leave the update check on, under Settings. It is how the project knows anyone is out there.', 'keelhob'); ?></li>
                </ul>

                <h2><?php esc_html_e('Need this set up for you?', 'keelhob'); ?></h2>
                <p style="line-height:1.7;">
                    <?php esc_html_e('The plugin is free and always will be. If you would rather someone else did the install, the configuration, or the whole site, that is a paid service and it is entirely optional — the plugin does not behave differently either way.', 'keelhob'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(KEELHOB_SERVICES_URL); ?>" target="_blank" rel="noopener noreferrer" class="button">
                        <?php esc_html_e('See what that involves', 'keelhob'); ?>
                    </a>
                    <a href="<?php echo esc_url(KEELHOB_REPO_URL . '/blob/main/ROADMAP.md'); ?>" target="_blank" rel="noopener noreferrer" class="button">
                        <?php esc_html_e('Read the roadmap', 'keelhob'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }
}
