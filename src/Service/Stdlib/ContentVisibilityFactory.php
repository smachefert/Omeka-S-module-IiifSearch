<?php declare(strict_types=1);

namespace IiifSearch\Service\Stdlib;

use IiifSearch\Stdlib\ContentVisibility;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ContentVisibilityFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ContentVisibility($services->get('Omeka\Connection'));
    }
}
