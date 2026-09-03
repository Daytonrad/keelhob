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
 * Asset-free, like the other screens: native markup, no CSS or JS files.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Admin_Skills
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
        return Claudpress::instance()->skills->all();
    }

    /**
     * Handle the Refresh button before anything is rendered.
     *
     * Capability and nonce, both — a refresh reaches out to the network and
     * writes to the skills table, so it is not a link anybody may follow.
     */
    private function maybe_refresh(): array
    {
        if (empty($_POST['claudpress_skills_refresh'])) {
            return [];
        }
        if (!current_user_can('manage_options') || !check_admin_referer('claudpress_skills_refresh')) {
            return [];
        }
        return Claudpress_Skills_Remote::refresh();
    }

    public function render(): void
    {
        $refreshed = $this->maybe_refresh();
        $rows      = self::rows();
        $new       = Claudpress_Skills_Remote::newly_added();
        $cache     = Claudpress_Skills_Remote::cache();
        $freshness = Claudpress_Skills_Remote::freshness((int) $cache['fetched_at'], time());
        $history   = Claudpress_Skills_Remote::history();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Skills', 'claudpress'); ?></h1><?php Claudpress_Donate::button(); ?>

            <?php if ($refreshed) : ?>
                <div class="notice <?php echo !empty($refreshed['success']) ? 'notice-success' : 'notice-warning'; ?>">
                    <p><?php echo esc_html((string) ($refreshed['notice'] ?? '')); ?></p>
                </div>
            <?php endif; ?>

            <p style="max-width:820px;color:#50575e;">
                <?php esc_html_e('Skills are the playbooks a connected agent follows: how to build a page, how to publish a post, how to harden this site. Each one is plain Markdown — nothing here is executed.', 'claudpress'); ?>
            </p>

            <form method="post" style="margin:12px 0 20px;">
                <?php wp_nonce_field('claudpress_skills_refresh'); ?>
                <input type="hidden" name="claudpress_skills_refresh" value="1">
                <button type="submit" class="button button-secondary"><?php esc_html_e('Refresh skills', 'claudpress'); ?></button>
                <span style="margin-left:10px;color:#646970;">
                    <?php if ((int) $cache['fetched_at'] === 0) : ?>
                        <?php esc_html_e('Never checked for new skills.', 'claudpress'); ?>
                    <?php elseif ($freshness === 'fresh') : ?>
                        <?php esc_html_e('Up to date.', 'claudpress'); ?>
                    <?php elseif ($freshness === 'grace') : ?>
                        <?php esc_html_e('Not checked in over a week. Your skills still work.', 'claudpress'); ?>
                    <?php else : ?>
                        <?php esc_html_e('Not checked in over a month. Your skills still work, but they may be out of date.', 'claudpress'); ?>
                    <?php endif; ?>
                </span>
            </form>

            <h2><?php esc_html_e('On this site', 'claudpress'); ?></h2>
            <table class="widefat striped" style="max-width:980px;">
                <thead><tr>
                    <th><?php esc_html_e('Skill', 'claudpress'); ?></th>
                    <th style="width:110px;"><?php esc_html_e('Version', 'claudpress'); ?></th>
                    <th style="width:110px;"><?php esc_html_e('Source', 'claudpress'); ?></th>
                    <th style="width:170px;"><?php esc_html_e('Last changed', 'claudpress'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (!$rows) : ?>
                    <tr><td colspan="4"><?php esc_html_e('No skills are installed yet. Press Refresh skills, or reactivate the plugin to reinstall the bundled set.', 'claudpress'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row) : ?>
                    <?php $name = (string) ($row['name'] ?? ''); ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($name); ?></strong>
                            <?php if (in_array($name, $new, true)) : ?>
                                <span style="margin-left:6px;padding:1px 6px;border-radius:9px;background:#00a32a;color:#fff;font-size:11px;"><?php esc_html_e('New', 'claudpress'); ?></span>
                            <?php endif; ?>
                            <?php if (empty($row['enabled'])) : ?>
                                <span style="margin-left:6px;color:#b32d2e;"><?php esc_html_e('(off)', 'claudpress'); ?></span>
                            <?php endif; ?>
                            <div style="color:#50575e;"><?php echo esc_html((string) ($row['description'] ?? '')); ?></div>
                        </td>
                        <td><?php echo esc_html((string) ($row['version'] ?? '') ?: '—'); ?></td>
                        <td><?php echo esc_html(Claudpress_Skills::normalize_source((string) ($row['source'] ?? ''))); ?></td>
                        <td><?php echo esc_html((string) ($row['updated_at'] ?? '') ?: '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:28px;"><?php esc_html_e('Recent changes', 'claudpress'); ?></h2>
            <?php if (!$history) : ?>
                <p style="color:#50575e;"><?php esc_html_e('Nothing has arrived from the skills server yet.', 'claudpress'); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:980px;">
                    <thead><tr>
                        <th style="width:170px;"><?php esc_html_e('When', 'claudpress'); ?></th>
                        <th><?php esc_html_e('What changed', 'claudpress'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($history as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($entry['at'] ?? '')); ?></td>
                            <td>
                                <?php if (!empty($entry['installed'])) : ?>
                                    <div><?php esc_html_e('Added:', 'claudpress'); ?> <?php echo esc_html(implode(', ', (array) $entry['installed'])); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($entry['updated'])) : ?>
                                    <div><?php esc_html_e('Updated:', 'claudpress'); ?> <?php echo esc_html(implode(', ', (array) $entry['updated'])); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
