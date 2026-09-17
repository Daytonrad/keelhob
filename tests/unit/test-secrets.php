<?php
/**
 * S2 - credentials encrypted at rest and never echoed back into the page.
 *
 * Keelhob stores other people's API keys. Before 1.10.1 every one sat in
 * wp_options in clear text and was printed into the admin page source on every
 * render, where admin-side XSS, a browser extension, a screen share or plain
 * view-source would pick it up.
 */

KH_Test::group('Secrets - encryption at rest');

KH_Test::ok(Keelhob_Secrets::available(), 'AES-256-GCM is available');

$key = 'sk-ant-api03-REAL-LOOKING-KEY-abcdef123456';
$enc = Keelhob_Secrets::encrypt($key);

KH_Test::ok($enc !== $key, 'the stored value is not the plaintext');
KH_Test::ok(strpos($enc, $key) === false, 'the plaintext does not appear inside the ciphertext');
KH_Test::ok(strpos($enc, 'sk-ant') === false, 'not even a recognisable prefix leaks');
KH_Test::ok(strncmp($enc, Keelhob_Secrets::PREFIX, 9) === 0, 'the value carries a version prefix so the format can change later');
KH_Test::same($key, Keelhob_Secrets::decrypt($enc), 'it round-trips exactly');
KH_Test::ok(Keelhob_Secrets::encrypt($key) !== $enc, 'the same key encrypts differently each time, so two sites sharing a key is not visible');
KH_Test::same('', Keelhob_Secrets::decrypt(substr($enc, 0, -6) . 'AAAAAA'), 'a tampered value fails closed rather than returning garbage');
KH_Test::same($key, Keelhob_Secrets::decrypt($key), 'a plaintext value stored before the upgrade is still readable');
KH_Test::same('', Keelhob_Secrets::encrypt(''), 'an empty value stays empty');
KH_Test::same($enc, Keelhob_Secrets::encrypt($enc), 'encrypting twice is a no-op');

KH_Test::group('Secrets - the mask shown in wp-admin');

$mask = Keelhob_Secrets::mask($key);
KH_Test::ok(strpos($mask, 'sk-ant') === false, 'the mask hides the key');
KH_Test::same(substr($key, -4), substr($mask, -4), 'the last four characters show, so several keys stay distinguishable');
KH_Test::same('', Keelhob_Secrets::mask(''), 'an empty key masks to nothing, not to dots');
KH_Test::ok(strpos(Keelhob_Secrets::mask('abc'), 'abc') === false, 'a short key is hidden completely');
KH_Test::ok(Keelhob_Secrets::is_mask($mask), 'a posted mask is recognised as unchanged');
KH_Test::ok(!Keelhob_Secrets::is_mask($key), 'a real key is not mistaken for a mask');
KH_Test::ok(!Keelhob_Secrets::is_mask(''), 'an empty field means "clear it", not "unchanged"');

KH_Test::group('Secrets - settings round-trip');

kh_test_reset_options();
Keelhob::instance()->update(['agent_api_key' => $key]);
$raw = get_option('keelhob_settings');
KH_Test::ok(strpos(json_encode($raw), $key) === false, 'the key is not in the options row in clear text');
KH_Test::same($key, Keelhob::instance()->get('agent_api_key'), 'callers still read a plain value, unaware of the encryption');
KH_Test::same(false, $GLOBALS['kh_test_autoload']['keelhob_settings'] ?? null, 'the settings option does not autoload on every request');

Keelhob::instance()->update(['site_label' => 'My Shop']);
KH_Test::same($key, Keelhob::instance()->get('agent_api_key'), 'saving an unrelated setting does not corrupt the stored key');

KH_Test::group('Secrets - the admin template');

// Every file the admin UI was split into: a raw echo of a secret is just as
// bad on one screen as on another.
$admin = '';
foreach (['class-admin.php', 'class-admin-screens.php', 'class-admin-automation.php', 'class-admin-providers.php', 'class-admin-settings.php'] as $kh_admin_file) {
    $admin .= (string) file_get_contents(KEELHOB_DIR . 'includes/' . $kh_admin_file);
}
foreach (['agent_api_key', 'image_api_key', 'voice_api_key', 'notify_telegram_token', 'deploy_target_token'] as $field) {
    $raw_echo = 'value="<?php echo esc_attr($s[' . chr(39) . $field . chr(39) . '] ?? ' . chr(39) . chr(39) . '); ?>"';
    KH_Test::ok(strpos($admin, $raw_echo) === false, $field . ' is never echoed into the page');
}
