<?php declare(strict_types=1);

namespace IiifSearch\Mvc\Controller\Plugin;

use Access\Mvc\Controller\Plugin\IsAllowedMediaContent;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\View\HelperPluginManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Check if the OCR of a page is readable by the current user or visitor.
 *
 * The OCR follows the access level of the file it belongs to. So, when the
 * ALTO is an attached file, that file rules. When it is not attached (built by
 * the Extract OCR job or consolidated in a single file), it follows the image
 * of the page, and the item itself when the item has no image, for example an
 * item made of a single pdf.
 *
 * Keeping the files of an item consistent is the responsibility of the user:
 * an ALTO left free on an item whose images are reserved stays readable.
 */
class IsAllowedOcr extends AbstractPlugin
{
    /**
     * @var \Laminas\View\HelperPluginManager
     */
    protected $viewHelpers;

    /**
     * @var \Access\Mvc\Controller\Plugin\IsAllowedMediaContent|null
     */
    protected $isAllowedMediaContent;

    public function __construct(
        HelperPluginManager $viewHelpers,
        ?IsAllowedMediaContent $isAllowedMediaContent
    ) {
        $this->viewHelpers = $viewHelpers;
        $this->isAllowedMediaContent = $isAllowedMediaContent;
    }

    /**
     * @param int $pageIndex Zero-based page index.
     */
    public function __invoke(ItemRepresentation $item, int $pageIndex): bool
    {
        // Without the module Access, there is no restriction to apply.
        if (!$this->isAllowedMediaContent) {
            return true;
        }

        $resource = $this->viewHelpers->get('xmlAltoSplitter')
            ->sourceResource($item, $pageIndex);

        if (!$resource) {
            $images = $this->viewHelpers->get('itemFileResources')->images($item);
            $image = $images[$pageIndex] ?? null;
            $resource = $image ? $image->inner() : $item;
        }

        return (bool) $this->isAllowedMediaContent->__invoke($resource);
    }
}
