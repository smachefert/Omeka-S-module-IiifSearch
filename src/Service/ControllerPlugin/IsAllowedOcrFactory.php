<?php declare(strict_types=1);

namespace IiifSearch\Service\ControllerPlugin;

use IiifSearch\Mvc\Controller\Plugin\IsAllowedOcr;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IsAllowedOcrFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        return new IsAllowedOcr(
            $services->get('ViewHelperManager'),
            $plugins->has('isAllowedMediaContent') ? $plugins->get('isAllowedMediaContent') : null
        );
    }
}
