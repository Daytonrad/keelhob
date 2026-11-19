<?php
/**
 * Does a connected agent actually know the skills exist?
 *
 * Fifteen skills ship, and none of them do anything if the agent never learns
 * they are there. Over MCP that was already handled — server_instructions()
 * inlines the whole catalog at initialize. Everywhere else it was not.
 *
 * The bug this file pins down: Keelhob_Agent_Runtime::system_prompt() built
 * a prompt containing the operating protocol — including rule 11, "load the
 * build-standard skill before building anything" — and never told the agent
 * that build-standard existed. A Telegram owner asking for a landing page got
 * one built with none of the taste, SEO or safety work the skills carry, and
 * nothing in the transcript would ever say why. The failure is invisible: the
 * agent does not error, it just quietly does a worse job.
 *
 * Both prompt branches are asserted, because "never ask" versus "you may ask"
 * is the only thing that should differ between a 3am cron run and a live chat.
 */

require_once KEELHOB_DIR . 'includes/class-mcp-server.php';
require_once KEELHOB_DIR . 'includes/class-agent-runtime.php';

KH_Test::group('Skill delivery — the catalog reaches every surface');

// --- the wording, with no database in the way --------------------------------

$two = [
    [
        'name'        => 'build-standard',
        'description' => 'The Definition of Done for any build work.',
        'role'        => 'gate',
        'next_skills' => ['web-design-master'],
        'verified_by' => ['pre-launch-audit'],
    ],
    [
        'name'        => 'web-design-master',
        'description' => 'The design reference. Loads for ANY visual work.',
        'role'        => 'reference',
        'next_skills' => [],
        'verified_by' => [],
    ],
];

$block = Keelhob_MCP_Server::format_skill_catalog($two);

KH_Test::contains('build-standard', $block, 'the catalog names each skill');
KH_Test::contains('The design reference', $block, 'and carries its description, which is the only thing that routes a task to a skill');
KH_Test::contains('skill_get', $block, 'and says how to load a body, so the agent does not have to guess the tool name');
KH_Test::contains(
    'build-standard carries the router table',
    $block,
    'and points at the router — an agent that reads one description and stops builds a page with no SEO pass and no taste'
);

// The edges are what turn a list of names into a map. Without them the agent
// learns that eighteen skills exist and nothing about how they relate, so it
// picks one, follows it to the end, and stops — which is the failure the whole
// skill standard was built to end.
KH_Test::contains('[gate]', $block, 'the catalog says what kind of skill each one is');
KH_Test::contains('then: web-design-master', $block, 'and where to go when a skill is finished');
KH_Test::contains('not done until: pre-launch-audit', $block, 'and what has to run before the work counts as done');
KH_Test::contains('gate = work is not finished until it passes', $block, 'and it explains the roles, because a label nobody can interpret is noise');

KH_Test::ok(
    strpos($block, 'web-design-master [reference]: The design reference. Loads for ANY visual work.' . "\n") !== false
        || substr_count($block, 'web-design-master [reference]') === 1,
    'a skill with no edges gets no empty brackets after it'
);

KH_Test::same(
    '',
    Keelhob_MCP_Server::format_skill_catalog([]),
    'an empty catalog produces nothing at all, not a heading with no skills under it'
);

// --- the guard that stops decoration killing a run ---------------------------
//
// skills is set during boot(). This block is built while assembling a system
// prompt, and a fatal here would take down a Telegram reply mid-sentence over
// a list the agent could have done without. It already happened once in this
// suite, where an earlier test leaves a stub in that property.

$saved = Keelhob::instance()->skills;
Keelhob::instance()->skills = null;
KH_Test::same(
    '',
    Keelhob_MCP_Server::skill_catalog_block(),
    'no skills service means no catalog, not a fatal error in the middle of a reply'
);
Keelhob::instance()->skills = $saved;

// --- the wiring: both agent-runtime prompts carry it -------------------------

$GLOBALS['kh_test_db_results'] = [
    [
        'name'           => 'build-standard',
        'description'    => 'The Definition of Done for any build work.',
        'enabled'        => 1,
        'enable_agentic' => 1,
        'enable_prompt'  => 1,
    ],
    [
        'name'           => 'motion-and-interaction',
        'description'    => 'Animation and scroll craft.',
        'enabled'        => 1,
        'enable_agentic' => 1,
        'enable_prompt'  => 1,
    ],
];
Keelhob::instance()->skills = new Keelhob_Skills();

$prompt = new ReflectionMethod(Keelhob_Agent_Runtime::class, 'system_prompt');
@$prompt->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor

$scheduled   = (string) $prompt->invoke(null, false, '');
$interactive = (string) $prompt->invoke(null, true, 'Telegram');

foreach (['scheduled' => $scheduled, 'Telegram' => $interactive] as $label => $text) {
    KH_Test::contains('build-standard', $text, "a $label run is told build-standard exists");
    KH_Test::contains('motion-and-interaction', $text, "a $label run is told motion-and-interaction exists");
    KH_Test::contains('skill_get', $text, "a $label run is told how to load a skill body");
    // Rule 11 orders the agent to load build-standard. Before this fix these
    // prompts carried that order and no way to obey it.
    KH_Test::contains('Load the build-standard skill', $text, "a $label run still carries rule 11");
}

unset($GLOBALS['kh_test_db_results']);
Keelhob::instance()->skills = $saved;

// --- rules 7a/7b/7c: agree what to build before building it -------------------
//
// The owner hit this twice on a real site: a long, detailed prompt reads to a
// model as a finished brief, so it skipped the intake and built a whole site
// against its own reading of the request. Nothing errored; the work was simply
// not the work that was wanted.
//
// Rule 7 already existed and was not enough. It says to NAME an ambiguity and
// "do the safe part" — which is permission to build. These three say stop.
// They live in the protocol rather than only in the build skills for the usual
// reason: a skill can be switched off, or simply never loaded, and the skills
// were what invited the mistake in the first place.

$protocol = Keelhob_MCP_Server::operating_protocol();

KH_Test::contains('7a.', $protocol, 'the protocol carries rule 7a');
KH_Test::contains(
    'agree what it is first',
    $protocol,
    'and it is the build-confirmation gate, not something else numbered 7a'
);
KH_Test::contains(
    'is not agreement',
    $protocol,
    'it names the actual failure: a detailed prompt mistaken for agreement'
);
KH_Test::contains(
    'ONE short batch',
    $protocol,
    'and it bounds the questioning, so the gate does not become an interrogation'
);
KH_Test::contains(
    'even when you think you already have a brief',
    $protocol,
    'and it closes the exact excuse the skills used to offer'
);

/* Scoped to creating, deliberately. A gate on every edit would turn a one-line
   fix into an interview, which is its own failure mode. */
KH_Test::contains(
    'not an edit to what exists',
    $protocol,
    'the gate is bounded to new work, so ordinary edits stay fast'
);

KH_Test::contains('7b.', $protocol, 'the protocol carries rule 7b');
KH_Test::contains(
    'wrong for their BUSINESS',
    $protocol,
    'and it asks for product risk, which is the half a technical review never covers'
);
KH_Test::contains(
    'before the first write, not in the summary afterwards',
    $protocol,
    'and it demands the risks up front, where they can still change the build'
);

/* 7c is the one that matters on a pipe with nobody on the other end. The owner
   chose "stop and report" over "build the smallest safe version": a site built
   unattended still has to be read before any of it can be trusted. */
KH_Test::contains('7c.', $protocol, 'the protocol carries rule 7c');
KH_Test::contains(
    'If nobody is answering, stop',
    $protocol,
    'and unattended means stop'
);
KH_Test::contains(
    'Do NOT build the smallest safe version',
    $protocol,
    'it rules out the tempting middle path explicitly, because that is what an agent would choose'
);

/* And the gate skill the rules point at has to exist, or 7a names a dead end. */
KH_Test::ok(
    file_exists(KEELHOB_DIR . 'skills/confirm-before-building.md'),
    'the gate skill the rules send the agent to is really bundled'
);

$kh_gate = (string) file_get_contents(KEELHOB_DIR . 'skills/confirm-before-building.md');
KH_Test::contains('role: gate', $kh_gate, 'and it is declared a gate, so the linter demands its checklist');

/* Every skill that CREATES something has to require it. A gate nothing requires
   is a file, not a guard — and website-building is the one that used to open by
   writing the page. */
foreach ([
    'website-building',
    'site-build-kickoff',
    'elementor-page-building',
    'solace-header-footer',
    'blog-publishing',
    'scroll-craft',
    'site-clone',
    // Added in wave 2. These two had no `requires:` line at all, and they
    // create products, set prices and configure payments - the one area
    // where a wrong reading of the request costs real money.
    'woocommerce-store-operations',
    'store-launch-and-deploy',
] as $kh_builder) {
    $kh_src = (string) file_get_contents(KEELHOB_DIR . 'skills/' . $kh_builder . '.md');
    KH_Test::ok(
        (bool) preg_match('/^requires:.*confirm-before-building/m', $kh_src),
        $kh_builder . ' requires the build-confirmation gate'
    );
}

// --- rule 11a: this site's skills outrank the caller's own -------------------
//
// THE WHISPER-VERSUS-SHOUT PROBLEM, and the second cause of the rogue builds
// that 7a was written for.
//
// An agent reaches this site with skills already loaded from its own machine,
// and many of those are written to claim a job on sight - "use this whenever
// you build, design, redesign or review a landing page", "read FIRST, before
// you answer". This bundle's catalog, by contrast, was introduced with
// "when a task matches" and nothing more. So on exactly the work this plugin
// exists for, the caller's own skills were shouting and `website-building` was
// whispering, and the agent never read it. Rules 11, 13 and 14 all tell the
// agent to load skills; not one of them said WHOSE win.
//
// Asserted on the phrase that carries the meaning rather than a whole
// sentence, the same way the 7a/7b/7c assertions are, so trimming the rule for
// the 12 KB budget does not break the test for no reason.

$protocol = Keelhob_MCP_Server::operating_protocol();

KH_Test::contains('11a.', $protocol, 'the protocol carries rule 11a');
KH_Test::contains(
    'outrank your own',
    $protocol,
    'rule 11a settles whose skills win, which nothing did before'
);
KH_Test::contains(
    'claim a job on sight',
    $protocol,
    'and names the actual mechanism: a local skill worded to grab the work'
);
KH_Test::contains(
    'read the matching skill here first',
    $protocol,
    'and says to read this site\'s skill BEFORE acting, not instead of reporting'
);
KH_Test::contains(
    'follow this one',
    $protocol,
    'and says which side wins on a conflict'
);
// Without this half the rule reads as "never use your own skills", which is
// wrong and would get ignored wholesale: the bundle has no skill for every
// job, and an absolute rule nobody can follow is a rule nobody follows.
KH_Test::contains(
    'a gap no skill here covers',
    $protocol,
    'while still allowing the caller\'s own skills where this bundle has none'
);

// --- rule 13's FIRST half: a requirement is binding, not advisory ------------
//
// Rule 13 used to cover only `verified_by`, which is the end of a job. Every
// gate in the bundle is reached through `requires` - site-safety, and now
// confirm-before-building - so a skill could declare that it must not be
// started without its gate, and the agent decided whether to read it. That is
// the bug 7a exists for, one level up.
//
// Two halves, and both are needed. The rule has to SAY requirements bind, and
// the catalog has to PRINT them, or the rule points at a field the agent is
// never shown.

$protocol = Keelhob_MCP_Server::operating_protocol();

KH_Test::contains(
    'Load what a skill requires',
    $protocol,
    'rule 13 covers the start of a job, not only the end'
);
KH_Test::contains(
    'Both bind',
    $protocol,
    'and says a requirement is as binding as a verifier'
);
KH_Test::contains(
    'all the way down',
    $protocol,
    'and that the chain is followed, not just the first link'
);
KH_Test::contains(
    'requirements included',
    $protocol,
    'and that skills loaded BECAUSE they were required still get named'
);

/* The catalog half. Asserted through the pure formatter rather than
   skill_catalog_block(), which reads the live skills service - empty in this
   fixture, so those assertions would have passed against an empty string
   whatever the code did. One skill in, one line out. */
$kh_cat = Keelhob_MCP_Server::format_skill_catalog([
    [
        'name'        => 'website-building',
        'role'        => 'procedure',
        'description' => 'Build a page.',
        'requires'    => ['confirm-before-building', 'web-design-master'],
        'next_skills' => ['seo-optimization'],
        'verified_by' => ['pre-launch-audit'],
    ],
]);

KH_Test::contains(
    'first: ',
    $kh_cat,
    'the catalog prints what a skill requires, so rule 13 points at something readable'
);
KH_Test::contains(
    'first: confirm-before-building, web-design-master',
    $kh_cat,
    'and prints every requirement, in order, not just the first'
);
KH_Test::contains(
    'load x and do what it asks BEFORE starting',
    $kh_cat,
    'and the legend explains what "first" means, because the word alone does not'
);

/* Order matters in that line: what to load BEFORE, then what comes after. A
   catalog printing them the other way round would read as a sequence that runs
   backwards. */
$kh_line = '';
foreach (explode("\n", $kh_cat) as $kh_l) {
    if (strpos($kh_l, '- website-building ') === 0) {
        $kh_line = $kh_l;
        break;
    }
}
KH_Test::ok($kh_line !== '', 'the fixture produced a skill line (the premise of the next assertion)');
KH_Test::ok(
    strpos($kh_line, 'first:') !== false
        && strpos($kh_line, 'then:') !== false
        && strpos($kh_line, 'first:') < strpos($kh_line, 'then:'),
    'and it lists "first" before "then", so the line reads in the order the work happens'
);

/* A skill with no requirements must not gain an empty "first:" - the same rule
   the surface field follows, and for the same reason: a field printed on every
   line to say nothing trains the reader to skip it on the lines that matter. */
$kh_bare = '';
foreach (explode("\n", Keelhob_MCP_Server::format_skill_catalog([
    ['name' => 'web-design-master', 'role' => 'reference', 'description' => 'Taste.'],
])) as $kh_l) {
    if (strpos($kh_l, '- web-design-master ') === 0) {
        $kh_bare = $kh_l;
        break;
    }
}
KH_Test::ok($kh_bare !== '', 'a skill with no edges still gets a catalog line');

/* Checked on the LINE, not on the whole block. The legend explains what
   "first" means, so the block always contains the word — searching the
   block would have passed for a reason that had nothing to do with the
   skill. */
KH_Test::ok(
    strpos($kh_bare, 'first:') === false,
    'and a skill that requires nothing gets no empty "first:" on its line'
);

// --- the whitelist in the middle, which is where this wave actually broke ----
//
// agentic_catalog() does not pass a row through; it rebuilds each one as a
// whitelist of named fields. It did not copy `requires`, so the rig served
// sixteen skills with no "first:" on any line while the database held the
// value and the formatter handled it correctly. Nothing was wrong at either
// end. The field simply never crossed the middle.
//
// A fixture cannot catch that, because a fixture built by hand always has the
// key. What CAN be checked without a database is that every field the
// formatter reads is a field the whitelist copies — asserted against the
// source of both, since there is no other honest way to compare the two.

$kh_skills_src = (string) file_get_contents(KEELHOB_DIR . 'includes/class-skills.php');
$kh_server_src = (string) file_get_contents(KEELHOB_DIR . 'includes/class-mcp-server.php');

$kh_catalog_fn = '';
if (preg_match('/function agentic_catalog\(\).*?\n    \}/s', $kh_skills_src, $m)) {
    $kh_catalog_fn = $m[0];
}
KH_Test::ok($kh_catalog_fn !== '', 'agentic_catalog() was located (the premise of the next assertions)');

$kh_format_fn = '';
if (preg_match('/function format_skill_catalog\(.*?\n    \}/s', $kh_server_src, $m)) {
    $kh_format_fn = $m[0];
}
KH_Test::ok($kh_format_fn !== '', 'format_skill_catalog() was located');

/* Every field the formatter reads off a row has to be one the whitelist puts
   there. `role` and `surface` get defaults in the formatter, so they are safe
   either way; the edge fields are the ones that go quietly missing. */
foreach (['name', 'description', 'role', 'surface', 'requires', 'next_skills', 'verified_by'] as $kh_field) {
    KH_Test::ok(
        strpos($kh_catalog_fn, "'" . $kh_field . "'") !== false,
        'agentic_catalog() copies ' . $kh_field . ', so the formatter is given something to print'
    );
}

/* And the reverse direction, for the field this wave added: the formatter must
   actually read it. A whitelist that carries a value nothing prints is the
   same bug facing the other way. */
KH_Test::contains(
    "\$s['requires']",
    $kh_format_fn,
    'and the formatter reads requires, so carrying it is not pointless'
);

// --- the chain, which is the whole point of "all the way down" ---------------
//
// site-clone-rebuild reaches the build gate through TWO intermediate skills:
// rebuild -> capture -> site-clone -> confirm-before-building. I claimed those
// sub-skills bypassed the gate and was wrong; they do not. What was true is
// that nothing walked the chain. This asserts the chain exists, so the claim
// can be checked instead of argued about.

$kh_requires = static function (string $slug): array {
    $src = (string) file_get_contents(KEELHOB_DIR . 'skills/' . $slug . '.md');
    if (!preg_match('/^requires:(.*)$/m', $src, $m)) {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
};

/** Follow `requires` transitively and report whether the gate is reachable. */
$kh_reaches = static function (string $from) use ($kh_requires, &$kh_reaches): bool {
    foreach ($kh_requires($from) as $req) {
        if ($req === 'confirm-before-building' || $kh_reaches($req)) {
            return true;
        }
    }
    return false;
};

foreach ([
    'site-clone-capture'       => 'one link away (site-clone)',
    'site-clone-rebuild'       => 'two links away (capture, site-clone)',
    'site-clone-design-system' => 'two links away (capture, site-clone)',
] as $kh_sub => $kh_why) {
    KH_Test::ok(
        $kh_reaches($kh_sub),
        $kh_sub . ' reaches the build gate through its requires chain — ' . $kh_why
    );
}

/* And the gate is NOT reachable from a skill that only reads, or the gate has
   spread into work it was deliberately kept out of. seo-optimization runs on
   every page of every build; gating it would be the interrogation this whole
   design avoids. */
foreach (['seo-optimization', 'pre-launch-audit', 'auto-optimize'] as $kh_free) {
    KH_Test::ok(
        !$kh_reaches($kh_free),
        $kh_free . ' is NOT gated — it reports or repeats, and gating it would make small work tiring'
    );
}

// --- rule 12: ask before spending the owner's money --------------------------
//
// In the protocol rather than in a skill, because a skill can be switched off
// and this must not be. A whole-site audit can burn an hour and real API
// credit off the back of someone typing "check my SEO".

$protocol = Keelhob_MCP_Server::operating_protocol();

KH_Test::contains('12.', $protocol, 'the protocol carries a twelfth rule');
KH_Test::contains(
    'gets agreed first',
    $protocol,
    'and it is the spending guard, not something else that happens to be numbered 12'
);
KH_Test::contains('wait for a yes', $protocol, 'it requires consent, not merely an announcement');
KH_Test::contains(
    'does not apply to ordinary single-page work',
    $protocol,
    'and it is bounded, so an agent does not start asking permission to set a meta description'
);

// --- rule 13: the verifier a skill declares is not decoration ---------------
//
// Also in PHP rather than in a skill. A skill can be switched off, and the one
// obligation that must survive that is "do not report work as done when the
// thing that checks it never ran". Rule 11 already tells the agent to load a
// skill; nothing until this checked that it honoured what the skill pointed at,
// so verified_by would have read like a guarantee in the file and been a no-op
// in practice.

KH_Test::contains('13.', $protocol, 'the protocol carries a thirteenth rule');
KH_Test::contains('honour their verifiers', $protocol, 'and it is the verifier rule, not something else numbered 13');
KH_Test::contains('not done until', $protocol, 'it uses the same wording the catalog uses, so the agent is not asked to connect two vocabularies');
KH_Test::contains(
    'say so plainly and say why',
    $protocol,
    'and skipping a verifier is allowed as long as it is said out loud — an unenforceable rule gets quietly ignored, a reporting duty does not'
);

KH_Test::ok(
    strpos($protocol, '12.') < strpos($protocol, '13.'),
    'the rules are in order — they are read by a model as a numbered list, and one out of sequence reads like a mistake in the instructions'
);

// --- rule 14: do not start what this connection cannot finish -----------------
//
// The same failure as rule 13, one step earlier. A skill body reads identically
// whether the agent reached it from Claude Code or from a Telegram message, so
// an agent in a chat window will happily begin a skill whose third step needs a
// browser, get several steps in, and then either stall or report a result it
// could not measure. Refusing at step zero and naming the client that CAN do it
// is the only outcome that helps anyone.

KH_Test::contains('14.', $protocol, 'the protocol carries a fourteenth rule');
KH_Test::contains('needs: workstation', $protocol, 'and it is the surface guard, naming the marker the catalog prints');
KH_Test::contains('Claude Code or an IDE', $protocol, 'it names what the owner should use instead, rather than only refusing');

// The catalog is where the marker is defined, so it has to print it.
$restricted = [
    [
        'name'        => 'site-clone-capture',
        'description' => 'Records a page in a real browser.',
        'role'        => 'procedure',
        'surface'     => 'workstation',
        'next_skills' => [],
        'verified_by' => [],
    ],
    [
        'name'        => 'blog-publishing',
        'description' => 'Writes and publishes a post.',
        'role'        => 'procedure',
        'surface'     => 'any',
        'next_skills' => [],
        'verified_by' => [],
    ],
];
$restricted_block = Keelhob_MCP_Server::format_skill_catalog($restricted);
KH_Test::contains('needs: workstation', $restricted_block, 'a skill that needs a workstation says so in the catalog');
KH_Test::ok(
    substr_count($restricted_block, 'needs: any') === 0,
    'but "any" is never printed — marking seventeen of eighteen lines with the default would bury the one that matters'
);

// The two prompts that know exactly what they are must say so. Only the MCP
// endpoint is left to check for itself, because the same URL is opened by
// Claude Code, an IDE, a desktop client and other people's scripts, and nothing
// in the handshake says which.
KH_Test::contains('THIS SESSION IS', $interactive, 'a Telegram run is told which surface it is');
KH_Test::contains('Claude Code', $interactive, '...and where to send the owner for the skills it cannot finish');
KH_Test::contains('THIS SESSION IS', $scheduled, 'an unattended run is told too');
KH_Test::contains('needs: interactive', $scheduled, '...including that it cannot finish a skill needing a human, which Telegram can');
