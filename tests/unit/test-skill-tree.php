<?php
/**
 * The eighteen shipped skills, judged by the standard they ship under.
 *
 * This file used to build the tree itself, by looking for one skill's name
 * anywhere inside another skill's body with strpos(). That was wrong in a way
 * worth recording: it passed on a body that said "unlike web-design-master",
 * which links nothing, and nine of the eighteen files had no "Related skills"
 * section at all while every one of them passed. It measured whether a word
 * appeared, and reported it as whether a skill connected.
 *
 * The edges are declared data now — `role`, `requires`, `next_skills` and
 * `verified_by` in the front matter — and Claudpress_Skill_Standard is the only
 * thing that judges them. tests/unit/test-skill-standard.php proves those rules
 * fire on planted failures; this file points them at what actually ships.
 */

CP_Test::group('Skill tree — every shipped skill meets the standard');

$files = glob(CLAUDPRESS_DIR . 'skills/*.md') ?: [];
sort($files);

CP_Test::ok(count($files) >= 15, 'the skills folder is populated (' . count($files) . ' files)');

$skills = [];
foreach ($files as $file) {
    $slug = basename($file, '.md');

    // Parsed by the plugin's own parser, not a friendlier one written for the
    // test. parse_markdown() is not YAML: one key per line, no nesting, no
    // wrapped values. A description that wraps silently loses everything after
    // the first line, and the description is the only thing that routes a task
    // to a skill — so a test that parsed it more generously would pass a file
    // the site cannot read.
    $parsed = Claudpress_Skills::parse_markdown((string) file_get_contents($file));

    CP_Test::same($slug, $parsed['name'] ?? '', $slug . ': the front matter parses and its name matches the filename');
    if (($parsed['name'] ?? '') !== $slug) {
        continue;
    }
    $skills[$slug] = $parsed;
}

$known = array_keys($skills);

foreach ($skills as $slug => $skill) {
    $findings = Claudpress_Skill_Standard::check($skill, $known);
    $errors   = array_values(array_filter($findings, static fn($f) => $f['level'] === 'error'));

    CP_Test::ok(
        empty($errors),
        $slug . ': meets the standard' . (empty($errors) ? '' : ' — ' . $errors[0]['message'])
    );
}

$graph = Claudpress_Skill_Standard::check_graph($skills);
$fatal = array_values(array_filter($graph, static fn($f) => $f['level'] === 'error'));

CP_Test::ok(
    empty($fatal),
    'the tree as a whole holds: no loops, no orphans, nothing over the context budget, nothing routed to the wrong skill'
        . (empty($fatal) ? '' : ' — ' . $fatal[0]['skill'] . ': ' . $fatal[0]['message'])
);

// ---- the properties worth naming, rather than only scoring -----------------

// build-standard is the router the operating protocol sends the agent to. If a
// skill cannot be reached from it, that skill is found only by luck.
//
// REACHABLE, not NAMED. This used to require build-standard to name every skill
// directly, which quietly forbade a family: site-clone is one entry in the
// router and the four skills behind it (capture, design-system, rebuild,
// verify) are reached through it, which is the design rather than an accident.
// Forcing all five into the top-level list would make the router twenty-three
// entries instead of nineteen real starting points, and a router nobody can
// skim routes nothing.
//
// The property that actually matters is that a walk from build-standard finds
// everything. That is strictly stronger than the old check on a flat set, and
// it still fails the moment a skill is genuinely stranded.
$reachable = [];
$queue     = ['build-standard'];
while ($queue) {
    $slug = array_shift($queue);
    if (isset($reachable[$slug]) || !isset($skills[$slug])) {
        continue;
    }
    $reachable[$slug] = true;
    foreach (Claudpress_Skill_Standard::outbound($skills[$slug]) as $next) {
        $queue[] = $next;
    }
}
foreach ($known as $slug) {
    if ($slug === 'build-standard') {
        continue;
    }
    CP_Test::ok(isset($reachable[$slug]), 'the router reaches ' . $slug);
}

// A gate that nothing is verified by is a gate nobody has to pass.
$verifiers = [];
foreach ($skills as $skill) {
    $verifiers = array_merge($verifiers, Claudpress_Skill_Standard::edges($skill)['verified_by']);
}
$verifiers = array_unique($verifiers);
CP_Test::ok(in_array('pre-launch-audit', $verifiers, true), 'pre-launch-audit is declared as a verifier, so build work is not done on the builder\'s own say-so');

// A gate can stand at either end of the work, and both are real gating.
//
// pre-launch-audit gates the EXIT: things declare it as their verifier, so the
// work is not done until it passes. site-clone gates the ENTRANCE: nothing is
// verified by it, but site-clone-capture REQUIRES it, so no measuring can start
// until the permission question has been answered. Demanding that every gate be
// a verifier would have forced site-clone to be mislabelled a procedure, which
// would then have been a lie about what it does.
//
// What is not allowed is a gate with neither: nothing gated on it before, and
// nothing gated on it after. That is a gate in name only.
$required = [];
foreach ($skills as $skill) {
    $required = array_merge($required, Claudpress_Skill_Standard::edges($skill)['requires']);
}
$required = array_unique($required);

foreach ($skills as $slug => $skill) {
    if (Claudpress_Skill_Standard::role($skill) !== 'gate') {
        continue;
    }
    // build-standard is the one exception, and it is exempt for a stated
    // reason rather than to make the test pass: it is the entry point, named
    // by operating-protocol rule 11 from PHP, so nothing declares it as either
    // a verifier or a prerequisite.
    CP_Test::ok(
        in_array($slug, $verifiers, true) || in_array($slug, $required, true) || $slug === 'build-standard',
        $slug . ' is a gate and something is actually gated on it'
    );
}

// The whole set, priced. Every connected session pays this before any work
// happens, so it is worth an assertion rather than a discovery later.
$catalog = Claudpress_Skill_Standard::catalog_cost($skills);
CP_Test::ok(
    $catalog <= Claudpress_Skill_Standard::CATALOG_BUDGET,
    sprintf('the session-start catalog costs %d KB, within the %d KB budget', (int) round($catalog / 1024), (int) round(Claudpress_Skill_Standard::CATALOG_BUDGET / 1024))
);
