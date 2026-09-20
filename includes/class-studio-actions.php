<?php
/**
 * Every write the Studio makes, in one place.
 *
 * One handler rather than a method per screen, because the checks that matter —
 * capability, nonce, licence — then exist once and cannot be forgotten on the
 * next action somebody adds. A permission check spread across six entry points
 * is a permission check with five chances to be missed.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Studio_Actions
{
    public function hooks(): void
    {
        add_action('admin_post_keelhob_studio', [$this, 'handle']);
        add_action('wp_ajax_keelhob_studio_review', [$this, 'ajax_review']);
        add_action('wp_ajax_keelhob_studio_chat', [$this, 'ajax_chat']);
        add_action('wp_ajax_keelhob_studio_render', [$this, 'ajax_render']);
    }

    /**
     * Capability first, then nonce.
     *
     * That order is deliberate. A logged-out or under-privileged request should
     * be turned away on who it is, not on whether it presented a token — a
     * nonce failure invites "fetch a valid nonce and retry", and it also leaks
     * that the endpoint exists and is otherwise reachable.
     */
    private static function guard(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage snippets.', 'keelhob'), '', ['response' => 403]);
        }
        check_admin_referer(Keelhob_Studio::NONCE);
    }

    private static function guard_ajax(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do that.', 'keelhob')], 403);
        }
        check_ajax_referer(Keelhob_Studio::NONCE, 'nonce');
    }

    public function handle(): void
    {
        self::guard();

        $action = isset($_POST['studio_action']) ? sanitize_key(wp_unslash($_POST['studio_action'])) : '';
        $id     = isset($_POST['snippet_id']) ? (int) $_POST['snippet_id'] : 0;

        switch ($action) {
            case 'save':
                $this->save($id);
                break;
            case 'save_composed':
                $this->save_composed();
                break;
            case 'activate':
            case 'deactivate':
                Keelhob_Snippets::toggle($id, $action === 'activate');
                Keelhob_Studio::redirect('all', $action === 'activate' ? 'activated' : 'deactivated');
                break;
            case 'duplicate':
                $this->duplicate($id);
                break;
            case 'delete':
                Keelhob_Snippets::delete($id);
                Keelhob_Studio::redirect('all', 'deleted');
                break;
            case 'restore':
                $this->restore($id, isset($_POST['revision_id']) ? (int) $_POST['revision_id'] : 0);
                break;
                break;
            default:
                Keelhob_Studio::redirect('all', 'saved');
        }
    }

    private function save(int $id): void
    {
        $activate = !empty($_POST['and_activate']);

        // The form's location dropdown defaults to "everywhere", which is
        // silently wrong for css/js/html — nothing emits them there. save()
        // refuses that pair, and a refusal here would redirect away with the
        // code the owner just typed still in the POST and nowhere else. So the
        // form corrects it and says so; only the tool layer refuses.
        $type     = sanitize_key(wp_unslash($_POST['snippet_type'] ?? 'php'));
        $location = sanitize_key(wp_unslash($_POST['location'] ?? 'everywhere'));
        $moved    = $type !== 'php' && !in_array($location, Keelhob_Snippets::EMITTING_LOCATIONS, true);
        if ($moved) {
            $location = Keelhob_Snippets::default_location_for_type($type);
        }

        $result = Keelhob_Snippets::save([
            'id'           => $id ?: null,
            'name'         => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'description'  => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'snippet_type' => $type,
            'location'     => $location,
            'priority'     => (int) ($_POST['priority'] ?? 10),
            'tags'         => sanitize_text_field(wp_unslash($_POST['tags'] ?? '')),
            'source'       => sanitize_key(wp_unslash($_POST['source'] ?? 'manual')),
            // Deliberately raw. Any sanitiser corrupts valid code — && becomes
            // &amp;&amp;, angle brackets vanish — and hands the owner a broken
            // snippet with no error to explain it. Authorisation is the
            // capability and nonce check above; correctness is the parse inside
            // save() and the crash guard at runtime.
            'code'         => wp_unslash($_POST['code'] ?? ''),
            'active'       => $activate ? 1 : 0,
            /*
             * Always sent, even when empty, and that is deliberate.
             *
             * Keelhob_Snippets::save() only writes conditions when the key is
             * PRESENT, so that `snippet_save({id, tags})` from an agent cannot
             * silently wipe rules it never mentioned. This form is the opposite
             * case: it always shows the whole rule builder, so whatever it
             * submits IS the complete intent — including "I unticked everything
             * and there are no rules now", which must actually clear them.
             */
            'conditions'   => Keelhob_Snippet_Conditions::from_form(wp_unslash($_POST)),
        ]);

        $id = (int) ($result['id'] ?? $id);

        // save() forces a snippet that does not parse to inactive and records
        // the error, so "asked to activate but came back off" is exactly the
        // case where the parse refused.
        $blocked = $activate && empty($result['active']);

        // "Moved" outranks "saved" but not "does not parse": a snippet that was
        // both relocated and broken has a bigger problem than its location.
        $message = $blocked ? 'lint' : ($moved ? 'moved' : ($activate ? 'activated' : 'saved'));

        Keelhob_Studio::redirect(
            ($blocked || $moved) ? 'edit' : 'all',
            $message,
            ($blocked || $moved) ? ['snippet' => $id] : []
        );
    }

    private function save_composed(): void
    {
        $prompt = sanitize_textarea_field(wp_unslash($_POST['ai_prompt'] ?? ''));
        $type   = sanitize_key(wp_unslash($_POST['snippet_type'] ?? 'php'));
        $result = Keelhob_Snippets::save([
            'name'         => sanitize_text_field(wp_unslash($_POST['name'] ?? '')) ?: __('AI snippet', 'keelhob'),
            'code'         => wp_unslash($_POST['code'] ?? ''),
            'snippet_type' => $type,
            // Compose asks for a type and never a location, so without this a
            // composed css snippet would land at the default "everywhere" and
            // be refused — throwing away code the model just wrote.
            'location'     => Keelhob_Snippets::default_location_for_type($type),
            'source'       => 'ai',
            'ai_prompt'    => $prompt,
            'ai_model'     => (string) Keelhob::instance()->get('agent_model', ''),
            // Always off. The whole point of Compose saving inactive is that
            // nobody has read this code yet.
            'active'       => 0,
        ]);

        Keelhob_Studio::redirect('edit', 'saved', ['snippet' => (int) ($result['id'] ?? 0)]);
    }

    private function duplicate(int $id): void
    {
        $row = Keelhob_Snippets::get($id);
        if (!$row) {
            Keelhob_Studio::redirect('all', 'saved');
        }
        // A row saved before the location check existed may be a css snippet at
        // a location that cannot emit it. Copying it verbatim would be refused,
        // so the copy is put somewhere it can actually run.
        $copy_type     = (string) $row['snippet_type'];
        $copy_location = (string) $row['location'];
        if ($copy_type !== 'php' && !in_array($copy_location, Keelhob_Snippets::EMITTING_LOCATIONS, true)) {
            $copy_location = Keelhob_Snippets::default_location_for_type($copy_type);
        }

        $copy = Keelhob_Snippets::save([
            /* translators: %s: the original snippet's name. */
            'name'         => sprintf(__('%s (copy)', 'keelhob'), (string) $row['name']),
            'description'  => (string) $row['description'],
            'code'         => (string) $row['code'],
            'snippet_type' => $copy_type,
            'location'     => $copy_location,
            'priority'     => (int) $row['priority'],
            'tags'         => (string) $row['tags'],
            'source'       => (string) ($row['source'] ?? 'manual'),
            'active'       => 0, // A copy never inherits "on".
        ]);
        Keelhob_Studio::redirect('edit', 'saved', ['snippet' => (int) ($copy['id'] ?? 0)]);
    }

    /**
     * Restore an old version.
     *
     * Two rules: the current code is recorded first, so restoring is itself
     * undoable; and the snippet comes back SWITCHED OFF, because an old version
     * is code nobody has looked at recently and restoring is not a reason to
     * start running it unread.
     */
    private function restore(int $id, int $revision_id): void
    {
        $current = Keelhob_Snippets::get($id);
        if (!$current || $revision_id <= 0) {
            Keelhob_Studio::redirect('all', 'saved');
        }

        $revision = null;
        foreach (Keelhob_Snippets::revisions($id, 200) as $rev) {
            if ((int) $rev['id'] === $revision_id) {
                $revision = $rev;
                break;
            }
        }
        if (!$revision) {
            Keelhob_Studio::redirect('edit', 'saved', ['snippet' => $id]);
        }

        Keelhob_Snippets::save([
            'id'     => $id,
            'code'   => (string) $revision['code'],
            'active' => 0,
        ]);
        Keelhob_Studio::redirect('edit', 'saved', ['snippet' => $id]);
    }

    // ---- AJAX ------------------------------------------------------------

    public function ajax_review(): void
    {
        self::guard_ajax();

        $code = (string) wp_unslash($_POST['code'] ?? '');
        $type = sanitize_key(wp_unslash($_POST['snippet_type'] ?? 'php'));

        if (trim($code) === '') {
            wp_send_json_error(['message' => __('There is no code to look at.', 'keelhob')]);
        }

        $review = Keelhob_Studio_AI::review($code, $type);
        if (is_wp_error($review)) {
            wp_send_json_error(['message' => $review->get_error_message()]);
        }

        // The parse result travels with the opinion, so the panel can show both.
        ob_start();
        Keelhob_Studio_Edit::review_panel([
            'ai_review'    => wp_json_encode($review),
            'ai_review_at' => current_time('mysql'),
            'last_error'   => Keelhob_Snippets::lint_php($code, $type),
        ]);
        wp_send_json_success(['html' => (string) ob_get_clean()]);
    }

    /**
     * Re-render stored assistant turns after a browser reload.
     *
     * NO AI CALL, and that is the point. The transcript is mirrored into
     * sessionStorage as plain text, never as rendered HTML — storing markup and
     * injecting it back on load is precisely how a stored-XSS bug gets written.
     * So the text comes back here to be parsed and escaped by the same server
     * code that rendered it the first time, and the reload costs nothing.
     *
     * The parse gate is re-run rather than remembered, so a restored block
     * cannot show a stale "passed" badge over code that does not parse.
     */
    public function ajax_render(): void
    {
        self::guard_ajax();

        $raw = wp_unslash($_POST['texts'] ?? '');
        $raw = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($raw)) {
            wp_send_json_error(['message' => __('That conversation could not be read.', 'keelhob')]);
        }

        $out = [];
        foreach (array_slice($raw, 0, Keelhob_Studio_Chat::MAX_TURNS) as $text) {
            if (!is_string($text) || trim($text) === '') {
                $out[] = '';
                continue;
            }
            $text     = mb_substr($text, 0, Keelhob_Studio_Chat::MAX_TURN_CHARS);
            $segments = Keelhob_Studio_Chat::segments($text);
            foreach ($segments as $i => $seg) {
                if (($seg['kind'] ?? '') === 'code') {
                    $segments[$i]['error'] = Keelhob_Snippets::lint_php((string) $seg['code'], (string) $seg['type']);
                }
            }
            $out[] = Keelhob_Studio_Compose::message_html($segments);
        }

        wp_send_json_success(['html' => $out]);
    }

    /**
     * One turn of AI Compose.
     *
     * The browser posts the WHOLE transcript every time and the server keeps
     * none of it. That is not laziness — it is what makes "leave the tab and it
     * is gone" true rather than merely claimed, and it means a screen that can
     * generate PHP stores nothing at all until somebody clicks Save.
     *
     * The transcript is untrusted input. It is never executed and never stored;
     * it is forwarded to the provider and rendered back escaped. The caps in
     * Keelhob_Studio_Chat are what stop a tampered post turning one click
     * into an enormous bill.
     */
    public function ajax_chat(): void
    {
        self::guard_ajax();

        $raw = wp_unslash($_POST['turns'] ?? '');
        $raw = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($raw)) {
            wp_send_json_error(['message' => __('That conversation could not be read.', 'keelhob')]);
        }

        $turns = Keelhob_Studio_Chat::normalize($raw);
        if (empty($turns)) {
            wp_send_json_error(['message' => __('Type something first.', 'keelhob')]);
        }

        $budget = Keelhob_Studio_Chat::trim_history($turns);
        $reply  = Keelhob_Studio_Chat::reply($budget['turns']);

        if (is_wp_error($reply)) {
            wp_send_json_error(['message' => $reply->get_error_message()]);
        }

        $text     = (string) $reply['text'];
        $segments = Keelhob_Studio_Chat::segments($text);

        // Lint in place, so the badge on screen and the error sent back to the
        // model come from the same parse — the one the save path will run too.
        foreach ($segments as $i => $seg) {
            if (($seg['kind'] ?? '') === 'code') {
                $segments[$i]['error'] = Keelhob_Snippets::lint_php((string) $seg['code'], (string) $seg['type']);
            }
        }

        $blocks = [];
        foreach ($segments as $seg) {
            if (($seg['kind'] ?? '') === 'code') {
                $blocks[] = $seg;
            }
        }

        wp_send_json_success([
            'text'      => $text,
            'html'      => Keelhob_Studio_Compose::message_html($segments),
            'has_error' => Keelhob_Studio_Chat::has_error($blocks),
            'fix_turn'  => Keelhob_Studio_Chat::has_error($blocks)
                ? Keelhob_Studio_Chat::error_turn($blocks)
                : '',
            'dropped'   => (int) $budget['dropped'],
        ]);
    }
}
