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

        $search = get('search');
        $results = $this->pois($search);
        $pages = [];

        foreach ($results as $key => $detour) {
            $slug = 'poi-' . $detour['id'];

            $pages[] = [
                'num' => 0,
                'slug' => $slug,
                'template' => 'point-of-interest',
                'model' => 'PointOfInterest',
                'content' => [
                    'title' => $detour['name'],
                    "description" => $detour['description'],
                    'begin_date' => $detour['beginDate'],
                    'end_date' => $detour['endDate'],
                    // "detour_status" => $detour['status'],
                    // "slug" => $detour['slug'],
                    // "website" => $detour['website'],
                    "cover" => $detour['coverPhoto'],
                    // "type" => $detour['detourType'],
                    // "fases" => $detour['fases'],
                    // "infraManager" => isset($detour['infraManager']) ? $detour['infraManager'] : null,
                    // 'uuid' => $detour['id'],
                    // 'actions' => $page ? $page->actions()->value() : null,
                    // 'employer' => $page ? $page->employer()->value() : null,
                    // 'logistic' => $page ? $page->logistic()->value() : null,
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
            // $pois = array_values(A::filter($pois, function ($poi) use ($status) {
            //     if (!$status) {
            //         return $poi['status'] != 'Afgerond';
            //     }
            //     return $poi['status'] == $status;
            // }));
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
                }
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
        // if (isset($status) && !empty($status)) {
        //     $filter['status'] = $status;
        // }
        // if (isset($areas) && !empty($areas)) {
        //     $filter['areas'] = [$areas];
        // }

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

        // $arr = array_values(A::filter($arr, function ($detour) use ($status) {
        //     if (!$status) {
        //         return $detour['status'] != 'Afgerond';
        //     }
        //     return $detour['status'] == $status;
        // }));

        return $arr;
    }

    public function apiPointOfInterests()
    {
        $search = get('search');
        $pois = $this->pois($search);

        return Response::json(['pois' => $pois]);
    }

    public function apiPointOfInterest($detourId)
    {
        $detours = $this->pois();
        $requestedDetour = null;
        foreach ($detours as $detour) {
            if ($detour['id'] === $detourId) {
                $requestedDetour = $detour;
                break;
            }
        }

        if ($requestedDetour !== null) {
            return Response::json(['pois' => [$requestedDetour]]);
        } else {
            return Response::json(['error' => 'Detour not found'], 404);
        }
    }
}