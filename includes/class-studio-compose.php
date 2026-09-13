<?php
/**
 * Studio tab 3 — AI Compose.
 *
 * Describe an outcome, get a plan, approve it, get code, review it, save it.
 *
 * THE RULE THAT MUST NOT BE RELAXED: nothing is written to the snippets table
 * until the person clicks Save. Planning and building are pure. It is tempting
 * to auto-save a draft "for convenience", and the cost of that convenience is
 * that a screen which can generate PHP also silently fills someone's site with
 * rows they never asked for.
 *
 * The approve step between plan and build is not ceremony either. It is the one
 * moment where a person can read what the model intends in plain English and
 * stop it, before any code exists to be tempted by.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Studio_Compose
{
    public static function render(): void
    {
        self::enqueue();

        $ready = Claudpress_Provider_Settings_Ready();
        ?>
        <h2><?php esc_html_e('Describe what you want', 'claudpress'); ?></h2>

        <?php if (!$ready) : ?>
            <div class="notice notice-warning">
                <p><strong><?php esc_html_e('No AI provider is connected yet.', 'claudpress'); ?></strong></p>
                <p><?php esc_html_e('Compose needs a provider key. Everything below still works once one is set — you are not missing a feature, only a connection.', 'claudpress'); ?></p>
                <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=claudpress-automation')); ?>"><?php esc_html_e('Connect a provider', 'claudpress'); ?></a></p>
            </div>
        <?php endif; ?>

        <p class="description" style="max-width:640px;">
            <?php esc_html_e('Say what you want to happen, not how to code it. "Hide the price on out-of-stock products" is a better prompt than "add a filter to woocommerce_get_price_html".', 'claudpress'); ?>
        </p>

        <textarea id="cp-compose-prompt" rows="4" class="large-text" style="max-width:640px;"
                  placeholder="<?php esc_attr_e('e.g. Add a free-shipping banner to the cart page when the total is over 200.', 'claudpress'); ?>"></textarea>

        <p>
            <button type="button" class="button button-primary" id="cp-compose-plan"><?php esc_html_e('Plan it', 'claudpress'); ?></button>
            <span id="cp-compose-status" style="margin-left:10px;color:#666;"></span>
        </p>

        <div id="cp-compose-plan-box" hidden></div>
        <div id="cp-compose-code-box" hidden></div>
        <?php
    }

    /**
     * The script, as a file.
     *
     * It was an inline <script> printed in the page body. That is the same
     * mistake that left the snippet editor without a code editor on every
     * install for as long as it existed: an inline block prints before
     * everything the render callback enqueued. Nothing here depended on a
     * footer script yet, so it happened to work - it was one dependency away
     * from breaking in silence.
     */
    private static function enqueue(): void
    {
        wp_enqueue_script(
            'claudpress-studio-compose',
            CLAUDPRESS_URL . 'assets/js/studio-compose.js',
            [],
            CLAUDPRESS_VERSION,
            true
        );

        wp_localize_script('claudpress-studio-compose', 'cpStudioCompose', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(Claudpress_Studio::NONCE),
            'i18n'    => [
                'thinking'      => __('Thinking…', 'claudpress'),
                'writing'       => __('Writing the code…', 'claudpress'),
                'describeFirst' => __('Describe what you want first.', 'claudpress'),
                'unreachable'   => __('Could not reach the AI.', 'claudpress'),
            ],
        ]);
    }

    /** The plan, rendered for a human to approve or reject. */
    public static function plan_html(array $plan): string
    {
        ob_start();
        ?>
        <div class="card" style="max-width:none;padding:16px 20px;">
            <h3 style="margin-top:0;"><?php esc_html_e('Here is the plan', 'claudpress'); ?></h3>
            <p><?php echo esc_html((string) ($plan['summary'] ?? '')); ?></p>

            <?php if (!empty($plan['steps']) && is_array($plan['steps'])) : ?>
                <ol style="margin-left:18px;">
                    <?php foreach ($plan['steps'] as $step) : ?>
                        <li><?php echo esc_html((string) $step); ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if (!empty($plan['risks']) && is_array($plan['risks'])) : ?>
                <p style="margin-bottom:4px;"><strong><?php esc_html_e('Worth knowing', 'claudpress'); ?>:</strong></p>
                <ul style="margin:0 0 10px 18px;list-style:disc;">
                    <?php foreach ($plan['risks'] as $risk) : ?>
                        <li><?php echo esc_html((string) $risk); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p style="color:#666;"><?php esc_html_e('No code has been written and nothing has been saved. Read the plan, then decide.', 'claudpress'); ?></p>

            <?php
            /*
             * THREE ANSWERS, not one.
             *
             * This card used to offer only "write the code". If the plan was
             * wrong, the way out was to scroll 300px back up to the prompt, or
             * to reload the page - and in practice people reloaded. A screen
             * that can only say yes is not asking a question.
             *
             * Discard and Plan again cost nothing: no network call, no server
             * state, because nothing has been stored yet. That is exactly why
             * they can be offered freely.
             */
            ?>
            <button type="button" class="button button-primary" id="cp-compose-build"><?php esc_html_e('Looks right — write the code', 'claudpress'); ?></button>
            <button type="button" class="button" id="cp-compose-replan"><?php esc_html_e('Plan again with changes', 'claudpress'); ?></button>
            <button type="button" class="button-link" id="cp-compose-discard" style="margin-left:10px;color:#b32d2e;"><?php esc_html_e('Discard', 'claudpress'); ?></button>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * The built code, with its parse result, and a Save button that is the ONLY
     * thing on this screen that writes anything.
     */
    public static function code_html(string $code, string $type, string $lint, string $prompt): string
    {
        $ok = trim($lint) === '';
        ob_start();
        ?>
        <div class="card" style="max-width:none;padding:16px 20px;">
            <h3 style="margin-top:0;"><?php esc_html_e('Proposed snippet', 'claudpress'); ?></h3>

            <p>
                <strong><?php esc_html_e('Syntax check', 'claudpress'); ?>:</strong>
                <?php if ($ok) : ?>
                    <span style="color:#00794b;"><?php esc_html_e('passed', 'claudpress'); ?></span>
                <?php else : ?>
                    <span style="color:#d63638;"><?php esc_html_e('failed', 'claudpress'); ?></span> — <code><?php echo esc_html($lint); ?></code>
                <?php endif; ?>
            </p>

            <textarea rows="14" class="large-text code" readonly style="font-family:monospace;"><?php echo esc_textarea($code); ?></textarea>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field(Claudpress_Studio::NONCE); ?>
                <input type="hidden" name="action" value="claudpress_studio">
                <input type="hidden" name="studio_action" value="save_composed">
                <input type="hidden" name="code" value="<?php echo esc_attr($code); ?>">
                <input type="hidden" name="snippet_type" value="<?php echo esc_attr($type); ?>">
                <input type="hidden" name="ai_prompt" value="<?php echo esc_attr($prompt); ?>">
                <input type="text" name="name" class="regular-text" required
                       value="<?php echo esc_attr(wp_trim_words($prompt, 7, '')); ?>"
                       placeholder="<?php esc_attr_e('Name this snippet', 'claudpress'); ?>">

                <?php
                /*
                 * A FAILED PARSE GETS AN ACKNOWLEDGEMENT, NOT A REFUSAL.
                 *
                 * This card used to print "Syntax check: failed" and then offer
                 * exactly the same Save button it offers on success, as though
                 * it had not just said that. Incoherent, though never unsafe:
                 * save() parses again on the server and stores failing code
                 * switched off.
                 *
                 * Refusing outright would be worse. The AI wrote it, the person
                 * may want to fix one missing semicolon by hand, and throwing
                 * the work away to protect them from an error they can already
                 * read is patronising. So: say it plainly, make them tick a box,
                 * then get out of the way.
                 */
                ?>
                <?php if (!$ok) : ?>
                    <p style="margin:10px 0 6px;">
                        <label>
                            <input type="checkbox" id="cp-compose-ack">
                            <?php esc_html_e('I have read the error and want to keep this code.', 'claudpress'); ?>
                        </label>
                    </p>
                <?php endif; ?>

                <button id="cp-compose-save" class="button button-primary" <?php disabled(!$ok); ?>><?php
                    echo $ok
                        ? esc_html__('Save it (switched off)', 'claudpress')
                        : esc_html__('Save anyway (it will stay switched off)', 'claudpress');
                ?></button>
            </form>

            <p style="color:#666;margin-bottom:0;">
                <?php esc_html_e('It saves switched OFF. Open it, read it, then switch it on when you are happy — code you have not read should never be running on a live site.', 'claudpress'); ?>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

/** Small helper so the template does not have to know how providers are configured. */
function Claudpress_Provider_Settings_Ready(): bool
{
    $s = Claudpress::instance()->settings();
    return trim((string) ($s['agent_api_key'] ?? '')) !== '';
}
