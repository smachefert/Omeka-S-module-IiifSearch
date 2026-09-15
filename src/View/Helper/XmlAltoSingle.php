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
    protected $xmlImageMatch;

    /**
     * @var string
     */
    protected $xmlFixMode;

    public function __construct(
        Logger $logger,
        FixUtf8 $fixUtf8,
        string $basePath,
        string $xmlImageMatch,
        string $xmlFixMode
    ) {
        $this->logger = $logger;
        $this->fixUtf8 = $fixUtf8;
        $this->basePath = $basePath;
        $this->xmlImageMatch = $xmlImageMatch;
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
        $mediaData = [];
        foreach ($item->media() as $media) {
            if (!$media->hasOriginal() || !$media->size()) {
                continue;
            }
            $filename = $media->filename();
            $filepath = $this->basePath . '/original/' . $filename;
            $ready = file_exists($filepath) && is_readable($filepath) && filesize($filepath);
            if (!$ready) {
                continue;
            }
            $mediaType = $media->mediaType();
            if (!$mediaType) {
                continue;
            }
            $extension = $media->extension();
            // Accept either the canonical alto mime or any xml-like media whose
            // content sniffs as alto (common case: mime is application/xml
            // because Omeka did not recognize the dialect).
            $isAlto = $mediaType === 'application/alto+xml';
            if (!$isAlto
                && $classifier->isXmlLikeMedia($media)
                && $classifier->classifyFile($filepath) === XmlMediaClassifier::TYPE_ALTO
            ) {
                $isAlto = true;
            }
            if (!$isAlto) {
                continue;
            }
            $mainType = strtok($mediaType, '/');
            $mediaId = $media->id();
            $mediaData[$mediaId] = [
                'id' => $mediaId,
                'media' => $media,
                'source' => $media->source(),
                'filename' => $filename,
                'filepath' => $filepath,
                'mediatype' => $mediaType,
                'maintype' => $mainType,
                'extension' => $extension,
                'size' => $media->size(),
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
        $pairer = new PagePairer($this->basePath);
        $pairing = $pairer->pair($item, $sources);
        if ($pairing->method === PairingResult::METHOD_SEQUENTIAL) {
            return $mediaData;
        }
        $imagePositions = [];
        $i = 0;
        foreach ($item->media() as $media) {
            $imagePositions[(int) $media->id()] = $i++;
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

        $xmlContent = file_get_contents($filepath);

        try {
            if ($this->xmlFixMode === 'dom') {
                $currentXml = $this->fixXmlDom($xmlContent);
            } elseif ($this->xmlFixMode === 'regex') {
                $xmlContent = $this->fixUtf8->__invoke($xmlContent);
                $currentXml = @simplexml_load_string($xmlContent, null, LIBXML_NONET);
            } elseif ($this->xmlFixMode === 'all') {
                $xmlContent = $this->fixUtf8->__invoke($xmlContent);
                $currentXml = $this->fixXmlDom($xmlContent);
            } else {
                $currentXml = @simplexml_load_string($xmlContent, null, LIBXML_NONET);
            }
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
        foreach ($mediaData as $fileData) {
            $currentXml = $this->loadXmlFromFilepath($fileData['filepath'], $fileData['id'] ?? null);
            if (!$currentXml) {
                $this->logger->err(new Message(
                    'Error: Cannot get XML content from media #%d.', // @translate
                    $fileData['id']
                ));
                if (!$alto) {
                    return null;
                }
                // Insert an empty page to keep page indexes.
                $altoLayout->addChild('Page');
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
