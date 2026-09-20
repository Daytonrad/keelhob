<?php
/**
 * Generate everything about the skills that used to be kept by hand.
 *
 *   php bin/build-skill-router.php            write the generated files
 *   php bin/build-skill-router.php --check    fail if they are out of date
 *
 * Writes two things, both derived from skills/*.md and nothing else:
 *
 *   1. includes/class-skill-catalog.php — the name/title/description map the
 *      Skills admin screen renders without loading eighteen bodies.
 *   2. The skill-tree table in skills/build-standard.md, between the
 *      ROUTER:START / ROUTER:END markers.
 *
 * Why: accepting a good skill used to cost four edits in four unrelated files —
 * a router row here, a catalogue entry there, a count in the zip builder, a
 * number in a test. Miss one and the build broke, which meant every contributed
 * skill arrived with a chore attached and a way to get it wrong. A standard
 * nobody can follow without a checklist is not a standard.
 *
 * The hand-written "Job → Skill" table in build-standard.md is deliberately NOT
 * generated. Its left column says what a person is trying to DO, in their
 * words, and no rule can derive "make it feel alive/premium/expensive" from the
 * slug motion-and-interaction. Generating it would replace judgement with
 * slugs and make the router worse. What is generated is the part no human
 * should be maintaining: the machine-readable tree.
 *
 * CI runs this with --check, so a skill added without regenerating fails the
 * build with a clear reason instead of drifting quietly.
 */

$root = dirname(__DIR__);

if (!defined('ABSPATH')) {
    define('ABSPATH', $root . '/');
}
require_once $root . '/includes/class-skill-standard.php';
require_once $root . '/includes/class-skills.php';

// $argv only exists under the CLI SAPI, which is the only way this is ever
// run — but PHPStan is right that nothing here guarantees it.
$args  = isset($argv) && is_array($argv) ? $argv : [];
$check = in_array('--check', $args, true);

// ---- read -------------------------------------------------------------------

$files = glob($root . '/skills/*.md') ?: [];
sort($files);

$skills = [];
foreach ($files as $file) {
    $slug   = basename($file, '.md');
    $parsed = Keelhob_Skills::parse_markdown((string) file_get_contents($file));
    if (empty($parsed['name'])) {
        fwrite(STDERR, "$slug has no readable front matter. Run bin/lint-skills.php.\n");
        exit(1);
    }
    $skills[$slug] = $parsed;
}

if (empty($skills)) {
    fwrite(STDERR, "No skills found.\n");
    exit(1);
}

/**
 * The first sentence of a description.
 *
 * Two traps, both hit by descriptions that actually ship:
 *
 *   - "llms.txt" and "robots.txt" mean a naive explode('.') cuts mid-word, so
 *     the break has to be a full stop followed by whitespace and a capital.
 *   - That alone still cuts "(free or Pro, incl. Xpro Addons)" after "incl.",
 *     because "Xpro" is capitalised. Abbreviations are checked explicitly.
 */
$first_sentence = static function (string $text): string {
    $text = trim($text);
    $abbr = ['incl', 'e.g', 'i.e', 'etc', 'vs', 'approx', 'no', 'ca', 'dr', 'mr', 'ms', 'st'];

    if (!preg_match_all('/\.\s+(?=[A-Z])/', $text, $m, PREG_OFFSET_CAPTURE)) {
        return $text;
    }

    foreach ($m[0] as $hit) {
        $at     = (int) $hit[1];
        $before = strtolower((string) preg_replace('/.*?([A-Za-z.]+)$/', '$1', substr($text, 0, $at)));
        if (in_array($before, $abbr, true)) {
            continue;
        }
        return trim(substr($text, 0, $at + 1));
    }

    return $text;
};

/** A PHP single-quoted string literal. */
$q = static function (string $s): string {
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
};

// ---- 1. the catalogue -------------------------------------------------------

$cat = <<<'HEAD'
<?php
/**
 * The skill catalogue: what exists, what it is called, and what it does.
 *
 * GENERATED FILE — do not edit by hand. Run `php bin/build-skill-router.php`.
 * The source of truth is the front matter in skills/*.md; every value below is
 * that file's `title` and the first sentence of its `description`.
 *
 * It is deliberately separate from the skill BODIES. It exists so the Skills
 * admin screen can name and describe every skill without loading eighteen
 * bodies to render one list — the bodies are large, and the screen only ever
 * shows a title and a line.
 *
 * It was hand-kept until the skill standard arrived, and drifting from skills/
 * was one of the four chores that made accepting a contributed skill a job in
 * itself.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Skill_Catalog
{
    /**
     * @return array<string, array{title:string, description:string}>
     */
    public static function all(): array
    {
        return [
HEAD;

$cat .= "\n";
foreach ($skills as $slug => $s) {
    $title = (string) ($s['title'] ?? ucfirst(str_replace('-', ' ', $slug)));
    $cat .= '            ' . $q($slug) . " => [\n";
    $cat .= "                'title'       => " . $q($title) . ",\n";
    $cat .= "                'description' => " . $q($first_sentence((string) ($s['description'] ?? ''))) . ",\n";
    $cat .= "            ],\n";
}

$cat .= <<<'TAIL'
        ];
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::all());
    }
}

TAIL;

// ---- 2. the tree table ------------------------------------------------------

$rows = [];
$rows[] = '| Skill | Role | Runs on | Requires first | Then consider | Not done until |';
$rows[] = '|---|---|---|---|---|---|';

$fmt = static function (array $list): string {
    return empty($list) ? '—' : '`' . implode('`, `', $list) . '`';
};

foreach ($skills as $slug => $s) {
    $e = Keelhob_Skill_Standard::edges($s);
    $rows[] = sprintf(
        '| `%s` | %s | %s | %s | %s | %s |',
        $slug,
        Keelhob_Skill_Standard::role($s),
        Keelhob_Skill_Standard::surface($s),
        $fmt($e['requires']),
        $fmt($e['next_skills']),
        $fmt($e['verified_by'])
    );
}

$tree = "\n" . implode("\n", $rows) . "\n";

// ---- 3. the contributor documentation ---------------------------------------
//
// Generated for one reason: every number in it is read from
// Keelhob_Skill_Standard, so the document can never tell a contributor a
// limit the linter does not actually enforce. A hand-written standard that has
// drifted from the check is worse than no document, because someone follows it
// and is failed by a rule they were never told about.

$S = 'Keelhob_Skill_Standard';

$doc = "# The Keelhob skill standard\n\n"
    . "**GENERATED FILE — do not edit by hand.** Run `php bin/build-skill-router.php`.\n"
    . "Every limit below is read from `includes/class-skill-standard.php`, so this page and\n"
    . "the check that enforces it cannot disagree.\n\n"
    . "A skill is a Markdown playbook any connected agent loads when its description matches\n"
    . "the task at hand. This page says what one has to contain to be accepted, and why each\n"
    . "rule exists. Run `php bin/lint-skills.php` to check your work — it prints a score out\n"
    . "of 100 and names what is wrong. It is also what a reviewer runs on your pull request.\n\n"

    . "## Front matter\n\n"
    . "It is **not YAML.** `Keelhob_Skills::parse_markdown()` reads one key per line with\n"
    . "no nesting, no lists and no wrapped values. A description that runs onto a second line\n"
    . "silently loses everything after the first, and the description is the only thing that\n"
    . "routes a task to your skill — so it fails in total silence.\n\n"
    . "```\n"
    . "---\n"
    . "name: kebab-case-slug          # must match the filename\n"
    . "title: Human readable name     # how the admin screen lists it\n"
    . "description: One line...       # see below — this is the router\n"
    . "enable_agentic: true\n"
    . "enable_prompt: true\n"
    . "role: procedure                # " . implode(' | ', constant($S . '::ROLES')) . "\n"
    . "surface: any                   # " . implode(' | ', constant($S . '::SURFACES')) . "\n"
    . "requires: skill-a, skill-b     # load these BEFORE starting\n"
    . "next_skills: skill-c           # consider these when finished\n"
    . "verified_by: pre-launch-audit  # NOT done until this has run\n"
    . "---\n"
    . "```\n\n"

    . "### The three roles\n\n"
    . "| Role | Meaning |\n|---|---|\n"
    . "| `gate` | Gets in the way on purpose. Work is not done until it passes. |\n"
    . "| `procedure` | Steps to follow to accomplish a task. The default. |\n"
    . "| `reference` | Background knowledge, read for judgement. No steps. |\n\n"

    . "### Where a skill can run\n\n"
    . "`role` says what a skill IS. `surface` says which kind of session can FINISH it, and\n"
    . "it exists because a skill body reads identically down every pipe. Without it, an agent\n"
    . "on Telegram loads a skill whose third step is \"open the page in a headless browser and\n"
    . "compare computed styles\", gets four steps in, and then either stalls or invents a\n"
    . "result. Both are worse than refusing at step zero and naming the client that can.\n\n"
    . "| Surface | Meaning |\n|---|---|\n"
    . "| `any` | " . constant($S . '::SURFACE_NOTES')['any'] . " The default. |\n"
    . "| `interactive` | " . constant($S . '::SURFACE_NOTES')['interactive'] . " |\n"
    . "| `workstation` | " . constant($S . '::SURFACE_NOTES')['workstation'] . " |\n\n"
    . "A skill may not `requires`, or be `verified_by`, a skill with a stricter surface than\n"
    . "its own: that is a promise it cannot keep, and the linter refuses it. `next_skills` is\n"
    . "deliberately exempt, because that edge is advice for afterwards rather than a\n"
    . "prerequisite — \"and when you are next at your machine, do this too\" is a useful thing\n"
    . "for a chat-safe skill to be able to say.\n\n"

    . "### The three edges\n\n"
    . "This is what makes a skill part of an ecosystem instead of an island. Before these\n"
    . "existed, one skill could only point at another in prose — so nothing could detect a\n"
    . "loop, price a chain, or build a router, and four of the shipped skills were dead ends\n"
    . "nobody had noticed. A skill must declare at least one of `next_skills` or\n"
    . "`verified_by`, and at least one other skill must point at it.\n\n"
    . "`verified_by` is the important one. Finishing the steps in a skill is not the same as\n"
    . "the work being right, and operating-protocol rule 13 makes an unrun verifier something\n"
    . "the agent has to declare rather than something it can quietly skip.\n\n"

    . "## The description is the router\n\n"
    . "It is the only text a model sees when deciding whether to load your skill. Write it\n"
    . "for a stranger, and put in the words a user would actually type — not the words you\n"
    . "would use to categorise it.\n\n"
    . "- Between **" . constant($S . '::DESC_MIN') . "** and **" . constant($S . '::DESC_MAX') . "** characters.\n"
    . "- One line. No wrapping.\n"
    . "- Say WHEN to load it, not only what it is.\n\n"
    . "The whole catalogue is capped at **" . (int) round(constant($S . '::CATALOG_BUDGET') / 1024) . " KB**, because every connected session pays\n"
    . "for it before any work happens. If the set outgrows that, someone has to earn the\n"
    . "space by tightening a description or merging two skills.\n\n"

    . "## Required sections\n\n"
    . "In this order:\n\n";

foreach (constant($S . '::SECTIONS') as $i => $heading) {
    $doc .= ($i + 1) . ". `## " . $heading . "`\n";
}

$doc .= "\n`When NOT to use` is the one people leave out, and it is half of routing. A skill\n"
    . "firing on the wrong job costs as much as one that never fires at all.\n\n"
    . "`Routing examples` is a machine-read fixture, not prose. At least **two** `fires:`\n"
    . "phrases and at least one `skips:`:\n\n"
    . "```\n## Routing examples\n\n- fires: my site is slow on google\n- fires: fix my seo\n- skips: write me a blog post\n```\n\n"
    . "The linter checks each `fires:` phrase reaches your skill and not another, and that\n"
    . "each `skips:` phrase does not. It scores by shared significant words — it is not a\n"
    . "model and does not pretend to be. What it reliably catches is a description that does\n"
    . "not contain the words your users would type, which is what makes a good skill\n"
    . "unreachable.\n\n"

    . "## Length\n\n"
    . "Body lines, excluding the front matter.\n\n"
    . "| Role | Minimum | Maximum |\n|---|---|---|\n"
    . "| `procedure`, `gate` | " . constant($S . '::LINES_MIN') . " | " . constant($S . '::LINES_MAX') . " |\n"
    . "| `reference` | " . constant($S . '::LINES_MIN') . " | " . constant($S . '::LINES_MAX_REFERENCE') . " |\n\n"
    . "Below the floor it is a paragraph, and a paragraph belongs inside a skill that already\n"
    . "exists rather than competing with one. Above the ceiling, split it — or say plainly\n"
    . "that it is a reference, which is read for judgement rather than followed step by step.\n\n"
    . "Following a skill and everything it `requires` may not exceed **"
    . (int) round(constant($S . '::WEIGHT_BUDGET') / 1024) . " KB** of context.\n"
    . "If you are over, move something from `requires` to `next_skills`: one is an obligation,\n"
    . "the other is advice.\n\n"

    . "## What the linter also checks\n\n"
    . "- **Loops.** A verifies B and B verifies A means \"am I done yet\" never terminates.\n"
    . "  `requires` and `verified_by` are each checked separately. `next_skills` may form a\n"
    . "  ring, because it is advice rather than an obligation.\n"
    . "- **Orphans.** Nothing points at your skill, so it is only ever reached if a user's own\n"
    . "  wording happens to match. A skill nobody can reach does not exist.\n"
    . "- **Clashes.** Two descriptions more than " . (int) round(constant($S . '::CLASH_THRESHOLD') * 100) . "%% alike are competing for the same\n"
    . "  request, and the agent picks one at random. This is how a skill pack rots as it grows.\n\n"

    . "## Before you open a pull request\n\n"
    . "```bash\n"
    . "php bin/lint-skills.php              # must pass; aim for 100\n"
    . "php bin/build-skill-router.php       # regenerate the catalogue and the tree table\n"
    . "php tests/run.php\n"
    . "```\n\n"
    . "Commit whatever the generator changed. You do **not** need to update a router row, a\n"
    . "catalogue entry, a count in the zip builder, or a number in a test — those were four\n"
    . "separate chores that made accepting a contributed skill a job in itself, and they are\n"
    . "all derived now.\n\n"

    . "## On a live site\n\n"
    . "The same rules run inside the plugin. Call the `skill_lint` tool to check a draft\n"
    . "before saving it, or to check every skill and the links between them. `skill_save`\n"
    . "reports the same findings as warnings — it never refuses. Enforcing a standard is this\n"
    . "repository's job; on someone else's site, telling them is.\n";

$doc = str_replace('%%', '%', $doc);

// ---- write or check ---------------------------------------------------------

$stale = [];

$cat_path = $root . '/includes/class-skill-catalog.php';
$cat_now  = is_file($cat_path) ? (string) file_get_contents($cat_path) : '';
$cat_nl   = strpos($cat_now, "\r\n") !== false ? "\r\n" : "\n";
$cat_out  = str_replace("\n", $cat_nl, $cat);

if ($cat_out !== $cat_now) {
    $stale[] = 'includes/class-skill-catalog.php';
    if (!$check) {
        file_put_contents($cat_path, $cat_out);
    }
}

$doc_path = $root . '/docs/SKILL-STANDARD.md';
$doc_now  = is_file($doc_path) ? (string) file_get_contents($doc_path) : '';
$doc_nl   = strpos($doc_now, "\r\n") !== false ? "\r\n" : "\n";
$doc_out  = str_replace("\n", $doc_nl, $doc);

if ($doc_out !== $doc_now) {
    $stale[] = 'docs/SKILL-STANDARD.md';
    if (!$check) {
        if (!is_dir(dirname($doc_path))) {
            mkdir(dirname($doc_path), 0777, true);
        }
        file_put_contents($doc_path, $doc_out);
    }
}

$bs_path = $root . '/skills/build-standard.md';
$bs_raw  = (string) file_get_contents($bs_path);
$bs_nl   = strpos($bs_raw, "\r\n") !== false ? "\r\n" : "\n";
$bs      = str_replace(["\r\n", "\r"], "\n", $bs_raw);

$start = '<!-- ROUTER:START -->';
$end   = '<!-- ROUTER:END -->';

if (strpos($bs, $start) === false) {
    fwrite(STDERR, "skills/build-standard.md has no $start marker. Add the markers once, then this script keeps the table between them current.\n");
    exit(1);
}

$bs_new = (string) preg_replace(
    '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '/s',
    $start . $tree . $end,
    $bs,
    1
);
$bs_out = str_replace("\n", $bs_nl, $bs_new);

if ($bs_out !== $bs_raw) {
    $stale[] = 'skills/build-standard.md';
    if (!$check) {
        file_put_contents($bs_path, $bs_out);
    }
}

if ($check) {
    if (!empty($stale)) {
        fwrite(STDERR, "Out of date: " . implode(', ', $stale) . "\nRun: php bin/build-skill-router.php\n");
        exit(1);
    }
    echo "Generated files are current (" . count($skills) . " skills).\n";
    exit(0);
}

echo empty($stale)
    ? "Already current (" . count($skills) . " skills).\n"
    : "Wrote: " . implode(', ', $stale) . " (" . count($skills) . " skills).\n";
exit(0);
