<?php

namespace Omleidingennet\Plugin;

use Exception;
use Kirby\Http\Remote;

class Token
{
    public static function get()
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $key = 'token.' . hash('sha256', option('omleidingennet.plugin.url') . '|' . option('omleidingennet.plugin.clientId') . '|' . option('omleidingennet.plugin.clientSecret'));
        $token = $cache->get($key);

        if ($token === null) {
            // get access token from omleidingennet
            $res = Remote::post(option('omleidingennet.plugin.url') . '/oauth/token', [
                'headers' => [
                    'Content-Type: application/json',
                ],
                'data' => json_encode([
                    'grant_type' => 'client_credentials',
                    'client_id' => option('omleidingennet.plugin.clientId'),
                    'client_secret' => option('omleidingennet.plugin.clientSecret'),
                    'audience' => "https://www.omleidingennet.nl/api/graphql",
                ])
            ]);

            $data = $res->json();
            if ($res->code() === 200 && is_string($data['access_token'] ?? null) && is_numeric($data['expires_in'] ?? null)) {

                $cache->set($key, $data['access_token'], max(1, ($data['expires_in'] - 60) / 60));
                return $data['access_token'];
            }
            throw new Exception('Could not fetch access token');
        }

        return $token;
    }
}
