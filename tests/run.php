<?php
/**
 * Run the whole unit suite.
 *
 *   php tests/run.php              every test in tests/unit
 *   php tests/run.php snippets     only files whose name matches
 *
 * One process, one bootstrap, one summary. These are plain PHP with hand-rolled
 * WordPress stubs rather than PHPUnit against a real install, because a suite
 * that needs a database and a bootstrap is a suite nobody runs.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/framework.php';

$filter = $argv[1] ?? '';
$files  = glob(__DIR__ . '/unit/test-*.php') ?: [];
sort($files);

$ran = 0;
foreach ($files as $file) {
    if ($filter !== '' && stripos(basename($file), $filter) === false) {
        continue;
    }
    $ran++;
    require $file;
}

if ($ran === 0) {
    echo "No test files matched " . ($filter !== '' ? '"' . $filter . '"' : 'anything') . ".\n";
    exit(1);
}

exit(KH_Test::summary());
