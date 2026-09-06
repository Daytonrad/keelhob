<?php
/**
 * S1 - path traversal in the filesystem tools.
 *
 * Before 1.10.1 write_file() could create a file anywhere the PHP user could
 * write. Two bugs combined: wp_normalize_path() does not resolve "..", and
 * realpath() returns false for a file that does not exist yet - which is every
 * file write_file() creates - so the guard fell back to checking the raw,
 * unresolved string and the OS resolved the ".." later.
 *
 * The allowed cases matter as much as the blocked ones: they are why the guard
 * cannot simply reject anything that does not already exist.
 */

CP_Test::group('Filesystem - path confinement');

$base = rtrim(wp_normalize_path(ABSPATH), '/');
if (!is_dir($base . '/wp-content')) { @mkdir($base . '/wp-content', 0777, true); }
if (!is_dir($base . '-backup'))     { @mkdir($base . '-backup', 0777, true); }
@file_put_contents($base . '/wp-content/real.txt', 'x');
@file_put_contents($base . '-backup/secret.txt', 'x');

$resolve = new ReflectionMethod('Claudpress_Tools_Filesystem', 'resolve');
@$resolve->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor
$allowed = function ($path) use ($resolve) {
    $out = $resolve->invoke(null, $path);
    return $out[1] === null;
};

CP_Test::ok($allowed('wp-content/new.txt'), 'a new file inside the base is allowed');
CP_Test::ok($allowed('wp-content/a/b/c/new.txt'), 'missing parent directories are still allowed (write_file creates them)');
CP_Test::ok($allowed('wp-content/real.txt'), 'an existing file inside the base is allowed');
CP_Test::ok($allowed(''), 'the base directory itself is allowed');

CP_Test::ok(!$allowed('wp-content/../../cp_pwn.txt'), 'THE BUG: ".." cannot escape the base');
CP_Test::ok(!$allowed('wp-content/../../../../../../etc/x'), 'a long traversal cannot escape either');
CP_Test::ok(!$allowed('../cp_pwn2.txt'), 'a leading ".." is refused');
CP_Test::ok(!$allowed('..'), 'a bare ".." is refused');
CP_Test::ok(!$allowed($base . '-backup/secret.txt'), 'a sibling directory sharing the base prefix is refused');
CP_Test::ok(!$allowed('/etc/passwd'), 'an absolute path outside the base is refused');
