<?php
/**
 * The consent gate on "Turn on AI abilities".
 *
 * This was a bare checkbox with a one-line red warning. It is the single
 * riskiest control in the plugin — it is what lets a remote model write PHP,
 * change checkout and install plugins on a live site — and it read like a
 * preference.
 *
 * What the gate is and is not: it sets expectations, and it records who agreed
 * so the question is answerable later. It is not, on its own, a legal shield.
 * The liability position comes from the no-warranty terms; this is the half
 * that makes those terms honest.
 */

CP_Test::group('Consent — the warning is actually on the screen');

$src = file_get_contents(CLAUDPRESS_DIR . 'includes/class-admin.php');

foreach ([
    'Before you switch this on'                => 'the block is headed as a warning, not a setting',
    'break pages, checkout, or the admin area' => 'it names the concrete damage, not a vague "at your own risk"',
    'sent to the AI provider you choose'       => 'it discloses that site data leaves the server',
    'cannot see or cap that spend'             => 'it warns that the bill is theirs and uncapped',
    'safeguards, not a guarantee'              => 'it does not oversell the safety loop',
    'for having a backup'                      => 'it says whose job the backup is',
    'at my own risk'                           => 'the checkbox label is an acceptance, not just an on switch',
] as $needle => $why) {
    CP_Test::contains($needle, $src, $why);
}

// A consent nobody can read is not a consent. If this is ever collapsed behind
// a "learn more" toggle, or moved below the box, that is a regression.
$block = strpos($src, 'Before you switch this on');
$box   = strpos($src, 'name="abilities_enabled"');
CP_Test::ok($block !== false && $box !== false && $block < $box, 'the warning renders ABOVE the checkbox, not after it');
CP_Test::ok(strpos(substr($src, $block, $box - $block), '<details') === false, 'the warning is not hidden inside a collapsed section');

CP_Test::group('Consent — it is recorded');

$from_post = new ReflectionMethod('Claudpress_Admin', 'consent_from_post');

$GLOBALS['cp_test_current_user'] = 7;
cp_test_reset_options(['claudpress_settings' => []]);
$_POST = ['abilities_enabled' => '1'];
$c = $from_post->invoke(null);

CP_Test::ok($c['abilities_enabled'], 'ticking the box turns abilities on');
CP_Test::same(7, $c['consent_by'], 'it records WHO accepted');
CP_Test::ok($c['consent_at'] > 0, 'it records WHEN');
CP_Test::same(Claudpress_Admin::CONSENT_VERSION, $c['consent_version'], 'it records WHICH version of the terms');

CP_Test::group('Consent — switching off does not erase the record');

$accepted = [
    'abilities_enabled' => true,
    'consent_by'        => 7,
    'consent_at'        => 1000,
    'consent_version'   => Claudpress_Admin::CONSENT_VERSION,
];
cp_test_reset_options(['claudpress_settings' => $accepted]);
$_POST = [];
$c = $from_post->invoke(null);

CP_Test::ok(!$c['abilities_enabled'], 'unticking switches abilities off');
CP_Test::same(7, $c['consent_by'], 'but the record of who accepted survives — it is a historical fact');
CP_Test::same(1000, $c['consent_at'], '...and so does when');

CP_Test::group('Consent — re-saving does not fake a fresh acceptance');

cp_test_reset_options(['claudpress_settings' => $accepted]);
$_POST = ['abilities_enabled' => '1'];
$c = $from_post->invoke(null);
CP_Test::same(1000, $c['consent_at'], 'saving an unrelated setting leaves the original timestamp alone');

CP_Test::group('Consent — the terms can change');

$stale = $accepted;
$stale['consent_version'] = 0;
cp_test_reset_options(['claudpress_settings' => $stale]);
CP_Test::ok(Claudpress_Admin::consent_is_stale(), 'an acceptance of older terms is flagged stale');

$_POST = ['abilities_enabled' => '1'];
$GLOBALS['cp_test_current_user'] = 9;
$c = $from_post->invoke(null);
CP_Test::same(9, $c['consent_by'], 'accepting the new terms records the new acceptor');
CP_Test::ok($c['consent_at'] > 1000, '...with a fresh timestamp');

cp_test_reset_options(['claudpress_settings' => $accepted]);
CP_Test::ok(!Claudpress_Admin::consent_is_stale(), 'current terms are not stale');

cp_test_reset_options(['claudpress_settings' => ['abilities_enabled' => false, 'consent_version' => 0]]);
CP_Test::ok(!Claudpress_Admin::consent_is_stale(), 'a site with abilities off is not nagged about stale terms');

$_POST = [];
cp_test_reset_options();
