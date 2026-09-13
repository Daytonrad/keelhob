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

final class Claudpress_Studio_Edit
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

        self::enqueue((string) $s['snippet_type']);

        $has_details = trim((string) $s['description']) !== ''
            || (string) $s['location'] !== 'everywhere'
            || (int) $s['priority'] !== 10
            || trim((string) $s['tags']) !== '';
        ?>
        <h2><?php echo $is_new ? esc_html__('Add Snippet', 'claudpress') : esc_html__('Edit Snippet', 'claudpress'); ?></h2>

        <?php self::error_notice((string) $s['last_error']); ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="cp-studio-form">
            <?php wp_nonce_field(Claudpress_Studio::NONCE); ?>
            <input type="hidden" name="action" value="claudpress_studio">
            <input type="hidden" name="studio_action" value="save">
            <input type="hidden" name="snippet_id" value="<?php echo esc_attr((string) $id); ?>">
            <input type="hidden" name="source" value="<?php echo esc_attr((string) $s['source']); ?>">

            <?php
            /*
             * THE CODE COMES FIRST, and this is the whole reason the layout was
             * changed.
             *
             * The old screen put six form-table rows above the editor: Name,
             * Description, Type, Runs where, Priority, Tags. Measured on a
             * 1536x674 laptop, that pushed the code box to 736px down the page,
             * so opening a snippet to look at its code showed six fields and no
             * code, every single time.
             *
             * Of those six, exactly one is required. The other five are set once
             * and almost never revisited, so they sit below the code now, in a
             * <details> that opens itself when any of them is actually in use.
             * An existing snippet must never hide a setting that is doing
             * something.
             */
            ?>
            <div class="cp-edit-head">
                <p class="cp-edit-field cp-edit-name">
                    <label for="cp-name"><?php esc_html_e('Name', 'claudpress'); ?></label>
                    <input name="name" id="cp-name" type="text" class="regular-text" required
                           value="<?php echo esc_attr((string) $s['name']); ?>"
                           placeholder="<?php esc_attr_e('What this snippet does, in a few words', 'claudpress'); ?>">
                </p>
                <p class="cp-edit-field">
                    <label for="cp-type"><?php esc_html_e('Type', 'claudpress'); ?></label>
                    <select name="snippet_type" id="cp-type">
                        <?php foreach (['php' => 'PHP', 'css' => 'CSS', 'js' => 'JavaScript', 'html' => 'HTML'] as $v => $l) : ?>
                            <option value="<?php echo esc_attr($v); ?>" <?php selected($s['snippet_type'], $v); ?>><?php echo esc_html($l); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p class="cp-edit-field cp-edit-state">
                    <?php if ((int) $s['active'] === 1) : ?>
                        <span class="cp-state cp-state-on"><?php esc_html_e('Switched on', 'claudpress'); ?></span>
                    <?php else : ?>
                        <span class="cp-state cp-state-off"><?php esc_html_e('Switched off', 'claudpress'); ?></span>
                    <?php endif; ?>
                </p>
            </div>

            <div id="cp-editor-warning" hidden></div>

            <label class="screen-reader-text" for="cp-code"><?php esc_html_e('Code', 'claudpress'); ?></label>
            <textarea name="code" id="cp-code" rows="18" class="large-text code"
                      spellcheck="false"><?php
                /*
                 * esc_textarea and nothing else. Do NOT add sanitize_textarea_field,
                 * wp_kses or any other filter here: they mangle valid code (&&
                 * becomes &amp;&amp;, angle brackets vanish) and hand the owner a
                 * silently broken snippet. The safety net is the manage_options
                 * check, the nonce, the parse in save(), and the runtime crash
                 * guard - never sanitising the source.
                 */
                echo esc_textarea((string) $s['code']);
            ?></textarea>

            <details class="cp-edit-details" <?php echo $has_details ? 'open' : ''; ?>>
                <summary><?php esc_html_e('Details — description, where it runs, priority, tags', 'claudpress'); ?></summary>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cp-desc"><?php esc_html_e('Description', 'claudpress'); ?></label></th>
                        <td>
                            <textarea name="description" id="cp-desc" rows="2" class="large-text"><?php echo esc_textarea((string) $s['description']); ?></textarea>
                            <p class="description"><?php esc_html_e('Optional. Write it for whoever finds this in six months.', 'claudpress'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cp-location"><?php esc_html_e('Runs where', 'claudpress'); ?></label></th>
                        <td>
                            <select name="location" id="cp-location">
                                <?php foreach (self::locations() as $v => $l) : ?>
                                    <option value="<?php echo esc_attr($v); ?>" <?php selected($s['location'], $v); ?>><?php echo esc_html($l); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cp-priority"><?php esc_html_e('Priority', 'claudpress'); ?></label></th>
                        <td>
                            <input name="priority" id="cp-priority" type="number" class="small-text" value="<?php echo esc_attr((string) $s['priority']); ?>">
                            <p class="description"><?php esc_html_e('Lower runs first. Leave at 10 unless one snippet has to beat another.', 'claudpress'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cp-tags"><?php esc_html_e('Tags', 'claudpress'); ?></label></th>
                        <td><input name="tags" id="cp-tags" type="text" class="regular-text"
                                   value="<?php echo esc_attr(implode(', ', Claudpress_Snippets::tags_array($s['tags']))); ?>"
                                   placeholder="<?php esc_attr_e('checkout, seo', 'claudpress'); ?>"></td>
                    </tr>
                </table>
            </details>

            <div id="cp-ai-report"><?php self::review_panel($s); ?></div>

            <?php
            /*
             * SAVE IS THE PRIMARY BUTTON, and it used not to be.
             *
             * The loudest button on this screen was "Save & switch on" - the one
             * that changes what code is running on a live site - while the
             * common action, editing something already live, was the quiet grey
             * one. That is backwards.
             *
             * "Save & switch on" is offered only while the snippet is off.
             * Offering to switch on something already on is noise.
             */
            ?>
            <p class="submit">
                <button type="submit" name="and_activate" value="<?php echo (int) $s['active'] === 1 ? '1' : '0'; ?>"
                        class="button button-primary"><?php esc_html_e('Save', 'claudpress'); ?></button>
                <?php if ((int) $s['active'] !== 1) : ?>
                    <button type="submit" name="and_activate" value="1" class="button"><?php esc_html_e('Save & switch on', 'claudpress'); ?></button>
                <?php endif; ?>
                <button type="button" class="button" id="cp-ask-ai"><?php esc_html_e('Ask AI what this does', 'claudpress'); ?></button>
                <a href="<?php echo esc_url(Claudpress_Studio::url('all')); ?>" class="button-link" style="margin-left:12px;"><?php esc_html_e('Cancel', 'claudpress'); ?></a>
            </p>
        </form>

        <?php if (!$is_new) : ?>
            <?php self::history($id); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Load the code editor properly.
     *
     * THE BUG THIS REPLACES. The editor was set up by an inline <script> printed
     * in the page body. Everything enqueued from a screen render callback prints
     * in the FOOTER, so that block ran as script 9 of 85 while
     * code-editor.min.js loaded as script 74. `wp.codeEditor` did not exist yet,
     * a truthy guard swallowed the failure, and every install shipped a plain
     * textarea where the source plainly said there was a CodeMirror editor. No
     * console error, and nothing for a grep or a PHP test to find.
     *
     * A real file with a declared dependency cannot have that bug: WordPress
     * orders the graph, so nobody has to remember to.
     *
     * wp_enqueue_code_editor() returns false when the person has switched syntax
     * highlighting off in their own profile. That is a preference, not a fault -
     * the textarea still works, the script still loads so the Ask AI button
     * keeps working, and no warning is shown.
     */
    private static function enqueue(string $type): void
    {
        $settings = wp_enqueue_code_editor([
            'type'       => self::mime($type),
            'codemirror' => [
                'lineNumbers' => true,
                'indentUnit'  => 4,
                'tabSize'     => 4,
            ],
        ]);

        $deps = ['jquery'];
        if (false !== $settings) {
            wp_enqueue_style('wp-codemirror');
            $deps[] = 'code-editor';
        }

        wp_enqueue_script(
            'claudpress-studio-edit',
            CLAUDPRESS_URL . 'assets/js/studio-edit.js',
            $deps,
            CLAUDPRESS_VERSION,
            true
        );

        // The name is load-bearing: tests/rig/ui/check-snippets.mjs asserts this
        // global exists and carries a mode, because its absence is exactly what
        // the old bug looked like from the outside.
        wp_add_inline_script(
            'claudpress-studio-edit',
            'window.cpStudioEditorSettings = ' . wp_json_encode($settings) . ';',
            'before'
        );

        wp_localize_script('claudpress-studio-edit', 'cpStudioEdit', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(Claudpress_Studio::NONCE),
            'modes'   => [
                'php'  => 'application/x-httpd-php',
                'css'  => 'text/css',
                'js'   => 'text/javascript',
                'html' => 'text/html',
            ],
            'i18n'    => [
                'askAi'               => __('Ask AI what this does', 'claudpress'),
                'asking'              => __('Asking…', 'claudpress'),
                'writeSomethingFirst' => __('Write or paste some code first.', 'claudpress'),
                'unreachable'         => __('The AI could not be reached.', 'claudpress'),
                'unreachableSafe'     => __('The AI could not be reached. Your code is untouched.', 'claudpress'),
                'editorFailed'        => __('The code editor could not start, so this is a plain text box. Your code is safe and saving still works, but there are no line numbers and no syntax colouring. Reload the page to try again.', 'claudpress'),
            ],
        ]);

        self::styles();
    }

    /**
     * The few rules the code-first layout needs.
     *
     * Printed rather than shipped as a stylesheet: under thirty lines, one
     * screen, and a separate file would be a second request and a second thing
     * to keep in step with this template.
     */
    private static function styles(): void
    {
        wp_register_style('claudpress-studio-edit', false, [], CLAUDPRESS_VERSION);
        wp_enqueue_style('claudpress-studio-edit');
        wp_add_inline_style('claudpress-studio-edit', '
            .cp-edit-head { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 18px; margin: 12px 0 10px; }
            .cp-edit-field { margin: 0; display: flex; flex-direction: column; gap: 4px; }
            .cp-edit-field label { font-weight: 600; }
            .cp-edit-name { flex: 1 1 320px; }
            .cp-edit-name input { width: 100%; }
            .cp-edit-state { align-self: flex-end; padding-bottom: 4px; }
            .cp-state { font-weight: 600; }
            .cp-state-on { color: #00794b; }
            .cp-state-off { color: #777; }
            .cp-edit-details { margin: 14px 0; border: 1px solid #dcdcde; border-radius: 4px; background: #fff; }
            .cp-edit-details > summary { cursor: pointer; padding: 10px 14px; font-weight: 600; }
            .cp-edit-details[open] > summary { border-bottom: 1px solid #dcdcde; }
            .cp-edit-details .form-table { margin: 0 14px 8px; }
            #cp-code, .CodeMirror { border: 1px solid #8c8f94; border-radius: 4px; }
            .CodeMirror { height: 340px; }
        ');
    }

    private static function error_notice(string $error): void
    {
        if (trim($error) === '') {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e('This snippet is switched off because of an error:', 'claudpress'); ?></strong></p>
            <p><code><?php echo esc_html($error); ?></code></p>
            <p><?php esc_html_e('Fix the code and save again. It stays off until it parses.', 'claudpress'); ?></p>
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
            'safe'    => ['#00794b', __('safe', 'claudpress')],
            'caution' => ['#8c5e00', __('caution', 'claudpress')],
            'unsafe'  => ['#d63638', __('unsafe', 'claudpress')],
        ];
        $v = (string) ($review['verdict'] ?? 'caution');
        [$colour, $label] = $verdicts[$v] ?? $verdicts['caution'];
        ?>
        <div class="card" style="max-width:none;padding:14px 18px;">
            <p style="margin-top:0;">
                <strong><?php esc_html_e('Syntax check', 'claudpress'); ?>:</strong>
                <?php if (trim((string) ($s['last_error'] ?? '')) === '') : ?>
                    <span style="color:#00794b;"><?php esc_html_e('passed', 'claudpress'); ?></span>
                <?php else : ?>
                    <span style="color:#d63638;"><?php esc_html_e('failed', 'claudpress'); ?></span>
                <?php endif; ?>
                <span style="color:#666;">— <?php esc_html_e('a real parse. This is what decides whether the snippet may run.', 'claudpress'); ?></span>
            </p>
            <p>
                <strong><?php esc_html_e('AI assessment', 'claudpress'); ?>:</strong>
                <span style="color:<?php echo esc_attr($colour); ?>;"><?php echo esc_html($label); ?></span>
                <span style="color:#666;">— <?php esc_html_e('an opinion. It does not gate anything.', 'claudpress'); ?></span>
            </p>

            <?php if (!empty($review['summary'])) : ?>
                <p><?php echo esc_html((string) $review['summary']); ?></p>
            <?php endif; ?>
            <?php if (!empty($review['site_impact'])) : ?>
                <p><strong><?php esc_html_e('Effect on the site', 'claudpress'); ?>:</strong> <?php echo esc_html((string) $review['site_impact']); ?></p>
            <?php endif; ?>
            <?php if (!empty($review['risks']) && is_array($review['risks'])) : ?>
                <p style="margin-bottom:4px;"><strong><?php esc_html_e('Watch out for', 'claudpress'); ?>:</strong></p>
                <ul style="margin:0 0 8px 18px;list-style:disc;">
                    <?php foreach ($review['risks'] as $risk) : ?>
                        <li><?php echo esc_html((string) $risk); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($review['notes'])) : ?>
                <p><strong><?php esc_html_e('After switching it on', 'claudpress'); ?>:</strong> <?php echo esc_html((string) $review['notes']); ?></p>
            <?php endif; ?>

            <?php if (!empty($s['ai_review_at'])) : ?>
                <p style="color:#777;font-size:12px;margin-bottom:0;">
                    <?php
                    /* translators: %s: how long ago, e.g. "3 hours". */
                    echo esc_html(sprintf(__('Reviewed %s ago. If you have edited the code since, ask again.', 'claudpress'),
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
        <h3><?php esc_html_e('History', 'claudpress'); ?></h3>
        <p class="description"><?php esc_html_e('What this snippet looked like before each change. Recorded on every edit, by anyone.', 'claudpress'); ?></p>
        <table class="wp-list-table widefat striped">
            <thead><tr>
                <th><?php esc_html_e('When', 'claudpress'); ?></th>
                <th><?php esc_html_e('By', 'claudpress'); ?></th>
                <th><?php esc_html_e('Note', 'claudpress'); ?></th>
                <th style="width:110px;"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($revisions as $rev) : ?>
                <tr>
                    <td><?php echo esc_html((string) $rev['created_at']); ?></td>
                    <td><?php echo esc_html($rev['author_type'] === 'agent' ? __('the agent', 'claudpress') : self::user_name((int) $rev['user_id'])); ?></td>
                    <td><?php echo esc_html((string) $rev['note']); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                              onsubmit="return confirm('<?php echo esc_js(__('Restore this version? The current code is saved to history first, and the snippet is switched off until you review it.', 'claudpress')); ?>');">
                            <?php wp_nonce_field(Claudpress_Studio::NONCE); ?>
                            <input type="hidden" name="action" value="claudpress_studio">
                            <input type="hidden" name="studio_action" value="restore">
                            <input type="hidden" name="snippet_id" value="<?php echo esc_attr((string) $id); ?>">
                            <input type="hidden" name="revision_id" value="<?php echo esc_attr((string) $rev['id']); ?>">
                            <button class="button button-small"><?php esc_html_e('Restore', 'claudpress'); ?></button>
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
            return __('unknown', 'claudpress');
        }
        $user = get_userdata($user_id);
        return $user ? $user->user_login : __('a deleted user', 'claudpress');
    }

    private static function locations(): array
    {
        return [
            'everywhere'     => __('Everywhere', 'claudpress'),
            'frontend_only'  => __('Front end only', 'claudpress'),
            'admin_only'     => __('Admin only', 'claudpress'),
            'header'         => __('In the <head>', 'claudpress'),
            'footer'         => __('Before </body>', 'claudpress'),
            'before_content' => __('Before post content', 'claudpress'),
            'after_content'  => __('After post content', 'claudpress'),
            'shortcode'      => __('Only via shortcode', 'claudpress'),
            'page_template'  => __('As a page template', 'claudpress'),
        ];
    }

    private static function mime(string $type): string
    {
        $map = ['php' => 'application/x-httpd-php', 'css' => 'text/css', 'js' => 'text/javascript', 'html' => 'text/html'];
        return $map[$type] ?? 'text/plain';
    }
}
