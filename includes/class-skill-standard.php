<?php
/**
 * The skill standard — what makes a skill fit to ship, expressed as code.
 *
 * Keelhob skills used to be connected by prose. One skill mentioned another
 * somewhere in its body, and a test looked for that name with strpos(). That
 * check passed on a skill whose only reference was "unlike web-design-master",
 * which links nothing, and it could never answer the questions that actually
 * matter: does this chain loop? how much context does it cost to follow? do two
 * skills fight over the same request? Nine of eighteen shipped skills had no
 * "Related skills" section at all and every one of them passed.
 *
 * So the edges became data. A skill declares `role`, `requires`, `next_skills`
 * and `verified_by` in its front matter, and this class is the only thing that
 * judges the result.
 *
 * Two properties are deliberate:
 *
 *   1. PURE. No filesystem, no database, no WordPress. Everything here takes
 *      arrays and returns arrays, which is what lets the same rules run in CI
 *      (bin/lint-skills.php) and inside a live site (the skill_lint tool). A
 *      standard that only runs in our repo would never see the skills agents
 *      write on other people's sites, which is most of them.
 *   2. ADVISORY BY LEVEL. Findings carry 'error' or 'warn'. CI fails on error.
 *      skill_save never refuses either — warning an owner about a missing
 *      heading is help, refusing to save their work is not.
 *
 * On the routing scorer, honestly: it counts shared significant words. It is
 * not a model and it does not pretend to be. What it reliably catches is a
 * description that does not contain the words a user would actually type, which
 * is the failure that makes a good skill never load. Treat a routing finding as
 * "your wording is off", not as "the model will definitely mis-route this".
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Skill_Standard
{
    /**
     * How the agent should treat a skill.
     *
     *   gate      — gets in the way on purpose. Work is not done until it passes.
     *   procedure — do these steps to accomplish a task.
     *   reference — background knowledge, read for judgement, no steps to follow.
     */
    const ROLES = ['gate', 'procedure', 'reference'];

    /** Anything that does not say otherwise is a procedure. */
    const ROLE_DEFAULT = 'procedure';

    /**
     * WHERE a skill can actually be finished.
     *
     * `role` says what a skill is. This says which kind of session can complete
     * it, and it exists because the answer is not always "any of them".
     *
     * The same agent reaches this site down three very different pipes. Claude
     * Code and the IDE clients are a workstation: a human is present, and the
     * client has local tools of its own — a browser, a filesystem, other MCP
     * servers. Telegram and Slack are a chat window: a human is present but the
     * agent has nothing except this site's tools. A scheduled task is neither;
     * nobody is there at all.
     *
     * Without this field an agent on Telegram happily loads a skill whose third
     * step is "open the page in a headless browser and compare computed
     * styles", gets four steps in, and then either stalls or invents a result.
     * Both outcomes are worse than refusing at the start, and the agent had no
     * way to know — a skill body reads exactly the same down every pipe.
     *
     *   any          — no special requirement. Nearly everything.
     *   interactive  — needs a human answering questions DURING the run, so it
     *                  cannot be completed by an unattended scheduled task.
     *                  Chat is fine.
     *   workstation  — needs a client with local tools of its own (a browser,
     *                  a second MCP server, the local filesystem). Claude Code
     *                  or an IDE only: not chat, not unattended.
     *
     * The values are ordered weakest requirement first, which is what
     * surface_rank() relies on.
     */
    const SURFACES = ['any', 'interactive', 'workstation'];

    /** Anything that does not say otherwise runs anywhere. */
    const SURFACE_DEFAULT = 'any';

    /**
     * What each session type can actually do, for the agent-facing explanation.
     *
     * Keyed by surface, so the message a caller shows is written once here
     * rather than reinvented at each of the three places that need it.
     */
    const SURFACE_NOTES = [
        'any'         => 'Runs anywhere: Claude Code, an IDE, Telegram, Slack, or an unattended task.',
        'interactive' => 'Needs a person answering questions while it runs. Not an unattended scheduled task.',
        'workstation' => 'Needs Claude Code or an IDE — it uses tools that live on the caller\'s machine (a browser, another MCP server). Cannot be completed over Telegram or Slack.',
    ];

    /**
     * The one skill allowed to have nothing pointing at it. Operating-protocol
     * rule 11 injects it into every session, so it is reached by name from PHP
     * rather than by an edge from another skill.
     */
    const ENTRY_POINT = 'build-standard';

    /** The description is the router. Too short says nothing; too long is a body. */
    const DESC_MIN = 120;
    const DESC_MAX = 700;

    /**
     * What the whole catalog may cost, in bytes, at session start.
     *
     * This is the constraint that actually matters, and it is why DESC_MAX is
     * loose: a rich description is the single most valuable text in a skill,
     * because it is the only thing that routes work to it. Capping each one
     * tightly would trade the thing that works for a saving nobody feels. What
     * does need a ceiling is the total, since every connected session pays it
     * before a word of work happens.
     *
     * RAISED FROM 12 KB TO 16 KB ON 2026-09-10, AND WHY THAT IS NOT CHEATING.
     *
     * The old value was 12 KB against a set of 18 skills costing 9.5 KB, and its
     * note said it left room for "roughly four more skills". The site-clone
     * family is five, arriving together, and the check fired at 12,729 bytes.
     * It did its job.
     *
     * The choice was then between trimming ~440 bytes out of 23 descriptions to
     * squeeze under a line, or moving the line. Trimming was rejected because
     * this same docblock argues that descriptions are the one thing worth paying
     * for — shaving 19 bytes off each to satisfy a threshold would degrade the
     * routing quality the budget exists to protect, which is the wrong trade
     * made for the wrong reason.
     *
     * What matters is whether 12.7 KB at session start is acceptable in itself,
     * and it is: roughly 3,200 tokens, once, against context windows measured in
     * hundreds of thousands. So the number moved deliberately rather than being
     * met by degrading the thing it protects.
     *
     * The measured set is now 23 skills at ~12.7 KB. This leaves room for about
     * eight more before the same question has to be asked again — which is the
     * right cadence for asking it, and the reason this is not simply set to a
     * number no realistic set could ever reach. A budget that cannot fire is
     * cover, not a check.
     */
    const CATALOG_BUDGET = 16384;

    /**
     * How many `next_skills` a catalog line prints before summarising the rest.
     *
     * build-standard is the router and genuinely names every other skill, which
     * is right as data and expensive as text — that one line ran to about 400
     * bytes in every session. The full list is still one skill_get away, and
     * operating-protocol rule 11 sends the agent there before it builds
     * anything, so nothing is lost by not spelling it all out up front.
     */
    const CATALOG_EDGES = 4;

    /**
     * Body length, in lines. Below the floor it is a paragraph, and a paragraph
     * belongs inside a skill that already exists rather than competing with it.
     * References get more room because they are read for judgement, not steps.
     */
    const LINES_MIN = 40;
    const LINES_MAX = 400;
    const LINES_MAX_REFERENCE = 600;

    /**
     * Worst-case bytes an agent must read to follow one skill and everything it
     * requires. 60 KB is roughly 15,000 tokens — already a large slice of a
     * session before any actual work has happened.
     */
    const WEIGHT_BUDGET = 61440;

    /**
     * How alike two descriptions may be before they are competing for the same
     * request. Jaccard overlap of significant words.
     *
     * Measured, not guessed. Across the eighteen shipped skills the closest
     * legitimate pair is elementor-page-building / solace-header-footer at
     * 0.194 — genuinely close cousins, and correctly separate. Nothing else
     * clears 0.16. This sits above that with headroom, so it fires on a pair
     * meaningfully more alike than the most alike pair we deliberately ship.
     *
     * The first number tried was 0.45, which nothing in the set could ever have
     * reached. A threshold that cannot fire reads like a check and is cover.
     * If this set changes shape, re-measure rather than re-guess.
     */
    const CLASH_THRESHOLD = 0.30;

    /** Headings every skill carries. The order is the order they must appear in. */
    const SECTIONS = ['When to use', 'When NOT to use', 'Routing examples', 'Related skills'];

    /**
     * Words too common to say anything about what a skill is for.
     *
     * The list is long because the floor is three letters, not four. Four would
     * be tidier and would throw away "seo", "gdpr", "css", "php" and "gbp" —
     * the exact words that distinguish one skill from another here. Paying for
     * that with a bigger stopword list is the right trade.
     */
    const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'into', 'when', 'what',
        'use', 'used', 'using', 'user', 'users', 'any', 'all', 'not', 'you', 'your',
        'its', 'has', 'have', 'are', 'was', 'were', 'can', 'will', 'would', 'should',
        'site', 'sites', 'page', 'pages', 'wordpress', 'skill', 'skills', 'keelhob',
        'before', 'after', 'them', 'they', 'their', 'than', 'then', 'each', 'every',
        'one', 'two', 'more', 'most', 'some', 'other', 'others', 'about', 'over',
        'make', 'makes', 'made', 'need', 'needs', 'want', 'wants', 'ask', 'asks',
        'load', 'loads', 'call', 'calls', 'run', 'runs', 'work', 'works', 'like',
        'who', 'how', 'why', 'out', 'own', 'per', 'via', 'off', 'yes', 'get', 'got',
        'new', 'old', 'set', 'put', 'say', 'says', 'see', 'let', 'may', 'but', 'his',
        'her', 'him', 'she', 'does', 'did', 'doing', 'been', 'being', 'here',
        'there', 'where', 'which', 'while', 'also', 'just', 'only', 'very', 'much',
        'take', 'takes', 'give', 'gives', 'keep', 'keeps', 'know', 'knows', 'thing',
        'things', 'something', 'anything', 'everything', 'across', 'through',
    ];

    // ---- reading a skill ----------------------------------------------------

    /**
     * Split a comma-separated front-matter list into clean slugs.
     *
     * The front matter is not YAML — Keelhob_Skills::parse_markdown() matches
     * one key per line with no nesting and no lists — so every edge field is one
     * line of comma-separated names, and this is what turns it into an array.
     *
     * @return string[]
     */
    public static function slugs(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $part) {
            $slug = strtolower(trim($part, " \t\"'`[]"));
            $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
            $slug = trim((string) preg_replace('/-+/', '-', (string) $slug), '-');
            if ($slug !== '' && !in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    /** Anything unrecognised is a procedure, which is the least surprising default. */
    public static function role(array $skill): string
    {
        $role = strtolower(trim((string) ($skill['role'] ?? '')));
        return in_array($role, self::ROLES, true) ? $role : self::ROLE_DEFAULT;
    }

    /**
     * Anything unrecognised runs anywhere.
     *
     * Defaulting to the LOOSEST value is deliberate, and it is the opposite of
     * what a safety-minded default usually looks like. A wrong `workstation`
     * default would make every existing skill refuse to run on Telegram
     * overnight, breaking working setups over a field their author never wrote.
     * A wrong `any` leaves exactly the behaviour there was before this field
     * existed. The linter is what makes authors state it; the runtime is not
     * the place to guess.
     */
    public static function surface(array $skill): string
    {
        $surface = strtolower(trim((string) ($skill['surface'] ?? '')));
        return in_array($surface, self::SURFACES, true) ? $surface : self::SURFACE_DEFAULT;
    }

    /**
     * What to tell an agent about the session it is actually in.
     *
     * The catalog explains what each surface MEANS; this says which one the
     * agent has. Both halves are needed and neither is guessable: a skill body
     * reads identically down every pipe, and an agent has no way to discover
     * that this particular connection has no browser attached.
     *
     * Written once here rather than at each call site because the wording is
     * the whole product — an agent that is told "you cannot do this" without
     * being told what CAN do it will improvise, which is the failure this is
     * for.
     *
     * @param string $session One of SURFACES: what this connection offers.
     */
    public static function session_surface_notice(string $session): string
    {
        $blocked = [];
        foreach (self::SURFACES as $surface) {
            if (!self::surface_allows($session, $surface)) {
                $blocked[] = $surface;
            }
        }
        if (empty($blocked)) {
            return 'THIS SESSION IS A WORKSTATION. Every skill in the catalog can be completed here, including any marked "needs: interactive" or "needs: workstation".';
        }
        return sprintf(
            'THIS SESSION IS "%s". You CANNOT complete a skill the catalog marks "needs: %s". If the task requires one, name the skill, say plainly that this connection cannot finish it, and tell the owner to run it from Claude Code or an IDE connected to this site. Do not start it and work around the missing tool, and never report a result you could not measure.',
            $session,
            implode('" or "needs: ', $blocked)
        );
    }

    /** How demanding a surface is. Higher needs more of the session. */
    public static function surface_rank(string $surface): int
    {
        $i = array_search($surface, self::SURFACES, true);
        return $i === false ? 0 : (int) $i;
    }

    /**
     * Can a session of this kind finish a skill of that kind?
     *
     * Callers pass what the SESSION offers; the skill says what it NEEDS. A
     * workstation session can run everything, chat can run `any` and
     * `interactive`, an unattended task can run only `any`.
     */
    public static function surface_allows(string $session, string $needed): bool
    {
        return self::surface_rank($session) >= self::surface_rank($needed);
    }

    /**
     * The three edge lists, normalised.
     *
     * @return array{requires:string[], next_skills:string[], verified_by:string[]}
     */
    public static function edges(array $skill): array
    {
        return [
            'requires'    => self::slugs((string) ($skill['requires'] ?? '')),
            'next_skills' => self::slugs((string) ($skill['next_skills'] ?? '')),
            'verified_by' => self::slugs((string) ($skill['verified_by'] ?? '')),
        ];
    }

    /** Everything this skill points at, whatever the edge type. @return string[] */
    public static function outbound(array $skill): array
    {
        $e = self::edges($skill);
        return array_values(array_unique(array_merge($e['requires'], $e['next_skills'], $e['verified_by'])));
    }

    /**
     * The text under one `## Heading`, up to the next heading of the same level.
     * Returns '' when the heading is absent, which is how the section check
     * tells "missing" from "empty".
     */
    public static function section(string $body, string $heading): string
    {
        $body    = self::readable($body);
        $pattern = '/^##\s+' . preg_quote($heading, '/') . '\s*$(.*?)(?=^##\s|\z)/msi';
        return preg_match($pattern, $body, $m) ? trim($m[1]) : '';
    }

    /** True when the heading exists at all, even with nothing under it. */
    public static function has_section(string $body, string $heading): bool
    {
        return (bool) preg_match('/^##\s+' . preg_quote($heading, '/') . '\s*$/mi', self::readable($body));
    }

    /**
     * The body as structure, with fenced code blocks removed.
     *
     * A skill is allowed to show what a skill looks like, and skill-creator
     * does — including an example "## Routing examples" block with placeholder
     * phrases in it. Without this, the first heading found was the one inside
     * the fence, so the linter judged skill-creator against a sample it had
     * printed for a reader rather than against its own. A code sample must
     * never be read as structure.
     */
    private static function readable(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        return (string) preg_replace('/^```.*?^```[^\n]*$/ms', '', $body);
    }

    /**
     * The `## Routing examples` fixture: phrases that must load this skill, and
     * phrases that must not.
     *
     *   - fires: my site is slow on google
     *   - skips: write me a blog post
     *
     * @return array{fires:string[], skips:string[]}
     */
    public static function routing_examples(string $body): array
    {
        $out = ['fires' => [], 'skips' => []];
        $section = self::section($body, 'Routing examples');
        foreach (explode("\n", $section) as $line) {
            if (preg_match('/^\s*[-*]\s*(fires|skips)\s*:\s*(.+?)\s*$/i', $line, $m)) {
                $out[strtolower($m[1])][] = $m[2];
            }
        }
        return $out;
    }

    /**
     * Significant words, lowercased, singularised and de-duplicated.
     *
     * The singularising is not decoration. Without it "coupon" and "coupons"
     * were different words, so a user phrase almost never matched the skill
     * written to serve it, every score came out low, and half the set tied with
     * half the set — which made the routing check look like it was finding
     * ambiguity when it was really just failing to read English.
     *
     * @return string[]
     */
    public static function tokens(string $text): array
    {
        $text = strtolower((string) preg_replace('/[^a-z0-9\s\-]/i', ' ', $text));
        $out  = [];
        foreach (preg_split('/[\s\-]+/', $text) ?: [] as $raw) {
            // Both forms are tested against the stopword list. Stemming first
            // and testing once let "this" through as "thi", which then matched
            // any skill whose prose happened to contain the word "this" — so
            // the highest-scoring skill for "make this page look more premium"
            // was the one that said "this" most often. A stopword has to be
            // dropped whichever shape it arrives in.
            $word = self::stem($raw);
            if (strlen($word) < 3
                || in_array($raw, self::STOPWORDS, true)
                || in_array($word, self::STOPWORDS, true)
                || in_array($word, $out, true)
            ) {
                continue;
            }
            $out[] = $word;
        }
        return $out;
    }

    /**
     * The crudest stemmer that helps: plural to singular, nothing else.
     *
     * Deliberately not a real stemmer. "-ss" is left alone so "css" and
     * "business" survive, and no attempt is made at "-es" because it turns
     * "changes" into "chang" while "change" stays whole, which is worse than
     * doing nothing.
     */
    public static function stem(string $word): string
    {
        if (strlen($word) > 4 && substr($word, -3) === 'ies') {
            return substr($word, 0, -3) . 'y';
        }
        if (strlen($word) > 3 && substr($word, -1) === 's' && substr($word, -2) !== 'ss') {
            return substr($word, 0, -1);
        }
        return $word;
    }

    /**
     * How well a phrase matches a skill, 0.0 to 1.0 — the share of the phrase's
     * significant words that the skill's name, description or "When to use"
     * section actually contains.
     */
    public static function route_score(string $phrase, array $skill): float
    {
        $want = self::tokens($phrase);
        if (empty($want)) {
            return 0.0;
        }
        $have = self::tokens(
            (string) ($skill['name'] ?? '') . ' '
            . (string) ($skill['description'] ?? '') . ' '
            . self::section((string) ($skill['body'] ?? ''), 'When to use')
        );
        return count(array_intersect($want, $have)) / count($want);
    }

    // ---- rules that judge one skill on its own ------------------------------

    /**
     * @param array $skill Parsed skill: name, description, body, and the edge fields.
     * @param string[] $known Every skill name that exists, for dangling-edge detection.
     * @return array<int, array{level:string, code:string, skill:string, message:string}>
     */
    public static function check(array $skill, array $known = []): array
    {
        $name = (string) ($skill['name'] ?? '');
        $body = str_replace(["\r\n", "\r"], "\n", (string) ($skill['body'] ?? ''));
        $desc = trim((string) ($skill['description'] ?? ''));
        $f    = [];

        if ($name === '' || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) {
            $f[] = self::finding('error', 'name', $name, 'The name must be a kebab-case slug — it is how skill_get addresses the skill.');
        }

        // The description is the only text a model sees when deciding whether to
        // load the body. Everything else in the file is invisible until then.
        if ($desc === '') {
            $f[] = self::finding('error', 'description-missing', $name, 'No description. Nothing can route a task to this skill.');
        } elseif (strlen($desc) < self::DESC_MIN) {
            $f[] = self::finding('error', 'description-short', $name, sprintf(
                'The description is %d characters; %d is the floor. It has to say WHEN to load the skill, not just what it is.',
                strlen($desc),
                self::DESC_MIN
            ));
        } elseif (strlen($desc) > self::DESC_MAX) {
            $f[] = self::finding('warn', 'description-long', $name, sprintf(
                'The description is %d characters. Past about %d it is a body, and it is paid for in every session.',
                strlen($desc),
                self::DESC_MAX
            ));
        }

        // parse_markdown() matches one key per line. A description that wraps
        // onto a second line silently loses everything after the first, and the
        // skill becomes unroutable with no error anywhere.
        if (strpos($desc, "\n") !== false) {
            $f[] = self::finding('error', 'description-wrapped', $name, 'The description spans more than one line. The front-matter parser reads one line per key and would drop the rest.');
        }

        $role = self::role($skill);
        if (($skill['role'] ?? '') !== '' && $role !== strtolower(trim((string) $skill['role']))) {
            $f[] = self::finding('error', 'role-unknown', $name, sprintf(
                'Unknown role "%s". Use one of: %s.',
                (string) $skill['role'],
                implode(', ', self::ROLES)
            ));
        }

        // A typo here is worse than a missing value, because both read as "runs
        // anywhere" at runtime and only one of them is what the author meant.
        // `surface: workstaton` would silently let a browser-driven skill start
        // on Telegram — exactly the failure the field was added to prevent.
        $surface = self::surface($skill);
        if (($skill['surface'] ?? '') !== '' && $surface !== strtolower(trim((string) $skill['surface']))) {
            $f[] = self::finding('error', 'surface-unknown', $name, sprintf(
                'Unknown surface "%s". Use one of: %s.',
                (string) $skill['surface'],
                implode(', ', self::SURFACES)
            ));
        }

        // A skill that names a tool nobody on this site provides is describing
        // work that has to happen on the caller's machine, and it has to say so.
        // Caught by looking for the phrases that give it away rather than by
        // parsing prose: the point is to make the author think, not to be
        // exhaustive, and a false positive costs one word of frontmatter.
        if ($surface === self::SURFACE_DEFAULT) {
            $needs_local = ['headless browser', 'chrome-devtools', 'playwright', 'puppeteer', 'firecrawl', 'screenshot the page', 'on your machine'];
            foreach ($needs_local as $phrase) {
                if (stripos($body, $phrase) !== false) {
                    $f[] = self::finding('error', 'surface-understated', $name, sprintf(
                        'The body mentions "%s" but the skill claims surface: any. Work that needs a tool on the caller\'s machine cannot finish over Telegram — declare surface: workstation.',
                        $phrase
                    ));
                    break;
                }
            }
        }

        foreach (self::SECTIONS as $heading) {
            if (!self::has_section($body, $heading)) {
                $f[] = self::finding('error', 'section-missing', $name, sprintf('Missing the "## %s" section.', $heading));
            }
        }

        // "When NOT to use" is the half of routing everyone forgets. A skill
        // firing on the wrong job costs as much as one that never fires.
        if (self::has_section($body, 'When NOT to use') && self::section($body, 'When NOT to use') === '') {
            $f[] = self::finding('error', 'section-empty', $name, 'The "When NOT to use" heading is there but says nothing. One line is enough; a blank one is worse than none.');
        }

        $lines = substr_count(trim($body), "\n") + 1;
        $max   = $role === 'reference' ? self::LINES_MAX_REFERENCE : self::LINES_MAX;
        if ($lines < self::LINES_MIN) {
            $f[] = self::finding('warn', 'too-short', $name, sprintf(
                'The body is %d lines. Under %d it is a paragraph — consider folding it into a skill that already exists rather than competing with one.',
                $lines,
                self::LINES_MIN
            ));
        } elseif ($lines > $max) {
            $f[] = self::finding('error', 'too-long', $name, sprintf(
                'The body is %d lines; the limit for a %s skill is %d. Split it, or say what it really is.',
                $lines,
                $role,
                $max
            ));
        }

        $edges = self::edges($skill);
        if (empty($edges['next_skills']) && empty($edges['verified_by'])) {
            $f[] = self::finding('error', 'dead-end', $name, 'Names nothing to do next and nothing that verifies it. An agent that finishes this skill has nowhere to go.');
        }

        if (!empty($known)) {
            foreach ($edges as $kind => $targets) {
                foreach ($targets as $target) {
                    if (!in_array($target, $known, true)) {
                        $f[] = self::finding('error', 'dangling-edge', $name, sprintf('%s names "%s", which is not a skill on this site.', $kind, $target));
                    }
                }
                if (in_array($name, $targets, true)) {
                    $f[] = self::finding('error', 'self-edge', $name, sprintf('%s names itself.', $kind));
                }
            }
        }

        $ex = self::routing_examples($body);
        if (count($ex['fires']) < 2) {
            $f[] = self::finding('error', 'examples-few', $name, 'Fewer than two "- fires:" phrases. These are what prove the description contains the words a user would actually type.');
        }
        if (empty($ex['skips'])) {
            $f[] = self::finding('warn', 'examples-no-skips', $name, 'No "- skips:" phrase. Without one, nothing checks that this skill stays out of jobs that are not its own.');
        }

        return $f;
    }

    // ---- rules that need every skill at once --------------------------------

    /**
     * @param array<string, array> $skills name => parsed skill
     * @return array<int, array{level:string, code:string, skill:string, message:string}>
     */
    public static function check_graph(array $skills): array
    {
        $f     = [];
        $names = array_keys($skills);

        // Nothing points at it, so it is only ever reached if the owner's own
        // wording happens to match. A skill nobody can find does not exist.
        $inbound = array_fill_keys($names, 0);
        foreach ($skills as $skill) {
            foreach (self::outbound($skill) as $target) {
                if (isset($inbound[$target])) {
                    $inbound[$target]++;
                }
            }
        }
        foreach ($names as $name) {
            if ($inbound[$name] === 0 && $name !== self::ENTRY_POINT) {
                $f[] = self::finding('error', 'orphan', $name, 'No other skill points at this one. Add it to a related skill\'s next_skills, or it will never be reached.');
            }
        }

        // A cycle is the way this whole design could hang an agent: if A is
        // verified by B and B is verified by A, "am I done yet" never ends.
        // requires and verified_by are checked separately, because a cycle in
        // one is not excused by the other being clean.
        foreach (['requires', 'verified_by'] as $kind) {
            $graph = [];
            foreach ($skills as $name => $skill) {
                $graph[$name] = self::edges($skill)[$kind];
            }
            foreach (self::cycles($graph) as $cycle) {
                $f[] = self::finding('error', 'cycle', $cycle[0], sprintf(
                    '%s forms a loop: %s. An agent following it never finishes.',
                    $kind,
                    implode(' -> ', $cycle)
                ));
            }
        }

        // A binding edge cannot demand more of the session than the skill does.
        //
        // If A declares surface: any but REQUIRES B, and B needs a workstation,
        // then A cannot honestly be run anywhere — an agent on Telegram loads
        // A, is told to load B first, and is stuck. Same for verified_by: a
        // skill whose verifier needs a browser is a skill that cannot be
        // reported done without one.
        //
        // next_skills is deliberately NOT checked. That edge is a suggestion
        // for afterwards, not a prerequisite, so an `any` skill may perfectly
        // well point at a workstation one — "and when you are next at your
        // machine, do this too" is a useful thing to be able to say.
        foreach ($names as $name) {
            $own      = self::surface($skills[$name]);
            $own_rank = self::surface_rank($own);
            foreach (['requires', 'verified_by'] as $kind) {
                foreach (self::edges($skills[$name])[$kind] as $target) {
                    if (!isset($skills[$target])) {
                        continue; // dangling edges are reported by check()
                    }
                    $their = self::surface($skills[$target]);
                    if (self::surface_rank($their) > $own_rank) {
                        $f[] = self::finding('error', 'surface-conflict', $name, sprintf(
                            'Declares surface: %s but %s "%s", which needs surface: %s. Raise this skill to %s, or move the edge to next_skills if it is advice rather than a prerequisite.',
                            $own,
                            $kind,
                            $target,
                            $their,
                            $their
                        ));
                    }
                }
            }
        }

        // What every connected session pays before any work happens. Charged to
        // the entry point because no single skill is at fault for the total —
        // it is a property of the set, and it is the set that has to give.
        $catalog = self::catalog_cost($skills);
        if ($catalog > self::CATALOG_BUDGET) {
            $f[] = self::finding('error', 'catalog-budget', self::ENTRY_POINT, sprintf(
                'The session-start catalog costs %d KB across %d skills; the budget is %d KB. Every connected agent pays this before doing anything. Tighten descriptions or merge two skills.',
                (int) round($catalog / 1024),
                count($skills),
                (int) round(self::CATALOG_BUDGET / 1024)
            ));
        }

        // What it actually costs to follow this skill properly.
        foreach ($names as $name) {
            $weight = self::weight($name, $skills);
            if ($weight > self::WEIGHT_BUDGET) {
                $f[] = self::finding('error', 'too-heavy', $name, sprintf(
                    'Following this skill and everything it requires costs %d KB of context; the budget is %d KB. Move something to next_skills, which is advice rather than an obligation.',
                    (int) round($weight / 1024),
                    (int) round(self::WEIGHT_BUDGET / 1024)
                ));
            }
        }

        // Two skills competing for the same request make the agent pick one at
        // random, and the loser is dead weight in every session. This is how a
        // skill pack rots as it grows, so it is worth catching at 18 rather
        // than at 60.
        foreach ($names as $i => $a) {
            foreach (array_slice($names, $i + 1) as $b) {
                $sim = self::similarity(
                    (string) ($skills[$a]['description'] ?? ''),
                    (string) ($skills[$b]['description'] ?? '')
                );
                if ($sim >= self::CLASH_THRESHOLD) {
                    $f[] = self::finding('warn', 'clash', $a, sprintf(
                        'The description overlaps %d%% with %s. Make each one say what the OTHER is not for.',
                        (int) round($sim * 100),
                        $b
                    ));
                }
            }
        }

        // The fixture check: does the wording actually route where it claims?
        foreach ($skills as $name => $skill) {
            $ex = self::routing_examples((string) ($skill['body'] ?? ''));

            foreach ($ex['fires'] as $phrase) {
                $ranked = self::rank($phrase, $skills);
                $own    = $ranked[$name] ?? 0.0;
                if ($own <= 0.0) {
                    $f[] = self::finding('error', 'routing-miss', $name, sprintf(
                        '"%s" shares no significant word with this skill\'s name, description or "When to use". Put the words users say into the description.',
                        $phrase
                    ));
                    continue;
                }
                $beaten = array_filter($ranked, static fn($v, $k) => $k !== $name && $v > $own, ARRAY_FILTER_USE_BOTH);
                if (!empty($beaten)) {
                    $f[] = self::finding('error', 'routing-lost', $name, sprintf(
                        '"%s" matches %s better than it matches this skill. It would reach the wrong one.',
                        $phrase,
                        array_key_first($beaten)
                    ));
                    continue;
                }

                // A tie is only worth raising when this skill is ALSO a weak
                // match. Two skills both scoring well on a short phrase is
                // normal and usually correct — "build me a website" genuinely
                // belongs to build-standard and site-build-kickoff both. What
                // is not fine is a phrase that barely matches anything and has
                // company: that is thin wording, not honest overlap. Warning on
                // every tie fired eleven times on a set that is well separated,
                // and a check that cries wolf is one nobody reads.
                $tied = array_filter($ranked, static fn($v, $k) => $k !== $name && $v >= $own, ARRAY_FILTER_USE_BOTH);
                if (!empty($tied) && $own < 0.5) {
                    $f[] = self::finding('warn', 'routing-weak', $name, sprintf(
                        '"%s" only weakly matches this skill (%d%%) and matches %s just as well. Put more of the words a user would actually type into the description.',
                        $phrase,
                        (int) round($own * 100),
                        array_key_first($tied)
                    ));
                }
            }

            foreach ($ex['skips'] as $phrase) {
                $ranked = self::rank($phrase, $skills);
                $top    = array_key_first($ranked);
                if ($top === $name && ($ranked[$name] ?? 0.0) > 0.0) {
                    $f[] = self::finding('error', 'routing-overreach', $name, sprintf(
                        '"%s" is meant to go elsewhere, but this skill is its best match. Narrow the description.',
                        $phrase
                    ));
                }
            }
        }

        return $f;
    }

    /**
     * Every skill scored against one phrase, best first.
     *
     * @param array<string, array> $skills
     * @return array<string, float>
     */
    public static function rank(string $phrase, array $skills): array
    {
        $scores = [];
        foreach ($skills as $name => $skill) {
            $scores[$name] = self::route_score($phrase, $skill + ['name' => $name]);
        }
        arsort($scores);
        return $scores;
    }

    /**
     * Bytes an agent reads to follow a skill: its own body plus every body it
     * transitively requires. next_skills and verified_by are not counted — they
     * happen after the work, not before it.
     *
     * @param array<string, array> $skills
     */
    public static function weight(string $name, array $skills, array $seen = []): int
    {
        if (isset($seen[$name]) || !isset($skills[$name])) {
            return 0;
        }
        $seen[$name] = true;
        $bytes = strlen((string) ($skills[$name]['body'] ?? ''));
        foreach (self::edges($skills[$name])['requires'] as $dep) {
            $bytes += self::weight($dep, $skills, $seen);
            $seen[$dep] = true;
        }
        return $bytes;
    }

    /**
     * What the session-start catalog costs in bytes.
     *
     * Counted the way Keelhob_MCP_Server::format_skill_catalog() actually
     * prints a line, not with a flat per-skill allowance. The first version
     * added 60 bytes a skill for the edges and reported 9 KB against a real
     * catalog of 10.8 KB, because build-standard is the router and names
     * sixteen skills on one line. A budget check that under-reports is not a
     * budget check.
     *
     * Public so the test suite measures the shipped set with this method rather
     * than a copy of the sum — a duplicated formula is a formula that drifts,
     * and the copy would keep passing after this one was corrected.
     *
     * @param array<string, array> $skills
     */
    public static function catalog_cost(array $skills): int
    {
        $bytes = 0;
        foreach ($skills as $name => $skill) {
            $e = self::edges($skill);
            $bytes += strlen((string) $name)
                + strlen(self::role($skill))
                + strlen((string) ($skill['description'] ?? ''))
                + strlen(implode(', ', array_slice($e['next_skills'], 0, self::CATALOG_EDGES)))
                + strlen(implode(', ', $e['verified_by']))
                + 45;   // the punctuation, labels and newline around them
        }
        return $bytes;
    }

    /** Jaccard overlap of two texts' significant words, 0.0 to 1.0. */
    public static function similarity(string $a, string $b): float
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if (empty($ta) || empty($tb)) {
            return 0.0;
        }
        $union = count(array_unique(array_merge($ta, $tb)));
        return $union === 0 ? 0.0 : count(array_intersect($ta, $tb)) / $union;
    }

    /**
     * Every cycle in a directed graph, as a path that returns to its start.
     *
     * @param array<string, string[]> $graph
     * @return array<int, string[]>
     */
    public static function cycles(array $graph): array
    {
        $found = [];
        $state = [];   // 0/unset = unvisited, 1 = on the current path, 2 = done
        $path  = [];

        $walk = static function (string $node) use (&$walk, &$graph, &$state, &$path, &$found): void {
            if (($state[$node] ?? 0) === 2) {
                return;
            }
            if (($state[$node] ?? 0) === 1) {
                $at = array_search($node, $path, true);
                if ($at !== false) {
                    $found[] = array_merge(array_slice($path, (int) $at), [$node]);
                }
                return;
            }
            $state[$node] = 1;
            $path[] = $node;
            foreach ($graph[$node] ?? [] as $next) {
                if (isset($graph[$next])) {
                    $walk($next);
                }
            }
            array_pop($path);
            $state[$node] = 2;
        };

        foreach (array_keys($graph) as $node) {
            $walk($node);
        }
        return $found;
    }

    // ---- reporting ----------------------------------------------------------

    /**
     * A single number for a pull request. Errors cost 12, warnings cost 3, and
     * the floor is 0 — a skill with nine problems is not usefully worse than a
     * skill with twelve.
     *
     * @param array<int, array{level:string}> $findings
     */
    public static function score(array $findings): int
    {
        $points = 100;
        foreach ($findings as $finding) {
            $points -= ($finding['level'] ?? 'warn') === 'error' ? 12 : 3;
        }
        return max(0, $points);
    }

    /** @param array<int, array{level:string}> $findings */
    public static function has_errors(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (($finding['level'] ?? '') === 'error') {
                return true;
            }
        }
        return false;
    }

    /** @return array{level:string, code:string, skill:string, message:string} */
    private static function finding(string $level, string $code, string $skill, string $message): array
    {
        return ['level' => $level, 'code' => $code, 'skill' => $skill, 'message' => $message];
    }
}
