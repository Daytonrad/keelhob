<?php
/**
 * Snippet storage logic — the pure, database-free half.
 *
 * These cover the three rules the Snippets page and cross-site copying stand
 * on, each of which fails silently rather than loudly when it breaks:
 *   - tag matching must be exact, or filtering by "seo" also returns "seo-audit"
 *   - a re-copy must resolve to the row it already created, or every copy
 *     duplicates the snippet on the destination site
 *   - PHP that does not parse must be caught before it is switched on
 */

CP_Test::group('Snippets — portable identity');

$uid = Claudpress_Snippets::new_uid();
CP_Test::ok(strpos($uid, 'cps_') === 0, 'a uid is prefixed cps_');
CP_Test::same(20, strlen($uid), 'a uid is a fixed 20 characters');

$seen = [];
for ($i = 0; $i < 1000; $i++) {
    $seen[Claudpress_Snippets::new_uid()] = true;
}
CP_Test::same(1000, count($seen), '1000 generated uids are all distinct');

CP_Test::group('Snippets — tag normalization');

CP_Test::same(',seo,perf,', Claudpress_Snippets::normalize_tags(['seo', 'perf']), 'tags are stored sentinel-wrapped');
CP_Test::same(',seo,', Claudpress_Snippets::normalize_tags('seo'), 'a bare string is accepted');
CP_Test::same(',seo,perf,', Claudpress_Snippets::normalize_tags(' SEO , Perf '), 'tags are lowercased and trimmed');
CP_Test::same(',seo,', Claudpress_Snippets::normalize_tags(['seo', 'seo', '']), 'duplicates and blanks are dropped');
CP_Test::same('', Claudpress_Snippets::normalize_tags([]), 'no tags means an empty string, not a lone sentinel');
CP_Test::same('', Claudpress_Snippets::normalize_tags(null), 'null is tolerated');
CP_Test::same(',sticky-header,', Claudpress_Snippets::normalize_tags('Sticky Header'), 'spaces become hyphens');
CP_Test::same(',drop,', Claudpress_Snippets::normalize_tags('dr%op'), 'punctuation is stripped rather than stored');

$many = Claudpress_Snippets::normalize_tags(range(1, 40));
CP_Test::same(10, count(Claudpress_Snippets::tags_array($many)), 'no more than 10 tags are kept');
CP_Test::ok(strlen(Claudpress_Snippets::tags_array(Claudpress_Snippets::normalize_tags([str_repeat('a', 80)]))[0]) <= 32, 'a tag is capped at 32 characters');

CP_Test::same(['seo', 'perf'], Claudpress_Snippets::tags_array(',seo,perf,'), 'tags round-trip back to an array');
CP_Test::same([], Claudpress_Snippets::tags_array(''), 'an empty tag string yields no tags');
CP_Test::same([], Claudpress_Snippets::tags_array(null), 'a null tag column yields no tags');

// The whole reason for the sentinels: an exact-tag LIKE must not match a prefix.
$stored = Claudpress_Snippets::normalize_tags(['seo-audit']);
CP_Test::ok(strpos($stored, ',seo,') === false, 'the tag seo-audit does not contain the tag seo');

CP_Test::group('Snippets — save() resolves the row to update');

/** Pretend rows 5 (uid cps_local) and 7 (a copy of cps_remote) already exist. */
$finder = static function (string $column, string $value): ?int {
    $rows = [
        'id'         => ['5' => 5, '7' => 7],
        'uid'        => ['cps_local' => 5, 'cps_copy' => 7],
        'origin_uid' => ['cps_remote' => 7],
    ];
    return $rows[$column][$value] ?? null;
};

CP_Test::same(5, Claudpress_Snippets::resolve_target(['id' => 5], $finder), 'an existing id resolves to that row');
CP_Test::same(null, Claudpress_Snippets::resolve_target(['id' => 99], $finder), 'an id that does not exist falls through to insert');
CP_Test::same(5, Claudpress_Snippets::resolve_target(['uid' => 'cps_local'], $finder), 'a known uid resolves to its row');
CP_Test::same(null, Claudpress_Snippets::resolve_target([], $finder), 'an empty payload inserts');
CP_Test::same(null, Claudpress_Snippets::resolve_target(['uid' => '', 'origin_uid' => ''], $finder), 'blank identifiers do not match anything');

// The anti-duplication guarantee: copying the same source snippet again lands
// on the copy already made, instead of adding a second one every time.
CP_Test::same(7, Claudpress_Snippets::resolve_target(['origin_uid' => 'cps_remote'], $finder), 're-copying resolves to the existing copy');
CP_Test::same(null, Claudpress_Snippets::resolve_target(['origin_uid' => 'cps_never_seen'], $finder), 'a first-time copy inserts');

// Precedence: an explicit id wins over the identity fields.
CP_Test::same(5, Claudpress_Snippets::resolve_target(['id' => 5, 'origin_uid' => 'cps_remote'], $finder), 'an explicit id outranks origin_uid');

CP_Test::group('Snippets — PHP is parse-checked before it can run');

CP_Test::same('', Claudpress_Snippets::lint_php('add_action("init", function () { return 1; });'), 'valid tag-less snippet PHP passes');
CP_Test::same('', Claudpress_Snippets::lint_php('$a = [1, 2, 3]; foreach ($a as $n) { echo $n; }'), 'valid multi-statement PHP passes');
CP_Test::ok(Claudpress_Snippets::lint_php('if (true) { echo "x";') !== '', 'an unclosed brace is rejected');
CP_Test::ok(Claudpress_Snippets::lint_php('function () {{{') !== '', 'garbage is rejected');

// page_template bodies are raw markup with <?php islands, so they lint as-is.
CP_Test::same('', Claudpress_Snippets::lint_php('<h1>Hi</h1><?php echo get_the_title(); ?>', 'php', 'page_template'), 'a page_template body with PHP islands passes');
CP_Test::ok(Claudpress_Snippets::lint_php('<h1>Hi</h1><?php echo (;', 'php', 'page_template') !== '', 'a broken page_template island is rejected');

// Only PHP is parse-checked; CSS and JS are not PHP and must not be judged as such.
CP_Test::same('', Claudpress_Snippets::lint_php('.a { color: red; }', 'css'), 'CSS is not linted as PHP');
CP_Test::same('', Claudpress_Snippets::lint_php('if (x) { y(); }', 'js'), 'JS is not linted as PHP');
CP_Test::same('', Claudpress_Snippets::lint_php('', 'php'), 'an empty body is not an error');

CP_Test::group('Snippets — the wp-config kill switch');

// The guard this covers is not a code path a unit test can execute: init()
// registers WordPress hooks, and the constant has to be undefined for the rest
// of the suite. So this asserts the SHAPE of the guard in the source instead,
// which is what would actually rot: someone reorders init() and the kill switch
// silently stops being first, or the constant gets renamed in one of the four
// places it is documented and not the others.
//
// Why it matters enough to test at all: an active snippet can take a site
// offline WITHOUT erroring — return a 503 from the front end and a container
// host stops routing to the site, wp-admin included. The fatal-error guard
// never fires, because nothing was fatal. Every in-WordPress switch is then
// unreachable, so this constant is the only way back. It has to keep working
// and it has to stay findable.

$src = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/class-snippets.php');

preg_match('/function init\(\): void\s*\{(.*?)\n    \}/s', $src, $m);
$init = (string) ($m[1] ?? '');
CP_Test::ok($init !== '', 'found Claudpress_Snippets::init()');

CP_Test::contains(
    "defined('CLAUDPRESS_SNIPPETS_OFF')",
    $init,
    'init() checks the CLAUDPRESS_SNIPPETS_OFF constant'
);

// Order is the whole point. A kill switch that runs after the settings lookup
// still works, but one that runs after the hooks are added does not.
$killPos = strpos($init, "CLAUDPRESS_SNIPPETS_OFF");
$hookPos = strpos($init, 'add_action');
CP_Test::ok(
    $killPos !== false && $hookPos !== false && $killPos < $hookPos,
    'the kill switch is checked BEFORE any snippet hook is registered'
);

$settingPos = strpos($init, "get('run_snippets'");
CP_Test::ok(
    $killPos !== false && $settingPos !== false && $killPos < $settingPos,
    'and before the run_snippets setting, so it wins over the database'
);

// A kill switch nobody can find is not a kill switch. These are the two places
// an owner with a broken site actually looks.
$root = dirname(__DIR__, 2);
foreach (['readme.txt', 'README.md'] as $doc) {
    CP_Test::contains(
        'CLAUDPRESS_SNIPPETS_OFF',
        (string) file_get_contents($root . '/' . $doc),
        $doc . ' documents the kill switch'
    );
}

// And the agent has to know it exists, or it cannot tell the owner how to
// recover from something the agent itself installed.
CP_Test::contains(
    'CLAUDPRESS_SNIPPETS_OFF',
    (string) file_get_contents($root . '/includes/class-mcp-server.php'),
    'the operating protocol tells the agent about the kill switch'
);

CP_Test::group('Snippets — agents are warned about front-end status codes');

// The failure that prompted all of the above: an agent building a coming-soon
// page reaches for 503, because 503 is the textbook-correct status for
// "temporarily unavailable" and WordPress core itself uses it for maintenance
// mode. On a containerised host that takes the whole site off the internet.
// The only cheap fix is telling the agent before it acts.
$protocol = (string) file_get_contents($root . '/includes/class-mcp-server.php');
$toolDesc = (string) file_get_contents($root . '/includes/tools/class-tools-snippets.php');

foreach ([['operating protocol', $protocol], ['snippet_save description', $toolDesc]] as [$where, $text]) {
    CP_Test::ok(
        stripos($text, '5xx') !== false || stripos($text, '503') !== false,
        $where . ' warns about 5xx status codes from the front end'
    );
    CP_Test::ok(
        stripos($text, 'wp-login') !== false,
        $where . ' says a front-end gate must let wp-login through'
    );
}

CP_Test::group('Snippets — only front-end locations are worth checking');

// The verdict logic itself (probe_home / healthy_status / reachability_verdict)
// lives in Claudpress_Health now — see tests/unit/test-health.php. This is the
// one piece of the reachability check that stays snippet-specific: deciding
// which locations can even reach an anonymous visitor in the first place.

$affects = new ReflectionMethod('Claudpress_Snippets', 'affects_front_end');
$affects->setAccessible(true);
foreach (['everywhere', 'frontend_only', 'header', 'footer', 'page_template'] as $loc) {
    CP_Test::ok($affects->invoke(null, $loc), "$loc can reach an anonymous visitor, so it is checked");
}
foreach (['admin_only', 'shortcode'] as $loc) {
    CP_Test::ok(!$affects->invoke(null, $loc), "$loc cannot break the home page, so it is not checked");
}

CP_Test::group('Snippets — the reachability verdict survives the tool layer');

// Found by running it: snippet_save's generic "Active PHP snippet..." note was
// assigned over the top of save()'s note, which is where the reachability
// verdict travels. The live response said front_end_status 503 with no
// explanation whatsoever — the one message the agent needed was the one thrown
// away. Assignment vs concatenation, and it is invisible in every unit test
// that does not go through the tool.
$tool = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/tools/class-tools-snippets.php');

preg_match('/\$res = Claudpress_Snippets::save\(\$a\);(.*?)return \$res;/s', $tool, $m);
$block = (string) ($m[1] ?? '');
CP_Test::ok($block !== '', 'found the snippet_save note block');

CP_Test::ok(
    strpos($block, "\$res['note'] = 'Active PHP snippet") === false,
    'the generic note is not assigned straight over $res[note]'
);
CP_Test::contains('$res[\'note\']', $block, 'it still sets a note');
CP_Test::ok(
    strpos($block, '$generic . \' \' . $res[\'note\']') !== false,
    'and it appends the existing note rather than replacing it'
);

CP_Test::group('Snippets — save() refuses css/js/html at a location that cannot emit them');

// The bug this exists for: a css snippet saved at frontend_only is stored,
// marked active, listed as active in the admin, and never runs — because
// run_php_everywhere() skips anything that is not snippet_type php. Nobody is
// warned. save() must refuse the write outright rather than store dead code.

/** A $wpdb that remembers whether it was ever asked to write a row. */
class CP_Save_Guard_WPDB extends CP_Test_WPDB
{
    /** @var array<string, mixed>|null Row returned for the next get_row()/get_var() lookup. */
    public ?array $existing = null;
    public int $inserts     = 0;
    public int $updates     = 0;

    public function get_var($query, $column = 0, $row = 0)
    {
        return $this->existing['id'] ?? null;
    }

    public function get_row($query, $output = null)
    {
        return $this->existing;
    }

    public function insert($table, $data)
    {
        if ($table === $this->prefix . 'claudpress_snippets') {
            $this->inserts++;
        }
        return 1;
    }

    public function update($table, $data, $where)
    {
        if ($table === $this->prefix . 'claudpress_snippets') {
            $this->updates++;
        }
        return 1;
    }
}

$real_wpdb = $GLOBALS['wpdb'];
$guard     = new CP_Save_Guard_WPDB();
$GLOBALS['wpdb'] = $guard;

// 1. css at frontend_only is refused, and nothing is written.
$res = Claudpress_Snippets::save(['name' => 'Site CSS', 'code' => '.a{color:red}', 'snippet_type' => 'css', 'location' => 'frontend_only']);
CP_Test::same(false, $res['success'], 'a css snippet at frontend_only is refused');
CP_Test::contains('header', (string) ($res['error'] ?? ''), 'the error names a location that would work');
CP_Test::contains('frontend_only', (string) ($res['error'] ?? ''), 'the error names the location it was given');
CP_Test::contains('css', (string) ($res['error'] ?? ''), 'the error names the type');
CP_Test::same(0, $guard->inserts, 'nothing was inserted into the snippets table');
CP_Test::same(0, $guard->updates, 'nothing was updated in the snippets table either');

// 2. css at header saves fine.
$guard->inserts = 0;
$res = Claudpress_Snippets::save(['name' => 'Site CSS', 'code' => '.a{color:red}', 'snippet_type' => 'css', 'location' => 'header']);
CP_Test::same(true, $res['success'], 'a css snippet at header saves fine');
CP_Test::same(1, $guard->inserts, 'and is actually written');

// 3. js at everywhere is refused.
$guard->inserts = 0;
$res = Claudpress_Snippets::save(['name' => 'Tracker', 'code' => 'console.log(1)', 'snippet_type' => 'js', 'location' => 'everywhere']);
CP_Test::same(false, $res['success'], 'a js snippet at everywhere is refused');
CP_Test::same(0, $guard->inserts, 'nothing was inserted for it');

// 4. php at frontend_only still saves — the common case must not regress.
$guard->inserts = 0;
$res = Claudpress_Snippets::save(['name' => 'Redirect', 'code' => 'add_action("init", function () {});', 'snippet_type' => 'php', 'location' => 'frontend_only']);
CP_Test::same(true, $res['success'], 'a php snippet at frontend_only still saves fine');
CP_Test::same(1, $guard->inserts, 'and is actually written');

// 5. A metadata-only update on an existing css/header snippet still succeeds:
// the merge path must resolve the STORED location (header), not treat a
// missing `location` key in $a as unset-and-therefore-invalid.
$guard->existing = [
    'id'              => 5,
    'uid'             => 'cps_existing01234',
    'name'            => 'Site CSS',
    'description'     => '',
    'code'            => '.a{color:red}',
    'snippet_type'    => 'css',
    'location'        => 'header',
    'target_id'       => 0,
    'priority'        => 10,
    'active'          => 0,
    'tags'            => '',
    'purpose'         => '',
    'impact'          => '',
    'origin_site'     => '',
    'origin_uid'      => '',
    'source'          => 'manual',
    'ai_prompt'       => null,
    'ai_model'        => '',
    'conditions_json' => null,
    'last_error'      => null,
    'created_at'      => '2026-01-01 00:00:00',
    'updated_at'      => '2026-01-01 00:00:00',
];
$guard->inserts = 0;
$guard->updates = 0;
$res = Claudpress_Snippets::save(['id' => 5, 'tags' => 'x']);
CP_Test::same(true, $res['success'], 'a metadata-only update on an existing css/header snippet still succeeds');
CP_Test::same(1, $guard->updates, 'and it updates the existing row rather than being refused');

// 6. A LEGACY row — css already stored at a location that cannot emit it,
// saved before this check existed — must stay editable. Refusing an edit that
// supplies neither snippet_type nor location would lock the owner out of the
// one snippet they are trying to rescue, which is a worse bug than the dead
// snippet it is trying to prevent.
$guard->existing['location'] = 'frontend_only';
$guard->updates = 0;
$res = Claudpress_Snippets::save(['id' => 5, 'name' => 'Site CSS (renamed)']);
CP_Test::same(true, $res['success'], 'a legacy css@frontend_only row can still be renamed');
CP_Test::same(1, $guard->updates, 'and the rename actually reaches the database');

$guard->updates = 0;
$res = Claudpress_Snippets::save(['id' => 5, 'code' => '.a{color:blue}']);
CP_Test::same(true, $res['success'], 'and its code can still be fixed');
CP_Test::same(1, $guard->updates, 'and that write lands too');

// But asking for the broken pair again IS refused, even on that same row —
// the exemption is for edits that never mention type or location, not for the
// row itself.
$guard->updates = 0;
$res = Claudpress_Snippets::save(['id' => 5, 'location' => 'everywhere']);
CP_Test::same(false, $res['success'], 'moving that same row to another dead location is still refused');
CP_Test::same(0, $guard->updates, 'and nothing is written when it is');

CP_Test::same('header', Claudpress_Snippets::default_location_for_type('css'), 'css defaults to header');
CP_Test::same('header', Claudpress_Snippets::default_location_for_type('js'), 'so does js');
CP_Test::same('everywhere', Claudpress_Snippets::default_location_for_type('php'), 'php still defaults to everywhere');

CP_Test::group('Snippets - an explicit id that matches nothing is an error');

// THE regression, found on this project's own site. resolve_target() returned
// null for a missing id and save() fell through to an INSERT, so asking to
// update snippet 14 created a brand-new snippet 17 and reported success. The
// only clue was a different id in a response nobody reads.
//
// It costs an agent far more than a person. An agent restoring from a bundle,
// or working from notes taken on a different site, names ids that do not exist
// here - and every one becomes a silent duplicate, several of them active.

$guard->existing = null;
$guard->inserts  = 0;
$guard->updates  = 0;

$res = Claudpress_Snippets::save(['id' => 14, 'name' => 'Anything', 'code' => 'echo 1;']);
CP_Test::same(false, $res['success'], 'updating an id that does not exist is refused, not turned into a create');
CP_Test::contains('14', (string) ($res['error'] ?? ''), 'and the error names the id that was asked for');
CP_Test::contains('Omit "id"', (string) ($res['error'] ?? ''), '...and says how to create one on purpose, so the refusal is not a dead end');
CP_Test::same(0, $guard->inserts, 'nothing was inserted');
CP_Test::same(0, $guard->updates, 'and nothing was updated');

// Creating is still one omitted key away. If this breaks, the fix above has
// cost more than the bug did.
$guard->inserts = 0;
$res = Claudpress_Snippets::save(['name' => 'Brand new', 'code' => 'echo 1;', 'snippet_type' => 'php']);
CP_Test::same(true, $res['success'], 'omitting id still creates, which is the whole escape hatch');
CP_Test::same(1, $guard->inserts, 'and it is really written');

// id 0 is "no id", not "id number zero". A caller passing a default must not
// be told a snippet is missing.
$guard->inserts = 0;
$res = Claudpress_Snippets::save(['id' => 0, 'name' => 'Zero', 'code' => 'echo 1;', 'snippet_type' => 'php']);
CP_Test::same(true, $res['success'], 'id 0 means "unset" and still creates');
CP_Test::same(1, $guard->inserts, 'and is written');

CP_Test::group('Snippets - uid keeps the create-if-missing fall-through');

// uid and origin_uid exist precisely to mean "this row if you have it, a new
// one if you do not". Cross-site copy depends on it, so the strictness above
// must stop at the numeric id and go no further.
$guard->existing = null;
$guard->inserts  = 0;
$res = Claudpress_Snippets::save(['uid' => 'cps_from_another01', 'name' => 'Copied', 'code' => 'echo 1;', 'snippet_type' => 'php']);
CP_Test::same(true, $res['success'], 'an unknown uid creates rather than erroring');
CP_Test::same(1, $guard->inserts, 'and is written, so copying a snippet between sites still works');

$guard->existing = null;
$guard->inserts  = 0;
$res = Claudpress_Snippets::save(['origin_uid' => 'cps_origin0123456', 'name' => 'Copied', 'code' => 'echo 1;', 'snippet_type' => 'php']);
CP_Test::same(true, $res['success'], 'so does an unknown origin_uid');
CP_Test::same(1, $guard->inserts, 'and it is written too');

CP_Test::group('Snippets - resolve_target, with a finder that can tell columns apart');

// The wpdb double above answers every get_var the same way, which cannot model
// "id 14 is gone but uid still matches row 5". resolve_target() takes its
// finder as an argument precisely so that case can be tested without a
// database.
$finder = static function (string $col, string $val): ?int {
    return ($col === 'uid' && $val === 'cps_known01234567') ? 5 : null;
};

CP_Test::same(null, Claudpress_Snippets::resolve_target(['id' => 14], $finder), 'a missing id resolves to nothing, which is what save() now refuses on');
CP_Test::same(5, Claudpress_Snippets::resolve_target(['uid' => 'cps_known01234567'], $finder), 'a known uid resolves');
CP_Test::same(5, Claudpress_Snippets::resolve_target(['id' => 14, 'uid' => 'cps_known01234567'], $finder), 'a dead id alongside a live uid resolves to the uid row - the copy case must not be caught by the new strictness');

$GLOBALS['wpdb'] = $real_wpdb;
