<?php declare(strict_types=1);

namespace IiifSearch\Service\ViewHelper;

use IiifSearch\View\Helper\IiifSearchTeiStructure;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IiifSearchTeiStructureFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null): IiifSearchTeiStructure
    {
        $config = $services->get('Config');
        return new IiifSearchTeiStructure(
            $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files')
        );
    }
}
