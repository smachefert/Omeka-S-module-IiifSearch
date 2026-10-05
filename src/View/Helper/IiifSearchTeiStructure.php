<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use IiifSearch\Stdlib\TeiStructureExtractor;
use IiifSearch\Stdlib\XmlMediaClassifier;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Get the table of contents of the TEI file attached to an item.
 *
 * The structure uses the literal format of the module Iiif Server, so it is
 * processed like a structure set in a property. It is cached in a file, that is
 * rebuilt when the TEI or the list of images changes.
 */
class IiifSearchTeiStructure extends AbstractHelper
{
    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var array Structures by item id.
     */
    protected $structures = [];

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * @return string|null The literal structure, or null when the item has no
     *   public TEI file or when it has no section.
     */
    public function __invoke(ItemRepresentation $item): ?string
    {
        $itemId = (int) $item->id();
        if (array_key_exists($itemId, $this->structures)) {
            return $this->structures[$itemId];
        }

        $resources = $this->getView()->plugin('itemFileResources')->__invoke($item);

        // Only a public TEI is used: the structure is cached and shared by all
        // visitors, so it must not reveal the headings of a private file.
        $classifier = new XmlMediaClassifier();
        $teiPath = null;
        foreach ($resources as $resource) {
            if ($resource->isPublic()
                && $classifier->isXmlLikeMedia($resource)
                && $classifier->classifyMedia($resource, $this->basePath) === XmlMediaClassifier::TYPE_TEI
            ) {
                $teiPath = $resource->originalFilepath($this->basePath);
                break;
            }
        }
        if (!$teiPath || !is_readable($teiPath)) {
            return $this->structures[$itemId] = null;
        }

        // The canvases are the images, in the order of the manifest.
        $canvasByName = [];
        $index = 0;
        foreach ($this->getView()->plugin('itemFileResources')->images($item) as $image) {
            ++$index;
            foreach ([$image->source(), $image->filename()] as $name) {
                if ($name !== null && $name !== '') {
                    $canvasByName[$name] ??= $index;
                }
            }
        }

        $signature = md5(TeiStructureExtractor::VERSION . '|' . $teiPath . '|' . filemtime($teiPath) . '|' . json_encode($canvasByName));
        $cachePath = sprintf('%s/iiif-search/%d.toc.json', $this->basePath, $itemId);
        if (is_readable($cachePath)) {
            $cache = json_decode((string) file_get_contents($cachePath), true);
            if (is_array($cache) && ($cache['signature'] ?? null) === $signature) {
                return $this->structures[$itemId] = $cache['structure'] ?? null;
            }
        }

        $structure = (new TeiStructureExtractor())->extract($teiPath, $canvasByName, $index);

        // The directory is protected against a direct web access.
        if (is_dir(dirname($cachePath)) || @mkdir(dirname($cachePath), 0775, true)) {
            @file_put_contents($cachePath, json_encode(
                ['signature' => $signature, 'structure' => $structure],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        }

        return $this->structures[$itemId] = $structure;
    }
}
