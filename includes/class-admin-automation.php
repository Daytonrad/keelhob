<?php
/**
 * The Automation screen — where the site is given a brain and a way to be
 * reached.
 *
 * It is the longest screen in the plugin because it is the only one that
 * configures something that acts on its own: which model thinks, which channel
 * carries the conversation, and which events are allowed to start one without
 * being asked. The Telegram and Events cards are the two halves of that and
 * live here rather than in their own files, because neither is reachable or
 * meaningful outside this page.
 *
 * STYLED FROM assets/css/admin.css. The screen is wrapped in
 * Keelhob_Admin::shell_open(), which carries the `.kh-ui` root the token layer
 * is scoped under, and the surfaces are `.kh-u-card` rather than core's
 * `.card`. Colours named inline are var(--kh-*) references, never literal
 * hexes, so they follow the dark variant instead of staying light inside a
 * dark panel.
 *
 * THE STATUS INDICATORS DO NOT CARRY THEIR MEANING IN COLOUR. --kh-good on
 * --kh-good-soft measures 3.12:1, --kh-mid on --kh-mid-soft 2.99:1 and
 * --kh-bad on --kh-bad-soft 4.41:1, all of which fail AA. So every pill here
 * is built the other way round: the tint is the fill, the status colour is the
 * dot or the border only, and the words are --kh-ink-2 (13.69:1 light,
 * 12.09:1 dark). The word itself always says which state it is.
 *
 * Prose was cut to one sub-line per heading, with the long "why" moved into
 * closed <details> panels. Nothing that says a thing cannot be undone, and
 * nothing in a safety notice, was shortened.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Admin_Automation
{
    /*
     * TABLE STYLING AS CLASS CONSTANTS, AND THE REASON THEY ARE NOT LOCALS.
     *
     * assets/css/admin.css belongs to the core-screens lane and is read-only
     * here, so a `kh-u-table` component cannot be added to it from this file.
     * Core's own list-table classes are the other option and they are worse:
     * they paint white and near-white unconditionally, which is a light table
     * inside a dark card the moment the panel goes dark. Recorded in
     * BUILD-NOTES.md as a component the token layer should grow.
     *
     * They started as `$t_*` locals in render_automation(), which was a real
     * bug rather than a tidiness problem: render_telegram_card() and
     * render_events_card() are separate methods and draw tables of their own, so
     * the locals were simply undefined there and those `style` attributes
     * rendered empty. PHPStan found it; nothing in the markup looked wrong.
     * Constants are visible to every method in the class and cannot drift apart.
     */
    private const T_TABLE = 'width:100%;border-collapse:collapse;';
    private const T_TH    = 'text-align:left;padding:8px 10px;border-bottom:1px solid var(--kh-line);'
                          . 'font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;'
                          . 'color:var(--kh-muted);';
    private const T_TD    = 'padding:9px 10px;border-bottom:1px solid var(--kh-line);vertical-align:top;';
    /* --kh-muted: 6.18:1 on --kh-card light, 8.90:1 dark. */
    private const T_SUB   = 'color:var(--kh-muted);font-size:12px;';

    public function render_automation(): void
    {
        $s       = Keelhob::instance()->settings();
        $policy  = is_array($s['policy'] ?? null) ? $s['policy'] : [];
        $tasks   = Keelhob_Tasks::all();
        $next    = wp_next_scheduled(Keelhob_Tasks::HOOK);
        $has_key = !empty($s['agent_api_key']);

        // The shell — root class, header line and tab strip — is drawn by
        // Keelhob_Admin so the screens cannot drift into one header each. It
        // prints the <h1> too, which is why one no longer appears here: core
        // moves admin notices into the first .wrap after the first heading, and
        // a second <h1> would hand it the wrong one.
        Keelhob_Admin::shell_open('keelhob-automation');
        ?>
            <p style="margin:0 0 6px;max-width:760px;"><?php esc_html_e('Let the site run itself — on a schedule, or on a plain-language instruction.', 'keelhob'); ?></p>
            <details style="margin:0 0 18px;max-width:760px;">
                <summary class="kh-u-sub" style="cursor:pointer;"><?php esc_html_e('What the two kinds of task do', 'keelhob'); ?></summary>
                <p style="margin:8px 0 0;">
                    <?php esc_html_e('Digest tasks email you a status snapshot on a schedule and need no API key. Autonomous tasks hand a plain-language instruction to the on-site AI agent, which does the work and reports back — no client open. Add a provider API key below to enable autonomy.', 'keelhob'); ?>
                </p>
            </details>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'keelhob'); ?></p></div><?php endif; ?>
            <?php if (isset($_GET['ran'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Task ran. See its last summary below.', 'keelhob'); ?></p></div><?php endif; ?>
            <?php
            if (isset($_GET['tested'])) :
                $test = get_transient('keelhob_test_notify_result');
                $rows = (is_array($test) && !empty($test['results'])) ? $test['results'] : [];

                if (empty($rows)) :
                    ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php esc_html_e('Nothing was sent: no notification channel is both selected and connected.', 'keelhob'); ?></p>
                    </div>
                    <?php
                else :
                    // Report per channel. A blanket "sent" was the original
                    // bug: wp_mail() returning false still printed success.
                    $labels  = Keelhob_Notifier::labels();
                    $health  = (is_array($test) && !empty($test['health'])) ? $test['health'] : [];
                    $all_ok  = true;
                    $lines   = [];

                    foreach ($rows as $channel => $result) {
                        $ok      = !empty($health[$channel]['ok']);
                        $all_ok  = $all_ok && $ok;
                        $label   = $labels[$channel] ?? $channel;
                        $where   = ($channel === 'email' && !empty($test['to'])) ? ' → ' . $test['to'] : '';
                        $lines[] = [$ok, $label . $where, (string) ($health[$channel]['error'] ?? '')];
                    }
                    ?>
                    <div class="notice <?php echo $all_ok ? 'notice-success' : 'notice-error'; ?> is-dismissible">
                        <?php foreach ($lines as $line) : ?>
                            <p style="margin:6px 0;">
                                <strong><?php echo $line[0] ? '✓' : '✕'; ?></strong>
                                <?php echo esc_html($line[1]); ?>
                                <?php if (!$line[0] && $line[2] !== '') : ?>
                                    <?php /* Inside a core .notice, which paints its own white background in both modes; #a00 on #fff is 8.42:1. The ✕ above carries the state, so the colour is decoration. */ ?>
                                    — <span style="color:#a00;"><?php echo esc_html($line[2]); ?></span>
                                <?php endif; ?>
                            </p>
                        <?php endforeach; ?>
                    </div>
                    <?php
                endif;
            endif;
            ?>

            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:2;min-width:460px;">
                    <div class="kh-u-card">
                        <h2 style="margin-top:0;">
                            <?php esc_html_e('Autonomy engine', 'keelhob'); ?>
                            <?php if ($has_key) : ?>
                                <span class="kh-u-pill" style="margin-left:8px;vertical-align:middle;font-weight:400;font-size:12px;"><span class="kh-u-dot" style="background:var(--kh-good);" aria-hidden="true"></span><?php esc_html_e('ready', 'keelhob'); ?></span>
                            <?php else : ?>
                                <span class="kh-u-pill" style="margin-left:8px;vertical-align:middle;font-weight:400;font-size:12px;"><span class="kh-u-dot" style="background:var(--kh-muted);" aria-hidden="true"></span><?php esc_html_e('needs API key', 'keelhob'); ?></span>
                            <?php endif; ?>
                        </h2>
                        <p>
                            <?php esc_html_e('Cron heartbeat:', 'keelhob'); ?>
                            <strong><?php echo $next
                                /* translators: %s: human-readable time until the next cron run */
                                ? esc_html(sprintf(__('%s from now', 'keelhob'), human_time_diff(time(), $next)))
                                : esc_html__('not scheduled', 'keelhob'); ?></strong>
                            <?php esc_html_e('(WP-Cron fires when your site gets traffic).', 'keelhob'); ?>
                        </p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('keelhob_save'); ?>
                            <input type="hidden" name="action" value="keelhob_save">
                            <input type="hidden" name="keelhob_action" value="automation_settings">
                            <?php
                            $agent_id = (string) ($s['agent_provider'] ?? 'anthropic');
                            /** @var class-string<Keelhob_Provider> $agent_p */
                            $agent_p  = Keelhob_Provider::get($agent_id);
                            ?>
                            <h3><?php esc_html_e('Agent brain', 'keelhob'); ?></h3>
                            <p style="color:var(--kh-muted);max-width:620px;">
                                <?php esc_html_e('Bring your own key. Pick a provider, paste its key and choose a model — the fields below follow whichever provider is selected, with no save in between.', 'keelhob'); ?>
                            </p>
                            <?php
                            $provider_nonce = wp_create_nonce(Keelhob_Admin_Providers::PROVIDER_NONCE);
                            $stored_model   = (string) ($s['agent_model'] ?? '');
                            ?>
                            <div id="keelhob-agent-brain">
                                <p>
                                    <label for="keelhob-agent-provider"><strong><?php esc_html_e('Provider', 'keelhob'); ?></strong></label>
                                    <select name="agent_provider" id="keelhob-agent-provider" style="max-width:280px;">
                                        <?php foreach (Keelhob_Provider::providers() as $pid => $cls) : ?>
                                            <option value="<?php echo esc_attr($pid); ?>" <?php selected($agent_id, $pid); ?>><?php echo esc_html($cls::label()); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </p>

                                <?php foreach (Keelhob_Provider::providers() as $pid => $cls) :
                                    // Only the selected provider's row carries the stored key, model
                                    // and endpoint: those belong to it, and pre-filling another
                                    // vendor's row with them is how a key ends up at the wrong host.
                                    $is_current = ($pid === $agent_id);
                                    $models     = Keelhob_Provider::cached_models($pid, $is_current ? $stored_model : '');
                                    $chosen     = ($is_current && $stored_model !== '') ? $stored_model : $cls::default_model();
                                    $has_list   = ($cls::models_list_url($cls::default_endpoint()) !== '');
                                    ?>
                                    <div class="keelhob-provider-row" data-provider="<?php echo esc_attr($pid); ?>" <?php echo $is_current ? '' : 'hidden'; ?>
                                        style="border:1px solid var(--kh-line);border-radius:8px;padding:12px 14px;margin:0 0 12px;background:var(--kh-soft);">
                                        <?php
                                        // Disabled fields post nothing. The form stores one key, one
                                        // model and one endpoint, so without this the two rows nobody
                                        // is looking at would submit alongside the one they filled in
                                        // and whichever came last in the document would win. Rendered
                                        // server-side rather than left to the script, so a page where
                                        // the script never ran saves the right provider rather than
                                        // silently wiping the key.
                                        ?>
                                        <fieldset class="keelhob-provider-fields" style="border:0;padding:0;margin:0;" <?php echo $is_current ? '' : 'disabled'; ?>>

                                        <p style="margin-top:0;">
                                            <label for="keelhob-key-<?php echo esc_attr($pid); ?>"><strong><?php esc_html_e('API key', 'keelhob'); ?></strong></label><br>
                                            <input type="password" name="agent_api_key" id="keelhob-key-<?php echo esc_attr($pid); ?>"
                                                class="regular-text keelhob-key-input" style="width:100%;max-width:520px;" autocomplete="off"
                                                value="<?php echo $is_current ? esc_attr(Keelhob_Secrets::mask((string) ($s['agent_api_key'] ?? ''))) : ''; ?>"
                                                placeholder="<?php echo esc_attr($cls::key_hint()); ?>">
                                            <br><span style="color:var(--kh-muted);">
                                                <?php if ($cls::key_url() !== '') : ?>
                                                    <a href="<?php echo esc_url($cls::key_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get a key ↗', 'keelhob'); ?></a> &middot;
                                                <?php endif; ?>
                                                <?php esc_html_e('Stored on this site and sent only to the provider you select.', 'keelhob'); ?>
                                            </span>
                                        </p>

                                        <p>
                                            <label for="keelhob-model-<?php echo esc_attr($pid); ?>"><strong><?php esc_html_e('Model', 'keelhob'); ?></strong></label><br>
                                            <?php if ($has_list) : ?>
                                                <select name="agent_model" id="keelhob-model-<?php echo esc_attr($pid); ?>" class="keelhob-model-select" style="max-width:320px;">
                                                    <?php foreach ($models as $mid) : ?>
                                                        <option value="<?php echo esc_attr($mid); ?>" <?php selected($chosen, $mid); ?>><?php echo esc_html($mid); ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="<?php echo esc_attr(Keelhob_Admin_Providers::MODEL_CUSTOM); ?>"><?php esc_html_e('Type a custom model…', 'keelhob'); ?></option>
                                                </select>
                                                <input type="text" name="agent_model_custom" class="regular-text keelhob-model-custom" style="max-width:280px;"
                                                    placeholder="<?php echo esc_attr($cls::default_model()); ?>" hidden>
                                                <button type="button" class="button keelhob-provider-action"
                                                    data-action="keelhob_fetch_models" data-busy="<?php esc_attr_e('Refreshing…', 'keelhob'); ?>"><?php esc_html_e('Refresh models', 'keelhob'); ?></button>
                                            <?php else : ?>
                                                <?php // No catalogue to offer, so the sentinel is fixed and the box is the field. ?>
                                                <input type="hidden" name="agent_model" value="<?php echo esc_attr(Keelhob_Admin_Providers::MODEL_CUSTOM); ?>">
                                                <input type="text" name="agent_model_custom" class="regular-text keelhob-model-custom" style="max-width:280px;"
                                                    value="<?php echo $is_current ? esc_attr($stored_model) : ''; ?>" placeholder="<?php echo esc_attr($cls::default_model()); ?>">
                                                <br><span style="color:var(--kh-muted);"><?php esc_html_e('This provider does not publish a model list — type the model name your service uses.', 'keelhob'); ?></span><br>
                                            <?php endif; ?>
                                            <button type="button" class="button keelhob-provider-action"
                                                data-action="keelhob_test_connection" data-busy="<?php esc_attr_e('Testing…', 'keelhob'); ?>"><?php esc_html_e('Test connection', 'keelhob'); ?></button>
                                            <span class="keelhob-provider-status" role="status" style="margin-left:8px;"></span>
                                        </p>

                                        <details>
                                            <summary style="cursor:pointer;"><?php esc_html_e('Advanced', 'keelhob'); ?></summary>
                                            <p style="margin:8px 0 0;">
                                                <label for="keelhob-endpoint-<?php echo esc_attr($pid); ?>"><?php esc_html_e('Custom endpoint', 'keelhob'); ?></label><br>
                                                <input type="text" name="agent_api_endpoint" id="keelhob-endpoint-<?php echo esc_attr($pid); ?>"
                                                    class="regular-text keelhob-endpoint-input" style="width:100%;max-width:520px;"
                                                    value="<?php echo $is_current ? esc_attr((string) ($s['agent_api_endpoint'] ?? '')) : ''; ?>"
                                                    placeholder="<?php echo esc_attr($cls::default_endpoint()); ?>">
                                                <br><span style="color:var(--kh-muted);">
                                                    <?php esc_html_e('Pointing this at a gateway, proxy or self-hosted deployment sends your site\'s data to that host, under that host\'s terms rather than the vendor\'s. Blank uses the provider default.', 'keelhob'); ?>
                                                </span>
                                            </p>
                                        </details>
                                        </fieldset>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <script>
                            (function () {
                                var root = document.getElementById('keelhob-agent-brain');
                                if (!root) { return; }

                                var picker = document.getElementById('keelhob-agent-provider');
                                var rows   = root.querySelectorAll('.keelhob-provider-row');
                                var ajax   = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                                var nonce  = <?php echo wp_json_encode($provider_nonce); ?>;
                                var CUSTOM = <?php echo wp_json_encode(Keelhob_Admin_Providers::MODEL_CUSTOM); ?>;
                                var CUSTOM_LABEL = <?php echo wp_json_encode(__('Type a custom model…', 'keelhob')); ?>;
                                var FAILED = <?php echo wp_json_encode(__('That did not go through. Check the browser console for details.', 'keelhob')); ?>;

                                function each(list, fn) { Array.prototype.forEach.call(list, fn); }

                                function toggleCustom(row) {
                                    var sel = row.querySelector('.keelhob-model-select');
                                    var box = row.querySelector('.keelhob-model-custom');
                                    if (sel && box) { box.hidden = (sel.value !== CUSTOM); }
                                }

                                function show(id) {
                                    each(rows, function (row) {
                                        var on = (row.getAttribute('data-provider') === id);
                                        row.hidden = !on;
                                        // One flag for the whole row: a disabled fieldset takes its
                                        // fields out of the form, which is what keeps the rows nobody
                                        // is looking at from overwriting the one they filled in.
                                        var fields = row.querySelector('.keelhob-provider-fields');
                                        if (fields) { fields.disabled = !on; }
                                        toggleCustom(row);
                                    });
                                }

                                function fill(sel, models) {
                                    var keep = sel.value;
                                    sel.innerHTML = '';
                                    models.forEach(function (id) { sel.appendChild(new Option(id, id, false, id === keep)); });
                                    sel.appendChild(new Option(CUSTOM_LABEL, CUSTOM, false, keep === CUSTOM));
                                    if (!sel.value) { sel.selectedIndex = 0; }
                                }

                                function modelOf(row) {
                                    var sel = row.querySelector('.keelhob-model-select');
                                    var box = row.querySelector('.keelhob-model-custom');
                                    if (sel && sel.value !== CUSTOM) { return sel.value; }
                                    return box ? box.value : '';
                                }

                                function valueOf(row, selector) {
                                    var el = row.querySelector(selector);
                                    return el ? el.value : '';
                                }

                                root.addEventListener('change', function (e) {
                                    if (e.target === picker) { show(picker.value); return; }
                                    if (e.target.classList.contains('keelhob-model-select')) {
                                        var row = e.target.closest('.keelhob-provider-row');
                                        toggleCustom(row);
                                        if (e.target.value === CUSTOM) { row.querySelector('.keelhob-model-custom').focus(); }
                                    }
                                });

                                root.addEventListener('click', function (e) {
                                    var btn = e.target.closest('.keelhob-provider-action');
                                    if (!btn) { return; }
                                    e.preventDefault();

                                    var row    = btn.closest('.keelhob-provider-row');
                                    var status = row.querySelector('.keelhob-provider-status');
                                    var sel    = row.querySelector('.keelhob-model-select');
                                    var body   = new URLSearchParams();
                                    body.set('action', btn.getAttribute('data-action'));
                                    body.set('nonce', nonce);
                                    body.set('provider', row.getAttribute('data-provider'));
                                    body.set('api_key', valueOf(row, '.keelhob-key-input'));
                                    body.set('model', modelOf(row));
                                    body.set('endpoint', valueOf(row, '.keelhob-endpoint-input'));

                                    status.style.color = 'var(--kh-muted)';
                                    status.textContent = btn.getAttribute('data-busy');
                                    btn.disabled = true;

                                    fetch(ajax, {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: body.toString()
                                    }).then(function (r) {
                                        return r.json();
                                    }).then(function (json) {
                                        var ok  = !!(json && json.success);
                                        var msg = (json && json.data && json.data.message) ? json.data.message : FAILED;
                                        // The GLYPH carries the state, not the colour: --kh-good as
                                        // text on a light card is 3.30:1 and fails AA, and a
                                        // colour-only signal says nothing to a colour-blind reader
                                        // either way. --kh-ink is 18.33:1 light, 15.87:1 dark.
                                        status.style.color = 'var(--kh-ink)';
                                        status.textContent = (ok ? '✓ ' : '✕ ') + msg;
                                        if (ok && sel && json.data.models) { fill(sel, json.data.models); }
                                    })['catch'](function () {
                                        status.style.color = 'var(--kh-ink)';
                                        status.textContent = '✕ ' + FAILED;
                                    })['finally'](function () {
                                        btn.disabled = false;
                                    });
                                });

                                show(picker.value);
                            })();
                            </script>

                            <?php Keelhob_Admin_Providers::render_model_guide(
                                __('What is each model good for?', 'keelhob'),
                                Keelhob_Provider::providers(),
                                $agent_id
                            ); ?>

                            <h3><?php esc_html_e('Budget caps', 'keelhob'); ?></h3>
                            <p style="color:var(--kh-muted);max-width:620px;">
                                <?php esc_html_e('The agent bills your API key on every run. These caps stop a runaway loop from spending real money — a run halts cleanly and reports what it managed to finish.', 'keelhob'); ?>
                                <strong><?php esc_html_e('0 = unlimited.', 'keelhob'); ?></strong>
                            </p>
                            <?php
                            /*
                             * A tinted callout, not orange words. --kh-mid on --kh-card is
                             * 3.19:1 and fails AA; --kh-ink on --kh-mid-soft is 17.20:1 light
                             * and 14.34:1 dark. admin.css redefines the tint for dark, so the
                             * ink must invert with it. NOT SHORTENED — this is the notice that says the
                             * numbers are estimates and the provider's invoice is the real
                             * one, and PLAN.md 2.7 puts that in the do-not-cut list.
                             */
                            ?>
                            <p style="margin:10px 0;max-width:720px;background:var(--kh-mid-soft);border-left:3px solid var(--kh-mid);border-radius:8px;padding:10px 14px;color:var(--kh-ink);">
                                <?php esc_html_e('Costs shown here are estimates, not billing. They are calculated from token counts using built-in list prices that can go out of date, and they do not know about your discounts, cached-token rates, or a gateway\'s own pricing. Treat them as a safety brake — your provider\'s invoice is the real number.', 'keelhob'); ?>
                            </p>
                            <p>
                                <label><?php esc_html_e('Max spend per run (USD):', 'keelhob'); ?> <input type="number" step="0.01" min="0" name="agent_max_usd_per_run" value="<?php echo esc_attr($s['agent_max_usd_per_run'] ?? 0.5); ?>" style="max-width:120px;"></label>
                                <label style="margin-left:14px;"><?php esc_html_e('Max tokens per run:', 'keelhob'); ?> <input type="number" step="1000" min="0" name="agent_max_tokens_per_run" value="<?php echo esc_attr($s['agent_max_tokens_per_run'] ?? 300000); ?>" style="max-width:140px;"></label>
                            </p>
                            <p>
                                <label><?php esc_html_e('Max spend per day (USD):', 'keelhob'); ?> <input type="number" step="0.01" min="0" name="agent_max_usd_per_day" value="<?php echo esc_attr($s['agent_max_usd_per_day'] ?? 5); ?>" style="max-width:120px;"></label>
                                <label style="margin-left:14px;"><?php esc_html_e('Max runs per day:', 'keelhob'); ?> <input type="number" step="1" min="0" name="agent_max_runs_per_day" value="<?php echo esc_attr($s['agent_max_runs_per_day'] ?? 48); ?>" style="max-width:120px;"></label>
                            </p>
                            <?php $u24 = Keelhob_Agent_Runs::usage_since(24); ?>
                            <p style="color:var(--kh-ink-2);">
                                <?php
                                printf(
                                    /* translators: 1: number of runs, 2: token count, 3: estimated USD cost */
                                    esc_html__('Last 24h: %1$s runs · %2$s tokens · $%3$s estimated.', 'keelhob'),
                                    '<strong>' . esc_html((string) (int) $u24['runs']) . '</strong>',
                                    '<strong>' . esc_html(number_format((int) $u24['tokens'])) . '</strong>',
                                    '<strong>' . esc_html(number_format((float) $u24['cost'], 4)) . '</strong>'
                                );
                                ?>
                            </p>

                            <h3><?php esc_html_e('Notifications', 'keelhob'); ?></h3>
                            <p><label><?php esc_html_e('Email(s), comma-separated (blank = admin email):', 'keelhob'); ?> <input type="text" name="notify_email" value="<?php echo esc_attr($s['notify_email'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;"></label></p>
                            <p><label><?php esc_html_e('Webhook URL (Slack / generic — send only, optional):', 'keelhob'); ?> <input type="text" name="notify_webhook_url" value="<?php echo esc_attr($s['notify_webhook_url'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;"></label></p>
                            <p class="description" style="margin-top:-6px;"><?php esc_html_e('Keelhob can SEND messages to Slack through an Incoming Webhook. Two-way Slack chat — talking back to the site, the way Telegram works — is not available yet.', 'keelhob'); ?></p>
                            <p><label><?php esc_html_e('Telegram bot token:', 'keelhob'); ?> <input type="text" name="notify_telegram_token" value="<?php echo esc_attr(Keelhob_Secrets::mask((string) ($s['notify_telegram_token'] ?? ''))); ?>" class="regular-text" style="max-width:340px;"></label>
                               <label style="margin-left:8px;"><?php esc_html_e('chat id:', 'keelhob'); ?> <input type="text" name="notify_telegram_chat" value="<?php echo esc_attr($s['notify_telegram_chat'] ?? ''); ?>" style="max-width:160px;"></label></p>

                            <h3><?php esc_html_e('Guardrails (policy)', 'keelhob'); ?></h3>
                            <p><label><input type="checkbox" name="policy_enabled" value="1" <?php checked(!empty($policy['enabled'])); ?>> <?php esc_html_e('Enforce policy', 'keelhob'); ?></label></p>
                            <p><label><input type="checkbox" name="policy_require_optin" value="1" <?php checked(!empty($policy['require_optin'])); ?>> <?php esc_html_e('In autonomous runs, require a task to opt in (allow_risky) before destructive/financial tools', 'keelhob'); ?></label></p>
                            <p><label><?php esc_html_e('Max autonomous refund (0 = block refunds):', 'keelhob'); ?> <input type="number" step="0.01" name="policy_max_refund" value="<?php echo esc_attr($policy['max_refund'] ?? 0); ?>" style="max-width:140px;"></label></p>
                            <p><label><?php esc_html_e('Always-blocked tools (comma-separated, optional):', 'keelhob'); ?> <input type="text" name="policy_blocked_tools" value="<?php echo esc_attr(implode(',', (array) ($policy['blocked_tools'] ?? []))); ?>" class="regular-text" style="width:100%;max-width:520px;"></label></p>

                            <p><button class="button button-primary"><?php esc_html_e('Save automation settings', 'keelhob'); ?></button></p>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('keelhob_save'); ?>
                            <input type="hidden" name="action" value="keelhob_save">
                            <input type="hidden" name="keelhob_action" value="test_notify">
                            <p style="margin:0 0 6px;">
                                <label>
                                    <?php esc_html_e('Send the test email to:', 'keelhob'); ?>
                                    <input type="email" name="test_notify_email"
                                        value="<?php echo esc_attr(trim((string) ($s['notify_email'] ?? '')) ?: (string) get_option('admin_email')); ?>"
                                        class="regular-text" style="max-width:300px;">
                                </label>
                                <span style="color:var(--kh-muted);font-size:12px;display:block;margin-left:2px;">
                                    <?php esc_html_e('This one message only — it is not saved, and it does not change your notification address. Telegram and webhook go to their configured destinations.', 'keelhob'); ?>
                                </span>
                            </p>
                            <button class="button"><?php esc_html_e('Send test notification', 'keelhob'); ?></button>
                        </form>
                    </div>

                    <?php $this->render_telegram_card($s); ?>
                    <?php $this->render_events_card($s); ?>

                    <div class="kh-u-card" style="margin-top:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('Scheduled tasks', 'keelhob'); ?></h2>

                        <div style="background:var(--kh-soft);border:1px solid var(--kh-line);border-radius:8px;padding:12px 16px;margin-bottom:16px;">
                            <strong><?php esc_html_e('Schedule a status digest', 'keelhob'); ?></strong>
                            <p style="color:var(--kh-muted);margin:.4em 0 .8em;">
                                <?php
                                printf(
                                    /* translators: 1: site timezone string, 2: current site time (HH:MM) */
                                    esc_html__('Get the store snapshot sent to you on a cadence — no API key needed. Times use the site timezone (%1$s, currently %2$s).', 'keelhob'),
                                    '<code>' . esc_html(wp_timezone_string()) . '</code>',
                                    esc_html(current_time('H:i'))
                                );
                                ?>
                            </p>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                                <?php wp_nonce_field('keelhob_save'); ?>
                                <input type="hidden" name="action" value="keelhob_save">
                                <input type="hidden" name="keelhob_action" value="task_create">
                                <label><?php esc_html_e('Name', 'keelhob'); ?><br><input type="text" name="task_name" value="<?php esc_attr_e('Status digest', 'keelhob'); ?>" style="max-width:200px;"></label>
                                <label><?php esc_html_e('Cadence', 'keelhob'); ?><br>
                                    <select name="task_schedule" id="kh-digest-schedule">
                                        <?php
                                        $cadences = [
                                            'hourly'     => __('Every hour', 'keelhob'),
                                            'twicedaily' => __('Twice a day', 'keelhob'),
                                            'daily'      => __('Daily', 'keelhob'),
                                            'weekly'     => __('Weekly', 'keelhob'),
                                            'monthly'    => __('Monthly', 'keelhob'),
                                        ];
                                        foreach ($cadences as $val => $label) : ?>
                                            <option value="<?php echo esc_attr($val); ?>" <?php selected($val, 'daily'); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label><?php esc_html_e('At hour', 'keelhob'); ?><br>
                                    <select name="task_run_hour" id="kh-digest-hour">
                                        <option value=""><?php esc_html_e('any time', 'keelhob'); ?></option>
                                        <?php for ($hr = 0; $hr < 24; $hr++) : ?>
                                            <option value="<?php echo (int) $hr; ?>" <?php selected($hr, 8); ?>><?php echo esc_html(sprintf('%02d:00', $hr)); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </label>
                                <label style="padding-bottom:6px;"><input type="checkbox" name="task_notify" value="1" checked> <?php esc_html_e('Notify me', 'keelhob'); ?></label>
                                <button class="button button-primary"><?php esc_html_e('Add digest', 'keelhob'); ?></button>
                            </form>
                            <p style="color:var(--kh-muted);font-size:12px;margin:.8em 0 0;"><?php esc_html_e('Hour-of-day applies to daily, weekly and monthly cadences. WP-Cron fires on site traffic, so a digest lands on the first visit at or after the chosen hour.', 'keelhob'); ?></p>
                        </div>

                        <p style="color:var(--kh-muted);"><?php esc_html_e('You can also create tasks by asking your AI:', 'keelhob'); ?> <em><?php esc_html_e('"Create an autonomous task that fulfils paid orders and publishes due drafts every morning at 7."', 'keelhob'); ?></em></p>
                        <table class="kh-u-table">
                            <thead><tr>
                                <th><?php esc_html_e('Name', 'keelhob'); ?></th>
                                <th><?php esc_html_e('Mode', 'keelhob'); ?></th>
                                <th><?php esc_html_e('Schedule', 'keelhob'); ?></th>
                                <th><?php esc_html_e('On', 'keelhob'); ?></th>
                                <th><?php esc_html_e('Last run', 'keelhob'); ?></th>
                                <th><?php esc_html_e('Actions', 'keelhob'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($tasks as $t) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($t['name']); ?></strong><?php if ((int) $t['allow_risky'] === 1) : ?> <span title="<?php esc_attr_e('allow_risky', 'keelhob'); ?>">⚠️</span><?php endif; ?></td>
                                    <td><?php echo esc_html($t['mode']); ?></td>
                                    <td><?php
                                        echo esc_html($t['schedule']);
                                        if (isset($t['run_hour']) && $t['run_hour'] !== null && $t['run_hour'] !== '') {
                                            echo ' <span style="color:var(--kh-muted);">@ ' . esc_html(sprintf('%02d:00', (int) $t['run_hour'])) . '</span>';
                                        }
                                    ?></td>
                                    <td><?php echo (int) $t['enabled'] === 1 ? '✓' : '—'; ?></td>
                                    <td><?php
                                        if ($t['last_run']) {
                                            printf(
                                                /* translators: 1: timestamp of the last run, 2: its status */
                                                esc_html__('%1$s (%2$s)', 'keelhob'),
                                                esc_html($t['last_run']),
                                                esc_html((string) $t['last_status'])
                                            );
                                        } else {
                                            echo '<em>' . esc_html__('never', 'keelhob') . '</em>';
                                        }
                                    ?></td>
                                    <td style="white-space:nowrap;">
                                        <?php
                                        $task_actions = [
                                            'task_run'    => __('Run now', 'keelhob'),
                                            'task_toggle' => ((int) $t['enabled'] === 1 ? __('Disable', 'keelhob') : __('Enable', 'keelhob')),
                                            'task_delete' => __('Delete', 'keelhob'),
                                        ];
                                        foreach ($task_actions as $act => $label) : ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" <?php if ($act === 'task_delete') : ?>onsubmit="return confirm('<?php echo esc_js(__('Delete this task?', 'keelhob')); ?>');"<?php endif; ?>>
                                                <?php wp_nonce_field('keelhob_save'); ?>
                                                <input type="hidden" name="action" value="keelhob_save">
                                                <input type="hidden" name="keelhob_action" value="<?php echo esc_attr($act); ?>">
                                                <input type="hidden" name="task_id" value="<?php echo (int) $t['id']; ?>">
                                                <button class="button button-small"><?php echo esc_html($label); ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <?php if (!empty($t['last_summary'])) : ?>
                                    <tr><td colspan="6" style="background:var(--kh-soft);"><small class="kh-u-clamp"><strong><?php esc_html_e('Last summary:', 'keelhob'); ?></strong> <?php echo esc_html((string) $t['last_summary']); ?></small></td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty($tasks)) : ?><tr><td colspan="6"><em><?php esc_html_e('No tasks yet.', 'keelhob'); ?></em></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php $runs = Keelhob_Agent_Runs::recent(25); ?>
                    <div class="kh-u-card" style="margin-top:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e('Agent run history', 'keelhob'); ?></h2>
                        <p style="color:var(--kh-muted);"><?php esc_html_e('Every autonomous run — scheduled or one-off — with what it cost. "capped" means a budget cap stopped it early; "truncated" means it hit the step limit. Costs are estimates, not billing.', 'keelhob'); ?></p>
                        <table class="kh-u-table">
                            <thead><tr>
                                <th><?php esc_html_e('Started', 'keelhob'); ?></th>
                                <th><?php esc_html_e('Source', 'keelhob'); ?></th>
                                <th><?php esc_html_e('Status', 'keelhob'); ?></th>
                                <th><?php esc_html_e('Steps', 'keelhob'); ?></th>
                                <th><?php esc_html_e('Tools', 'keelhob'); ?></th>
                                <th><?php esc_html_e('Retries', 'keelhob'); ?></th>
                                <th><?php esc_html_e('Tokens', 'keelhob'); ?></th>
                                <th><?php esc_html_e('Est. cost', 'keelhob'); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($runs as $r) :
                                /*
                                 * A DOT, NOT COLOURED TEXT. --kh-bad on --kh-card is
                                 * 4.83:1 light but 3.80:1 dark, and --kh-good is 3.30:1
                                 * light — coloured status words fail AA in one mode or
                                 * the other. As an 8px dot the same tokens only have to
                                 * clear 3:1 for a graphical object, which all of them do
                                 * in both modes (good 3.30/5.56, mid 3.19/5.76,
                                 * bad 4.83/3.80, muted 6.18/8.90). The word beside it is
                                 * --kh-ink-2: 13.69:1 light, 12.09:1 dark.
                                 */
                                $dots  = ['ok' => 'good', 'error' => 'bad', 'capped' => 'mid', 'truncated' => 'mid', 'running' => 'accent-2'];
                                $dot   = $dots[$r['status']] ?? 'muted'; ?>
                                <tr>
                                    <td><?php echo esc_html($r['started_at']); ?></td>
                                    <td><?php echo esc_html($r['context']); ?><?php echo $r['task_id'] ? ' #' . (int) $r['task_id'] : ''; ?></td>
                                    <td>
                                        <span class="kh-u-pill" style="font-size:12px;padding:3px 10px;">
                                            <span class="kh-u-dot" style="background:var(--kh-<?php echo esc_attr($dot); ?>);" aria-hidden="true"></span>
                                            <?php echo esc_html($r['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo (int) $r['steps']; ?></td>
                                    <td><?php echo (int) $r['tool_calls']; ?></td>
                                    <td><?php echo (int) $r['retries'] ?: '—'; ?></td>
                                    <td><?php echo esc_html(number_format((int) $r['input_tokens'] + (int) $r['output_tokens'])); ?></td>
                                    <td>$<?php echo esc_html(number_format((float) $r['cost_usd'], 4)); ?></td>
                                </tr>
                                <?php if (!empty($r['summary'])) : ?>
                                    <tr><td colspan="8" style="background:var(--kh-soft);"><small class="kh-u-clamp"><strong><?php echo esc_html((string) $r['instruction']); ?></strong> → <?php echo esc_html((string) $r['summary']); ?></small></td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty($runs)) : ?><tr><td colspan="8"><em><?php esc_html_e('No autonomous runs yet.', 'keelhob'); ?></em></td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="flex:1;min-width:300px;">
                    <div class="kh-u-card">
                        <h2 style="margin-top:0;"><?php esc_html_e('Live status', 'keelhob'); ?></h2>
                        <?php $d = Keelhob_Digest::snapshot(); ?>
                        <ul style="line-height:1.7;">
                            <?php foreach ($d['summary'] as $line) : ?><li><?php echo esc_html($line); ?></li><?php endforeach; ?>
                        </ul>
                        <details><summary style="cursor:pointer;"><?php esc_html_e('Full snapshot JSON', 'keelhob'); ?></summary>
                        <pre style="white-space:pre-wrap;font-size:11px;max-height:320px;overflow:auto;"><?php echo esc_html(wp_json_encode($d, JSON_PRETTY_PRINT)); ?></pre></details>
                    </div>
                </div>
            </div>
        <?php
        Keelhob_Admin::shell_close();
    }

    /**
     * The Telegram chat card on the Automation tab.
     *
     * Split out of render_automation() because it is the one panel that talks to
     * a third-party API while rendering (to show whether the webhook is really
     * registered), and that call is worth keeping visible rather than buried in
     * a 300-line template.
     */
    private function render_telegram_card(array $s): void
    {
        $tg_on      = Keelhob_Telegram::enabled();
        $tg_tok     = Keelhob_Telegram::token() !== '';
        $chat_roles = Keelhob_Telegram::chat_roles();
        $allowed    = array_keys($chat_roles);

        // Only ask Telegram about the webhook when there is something to ask
        // about — otherwise every page load would pay for a pointless request.
        $info = $tg_on ? Keelhob_Telegram::webhook_info() : [];
        $hook = is_array($info['result'] ?? null) ? $info['result'] : [];
        $live = !empty($hook['url']) && strpos((string) $hook['url'], Keelhob_Telegram::webhook_url()) === 0;
        ?>
        <div class="kh-u-card" style="margin-top:16px;" id="chat">
            <h2 style="margin-top:0;">
                <?php esc_html_e('Chat to your site on Telegram', 'keelhob'); ?>
                <?php if ($tg_on && $live) : ?>
                    <span class="kh-u-pill" style="margin-left:8px;vertical-align:middle;font-weight:400;font-size:12px;"><span class="kh-u-dot" style="background:var(--kh-good);" aria-hidden="true"></span><?php esc_html_e('connected', 'keelhob'); ?></span>
                <?php elseif ($tg_on) : ?>
                    <span class="kh-u-pill" style="margin-left:8px;vertical-align:middle;font-weight:400;font-size:12px;"><span class="kh-u-dot" style="background:var(--kh-mid);" aria-hidden="true"></span><?php esc_html_e('on, webhook not registered', 'keelhob'); ?></span>
                <?php else : ?>
                    <span class="kh-u-pill" style="margin-left:8px;vertical-align:middle;font-weight:400;font-size:12px;"><span class="kh-u-dot" style="background:var(--kh-muted);" aria-hidden="true"></span><?php esc_html_e('off', 'keelhob'); ?></span>
                <?php endif; ?>
            </h2>

            <p style="max-width:720px;">
                <?php esc_html_e('Message a Telegram bot and the on-site agent does the work — same tools, same guardrails as a scheduled run. No MCP client, no terminal, no laptop. This uses the bot token above, and it bills the API key above.', 'keelhob'); ?>
            </p>

            <?php if (isset($_GET['tg'])) :
                $tg_state = sanitize_key(wp_unslash($_GET['tg']));
                $tg_note  = [
                    'connected'    => __('Bot connected. Message it on Telegram to try it.', 'keelhob'),
                    'disconnected' => __('Webhook removed. The bot no longer reaches this site.', 'keelhob'),
                    'rotated'      => __('Secret rotated and the webhook re-registered.', 'keelhob'),
                    'cleared'      => __('Conversation history wiped.', 'keelhob'),
                    'failed'       => __('Telegram rejected that. Check the bot token, and that this site is reachable over public HTTPS.', 'keelhob'),
                ][$tg_state] ?? '';
                ?>
                <?php if ($tg_note !== '') : ?>
                    <div class="notice <?php echo $tg_state === 'failed' ? 'notice-error' : 'notice-success'; ?> is-dismissible" style="margin:10px 0;">
                        <p><?php echo esc_html($tg_note); ?>
                        <?php if (!empty($_GET['tg_msg'])) : ?>
                            <code><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['tg_msg'])))); ?></code>
                        <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$tg_tok) : ?>
                <div class="notice notice-warning inline" style="margin:10px 0;">
                    <p><?php esc_html_e('Add a Telegram bot token in the Notifications section above first — talk to @BotFather on Telegram, send /newbot, and paste the token it gives you.', 'keelhob'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('keelhob_save'); ?>
                <input type="hidden" name="action" value="keelhob_save">
                <input type="hidden" name="keelhob_action" value="telegram_settings">

                <p>
                    <label style="font-size:15px;">
                        <input type="checkbox" name="telegram_bot_enabled" value="1" <?php checked($tg_on); ?>>
                        <strong><?php esc_html_e('Accept messages from Telegram', 'keelhob'); ?></strong>
                    </label>
                </p>

                <p>
                    <label><?php esc_html_e('Allowed chat IDs (comma-separated, optionally with a role):', 'keelhob'); ?><br>
                    <input type="text" name="telegram_allowed_chats" value="<?php echo esc_attr($s['telegram_allowed_chats'] ?? ''); ?>" class="regular-text" style="width:100%;max-width:520px;" placeholder="<?php echo esc_attr(($s['notify_telegram_chat'] ?? '123456789') . ', 987654321:viewer'); ?>"></label>
                    <br><span style="color:var(--kh-muted);">
                        <?php esc_html_e('Only these chats get answered; everyone else is refused. Blank falls back to the notification chat ID above. Don\'t know yours? Message the bot and it replies with your ID.', 'keelhob'); ?>
                    </span>
                    <?php if (empty($allowed)) : ?>
                        <?php /* --kh-ink on --kh-bad-soft: 16.76:1 light, 15.25:1 dark. Not shortened: it says the bot will refuse the owner. */ ?>
                        <?php /* display:block, or this sits on the text around it - see the voice warning below. */ ?>
                        <br><span style="display:block;margin:10px 0;max-width:720px;background:var(--kh-bad-soft);border-left:3px solid var(--kh-bad);border-radius:8px;padding:10px 14px;color:var(--kh-ink);"><?php esc_html_e('No chat is on the list yet, so the bot will refuse everyone — including you.', 'keelhob'); ?></span>
                    <?php endif; ?>
                </p>

                <table class="kh-u-table" style="max-width:720px;margin:0 0 14px;">
                    <thead><tr>
                        <th style="width:150px;"><?php esc_html_e('Write it as', 'keelhob'); ?></th>
                        <th><?php esc_html_e('What that chat can do', 'keelhob'); ?></th>
                    </tr></thead>
                    <tbody>
                        <tr><td><code>123456789</code></td><td><?php esc_html_e('admin (the default) — everything, may press Approve and use /risky', 'keelhob'); ?></td></tr>
                        <tr><td><code>123456789:editor</code></td><td><?php esc_html_e('may change the site, but never destructive or financial tools, and cannot approve', 'keelhob'); ?></td></tr>
                        <tr><td><code>123456789:viewer</code></td><td><?php esc_html_e('read-only — can ask questions and read reports, cannot change anything', 'keelhob'); ?></td></tr>
                    </tbody>
                </table>

                <?php if (!empty($chat_roles)) : ?>
                    <p style="color:var(--kh-ink-2);">
                        <strong><?php esc_html_e('Currently allowlisted:', 'keelhob'); ?></strong>
                        <?php
                        $bits = [];
                        foreach ($chat_roles as $cid => $crole) {
                            $bits[] = '<code>' . esc_html((string) $cid) . '</code> → ' . esc_html($crole);
                        }
                        echo wp_kses_post(implode(' · ', $bits));
                        ?>
                    </p>
                <?php endif; ?>

                <p>
                    <label><?php esc_html_e('Remember this many turns per conversation:', 'keelhob'); ?>
                    <input type="number" min="0" max="60" step="1" name="telegram_history_turns" value="<?php echo esc_attr($s['telegram_history_turns'] ?? 12); ?>" style="max-width:100px;"></label>
                    <span style="color:var(--kh-muted);margin-left:8px;"><?php esc_html_e('0 = every message starts fresh. Longer memory means better follow-ups and a bigger bill per message.', 'keelhob'); ?></span>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="telegram_allow_risky" value="1" <?php checked(!empty($s['telegram_allow_risky'])); ?>>
                        <?php esc_html_e('Let an admin chat escalate to destructive/financial tools', 'keelhob'); ?>
                    </label>
                    <br><span style="color:var(--kh-muted);"><?php esc_html_e('Off by default. When allowed, escalation is either a per-action "Approve & run" button on the reply, or a /risky on window that lapses by itself after 30 minutes. Editor and viewer chats can never escalate, whatever this says.', 'keelhob'); ?></span>
                </p>

                <hr>
                <h3><?php esc_html_e('Voice notes, photos and progress', 'keelhob'); ?></h3>

                <p>
                    <label>
                        <input type="checkbox" name="telegram_progress" value="1" <?php checked(!empty($s['telegram_progress'])); ?>>
                        <?php esc_html_e('Show the work in progress', 'keelhob'); ?>
                    </label>
                    <br><span style="color:var(--kh-muted);"><?php esc_html_e('One message is edited in place as the agent works, then becomes the answer — instead of silence for 40 seconds.', 'keelhob'); ?></span>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="telegram_photos" value="1" <?php checked(!empty($s['telegram_photos'])); ?>>
                        <?php esc_html_e('Accept photos and screenshots', 'keelhob'); ?>
                    </label>
                    <br><span style="color:var(--kh-muted);"><?php esc_html_e('The image is shown to the model and filed in the media library, so it can go straight into a post or product. Needs a model that can see images — all current Claude, GPT and Gemini models can.', 'keelhob'); ?></span>
                </p>

                <?php
                $voice_id = (string) ($s['voice_provider'] ?? 'openai');
                $voice_key = Keelhob_Transcribe_Provider::key();
                ?>
                <p>
                    <label>
                        <input type="checkbox" name="telegram_voice" value="1" <?php checked(!empty($s['telegram_voice'])); ?>>
                        <?php esc_html_e('Accept voice notes', 'keelhob'); ?>
                    </label>
                    <br><span style="color:var(--kh-muted);"><?php esc_html_e('Transcribed first, and the transcript is shown to you before the agent acts — so a mis-hearing is visible, not silently acted on.', 'keelhob'); ?></span>
                    <?php if (!empty($s['telegram_voice']) && $voice_key === '') : ?>
                        <?php
                        /*
                         * display:block is load-bearing on both of these warnings.
                         *
                         * A span is display:inline. An inline box ignores vertical
                         * margin entirely, and its vertical padding is PAINTED but
                         * does not push the surrounding lines apart - so the red
                         * panel was drawn over the help text above and below it,
                         * and max-width did nothing either. The owner reported it
                         * as the warning covering the text behind it, which is
                         * exactly what an inline box with 10px padding does.
                         */
                        ?>
                        <br><span style="display:block;margin:10px 0;max-width:720px;background:var(--kh-bad-soft);border-left:3px solid var(--kh-bad);border-radius:8px;padding:10px 14px;color:var(--kh-ink);"><?php esc_html_e('Voice is on but there is no transcription key, so voice notes will be refused.', 'keelhob'); ?></span>
                    <?php endif; ?>
                </p>

                <p style="color:var(--kh-muted);max-width:620px;">
                    <?php esc_html_e('Transcription is a separate service from the agent brain: Anthropic has no speech-to-text API, so voice needs an OpenAI or Google key even if Claude is doing the thinking. Leave the key blank to reuse a key you already entered for the same vendor.', 'keelhob'); ?>
                </p>
                <?php Keelhob_Admin_Providers::render_media_provider([
                    'kind'           => 'voice',
                    'providers'      => Keelhob_Transcribe_Provider::providers(),
                    'current'        => $voice_id,
                    'provider_field' => 'voice_provider',
                    'key_field'      => 'voice_api_key',
                    'model_field'    => 'voice_model',
                    'endpoint_field' => 'voice_api_endpoint',
                    'provider_label' => __('Transcription provider', 'keelhob'),
                    'key_label'      => __('Transcription API key', 'keelhob'),
                    'model_label'    => __('Transcription model', 'keelhob'),
                    'key_value'      => Keelhob_Secrets::mask((string) ($s['voice_api_key'] ?? '')),
                    'model_value'    => (string) ($s['voice_model'] ?? ''),
                    'endpoint_value' => (string) ($s['voice_api_endpoint'] ?? ''),
                    // Only true when the box really is empty and a same-vendor
                    // key is filling in for it. Saying so beats an empty field
                    // that looks broken but works.
                    'current_key_note' => ($voice_key !== '' && trim((string) ($s['voice_api_key'] ?? '')) === '')
                        ? __('Blank — currently reusing another key you entered for this same vendor.', 'keelhob')
                        : '',
                ]); ?>

                <p><button class="button button-primary"><?php esc_html_e('Save chat settings', 'keelhob'); ?></button></p>
            </form>

            <hr>
            <p>
                <strong><?php esc_html_e('Webhook:', 'keelhob'); ?></strong>
                <code><?php echo esc_html(Keelhob_Telegram::webhook_url()); ?></code>
            </p>
            <?php if ($tg_on && !empty($hook['last_error_message'])) : ?>
                <p style="margin:10px 0;max-width:720px;background:var(--kh-bad-soft);border-left:3px solid var(--kh-bad);border-radius:8px;padding:10px 14px;color:var(--kh-ink);">
                    <?php esc_html_e('Telegram\'s last delivery failed:', 'keelhob'); ?>
                    <code><?php echo esc_html((string) $hook['last_error_message']); ?></code>
                </p>
            <?php endif; ?>

            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php
                $tg_buttons = [
                    'telegram_connect'       => __('Connect bot to this site', 'keelhob'),
                    'telegram_disconnect'    => __('Disconnect', 'keelhob'),
                    'telegram_rotate_secret' => __('Rotate webhook secret', 'keelhob'),
                    'telegram_clear_chats'   => __('Clear all conversations', 'keelhob'),
                ];
                foreach ($tg_buttons as $tg_act => $tg_label) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" <?php if ($tg_act === 'telegram_clear_chats') : ?>onsubmit="return confirm('<?php echo esc_js(__('Wipe every stored Telegram conversation?', 'keelhob')); ?>');"<?php endif; ?>>
                        <?php wp_nonce_field('keelhob_save'); ?>
                        <input type="hidden" name="action" value="keelhob_save">
                        <input type="hidden" name="keelhob_action" value="<?php echo esc_attr($tg_act); ?>">
                        <button class="button <?php echo $tg_act === 'telegram_connect' ? 'button-primary' : ''; ?>"><?php echo esc_html($tg_label); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
            <p style="color:var(--kh-muted);margin-top:10px;">
                <?php esc_html_e('Telegram only delivers to a publicly reachable HTTPS URL with a valid certificate — a localhost or self-signed staging site cannot receive messages.', 'keelhob'); ?>
            </p>

            <details style="margin-top:14px;">
                <summary style="cursor:pointer;font-weight:600;"><?php esc_html_e('Commands the bot understands', 'keelhob'); ?></summary>
                <table class="kh-u-table" style="max-width:720px;margin-top:10px;">
                    <tbody>
                    <?php foreach (Keelhob_Telegram::commands() as $tg_cmd => $tg_desc) : ?>
                        <tr>
                            <td style="width:120px;"><code>/<?php echo esc_html($tg_cmd); ?></code></td>
                            <td><?php echo esc_html($tg_desc); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="color:var(--kh-muted);">
                    <?php esc_html_e('Anything that is not a command goes to the agent as plain instruction. /status and /usage are answered by the plugin itself, so they cost nothing.', 'keelhob'); ?>
                </p>
            </details>
        </div>
        <?php
    }

    /**
     * The event-notification card: the site messaging the owner unprompted.
     *
     * Every event is individually switchable and orders have a value floor,
     * because a notifier that cries wolf gets muted — and then the one message
     * that mattered is missed with the rest.
     */
    private function render_events_card(array $s): void
    {
        $has_wc = class_exists('WooCommerce');
        ?>
        <div class="kh-u-card" style="margin-top:16px;" id="events">
            <h2 style="margin-top:0;">
                <?php esc_html_e('Let the site tell you first', 'keelhob'); ?>
                <?php if (!empty($s['events_enabled'])) : ?>
                    <span class="kh-u-pill" style="margin-left:8px;vertical-align:middle;font-weight:400;font-size:12px;"><span class="kh-u-dot" style="background:var(--kh-good);" aria-hidden="true"></span><?php esc_html_e('on', 'keelhob'); ?></span>
                <?php else : ?>
                    <span class="kh-u-pill" style="margin-left:8px;vertical-align:middle;font-weight:400;font-size:12px;"><span class="kh-u-dot" style="background:var(--kh-muted);" aria-hidden="true"></span><?php esc_html_e('off', 'keelhob'); ?></span>
                <?php endif; ?>
            </h2>
            <p style="max-width:720px;">
                <?php esc_html_e('Everything else here is you asking and the site answering. This is the other way round: an order lands, stock runs out, a live snippet crashes — and you hear about it on whichever notification channels you configured above (email, webhook, Telegram).', 'keelhob'); ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('keelhob_save'); ?>
                <input type="hidden" name="action" value="keelhob_save">
                <input type="hidden" name="keelhob_action" value="events_settings">

                <p>
                    <label style="font-size:15px;">
                        <input type="checkbox" name="events_enabled" value="1" <?php checked(!empty($s['events_enabled'])); ?>>
                        <strong><?php esc_html_e('Send me event notifications', 'keelhob'); ?></strong>
                    </label>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="events_order" value="1" <?php checked(!empty($s['events_order'])); ?> <?php disabled(!$has_wc); ?>>
                        <?php esc_html_e('Paid orders', 'keelhob'); ?>
                    </label>
                    <label style="margin-left:14px;"><?php esc_html_e('only at or above:', 'keelhob'); ?>
                        <input type="number" step="0.01" min="0" name="events_order_min" value="<?php echo esc_attr($s['events_order_min'] ?? 0); ?>" style="max-width:110px;">
                    </label>
                    <span style="color:var(--kh-muted);margin-left:8px;"><?php esc_html_e('0 = every order. Each order is announced once per status change, never twice.', 'keelhob'); ?></span>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="events_stock" value="1" <?php checked(!empty($s['events_stock'])); ?> <?php disabled(!$has_wc); ?>>
                        <?php esc_html_e('Low stock and sold out', 'keelhob'); ?>
                    </label>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="events_snippet" value="1" <?php checked(!empty($s['events_snippet'])); ?>>
                        <?php esc_html_e('A live code snippet crashed and was auto-disabled', 'keelhob'); ?>
                    </label>
                    <br><span style="color:var(--kh-muted);"><?php esc_html_e('Strongly recommended. The site stays up because the snippet switched itself off — but whatever it was doing has stopped, and only you can judge whether that matters.', 'keelhob'); ?></span>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="events_updates" value="1" <?php checked(!empty($s['events_updates'])); ?>>
                        <?php esc_html_e('New plugin, theme or core updates are available', 'keelhob'); ?>
                    </label>
                    <br><span style="color:var(--kh-muted);"><?php esc_html_e('Checked on the hourly cron tick, and only when the set of pending updates actually changes — so holding one back deliberately does not nag you forever.', 'keelhob'); ?></span>
                </p>

                <?php if (!$has_wc) : ?>
                    <p style="color:var(--kh-muted);"><?php esc_html_e('Order and stock alerts need WooCommerce, which is not active on this site.', 'keelhob'); ?></p>
                <?php endif; ?>

                <p><button class="button button-primary"><?php esc_html_e('Save notification events', 'keelhob'); ?></button></p>
            </form>
        </div>
        <?php
    }
}
