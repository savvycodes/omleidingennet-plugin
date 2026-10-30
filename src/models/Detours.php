<?php

namespace Omleidingennet\Plugin;

use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Response;
use Kirby\Toolkit\A;

class DetoursPage extends Page
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
        $status = Api::filter('detour_status');
        $areas = Api::filter('areas');
        $results = $this->detours($search, $status, $areas);
        $pages = [];
        $remoteSlugs = [];

        foreach ($results as $detour) {
            $slug = $detour['slug'];
            $remoteSlugs[$slug] = true;
            $page = $this->subpages()->find($slug);
            $content = $page ? $page->content()->toArray() : [];

            $pages[] = [
                'slug' => $slug,
                'num' => 0,
                'template' => 'detour',
                'model' => 'detour',
                'files' => $page ? $page->files()->toArray() : null,
                'content' =>  A::append(
                    $content,
                    [
                        "active_fase" => $this->activeFase($detour['fases']),
                        'title' => $detour['name'],
                        "description" => $detour['description'],
                        "current_status" => $detour['status'],
                        "detour_type" => $detour['detourType']['name'],
                        "slug" => $slug,
                        "website" => $detour['website'],
                        "cover" => $detour['coverPhoto'] ? $detour['coverPhoto']['url'] : null,
                        "fases" => $detour['fases'],
                        "infraManager" => isset($detour['infraManager']) ? $detour['infraManager'] : null,
                        'uuid' => $detour['id'],
                    ]
                ),
            ];
        }

        // Keep locally stored detours editable after they have been removed from Omleidingennet.
        foreach ($this->subpages() as $page) {
            if (isset($remoteSlugs[$page->slug()])) {
                continue;
            }

            $pages[] = [
                'slug' => $page->slug(),
                'num' => $page->num(),
                'template' => 'detour',
                'model' => 'detour',
                'files' => $page->files()->toArray(),
                'content' => $page->content()->toArray(),
            ];
        }

        return $this->children = Pages::factory($pages, $this);
    }

    private function detours($search = null, $status = null, $areas = null, $hideCompleted = false, $refresh = false)
    {
        $cache = kirby()->cache('omleidingennet.plugin');
        $backupCache = kirby()->cache('backup.omleidingennet.plugin');

        $hash = hash('sha256', '');
        $cacheKey = "detours." . $hash . option('omleidingennet.plugin.organisationId');
        $backupCacheKey = "backup.detours." . $hash . option('omleidingennet.plugin.organisationId');

        $detours = $cache->get($cacheKey);
        if ($refresh === false) {
            if ($detours !== null) {
                return $this->filterDetours($detours, $search, $status, $areas, $hideCompleted);
            }

            $backupDetours = $backupCache->get($backupCacheKey);
            if ($backupDetours !== null) {
                return $this->filterDetours($backupDetours, $search, $status, $areas, $hideCompleted);
            }

            $cliDetours = Cache::cli('omleidingennet.plugin')->get($cacheKey);
            if ($cliDetours !== null) {
                return $this->filterDetours($cliDetours, $search, $status, $areas, $hideCompleted);
            }

            $backupCliDetours = Cache::cli('backup.omleidingennet.plugin')->get($backupCacheKey) ?? [];
            return $this->filterDetours($backupCliDetours, $search, $status, $areas, $hideCompleted);
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

        // Failed/incomplete fetches must never replace the last complete cache.
        $detours = Api::paginate($query, [
            'organisationId' => option('omleidingennet.plugin.organisationId'),
        ], 'detours', 'detours', 10);
        Api::validateDetours($detours);

        // cache until the Kirby command refreshes it
        Cache::store($cache, $backupCache, $cacheKey, $backupCacheKey, $detours);

        return $this->filterDetours($detours, $search, $status, $areas, $hideCompleted);
    }

    private function filterDetours($detours, $search = null, $status = null, $areas = null, $hideCompleted = false)
    {
        if (isset($search) && !empty($search)) {
            $detours = array_values(array_filter($detours, function ($detour) use ($search) {
                return stripos($detour['name'] ?? '', $search) !== false
                    || stripos($detour['description'] ?? '', $search) !== false;
            }));
        }

        if (isset($status) && !empty($status)) {
            $detours = array_values(array_filter($detours, function ($detour) use ($status) {
                return ($detour['status'] ?? null) === $status;
            }));
        }

        if (isset($areas) && !empty($areas)) {
            $selectedAreas = array_filter(array_map('trim', explode(',', $areas)));

            $detours = array_values(array_filter($detours, function ($detour) use ($selectedAreas) {
                foreach ($detour['fases'] ?? [] as $fase) {
                    foreach ($fase['areas'] ?? [] as $area) {
                        if (in_array($area['id'] ?? null, $selectedAreas, true)) {
                            return true;
                        }
                    }
                }

                return false;
            }));
        }

        // if filter is not set and showAll == false, filter out detours with status 'Afgerond'
        if ((empty($status) || $status === '') && $hideCompleted) {
            $detours = array_values(array_filter($detours, function ($detour) {
                return $detour['status'] !== 'Afgerond';
            }));
        }

        return $detours;
    }

    public function refreshCache()
    {
        return $this->detours(null, null, null, false, true);
    }

    public function apiDetours()
    {
        $search = Api::filter('search_query');
        $status = Api::filter('detour_status');
        $areas = Api::filter('areas');
        $detours = $this->detours($search, $status, $areas, true);

        foreach ($detours as &$detour) {
            $detour['activeFase'] = $this->activeFase($detour['fases']);
            $detour['period'] = $this->period($detour);
            $detour['startDate'] = $this->startDate($detour);
            $detour['endDate'] = $this->endDate($detour);
            $detour['areas'] = $this->allAreas($detour);
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

    private function fetchAreas($refresh = false)
    {
        $key = 'areas.' . option('omleidingennet.plugin.organisationId');
        $backupKey = 'backup.areas.' . option('omleidingennet.plugin.organisationId');
        $cache = kirby()->cache('omleidingennet.plugin');
        $backupCache = kirby()->cache('backup.omleidingennet.plugin');
        $areas = $cache->get($key);
        if ($refresh === false) {
            if ($areas !== null) {
                return $areas;
            }

            $backupAreas = $backupCache->get($backupKey);
            if ($backupAreas !== null) {
                return $backupAreas;
            }

            $cliAreas = Cache::cli('omleidingennet.plugin')->get($key);
            if ($cliAreas !== null) {
                return $cliAreas;
            }

            return Cache::cli('backup.omleidingennet.plugin')->get($backupKey) ?? [];
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

        $areas = Api::request($query, [
            'organisationId' => option('omleidingennet.plugin.organisationId'),
        ], 'areas');
        Api::validateList($areas, ['id' => 'string', 'name' => 'string', 'type' => 'string']);
        Cache::store($cache, $backupCache, $key, $backupKey, $areas);

        return $areas;
    }

    public function areas()
    {
        $areas = $this->fetchAreas();
        $pages = [];

        if (!$areas) return Pages::factory($pages, $this);

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

    public function refreshAreasCache()
    {
        return $this->fetchAreas(true);
    }

    private function activeFase($fases)
    {
        $selected = null;
        if (!$fases || count($fases) === 0) return $selected;
        foreach ($fases as $fase) {
            if ($fase['status'] === 'In uitvoering') {
                $fase['label'] = 'Huidige fase';
                $selected = $fase;
                break;
            } else if ($fase['status'] === 'Op de planning') {
                $fase['label'] = 'Eerstvolgende fase';
                $selected = $fase;
                break;
            }
        }
        return $selected;
    }

    // reuse period functions from Detour model
    private function period($detour)
    {
        $page = $this->children()->find($detour['slug']);
        return $page ? $page->period() : '';
    }

    private function startDate($detour)
    {
        $page = $this->children()->find($detour['slug']);
        return $page ? $page->startDate() : '';
    }

    private function endDate($detour)
    {
        $page = $this->children()->find($detour['slug']);
        return $page ? $page->endDate() : '';
    }

    private function allAreas($detour)
    {
        $page = $this->children()->find($detour['slug']);
        return $page ? $page->areas() : '';
    }
}
