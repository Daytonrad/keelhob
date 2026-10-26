<?php
/**
 * The Skills admin page — "what expertise does this site have, and what else
 * exists?"
 *
 * A sync that silently swaps the instructions an agent follows is untrustworthy
 * and unsellable, so the manifest is shown rather than assumed: what is
 * installed, where each one came from, when it last changed, and what arrived
 * in the last few refreshes.
 *
 * The second list is the honest part. Pro skills are shown by name and
 * description and never by body — descriptions are marketing, bodies are the
 * product. A free user can read exactly what they would get, which costs
 * nothing to give away and is the most truthful upsell in the plugin. Owners
 * who would rather not see it at all switch it off in Settings, and this page
 * honours that.
 *
 * STYLED FROM assets/css/admin.css, NOT FROM CORE. It used to say "asset-free,
 * like the other screens: native markup, no CSS or JS files", and that stopped
 * being true when the panel got a design system: the screen is wrapped in
 * Keelhob_Admin::shell_open(), which carries the stylesheet's root class, and
 * the surfaces are .kh-u-card. The few colours still named inline are
 * var(--kh-*) references rather than literal hexes, so they follow the dark
 * variant instead of staying light inside a dark panel.
 *
 * The prose was cut to one sub-line per heading, with the longer explanation
 * moved into a closed <details> rather than deleted. Nothing about what the
 * agent is allowed to do lives on this screen, so there is no safety text here
 * that shortening could weaken.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Admin_Skills
{
    /**
     * Row source, overridable so the render tests can drive the template with
     * fixtures. Null = the live skills store.
     *
     * @var callable():array|null
     */
    public static $source = null;

    private static function rows(): array
    {
        if (is_callable(self::$source)) {
            return (array) call_user_func(self::$source);
        }
        return Keelhob::instance()->skills->all();
    }

    /**
     * Handle the Refresh button before anything is rendered.
     *
     * Capability and nonce, both — a refresh reaches out to the network and
     * writes to the skills table, so it is not a link anybody may follow.
     */
    private function maybe_refresh(): array
    {
        if (empty($_POST['keelhob_skills_refresh'])) {
            return [];
        }
        if (!current_user_can('manage_options') || !check_admin_referer('keelhob_skills_refresh')) {
            return [];
        }
        return Keelhob_Skills_Remote::refresh();
    }

    public function render(): void
    {
        $refreshed = $this->maybe_refresh();
        $rows      = self::rows();
        $new       = Keelhob_Skills_Remote::newly_added();
        $cache     = Keelhob_Skills_Remote::cache();
        $freshness = Keelhob_Skills_Remote::freshness((int) $cache['fetched_at'], time());
        $history   = Keelhob_Skills_Remote::history();

        /*
         * The table styles, as PHP strings rather than a new stylesheet.
         *
         * assets/css/admin.css is owned by the core-screens lane and is
         * READ-ONLY here, so a `kh-u-table` component cannot be added to it
         * from this file. Core's own `widefat striped` is the other option and
         * it is worse: it paints #fff and #f6f7f7 unconditionally, which is a
         * white table sitting inside a dark card the moment the panel goes
         * dark. These strings reference the tokens instead, so the table
         * follows the panel in both modes. Noted in BUILD-NOTES.md as a
         * component the token layer should grow.
         */
        $t_table = 'width:100%;max-width:980px;border-collapse:collapse;';
        $t_th    = 'text-align:left;padding:8px 10px;border-bottom:1px solid var(--kh-line);'
                 . 'font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;'
                 . 'color:var(--kh-muted);';
        $t_td    = 'padding:10px;border-bottom:1px solid var(--kh-line);vertical-align:top;';
        /* --kh-muted on --kh-card is 6.18:1 light, 8.90:1 dark. */
        $t_sub   = 'color:var(--kh-muted);font-size:12px;';

        Keelhob_Admin::shell_open('keelhob-skills');
        ?>
            <?php if ($refreshed) : ?>
                <div class="notice <?php echo !empty($refreshed['success']) ? 'notice-success' : 'notice-warning'; ?>">
                    <p><?php echo esc_html((string) ($refreshed['notice'] ?? '')); ?></p>
                </div>
            <?php endif; ?>

            <div class="kh-u-card" style="margin:0 0 16px;max-width:980px;">
                <h2 style="margin:0 0 4px;font-size:16px;"><?php esc_html_e('Skills', 'keelhob'); ?></h2>
                <p style="margin:0 0 12px;<?php echo esc_attr($t_sub); ?>">
                    <?php esc_html_e('The playbooks a connected agent follows. Plain Markdown — nothing here is executed.', 'keelhob'); ?>
                </p>

                <details style="margin:0 0 14px;">
                    <summary style="cursor:pointer;<?php echo esc_attr($t_sub); ?>"><?php esc_html_e('What a skill is, and why the list is shown rather than assumed', 'keelhob'); ?></summary>
                    <p style="max-width:70ch;margin:8px 0 0;">
                        <?php esc_html_e('A skill is a plain-Markdown playbook: how to build a page, how to publish a post, how to harden this site. A sync that silently swapped the instructions an agent follows would be untrustworthy, so the manifest is shown in full — what is installed, where each one came from, and when it last changed.', 'keelhob'); ?>
                    </p>
                </details>

                <form method="post" style="margin:0;">
                    <?php wp_nonce_field('keelhob_skills_refresh'); ?>
                    <input type="hidden" name="keelhob_skills_refresh" value="1">
                    <button type="submit" class="button button-secondary"><?php esc_html_e('Refresh skills', 'keelhob'); ?></button>
                    <span style="margin-left:10px;<?php echo esc_attr($t_sub); ?>">
                        <?php if ((int) $cache['fetched_at'] === 0) : ?>
                            <?php esc_html_e('Never checked for new skills.', 'keelhob'); ?>
                        <?php elseif ($freshness === 'fresh') : ?>
                            <?php esc_html_e('Up to date.', 'keelhob'); ?>
                        <?php elseif ($freshness === 'grace') : ?>
                            <?php esc_html_e('Not checked in over a week. Your skills still work.', 'keelhob'); ?>
                        <?php else : ?>
                            <?php esc_html_e('Not checked in over a month. Your skills still work, but they may be out of date.', 'keelhob'); ?>
                        <?php endif; ?>
                    </span>
                </form>
            </div>

            <div class="kh-u-card" style="margin:0 0 16px;max-width:980px;">
                <h2 style="margin:0 0 12px;font-size:16px;"><?php esc_html_e('On this site', 'keelhob'); ?></h2>
                <table style="<?php echo esc_attr($t_table); ?>">
                    <thead><tr>
                        <th style="<?php echo esc_attr($t_th); ?>"><?php esc_html_e('Skill', 'keelhob'); ?></th>
                        <th style="<?php echo esc_attr($t_th); ?>width:110px;"><?php esc_html_e('Version', 'keelhob'); ?></th>
                        <th style="<?php echo esc_attr($t_th); ?>width:110px;"><?php esc_html_e('Source', 'keelhob'); ?></th>
                        <th style="<?php echo esc_attr($t_th); ?>width:170px;"><?php esc_html_e('Last changed', 'keelhob'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$rows) : ?>
                        <tr><td style="<?php echo esc_attr($t_td); ?>" colspan="4"><?php esc_html_e('No skills are installed yet. Press Refresh skills, or reactivate the plugin to reinstall the bundled set.', 'keelhob'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row) : ?>
                        <?php $name = (string) ($row['name'] ?? ''); ?>
                        <tr>
                            <td style="<?php echo esc_attr($t_td); ?>">
                                <strong><?php echo esc_html($name); ?></strong>
                                <?php if (in_array($name, $new, true)) : ?>
                                    <?php
                                    /*
                                     * The badge used to be white on #00a32a, which measures
                                     * 3.08:1 and fails AA at 11px. Soft tint as the fill, the
                                     * status colour as the border only, and var(--kh-ink) for
                                     * the word: 17.38:1 light, 13.96:1 dark. admin.css
                                     * redefines --kh-good-soft for dark, so the ink has to
                                     * invert with it — a literal dark ink measured 1.14:1 on
                                     * the dark tint when this was checked in a browser.
                                     */
                                    ?>
                                    <span class="kh-u-pill" style="margin-left:6px;font-size:11px;padding:1px 8px;background:var(--kh-good-soft);border-color:var(--kh-good);color:var(--kh-ink);"><?php esc_html_e('New', 'keelhob'); ?></span>
                                <?php endif; ?>
                                <?php if (empty($row['enabled'])) : ?>
                                    <span style="margin-left:6px;color:var(--kh-muted);"><?php esc_html_e('(off)', 'keelhob'); ?></span>
                                <?php endif; ?>
                                <div style="<?php echo esc_attr($t_sub); ?>"><?php echo esc_html((string) ($row['description'] ?? '')); ?></div>
                            </td>
                            <td style="<?php echo esc_attr($t_td); ?>"><?php echo esc_html((string) ($row['version'] ?? '') ?: '—'); ?></td>
                            <td style="<?php echo esc_attr($t_td); ?>"><?php echo esc_html(Keelhob_Skills::normalize_source((string) ($row['source'] ?? ''))); ?></td>
                            <td style="<?php echo esc_attr($t_td); ?>"><?php echo esc_html((string) ($row['updated_at'] ?? '') ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="kh-u-card" style="margin:0;max-width:980px;">
                <h2 style="margin:0 0 12px;font-size:16px;"><?php esc_html_e('Recent changes', 'keelhob'); ?></h2>
                <?php if (!$history) : ?>
                    <p style="margin:0;<?php echo esc_attr($t_sub); ?>"><?php esc_html_e('Nothing has arrived from the skills server yet.', 'keelhob'); ?></p>
                <?php else : ?>
                    <table style="<?php echo esc_attr($t_table); ?>">
                        <thead><tr>
                            <th style="<?php echo esc_attr($t_th); ?>width:170px;"><?php esc_html_e('When', 'keelhob'); ?></th>
                            <th style="<?php echo esc_attr($t_th); ?>"><?php esc_html_e('What changed', 'keelhob'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($history as $entry) : ?>
                            <tr>
                                <td style="<?php echo esc_attr($t_td); ?>"><?php echo esc_html((string) ($entry['at'] ?? '')); ?></td>
                                <td style="<?php echo esc_attr($t_td); ?>">
                                    <?php if (!empty($entry['installed'])) : ?>
                                        <div><?php esc_html_e('Added:', 'keelhob'); ?> <?php echo esc_html(implode(', ', (array) $entry['installed'])); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['updated'])) : ?>
                                        <div><?php esc_html_e('Updated:', 'keelhob'); ?> <?php echo esc_html(implode(', ', (array) $entry['updated'])); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php
        Keelhob_Admin::shell_close();
    }
}
