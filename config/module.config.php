<?php declare(strict_types=1);

namespace IiifSearch;

return [
    'service_manager' => [
        'factories' => [
            // Copied from EasyAdmin.
            'Omeka\File\TempFileFactory' => Service\File\TempFileFactoryFactory::class,
            'Omeka\File\Validator' => Service\File\ValidatorFactory::class,
        ],
    ],
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
        'factories' => [
            // Copied from EasyAdmin.
            'specifyMediaType' => Service\ControllerPlugin\SpecifyMediaTypeFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            /*
             * @link https://iiif.io/api/search/1.0/#search
             */
            'iiifsearch' => [
                'type' => \Laminas\Router\Http\Segment::class,
                'options' => [
                    'route' => '/iiif-search/:id',
                    'constraints' => [
                        'id' => '[^/]*',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'IiifSearch\Controller',
                        'controller' => 'Search',
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    // This format follows the example of the specification.
                    // It allows to make a quick distinction between level 0 and level 1.
                    // @link https://iiif.io/api/search/1.0/#service-description
                    'search' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/search',
                            'defaults' => [
                                '__NAMESPACE__' => 'IiifSearch\Controller',
                                'controller' => 'Search',
                                'action' => 'index',
                                'service' => 'SearchService1',
                            ],
                        ],
                    ],
                    // @link https://iiif.io/api/presentation/2.1/#annotation-list
                    // Annotation name may follow the name of the canvas.
                    // In 2.1, canvas id is media id and name is p + index.
                    // In 3.0, canvas id is item identifier and name is media id.
                    // TODO Manage identifiers for iiif search annotation list.
                    'annotation-list' => [
                        'type' => \Laminas\Router\Http\Segment::class,
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
