<?php

// A clean consuming Composer project: no application plugins or live credentials.
require dirname(__DIR__) . '/kirby/bootstrap.php';

use Kirby\Filesystem\Dir;

$plugin = dirname(__DIR__);
$root = sys_get_temp_dir() . '/omleidingennet-install-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    Dir::make($root . '/package');
    foreach (['src', 'blueprints'] as $directory) {
        Dir::copy($plugin . '/' . $directory, $root . '/package/' . $directory);
    }
    foreach (['composer.json', 'index.php', 'command.php'] as $file) {
        copy($plugin . '/' . $file, $root . '/package/' . $file);
    }
    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'omleidingennet/install-test',
        'repositories' => [[
            'type' => 'path', 'url' => './package',
            'options' => ['symlink' => false, 'versions' => ['omleidingennet/plugin' => 'dev-test']],
        ]],
        'require' => ['omleidingennet/plugin' => 'dev-test'],
        'config' => ['allow-plugins' => ['getkirby/composer-installer' => true]],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    $process = proc_open(['composer', 'install', '--no-interaction', '--prefer-dist'], [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/composer.log', 'a'], 2 => ['file', $root . '/composer.log', 'a'],
    ], $pipes, $root);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Clean Composer installation failed: ' . file_get_contents($root . '/composer.log'));
    }
    foreach (['kirby/bootstrap.php', 'site/plugins/omleidingennet/index.php', 'site/plugins/omleidingennet/blueprints/point-of-interest.yml'] as $path) {
        if (!is_file($root . '/' . $path)) throw new RuntimeException('Missing installed file: ' . $path);
    }
    // Check the installed copy in a separate PHP process, not the dev checkout.
    file_put_contents($root . '/verify.php', <<<'PHP'
<?php
require __DIR__ . '/kirby/bootstrap.php';
$app = new Kirby\Cms\App(['roots' => ['index' => __DIR__]]);
if ($app->extension('pageModels', 'point-of-interest') === null ||
    $app->extension('commands', 'omleidingennet:refresh-cache') === null) {
    exit(1);
}
PHP);
    $process = proc_open([PHP_BINARY, $root . '/verify.php'], [
        0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR,
    ], $pipes, $root);
    if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Installed plugin failed to register');
    echo "PASS: Clean Composer install uses the correct package/path and registers the installed plugin\n";
} finally {
    Dir::remove($root);
}
