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
