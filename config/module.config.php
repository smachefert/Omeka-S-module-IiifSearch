<?php declare(strict_types=1);

namespace IiifSearch;

return [
    'view_manager' => [
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'fixUtf8' => Service\ViewHelper\FixUtf8Factory::class,
            'iiifSearch' => Service\ViewHelper\IiifSearchFactory::class,
            'iiifSearch2' => Service\ViewHelper\IiifSearch2Factory::class,
            'xmlAltoSingle' => Service\ViewHelper\XmlAltoSingleFactory::class,
            'xmlAltoSplitter' => Service\ViewHelper\XmlAltoSplitterFactory::class,
        ],
        'invokables' => [
            'iiifAltoAnnotations' => View\Helper\IiifAltoAnnotations::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
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
                    'search-1' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/search/1',
                            'defaults' => [
                                '__NAMESPACE__' => 'IiifSearch\Controller',
                                'controller' => 'Search',
                                'action' => 'index',
                                'service' => 'SearchService1',
                            ],
                        ],
                    ],
                    'search-2' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/search/2',
                            'defaults' => [
                                '__NAMESPACE__' => 'IiifSearch\Controller',
                                'controller' => 'Search',
                                'action' => 'index2',
                                'service' => 'SearchService2',
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
                    'alto-page' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/alto/:page.xml',
                            'constraints' => [
                                'page' => '\d+',
                            ],
                            'defaults' => [
                                'action' => 'alto-page',
                            ],
                        ],
                    ],
                    'annotation-page' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/annotations/:page.json',
                            'constraints' => [
                                'page' => '\d+',
                            ],
                            'defaults' => [
                                'action' => 'annotation-page',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => \Laminas\I18n\Translator\Loader\Gettext::class,
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'iiifsearch' => [
        'config' => [
            'iiifsearch_versions' => ['1', '2'],
            'iiifsearch_minimum_query_length' => 3,
            'iiifsearch_disable_search_media_values' => false,
            'iiifsearch_xml_image_match' => 'order',
            'iiifsearch_xml_fix_mode' => 'no',
            'iiifsearch_alto_canvas_inject' => true,
            'iiifsearch_alto_page_match' => 'order',
            // OCR extraction (formerly module ExtractOcr).
            'iiifsearch_extract_types_files' => [],
            'iiifsearch_extract_types_media' => [],
            'iiifsearch_extract_content_store' => [],
            'iiifsearch_extract_content_property' => null,
            'iiifsearch_extract_content_language' => '',
            'iiifsearch_extract_create_empty_file' => false,
            'iiifsearch_extract_reocr_no_text_layer' => true,
            'iiifsearch_extract_ocr_images' => true,
            'iiifsearch_extract_ocr_language' => '',
            'iiifsearch_alto_pairing_mode' => 'auto',
        ],
    ],
];
