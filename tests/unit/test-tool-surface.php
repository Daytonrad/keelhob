<?php
/**
 * Every tool that existed before the add-on was merged back in still exists.
 *
 * The merge folded four *-pro tool files into the originals they had been split
 * out of. The repo history records that the *split* nearly dropped three
 * always-on tools and nobody noticed until the zip was read by hand, so the
 * reverse operation gets a fixture rather than trust.
 *
 * tests/fixtures/tools-baseline.json was captured on the last commit where the
 * two halves still existed separately, by a script that has since been deleted
 * because there is nothing left for it to read. If a tool is deliberately added
 * or removed, edit the fixture in the same commit and say why - that edit is
 * the record that the change was intended.
 */

KH_Test::group('Tool surface — nothing was lost in the merge');

$baseline = json_decode(file_get_contents(__DIR__ . '/../fixtures/tools-baseline.json'), true);
// 69, not 68: snippet_revisions was added deliberately. The revisions table
// has existed since 1.9 and the admin Studio has always shown it, but no tool
// reached it — and code-snippets.md told agents in writing that snippet edits
// "are not versioned". An agent that overwrote a snippet therefore believed
// the previous code was gone and told the owner so, while it sat one query
// away. This is that edit-the-fixture-and-say-why record.
KH_Test::ok(is_array($baseline) && count($baseline) === 69, 'the baseline fixture holds all 69 tools');

// The WooCommerce groups refuse to register without WooCommerce, exactly as the
// snapshot run had to account for.
if (!class_exists('WooCommerce')) {
    class WooCommerce {}
}
foreach (glob(KEELHOB_DIR . 'includes/tools/class-tools-*.php') as $file) {
    require_once $file;
}

$registry = new Keelhob_Tool_Registry();
foreach ([
    'Core', 'Filesystem', 'Content', 'Site', 'WooCommerce', 'SEO', 'Memory',
    'Skills', 'Snippets', 'Elementor', 'Ops', 'Automation', 'Payments',
    'Deploy', 'Readiness',
] as $group) {
    call_user_func(['Keelhob_Tools_' . $group, 'register'], $registry);
}

$now = [];
foreach ($registry->list() as $tool) {
    $props = array_keys((array) ($tool['inputSchema']['properties'] ?? []));
    sort($props);
    $now[$tool['name']] = [
        'required'   => array_values((array) ($tool['inputSchema']['required'] ?? [])),
        'properties' => $props,
    ];
}
ksort($now);

// One tool is gone on purpose. pro_status reported which licence a site held,
// and there are no licences. Anything else missing is a bug.
$expected = $baseline;
unset($expected['pro_status']);

// Tools added deliberately since the snapshot. They are declared here rather
// than written into the fixture, because the fixture's whole value is being an
// untouched record of what the two halves held on the day they were merged —
// editing it to include something that never existed then would quietly turn a
// piece of evidence into a wish list.
//
// skill_lint applies the skill standard on a live site. It exists because the
// standard would otherwise only ever police the skills in this repository, and
// never the ones agents write on other people's sites.
$expected['skill_lint'] = ['required' => [], 'properties' => ['markdown', 'name']];

KH_Test::same([], array_values(array_diff(array_keys($expected), array_keys($now))), 'no tool was lost in the merge');
KH_Test::same([], array_values(array_diff(array_keys($now), array_keys($expected))), 'no tool appeared unannounced');
KH_Test::ok(!isset($now['pro_status']), 'pro_status is gone, which is the one intended removal');

$changed = [];
foreach ($expected as $name => $shape) {
    if (isset($now[$name]) && $now[$name] !== $shape) {
        $changed[] = $name;
    }
}
KH_Test::same([], $changed, 'no tool changed its input schema during the merge');

KH_Test::group('Every shipped tool survives Google\'s function-calling rules');

/*
 * THE BUG THIS PINS, reported from a live Telegram chat on Gemini:
 *
 *   LLM API error: HTTP 400: Invalid value at 'tools[0]
 *   .function_declarations[28].parameters.properties[3].value.enum[0]'
 *   (TYPE_STRING), 301
 *
 * seo_redirect declared `type: integer, enum: [301, 302]`. Anthropic and OpenAI
 * accept that without comment; Google allows `enum` only on a STRING and
 * rejects the ENTIRE request. One integer in one tool therefore took every
 * other tool down with it, and the agent could not answer anything at all on
 * that provider.
 *
 * Checked over the WHOLE tool surface, not just the tool that broke, because
 * the next one added will be written the same way.
 */
// The SAME shape the agent hands a provider: Keelhob_Agent_Runtime maps
// inputSchema onto 'schema'. Feeding the raw registry list would test a shape
// that never reaches a vendor.
$api_tools = [];
foreach ($registry->list() as $t) {
    $schema = $t['inputSchema'];
    if (empty($schema['type'])) {
        $schema = ['type' => 'object', 'properties' => (object) []];
    }
    $api_tools[] = ['name' => $t['name'], 'description' => (string) $t['description'], 'schema' => $schema];
}

$google = Keelhob_Provider_Google::body('gemini-3-pro', 'SYS', $api_tools, []);
$decls  = $google['tools'][0]['functionDeclarations'] ?? [];

KH_Test::ok(count($decls) > 20, 'the declarations were built at all (' . count($decls) . ')');

/*
 * EVERY rule, not just the one that broke.
 *
 * The enum rule was fixed first, shipped, and the very next message hit a
 * SECOND Google rule: "parameters.properties[data].items: missing field".
 * Anthropic and OpenAI accept a bare `type: array`; Google does not, and again
 * rejected the whole request rather than the one tool. Fixing rules one at a
 * time, each discovered by the owner in a live chat, is not a process -- so
 * this walks the entire declaration for everything Google is known to refuse.
 */
$bad = [];
$walk = static function (array $node, string $path) use (&$walk, &$bad): void {
    $type = $node['type'] ?? null;

    // enum belongs to a STRING, and every value must be a string.
    if (isset($node['enum'])) {
        if ($type !== 'string') {
            $bad[] = $path . ': enum on type "' . (string) ($type ?? 'none') . '"';
        }
        foreach ((array) $node['enum'] as $i => $v) {
            if (!is_string($v)) {
                $bad[] = $path . ".enum[$i] is " . gettype($v);
            }
        }
    }
    // An array MUST say what it contains.
    if ($type === 'array' && !isset($node['items'])) {
        $bad[] = $path . ': type array with no items';
    }
    // An object must carry a properties map, even an empty one.
    if ($type === 'object' && !isset($node['properties'])) {
        $bad[] = $path . ': type object with no properties';
    }
    // A union type ("string or null") is JSON Schema, not Google.
    if (is_array($type)) {
        $bad[] = $path . ': type is a list, not a single type';
    }

    foreach ((array) ($node['properties'] ?? []) as $name => $prop) {
        if (is_array($prop)) {
            $walk($prop, $path . '.' . $name);
        }
    }
    if (is_array($node['items'] ?? null)) {
        $walk($node['items'], $path . '[]');
    }
};

// Walk EVERY declaration. This loop went missing once during an edit, and the
// assertion below then passed on an empty $bad while the bug it was written for
// was sitting in the file. A test that reports green without running is worse
// than no test, so the count is asserted too.
$checked = 0;
foreach ($decls as $d) {
    $walk((array) ($d['parameters'] ?? []), (string) ($d['name'] ?? '?'));
    $checked++;
}
KH_Test::same(count($decls), $checked, 'every declaration was actually inspected');
KH_Test::same([], $bad, 'no tool sends Google a schema it will reject');

// The specific one, named, so a regression says what it is rather than just
// dumping a list.
$redirect = null;
foreach ($decls as $d) {
    if (($d['name'] ?? '') === 'seo_redirect') {
        $redirect = $d;
    }
}
KH_Test::ok($redirect !== null, 'seo_redirect is offered to Google');
if ($redirect) {
    $type = $redirect['parameters']['properties']['type'] ?? [];
    KH_Test::same('string', $type['type'] ?? '', 'its status-code parameter is sent as a string');
    KH_Test::same(['301', '302'], array_values((array) ($type['enum'] ?? [])), 'with its choices as strings, so the model still sees them');
}

// The OTHER providers must be left alone: they accept the integer enum, and
// quietly restating it as a string everywhere would be a change nobody asked
// for.
$anthropic = Keelhob_Provider_Anthropic::body('claude-sonnet-5', 'SYS', $api_tools, []);
$a_redirect = null;
foreach ((array) ($anthropic['tools'] ?? []) as $t) {
    if (($t['name'] ?? '') === 'seo_redirect') {
        $a_redirect = $t;
    }
}
KH_Test::ok($a_redirect !== null, 'seo_redirect reaches Anthropic too');
if ($a_redirect) {
    KH_Test::same('integer', $a_redirect['input_schema']['properties']['type']['type'] ?? '', 'and Anthropic still gets the honest integer');
}

KH_Test::group('Every list tool states its own count');

/*
 * The same defect as snippet_list's "28 of 44": a tool hands back a long array
 * and the model is left to count it. Pinned across the whole surface rather
 * than per tool, because the next list tool added will be written the same way
 * and nobody will remember this.
 */
$counting = [
    'snippet_list' => 'snippets',
    'task_list'    => 'tasks',
    'memory_list'  => 'memory',
    'skill_list'   => 'skills',
    'list_posts'   => 'posts',
];
$missing = [];
foreach ($counting as $tool => $rows_key) {
    $src = '';
    foreach (glob(KEELHOB_DIR . 'includes/tools/class-tools-*.php') as $f) {
        $body = (string) file_get_contents($f);
        if (strpos($body, "'" . $tool . "'") !== false) {
            $src .= $body;
        }
    }
    // 'count' for most; list_posts pre-dates this and says 'total' because it
    // is paginated -- the whole-result-set number, which is the honest one there.
    if (strpos($src, "'count'") === false && strpos($src, "'total'") === false) {
        $missing[] = $tool;
    }
}
KH_Test::same([], $missing, 'no list tool makes the model count its own rows');
