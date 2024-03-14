<?php

F::loadClasses([
    'omleidingennet\\plugin\\token' => 'src/Token.php',
    'omleidingennet\\plugin\\detourspage' => 'src/models/Detours.php',
    'omleidingennet\\plugin\\pointofinterestspage' => 'src/models/PointOfInterests.php',
    'omleidingennet\\plugin\\detourpage' => 'src/models/Detour.php',
    'omleidingennet\\plugin\\pointofinterestpage' => 'src/models/PointOfInterest.php',
], __DIR__);

Kirby::plugin('omleidingennet/plugin', [
    'blueprints' => [
        'pages/detours' => __DIR__ . '/blueprints/detours.yml',
        'pages/detour' => __DIR__ . '/blueprints/detour.yml',
        'pages/point-of-interests' => __DIR__ . '/blueprints/point-of-interests.yml',
        'pages/point-of-interests' => __DIR__ . '/blueprints/point-of-interest.yml',
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
                return $poisPage->apiPointOfInterests($id);
            }
        ],
    ],

]);
