<?php

namespace Omleidingennet\Plugin;

use Exception;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Response;
use Kirby\Http\Remote;
use Kirby\Toolkit\Str;

class PointOfInterestsPage extends Page
{
    static $subpages = null;

    public function subpages()
    {
        if (static::$subpages) {
            return static::$subpages;
        }

        return static::$subpages = Pages::factory(
            preferNumberedChildren($this->inventory()['children']),
            $this
        );
    }

    public function children(): Pages
    {
        if ($this->children instanceof Pages) {
            return $this->children;
        }

        $search = get('search_query');
        $type = get('poi_type');
        $results = $this->pois($search, $type);
        $pages = [];

        foreach ($results as $poi) {
            $slug = Str::slug($poi['name']);
            $page = $this->subpages()->find($slug);
            $pages[] = [
                'slug' => $slug,
                'num' => 0,
                'template' => 'point-of-interest',
                'model' => 'PointOfInterest',
                'files' => $page ? $page->files()->toArray() : null,
                'content' => [
                    'title' => $poi['name'],
                    "description" => $poi['description'],
                    'begin_date' => $poi['beginDate'],
                    'end_date' => $poi['endDate'],
                    'slug' => $slug,
                    "cover" => $poi['coverPhoto'] ? $poi['coverPhoto']['url'] : null,
                    "styling" => $poi['type']['styling'],
                    'uuid' => $poi['id'],
                ],
            ];
        }

        return $this->children = Pages::factory($pages, $this);
    }

    private function getToken()
    {
        return Token::get();
    }

    private function pois($search = null, $type = null, $refresh = false)
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $backupCache = kirby()->cache('backup.omleidingennet.plugin');

        $hashKey = (empty($status) ? '' :  $status) . (empty($areas) ? '' : $areas);
        $hash = hash('sha256', $hashKey);

        $cacheKey = "pois." . $hash . option('omleidingennet.plugin.organisationId');
        $backupCacheKey = "backup.pois." . $hash . option('omleidingennet.plugin.organisationId');

        $pois = $cache->get($cacheKey);
        if ($refresh === false) {
            if ($pois !== null) {
                return $pois;
            }

            $backupPois = $backupCache->get($backupCacheKey);
            if ($backupPois !== null) {
                return $backupPois;
            }

            $cliPois = Cache::cli('omleidingennet.plugin')->get($cacheKey);
            if ($cliPois !== null) {
                return $cliPois;
            }

            return Cache::cli('backup.omleidingennet.plugin')->get($backupCacheKey) ?? [];
        }

        $query = <<<'GRAPHQL'
        query pointOfInterests($organisationId: String!, $filter: PoiFilter) {
            pointOfInterests(organisationId: $organisationId, filter: $filter) {
              pois {
                beginDate
                coverPhoto {
                  url
                  slug
                }
                description
                endDate
                geometry
                id 
                name
                type {
                  id
                  name
                  styling
                  __typename
                }
              }
              totalCount
            }
          }
GRAPHQL;

        $token = $this->getToken();
        $take = 1000; // todo: pagination

        $filter = [
            'take' => $take,
        ];

        if (isset($search) && !empty($search)) {
            $filter['query'] = $search;
        }

        if (isset($type) && !empty($type)) {
            $filter['type'] = $type;
        }

        $arr = [];
        $skip = 0;
        $totalCount = 0;
        $completedFetch = false;
        try {
            do {
                $filter['skip'] = $skip;
                // there is no skip filter (yet)
                $res = Remote::post(option('omleidingennet.plugin.url') . '/api/graphql', [
                    'headers' => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $token
                    ],
                    'data' => json_encode([
                        'query' => $query,
                        'variables' => [
                            'organisationId' => option('omleidingennet.plugin.organisationId'),
                            'filter' => $filter
                        ]
                    ])
                ]);

                if ($res->code() === 200) {
                    $data = $res->json();
                    if (isset($data['error'])) {
                        throw new Exception($data['error']['message']);
                    }

                    if (isset($data['errors'])) {
                        throw new Exception(json_encode($data['errors'][0]['message']));
                    }

                    $totalCount = $data['data']['pointOfInterests']['totalCount'];
                    $arr = array_merge($arr, $data['data']['pointOfInterests']['pois']);
                    $skip += $take;
                } else {
                    throw new Exception('Could not fetch detours: ' . $res->code());
                }
            } while (count($arr) < $totalCount);

            $completedFetch = count($arr) >= $totalCount;
        } catch (Exception $e) {
            \Sentry\captureException($e);

            // Fallback to backup cache if request fails
            $backupPois = $backupCache->get($backupCacheKey);
            if ($backupPois !== null) {
                return $backupPois;
            }
        }

        if ($completedFetch === false) {
            return $arr;
        }

        // cache until the Kirby command refreshes it
        if (empty($search)) {
            $cache->set($cacheKey, $arr);
            $backupCache->set($backupCacheKey, $arr); // no timout for backup cache

        }

        return $arr;
    }

    public function refreshCache()
    {
        return $this->pois(null, null, true);
    }

    public function apiPointOfInterests()
    {
        $search = get('search_query');
        $pois = $this->pois($search);

        foreach ($pois as &$poi) {
            $poi['slug'] = Str::slug($poi['name']);
        }

        return Response::json(['pois' => $pois]);
    }

    public function apiPointOfInterest($id)
    {
        $pois = $this->pois();
        $requestedPoi = null;
        foreach ($pois as $poi) {
            if ($poi['id'] === $id) {
                $requestedPoi = $poi;
                break;
            }
        }

        if ($requestedPoi !== null) {
            return Response::json(['pois' => [$requestedPoi]]);
        } else {
            return Response::json(['error' => 'Point of Interest not found'], 404);
        }
    }

    private function types($search = null, $type = null, $refresh = false)
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $backupCache = kirby()->cache('backup.omleidingennet.plugin');
        $hashKey = (empty($status) ? '' :  $status) . (empty($areas) ? '' : $areas);
        $hash = hash('sha256', $hashKey);

        // create sha256 of status and areas
        $cacheKey = "types." . $hash . option('omleidingennet.plugin.organisationId');
        $backupCacheKey = "backup.types." . $hash . option('omleidingennet.plugin.organisationId');

        $types = $cache->get($cacheKey);
        if ($refresh === false) {
            if ($types !== null) {
                return $types;
            }

            $backupTypes = $backupCache->get($backupCacheKey);
            if ($backupTypes !== null) {
                return $backupTypes;
            }

            $cliTypes = Cache::cli('omleidingennet.plugin')->get($cacheKey);
            if ($cliTypes !== null) {
                return $cliTypes;
            }

            return Cache::cli('backup.omleidingennet.plugin')->get($backupCacheKey) ?? [];
        }

        $query = <<<'GRAPHQL'
        query pointOfInterestsTypes($organisationId: String!) {
            pointOfInterestTypes(organisationId: $organisationId) {
              id
              name
              createdAt
              organisationId
              __typename
            }
          }
GRAPHQL;

        $token = $this->getToken();
        $take = 1000; // todo: pagination

        $filter = [
            'take' => $take,
        ];
        $filter['type'] = '';

        $arr = [];
        $totalCount = 0;
        $res = Remote::post(option('omleidingennet.plugin.url') . '/api/graphql', [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ],
            'data' => json_encode([
                'query' => $query,
                'variables' => [
                    'organisationId' => option('omleidingennet.plugin.organisationId'),
                ]
            ])
        ]);

        if ($res->code() === 200) {
            $data = $res->json();
            if (isset($data['error'])) {
                throw new Exception($data['error']['message']);
            }

            $arr = array_merge($arr, $data['data']['pointOfInterestTypes']);
        } else {
            // disabled for now to prevent site from crashing
            // $data = $res->json_encode();
            // throw new Exception($data, $res->code());
        }

        // cache until the Kirby command refreshes it
        if (empty($search)) {
            $cache->set($cacheKey, $arr);
            $backupCache->set($backupCacheKey, $arr);
        }

        return $arr;
    }

    public function getTypes()
    {
        $types = $this->types();

        // filter out to only have the name and id
        $types = array_map(function ($type) {
            return [
                'name' => $type['name'],
                'uuid' => $type['id'],
            ];
        }, $types);

        return $types;
    }

    public function refreshTypesCache()
    {
        return $this->types(null, null, true);
    }
}
