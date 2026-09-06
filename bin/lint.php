<?php
/**
 * Portable `php -l` over every PHP file in the repository.
 *
 *   php bin/lint.php          lint the whole repo
 *   composer lint             the same thing
 *
 * Why a PHP script and not `find . -name '*.php' -exec php -l {} \;`:
 * `composer lint` shells out through cmd.exe on Windows, where `find` is either
 * absent or is Windows' own unrelated `find.exe`. Git Bash's find works, but a
 * developer running `composer lint` from PowerShell would get a confusing
 * failure that has nothing to do with their code. This runs identically on
 * Windows, Linux and macOS with nothing installed but the PHP that is already
 * required to run the plugin.
 *
 * Exit code is 0 when every file parses, 1 otherwise, so CI can gate on it.
 */

$root = dirname(__DIR__);

// Directories that are never ours to lint. vendor/ is dev-only tooling and is
// never shipped; build/ is a staging copy of files already linted at source.
$skipDirs = ['vendor', 'build', 'node_modules', '.git', '.codegraph'];

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($skipDirs): bool {
            if ($file->isDir()) {
                return !in_array($file->getFilename(), $skipDirs, true);
            }
            return strtolower($file->getExtension()) === 'php';
        }
    ),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $file) {
    $files[] = $file->getPathname();
}
sort($files);

$php = PHP_BINARY;
$failed = [];

foreach ($files as $file) {
    $cmd = escapeshellarg($php) . ' -l ' . escapeshellarg($file);
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $failed[] = [$file, implode("\n", $out)];
    }
}

$total = count($files);

if ($failed !== []) {
    foreach ($failed as [$file, $out]) {
        $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
        fwrite(STDERR, "FAIL {$rel}\n{$out}\n\n");
    }
    fwrite(STDERR, count($failed) . " of {$total} files failed to parse.\n");
    exit(1);
}

echo "Lint OK — {$total} PHP files parse cleanly (php " . PHP_VERSION . ").\n";
exit(0);
