<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use IiifSearch\Iiif\AnnotationList;
use IiifSearch\Iiif\AnnotationSearchResult;
use IiifSearch\Iiif\SearchHit;
use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;
use SimpleXMLElement;

class IiifSearch extends AbstractHelper
{
    /**
     * @var array
     */
    protected $supportedMediaTypes = [
        'application/alto+xml',
        'application/vnd.pdf2xml+xml',
    ];

    /**
     * @var int
     */
    protected $minimumQueryLength = 3;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * Full path to the files.
     *
     * @var string
     */
    protected $basePath;

    /**
     * @var ItemRepresentation
     */
    protected $item;

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation
     */
    protected $xmlFile;

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation[]
     */
    protected $xmlFiles;

    /**
     * @var string
     */
    protected $xmlMediaType;

    /**
     * @var array
     */
    protected $imageSizes;

    public function __construct(Logger $logger, $basePath)
    {
        $this->logger = $logger;
        $this->basePath = $basePath;
    }

    /**
     * Get the IIIF search response for fulltext research query.
     *
     * @param ItemRepresentation $item
     * @return AnnotationList|null Null is returned if search is not supported
     * for the resource.
     */
    public function __invoke(ItemRepresentation $item): ?AnnotationList
    {
        $this->item = $item;

        if (!$this->prepareSearch()) {
            return null;
        }

        $view = $this->getView();
        $query = (string) $view->params()->fromQuery('q');
        $result = $this->searchFulltext($query);

        $response = new AnnotationList;
        $response->initOptions(['requestUri' => $view->serverUrl(true)]);
        if ($result) {
            $response['resources'] = $result['resources'];
            $response['hits'] = $result['hits'];
        }
        $response->isValid(true);
        return $response;
    }

    /**
     * Returns answers to a query.
     *
     * @todo add xml validation ( pdf filename == xml filename according to Extract Ocr plugin )
     *
     * @return array|null
     *  Return resources that match query for IIIF Search API
     * [
     *      [
     *          '@id' => 'https://your_domain.com/omeka-s/iiif-search/itemID/searchResults/ . a . numCanvas . h . numresult. r .  xCoord , yCoord, wCoord , hCoord ',
     *          '@type' => 'oa:Annotation',
     *          'motivation' => 'sc:painting',
     *          [
     *              '@type' => 'cnt:ContentAsText',
     *              'chars' =>  corresponding match char list ,
     *          ]
     *          'on' => canvas url with coordonate for IIIF Server module,
     *      ]
     *      ...
     */
    protected function searchFulltext(string $query): ?array
    {
        if (!strlen($query)) {
            return null;
        }

        $queryWords = $this->formatQuery($query);
        if (empty($queryWords)) {
            return null;
        }

        $xml = $this->loadXml();
        if (empty($xml)) {
            return null;
        }

        $this->prepareImageSizes();

        if ($this->xmlMediaType === 'application/alto+xml') {
            return $this->searchFullTextAlto($xml, $queryWords);
        }

        return $this->searchFullTextPdfXml($xml, $queryWords);
    }

    protected function searchFullTextAlto(SimpleXmlElement $xml, $queryWords): ?array
    {
        $result = [
            'resources' => [],
            'hits' => [],
        ];

        // A search result is an annotation on the canvas of the original item,
        // so an url managed by the iiif server.
        $view = $this->getView();
        $baseResultUrl = $view->iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'annotation',
            'name' => 'search-result',
        ]) . '/';

        $baseCanvasUrl = $view->iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'canvas',
        ]) . '/p';

        $resource = $this->item;
        try {
            $hit = 0;
            $index = -1;
            foreach ($xml->alto as $xmlPage) {
                ++$index;
                $attributes = $xmlPage->Layout->Page->attributes();
                // TODO Alto is merged, so use the index, not the page.
                // TODO Check why casting to string is needed.
                // $page['number'] = (string) ((@$attributes->PHYSICAL_IMG_NR) + 1);
                $page['number'] = (string) ($index + 1);
                $page['width'] = (string) @$attributes->WIDTH;
                $page['height'] = (string) @$attributes->HEIGHT;
                if (!strlen($page['number']) || !strlen($page['width']) || !strlen($page['height'])) {
                    $view->logger()->warn(sprintf(
                        'Incomplete data for xml file from media #%1$s, page %2$s.', // @translate
                        $this->xmlFile->id(), $index
                    ));
                    continue;
                }

                // TODO Not checked for now: alto is rebuilt so all images are first.
                $pageIndex = $index;
                // Should be the same than index.
                /*
                $pageIndex = $page['number'] - 1;
                if ($pageIndex !== $index) {
                    $view->logger()->warn(sprintf(
                        'Inconsistent data for xml file from media #%1$s, page %2$s.', // @translate
                        $this->xmlFile->id(), $index
                    ));
                    continue;
                }
                */

                $hits = [];
                $hitMatches = [];

                $extractStringData = function (\SimpleXMLElement $xmlString) use (&$result, &$hits, &$hitMatches, &$hit, $resource, $view, $baseResultUrl, $baseCanvasUrl, $page, $pageIndex, $queryWords): void {
                    $attributes = $xmlString->attributes();
                    $matches = [];
                    $zone = [];
                    $zone['text'] = (string) $attributes->CONTENT;
                    foreach ($queryWords as $chars) {
                        if (!empty($this->imageSizes[$pageIndex]['width'])
                            && !empty($this->imageSizes[$pageIndex]['height'])
                            && mb_strlen($chars) >= $this->minimumQueryLength
                            && preg_match('/' . preg_quote($chars, '/') . '/Uui', $zone['text'], $matches) > 0
                        ) {
                            $zone['top'] = (string) @$attributes->VPOS;
                            $zone['left'] = (string) @$attributes->HPOS;
                            $zone['width'] = (string) @$attributes->WIDTH;
                            $zone['height'] = (string) @$attributes->HEIGHT;
                            if (!strlen($zone['top']) || !strlen($zone['left']) || !strlen($zone['width']) || !strlen($zone['height'])) {
                                $view->logger()->warn(sprintf(
                                    'Inconsistent data for xml file from media #%1$s, page %2$s.', // @translate
                                    $this->xmlFile->id(), $pageIndex
                                ));
                                continue;
                            }

                            ++$hit;

                            $image = $this->imageSizes[$pageIndex];
                            $searchResult = new AnnotationSearchResult;
                            $searchResult->initOptions(['baseResultUrl' => $baseResultUrl, 'baseCanvasUrl' => $baseCanvasUrl]);
                            $result['resources'][] = $searchResult->setResult(compact('resource', 'image', 'page', 'zone', 'chars', 'hit'));

                            $hits[] = $searchResult->id();
                            // TODO Get matches as whole world and all matches in last time (preg_match_all).
                            // TODO Get the text before first and last hit of the page.
                            $hitMatches[] = $matches[0];
                        }
                    }
                };

                foreach ($xmlPage->Layout->Page->children() as $pageElement) {
                    if ($pageElement->getName() === 'String') {
                        $extractStringData($pageElement);
                    } else {
                        foreach ($pageElement->children() as $pageElement2) {
                            if ($pageElement2->getName() === 'String') {
                                $extractStringData($pageElement2);
                            } else {
                                foreach ($pageElement2->children() as $pageElement3) {
                                    if ($pageElement3->getName() === 'String') {
                                        $extractStringData($pageElement3);
                                    } else {
                                        foreach ($pageElement3->children() as $pageElement4) {
                                            if ($pageElement4->getName() === 'String') {
                                                $extractStringData($pageElement4);
                                            } else {
                                                foreach ($pageElement4->children() as $pageElement5) {
                                                    if ($pageElement5->getName() === 'String') {
                                                        $extractStringData($pageElement5);
                                                    } else {
                                                        foreach ($pageElement5->children() as $pageElement6) {
                                                            if ($pageElement6->getName() === 'String') {
                                                                $extractStringData($pageElement6);
                                                            } else {
                                                                foreach ($pageElement6->children() as $pageElement7) {
                                                                    if ($pageElement7->getName() === 'String') {
                                                                        $extractStringData($pageElement7);
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // Add hits per page.
                if ($hits) {
                    $searchHit = new SearchHit;
                    $searchHit['annotations'] = $hits;
                    $searchHit['match'] = implode(' ', array_unique($hitMatches));
                    $result['hits'][] = $searchHit;
                }
            }
        } catch (\Exception $e) {
            $view->logger()->err(sprintf(
                'Error: XML alto content may be invalid for media file #%d!', // @translate
                $this->xmlFile->id()
            ));
            return null;
        }

        return $result;
    }

    protected function searchFullTextPdfXml(SimpleXmlElement $xml, $queryWords): ?array
    {
        $result = [
            'resources' => [],
            'hits' => [],
        ];

        // A search result is an annotation on the canvas of the original item,
        // so an url managed by the iiif server.
        $view = $this->getView();
        $baseResultUrl = $view->iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'annotation',
            'name' => 'search-result',
        ]) . '/';

        $baseCanvasUrl = $view->iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'canvas',
        ]) . '/p';

        $resource = $this->item;
        $matches = [];
        try {
            $hit = 0;
            $index = -1;
            foreach ($xml->page as $xmlPage) {
                ++$index;
                $attributes = $xmlPage->attributes();
                $page['number'] = (string) @$attributes->number;
                $page['width'] = (string) @$attributes->width;
                $page['height'] = (string) @$attributes->height;
                if (!strlen($page['number']) || !strlen($page['width']) || !strlen($page['height'])) {
                    $this->logger->warn(sprintf(
                        'Incomplete data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->xmlFile->id(), $index
                    ));
                    continue;
                }

                // Should be the same than index.
                $pageIndex = $page['number'] - 1;
                if ($pageIndex !== $index) {
                    $this->logger->warn(sprintf(
                        'Inconsistent data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->xmlFile->id(), $index
                    ));
                    continue;
                }

                $hits = [];
                $hitMatches = [];
                $rowIndex = -1;
                foreach ($xmlPage->text as $xmlRow) {
                    ++$rowIndex;
                    $zone = [];
                    $zone['text'] = strip_tags($xmlRow->asXML());
                    foreach ($queryWords as $chars) {
                        if (!empty($this->imageSizes[$pageIndex]['width'])
                            && !empty($this->imageSizes[$pageIndex]['height'])
                            && mb_strlen($chars) >= $this->minimumQueryLength
                            && preg_match('/' . preg_quote($chars, '/') . '/Uui', $zone['text'], $matches) > 0
                        ) {
                            $attributes = $xmlRow->attributes();
                            $zone['top'] = (string) @$attributes->top;
                            $zone['left'] = (string) @$attributes->left;
                            $zone['width'] = (string) @$attributes->width;
                            $zone['height'] = (string) @$attributes->height;
                            if (!strlen($zone['top']) || !strlen($zone['left']) || !strlen($zone['width']) || !strlen($zone['height'])) {
                                $this->logger->warn(sprintf(
                                    'Inconsistent data for xml file from pdf media #%1$s, page %2$s, row %3$s.', // @translate
                                    $this->xmlFile->id(), $pageIndex, $rowIndex
                                ));
                                continue;
                            }

                            ++$hit;

                            $image = $this->imageSizes[$pageIndex];
                            $searchResult = new AnnotationSearchResult;
                            $searchResult->initOptions(['baseResultUrl' => $baseResultUrl, 'baseCanvasUrl' => $baseCanvasUrl]);
                            $result['resources'][] = $searchResult->setResult(compact('resource', 'image', 'page', 'zone', 'chars', 'hit'));

                            $hits[] = $searchResult->id();
                            // TODO Get matches as whole world and all matches in last time (preg_match_all).
                            // TODO Get the text before first and last hit of the page.
                            $hitMatches[] = $matches[0];
                        }
                    }
                }

                // Add hits per page.
                if ($hits) {
                    $searchHit = new SearchHit;
                    $searchHit['annotations'] = $hits;
                    $searchHit['match'] = implode(' ', array_unique($hitMatches));
                    $result['hits'][] = $searchHit;
                }
            }
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Error: PDF to XML conversion failed for media file #%d!', // @translate
                $this->xmlFile->id()
            ));
            return null;
        }

        return $result;
    }

    /**
     * Check if the item support search and init the xml files.
     */
    protected function prepareSearch(): bool
    {
        $this->xmlFile = null;
        $this->xmlFiles = [];

        $this->imageSizes = [];
        foreach ($this->item->media() as $media) {
            $mediaType = $media->mediaType();
            if (in_array($mediaType, $this->supportedMediaTypes)) {
                // TODO Remove single xmlFile.
                if (!$this->xmlFile) {
                    $this->xmlFile = $media;
                }
                $this->xmlFiles[] = $media;
            } elseif ($mediaType === 'text/xml' || $mediaType === 'application/xml') {
                $this->logger->warn(
                    sprintf('Warning: Xml format "%1$s" of media #%2$d is not precise enough and is skipped.', // @translate
                        $this->xmlMediaType, $media->id
                    ));
            } elseif ($media->ingester() == 'iiif') {
                $this->imageSizes[] = [
                    'media' => $media,
                ];
            } elseif ($media->hasOriginal() && strtok($mediaType, '/') === 'image') {
                $this->imageSizes[] = [
                    'media' => $media,
                ];
            }
        }
        return isset($this->xmlFile) && count($this->imageSizes);
    }

    protected function prepareImageSizes(): void
    {
        // TODO Use plugin imageSize from modules IiifServer and ImageServer.
        foreach ($this->imageSizes as &$image) {
            // Some media types don't save the file locally.
            if ($filename = $image['media']->filename()) {
                $filepath = $this->basePath . '/original/' . $filename;
            } else {
                $filepath = $image['media']->originalUrl();
            }
            if ($image['media']->ingester() == 'iiif') {
                $mediaData = $image['media']->mediaData();
                list($image['width'], $image['height']) = [$mediaData['width'], $mediaData['height']];
            } else {
                list($image['width'], $image['height']) = getimagesize($filepath);
            }
        }
    }

    /**
     * Normalize query because the search occurs inside a normalized text.
     */
    protected function formatQuery($query): array
    {
        $cleanQuery = $this->alnumString($query);
        if (mb_strlen($cleanQuery) < $this->minimumQueryLength) {
            return [];
        }

        $queryWords = explode(' ', $cleanQuery);
        if (count($queryWords) > 1) {
            $queryWords[] = $cleanQuery;
        }

        return $queryWords;
    }

    protected function loadXml(): ?SimpleXMLElement
    {
        if (count($this->xmlFiles) > 1) {
            return $this->loadXmls();
        }

        $filepath = ($filename = $this->xmlFile->filename())
            ? $this->basePath . '/original/' . $filename
            : $this->xmlFile->originalUrl();

        // The media type is already checked.
        $this->xmlMediaType = $this->xmlFile->mediaType();

        // Fix badly formatted xml files.
        $xmlContent = file_get_contents($filepath);
        $xmlContent = $this->fixBadUtf8($xmlContent);
        if (!$xmlContent) {
            $this->logger->err(sprintf(
                'Error: XML content seems empty for media #%d!', // @translate
                $this->xmlFile->id()
            ));
            return null;
        }

        // Manage an exception.
        if ($this->xmlMediaType === 'application/vnd.pdf2xml+xml') {
            $xmlContent = preg_replace('/\s{2,}/ui', ' ', $xmlContent);
            $xmlContent = preg_replace('/<\/?b>/ui', '', $xmlContent);
            $xmlContent = preg_replace('/<\/?i>/ui', '', $xmlContent);
            $xmlContent = str_replace('<!doctype pdf2xml system "pdf2xml.dtd">', '<!DOCTYPE pdf2xml SYSTEM "pdf2xml.dtd">', $xmlContent);
        } elseif (true) {
            return $this->loadXmls();
        }

        $xmlContent = simplexml_load_string($xmlContent);
        if (!$xmlContent) {
            $this->logger->err(sprintf(
                'Error: Cannot get XML content from media #%d!', // @translate
                $this->xmlFile->id()
            ));
            return null;
        }

        return $xmlContent;
    }

    protected function loadXmls(): ?SimpleXMLElement
    {
        $this->xmlMediaType = 'application/alto+xml';

        if (!in_array($this->xmlMediaType, $this->supportedMediaTypes)) {
            $this->getView()->logger()->err(sprintf(
                'Error: Xml format "%1$s" is not managed currently (media #%2$d).', // @translate
                $this->xmlMediaType, $this->xmlFile->id()
            ));
            return null;
        }

        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xmlContent .= '<altos>' . "\n";

        foreach ($this->xmlFiles as $xmlFileMedia) {
            $filepath = ($filename = $xmlFileMedia->filename())
                ? $this->basePath . '/original/' . $filename
                : $xmlFileMedia->originalUrl();
            $currentXml = file_get_contents($filepath);
            $currentXml = str_replace(['<?xml version="1.0" encoding="UTF-8"?>', '<alto xmlns="http://www.loc.gov/standards/alto/ns-v3#" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.loc.gov/standards/alto/ns-v3# http://www.loc.gov/alto/v3/alto-3-0.xsd">'], ['<alto>'], $currentXml);

            $xmlContent .= $currentXml . "\n";
        }

        $xmlContent .= '</altos>';
        $xmlContent = $this->fixBadUtf8($xmlContent);
        return @simplexml_load_string($xmlContent);
    }

    /**
     * Returns a cleaned  string.
     *
     * Removes trailing spaces and anything else, except letters, numbers and
     * symbols.
     */
    protected function alnumString($string): string
    {
        $string = preg_replace('/[^\p{L}\p{N}\p{S}]/u', ' ', (string) $string);
        return trim(preg_replace('/\s+/', ' ', $string));
    }

    /**
     * Some utf8 files, generally edited under Windows, should be cleaned.
     *
     * @see https://stackoverflow.com/questions/1401317/remove-non-utf8-characters-from-string#1401716
     */
    protected function fixBadUtf8($string): ?string
    {
        $regex = <<<'REGEX'
/
  (
    (?: [\x00-\x7F]               # single-byte sequences   0xxxxxxx
    |   [\xC0-\xDF][\x80-\xBF]    # double-byte sequences   110xxxxx 10xxxxxx
    |   [\xE0-\xEF][\x80-\xBF]{2} # triple-byte sequences   1110xxxx 10xxxxxx * 2
    |   [\xF0-\xF7][\x80-\xBF]{3} # quadruple-byte sequence 11110xxx 10xxxxxx * 3
    ){1,100}                      # ...one or more times
  )
| ( [\x80-\xBF] )                 # invalid byte in range 10000000 - 10111111
| ( [\xC0-\xFF] )                 # invalid byte in range 11000000 - 11111111
/x
REGEX;

        $utf8replacer = function ($captures) {
            if ($captures[1] !== '') {
                // Valid byte sequence. Return unmodified.
                return $captures[1];
            } elseif ($captures[2] !== '') {
                // Invalid byte of the form 10xxxxxx.
                // Encode as 11000010 10xxxxxx.
                return "\xC2" . $captures[2];
            } else {
                // Invalid byte of the form 11xxxxxx.
                // Encode as 11000011 10xxxxxx.
                return "\xC3" . chr(ord($captures[3]) - 64);
            }
        };

        // Log invalid files.
        $count = 0;
        $result = preg_replace_callback($regex, $utf8replacer, (string) $string, -1, $count);
        if ($count) {
            $this->logger->warn(sprintf(
                'Warning: some files contain invalid unicode characters and cannot be searched quickly.' // @translate
            ));
        }
        return $result;
    }
}
