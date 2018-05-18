<?php

namespace IiifSearch\Service\Controller;

use IiifSearch\Controller\PdfController;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class PdfControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        $store = $services->get('Omeka\File\Store');
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        return new PdfController($store, $basePath);
    }
}