<?php
/**
 * The provider half of the admin UI: the AI model boxes, and the buttons next
 * to them.
 *
 * Two jobs that only make sense together. The render helpers draw the provider
 * pickers — chat, image and transcription — onto whichever screen needs them,
 * and the AJAX endpoints answer the buttons those pickers put on the page:
 * "list this vendor's models" and "does this key work?".
 *
 * Splitting the pair would mean a screen emitting a nonce and a field name that
 * an endpoint in another file has to agree with by convention. They agree here
 * by sharing PROVIDER_NONCE and reading the same $_POST in one file.
 *
 * The endpoints accept an API key in the request body so a key that has been
 * typed but not saved can be tested. That makes them a credential path rather
 * than a settings path, which is why every one of them starts at
 * guard_provider_ajax().
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Admin_Providers
{
    /**
     * Nonce action shared by the two provider endpoints.
     *
     * Public because the screens that render a form posting to those endpoints
     * live in other files now (Connect, and Automation) and have to mint the
     * matching nonce. One name, read from one place, is the only way the form
     * and the guard cannot drift apart.
     */
    public const PROVIDER_NONCE = 'keelhob_provider';

    /**
     * Dropdown value meaning "the model is in the box next to me".
     *
     * Vendors ship models faster than a plugin ships releases, so a dropdown on
     * its own would eventually block the one model someone actually wants. The
     * sentinel keeps free text reachable without making it the default.
     *
     * Public because the two halves of that bargain sit in different files: the
     * Automation screen writes the sentinel into the dropdown, and the save
     * handler recognises it on the way back in.
     */
    public const MODEL_CUSTOM = '__custom__';

    /**
     * The gate both provider endpoints go through.
     *
     * They accept an API key in the request body so a key that has been typed
     * but not saved can be tested — which makes them a credential path, not a
     * settings path: capability AND nonce, both, every time. Capability first,
     * so an unauthorised caller is refused before the nonce check tells them
     * anything about whether their nonce was good.
     */
    private static function guard_provider_ajax(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to change these settings.', 'keelhob')], 403);
        }
        check_ajax_referer(self::PROVIDER_NONCE, 'nonce');
    }

    /**
     * What the settings form currently shows, for a request that must not save it.
     *
     * Reading the form rather than the option is the whole point: someone
     * pressing Refresh or Test has usually just pasted a key and not saved yet,
     * and testing the stored values would answer a question nobody asked.
     */
    private static function provider_from_post(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_provider_ajax() checks it.
        $pid = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
        $key = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));

        return [
            'provider' => isset(Keelhob_Provider::providers()[$pid]) ? $pid : '',
            // An untouched key field posts the mask back. That is not a key, and
            // sending eight bullets to a vendor tests nothing — blank it, and the
            // stored key is used instead.
            'api_key'  => Keelhob_Secrets::is_mask($key) ? '' : $key,
            'model'    => sanitize_text_field(wp_unslash($_POST['model'] ?? '')),
            // esc_url_raw rather than a text sanitizer: this is an address the
            // request will really be sent to, so the scheme is validated, not tidied.
            'endpoint' => trim(esc_url_raw(wp_unslash($_POST['endpoint'] ?? ''))),
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /** Replace a provider's model dropdown with what its key can really use. */
    public function ajax_fetch_models(): void
    {
        self::guard_provider_ajax();

        $models = Keelhob_Provider::fetch_models(self::provider_from_post());
        if (is_wp_error($models)) {
            wp_send_json_error(['message' => $models->get_error_message()], 400);
        }

        wp_send_json_success([
            'models'  => $models,
            /* translators: %d: number of models the provider listed. */
            'message' => sprintf(_n('%d model available.', '%d models available.', count($models), 'keelhob'), count($models)),
        ]);
    }

    /** One round trip that says whether the key on screen works. Saves nothing. */
    public function ajax_test_connection(): void
    {
        self::guard_provider_ajax();

        $result = Keelhob_Provider::test_connection(self::provider_from_post());
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: provider name, 2: model id. */
                __('Connected to %1$s using %2$s.', 'keelhob'),
                $result['label'],
                $result['model']
            ),
        ]);
    }

    /**
     * Test an image or transcription key.
     *
     * Separate from ajax_test_connection() because the check is a different
     * shape: those providers are not chat providers, and testing them the chat
     * way would mean generating an image or transcribing audio - a real charge,
     * on every press, for a button people press when something is already wrong.
     */
    public function ajax_test_media(): void
    {
        self::guard_provider_ajax();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_provider_ajax() checks it.
        $kind     = sanitize_key(wp_unslash($_POST['kind'] ?? ''));
        $pid      = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
        $key      = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        $model    = sanitize_text_field(wp_unslash($_POST['model'] ?? ''));
        $endpoint = trim(esc_url_raw(wp_unslash($_POST['endpoint'] ?? '')));
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        [$cls, $key] = self::media_provider_from_post($kind, $pid, $key);

        $model  = $model !== '' ? $model : $cls::default_model();
        $result = Keelhob_Media_Check::run($cls::verify_url($endpoint, $model), $cls::auth_headers($key), $model);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: provider name, 2: model id. */
                __('Connected to %1$s using %2$s.', 'keelhob'),
                $cls::label(),
                $model
            ),
        ]);
    }

    /**
     * Which adapter and which key a media request is really about.
     *
     * Sends the JSON error and stops the request itself rather than returning a
     * failure for the caller to remember to check. Both endpoints accept a key
     * from the form, so a path that carried on with a null adapter would send
     * somebody's key to whichever vendor happened to be the default.
     *
     * @return array{0: class-string, 1: string}
     */
    private static function media_provider_from_post(string $kind, string $pid, string $key): array
    {
        $s = Keelhob::instance()->settings();

        if ($kind === 'image') {
            /** @var class-string<Keelhob_Image_Provider>|null $cls */
            $cls    = Keelhob_Image_Provider::providers()[$pid] ?? null;
            $stored = (string) ($s['image_api_key'] ?? '');
        } elseif ($kind === 'voice') {
            /** @var class-string<Keelhob_Transcribe_Provider>|null $cls */
            $cls = Keelhob_Transcribe_Provider::providers()[$pid] ?? null;
            // Voice deliberately falls back to another key for the same vendor,
            // so an owner who already pasted an OpenAI key is not asked twice.
            // Both endpoints have to honour that, or they report "no key" for a
            // setup that works perfectly.
            $stored = ((string) ($s['voice_provider'] ?? 'openai') === $pid)
                ? Keelhob_Transcribe_Provider::key()
                : (string) ($s['voice_api_key'] ?? '');
        } else {
            wp_send_json_error(['message' => __('Unknown provider type.', 'keelhob')], 400);
            exit;
        }

        if ($cls === null) {
            wp_send_json_error(['message' => __('Unknown provider.', 'keelhob')], 400);
            exit;
        }

        // An untouched key field posts the mask back. Eight bullets are not a
        // key, and sending them to a vendor tests nothing - fall through to the
        // stored one rather than report a failure the owner cannot act on.
        if ($key === '' || Keelhob_Secrets::is_mask($key)) {
            $key = $stored;
        }
        if (trim($key) === '') {
            wp_send_json_error(['message' => __('Add an API key first, then try again.', 'keelhob')], 400);
            exit;
        }

        return [$cls, $key];
    }

    /**
     * Replace an image or transcription dropdown with what the key can reach.
     *
     * The list that comes back is the curated one PLUS anything new the vendor
     * advertises - never the vendor's alone. See Keelhob_Media_Check::models()
     * for why: the catalogue is matched by name, and a name match that misses
     * would otherwise empty the dropdown or drop the model a site is using.
     */
    public function ajax_fetch_media_models(): void
    {
        self::guard_provider_ajax();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_provider_ajax() checks it.
        $kind     = sanitize_key(wp_unslash($_POST['kind'] ?? ''));
        $pid      = sanitize_key(wp_unslash($_POST['provider'] ?? ''));
        $key      = sanitize_text_field(wp_unslash($_POST['api_key'] ?? ''));
        $keep     = sanitize_text_field(wp_unslash($_POST['model'] ?? ''));
        $endpoint = trim(esc_url_raw(wp_unslash($_POST['endpoint'] ?? '')));
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        [$cls, $key] = self::media_provider_from_post($kind, $pid, $key);

        $models = Keelhob_Media_Check::refresh($cls, $kind, $pid, $key, $endpoint, $keep);
        if (is_wp_error($models)) {
            wp_send_json_error(['message' => $models->get_error_message()], 400);
        }

        wp_send_json_success([
            'models'  => $models,
            /* translators: %d: number of models on the list. */
            'message' => sprintf(_n('%d model available.', '%d models available.', count($models), 'keelhob'), count($models)),
        ]);
    }

    /**
     * The model the form actually chose.
    /**
     * The model the form actually chose.
     *
     * The dropdown carries a sentinel rather than a value for "type your own",
     * so the free-text box beside it is what gets stored in that case.
     *
     * Public because the save handler (Keelhob_Admin_Settings) is what reads
     * the form, and the sentinel it has to understand is defined here.
     */
    public static function model_from_post(): string
    {
        $picked = sanitize_text_field(wp_unslash($_POST['agent_model'] ?? ''));
        if ($picked !== self::MODEL_CUSTOM) {
            return $picked;
        }
        return sanitize_text_field(wp_unslash($_POST['agent_model_custom'] ?? ''));
    }

    /**
     * Collapsible cheat-sheet of valid model IDs for every provider.
     *
     * The model field has to accept free text — vendors ship new models faster
     * than a plugin can ship a dropdown, and gateways expose their own names.
     * Free text means an owner can type something that simply does not exist and
     * get an opaque 404 from the API, so the valid IDs live right next to the
     * field, with a link to the vendor's list for anything newer than this build.
     *
     * @param array<string, class-string> $providers  id => adapter class
     * @param string                      $selected   currently chosen provider id
     *
     * Public because the Automation screen (Keelhob_Admin_Automation) draws
     * it. It is a partial of that page, not a page of its own.
     */
    public static function render_model_guide(string $title, array $providers, string $selected): void
    {
        ?>
        <?php
        /*
         * Table styling as PHP strings. assets/css/admin.css is the core-screens
         * lane's file and is read-only here, so a `kh-u-table` component cannot
         * be added to it from this one. Core's list-table classes paint white
         * and near-white unconditionally, which is a light table inside a dark
         * card as soon as the panel goes dark. Recorded in BUILD-NOTES.md.
         */
        $t_table = 'width:100%;border-collapse:collapse;';
        $t_th    = 'text-align:left;padding:8px 10px;border-bottom:1px solid var(--kh-line);'
                 . 'font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;'
                 . 'color:var(--kh-muted);';
        $t_td    = 'padding:9px 10px;border-bottom:1px solid var(--kh-line);vertical-align:top;';
        ?>
        <details style="margin:10px 0 18px;border:1px solid var(--kh-line);border-radius:8px;padding:10px 14px;background:var(--kh-soft);">
            <summary style="cursor:pointer;font-weight:600;"><?php echo esc_html($title); ?></summary>
            <?php /* --kh-muted on --kh-soft is 5.50:1 light, 8.23:1 dark. */ ?>
            <p style="color:var(--kh-muted);margin:10px 0;">
                <?php esc_html_e('Type the ID exactly as shown. Anything not on this list may still work if your provider offers it — check the vendor\'s model list.', 'keelhob'); ?>
            </p>
            <?php foreach ($providers as $pid => $cls) : ?>
                <div style="margin:12px 0;<?php echo $pid === $selected ? 'padding:8px 10px;border-left:3px solid var(--kh-accent-2);background:var(--kh-card);' : ''; ?>">
                    <p style="margin:0 0 6px;">
                        <strong><?php echo esc_html($cls::label()); ?></strong>
                        <?php if ($pid === $selected) : ?>
                            <?php /* --kh-accent-2-ink is the TEXT variant: 5.03:1 on --kh-card light; in dark it resolves to #4bb8c1 at 8.58:1. Raw --kh-accent-2 would be 3.35:1 and fail. */ ?>
                            <span style="color:var(--kh-accent-2-ink);">← <?php esc_html_e('your current provider', 'keelhob'); ?></span>
                        <?php endif; ?>
                    </p>
                    <table style="<?php echo esc_attr($t_table); ?>max-width:720px;">
                        <thead><tr>
                            <th style="<?php echo esc_attr($t_th); ?>width:230px;"><?php esc_html_e('Model ID to type', 'keelhob'); ?></th>
                            <th style="<?php echo esc_attr($t_th); ?>"><?php esc_html_e('What it is', 'keelhob'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($cls::models() as $m) : ?>
                            <tr>
                                <td style="<?php echo esc_attr($t_td); ?>"><code><?php echo esc_html($m['id']); ?></code></td>
                                <td style="<?php echo esc_attr($t_td); ?>"><?php echo esc_html($m['note']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin:6px 0 0;">
                        <a href="<?php echo esc_url($cls::models_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Full model list from the vendor ↗', 'keelhob'); ?></a>
                        &nbsp;·&nbsp;
                        <a href="<?php echo esc_url($cls::key_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get an API key ↗', 'keelhob'); ?></a>
                    </p>
                </div>
            <?php endforeach; ?>
        </details>
        <?php
    }

    /**
     * The image / transcription provider block: picker, key, model, Test.
     *
     * One renderer for both, because they are the same screen twice and two
     * copies would drift. The shape mirrors the agent-brain block above: one
     * row per provider, only the selected one enabled, everything swapping the
     * moment the picker changes rather than after a save.
     *
     * The model is a dropdown with no free-text escape. These lists are short,
     * curated and vendor-specific, and a typed model id fails as an opaque 404
     * days later, inside a scheduled run, where nobody connects it back to a
     * settings screen. An owner who genuinely needs a model that is not listed
     * has the endpoint override under Advanced.
     *
     * @param array{
     *   kind: string, providers: array<string, class-string>, current: string,
     *   provider_field: string, key_field: string, model_field: string,
     *   endpoint_field: string, provider_label: string, key_label: string,
     *   model_label: string, key_value: string, model_value: string,
     *   endpoint_value: string, current_key_note?: string
     * } $a
     *
     * Public because two screens draw it — Connect and Automation — and both
     * now live outside this file. Keeping one copy here is what makes the two
     * pages configure a media provider identically.
     */
    public static function render_media_provider(array $a): void
    {
        $kind = $a['kind'];
        $note = (string) ($a['current_key_note'] ?? '');
        // Minted here rather than passed in: the two callers sit on different
        // admin pages, and a block that mints its own nonce cannot be rendered
        // with one that was never created.
        $nonce = wp_create_nonce(self::PROVIDER_NONCE);
        ?>
        <div class="keelhob-media-block" id="keelhob-media-<?php echo esc_attr($kind); ?>" data-kind="<?php echo esc_attr($kind); ?>"
            data-nonce="<?php echo esc_attr($nonce); ?>">
            <p>
                <label><strong><?php echo esc_html($a['provider_label']); ?></strong><br>
                    <select name="<?php echo esc_attr($a['provider_field']); ?>" class="keelhob-media-picker" style="max-width:340px;">
                        <?php foreach ($a['providers'] as $pid => $cls) : ?>
                            <option value="<?php echo esc_attr($pid); ?>" <?php selected($a['current'], $pid); ?>><?php echo esc_html($cls::label()); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>

            <?php foreach ($a['providers'] as $pid => $cls) :
                // Only the selected provider's row carries the stored key, model
                // and endpoint. Those belong to it, and pre-filling another
                // vendor's row with them is how a key ends up at the wrong host.
                $is_current = ($pid === $a['current']);
                $chosen     = ($is_current && $a['model_value'] !== '') ? $a['model_value'] : $cls::default_model();
                // The curated list, plus anything a Refresh has since found, plus
                // whatever is saved even if neither mentions it any more.
                $models     = Keelhob_Media_Check::models($cls, $kind, $pid, $is_current ? $chosen : '');
                ?>
                <div class="keelhob-provider-row" data-provider="<?php echo esc_attr($pid); ?>" <?php echo $is_current ? '' : 'hidden'; ?>
                    style="border:1px solid var(--kh-line);border-radius:8px;padding:12px 14px;margin:0 0 12px;background:var(--kh-soft);">
                    <?php
                    // Disabled fields post nothing. The form stores one key, one
                    // model and one endpoint, so without this the rows nobody is
                    // looking at would submit alongside the one they filled in,
                    // and whichever came last in the document would win.
                    // Rendered server-side rather than left to the script, so a
                    // page where the script never ran still saves the right
                    // provider instead of silently wiping the key.
                    ?>
                    <fieldset class="keelhob-provider-fields" style="border:0;padding:0;margin:0;" <?php echo $is_current ? '' : 'disabled'; ?>>

                        <p style="margin-top:0;">
                            <label><strong><?php echo esc_html($a['key_label']); ?></strong><br>
                                <input type="password" name="<?php echo esc_attr($a['key_field']); ?>" class="regular-text keelhob-key-input"
                                    style="width:100%;max-width:520px;" autocomplete="off"
                                    value="<?php echo $is_current ? esc_attr($a['key_value']) : ''; ?>"
                                    placeholder="<?php echo esc_attr($cls::key_hint()); ?>">
                            </label>
                            <br><span style="color:var(--kh-muted);">
                                <?php
                                // The link is per-row and unconditional. Hiding
                                // it behind the reuse note was the original bug
                                // in miniature: the one thing somebody without
                                // a key needs is the link to go and get one.
                                ?>
                                <a href="<?php echo esc_url($cls::key_url()); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get a key from this provider', 'keelhob'); ?></a>
                                &middot; <?php esc_html_e('Stored on this site and sent only to the provider you select.', 'keelhob'); ?>
                                <?php if ($is_current && $note !== '') : ?>
                                    <br><em><?php echo esc_html($note); ?></em>
                                <?php endif; ?>
                            </span>
                        </p>

                        <p>
                            <label><strong><?php echo esc_html($a['model_label']); ?></strong><br>
                                <select name="<?php echo esc_attr($a['model_field']); ?>" class="keelhob-model-select" style="max-width:560px;">
                                    <?php foreach ($models as $m) : ?>
                                        <option value="<?php echo esc_attr($m['id']); ?>" <?php selected($chosen, $m['id']); ?>>
                                            <?php echo esc_html($m['id'] . ' - ' . $m['note']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <br>
                            <?php if ($cls::models_list_url($cls::default_endpoint()) !== '') : ?>
                                <button type="button" class="button keelhob-media-refresh" data-busy="<?php esc_attr_e('Refreshing...', 'keelhob'); ?>" style="margin-top:8px;">
                                    <?php esc_html_e('Refresh models', 'keelhob'); ?>
                                </button>
                            <?php endif; ?>
                            <button type="button" class="button keelhob-media-test" data-busy="<?php esc_attr_e('Testing...', 'keelhob'); ?>" style="margin-top:8px;">
                                <?php esc_html_e('Test connection', 'keelhob'); ?>
                            </button>
                            <span class="keelhob-provider-status" role="status" style="margin-left:8px;"></span>
                            <br><span style="color:var(--kh-muted);"><?php esc_html_e('The test only asks the provider to describe the model, so it costs nothing and generates nothing.', 'keelhob'); ?></span>
                        </p>

                        <details>
                            <summary style="cursor:pointer;"><?php esc_html_e('Advanced', 'keelhob'); ?></summary>
                            <p style="margin:8px 0 0;">
                                <label><?php esc_html_e('Custom endpoint', 'keelhob'); ?><br>
                                    <input type="text" name="<?php echo esc_attr($a['endpoint_field']); ?>" class="regular-text keelhob-endpoint-input"
                                        style="width:100%;max-width:520px;"
                                        value="<?php echo $is_current ? esc_attr($a['endpoint_value']) : ''; ?>"
                                        placeholder="<?php echo esc_attr($cls::default_endpoint()); ?>">
                                </label>
                                <br><span style="color:var(--kh-muted);">
                                    <?php esc_html_e('Pointing this at a gateway, proxy or self-hosted deployment sends your data to that host, under that host\'s terms rather than the vendor\'s. Blank uses the provider default.', 'keelhob'); ?>
                                </span>
                            </p>
                        </details>
                    </fieldset>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        self::media_provider_script();
    }

    /**
     * One script for every media block on the page.
     *
     * Bound at document level rather than per block, so the Connect page's image
     * block and the Automation page's voice block share it and cannot drift.
     *
     * The "only once" guard is in the JavaScript rather than a PHP static on
     * purpose. A PHP static is scoped to the request, which is correct in
     * WordPress and quietly wrong everywhere else - it made the second page
     * rendered in any one process come out with no script at all, and the first
     * place that bit was the harness written to prove this feature works. A
     * flag on window is true wherever the page actually runs.
     */
    private static function media_provider_script(): void
    {
        ?>
        <script>
        (function () {
            if (window.keelhobMediaBound) { return; }
            window.keelhobMediaBound = true;

            var ajax   = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var FAILED = <?php echo wp_json_encode(__('That did not go through. Check the browser console for details.', 'keelhob')); ?>;

            function each(list, fn) { Array.prototype.forEach.call(list, fn); }

            function show(block, id) {
                each(block.querySelectorAll('.keelhob-provider-row'), function (row) {
                    var on = (row.getAttribute('data-provider') === id);
                    row.hidden = !on;
                    // One flag for the whole row: a disabled fieldset takes its
                    // fields out of the form, which is what keeps the rows
                    // nobody is looking at from overwriting the one they filled
                    // in when the page is saved.
                    var fields = row.querySelector('.keelhob-provider-fields');
                    if (fields) { fields.disabled = !on; }
                });
            }

            function valueOf(row, sel) {
                var el = row.querySelector(sel);
                return el ? el.value : '';
            }

            document.addEventListener('change', function (e) {
                if (!e.target.classList || !e.target.classList.contains('keelhob-media-picker')) { return; }
                var block = e.target.closest('.keelhob-media-block');
                if (!block) { return; }
                show(block, e.target.value);
                // A provider change invalidates the previous answer. A green
                // "Connected to OpenAI" sitting beside a Google row is worse
                // than showing nothing at all.
                each(block.querySelectorAll('.keelhob-provider-status'), function (st) { st.textContent = ''; });
            });

            function fill(sel, models) {
                // The chosen model survives a refresh. Repopulating a dropdown
                // is not permission to change which model the site uses.
                var keep = sel.value;
                sel.innerHTML = '';
                models.forEach(function (m) {
                    sel.appendChild(new Option(m.id + ' - ' + m.note, m.id, false, m.id === keep));
                });
                if (!sel.value) { sel.selectedIndex = 0; }
            }

            document.addEventListener('click', function (e) {
                if (!e.target.closest) { return; }
                var btn = e.target.closest('.keelhob-media-test, .keelhob-media-refresh');
                if (!btn) { return; }
                e.preventDefault();

                var refreshing = btn.classList.contains('keelhob-media-refresh');
                var block  = btn.closest('.keelhob-media-block');
                var row    = btn.closest('.keelhob-provider-row');
                var status = row.querySelector('.keelhob-provider-status');

                var body = new URLSearchParams();
                body.set('action', refreshing ? 'keelhob_fetch_media_models' : 'keelhob_test_media');
                body.set('nonce', block.getAttribute('data-nonce'));
                body.set('kind', block.getAttribute('data-kind'));
                body.set('provider', row.getAttribute('data-provider'));
                body.set('api_key', valueOf(row, '.keelhob-key-input'));
                body.set('model', valueOf(row, '.keelhob-model-select'));
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
                    // The GLYPH carries the state, never the colour alone: --kh-good
                    // as text on a light card measures 3.30:1 and fails AA, and a
                    // colour-only signal says nothing to a colour-blind reader.
                    // --kh-ink is 18.33:1 light, 15.87:1 dark.
                    status.style.color = 'var(--kh-ink)';
                    status.textContent = (ok ? '✓ ' : '✕ ') + msg;
                    if (ok && refreshing && json.data.models) {
                        fill(row.querySelector('.keelhob-model-select'), json.data.models);
                    }
                })['catch'](function () {
                    status.style.color = 'var(--kh-ink)';
                    status.textContent = '✕ ' + FAILED;
                })['finally'](function () {
                    btn.disabled = false;
                });
            });

            each(document.querySelectorAll('.keelhob-media-block'), function (block) {
                var picker = block.querySelector('.keelhob-media-picker');
                if (picker) { show(block, picker.value); }
            });
        })();
        </script>
        <?php
    }
}
