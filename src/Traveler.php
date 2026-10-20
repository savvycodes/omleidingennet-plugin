<?php

namespace Omleidingennet\Plugin;

use Kirby\Http\Remote;
use RuntimeException;

class Traveler
{
    public static function transportation(?string $detourId = null): array
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $key = 'transportation.' . hash('sha256', option('omleidingennet.plugin.url') . '|' . option('omleidingennet.plugin.organisationId') . '|' . ($detourId ?? ''));
        if (($vehicles = $cache->get($key)) !== null) {
            return $vehicles;
        }

        $response = Remote::post(option('omleidingennet.plugin.url') . '/api/graphql', [
            'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . Token::get()],
            'data' => json_encode([
                'query' => 'query Transportation($organisationId: String!, $detourId: String) { transportation(organisationId: $organisationId, detourId: $detourId) { id name planetFriendly } }',
                'variables' => ['organisationId' => option('omleidingennet.plugin.organisationId'), 'detourId' => $detourId],
            ]),
        ]);
        $data = $response->json();
        if ($response->code() !== 200 || !empty($data['errors']) || !is_array($data['data']['transportation'] ?? null)) {
            error_log('OmleidingenNet transportation failed: ' . json_encode([
                'endpoint' => option('omleidingennet.plugin.url') . '/api/graphql',
                'organisationId' => option('omleidingennet.plugin.organisationId'),
                'httpStatus' => $response->code(),
                'errors' => $data['errors'] ?? null,
                'response' => $data,
                'nonJsonBodyPreview' => $data === null ? substr($response->content(), 0, 500) : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            throw new RuntimeException('Vervoersmiddelen konden niet worden geladen. Probeer het later opnieuw.');
        }
        $vehicles = $data['data']['transportation'];
        $cache->set($key, $vehicles, 60);
        return $vehicles;
    }

    public static function check(?string $detourId = null): array
    {
        $point = static function ($value): array {
            if (!is_string($value) || !preg_match('/^\s*([+-]?(?:\d+\.?\d*|\.\d+))\s*,\s*([+-]?(?:\d+\.?\d*|\.\d+))\s*$/', $value, $matches)) {
                throw new RuntimeException('Vul coördinaten in als breedtegraad, lengtegraad (bijv. 51.5, 3.6).');
            }
            $lat = (float) $matches[1];
            $lon = (float) $matches[2];
            if (abs($lat) > 90 || abs($lon) > 180) {
                throw new RuntimeException('De coördinaten vallen buiten het geldige bereik.');
            }
            return compact('lat', 'lon');
        };

        $vehicle = kirby()->request()->body()->get('vehicle');
        if (!is_string($vehicle) || !in_array($vehicle, array_column(self::transportation($detourId), 'id'), true)) {
            throw new RuntimeException('Kies een geldig vervoermiddel.');
        }

        $query = <<<'GRAPHQL'
query CheckRoute($detourId: String, $from: RoutePoint!, $to: RoutePoint!, $vehicleId: ID!) {
  checkRoute(detourId: $detourId, from: $from, to: $to, vehicleId: $vehicleId) {
    detours {
      detourId
      message
      phases { phaseId touches status message }
    }
  }
}
GRAPHQL;

        $response = Remote::post(option('omleidingennet.plugin.url') . '/api/graphql', [
            'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . Token::get()],
            'data' => json_encode([
                'query' => $query,
                'variables' => ['detourId' => $detourId, 'from' => $point(kirby()->request()->body()->get('from')), 'to' => $point(kirby()->request()->body()->get('to')), 'vehicleId' => $vehicle],
            ]),
        ]);
        $data = $response->json();
        if ($response->code() !== 200 || !is_array($data) || !empty($data['errors']) || !isset($data['data']['checkRoute']['detours']) || !is_array($data['data']['checkRoute']['detours'])) {
            error_log('OmleidingenNet checkRoute failed: ' . json_encode([
                'endpoint' => option('omleidingennet.plugin.url') . '/api/graphql',
                'httpStatus' => $response->code(),
                'errors' => $data['errors'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            throw new RuntimeException('De route kon niet worden gecontroleerd. Probeer het later opnieuw.');
        }
        $detours = $data['data']['checkRoute']['detours'];
        $hasAffectedPhase = false;
        foreach ($detours as $detour) {
            foreach ($detour['phases'] ?? [] as $phase) {
                if (!empty($phase['touches'])) {
                    $hasAffectedPhase = true;
                    break 2;
                }
            }
        }
        if (!$hasAffectedPhase) {
            error_log('OmleidingenNet checkRoute: no affected phases; detours=' . json_encode($detours, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $data['data']['checkRoute']['detours'];
    }
}
