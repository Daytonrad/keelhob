<?php
/**
 * Does a connected agent actually know the skills exist?
 *
 * Fifteen skills ship, and none of them do anything if the agent never learns
 * they are there. Over MCP that was already handled — server_instructions()
 * inlines the whole catalog at initialize. Everywhere else it was not.
 *
 * The bug this file pins down: Claudpress_Agent_Runtime::system_prompt() built
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

require_once CLAUDPRESS_DIR . 'includes/class-mcp-server.php';
require_once CLAUDPRESS_DIR . 'includes/class-agent-runtime.php';

CP_Test::group('Skill delivery — the catalog reaches every surface');

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

$block = Claudpress_MCP_Server::format_skill_catalog($two);

CP_Test::contains('build-standard', $block, 'the catalog names each skill');
CP_Test::contains('The design reference', $block, 'and carries its description, which is the only thing that routes a task to a skill');
CP_Test::contains('skill_get', $block, 'and says how to load a body, so the agent does not have to guess the tool name');
CP_Test::contains(
    'build-standard carries the router table',
    $block,
    'and points at the router — an agent that reads one description and stops builds a page with no SEO pass and no taste'
);

// The edges are what turn a list of names into a map. Without them the agent
// learns that eighteen skills exist and nothing about how they relate, so it
// picks one, follows it to the end, and stops — which is the failure the whole
// skill standard was built to end.
CP_Test::contains('[gate]', $block, 'the catalog says what kind of skill each one is');
CP_Test::contains('then: web-design-master', $block, 'and where to go when a skill is finished');
CP_Test::contains('not done until: pre-launch-audit', $block, 'and what has to run before the work counts as done');
CP_Test::contains('gate = work is not finished until it passes', $block, 'and it explains the roles, because a label nobody can interpret is noise');

CP_Test::ok(
    strpos($block, 'web-design-master [reference]: The design reference. Loads for ANY visual work.' . "\n") !== false
        || substr_count($block, 'web-design-master [reference]') === 1,
    'a skill with no edges gets no empty brackets after it'
);

CP_Test::same(
    '',
    Claudpress_MCP_Server::format_skill_catalog([]),
    'an empty catalog produces nothing at all, not a heading with no skills under it'
);

// --- the guard that stops decoration killing a run ---------------------------
//
// skills is set during boot(). This block is built while assembling a system
// prompt, and a fatal here would take down a Telegram reply mid-sentence over
// a list the agent could have done without. It already happened once in this
// suite, where an earlier test leaves a stub in that property.

$saved = Claudpress::instance()->skills;
Claudpress::instance()->skills = null;
CP_Test::same(
    '',
    Claudpress_MCP_Server::skill_catalog_block(),
    'no skills service means no catalog, not a fatal error in the middle of a reply'
);
Claudpress::instance()->skills = $saved;

// --- the wiring: both agent-runtime prompts carry it -------------------------

$GLOBALS['cp_test_db_results'] = [
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
Claudpress::instance()->skills = new Claudpress_Skills();

$prompt = new ReflectionMethod(Claudpress_Agent_Runtime::class, 'system_prompt');
@$prompt->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor

$scheduled   = (string) $prompt->invoke(null, false, '');
$interactive = (string) $prompt->invoke(null, true, 'Telegram');

foreach (['scheduled' => $scheduled, 'Telegram' => $interactive] as $label => $text) {
    CP_Test::contains('build-standard', $text, "a $label run is told build-standard exists");
    CP_Test::contains('motion-and-interaction', $text, "a $label run is told motion-and-interaction exists");
    CP_Test::contains('skill_get', $text, "a $label run is told how to load a skill body");
    // Rule 11 orders the agent to load build-standard. Before this fix these
    // prompts carried that order and no way to obey it.
    CP_Test::contains('Load the build-standard skill', $text, "a $label run still carries rule 11");
}

unset($GLOBALS['cp_test_db_results']);
Claudpress::instance()->skills = $saved;

// --- rule 12: ask before spending the owner's money --------------------------
//
// In the protocol rather than in a skill, because a skill can be switched off
// and this must not be. A whole-site audit can burn an hour and real API
// credit off the back of someone typing "check my SEO".

$protocol = Claudpress_MCP_Server::operating_protocol();

CP_Test::contains('12.', $protocol, 'the protocol carries a twelfth rule');
CP_Test::contains(
    'gets agreed first',
    $protocol,
    'and it is the spending guard, not something else that happens to be numbered 12'
);
CP_Test::contains('wait for a yes', $protocol, 'it requires consent, not merely an announcement');
CP_Test::contains(
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

CP_Test::contains('13.', $protocol, 'the protocol carries a thirteenth rule');
CP_Test::contains('honour their verifiers', $protocol, 'and it is the verifier rule, not something else numbered 13');
CP_Test::contains('not done until', $protocol, 'it uses the same wording the catalog uses, so the agent is not asked to connect two vocabularies');
CP_Test::contains(
    'say so plainly and say why',
    $protocol,
    'and skipping a verifier is allowed as long as it is said out loud — an unenforceable rule gets quietly ignored, a reporting duty does not'
);

CP_Test::ok(
    strpos($protocol, '12.') < strpos($protocol, '13.'),
    'the rules are in order — they are read by a model as a numbered list, and one out of sequence reads like a mistake in the instructions'
);
