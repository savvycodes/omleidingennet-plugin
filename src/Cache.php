<?php

namespace Omleidingennet\Plugin;

use Kirby\Cache\FileCache;

class Cache
{
    /** Keep a known-good backup when the primary cache cannot be written. */
    public static function store($cache, $backup, string $key, string $backupKey, array $data): void
    {
        if ($cache->set($key, $data) === false) {
            throw new \RuntimeException('Could not write Omleidingennet cache.');
        }
        if ($backup->set($backupKey, $data) === false) {
            throw new \RuntimeException('Could not write Omleidingennet backup cache.');
        }
    }

    public static function scope(): string
    {
        return hash('sha256', json_encode([
            option('omleidingennet.plugin.url'),
            option('omleidingennet.plugin.organisationId'),
            option('omleidingennet.plugin.clientId'),
            option('omleidingennet.plugin.clientSecret'),
        ], JSON_THROW_ON_ERROR));
    }

    public static function cli(string $name): FileCache
    {
        return new FileCache([
            'root' => kirby()->root('cache'),
            'prefix' => '_/' . str_replace(['/', '.'], ['_', '/'], $name),
            'extension' => 'cache',
        ]);
    }
}
