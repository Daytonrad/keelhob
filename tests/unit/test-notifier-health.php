<?php
/**
 * Channel health - the difference between "configured" and "arrives".
 *
 * The bug: availability() reported email as connected on a host where wp_mail()
 * fails every single time, because wp_mail() exists on every host. The owner
 * read a tick, believed alerts worked, and heard nothing when the site went
 * down. A silent notification channel is worse than an absent one, because only
 * the second kind tells you to arrange something else.
 *
 * The two design decisions this file pins down, both of which are easy to
 * "tidy" into bugs later:
 *
 *   1. A FAILING CHANNEL IS STILL CONNECTED, and is still attempted. Only a
 *      successful send can clear a failure, so a channel that stopped being
 *      tried could never recover.
 *   2. HEALTH COMES FROM REAL SENDS ONLY. Nothing here probes, and no test
 *      email is sent behind the owner's back.
 */

KH_Test::group('Notifier health - the three states of a configured channel');

$settings = [
    'notify_channels'        => ['email', 'telegram'],
    'notify_telegram_token'  => '123:ABC',
    'notify_telegram_chat'   => '999',
];

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => $settings]);
Keelhob_Notifier::reset_health();

$a = Keelhob_Notifier::availability();
KH_Test::same('untested', $a['email']['state'], 'a configured channel that has never been used is "untested", not "working"');
KH_Test::same('', $a['email']['warning'], 'untested is not a warning - nothing is wrong yet');
KH_Test::same('unconfigured', $a['webhook']['state'], 'a channel with no URL is unconfigured, which is a different thing again');
KH_Test::ok($a['email']['connected'], 'untested is still connected, so it will be tried');

Keelhob_Notifier::record_outcome('email', true);
$a = Keelhob_Notifier::availability();
KH_Test::same('working', $a['email']['state'], 'one successful send makes it "working"');
KH_Test::ok($a['email']['last_ok'], 'and the outcome is readable');
KH_Test::ok($a['email']['last_at'] > 0, 'and stamped with when');

Keelhob_Notifier::record_outcome('email', false, 'Could not instantiate mail function.');
$a = Keelhob_Notifier::availability();
KH_Test::same('failing', $a['email']['state'], 'a failure after a success moves it to "failing"');
KH_Test::contains('Could not instantiate mail function', $a['email']['warning'], 'the host\'s own words are kept - "it did not work" is the least useful half of the diagnosis');

KH_Test::group('Notifier health - a failing channel is still attempted');

// THE regression. If a failure switched 'connected' off, send() would stop
// trying, and only a send can clear a failure. The channel would be dead for
// ever with no way back short of editing the database.
KH_Test::ok($a['email']['connected'], 'a failing channel stays CONNECTED so it is retried');
KH_Test::ok(in_array('email', Keelhob_Notifier::effective_channels(), true), '...and stays in effective_channels(), which answers "what would be attempted"');
KH_Test::ok(!in_array('email', Keelhob_Notifier::healthy_channels(), true), '...but drops out of healthy_channels(), which answers "what can be relied on"');

Keelhob_Notifier::record_outcome('email', true);
$a = Keelhob_Notifier::availability();
KH_Test::same('working', $a['email']['state'], 'one success heals it - no reset button, no support ticket');
KH_Test::same('', $a['email']['warning'], 'and the stale error is cleared, so a fixed channel stops looking broken');

KH_Test::group('Notifier health - what counts as healthy');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => $settings]);
Keelhob_Notifier::reset_health();
KH_Test::same(['email', 'telegram'], Keelhob_Notifier::healthy_channels(), 'never-tried channels count as healthy: absence of evidence is not evidence of failure');

Keelhob_Notifier::record_outcome('telegram', false, 'chat not found');
KH_Test::same(['email'], Keelhob_Notifier::healthy_channels(), 'a channel with a recorded failure does not');

KH_Test::group('Notifier health - only real channels, and bounded errors');

Keelhob_Notifier::reset_health();
Keelhob_Notifier::record_outcome('carrier_pigeon', false, 'hawk');
KH_Test::same([], Keelhob_Notifier::health(), 'an unknown channel is ignored rather than written, so the option cannot be used as scratch storage');

Keelhob_Notifier::record_outcome('webhook', false, '<b>' . str_repeat('x', 5000) . '</b>');
$stored = Keelhob_Notifier::health();
KH_Test::ok(strlen($stored['webhook']['error']) <= 200, 'a webhook that answers with a huge HTML error page cannot put it all in an option read on every settings render');
KH_Test::ok(strpos($stored['webhook']['error'], '<b>') === false, 'and the markup is stripped, because this string is printed on an admin screen');

KH_Test::group('Notifier - send() records what happened');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => ['notify_channels' => ['email']]]);
Keelhob_Notifier::reset_health();
$GLOBALS['kh_test_mail']       = [];
$GLOBALS['kh_test_mail_fails'] = false;

$res = Keelhob_Notifier::send('Subject', 'Body');
KH_Test::ok(!empty($res['email']), 'a working send reports true');
KH_Test::same('working', Keelhob_Notifier::availability()['email']['state'], '...and is remembered as working');

$GLOBALS['kh_test_mail_fails'] = true;
$res = Keelhob_Notifier::send('Subject', 'Body');
KH_Test::ok(empty($res['email']), 'a failing send reports false');
KH_Test::same('failing', Keelhob_Notifier::availability()['email']['state'], '...and is remembered as failing, which is the whole point: nobody has to notice the silence');
$GLOBALS['kh_test_mail_fails'] = false;

KH_Test::group('Notifier - the one-off test address');

// The Automation screen's "send test" button lives in its own <form>. Typing an
// address into the settings form above it and pressing the button posted
// nothing, so the test always went to the saved address - and then said it had
// been sent. That is how a test can pass on a channel the owner never sees.
kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => ['notify_channels' => ['email'], 'notify_email' => 'stored@example.test']]);
Keelhob_Notifier::reset_health();

$GLOBALS['kh_test_mail'] = [];
Keelhob_Notifier::send('Subject', 'Body');
KH_Test::same('stored@example.test', $GLOBALS['kh_test_mail'][0]['to'], 'with no override, the stored notification address is used');

$GLOBALS['kh_test_mail'] = [];
Keelhob_Notifier::send('Subject', 'Body', null, null, 'somewhere-else@example.test');
KH_Test::same('somewhere-else@example.test', $GLOBALS['kh_test_mail'][0]['to'], 'an override sends this one message somewhere else');
KH_Test::same('stored@example.test', Keelhob::instance()->settings()['notify_email'], '...and does not change the stored address, because a test is not a setting');

$GLOBALS['kh_test_mail'] = [];
Keelhob_Notifier::send('Subject', 'Body', null, null, '   ');
KH_Test::same('stored@example.test', $GLOBALS['kh_test_mail'][0]['to'], 'a blank override falls back rather than mailing nowhere');

KH_Test::group('Notifier health - HTTP grading');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => [
    'notify_channels'    => ['webhook'],
    'notify_webhook_url' => 'https://hooks.example.test/abc',
]]);
Keelhob_Notifier::reset_health();

kh_test_http_queue(404, ['description' => 'chat not found']);
Keelhob_Notifier::send('Subject', 'Body');
$a = Keelhob_Notifier::availability();
KH_Test::same('failing', $a['webhook']['state'], 'a 404 is a failure, not a delivery');
KH_Test::contains('chat not found', $a['webhook']['warning'], 'and the server\'s own description is kept, because "HTTP 404" alone fixes nothing');

kh_test_http_queue(302, '');
Keelhob_Notifier::send('Subject', 'Body');
KH_Test::same('failing', Keelhob_Notifier::availability()['webhook']['state'], 'a redirect is not a delivery either - this is how a dead Slack URL keeps looking healthy');

kh_test_http_queue(200, '{}');
Keelhob_Notifier::send('Subject', 'Body');
KH_Test::same('working', Keelhob_Notifier::availability()['webhook']['state'], 'and a 200 heals it again');

$GLOBALS['kh_test_http'] = [];
