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

KH_Test::group('Consent — the warning is actually on the screen');

// The consent block is part of the Connect screen, which is where it moved
// when the admin UI was split up.
$src = file_get_contents(KEELHOB_DIR . 'includes/class-admin-screens.php');

foreach ([
    'Before you switch this on'                => 'the block is headed as a warning, not a setting',
    'break pages, checkout, or the admin area' => 'it names the concrete damage, not a vague "at your own risk"',
    'sent to the AI provider you choose'       => 'it discloses that site data leaves the server',
    'cannot see or cap that spend'             => 'it warns that the bill is theirs and uncapped',
    'Set a spend limit on your provider'       => 'and tells them the one thing that actually protects them',
    'safeguards, not a guarantee'              => 'it does not oversell the safety loop',
    'for having a backup'                      => 'it says whose job the backup is',
    'at my own risk'                           => 'the checkbox label is an acceptance, not just an on switch',
] as $needle => $why) {
    KH_Test::contains($needle, $src, $why);
}

// A consent nobody can read is not a consent. If this is ever collapsed behind
// a "learn more" toggle, or moved below the box, that is a regression.
$block = strpos($src, 'Before you switch this on');
$box   = strpos($src, 'name="abilities_enabled"');
KH_Test::ok($block !== false && $box !== false && $block < $box, 'the warning renders ABOVE the checkbox, not after it');
KH_Test::ok(strpos(substr($src, $block, $box - $block), '<details') === false, 'the warning is not hidden inside a collapsed section');

KH_Test::group('Consent — it is recorded');

$from_post = new ReflectionMethod('Keelhob_Admin_Settings', 'consent_from_post');
@$from_post->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor

$GLOBALS['kh_test_current_user'] = 7;
kh_test_reset_options(['keelhob_settings' => []]);
$_POST = ['abilities_enabled' => '1'];
$c = $from_post->invoke(null);

KH_Test::ok($c['abilities_enabled'], 'ticking the box turns abilities on');
KH_Test::same(7, $c['consent_by'], 'it records WHO accepted');
KH_Test::ok($c['consent_at'] > 0, 'it records WHEN');
KH_Test::same(Keelhob_Admin::CONSENT_VERSION, $c['consent_version'], 'it records WHICH version of the terms');

KH_Test::group('Consent — switching off does not erase the record');

$accepted = [
    'abilities_enabled' => true,
    'consent_by'        => 7,
    'consent_at'        => 1000,
    'consent_version'   => Keelhob_Admin::CONSENT_VERSION,
];
kh_test_reset_options(['keelhob_settings' => $accepted]);
$_POST = [];
$c = $from_post->invoke(null);

KH_Test::ok(!$c['abilities_enabled'], 'unticking switches abilities off');
KH_Test::same(7, $c['consent_by'], 'but the record of who accepted survives — it is a historical fact');
KH_Test::same(1000, $c['consent_at'], '...and so does when');

KH_Test::group('Consent — re-saving does not fake a fresh acceptance');

kh_test_reset_options(['keelhob_settings' => $accepted]);
$_POST = ['abilities_enabled' => '1'];
$c = $from_post->invoke(null);
KH_Test::same(1000, $c['consent_at'], 'saving an unrelated setting leaves the original timestamp alone');

KH_Test::group('Consent — the terms can change');

$stale = $accepted;
$stale['consent_version'] = 0;
kh_test_reset_options(['keelhob_settings' => $stale]);
KH_Test::ok(Keelhob_Admin::consent_is_stale(), 'an acceptance of older terms is flagged stale');

$_POST = ['abilities_enabled' => '1'];
$GLOBALS['kh_test_current_user'] = 9;
$c = $from_post->invoke(null);
KH_Test::same(9, $c['consent_by'], 'accepting the new terms records the new acceptor');
KH_Test::ok($c['consent_at'] > 1000, '...with a fresh timestamp');

kh_test_reset_options(['keelhob_settings' => $accepted]);
KH_Test::ok(!Keelhob_Admin::consent_is_stale(), 'current terms are not stale');

kh_test_reset_options(['keelhob_settings' => ['abilities_enabled' => false, 'consent_version' => 0]]);
KH_Test::ok(!Keelhob_Admin::consent_is_stale(), 'a site with abilities off is not nagged about stale terms');

$_POST = [];
kh_test_reset_options();
