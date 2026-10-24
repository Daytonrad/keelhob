<?php
/**
 * Undo's `file` backup type.
 *
 * This exists for one reason: `image_compress` is about to write over people's
 * original uploads, and "we kept a copy" is only true if the copy comes back
 * byte for byte. An image that returns 3 bytes shorter is a corrupt JPEG, and
 * the owner finds out weeks later when a page stops rendering.
 *
 * So the central assertion here is a hash comparison, not a "did it say
 * success". The rest pins the refusals — because a caller that cannot tell
 * "backed up" from "not backed up" will write anyway.
 *
 * Every file this creates is removed in the same run, including when an
 * assertion fails: the teardown is in a finally block, not at the end.
 */

KH_Test::group('Undo — the file backup type');

$kh_fb_root   = sys_get_temp_dir() . '/kh-undo-test-' . uniqid('', false);
$kh_fb_store  = $kh_fb_root . '/store';
$kh_fb_live   = $kh_fb_root . '/live';
$kh_fb_meta   = [];

@mkdir($kh_fb_live, 0777, true);
@mkdir($kh_fb_store, 0777, true);

// The store is redirected rather than written into the repo's fake uploads
// directory. apply_filters in tests/bootstrap.php runs callbacks for real.
add_filter('keelhob_undo_files_dir', static function () use ($kh_fb_store): string {
    return $kh_fb_store;
});

/*
 * Attachment metadata, faked. The real pair is defined by WordPress; the code
 * under test guards both with function_exists precisely so the suite can stand
 * in for them.
 */
if (!function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata($id = 0, $unfiltered = false)
    {
        return $GLOBALS['kh_fb_attachment_meta'][(int) $id] ?? false;
    }
}
if (!function_exists('wp_update_attachment_metadata')) {
    function wp_update_attachment_metadata($id, $data)
    {
        $GLOBALS['kh_fb_attachment_meta'][(int) $id] = $data;
        return true;
    }
}

try {
    // ---- the round trip, byte for byte ---------------------------------

    /*
     * Binary, not text. A backup store that round-trips "hello world" and
     * mangles a JPEG is the failure that matters, and the usual cause is a
     * stray text-mode read or a trailing newline. Real JPEG header bytes,
     * a null, a CR, an LF and a CRLF are all in here on purpose.
     */
    $kh_fb_original = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00binary\x00\r\n\x1A payload \xFF\xD9";
    $kh_fb_path     = $kh_fb_live . '/photo.jpg';
    file_put_contents($kh_fb_path, $kh_fb_original);
    $kh_fb_before   = hash_file('sha256', $kh_fb_path);

    $GLOBALS['kh_fb_attachment_meta'] = [
        77 => ['file' => '2026/10/photo.jpg', 'width' => 4000, 'height' => 3000, 'filesize' => strlen($kh_fb_original)],
    ];

    KH_Test::same(false, Keelhob_Undo::record_file($kh_fb_path, 77), 'with no turn recording, record_file refuses — so a caller cannot believe it has an undo it does not have');

    Keelhob_Undo::start('file-test');
    KH_Test::same(true, Keelhob_Undo::record_file($kh_fb_path, 77), 'while recording, the file is banked');
    KH_Test::same(1, count(glob($kh_fb_store . '/*.bak') ?: []), 'and exactly one backup copy exists');

    // The store holds the owner's media at a guessable path, so it is closed
    // off to the web on both kinds of host.
    KH_Test::ok(is_file($kh_fb_store . '/index.php'), 'the store gets an index.php');
    KH_Test::ok(is_file($kh_fb_store . '/.htaccess'), 'and a deny-all .htaccess');
    KH_Test::contains('Deny from all', (string) file_get_contents($kh_fb_store . '/.htaccess'), 'which actually denies');

    // Now do what image_compress will do: overwrite the original with a
    // smaller file, and change the metadata to match.
    file_put_contents($kh_fb_path, "\xFF\xD8 shrunk \xFF\xD9");
    wp_update_attachment_metadata(77, ['file' => '2026/10/photo.jpg', 'width' => 2048, 'height' => 1536, 'filesize' => 14]);
    KH_Test::ok(hash_file('sha256', $kh_fb_path) !== $kh_fb_before, 'the live file really was changed');

    KH_Test::same(1, Keelhob_Undo::finish(), 'the turn banks one entry');

    $kh_fb_result = Keelhob_Undo::revert('file-test');
    KH_Test::same(true, $kh_fb_result['success'], 'undo reports success');
    KH_Test::same(1, count($kh_fb_result['restored']), 'and says it restored one thing');
    KH_Test::contains('photo.jpg', $kh_fb_result['restored'][0] ?? '', 'naming the file, so /undo can tell the owner what it did');

    // THE assertion.
    KH_Test::same($kh_fb_before, hash_file('sha256', $kh_fb_path), 'the original file came back byte for byte — same sha256');
    KH_Test::same($kh_fb_original, (string) file_get_contents($kh_fb_path), 'and byte-identical content, binary included');

    // Putting an image file back without its metadata gives you a site that is
    // wrong in a way nobody can see: every srcset still advertises the old
    // dimensions.
    KH_Test::same(4000, $GLOBALS['kh_fb_attachment_meta'][77]['width'] ?? 0, 'the attachment metadata came back with it (width)');
    KH_Test::same(strlen($kh_fb_original), $GLOBALS['kh_fb_attachment_meta'][77]['filesize'] ?? 0, 'filesize included');

    KH_Test::same(0, count(glob($kh_fb_store . '/*.bak') ?: []), 'and the backup copy is cleaned up afterwards, so the store does not grow forever');
    KH_Test::same(0, Keelhob_Undo::depth('file-test'), 'the turn is spent');

    // ---- the refusals ---------------------------------------------------

    Keelhob_Undo::start('file-test-2');
    KH_Test::same(false, Keelhob_Undo::record_file($kh_fb_live . '/not-here.jpg'), 'a file that does not exist is refused rather than recorded as an empty backup');
    KH_Test::same(false, Keelhob_Undo::record_file(''), 'and so is an empty path');

    $kh_fb_big = $kh_fb_live . '/huge.bin';
    file_put_contents($kh_fb_big, str_repeat('x', 1024));
    // Checked through the constant rather than by writing 16 MB to a temp disk:
    // the cap is the contract, and the suite should not need 16 MB of IO to
    // assert one.
    KH_Test::ok(Keelhob_Undo::FILE_MAX_BYTES === 16 * 1024 * 1024, 'the per-file cap is 16 MB');
    KH_Test::ok(Keelhob_Undo::FILES_MAX_TOTAL_BYTES === 128 * 1024 * 1024, 'and the whole store is capped at 128 MB, so backups cannot fill the account they protect');

    // First snapshot wins, same rule as posts: undo returns the file to how it
    // was before the turn, not to how it was halfway through.
    $kh_fb_twice = $kh_fb_live . '/twice.txt';
    file_put_contents($kh_fb_twice, 'first');
    Keelhob_Undo::record_file($kh_fb_twice);
    file_put_contents($kh_fb_twice, 'second');
    Keelhob_Undo::record_file($kh_fb_twice);
    file_put_contents($kh_fb_twice, 'third');
    Keelhob_Undo::finish();
    Keelhob_Undo::revert('file-test-2');
    KH_Test::same('first', (string) file_get_contents($kh_fb_twice), 'two writes in one turn undo to the state before the turn, not to the middle of it');

    // ---- when the backup is gone ---------------------------------------

    /*
     * Undo's worst possible behaviour is reporting success on something it did
     * not put back. A half-working undo is worse than none, because the owner
     * trusts it and finds out later.
     */
    $kh_fb_lost = $kh_fb_live . '/lost.txt';
    file_put_contents($kh_fb_lost, 'original');
    Keelhob_Undo::start('file-test-3');
    Keelhob_Undo::record_file($kh_fb_lost);
    Keelhob_Undo::finish();
    foreach (glob($kh_fb_store . '/*.bak') ?: [] as $kh_fb_f) {
        unlink($kh_fb_f);
    }
    file_put_contents($kh_fb_lost, 'damaged');
    $kh_fb_lost_result = Keelhob_Undo::revert('file-test-3');
    KH_Test::same(false, $kh_fb_lost_result['success'], 'a missing backup copy is reported as a failure, never as a success');
    KH_Test::same(1, count($kh_fb_lost_result['failed']), 'and it is named in failed');
    KH_Test::contains('lost.txt', $kh_fb_lost_result['failed'][0] ?? '', 'by name');
    KH_Test::same('damaged', (string) file_get_contents($kh_fb_lost), 'the damaged file is left alone rather than truncated by a hopeful copy');

    // ---- a file entry never goes down the post path --------------------

    /*
     * The revert loop used to start by asking "does this post still exist".
     * A file entry has no post id, so without its own branch every file undo
     * would be reported as "no longer exists" — success-shaped, and wrong.
     */
    $kh_fb_mixed = $kh_fb_live . '/mixed.txt';
    file_put_contents($kh_fb_mixed, 'before');
    Keelhob_Undo::start('file-test-4');
    Keelhob_Undo::record_file($kh_fb_mixed);
    Keelhob_Undo::finish();
    file_put_contents($kh_fb_mixed, 'after');
    $kh_fb_mixed_result = Keelhob_Undo::revert('file-test-4');
    KH_Test::same([], $kh_fb_mixed_result['failed'], 'a file entry is not run through the "does this post exist" check');
    KH_Test::same('before', (string) file_get_contents($kh_fb_mixed), 'and it is genuinely put back');

    // ---- the KEEP window does not leak bytes ---------------------------

    /*
     * Six turns, five kept. The sixth-from-last turn can never be undone
     * again, so its copy must not sit on disk forever. A backup store that
     * only grows is how a plugin fills someone's hosting account.
     */
    for ($kh_fb_i = 1; $kh_fb_i <= Keelhob_Undo::KEEP + 1; $kh_fb_i++) {
        $kh_fb_each = $kh_fb_live . '/turn-' . $kh_fb_i . '.txt';
        file_put_contents($kh_fb_each, 'v' . $kh_fb_i);
        Keelhob_Undo::start('file-window');
        Keelhob_Undo::record_file($kh_fb_each);
        Keelhob_Undo::finish();
    }
    KH_Test::same(Keelhob_Undo::KEEP, Keelhob_Undo::depth('file-window'), 'only five turns are kept');
    KH_Test::same(Keelhob_Undo::KEEP, count(glob($kh_fb_store . '/*.bak') ?: []), 'and exactly five backup copies remain — the dropped turn took its bytes with it');
} finally {
    // Teardown in finally, so a failed assertion above still leaves no files
    // behind. A test that litters on failure is a test nobody runs twice.
    Keelhob_Undo::clear('file-test');
    Keelhob_Undo::clear('file-test-2');
    Keelhob_Undo::clear('file-test-3');
    Keelhob_Undo::clear('file-test-4');
    Keelhob_Undo::clear('file-window');
    foreach ([$kh_fb_store, $kh_fb_live] as $kh_fb_dir) {
        foreach ((glob($kh_fb_dir . '/{,.}*', GLOB_BRACE) ?: []) as $kh_fb_f) {
            if (is_file($kh_fb_f)) {
                @unlink($kh_fb_f);
            }
        }
        @rmdir($kh_fb_dir);
    }
    @rmdir($kh_fb_root);
    kh_test_reset_filters();

    KH_Test::ok(!is_dir($kh_fb_root), 'every file this test made is gone again (' . $kh_fb_root . ')');
}
