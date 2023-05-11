<?php declare(strict_types=1);

namespace IiifSearch\Service\ViewHelper;

use IiifSearch\View\Helper\FixUtf8;
use Interop\Container\ContainerInterface;

class FixUtf8Factory
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new FixUtf8(
            $services->get('Omeka\Logger')
        );
    }
}
