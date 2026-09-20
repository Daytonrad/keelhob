<?php
/**
 * The skill catalogue.
 *
 * The drift test matters more than it looks. The catalogue is hand-maintained
 * and the skills directory is not, so without this a skill added to skills/
 * would quietly never appear in the catalogue at all.
 */

KH_Test::group('Skill catalogue - it matches what is on disk');

$catalog = Keelhob_Skill_Catalog::all();
$on_disk = [];
foreach (glob(KEELHOB_DIR . 'skills/*.md') as $file) {
    $on_disk[] = basename($file, '.md');
}
sort($on_disk);
$listed = array_keys($catalog);
sort($listed);

KH_Test::same($on_disk, $listed, 'every skill file is catalogued and nothing is catalogued that does not exist');

foreach ($catalog as $name => $meta) {
    KH_Test::ok(($meta['title'] ?? '') !== '', $name . ' has a title');
    KH_Test::ok(strlen($meta['description'] ?? '') > 20, $name . ' has a real description, not a placeholder');
}

KH_Test::group('Skill catalogue - every skill ships to everyone');

// These once split nine/six across a free and a paid zip. There is one zip
// now, and the assertion worth keeping is that every skill named in the
// catalogue is actually present and describable - a catalogue entry with no
// file behind it is how a skill goes missing without anyone noticing, and a
// file with no entry is a skill the admin screen cannot name.
$names = Keelhob_Skill_Catalog::names();

// Derived from the folder rather than typed here. The catalogue is generated
// from skills/*.md by bin/build-skill-router.php, so a hard-coded number would
// only ever record how many skills existed on the day someone last remembered
// to update it — and updating it was one of the four chores that made accepting
// a contributed skill a job in itself. What is worth asserting is that the two
// sides agree, in both directions, which the loops below do.
$files = glob(KEELHOB_DIR . 'skills/*.md') ?: [];
KH_Test::same(count($files), count($names), 'every skill file has a catalogue entry, and nothing extra is catalogued');
KH_Test::ok(count($names) >= 15, 'and the catalogue is not empty or truncated (' . count($names) . ' skills)');

foreach ($names as $name) {
    KH_Test::ok(is_file(KEELHOB_DIR . 'skills/' . $name . '.md'), $name . ' has a file behind its catalogue entry');
}

// Safety is never withheld from anyone, and it never was.
foreach (['site-safety', 'security-standard'] as $name) {
    KH_Test::ok(in_array($name, $names, true), $name . ' ships');
}

// The ten-minute build has to work end to end out of the box.
foreach (['site-build-kickoff', 'website-building', 'elementor-page-building', 'seo-optimization', 'blog-publishing', 'build-standard', 'code-snippets'] as $name) {
    KH_Test::ok(in_array($name, $names, true), $name . ' ships: the build journey must run on a fresh install');
}

// And the six that used to be behind the paywall.
foreach (['web-design-master', 'pre-launch-audit', 'store-launch-and-deploy', 'woocommerce-store-operations', 'skill-creator', 'solace-header-footer'] as $name) {
    KH_Test::ok(in_array($name, $names, true), $name . ' ships - it used to be paid');
}

// The catalogue carries metadata, never bodies. That was true when bodies were
// the paid part and it stays true now, because a 15-skill catalogue that grew
// bodies would be loaded into memory on every single request.
$src = file_get_contents(KEELHOB_DIR . 'includes/class-skill-catalog.php');
KH_Test::ok(strlen($src) < 20000, 'the catalogue is metadata-sized, not body-sized');

KH_Test::group('Skill catalogue — every skill ships, and nothing is kept by hand');

// The build used to split these across two zips by tier. It copies the whole
// folder now, which makes the old "did every skill land somewhere" check
// tautologically true - and a check that cannot fail is worse than none,
// because it reads like cover. It carried a hand-typed EXPECTED_SKILLS instead,
// which did make somebody notice they had added a skill.
//
// That noticing has a better home now. bin/lint-skills.php has to pass and
// bin/build-skill-router.php --check has to be current before a skill can ship
// at all, and both of those say something about the skill rather than merely
// counting it. So the constant is gone, and what the build compares is the
// staged folder against the source folder - which still catches a copy that
// silently dropped a file, and no longer asks a contributor to update a number
// in a shell script they have no other reason to open.
$build = file_get_contents(KEELHOB_DIR . 'bin/build-zip.sh');

KH_Test::ok(strpos($build, 'EXPECTED_SKILLS') === false, 'the hand-typed skill count is gone from the build script');
KH_Test::contains('source_skills', $build, 'the build compares what it staged against what is in skills/');
KH_Test::ok(strpos($build, 'PRO_STAGE') === false, 'there is no second zip to stage skills into');
KH_Test::ok(strpos($build, 'Keelhob_Skill_Catalog::names') === false, 'the build no longer asks the catalogue which tier a skill is');

// The catalogue is generated, so the assertion that matters is that the
// generator has been run. A stale catalogue is a skill the admin screen cannot
// name, which is exactly the drift the generator exists to end.
$gen = trim((string) shell_exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(KEELHOB_DIR . 'bin/build-skill-router.php') . ' --check 2>&1'
));
KH_Test::contains('current', $gen, 'the generated catalogue and skill-tree table are up to date (' . $gen . ')');
