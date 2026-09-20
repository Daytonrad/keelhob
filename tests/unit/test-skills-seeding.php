<?php
/**
 * Bundled-skill refresh rules.
 *
 * These decide whether a plugin update is allowed to replace a playbook already
 * on somebody's site. Get it wrong in one direction and shipped improvements
 * never arrive; get it wrong in the other and you silently delete work the owner
 * did. Neither failure announces itself, so the rules are pinned here.
 */

KH_Test::group('Skills — deciding whether an update may replace a bundled skill');

$shipped = md5('NEW BODY');

/** @param string $body @param string $hash */
$row = static function (string $body, string $hash): array {
    return ['body' => $body, 'bundled_hash' => $hash, 'enabled' => 1];
};

KH_Test::same(
    'install',
    Keelhob_Skills::bundled_action(null, $shipped),
    'a skill the site has never seen is installed'
);

// Untouched = the stored body still hashes to what Keelhob last wrote.
KH_Test::same(
    'update',
    Keelhob_Skills::bundled_action($row('OLD BODY', md5('OLD BODY')), $shipped),
    'an untouched bundled skill is refreshed to the newer shipped text'
);
KH_Test::same(
    'current',
    Keelhob_Skills::bundled_action($row('NEW BODY', $shipped), $shipped),
    'a skill already matching the shipped text is left alone'
);

// Edited = the stored body no longer matches the hash Keelhob recorded.
KH_Test::same(
    'keep',
    Keelhob_Skills::bundled_action($row('OWNER REWROTE THIS', md5('OLD BODY')), $shipped),
    'a skill the owner edited is never overwritten by an update'
);

// Pre-1.9 rows carry no hash at all, so an edit cannot be ruled out.
KH_Test::same(
    'keep',
    Keelhob_Skills::bundled_action($row('OLD BODY', ''), $shipped),
    'a skill with no recorded hash is kept rather than guessed at'
);

// force is the owner explicitly asking for the shipped version back.
KH_Test::same(
    'update',
    Keelhob_Skills::bundled_action($row('OWNER REWROTE THIS', md5('OLD BODY')), $shipped, true),
    'force replaces an edited skill'
);
KH_Test::same(
    'update',
    Keelhob_Skills::bundled_action($row('OLD BODY', ''), $shipped, true),
    'force replaces an untracked skill'
);
KH_Test::same(
    'install',
    Keelhob_Skills::bundled_action(null, $shipped, true),
    'force still installs a missing skill rather than skipping it'
);

KH_Test::group('Skills — every bundled skill file is loadable');

// A skill with broken frontmatter seeds under the wrong name, or not at all, and
// the failure only shows up as the agent quietly not having the playbook.
$files = glob(KEELHOB_DIR . 'skills/*.md');
KH_Test::ok(count($files) > 8, 'the bundled skills are present (' . count($files) . ' files)');

$bad = [];
foreach ($files as $file) {
    $parsed = Keelhob_Skills::parse_markdown((string) file_get_contents($file));
    $name   = trim((string) ($parsed['name'] ?? ''));
    $desc   = trim((string) ($parsed['description'] ?? ''));
    $body   = trim((string) ($parsed['body'] ?? ''));
    if ($name === '' || $desc === '' || $body === '') {
        $bad[] = basename($file);
        continue;
    }
    // The description is the only thing the agent sees before loading the skill,
    // so a vague one-liner means the skill never triggers.
    if (strlen($desc) < 60) {
        $bad[] = basename($file) . ' (description too short to trigger on)';
    }
}
KH_Test::same([], $bad, 'every bundled skill has a name, a usable description, and a body');

/**
 * Every shipped skill file must actually survive parse_markdown().
 *
 * seed_bundled_skills() silently `continue`s past a file whose frontmatter it
 * cannot read, so a skill with a broken header does not fail loudly — it just
 * never reaches the site. That is exactly the delivery hole this release set
 * out to close, so the shipped files get checked here rather than discovered
 * missing on somebody's install.
 */
KH_Test::group('Skills — every shipped skill file parses and is complete');

$skill_files = glob(dirname(__DIR__, 2) . '/skills/*.md');
KH_Test::ok(count($skill_files) >= 15, 'the plugin ships a full skill pack');

$seen_names = [];
foreach ($skill_files as $file) {
    $slug   = basename($file, '.md');
    $parsed = Keelhob_Skills::parse_markdown((string) file_get_contents($file));

    KH_Test::ok(!empty($parsed['name']), $slug . ' declares a name in its frontmatter');
    KH_Test::ok(!empty($parsed['description']), $slug . ' declares a description agents can route on');
    KH_Test::ok(trim((string) ($parsed['body'] ?? '')) !== '', $slug . ' has a body left after the frontmatter is stripped');
    KH_Test::same($slug, (string) ($parsed['name'] ?? ''), $slug . ' has a frontmatter name matching its filename');

    $seen_names[] = (string) ($parsed['name'] ?? '');
}

KH_Test::same(count($seen_names), count(array_unique($seen_names)), 'no two shipped skills claim the same name');

// The contract in the operating protocol names these two by name. If either
// stops shipping, every agent is pointed at a skill that does not exist.
KH_Test::ok(in_array('build-standard', $seen_names, true), 'build-standard ships, because the operating protocol tells every agent to load it');
KH_Test::ok(in_array('security-standard', $seen_names, true), 'security-standard ships, because build-standard routes security work to it');

KH_Test::group('Seeding — a front-matter-only change still reaches existing sites');

// bundled_hash covers the BODY only, so a skill whose declaration changed but
// whose prose did not used to be classed "current" and skipped entirely. That
// is exactly the shape of the change that added `surface: interactive` to
// site-build-kickoff — no word of its body moved — and it would have shipped
// to nobody who already had the plugin.

$body = "# Alpha\n\nSome unchanged prose.\n";
$row  = [
    'name'           => 'alpha',
    'title'          => 'Alpha',
    'description'    => 'The original description.',
    'body'           => $body,
    'role'           => 'procedure',
    'surface'        => 'any',
    'requires'       => '',
    'next_skills'    => 'other',
    'verified_by'    => '',
    'enabled'        => 1,
    'enable_agentic' => 1,
    'enable_prompt'  => 1,
    'bundled_hash'   => md5($body),
];

// Same everything: nothing to do, and the row must not be rewritten on every
// page load just because the strings were assembled differently.
$same = array_merge($row, ['next_skills' => 'other']);
KH_Test::ok(!Keelhob_Skills::metadata_drifted($row, $same), 'an identical declaration is not drift');
KH_Test::ok(
    !Keelhob_Skills::metadata_drifted($row, array_merge($row, ['next_skills' => ' other ,  other '])),
    'nor is the same edge list written untidily — it is normalised before comparing'
);

// The real case, and the four others that share its shape.
KH_Test::ok(Keelhob_Skills::metadata_drifted($row, array_merge($row, ['surface' => 'interactive'])), 'a changed surface IS drift — this is the bug that was found');
KH_Test::ok(Keelhob_Skills::metadata_drifted($row, array_merge($row, ['role' => 'gate'])), 'a changed role is drift');
KH_Test::ok(Keelhob_Skills::metadata_drifted($row, array_merge($row, ['description' => 'A better description.'])), 'a rewritten description is drift — it is the only thing that routes work to a skill');
KH_Test::ok(Keelhob_Skills::metadata_drifted($row, array_merge($row, ['verified_by' => 'pre-launch-audit'])), 'a newly declared verifier is drift');
KH_Test::ok(Keelhob_Skills::metadata_drifted($row, array_merge($row, ['title' => 'Alpha renamed'])), 'a renamed title is drift');

// The owner's switches are theirs. A skill they turned off stays off.
KH_Test::ok(!Keelhob_Skills::metadata_drifted($row, array_merge($row, ['enabled' => 0])), 'the owner switching a skill off is NOT drift to be undone');
KH_Test::ok(!Keelhob_Skills::metadata_drifted($row, array_merge($row, ['enable_agentic' => 0])), 'nor is switching it out of the agent catalog');
