<?php

namespace Omleidingennet\Plugin;

use RuntimeException;
use Throwable;

class RefreshCache
{
    public static function run($cli): void
    {
        $root = kirby()->root('cache');
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Could not create Omleidingennet lock directory.');
        }
        // Scope to cache root + organisation, independent of HTTP/CLI prefixes.
        // Never unlink the file: doing so could allow locks on two inodes.
        $lock = fopen($root . '/omleidingennet-refresh-' . hash('sha256',
            (string)option('omleidingennet.plugin.organisationId')) . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Could not open Omleidingennet refresh lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $cli->success('Omleidingennet cache refresh already running; skipped.');
            return;
        }

        $results = [];
        $errors = [];
        $run = function ($key, $callback) use (&$results, &$errors) {
            try {
                $data = $callback();
                $results[$key] = $key === 'organisation' ? 1 : count($data);
            } catch (Throwable $e) {
                $errors[$key] = $e->getMessage();
            }
        };

        try {
            $detoursPage = new DetoursPage([
                'slug' => 'detours',
                'template' => 'detours',
            ]);
            $poisPage = new PointOfInterestsPage([
                'slug' => 'pois',
                'template' => 'point-of-interests',
            ]);
            $organisation = new Organisation();

            $run('detours', fn() => $detoursPage->refreshCache());
            $run('areas', fn() => $detoursPage->refreshAreasCache());
            $run('pois', fn() => $poisPage->refreshCache());
            $run('poiTypes', fn() => $poisPage->refreshTypesCache());
            $run('organisation', fn() => $organisation->refreshCache());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        foreach ($results as $key => $count) {
            $cli->success($key . ': ' . $count);
        }

        foreach ($errors as $key => $message) {
            if (is_callable([$cli, 'error'])) {
                $cli->error($key . ': ' . $message);
            }
        }

        if (empty($errors) === false) {
            $messages = [];

            foreach ($errors as $key => $message) {
                $messages[] = $key . ': ' . $message;
            }

            throw new RuntimeException('Omleidingennet cache refresh failed: ' . implode('; ', $messages));
        }
    }
}
