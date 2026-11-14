<?php declare(strict_types=1);

namespace IiifSearch\Service\ViewHelper;

use IiifSearch\View\Helper\XmlAltoSingle;
use Psr\Container\ContainerInterface;

class XmlAltoSingleFactory
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $helpers = $services->get('ViewHelperManager');

        return new XmlAltoSingle(
            $services->get('Omeka\Logger'),
            $helpers->get('fixUtf8'),
            $basePath
        );
    }
}
