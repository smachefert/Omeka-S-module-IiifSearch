<?php declare(strict_types=1);

namespace IiifSearch\Service\ViewHelper;

use IiifSearch\View\Helper\IiifSearch2;
use Psr\Container\ContainerInterface;

class IiifSearch2Factory
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IiifSearch2();
    }
}
