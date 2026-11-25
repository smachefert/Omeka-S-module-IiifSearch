<?php declare(strict_types=1);

namespace IiifSearch\Service\ViewHelper;

use IiifSearch\View\Helper\ItemFileResources;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ItemFileResourcesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $helpers = $services->get('ViewHelperManager');
        $digitalObjectInline = $helpers->has('digitalObjectInline')
            ? $helpers->get('digitalObjectInline')
            : null;
        return new ItemFileResources($digitalObjectInline);
    }
}
