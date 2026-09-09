<?php
/**
 * The skills have to form a connected tree, and each one has to parse.
 *
 * Two silent failures this guards, both of which cost the agent quality with
 * no error anywhere:
 *
 * 1. A LEAF — a skill that names no other skill. The agent loads it, follows
 *    it to the end, and stops. blog-publishing was one: an agent could write
 *    and publish a whole post without ever being told that seo-optimization or
 *    web-design-master existed, so the post shipped with no meta, no schema and
 *    a default template. Nothing errors; the work is just worse.
 *
 * 2. AN ORPHAN — a skill no other skill names. Nothing routes to it, so it is
 *    only ever reached if the owner's own wording happens to match its
 *    description. A skill nobody can find is a skill that does not exist.
 *
 * And a parse check, because Claudpress_Skills::parse_markdown() is not a YAML
 * parser: four keys, matched line by line, no nesting and no wrapped values. A
 * description that wraps onto a second line silently becomes empty, and the
 * description is the ONLY thing that matches a task to a skill.
 */

CP_Test::group('Skill tree — every skill parses, and none is a dead end');

$files = glob(CLAUDPRESS_DIR . 'skills/*.md');
sort($files);

$names  = [];
$bodies = [];

foreach ($files as $file) {
    $slug = basename($file, '.md');
    $raw  = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($file));

    CP_Test::ok(
        (bool) preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $raw, $m),
        $slug . ': has front matter the plugin can find'
    );
    if (empty($m)) {
        continue;
    }

    // Parsed exactly as class-skills.php does it.
    $meta = [];
    foreach (explode("\n", $m[1]) as $line) {
        if ($line !== '' && preg_match('/^([a-zA-Z_]+)\s*:\s*(.*)$/', $line, $kv)) {
            $meta[$kv[1]] = trim($kv[2], " '\"");
        }
    }

    CP_Test::same($slug, $meta['name'] ?? '', $slug . ': name matches the filename, so skill_get finds it by the name people see');
    CP_Test::ok(($meta['description'] ?? '') !== '', $slug . ': has a description — without one nothing can route a task to it');
    CP_Test::ok(
        strlen($meta['description'] ?? '') >= 120,
        $slug . ': the description is long enough to say when to load it, not just what it is'
    );
    CP_Test::same('true', strtolower($meta['enable_agentic'] ?? ''), $slug . ': is agentic, so it appears in the session catalog');

    $names[]        = $slug;
    $bodies[$slug]  = $m[2];
}

CP_Test::ok(count($names) >= 15, 'every skill file parsed (' . count($names) . ' of ' . count($files) . ')');

// --- the tree ----------------------------------------------------------------

$out = [];
$in  = array_fill_keys($names, 0);

foreach ($names as $from) {
    $out[$from] = [];
    foreach ($names as $to) {
        if ($from !== $to && strpos($bodies[$from], $to) !== false) {
            $out[$from][] = $to;
            $in[$to]++;
        }
    }
}

foreach ($names as $slug) {
    CP_Test::ok(
        !empty($out[$slug]),
        $slug . ': names at least one other skill — an agent that finishes it knows where to go next'
    );
    CP_Test::ok(
        $in[$slug] > 0,
        $slug . ': is named by at least one other skill — nothing routes to a skill nobody mentions'
    );
}

// build-standard is the router. If it stops naming the others, every new skill
// is reachable only by luck.
CP_Test::ok(
    count($out['build-standard'] ?? []) >= count($names) - 1,
    'build-standard still names every other skill — it is the router table the operating protocol sends the agent to'
);

// The three skills added for SEO, motion and compliance are the ones most
// likely to be forgotten in the router, because nothing breaks when they are.
foreach (['seo-audit-and-fix', 'motion-and-interaction', 'launch-readiness-and-compliance'] as $slug) {
    CP_Test::ok(
        in_array($slug, $out['build-standard'] ?? [], true),
        'the router names ' . $slug
    );
}
