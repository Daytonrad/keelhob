<?php
/**
 * Step 2 of tests/rig/upgrade-check.sh — fill the OLD version with the kind of
 * data a real site accumulates.
 *
 * EVERYTHING GOES IN THROUGH THE OLD VERSION'S OWN save() PATH, never by
 * INSERTing rows by hand. A hand-written fixture encodes what you BELIEVE the
 * old shape was; running the old code encodes what it actually was. The whole
 * value of this test is that the fixture is not a guess — and a guess is
 * exactly what would hide a migration bug, because it would be written from the
 * same misunderstanding that caused it.
 *
 * The snippets below are chosen so that each one can fail differently:
 *
 *   flat-conditions   the 1.1.0 condition shape {logged_in, front_page,
 *                     post_types, post_ids}, which 1.2.0 must LIFT into the new
 *                     {enabled, relation, rules[]} form at read time without
 *                     rewriting the row.
 *   no-conditions     the plain case, which must not acquire conditions.
 *   revisions         edited three times, so there is history to lose.
 *   switched-off      must still be off afterwards. An upgrade that switches
 *                     somebody's code ON is the worst outcome available here.
 *   live-and-running  active, and asserted to still EXECUTE after the upgrade —
 *                     surviving as a database row is not the same as working.
 */

if (!defined('ABSPATH')) {
    exit;
}

wp_set_current_user(1);

$made = [];

// --- 1. the old flat condition shape ---------------------------------------
$flat = Claudpress_Snippets::save([
    'name'         => 'UP flat conditions',
    'description'  => 'Written by the old version with the pre-1.2.0 condition shape.',
    'code'         => "add_filter('the_content', function (\$c) { return \$c . '<!-- up-flat -->'; });",
    'snippet_type' => 'php',
    'location'     => 'everywhere',
    'conditions'   => [
        'logged_in'  => false,
        'front_page' => true,
        'post_types' => ['post', 'page'],
        'post_ids'   => [1, 2],
    ],
    'active'       => 1,
]);
$made['flat'] = (int) ($flat['id'] ?? 0);

// --- 2. no conditions at all -----------------------------------------------
$plain = Claudpress_Snippets::save([
    'name'         => 'UP no conditions',
    'code'         => '.up-plain { color: rebeccapurple; }',
    'snippet_type' => 'css',
    'location'     => 'header',
    'active'       => 1,
]);
$made['plain'] = (int) ($plain['id'] ?? 0);

// --- 3. something with a history -------------------------------------------
$rev = Claudpress_Snippets::save([
    'name'         => 'UP with revisions',
    'code'         => "// version one\n",
    'snippet_type' => 'php',
    'location'     => 'everywhere',
    'active'       => 0,
]);
$rev_id = (int) ($rev['id'] ?? 0);
foreach (['// version two', '// version three', '// version four'] as $body) {
    Claudpress_Snippets::save(['id' => $rev_id, 'code' => $body . "\n"]);
}
$made['revisions'] = $rev_id;

// --- 4. deliberately switched off ------------------------------------------
$off = Claudpress_Snippets::save([
    'name'         => 'UP switched off on purpose',
    'code'         => "add_action('init', function () { /* up-off */ });",
    'snippet_type' => 'php',
    'location'     => 'everywhere',
    'active'       => 0,
]);
$made['off'] = (int) ($off['id'] ?? 0);

// --- 5. live, and asserted to still RUN afterwards --------------------------
// A footer marker is used rather than the_content because it does not depend on
// a post existing, so the check works on any rig.
$live = Claudpress_Snippets::save([
    'name'         => 'UP live marker',
    'code'         => '<!-- up-live-marker -->',
    'snippet_type' => 'html',
    'location'     => 'footer',
    'active'       => 1,
]);
$made['live'] = (int) ($live['id'] ?? 0);

// --- other subsystems a real site carries ----------------------------------
// Memory and Skills are INSTANCE APIs in both versions, not static.
$memory = new Claudpress_Memory();
$memory->save('up-memory-key', 'a decision recorded before the upgrade');

$skills = new Claudpress_Skills();
$skills->save([
    'name'        => 'up-custom-skill',
    'description' => 'A skill the owner wrote themselves, which an upgrade must not overwrite.',
    'body'        => "## When to use\nNever. This is a fixture.\n",
]);

Claudpress::instance()->update([
    'agent_provider'    => 'anthropic',
    'agent_model'       => 'mock-model-newest',
    'abilities_enabled' => true,
]);

$store = [
    'ids'      => $made,
    'old_ver'  => defined('CLAUDPRESS_VERSION') ? CLAUDPRESS_VERSION : '?',
];
update_option('cp_upgrade_ids', $store, false);

printf(
    "      seeded %d snippet(s), 1 memory, 1 custom skill, settings; running %s\n",
    count($made),
    $store['old_ver']
);

foreach ($made as $label => $id) {
    if ($id <= 0) {
        printf("      FAILED: snippet '%s' did not save\n", $label);
        exit(1);
    }
}
