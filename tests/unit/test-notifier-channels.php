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

CP_Test::group('Notifier - connectivity');

cp_test_reset_options(['admin_email' => 'owner@example.test', 'claudpress_settings' => []]);
$a = Claudpress_Notifier::availability();
CP_Test::ok($a['email']['connected'], 'email falls back to the site admin address, so it is connected out of the box');
CP_Test::ok(!$a['telegram']['connected'], 'telegram is not connected with no token');
CP_Test::ok(!$a['webhook']['connected'], 'webhook is not connected with no URL');
CP_Test::ok($a['telegram']['reason'] !== '', 'an unconnected channel explains why, so the checkbox is never just greyed out');
CP_Test::same('', $a['email']['reason'], 'a connected channel gives no reason');

cp_test_reset_options(['admin_email' => 'owner@example.test', 'claudpress_settings' => ['notify_telegram_token' => '123:ABC']]);
$a = Claudpress_Notifier::availability();
CP_Test::ok(!$a['telegram']['connected'], 'a token without a chat ID is still not connected');
CP_Test::contains('chat ID', $a['telegram']['reason'], '...and the reason says which half is missing');

CP_Test::group('Notifier - the stored preference');

cp_test_reset_options(['admin_email' => 'owner@example.test', 'claudpress_settings' => []]);
CP_Test::same(Claudpress_Notifier::CHANNELS, Claudpress_Notifier::chosen_channels(), 'never configured means every channel, so upgrading does not silently stop digests');

cp_test_reset_options(['admin_email' => 'owner@example.test', 'claudpress_settings' => ['notify_channels' => ['telegram']]]);
CP_Test::same(['telegram'], Claudpress_Notifier::chosen_channels(), 'an explicit choice is honoured');

cp_test_reset_options(['admin_email' => 'owner@example.test', 'claudpress_settings' => ['notify_channels' => []]]);
CP_Test::same([], Claudpress_Notifier::chosen_channels(), 'choosing nothing is a real choice, not "unset"');

cp_test_reset_options(['admin_email' => 'owner@example.test', 'claudpress_settings' => ['notify_channels' => ['telegram', 'carrier_pigeon']]]);
CP_Test::same(['telegram'], Claudpress_Notifier::chosen_channels(), 'an unknown channel in stored data is ignored rather than fatal');

CP_Test::group('Notifier - preference survives a disconnection');

// THE regression. Telegram is chosen, then loses its token, then gets it back.
$with_tg = ['notify_channels' => ['telegram'], 'notify_telegram_token' => '123:ABC', 'notify_telegram_chat' => '999'];
cp_test_reset_options(['admin_email' => 'owner@example.test', 'claudpress_settings' => $with_tg]);
CP_Test::same(['telegram'], Claudpress_Notifier::effective_channels(), 'chosen and connected: it delivers');

$broken = $with_tg;
$broken['notify_telegram_token'] = '';
cp_test_reset_options(['admin_email' => 'owner@example.test', 'claudpress_settings' => $broken]);
CP_Test::same([], Claudpress_Notifier::effective_channels(), 'disconnected: nothing is delivered');
CP_Test::same(['telegram'], Claudpress_Notifier::chosen_channels(), '...but the CHOICE is untouched');

cp_test_reset_options(['admin_email' => 'owner@example.test', 'claudpress_settings' => $with_tg]);
CP_Test::same(['telegram'], Claudpress_Notifier::effective_channels(), 'reconnecting restores delivery without the owner re-ticking anything');

CP_Test::group('Notifier - effective is chosen AND connected');

cp_test_reset_options(['admin_email' => 'owner@example.test', 'claudpress_settings' => [
    'notify_channels'        => ['email', 'telegram', 'webhook'],
    'notify_telegram_token'  => '123:ABC',
    'notify_telegram_chat'   => '999',
]]);
CP_Test::same(['email', 'telegram'], Claudpress_Notifier::effective_channels(), 'the unconfigured webhook is dropped, the rest deliver');

cp_test_reset_options(['admin_email' => 'owner@example.test', 'claudpress_settings' => ['notify_channels' => []]]);
CP_Test::same([], Claudpress_Notifier::effective_channels(), 'with nothing chosen the digest goes nowhere, and the screen must say so');

cp_test_reset_options();
