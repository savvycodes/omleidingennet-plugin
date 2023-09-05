<?php

namespace Omleidingennet\Plugin;

use Exception;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Response;
use Kirby\Http\Remote;
use Kirby\Toolkit\A;

class DetoursPage extends Page
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
        $status = get('status');
        $areas = get('areas');
        $results = $this->detours($search, $status, $areas);
        $pages   = [];

        foreach ($results as $key => $detour) {
            $slug = $detour['slug'];
            $page = $this->subpages()->find($slug);

            $pages[] = [
                'slug'      => $detour['slug'],
                'num'       => 0,
                'template'  => 'detour',
                'model'     => 'detour',
                'files'     => $page ? $page->files()->toArray() : null,
                'content' => [
                    'title'          => $page ? $page->title()->value() : $detour['name'],
                    "description"     => $detour['description'],
                    "detour_status"   => $detour['status'],
                    "slug"            => $detour['slug'],
                    "cover"           => $detour['coverPhoto'],
                    "type"            => $detour['detourType'],
                    "fases"           => $detour['fases'],
                    "infraManager"    => isset($detour['infraManager']) ? $detour['infraManager'] : null,
                    'uuid'            => $detour['id'],
                    'actions'         => $page ? $page->actions()->value() : null,
                    'employer'        => $page ? $page->employer()->value() : null,
                    'logistic'        => $page ? $page->logistic()->value() : null,
                ],
            ];
        }

        return $this->children = Pages::factory($pages, $this);
    }

    private function getToken()
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

    private function detours($search = null, $status = null, $areas = null)
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $detours = $cache->get('detours');
        // only serve from cache when $search, $status or $areas is not set
        if ($detours !== null && ($search === null && $status === null && $areas === null)) {
            $detours = array_values(A::filter($detours, function ($detour) use ($status) {
                if (!$status) {
                    return $detour['status'] != 'Afgerond';
                }
                return $detour['status'] == $status;
            }));
            return $detours;
        }

        $query = <<<'GRAPHQL'
query detours($organisationId: String!, $filter: DetoursFilter) {
  detours(organisationId: $organisationId, filter:$filter) {
    detours {
      id
      name
      description
      slug
      status
      infraManager {
        name
        website
        phoneNumber
        emailAddress
        logo {
          url
        }
      }
      detourType {
        id
        name
      }
      coverPhoto {
        url
        slug
      }
      fases {
        name
        slug
        status
        description
        beginDate
        endDate
        blockedTransportations {
          id
          name
        }
        availableTransportations {
            id
            name
        }
        areas {
          name
          type
          geometry
        }
        detourlines {
          id
          refinedGeometry
          type
          directionalImpact
        }
      }
    }
    totalCount
  }
}
GRAPHQL;

        // get detours from omleidingennet
        $token = $this->getToken();
        $take = 1000; // todo: pagination

        $filter = [
            'take' => $take,
        ];

        if ($search) {
            $filter['query']  = $search;
        }
        if ($status) {
            $filter['status'] = $status;
        }
        if ($areas) {
            $filter['areas'] = [$areas];
        }

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

            $detours = $data['data']['detours']['detours'];
            $detours = array_values(A::filter($detours, function ($detour) {
                return $detour['status'] != 'Concept';
            }));

            // cache for ttl minutes
            if (($search === null && $status === null && $areas === null)) {
                $cache->set('detours', $detours, option('omleidingennet.plugin.ttl'));
            }

            $detours = array_values(A::filter($detours, function ($detour) use ($status) {
                if (!$status) {
                    return $detour['status'] != 'Afgerond';
                }
                return $detour['status'] == $status;
            }));

            return $detours;
        }
        throw new Exception('Could not fetch detours');
    }

    public function apiDetours()
    {
        $search = get('search');
        $status = get('status');
        $areas = get('areas');
        $detours = $this->detours($search, $status, $areas);

        return Response::json(['detours' => $detours]);
    }

    public function apiDetour($detourId)
    {
        $detours = $this->detours();
        $requestedDetour = null;
        foreach ($detours as $detour) {
            if ($detour['id'] === $detourId) {
                $requestedDetour = $detour;
                break;
            }
        }

        if ($requestedDetour !== null) {
            return Response::json(['detours' => [$requestedDetour]]);
        } else {
            return Response::json(['error' => 'Detour not found'], 404);
        }
    }

    private function fetchAreas()
    {
        $key = 'areas.' . option('omleidingennet.plugin.organisationId');
        $cache = kirby()->cache('omleidingennet.plugin');
        $areas = $cache->get($key);
        // only serve from cache when $search, $status or $areas is not set
        if ($areas !== null) {
            return $areas;
        }

        $query = <<<'GRAPHQL'
query areas($organisationId: String!) {
  areas(organisationId: $organisationId) {
    id
    name
    type
  }
}
GRAPHQL;

        // get detours from omleidingennet
        $token = $this->getToken();

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

            $areas = $data['data']['areas'];

            // cache for ttl minutes
            if ($areas) {
                $cache->set($key, $areas, option('omleidingennet.plugin.ttl'));
            }

            return $areas;
        }
        throw new Exception('Could not fetch areas');
    }

    public function areas()
    {
        $areas = $this->fetchAreas();
        $pages = [];

        foreach ($areas as $key => $area) {
            $slug = $area['id'];
            $page = $this->subpages()->find($slug);

            $pages[] = [
                'slug'      => $area['id'],
                'num'       => 0,
                'template'  => 'area',
                'model'     => 'area',
                'files'     => $page ? $page->files()->toArray() : null,
                'content' => [
                    'title'          => $page ? $page->title()->value() : $area['name'],
                    "type"            => $area['type'],
                    'uuid'            => $area['id'],
                    'name' => $area['id']
                ],
            ];
        }

        return Pages::factory($pages, $this);
    }
}
