<?php
/**
 * Notification channels - the preference/connectivity split.
 *
 * The bug this guards against is subtle and permanent once it happens: if the
 * owner's channel choice were derived from the form, a disabled checkbox would
 * post nothing, so disconnecting Telegram would silently erase the choice - and
 * reconnecting would not bring it back. Preference and connectivity have to be
 * two separate values that meet only at send time.
 */

KH_Test::group('Notifier - connectivity');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => []]);
$a = Keelhob_Notifier::availability();
KH_Test::ok($a['email']['connected'], 'email falls back to the site admin address, so it is connected out of the box');
KH_Test::ok(!$a['telegram']['connected'], 'telegram is not connected with no token');
KH_Test::ok(!$a['webhook']['connected'], 'webhook is not connected with no URL');
KH_Test::ok($a['telegram']['reason'] !== '', 'an unconnected channel explains why, so the checkbox is never just greyed out');
KH_Test::same('', $a['email']['reason'], 'a connected channel gives no reason');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => ['notify_telegram_token' => '123:ABC']]);
$a = Keelhob_Notifier::availability();
KH_Test::ok(!$a['telegram']['connected'], 'a token without a chat ID is still not connected');
KH_Test::contains('chat ID', $a['telegram']['reason'], '...and the reason says which half is missing');

KH_Test::group('Notifier - the stored preference');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => []]);
KH_Test::same(Keelhob_Notifier::CHANNELS, Keelhob_Notifier::chosen_channels(), 'never configured means every channel, so upgrading does not silently stop digests');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => ['notify_channels' => ['telegram']]]);
KH_Test::same(['telegram'], Keelhob_Notifier::chosen_channels(), 'an explicit choice is honoured');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => ['notify_channels' => []]]);
KH_Test::same([], Keelhob_Notifier::chosen_channels(), 'choosing nothing is a real choice, not "unset"');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => ['notify_channels' => ['telegram', 'carrier_pigeon']]]);
KH_Test::same(['telegram'], Keelhob_Notifier::chosen_channels(), 'an unknown channel in stored data is ignored rather than fatal');

KH_Test::group('Notifier - preference survives a disconnection');

// THE regression. Telegram is chosen, then loses its token, then gets it back.
$with_tg = ['notify_channels' => ['telegram'], 'notify_telegram_token' => '123:ABC', 'notify_telegram_chat' => '999'];
kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => $with_tg]);
KH_Test::same(['telegram'], Keelhob_Notifier::effective_channels(), 'chosen and connected: it delivers');

$broken = $with_tg;
$broken['notify_telegram_token'] = '';
kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => $broken]);
KH_Test::same([], Keelhob_Notifier::effective_channels(), 'disconnected: nothing is delivered');
KH_Test::same(['telegram'], Keelhob_Notifier::chosen_channels(), '...but the CHOICE is untouched');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => $with_tg]);
KH_Test::same(['telegram'], Keelhob_Notifier::effective_channels(), 'reconnecting restores delivery without the owner re-ticking anything');

KH_Test::group('Notifier - effective is chosen AND connected');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => [
    'notify_channels'        => ['email', 'telegram', 'webhook'],
    'notify_telegram_token'  => '123:ABC',
    'notify_telegram_chat'   => '999',
]]);
KH_Test::same(['email', 'telegram'], Keelhob_Notifier::effective_channels(), 'the unconfigured webhook is dropped, the rest deliver');

kh_test_reset_options(['admin_email' => 'owner@example.test', 'keelhob_settings' => ['notify_channels' => []]]);
KH_Test::same([], Keelhob_Notifier::effective_channels(), 'with nothing chosen the digest goes nowhere, and the screen must say so');

kh_test_reset_options();
