<?php

/**
 * Put a "## Contents" list at the top of every skill long enough to need one,
 * built from that skill's own headings.
 *
 * Generated, never hand-written. A table of contents is the single most
 * rot-prone thing in a document: it is correct on the day it is typed and wrong
 * the first time somebody adds a section. Every hand-kept list in this repo has
 * rotted — readme.txt said "fifteen" skills while twenty-four shipped — so this
 * one is derived and the drift is checked in CI.
 *
 *   php bin/build-skill-contents.php            rewrite every skill that needs one
 *   php bin/build-skill-contents.php --check    exit 1 if any list is stale
 *
 * The list does not count toward a skill's line cap. Keelhob_Skill_Standard::
 * body_lines() exempts it, narrowly: only the run under "## Contents", and only
 * up to CONTENTS_FREE_LINES of it.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// Only ever run from the CLI, where $argv exists; the guard is for the
// static analyser, which cannot know that.
$args = $argv ?? [];
$check = in_array('--check', $args, true);
if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
require_once $root . '/includes/class-skill-standard.php';

const MARK_START = '<!-- CONTENTS:START -->';
const MARK_END   = '<!-- CONTENTS:END -->';

/** Headings a reader would navigate by. H2 only: H3 makes the list longer than the help it gives. */
function kh_headings(string $body): array
{
    $out = [];
    foreach (explode("\n", $body) as $line) {
        if (preg_match('/^##\s+(?!Contents\s*$)(.+?)\s*$/', $line, $m)) {
            $out[] = trim($m[1]);
        }
    }
    return $out;
}

/** GitHub-style anchor, so the links work when the file is read on the web too. */
function kh_anchor(string $text): string
{
    $a = strtolower($text);
    $a = preg_replace('/[^a-z0-9 \-]/', '', $a) ?? '';
    return trim(preg_replace('/\s+/', '-', $a) ?? '', '-');
}

$changed = [];
$stale   = [];

foreach (glob($root . '/skills/*.md') ?: [] as $file) {
    $raw = (string) file_get_contents($file);
    $doc = str_replace(["\r\n", "\r"], "\n", $raw);

    // Split front matter from body; only the body is numbered or listed.
    if (substr($doc, 0, 4) !== "---\n") {
        continue;
    }
    $end = strpos($doc, "\n---\n", 4);
    if ($end === false) {
        continue;
    }
    $front = substr($doc, 0, $end + 5);
    $body  = substr($doc, $end + 5);

    // Strip any previous generated block before measuring, or the list keeps
    // the skill over the threshold and then justifies its own existence.
    $bare = preg_replace('/' . preg_quote(MARK_START, '/') . '.*?' . preg_quote(MARK_END, '/') . "\n*/s", '', $body) ?? $body;

    $needs = Keelhob_Skill_Standard::body_lines($bare) > Keelhob_Skill_Standard::CONTENTS_FROM;
    $heads = kh_headings($bare);

    // Under three headings a list is noise: the reader can already see them all.
    if (!$needs || count($heads) < 3) {
        $next = $front . $bare;
    } else {
        $lines = [MARK_START, '## Contents', ''];
        foreach ($heads as $h) {
            $lines[] = '- [' . $h . '](#' . kh_anchor($h) . ')';
        }
        $lines[] = MARK_END;
        $block   = implode("\n", $lines) . "\n";

        // After the H1 title, before the first section.
        if (preg_match('/^(#\s+.+?\n\n)/s', ltrim($bare, "\n"), $m)) {
            $rest = substr(ltrim($bare, "\n"), strlen($m[1]));
            $next = $front . "\n" . $m[1] . $block . "\n" . $rest;
        } else {
            $next = $front . "\n" . $block . "\n" . ltrim($bare, "\n");
        }
    }

    if ($next === $doc) {
        continue;
    }

    $name = basename($file);
    if ($check) {
        $stale[] = $name;
        continue;
    }
    file_put_contents($file, $next);
    $changed[] = $name;
}

if ($check) {
    if ($stale) {
        fwrite(STDERR, "Contents lists are stale in: " . implode(', ', $stale) . "\n");
        fwrite(STDERR, "Run: php bin/build-skill-contents.php\n");
        exit(1);
    }
    echo "Contents lists are current.\n";
    exit(0);
}

echo $changed
    ? 'Rewrote ' . count($changed) . " contents list(s): " . implode(', ', $changed) . "\n"
    : "Nothing to do — every contents list was already current.\n";
