<?php
/**
 * The numbers the docs say out loud must be the numbers the code actually has.
 *
 * Found by reading, not by any test: README.md claimed "eighteen" skills when
 * twenty-four shipped, ROADMAP.md claimed "62 tools" when there were 68, and
 * six files claimed "~1,500 unit assertions" against a suite of 2,559. None of
 * those were ever wrong on the day they were written. They rotted, quietly,
 * because a person had to remember to change them.
 *
 * This project has already been bitten twice by exactly that: EXPECTED_SKILLS
 * in bin/build-zip.sh, and a CI step that grepped for the literal string
 * "All 3 check files passed." A number a human keeps by hand is not a fact, it
 * is a promise to remember, and nobody remembers.
 *
 * So the two counts a stranger would check first are checked here instead.
 *
 * ASSERTION COUNTS ARE DELIBERATELY NOT CHECKED. A test cannot know the size of
 * the suite it is running inside without lying about itself, and the docs say
 * "~2,500", which is an approximation and is meant to read as one. Skills and
 * tools are stated as exact figures, so they get treated as exact.
 */

KH_Test::group('Doc counts — the numbers in the docs match the code');

// ---------------------------------------------------------------- the truth

$real_skills = count(glob(KEELHOB_DIR . 'skills/*.md') ?: []);
KH_Test::ok($real_skills > 0, 'there are skill files to count (' . $real_skills . ')');

// The WooCommerce groups refuse to register without WooCommerce, so the honest
// total is the one a site with WooCommerce sees. Defined defensively: another
// test file may or may not have run first, and a count that changes with file
// ordering is worse than no count.
if (!class_exists('WooCommerce')) {
    class WooCommerce {}
}
foreach (glob(KEELHOB_DIR . 'includes/tools/class-tools-*.php') ?: [] as $file) {
    require_once $file;
}
$registry = new Keelhob_Tool_Registry();
foreach ([
    'Core', 'Filesystem', 'Content', 'Site', 'WooCommerce', 'SEO', 'Memory',
    'Skills', 'Snippets', 'Elementor', 'Ops', 'Automation', 'Payments',
    'Deploy', 'Readiness',
] as $group) {
    call_user_func(['Keelhob_Tools_' . $group, 'register'], $registry);
}
$real_tools = count($registry->list());
KH_Test::ok($real_tools > 0, 'the tool registry populated (' . $real_tools . ')');

// ------------------------------------------------------------- what we claim

/** Words, because prose spells small numbers out and "eighteen" is what rotted. */
$WORDS = [
    'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15,
    'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19,
    'twenty' => 20, 'twenty-one' => 21, 'twenty-two' => 22, 'twenty-three' => 23,
    'twenty-four' => 24, 'twenty-five' => 25, 'twenty-six' => 26,
    'twenty-seven' => 27, 'twenty-eight' => 28, 'twenty-nine' => 29, 'thirty' => 30,
];

$readme  = (string) file_get_contents(KEELHOB_DIR . 'README.md');
$roadmap = (string) file_get_contents(KEELHOB_DIR . 'ROADMAP.md');

// README: "...description; twenty-four ship with the plugin..."
$claimed_skills = null;
if (preg_match('/([a-z-]+|\d+) ship with the plugin/i', $readme, $m)) {
    $raw            = strtolower($m[1]);
    $claimed_skills = ctype_digit($raw) ? (int) $raw : ($WORDS[$raw] ?? null);
}
KH_Test::ok($claimed_skills !== null, 'README states how many skills ship');
KH_Test::same($real_skills, $claimed_skills, 'and that number is the number of files in skills/');

// ROADMAP: "Working today: 68 tools across content..."
$claimed_tools = null;
if (preg_match('/(\d+) tools across/i', $roadmap, $m)) {
    $claimed_tools = (int) $m[1];
}
KH_Test::ok($claimed_tools !== null, 'ROADMAP states how many tools work today');
KH_Test::same($real_tools, $claimed_tools, 'and that number is the number the registry returns');

// ROADMAP repeats the skills count in the same sentence.
$claimed_roadmap_skills = null;
if (preg_match('/(\d+) expert skills/i', $roadmap, $m)) {
    $claimed_roadmap_skills = (int) $m[1];
}
KH_Test::ok($claimed_roadmap_skills !== null, 'ROADMAP states how many skills ship');
KH_Test::same($real_skills, $claimed_roadmap_skills, 'and it agrees with README and with the folder');

// ------------------------------------------------------- the release claim

// "Shipped (1.1.0, 1.2.0)" said 1.2.0 was out while it was untagged. The words
// are checked rather than the tag, because the tag lives on GitHub and this
// suite runs with no network.
KH_Test::ok(
    strpos($roadmap, 'awaiting release') !== false || strpos($roadmap, '## Shipped (1.1.0, 1.2.0)') === false,
    'ROADMAP does not describe an unreleased version as simply "Shipped"'
);
