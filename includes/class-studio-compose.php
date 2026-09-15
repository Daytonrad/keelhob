<?php
/**
 * Studio tab 3 — AI Compose, as a chat.
 *
 * WHAT THIS SCREEN USED TO BE. A three-step wizard: type a prompt, read a plan,
 * approve it, receive code, save it. Each step was a separate one-shot request
 * carrying a single message, so the model had no memory of the step before.
 *
 * That shape produced two failures a person could not get out of:
 *
 *   - The plan step demanded a JSON object and threw away anything else, so a
 *     model answering in ordinary sentences produced "The AI replied, but not
 *     in a form this screen could read" over a perfectly good answer.
 *   - A syntax error in the generated code was shown to the PERSON and never to
 *     the MODEL, because there was no next turn to put it in. The only ways out
 *     were to save broken code or start again from a blank prompt.
 *
 * Both are the same root cause, so both are fixed by the same change: make it a
 * conversation. Claudpress_Studio_Chat holds the rules; this file is the
 * screen, and it renders exactly two things — a transcript and a box to type in.
 *
 * WHAT DID NOT CHANGE, AND MUST NOT. Nothing is written to the snippets table
 * until a person clicks Save, and Save still stores the snippet switched OFF.
 * The chat itself is never persisted: no table, no option, no post meta. It
 * lives in the tab and dies with it, which is also why leaving the screen is a
 * real reset rather than a hidden draft waiting to surprise somebody.
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
        <div class="cp-chat-wrap">
            <div class="cp-chat-head">
                <h2><?php esc_html_e('Describe what you want', 'claudpress'); ?></h2>
                <button type="button" class="button-link cp-chat-clear" id="cp-chat-clear"><?php
                    esc_html_e('Clear chat', 'claudpress');
                ?></button>
            </div>

            <?php if (!$ready) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e('No AI provider is connected yet.', 'claudpress'); ?></strong></p>
                    <p><?php esc_html_e('Compose needs a provider key. Everything below still works once one is set — you are not missing a feature, only a connection.', 'claudpress'); ?></p>
                    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=claudpress-automation')); ?>"><?php esc_html_e('Connect a provider', 'claudpress'); ?></a></p>
                </div>
            <?php endif; ?>

            <div class="cp-chat" id="cp-chat" role="log" aria-live="polite" aria-label="<?php esc_attr_e('Conversation', 'claudpress'); ?>">
                <div class="cp-chat-empty" id="cp-chat-empty">
                    <p><strong><?php esc_html_e('Say what you want to happen, not how to code it.', 'claudpress'); ?></strong></p>
                    <p><?php esc_html_e('“Hide the price on out-of-stock products” is a better opener than “add a filter to woocommerce_get_price_html”.', 'claudpress'); ?></p>
                    <p><?php esc_html_e('You can keep talking afterwards — ask for changes, point out a mistake, or ask why it did something. It remembers the whole conversation.', 'claudpress'); ?></p>
                </div>
            </div>

            <div class="cp-chat-status" id="cp-chat-status" hidden></div>

            <div class="cp-chat-composer">
                <textarea id="cp-chat-input" rows="3" class="large-text"
                          placeholder="<?php esc_attr_e('e.g. Add a free-shipping banner to the cart page when the total is over 200.', 'claudpress'); ?>"></textarea>
                <div class="cp-chat-send-row">
                    <span class="description"><?php esc_html_e('Enter sends. Shift+Enter makes a new line.', 'claudpress'); ?></span>
                    <button type="button" class="button button-primary" id="cp-chat-send"><?php esc_html_e('Send', 'claudpress'); ?></button>
                </div>
            </div>

            <p class="description cp-chat-foot">
                <?php esc_html_e('Nothing here is saved until you click Save on a block, and it always saves switched OFF. Leaving this tab clears the conversation.', 'claudpress'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * The script, as a file.
     *
     * It was an inline <script> printed in the page body. That is the same
     * mistake that left the snippet editor without a code editor on every
     * install for as long as it existed: an inline block prints before
     * everything the render callback enqueued.
     */
    private static function enqueue(): void
    {
        wp_enqueue_style(
            'claudpress-studio',
            CLAUDPRESS_URL . 'assets/css/studio.css',
            [],
            CLAUDPRESS_VERSION
        );

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
                'thinking'    => __('Thinking…', 'claudpress'),
                'fixing'      => __('That code did not parse. Asking it to fix that…', 'claudpress'),
                'typeFirst'   => __('Type something first.', 'claudpress'),
                'unreachable' => __('Could not reach the AI.', 'claudpress'),
                'you'         => __('You', 'claudpress'),
                'ai'          => __('AI', 'claudpress'),
                'site'        => __('Syntax check', 'claudpress'),
                'clearAsk'    => __('Clear this conversation? It cannot be brought back.', 'claudpress'),
            ],
        ]);
    }

    /**
     * One assistant turn, rendered.
     *
     * Rendered on the SERVER, not assembled in JavaScript, for one reason that
     * matters more than tidiness: every piece of model output that reaches the
     * page goes through esc_html() here. A client-side renderer would have to
     * repeat that correctly in a second language, and the day it got it wrong,
     * a snippet containing a <script> tag would run in wp-admin.
     *
     * @param array $segments from Claudpress_Studio_Chat::segments()
     */
    public static function message_html(array $segments): string
    {
        ob_start();
        ?>
        <div class="cp-msg cp-msg-ai">
            <div class="cp-msg-who"><?php esc_html_e('AI', 'claudpress'); ?></div>
            <div class="cp-msg-body">
                <?php foreach ($segments as $seg) : ?>
                    <?php if (($seg['kind'] ?? '') === 'text') : ?>
                        <div class="cp-msg-text"><?php echo nl2br(esc_html((string) $seg['text'])); ?></div>
                    <?php else : ?>
                        <?php echo self::block_html($seg); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * One code block, with its parse result and its own Save.
     *
     * EVERY block gets a Save, not just the last one. A reply that sends a PHP
     * snippet and the CSS that styles it is two snippets, and a screen that can
     * only save one of them makes the person copy the other out by hand.
     *
     * @param array{type:string,lang:string,code:string,error?:string} $block
     */
    public static function block_html(array $block): string
    {
        $code  = (string) $block['code'];
        $type  = (string) $block['type'];
        $error = trim((string) ($block['error'] ?? ''));
        $ok    = $error === '';

        ob_start();
        ?>
        <div class="cp-code<?php echo $ok ? '' : ' cp-code-bad'; ?>">
            <div class="cp-code-head">
                <span class="cp-code-lang"><?php echo esc_html($type); ?></span>
                <?php if ($ok) : ?>
                    <span class="cp-lint cp-lint-ok"><?php esc_html_e('syntax check passed', 'claudpress'); ?></span>
                <?php else : ?>
                    <span class="cp-lint cp-lint-bad"><?php esc_html_e('syntax check failed', 'claudpress'); ?></span>
                <?php endif; ?>
            </div>

            <pre class="cp-code-body"><code><?php echo esc_html($code); ?></code></pre>

            <?php if (!$ok) : ?>
                <p class="cp-lint-detail"><code><?php echo esc_html($error); ?></code></p>
                <?php
                /*
                 * The button is rendered even when the automatic retry is about
                 * to fire, and hidden by the script in that moment.
                 *
                 * The retry runs ONCE. A loop that keeps resending a parse
                 * error spends real money on somebody's API key while looking
                 * like it is working, and two models in a row failing the same
                 * parse is a signal a person should see, not a thing to keep
                 * grinding at.
                 */
                ?>
                <p>
                    <button type="button" class="button cp-chat-fix"
                            data-error="<?php echo esc_attr($error); ?>"
                            data-type="<?php echo esc_attr($type); ?>"><?php
                        esc_html_e('Ask it to fix this', 'claudpress');
                    ?></button>
                </p>
            <?php endif; ?>

            <form class="cp-code-save" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(Claudpress_Studio::NONCE); ?>
                <input type="hidden" name="action" value="claudpress_studio">
                <input type="hidden" name="studio_action" value="save_composed">
                <input type="hidden" name="code" value="<?php echo esc_attr($code); ?>">
                <input type="hidden" name="snippet_type" value="<?php echo esc_attr($type); ?>">
                <input type="hidden" name="ai_prompt" value="" class="cp-code-prompt">
                <input type="text" name="name" class="regular-text" required
                       placeholder="<?php esc_attr_e('Name this snippet', 'claudpress'); ?>">
                <button class="button"><?php
                    echo $ok
                        ? esc_html__('Save it (switched off)', 'claudpress')
                        : esc_html__('Save anyway (stays switched off)', 'claudpress');
                ?></button>
            </form>
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
