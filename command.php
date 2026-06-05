<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../../../kirby/bootstrap.php';

$kirby = new Kirby\Cms\App([
    'roots' => [
        'index' => dirname(__DIR__, 3),
    ],
]);

$cli = new class {
    public function success(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    public function error(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
};

\Omleidingennet\Plugin\RefreshCache::run($cli);
