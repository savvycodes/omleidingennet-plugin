<?php

$root = dirname(__DIR__);
$paths = [$root . '/index.php', $root . '/command.php'];
foreach (['src', 'tests'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $paths[] = $file->getPathname();
        }
    }
}
foreach ($paths as $path) {
    passthru(escapeshellarg(PHP_BINARY) . ' -d error_reporting=32767 -l ' . escapeshellarg($path), $status);
    if ($status !== 0) {
        exit($status);
    }
}
