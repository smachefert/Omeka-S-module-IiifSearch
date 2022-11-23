<?php declare(strict_types=1);

namespace IiifSearch\Service\File;

use IiifSearch\File\TempFileFactory;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class TempFileFactoryFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $tempFileFactory = new TempFileFactory($services);
        return $tempFileFactory
            ->setSpecifyMediaType($services->get('ControllerPluginManager')->get('specifyMediaType'));
    }
}
