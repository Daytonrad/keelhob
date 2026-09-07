<?php
/**
 * The front-end reachability check, shared by every risky write.
 *
 * Extracted out of Claudpress_Snippets, which was the first thing to need it:
 * a snippet returned HTTP 503 from the front end, a containerised host read
 * that as "this server is dead" and stopped routing to it — wp-admin and the
 * REST API included, with nothing having errored. execute_php, execute_sql,
 * write_file and manage_plugin/manage_theme activation can all cause the same
 * failure and none of them were checking. This class holds the check itself;
 * each risky write calls Claudpress_Health::guard() explicitly.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Health
{
    /**
     * One anonymous GET of the home page.
     *
     * No cookies, so this is what the public sees rather than what the logged-in
     * owner sees — the whole point, since the snippet that caused this let
     * logged-in users straight through.
     *
     * @return array{status:int, transport_error:string}
     */
    public static function probe_home(): array
    {
        $url = add_query_arg('claudpress_probe', (string) time(), home_url('/'));

        $res = wp_remote_get($url, [
            'timeout'     => 10,
            'redirection' => 3,
            // Self-signed and staging certificates are common and are not what
            // this check is about.
            'sslverify'   => false,
            'cookies'     => [],
            'headers'     => ['Cache-Control' => 'no-cache', 'X-Claudpress-Probe' => '1'],
            'user-agent'  => 'Claudpress/' . CLAUDPRESS_VERSION . ' (front-end reachability check)',
        ]);

        if (is_wp_error($res)) {
            return ['status' => 0, 'transport_error' => $res->get_error_message()];
        }
        return ['status' => (int) wp_remote_retrieve_response_code($res), 'transport_error' => ''];
    }

    /** 2xx and 3xx are a working site. A redirect to https or a trailing slash is not a fault. */
    public static function healthy_status(int $status): bool
    {
        return $status >= 200 && $status < 400;
    }

    /**
     * The whole decision, with no I/O in it.
     *
     * Separated from probe_home() so the rules can be tested directly. The
     * interesting cases are all about what we are NOT allowed to conclude:
     * a transport error means we learned nothing, and a site that is broken
     * with the change reverted was not broken by it.
     *
     * @param array{status:int, transport_error:string}      $probe   with the write in place
     * @param array{status:int, transport_error:string}|null $control with it reverted, or null if not run yet
     * @param string                                          $subject what was just done, named in the messages — e.g. 'this snippet', 'activating this plugin', 'that PHP'
     * @return array{checked:bool, ok:bool, needs_control:bool, status?:int, reason?:string, note?:string}
     */
    public static function verdict(array $probe, ?array $control, string $subject): array
    {
        if ($probe['transport_error'] !== '') {
            return [
                'checked'       => true,
                'ok'            => true,
                'needs_control' => false,
                'note'          => 'Could not reach this site from itself (' . $probe['transport_error']
                    . '). Many hosts block loopback requests, so this proves nothing and nothing was'
                    . ' undone. Load the site in a logged-out browser to be sure.',
            ];
        }

        if (self::healthy_status($probe['status'])) {
            return ['checked' => true, 'ok' => true, 'needs_control' => false, 'status' => $probe['status']];
        }

        if ($control === null) {
            return ['checked' => true, 'ok' => false, 'needs_control' => true, 'status' => $probe['status']];
        }

        // Broken with it reverted too — so it was already broken. Innocent.
        if ($control['transport_error'] === '' && !self::healthy_status($control['status'])) {
            return [
                'checked'       => true,
                'ok'            => true,
                'needs_control' => false,
                'status'        => $probe['status'],
                'note'          => 'The front page returns HTTP ' . $probe['status'] . ' even with the change'
                    . ' reverted, so ' . $subject . ' is not the cause. Something else is breaking the site.',
            ];
        }

        $reason = 'Undone automatically: the front page returned HTTP ' . $probe['status']
            . ' to a logged-out visitor, and returned '
            . ($control['transport_error'] === '' ? 'HTTP ' . $control['status'] : 'normally')
            . ' once reverted — ' . $subject . ' caused it.'
            . ($probe['status'] >= 500
                ? ' A 5xx on the home page makes managed and containerised hosts treat the whole site as'
                . ' dead and stop routing to it, including wp-admin. Serve alternative pages with'
                . ' status 200 and a noindex meta tag instead.'
                : ' A logged-out visitor could not load the site.');

        return [
            'checked'       => true,
            'ok'            => false,
            'needs_control' => false,
            'status'        => $probe['status'],
            'reason'        => $reason,
        ];
    }

    /**
     * Probe the front end after a risky write, and annotate the tool result if
     * it broke the site for a logged-out visitor.
     *
     * THE CONTRACT — read this before wiring guard() into a new tool:
     *
     * guard() runs $revert AT MOST ONCE, to use as the control for attribution.
     * It never runs it a second time, because a closure that undoes a change
     * has no way to undo itself. So when $revert has already run and the
     * verdict comes back innocent (the site was broken before $revert too, i.e.
     * NOT caused by $subject), the write is now reverted for no reason — and
     * putting it back is the CALLER's job, not guard()'s. Check
     * $result['front_end_check'] after calling: with a $revert given, 'FAILED'
     * means $revert fixed the site and should stay applied; 'passed' means
     * $revert ran, did not help, and the caller must undo it or an innocent
     * change stays reverted. With $revert null, 'FAILED' means the site is
     * broken and nothing was done about it — there was nothing to attribute
     * against and nothing to undo.
     *
     * @param array<string,mixed> $result  the tool result to annotate
     * @param string              $subject what was just done, for the message
     * @param callable|null       $revert  undoes it, or null when it cannot be undone
     * @return array<string,mixed>
     */
    public static function guard(array $result, string $subject, ?callable $revert = null): array
    {
        if (!apply_filters('claudpress_verify_health', true, $subject)) {
            return $result;
        }

        $probe = self::probe_home();

        if ($probe['transport_error'] !== '') {
            $verdict = self::verdict($probe, null, $subject);
            $result['note'] = trim((string) (($result['note'] ?? '') . ' ' . (string) ($verdict['note'] ?? '')));
            return $result;
        }

        if (self::healthy_status($probe['status'])) {
            return $result;
        }

        if ($revert === null) {
            $result['front_end_check']  = 'FAILED';
            $result['front_end_status'] = $probe['status'];
            $result['note'] = trim((string) (($result['note'] ?? '') . ' '
                . 'The front page now returns HTTP ' . $probe['status'] . ' to a logged-out visitor after '
                . $subject . ', and this cannot be undone automatically.'));
            return $result;
        }

        $revert();
        $control = self::probe_home();
        $verdict = self::verdict($probe, $control, $subject);

        $result['front_end_check'] = $verdict['ok'] ? 'passed' : 'FAILED';
        if (!empty($verdict['status'])) {
            $result['front_end_status'] = $verdict['status'];
        }
        // 'passed' here means "not your fault", NOT "the site is fine" — the
        // control probe only ran because the front page was already failing.
        // An agent skimming for front_end_check would read that as all clear
        // and move on, which is the exact shape of the bug this class exists
        // to catch. So the bad news gets its own unambiguous field.
        if ($verdict['ok']) {
            $result['site_down'] = true;
        }
        $message = $verdict['ok'] ? (string) ($verdict['note'] ?? '') : (string) ($verdict['reason'] ?? '');
        if ($message !== '') {
            $result['note'] = trim((string) (($result['note'] ?? '') . ' ' . $message));
        }

        return $result;
    }
}
