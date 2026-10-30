<?php

namespace Omleidingennet\Plugin;

use Kirby\Http\Remote;
use RuntimeException;
use Throwable;

/** Server-side API access. Never include credentials or response bodies in errors. */
class Api
{
    public static function request(string $query, array $variables, string $field): array
    {
        try {
            $response = Remote::post(option('omleidingennet.plugin.url') . '/api/graphql', [
                'headers' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . Token::get(),
                ],
                'timeout' => 30,
                'data' => json_encode(compact('query', 'variables'), JSON_THROW_ON_ERROR),
            ]);
            $data = $response->json();
        } catch (Throwable $e) {
            // Transport exceptions may contain URLs or response data.
            throw new RuntimeException('Omleidingennet API transport failed.');
        }
        if ($response->code() !== 200 || !is_array($data) ||
            !empty($data['error']) || !empty($data['errors']) ||
            !is_array($data['data'][$field] ?? null)) {
            throw new RuntimeException('Omleidingennet API request failed (' . $field . ').');
        }

        return $data['data'][$field];
    }

    public static function paginate(string $query, array $variables, string $field, string $items, int $take = 100): array
    {
        if ($take < 1 || $take > 1000) {
            throw new RuntimeException('Invalid Omleidingennet page size.');
        }
        $result = [];
        $seen = [];
        $skip = 0;
        $expectedTotal = null;
        $requests = 0;
        do {
            if (++$requests > 10000) {
                throw new RuntimeException('Omleidingennet pagination request limit reached.');
            }
            $variables['filter'] = [...($variables['filter'] ?? []), 'take' => $take, 'skip' => $skip];
            $page = self::request($query, $variables, $field);
            $total = $page['totalCount'] ?? null;
            $batch = $page[$items] ?? null;
            if (!is_int($total) || $total < 0 || $total > 100000 ||
                !is_array($batch) || !array_is_list($batch) || count($batch) > $take) {
                throw new RuntimeException('Invalid Omleidingennet pagination response.');
            }
            $expectedTotal ??= $total;
            if ($total !== $expectedTotal) {
                throw new RuntimeException('Omleidingennet result count changed during pagination; retry refresh.');
            }
            foreach ($batch as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException('Invalid Omleidingennet pagination item.');
                }
                $id = $item['id'] ?? null;
                if (!is_string($id) || $id === '' || isset($seen[$id])) {
                    throw new RuntimeException('Invalid or repeated Omleidingennet pagination item.');
                }
                $seen[$id] = true;
                $result[] = $item;
            }
            $skip += count($batch);
            if (count($result) > $total || ($batch === [] && count($result) < $total) || $skip > 100000) {
                throw new RuntimeException('Omleidingennet pagination did not complete.');
            }
        } while (count($result) < $total);

        return $result;
    }

    public static function validateRecord(array $record, array $fields): void
    {
        foreach ($fields as $field => $type) {
            if (!array_key_exists($field, $record)) {
                throw new RuntimeException('Missing Omleidingennet response field: ' . $field);
            }
            $value = $record[$field];
            $valid = match ($type) {
                'string' => is_string($value),
                'array' => is_array($value),
                'nullableString' => $value === null || is_string($value),
                'nullableArray' => $value === null || is_array($value),
                // Geometry/styling can be serialized JSON or native JSON values.
                'nullable' => $value === null || is_string($value) || is_array($value),
                default => false,
            };
            if (!$valid) {
                throw new RuntimeException('Invalid Omleidingennet response field: ' . $field);
            }
        }
    }

    public static function validateList(array $items, array $fields): void
    {
        if (!array_is_list($items)) {
            throw new RuntimeException('Invalid Omleidingennet response list.');
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Invalid Omleidingennet response item.');
            }
            self::validateRecord($item, $fields);
        }
    }

    public static function validateDetours(array $detours): void
    {
        self::validateList($detours, [
            'id' => 'string', 'slug' => 'string', 'name' => 'string',
            'description' => 'nullableString', 'status' => 'string',
            'website' => 'nullableString', 'detourType' => 'array',
            'coverPhoto' => 'nullableArray', 'fases' => 'array',
        ]);
        $slugs = [];
        foreach ($detours as $detour) {
            $slug = $detour['slug'];
            if ($slug === '' || isset($slugs[$slug])) {
                throw new RuntimeException('Invalid or repeated Omleidingennet detour slug.');
            }
            $slugs[$slug] = true;
            self::validateRecord($detour['detourType'], ['name' => 'string']);
            if ($detour['coverPhoto'] !== null) {
                self::validateRecord($detour['coverPhoto'], ['url' => 'string']);
            }
            self::validateList($detour['fases'], [
                'id' => 'string', 'name' => 'string', 'status' => 'string',
                'beginDate' => 'nullableString', 'endDate' => 'nullableString',
                'areas' => 'array', 'detourlines' => 'array',
            ]);
            foreach ($detour['fases'] as $fase) {
                self::validateList($fase['areas'], ['id' => 'string', 'name' => 'string']);
                self::validateList($fase['detourlines'], ['id' => 'string']);
            }
        }
    }

    public static function validatePois(array $pois): void
    {
        self::validateList($pois, [
            'id' => 'string', 'name' => 'string', 'description' => 'nullableString',
            'beginDate' => 'nullableString', 'endDate' => 'nullableString',
            'type' => 'array', 'coverPhoto' => 'nullableArray',
        ]);
        foreach ($pois as $poi) {
            self::validateRecord($poi['type'], ['id' => 'string', 'styling' => 'nullable']);
            if ($poi['coverPhoto'] !== null) {
                self::validateRecord($poi['coverPhoto'], ['url' => 'string']);
            }
        }
    }

    /** Query parameters are untrusted; array-valued/oversized filters are invalid. */
    public static function filter(string $name): ?string
    {
        $value = get($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 1000) {
            throw new \Kirby\Exception\InvalidArgumentException('Invalid filter: ' . $name);
        }
        return $value;
    }
}
