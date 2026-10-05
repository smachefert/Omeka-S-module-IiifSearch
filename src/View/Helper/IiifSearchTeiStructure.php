<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use IiifSearch\Stdlib\FileResource;
use IiifSearch\Stdlib\TeiStructureExtractor;
use IiifSearch\Stdlib\XmlMediaClassifier;
use Laminas\Log\LoggerInterface;
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

    /**
     * @var \Laminas\Log\LoggerInterface
     */
    protected $logger;

    public function __construct(string $basePath, LoggerInterface $logger)
    {
        $this->basePath = $basePath;
        $this->logger = $logger;
    }

    /**
     * Get the TEI file of an item, that carries the table of contents.
     *
     * Only a public TEI is used: the structure is cached and shared by all
     * visitors, so it must not reveal the headings of a private file. The level
     * of access (module Access) is checked when the manifest is built.
     */
    public function teiResource(ItemRepresentation $item): ?FileResource
    {
        $classifier = new XmlMediaClassifier();
        foreach ($this->getView()->plugin('itemFileResources')->__invoke($item) as $resource) {
            if ($resource->isPublic()
                && $classifier->isXmlLikeMedia($resource)
                && $classifier->classifyMedia($resource, $this->basePath) === XmlMediaClassifier::TYPE_TEI
            ) {
                return $resource;
            }
        }
        return null;
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

        $tei = $this->teiResource($item);
        $teiPath = $tei ? $tei->originalFilepath($this->basePath) : null;
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

        $extractor = new TeiStructureExtractor();
        $structure = $extractor->extract($teiPath, $canvasByName, $index);

        // A wrong alignment shifts all the sections, so it is reported.
        if ($structure && $extractor->isAlignmentUncertain()) {
            $stats = $extractor->stats();
            if ($stats['by_name']) {
                $this->logger->warn(
                    'Item #{item_id}: the table of contents of the TEI file may target wrong pages: {count} pages of {total} are not found by the file names of the images, so they are placed after the previous page found.', // @translate
                    ['item_id' => $itemId, 'count' => $stats['unmatched'], 'total' => $stats['pages']]
                );
            } else {
                $this->logger->warn(
                    'Item #{item_id}: the table of contents of the TEI file may target wrong pages: the pages are matched by order, but the TEI has {pages} pages and the item {canvases} images. Add a facsimile with the file names of the images to the TEI.', // @translate
                    ['item_id' => $itemId, 'pages' => $stats['pages'], 'canvases' => $stats['canvases']]
                );
            }
        }

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
