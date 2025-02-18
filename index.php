<?php

use Kirby\Toolkit\Collection;
use Kirby\Cms\App as Kirby;
use Kirby\Filesystem\F;

F::loadOnce(__DIR__ . '/src/Search.php');

F::loadClasses([
    'omleidingennet\\plugin\\token' => 'src/Token.php',
    'omleidingennet\\plugin\\detourspage' => 'src/models/Detours.php',
    'omleidingennet\\plugin\\detourpage' => 'src/models/Detour.php',
    'omleidingennet\\plugin\\pointofinterestspage' => 'src/models/PointOfInterests.php',
    'omleidingennet\\plugin\\pointofinterestpage' => 'src/models/PointOfInterest.php',
], __DIR__);

Kirby::plugin('omleidingennet/plugin', [
    'blueprints' => [
        'pages/detours' => __DIR__ . '/blueprints/detours.yml',
        'pages/detour' => __DIR__ . '/blueprints/detour.yml',
        'pages/point-of-interests' => __DIR__ . '/blueprints/point-of-interests.yml',
        'pages/point-of-interest' => __DIR__ . '/blueprints/point-of-interest.yml',
    ],
    'pageModels' => [
        'detours' => 'omleidingennet\\plugin\\detourspage',
        'detour' => 'omleidingennet\\plugin\\detourpage',
        'point-of-interests' => 'omleidingennet\\plugin\\pointofinterestspage',
        'point-of-interest' => 'omleidingennet\\plugin\\pointofinterestpage',
    ],
    'options' => [
        'url' => 'https://www.omleidingennet.nl',
        'cache' => true,
        'ttl' => 5,
        'organisationId' => '',
        'clientId' => '',
        'clientSecret' => '',
    ],
    'routes' => [
        [
            'pattern' => 'omleidingennet/detours',
            'action' => function () {
                $detoursPage = new \Omleidingennet\Plugin\DetoursPage([
                    'slug' => 'detours',
                    'template' => 'detours',
                ]);
                return $detoursPage->apiDetours();
            }
        ],
        [
            'pattern' => 'omleidingennet/detours/(:any)',
            'action' => function ($detourId) {
                $detoursPage = new \Omleidingennet\Plugin\DetoursPage([
                    'slug' => 'detours',
                    'template' => 'detours',
                ]);
                return $detoursPage->apiDetour($detourId);
            }
        ],
        [
            'pattern' => 'omleidingennet/pois',
            'action' => function () {
                $poisPage = new \Omleidingennet\Plugin\PointOfInterestsPage([
                    'slug' => 'pois',
                    'template' => 'pois',
                ]);
                return $poisPage->apiPointOfInterests();
            }
        ],
        [
            'pattern' => 'omleidingennet/pois/(:any)',
            'action' => function ($id) {
                $poisPage = new \Omleidingennet\Plugin\PointOfInterestsPage([
                    'slug' => 'pois',
                    'template' => 'pois',
                ]);
                return $poisPage->apiPointOfInterest($id);
            }
        ],
    ],
    'components' => [
        'search' => function (Kirby $kirby, Collection $collection, string $query = null, $params = []) {

            // Only replace the search component for Pages collections
            if (is_a($collection, 'Kirby\Cms\Pages') === true) {

                // Separate detours from the rest of the collection
                $nonDetours = $collection->filterBy('template', '!=', 'detour');
                $detours = $collection->filterBy('template', '==', 'detour');

                $nonDetoursResult = null;
                $detoursResult = null;

                // Perform the custom search on detours
                if ($detours->isNotEmpty()) {
                    $detoursResult = \Omleidingennet\Plugin\searchDetours($detours, $query, $params);
                }

                // Perform the native search on the rest of the collection
                $nonDetoursResult = $kirby->nativeComponent('search')($kirby, $nonDetours, $query, $params);

                // Return the non-null result or merge if both are non-null
                if ($detoursResult && $nonDetoursResult) {
                    // Ensure both are objects that have the merge method
                    return $detoursResult->merge($nonDetoursResult);
                } elseif ($detoursResult) {
                    return $detoursResult;
                } elseif ($nonDetoursResult) {
                    return $nonDetoursResult;
                } else {
                    return null; // If both are null, return null
                }
            }

            // Use the native component for other searches (users, files, etc.)
            return $kirby->nativeComponent('search')($kirby, $collection, $query, $params);
        }
    ]
]);
