<?php
/**
 * AI Compose, as a conversation.
 *
 * WHAT THIS REPLACES, AND WHY.
 *
 * Compose used to be three one-shot calls: plan, then build, then save. Each
 * call was a fresh request carrying exactly one message, so the model never saw
 * what it had said a moment earlier. That produced two failures nobody could
 * work around from the screen:
 *
 *   1. plan() DEMANDED a JSON object back. A model that answered in prose --
 *      which is the normal thing for a model asked to think out loud -- got its
 *      whole reply thrown away behind "The AI replied, but not in a form this
 *      screen could read." Nothing was wrong with the answer. The screen simply
 *      only spoke JSON.
 *   2. When built code failed the syntax check, the error was rendered for the
 *      PERSON and never shown to the MODEL. There was no turn to put it in. So
 *      a one-character mistake was a dead end: save it broken, or start over.
 *
 * Both are the same root cause -- no conversation -- so both are fixed by the
 * same change rather than by two patches.
 *
 * THE SHAPE NOW. The browser holds the transcript and posts all of it on every
 * send. This class normalises what arrives, trims it to a budget, prepends a
 * system prompt that describes THIS site and THIS snippet format, and asks the
 * provider. Code arrives in fenced blocks, not JSON, because a model that is
 * told to answer with a fenced block does so far more reliably than one told to
 * answer with bare JSON -- and because a malformed fence still leaves readable
 * prose on screen, where malformed JSON left nothing at all.
 *
 * THE RULE THAT DOES NOT MOVE: nothing here writes to the snippets table.
 * Saving is still a person clicking Save, and it still saves switched off.
 *
 * TRUST. The transcript is posted by the browser, so it is untrusted input. It
 * is never executed, never stored, and never interpreted as an instruction by
 * this plugin -- it is text forwarded to a provider and text rendered back into
 * the page, escaped. The caps below exist so a tampered post cannot turn one
 * click into an enormous bill.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Studio_Chat
{
    /**
     * Budget caps.
     *
     * A chat grows without limit and every send pays for the whole thing, so
     * the cost of a long conversation is quadratic if nothing bounds it. These
     * are deliberately generous -- the point is to stop a runaway, not to cut
     * somebody off mid-thought.
     */
    const MAX_TURNS        = 40;
    const MAX_TURN_CHARS   = 24000;
    const MAX_HISTORY_CHARS = 90000;

    /** Fence languages we understand, mapped to snippet types. */
    const LANGS = [
        'php'        => 'php',
        'css'        => 'css',
        'js'         => 'js',
        'javascript' => 'js',
        'html'       => 'html',
    ];

    /**
     * Clean a transcript that arrived from the browser.
     *
     * Anything that is not a recognised role is dropped rather than coerced: a
     * turn whose role cannot be read is a turn whose meaning cannot be read,
     * and guessing produces a conversation the model will answer wrongly.
     *
     * @param  array $raw  [ ['role'=>'user'|'assistant','text'=>string], ... ]
     * @return array same shape, cleaned
     */
    public static function normalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $role = isset($turn['role']) ? (string) $turn['role'] : '';
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $text = isset($turn['text']) ? (string) $turn['text'] : '';

            // NOT sanitize_textarea_field(). Code is the payload here, and that
            // function strips tags -- which eats every line of an HTML snippet
            // and any PHP that prints markup. The text is escaped where it is
            // RENDERED instead, which is the only place escaping is correct.
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > self::MAX_TURN_CHARS) {
                $text = mb_substr($text, 0, self::MAX_TURN_CHARS) . "\n\n[trimmed]";
            }
            $out[] = ['role' => $role, 'text' => $text];
        }
        return $out;
    }

    /**
     * Drop the oldest turns until the conversation fits the budget.
     *
     * Oldest-first, because the end of a conversation is what the next answer
     * depends on. The most recent turn is always kept even if it alone busts
     * the budget -- refusing to answer the thing somebody just typed would be a
     * worse failure than one expensive request.
     *
     * @return array{turns:array,dropped:int}
     */
    public static function trim_history(array $turns): array
    {
        $dropped = 0;

        while (count($turns) > self::MAX_TURNS) {
            array_shift($turns);
            $dropped++;
        }

        $total = 0;
        foreach ($turns as $t) {
            $total += mb_strlen($t['text']);
        }
        while ($total > self::MAX_HISTORY_CHARS && count($turns) > 1) {
            $gone   = array_shift($turns);
            $total -= mb_strlen($gone['text']);
            $dropped++;
        }

        return ['turns' => array_values($turns), 'dropped' => $dropped];
    }

    /** The one fence pattern. Everything that reads a reply reads it through this. */
    const FENCE = '/```[ \t]*([a-zA-Z0-9_+-]*)[ \t]*\r?\n(.*?)(?:```|\z)/s';

    /**
     * Break a reply into ordered segments of prose and code.
     *
     * ORDERED, and that is the whole point. Rendering prose and code separately
     * and then stacking them would put every explanation above every block, so
     * "here is the CSS, and here is the PHP that needs it" would arrive as two
     * unlabelled blocks under one paragraph. The model writes in an order; the
     * screen has to keep it.
     *
     * The closing fence is optional on purpose. A reply cut short by a token
     * limit ends mid-block, and the half-written code is still the most useful
     * thing on the screen -- hiding it because the fence never closed would
     * hide the very thing the person asked for.
     *
     * @return array<int, array{kind:string,text?:string,lang?:string,type?:string,code?:string}>
     */
    public static function segments(string $text): array
    {
        $out    = [];
        $offset = 0;

        if (preg_match_all(self::FENCE, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $start = (int) $hit[0][1];
                $prose = substr($text, $offset, $start - $offset);
                if (trim($prose) !== '') {
                    $out[] = ['kind' => 'text', 'text' => trim($prose)];
                }
                $offset = $start + strlen((string) $hit[0][0]);

                $code = rtrim((string) $hit[2][0]);
                if (trim($code) === '') {
                    continue;
                }
                $lang = strtolower(trim((string) $hit[1][0]));
                $type = self::LANGS[$lang] ?? Keelhob_Studio_AI::guess_type($code);

                // Snippets are stored BARE. A block that arrives with its own
                // opening tag would become "<?php <?php ..." at lint time and
                // fail the parse gate, so it would save switched off with a
                // syntax error the model did not actually make.
                $code = Keelhob_Studio_AI::strip_php_tags($code, $type);
                if (trim($code) === '') {
                    continue;
                }

                $out[] = [
                    'kind' => 'code',
                    'lang' => $lang !== '' ? $lang : $type,
                    'type' => $type,
                    'code' => $code,
                ];
            }
        }

        $tail = substr($text, $offset);
        if (trim($tail) !== '') {
            $out[] = ['kind' => 'text', 'text' => trim($tail)];
        }

        return $out;
    }

    /**
     * Just the code blocks, in order.
     *
     * @return array<int, array{lang:string,type:string,code:string}>
     */
    public static function blocks(string $text): array
    {
        $out = [];
        foreach (self::segments($text) as $seg) {
            if (($seg['kind'] ?? '') === 'code') {
                $out[] = ['lang' => $seg['lang'], 'type' => $seg['type'], 'code' => $seg['code']];
            }
        }
        return $out;
    }

    /**
     * Run the parse gate over every block in a reply.
     *
     * Same function the editor and the save path use, so what the chat says
     * about a block is what the site will say about it later. A second opinion
     * that disagrees with the gate would be worse than none.
     *
     * @param  array $blocks from blocks()
     * @return array same blocks, each with an added 'error' key ('' when clean)
     */
    public static function lint_blocks(array $blocks): array
    {
        foreach ($blocks as $i => $b) {
            $blocks[$i]['error'] = Keelhob_Snippets::lint_php((string) $b['code'], (string) $b['type']);
        }
        return $blocks;
    }

    /** True when at least one block in a reply failed the parse gate. */
    public static function has_error(array $blocks): bool
    {
        foreach ($blocks as $b) {
            if (trim((string) ($b['error'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * The message the site sends back when a block fails the parse gate.
     *
     * Written as something a person could have typed, and labelled as coming
     * from the site, because it appears in the transcript where the person can
     * read it. A hidden correction turn would make the conversation on screen
     * disagree with the conversation the model actually had.
     */
    public static function error_turn(array $blocks): string
    {
        $lines = [];
        foreach ($blocks as $i => $b) {
            $err = trim((string) ($b['error'] ?? ''));
            if ($err === '') {
                continue;
            }
            $lines[] = sprintf('Block %d (%s): %s', $i + 1, (string) $b['type'], $err);
        }

        return "The site ran its syntax check on that code and it did not parse:\n\n"
            . implode("\n", $lines)
            . "\n\nFix it and send the corrected snippet back as a single fenced code block. "
            . 'Remember snippets are stored without an opening <?php tag.';
    }

    /**
     * What the model is told about where it is.
     *
     * Compose used to send NONE of this. The model was asked to write code for
     * a WordPress site it knew nothing about -- not the PHP version, not
     * whether WooCommerce was even installed -- and then judged on whether the
     * code fitted. Most of what looked like a bad model was a missing briefing.
     */
    public static function site_facts(): array
    {
        $plugins = [];
        if (function_exists('get_option')) {
            foreach ((array) get_option('active_plugins', []) as $p) {
                $plugins[] = (string) $p;
            }
        }
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;

        return [
            'wp'      => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '',
            'php'     => PHP_VERSION,
            'theme'   => $theme ? (string) $theme->get('Name') : '',
            'woo'     => class_exists('WooCommerce'),
            'plugins' => array_slice($plugins, 0, 30),
        ];
    }

    /** The system prompt: the format rules first, then the site. */
    public static function system_prompt(array $facts): string
    {
        $locations = 'everywhere, admin_only, frontend_only, header, footer, '
            . 'before_content, after_content, shortcode, page_template';

        $lines = [
            'You are helping a WordPress site owner write a code snippet inside Keelhob.',
            'You are talking in a chat window. Answer like a person: plain sentences, no JSON wrapper, no preamble about what you are about to do.',
            '',
            'HOW CODE MUST BE SENT.',
            'When you propose code, put it in exactly ONE fenced block tagged with its language: php, css, js or html.',
            'Never put explanation inside the fence. Explanation goes in the sentences around it.',
            'Snippets are stored as BARE code: do NOT write an opening <?php tag and do NOT write a closing ?> tag.',
            'Write one self-contained snippet. Prefix your function names so they cannot collide with a theme or plugin.',
            'Guard anything that assumes a plugin is present, and fail quietly rather than fatally.',
            '',
            'WHAT HAPPENS NEXT.',
            'The site runs a real PHP parse check on every block you send and shows the result on screen.',
            'If it fails, you will be sent the parse error as the next message. Fix it and resend the whole corrected block.',
            'Nothing you write is saved or run until the owner clicks Save, and it always saves switched OFF.',
            '',
            'WHERE THE CODE WILL RUN.',
            'A snippet has a type (php, css, js, html) and a location. Locations are: ' . $locations . '.',
            'css, js and html only run at an emitting location such as header or footer.',
            '',
            'THIS SITE.',
            'WordPress ' . ($facts['wp'] ?: 'unknown') . ' on PHP ' . ($facts['php'] ?: 'unknown') . '.',
            'Active theme: ' . ($facts['theme'] ?: 'unknown') . '.',
            'WooCommerce is ' . (!empty($facts['woo']) ? 'ACTIVE' : 'NOT active - do not write WooCommerce hooks unless asked') . '.',
        ];

        if (!empty($facts['plugins'])) {
            $lines[] = 'Active plugins: ' . implode(', ', (array) $facts['plugins']) . '.';
        }

        return implode("\n", $lines);
    }

    /**
     * One round trip: the whole conversation in, one assistant turn out.
     *
     * Deliberately NOT Keelhob_Agent_Runtime. That runs a tool-calling loop
     * against the live site. This hands the model no tools at all, so the
     * strongest statement available about this screen stays true: a chat about
     * code cannot become an action against the site.
     *
     * @param  array $turns normalised, trimmed
     * @return array{text:string}|WP_Error
     */
    public static function reply(array $turns)
    {
        $target = Keelhob_Provider::configured();
        $key    = trim((string) ($target['key'] ?? ''));

        if ($key === '') {
            return new WP_Error(
                'keelhob_chat_no_key',
                __('No AI provider is connected. Add a key under Keelhob > Automation.', 'keelhob')
            );
        }

        /** @var string $provider a Keelhob_Provider subclass name */
        $provider = $target['class'];
        $model    = (string) $target['model'];

        $messages = [];
        foreach ($turns as $t) {
            $messages[] = $t['role'] === 'assistant'
                ? $provider::assistant_text_message($t['text'])
                : $provider::user_message($t['text']);
        }

        // A conversation must start with a user turn on every provider. A
        // transcript trimmed down to a leading assistant turn is rejected with
        // an opaque 400, so the leading assistant turns are dropped here where
        // the reason can be seen.
        while (!empty($turns) && $turns[0]['role'] === 'assistant') {
            array_shift($turns);
            array_shift($messages);
        }
        if (empty($messages)) {
            return new WP_Error('keelhob_chat_empty', __('There is nothing to send yet.', 'keelhob'));
        }

        $response = wp_remote_post($provider::url((string) $target['endpoint'], $model), [
            'timeout' => 120,
            'headers' => $provider::headers($key),
            'body'    => wp_json_encode($provider::body($model, self::system_prompt(self::site_facts()), [], $messages)),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('keelhob_chat_http', $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($data) ? $provider::error_message($data) : '';
            if ($code === 401 || $code === 403) {
                $message = __('Your API key was rejected. Check it under Keelhob > Automation.', 'keelhob');
            }
            /* translators: %d: HTTP status code. */
            return new WP_Error('keelhob_chat_api', $message ?: sprintf(__('The AI provider returned HTTP %d.', 'keelhob'), $code));
        }
        if (!is_array($data)) {
            return new WP_Error('keelhob_chat_api', __('The AI provider returned something unreadable.', 'keelhob'));
        }

        $parsed = $provider::parse($data);
        $text   = trim((string) ($parsed['text'] ?? ''));

        if ($text === '') {
            return new WP_Error('keelhob_chat_empty_reply', __('The AI returned an empty answer.', 'keelhob'));
        }

        return ['text' => $text];
    }
}
