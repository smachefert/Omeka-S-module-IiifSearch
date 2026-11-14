<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use IiifSearch\Stdlib\FileResource;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Collects every file-backed resource attached to or referenced by an item:
 * native medias first, then DigitalObjects linked via the configured properties
 * when the DigitalObject module is active.
 */
class ItemFileResources extends AbstractHelper
{
    /** @var callable|null */
    protected $digitalObjectInline;

    public function __construct(?callable $digitalObjectInline)
    {
        $this->digitalObjectInline = $digitalObjectInline;
    }

    /**
     * Pick the image resources rather than every resource: items with attached
     * ALTOs or PDFs interleaved with images would otherwise index into a non-
     * image entry. The position in this list is the page index.
     *
     * @return FileResource[]
     */
    public function images(ItemRepresentation $item): array
    {
        $images = [];
        foreach ($this->__invoke($item) as $resource) {
            if ($resource->isCanvasImage()) {
                $images[] = $resource;
            }
        }
        return $images;
    }

    /**
     * @return FileResource[]
     */
    public function __invoke(ItemRepresentation $item): array
    {
        $out = [];
        foreach ($item->media() as $media) {
            $out[] = new FileResource($media, $item);
        }
        if ($this->digitalObjectInline) {
            foreach (($this->digitalObjectInline)($item) as $do) {
                if (method_exists($do, 'setItem')) {
                    $do->setItem($item);
                }
                $out[] = new FileResource($do, $item);
            }
        }
        return $out;
    }
}
