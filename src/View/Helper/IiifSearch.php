<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use DerivativeMedia\View\Helper\DerivativeList;
use DOMDocument;
use Exception;
use IiifSearch\Iiif\Search1\AnnotationList;
use IiifSearch\Iiif\Search1\Builder as Search1Builder;
use IiifServer\Mvc\Controller\Plugin\MediaDimension;
use Laminas\Log\Logger;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Manager as ApiManager;
use IiifSearch\Mvc\Controller\Plugin\IsAllowedOcr;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Stdlib\Message;
use SimpleXMLElement;

class IiifSearch extends AbstractHelper
{
    /**
     * Local index files: files/{dir}/{item_id}.{extension}.
     */
    const SUPPORTED_INDEXES = [
        'text/tab-separated-values' => [
            'dir' => 'iiif-search',
            'extension' => 'full.tsv',
            'media_type' => 'text/tab-separated-values',
            'short_extension' => 'tsv',
        ],
        'text/tab-separated-values;by-word' => [
            'dir' => 'iiif-search',
            'extension' => 'by-word.tsv',
            'media_type' => 'text/tab-separated-values',
            'short_extension' => 'tsv',
        ],
        'application/vnd.pdf2xml+xml' => [
            'dir' => 'pdf2xml',
            'extension' => 'pdf2xml.xml',
            'media_type' => 'application/vnd.pdf2xml+xml',
            'short_extension' => 'xml',
        ],
        'application/alto+xml' => [
            'dir' => 'alto',
            'extension' => 'alto.xml',
            'media_type' => 'application/alto+xml',
            'short_extension' => 'xml',
        ],
        'text/vnd.hocr+html' => [
            'dir' => 'hocr',
            'extension' => 'hocr.html',
            'media_type' => 'text/vnd.hocr+html',
            'short_extension' => 'html',
        ],
    ];

    /**
     * Number of words of context before and after each hit.
     */
    const CONTEXT_WORDS = 5;

    /**
     * @var array
     */
    protected $supportedIndexes = self::SUPPORTED_INDEXES;

    /**
     * @var int
     */
    protected $minimumQueryLength = 3;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \DerivativeMedia\View\Helper\DerivativeList
     */
    protected $derivativeList;

    /**
     * @var \IiifServer\Mvc\Controller\Plugin\FixUtf8
     */
    protected $fixUtf8;

    /**
     * @var \IiifServer\Mvc\Controller\Plugin\MediaDimension|null
     */
    protected $mediaDimension;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \IiifSearch\View\Helper\XmlAltoSingle
     */
    protected $xmlAltoSingle;

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
    protected $xmlFixMode;

    /**
     * @var string
     */
    protected $pairingMode = 'auto';

    public function setPairingMode(string $mode): self
    {
        $this->pairingMode = $mode ?: 'auto';
        return $this;
    }

    /**
     * @var array
     */
    protected $imageSizes;

    /**
     * @var string|null
     */
    protected $index;

    /**
     * @var string|null
     */
    protected $indexFilePath;

    /**
     * @var ItemRepresentation
     */
    protected $item;

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation
     */
    protected $mediaTsv;

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation[]
     */
    protected $mediaXml;

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation
     */
    protected $mediaXmlFirst;

    /**
     * @var string
     */
    protected $query;

    /**
     * @var string
     */
    protected $queryWords;

    /**
     * The words of the query to match, with the same keys than queryWords.
     *
     * @var array|null
     */
    protected $queryTerms;

    /**
     * Cache of the keys of the words of the index.
     *
     * @var array
     */
    protected $wordKeys = [];

    /**
     * @var bool
     */
    protected $queryIsExactSearch;

    /**
     * @var string
     */
    protected $baseResultUrl;

    /**
     * @var string
     */
    protected $baseCanvasUrl;

    /**
     * @var \IiifSearch\Mvc\Controller\Plugin\IsAllowedOcr|null
     */
    protected $isAllowedOcr;

    /**
     * Access to the ocr of each page, by page index.
     *
     * @var array<int, bool>
     */
    protected $ocrPageAllowed = [];

    public function __construct(
        ApiManager $api,
        ?DerivativeList $derivativeList,
        FixUtf8 $fixUtf8,
        ?MediaDimension $mediaDimension,
        Logger $logger,
        XmlAltoSingle $xmlAltoSingle,
        string $basePath,
        bool $searchMediaValues,
        string $xmlFixMode,
        ?IsAllowedOcr $isAllowedOcr = null
    ) {
        $this->isAllowedOcr = $isAllowedOcr;
        $this->api = $api;
        $this->derivativeList = $derivativeList;
        $this->fixUtf8 = $fixUtf8;
        $this->mediaDimension = $mediaDimension;
        $this->logger = $logger;
        $this->xmlAltoSingle = $xmlAltoSingle;
        $this->basePath = $basePath;
        $this->searchMediaValues = $searchMediaValues;
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
        $pivot = $this->getRawMatches($item);
        if ($pivot === null) {
            return null;
        }
        $requestUri = $this->getView()->serverUrl(true);
        return (new Search1Builder())->build($pivot, $requestUri);
    }

    /**
     * Run the search engine and return a neutral pivot consumed by version
     * specific builders (Search 1.0 / Search 2.0).
     *
     * Returns null only when the resource does not support search at all.
     * Returns an empty pivot (matches=[], pageHits=[]) when search is supported
     * but the query is missing, too short, or has no match.
     */
    public function getRawMatches(ItemRepresentation $item): ?array
    {
        $this->item = $item;
        $this->ocrPageAllowed = [];

        $view = $this->getView();

        // Prepare query early to manage the better search process. Limit query
        // length to 100 characters to prevent overload.
        $this->query = mb_substr(trim((string) $view->params()->fromQuery('q')), 0, 100);
        $this->queryIsExactSearch = mb_substr($this->query, 0, 1) === '"' && mb_substr($this->query, -1) === '"';
        if ($this->queryIsExactSearch) {
            $this->query = trim(mb_substr($this->query, 1, -1));
        }

        // Normally not possible, because the service should not be set in the
        // manifest in that case. But some viewers or users can try direct
        // requests.
        if (!$this->prepareSearchIndexAndImages()) {
            return null;
        }

        // Without any readable page there is no transcription to search in, so
        // report the search as unavailable rather than as empty.
        if (!$this->hasAllowedOcrPage()) {
            return null;
        }

        $pivot = [
            'baseResultUrl' => '',
            'baseCanvasUrl' => '',
            'totalHit' => 0,
            'matches' => [],
            'pageHits' => [],
        ];

        if (!strlen($this->query)) {
            return $pivot;
        }

        // TODO Add a warning when the number of images is not the same than the number of pages. But it may be complex because images are not really managed with xml files, so warn somewhere else.

        // Pre-compute base URLs once for all search methods.
        $iiifUrl = $view->plugin('iiifUrl');
        // Canvas/annotation identifiers must match the ones embedded in the
        // served manifest, so that viewers (Mirador, UV, etc.) can map search
        // results back to canvases. Many viewers still use Search 1 even with a
        // Presentation 3 manifest, so align on the version of the manifest that
        // declares the service, set in the url when it is not the default one,
        // regardless of the search API version.
        $manifestVersion = (string) ($view->params()->fromRoute('manifest')
            ?: $view->setting('iiifserver_manifest_default_version', '2'));
        $this->baseResultUrl = $iiifUrl($this->item, 'iiifserver/uri', $manifestVersion, [
            'type' => 'annotation',
            'name' => 'search-result',
        ]) . '/';
        $this->baseCanvasUrl = $iiifUrl($this->item, 'iiifserver/uri', $manifestVersion, [
            'type' => 'canvas',
        ]) . '/p';

        $pivot['baseResultUrl'] = $this->baseResultUrl;
        $pivot['baseCanvasUrl'] = $this->baseCanvasUrl;

        $result = $this->filterDeniedOcrPages($this->searchFulltext());

        if ($this->searchMediaValues) {
            $resultValues = $this->searchMediaValues($result ? $result['hit'] : 0, $result ? $result['media_ids'] : []);
            if ($result === null && $resultValues === null) {
                return $pivot;
            } elseif ($result === null) {
                $result = $resultValues;
            } elseif ($resultValues === null || $resultValues['hit'] === 0) {
                // Nothing to add to result.
            } else {
                $result['resources'] = array_merge($result['resources'], $resultValues['resources']);
                $result['hit'] += $resultValues['hit'];
            }
        }

        if ($result && $result['hit']) {
            $pivot['totalHit'] = $result['hit'];
            $pivot['matches'] = $result['resources'] ?? [];
            $pivot['pageHits'] = $result['hits'] ?? [];
        }

        return $pivot;
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
     *             'before' => 'the words before the ',
     *             'after' => ' and the words after',
     *         ],
     *     ],
     *     'media_ids' => [1],
     *     'hit' => 1,
     * ]
     * ```
     */
    /**
     * The ocr of a page follows the access level of the file it belongs to. The
     * check is memoized, because the tsv sources ask it for each word.
     */
    protected function isDeniedOcrPage(int $pageIndex): bool
    {
        if (!$this->isAllowedOcr) {
            return false;
        }
        if (!isset($this->ocrPageAllowed[$pageIndex])) {
            $this->ocrPageAllowed[$pageIndex] = $this->isAllowedOcr->__invoke($this->item, $pageIndex);
        }
        return !$this->ocrPageAllowed[$pageIndex];
    }

    /**
     * Is at least one page of the item readable? The pages are counted with the
     * images, and a single page is assumed when the item has none, for example
     * an item made of a single pdf.
     */
    protected function hasAllowedOcrPage(): bool
    {
        if (!$this->isAllowedOcr) {
            return true;
        }
        $countPages = max(count($this->imageSizes), 1);
        for ($pageIndex = 0; $pageIndex < $countPages; $pageIndex++) {
            if (!$this->isDeniedOcrPage($pageIndex)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove the matches of the pages that are not readable.
     *
     * The hits are grouped by page, but only by position, without the number of
     * the page. So rebuild them from the numbers of the hits that are kept. The
     * numbers are identifiers used to build the uris of the results, so gaps
     * are harmless.
     */
    protected function filterDeniedOcrPages(?array $result): ?array
    {
        if (!$this->isAllowedOcr || empty($result['resources'])) {
            return $result;
        }

        $keptHits = [];
        $resources = [];
        $mediaIds = [];
        foreach ($result['resources'] as $key => $resource) {
            // The number of the page is one-based in all ocr sources.
            $pageIndex = (int) ($resource['page']['number'] ?? 0) - 1;
            if ($pageIndex < 0 || $this->isDeniedOcrPage($pageIndex)) {
                continue;
            }
            $resources[] = $resource;
            $keptHits[(int) $resource['hit']] = true;
            if (isset($result['media_ids'][$key])) {
                $mediaIds[] = $result['media_ids'][$key];
            }
        }

        $hits = [];
        foreach ($result['hits'] ?? [] as $pageHits) {
            $kept = array_values(array_filter(
                $pageHits['hits'] ?? [],
                fn ($hit) => isset($keptHits[(int) $hit])
            ));
            if ($kept) {
                // Keep the match and its context.
                $hits[] = ['hits' => $kept] + $pageHits + ['match' => ''];
            }
        }

        $result['resources'] = $resources;
        $result['media_ids'] = $mediaIds;
        $result['hits'] = $hits;
        $result['hit'] = count($resources);

        return $resources ? $result : null;
    }

    protected function searchFulltext(): ?array
    {
        if (!strlen($this->query)) {
            return null;
        }

        $this->queryWords = $this->prepareAndFormatQueryByWord();
        if (empty($this->queryWords)) {
            return null;
        }

        if ($this->index === 'text/tab-separated-values') {
            $filepath = $this->indexFilePath ?: $this->basePath . '/original/' . $this->mediaTsv->filename();
            return $this->searchFullTextTsv($filepath, false);
        } elseif ($this->index === 'text/tab-separated-values;by-word') {
            $filepath = $this->indexFilePath ?: $this->basePath . '/original/' . $this->mediaTsv->filename();
            return $this->searchFullTextTsv($filepath, true);
        }

        if ($this->index === 'text/vnd.hocr+html') {
            return $this->searchFullTextHocr();
        }

        $xml = $this->loadXml();
        // loadXml() may set $this->index for media-based hOCR.
        if ($this->index === 'text/vnd.hocr+html') {
            return $this->searchFullTextHocr();
        }
        if (empty($xml)) {
            return null;
        } elseif ($this->index === 'application/alto+xml') {
            return $this->searchFullTextAlto($xml);
        } elseif ($this->index === 'application/vnd.pdf2xml+xml') {
            return $this->searchFullTextPdfXml($xml);
        } else {
            return null;
        }
    }

    protected function searchFullTextAlto(SimpleXmlElement $xml): ?array
    {
        $result = [
            'resources' => [],
            'hits' => [],
            'media_ids' => [],
            'hit' => 0,
        ];

        $baseResultUrl = $this->baseResultUrl;
        $baseCanvasUrl = $this->baseCanvasUrl;

        // XPath’s string literals can’t contain both " and ‘ and doesn’t manage
        // insensitive comparaison simply, so get all strings and preg them.

        $namespaces = $xml->getDocNamespaces();
        $altoNamespace = $namespaces['alto'] ?? $namespaces[''] ?? 'http://www.loc.gov/standards/alto/ns-v4#';
        $xml->registerXPathNamespace('alto', $altoNamespace);

        $resource = $this->item;
        try {
            // The hit index in the full resource, used to build search result
            // uris.
            $hit = 0;
            // 0-based page index.
            $indexPageXml = -1;
            /** @var \SimpleXmlElement $xmlPage */
            foreach ($xml->Layout->Page as $xmlPage) {
                ++$indexPageXml;
                $attributes = $xmlPage->attributes();
                // Skip empty pages.
                if (!$attributes->count()) {
                    continue;
                }
                // TODO The measure may not be pixel, but mm or inch. This may be managed by viewer.
                // TODO Check why casting to string is needed.
                $page = [];
                // $page['number'] = (string) ((@$attributes->PHYSICAL_IMG_NR) + 1);
                $page['number'] = (string) ($indexPageXml + 1);
                $page['width'] = (string) @$attributes->WIDTH;
                $page['height'] = (string) @$attributes->HEIGHT;
                if (!$page['width'] || !$page['height']) {
                    $this->logger->warn(new Message(
                        'Incomplete data for xml file from item #%1$s, page %2$s.', // @translate
                        $this->item->id(), $indexPageXml + 1
                    ));
                    continue;
                }

                $pageIndex = $indexPageXml;
                // Should be the same than index.
                $pageIndex = $page['number'] - 1;
                if ($pageIndex !== $indexPageXml) {
                    $this->logger->warn(new Message(
                        'Inconsistent data for xml file from item #%1$s, page %2$s.', // @translate
                        $this->item->id(), $indexPageXml + 1
                    ));
                    continue;
                }

                // The words of the page, in order, to get the context of hits.
                $pageWords = [];
                $pageHits = [];

                $xmlPage->registerXPathNamespace('alto', $altoNamespace);

                foreach ($xmlPage->xpath('descendant::alto:String') as $xmlString) {
                    $attributes = $xmlString->attributes();
                    $matches = [];
                    $zone = [];
                    $zone['text'] = (string) $attributes->CONTENT;
                    $wordIndex = count($pageWords);
                    $pageWords[] = $zone['text'];
                    foreach ($this->queryWords as $chars) {
                        if (!empty($this->imageSizes[$pageIndex]['width'])
                            && !empty($this->imageSizes[$pageIndex]['height'])
                            && preg_match('/' . $chars . '/Uui', $zone['text'], $matches) > 0
                        ) {
                            $zone['top'] = (string) @$attributes->VPOS;
                            $zone['left'] = (string) @$attributes->HPOS;
                            $zone['width'] = (string) @$attributes->WIDTH;
                            $zone['height'] = (string) @$attributes->HEIGHT;
                            if (!strlen($zone['top']) || !strlen($zone['left']) || !$zone['width'] || !$zone['height']) {
                                $this->logger->warn(new Message(
                                    'Inconsistent data for xml file from item #%1$s, page %2$s.', // @translate
                                    $this->item->id(), $indexPageXml + 1
                                ));
                                continue;
                            }

                            ++$hit;

                            $image = $this->imageSizes[$pageIndex];

                            $result['resources'][] = compact('resource', 'image', 'page', 'zone', 'chars', 'hit');
                            $result['media_ids'][] = $image['id'];

                            // TODO Get matches as whole world and all matches in last time (preg_match_all).
                            $pageHits[] = [$hit, $wordIndex, $matches[0]];
                        }
                    }
                }

                // Add one hit by match, with its context.
                foreach ($pageHits as [$pageHit, $wordIndex, $match]) {
                    $result['hits'][] = ['hits' => [$pageHit]]
                        + $this->contextOf($pageWords, $wordIndex, $match);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->err(new Message(
                'Error: XML alto content may be invalid for item #%1$d, index #%2$d.', // @translate
                $this->item->id(), $indexPageXml + 1
            ));
            return null;
        }

        $result['hit'] = $hit;

        return $result;
    }

    /**
     * Search full text in hOCR (html-based ocr format).
     *
     * hocr uses html with specific css classes (ocr_page, ocrx_word) and bbox
     * coordinates in title attributes.
     *
     * @see https://kba.github.io/hocr-spec/1.2/
     */
    protected function searchFullTextHocr(): ?array
    {
        $result = [
            'resources' => [],
            'hits' => [],
            'media_ids' => [],
            'hit' => 0,
        ];

        $filepath = $this->indexFilePath;
        if (!$filepath) {
            if (!$this->mediaXmlFirst) {
                return null;
            }
            $filename = $this->mediaXmlFirst->filename();
            $filepath = $filename
                ? $this->basePath . '/original/' . $filename
                : $this->mediaXmlFirst->originalUrl();
        }

        $htmlContent = @file_get_contents($filepath);
        if (!$htmlContent) {
            $this->logger->err(new Message(
                'Error: hOCR content is empty for item #%d.', // @translate
                $this->item->id()
            ));
            return null;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?>' . $htmlContent,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $baseResultUrl = $this->baseResultUrl;
        $baseCanvasUrl = $this->baseCanvasUrl;

        $resource = $this->item;
        $xpath = new \DOMXPath($dom);

        try {
            $hit = 0;
            $indexPageXml = -1;

            $pages = $xpath->query(
                "//*[contains(@class, 'ocr_page')]"
            );
            foreach ($pages as $pageNode) {
                ++$indexPageXml;

                $pageBbox = $this->parseHocrBbox(
                    $pageNode->getAttribute('title')
                );
                if (!$pageBbox) {
                    $this->logger->warn(new Message(
                        'Incomplete data for hOCR file from item #%1$s, page %2$s.', // @translate
                        $this->item->id(), $indexPageXml + 1
                    ));
                    continue;
                }

                $page = [];
                $page['number'] = (string) ($indexPageXml + 1);
                $page['width'] = (string) $pageBbox['width'];
                $page['height'] = (string) $pageBbox['height'];

                $pageIndex = $indexPageXml;

                // The words of the page, in order, to get the context of hits.
                $pageWords = [];
                $pageHits = [];

                $words = $xpath->query(
                    ".//*[contains(@class, 'ocrx_word')]",
                    $pageNode
                );
                foreach ($words as $wordNode) {
                    $zone = [];
                    $zone['text'] = $wordNode->textContent;
                    if (!strlen($zone['text'])) {
                        continue;
                    }
                    $wordIndex = count($pageWords);
                    $pageWords[] = trim($zone['text']);

                    foreach ($this->queryWords as $chars) {
                        $matches = [];
                        if (!empty($this->imageSizes[$pageIndex]['width'])
                            && !empty($this->imageSizes[$pageIndex]['height'])
                            && preg_match('/' . $chars . '/Uui', $zone['text'], $matches) > 0
                        ) {
                            $wordBbox = $this->parseHocrBbox(
                                $wordNode->getAttribute('title')
                            );
                            if (!$wordBbox) {
                                $this->logger->warn(new Message(
                                    'Inconsistent data for hOCR file from item #%1$s, page %2$s.', // @translate
                                    $this->item->id(), $indexPageXml + 1
                                ));
                                continue;
                            }

                            $zone['left'] = (string) $wordBbox['left'];
                            $zone['top'] = (string) $wordBbox['top'];
                            $zone['width'] = (string) $wordBbox['width'];
                            $zone['height'] = (string) $wordBbox['height'];

                            ++$hit;

                            $image = $this->imageSizes[$pageIndex];

                            $result['resources'][] = compact('resource', 'image', 'page', 'zone', 'chars', 'hit');
                            $result['media_ids'][] = $image['id'];

                            $pageHits[] = [$hit, $wordIndex, $matches[0]];
                        }
                    }
                }

                // Add one hit by match, with its context.
                foreach ($pageHits as [$pageHit, $wordIndex, $match]) {
                    $result['hits'][] = ['hits' => [$pageHit]]
                        + $this->contextOf($pageWords, $wordIndex, $match);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->err(new Message(
                'Error: hOCR content may be invalid for item #%1$d, page #%2$d.', // @translate
                $this->item->id(), $indexPageXml + 1
            ));
            return null;
        }

        $result['hit'] = $hit;

        return $result;
    }

    /**
     * Parse a hOCR title attribute and extract bbox coordinates.
     *
     * The title attribute has the format:
     * "bbox x1 y1 x2 y2; x_wconf 95"
     *
     * @return array|null Array with keys left, top, width, height
     *   or null if bbox is missing.
     */
    protected function parseHocrBbox(string $title): ?array
    {
        if (!preg_match('/\bbbox\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $title, $m)) {
            return null;
        }
        $x1 = (int) $m[1];
        $y1 = (int) $m[2];
        $x2 = (int) $m[3];
        $y2 = (int) $m[4];
        $w = $x2 - $x1;
        $h = $y2 - $y1;
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        return [
            'left' => $x1,
            'top' => $y1,
            'width' => $w,
            'height' => $h,
        ];
    }

    protected function searchFullTextPdfXml(SimpleXmlElement $xml): ?array
    {
        $result = [
            'resources' => [],
            'hits' => [],
            'media_ids' => [],
            'hit' => 0,
        ];

        $baseResultUrl = $this->baseResultUrl;
        $baseCanvasUrl = $this->baseCanvasUrl;

        $resource = $this->item;
        $matches = [];
        try {
            // The hit index in the full resource, used to build search result
            // uris.
            $hit = 0;
            // 0-based page index.
            $indexPageXml = -1;
            // There is one xml that contains text of each page.
            foreach ($xml->page as $xmlPage) {
                ++$indexPageXml;
                $attributes = $xmlPage->attributes();
                $page = [];
                $page['number'] = (string) @$attributes->number;
                $page['width'] = (string) @$attributes->width;
                $page['height'] = (string) @$attributes->height;
                if (!strlen($page['number']) || !strlen($page['width']) || !strlen($page['height'])) {
                    $this->logger->warn(new Message(
                        'Incomplete data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->mediaXmlFirst->id(), $indexPageXml + 1
                    ));
                    continue;
                }

                // Should be the same than index.
                $pageIndex = $page['number'] - 1;
                if ($pageIndex !== $indexPageXml) {
                    $this->logger->warn(new Message(
                        'Inconsistent data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->mediaXmlFirst->id(), $indexPageXml + 1
                    ));
                    continue;
                }

                // The words of the page, in order, to get the context of hits.
                // A zone is a row here, so the words are the words of the rows.
                $pageWords = [];
                $pageHits = [];

                // 0-based row index.
                $indexXmlLine = -1;

                foreach ($xmlPage->text as $xmlRow) {
                    ++$indexXmlLine;
                    $zone = [];
                    $zone['text'] = strip_tags($xmlRow->asXML());
                    $rowStart = count($pageWords);
                    $rowWords = preg_split('/\s+/u', trim(html_entity_decode($zone['text'], ENT_QUOTES | ENT_XML1, 'UTF-8')), -1, PREG_SPLIT_NO_EMPTY);
                    array_push($pageWords, ...$rowWords);

                    foreach ($this->queryWords as $chars) {
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
                                $this->logger->warn(new Message(
                                    'Inconsistent data for xml file from pdf media #%1$s, page %2$s, row %3$s.', // @translate
                                    $this->mediaXmlFirst->id(), $indexPageXml + 1, $indexXmlLine + 1
                                ));
                                continue;
                            }

                            ++$hit;

                            // Manage the case where some image data are missing.
                            $image = $this->imageSizes[$pageIndex] ?? [
                                'id' => null,
                                'width' => null,
                                'height' => null,
                            ];

                            $result['resources'][] = compact('resource', 'image', 'page', 'zone', 'chars', 'hit');
                            $result['media_ids'][] = $image['id'];

                            // TODO Get matches as whole world and all matches in last time (preg_match_all).
                            // Locate the match in the words of the row.
                            $wordIndex = $rowStart;
                            foreach ($rowWords as $rowIndex => $rowWord) {
                                if (mb_stripos($rowWord, $matches[0]) !== false) {
                                    $wordIndex = $rowStart + $rowIndex;
                                    break;
                                }
                            }
                            $pageHits[] = [$hit, $wordIndex, $matches[0]];
                        }
                    }
                }

                // Add one hit by match, with its context.
                foreach ($pageHits as [$pageHit, $wordIndex, $match]) {
                    $result['hits'][] = ['hits' => [$pageHit]]
                        + $this->contextOf($pageWords, $wordIndex, $match);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->err(new Message(
                'Error: PDF to XML conversion failed for item #%1$d, media file #%2$d.', // @translate
                $this->item->id(), $this->mediaXmlFirst->id()
            ));
            return null;
        }

        $result['hit'] = $hit;

        return $result;
    }

    protected function searchFulltextTsv(string $filepath, bool $isTsvByWord) :?array
    {
        // Extract whole tsv.
        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            $this->logger->err(new Message(
                'Error: PDF to TSV conversion failed for item #%1$d, media #%2$d.', // @translate
                $this->item->id(), $this->mediaTsv ? $this->mediaTsv->id() : '-'
            ));
            return null;
        }

        $processExactSearch = $this->queryIsExactSearch
            // TODO Manage process for a single word.
            && count($this->queryWords) > 1
            && !$isTsvByWord;

        // The words around the hits, for their context. The tsv by word has no
        // order, so it has no context.
        $tracker = [];

        if ($processExactSearch) {
            // Search all words as an expression.
            // The words of the index keep the punctuation, so compare keys.
            $queryTerms = $this->getQueryTerms();
            unset($queryTerms['full']);
            $queryTerms = array_values($queryTerms);

            $countQueryWords = count($queryTerms);

            $expressionPositions = [];
            $indexQueryWord = 0;
            $currentExpression = [];
            $expressionBefore = [];
            while (($data = fgetcsv($handle, 1000000, "\t", "\0", "\0")) !== false) {
                $before = $this->trackTsvContext($tracker, $data);
                $wordKey = $this->wordKey($data[0]);
                if (!$this->matchTerm($queryTerms[$indexQueryWord], $wordKey)) {
                    // Reset current result, but retry with the first word.
                    $currentExpression = [];
                    if (!$indexQueryWord) {
                        continue;
                    }
                    $indexQueryWord = 0;
                    if (!$this->matchTerm($queryTerms[$indexQueryWord], $wordKey)) {
                        continue;
                    }
                }
                if (!$currentExpression) {
                    $expressionBefore = $before;
                }
                $currentExpression[] = $data;
                if (count($currentExpression) < $countQueryWords) {
                    ++$indexQueryWord;
                } else {
                    // Keep the context of the expression with each of its rows.
                    $contextKey = $this->addTsvContext($tracker, $expressionBefore, implode(' ', array_column($currentExpression, 0)));
                    foreach (array_keys($currentExpression) as $key) {
                        $currentExpression[$key][3] = $contextKey;
                    }
                    // Display the words as a whole, so compute xywh, but manage
                    // the case where there are multiple pages.
                    // The expression may be partial when it is on two pages.
                    $pages = array_unique(array_column($currentExpression, 1));
                    if (count($pages) === 1) {
                        // Get the xywh by page and by expression
                        // The string is probably useless here, but needed to
                        // manage the case where there are expressions on
                        // a single page and on multiple pages (see below).
                        $zones = [];
                        foreach ($currentExpression as $pageData) {
                            $left = strtok($pageData[2], ',');
                            $top = strtok(',');
                            $width = strtok(',');
                            $height = strtok(',');
                            if (!strlen($top) || !strlen($left) || !$width || !$height) {
                                $this->logger->warn(new Message(
                                    'Inconsistent data for item #%1$d, tsv media #%2$d, page %3$d, word %4$s.', // @translate
                                    $this->item->id(), $this->mediaTsv ? $this->mediaTsv->id() : '-', reset($pages) + 1, $this->query
                                ));
                                $indexQueryWord = 0;
                                $currentExpression = [];
                                continue 2;
                            }
                            $zones['left'][] = $left;
                            $zones['top'][] = $top;
                            $zones['right'][] = $left + $width;
                            $zones['bottom'][] = $top + $height;
                        }
                        // When a string is on multiple lines, so when a next
                        // left or a next top is greater than the previous one,
                        // display by word.
                        if (min($zones['left']) !== reset($zones['left'])
                            // TODO Check top, but the box may be greater and on the same line, according to bigger letters.
                            // TODO Manage rtl languages.
                            // || min($zones['top']) !== reset($zones['top'])
                        ) {
                            $expressionPositions[] = $currentExpression;
                        } else {
                            $wholeExpression = [
                                $this->query,
                                reset($pages),
                                min($zones['left'])
                                    . ',' . min($zones['top'])
                                    . ',' . (max($zones['right']) - min($zones['left']))
                                    . ',' . (max($zones['bottom']) - min($zones['top'])),
                                $contextKey,
                            ];
                            $expressionPositions[] = [$wholeExpression];
                        }
                    } else {
                        $expressionPositions[] = $currentExpression;
                    }
                    $indexQueryWord = 0;
                    $currentExpression = [];
                }
            }

            if (!count($expressionPositions)) {
                fclose($handle);
                return null;
            }

            $wordPositions = [$this->query => array_merge(...$expressionPositions)];
        } else {
            // Search each word ("OR").
            // The words of the index keep the punctuation, so compare keys.
            $queryTerms = $this->getQueryTerms();

            $wordPositions = [];
            if ($isTsvByWord) {
                while (($data = fgetcsv($handle, 1000000, "\t", "\0", "\0")) !== false) {
                    if ($this->matchQueryTerms($queryTerms, $this->wordKey($data[0]))) {
                        $wordPositions[$data[0]] = $data[1];
                    }
                }
            } else {
                while (($data = fgetcsv($handle, 1000000, "\t", "\0", "\0")) !== false) {
                    $before = $this->trackTsvContext($tracker, $data);
                    if ($this->matchQueryTerms($queryTerms, $this->wordKey($data[0]))) {
                        $contextKey = $this->addTsvContext($tracker, $before, $data[0]);
                        $wordPositions[mb_strtolower($data[0], 'UTF-8')][] = [$data[1], $data[2], $contextKey];
                    }
                }
            }

            if (!count($wordPositions)) {
                fclose($handle);
                return null;
            }
        }

        fclose($handle);

        // TODO Seach all words ("AND") but not an expression (require +).

        $result = [
            'resources' => [],
            'hits' => [],
            'media_ids' => [],
            'hit' => 0,
        ];

        $resource = $this->item;

        try {
            // The hit index in the full resource, used to build search result
            // uris.
            $hit = 0;

            // Because the tsv is not structured by page, store results then
            // normalize response. The two-steps process is simpler and allows
            // to keep the hits in the right order.
            $results = [];
            // The key of the context in the tracker, by hit.
            $hitContexts = [];

            // All words are already found.
            foreach ($wordPositions as $chars => $wordData) {
                $chars = $this->wordCore((string) $chars);
                $zone = [];
                $zone['text'] = $chars;
                foreach ($isTsvByWord ? explode(';', $wordData) : $wordData as $pageAndPosition) {
                    if ($isTsvByWord) {
                        $pageIndex = strtok($pageAndPosition, ':');
                        $zone['left'] = strtok(',');
                        $contextKey = null;
                    } elseif ($processExactSearch) {
                        $pageIndex = $pageAndPosition[1];
                        $zone['left'] = strtok($pageAndPosition[2], ',');
                        $contextKey = $pageAndPosition[3] ?? null;
                    } else {
                        $pageIndex = $pageAndPosition[0];
                        $zone['left'] = strtok($pageAndPosition[1], ',');
                        $contextKey = $pageAndPosition[2] ?? null;
                    }
                    $zone['top'] = strtok(',');
                    $zone['width'] = strtok(',');
                    $zone['height'] = strtok(',');

                    if (!strlen($zone['top']) || !strlen($zone['left']) || !$zone['width'] || !$zone['height']) {
                        $this->logger->warn(new Message(
                            'Inconsistent data for item #%1$d, tsv media #%2$d, page %3$s, word %4$s.', // @translate
                            $this->item->id(), $this->mediaTsv ? $this->mediaTsv->id() : '-', $pageIndex, $chars
                        ));
                        continue;
                    }

                    ++$hit;

                    // Images are 0-based, but pageIndex is 1-based.
                    // Manage the case where some image data are missing.
                    $image = $this->imageSizes[$pageIndex - 1] ?? [
                        'id' => null,
                        'width' => null,
                        'height' => null,
                    ];

                    // The tsv uses the size of the image, or the size of the page
                    // of the pdf in points.
                    $page = [];
                    $page['number'] = (string) $pageIndex;
                    $page['width'] = (string) ($image['page_width'] ?? $image['width']);
                    $page['height'] = (string) ($image['page_height'] ?? $image['height']);

                    $results[$pageIndex][$hit]['resource'] = $resource;
                    $results[$pageIndex][$hit]['image'] = $image;
                    $results[$pageIndex][$hit]['page'] = $page;
                    $results[$pageIndex][$hit]['zone'] = $zone;
                    $results[$pageIndex][$hit]['chars'] = $chars;
                    $results[$pageIndex][$hit]['hit'] = $hit;
                    if ($contextKey !== null) {
                        $hitContexts[$hit] = $contextKey;
                    }
                }
            }

            $baseResultUrl = $this->baseResultUrl;
            $baseCanvasUrl = $this->baseCanvasUrl;

            // The variable is reinit below, so store total first.
            $result['hit'] = $hit;

            // Add one hit by match for all pages, with its context if any.
            ksort($results);
            foreach ($results as $pageIndex => $resultHits) {
                foreach ($resultHits as $hit => $resultHit) {
                    $result['resources'][] = $resultHit;
                    $result['media_ids'][] = $resultHit['image']['id'];

                    $context = isset($hitContexts[$hit])
                        ? $tracker['contexts'][$hitContexts[$hit]] ?? null
                        : null;
                    $result['hits'][] = ['hits' => [$hit]]
                        + ($context
                            ? $this->formatContext($context['before'], $context['word'], $this->wordCore($context['word']), $context['after'])
                            : ['match' => $resultHit['chars']]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->err(new Message(
                'Error: PDF to TSV conversion failed for item #%1$d, media #%2$d.', // @translate
                $this->item->id(),
                $this->mediaTsv ? $this->mediaTsv->id() : '-'
            ));
            return null;
        }

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
     *   Results have no key "hits" and image cannot be highlighted, since the
     *   result is not present in the text, but in the metadata.
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
    protected function searchMediaValues(int $hit, array $iiifMediaIds = []): ?array
    {
        if (!strlen($this->query)) {
            return null;
        }

        // Only media is needed.
        $mediaQuery = [
            'item_id' => $this->item->id(),
            'fulltext_search' => $this->query,
            // Position is not supported before v4.1.
            'sort_by' => version_compare(\Omeka\Module::VERSION, '4.1.0', '<') ? 'id' : 'position',
            'sort_order' => 'asc',
        ];

        // TODO Remove files that are not images early.
        try {
            $mediaIds = $this->api->search('media', $mediaQuery, ['initialize' => false, 'returnScalar' => 'id'])->getContent();
        } catch (Exception $e) {
            return null;
        }

        // Also match DigitalObjects linked to the item: collect their ids via
        // itemFileResources, then ask the DO adapter for fulltext hits limited
        // to that set (DOs are not item-owned so item_id filtering does not
        // apply at the adapter level).
        try {
            $itemFileResources = $this->getView()->plugin('itemFileResources');
            $doIds = [];
            foreach ($itemFileResources($this->item) as $resource) {
                if ($resource->isDigitalObject()) {
                    $doIds[] = $resource->id();
                }
            }
            if ($doIds) {
                $doHits = $this->api->search('digital_objects', [
                    'id' => $doIds,
                    'fulltext_search' => $this->query,
                ], ['initialize' => false, 'returnScalar' => 'id'])->getContent();
                if ($doHits) {
                    $mediaIds = array_merge($mediaIds, $doHits);
                }
            }
        } catch (Exception $e) {
            // DO module absent or query unsupported — keep media-only hits.
        }

        $mediaIds = array_diff($mediaIds, $iiifMediaIds);
        if (!$mediaIds) {
            return null;
        }

        $imageSizesById = [];
        foreach ($this->imageSizes as $index => $imageData) {
            $imageData['index'] = $index;
            $imageSizesById[$imageData['id']] = $imageData;
        }
        $imageIndexById = array_column($imageSizesById, 'index', 'id');

        $baseResultUrl = $this->baseResultUrl;
        $baseCanvasUrl = $this->baseCanvasUrl;

        $result = [
            'resources' => [],
            'hit' => 0,
        ];
        $resource = $this->item;
        $chars = $this->query;
        foreach ($mediaIds as $id) {
            // Skip files that are not images.
            if (!isset($imageIndexById[$id])) {
                continue;
            }
            ++$hit;
            ++$result['hit'];
            $image = $imageSizesById[$id];
            $zone = [
                'text' => $chars,
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
            $result['resources'][] = compact('resource', 'image', 'page', 'zone', 'chars', 'hit');
        }

        return $result;
    }

    /**
     * Check if the item support search and init the xml files.
     *
     * There may be one tsv file for the whole item.
     * There may be one xml for all pages (pdf2xml).
     * There may be one xml by page.
     * There may be missing alto to some images.
     * There may be only xml files in the items.
     * Alto allows one xml by page or one xml for all pages too.
     * The data file may be stored in a file, not a media.
     *
     * So get the exact list matching images (if any) to avoid bad page indexes.
     *
     * The logic is managed by the module Iiif server if available: it manages
     * the same process for the text overlay.
     *
     * An option allows to force the matching of files (order or filename).
     */
    protected function prepareSearchIndexAndImages(): bool
    {
        $this->index = null;
        $this->mediaTsv = null;
        $this->mediaXml = [];
        $this->imageSizes = [];
        $this->indexFilePath = null;

        $this->prepareSearchOrder();

        // When the two tsv formats are available and the query is not an exact
        // search, use the by-word format, unless the full format is preferred
        // for the context of the hits. For exact search, use the full format
        // if available.
        if ($this->isTsvFullPreferred()) {
            $tsvByWord = $this->supportedIndexes['text/tab-separated-values;by-word'];
            unset($this->supportedIndexes['text/tab-separated-values;by-word']);
            $this->supportedIndexes['text/tab-separated-values;by-word'] = $tsvByWord;
        } else {
            $this->supportedIndexes = array_replace(['text/tab-separated-values;by-word' => null], $this->supportedIndexes);
        }

        // Check for local files first.
        foreach ($this->supportedIndexes as $supportedIndex => $data) {
            $filepath = $this->basePath . '/' . $data['dir'] . '/' . $this->item->id() . '.' . $data['extension'];
            if (file_exists($filepath)) {
                $this->index = $supportedIndex;
                $this->indexFilePath = $filepath;
                return true;
            }
        }

        // Check for media files.

        $this->mediaXmlFirst = count($this->mediaXml) ? reset($this->mediaXml) : null;

        if ($this->mediaTsv) {
            // Keep the index set by prepareSearchOrder(), which may be
            // 'text/tab-separated-values;by-word' for by-word TSV files.
            return true;
        }

        $result = $this->mediaXmlFirst
            && count($this->imageSizes);

        if (!$result) {
            return false;
        }

        if ($this->pairingMode !== 'sequential'
            && count($this->mediaXml) > 1
        ) {
            $this->prepareSearchPaired();
        }

        return true;
    }

    protected function prepareSearchOrder(): self
    {
        // Currently, only tsv and xml indexes are supported.

        $supportedXmlMediaTypes = [
            'application/vnd.pdf2xml+xml',
            'application/alto+xml',
            'text/vnd.hocr+html',
        ];

        $mediaTsvFull = null;
        $mediaTsvByWord = null;
        $itemFileResources = $this->getView()->plugin('itemFileResources');
        foreach ($itemFileResources($this->item) as $resource) {
            $mediaId = $resource->id();
            $mediaType = $resource->mediaType();
            $inner = $resource->inner();
            if ($mediaType === 'text/tab-separated-values') {
                if (substr((string) $resource->source(), -12) === '.by-word.tsv') {
                    $mediaTsvByWord = $inner;
                } else {
                    $mediaTsvFull = $inner;
                }
            } elseif (in_array($mediaType, $supportedXmlMediaTypes)) {
                $this->mediaXml[] = $inner;
            } elseif ($mediaType === 'text/xml' || $mediaType === 'application/xml') {
                $this->logger->warn(new Message(
                    'Warning: Xml format "%1$s" of media #%2$d is not precise. It may be related to a badly formatted file (%3$s). Use EasyAdmin tasks to fix media type.', // @translate
                    $mediaType, $mediaId, $resource->originalUrl()
                ));
                $this->mediaXml[] = $inner;
            } else {
                // TODO The images sizes may be stored by xml files too, so
                // skip size retrieving once the matching between images and
                // text is done by page.
                $mediaData = $resource->mediaData();
                if (isset($mediaData['width'])) {
                    $this->imageSizes[] = [
                        'id' => $mediaId,
                        'width' => $mediaData['width'],
                        'height' => $mediaData['height'],
                        'source' => $resource->source(),
                    ];
                } elseif (isset($mediaData['dimensions']['original']['width'])) {
                    $this->imageSizes[] = [
                        'id' => $mediaId,
                        'width' => $mediaData['dimensions']['original']['width'],
                        'height' => $mediaData['dimensions']['original']['height'],
                        'source' => $resource->source(),
                    ];
                } elseif ($resource->hasOriginal() && strtok($mediaType, '/') === 'image') {
                    $size = ['id' => $mediaId];
                    $size += $this->mediaDimension && $resource->isMedia()
                        ? $this->mediaDimension->__invoke($inner, 'original')
                        : $this->imageSizeLocal($resource);
                    $size['source'] = $resource->source();
                    $this->imageSizes[] = $size;
                }
            }
        }

        // Without image, the pages of the pdf may be the canvases, rendered by
        // the image server, while the ocr extracted from the pdf is in points.
        if (!$this->imageSizes && $this->getView()->getHelperPluginManager()->has('pdfPageSizes')) {
            $pdfPageSizes = $this->getView()->plugin('pdfPageSizes');
            foreach ($itemFileResources($this->item) as $resource) {
                if ($resource->mediaType() !== 'application/pdf') {
                    continue;
                }
                foreach ($pdfPageSizes($resource->inner()) as $size) {
                    $this->imageSizes[] = [
                        'id' => $resource->id(),
                        'width' => $size['width'],
                        'height' => $size['height'],
                        'page_width' => $size['points_width'],
                        'page_height' => $size['points_height'],
                        'source' => $resource->source(),
                    ];
                }
            }
        }

        // Pick the optimal TSV variant: by-word for single/multi word queries
        // (small, fast lookup), full for exact phrase search (preserves order)
        // or when preferred for the context of the hits.
        // Fall back to the other when only one variant is present.
        if ($mediaTsvFull && $mediaTsvByWord) {
            if ($this->isTsvFullPreferred()) {
                $this->mediaTsv = $mediaTsvFull;
                $this->index = 'text/tab-separated-values';
            } else {
                $this->mediaTsv = $mediaTsvByWord;
                $this->index = 'text/tab-separated-values;by-word';
            }
        } elseif ($mediaTsvByWord) {
            $this->mediaTsv = $mediaTsvByWord;
            $this->index = 'text/tab-separated-values;by-word';
        } elseif ($mediaTsvFull) {
            $this->mediaTsv = $mediaTsvFull;
            $this->index = 'text/tab-separated-values';
        }

        return $this;
    }

    /**
     * Reorder xml medias to align with images using the PagePairer cascade.
     * Falls back to position when the cascade settles on sequential.
     */
    protected function prepareSearchPaired(): self
    {
        $hintReader = new \IiifSearch\Stdlib\PageSourceHintReader();
        $sources = [];
        $i = 0;
        $altoMedias = array_values($this->mediaXml);
        foreach ($altoMedias as $media) {
            ++$i;
            $filepath = $this->basePath . '/original/' . $media->filename();
            $ps = new \IiifSearch\Stdlib\PageSource(
                $i,
                $media,
                $filepath,
                (string) $media->source(),
                'alto'
            );
            $ps->sourceImageFileName = $hintReader->readImageHint($filepath, 'alto');
            $sources[] = $ps;
        }
        $pairer = new \IiifSearch\Stdlib\PagePairer($this->basePath);
        $pairing = $pairer->pair($this->item, $sources, $this->pairingMode);
        if ($pairing->method === \IiifSearch\Stdlib\PairingResult::METHOD_SEQUENTIAL) {
            return $this;
        }

        // Build image-id -> alto media mapping from the cascade result, then
        // rebuild mediaXml in image order so existing imageSizes-indexed code
        // can keep using positional lookups.
        $altoByImageId = [];
        $page = 0;
        foreach ($altoMedias as $alto) {
            ++$page;
            $img = $pairing->imageForPage($page);
            if ($img) {
                $altoByImageId[(int) $img->id()] = $alto;
            }
        }

        $reordered = [];
        foreach ($this->imageSizes as $indexImage => $sizeData) {
            $id = (int) ($sizeData['id'] ?? 0);
            $reordered[$indexImage] = $altoByImageId[$id] ?? null;
        }
        $this->mediaXml = $reordered;
        $this->mediaXmlFirst = count($this->mediaXml) ? reset($this->mediaXml) : null;

        return $this;
    }

    /**
     * @param MediaRepresentation|\IiifSearch\Stdlib\FileResource $media
     */
    protected function imageSizeLocal($media): array
    {
        // Some media types don't save the file locally.
        $filepath = ($filename = $media->filename())
            ? $this->basePath . '/original/' . $filename
            : $media->originalUrl();
        $size = @getimagesize($filepath);
        if (!$size) {
            return ['width' => 0, 'height' => 0];
        }
        $width = $size[0];
        $height = $size[1];
        // EXIF orientations 5-8 indicate a 90° or 270°
        // rotation, so width and height must be swapped.
        $exif = @exif_read_data($filepath);
        if ($exif && !empty($exif['Orientation']) && $exif['Orientation'] >= 5) {
            [$width, $height] = [$height, $width];
        }
        return ['width' => $width, 'height' => $height];
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
    protected function prepareAndFormatQueryByWord(): array
    {
        $minimumQueryLength = $this->view->setting('iiifsearch_minimum_query_length')
            ?: $this->minimumQueryLength;

        // The terms are prepared from the query words when needed.
        $this->queryTerms = null;
        $this->wordKeys = [];

        // TODO Manage a single word + an expression.

        // TODO Should we use alnumSring() here too?
        // No cleaning for exact search, except spaces.
        if ($this->queryIsExactSearch
            // Tsv By word does not support exact search.
            && $this->index !== 'text/tab-separated-values;by-word'
        ) {
            if (mb_strlen($this->query) < $minimumQueryLength) {
                return [];
            }
            // Store each word separately to check if they are stored in the
            // right order.
            $quotedQueryWords = [];
            $queryWords = explode(' ', $this->query);
            // Limit the number of words to prevent overload.
            $queryWords = array_slice($queryWords, 0, 20);
            foreach ($queryWords as $queryWord) {
                $quotedQueryWords[] = preg_quote($queryWord, '/');
            }
            if (count($queryWords) > 1) {
                $quotedQueryWords['full'] = preg_quote($this->query, '/');
            }
            return $quotedQueryWords;
        }

        $cleanQuery = $this->alnumString($this->query);
        if (mb_strlen($cleanQuery) < $minimumQueryLength) {
            return [];
        }

        $queryWords = explode(' ', $cleanQuery);
        // Limit the number of words to prevent overload via regex on each word.
        $queryWords = array_slice($queryWords, 0, 20);
        if (count($queryWords) === 1) {
            return [
                preg_quote($queryWords[0], '/')
            ];
        }

        $quotedQueryWords = [];
        foreach ($queryWords as $queryWord) {
            if (mb_strlen($queryWord) >= $minimumQueryLength) {
                $quotedQueryWords[] = preg_quote($queryWord, '/');
            }
        }
        if (count($quotedQueryWords) > 1) {
            $quotedQueryWords['full'] = preg_quote(implode(' ', $queryWords), '/');
        }
        return array_unique($quotedQueryWords);
    }

    /**
     * Load xml of the item.
     *
     * When there are multiple xml alto, they are merged into a single
     * multi-pages xml content.
     *
     * @see \IiifServer\Iiif\TraitXml::loadXml()
     *
     * @todo The format pdf2xml in ExtractOcr can be replaced by an alto multi-pages, even if the format is quicker for search, but less precise for positions.
     */
    protected function loadXml(): ?SimpleXMLElement
    {
        if ($this->indexFilePath) {
            // hOCR is html, not xml: handled by searchFullTextHocr().
            if ($this->index === 'text/vnd.hocr+html') {
                return null;
            }
            return $this->loadXmlFromFilepath($this->indexFilePath, null);
        }

        if (!$this->mediaXmlFirst) {
            return null;
        }

        // The media type is already checked.
        // For xml, the type is the same than the media type.
        $this->index = $this->mediaXmlFirst->mediaType();

        // hOCR is html, not xml: handled by searchFullTextHocr().
        if ($this->index === 'text/vnd.hocr+html') {
            return null;
        }

        $toCache = false;
        // Merge all xml
        if ($this->index === 'application/alto+xml'
            && count($this->mediaXml) > 1
        ) {
            // Check if the file is cached via module DerivativeMedia.
            if ($this->derivativeList) {
                $derivative = $this->derivativeList->__invoke($this->item, ['type' => 'alto']);
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
            foreach ($this->mediaXml as $media) {
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
        $filepath = ($filename = $this->mediaXmlFirst->filename())
            ? $this->basePath . '/original/' . $filename
            : $this->mediaXmlFirst->originalUrl();

        $isPdf2Xml = $this->index === 'application/vnd.pdf2xml+xml';

        return $this->loadXmlFromFilepath($filepath, $isPdf2Xml);
    }

    protected function loadXmlFromFilepath(string $filepath, ?bool $isPdf2Xml = false): ?SimpleXMLElement
    {
        // Avoid the file_get_contents() warning when the source file is gone
        // (orphan media row, removed derivative, etc.). Without this guard,
        // file_get_contents returns false and mb_detect_encoding throws a
        // TypeError, which surfaces as a 500 to the iiif-search endpoint.
        $xmlContent = is_string($filepath) && $filepath !== '' && is_readable($filepath)
            ? @file_get_contents($filepath)
            : false;
        if ($xmlContent === false || $xmlContent === '') {
            return null;
        }

        $xmlContent = mb_convert_encoding(
            $xmlContent,
            'UTF-8',
            mb_detect_encoding($xmlContent, mb_detect_order(), true) ?: mb_internal_encoding()
        );

        // The media type is not set earlier, so set it now.
        $hasNoMedia = $isPdf2Xml === null;
        if ($hasNoMedia) {
            $isPdf2Xml = mb_strpos(mb_substr($xmlContent, 0, 500), 'pdf2xml');
        }

        try {
            if ($this->xmlFixMode === 'dom') {
                if ($isPdf2Xml) {
                    $xmlContent = $this->fixXmlPdf2Xml($xmlContent);
                }
                $currentXml = $this->fixXmlDom($xmlContent);
            } elseif ($this->xmlFixMode === 'regex') {
                $xmlContent = $this->fixUtf8->__invoke($xmlContent);
                if ($isPdf2Xml) {
                    $xmlContent = $this->fixXmlPdf2Xml($xmlContent);
                }
                $currentXml = @simplexml_load_string($xmlContent, null, LIBXML_NONET);
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
                $currentXml = @simplexml_load_string($xmlContent, null, LIBXML_NONET);
            }
        } catch (\Throwable $e) {
            if ($hasNoMedia || !$this->mediaXmlFirst) {
                $this->logger->err(new Message(
                    'Error: XML content is incorrect for item #%d.', // @translate
                    $this->item->id()
                ));
            } else {
                $this->logger->err(new Message(
                    'Error: XML content is incorrect for media #%d.', // @translate
                    $this->mediaXmlFirst->id()
                ));
            }
            return null;
        }

        if (!$currentXml) {
            if ($hasNoMedia || !$this->mediaXmlFirst) {
                $this->logger->err(new Message(
                    'Error: XML content seems empty for item #%d.', // @translate
                    $this->item->id()
                ));
            } else {
                $this->logger->err(new Message(
                    'Error: XML content seems empty for media #%d.', // @translate
                    $this->mediaXmlFirst->id()
                ));
            }
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
        return \IiifSearch\Stdlib\XmlRepair::fixXmlDom($xmlContent);
    }

    protected function fixXmlPdf2Xml(?string $xmlContent): string
    {
        return \IiifSearch\Stdlib\XmlRepair::fixXmlPdf2Xml($xmlContent);
    }

    /**
     * Check if the full tsv should be used when the two tsv are available.
     *
     * The full tsv is required for exact search, and it is the only one that
     * keeps the order of the words, so the context of the hits.
     */
    protected function isTsvFullPreferred(): bool
    {
        return $this->queryIsExactSearch
            || (bool) $this->view->setting('iiifsearch_tsv_prefer_full');
    }

    /**
     * Get the context of a hit from the list of the words of its page.
     *
     * The word may contain more than the match, for example when the query is
     * a part of the word, so the rest of the word is part of the context.
     *
     * @return array With keys "match", "before" and "after".
     */
    protected function contextOf(array $words, int $index, string $match): array
    {
        $start = max(0, $index - self::CONTEXT_WORDS);
        return $this->formatContext(
            array_slice($words, $start, $index - $start),
            (string) ($words[$index] ?? $match),
            $match,
            array_slice($words, $index + 1, self::CONTEXT_WORDS)
        );
    }

    /**
     * Track the words of a tsv to get the context of the hits.
     *
     * The tsv is read once and may be big, so the whole text is not stored:
     * only the last words of the current page are kept, and the next words are
     * added to the hits that are waiting for them.
     *
     * @param array $tracker Updated with keys "page", "words", "waiting" and
     *   "contexts".
     * @param array $data A row of the tsv: word, page and position.
     * @return array The words before the current word in its page.
     */
    protected function trackTsvContext(array &$tracker, array $data): array
    {
        if (($tracker['page'] ?? null) !== $data[1]) {
            $tracker['page'] = $data[1];
            $tracker['words'] = [];
            $tracker['waiting'] = [];
        }
        $before = $tracker['words'];
        foreach ($tracker['waiting'] as $key => $contextKey) {
            $tracker['contexts'][$contextKey]['after'][] = $data[0];
            if (count($tracker['contexts'][$contextKey]['after']) >= self::CONTEXT_WORDS) {
                unset($tracker['waiting'][$key]);
            }
        }
        $tracker['words'][] = $data[0];
        if (count($tracker['words']) > self::CONTEXT_WORDS) {
            array_shift($tracker['words']);
        }
        return $before;
    }

    /**
     * Add a hit to the tsv tracker, so it gets the next words of its page.
     *
     * @return int The key of the context in the tracker.
     */
    protected function addTsvContext(array &$tracker, array $before, string $word): int
    {
        $contextKey = count($tracker['contexts'] ?? []);
        $tracker['contexts'][$contextKey] = [
            'before' => $before,
            'word' => $word,
            'after' => [],
        ];
        $tracker['waiting'][] = $contextKey;
        return $contextKey;
    }

    /**
     * Format the context of a hit like the iiif search api, with the spaces
     * between the words included in "before" and "after".
     */
    protected function formatContext(array $wordsBefore, string $word, string $match, array $wordsAfter): array
    {
        $position = strlen($match) ? mb_stripos($word, $match) : false;
        if ($position === false) {
            $match = $word;
            $position = 0;
        }
        $before = trim(implode(' ', $wordsBefore));
        $before = ($before === '' ? '' : $before . ' ') . mb_substr($word, 0, $position);
        $after = trim(implode(' ', $wordsAfter));
        $after = mb_substr($word, $position + mb_strlen($match)) . ($after === '' ? '' : ' ' . $after);
        return [
            'match' => $match,
            'before' => $before === '' ? null : $before,
            'after' => $after === '' ? null : $after,
        ];
    }

    /**
     * Get the terms of the query, with the same keys than the query words.
     *
     * The terms are prepared with the query words, else they are the query
     * words themselves.
     *
     * @return array Each term has keys "text" and "key".
     */
    protected function getQueryTerms(): array
    {
        if ($this->queryTerms === null) {
            $this->queryTerms = [];
            foreach ($this->queryWords as $index => $queryWord) {
                $this->queryTerms[$index] = $this->makeTerm(stripslashes((string) $queryWord));
            }
        }
        return $this->queryTerms;
    }

    /**
     * Prepare a word of the query to match it against the words of the index.
     */
    protected function makeTerm(string $word): array
    {
        return [
            'text' => $word,
            'key' => $this->wordKey($word),
        ];
    }

    /**
     * Check if a word of the index matches one of the terms of the query.
     *
     * @param string $key The key of the word of the index.
     */
    protected function matchQueryTerms(array $terms, string $key): bool
    {
        foreach ($terms as $term) {
            if ($this->matchTerm($term, $key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a word of the index matches a term of the query.
     *
     * @param string $key The key of the word of the index.
     */
    protected function matchTerm(array $term, string $key): bool
    {
        return $key !== '' && $key === $term['key'];
    }

    /**
     * Get the word without the punctuation around it.
     *
     * The words of the tsv indexes keep the punctuation, like "house," or
     * "(house".
     */
    protected function wordCore(string $word): string
    {
        return (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $word);
    }

    /**
     * Get the key used to compare a word of the query and a word of the index.
     */
    protected function wordKey(string $word): string
    {
        return $this->wordKeys[$word] ??= $this->foldWord($this->wordCore($word));
    }

    /**
     * Lower and normalize a string to compare words.
     */
    protected function foldWord(string $string): string
    {
        $string = mb_strtolower($string, 'UTF-8');
        // The normalization is slow, so skip it for ascii.
        return preg_match('/[^\x00-\x7F]/', $string)
            ? $this->normalize($string)
            : $string;
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
     * Normalize a string as utf8.
     *
     * Should be the same normalization in IiifSearch and ExtractOcr.
     *
     * @todo Check if it is working for non-latin languages.
     *
     * @param string $input
     * @return string
     */
    protected function normalize($input): string
    {
        if (extension_loaded('intl')) {
            static $transliterator;
            $transliterator ??= \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
            $string = $transliterator->transliterate((string) $input);
        } elseif (extension_loaded('iconv')) {
            $string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $input);
        } else {
            $string = $input;
        }
        return (string) $string;
    }
}
