<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use IiifSearch\Iiif\Search2\AnnotationPage;
use IiifSearch\Iiif\Search2\Builder as Search2Builder;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Build a IIIF Content Search 2.0 response (W3C AnnotationPage) from the
 * neutral pivot produced by IiifSearch::getRawMatches().
 */
class IiifSearch2 extends AbstractHelper
{
    public function __invoke(ItemRepresentation $item): ?AnnotationPage
    {
        $view = $this->getView();
        $iiifSearch = $view->plugin('iiifSearch');

        $pivot = $iiifSearch->getRawMatches($item);
        if ($pivot === null) {
            return null;
        }

        return (new Search2Builder())->build($pivot, $view->serverUrl(true));
    }
}
