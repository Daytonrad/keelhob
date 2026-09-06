<?php
/**
 * S2 - credentials encrypted at rest and never echoed back into the page.
 *
 * Claudpress stores other people's API keys. Before 1.10.1 every one sat in
 * wp_options in clear text and was printed into the admin page source on every
 * render, where admin-side XSS, a browser extension, a screen share or plain
 * view-source would pick it up.
 */

CP_Test::group('Secrets - encryption at rest');

CP_Test::ok(Claudpress_Secrets::available(), 'AES-256-GCM is available');

$key = 'sk-ant-api03-REAL-LOOKING-KEY-abcdef123456';
$enc = Claudpress_Secrets::encrypt($key);

CP_Test::ok($enc !== $key, 'the stored value is not the plaintext');
CP_Test::ok(strpos($enc, $key) === false, 'the plaintext does not appear inside the ciphertext');
CP_Test::ok(strpos($enc, 'sk-ant') === false, 'not even a recognisable prefix leaks');
CP_Test::ok(strncmp($enc, Claudpress_Secrets::PREFIX, 9) === 0, 'the value carries a version prefix so the format can change later');
CP_Test::same($key, Claudpress_Secrets::decrypt($enc), 'it round-trips exactly');
CP_Test::ok(Claudpress_Secrets::encrypt($key) !== $enc, 'the same key encrypts differently each time, so two sites sharing a key is not visible');
CP_Test::same('', Claudpress_Secrets::decrypt(substr($enc, 0, -6) . 'AAAAAA'), 'a tampered value fails closed rather than returning garbage');
CP_Test::same($key, Claudpress_Secrets::decrypt($key), 'a plaintext value stored before the upgrade is still readable');
CP_Test::same('', Claudpress_Secrets::encrypt(''), 'an empty value stays empty');
CP_Test::same($enc, Claudpress_Secrets::encrypt($enc), 'encrypting twice is a no-op');

CP_Test::group('Secrets - the mask shown in wp-admin');

$mask = Claudpress_Secrets::mask($key);
CP_Test::ok(strpos($mask, 'sk-ant') === false, 'the mask hides the key');
CP_Test::same(substr($key, -4), substr($mask, -4), 'the last four characters show, so several keys stay distinguishable');
CP_Test::same('', Claudpress_Secrets::mask(''), 'an empty key masks to nothing, not to dots');
CP_Test::ok(strpos(Claudpress_Secrets::mask('abc'), 'abc') === false, 'a short key is hidden completely');
CP_Test::ok(Claudpress_Secrets::is_mask($mask), 'a posted mask is recognised as unchanged');
CP_Test::ok(!Claudpress_Secrets::is_mask($key), 'a real key is not mistaken for a mask');
CP_Test::ok(!Claudpress_Secrets::is_mask(''), 'an empty field means "clear it", not "unchanged"');

CP_Test::group('Secrets - settings round-trip');

cp_test_reset_options();
Claudpress::instance()->update(['agent_api_key' => $key]);
$raw = get_option('claudpress_settings');
CP_Test::ok(strpos(json_encode($raw), $key) === false, 'the key is not in the options row in clear text');
CP_Test::same($key, Claudpress::instance()->get('agent_api_key'), 'callers still read a plain value, unaware of the encryption');
CP_Test::same(false, $GLOBALS['cp_test_autoload']['claudpress_settings'] ?? null, 'the settings option does not autoload on every request');

Claudpress::instance()->update(['site_label' => 'My Shop']);
CP_Test::same($key, Claudpress::instance()->get('agent_api_key'), 'saving an unrelated setting does not corrupt the stored key');

CP_Test::group('Secrets - the admin template');

// Every file the admin UI was split into: a raw echo of a secret is just as
// bad on one screen as on another.
$admin = '';
foreach (['class-admin.php', 'class-admin-screens.php', 'class-admin-automation.php', 'class-admin-providers.php', 'class-admin-settings.php'] as $cp_admin_file) {
    $admin .= (string) file_get_contents(CLAUDPRESS_DIR . 'includes/' . $cp_admin_file);
}
foreach (['agent_api_key', 'image_api_key', 'voice_api_key', 'notify_telegram_token', 'deploy_target_token'] as $field) {
    $raw_echo = 'value="<?php echo esc_attr($s[' . chr(39) . $field . chr(39) . '] ?? ' . chr(39) . chr(39) . '); ?>"';
    CP_Test::ok(strpos($admin, $raw_echo) === false, $field . ' is never echoed into the page');
}
