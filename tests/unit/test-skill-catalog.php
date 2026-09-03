<?php
/**
 * The skill catalogue.
 *
 * The drift test matters more than it looks. The catalogue is hand-maintained
 * and the skills directory is not, so without this a skill added to skills/
 * would quietly never appear in the catalogue at all.
 */

CP_Test::group('Skill catalogue - it matches what is on disk');

$catalog = Claudpress_Skill_Catalog::all();
$on_disk = [];
foreach (glob(CLAUDPRESS_DIR . 'skills/*.md') as $file) {
    $on_disk[] = basename($file, '.md');
}
sort($on_disk);
$listed = array_keys($catalog);
sort($listed);

CP_Test::same($on_disk, $listed, 'every skill file is catalogued and nothing is catalogued that does not exist');

foreach ($catalog as $name => $meta) {
    CP_Test::ok(($meta['title'] ?? '') !== '', $name . ' has a title');
    CP_Test::ok(strlen($meta['description'] ?? '') > 20, $name . ' has a real description, not a placeholder');
}

CP_Test::group('Skill catalogue - every skill ships to everyone');

// These fifteen used to be split nine/six across a free and a paid zip. There
// is one zip now, and the assertion worth keeping is that every skill named in
// the catalogue is actually present and describable - a catalogue entry with no
// file behind it is how a skill goes missing without anyone noticing.
$names = Claudpress_Skill_Catalog::names();
CP_Test::same(15, count($names), 'all fifteen skills are catalogued');

foreach ($names as $name) {
    CP_Test::ok(is_file(CLAUDPRESS_DIR . 'skills/' . $name . '.md'), $name . ' has a file behind its catalogue entry');
}

// Safety is never withheld from anyone, and it never was.
foreach (['site-safety', 'security-standard'] as $name) {
    CP_Test::ok(in_array($name, $names, true), $name . ' ships');
}

// The ten-minute build has to work end to end out of the box.
foreach (['site-build-kickoff', 'website-building', 'elementor-page-building', 'seo-optimization', 'blog-publishing', 'build-standard', 'code-snippets'] as $name) {
    CP_Test::ok(in_array($name, $names, true), $name . ' ships: the build journey must run on a fresh install');
}

// And the six that used to be behind the paywall.
foreach (['web-design-master', 'pre-launch-audit', 'store-launch-and-deploy', 'woocommerce-store-operations', 'skill-creator', 'solace-header-footer'] as $name) {
    CP_Test::ok(in_array($name, $names, true), $name . ' ships - it used to be paid');
}

// The catalogue carries metadata, never bodies. That was true when bodies were
// the paid part and it stays true now, because a 15-skill catalogue that grew
// bodies would be loaded into memory on every single request.
$src = file_get_contents(CLAUDPRESS_DIR . 'includes/class-skill-catalog.php');
CP_Test::ok(strlen($src) < 20000, 'the catalogue is metadata-sized, not body-sized');

CP_Test::group('Skill catalogue — every skill ships, and the count is asserted');

// The build used to split these across two zips by tier. It copies the whole
// folder now, which makes the old "did every skill land somewhere" check
// tautologically true - and a check that cannot fail is worse than none,
// because it reads like cover. So the script asserts an expected count
// instead, and this asserts the script still does that.
$build = file_get_contents(CLAUDPRESS_DIR . 'bin/build-zip.sh');

CP_Test::contains('EXPECTED_SKILLS', $build, 'the build asserts how many skills it expects to stage');
CP_Test::ok(strpos($build, 'PRO_STAGE') === false, 'there is no second zip to stage skills into');
CP_Test::ok(strpos($build, 'Claudpress_Skill_Catalog::names') === false, 'the build no longer asks the catalogue which tier a skill is');

// The number in the script and the number on disk must agree, or the build
// fails for everyone the moment somebody adds a skill without updating it.
preg_match('/EXPECTED_SKILLS=(\d+)/', $build, $m);
$on_disk = count(glob(CLAUDPRESS_DIR . 'skills/*.md'));
CP_Test::same($on_disk, (int) ($m[1] ?? 0), 'EXPECTED_SKILLS in the build script matches what is actually in skills/');
