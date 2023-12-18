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
        $status = get('detour_status');
        $areas = get('areas');
        $results = $this->detours($search, $status, $areas);
        $pages = [];

        foreach ($results as $key => $detour) {
            $slug = $detour['slug'];
            $page = $this->subpages()->find($slug);

            $pages[] = [
                'slug' => $detour['slug'],
                'num' => 0,
                'template' => 'detour',
                'model' => 'detour',
                'files' => $page ? $page->files()->toArray() : null,
                'content' => [
                    'title' => $detour['name'],
                    "description" => $detour['description'],
                    "detour_status" => $detour['status'],
                    "slug" => $detour['slug'],
                    "website" => $detour['website'],
                    "cover" => $detour['coverPhoto'],
                    "type" => $detour['detourType'],
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
        $hashKey = (empty($status) ? '' :  $status) . (empty($areas) ? '' : $areas);
        $hash = hash('sha256', $hashKey);

        // create sha256 of status and areas
        $cacheKey = "detours." . $hash . option('omleidingennet.plugin.organisationId');

        $detours = $cache->get($cacheKey);
        // only serve from cache when $search, $status or $areas is not set
        if ($detours !== null && empty(isset($search))) {
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
        endDate
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

        if (isset($search) && !empty($search)) {
            $filter['query'] = $search;
        }
        if (isset($status) && !empty($status)) {
            $filter['status'] = $status;
        }
        if (isset($areas) && !empty($areas)) {
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
            if (empty($search)) {
                $cache->set($cacheKey, $detours, option('omleidingennet.plugin.ttl'));
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
}
