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

        return static::$subpages = Pages::factory($this->inventory()['children'], $this);
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
            $pages[] = [
                'slug' => Str::slug($poi['name']),
                'num' => 0,
                'template' => 'point-of-interest',
                'model' => 'PointOfInterest',
                'content' => [
                    'title' => $poi['name'],
                    "description" => $poi['description'],
                    'begin_date' => $poi['beginDate'],
                    'end_date' => $poi['endDate'],
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

    private function pois($search = null, $type = null)
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $hashKey = (empty($status) ? '' :  $status) . (empty($areas) ? '' : $areas);
        $hash = hash('sha256', $hashKey);

        // create sha256 of status and areas
        $cacheKey = "pois." . $hash . option('omleidingennet.plugin.organisationId');

        $pois = $cache->get($cacheKey);
        // only serve from cache when $search, $status or $areas is not set
        if ($pois !== null && empty(isset($search))) {
            return $pois;
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
                // disabled for now to prevent site from crashing
                // $data = $res->json_encode();
                // throw new Exception($data, $res->code());
            }
        } while (count($arr) < $totalCount);

        // cache for ttl minutes
        if (empty($search)) {
            $cache->set($cacheKey, $arr, option('omleidingennet.plugin.ttl'));
        }

        return $arr;
    }

    public function apiPointOfInterests()
    {
        $search = get('search_query');
        $pois = $this->pois($search);

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

    private function types($search = null, $type = null)
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $hashKey = (empty($status) ? '' :  $status) . (empty($areas) ? '' : $areas);
        $hash = hash('sha256', $hashKey);

        // create sha256 of status and areas
        $cacheKey = "types." . $hash . option('omleidingennet.plugin.organisationId');

        $types = $cache->get($cacheKey);
        // only serve from cache when $search, $status or $areas is not set
        if ($types !== null && empty(isset($search))) {
            return $types;
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

        // cache for ttl minutes
        if (empty($search)) {
            $cache->set($cacheKey, $arr, option('omleidingennet.plugin.ttl'));
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
}
