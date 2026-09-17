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
 * conversation. Keelhob_Studio_Chat holds the rules; this file is the
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

final class Keelhob_Studio_Compose
{
    public static function render(): void
    {
        self::enqueue();

        $ready = Keelhob_Provider_Settings_Ready();
        ?>
        <div class="kh-chat-wrap">
            <div class="kh-chat-head">
                <h2><?php esc_html_e('Describe what you want', 'keelhob'); ?></h2>
                <button type="button" class="button-link kh-chat-clear" id="kh-chat-clear"><?php
                    esc_html_e('Clear chat', 'keelhob');
                ?></button>
            </div>

            <?php if (!$ready) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e('No AI provider is connected yet.', 'keelhob'); ?></strong></p>
                    <p><?php esc_html_e('Compose needs a provider key. Everything below still works once one is set — you are not missing a feature, only a connection.', 'keelhob'); ?></p>
                    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=keelhob-automation')); ?>"><?php esc_html_e('Connect a provider', 'keelhob'); ?></a></p>
                </div>
            <?php endif; ?>

            <div class="kh-chat" id="kh-chat" role="log" aria-live="polite" aria-label="<?php esc_attr_e('Conversation', 'keelhob'); ?>">
                <div class="kh-chat-empty" id="kh-chat-empty">
                    <p><strong><?php esc_html_e('Say what you want to happen, not how to code it.', 'keelhob'); ?></strong></p>
                    <p><?php esc_html_e('“Hide the price on out-of-stock products” is a better opener than “add a filter to woocommerce_get_price_html”.', 'keelhob'); ?></p>
                    <p><?php esc_html_e('You can keep talking afterwards — ask for changes, point out a mistake, or ask why it did something. It remembers the whole conversation.', 'keelhob'); ?></p>
                </div>
            </div>

            <div class="kh-chat-status" id="kh-chat-status" hidden></div>

            <div class="kh-chat-composer">
                <textarea id="kh-chat-input" rows="3" class="large-text"
                          placeholder="<?php esc_attr_e('e.g. Add a free-shipping banner to the cart page when the total is over 200.', 'keelhob'); ?>"></textarea>
                <div class="kh-chat-send-row">
                    <span class="description"><?php esc_html_e('Enter sends. Shift+Enter makes a new line.', 'keelhob'); ?></span>
                    <button type="button" class="button button-primary" id="kh-chat-send"><?php esc_html_e('Send', 'keelhob'); ?></button>
                </div>
            </div>

            <p class="description kh-chat-foot">
                <?php esc_html_e('Nothing here is saved until you click Save on a block, and it always saves switched OFF. Leaving this tab clears the conversation.', 'keelhob'); ?>
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
            'keelhob-studio',
            KEELHOB_URL . 'assets/css/studio.css',
            [],
            Keelhob::asset_version('assets/css/studio.css')
        );

        wp_enqueue_script(
            'keelhob-studio-compose',
            KEELHOB_URL . 'assets/js/studio-compose.js',
            [],
            Keelhob::asset_version('assets/js/studio-compose.js'),
            true
        );

        wp_localize_script('keelhob-studio-compose', 'cpStudioCompose', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(Keelhob_Studio::NONCE),
            'i18n'    => [
                'thinking'    => __('Thinking…', 'keelhob'),
                'fixing'      => __('That code did not parse. Asking it to fix that…', 'keelhob'),
                'typeFirst'   => __('Type something first.', 'keelhob'),
                'unreachable' => __('Could not reach the AI.', 'keelhob'),
                'you'         => __('You', 'keelhob'),
                'ai'          => __('AI', 'keelhob'),
                'site'        => __('Syntax check', 'keelhob'),
                'clearAsk'    => __('Clear this conversation? It cannot be brought back.', 'keelhob'),
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
     * @param array $segments from Keelhob_Studio_Chat::segments()
     */
    public static function message_html(array $segments): string
    {
        ob_start();
        ?>
        <div class="kh-msg kh-msg-ai">
            <div class="kh-msg-who"><?php esc_html_e('AI', 'keelhob'); ?></div>
            <div class="kh-msg-body">
                <?php foreach ($segments as $seg) : ?>
                    <?php if (($seg['kind'] ?? '') === 'text') : ?>
                        <div class="kh-msg-text"><?php echo nl2br(esc_html((string) $seg['text'])); ?></div>
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
        <div class="kh-code<?php echo $ok ? '' : ' kh-code-bad'; ?>">
            <div class="kh-code-head">
                <span class="kh-code-lang"><?php echo esc_html($type); ?></span>
                <?php if ($ok) : ?>
                    <span class="kh-lint kh-lint-ok"><?php esc_html_e('syntax check passed', 'keelhob'); ?></span>
                <?php else : ?>
                    <span class="kh-lint kh-lint-bad"><?php esc_html_e('syntax check failed', 'keelhob'); ?></span>
                <?php endif; ?>
            </div>

            <pre class="kh-code-body"><code><?php echo esc_html($code); ?></code></pre>

            <?php if (!$ok) : ?>
                <p class="kh-lint-detail"><code><?php echo esc_html($error); ?></code></p>
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
                    <button type="button" class="button kh-chat-fix"
                            data-error="<?php echo esc_attr($error); ?>"
                            data-type="<?php echo esc_attr($type); ?>"><?php
                        esc_html_e('Ask it to fix this', 'keelhob');
                    ?></button>
                </p>
            <?php endif; ?>

            <form class="kh-code-save" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(Keelhob_Studio::NONCE); ?>
                <input type="hidden" name="action" value="keelhob_studio">
                <input type="hidden" name="studio_action" value="save_composed">
                <input type="hidden" name="code" value="<?php echo esc_attr($code); ?>">
                <input type="hidden" name="snippet_type" value="<?php echo esc_attr($type); ?>">
                <input type="hidden" name="ai_prompt" value="" class="kh-code-prompt">
                <input type="text" name="name" class="regular-text" required
                       placeholder="<?php esc_attr_e('Name this snippet', 'keelhob'); ?>">
                <button class="button"><?php
                    echo $ok
                        ? esc_html__('Save it (switched off)', 'keelhob')
                        : esc_html__('Save anyway (stays switched off)', 'keelhob');
                ?></button>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

/** Small helper so the template does not have to know how providers are configured. */
function Keelhob_Provider_Settings_Ready(): bool
{
    $s = Keelhob::instance()->settings();
    return trim((string) ($s['agent_api_key'] ?? '')) !== '';
}
