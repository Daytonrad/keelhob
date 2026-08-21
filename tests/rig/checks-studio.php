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

$GLOBALS['cp_rig_pass'] = 0;
$GLOBALS['cp_rig_fail'] = 0;
function check($label, $ok, $detail = '') {
    $ok ? $GLOBALS['cp_rig_pass']++ : $GLOBALS['cp_rig_fail']++;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', str_pad($label, 52), $detail);
}

add_filter('claudpress_pro_licence_override', static fn() => true, 99);
delete_transient(Claudpress_Pro_Licence::TRANSIENT);
wp_set_current_user(1);
set_current_screen('admin');

check('the Studio class loaded', class_exists('Claudpress_Pro_Studio'));

// Something to list.
$made = Claudpress_Snippets::save([
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
    $_GET = array_merge(['page' => Claudpress_Pro_Studio::SLUG, 'tab' => $tab], $extra);
    ob_start();
    (new Claudpress_Pro_Studio())->render();
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
    check('  all four tabs are linked from "' . $tab . '"', substr_count($out, 'nav-tab') >= 5);
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
check('the editor offers Ask AI', strpos($edit, 'cp-ask-ai') !== false);
check('the editor has both save buttons', strpos($edit, 'and_activate') !== false);
check('the code is escaped, not stripped', strpos($edit, '&lt;?php') !== false);

// --- compose ---
$compose = $render('compose');
check('compose renders with no key configured', strpos($compose, 'cp-compose-prompt') !== false);
check('...and says so rather than hiding', strpos($compose, 'No AI provider is connected') !== false || trim((string) Claudpress::instance()->get('agent_api_key')) !== '');

// --- library ---
$library = $render('library');
check('library shows both sub-tabs', strpos($library, 'Private library') !== false && strpos($library, 'Claudpress library') !== false);
$private = $render('library', ['lib' => 'private']);
check('an unlinked site is offered pairing, not an error', strpos($private, 'Link this site') !== false);
// The reassurance is the point of the whole flow: a device code exists so that
// nobody types their claudpress.com password into a WordPress install.
check('...and told their password stays out of WordPress', strpos($private, 'never typed into WordPress') !== false);
check('the pairing screen has no password field at all', strpos($private, 'type="password"') === false);

// --- the two verdicts, side by side ---
ob_start();
Claudpress_Pro_Studio_Edit::review_panel([
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
check('fences are stripped from a model reply', Claudpress_Pro_Studio_AI::strip_fences("```php\n<?php echo 1;\n```") === '<?php echo 1;');
check('JSON is found inside prose', (Claudpress_Pro_Studio_AI::json('Here you go: {"a":1} hope that helps') ?? [])['a'] === 1);
check('type comes from the code, not the label', Claudpress_Pro_Studio_AI::guess_type("<?php echo 1;") === 'php');
check('...css is recognised', Claudpress_Pro_Studio_AI::guess_type(".a { color: red; }") === 'css');

Claudpress_Snippets::delete($made_id);
$_GET = [];

printf("\n%d passed, %d failed\n", $GLOBALS['cp_rig_pass'], $GLOBALS['cp_rig_fail']);
if ($GLOBALS['cp_rig_fail'] > 0) {
    exit(1);
}
