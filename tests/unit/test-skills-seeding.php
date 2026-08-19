<?php
/**
 * Bundled-skill refresh rules.
 *
 * These decide whether a plugin update is allowed to replace a playbook already
 * on somebody's site. Get it wrong in one direction and shipped improvements
 * never arrive; get it wrong in the other and you silently delete work the owner
 * did. Neither failure announces itself, so the rules are pinned here.
 */

CP_Test::group('Skills — deciding whether an update may replace a bundled skill');

$shipped = md5('NEW BODY');

/** @param string $body @param string $hash */
$row = static function (string $body, string $hash): array {
    return ['body' => $body, 'bundled_hash' => $hash, 'enabled' => 1];
};

CP_Test::same(
    'install',
    Claudpress_Skills::bundled_action(null, $shipped),
    'a skill the site has never seen is installed'
);

// Untouched = the stored body still hashes to what Claudpress last wrote.
CP_Test::same(
    'update',
    Claudpress_Skills::bundled_action($row('OLD BODY', md5('OLD BODY')), $shipped),
    'an untouched bundled skill is refreshed to the newer shipped text'
);
CP_Test::same(
    'current',
    Claudpress_Skills::bundled_action($row('NEW BODY', $shipped), $shipped),
    'a skill already matching the shipped text is left alone'
);

// Edited = the stored body no longer matches the hash Claudpress recorded.
CP_Test::same(
    'keep',
    Claudpress_Skills::bundled_action($row('OWNER REWROTE THIS', md5('OLD BODY')), $shipped),
    'a skill the owner edited is never overwritten by an update'
);

// Pre-1.9 rows carry no hash at all, so an edit cannot be ruled out.
CP_Test::same(
    'keep',
    Claudpress_Skills::bundled_action($row('OLD BODY', ''), $shipped),
    'a skill with no recorded hash is kept rather than guessed at'
);

// force is the owner explicitly asking for the shipped version back.
CP_Test::same(
    'update',
    Claudpress_Skills::bundled_action($row('OWNER REWROTE THIS', md5('OLD BODY')), $shipped, true),
    'force replaces an edited skill'
);
CP_Test::same(
    'update',
    Claudpress_Skills::bundled_action($row('OLD BODY', ''), $shipped, true),
    'force replaces an untracked skill'
);
CP_Test::same(
    'install',
    Claudpress_Skills::bundled_action(null, $shipped, true),
    'force still installs a missing skill rather than skipping it'
);

CP_Test::group('Skills — every bundled skill file is loadable');

// A skill with broken frontmatter seeds under the wrong name, or not at all, and
// the failure only shows up as the agent quietly not having the playbook.
$files = glob(CLAUDPRESS_DIR . 'skills/*.md');
CP_Test::ok(count($files) > 8, 'the bundled skills are present (' . count($files) . ' files)');

$bad = [];
foreach ($files as $file) {
    $parsed = Claudpress_Skills::parse_markdown((string) file_get_contents($file));
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
CP_Test::same([], $bad, 'every bundled skill has a name, a usable description, and a body');

/**
 * Every shipped skill file must actually survive parse_markdown().
 *
 * seed_bundled_skills() silently `continue`s past a file whose frontmatter it
 * cannot read, so a skill with a broken header does not fail loudly — it just
 * never reaches the site. That is exactly the delivery hole this release set
 * out to close, so the shipped files get checked here rather than discovered
 * missing on somebody's install.
 */
CP_Test::group('Skills — every shipped skill file parses and is complete');

$skill_files = glob(dirname(__DIR__, 2) . '/skills/*.md');
CP_Test::ok(count($skill_files) >= 15, 'the plugin ships a full skill pack');

$seen_names = [];
foreach ($skill_files as $file) {
    $slug   = basename($file, '.md');
    $parsed = Claudpress_Skills::parse_markdown((string) file_get_contents($file));

    CP_Test::ok(!empty($parsed['name']), $slug . ' declares a name in its frontmatter');
    CP_Test::ok(!empty($parsed['description']), $slug . ' declares a description agents can route on');
    CP_Test::ok(trim((string) ($parsed['body'] ?? '')) !== '', $slug . ' has a body left after the frontmatter is stripped');
    CP_Test::same($slug, (string) ($parsed['name'] ?? ''), $slug . ' has a frontmatter name matching its filename');

    $seen_names[] = (string) ($parsed['name'] ?? '');
}

CP_Test::same(count($seen_names), count(array_unique($seen_names)), 'no two shipped skills claim the same name');

// The contract in the operating protocol names these two by name. If either
// stops shipping, every agent is pointed at a skill that does not exist.
CP_Test::ok(in_array('build-standard', $seen_names, true), 'build-standard ships, because the operating protocol tells every agent to load it');
CP_Test::ok(in_array('security-standard', $seen_names, true), 'security-standard ships, because build-standard routes security work to it');
