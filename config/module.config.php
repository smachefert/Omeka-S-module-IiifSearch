<?php

namespace IiifSearch;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'view_helpers' => [
        'invokables' => [

        ],
        'factories' => [
            'iiifSearch' => Service\ViewHelper\IiifSearchFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
        ],
        'factories' => [
            'IiifSearch\Controller\Pdf' => Service\Controller\PdfControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'jsonLd' => Mvc\Controller\Plugin\JsonLd::class,
        ],
    ],
    'router' => [
        'routes' => [
            'iiifsearch_pdf' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/iiif-search/:id',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'IiifSearch\Controller',
                        'controller' => 'Pdf',
                        'action' => 'index',
                    ],
                ],
            ],
            'iiifsearch_pdf_researchInfo' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/iiif-search/:id',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'IiifSearch\Controller',
                        'controller' => 'Pdf',
                        'action' => 'ocrResearch',
                    ],
                ],
            ],
        ],
    ],
];
