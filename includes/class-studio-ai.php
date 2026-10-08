<?php
/**
 * The three AI calls the Studio makes: review, plan, build.
 *
 * Deliberately NOT reusing Keelhob_Agent_Runtime. That runs a tool-calling
 * loop with policy guardrails, budget caps and run accounting, which is the
 * right machinery for "go and do things to the site" and the wrong machinery
 * for "read this and tell me what you think". These three calls hand the model
 * no tools at all, so there is nothing for it to do except answer — which is
 * the strongest guarantee available that Ask AI cannot change anything.
 *
 * Every method returns a plain array or a WP_Error. Nothing here writes to the
 * database; saving is the caller's decision, made by a person clicking Save.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Studio_AI
{
    /**
     * Ask the model what a snippet does and whether it looks risky.
     *
     * The result is advisory. The deterministic parse is what gates activation,
     * and the two are shown separately on the screen so nobody confuses an
     * opinion for a fact.
     *
     * @return array|WP_Error
     */
    public static function review(string $code, string $type) {
        $system = 'You are reviewing a WordPress code snippet for a site owner who may not be a developer. '
            . 'Be specific and concrete: name the actual hooks, functions and options it touches. '
            . 'Flag anything that writes to the database, makes outbound requests, changes user capabilities, '
            . 'handles money, or disables a security check. '
            . 'Prefer "caution" over "safe" when you are unsure — a review that says "looks fine" about code that '
            . 'quietly disables an auth check is worse than no review at all. '
            . 'Reply with ONLY a JSON object, no prose around it, with keys: '
            . 'summary, verdict (safe|caution|unsafe), what_it_does, site_impact, risks (array of strings), '
            . 'recommended_type, recommended_location, suggested_title, suggested_tags (array), '
            . 'should_activate (boolean), notes.';

        $user = "Snippet type: {$type}\n\nCode:\n" . $code;

        $text = self::ask($system, $user);
        if (is_wp_error($text)) {
            return $text;
        }
        $parsed = self::json($text);
        if (is_array($parsed)) {
            return $parsed;
        }

        /*
         * A PROSE ANSWER IS AN ANSWER.
         *
         * This used to return WP_Error('...not in a form this screen could
         * read'), which threw away a perfectly good review because it arrived
         * as sentences instead of as JSON. The person saw a failure over a
         * reply that was sitting right there, and had no way to make the model
         * comply -- the prompt asking for JSON is a request, not a guarantee.
         *
         * So: keep the words, and be honest that the structured fields are
         * missing. verdict is 'caution' rather than 'safe' on purpose. An
         * unparsed review has not established that anything is fine, and a
         * screen that defaults to "safe" when it does not know is worse than a
         * screen that says nothing.
         */
        return [
            'summary'      => $text,
            'verdict'      => 'caution',
            'what_it_does' => '',
            'risks'        => [],
            'notes'        => __('The AI answered in plain words rather than the structured form this panel expects, so only its summary is shown. The syntax check below is unaffected — that is run by the site, not the AI.', 'keelhob'),
        ];
    }

    /**
     * One request, no tools, no retries beyond the transport's own.
     *
     * @return string|WP_Error
     */
    private static function ask(string $system, string $user) {
        $target = Keelhob_Provider::configured();
        $key    = trim((string) ($target['key'] ?? ''));

        if ($key === '') {
            return new WP_Error(
                'keelhob_studio_no_key',
                __('No AI provider is connected. Add a key under Keelhob > Automation.', 'keelhob')
            );
        }

        /** @var string $provider a Keelhob_Provider subclass name */
        $provider = $target['class'];
        $model    = (string) $target['model'];

        $response = wp_remote_post($provider::url((string) $target['endpoint'], $model), [
            'timeout' => 90,
            'headers' => $provider::headers($key),
            // No tools. The model has nothing it can call, so a review or a plan
            // cannot become an action against the site.
            'body'    => wp_json_encode($provider::body($model, $system, [], [$provider::user_message($user)])),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('keelhob_studio_http', $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($data) ? $provider::error_message($data) : '';
            if ($code === 401 || $code === 403) {
                $message = __('Your API key was rejected. Check it under Keelhob > Automation.', 'keelhob');
            }
            /* translators: %d: HTTP status code. */
            return new WP_Error('keelhob_studio_api', $message ?: sprintf(__('The AI provider returned HTTP %d.', 'keelhob'), $code));
        }

        if (!is_array($data)) {
            return new WP_Error('keelhob_studio_api', __('The AI provider returned something unreadable.', 'keelhob'));
        }

        $parsed = $provider::parse($data);
        $text   = trim((string) ($parsed['text'] ?? ''));

        return $text !== '' ? $text : new WP_Error('keelhob_studio_empty', __('The AI returned an empty answer.', 'keelhob'));
    }

    /**
     * Pull a JSON object out of a reply that may have prose around it.
     *
     * Models are asked for bare JSON and mostly comply, but "Here's the JSON:"
     * happens often enough that failing on it would make the feature feel
     * flaky for a reason the user cannot see or fix.
     */
    public static function json(string $text): ?array {
        $direct = json_decode($text, true);
        if (is_array($direct)) {
            return $direct;
        }
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $slice = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($slice) ? $slice : null;
    }

    /** Remove ```php fences a model wrapped the code in despite being asked not to. */
    public static function strip_fences(string $text): string {
        $text = trim($text);
        if (strpos($text, '```') === false) {
            return $text;
        }
        if (preg_match('/```[a-zA-Z]*\s*(.*?)```/s', $text, $m)) {
            return trim($m[1]);
        }
        return trim(str_replace('```', '', $text));
    }

    /**
     * Work out what kind of snippet this is from the code itself.
     *
     * Trusting the model's own label would be simpler and is not safe: a
     * mislabelled PHP snippet stored as CSS skips the parse gate entirely,
     * because CSS is not parsed. The code decides, not the label.
     *
     * The hard case is BARE PHP. Keelhob stores snippets without an opening
     * tag, so "add_filter('x', function () { ... });" is the normal shape - and
     * a naive CSS check matches it happily, because it is a selector-ish thing
     * followed by braces. PHP is therefore tested for first, on what PHP
     * actually contains, and CSS last and strictly.
     */
    public static function guess_type(string $code): string
    {
        $trimmed = ltrim($code);

        if (stripos($trimmed, '<?php') === 0 || strpos($trimmed, '<?php') !== false) {
            return 'php';
        }

        // Bare PHP: look for things only PHP has.
        //
        // Three of these patterns spent their whole life unable to match.
        // The \b word boundaries had been stored as literal 0x08 BACKSPACE
        // bytes, so the pattern read as "<backspace>add_(action..." and no
        // input could ever satisfy it. It is invisible in grep, in awk and
        // in most editors, which is why it survived.
        //
        // It was not harmless. guess_type() is what decides whether code an
        // agent wrote is php, js or css, and the type decides where it may
        // run. A bare add_action() snippet with no variable and no arrow
        // fell past every working marker and could be stored as something
        // other than PHP - which means saved to a location that never
        // executes PHP, and silently doing nothing at all.
        $php_markers = [
            '/\badd_(action|filter|shortcode|menu_page)\s*\(/i',
            // NOT "function": JavaScript has it too, and an IIFE
            // ( function () { ... } )() would be read as PHP and stored as PHP.
            // While these patterns were broken that could not happen, so
            // repairing them surfaced a bug the breakage had been hiding.
            // Only keywords PHP alone has belong in this list.
            '/\b(foreach|endforeach|endif|elseif|require_once|include_once|namespace)\b/',
            '/\$[a-zA-Z_]\w*\s*(=|->|\[)/',
            '/->\s*[a-zA-Z_]\w*\s*\(/',
            '/\b[A-Za-z_]\w*::\w+/',
        ];
        foreach ($php_markers as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return 'php';
            }
        }

        if (stripos($trimmed, '<script') === 0 || preg_match('/^\s*(document\.|window\.|jQuery|\(function)/', $trimmed)) {
            return 'js';
        }

        // CSS, strictly: a selector, a brace, and at least one "property: value".
        if (preg_match('/^[^{};]+\{[^}]*[a-z-]+\s*:\s*[^;}]+[;}]/i', $trimmed)) {
            return 'css';
        }

        if (stripos($trimmed, '<') === 0) {
            return 'html';
        }

        return 'php';
    }


    /**
     * Remove the opening <?php and any closing ?> from generated PHP.
     *
     * Snippets are stored as BARE PHP - Keelhob_Snippets::lint_php() prepends
     * its own "<?php " before parsing, and run_php() hands the body straight to
     * eval(). A snippet that arrives with its own tag becomes "<?php <?php ..."
     * at lint time and fails the parse gate, so it would save switched off with
     * a syntax error and the whole feature would look broken.
     *
     * The system prompt asks the model not to include one. This strips it
     * anyway, because an instruction in a prompt is a request, not a guarantee,
     * and the failure it prevents is silent and total.
     */
    public static function strip_php_tags(string $code, string $type): string
    {
        if ($type !== 'php') {
            return $code;
        }
        $code = preg_replace('/^\s*<\?php\s*/i', '', $code, 1);
        $code = preg_replace('/\s*\?>\s*$/', '', (string) $code, 1);

        return trim((string) $code);
    }
}
