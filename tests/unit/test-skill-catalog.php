<?php
/**
 * The skill catalogue - and the boundary that makes the Pro split honest.
 *
 * Descriptions ship in the free plugin so the Skills screen can show someone
 * exactly what Pro would give them. Bodies do not. That line is the whole
 * design: descriptions are marketing, bodies are the product.
 *
 * The drift test matters more than it looks. The catalogue is hand-maintained
 * and the skills directory is not, so without this a skill added to skills/
 * would quietly never appear in the catalogue, never be tiered, and - once the
 * build script reads the catalogue - silently ship to the wrong zip.
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
    CP_Test::ok(in_array($meta['tier'] ?? '', ['free', 'pro'], true), $name . ' declares a valid tier');
}

CP_Test::group('Skill catalogue - the split');

$free = Claudpress_Skill_Catalog::names('free');
$pro  = Claudpress_Skill_Catalog::names('pro');

CP_Test::same(9, count($free), 'nine skills are free - the whole build journey');
CP_Test::same(6, count($pro), 'six skills are Pro - the run-and-grow half');

// Safety is never a paid feature, and charging someone to find out whether
// their site is hacked is both bad ethics and bad PR.
CP_Test::ok(in_array('site-safety', $free, true), 'site-safety is free on principle');
CP_Test::ok(in_array('security-standard', $free, true), 'security-standard is free on principle');

// The ten-minute demo has to work end to end with no licence.
foreach (['site-build-kickoff', 'website-building', 'elementor-page-building', 'seo-optimization', 'blog-publishing', 'build-standard'] as $name) {
    CP_Test::ok(in_array($name, $free, true), $name . ' is free: the build demo must run without a licence');
}

// The snippet TOOLS are free, so the skill that explains them must be too.
CP_Test::ok(in_array('code-snippets', $free, true), 'code-snippets is free because snippet_save is');

foreach (['web-design-master', 'pre-launch-audit', 'store-launch-and-deploy', 'woocommerce-store-operations', 'skill-creator', 'solace-header-footer'] as $name) {
    CP_Test::ok(in_array($name, $pro, true), $name . ' is Pro');
}

CP_Test::group('Skill catalogue - names and descriptions only');

cp_test_reset_options();
cp_test_reset_filters();

$missing = Claudpress_Skill_Catalog::unavailable();
CP_Test::same(6, count($missing), 'a free site is told about all six Pro skills');

foreach ($missing as $entry) {
    CP_Test::ok(isset($entry['name'], $entry['title'], $entry['description']), $entry['name'] . ' is described');
    CP_Test::ok(!isset($entry['body']), $entry['name'] . ' exposes no body - that is the paid part');
}

// And the catalogue file itself must never carry a body: it ships in the free
// zip, so anything in it is public.
$src = file_get_contents(CLAUDPRESS_DIR . 'includes/class-skill-catalog.php');
CP_Test::ok(strlen($src) < 20000, 'the catalogue is metadata-sized, not body-sized');

add_filter('claudpress_is_pro', static function () { return true; });
CP_Test::same([], Claudpress_Skill_Catalog::unavailable(), 'a Pro site is told nothing is missing');
cp_test_reset_filters();

CP_Test::group('Skill catalogue — the build splits skills by tier');

// Before this was wired, every one of the 15 skills shipped in the free zip,
// including all six Pro ones. The tier split existed in the catalogue and
// nowhere that mattered, which is the most expensive kind of half-done: it
// looks finished from the inside.
$build = file_get_contents(CLAUDPRESS_DIR . 'bin/build-zip.sh');

CP_Test::contains('Claudpress_Skill_Catalog::names', $build, 'the build reads the catalogue rather than keeping a second list that would drift');
CP_Test::contains('FREE_STAGE/skills', $build, 'free skills are staged for the free zip');
CP_Test::contains('PRO_STAGE/skills', $build, 'Pro skills are staged for the Pro zip');
CP_Test::ok(strpos($build, 'cp -r "$ROOT/skills"       "$FREE_STAGE/"') === false, 'the old copy-everything line is gone');
CP_Test::contains('is in neither zip', $build, 'a skill nobody tiered fails the build instead of vanishing from both zips');
