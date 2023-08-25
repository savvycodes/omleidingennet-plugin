<?php

namespace Omleidingennet\Plugin;

use Exception;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Http\Remote;

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

    $results = $this->detours();
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
          "infraManager"    => $detour['infraManager'],
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

  private function detours()
  {
    $cache = kirby()->cache('omleidingennet.plugin');
    $detours = $cache->get('detours');

    if ($detours !== null) {
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
          'filter' => []
        ]
      ])
    ]);

    if ($res->code() === 200) {
      $data = $res->json();
      if (isset($data['error'])) {
        throw new Exception($data['error']['message']);
      }
      // cache for ttl minutes
      $cache->set('detours', $data['data']['detours']['detours'], option('omleidingennet.plugin.ttl'));
      return $data['data']['detours']['detours'];
    }

    throw new Exception('Could not fetch detours');
  }
}
