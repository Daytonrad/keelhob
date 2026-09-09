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

// build-standard is the router the operating protocol sends the agent to. If it
// stops naming a skill, that skill is reachable only by luck.
$router = Claudpress_Skill_Standard::outbound($skills['build-standard'] ?? []);
foreach ($known as $slug) {
    if ($slug === 'build-standard') {
        continue;
    }
    CP_Test::ok(in_array($slug, $router, true), 'the router names ' . $slug);
}

// A gate that nothing is verified by is a gate nobody has to pass.
$verifiers = [];
foreach ($skills as $skill) {
    $verifiers = array_merge($verifiers, Claudpress_Skill_Standard::edges($skill)['verified_by']);
}
$verifiers = array_unique($verifiers);
CP_Test::ok(in_array('pre-launch-audit', $verifiers, true), 'pre-launch-audit is declared as a verifier, so build work is not done on the builder\'s own say-so');

foreach ($skills as $slug => $skill) {
    if (Claudpress_Skill_Standard::role($skill) !== 'gate') {
        continue;
    }
    // build-standard is the one exception, and it is exempt for a stated
    // reason rather than to make the test pass: it is the entry point, named
    // by operating-protocol rule 11 from PHP, so nothing declares it as a
    // verifier. Every other gate must have something actually gated on it, or
    // it is a gate in name only.
    CP_Test::ok(
        in_array($slug, $verifiers, true) || $slug === 'build-standard',
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
