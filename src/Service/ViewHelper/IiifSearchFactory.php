<?php declare(strict_types=1);

namespace IiifSearch\Service\ViewHelper;

use IiifSearch\View\Helper\IiifSearch;
use Interop\Container\ContainerInterface;

class IiifSearchFactory
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $plugins = $services->get('ControllerPluginManager');
        return new IiifSearch(
            $services->get('Omeka\Logger'),
            empty($config['iiifserver']['config']['iiifserver_enable_utf8_fix'])
                ? null
                : $services->get('ViewHelperManager')->get('fixUtf8'),
            $plugins->has('imageSize') ? $plugins->get('imageSize') : null,
            $basePath
        );
    }
}
