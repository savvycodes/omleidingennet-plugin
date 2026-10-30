<?php

namespace Omleidingennet\Plugin;

use Exception;
use Kirby\Http\Remote;

class Token
{
    public static function get()
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $token = $cache->get('token');

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

            if ($res->code() === 200) {
                $data = $res->json();

                $cache->set('token', $data['access_token'], $data['expires_in'] / 60);
                return $data['access_token'];
            }
            throw new Exception('Could not fetch access token');
        }

        return $token;
    }
}
