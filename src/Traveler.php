<?php

namespace Omleidingennet\Plugin;

use RuntimeException;

class Traveler
{
    public static function transportation(?string $detourId = null): array
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $key = 'transportation.' . Cache::scope() . '.' . hash('sha256', $detourId ?? '');
        if (($vehicles = $cache->get($key)) !== null) {
            return $vehicles;
        }

        $vehicles = Api::request(
            'query Transportation($organisationId: String!, $detourId: String) { transportation(organisationId: $organisationId, detourId: $detourId) { id name planetFriendly } }',
            ['organisationId' => option('omleidingennet.plugin.organisationId'), 'detourId' => $detourId],
            'transportation'
        );
        Api::validateList($vehicles, ['id' => 'string', 'name' => 'string']);
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

        $result = Api::request($query, [
            'detourId' => $detourId,
            'from' => $point(kirby()->request()->body()->get('from')),
            'to' => $point(kirby()->request()->body()->get('to')),
            'vehicleId' => $vehicle,
        ], 'checkRoute');
        Api::validateRecord($result, ['detours' => 'array']);
        Api::validateList($result['detours'], ['detourId' => 'string', 'phases' => 'array']);
        return $result['detours'];
    }
}
