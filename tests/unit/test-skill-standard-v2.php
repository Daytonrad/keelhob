<?php

/**
 * The house-style rules: contents lists, and checklists where work gets checked.
 *
 * Two things are being guarded here, and the second one is the reason this file
 * is longer than it looks like it needs to be.
 *
 * First, the rules themselves. A checklist is required only from a skill that
 * is a gate, or that some other skill names as its verifier. Not from every
 * skill: Anthropic's own guidance is that the newer models are hurt by
 * over-prescription, so demanding a checklist from web-design-master would obey
 * one rule by breaking two others.
 *
 * Second, that the patterns can actually match anything. The failure-branch
 * rule shipped for an hour with literal 0x08 BACKSPACE bytes where its \b word
 * boundaries should have been, reported every skill as missing a branch the
 * text plainly contained, and looked completely correct in grep, in awk and in
 * the editor. The same bug was then found sitting in guess_type() in shipping
 * code. A pattern that cannot match is invisible, so it gets asserted on.
 */

declare(strict_types=1);

KH_Test::group('Skill standard — a checklist is asked for where work gets CHECKED');

$gate = ['name' => 'a-gate', 'role' => 'gate'];
$plain = ['name' => 'a-doc', 'role' => 'procedure'];
$all = [
    ['name' => 'someone', 'verified_by' => 'the-verifier'],
    ['name' => 'other', 'verified_by' => ''],
];

KH_Test::ok(
    Keelhob_Skill_Standard::needs_checklist('a-gate', 'gate', $all),
    'a gate needs one — being a checkpoint is its whole job'
);
KH_Test::ok(
    Keelhob_Skill_Standard::needs_checklist('the-verifier', 'reference', $all),
    'so does a skill that another skill names as its verifier, whatever its role'
);
KH_Test::ok(
    !Keelhob_Skill_Standard::needs_checklist('a-doc', 'procedure', $all),
    'an ordinary procedure does not'
);
KH_Test::ok(
    !Keelhob_Skill_Standard::needs_checklist('a-doc', 'reference', $all),
    'and neither does a reference — that is the over-prescription the rule avoids'
);

// The earlier cut of this took the linter's array of NAMES and read
// ['verified_by'] off a string. PHP returned null without an error and
// security-standard, the most safety-critical skill we ship, walked straight
// through the rule.
KH_Test::ok(
    !Keelhob_Skill_Standard::needs_checklist('the-verifier', 'reference', ['someone', 'other']),
    'a list of names instead of rows must not silently pass everything'
);

KH_Test::group('Skill standard — the failure-branch pattern can actually match');

// This is the assertion that would have caught the backspace bug in seconds.
foreach ([
    'If any box cannot be ticked, go back to the step that produces it.' => true,
    'If a fix does not move the score, go back to Step 2.'               => true,
    'If it fails, roll back and report.'                                 => true,
    'If it fails, start again from the top.'                             => true,
    'Tick each box as you do it. Report when finished.'                  => false,
    'Work through the list in order and report the result.'              => false,
] as $text => $want) {
    KH_Test::same(
        $want,
        Keelhob_Skill_Standard::has_failure_branch($text),
        ($want ? 'accepts: ' : 'rejects: ') . substr($text, 0, 46)
    );
}

KH_Test::group('Skill standard — no pattern in the source holds a backspace byte');

// 0x08 renders as nothing in grep, awk and most editors, so a broken pattern
// looks perfect. Ten of them were in class-skill-standard.php and four more in
// class-studio-ai.php, where they silently broke code-type detection. Cheap to
// assert, impossible to spot by reading.
$offenders = [];
foreach ((array) glob(KEELHOB_DIR . 'includes/*.php') as $file) {
    if (strpos((string) file_get_contents($file), "\x08") !== false) {
        $offenders[] = basename($file);
    }
}
foreach ((array) glob(KEELHOB_DIR . 'includes/tools/*.php') as $file) {
    if (strpos((string) file_get_contents($file), "\x08") !== false) {
        $offenders[] = basename($file);
    }
}
KH_Test::same([], $offenders, 'no source file contains a literal backspace character');

KH_Test::group('Skill standard — guess_type classifies bare PHP');

// What the backspace bug actually cost: a bare add_action() with no variable
// and no arrow fell past every working marker. The type decides WHERE a snippet
// may run, so a misread type is a snippet stored somewhere it never executes.
foreach ([
    'add_action("init", "my_setup");'      => 'php',
    // deliberately absent: "function hello() {}". It is genuinely
    // ambiguous - JavaScript has the same line - and the PHP-only marker
    // must not claim it, or an IIFE gets stored as PHP.
    'foreach ($x as $y) {}'                => 'php',
    'My_Class::go();'                      => 'php',
    '$x = 1;'                              => 'php',
    '.btn { color: red; }'                 => 'css',
] as $code => $want) {
    KH_Test::same($want, Keelhob_Studio_AI::guess_type($code), 'guess_type: ' . substr($code, 0, 34));
}

KH_Test::group('Skill standard — a Contents list does not count toward the size cap');

$plain_body = "# T\n" . str_repeat("line\n", 40);
$with_toc   = "# T\n\n## Contents\n\n" . str_repeat("- [x](#x)\n", 10) . "\n## Real\n" . str_repeat("line\n", 40);

KH_Test::same(
    Keelhob_Skill_Standard::body_lines($plain_body),
    Keelhob_Skill_Standard::body_lines($with_toc) - 3,
    'ten list lines cost nothing; only the headings and blanks around them count'
);

// "Free lines" is an invitation, so the exemption is capped. Park a wall of
// real content under the heading and everything past the limit is charged.
$abuse = "# T\n\n## Contents\n\n" . str_repeat("sneaky content\n", 200) . "\n## Real\nx\n";
KH_Test::ok(
    Keelhob_Skill_Standard::body_lines($abuse) > 150,
    'a body hidden under the Contents heading is still counted past the free limit'
);

KH_Test::group('Skill standard — house style stays out of a user own skill');

// The product decision: our 24 are held to the full standard; a skill somebody
// wrote for their own site is checked for real faults only. Somebody saving a
// forty-line note about their brand voice should not be told it needs a
// checklist. $all empty means "not the repo linter".
$long = ['name' => 'my-notes', 'role' => 'gate', 'description' => str_repeat('a note about how we write. ', 8), 'body' => "# Notes\n" . str_repeat("line\n", 200)];

$codes = array_column(Keelhob_Skill_Standard::check($long, ['my-notes']), 'code');
KH_Test::ok(!in_array('checklist-missing', $codes, true), 'a user skill is not told it needs a checklist');
KH_Test::ok(!in_array('contents-missing', $codes, true), 'nor that it needs a contents list');

$codes_lint = array_column(Keelhob_Skill_Standard::check($long, ['my-notes'], [$long]), 'code');
KH_Test::ok(in_array('checklist-missing', $codes_lint, true), 'but the repo linter, which passes the full set, does say so');

KH_Test::group('Skill standard — every shipped skill meets it');

$skills = [];
foreach ((array) glob(KEELHOB_DIR . 'skills/*.md') as $file) {
    $parsed = Keelhob_Skills::parse_markdown((string) file_get_contents($file));
    if (isset($parsed['name'])) {
        $skills[$parsed['name']] = $parsed;
    }
}
KH_Test::ok(count($skills) >= 20, 'the skills parsed (' . count($skills) . ')');

$bad = [];
foreach ($skills as $name => $skill) {
    foreach (Keelhob_Skill_Standard::check($skill, array_keys($skills), $skills) as $f) {
        if (($f['level'] ?? '') === 'error') {
            $bad[] = $name . ': ' . $f['code'];
        }
    }
}
KH_Test::same([], $bad, 'no shipped skill has a standard error');
