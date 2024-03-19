<?php

namespace Omleidingennet\Plugin;

use Exception;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Response;
use Kirby\Http\Remote;
use Kirby\Toolkit\A;

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

    public function children()
    {
        if ($this->children instanceof Pages) {
            return $this->children;
        }

        $search = get('search_query');
        $results = $this->pois($search);
        $pages = [];

        foreach ($results as $key => $poi) {

            $pages[] = [
                'num' => 0,
                'slug' => $poi['id'], // no slug for now
                'template' => 'point-of-interest',
                'model' => 'PointOfInterest',
                'content' => [
                    'title' => $poi['name'],
                    "description" => $poi['description'],
                    'begin_date' => $poi['beginDate'],
                    'end_date' => $poi['endDate'],
                    "cover" => $poi['coverPhoto'],
                    "styling" => $poi['styling'],
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

    private function pois($search = null)
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
                  __typename
                }
                styling 
              }
              totalCount
            }
          }
GRAPHQL;

        // get detours from omleidingennet
        $token = $this->getToken();
        $take = 10; // todo: pagination

        $filter = [
            'take' => $take,
        ];

        if (isset($search) && !empty($search)) {
            $filter['query'] = $search;
        }

        // contiously fetch detours until totalCount is reached
        $arr = [];
        $skip = 0;
        $totalCount = 0;
        // do {
            // $filter['skip'] = $skip;
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

                $totalCount = $data['data']['pointOfInterests']['totalCount'];
                $arr = array_merge($arr, $data['data']['pointOfInterests']['pois']);
                // $skip += $take;
            } else {
                // disabled for now to prevent site from crashing
                $data = $res->json_encode();
                throw new Exception($data, $res->code());
            }
        // } while (count($arr) < $totalCount);

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
}