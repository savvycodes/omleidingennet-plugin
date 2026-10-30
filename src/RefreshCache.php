<?php

namespace Omleidingennet\Plugin;

use RuntimeException;
use Throwable;

class RefreshCache
{
    public static function run($cli): void
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $lockKey = 'refresh.lock.' . option('omleidingennet.plugin.organisationId');
        $lockStartedAt = $cache->get($lockKey);

        if ($lockStartedAt !== null) {
            if (time() - (int)$lockStartedAt < 15 * 60) {
                $cli->success('Omleidingennet cache refresh already running; skipped.');
                return;
            }

            $cache->remove($lockKey);
        }

        $cache->set($lockKey, time(), 15);

        $results = [];
        $errors = [];
        $run = function ($key, $callback) use (&$results, &$errors) {
            try {
                $data = $callback();
                $results[$key] = is_array($data) ? count($data) : ($data === null ? 0 : 1);
            } catch (Throwable $e) {
                $errors[$key] = $e->getMessage();
            }
        };

        $detoursPage = new DetoursPage([
            'slug' => 'detours',
            'template' => 'detours',
        ]);
        $poisPage = new PointOfInterestsPage([
            'slug' => 'pois',
            'template' => 'pois',
        ]);
        $organisation = new Organisation();

        try {
            $run('detours', fn() => $detoursPage->refreshCache());
            $run('areas', fn() => $detoursPage->refreshAreasCache());
            $run('pois', fn() => $poisPage->refreshCache());
            $run('poiTypes', fn() => $poisPage->refreshTypesCache());
            $run('organisation', fn() => $organisation->refreshCache());
        } finally {
            $cache->remove($lockKey);
        }

        foreach ($results as $key => $count) {
            $cli->success($key . ': ' . $count);
        }

        foreach ($errors as $key => $message) {
            if (method_exists($cli, 'error')) {
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
