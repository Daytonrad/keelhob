<?php
/**
 * Bundled CSS and JS must get a NEW URL whenever the file changes.
 *
 * THE BUG THIS PINS, reported from a real browser on a live site.
 *
 * Every asset was registered with KEELHOB_VERSION. That only moves when the
 * RELEASE number moves, so editing a script without cutting a release leaves
 * its URL byte-identical — `studio-compose.js?ver=1.2.0` before the edit and
 * after it. Browsers reuse what they already hold under that URL.
 *
 * AI Compose was rewritten from a wizard into a chat inside a single version.
 * Anyone who had opened the old screen then got the NEW markup served with the
 * OLD cached script. That script looks for `#kh-compose-plan`, does not find
 * it, and returns without binding anything: Send did nothing, and Enter typed a
 * newline. No console error, nothing wrong in the page source, and nothing any
 * server-side test could see — every file on disk was correct.
 *
 * The screen was fine. The URL was the bug.
 */

KH_Test::group('Assets — the cache-busting version');

$js  = Keelhob::asset_version('assets/js/studio-compose.js');
$css = Keelhob::asset_version('assets/css/studio.css');

KH_Test::ok($js !== '', 'an asset version is produced');
KH_Test::contains(KEELHOB_VERSION, $js, 'it still carries the release version, so a support answer to "what are you running" is unchanged');

// The whole point: it must differ from the bare release string, or it buys
// nothing over what was there before.
KH_Test::ok($js !== KEELHOB_VERSION, 'but it is NOT just the release version');

// Two files edited at different times must not share a version, or changing one
// silently ships the other's cached copy.
KH_Test::ok($js !== $css || filemtime(KEELHOB_DIR . 'assets/js/studio-compose.js') === filemtime(KEELHOB_DIR . 'assets/css/studio.css'), 'two different files get their own versions unless they were written in the same second');

// Touching a file has to change its version. This is the entire contract.
$path   = KEELHOB_DIR . 'assets/js/studio-compose.js';
$was    = filemtime($path);
$before = Keelhob::asset_version('assets/js/studio-compose.js');
@touch($path, $was + 60);
clearstatcache(true, $path);
$after = Keelhob::asset_version('assets/js/studio-compose.js');
@touch($path, $was);          // put it back in the same step that asserted on it
clearstatcache(true, $path);

KH_Test::ok($before !== $after, 'changing a file changes its version — the one thing that was broken');
KH_Test::same($before, Keelhob::asset_version('assets/js/studio-compose.js'), 'and the file was restored, so this test leaves nothing behind');

// A hardened host can block filemtime(). Degrading to the release version is
// the old behaviour, which is imperfect; degrading to no version at all would
// be worse, because WordPress then appends its own core version and every
// install shares one URL forever.
KH_Test::same(KEELHOB_VERSION, Keelhob::asset_version('assets/js/there-is-no-such-file.js'), 'an unreadable file falls back to the release version rather than to nothing');

KH_Test::group('Assets — nothing is registered with the bare release version');

// Asserted from the SOURCE, because the failure is invisible at runtime: the
// page renders, the file is correct, and only a browser that visited the old
// build ever sees it. A new enqueue added later would reintroduce the bug in
// total silence, so the check is on the text of the files themselves.
foreach (['class-studio.php', 'class-studio-edit.php', 'class-studio-compose.php'] as $file) {
    $src = (string) file_get_contents(KEELHOB_DIR . 'includes/' . $file);

    // Grab each wp_enqueue_script/style call and look at its version argument.
    $bad = 0;
    if (preg_match_all('/wp_enqueue_(?:script|style)\s*\((.*?)\);/s', $src, $m)) {
        foreach ($m[1] as $call) {
            if (strpos($call, 'KEELHOB_VERSION') !== false && strpos($call, 'asset_version') === false) {
                $bad++;
            }
        }
    }
    KH_Test::same(0, $bad, $file . ' registers no asset with the bare release version');
}
