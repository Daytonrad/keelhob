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

final class Claudpress_Pro_Studio_Compose
{
    public static function render(): void
    {
        $ready = Claudpress_Provider_Settings_Ready();
        ?>
        <h2><?php esc_html_e('Describe what you want', 'claudpress-pro'); ?></h2>

        <?php if (!$ready) : ?>
            <div class="notice notice-warning">
                <p><strong><?php esc_html_e('No AI provider is connected yet.', 'claudpress-pro'); ?></strong></p>
                <p><?php esc_html_e('Compose needs a provider key. Everything below still works once one is set — you are not missing a feature, only a connection.', 'claudpress-pro'); ?></p>
                <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=claudpress-automation')); ?>"><?php esc_html_e('Connect a provider', 'claudpress-pro'); ?></a></p>
            </div>
        <?php endif; ?>

        <p class="description" style="max-width:640px;">
            <?php esc_html_e('Say what you want to happen, not how to code it. "Hide the price on out-of-stock products" is a better prompt than "add a filter to woocommerce_get_price_html".', 'claudpress-pro'); ?>
        </p>

        <textarea id="cp-compose-prompt" rows="4" class="large-text" style="max-width:640px;"
                  placeholder="<?php esc_attr_e('e.g. Add a free-shipping banner to the cart page when the total is over 200.', 'claudpress-pro'); ?>"></textarea>

        <p>
            <button type="button" class="button button-primary" id="cp-compose-plan"><?php esc_html_e('Plan it', 'claudpress-pro'); ?></button>
            <span id="cp-compose-status" style="margin-left:10px;color:#666;"></span>
        </p>

        <div id="cp-compose-plan-box" style="display:none;margin-top:8px;"></div>
        <div id="cp-compose-code-box" style="display:none;margin-top:16px;"></div>

        <script>
        (function () {
            var nonce  = <?php echo wp_json_encode(wp_create_nonce(Claudpress_Pro_Studio::NONCE)); ?>;
            var status = document.getElementById('cp-compose-status');
            var planBox = document.getElementById('cp-compose-plan-box');
            var codeBox = document.getElementById('cp-compose-code-box');
            var approvedPlan = '';

            function post(action, fields) {
                var body = new FormData();
                body.append('action', action);
                body.append('nonce', nonce);
                Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
                return fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); });
            }

            function busy(msg) { status.textContent = msg; }

            document.getElementById('cp-compose-plan').addEventListener('click', function () {
                var prompt = document.getElementById('cp-compose-prompt').value.trim();
                if (!prompt) { alert(<?php echo wp_json_encode(__('Describe what you want first.', 'claudpress-pro')); ?>); return; }
                busy(<?php echo wp_json_encode(__('Thinking…', 'claudpress-pro')); ?>);
                codeBox.style.display = 'none';
                post('claudpress_studio_plan', { prompt: prompt }).then(function (json) {
                    busy('');
                    if (!json || !json.success) {
                        planBox.innerHTML = '<div class="notice notice-error inline"><p>' +
                            ((json && json.data && json.data.message) || 'Could not reach the AI.') + '</p></div>';
                        planBox.style.display = 'block';
                        return;
                    }
                    approvedPlan = json.data.plan_text || '';
                    planBox.innerHTML = json.data.html;
                    planBox.style.display = 'block';
                    var build = document.getElementById('cp-compose-build');
                    if (build) {
                        build.addEventListener('click', function () {
                            busy(<?php echo wp_json_encode(__('Writing the code…', 'claudpress-pro')); ?>);
                            post('claudpress_studio_build', {
                                prompt: document.getElementById('cp-compose-prompt').value.trim(),
                                plan: approvedPlan
                            }).then(function (res) {
                                busy('');
                                codeBox.innerHTML = (res && res.success)
                                    ? res.data.html
                                    : '<div class="notice notice-error inline"><p>' +
                                      ((res && res.data && res.data.message) || 'Could not reach the AI.') + '</p></div>';
                                codeBox.style.display = 'block';
                            });
                        });
                    }
                });
            });
        }());
        </script>
        <?php
    }

    /** The plan, rendered for a human to approve or reject. */
    public static function plan_html(array $plan): string
    {
        ob_start();
        ?>
        <div class="card" style="max-width:none;padding:16px 20px;">
            <h3 style="margin-top:0;"><?php esc_html_e('Here is the plan', 'claudpress-pro'); ?></h3>
            <p><?php echo esc_html((string) ($plan['summary'] ?? '')); ?></p>

            <?php if (!empty($plan['steps']) && is_array($plan['steps'])) : ?>
                <ol style="margin-left:18px;">
                    <?php foreach ($plan['steps'] as $step) : ?>
                        <li><?php echo esc_html((string) $step); ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if (!empty($plan['risks']) && is_array($plan['risks'])) : ?>
                <p style="margin-bottom:4px;"><strong><?php esc_html_e('Worth knowing', 'claudpress-pro'); ?>:</strong></p>
                <ul style="margin:0 0 10px 18px;list-style:disc;">
                    <?php foreach ($plan['risks'] as $risk) : ?>
                        <li><?php echo esc_html((string) $risk); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p style="color:#666;"><?php esc_html_e('No code has been written and nothing has been saved. Read the plan, then decide.', 'claudpress-pro'); ?></p>
            <button type="button" class="button button-primary" id="cp-compose-build"><?php esc_html_e('Looks right — write the code', 'claudpress-pro'); ?></button>
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
            <h3 style="margin-top:0;"><?php esc_html_e('Proposed snippet', 'claudpress-pro'); ?></h3>

            <p>
                <strong><?php esc_html_e('Syntax check', 'claudpress-pro'); ?>:</strong>
                <?php if ($ok) : ?>
                    <span style="color:#00794b;"><?php esc_html_e('passed', 'claudpress-pro'); ?></span>
                <?php else : ?>
                    <span style="color:#d63638;"><?php esc_html_e('failed', 'claudpress-pro'); ?></span> — <code><?php echo esc_html($lint); ?></code>
                <?php endif; ?>
            </p>

            <textarea rows="14" class="large-text code" readonly style="font-family:monospace;"><?php echo esc_textarea($code); ?></textarea>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                <?php wp_nonce_field(Claudpress_Pro_Studio::NONCE); ?>
                <input type="hidden" name="action" value="claudpress_studio">
                <input type="hidden" name="studio_action" value="save_composed">
                <input type="hidden" name="code" value="<?php echo esc_attr($code); ?>">
                <input type="hidden" name="snippet_type" value="<?php echo esc_attr($type); ?>">
                <input type="hidden" name="ai_prompt" value="<?php echo esc_attr($prompt); ?>">
                <input type="text" name="name" class="regular-text" required
                       value="<?php echo esc_attr(wp_trim_words($prompt, 7, '')); ?>"
                       placeholder="<?php esc_attr_e('Name this snippet', 'claudpress-pro'); ?>">
                <button class="button button-primary"><?php esc_html_e('Save it (switched off)', 'claudpress-pro'); ?></button>
            </form>

            <p style="color:#666;margin-bottom:0;">
                <?php esc_html_e('It saves switched OFF. Open it, read it, then switch it on when you are happy — code you have not read should never be running on a live site.', 'claudpress-pro'); ?>
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
