<?php
/**
 * The owner's side of ratings and lessons: thumbs on a logged call, and the
 * lesson editor.
 *
 * Its own file and its own admin-post targets rather than another branch of
 * Keelhob_Admin_Settings::handle_save(), for two reasons. The settings handler
 * accepts one whole screen as one decision and redirects back to it; a thumbs
 * click is a single-field write that has to work from a paginated table and
 * come back to the same page of it. And this file is a safety surface — the
 * lesson text it accepts is read by an agent that can change a live site — so
 * it is worth being able to read all of it in one place.
 *
 * NOTHING HERE DEPENDS ON JAVASCRIPT.
 *
 * Every control is a real form posting to admin-post.php. The AJAX endpoint is
 * an enhancement over the identical logic, added so a thumb does not reload a
 * long table, and the script that uses it (assets/js/admin-ratings.js) waits
 * for the server's answer before it changes anything on screen. A failed
 * request shows the error and leaves the control exactly as it was — there is
 * no optimistic update anywhere, because the thing being displayed is a record
 * of what the server stored.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Admin_Ratings
{
    const NONCE_VOTE = 'keelhob_rate_run';
    const NONCE_LESSON = 'keelhob_lesson';

    public function hooks(): void
    {
        add_action('admin_post_keelhob_rate_run', [$this, 'handle_vote']);
        add_action('admin_post_keelhob_lesson_save', [$this, 'handle_lesson_save']);
        add_action('admin_post_keelhob_lesson_delete', [$this, 'handle_lesson_delete']);
        add_action('wp_ajax_keelhob_rate_run', [$this, 'ajax_vote']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * The thumbs script, on the Activity screen and nowhere else.
     *
     * Versioned with the file's mtime like every other asset here: without it a
     * site behind a CDN serves the old script against the new markup for as
     * long as the edge cache lasts, and on this project that is four hours.
     */
    public function enqueue($hook_suffix = ''): void
    {
        if (strpos((string) $hook_suffix, 'keelhob-activity') === false) {
            return;
        }
        wp_enqueue_script(
            'keelhob-admin-ratings',
            KEELHOB_URL . 'assets/js/admin-ratings.js',
            [],
            Keelhob::asset_version('assets/js/admin-ratings.js'),
            true
        );
        wp_localize_script('keelhob-admin-ratings', 'keelhobRatings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_VOTE),
            'i18n'    => [
                'failed' => __('Could not save that. Nothing was changed.', 'keelhob'),
                'offline' => __('Could not reach the site to save that. Nothing was changed.', 'keelhob'),
            ],
        ]);
    }

    // ---- thumbs -----------------------------------------------------------

    /** 'up' / 'down' / 'clear' from a request, as the stored integer. */
    private static function vote_value(string $raw): int
    {
        if ($raw === 'up') {
            return Keelhob_Logger::THUMBS_UP;
        }
        if ($raw === 'down') {
            return Keelhob_Logger::THUMBS_DOWN;
        }
        return Keelhob_Logger::NO_VOTE;
    }

    /** The no-JavaScript path. Posts, redirects back to the same page. */
    public function handle_vote(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer(self::NONCE_VOTE)) {
            wp_die(esc_html__('You do not have permission to do that.', 'keelhob'), '', ['response' => 403]);
        }

        $id   = (int) ($_POST['run_id'] ?? 0);
        $vote = self::vote_value(sanitize_key($_POST['vote'] ?? ''));
        $ok   = Keelhob::instance()->logger->vote($id, $vote) !== null;

        $args = ['page' => 'keelhob-activity', 'log_page' => max(1, (int) ($_POST['log_page'] ?? 1))];
        $args[$ok ? 'rated' : 'rate_failed'] = 1;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#kh-runs');
        exit;
    }

    /**
     * The enhanced path. Same checks, same write, same outcome — it only
     * differs in answering with JSON instead of a redirect.
     *
     * The response carries the tool's recomputed rating, so the number next to
     * the table is whatever the server now holds rather than whatever the
     * browser guessed it would be.
     */
    public function ajax_vote(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to do that.', 'keelhob')], 403);
        }
        if (!check_ajax_referer(self::NONCE_VOTE, 'nonce', false)) {
            wp_send_json_error(['message' => __('That page has been open a while. Reload it and try again.', 'keelhob')], 400);
        }

        $id    = (int) ($_POST['run_id'] ?? 0);
        $vote  = self::vote_value(sanitize_key($_POST['vote'] ?? ''));
        $stats = Keelhob::instance()->logger->vote($id, $vote);

        if ($stats === null) {
            wp_send_json_error(['message' => __('That entry is no longer in the log, so the vote was not saved.', 'keelhob')], 404);
        }

        wp_send_json_success([
            'vote'   => $vote,
            'tool'   => (string) $stats['tool'],
            'score'  => $stats['score'],
            'label'  => (string) $stats['label'],
            'runs'   => (int) $stats['runs'],
            'rating' => $stats['score'] === null
                ? __('New', 'keelhob')
                : sprintf(
                    /* translators: 1: score out of 100, 2: word for the score, 3: number of runs. */
                    __('%1$d/100 · %2$s · %3$d runs', 'keelhob'),
                    (int) $stats['score'],
                    (string) $stats['label'],
                    (int) $stats['runs']
                ),
        ]);
    }

    // ---- lessons ----------------------------------------------------------

    /**
     * Save a new lesson, or rewrite an existing one.
     *
     * Everything the owner types goes through Keelhob_Lessons, which strips
     * markup and control characters and caps the length. This handler does not
     * get its own sanitiser: one writer, one set of guards, so there is no
     * second path on which the cap could be forgotten.
     */
    public function handle_lesson_save(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer(self::NONCE_LESSON)) {
            wp_die(esc_html__('You do not have permission to do that.', 'keelhob'), '', ['response' => 403]);
        }

        $id     = (int) ($_POST['lesson_id'] ?? 0);
        $lesson = (string) wp_unslash($_POST['lesson'] ?? '');
        $topic  = (string) wp_unslash($_POST['topic'] ?? '');

        $res = $id > 0
            ? Keelhob_Lessons::update($id, $lesson)
            : Keelhob_Lessons::save($topic, $lesson, 'owner');

        $args = ['page' => 'keelhob-activity'];
        if (empty($res['success'])) {
            $args['lesson_error'] = 1;
        } else {
            $args['lesson_saved'] = 1;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')) . '#kh-lessons');
        exit;
    }

    public function handle_lesson_delete(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer(self::NONCE_LESSON)) {
            wp_die(esc_html__('You do not have permission to do that.', 'keelhob'), '', ['response' => 403]);
        }
        Keelhob_Lessons::delete((int) ($_POST['lesson_id'] ?? 0));
        wp_safe_redirect(add_query_arg(['page' => 'keelhob-activity', 'lesson_deleted' => 1], admin_url('admin.php')) . '#kh-lessons');
        exit;
    }
}
