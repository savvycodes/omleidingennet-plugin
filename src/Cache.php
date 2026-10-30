<?php

namespace Omleidingennet\Plugin;

use Kirby\Cache\FileCache;

class Cache
{
    public static function cli(string $name): FileCache
    {
        return new FileCache([
            'root' => kirby()->root('cache'),
            'prefix' => '_/' . str_replace(['/', '.'], ['_', '/'], $name),
            'extension' => 'cache',
        ]);
    }
}
