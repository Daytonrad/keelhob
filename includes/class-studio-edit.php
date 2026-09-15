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
 *
 * ---------------------------------------------------------------------------
 * THE LAYOUT, AND WHY IT CHANGED AGAIN IN 1.2.0
 *
 * Before this, everything was one column: name, a bare textarea, a collapsed
 * <details> holding five settings, then the buttons, then the history table
 * right at the bottom. Every one of those pieces existed and worked. The
 * screen still read as "a form with a big box in it" rather than as an editor,
 * and two real features were effectively invisible:
 *
 *   - Revisions were rendered, but below the fold, after the submit buttons, on
 *     a screen most people never scrolled. Somebody asked for revisions to be
 *     ADDED to a plugin that already had them.
 *   - Conditions had an engine and no UI at all, so only an agent could set
 *     them.
 *
 * So: two columns. The code, which is what you came for, gets the whole left
 * side with a proper editor frame around it — a coloured language dot, a
 * filename, a language pill. Everything that is a SETTING rather than the work
 * moves to a narrow right-hand rail, in labelled panels, always open. Nothing
 * that is switched on is ever hidden behind a disclosure triangle.
 *
 * The revisions panel and the conditions builder are both first-class here.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Studio_Edit
{
    /** File extension per snippet type, for the editor's filename chip. */
    private const EXT = ['php' => 'php', 'css' => 'css', 'js' => 'js', 'html' => 'html'];

    public static function render(): void
    {
        $id      = isset($_GET['snippet']) ? (int) $_GET['snippet'] : 0;
        $snippet = $id ? Claudpress_Snippets::get($id) : null;
        $is_new  = !$snippet;

        $defaults = [
            'name' => '', 'description' => '', 'code' => '', 'snippet_type' => 'php',
            'location' => 'everywhere', 'priority' => 10, 'tags' => '', 'active' => 0,
            'source' => 'manual', 'last_error' => '', 'ai_review' => '', 'ai_review_at' => null,
            'conditions_json' => null,
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

        $type = (string) $s['snippet_type'];
        $cond = Claudpress_Snippet_Conditions::normalize($s['conditions_json']);

        self::enqueue($type, $cond);
        ?>
        <h2 class="cp-edit-title">
            <?php echo $is_new ? esc_html__('Add Snippet', 'claudpress') : esc_html__('Edit Snippet', 'claudpress'); ?>
        </h2>

        <?php self::error_notice((string) $s['last_error']); ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="cp-studio-form">
            <?php wp_nonce_field(Claudpress_Studio::NONCE); ?>
            <input type="hidden" name="action" value="claudpress_studio">
            <input type="hidden" name="studio_action" value="save">
            <input type="hidden" name="snippet_id" value="<?php echo esc_attr((string) $id); ?>">
            <input type="hidden" name="source" value="<?php echo esc_attr((string) $s['source']); ?>">

            <div class="cp-grid">
                <div class="cp-main">
                    <?php
                    /*
                     * `required`, so the browser stops the submit and points at
                     * this box. Without it an untitled row lands in the list and
                     * is then genuinely hard to find again.
                     */
                    ?>
                    <label class="screen-reader-text" for="cp-name"><?php esc_html_e('Snippet name', 'claudpress'); ?></label>
                    <input name="name" id="cp-name" type="text" class="cp-title-input" required
                           value="<?php echo esc_attr((string) $s['name']); ?>"
                           placeholder="<?php esc_attr_e('Snippet name — required', 'claudpress'); ?>">

                    <div class="cp-editor-shell">
                        <div class="cp-editor-bar cp-lang-<?php echo esc_attr($type); ?>" id="cp-editor-bar">
                            <span class="cp-lang-dot" aria-hidden="true"></span>
                            <span class="cp-file-name" id="cp-file-name">snippet.<?php echo esc_html(self::EXT[$type] ?? 'txt'); ?></span>
                            <span class="cp-lang-pill" id="cp-lang-pill"><?php echo esc_html(strtoupper($type)); ?></span>
                            <button type="button" class="cp-ask-btn" id="cp-ask-ai">
                                <?php esc_html_e('Ask AI what this does', 'claudpress'); ?>
                            </button>
                        </div>

                        <div id="cp-editor-warning" hidden></div>

                        <label class="screen-reader-text" for="cp-code"><?php esc_html_e('Code', 'claudpress'); ?></label>
                        <textarea name="code" id="cp-code" rows="18" class="cp-code"
                                  spellcheck="false"><?php
                            /*
                             * esc_textarea and nothing else. Do NOT add
                             * sanitize_textarea_field, wp_kses or any other filter
                             * here: they mangle valid code (&& becomes &amp;&amp;,
                             * angle brackets vanish) and hand the owner a silently
                             * broken snippet. The safety net is the manage_options
                             * check, the nonce, the parse in save(), and the runtime
                             * crash guard — never sanitising the source.
                             */
                            echo esc_textarea((string) $s['code']);
                        ?></textarea>
                    </div>

                    <p class="cp-hint" id="cp-hint"></p>

                    <div id="cp-ai-report"><?php self::review_panel($s); ?></div>

                    <?php
                    /*
                     * "You can also just ask."
                     *
                     * Carried over from the second Snippets screen that was deleted
                     * in 1.2.0 — the one genuinely good idea it had that this screen
                     * did not. It says out loud that the page and the agent are the
                     * same system, which is the thing people take longest to work
                     * out about this plugin.
                     *
                     * Only for a saved snippet. On a new one there is no number yet,
                     * and an id of 0 would be a worse hint than none.
                     */
                    ?>
                    <?php if ($id > 0) : ?>
                        <p class="cp-ask-hint">
                            <?php
                            printf(
                                /* translators: %s: the phrase to say to the agent, already quoted. */
                                esc_html__('You can also just ask your agent: %s', 'claudpress'),
                                '<code>' . esc_html(sprintf(
                                    /* translators: %d: snippet ID. */
                                    __('tell me about snippet %d', 'claudpress'),
                                    $id
                                )) . '</code>'
                            );
                            ?>
                        </p>
                    <?php endif; ?>

                    <label class="cp-field-label" for="cp-desc"><?php esc_html_e('Notes', 'claudpress'); ?></label>
                    <textarea name="description" id="cp-desc" rows="2" class="widefat"
                              placeholder="<?php esc_attr_e('What does this snippet do? Write it for whoever finds this in six months. (optional)', 'claudpress'); ?>"><?php echo esc_textarea((string) $s['description']); ?></textarea>

                    <?php self::conditions_block($cond); ?>
                </div>

                <div class="cp-side">
                    <?php
                    self::panel_save($s);
                    self::panel_behaviour($s, $id);
                    self::panel_revisions($id);
                    ?>
                </div>
            </div>
        </form>

        <?php
        /*
         * The restore form lives OUTSIDE the editor form, because forms cannot
         * nest. Each Restore button in the sidebar points at it with the HTML5
         * `form` attribute and carries its own revision id as the submit
         * button's own value — which is submitted, so one form serves every row.
         */
        ?>
        <?php if (!$is_new) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  id="cp-restore-form" class="cp-restore-form"
                  onsubmit="return confirm('<?php echo esc_js(__('Restore this version? The current code is saved to history first, and the snippet is switched off until you review it.', 'claudpress')); ?>');">
                <?php wp_nonce_field(Claudpress_Studio::NONCE); ?>
                <input type="hidden" name="action" value="claudpress_studio">
                <input type="hidden" name="studio_action" value="restore">
                <input type="hidden" name="snippet_id" value="<?php echo esc_attr((string) $id); ?>">
            </form>
        <?php endif; ?>
        <?php
    }

    // ---- the right-hand rail ----------------------------------------------

    /**
     * Save.
     *
     * SAVE IS THE PRIMARY BUTTON, and it used not to be. The loudest button on
     * this screen was "Save & switch on" — the one that changes what code is
     * running on a live site — while the common action, editing something
     * already live, was the quiet grey one. That is backwards.
     *
     * "Save & switch on" is offered only while the snippet is off. Offering to
     * switch on something already on is noise.
     *
     * @param array<string, mixed> $s
     */
    private static function panel_save(array $s): void
    {
        $active  = (int) $s['active'] === 1;
        $errored = trim((string) $s['last_error']) !== '';
        ?>
        <div class="cp-panel">
            <h3><?php esc_html_e('Save', 'claudpress'); ?></h3>
            <p class="cp-status-line">
                <?php esc_html_e('Status:', 'claudpress'); ?>
                <?php if ($errored) : ?>
                    <span class="cp-status cp-status-error"><?php esc_html_e('Errored', 'claudpress'); ?></span>
                <?php elseif ($active) : ?>
                    <span class="cp-status cp-status-on"><?php esc_html_e('Active', 'claudpress'); ?></span>
                <?php else : ?>
                    <span class="cp-status cp-status-off"><?php esc_html_e('Inactive', 'claudpress'); ?></span>
                <?php endif; ?>
            </p>

            <div class="cp-save-buttons">
                <button type="submit" name="and_activate" value="<?php echo $active ? '1' : '0'; ?>"
                        class="button button-primary"><?php esc_html_e('Save', 'claudpress'); ?></button>
                <?php if (!$active) : ?>
                    <button type="submit" name="and_activate" value="1"
                            class="button"><?php esc_html_e('Save & switch on', 'claudpress'); ?></button>
                <?php endif; ?>
            </div>

            <p class="cp-microcopy">
                <?php esc_html_e('PHP is parsed before it can go active. If a live snippet ever crashes the site, Claudpress switches it off by itself.', 'claudpress'); ?>
            </p>
            <p class="cp-microcopy">
                <a href="<?php echo esc_url(Claudpress_Studio::url('all')); ?>"><?php esc_html_e('Back to all snippets', 'claudpress'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Behaviour: the five settings that used to be behind a disclosure triangle.
     *
     * @param array<string, mixed> $s
     */
    private static function panel_behaviour(array $s, int $id): void
    {
        ?>
        <div class="cp-panel">
            <h3><?php esc_html_e('Behaviour', 'claudpress'); ?></h3>

            <label class="cp-field-label" for="cp-type"><?php esc_html_e('Type', 'claudpress'); ?></label>
            <select name="snippet_type" id="cp-type" class="widefat">
                <?php foreach (['php' => 'PHP', 'css' => 'CSS', 'js' => 'JavaScript', 'html' => 'HTML'] as $v => $l) : ?>
                    <option value="<?php echo esc_attr($v); ?>" <?php selected($s['snippet_type'], $v); ?>><?php echo esc_html($l); ?></option>
                <?php endforeach; ?>
            </select>

            <label class="cp-field-label" for="cp-location"><?php esc_html_e('Where it runs', 'claudpress'); ?></label>
            <select name="location" id="cp-location" class="widefat">
                <?php foreach (self::locations() as $v => $l) : ?>
                    <option value="<?php echo esc_attr($v); ?>" <?php selected($s['location'], $v); ?>><?php echo esc_html($l); ?></option>
                <?php endforeach; ?>
            </select>

            <label class="cp-field-label" for="cp-priority"><?php esc_html_e('Priority', 'claudpress'); ?></label>
            <input name="priority" id="cp-priority" type="number" class="small-text"
                   value="<?php echo esc_attr((string) $s['priority']); ?>">
            <span class="cp-microcopy"><?php esc_html_e('Lower runs first.', 'claudpress'); ?></span>

            <label class="cp-field-label" for="cp-tags"><?php esc_html_e('Tags', 'claudpress'); ?></label>
            <input name="tags" id="cp-tags" type="text" class="widefat"
                   value="<?php echo esc_attr(implode(', ', Claudpress_Snippets::tags_array($s['tags']))); ?>"
                   placeholder="<?php esc_attr_e('checkout, seo', 'claudpress'); ?>">

            <?php if ((string) $s['location'] === 'shortcode' && $id) : ?>
                <p class="cp-microcopy">
                    <?php esc_html_e('Shortcode:', 'claudpress'); ?>
                    <code>[claudpress_snippet id="<?php echo (int) $id; ?>"]</code>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Revisions.
     *
     * This existed before 1.2.0 and was rendered below the submit buttons, at
     * the very bottom of a long single-column page. It was reported as a missing
     * feature by somebody using the plugin, which is as clear a verdict on where
     * it was as anyone could give.
     */
    private static function panel_revisions(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $revisions = Claudpress_Snippets::revisions($id, 10);
        if (!$revisions) {
            return;
        }
        ?>
        <div class="cp-panel">
            <h3><?php esc_html_e('Revisions', 'claudpress'); ?></h3>
            <ul class="cp-revisions">
                <?php foreach ($revisions as $rev) : ?>
                    <li>
                        <span class="cp-rev-note"><?php echo esc_html((string) $rev['note']); ?></span>
                        <span class="cp-rev-meta">
                            <?php
                            echo esc_html((string) $rev['created_at']);
                            echo ' · ';
                            echo esc_html($rev['author_type'] === 'agent'
                                ? __('the agent', 'claudpress')
                                : self::user_name((int) $rev['user_id']));
                            ?>
                        </span>
                        <button type="submit" form="cp-restore-form" name="revision_id"
                                value="<?php echo esc_attr((string) $rev['id']); ?>"
                                class="button button-small cp-rev-restore"><?php esc_html_e('Restore', 'claudpress'); ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="cp-microcopy">
                <?php esc_html_e('What this snippet looked like before each change. Recorded on every edit, by anyone — you or the agent.', 'claudpress'); ?>
            </p>
        </div>
        <?php
    }

    // ---- conditions --------------------------------------------------------

    /**
     * The rule builder.
     *
     * The rows themselves are drawn by JavaScript from the config handed over in
     * `cpStudioEdit.conditions`, because a row's third field depends on its
     * first: choosing "User role" needs a dropdown of roles, "URL path" needs a
     * text box. Rendering that server-side would mean printing every possible
     * third field for every row and hiding all but one.
     *
     * What is NOT left to JavaScript is the saved state: it is serialised into
     * the page so a reload shows exactly what is stored, and so the block can be
     * opened already populated rather than flashing empty first.
     *
     * @param array{enabled: bool, relation: string, rules: array<int, array<string, mixed>>} $cond
     */
    private static function conditions_block(array $cond): void
    {
        ?>
        <div class="cp-conditions" id="cp-conditions">
            <label class="cp-cond-enable">
                <input type="checkbox" name="conditions_enabled" id="cp-cond-enabled" value="1"
                       <?php checked($cond['enabled']); ?>>
                <span><?php esc_html_e('Smart conditional logic — only run when these rules match', 'claudpress'); ?></span>
            </label>

            <div class="cp-cond-body" id="cp-cond-body" <?php echo $cond['enabled'] ? '' : 'hidden'; ?>>
                <p class="cp-cond-relation">
                    <?php esc_html_e('Match', 'claudpress'); ?>
                    <select name="conditions_relation" id="cp-cond-relation">
                        <option value="AND" <?php selected($cond['relation'], 'AND'); ?>><?php esc_html_e('ALL', 'claudpress'); ?></option>
                        <option value="OR" <?php selected($cond['relation'], 'OR'); ?>><?php esc_html_e('ANY', 'claudpress'); ?></option>
                    </select>
                    <?php esc_html_e('of the rules below:', 'claudpress'); ?>
                </p>

                <div id="cp-cond-rules" class="cp-cond-rules"></div>

                <button type="button" class="button" id="cp-cond-add">
                    + <?php esc_html_e('Add rule', 'claudpress'); ?>
                </button>

                <p class="cp-microcopy">
                    <?php esc_html_e('A rule can only be judged once WordPress knows what page this is, so a PHP snippet with conditions runs slightly later in the request than one without.', 'claudpress'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    // ---- assets ------------------------------------------------------------

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
     *
     * @param array{enabled: bool, relation: string, rules: array<int, array<string, mixed>>} $cond
     */
    private static function enqueue(string $type, array $cond): void
    {
        $settings = wp_enqueue_code_editor([
            'type'       => self::mime($type),
            'codemirror' => [
                'lineNumbers' => true,
                'indentUnit'  => 4,
                'tabSize'     => 4,
            ],
        ]);

        /*
         * PHP SNIPPETS NEED text/x-php, NOT WordPress's default of 'php'.
         *
         * wp_get_code_editor_settings() maps application/x-httpd-php to
         * mode 'php', which is CodeMirror's HTML-with-embedded-PHP mode:
         * everything before a `<?php` is treated as HTML. Snippet code is
         * stored and eval'd WITHOUT an opening tag, so an entire snippet came
         * out as unhighlighted plain text.
         *
         * Measured, not assumed: zero syntax-coloured tokens on a page whose
         * editor was otherwise working correctly. That is exactly the failure
         * the previous editor bug had, one layer further in.
         *
         * Overridden here rather than passed in, because the type branch inside
         * wp_get_code_editor_settings() array_merges over anything we send. The
         * rest of WordPress's settings - matchBrackets, autoCloseTags, the lint
         * hooks - are kept.
         */
        if (is_array($settings) && $type === 'php') {
            $settings['codemirror']['mode'] = 'text/x-php';
        }

        $deps = ['jquery'];
        if (false !== $settings) {
            wp_enqueue_style('wp-codemirror');
            $deps[] = 'code-editor';
        }

        Claudpress_Studio::enqueue_styles();

        wp_enqueue_script(
            'claudpress-studio-edit',
            CLAUDPRESS_URL . 'assets/js/studio-edit.js',
            $deps,
            Claudpress::asset_version('assets/js/studio-edit.js'),
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
                // text/x-php, NOT application/x-httpd-php.
                //
                // Snippet code is stored and eval'd WITHOUT an opening tag, and
                // the httpd mode treats everything before a `<?php` as HTML -
                // so a whole snippet highlighted as plain text. Measured: zero
                // syntax-coloured tokens on a page whose editor was otherwise
                // working perfectly.
                'php'  => 'text/x-php',
                'css'  => 'text/css',
                'js'   => 'text/javascript',
                'html' => 'text/html',
            ],
            'ext'     => self::EXT,
            // Everything the rule builder draws itself from, plus what is stored.
            'conditions' => [
                'config' => Claudpress_Snippet_Conditions::ui_config(),
                'rules'  => $cond['rules'],
            ],
            'i18n'    => [
                'askAi'               => __('Ask AI what this does', 'claudpress'),
                'asking'              => __('Asking…', 'claudpress'),
                'writeSomethingFirst' => __('Write or paste some code first.', 'claudpress'),
                'unreachable'         => __('The AI could not be reached.', 'claudpress'),
                'unreachableSafe'     => __('The AI could not be reached. Your code is untouched.', 'claudpress'),
                'editorFailed'        => __('The code editor could not start, so this is a plain text box. Your code is safe and saving still works, but there are no line numbers and no syntax colouring. Reload the page to try again.', 'claudpress'),
                'remove'              => __('Remove', 'claudpress'),
                'removeRule'          => __('Remove this rule', 'claudpress'),
                'valuePlaceholder'    => __('value', 'claudpress'),
                'noRules'             => __('No rules yet. The snippet runs everywhere until you add one.', 'claudpress'),
            ],
        ]);
    }

    // ---- the rest ----------------------------------------------------------

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
     *
     * @param array<string, mixed> $s
     */
    public static function review_panel(array $s): void
    {
        $review = json_decode((string) ($s['ai_review'] ?? ''), true);
        if (!is_array($review)) {
            return;
        }
        $verdicts = [
            'safe'    => ['cp-verdict-safe', __('safe', 'claudpress')],
            'caution' => ['cp-verdict-caution', __('caution', 'claudpress')],
            'unsafe'  => ['cp-verdict-unsafe', __('unsafe', 'claudpress')],
        ];
        $v = (string) ($review['verdict'] ?? 'caution');
        [$class, $label] = $verdicts[$v] ?? $verdicts['caution'];
        ?>
        <div class="cp-review">
            <p class="cp-review-line">
                <strong><?php esc_html_e('Syntax check', 'claudpress'); ?>:</strong>
                <?php if (trim((string) ($s['last_error'] ?? '')) === '') : ?>
                    <span class="cp-verdict-safe"><?php esc_html_e('passed', 'claudpress'); ?></span>
                <?php else : ?>
                    <span class="cp-verdict-unsafe"><?php esc_html_e('failed', 'claudpress'); ?></span>
                <?php endif; ?>
                <span class="cp-review-aside"><?php esc_html_e('a real parse. This is what decides whether the snippet may run.', 'claudpress'); ?></span>
            </p>
            <p class="cp-review-line">
                <strong><?php esc_html_e('AI assessment', 'claudpress'); ?>:</strong>
                <span class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></span>
                <span class="cp-review-aside"><?php esc_html_e('an opinion. It does not gate anything.', 'claudpress'); ?></span>
            </p>

            <?php if (!empty($review['summary'])) : ?>
                <p><?php echo esc_html((string) $review['summary']); ?></p>
            <?php endif; ?>
            <?php if (!empty($review['site_impact'])) : ?>
                <p><strong><?php esc_html_e('Effect on the site', 'claudpress'); ?>:</strong> <?php echo esc_html((string) $review['site_impact']); ?></p>
            <?php endif; ?>
            <?php if (!empty($review['risks']) && is_array($review['risks'])) : ?>
                <p class="cp-review-risks-head"><strong><?php esc_html_e('Watch out for', 'claudpress'); ?>:</strong></p>
                <ul class="cp-review-risks">
                    <?php foreach ($review['risks'] as $risk) : ?>
                        <li><?php echo esc_html((string) $risk); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($review['notes'])) : ?>
                <p><strong><?php esc_html_e('After switching it on', 'claudpress'); ?>:</strong> <?php echo esc_html((string) $review['notes']); ?></p>
            <?php endif; ?>

            <?php if (!empty($s['ai_review_at'])) : ?>
                <p class="cp-microcopy">
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

    private static function user_name(int $user_id): string
    {
        if ($user_id <= 0) {
            return __('unknown', 'claudpress');
        }
        $user = get_userdata($user_id);
        return $user ? $user->user_login : __('a deleted user', 'claudpress');
    }

    /** @return array<string, string> */
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
