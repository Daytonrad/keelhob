<?php
/**
 * Everything that happens when the owner presses Save.
 *
 * One POST target handles the whole settings screen — the capability toggles,
 * the bearer token, the provider keys, the notification channels and the
 * consent record — because they are submitted as one form and have to be
 * accepted or rejected as one decision.
 *
 * Two rules run through the file. A secret is only overwritten when a new one
 * was actually typed, so an owner who saves an unrelated toggle never silently
 * wipes a key they cannot see. And every path ends at redirect_back(), so the
 * browser lands on the screen the form came from rather than on admin-post.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Admin_Settings
{
    /**
     * Store an endpoint only when it genuinely overrides the provider default.
     *
     * Without this, switching provider would leave the previous vendor's URL
     * behind and every request would go to the wrong host with the right key.
     * Storing '' means "follow whichever provider is selected".
     */
    private static function endpoint_override(string $submitted, string $default): string
    {
        $submitted = trim($submitted);
        return ($submitted === '' || untrailingslashit($submitted) === untrailingslashit($default)) ? '' : $submitted;
    }

    /** Every admin screen this plugin owns. Also the redirect whitelist. */
    private static function page_slugs(): array
    {
        return ['keelhob', 'keelhob-snippets', 'keelhob-automation', 'keelhob-guide', 'keelhob-activity'];
    }

    /**
     * Send the user back to the page they submitted from, preserving its
     * filters.
     *
     * The slug is whitelisted rather than trusted: it arrives in the POST body,
     * and a free-text redirect target is an open-redirect vector.
     */
    private static function redirect_back(): void
    {
        $page = sanitize_key($_POST['keelhob_redirect_page'] ?? '');
        if (!in_array($page, self::page_slugs(), true)) {
            $page = 'keelhob';
        }
        $args = ['page' => $page, 'saved' => 1];

        $filters = json_decode((string) wp_unslash($_POST['keelhob_filters'] ?? ''), true);
        if (is_array($filters)) {
            foreach (['search' => 's', 'tag' => 'tag', 'snippet_type' => 'type', 'active' => 'active'] as $key => $arg) {
                $value = isset($filters[$key]) ? sanitize_text_field((string) $filters[$key]) : '';
                if ($value !== '') {
                    $args[$arg] = $value;
                }
            }
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('keelhob_save')) {
            wp_die(esc_html__('Unauthorized', 'keelhob'));
        }
        $action = $_POST['keelhob_action'] ?? 'settings';

        if ($action === 'regenerate_token') {
            Keelhob::set_token();
        } elseif ($action === 'settings') {
            // Record WHO accepted and WHEN, not merely that the box is ticked.
            // If someone later says they never agreed to an AI editing their
            // live site, a timestamp and a user id is the whole answer, and it
            // has to be captured at the moment of the click or not at all.
            $consent = self::consent_from_post();
            Keelhob::instance()->update([
                'abilities_enabled'   => $consent['abilities_enabled'],
                'consent_by'          => $consent['consent_by'],
                'consent_at'          => $consent['consent_at'],
                'consent_version'     => $consent['consent_version'],
                'allow_app_passwords' => !empty($_POST['allow_app_passwords']),
                'require_https'       => !empty($_POST['require_https']),
                'log_calls'           => !empty($_POST['log_calls']),
                'feedback_log'        => !empty($_POST['feedback_log']),
                'delete_data_on_uninstall' => !empty($_POST['delete_data_on_uninstall']),
                'skip_checkin'             => !empty($_POST['skip_checkin']),

                'notify_channels'          => self::channels_from_post(),
                'fs_base'             => !empty($_POST['fs_base']) ? sanitize_text_field(wp_unslash($_POST['fs_base'])) : ABSPATH,
                'run_snippets'        => !empty($_POST['run_snippets']),
                'site_label'          => sanitize_text_field(wp_unslash($_POST['site_label'] ?? '')),
                'builder'             => sanitize_key($_POST['builder'] ?? 'auto'),
                'image_provider'      => sanitize_key($_POST['image_provider'] ?? 'openai'),
                'image_api_key'       => self::secret_from_post('image_api_key'),
                'image_api_endpoint'  => self::endpoint_override(
                    esc_url_raw(wp_unslash($_POST['image_api_endpoint'] ?? '')),
                    Keelhob_Image_Provider::get(sanitize_key($_POST['image_provider'] ?? 'openai'))::default_endpoint()
                ),
                'image_api_model'     => sanitize_text_field(wp_unslash($_POST['image_api_model'] ?? '')),
                'deploy_target_url'   => esc_url_raw(wp_unslash($_POST['deploy_target_url'] ?? '')),
                'deploy_target_token' => self::secret_from_post('deploy_target_token'),
            ]);
        } elseif ($action === 'clear_memory') {
            Keelhob::instance()->memory->clear();
        } elseif ($action === 'clear_feedback') {
            // Local only, and immediate. A log the owner cannot wipe is a log
            // the owner cannot trust, and trust is the entire basis on which
            // this feature is allowed to default to on.
            Keelhob_Feedback::clear();
        } elseif ($action === 'forget_feedback') {
            // The other half: ask the server to drop everything it holds for
            // this site. Anonymous data normally cannot honour a deletion
            // request because nothing can find it - this site knows its own
            // UUID, so it can ask.
            Keelhob_Feedback::forget_remote();
            Keelhob_Feedback::clear();
        } elseif ($action === 'oauth_toggle') {
            /*
             * One switch, its own action, its own form.
             *
             * The posted value is the value to STORE, not a checkbox state, so
             * there is no "unchecked sends nothing" trap here — the button says
             * what it will do and the hidden field carries exactly that. A
             * checkbox would have needed the same disabled-field reasoning as
             * channels_from_post(), for a control that has two states and one
             * button.
             */
            $on = isset($_POST['oauth_enabled']) && $_POST['oauth_enabled'] === '1';
            Keelhob::instance()->update([Keelhob_OAuth::SETTING => $on]);
            /*
             * Switching it OFF does not delete the registered clients, and that
             * is on purpose. Every token they hold stops working immediately
             * anyway — user_for_access_token() refuses everything while the
             * feature is off — so deleting the list would buy nothing and would
             * mean an owner who switched it off to think about it had to
             * re-approve every app afterwards. The Remove button is how a
             * client is actually revoked.
             */
        } elseif ($action === 'oauth_remove_client') {
            $client_id = isset($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '';
            if ($client_id !== '') {
                Keelhob_OAuth_Clients::remove($client_id);
            }
        } elseif ($action === 'capabilities') {
            /*
             * EVERY group gets an explicit value here, including the ones that
             * already matched their default. That is deliberate, and it has
             * been questioned twice, so the reasoning lives here now.
             *
             * The tempting change is to store only the groups that DIFFER from
             * Keelhob_Capabilities::default_enabled(), so the defaults stay
             * live. Do not make it. It destroys the only thing this array is
             * for: telling "the owner chose this" apart from "nobody has said".
             * A group the owner deliberately switched ON would become
             * indistinguishable from one that is merely on by default, and the
             * next time a default changed it would flip underneath them.
             * tests/unit/test-capability-save.php pins both halves.
             *
             * What this does NOT break: a group added in a LATER version is
             * absent from the stored array, so default_enabled() still decides
             * it. New sensitive groups still arrive switched off.
             *
             * And a default change that MUST reach saved sites is not this
             * array's job either. Keelhob::upgrade_from_paid_tier() is the
             * pattern: force the value, guard on evidence rather than on a
             * version number, and tell the owner in an admin notice. Its own
             * comment says why "only if no value is stored" is exactly the
             * version of that which does not work.
             */
            Keelhob::instance()->update(['capabilities' => self::caps_from_post()]);
        } elseif ($action === 'automation_settings') {
            Keelhob::instance()->update([
                'agent_provider'        => sanitize_key($_POST['agent_provider'] ?? 'anthropic'),
                'agent_api_key'         => self::secret_from_post('agent_api_key'),
                'agent_api_endpoint'    => self::endpoint_override(
                    esc_url_raw(wp_unslash($_POST['agent_api_endpoint'] ?? '')),
                    Keelhob_Provider::get(sanitize_key($_POST['agent_provider'] ?? 'anthropic'))::default_endpoint()
                ),
                'agent_model'           => Keelhob_Admin_Providers::model_from_post(),
                'agent_max_usd_per_run'    => max(0, (float) ($_POST['agent_max_usd_per_run'] ?? 0)),
                'agent_max_tokens_per_run' => max(0, (int) ($_POST['agent_max_tokens_per_run'] ?? 0)),
                'agent_max_usd_per_day'    => max(0, (float) ($_POST['agent_max_usd_per_day'] ?? 0)),
                'agent_max_runs_per_day'   => max(0, (int) ($_POST['agent_max_runs_per_day'] ?? 0)),
                'notify_email'          => sanitize_text_field(wp_unslash($_POST['notify_email'] ?? '')),
                'notify_webhook_url'    => esc_url_raw(wp_unslash($_POST['notify_webhook_url'] ?? '')),
                'notify_telegram_token' => self::secret_from_post('notify_telegram_token'),
                'notify_telegram_chat'  => sanitize_text_field(wp_unslash($_POST['notify_telegram_chat'] ?? '')),
                'policy' => [
                    'enabled'       => !empty($_POST['policy_enabled']),
                    'blocked_tools' => array_filter(array_map('trim', explode(',', (string) wp_unslash($_POST['policy_blocked_tools'] ?? '')))),
                    'max_refund'    => (float) ($_POST['policy_max_refund'] ?? 0),
                    'require_optin' => !empty($_POST['policy_require_optin']),
                ],
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'keelhob-automation', 'saved' => 1], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_create') {
            Keelhob_Tasks::save([
                'name'     => sanitize_text_field(wp_unslash($_POST['task_name'] ?? 'Status digest')),
                'mode'     => 'digest',
                'schedule' => sanitize_key($_POST['task_schedule'] ?? 'daily'),
                'run_hour' => ($_POST['task_run_hour'] ?? '') === '' ? null : (int) $_POST['task_run_hour'],
                'notify'   => !empty($_POST['task_notify']),
                'enabled'  => true,
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'keelhob-automation', 'saved' => 1], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_run') {
            Keelhob_Tasks::run_task((int) ($_POST['task_id'] ?? 0));
            wp_safe_redirect(add_query_arg(['page' => 'keelhob-automation', 'ran' => 1], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_toggle') {
            $t = Keelhob_Tasks::get((int) ($_POST['task_id'] ?? 0));
            if ($t) {
                Keelhob_Tasks::toggle((int) $t['id'], (int) $t['enabled'] !== 1);
            }
            wp_safe_redirect(add_query_arg(['page' => 'keelhob-automation'], admin_url('admin.php')));
            exit;
        } elseif ($action === 'task_delete') {
            Keelhob_Tasks::delete((int) ($_POST['task_id'] ?? 0));
            wp_safe_redirect(add_query_arg(['page' => 'keelhob-automation'], admin_url('admin.php')));
            exit;
        } elseif ($action === 'telegram_settings') {
            Keelhob::instance()->update([
                'telegram_bot_enabled'   => !empty($_POST['telegram_bot_enabled']),
                'telegram_allowed_chats' => sanitize_text_field(wp_unslash($_POST['telegram_allowed_chats'] ?? '')),
                'telegram_history_turns' => max(0, min(60, (int) ($_POST['telegram_history_turns'] ?? 12))),
                'telegram_allow_risky'   => !empty($_POST['telegram_allow_risky']),
                'telegram_voice'         => !empty($_POST['telegram_voice']),
                'telegram_photos'        => !empty($_POST['telegram_photos']),
                'telegram_progress'      => !empty($_POST['telegram_progress']),
                'voice_provider'         => sanitize_key($_POST['voice_provider'] ?? 'openai'),
                'voice_api_key'          => self::secret_from_post('voice_api_key'),
                'voice_model'            => sanitize_text_field(wp_unslash($_POST['voice_model'] ?? '')),
                'voice_api_endpoint'     => self::endpoint_override(
                    esc_url_raw(wp_unslash($_POST['voice_api_endpoint'] ?? '')),
                    Keelhob_Transcribe_Provider::get(sanitize_key($_POST['voice_provider'] ?? 'openai'))::default_endpoint()
                ),
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'keelhob-automation', 'saved' => 1], admin_url('admin.php')) . '#chat');
            exit;
        } elseif ($action === 'telegram_connect') {
            $res = Keelhob_Telegram::set_webhook();
            wp_safe_redirect(add_query_arg([
                'page'    => 'keelhob-automation',
                'tg'      => !empty($res['ok']) ? 'connected' : 'failed',
                'tg_msg'  => rawurlencode((string) ($res['description'] ?? '')),
            ], admin_url('admin.php')) . '#chat');
            exit;
        } elseif ($action === 'telegram_disconnect') {
            Keelhob_Telegram::delete_webhook();
            wp_safe_redirect(add_query_arg(['page' => 'keelhob-automation', 'tg' => 'disconnected'], admin_url('admin.php')) . '#chat');
            exit;
        } elseif ($action === 'telegram_rotate_secret') {
            // Rotating invalidates the old webhook registration, so re-register
            // in the same step — otherwise every delivery would 403 silently.
            Keelhob::instance()->update(['telegram_secret' => Keelhob_Telegram::generate_secret()]);
            $res = Keelhob_Telegram::enabled() ? Keelhob_Telegram::set_webhook() : ['ok' => true];
            wp_safe_redirect(add_query_arg([
                'page' => 'keelhob-automation',
                'tg'   => !empty($res['ok']) ? 'rotated' : 'failed',
            ], admin_url('admin.php')) . '#chat');
            exit;
        } elseif ($action === 'events_settings') {
            Keelhob::instance()->update([
                'events_enabled'   => !empty($_POST['events_enabled']),
                'events_order'     => !empty($_POST['events_order']),
                'events_order_min' => max(0, (float) ($_POST['events_order_min'] ?? 0)),
                'events_stock'     => !empty($_POST['events_stock']),
                'events_snippet'   => !empty($_POST['events_snippet']),
                'events_updates'   => !empty($_POST['events_updates']),
            ]);
            wp_safe_redirect(add_query_arg(['page' => 'keelhob-automation', 'saved' => 1], admin_url('admin.php')) . '#events');
            exit;
        } elseif ($action === 'telegram_clear_chats') {
            $n = Keelhob_Telegram::clear_all();
            wp_safe_redirect(add_query_arg(['page' => 'keelhob-automation', 'tg' => 'cleared', 'tg_n' => $n], admin_url('admin.php')) . '#chat');
            exit;
        } elseif ($action === 'snippet_toggle') {
            Keelhob_Snippets::toggle((int) ($_POST['snippet_id'] ?? 0), (string) ($_POST['snippet_active'] ?? '0') === '1');
            self::redirect_back();
        } elseif ($action === 'snippet_update') {
            Keelhob_Snippets::save([
                'id'   => (int) ($_POST['snippet_id'] ?? 0),
                'name' => sanitize_text_field(wp_unslash($_POST['snippet_name'] ?? '')),
                'tags' => sanitize_text_field(wp_unslash($_POST['snippet_tags'] ?? '')),
                // Deliberately raw: any sanitiser corrupts valid PHP/JS/CSS
                // (&& → &amp;&amp;, angle brackets stripped) and would hand the
                // owner a broken snippet with no error. Authorisation is the
                // manage_options + nonce check above; correctness is the parse
                // check inside save() and the runtime crash guard.
                'code' => wp_unslash($_POST['code'] ?? ''),
            ]);
            self::redirect_back();
        } elseif ($action === 'snippet_delete') {
            Keelhob_Snippets::delete((int) ($_POST['snippet_id'] ?? 0));
            self::redirect_back();
        } elseif ($action === 'test_notify') {
            // The address typed next to this button, not the stored one. The
            // form above is a different form: typing an address there and
            // pressing this posted nothing, so the test always went to the
            // saved address and "sent" was reported regardless.
            $test_to = sanitize_email(wp_unslash($_POST['test_notify_email'] ?? ''));

            $results = Keelhob_Notifier::send(
                '[' . get_bloginfo('name') . '] Keelhob test',
                'This is a test notification from Keelhob. If you are reading it, this channel works.',
                null,
                null,
                $test_to !== '' ? $test_to : null
            );

            // Hand the outcome back to the screen. A test whose result is not
            // shown is not a test, it is a button.
            set_transient('keelhob_test_notify_result', [
                'at'      => time(),
                'to'      => $test_to,
                'results' => $results,
                'health'  => Keelhob_Notifier::health(),
            ], 5 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg(['page' => 'keelhob-automation', 'tested' => 1], admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg(['page' => 'keelhob', 'saved' => 1], admin_url('admin.php')));
        exit;
    }

    /**
     * Read a credential field from $_POST, honouring the mask.
     *
     * The form renders a mask rather than the stored key, so an unchanged form
     * posts that mask straight back. Writing it blindly would replace every
     * saved credential with a row of dots the first time anyone saved an
     * unrelated setting on the same screen - which is exactly the sort of
     * silent, delayed breakage that is miserable to diagnose.
     *
     * So: a posted mask means 'leave it alone', an empty string means 'clear it',
     * and anything else is a genuinely new key.
     */
    private static function secret_from_post(string $field): string
    {
        $posted = sanitize_text_field(wp_unslash($_POST[$field] ?? ''));

        if (Keelhob_Secrets::is_mask($posted)) {
            return (string) Keelhob::instance()->get($field, '');
        }
        return $posted;
    }

    /**
     * Work out the consent fields for a settings save.
     *
     * Turning abilities ON records a fresh acceptance. Turning them OFF leaves
     * the record alone - it is a historical fact, not a current state, and
     * wiping it would destroy the only evidence that it ever happened.
     *
     * CONSENT_VERSION is bumped in code when what the owner is agreeing to
     * materially changes: a new provider host, a materially wider capability
     * set. A stored version older than the current one means the site re-asks.
     *
     * @return array{abilities_enabled:bool, consent_by:int, consent_at:int, consent_version:int}
     */
    private static function consent_from_post(): array
    {
        $s       = Keelhob::instance()->settings();
        $ticked  = !empty($_POST['abilities_enabled']);
        $was_on  = !empty($s['abilities_enabled']);
        $current = (int) ($s['consent_version'] ?? 0);

        if ($ticked && (!$was_on || $current < Keelhob_Admin::CONSENT_VERSION)) {
            return [
                'abilities_enabled' => true,
                'consent_by'        => get_current_user_id(),
                'consent_at'        => time(),
                'consent_version'   => Keelhob_Admin::CONSENT_VERSION,
            ];
        }

        return [
            'abilities_enabled' => $ticked,
            'consent_by'        => (int) ($s['consent_by'] ?? 0),
            'consent_at'        => (int) ($s['consent_at'] ?? 0),
            'consent_version'   => $current,
        ];
    }

    /**
     * Merge posted notification channels over the stored preference.
     *
     * The naive version of this is a straight read of $_POST, and it is wrong
     * in a way that only shows up later. A DISABLED checkbox submits nothing.
     * So a channel whose credentials are missing - the very reason its box is
     * disabled - would be absent from the post, get treated as "unticked", and
     * be quietly dropped from the preference. Reconnecting Telegram would then
     * NOT resume the digests, and nothing on screen would explain why.
     *
     * So the posted value is only trusted for channels the owner could actually
     * have touched. For the rest, the stored preference is carried through
     * untouched.
     *
     * @return string[]
     */
    /**
     * The capability array the form means, as a value that can be tested.
     *
     * Extracted from handle_save() for exactly one reason: handle_save() checks
     * a nonce and ends in a redirect, so the rule this method carries could not
     * be asserted while it lived inside it. Same shape, and the same reason, as
     * channels_from_post() above.
     *
     * @return array<string, bool>
     */
    private static function caps_from_post(): array
    {
        $posted = isset($_POST['cap']) && is_array($_POST['cap'])
            ? array_map('sanitize_key', array_keys($_POST['cap']))
            : [];

        $caps = [];
        foreach (array_keys(Keelhob_Capabilities::groups()) as $group) {
            $caps[$group] = in_array($group, $posted, true);
        }
        return $caps;
    }

    private static function channels_from_post(): array
    {
        $posted    = array_map('sanitize_key', (array) ($_POST['notify_channels'] ?? []));
        $stored    = Keelhob_Notifier::chosen_channels();
        $available = Keelhob_Notifier::availability();
        $out       = [];

        foreach (Keelhob_Notifier::CHANNELS as $channel) {
            $editable = !empty($available[$channel]['connected']);
            $keep     = $editable
                ? in_array($channel, $posted, true)
                : in_array($channel, $stored, true);

            if ($keep) {
                $out[] = $channel;
            }
        }
        return $out;
    }
}
