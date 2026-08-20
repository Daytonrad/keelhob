<?php
/**
 * Saving notification channels — the disabled-checkbox trap.
 *
 * This is the one bug in the whole notifications feature worth writing a test
 * for, because it is invisible at the moment it happens and only surfaces days
 * later as "my digests stopped and I don't know why".
 *
 * A disabled checkbox submits nothing. So if the stored preference is read
 * straight out of $_POST, a channel whose credentials went missing — the very
 * reason its box is disabled — looks exactly like a channel the owner
 * deliberately unticked. It gets dropped, and reconnecting Telegram does NOT
 * bring the digests back, with nothing on screen to explain it.
 *
 * The fix: trust the post only for channels the owner could actually have
 * clicked. Carry the rest through untouched.
 */

CP_Test::group('Notify channels — a connected channel obeys the form');

$save = new ReflectionMethod('Claudpress_Admin', 'channels_from_post');

$connected = [
    'admin_email'          => 'owner@example.test',
    'claudpress_settings'  => [
        'notify_channels'       => ['email', 'telegram'],
        'notify_telegram_token' => '123:ABC',
        'notify_telegram_chat'  => '999',
    ],
];

cp_test_reset_options($connected);
$_POST = ['notify_channels' => ['telegram']];
CP_Test::same(['telegram'], $save->invoke(null), 'unticking a connected channel removes it');

cp_test_reset_options($connected);
$_POST = ['notify_channels' => ['email', 'telegram']];
CP_Test::same(['email', 'telegram'], $save->invoke(null), 'leaving both ticked keeps both');

cp_test_reset_options($connected);
$_POST = [];
CP_Test::same([], $save->invoke(null), 'unticking everything is honoured — it is a real choice');

CP_Test::group('Notify channels — a disconnected channel keeps its tick');

// Telegram was chosen, then the token went missing. Its checkbox is now
// disabled, so it cannot appear in the post no matter what the owner does.
$token_lost = [
    'admin_email'         => 'owner@example.test',
    'claudpress_settings' => [
        'notify_channels'       => ['email', 'telegram'],
        'notify_telegram_token' => '',
        'notify_telegram_chat'  => '999',
    ],
];

cp_test_reset_options($token_lost);
$_POST = ['notify_channels' => ['email']];
$after = $save->invoke(null);

CP_Test::ok(in_array('telegram', $after, true), 'THE BUG: a disconnected channel is NOT silently unticked by saving the form');
CP_Test::ok(in_array('email', $after, true), 'the connected channel is still honoured normally');

// And now the whole point: reconnect, and it resumes with no further clicks.
$reconnected                                              = $token_lost;
$reconnected['claudpress_settings']['notify_telegram_token'] = '123:ABC';
cp_test_reset_options($reconnected);
CP_Test::ok(
    in_array('telegram', Claudpress_Notifier::effective_channels(), true),
    'reconnecting resumes delivery without the owner having to re-tick anything'
);

CP_Test::group('Notify channels — saving an unrelated setting is harmless');

// The commonest real-world path: someone changes the filesystem base and hits
// Save. Nothing about notifications was touched, and nothing should change.
cp_test_reset_options($token_lost);
$_POST = ['fs_base' => '/var/www/html'];
$after = $save->invoke(null);

CP_Test::ok(in_array('telegram', $after, true), 'the disconnected channel survives an unrelated save');
CP_Test::ok(!in_array('email', $after, true), 'and a connected channel absent from the post is treated as unticked');

CP_Test::group('Notify channels — junk in the post is ignored');

cp_test_reset_options($connected);
$_POST = ['notify_channels' => ['telegram', 'carrier_pigeon', '<script>']];
CP_Test::same(['telegram'], $save->invoke(null), 'an unknown channel name never reaches storage');

$_POST = [];
cp_test_reset_options();
