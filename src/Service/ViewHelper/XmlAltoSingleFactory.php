<?php declare(strict_types=1);

namespace IiifSearch\Service\ViewHelper;

use IiifSearch\View\Helper\XmlAltoSingle;
use Interop\Container\ContainerInterface;

class XmlAltoSingleFactory
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $helpers = $services->get('ViewHelperManager');
        $settings = $services->get('Omeka\Settings');

        return new XmlAltoSingle(
            $services->get('Omeka\Logger'),
            $helpers->get('fixUtf8'),
            $basePath,
            $settings->get('iiifsearch_xml_image_match', 'order'),
            $settings->get('iiifsearch_xml_fix_mode', 'no')
        );
    }
}
