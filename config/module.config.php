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
        'invokables' => [
            'IiifSearch\Controller\Search' => Controller\SearchController::class,
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
                'may_terminate' => true,
                'child_routes' => [
                    // @link https://iiif.io/api/presentation/2.1/#annotation-list
                    // Annotation name may follow the name of the canvas.
                    // In 2.1, canvas id is media id and name is p + index.
                    // In 3.0, canvas id is item id and name is media id.
                    'annotation-list' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/list/:name',
                            'constraints' => [
                                'name' => 'p?\d+',
                            ],
                            'defaults' => [
                                'action' => 'annotation-list',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
