<?php

namespace IiifSearch;

return [
    'view_manager' => [
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'iiifSearch' => Service\ViewHelper\IiifSearchFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'IiifSearch\Controller\Search' => Service\Controller\SearchControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'jsonLd' => Mvc\Controller\Plugin\JsonLd::class,
        ],
    ],
    'router' => [
        'routes' => [
            /**
             * @link https://iiif.io/api/search/1.0/#search
             */
            'iiifsearch' => [
                'type' => \Zend\Router\Http\Segment::class,
                'options' => [
                    'route' => '/iiif-search/:id',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'IiifSearch\Controller',
                        'controller' => 'Search',
                        'action' => 'index',
                    ],
                ],
            ],
        ],
    ],
];
