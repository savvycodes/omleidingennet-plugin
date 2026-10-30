<?php

namespace Omleidingennet\Plugin;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Response;
use Kirby\Toolkit\Str;

class PointOfInterestsPage extends Page
{
    protected $localSubpages = null;

    public function subpages()
    {
        if ($this->localSubpages !== null) {
            return $this->localSubpages;
        }

        return $this->localSubpages = Pages::factory(
            preferNumberedChildren($this->inventory()['children']),
            $this
        );
    }

    public function children(): Pages
    {
        if ($this->children instanceof Pages) {
            return $this->children;
        }

        $search = Api::filter('search_query');
        $type = Api::filter('poi_type');
        $results = $this->pois($search, $type);
        $pages = [];

        foreach ($results as $poi) {
            $slug = Str::slug($poi['name']);
            $page = $this->subpages()->find($slug);
            $pages[] = [
                'slug' => $slug,
                'num' => 0,
                'template' => 'point-of-interest',
                'model' => 'point-of-interest',
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

    private function pois($search = null, $type = null, $refresh = false)
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $backupCache = kirby()->cache('backup.omleidingennet.plugin');

        $hash = hash('sha256', '');

        $cacheKey = "pois." . $hash . option('omleidingennet.plugin.organisationId');
        $backupCacheKey = "backup.pois." . $hash . option('omleidingennet.plugin.organisationId');

        $pois = $cache->get($cacheKey);
        if ($refresh === false) {
            if ($pois !== null) {
                return $this->filterPois($pois, $search, $type);
            }

            $backupPois = $backupCache->get($backupCacheKey);
            if ($backupPois !== null) {
                return $this->filterPois($backupPois, $search, $type);
            }

            $cliPois = Cache::cli('omleidingennet.plugin')->get($cacheKey);
            if ($cliPois !== null) {
                return $this->filterPois($cliPois, $search, $type);
            }

            return $this->filterPois(Cache::cli('backup.omleidingennet.plugin')->get($backupCacheKey) ?? [], $search, $type);
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

        $pois = Api::paginate($query, [
            'organisationId' => option('omleidingennet.plugin.organisationId'),
        ], 'pointOfInterests', 'pois', 1000);
        Api::validatePois($pois);
        Cache::store($cache, $backupCache, $cacheKey, $backupCacheKey, $pois);

        return $this->filterPois($pois, $search, $type);
    }

    private function filterPois(array $pois, ?string $search, ?string $type): array
    {
        return array_values(array_filter($pois, static function ($poi) use ($search, $type) {
            return ($search === null || $search === '' ||
                stripos($poi['name'] ?? '', $search) !== false ||
                stripos($poi['description'] ?? '', $search) !== false) &&
                ($type === null || $type === '' || ($poi['type']['id'] ?? null) === $type);
        }));
    }

    public function refreshCache()
    {
        return $this->pois(null, null, true);
    }

    public function apiPointOfInterests()
    {
        $search = Api::filter('search_query');
        $pois = $this->pois($search, Api::filter('poi_type'));

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
        $hash = hash('sha256', '');
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

        $types = Api::request($query, [
            'organisationId' => option('omleidingennet.plugin.organisationId'),
        ], 'pointOfInterestTypes');
        Api::validateList($types, ['id' => 'string', 'name' => 'string']);
        Cache::store($cache, $backupCache, $cacheKey, $backupCacheKey, $types);

        return $types;
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
