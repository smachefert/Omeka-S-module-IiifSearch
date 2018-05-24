<?php

namespace IiifSearch\Service\ViewHelper;

use IiifSearch\View\Helper\IiifSearch;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class IiifSearchFactory
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        return new IiifSearch($tempFileFactory, $basePath);
    }
}