<?php declare(strict_types=1);

namespace IiifSearch\Service\ViewHelper;

use IiifSearch\View\Helper\XmlAltoSplitter;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class XmlAltoSplitterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null): XmlAltoSplitter
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $settings = $services->get('Omeka\Settings');
        $pageMatch = (string) $settings->get('iiifsearch_alto_page_match', 'order');

        return new XmlAltoSplitter(
            $services->get('Omeka\Logger'),
            $basePath,
            $pageMatch
        );
    }
}
