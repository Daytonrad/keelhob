<?php
/**
 * Agent-written abilities, owner-approved.
 *
 * The one thing this file is for: letting the model write a brand-new TOOL for
 * this site when nothing existing can do the job, WITHOUT that code ever
 * running until a human being has read it and pressed Approve.
 *
 * THE APPROVAL GATE IS NOT A FEATURE AROUND THE EDGE OF THIS. IT IS THIS.
 *
 * Four separate things have to hold, and each one is here because any single
 * one of them failing would be enough on its own:
 *
 *   1. PENDING CODE IS NEVER EXECUTED. Not on save, not on list, not on
 *      preview, and — the subtle one — not by the LINT. Linting is the only
 *      step that has to look at the code as code, so it is the only step that
 *      could run it by accident. See lint_code() for exactly how that is
 *      avoided and why the obvious implementation is wrong.
 *
 *   2. APPROVAL IS A HUMAN ACTION. A nonced form posting to admin-post.php,
 *      `manage_options` checked, no JavaScript involved. There is deliberately
 *      NO tool, no REST route and no AJAX handler that approves an ability —
 *      so there is no path by which the model can approve its own code. The
 *      model's only write is propose(), which can only ever produce a `pending`
 *      row. tests/unit/test-abilities-approval.php holds that to account.
 *
 *   3. AUTHORING SHIPS OFF. A fresh install must not let an agent author code
 *      unasked — see authoring_enabled(). The tools are not even registered
 *      when it is off, so the model never sees them in tools/list.
 *
 *   4. THE CODE THAT RUNS IS THE CODE THAT WAS APPROVED. code_hash is written
 *      at the moment of approval and is sha256 of exactly the bytes the owner
 *      read. run() refuses on any mismatch, and refuses just as hard on an
 *      EMPTY hash, which is what a row that was never approved has.
 *
 * WHAT THIS REUSES RATHER THAN REBUILDS. Keelhob already had most of the
 * machinery, in Keelhob_Snippets:
 *
 *   - the parse check is Keelhob_Snippets::lint_body(), shared, so there is one
 *     parser for every piece of model-written PHP on this site;
 *   - the crash guard is the snippets pattern — register_shutdown_function()
 *     plus error_get_last(), with the running id held in a static so a fatal
 *     can be attributed and that exact ability retired;
 *   - retire_with_error() below is deactivate_with_error() for abilities,
 *     including the Keelhob_Snippet_Log line and the do_action() announcement,
 *     because "something the agent wrote is now switched off" is the same event;
 *   - KEELHOB_SNIPPETS_OFF switches these off too. One emergency kill switch
 *     for all agent-written code, reachable from wp-config.php with no working
 *     site and no database client;
 *   - versions are the revisions idea: nothing is overwritten, the old one is
 *     kept, and there is a one-click way back to it.
 *
 * WHAT IS GENUINELY NEW is that a snippet is site code and this is a TOOL the
 * agent can then call — so it needs a name the registry can hold, an input
 * schema, a per-version rating, and a gate in front of it that a snippet does
 * not have.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Abilities
{
    /** Bumped when the table shape changes. See maybe_install(). */
    const SCHEMA = 1;

    const OPTION_SCHEMA = 'keelhob_abilities_schema';

    /**
     * The capability group that gates AUTHORING.
     *
     * `abilities` in Keelhob_Capabilities::groups(), and in its
     * DISABLED_BY_DEFAULT list, which is what makes a fresh install refuse to
     * let an agent author code unasked. authoring_enabled() does not take that
     * on trust — see the note there.
     */
    const CAP_GROUP = 'abilities';

    /** `^[a-z][a-z0-9_]{2,60}$` — the reference implementation's rule, kept. */
    const NAME_RE = '/^[a-z][a-z0-9_]{2,60}$/';

    /** @var array<int, string> */
    const STATUSES = ['pending', 'active', 'retired', 'rejected', 'superseded'];

    /**
     * Names registered from this table during this request.
     *
     * Needed to tell "this name is a built-in tool" from "this name is a custom
     * ability we ourselves just put in the registry" — without it, improve()
     * would refuse to improve anything, because the thing it is improving is in
     * the registry by then.
     *
     * @var array<string, int> name => version
     */
    private static $registered = [];

    /** @var string|null the ability currently executing, for crash attribution */
    private static $running = null;

    /** @var bool guards against double-registering the shutdown handler */
    private static $shutdown_registered = false;

    // ---- boot -------------------------------------------------------------

    /**
     * The one entry point: install the table if needed, register the two
     * authoring tools when the owner has switched authoring on, register every
     * APPROVED ability, and hook the owner's review screen.
     *
     * WHERE THIS IS CALLED FROM, AND WHY IT IS NOT IN Keelhob::boot().
     *
     * The tidy home for this is three explicit lines in Keelhob::boot(), beside
     * the other Keelhob_Tools_*::register() calls. That file is owned by a lane
     * running in parallel, so it is called from Keelhob_Tool_Registry's
     * constructor instead: the one place in the codebase where "a registry has
     * come into existence" is true, which is the only moment this needs. It is
     * idempotent and order-independent — add() is keyed by name — so moving it
     * into boot() later is a safe no-op.
     */
    public static function boot(Keelhob_Tool_Registry $registry): void
    {
        self::maybe_install();

        if (self::authoring_enabled()) {
            Keelhob_Tools_Abilities::register($registry);
        }

        // Approved abilities register whatever the authoring switch says. The
        // switch governs whether the model may WRITE code; an ability that is
        // already live got there by a human reading it and approving it, which
        // is a stronger gate than a group toggle, and silently un-registering
        // it would mean "Approve & go live" did not go live.
        self::register_custom($registry, self::active_rows());

        if (function_exists('is_admin') && is_admin()) {
            (new Keelhob_Admin_Abilities())->hooks();
        }
    }

    // ---- schema -----------------------------------------------------------

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'keelhob_abilities';
    }

    public static function install_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /*
         * `name` is the natural key and it is a STRING. There is deliberately
         * no column called `object_id` anywhere in this table: $wpdb casts a
         * column of that name to an integer and silently stores 'list_drafts'
         * as 0, which is gotcha 1 in the reference notes and produces a table
         * that looks populated and means nothing. The same care applies to
         * every other column here — `approved_by` is the only `*_id`-shaped
         * thing and it genuinely is an integer user id.
         */
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            label VARCHAR(191) NOT NULL DEFAULT '',
            description TEXT NULL,
            input_schema LONGTEXT NULL,
            code LONGTEXT NOT NULL,
            code_hash CHAR(64) NOT NULL DEFAULT '',
            writes TINYINT(1) NOT NULL DEFAULT 0,
            risky TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            change_note TEXT NULL,
            last_error TEXT NULL,
            author_type VARCHAR(20) NOT NULL DEFAULT 'agent',
            approved_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            approved_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY name (name),
            KEY status (status),
            KEY name_status (name, status)
        ) {$charset};");
    }

    /**
     * Self-healing install, on its own generation counter rather than on
     * KEELHOB_VERSION — the same shape Keelhob_Tool_Stats uses, and for the
     * same reason: a site already recorded at the current plugin version never
     * reaches the version-keyed install path, which is every development rig.
     */
    public static function maybe_install(): void
    {
        if ((int) get_option(self::OPTION_SCHEMA, 0) >= self::SCHEMA) {
            return;
        }
        self::install_table();
        update_option(self::OPTION_SCHEMA, self::SCHEMA);
    }

    // ---- the authoring gate ----------------------------------------------

    /**
     * May the model write code for this site at all? OFF unless the owner said
     * yes, and the default is the whole point.
     *
     * WHY THIS DOES NOT SIMPLY CALL Keelhob_Capabilities::is_group_enabled().
     *
     * Because of what that method does when it has never heard of a group:
     * is_group_enabled() falls through to default_enabled(), which returns TRUE
     * for anything not named in DISABLED_BY_DEFAULT. So the entire default-off
     * property of this feature rests on one string appearing in one array in
     * another file. If `abilities` is ever renamed, moved, or dropped from that
     * list, the honest failure is "authoring stops working"; the failure
     * is_group_enabled() would give on its own is "a fresh install lets a model
     * author PHP unasked", which is exactly the shape of the bug the
     * DISABLED_BY_DEFAULT comment was written about — a fence that turned out to
     * be something else doing the fencing by accident.
     *
     * So the owner's stored answer is read first and the default is stated here,
     * in the negative. For a group that IS in DISABLED_BY_DEFAULT the two
     * methods agree for every input, because this is precisely what
     * is_group_enabled() computes in that case: the stored value if the owner
     * set one, false otherwise. The group toggle on the Connect screen is still
     * the one control, and Keelhob_Capabilities::tool_allowed() remains the gate
     * the MCP server applies in front of the call.
     */
    public static function authoring_enabled(): bool
    {
        $caps = Keelhob::instance()->get('capabilities', []);
        if (is_array($caps) && array_key_exists(self::CAP_GROUP, $caps)) {
            return (bool) $caps[self::CAP_GROUP];
        }

        return false;
    }

    /** True when every agent-written thing on this site is switched off at wp-config. */
    public static function kill_switch_on(): bool
    {
        return defined('KEELHOB_SNIPPETS_OFF') && KEELHOB_SNIPPETS_OFF;
    }

    // ---- the lint ---------------------------------------------------------

    /**
     * Parse-check a proposed ability body. Returns '' when it parses, otherwise
     * the error to show the owner and hand back to the model.
     *
     * THIS IS THE ONLY STEP THAT LOOKS AT PENDING CODE AS CODE, SO IT IS THE
     * ONLY STEP THAT COULD RUN IT BY ACCIDENT.
     *
     * The reference notes give the eval trick:
     *
     *     eval('return null; return function( array $input ) { ' . $code . ' };')
     *
     * The leading `return null;` makes everything after it unreachable, so the
     * closure is never even constructed, while PHP still COMPILES the whole
     * string and throws ParseError on bad syntax. That is the right idea.
     *
     * It is not what is used here, and the reason is worth stating. PHP hoists
     * unconditional top-level declarations in an eval'd string at compile time,
     * before the first statement runs. Code that stays inside the braces cannot
     * reach the top level — but code that CLOSES them can:
     *
     *     }; class Boom { ... } $x = function( array $input ) {
     *
     * That is syntactically valid, so it lints clean, and its `class Boom` is
     * declared despite the `return null;`. Nothing executes, but a redeclaration
     * is a fatal error, and "pending code can fatal the request that linted it"
     * is not a sentence this file is allowed to contain.
     *
     * token_get_all($source, TOKEN_PARSE) runs the same full parser and throws
     * the same ParseError, with no runtime and no declarations at all. It is
     * also already the parser Keelhob_Snippets and Keelhob_Tools_Filesystem use,
     * so there is one answer on this site to "does this PHP parse" instead of
     * two. The body is wrapped in the closure that will eventually hold it, so a
     * body that only parses OUTSIDE a function is caught here rather than at
     * approval time.
     *
     * The eval form is still run, second, inside the same guard — see
     * lint_code_eval(). By then token_get_all has already proved the wrapped
     * source parses as one balanced function, which is what removes the escape
     * above. Belt, and then braces.
     */
    public static function lint_code(string $code): string
    {
        $code = (string) $code;

        if (trim($code) === '') {
            return 'The ability has no code.';
        }

        // A leading <?php is the single most common mistake, and it would make
        // the wrapped source nonsense in a way whose parse error says nothing
        // useful. Name it instead.
        if (preg_match('/^\s*<\?(php|=)?/i', $code)) {
            return 'Remove the leading <?php — send the function body only, the way a snippet does.';
        }
        if (strpos($code, '?>') !== false) {
            return 'Remove the ?> — an ability is PHP only, with no inline HTML blocks.';
        }

        // The body must STAY in its own braces. This is the check that makes
        // the eval below safe, and it is run before the eval for exactly that
        // reason. See escapes_braces().
        if (self::escapes_braces($code)) {
            return 'The code closes a brace it never opened. Send the function body only — '
                . 'everything between { and }, and nothing outside them.';
        }

        $wrapped = 'return function( array $input ) { ' . $code . ' };';

        $error = Keelhob_Snippets::lint_body($wrapped);
        if ($error !== '') {
            return self::parse_message($error);
        }

        return self::lint_code_eval($wrapped);
    }

    /**
     * Does this body reach outside the braces it will be compiled inside?
     *
     * THIS IS THE CHECK THAT MAKES THE eval() IN lint_code_eval() SAFE, and it
     * is here because the hole it closes is real and was demonstrated before
     * this method existed. Consider:
     *
     *     }; class Boom {} $unused = function( array $input ) { return [];
     *
     * Wrapped, that is perfectly valid PHP, so a parse check passes it. The
     * `return null;` at the front of the eval stops every STATEMENT from
     * running — but PHP hoists unconditional top-level declarations at compile
     * time, before the first statement, so `class Boom` is declared anyway. The
     * test for this is in tests/unit/test-abilities.php and it failed, on this
     * implementation, until this method was added.
     *
     * Depth is counted off the tokeniser rather than off the raw string, so a
     * brace inside a string literal, a comment or a heredoc is not counted — it
     * is part of a single token. `${` and `{$` interpolation open a brace of
     * their own and are counted as such. Depth going negative at any point
     * means the body left its own scope; ending non-zero means it did not come
     * back, which the parse check would catch anyway.
     */
    public static function escapes_braces(string $code): bool
    {
        /*
         * NO TOKEN_PARSE HERE, and that is the point rather than an oversight.
         * With the flag, token_get_all runs the PARSER, and a body that escapes
         * its braces is a parse error ON ITS OWN — `<?php };` does not parse —
         * so the ParseError would have to be swallowed and this method would
         * answer "fine" for precisely the input it exists to catch. That bug
         * was written, and the test caught it. Without the flag it is the plain
         * lexer: it never throws, and a brace count is all this needs.
         */
        $tokens = token_get_all('<?php ' . $code);

        $depth = 0;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                // T_CURLY_OPEN is `{$`, T_DOLLAR_OPEN_CURLY_BRACES is `${`.
                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                }
                continue;
            }
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
                if ($depth < 0) {
                    return true;
                }
            }
        }

        return $depth !== 0;
    }

    /**
     * The reference note's own check, run only after token_get_all has proved
     * the wrapped source is one balanced function.
     *
     * `return null;` first means the closure on the next line is never created
     * and the body is never entered. Kept because gotcha 5 names it and because
     * it exercises the real compiler in the real position the code will occupy.
     */
    private static function lint_code_eval(string $wrapped): string
    {
        try {
            // phpcs:ignore Squiz.PHP.Eval.Discouraged -- compile-only; see above.
            eval('return null; ' . $wrapped);
        } catch (\ParseError $e) {
            return self::parse_message($e->getMessage());
        } catch (\Throwable $e) {
            return self::parse_message($e->getMessage());
        }

        return '';
    }

    /** Turn a raw parse error into the sentence the owner and the model read. */
    private static function parse_message(string $raw): string
    {
        if (preg_match('/on line (\d+)/', $raw, $m)) {
            return sprintf('Syntax error on line %d: %s', (int) $m[1], $raw);
        }

        return 'Syntax error: ' . $raw;
    }

    // ---- naming -----------------------------------------------------------

    /** Lowercase, dashes to underscores, trimmed. Never throws. */
    public static function normalize_name(string $name): string
    {
        $name = strtolower(trim($name));
        $name = str_replace(['-', ' '], '_', $name);

        return preg_replace('/[^a-z0-9_]/', '', $name) ?? '';
    }

    public static function name_valid(string $name): bool
    {
        return (bool) preg_match(self::NAME_RE, $name);
    }

    /**
     * Is this name one of the plugin's own tools?
     *
     * A custom ability may never shadow a built-in one — that is how an
     * agent-written `execute_php` would get to be the thing the model calls —
     * so the registry is asked, minus the custom names this request put in it.
     */
    public static function is_builtin(string $name): bool
    {
        if (isset(self::$registered[$name])) {
            return false;
        }
        $keelhob = Keelhob::instance();
        if (!isset($keelhob->registry) || !($keelhob->registry instanceof Keelhob_Tool_Registry)) {
            return false;
        }

        return $keelhob->registry->has($name);
    }

    // ---- proposing (the model's only write) -------------------------------

    /**
     * Save a proposed ability as `pending`. This is the ONLY write the model
     * has, and every row it can produce has status `pending` and an empty
     * code_hash, so there is no argument to this method that makes code live.
     *
     * @param array<string, mixed> $a
     * @param bool                 $is_create true for ability_create, false for ability_improve
     * @return array<string, mixed>
     */
    public static function propose(array $a, bool $is_create): array
    {
        if (!self::authoring_enabled()) {
            return self::fail(
                'Writing new abilities is switched OFF for this site. Do not try to reach the same '
                . 'outcome through snippet_save, execute_php or a file write — that is working around a '
                . 'decision the owner made on purpose. Tell the owner they can switch on the '
                . '"Let the AI write new code tools" capability group under Keelhob > Connect, and then ask again.'
            );
        }

        $name = self::normalize_name((string) ($a['name'] ?? ''));
        if (!self::name_valid($name)) {
            return self::fail(
                'Invalid input: "name" must be 3-61 characters, start with a letter, and contain only '
                . 'lowercase letters, digits and underscores.'
            );
        }
        if (self::is_builtin($name)) {
            return self::fail(sprintf(
                '"%s" is a built-in tool and cannot be rewritten. Save a lesson for it with lesson_save '
                . 'instead, which is read back into its description on every tools/list.',
                $name
            ));
        }

        $latest = self::latest_row($name);

        if ($is_create && $latest !== null) {
            return self::fail(sprintf(
                'An ability called "%s" already exists on this site (version %d, %s). Use ability_improve '
                . 'to submit a better version of it.',
                $name,
                (int) $latest['version'],
                (string) $latest['status']
            ));
        }
        if (!$is_create && $latest === null) {
            return self::fail(sprintf(
                'There is no custom ability called "%s" to improve. Use ability_create to write a new one.',
                $name
            ));
        }

        // Missing fields are inherited from the latest version, so an improve
        // that only changes the code does not have to restate the label.
        $label       = self::text($a['label'] ?? ($latest['label'] ?? ''), 191);
        $description = self::text($a['description'] ?? ($latest['description'] ?? ''), 2000);
        $code        = isset($a['code']) ? (string) $a['code'] : (string) ($latest['code'] ?? '');
        $change_note = self::text($a['change_note'] ?? '', 2000);

        if ($label === '') {
            return self::fail('Invalid input: "label" is required — it is what the owner reads on the review card.');
        }
        if ($description === '') {
            return self::fail('Invalid input: "description" is required — it is what you yourself will read in tools/list.');
        }
        if (!$is_create && $change_note === '') {
            return self::fail(
                'Invalid input: "change_note" is required. The owner has already approved the version that '
                . 'is running; they will not approve a replacement without being told what changed and why.'
            );
        }

        $lint = self::lint_code($code);
        if ($lint !== '') {
            // Deliberately NOT saved. Keelhob_Snippets stores unparseable code
            // and forces it inactive, which is right there: a snippet already
            // exists and the owner may need to see what broke. Here there is
            // nothing to preserve, and putting code that cannot even compile in
            // front of a human asking "approve this?" is a worse outcome than
            // telling the model to fix it.
            return self::fail($lint);
        }

        $schema  = self::normalize_schema($a['input_schema'] ?? ($latest['input_schema'] ?? null));
        $writes  = self::flag($a['writes'] ?? ($latest['writes'] ?? false));
        $risky   = self::flag($a['risky'] ?? ($latest['risky'] ?? false));
        $version = $latest === null ? 1 : ((int) $latest['version'] + 1);

        global $wpdb;

        // Older pending rows for this name become `superseded`, so the owner is
        // never asked to approve two competing drafts of the same ability.
        $wpdb->update(
            self::table(),
            ['status' => 'superseded'],
            ['name' => $name, 'status' => 'pending']
        );

        $wpdb->insert(self::table(), [
            'name'         => $name,
            'version'      => $version,
            'label'        => $label,
            'description'  => $description,
            'input_schema' => $schema,
            'code'         => $code,
            // Empty until a human approves it. run() treats an empty hash as a
            // refusal, so a pending row cannot run even if it somehow reached
            // the registry.
            'code_hash'    => '',
            'writes'       => $writes ? 1 : 0,
            'risky'        => $risky ? 1 : 0,
            'status'       => 'pending',
            'change_note'  => $change_note,
            'author_type'  => 'agent',
            'created_at'   => current_time('mysql'),
        ]);

        $id = (int) ($wpdb->insert_id ?? 0);

        do_action('keelhob_ability_proposed', $id, $name, $version);

        return [
            'success'    => true,
            'status'     => 'pending_approval',
            'id'         => $id,
            'name'       => $name,
            'version'    => $version,
            'review_url' => self::review_url(),
            'message'    => sprintf(
                'Saved as version %d, pending approval. It runs NOTHING until the site owner reads the code '
                . 'and approves it on Keelhob > Connect > Abilities the AI wrote. %s Tell the owner it is '
                . 'waiting and link them to %s.',
                $version,
                $version === 1
                    ? 'There is no live version yet, so this ability does not exist until then.'
                    : 'The version already approved keeps working until they approve this one.',
                self::review_url()
            ),
        ];
    }

    // ---- the owner's actions (admin-post only) ----------------------------

    /**
     * Approve a pending version and make it live.
     *
     * Reachable only from Keelhob_Admin_Abilities::handle_approve(), behind
     * `manage_options` and a nonce. There is no tool, no REST route and no AJAX
     * handler that reaches it.
     *
     * @return array<string, mixed>
     */
    public static function approve(int $id, int $user_id = 0): array
    {
        global $wpdb;

        $row = self::row($id);
        if ($row === null) {
            return self::fail('That ability version no longer exists.');
        }
        if ((string) $row['status'] !== 'pending') {
            return self::fail(sprintf(
                'Only a version waiting for review can be approved; this one is "%s".',
                (string) $row['status']
            ));
        }

        // Re-linted at the gate. The row was linted when it was proposed, but
        // approval is the moment this code becomes something that runs, and the
        // bytes between then and now have been sitting in a database.
        $lint = self::lint_code((string) $row['code']);
        if ($lint !== '') {
            return self::fail('This code no longer parses, so it was not approved: ' . $lint);
        }

        $name = (string) $row['name'];
        if (self::is_builtin($name)) {
            return self::fail(sprintf('"%s" is a built-in tool name and cannot be replaced.', $name));
        }

        // The version that was live becomes retired, not deleted: "Roll back to
        // this" needs it, and so does the owner's answer to "what was it doing
        // before?".
        $wpdb->update(self::table(), ['status' => 'retired'], ['name' => $name, 'status' => 'active']);

        $wpdb->update(self::table(), [
            'status'      => 'active',
            'code_hash'   => self::hash((string) $row['code']),
            'approved_by' => max(0, $user_id),
            'approved_at' => current_time('mysql'),
            'last_error'  => null,
        ], ['id' => $id]);

        do_action('keelhob_ability_approved', $id, $name, (int) $row['version']);

        return ['success' => true, 'id' => $id, 'name' => $name, 'version' => (int) $row['version']];
    }

    /** Refuse a pending version. The code is kept so the owner can see what they said no to. */
    public static function reject(int $id): array
    {
        global $wpdb;

        $row = self::row($id);
        if ($row === null) {
            return self::fail('That ability version no longer exists.');
        }
        if ((string) $row['status'] !== 'pending') {
            return self::fail('Only a version waiting for review can be rejected.');
        }

        $wpdb->update(self::table(), ['status' => 'rejected'], ['id' => $id]);
        do_action('keelhob_ability_rejected', $id, (string) $row['name']);

        return ['success' => true, 'id' => $id];
    }

    /**
     * Put a previously-approved version back.
     *
     * ONLY `retired` rows. This is not a second approval path and it must never
     * become one: a `pending` row has never been read by anybody, and allowing
     * "roll back to" it would be approval without the confirm, without the
     * code on screen, and without the hash being written against bytes a human
     * saw. The row's existing code_hash is re-derived rather than trusted, so a
     * row whose bytes were edited in the database cannot be restored into a
     * state where it would pass the hash check.
     */
    public static function rollback(int $id, int $user_id = 0): array
    {
        global $wpdb;

        $row = self::row($id);
        if ($row === null) {
            return self::fail('That ability version no longer exists.');
        }
        if ((string) $row['status'] !== 'retired') {
            return self::fail(
                'Only a version that was previously approved and has since been replaced can be rolled '
                . 'back to. A version waiting for review has to be approved, with its code on screen.'
            );
        }

        $lint = self::lint_code((string) $row['code']);
        if ($lint !== '') {
            return self::fail('That version no longer parses, so it was not restored: ' . $lint);
        }

        $name = (string) $row['name'];
        $wpdb->update(self::table(), ['status' => 'retired'], ['name' => $name, 'status' => 'active']);
        $wpdb->update(self::table(), [
            'status'      => 'active',
            'code_hash'   => self::hash((string) $row['code']),
            'approved_by' => max(0, $user_id),
            'approved_at' => current_time('mysql'),
            'last_error'  => null,
        ], ['id' => $id]);

        do_action('keelhob_ability_rolled_back', $id, $name, (int) $row['version']);

        return ['success' => true, 'id' => $id, 'name' => $name, 'version' => (int) $row['version']];
    }

    // ---- reading ----------------------------------------------------------

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * Does the stored code still match the hash written when it was approved?
     *
     * An empty hash is a FAILURE, not a pass. That is the state of every row
     * that was never approved, and a check written as `$hash === '' || match`
     * would wave all of them through.
     *
     * @param array<string, mixed> $row
     */
    public static function hash_ok(array $row): bool
    {
        $hash = (string) ($row['code_hash'] ?? '');
        if (strlen($hash) !== 64) {
            return false;
        }

        return hash_equals($hash, self::hash((string) ($row['code'] ?? '')));
    }

    /** @return array<string, mixed>|null */
    public static function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** The newest row for a name whatever its status. @return array<string, mixed>|null */
    public static function latest_row(string $name): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE name = %s ORDER BY version DESC, id DESC LIMIT 1',
            $name
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** The live row for a name, or null. @return array<string, mixed>|null */
    public static function active_row(string $name): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE name = %s AND status = 'active' ORDER BY version DESC LIMIT 1",
            $name
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Every live ability, newest version per name.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function active_rows(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . self::table() . " WHERE status = 'active' ORDER BY name ASC, version DESC",
            ARRAY_A
        );

        return self::newest_per_name(is_array($rows) ? $rows : []);
    }

    /**
     * Keep one row per name — the highest version. Pure, so the "they can never
     * shadow each other" rule is testable without a database.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function newest_per_name(array $rows): array
    {
        $best = [];
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (!isset($best[$name]) || (int) $row['version'] > (int) $best[$name]['version']) {
                $best[$name] = $row;
            }
        }
        ksort($best);

        return array_values($best);
    }

    /** @return array<int, array<string, mixed>> oldest first, so the queue reads as a queue */
    public static function pending_rows(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . self::table() . " WHERE status = 'pending' ORDER BY id ASC",
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /** Every version of one name, newest first. @return array<int, array<string, mixed>> */
    public static function versions(string $name, int $limit = 50): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE name = %s ORDER BY version DESC, id DESC LIMIT %d',
            $name,
            max(1, min(200, $limit))
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public static function pending_count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'pending'");
    }

    public static function review_url(): string
    {
        return admin_url('admin.php?page=keelhob#kh-abilities');
    }

    /**
     * The rating key for one version.
     *
     * Per VERSION, not per name, because the question the owner actually has is
     * "is the new one better?" and a single rolled-up number cannot answer it.
     * Keelhob_Tool_Registry::set_stats_key() points the registry's tally and
     * the description line at this key, so both halves agree.
     */
    public static function stats_key(string $name, int $version): string
    {
        return 'ability:' . $name . ':v' . $version;
    }

    // ---- registration into the tool registry ------------------------------

    /**
     * Put live abilities into the registry.
     *
     * Rows are injected rather than fetched so the "a pending row never becomes
     * a callable tool" rule can be proved with a fixture and no database.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function register_custom(Keelhob_Tool_Registry $registry, array $rows): void
    {
        foreach (self::newest_per_name($rows) as $row) {
            $name    = (string) ($row['name'] ?? '');
            $status  = (string) ($row['status'] ?? '');
            $version = (int) ($row['version'] ?? 0);

            // Belt. active_rows() already filters on status, and this is the
            // line that makes it impossible for a caller — a future one, a
            // test, a third-party filter — to hand this method a pending row
            // and have it become callable.
            if ($status !== 'active' || !self::name_valid($name)) {
                continue;
            }
            // Braces. A custom ability may never shadow a built-in tool: that
            // is how an agent-written `execute_php` would become the thing the
            // model calls.
            if ($registry->has($name)) {
                continue;
            }

            $schema = json_decode((string) ($row['input_schema'] ?? ''), true);
            if (!is_array($schema) || empty($schema['type'])) {
                $schema = ['type' => 'object', 'properties' => (object) []];
            }

            self::$registered[$name] = $version;
            Keelhob_Tool_Registry::set_stats_key($name, self::stats_key($name, $version));

            $registry->add(
                $name,
                self::tool_description($row),
                $schema,
                static function (array $args) use ($name): array {
                    return self::run($name, $args);
                },
                [
                    'title'          => (string) ($row['label'] ?? $name),
                    'readOnlyHint'   => empty($row['writes']),
                    'destructiveHint' => !empty($row['risky']),
                ]
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function tool_description(array $row): string
    {
        $parts = [(string) ($row['description'] ?? '')];
        $parts[] = sprintf(
            'This ability was written by an AI for this site and approved by the owner (version %d). '
            . 'Only the owner can change what it does.',
            (int) ($row['version'] ?? 1)
        );
        if (!empty($row['writes'])) {
            $parts[] = 'This changes the site.';
        }

        return implode(' ', array_filter($parts));
    }

    // ---- running ----------------------------------------------------------

    /**
     * Run a live ability.
     *
     * The order of the guards is the safety property, so it is spelled out:
     * kill switch, then "is there a live row at all", then the hash. Only then
     * is a single eval reached, and by that point the bytes being compiled are
     * provably the bytes a human read and approved.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function run(string $name, array $args): array
    {
        if (self::kill_switch_on()) {
            return self::fail(
                'KEELHOB_SNIPPETS_OFF is defined in wp-config.php, so no agent-written code runs on this '
                . 'site, abilities included. Ask the owner to remove that line.'
            );
        }

        // Re-read every time. The closure in the registry was built when the
        // request started; the owner may have rejected, replaced or rolled back
        // the ability since, and the row is the authority on what is live.
        $row = self::active_row($name);
        if ($row === null) {
            return self::fail(sprintf(
                'The "%s" ability has no approved version, so there is nothing to run. A version waiting '
                . 'for review never runs.',
                $name
            ));
        }

        return self::run_row($row, $args);
    }

    /**
     * Run one row, guards included.
     *
     * Split from run() so that the guards — status, then hash, then the single
     * eval — can be exercised against a fixture with no database, which is what
     * tests/unit/test-abilities-approval.php does. It is safe to expose because
     * it re-checks the status itself: handing it a pending row gets the same
     * refusal a pending row gets everywhere else, and nothing in this plugin
     * calls it with one.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function run_row(array $row, array $args): array
    {
        if (self::kill_switch_on()) {
            return self::fail(
                'KEELHOB_SNIPPETS_OFF is defined in wp-config.php, so no agent-written code runs on this '
                . 'site, abilities included. Ask the owner to remove that line.'
            );
        }

        $name = (string) ($row['name'] ?? '');

        if ((string) ($row['status'] ?? '') !== 'active') {
            return self::fail(sprintf(
                'The "%s" ability is "%s", not approved, so it was not run. A version waiting for review '
                . 'never runs.',
                $name,
                (string) ($row['status'] ?? 'unknown')
            ));
        }

        if (!self::hash_ok($row)) {
            // Logged as well as returned: a hash mismatch is either a database
            // edit or a tampering attempt, and either way it is the sort of
            // thing the owner should be able to find three days later.
            Keelhob_Snippet_Log::record(
                'ability:' . $name,
                (string) ($row['label'] ?? $name),
                'hash_mismatch',
                'Stored code does not match the hash written when it was approved.'
            );

            return self::fail(
                "This ability's code changed after it was approved, so it was blocked. "
                . 'Ask the owner to review it again.'
            );
        }

        self::register_shutdown();
        self::$running = $name;

        try {
            // The ONE eval in this file that is meant to run anything. Every
            // guard above exists to make this line safe: the code parsed, a
            // human approved it, and sha256 says it has not changed since.
            // phpcs:ignore Squiz.PHP.Eval.Discouraged -- owner-approved, hash-verified.
            $fn = eval('return function( array $input ) { ' . (string) $row['code'] . ' };');
            if (!($fn instanceof Closure)) {
                return self::fail('That ability did not produce a callable body.');
            }
            $result = $fn($args);
        } catch (Throwable $e) {
            self::$running = null;
            self::note_error($name, $e->getMessage());

            return self::fail(sprintf('PHP error: %s (%s:%d)', $e->getMessage(), basename($e->getFile()), $e->getLine()));
        }

        self::$running = null;

        if (!is_array($result)) {
            $result = ['result' => $result];
        }
        $result['_ability'] = ['name' => $name, 'version' => (int) $row['version']];

        return $result;
    }

    private static function register_shutdown(): void
    {
        if (self::$shutdown_registered) {
            return;
        }
        self::$shutdown_registered = true;
        register_shutdown_function([self::class, 'on_shutdown']);
    }

    /**
     * A fatal inside an ability body, attributed and switched off.
     *
     * Lifted from Keelhob_Snippets::on_shutdown(), which is the proven version
     * of this: error_get_last() filtered to the four fatal types, attributed to
     * whatever was running, and the offender retired so the next call loads
     * cleanly instead of fataling again. An ability runs inside an MCP request
     * rather than a page load, so a fatal costs that one call rather than the
     * front end — which makes this less urgent than the snippets case and no
     * less correct.
     */
    public static function on_shutdown(): void
    {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
            return;
        }
        if (self::$running !== null) {
            self::retire_with_error(self::$running, (string) $error['message']);
            @error_log('[Keelhob] Retired crashing ability "' . self::$running . '": ' . $error['message']);
        }
    }

    /** Record a non-fatal failure against the live version without switching it off. */
    private static function note_error(string $name, string $message): void
    {
        global $wpdb;
        $wpdb->update(
            self::table(),
            ['last_error' => mb_substr($message, 0, 1000)],
            ['name' => $name, 'status' => 'active']
        );
    }

    /**
     * Keelhob_Snippets::deactivate_with_error() for abilities: switch it off,
     * keep the reason, write the log line, and announce it — because something
     * the agent wrote is now off, and only the owner can judge whether the
     * missing behaviour matters.
     */
    private static function retire_with_error(string $name, string $message): void
    {
        global $wpdb;
        $row = self::active_row($name);
        $wpdb->update(
            self::table(),
            ['status' => 'retired', 'last_error' => mb_substr($message, 0, 1000)],
            ['name' => $name, 'status' => 'active']
        );

        Keelhob_Snippet_Log::record(
            'ability:' . $name,
            (string) ($row['label'] ?? $name),
            'fatal_deactivated',
            $message
        );

        do_action('keelhob_ability_auto_retired', $name, $message);
    }

    // ---- small helpers ----------------------------------------------------

    /** @return array{success:false, error:string} */
    private static function fail(string $message): array
    {
        return ['success' => false, 'error' => $message];
    }

    private static function text($value, int $max): string
    {
        $value = is_string($value) ? $value : '';
        $value = wp_strip_all_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return trim(mb_substr($value, 0, $max));
    }

    /** "true"/"1"/"yes" and friends, the way the reference validator coerces them. */
    private static function flag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Store the input schema as JSON, or '' when there is nothing usable.
     *
     * A JSON string is decoded first, because a model that is told "object"
     * sends a string about one time in ten and a schema stored as a quoted
     * string makes every tools/list for that ability malformed.
     */
    public static function normalize_schema($schema): string
    {
        if (is_string($schema)) {
            $trimmed = trim($schema);
            if ($trimmed === '') {
                return '';
            }
            $decoded = json_decode($trimmed, true);
            $schema  = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($schema)) {
            return '';
        }
        if (empty($schema['type'])) {
            $schema = ['type' => 'object', 'properties' => $schema];
        }
        if (empty($schema['properties'])) {
            $schema['properties'] = (object) [];
        }

        return (string) wp_json_encode($schema);
    }

    /** Human words for a status, for the review card. */
    public static function status_label(string $status): string
    {
        $map = [
            'pending'    => __('Waiting for your review', 'keelhob'),
            'active'     => __('Live', 'keelhob'),
            'retired'    => __('Replaced', 'keelhob'),
            'rejected'   => __('Rejected', 'keelhob'),
            'superseded' => __('Superseded by a newer draft', 'keelhob'),
        ];

        return $map[$status] ?? $status;
    }
}
