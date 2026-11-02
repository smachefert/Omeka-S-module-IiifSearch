<?php declare(strict_types=1);

namespace IiifSearch;

return [
    'service_manager' => [
        'invokables' => [
            Stdlib\AltoProvenance::class => Stdlib\AltoProvenance::class,
            Stdlib\ToAltoConverter::class => Stdlib\ToAltoConverter::class,
        ],
        'factories' => [
            Stdlib\ContentVisibility::class => Service\Stdlib\ContentVisibilityFactory::class,
        ],
    ],
    'media_ingesters' => [
        'invokables' => [
            // This is an internal ingester.
            'iiifsearch_ocr' => Media\Ingester\OcrFile::class,
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
            'iiifSearch2' => Service\ViewHelper\IiifSearch2Factory::class,
            'iiifSearchTeiStructure' => Service\ViewHelper\IiifSearchTeiStructureFactory::class,
            'itemFileResources' => Service\ViewHelper\ItemFileResourcesFactory::class,
            'xmlAltoSingle' => Service\ViewHelper\XmlAltoSingleFactory::class,
            'xmlAltoSplitter' => Service\ViewHelper\XmlAltoSplitterFactory::class,
        ],
        'invokables' => [
            'fixUtf8' => View\Helper\FixUtf8::class,
            'iiifAltoAnnotations' => View\Helper\IiifAltoAnnotations::class,
            'iiifAltoAnnotations2' => View\Helper\IiifAltoAnnotations2::class,
            'iiifSearchAnnotationUrl' => View\Helper\IiifSearchAnnotationUrl::class,
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
        'factories' => [
            'isAllowedOcr' => Service\ControllerPlugin\IsAllowedOcrFactory::class,
        ],
        'invokables' => [
            'jsonLd' => Mvc\Controller\Plugin\JsonLd::class,
        ],
    ],
    'router' => [
        'routes' => [
            // Redirect the old route.
            // Used by un-updated cached manifests and by module DomainManager.
            'iiifsearch-legacy' => [
                'type' => \Laminas\Router\Http\Regex::class,
                'options' => [
                    'regex' => '/iiif-search(?<path>/.*)?',
                    'spec' => '/iiif-search%path%',
                    'defaults' => [
                        '__NAMESPACE__' => 'IiifSearch\Controller',
                        'controller' => 'Search',
                        'action' => 'legacy',
                    ],
                ],
            ],
            // Avoid the route of module IiifServer for an url without identifier.
            'iiifsearch-root' => [
                'type' => \Laminas\Router\Http\Literal::class,
                'priority' => 10,
                'options' => [
                    'route' => '/iiif/search',
                    'defaults' => [
                        '__NAMESPACE__' => 'IiifSearch\Controller',
                        'controller' => 'Search',
                        'action' => 'index',
                    ],
                ],
            ],
            /*
             * @link https://iiif.io/api/search/1.0/#search
             */
            // Priority over the image route of module IiifServer, that may match
            // "/iiif/search/{id}/search/{n}/info.json" too.
            'iiifsearch' => [
                'type' => \Laminas\Router\Http\Segment::class,
                'priority' => 10,
                'options' => [
                    'route' => '/iiif/search/:id',
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
                    // The optional version of the manifest that declares the
                    // service, when it is not the default one, so the targets of
                    // the results are the canvases of this manifest. It cannot
                    // be set in the parent route, that has child routes.
                    // This format follows the example of the specification.
                    // It allows to make a quick distinction between level 0 and level 1.
                    // @link https://iiif.io/api/search/1.0/#service-description
                    'search' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '[/v:manifest]/search',
                            'constraints' => [
                                'manifest' => '2|3',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'IiifSearch\Controller',
                                'controller' => 'Search',
                                'action' => 'index',
                                'service' => 'SearchService1',
                            ],
                        ],
                    ],
                    'search-1' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '[/v:manifest]/search/1',
                            'constraints' => [
                                'manifest' => '2|3',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'IiifSearch\Controller',
                                'controller' => 'Search',
                                'action' => 'index',
                                'service' => 'SearchService1',
                            ],
                        ],
                    ],
                    'search-2' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '[/v:manifest]/search/2',
                            'constraints' => [
                                'manifest' => '2|3',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'IiifSearch\Controller',
                                'controller' => 'Search',
                                'action' => 'index2',
                                'service' => 'SearchService2',
                            ],
                        ],
                    ],
                    // Some Mirador plugins request the search service as an image
                    // service, so redirect to the image of the first page.
                    'search-info' => [
                        'type' => \Laminas\Router\Http\Regex::class,
                        'options' => [
                            'regex' => '/search(?:/[12])?/info\.json',
                            'spec' => '/search/info.json',
                            'defaults' => [
                                'action' => 'search-info',
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
                    // Use Regex routes: Segment's :name parameter syntax
                    // greedily eats the trailing ".xml" / ".json" extension as
                    // part of the parameter name, defeating the \d+ constraint.
                    'alto-page' => [
                        'type' => \Laminas\Router\Http\Regex::class,
                        'options' => [
                            'regex' => '/alto/(?<page>\d+)\.xml',
                            'spec' => '/alto/%page%.xml',
                            'defaults' => [
                                'action' => 'alto-page',
                            ],
                        ],
                    ],
                    'alto' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/alto.xml',
                            'defaults' => [
                                'action' => 'alto',
                            ],
                        ],
                    ],
                    'annotation-page' => [
                        'type' => \Laminas\Router\Http\Regex::class,
                        'options' => [
                            'regex' => '/annotations/(?<page>\d+)\.json',
                            'spec' => '/annotations/%page%.json',
                            'defaults' => [
                                'action' => 'annotation-page',
                            ],
                        ],
                    ],
                    'hocr-page' => [
                        'type' => \Laminas\Router\Http\Regex::class,
                        'options' => [
                            'regex' => '/hocr/(?<page>\d+)\.html',
                            'spec' => '/hocr/%page%.html',
                            'defaults' => [
                                'action' => 'hocr-page',
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
            'iiifsearch_tsv_prefer_full' => false,
            'iiifsearch_spelling_rules' => '',
            'iiifsearch_wildcards' => false,
            'iiifsearch_fuzzy' => false,

            // Three independent toggles control what the IiifServer canvas
            // event listener appends on each canvas. Inherits the legacy
            // iiifsearch_alto_canvas_inject value on upgrade; once migrated,
            // the legacy key is dropped.
            'iiifsearch_canvas_seealso_alto' => true,
            'iiifsearch_canvas_annotations' => true,
            'iiifsearch_canvas_seealso_hocr' => false,

            // OCR extraction (formerly module ExtractOcr). Per-format
            // destinations: each format is independently routed to one or more
            // of "file" (default, flat file under files/), "media" (Omeka media
            // on the item), "digital_object" (DigitalObject module). Flat file
            // is the recommended default for everything.
            'iiifsearch_extract_dests_tsv' => ['file'],
            'iiifsearch_extract_dests_tsv_by_word' => ['file'],
            'iiifsearch_extract_dests_alto' => ['file'],
            'iiifsearch_extract_dests_pdf2xml' => [],

            // Per-item OCR policy. Two URL-encoded Omeka query strings: an
            // include filter (empty = all items) and an exclude filter
            // (empty = none). An item is eligible when it matches the include
            // filter and does not match the exclude filter. Used by both the
            // api.create.post listener and the bulk Extract OCR job, and
            // consulted by the orphan-file cleanup to remove already-produced
            // OCR for items that have been moved out of the policy.
            'iiifsearch_extract_query_include' => '',
            'iiifsearch_extract_query_exclude' => '',

            'iiifsearch_extract_content_store' => [],
            'iiifsearch_extract_content_property' => null,
            'iiifsearch_extract_content_visibility' => 'public',
            'iiifsearch_tei_structure_store' => false,
            'iiifsearch_tei_structure_property' => 'dcterms:tableOfContents',
            'iiifsearch_extract_content_language' => '',
            'iiifsearch_extract_create_empty_file' => false,
            'iiifsearch_extract_reocr_no_text_layer' => false,

            // Optional PDF text-layer rebuild. When set to 'update' the source
            // PDF (media or digital object) is replaced in place by an
            // ocrmypdf-processed copy carrying an invisible text layer; 'new'
            // creates a fresh media (or digital object, mirroring the source)
            // holding the OCRed PDF. Empty value disables the feature. Requires
            // ocrmypdf + ghostscript on PATH; phase 1 does not handle items
            // without a PDF (image-only items) nor PDFs that already carry a
            // text layer (skipped).
            'iiifsearch_extract_create_pdf_text_layer' => '',
            // When set, multi-PDF items (typically scanned books split into one
            // PDF per page) are concatenated via ghostscript before re-OCR; the
            // resulting consolidated PDF is then attached as a single new media
            // or digital object. The mode is forced to "new" in that case
            // because update-in-place is ambiguous across several source rows.
            'iiifsearch_extract_concat_monopage_pdf' => false,

            'iiifsearch_extract_ocr_images' => false,
            'iiifsearch_extract_ocr_language' => '',
            'iiifsearch_alto_pairing_mode' => 'auto',
        ],
    ],
];
