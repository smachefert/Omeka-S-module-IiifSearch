<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use DOMDocument;
use Exception;
use IiifSearch\Stdlib\PagePairer;
use IiifSearch\Stdlib\PageSource;
use IiifSearch\Stdlib\PageSourceHintReader;
use IiifSearch\Stdlib\PairingResult;
use IiifSearch\Stdlib\XmlMediaClassifier;
use IiifSearch\Stdlib\XmlRepair;
use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Stdlib\Message;
use SimpleXMLElement;

class XmlAltoSingle extends AbstractHelper
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \IiifServer\Mvc\Controller\Plugin\FixUtf8
     */
    protected $fixUtf8;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $xmlFixMode;

    public function __construct(
        Logger $logger,
        FixUtf8 $fixUtf8,
        string $basePath,
        string $xmlFixMode
    ) {
        $this->logger = $logger;
        $this->fixUtf8 = $fixUtf8;
        $this->basePath = $basePath;
        $this->xmlFixMode = $xmlFixMode;
    }

    /**
     * Create a single alto from a list of xml alto files in files/alto/xxx.alto.xml.
     *
     * It can be done from an item or a list of filepaths.
     */
    public function __invoke(?ItemRepresentation $item, ?string $filepath = null, array $mediaData = []): ?SimpleXMLElement
    {
        if (!$item && !$mediaData) {
            return null;
        }

        if ($mediaData) {
            $first = reset($mediaData);
            if (is_string($first)) {
                // If first it is zero, it's probably a simple list of files.
                // Else, it is probably a list of media id.
                $firstId = key($mediaData);
                foreach ($mediaData as $mediaId => &$filepath) {
                    $filepath = ['id' => $firstId ? $mediaId : null, 'filepath' => $filepath];
                }
                unset($filepath);
            }
        } else {
            $mediaData = $this->mediaData($item);
            if (!$mediaData) {
                return null;
            }
        }

        $xmlContent = $this->mergeXmlAlto($mediaData);

        if (!$xmlContent) {
            return null;
        }

        if ($filepath) {
            $xmlContent->asXML($filepath);
            if (!file_exists($filepath) || !filesize($filepath)) {
                return null;
            }
        }

        return $xmlContent;
    }

    protected function mediaData(ItemRepresentation $item): array
    {
        $classifier = new XmlMediaClassifier();
        $itemFileResources = $this->getView()->plugin('itemFileResources');
        $mediaData = [];
        foreach ($itemFileResources($item) as $resource) {
            if (!$resource->hasOriginal() || !$resource->size()) {
                continue;
            }
            $filename = $resource->filename();
            $filepath = $this->basePath . '/original/' . $filename;
            $ready = file_exists($filepath) && is_readable($filepath) && filesize($filepath);
            if (!$ready) {
                continue;
            }
            $mediaType = $resource->mediaType();
            if (!$mediaType) {
                continue;
            }
            $extension = $resource->extension();
            // Accept either the canonical alto mime or any xml-like resource
            // whose content sniffs as alto (common case: mime is
            // application/xml because Omeka did not recognize the dialect).
            $isAlto = $mediaType === 'application/alto+xml';
            if (!$isAlto
                && $classifier->isXmlLikeMedia($resource)
                && $classifier->classifyFile($filepath) === XmlMediaClassifier::TYPE_ALTO
            ) {
                $isAlto = true;
            }
            if (!$isAlto) {
                continue;
            }
            $mainType = strtok($mediaType, '/');
            $mediaId = $resource->id();
            $mediaData[$mediaId] = [
                'id' => $mediaId,
                'media' => $resource->inner(),
                'source' => $resource->source(),
                'filename' => $filename,
                'filepath' => $filepath,
                'mediatype' => $mediaType,
                'maintype' => $mainType,
                'extension' => $extension,
                'size' => $resource->size(),
            ];
        }

        if (count($mediaData) > 1) {
            $mediaData = $this->reorderByPairing($item, $mediaData);
        }

        return $mediaData;
    }

    /**
     * Re-order per-page alto entries by the position of their paired image
     * media, so the merged alto pages align with the item's image order even
     * when the alto medias were attached out of sequence.
     */
    protected function reorderByPairing(ItemRepresentation $item, array $mediaData): array
    {
        $hintReader = new PageSourceHintReader();
        $sources = [];
        $i = 0;
        foreach ($mediaData as $entry) {
            ++$i;
            $ps = new PageSource(
                $i,
                $entry['media'],
                $entry['filepath'],
                (string) $entry['source'],
                'alto'
            );
            $ps->sourceImageFileName = $hintReader->readImageHint($entry['filepath'], 'alto');
            $sources[] = $ps;
        }
        $itemFileResources = $this->getView()->plugin('itemFileResources');
        $pairer = new PagePairer($this->basePath, $itemFileResources);
        $pairing = $pairer->pair($item, $sources);
        if ($pairing->method === PairingResult::METHOD_SEQUENTIAL) {
            return $mediaData;
        }
        $imagePositions = [];
        $i = 0;
        foreach ($itemFileResources($item) as $resource) {
            $imagePositions[(int) $resource->id()] = $i++;
        }
        $byPair = [];
        $unpaired = [];
        $page = 0;
        foreach ($mediaData as $entry) {
            ++$page;
            $paired = $pairing->imageForPage($page);
            if ($paired) {
                $byPair[$imagePositions[(int) $paired->id()] ?? PHP_INT_MAX][] = $entry;
            } else {
                $unpaired[] = $entry;
            }
        }
        ksort($byPair);
        $result = [];
        foreach ($byPair as $bucket) {
            foreach ($bucket as $entry) {
                $result[$entry['id']] = $entry;
            }
        }
        foreach ($unpaired as $entry) {
            $result[$entry['id']] = $entry;
        }
        return $result;
    }

    protected function loadXmlFromFilepath(?string $filepath, ?int $resourceId = null): ?SimpleXMLElement
    {
        if (!$filepath) {
            return null;
        }

        // The path can come from a media filename that no longer exists on disk
        // (ArchiveRepertory rename, manual deletion, broken backup).
        // file_get_contents() would otherwise return false and downstream
        // fixers / SimpleXML throw a TypeError, surfacing as a 500.
        if (!is_readable($filepath)) {
            $this->logger->warn(new Message(
                'XML ALTO file "%1$s" is missing or unreadable (media #%2$d).', // @translate
                $filepath, $resourceId ?: 0
            ));
            return null;
        }
        $xmlContent = @file_get_contents($filepath);
        if ($xmlContent === false || $xmlContent === '') {
            $this->logger->warn(new Message(
                'XML ALTO file "%1$s" is empty or unreadable (media #%2$d).', // @translate
                $filepath, $resourceId ?: 0
            ));
            return null;
        }

        // Fix windows-1252 bytes in a xml declared as utf-8. Skipped when the
        // content is valid, because the regex is slow.
        if (!mb_check_encoding($xmlContent, 'UTF-8')) {
            $xmlContent = $this->fixUtf8->__invoke($xmlContent);
        }

        try {
            $currentXml = in_array($this->xmlFixMode, ['dom', 'all'], true)
                ? $this->fixXmlDom($xmlContent)
                : @simplexml_load_string($xmlContent, null, LIBXML_NONET);
        } catch (Exception $e) {
            $this->logger->err(new Message(
                'Error: XML content is incorrect for media #%d.', // @translate
                $resourceId ?: 0
            ));
            return null;
        }

        if (!$currentXml) {
            $this->logger->err(new Message(
                'Error: XML content seems empty for media #%d.', // @translate
                $resourceId ?: 0
            ));
            return null;
        }

        return $currentXml;
    }

    /**
     * Check if xml is valid.
     *
     * Copy in:
     * @see \ExtractOcr\Job\ExtractOcr::fixXmlDom()
     * @see \IiifSearch\View\Helper\IiifSearch::fixXmlDom()
     * @see \IiifSearch\View\Helper\XmlAltoSingle::fixXmlDom()
     * @see \IiifServer\Iiif\TraitXml::fixXmlDom()
     */
    protected function fixXmlDom(string $xmlContent): ?SimpleXMLElement
    {
        return XmlRepair::fixXmlDom($xmlContent);
    }

    /**
     * @todo Use the alto page indexes if available (but generally via mets).
     * @fixme Manage the case where the first page has no alto.
     */
    protected function mergeXmlAlto(array $mediaData): ?SimpleXMLElement
    {
        /**
         * DOM is used because SimpleXml cannot add xml nodes (only strings).
         *
         * @var \SimpleXMLElement $alto
         * @var \SimpleXMLElement $altoLayout
         * @var \DOMElement $altoLayoutDom
         */
        $alto = null;
        $altoLayout = null;
        $altoLayoutDom = null;

        $first = true;
        $leadingEmptyPages = 0;
        foreach ($mediaData as $fileData) {
            $currentXml = $this->loadXmlFromFilepath($fileData['filepath'], $fileData['id'] ?? null);
            if (!$currentXml) {
                // A null filepath is a page without xml, not an error.
                if ($fileData['filepath']) {
                    $this->logger->err(new Message(
                        'Error: Cannot get XML content from media #%d.', // @translate
                        $fileData['id']
                    ));
                }
                // Insert an empty page to keep page indexes.
                if ($alto) {
                    $altoLayout->addChild('Page');
                } else {
                    ++$leadingEmptyPages;
                }
                continue;
            }

            if ($first) {
                $first = false;
                $alto = $currentXml;
                $altoLayout = $alto->Layout;
                if (!$altoLayout || !$altoLayout->count()) {
                    return null;
                }
                $altoLayoutDom = dom_import_simplexml($altoLayout);
                $currentXmlFirstPage = $altoLayout->Page;
                if (!$currentXmlFirstPage || !$currentXmlFirstPage->count()) {
                    $altoLayout->addChild('Page');
                }
                // Prepend the empty pages of the leading pages without xml.
                $firstPageDom = dom_import_simplexml($altoLayout->Page[0]);
                for ($i = 0; $i < $leadingEmptyPages; ++$i) {
                    $altoLayoutDom->insertBefore(dom_import_simplexml($altoLayout->addChild('Page')), $firstPageDom);
                }
                continue;
            }

            $currentXmlFirstPage = $currentXml->Layout->Page;
            if (!$currentXmlFirstPage || !$currentXmlFirstPage->count()) {
                $altoLayout->addChild('Page');
                continue;
            }

            $currentXmlDomPage = dom_import_simplexml($currentXmlFirstPage);
            $altoLayoutDom->appendChild($altoLayoutDom->ownerDocument->importNode($currentXmlDomPage, true));
        }

        return $alto;
    }
}
