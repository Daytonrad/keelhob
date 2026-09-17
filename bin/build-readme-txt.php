<?php
/**
 * Regenerate the sections of readme.txt that also live in README.md.
 *
 *   php bin/build-readme-txt.php           write readme.txt
 *   php bin/build-readme-txt.php --check   exit 1 if it is out of date, write nothing
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 *
 * Three sections say the same thing in both files, for the same reason: what
 * this plugin can do to a site, what eval() is doing in the source, and what it
 * cannot do yet. They are the sections a careful person reads before trusting
 * the plugin, and two copies of a safety statement is two chances to be wrong.
 *
 * They had already drifted. README.md grew an eleventh limitation ("not on
 * WordPress.org, and it will not be") that readme.txt never got. Nothing caught
 * it, because nothing was looking.
 *
 * So README.md is the source and readme.txt is derived, and tests/unit/
 * test-integrity.php runs this with --check so a stale readme.txt fails CI
 * rather than shipping.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DELIBERATELY DOES *NOT* GENERATE
 *
 * The changelog. It looks like the same duplication and it is not: CHANGELOG.md
 * is written for developers — it names files, says what broke and why — while
 * readme.txt's is written for the person clicking update. CHANGELOG.md says so
 * in its own header. Generating one from the other would flatten a deliberate
 * decision into a tooling convenience.
 *
 * The Description, Features, External services and Installation sections, for
 * the same reason: they exist only in readme.txt, aimed at a WordPress user,
 * and README.md sends a developer to docs/features.md instead.
 *
 * The version. tests/unit/test-integrity.php already asserts the plugin header,
 * KEELHOB_VERSION and the Stable tag agree, and that a changelog entry
 * exists for the current version. That check has already caught a real miss.
 * Two mechanisms guarding one fact is one too many.
 */

/** The sections README.md owns. Order is readme.txt's, not README.md's. */
const SHARED = [
    'How risky this is, honestly',
    'About eval()',
    'What it cannot do yet',
];

/** Repo-relative links have to become absolute once they leave the repo. */
const BLOB = 'https://github.com/calflint/keelhob/blob/main/';

const WRAP = 100;

/**
 * Throws rather than exits, deliberately.
 *
 * This file is included by tests/unit/test-integrity.php. An exit() in here
 * would end the whole suite mid-run with a zero-ish success look, which is the
 * worst possible failure mode for a test. The CLI block at the bottom catches
 * it and turns it back into an exit code.
 */
function fail(string $msg): void
{
    throw new RuntimeException($msg);
}

/**
 * Pull one `## Heading` section out of a Markdown document.
 *
 * Returns the body only, without the heading, trimmed. The heading is matched
 * literally rather than by slug: these three names are load-bearing and a
 * silent rename should break the build, not quietly generate an empty section.
 */
function markdown_section(string $doc, string $heading): string
{
    $lines = preg_split('/\R/', $doc);
    $out = [];
    $inside = false;
    foreach ($lines as $line) {
        if (preg_match('/^##\s+(.*?)\s*$/', $line, $m)) {
            $name = trim(preg_replace('/`/', '', $m[1]));
            if ($name === $heading) {
                $inside = true;
                continue;
            }
            if ($inside) {
                break;
            }
        }
        if ($inside) {
            $out[] = $line;
        }
    }
    if (!$inside) {
        fail("README.md has no '## {$heading}' section. If it was renamed, rename it in SHARED too.");
    }
    return trim(implode("\n", $out));
}

/** Wrap a paragraph to WRAP columns, indenting continuation lines. */
function wrap_block(string $text, string $indent = ''): string
{
    $text = preg_replace('/\s+/', ' ', trim($text));
    $wrapped = wordwrap($text, WRAP - strlen($indent), "\n", false);
    $lines = explode("\n", $wrapped);
    foreach ($lines as $i => $line) {
        $lines[$i] = ($i === 0 ? '' : $indent) . $line;
    }
    return implode("\n", $lines);
}

/**
 * Markdown inline syntax to what readme.txt renders.
 *
 * Bold survives — readme.txt already uses it and the WordPress readme parser
 * understands it. Backticks do not: they mean nothing in this format and read
 * as stray punctuation to someone opening the file in the zip.
 */
function inline(string $s): string
{
    // [text](url) — relative targets become absolute, since readme.txt is read
    // outside the repository more often than in it.
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', static function (array $m): string {
        $text = $m[1];
        $url = $m[2];
        if (!preg_match('#^[a-z]+://#i', $url) && strpos($url, '#') !== 0) {
            $url = BLOB . ltrim($url, './');
        }
        // A link whose text is already the URL would otherwise print it twice.
        return $text === $url ? $url : $text . ' (' . $url . ')';
    }, $s);

    $s = str_replace('`', '', $s);
    // Single-asterisk emphasis, without touching the ** of bold or a bullet.
    $s = preg_replace('/(?<!\*)\*(?!\*)([^*]+)(?<!\*)\*(?!\*)/', '$1', $s);

    return $s;
}

/**
 * Markdown block syntax to readme.txt block syntax.
 *
 * Two-column tables become bullets. That is the one non-obvious conversion, and
 * it is here because README.md uses a table for the eight switched-off
 * capability groups and a plain-text file has no way to render one.
 */
function convert(string $md): string
{
    $lines = preg_split('/\R/', $md);
    $out = [];
    $para = [];

    $flush = static function () use (&$para, &$out): void {
        if ($para !== []) {
            $out[] = wrap_block(implode(' ', $para));
            $para = [];
        }
    };

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];

        // Table row. The separator row and the header row are both dropped: the
        // header labels a column, and a bullet has no columns to label.
        if (preg_match('/^\|(.+)\|\s*$/', $line, $m)) {
            $flush();
            $cells = array_map('trim', explode('|', trim($m[1], '|')));
            if (preg_match('/^:?-{3,}:?$/', $cells[0] ?? '')) {
                continue; // separator
            }
            $next = $lines[$i + 1] ?? '';
            if (preg_match('/^\|\s*:?-{3,}/', $next)) {
                continue; // header, identified by the separator beneath it
            }
            $cells = array_map(static fn(string $c): string => inline($c), $cells);
            $out[] = wrap_block('* ' . implode(' — ', array_filter($cells, 'strlen')), '  ');
            continue;
        }

        if (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
            $flush();
            // Gather the bullet's continuation lines before wrapping, or the
            // wrap happens per source line and the result is ragged.
            $text = $m[1];
            while (preg_match('/^\s{2,}\S/', $lines[$i + 1] ?? '')) {
                $text .= ' ' . trim($lines[++$i]);
            }
            $out[] = wrap_block('* ' . inline($text), '  ');
            continue;
        }

        if (trim($line) === '') {
            $flush();
            if (end($out) !== '') {
                $out[] = '';
            }
            continue;
        }

        $para[] = inline(trim($line));
    }
    $flush();

    return trim(implode("\n", $out));
}

/**
 * What readme.txt currently says, and what it should say.
 *
 * Returned as a pair rather than written, so tests/unit/test-integrity.php can
 * compare the two without a subprocess and without touching the file. Nothing
 * in this file has a WordPress dependency, which is what makes that safe.
 *
 * @return array{0:string,1:string,2:bool} current, generated, whether the file used CRLF
 */
function readme_txt_expected(string $root): array
{
    $readmeMd = @file_get_contents($root . '/README.md');
    $readmeTxt = @file_get_contents($root . '/readme.txt');
    if ($readmeMd === false || $readmeTxt === false) {
        fail('Could not read README.md and readme.txt.');
    }

    // Compare with normalised line endings. What is written back uses whatever
    // the file already had, so this never shows up as a whole-file diff.
    $crlf = strpos($readmeTxt, "\r\n") !== false;
    $txt = str_replace("\r\n", "\n", $readmeTxt);
    $md = str_replace("\r\n", "\n", $readmeMd);

    $updated = $txt;
    foreach (SHARED as $heading) {
        $body = convert(markdown_section($md, $heading));

        // Replace from this `== Heading ==` up to the next `== ` at line start.
        $pattern = '/^(==\s*' . preg_quote($heading, '/') . '\s*==\n)(.*?)(?=^==\s)/ms';
        if (!preg_match($pattern, $updated)) {
            fail("readme.txt has no '== {$heading} ==' section, or it is the last one in the file.");
        }
        $updated = preg_replace_callback($pattern, static function (array $m) use ($body): string {
            return $m[1] . "\n" . $body . "\n\n";
        }, $updated, 1);
    }

    return [$txt, $updated, $crlf];
}

// ---------------------------------------------------------------------------
// Run as a script only. Included from a test, this file defines functions and
// does nothing else.

if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    try {
        $root = dirname(__DIR__);
        [$current, $generated, $crlf] = readme_txt_expected($root);

        if ($current === $generated) {
            echo "readme.txt is already up to date.\n";
            exit(0);
        }

        if (in_array('--check', $argv, true)) {
            fail("readme.txt is out of date with README.md.\nRun: php bin/build-readme-txt.php");
        }

        file_put_contents($root . '/readme.txt', $crlf ? str_replace("\n", "\r\n", $generated) : $generated);
        echo 'readme.txt regenerated from README.md (' . count(SHARED) . " sections).\n";
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
