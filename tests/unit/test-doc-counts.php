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
// The group list is DISCOVERED, not written out here. It used to be a hand-kept
// array of seventeen names, and that is exactly how the number rotted again:
// two groups shipped yesterday — Abilities and Feedback — were added to the
// code and not to this list, so the test went on cheerfully agreeing that there
// were 81 tools while the registry held 85. A hand-kept list of the things to
// count has the same failure mode as a hand-kept count, which is the whole
// point of this file. Every Keelhob_Tools_* class that exists is now counted,
// so a new group cannot be forgotten here.
$registry = new Keelhob_Tool_Registry();
$groups   = [];
foreach (get_declared_classes() as $class) {
    if (strpos($class, 'Keelhob_Tools_') === 0 && method_exists($class, 'register')) {
        $groups[] = $class;
    }
}
sort($groups);
KH_Test::ok(
    count($groups) >= 19,
    'every tool group class was discovered, not listed by hand (' . count($groups) . ' groups)'
);
foreach ($groups as $group) {
    call_user_func([$group, 'register'], $registry);
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

// --------------------------------------------- assertion counts stay approximate

// The docstring above explains why the SIZE of the suite is not asserted: a test
// cannot count itself honestly. What CAN be checked, without any self-reference,
// is that the docs state it as an approximation rather than an exact figure.
//
// That distinction is the whole defence. ROADMAP.md once read "2,559 unit
// assertions" while every other file said "~2,500"; the exact number was true
// for about a week and then quietly became a lie, and nothing caught it because
// nothing was looking. An approximation survives a few hundred new assertions.
// An exact number is a promise to remember, and nobody remembers.
foreach ([
    'ROADMAP.md',
    'README.md',
    'CONTRIBUTING.md',
    'docs/architecture.md',
    'readme.txt',
] as $doc) {
    $text = (string) file_get_contents(KEELHOB_DIR . $doc);
    // An exact four-digit count of assertions, i.e. one NOT preceded by "~".
    $exact = (bool) preg_match('/(?<!~)\b\d{1,2},\d{3}\s+(?:unit\s+)?assertions/i', $text);
    KH_Test::ok(
        !$exact,
        $doc . ' states its assertion count as an approximation (~2,600), never an exact figure'
    );
}

// ------------------------------------------------- DOCUMENTATION.md, the manual

// DOCUMENTATION.md is the page a stranger reads INSTEAD of this repository, so
// a number that rots there is worse than one that rots in ROADMAP.md: the reader
// has nothing to check it against.
$manual = (string) file_get_contents(KEELHOB_DIR . 'DOCUMENTATION.md');

$manual_tools = null;
if (preg_match('/\*\*(\d+) tools\*\*/i', $manual, $m)) {
    $manual_tools = (int) $m[1];
}
KH_Test::ok($manual_tools !== null, 'DOCUMENTATION.md states how many tools there are');
KH_Test::same($real_tools, $manual_tools, 'and it is the number the registry returns');

$manual_skills = null;
if (preg_match('/([a-z-]+|\d+) ship with the plugin/i', $manual, $m)) {
    $raw           = strtolower($m[1]);
    $manual_skills = ctype_digit($raw) ? (int) $raw : ($WORDS[$raw] ?? null);
}
KH_Test::ok($manual_skills !== null, 'DOCUMENTATION.md states how many skills ship');
KH_Test::same($real_skills, $manual_skills, 'and it agrees with the skills/ folder');

// Every capability group the plugin defines must appear in the manual's table,
// and be marked with the default the code actually ships. A group added to the
// code and forgotten here is a power the owner was never told about.
$off = Keelhob_Capabilities::DISABLED_BY_DEFAULT;
$missing = [];
$wrong   = [];
foreach (Keelhob_Capabilities::groups() as $key => $group) {
    $label = $group['label'];
    // Matched VERBATIM. The manual is supposed to print the label the owner
    // reads on the Settings screen, so "close enough" is the wrong test: a
    // shortened label in the manual is itself the bug.
    $row = null;
    if (preg_match('/^\|\s*' . preg_quote($label, '/') . '\s*\|\s*([^|]+)\|/mi', $manual, $m)) {
        $row = trim($m[1]);
    }
    if ($row === null) {
        $missing[] = $label;
        continue;
    }
    $says_off    = stripos($row, 'off') !== false;
    $actually_off = in_array($key, $off, true);
    if ($says_off !== $actually_off) {
        $wrong[] = $label . ' (manual says ' . ($says_off ? 'off' : 'on') . ')';
    }
}
KH_Test::ok($missing === [], 'DOCUMENTATION.md lists every capability group' . ($missing ? ': missing ' . implode(', ', $missing) : ''));
KH_Test::ok($wrong === [], 'and gives each one the default the code ships' . ($wrong ? ': ' . implode('; ', $wrong) : ''));

// ----------------------------------------- browser and end-to-end check counts

// These two CANNOT be verified from here: both suites need a running rig and a
// browser, and counting their call sites statically is wrong because the checks
// run in loops. What CAN be kept honest without either is that the total
// ROADMAP.md advertises equals the per-file figures in tests/rig/ui/README.md,
// which is the table a maintainer updates when they add a check.
//
// ROADMAP.md once said "57 browser checks" against a real, measured 80. Nobody
// noticed for months, because the only way to find out was to run them.
$ui_readme = (string) file_get_contents(KEELHOB_DIR . 'tests/rig/ui/README.md');
preg_match_all('/^\|\s*`check-[a-z]+\.mjs`\s*\|[^|]*\|\s*(\d+)\s*\|/m', $ui_readme, $rows);
$per_file = array_map('intval', $rows[1]);

KH_Test::ok(count($per_file) >= 2, 'tests/rig/ui/README.md lists a check count per file (' . implode(' + ', $per_file) . ')');

$claimed_browser = null;
if (preg_match('/(\d+) browser checks/i', $roadmap, $m)) {
    $claimed_browser = (int) $m[1];
}
KH_Test::ok($claimed_browser !== null, 'ROADMAP states how many browser checks there are');
KH_Test::same(array_sum($per_file), $claimed_browser, 'and it equals the per-file totals in tests/rig/ui/README.md');

// ------------------------------------------------------- the release claim

// "Shipped (1.1.0, 1.2.0)" said 1.2.0 was out while it was untagged. The words
// are checked rather than the tag, because the tag lives on GitHub and this
// suite runs with no network.
KH_Test::ok(
    strpos($roadmap, 'awaiting release') !== false || strpos($roadmap, '## Shipped (1.1.0, 1.2.0)') === false,
    'ROADMAP does not describe an unreleased version as simply "Shipped"'
);

// ------------------------------------------------- readme.txt, the plugin page
//
// readme.txt is what a person reads in their own admin after installing, and
// nothing pointed at it: it claimed "fifteen bundled expert playbooks" while
// twenty-four shipped. Nine skills appeared over months and the sentence never
// moved, because no test had ever read this file. It is the least-visited
// document in the repo and the most-read one outside it.
$readme_txt = (string) file_get_contents(KEELHOB_DIR . 'readme.txt');

$txt_skills = null;
if (preg_match('/Skills engine with ([a-z-]+|\d+) bundled/i', $readme_txt, $m)) {
    $raw        = strtolower($m[1]);
    $txt_skills = ctype_digit($raw) ? (int) $raw : ($WORDS[$raw] ?? null);
}
KH_Test::ok($txt_skills !== null, 'readme.txt states how many skills are bundled');
KH_Test::same($real_skills, $txt_skills, 'and it agrees with the skills/ folder');

// The manual is the document a stranger is sent to. If the front door does not
// name it, it does not exist as far as that stranger is concerned — which is
// exactly what happened: 460 lines written, linked from nowhere.
foreach (['README.md', 'readme.txt'] as $door) {
    KH_Test::ok(
        strpos((string) file_get_contents(KEELHOB_DIR . $door), 'keelhob.com/docs') !== false,
        $door . ' points a reader at the manual'
    );
}
