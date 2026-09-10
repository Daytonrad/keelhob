<?php
/**
 * Check every skill in skills/ against the standard.
 *
 *   php bin/lint-skills.php            check them all
 *   php bin/lint-skills.php seo        only files whose name matches
 *   composer lint:skills               the same thing
 *
 * This is the gate a skill passes to ship, and it is also the rubric for
 * reviewing a pull request: run it, read the score, read the findings. Before
 * it existed, "is this skill any good" had no answer that two people would
 * agree on, and merging one meant hand-editing four separate counters.
 *
 * The rules live in includes/class-skill-standard.php, not here. This file only
 * finds the files, parses them the way the plugin does, and prints the result —
 * so the identical judgement runs in CI, inside a live site through the
 * skill_lint tool, and on a contributor's laptop.
 *
 * Exit code is 0 when nothing is an error, 1 otherwise, so CI can gate on it.
 * Warnings never fail the build. They are things worth a look, not things that
 * make a skill unfit to ship.
 */

$root = dirname(__DIR__);

// Both classes guard on ABSPATH, which is right for a plugin file and wrong for
// a CLI script. Defining it here is the same trick tests/bootstrap.php uses:
// neither class touches WordPress, they only refuse to be loaded outside it.
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
require_once $root . '/includes/class-skill-standard.php';
require_once $root . '/includes/class-skills.php';

$filter = $argv[1] ?? '';
$files  = glob($root . '/skills/*.md') ?: [];
sort($files);

if (empty($files)) {
    fwrite(STDERR, "No skills found in skills/.\n");
    exit(1);
}

// ---- parse ------------------------------------------------------------------

$skills = [];
$broken = [];

foreach ($files as $file) {
    $slug = basename($file, '.md');
    $raw  = (string) file_get_contents($file);

    // Parsed by the plugin's own parser, deliberately. A linter with its own
    // more forgiving parser would pass a file the site cannot read.
    $parsed = Claudpress_Skills::parse_markdown($raw);

    if (!isset($parsed['name'])) {
        $broken[$slug] = 'No front matter the plugin can find. It must open with --- on line 1.';
        continue;
    }
    if ($parsed['name'] !== $slug) {
        $broken[$slug] = sprintf('Front matter says name: %s but the file is %s.md. skill_get addresses it by the name, so they must match.', $parsed['name'], $slug);
        continue;
    }

    $skills[$slug] = $parsed;
}

// ---- judge ------------------------------------------------------------------

$known = array_keys($skills);

/** @var array<string, array<int, array{level:string, code:string, skill:string, message:string}>> $findings */
$findings = array_fill_keys($known, []);

foreach ($skills as $slug => $skill) {
    $findings[$slug] = Claudpress_Skill_Standard::check($skill, $known);
}

// Graph rules run over every skill regardless of the filter — a cycle or a
// clash is a property of the set, and checking half a graph proves nothing.
foreach (Claudpress_Skill_Standard::check_graph($skills) as $finding) {
    $slug = $finding['skill'];
    if (isset($findings[$slug])) {
        $findings[$slug][] = $finding;
    }
}

// ---- report -----------------------------------------------------------------

$errors  = 0;
$warns   = 0;
$shown   = 0;
$failed  = [];

foreach ($broken as $slug => $why) {
    printf("FAIL  %-38s  unreadable\n", $slug);
    printf("        error  %s\n", $why);
    $errors++;
    $failed[] = $slug;
}

foreach ($skills as $slug => $skill) {
    if ($filter !== '' && stripos($slug, $filter) === false) {
        continue;
    }
    $shown++;

    $mine  = $findings[$slug];
    $score = Claudpress_Skill_Standard::score($mine);
    $bad   = Claudpress_Skill_Standard::has_errors($mine);
    $role  = Claudpress_Skill_Standard::role($skill);

    // The surface is appended only when it restricts something. Printing
    // "any" on seventeen of eighteen lines would bury the one line where a
    // reviewer needs to stop and think about it.
    $surface = Claudpress_Skill_Standard::surface($skill);
    if ($surface !== Claudpress_Skill_Standard::SURFACE_DEFAULT) {
        $role .= ' · needs ' . $surface;
    }

    printf("%s  %-38s  %3d/100  [%s]\n", $bad ? 'FAIL' : 'pass', $slug, $score, $role);

    foreach ($mine as $finding) {
        printf("        %-5s  %s\n", $finding['level'], $finding['message']);
        if ($finding['level'] === 'error') {
            $errors++;
        } else {
            $warns++;
        }
    }

    if ($bad) {
        $failed[] = $slug;
    }
}

$all = [];
foreach ($findings as $mine) {
    $all = array_merge($all, $mine);
}

echo "\n";
printf(
    "%d skill%s checked · %d error%s · %d warning%s · overall %d/100\n",
    $shown,
    $shown === 1 ? '' : 's',
    $errors,
    $errors === 1 ? '' : 's',
    $warns,
    $warns === 1 ? '' : 's',
    Claudpress_Skill_Standard::score($all)
);

if (!empty($failed)) {
    echo "Not fit to ship: " . implode(', ', array_unique($failed)) . "\n";
    echo "The rules, and why each exists: docs/SKILL-STANDARD.md\n";
    exit(1);
}

echo "Every skill meets the standard.\n";
exit(0);
