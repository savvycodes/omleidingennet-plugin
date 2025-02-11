<?php

namespace Omleidingennet\Plugin;

use Exception;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Response;
use Kirby\Http\Remote;
use Sentry;
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

    public function children(): Pages
    {
        if ($this->children instanceof Pages) {
            return $this->children;
        }

        $search = get('search_query');
        $status = get('detour_status');
        $areas = get('areas');
        $results = $this->detours($search, $status, $areas);
        $pages = [];

        foreach ($results as $detour) {
            $slug = $detour['slug'];
            $page = $this->subpages()->find($slug);

            $pages[] = [
                'slug' => $detour['slug'],
                'num' => 0,
                'template' => 'detour',
                'model' => 'detour',
                'files' => $page ? $page->files()->toArray() : null,
                'content' => [
                    "active_fase" => $this->activeFase($detour['fases']),
                    'title' => $detour['name'],
                    "description" => $detour['description'],
                    "detour_status" => $detour['status'],
                    "slug" => $detour['slug'],
                    "website" => $detour['website'],
                    "cover" => $detour['coverPhoto'] ? $detour['coverPhoto']['url'] : null,
                    "fases" => $detour['fases'],
                    "infraManager" => isset($detour['infraManager']) ? $detour['infraManager'] : null,
                    'uuid' => $detour['id'],
                    'actions' => $page ? $page->actions()->value() : null,
                    'employer' => $page ? $page->employer()->value() : null,
                    'logistic' => $page ? $page->logistic()->value() : null,
                ],
            ];
        }

        return $this->children = Pages::factory($pages, $this);
    }

    private function getToken()
    {
        return Token::get();
    }

    private function detours($search = null, $status = null, $areas = null)
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $backupCache = kirby()->cache('backup.omleidingennet.plugin');

        $hashKey = (empty($status) ? '' :  $status) . (empty($areas) ? '' : $areas);
        $hash = hash('sha256', $hashKey);

        // create sha256 of status and areas
        $cacheKey = "detours." . $hash . option('omleidingennet.plugin.organisationId');
        $backupCacheKey = "backup.detours." . $hash . option('omleidingennet.plugin.organisationId');


        $detours = $cache->get($cacheKey);
        // only serve from cache when $search, $status or $areas is not set
        if ($detours !== null && empty(isset($search))) {
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
      website
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
        id
        name
        slug
        status
        description
        beginDate
        beginDateAlternative
        endDate
        endDateAlternative
        blockedTransportations {
          id
          name
        }
        availableTransportations {
            id
            name
        }
        limitedTransportations {
            id
            name
        }
        areas {
          id
          name
          type
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
        $take = 10; // todo: pagination

        $filter = [
            'take' => $take,
        ];

        if (isset($search) && !empty($search)) {
            $filter['query'] = $search;
        }
        if (isset($status) && !empty($status)) {
            $filter['status'] = $status;
        }
        if (isset($areas) && !empty($areas)) {
            $filter['areas'] = [$areas];
        }

        // contiously fetch detours until totalCount is reached
        $detours = [];
        $skip = 0;
        $totalCount = 0;
        try {
            do {
                $filter['skip'] = $skip;
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

                    $totalCount = $data['data']['detours']['totalCount'];
                    $detours = array_merge($detours, $data['data']['detours']['detours']);
                    $skip += $take;
                } else {
                    throw new Exception('Could not fetch detours: ' . $res->code());
                }
            } while (count($detours) < $totalCount);
        } catch (Exception $e) {
            \Sentry\captureException($e);

            // Fallback to backup cache if request fails
            $backupDetours = $backupCache->get($backupCacheKey);
            if ($backupDetours !== null) {
                return $backupDetours;
            }
        }

        // cache for ttl minutes
        if (empty($search)) {
            $cache->set($cacheKey, $detours, option('omleidingennet.plugin.ttl'));
            $backupCache->set($backupCacheKey, $detours); // no timout for backup cache
        }

        return $detours;
    }

    public function apiDetours()
    {
        $search = get('search_query');
        $status = get('detour_status');
        $areas = get('areas');
        $detours = $this->detours($search, $status, $areas);

        foreach ($detours as &$detour) {
            $detour['activeFase'] = $this->activeFase($detour['fases']);
            $detour['period'] = $this->period($detour);
        }

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
            if (isset($data['errors'])) {
                throw new Exception(json_encode($data['errors'][0]['message']));
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

        foreach ($areas as $area) {
            $slug = $area['id'];
            $page = $this->subpages()->find($slug);

            $pages[] = [
                'slug' => $area['id'],
                'num' => 0,
                'template' => 'area',
                'model' => 'area',
                'files' => $page ? $page->files()->toArray() : null,
                'content' => [
                    'title' => $page ? $page->title()->value() : $area['name'],
                    "type" => $area['type'],
                    'uuid' => $area['id'],
                    'name' => $area['id']
                ],
            ];
        }

        return Pages::factory($pages, $this);
    }

    private function activeFase($fases)
    {
        foreach ($fases as $fase) {
            if ($fase['status'] === 'In uitvoering') {
                $fase['label'] = 'Huidige fase';
                return $fase;
            } else if ($fase['status'] === 'Op de planning') {
                $fase['label'] = 'Eerstvolgende fase';
                return $fase;
            }
        }
    }

    // reuse period function from Detour model, in the future we might want to move it here
    private function period($detour)
    {
        $page = $this->children()->find($detour['slug']);
        return $page ? $page->period() : '';
    }
}
