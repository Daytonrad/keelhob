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

final class Claudpress_Studio_Actions
{
    public function hooks(): void
    {
        add_action('admin_post_claudpress_studio', [$this, 'handle']);
        add_action('wp_ajax_claudpress_studio_review', [$this, 'ajax_review']);
        add_action('wp_ajax_claudpress_studio_plan', [$this, 'ajax_plan']);
        add_action('wp_ajax_claudpress_studio_build', [$this, 'ajax_build']);
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
            wp_die(esc_html__('You do not have permission to manage snippets.', 'claudpress'), '', ['response' => 403]);
        }
        check_admin_referer(Claudpress_Studio::NONCE);
    }

    private static function guard_ajax(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do that.', 'claudpress')], 403);
        }
        check_ajax_referer(Claudpress_Studio::NONCE, 'nonce');
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
                Claudpress_Snippets::toggle($id, $action === 'activate');
                Claudpress_Studio::redirect('all', $action === 'activate' ? 'activated' : 'deactivated');
                break;
            case 'duplicate':
                $this->duplicate($id);
                break;
            case 'delete':
                Claudpress_Snippets::delete($id);
                Claudpress_Studio::redirect('all', 'deleted');
                break;
            case 'restore':
                $this->restore($id, isset($_POST['revision_id']) ? (int) $_POST['revision_id'] : 0);
                break;
            case 'use_library':
                $this->use_library(isset($_POST['library_key']) ? sanitize_key(wp_unslash($_POST['library_key'])) : '');
                break;
            default:
                Claudpress_Studio::redirect('all', 'saved');
        }
    }

    private function save(int $id): void
    {
        $activate = !empty($_POST['and_activate']);

        $result = Claudpress_Snippets::save([
            'id'           => $id ?: null,
            'name'         => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'description'  => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'snippet_type' => sanitize_key(wp_unslash($_POST['snippet_type'] ?? 'php')),
            'location'     => sanitize_key(wp_unslash($_POST['location'] ?? 'everywhere')),
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
        ]);

        $id = (int) ($result['id'] ?? $id);

        // save() forces a snippet that does not parse to inactive and records
        // the error, so "asked to activate but came back off" is exactly the
        // case where the parse refused.
        $blocked = $activate && empty($result['active']);

        Claudpress_Studio::redirect(
            $blocked ? 'edit' : 'all',
            $blocked ? 'lint' : ($activate ? 'activated' : 'saved'),
            $blocked ? ['snippet' => $id] : []
        );
    }

    private function save_composed(): void
    {
        $prompt = sanitize_textarea_field(wp_unslash($_POST['ai_prompt'] ?? ''));
        $result = Claudpress_Snippets::save([
            'name'         => sanitize_text_field(wp_unslash($_POST['name'] ?? '')) ?: __('AI snippet', 'claudpress'),
            'code'         => wp_unslash($_POST['code'] ?? ''),
            'snippet_type' => sanitize_key(wp_unslash($_POST['snippet_type'] ?? 'php')),
            'source'       => 'ai',
            'ai_prompt'    => $prompt,
            'ai_model'     => (string) Claudpress::instance()->get('agent_model', ''),
            // Always off. The whole point of Compose saving inactive is that
            // nobody has read this code yet.
            'active'       => 0,
        ]);

        Claudpress_Studio::redirect('edit', 'saved', ['snippet' => (int) ($result['id'] ?? 0)]);
    }

    private function duplicate(int $id): void
    {
        $row = Claudpress_Snippets::get($id);
        if (!$row) {
            Claudpress_Studio::redirect('all', 'saved');
        }
        $copy = Claudpress_Snippets::save([
            /* translators: %s: the original snippet's name. */
            'name'         => sprintf(__('%s (copy)', 'claudpress'), (string) $row['name']),
            'description'  => (string) $row['description'],
            'code'         => (string) $row['code'],
            'snippet_type' => (string) $row['snippet_type'],
            'location'     => (string) $row['location'],
            'priority'     => (int) $row['priority'],
            'tags'         => (string) $row['tags'],
            'source'       => (string) ($row['source'] ?? 'manual'),
            'active'       => 0, // A copy never inherits "on".
        ]);
        Claudpress_Studio::redirect('edit', 'saved', ['snippet' => (int) ($copy['id'] ?? 0)]);
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
        $current = Claudpress_Snippets::get($id);
        if (!$current || $revision_id <= 0) {
            Claudpress_Studio::redirect('all', 'saved');
        }

        $revision = null;
        foreach (Claudpress_Snippets::revisions($id, 200) as $rev) {
            if ((int) $rev['id'] === $revision_id) {
                $revision = $rev;
                break;
            }
        }
        if (!$revision) {
            Claudpress_Studio::redirect('edit', 'saved', ['snippet' => $id]);
        }

        Claudpress_Snippets::save([
            'id'     => $id,
            'code'   => (string) $revision['code'],
            'active' => 0,
        ]);
        Claudpress_Studio::redirect('edit', 'saved', ['snippet' => $id]);
    }

    private function use_library(string $key): void
    {
        if ($key === '' || !class_exists('Claudpress_Snippet_Library')) {
            Claudpress_Studio::redirect('library', 'saved');
        }
        $item = Claudpress_Snippet_Library::get($key);
        if (!$item) {
            Claudpress_Studio::redirect('library', 'saved');
        }

        $result = Claudpress_Snippets::save([
            'name'         => (string) ($item['title'] ?? $key),
            'description'  => (string) ($item['description'] ?? ''),
            'code'         => (string) ($item['code'] ?? ''),
            'snippet_type' => (string) ($item['type'] ?? 'php'),
            'location'     => (string) ($item['location'] ?? 'everywhere'),
            'tags'         => (string) ($item['tags'] ?? ''),
            'source'       => 'library',
            'origin_uid'   => $key,
            'active'       => 0,
        ]);
        Claudpress_Studio::redirect('edit', 'imported', ['snippet' => (int) ($result['id'] ?? 0)]);
    }

    // ---- AJAX ------------------------------------------------------------

    public function ajax_review(): void
    {
        self::guard_ajax();

        $code = (string) wp_unslash($_POST['code'] ?? '');
        $type = sanitize_key(wp_unslash($_POST['snippet_type'] ?? 'php'));

        if (trim($code) === '') {
            wp_send_json_error(['message' => __('There is no code to look at.', 'claudpress')]);
        }

        $review = Claudpress_Studio_AI::review($code, $type);
        if (is_wp_error($review)) {
            wp_send_json_error(['message' => $review->get_error_message()]);
        }

        // The parse result travels with the opinion, so the panel can show both.
        ob_start();
        Claudpress_Studio_Edit::review_panel([
            'ai_review'    => wp_json_encode($review),
            'ai_review_at' => current_time('mysql'),
            'last_error'   => Claudpress_Snippets::lint_php($code, $type),
        ]);
        wp_send_json_success(['html' => (string) ob_get_clean()]);
    }

    public function ajax_plan(): void
    {
        self::guard_ajax();

        $prompt = sanitize_textarea_field(wp_unslash($_POST['prompt'] ?? ''));
        if (trim($prompt) === '') {
            wp_send_json_error(['message' => __('Describe what you want first.', 'claudpress')]);
        }

        $plan = Claudpress_Studio_AI::plan($prompt);
        if (is_wp_error($plan)) {
            wp_send_json_error(['message' => $plan->get_error_message()]);
        }

        wp_send_json_success([
            'html'      => Claudpress_Studio_Compose::plan_html($plan),
            'plan_text' => wp_json_encode($plan),
        ]);
    }

    public function ajax_build(): void
    {
        self::guard_ajax();

        $prompt = sanitize_textarea_field(wp_unslash($_POST['prompt'] ?? ''));
        $plan   = (string) wp_unslash($_POST['plan'] ?? '');

        $built = Claudpress_Studio_AI::build($prompt, $plan);
        if (is_wp_error($built)) {
            wp_send_json_error(['message' => $built->get_error_message()]);
        }

        wp_send_json_success([
            'html' => Claudpress_Studio_Compose::code_html(
                $built['code'],
                $built['type'],
                Claudpress_Snippets::lint_php($built['code'], $built['type']),
                $prompt
            ),
        ]);
    }
}
