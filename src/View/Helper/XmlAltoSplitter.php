<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use DOMDocument;
use DOMElement;
use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Split a multipage ALTO XML into per-page standalone ALTO documents.
 *
 * Used to expose one seeAlso per canvas in a IIIF manifest and to feed
 * Mirador-textoverlay annotations that target a single canvas.
 */
class XmlAltoSplitter extends AbstractHelper
{
    protected Logger $logger;

    protected string $basePath;

    public function __construct(Logger $logger, string $basePath)
    {
        $this->logger = $logger;
        $this->basePath = $basePath;
    }

    /**
     * Return the cached path to a per-page ALTO file, generating it on demand.
     *
     * @return string|null Absolute path, or null if page absent or invalid.
     */
    public function __invoke(ItemRepresentation $item, int $pageIndex): ?string
    {
        $altoMedia = $this->findMultipageAltoMedia($item);
        if (!$altoMedia) {
            return null;
        }

        $sourcePath = $this->basePath . '/original/' . $altoMedia->filename();
        if (!is_readable($sourcePath)) {
            return null;
        }

        $cacheDir = sprintf('%s/iiif-search/%d', $this->basePath, $item->id());
        $cachePath = sprintf('%s/alto-%d.xml', $cacheDir, $pageIndex);
        if (file_exists($cachePath)
            && filemtime($cachePath) >= filemtime($sourcePath)
        ) {
            return $cachePath;
        }

        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return null;
        }

        $page = $this->extractPage($sourcePath, $item, $pageIndex);
        if (!$page) {
            return null;
        }

        return @file_put_contents($cachePath, $page) ? $cachePath : null;
    }

    /**
     * Find the first item resource (media or DigitalObject) that is an ALTO XML
     * containing more than one Page element.
     */
    public function findMultipageAltoMedia(ItemRepresentation $item): ?AbstractResourceEntityRepresentation
    {
        $itemFileResources = $this->getView()->plugin('itemFileResources');
        foreach ($itemFileResources($item) as $resource) {
            if ($resource->mediaType() !== 'application/alto+xml') {
                continue;
            }
            $path = $this->basePath . '/original/' . $resource->filename();
            if (!is_readable($path)) {
                continue;
            }
            if ($this->countPages($path) > 1) {
                return $resource->inner();
            }
        }
        return null;
    }

    public function countPages(string $sourcePath): int
    {
        $reader = new \XMLReader();
        if (!@$reader->open($sourcePath)) {
            return 0;
        }
        $count = 0;
        while (@$reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT
                && $reader->localName === 'Page'
            ) {
                $count++;
            }
        }
        $reader->close();
        return $count;
    }

    /**
     * Extract a single <Page> wrapped in a minimal valid ALTO document.
     */
    protected function extractPage(string $sourcePath, ItemRepresentation $item, int $pageIndex): ?string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!@$dom->load($sourcePath, LIBXML_NOBLANKS | LIBXML_NONET)) {
            return null;
        }

        $root = $dom->documentElement;
        if (!$root) {
            return null;
        }

        $layouts = $root->getElementsByTagName('Layout');
        if (!$layouts->length) {
            return null;
        }
        $layout = $layouts->item(0);

        $target = $this->matchPage($layout, $item, $pageIndex);
        if (!$target) {
            return null;
        }

        $out = new DOMDocument('1.0', 'UTF-8');
        $out->formatOutput = true;
        $altoNs = $root->namespaceURI ?: 'http://www.loc.gov/standards/alto/ns-v4#';
        $newRoot = $out->createElementNS($altoNs, $root->localName);
        $out->appendChild($newRoot);

        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'Description') {
                $newRoot->appendChild($out->importNode($child, true));
                break;
            }
        }

        $newLayout = $out->createElementNS($altoNs, 'Layout');
        $newRoot->appendChild($newLayout);
        $newLayout->appendChild($out->importNode($target, true));

        return $out->saveXML();
    }

    protected function matchPage(\DOMNode $layout, ItemRepresentation $item, int $pageIndex): ?DOMElement
    {
        $pages = [];
        foreach ($layout->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'Page') {
                $pages[] = $node;
            }
        }
        if (!$pages) {
            return null;
        }

        // Auto-resolve: prefer PHYSICAL_IMG_NR when present, then match
        // Page/@ID against item file basenames, fall back to positional order.
        foreach ($pages as $page) {
            $nr = (int) $page->getAttribute('PHYSICAL_IMG_NR');
            if ($nr && ($nr - 1) === $pageIndex) {
                return $page;
            }
        }
        $itemFileResources = $this->getView()->plugin('itemFileResources');
        $resources = $itemFileResources($item);
        $target = $resources[$pageIndex] ?? null;
        if ($target) {
            $base = pathinfo($target->source() ?: $target->filename(), PATHINFO_FILENAME);
            foreach ($pages as $page) {
                $id = $page->getAttribute('ID');
                if ($id === $base || $id === 'Page_' . $base) {
                    return $page;
                }
            }
        }
        return $pages[$pageIndex] ?? null;
    }
}
