<?php
/**
 * The old product name must not appear anywhere in this project.
 *
 * WHY THIS IS A TEST AND NOT A CHECKLIST
 *
 * The rename touched 4,597 occurrences across 208 files, ten database tables,
 * about forty saved settings keys, three filenames and the MCP route. No human
 * finishes a job that size and is right about having finished it. This project
 * has already been burned three times by a number or a name that a person was
 * supposed to remember: EXPECTED_SKILLS in bin/build-zip.sh, a CI step that
 * grepped for the literal string "All 3 check files passed.", and six files
 * claiming "~1,500 unit assertions" against a suite of 2,559.
 *
 * So the rename is not done when somebody says it is done. It is done when this
 * test is green, and it stays done every time CI runs.
 *
 * The needle is assembled from two halves on purpose, so that this file does
 * not itself contain the word it bans and therefore does not need an exception.
 * A rule that exempts its own enforcement is not a rule.
 */

KH_Test::group('Rebrand — the old name is gone from the whole project');

$needle = 'claud' . 'press';

/*
 * EVERY ENTRY HERE IS A DELIBERATE EXCEPTION, AND EACH SAYS WHY.
 *
 * Nothing goes in this list to make the test pass. It goes in because the old
 * name genuinely belongs in that file.
 */
$allowed = [
    // The document that explains the rename. It cannot explain it without
    // naming the thing that was renamed. This is the ONE place in the project
    // that still carries the old name, and that is deliberate: the fact is
    // recorded once, on purpose, rather than left scattered through old
    // release notes where it would read as an oversight.
    'docs/why-the-name-changed.md',

    // The adoption step reads the OLD table, option and meta names in order to
    // move the data off them. Take the old names out of this file and every
    // existing install silently loses its snippets, skills, memory, tasks and
    // connection token. It is written so the old name appears on exactly two
    // lines: one constant for the prefix, one for the scheduled hook.
    'includes/class-legacy-adopt.php',
];

/** Text files worth scanning. Binaries and build output are skipped. */
$extensions = ['php', 'md', 'txt', 'js', 'mjs', 'json', 'yml', 'yaml', 'sh', 'neon', 'css', 'html', 'xml', 'dist'];
$skip_dirs  = ['node_modules', 'build', '.git', 'vendor', '.impeccable', '.codegraph'];

$root  = rtrim(str_replace('\\', '/', KEELHOB_DIR), '/') . '/';
$hits  = [];
$files = 0;

$walk = static function (string $dir) use (&$walk, &$hits, &$files, $root, $needle, $allowed, $extensions, $skip_dirs): void {
    foreach ((array) scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        $rel  = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');

        if (is_dir($path)) {
            // continue 2, not return: `return` here abandoned the whole scan
            // the moment it met node_modules, and the test cheerfully reported
            // zero files read and no hits found.
            if (in_array($entry, $skip_dirs, true)) {
                continue;
            }
            $walk($path);
            continue;
        }

        $ext = strtolower((string) pathinfo($entry, PATHINFO_EXTENSION));
        if (!in_array($ext, $extensions, true)) {
            continue;
        }
        if (in_array($rel, $allowed, true)) {
            continue;
        }

        $files++;
        $body = (string) @file_get_contents($path);
        if ($body === '' || stripos($body, $needle) === false) {
            continue;
        }
        // Report the LINE, not just the file. A file name alone sends the next
        // person searching a 900-line file by hand.
        foreach (explode("\n", $body) as $i => $line) {
            if (stripos($line, $needle) !== false) {
                $hits[] = $rel . ':' . ($i + 1);
            }
        }
    }
};

$walk(rtrim($root, '/'));

KH_Test::ok($files > 100, 'the scan actually read the project (' . $files . ' text files)');

// The filename check is separate: a file can be named for the old product and
// contain no mention of it inside.
$named = [];
foreach ([$needle . '.php', 'includes/class-' . $needle . '.php'] as $legacy) {
    if (file_exists($root . $legacy)) {
        $named[] = $legacy;
    }
}
KH_Test::same([], $named, 'no file is still NAMED for the old product');

// The message prints every hit, because "it failed somewhere" is not actionable
// and the whole point of this file is to hand over a to-do list.
KH_Test::same(
    [],
    array_slice($hits, 0, 40),
    'the old name appears nowhere outside the ' . count($allowed) . ' allowed files'
        . ($hits ? ' — ' . count($hits) . ' occurrence(s) left' : '')
);
