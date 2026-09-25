<?php
/**
 * Live check of the Snippets Studio, with both plugins active.
 *
 * Rendering an admin screen is exactly the kind of thing unit stubs cannot
 * honestly answer: it needs real WordPress, a real current user, real
 * enqueueing, and a real database behind the list.
 *
 * Run: wp eval-file tests/rig/checks-studio.php
 */

$GLOBALS['kh_rig_pass'] = 0;
$GLOBALS['kh_rig_fail'] = 0;
function check($label, $ok, $detail = '') {
    $ok ? $GLOBALS['kh_rig_pass']++ : $GLOBALS['kh_rig_fail']++;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', str_pad($label, 52), $detail);
}

wp_set_current_user(1);
set_current_screen('admin');

check('the Studio class loaded', class_exists('Keelhob_Studio'));

// Something to list.
$made = Keelhob_Snippets::save(['purpose' => 'rig fixture', 'impact' => 'none, removed at the end of the run',
    'name'         => 'Studio rig snippet',
    'description'  => 'Created by the rig check.',
    'code'         => "<?php add_filter('the_content', fn(\$c) => \$c);",
    'snippet_type' => 'php',
    'source'       => 'ai',
    'tags'         => 'rig, content',
]);
$made_id = (int) ($made['id'] ?? 0);
check('a snippet exists to list', $made_id > 0);

$render = static function (string $tab, array $extra = []): string {
    $_GET = array_merge(['page' => Keelhob_Studio::SLUG, 'tab' => $tab], $extra);
    ob_start();
    (new Keelhob_Studio())->render();
    return (string) ob_get_clean();
};

foreach (['all', 'edit', 'compose', 'library'] as $tab) {
    $out = '';
    $err = '';
    try {
        $out = $render($tab);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    check('tab "' . $tab . '" renders', $err === '' && strlen($out) > 400, $err ?: strlen($out) . ' bytes');
    check('  no notice leaked into "' . $tab . '"', stripos($out, 'Warning:') === false && stripos($out, 'Fatal') === false);
    // Three tabs now, not four: the Library tab was deleted in 1.2.0 because it
    // could never appear. Counted from Keelhob_Studio::tabs() rather than
    // hard-coded, so adding a real tab does not need this line edited - the
    // previous version said "four" long after there were three.
    check(
        '  every tab is linked from "' . $tab . '"',
        substr_count($out, 'nav-tab ') >= count(Keelhob_Studio::tabs()),
        count(Keelhob_Studio::tabs()) . ' expected, ' . substr_count($out, 'nav-tab ') . ' found'
    );
}

// --- the list ---
$all = $render('all');
check('the list shows the snippet', strpos($all, 'Studio rig snippet') !== false);
check('...with its AI-generated badge', strpos($all, 'AI-generated') !== false);
check('...and its tags', strpos($all, 'rig') !== false);

// State changes must be POSTs. A GET link that switches code on would be fired
// by anything that prefetches a page.
check('switching on is a POST, not a link', strpos($all, 'value="activate"') !== false);
check('...and carries a nonce', substr_count($all, '_wpnonce') >= 1);
check('no GET link performs an action', strpos($all, 'studio_action=activate') === false);

// --- the editor ---
$edit = $render('edit', ['snippet' => $made_id]);
check('the editor loads the snippet', strpos($edit, 'Studio rig snippet') !== false);
check('the editor offers Ask AI', strpos($edit, 'kh-ask-ai') !== false);
check('the editor has both save buttons', strpos($edit, 'and_activate') !== false);
check('the code is escaped, not stripped', strpos($edit, '&lt;?php') !== false);

// --- compose ---
$compose = $render('compose');
// Compose is a CHAT now, not a three-step wizard. The old id was
// kh-compose-prompt, a single textarea that fed a one-shot plan request; it had
// no memory of its own last answer, which is what made a syntax error a dead
// end and made a prose reply look like a failure.
check('compose renders the transcript', strpos($compose, 'id="kh-chat"') !== false);
check('compose renders with no key configured', strpos($compose, 'kh-chat-input') !== false);
check('...and a Send button next to it', strpos($compose, 'kh-chat-send') !== false);
check('...and says so rather than hiding', strpos($compose, 'No AI provider is connected') !== false || trim((string) Keelhob::instance()->get('agent_api_key')) !== '');

// --- library ---
// The Library tab is GONE, not hidden.
//
// It used to be shown only when Keelhob_Studio_Library::has_items() said so,
// and that could never be true: has_items() asked Keelhob_Snippet_Library,
// a class nobody ever wrote. An unreachable screen shipped in every zip.
//
// This asserts the two things that matter after deleting it: the tab is not
// offered, and an old ?tab=library bookmark still lands somewhere useful rather
// than erroring - current_tab() falls back to the list for anything it does not
// recognise.
$library = $render('library');
check('the Library tab is not offered', !array_key_exists('library', Keelhob_Studio::tabs()));
check('...and Keelhob_Studio_Library is gone from the build', !class_exists('Keelhob_Studio_Library'));
check('...and an old ?tab=library link falls back to the list, not an error',
    strpos($library, 'Studio rig snippet') !== false && stripos($library, 'Fatal') === false);

// Stored data is NOT UI. Sites that used the old private library still have
// rows whose source is 'library', and the badge that renders them must survive
// the removal of the screen that once created them.
check('a snippet whose source is "library" still renders its badge',
    strpos(Keelhob_Studio::source_badge('library'), 'Library') !== false);
check('...and "library" is still a valid stored source',
    in_array('library', Keelhob_Snippets::SOURCES, true));

// The pairing flow made this promise in words. It is gone; the promise is not.
check('no Studio screen asks for a password',
    strpos($all . $edit . $compose . $library, 'type="password"') === false);

// --- the two verdicts, side by side ---
ob_start();
Keelhob_Studio_Edit::review_panel([
    'ai_review'    => wp_json_encode(['verdict' => 'caution', 'summary' => 'Test summary.', 'risks' => ['One risk']]),
    'ai_review_at' => current_time('mysql'),
    'last_error'   => '',
]);
$panel = (string) ob_get_clean();

check('the review panel shows the parse result', strpos($panel, 'Syntax check') !== false);
check('...and the AI opinion separately', strpos($panel, 'AI assessment') !== false);
check('...and says which one decides', strpos($panel, 'decides whether the snippet may run') !== false);
check('...and that the opinion does not', strpos($panel, 'does not gate anything') !== false);

// --- the AI helpers ---
check('fences are stripped from a model reply', Keelhob_Studio_AI::strip_fences("```php\n<?php echo 1;\n```") === '<?php echo 1;');
check('JSON is found inside prose', (Keelhob_Studio_AI::json('Here you go: {"a":1} hope that helps') ?? [])['a'] === 1);
check('type comes from the code, not the label', Keelhob_Studio_AI::guess_type("<?php echo 1;") === 'php');
check('...css is recognised', Keelhob_Studio_AI::guess_type(".a { color: red; }") === 'css');

Keelhob_Snippets::delete($made_id);
$_GET = [];

printf("\n%d passed, %d failed\n", $GLOBALS['kh_rig_pass'], $GLOBALS['kh_rig_fail']);
if ($GLOBALS['kh_rig_fail'] > 0) {
    exit(1);
}
