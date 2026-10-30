<?php

namespace Omleidingennet\Plugin;

use RuntimeException;
use Kirby\Http\Remote;
use Throwable;

class Token
{
    public static function get()
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $key = 'token.' . Cache::scope();
        $token = $cache->get($key);

        if ($token === null) {
            // get access token from omleidingennet
            try {
                $res = Remote::post(option('omleidingennet.plugin.url') . '/oauth/token', [
                    'headers' => [
                        'Content-Type: application/json',
                    ],
                    'timeout' => 30,
                    'data' => json_encode([
                        'grant_type' => 'client_credentials',
                        'client_id' => option('omleidingennet.plugin.clientId'),
                        'client_secret' => option('omleidingennet.plugin.clientSecret'),
                        'audience' => 'https://www.omleidingennet.nl/api/graphql',
                    ], JSON_THROW_ON_ERROR),
                ]);
            } catch (Throwable $e) {
                throw new RuntimeException('Could not fetch Omleidingennet access token');
            }

            if ($res->code() === 200) {
                $data = $res->json();

                if (!is_array($data) || !is_string($data['access_token'] ?? null) ||
                    $data['access_token'] === '' || !is_numeric($data['expires_in'] ?? null) ||
                    (float)$data['expires_in'] <= 0) {
                    throw new RuntimeException('Invalid Omleidingennet token response');
                }
                // Kirby cache lifetimes are minutes. Never turn a short TTL into
                // zero (which means no expiry); simply do not cache short tokens.
                $ttl = (int)floor(((float)$data['expires_in'] - 60) / 60);
                if ($ttl > 0) {
                    $cache->set($key, $data['access_token'], $ttl);
                }
                return $data['access_token'];
            }
            throw new RuntimeException('Could not fetch access token');
        }

        return $token;
    }
}
