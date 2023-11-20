<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use DerivativeMedia\View\Helper\HasDerivative;
use DOMDocument;
use IiifSearch\Iiif\AnnotationList;
use IiifSearch\Iiif\AnnotationSearchResult;
use IiifSearch\Iiif\SearchHit;
use IiifServer\Mvc\Controller\Plugin\ImageSize;
use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
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
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \IiifServer\Mvc\Controller\Plugin\FixUtf8
     */
    protected $fixUtf8;

    /**
     * @var \IiifSearch\View\Helper\XmlAltoSingle
     */
    protected $xmlAltoSingle;

    /**
     * @var \IiifServer\Mvc\Controller\Plugin\ImageSize
     */
    protected $imageSize;

    /**
     * @var \DerivativeMedia\View\Helper\HasDerivative
     */
    protected $hasDerivative;

    /**
     * Full path to the files.
     *
     * @var string
     */
    protected $basePath;

    /**
     * @var bool
     */
    protected $searchMediaValues;

    /**
     * @var string
     */
    protected $xmlImageMatch;

    /**
     * @var string
     */
    protected $xmlFixMode;

    /**
     * @var ItemRepresentation
     */
    protected $item;

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation[]
     */
    protected $xmlFiles;

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation
     */
    protected $firstXmlFile;

    /**
     * @var string
     */
    protected $mediaType;

    /**
     * @var array
     */
    protected $imageSizes;

    public function __construct(
        Logger $logger,
        ApiManager $api,
        FixUtf8 $fixUtf8,
        XmlAltoSingle $xmlAltoSingle,
        ?ImageSize $imageSize,
        ?HasDerivative $hasDerivative,
        string $basePath,
        bool $searchMediaValues,
        string $xmlImageMatch,
        string $xmlFixMode
    ) {
        $this->logger = $logger;
        $this->api = $api;
        $this->fixUtf8 = $fixUtf8;
        $this->xmlAltoSingle = $xmlAltoSingle;
        $this->imageSize = $imageSize;
        $this->hasDerivative = $hasDerivative;
        $this->basePath = $basePath;
        $this->searchMediaValues = $searchMediaValues;
        $this->xmlImageMatch = $xmlImageMatch;
        $this->xmlFixMode = $xmlFixMode;
    }

    /**
     * Get the IIIF search response for fulltext research query.
     *
     * @param ItemRepresentation $item
     * @return AnnotationList|null Null is returned if search is not supported
     * for the resource. The annotation list is empty when search has no result.
     */
    public function __invoke(ItemRepresentation $item): ?AnnotationList
    {
        $this->item = $item;

        if (!$this->prepareSearch()) {
            return null;
        }

        // TODO Add a warning when the number of images is not the same than the number of pages. But it may be complex because images are not really managed with xml files, so warn somewhere else.

        $view = $this->getView();
        $query = (string) $view->params()->fromQuery('q');

        $result = $this->searchFulltext($query);

        if ($this->searchMediaValues) {
            $resultValues = $this->searchMediaValues($query, $result ? $result['hit'] : 0, $result ? $result['media_ids'] : []);
            $result = $result === null && $resultValues === null
                ? null
                : array_merge($result ?? [], $resultValues ?? []);
        }

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
     *   Return resources (the pages) and hits (the position of the words to
     *   highlight) that match query for IIIF Search API.
     *
     * ```php
     * [
     *      'resources' => [
     *          [
     *              '@id' => 'https://your_domain.com/omeka-s/iiif-search/itemID/searchResults/ . a . numCanvas . h . numresult. r .  xCoord , yCoord, wCoord , hCoord ',
     *              '@type' => 'oa:Annotation',
     *              'motivation' => 'sc:painting',
     *              [
     *                  '@type' => 'cnt:ContentAsText',
     *                  'chars' =>  corresponding match char list,
     *              ],
     *              'on' => canvas url with coordonate for IIIF Server module,
     *          ],
     *     ],
     *     'hits' => [
     *         [
     *             '@type' => 'search:Hit',
     *             'annotations' => [
     *                 'https://your_domain.com/omeka-s/iiif/2/104205/annotation/search-result/a6h1r1265,1074,108,32',
     *             ],
     *             'match' => 'searched-word',
     *         ],
     *     ],
     *     'media_ids' => [1],
     *     'hit' => 1,
     * ]
     * ```
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
        } elseif ($this->mediaType === 'application/alto+xml') {
            return $this->searchFullTextAlto($xml, $queryWords);
        } elseif ($this->mediaType === 'application/vnd.pdf2xml+xml') {
            return $this->searchFullTextPdfXml($xml, $queryWords);
        } else {
            return null;
        }
    }

    protected function searchFullTextAlto(SimpleXmlElement $xml, $queryWords): ?array
    {
        $result = [
            'resources' => [],
            'hits' => [],
            'media_ids' => [],
            'hit' => 0,
        ];

        // A search result is an annotation on the canvas of the original item,
        // so an url managed by the iiif server.
        $iiifUrl = $this->getView()->plugin('iiifUrl');
        $baseResultUrl = $iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'annotation',
            'name' => 'search-result',
        ]) . '/';

        $baseCanvasUrl = $iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'canvas',
        ]) . '/p';

        // XPath’s string literals can’t contain both " and ' and doesn't manage
        // insensitive comparaison simply, so get all strings and preg them.

        $namespaces = $xml->getDocNamespaces();
        $altoNamespace = $namespaces['alto'] ?? $namespaces[''] ?? 'http://www.loc.gov/standards/alto/ns-v4#';
        $xml->registerXPathNamespace('alto', $altoNamespace);

        $resource = $this->item;
        try {
            // The hit index.
            $hit = 0;
            // 0-based page index.
            $indexXmlPage = -1;
            /** @var \SimpleXmlElement $xmlPage */
            foreach ($xml->Layout->Page as $xmlPage) {
                ++$indexXmlPage;
                $attributes = $xmlPage->attributes();
                // Skip empty pages.
                if (!$attributes->count()) {
                    continue;
                }
                // TODO The measure may not be pixel, but mm or inch. This may be managed by viewer.
                // TODO Check why casting to string is needed.
                $page = [];
                // $page['number'] = (string) ((@$attributes->PHYSICAL_IMG_NR) + 1);
                $page['number'] = (string) ($indexXmlPage + 1);
                $page['width'] = (string) @$attributes->WIDTH;
                $page['height'] = (string) @$attributes->HEIGHT;
                if (!$page['width'] || !$page['height']) {
                    $this->logger->warn(sprintf(
                        'Incomplete data for xml file from item #%1$s, page %2$s.', // @translate
                        $this->firstXmlFile->item()->id(), $indexXmlPage + 1
                    ));
                    continue;
                }

                $pageIndex = $indexXmlPage;
                // Should be the same than index.
                $pageIndex = $page['number'] - 1;
                if ($pageIndex !== $indexXmlPage) {
                    $this->logger->warn(sprintf(
                        'Inconsistent data for xml file from item #%1$s, page %2$s.', // @translate
                        $this->firstXmlFile->item()->id(), $indexXmlPage + 1
                    ));
                    continue;
                }

                $hits = [];
                $hitMatches = [];

                $xmlPage->registerXPathNamespace('alto', $altoNamespace);

                foreach ($xmlPage->xpath('descendant::alto:String') as $xmlString) {
                    $attributes = $xmlString->attributes();
                    $matches = [];
                    $zone = [];
                    $zone['text'] = (string) $attributes->CONTENT;
                    foreach ($queryWords as $chars) {
                        if (!empty($this->imageSizes[$pageIndex]['width'])
                            && !empty($this->imageSizes[$pageIndex]['height'])
                            && preg_match('/' . $chars . '/Uui', $zone['text'], $matches) > 0
                        ) {
                            $zone['top'] = (string) @$attributes->VPOS;
                            $zone['left'] = (string) @$attributes->HPOS;
                            $zone['width'] = (string) @$attributes->WIDTH;
                            $zone['height'] = (string) @$attributes->HEIGHT;
                            if (!strlen($zone['top']) || !strlen($zone['left']) || !$zone['width'] || !$zone['height']) {
                                $this->logger->warn(sprintf(
                                    'Inconsistent data for xml file from item #%1$s, page %2$s.', // @translate
                                    $this->firstXmlFile->item()->id(), $indexXmlPage + 1
                                ));
                                continue;
                            }

                            ++$hit;

                            $image = $this->imageSizes[$pageIndex];
                            $searchResult = new AnnotationSearchResult;
                            $searchResult->initOptions(['baseResultUrl' => $baseResultUrl, 'baseCanvasUrl' => $baseCanvasUrl]);
                            $result['resources'][] = $searchResult->setResult(compact('resource', 'image', 'page', 'zone', 'chars', 'hit'));
                            $result['media_ids'][] = $image['id'];

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
                'Error: XML alto content may be invalid for item #%1$d, index #%2$d!', // @translate
                $this->firstXmlFile->item()->id(), $indexXmlPage + 1
            ));
            return null;
        }

        $result['hit'] = $hit;

        return $result;
    }

    protected function searchFullTextPdfXml(SimpleXmlElement $xml, $queryWords): ?array
    {
        $result = [
            'resources' => [],
            'hits' => [],
            'media_ids' => [],
            'hit' => 0,
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
            // 0-based page index.
            $indexXmlPage = -1;
            // There is one xml that contains text of each page.
            foreach ($xml->page as $xmlPage) {
                ++$indexXmlPage;
                $attributes = $xmlPage->attributes();
                $page = [];
                $page['number'] = (string) @$attributes->number;
                $page['width'] = (string) @$attributes->width;
                $page['height'] = (string) @$attributes->height;
                if (!strlen($page['number']) || !strlen($page['width']) || !strlen($page['height'])) {
                    $this->logger->warn(sprintf(
                        'Incomplete data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->firstXmlFile->id(), $indexXmlPage + 1
                    ));
                    continue;
                }

                // Should be the same than index.
                $pageIndex = $page['number'] - 1;
                if ($pageIndex !== $indexXmlPage) {
                    $this->logger->warn(sprintf(
                        'Inconsistent data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->firstXmlFile->id(), $indexXmlPage + 1
                    ));
                    continue;
                }

                $hits = [];
                $hitMatches = [];
                // 0-based row index.
                $indexXmlLine = -1;
                foreach ($xmlPage->text as $xmlRow) {
                    ++$indexXmlLine;
                    $zone = [];
                    $zone['text'] = strip_tags($xmlRow->asXML());
                    foreach ($queryWords as $chars) {
                        if (!empty($this->imageSizes[$pageIndex]['width'])
                            && !empty($this->imageSizes[$pageIndex]['height'])
                            && preg_match('/' . $chars . '/Uui', $zone['text'], $matches) > 0
                        ) {
                            $attributes = $xmlRow->attributes();
                            $zone['top'] = (string) @$attributes->top;
                            $zone['left'] = (string) @$attributes->left;
                            $zone['width'] = (string) @$attributes->width;
                            $zone['height'] = (string) @$attributes->height;
                            if (!strlen($zone['top']) || !strlen($zone['left']) || !$zone['width'] || !$zone['height']) {
                                $this->logger->warn(sprintf(
                                    'Inconsistent data for xml file from pdf media #%1$s, page %2$s, row %3$s.', // @translate
                                    $this->firstXmlFile->id(), $indexXmlPage + 1, $indexXmlLine + 1
                                ));
                                continue;
                            }

                            ++$hit;

                            $image = $this->imageSizes[$pageIndex];
                            $searchResult = new AnnotationSearchResult;
                            $searchResult->initOptions(['baseResultUrl' => $baseResultUrl, 'baseCanvasUrl' => $baseCanvasUrl]);
                            $result['resources'][] = $searchResult->setResult(compact('resource', 'image', 'page', 'zone', 'chars', 'hit'));
                            $result['media_ids'][] = $image['id'];

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
                $this->firstXmlFile->id()
            ));
            return null;
        }

        $result['hit'] = $hit;

        return $result;
    }

    /**
     * Returns answers to a query on metadata.
     *
     * Media already returned with full text are not returned.
     *
     * @see self::searchFulltext()
     *
     * @return array|null
     *   Return resources (the pages) that match query for IIIF Search API.
     *
     * ```php
     * [
     *      'resources' => [
     *          [
     *              '@id' => 'https://your_domain.com/omeka-s/iiif-search/itemID/searchResults/ . a . numCanvas . h . numresult. r .  xCoord , yCoord, wCoord , hCoord ',
     *              '@type' => 'oa:Annotation',
     *              'motivation' => 'sc:painting',
     *              [
     *                  '@type' => 'cnt:ContentAsText',
     *                  'chars' =>  corresponding match char list,
     *              ],
     *              'on' => canvas url with coordonate for IIIF Server module,
     *          ],
     *     ],
     *     'hit' => 2,
     * ]
     * ```
     */
    protected function searchMediaValues(string $query, int $hit, array $iiifMediaIds = []): ?array
    {
        if (!strlen($query)) {
            return null;
        }

        // A search result is an annotation on the canvas of the original item,
        // so an url managed by the iiif server.
        $iiifUrl = $this->getView()->plugin('iiifUrl');
        $baseResultUrl = $iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'annotation',
            'name' => 'search-result',
        ]) . '/';

        $baseCanvasUrl = $iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'canvas',
        ]) . '/p';

        $resource = $this->item;

        $imageSizesById = [];
        foreach ($this->imageSizes as $index => $imageData) {
            $imageData['index'] = $index;
            $imageSizesById[$imageData['id']] = $imageData;
        }
        $imageIndexById = array_column($imageSizesById, 'index', 'id');

        // Only media is needed.
        $mediaQuery = [
            'item_id' => $this->item->id(),
            'fulltext_search' => $query,
            // Position is not supported before v4.1.
            'sort_by' => version_compare(\Omeka\Module::VERSION, '4.1.0', '<') ? 'id' : 'position',
            'sort_order' => 'asc',
        ];
        $result = [];
        $chars = '';
        // TODO Remove files that are not images in result.
        $mediaIds = $this->api->search('media', $mediaQuery, ['initialize' => false, 'returnScalar' => 'id'])->getContent();
        foreach (array_diff($mediaIds, $iiifMediaIds) as $id) {
            // Skip files that are not images.
            if (!isset($imageIndexById[$id])) {
                continue;
            }
            ++$hit;
            $image = $imageSizesById[$id];
            $zone = [
                'text' => '',
                'top' => 0,
                'left' => 0,
                'width' => $image['width'],
                'height' => $image['height'],
            ];
            $page = [
                'number' => $imageIndexById[$id] + 1,
                'width' => $image['width'],
                'height' => $image['height'],
            ];
            $searchResult = new AnnotationSearchResult;
            $searchResult->initOptions(['baseResultUrl' => $baseResultUrl, 'baseCanvasUrl' => $baseCanvasUrl]);
            $result['resources'][] = $searchResult->setResult(compact('resource', 'image', 'page', 'zone', 'chars', 'hit'));
        }

        $result['hit'] = $hit;

        return $result;
    }

    /**
     * Check if the item support search and init the xml files.
     *
     * There may be one xml for all pages (pdf2xml).
     * There may be one xml by page.
     * There may be missing alto to some images.
     * There may be only xml files in the items.
     * Alto allows one xml by page or one xml for all pages too.
     *
     * So get the exact list matching images (if any) to avoid bad page indexes.
     *
     * The logic is managed by the module Iiif server if available: it manages
     * the same process for the text overlay.
     *
     * An option allows to force the matching of files (order or filename).
     */
    protected function prepareSearch(): bool
    {
        $this->xmlFiles = [];
        $this->imageSizes = [];

        $this->prepareSearchOrder();

        $this->firstXmlFile = count($this->xmlFiles) ? reset($this->xmlFiles) : null;

        $result = $this->firstXmlFile
            && count($this->imageSizes);

        if (!$result) {
            return false;
        }

        if ($this->xmlImageMatch === 'basename') {
            $this->prepareSearchBasename();
        }

        return true;
    }

    protected function prepareSearchOrder(): self
    {
        foreach ($this->item->media() as $media) {
            $mediaId = $media->id();
            $mediaType = $media->mediaType();
            if (in_array($mediaType, $this->supportedMediaTypes)) {
                $this->xmlFiles[$mediaId] = $media;
            } elseif ($mediaType === 'text/xml' || $mediaType === 'application/xml') {
                $this->logger->warn(
                    sprintf('Warning: Xml format "%1$s" of media #%2$d is not precise. It may be related to a badly formatted file (%3$s). Use EasyAdmin tasks to fix media type.', // @translate
                        $mediaType, $mediaId, $media->originalUrl()
                ));
                $this->xmlFiles[$mediaId] = $media;
            } else {
                // TODO The images sizes may be stored by xml files too, so skip size retrieving once the matching between images and text is done by page.
                $mediaData = $media->mediaData();
                // Iiif info stored by Omeka.
                if (isset($mediaData['width'])) {
                    $this->imageSizes[$mediaId] = [
                        'id' => $mediaId,
                        'width' => $mediaData['width'],
                        'height' => $mediaData['height'],
                        'source' => $media->source(),
                    ];
                }
                // Info stored by Iiif Server.
                elseif (isset($mediaData['dimensions']['original']['width'])) {
                    $this->imageSizes[$mediaId] = [
                        'id' => $mediaId,
                        'width' => $mediaData['dimensions']['original']['width'],
                        'height' => $mediaData['dimensions']['original']['height'],
                        'source' => $media->source(),
                    ];
                } elseif ($media->hasOriginal() && strtok($mediaType, '/') === 'image') {
                    $size = ['id' => $mediaId];
                    $size += $this->imageSize
                        ? $this->imageSize->__invoke($media, 'original')
                        : $this->imageSizeLocal($media);
                    $size['source'] = $media->source();
                    $this->imageSizes[$mediaId] = $size;
                }
            }
        }

        return $this;
    }

    /**
     * Reorder files according to source basename.
     */
    protected function prepareSearchBasename(): self
    {
        $result = [];

        $xmls = [];
        foreach ($this->xmlFiles as $indexXml => $media) {
            $xmls[$indexXml] = pathinfo($media->source(), PATHINFO_FILENAME);
        }

        foreach ($this->imageSizes as $indexImage => $sizeData) {
            $basename = pathinfo($sizeData['source'], PATHINFO_FILENAME);
            $xmlIndex = array_search($basename, $xmls);
            $result[$indexImage] = $xmlIndex === false
                ? null
                : $this->xmlFiles[$xmlIndex];
        }

        $this->xmlFiles = $result;

        $this->firstXmlFile = count($this->xmlFiles) ? reset($this->xmlFiles) : null;

        return $this;
    }

    protected function imageSizeLocal(MediaRepresentation $media): array
    {
        // Some media types don't save the file locally.
        $filepath = ($filename = $media->filename())
            ? $this->basePath . '/original/' . $filename
            : $media->originalUrl();
        $size = getimagesize($filepath);
        return $size
            ? ['width' => $size[0], 'height' => $size[1]]
            : ['width' => 0, 'height' => 0];
    }

    /**
     * Normalize query because the search occurs inside a normalized text.
     *
     * Don't query small words and quote them one time.
     *
     * The comparaison with strcasecmp() give bad results with unicode ocr, so
     * use preg_match().
     *
     * The same word can be set multiple times in the same query.
     */
    protected function formatQuery($query): array
    {
        $minimumQueryLength = $this->view->setting('iiifsearch_minimum_query_length')
            ?: $this->minimumQueryLength;

        $cleanQuery = $this->alnumString($query);
        if (mb_strlen($cleanQuery) < $minimumQueryLength) {
            return [];
        }

        $queryWords = explode(' ', $cleanQuery);
        if (count($queryWords) === 1) {
            return [preg_quote($queryWords[0], '/')];
        }

        $chars = [];
        foreach ($queryWords as $queryWord) {
            if (mb_strlen($queryWord) >= $minimumQueryLength) {
                $chars[] = preg_quote($queryWord, '/');
            }
        }
        if (count($chars) > 1) {
            $chars[] = preg_quote($queryWords, '/');
        }
        return $chars;
    }

    /**
     * @see \IiifServer\Iiif\TraitXml::loadXml()
     *
     * @todo The format pdf2xml can be replaced by an alto multi-pages, even if the format is quicker for search, but less precise for positions.
     */
    protected function loadXml(): ?SimpleXMLElement
    {
        if (!$this->firstXmlFile) {
            return null;
        }

        // The media type is already checked.
        $this->mediaType = $this->firstXmlFile->mediaType();

        $toCache = false;
        if ($this->mediaType === 'application/alto+xml' && count($this->xmlFiles) > 1) {
            // Check if the file is cached via module DerivativeMedia.
            if ($this->hasDerivative) {
                $derivative = $this->hasDerivative->__invoke($this->item, 'alto');
                if ($derivative) {
                    $filepath = $this->basePath . '/' . $derivative['alto']['file'];
                    if ($derivative['alto']['ready']) {
                        $xml = @simplexml_load_file($filepath);
                        if ($xml) {
                            return $xml;
                        }
                        $toCache = true;
                    }
                    if (!$derivative['alto']['in_progress']) {
                        $toCache = true;
                    }
                }
                // Else derivative is not enabled in module DerivativeMedia.
            }

            // For compatibility, the process require all filepath and media id.
            // Files are already checked.
            $mediaData = [];
            foreach ($this->xmlFiles as $media) {
                $mediaId = $media->id();
                $filename = $media->filename();
                $filepath = $this->basePath . '/original/' . $filename;
                $mediaType = $media->mediaType();
                $mainType = strtok($mediaType, '/');
                $extension = $media->extension();
                $mediaData[$mediaId] = [
                    'id' => $mediaId,
                    'source' => $media->source(),
                    'filename' => $filename,
                    'filepath' => $filepath,
                    'mediatype' => $mediaType,
                    'maintype' => $mainType,
                    'extension' => $extension,
                    'size' => $media->size(),
                ];
            }

            $xml = $this->xmlAltoSingle->__invoke($this->item, null, $mediaData);

            if ($xml && $toCache) {
                // Ensure dirpath. Don't keep issue, it's only cache.
                if (!file_exists(dirname($filepath))) {
                    @mkdir(dirname($filepath), 0775, true);
                }
                @$xml->asXML($filepath);
            }

            return $xml;
        }

        // Get local file if any, else url (there is only one file here).
        $filepath = ($filename = $this->firstXmlFile->filename())
            ? $this->basePath . '/original/' . $filename
            : $this->firstXmlFile->originalUrl();

        $isPdf2Xml = $this->mediaType === 'application/vnd.pdf2xml+xml';

        return $this->loadXmlFromFilepath($filepath, $isPdf2Xml);
    }

    protected function loadXmlFromFilepath(string $filepath, bool $isPdf2Xml = false): ?SimpleXMLElement
    {
        $xmlContent = file_get_contents($filepath);

        try {
            if ($this->xmlFixMode === 'dom') {
                if ($isPdf2Xml) {
                    $xmlContent = $this->fixXmlPdf2Xml($xmlContent);
                }
                $xmlContent = $this->fixXmlDom($xmlContent);
            } elseif ($this->xmlFixMode === 'regex') {
                $xmlContent = $this->fixUtf8->__invoke($xmlContent);
                if ($isPdf2Xml) {
                    $xmlContent = $this->fixXmlPdf2Xml($xmlContent);
                }
                $currentXml = @simplexml_load_string($xmlContent);
            } elseif ($this->xmlFixMode === 'all') {
                $xmlContent = $this->fixUtf8->__invoke($xmlContent);
                if ($isPdf2Xml) {
                    $xmlContent = $this->fixXmlPdf2Xml($xmlContent);
                }
                $currentXml = $this->fixXmlDom($xmlContent);
            } else {
                if ($isPdf2Xml) {
                    $xmlContent = $this->fixXmlPdf2Xml($xmlContent);
                }
                $currentXml = @simplexml_load_string($xmlContent);
            }
        } catch (\Exception $e) {
            $this->logger->err(sprintf(
                'Error: XML content is incorrect for media #%d.', // @translate
                $this->firstXmlFile->id()
            ));
            return null;
        }

        if (!$currentXml) {
            $this->logger->err(sprintf(
                'Error: XML content seems empty for media #%d.', // @translate
                $this->firstXmlFile->id()
            ));
            return null;
        }

        return $currentXml;
    }

    protected function fixXmlDom(string $xmlContent): ?SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.1', 'UTF-8');
        $dom->strictErrorChecking = false;
        $dom->validateOnParse = false;
        $dom->recover = true;
        $dom->loadXML($xmlContent);
        $currentXml = simplexml_import_dom($dom);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        return $currentXml;
    }

    protected function fixXmlPdf2Xml(string $xmlContent): string
    {
        $xmlContent = preg_replace('/\s{2,}/ui', ' ', $xmlContent);
        $xmlContent = preg_replace('/<\/?b>/ui', '', $xmlContent);
        $xmlContent = preg_replace('/<\/?i>/ui', '', $xmlContent);
        $xmlContent = str_replace('<!doctype pdf2xml system "pdf2xml.dtd">', '<!DOCTYPE pdf2xml SYSTEM "pdf2xml.dtd">', $xmlContent);
        return $xmlContent;
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
}
