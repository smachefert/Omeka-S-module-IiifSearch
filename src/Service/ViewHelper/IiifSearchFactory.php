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
        $helpers = $services->get('ViewHelperManager');

        return new IiifSearch(
            $services->get('Omeka\Logger'),
            $services->get('Omeka\ApiManager'),
            empty($config['iiifserver']['config']['iiifserver_enable_utf8_fix'])
                ? null
                : $helpers->get('fixUtf8'),
            $plugins->has('imageSize') ? $plugins->get('imageSize') : null,
            $basePath,
            !$services->get('Omeka\Settings')->get('iiifsearch_disable_search_media_values')
        );
    }
}
