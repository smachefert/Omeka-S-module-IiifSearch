<?php

namespace IiifSearch\Service\Controller;

use IiifSearch\Controller\PdfController;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class PdfControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        // Not used for the moment, should we use it ?
        $store = $services->get('Omeka\File\Store');

        // Copied from LocalFactory.php, there should be a better way to process
        $config = $services->get('Config');

        $baseUri = $config['file_store']['local']['base_uri'];
        if (null === $baseUri) {
            $helpers = $services->get('ViewHelperManager');
            $serverUrlHelper = $helpers->get('ServerUrl');
            $basePathHelper = $helpers->get('BasePath');
            $baseUri = $serverUrlHelper($basePathHelper('files'));
        }

        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        return new PdfController($store, $basePath, $baseUri);
    }
}
