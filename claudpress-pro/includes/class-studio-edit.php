<?php
/**
 * Studio tab 2 — Add / Edit a snippet.
 *
 * The rule this screen exists to enforce: NOTHING GOES LIVE UNPARSED. Saving
 * always runs the PHP parse first, and code that does not parse is stored
 * switched off with the error attached rather than rejected — losing someone's
 * work because it has a typo in it is its own kind of bug.
 *
 * Ask AI is advisory and gates nothing. See the two-verdict block below; that
 * distinction is the most important thing on the page.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Pro_Studio_Edit
{
    public static function render(): void
    {
        $id      = isset($_GET['snippet']) ? (int) $_GET['snippet'] : 0;
        $snippet = $id ? Claudpress_Snippets::get($id) : null;
        $is_new  = !$snippet;

        $defaults = [
            'name' => '', 'description' => '', 'code' => '', 'snippet_type' => 'php',
            'location' => 'everywhere', 'priority' => 10, 'tags' => '', 'active' => 0,
            'source' => 'manual', 'last_error' => '', 'ai_review' => '', 'ai_review_at' => null,
        ];
        $s = array_merge($defaults, $snippet ?: []);

        // A snippet handed over from Compose, held in a transient rather than the
        // URL: code does not survive a query string, and putting it there would
        // also drop it into server logs and browser history.
        if ($is_new && !empty($_GET['from_compose'])) {
            $draft = get_transient('claudpress_studio_draft_' . get_current_user_id());
            if (is_array($draft)) {
                $s = array_merge($s, $draft);
                delete_transient('claudpress_studio_draft_' . get_current_user_id());
            }
        }

        wp_enqueue_code_editor(['type' => self::mime((string) $s['snippet_type'])]);
        wp_enqueue_script('wp-theme-plugin-editor');
        wp_enqueue_style('wp-codemirror');
        ?>
        <h2><?php echo $is_new ? esc_html__('Add Snippet', 'claudpress-pro') : esc_html__('Edit Snippet', 'claudpress-pro'); ?></h2>

        <?php self::error_notice((string) $s['last_error']); ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="cp-studio-form">
            <?php wp_nonce_field(Claudpress_Pro_Studio::NONCE); ?>
            <input type="hidden" name="action" value="claudpress_studio">
            <input type="hidden" name="studio_action" value="save">
            <input type="hidden" name="snippet_id" value="<?php echo esc_attr((string) $id); ?>">
            <input type="hidden" name="source" value="<?php echo esc_attr((string) $s['source']); ?>">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="cp-name"><?php esc_html_e('Name', 'claudpress-pro'); ?></label></th>
                    <td><input name="name" id="cp-name" type="text" class="regular-text" required
                               value="<?php echo esc_attr((string) $s['name']); ?>"
                               placeholder="<?php esc_attr_e('What this snippet does, in a few words', 'claudpress-pro'); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="cp-desc"><?php esc_html_e('Description', 'claudpress-pro'); ?></label></th>
                    <td>
                        <textarea name="description" id="cp-desc" rows="2" class="large-text"><?php echo esc_textarea((string) $s['description']); ?></textarea>
                        <p class="description"><?php esc_html_e('Optional. Write it for whoever finds this in six months.', 'claudpress-pro'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cp-type"><?php esc_html_e('Type', 'claudpress-pro'); ?></label></th>
                    <td>
                        <select name="snippet_type" id="cp-type">
                            <?php foreach (['php' => 'PHP', 'css' => 'CSS', 'js' => 'JavaScript', 'html' => 'HTML'] as $v => $l) : ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected($s['snippet_type'], $v); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cp-location"><?php esc_html_e('Runs where', 'claudpress-pro'); ?></label></th>
                    <td>
                        <select name="location" id="cp-location">
                            <?php foreach (self::locations() as $v => $l) : ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected($s['location'], $v); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cp-priority"><?php esc_html_e('Priority', 'claudpress-pro'); ?></label></th>
                    <td>
                        <input name="priority" id="cp-priority" type="number" class="small-text" value="<?php echo esc_attr((string) $s['priority']); ?>">
                        <p class="description"><?php esc_html_e('Lower runs first. Leave at 10 unless one snippet has to beat another.', 'claudpress-pro'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cp-tags"><?php esc_html_e('Tags', 'claudpress-pro'); ?></label></th>
                    <td><input name="tags" id="cp-tags" type="text" class="regular-text"
                               value="<?php echo esc_attr(implode(', ', Claudpress_Snippets::tags_array($s['tags']))); ?>"
                               placeholder="<?php esc_attr_e('checkout, seo', 'claudpress-pro'); ?>"></td>
                </tr>
            </table>

            <h3><?php esc_html_e('Code', 'claudpress-pro'); ?>
                <button type="button" class="button" id="cp-ask-ai" style="float:right;">
                    <?php esc_html_e('Ask AI what this does', 'claudpress-pro'); ?>
                </button>
            </h3>
            <textarea name="code" id="cp-code" rows="18" class="large-text code"
                      style="font-family:monospace;"><?php echo esc_textarea((string) $s['code']); ?></textarea>

            <div id="cp-ai-report" style="margin-top:12px;"><?php self::review_panel($s); ?></div>

            <p class="submit">
                <button type="submit" name="and_activate" value="0" class="button"><?php esc_html_e('Save (leave off)', 'claudpress-pro'); ?></button>
                <button type="submit" name="and_activate" value="1" class="button button-primary"><?php esc_html_e('Save & switch on', 'claudpress-pro'); ?></button>
                <a href="<?php echo esc_url(Claudpress_Pro_Studio::url('all')); ?>" class="button-link" style="margin-left:12px;"><?php esc_html_e('Cancel', 'claudpress-pro'); ?></a>
            </p>
        </form>

        <?php if (!$is_new) : ?>
            <?php self::history($id); ?>
        <?php endif; ?>

        <script>
        (function () {
            var editor = wp.codeEditor && wp.codeEditor.initialize
                ? wp.codeEditor.initialize(document.getElementById('cp-code'), window.cpStudioEditorSettings || {})
                : null;

            // The type selector changes what the editor is highlighting. Without
            // this, switching a snippet from PHP to CSS leaves you typing CSS
            // into a PHP highlighter, which looks broken.
            var modes = { php: 'application/x-httpd-php', css: 'text/css', js: 'text/javascript', html: 'text/html' };
            var typeEl = document.getElementById('cp-type');
            if (typeEl && editor && editor.codemirror) {
                typeEl.addEventListener('change', function () {
                    editor.codemirror.setOption('mode', modes[this.value] || 'text/plain');
                });
            }

            var btn = document.getElementById('cp-ask-ai');
            if (!btn) { return; }
            btn.addEventListener('click', function () {
                var code = editor && editor.codemirror ? editor.codemirror.getValue() : document.getElementById('cp-code').value;
                if (!code.trim()) {
                    alert(<?php echo wp_json_encode(__('Write or paste some code first.', 'claudpress-pro')); ?>);
                    return;
                }
                btn.disabled = true;
                btn.textContent = <?php echo wp_json_encode(__('Asking…', 'claudpress-pro')); ?>;

                var body = new FormData();
                body.append('action', 'claudpress_studio_review');
                body.append('nonce', <?php echo wp_json_encode(wp_create_nonce(Claudpress_Pro_Studio::NONCE)); ?>);
                body.append('code', code);
                body.append('snippet_type', document.getElementById('cp-type').value);

                fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        document.getElementById('cp-ai-report').innerHTML =
                            (json && json.data && json.data.html) ? json.data.html :
                            '<div class="notice notice-warning inline"><p>' +
                            ((json && json.data && json.data.message) || 'The AI could not be reached.') + '</p></div>';
                    })
                    .catch(function () {
                        document.getElementById('cp-ai-report').innerHTML =
                            '<div class="notice notice-warning inline"><p><?php echo esc_js(__('The AI could not be reached. Your code is untouched.', 'claudpress-pro')); ?></p></div>';
                    })
                    .finally(function () {
                        btn.disabled = false;
                        btn.textContent = <?php echo wp_json_encode(__('Ask AI what this does', 'claudpress-pro')); ?>;
                    });
            });
        }());
        </script>
        <?php
    }

    private static function error_notice(string $error): void
    {
        if (trim($error) === '') {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e('This snippet is switched off because of an error:', 'claudpress-pro'); ?></strong></p>
            <p><code><?php echo esc_html($error); ?></code></p>
            <p><?php esc_html_e('Fix the code and save again. It stays off until it parses.', 'claudpress-pro'); ?></p>
        </div>
        <?php
    }

    /**
     * The two verdicts, side by side and labelled differently.
     *
     * This is the most important thing on the page. The parse is a fact: PHP
     * either accepts the code or it does not, and that is what decides whether a
     * snippet may go live. The AI's opinion is an opinion — useful, frequently
     * right, and never load-bearing.
     *
     * Presenting them as one combined "safety score" would be the easy design
     * and a dishonest one: it would let a confident model talk someone into
     * switching on code the parser rejected, or scare them away from code that
     * is fine.
     */
    public static function review_panel(array $s): void
    {
        $review = json_decode((string) ($s['ai_review'] ?? ''), true);
        if (!is_array($review)) {
            return;
        }
        $verdicts = [
            'safe'    => ['#00794b', __('safe', 'claudpress-pro')],
            'caution' => ['#8c5e00', __('caution', 'claudpress-pro')],
            'unsafe'  => ['#d63638', __('unsafe', 'claudpress-pro')],
        ];
        $v = (string) ($review['verdict'] ?? 'caution');
        [$colour, $label] = $verdicts[$v] ?? $verdicts['caution'];
        ?>
        <div class="card" style="max-width:none;padding:14px 18px;">
            <p style="margin-top:0;">
                <strong><?php esc_html_e('Syntax check', 'claudpress-pro'); ?>:</strong>
                <?php if (trim((string) ($s['last_error'] ?? '')) === '') : ?>
                    <span style="color:#00794b;"><?php esc_html_e('passed', 'claudpress-pro'); ?></span>
                <?php else : ?>
                    <span style="color:#d63638;"><?php esc_html_e('failed', 'claudpress-pro'); ?></span>
                <?php endif; ?>
                <span style="color:#666;">— <?php esc_html_e('a real parse. This is what decides whether the snippet may run.', 'claudpress-pro'); ?></span>
            </p>
            <p>
                <strong><?php esc_html_e('AI assessment', 'claudpress-pro'); ?>:</strong>
                <span style="color:<?php echo esc_attr($colour); ?>;"><?php echo esc_html($label); ?></span>
                <span style="color:#666;">— <?php esc_html_e('an opinion. It does not gate anything.', 'claudpress-pro'); ?></span>
            </p>

            <?php if (!empty($review['summary'])) : ?>
                <p><?php echo esc_html((string) $review['summary']); ?></p>
            <?php endif; ?>
            <?php if (!empty($review['site_impact'])) : ?>
                <p><strong><?php esc_html_e('Effect on the site', 'claudpress-pro'); ?>:</strong> <?php echo esc_html((string) $review['site_impact']); ?></p>
            <?php endif; ?>
            <?php if (!empty($review['risks']) && is_array($review['risks'])) : ?>
                <p style="margin-bottom:4px;"><strong><?php esc_html_e('Watch out for', 'claudpress-pro'); ?>:</strong></p>
                <ul style="margin:0 0 8px 18px;list-style:disc;">
                    <?php foreach ($review['risks'] as $risk) : ?>
                        <li><?php echo esc_html((string) $risk); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($review['notes'])) : ?>
                <p><strong><?php esc_html_e('After switching it on', 'claudpress-pro'); ?>:</strong> <?php echo esc_html((string) $review['notes']); ?></p>
            <?php endif; ?>

            <?php if (!empty($s['ai_review_at'])) : ?>
                <p style="color:#777;font-size:12px;margin-bottom:0;">
                    <?php
                    /* translators: %s: how long ago, e.g. "3 hours". */
                    echo esc_html(sprintf(__('Reviewed %s ago. If you have edited the code since, ask again.', 'claudpress-pro'),
                        human_time_diff(strtotime((string) $s['ai_review_at']), current_time('timestamp'))));
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function history(int $id): void
    {
        $revisions = Claudpress_Snippets::revisions($id, 10);
        if (!$revisions) {
            return;
        }
        ?>
        <h3><?php esc_html_e('History', 'claudpress-pro'); ?></h3>
        <p class="description"><?php esc_html_e('What this snippet looked like before each change. Recorded on every edit, by anyone.', 'claudpress-pro'); ?></p>
        <table class="wp-list-table widefat striped">
            <thead><tr>
                <th><?php esc_html_e('When', 'claudpress-pro'); ?></th>
                <th><?php esc_html_e('By', 'claudpress-pro'); ?></th>
                <th><?php esc_html_e('Note', 'claudpress-pro'); ?></th>
                <th style="width:110px;"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($revisions as $rev) : ?>
                <tr>
                    <td><?php echo esc_html((string) $rev['created_at']); ?></td>
                    <td><?php echo esc_html($rev['author_type'] === 'agent' ? __('the agent', 'claudpress-pro') : self::user_name((int) $rev['user_id'])); ?></td>
                    <td><?php echo esc_html((string) $rev['note']); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                              onsubmit="return confirm('<?php echo esc_js(__('Restore this version? The current code is saved to history first, and the snippet is switched off until you review it.', 'claudpress-pro')); ?>');">
                            <?php wp_nonce_field(Claudpress_Pro_Studio::NONCE); ?>
                            <input type="hidden" name="action" value="claudpress_studio">
                            <input type="hidden" name="studio_action" value="restore">
                            <input type="hidden" name="snippet_id" value="<?php echo esc_attr((string) $id); ?>">
                            <input type="hidden" name="revision_id" value="<?php echo esc_attr((string) $rev['id']); ?>">
                            <button class="button button-small"><?php esc_html_e('Restore', 'claudpress-pro'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function user_name(int $user_id): string
    {
        if ($user_id <= 0) {
            return __('unknown', 'claudpress-pro');
        }
        $user = get_userdata($user_id);
        return $user ? $user->user_login : __('a deleted user', 'claudpress-pro');
    }

    private static function locations(): array
    {
        return [
            'everywhere'     => __('Everywhere', 'claudpress-pro'),
            'frontend_only'  => __('Front end only', 'claudpress-pro'),
            'admin_only'     => __('Admin only', 'claudpress-pro'),
            'header'         => __('In the <head>', 'claudpress-pro'),
            'footer'         => __('Before </body>', 'claudpress-pro'),
            'before_content' => __('Before post content', 'claudpress-pro'),
            'after_content'  => __('After post content', 'claudpress-pro'),
            'shortcode'      => __('Only via shortcode', 'claudpress-pro'),
            'page_template'  => __('As a page template', 'claudpress-pro'),
        ];
    }

    private static function mime(string $type): string
    {
        $map = ['php' => 'application/x-httpd-php', 'css' => 'text/css', 'js' => 'text/javascript', 'html' => 'text/html'];
        return $map[$type] ?? 'text/plain';
    }
}
