<?php
/**
 * The filesystem tools' undo.
 *
 * The syntax check next door (test-filesystem-safety.php) catches code that
 * does not parse, before it lands. This catches its opposite: content that
 * parses perfectly and takes the site down anyway — a theme file that redirects,
 * a must-use plugin that returns 503. Nothing errors, so nothing else notices.
 *
 * Undoing it needs the previous bytes, which is what the backup store is for.
 * The tests that matter here are the ones about EXACTNESS: a restore that is
 * nearly right is a corrupted file, and a "backup" that silently did not happen
 * is worse than no backup at all, because the revert then appears to work.
 */

require_once CLAUDPRESS_DIR . 'includes/tools/class-tools-filesystem.php';

$fs_base    = rtrim(wp_normalize_path(ABSPATH), '/');
$fs_dir     = $fs_base . '/wp-content/cp-backup-test';
$backup_dir = WP_CONTENT_DIR . '/uploads/claudpress-backups';

if (!is_dir($fs_dir)) {
    @mkdir($fs_dir, 0777, true);
}

// Deepest first, and prepended so these are removed before the bootstrap's own
// entries try to rmdir the directories above them.
array_unshift(
    $GLOBALS['cp_test_scratch_paths'],
    $backup_dir . '/index.php',
    $backup_dir . '/.htaccess',
    $backup_dir,
    WP_CONTENT_DIR . '/uploads',
    $fs_dir,
);

/** Remove every stored backup, so one group cannot see another's. */
$fs_clear_backups = static function () use ($backup_dir): void {
    foreach ((glob($backup_dir . '/*.bak') ?: []) as $f) {
        @unlink($f);
    }
};

$fs_backup_before = new ReflectionMethod('Claudpress_Tools_Filesystem', 'backup_before_write');
@$fs_backup_before->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor
$fs_restore = new ReflectionMethod('Claudpress_Tools_Filesystem', 'restore_backup');
@$fs_restore->setAccessible(true);

CP_Test::group('Filesystem backups — the restore is byte-exact');

// CRLF and a trailing newline are exactly what a careless round trip destroys,
// and a PHP file that loses its trailing newline or gains a stray \r is not the
// file the owner had. "Looks the same" is not the assertion; identical bytes is.
$crlf_file = $fs_dir . '/crlf.txt';
$original  = "<?php\r\n// line one\r\n// line two\r\n\r\n";
file_put_contents($crlf_file, $original);

$backup = $fs_backup_before->invoke(null, $crlf_file);
CP_Test::ok(is_string($backup) && is_file($backup), 'an existing file is backed up before it changes');

file_put_contents($crlf_file, "clobbered");
$restored = $fs_restore->invoke(null, $backup);
CP_Test::same($original, $restored, 'the restored content is byte-identical, CRLF and trailing newline included');
CP_Test::same(strlen($original), strlen((string) $restored), 'and exactly as long, so nothing was normalised away');

CP_Test::group('Filesystem backups — one per path, most recent only');

$fs_clear_backups();
$rolling = $fs_dir . '/rolling.txt';

file_put_contents($rolling, 'state one');
$fs_backup_before->invoke(null, $rolling);
file_put_contents($rolling, 'state two');
$second = $fs_backup_before->invoke(null, $rolling);

CP_Test::same(1, count(glob($backup_dir . '/*.bak') ?: []), 'two writes to one path leave exactly one backup');
CP_Test::same('state two', $fs_restore->invoke(null, $second), 'and it holds the state from just before the second write, not the first');

CP_Test::group('Filesystem backups — a file too big to copy is skipped, not failed');

$fs_clear_backups();
$huge = $fs_dir . '/huge.bin';
// One byte over the 5MB per-file cap. Backing up a large binary to make a text
// edit safe is not worth filling the disk for.
file_put_contents($huge, str_repeat('a', (5 * 1024 * 1024) + 1));
CP_Test::same(null, $fs_backup_before->invoke(null, $huge), 'a file over the per-file cap is not backed up');
CP_Test::same(0, count(glob($backup_dir . '/*.bak') ?: []), 'and nothing is left in the store');
@unlink($huge);

// The write itself must still go through. A skipped backup means guard() has no
// automatic revert for that write — never that the owner is refused.
cp_test_reset_ajax();
cp_test_http_queue(200, '');
$res = Claudpress_Tools_Filesystem::write_file(['path' => 'wp-content/cp-backup-test/big.txt', 'content' => 'x']);
CP_Test::same(true, $res['success'], 'and the write is still allowed to happen');

CP_Test::group('Filesystem guard — a write that breaks the site is undone');

$fs_clear_backups();
cp_test_reset_ajax();

$victim = $fs_dir . '/victim.txt';
file_put_contents($victim, "the good version\n");

cp_test_http_queue(503, ''); // the site is down after the write
cp_test_http_queue(200, ''); // and healthy again once reverted — so the write did it

$res = Claudpress_Tools_Filesystem::write_file([
    'path'    => 'wp-content/cp-backup-test/victim.txt',
    'content' => "the bad version\n",
]);

CP_Test::same('FAILED', $res['front_end_check'] ?? null, 'the write is identified as the cause');
CP_Test::same(false, $res['success'], 'and the result does NOT claim success beside a note saying it was rolled back');
CP_Test::same("the good version\n", (string) file_get_contents($victim), 'the file is back to exactly what it was');

CP_Test::group('Filesystem guard — an innocent write is put back');

$fs_clear_backups();
cp_test_reset_ajax();

// Both probes fail: the site was already broken before this write, so reverting
// it changed nothing. Leaving it reverted would silently lose the owner's work
// while telling them it saved.
cp_test_http_queue(503, '');
cp_test_http_queue(503, '');

$res = Claudpress_Tools_Filesystem::write_file([
    'path'    => 'wp-content/cp-backup-test/victim.txt',
    'content' => "the new version\n",
]);

CP_Test::same('passed', $res['front_end_check'] ?? null, 'the write is cleared of blame');
CP_Test::same(true, $res['site_down'] ?? null, 'but the site is still down, and says so rather than reading as all clear');
CP_Test::same("the new version\n", (string) file_get_contents($victim), 'and the write is re-applied, not silently lost');

CP_Test::group('Filesystem guard — a brand new file is undone by deleting it');

$fs_clear_backups();
cp_test_reset_ajax();

$fresh = $fs_dir . '/fresh.txt';
@unlink($fresh);

cp_test_http_queue(503, '');
cp_test_http_queue(200, '');

$res = Claudpress_Tools_Filesystem::write_file([
    'path'    => 'wp-content/cp-backup-test/fresh.txt',
    'content' => "new\n",
]);

CP_Test::same('FAILED', $res['front_end_check'] ?? null, 'a new file that breaks the site is caught too');
// The revert for a file that did not exist is deleting it. Restoring an empty
// backup instead would leave a 0-byte file behind — still there, still loaded,
// and now looking like the owner meant it.
CP_Test::ok(!is_file($fresh), 'and it is deleted, not left behind as an empty file');

CP_Test::group('Filesystem guard — reads never cost an HTTP request');

cp_test_reset_ajax();
file_put_contents($fs_dir . '/readable.txt', 'hello');
cp_test_http_queue(200, '');

Claudpress_Tools_Filesystem::read_file(['path' => 'wp-content/cp-backup-test/readable.txt']);
CP_Test::same(1, count($GLOBALS['cp_test_http']), 'read_file consumes no queued response, so it ran no probe');

Claudpress_Tools_Filesystem::list_directory(['path' => 'wp-content/cp-backup-test']);
CP_Test::same(1, count($GLOBALS['cp_test_http']), 'and neither does list_directory');

cp_test_reset_ajax();
$fs_clear_backups();

// Leave the tree as it was found. The bootstrap's shutdown handler rmdir()s the
// directories, which only works once the files inside them are gone.
foreach ((glob($fs_dir . '/*') ?: []) as $f) {
    @unlink($f);
}
