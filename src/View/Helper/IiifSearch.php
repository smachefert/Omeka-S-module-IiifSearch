<?php
/**
 * Created by IntelliJ IDEA.
 * User: xavier
 * Date: 17/05/18
 * Time: 16:01
 */
namespace IiifSearch\View\Helper;

use Omeka\Api\Representation\ItemRepresentation;
use SimpleXMLElement;
use Zend\View\Helper\AbstractHelper;

class IiifSearch extends AbstractHelper
{
    /**
     * Full path to the files.
     *
     * @var string
     */
    protected $basePath;

    protected $xmlMediaTypes = [
        'application/xml',
        'text/xml',
    ];

    /**
     * @var int
     */
    protected $minimumQueryLength = 3;

    /**
     * @var ItemRepresentation
     */
    protected $item;

    /**
     * @var \Omeka\Api\Representation\MediaRepresentation
     */
    protected $xmlFile;

    /**
     * @var array
     */
    protected $imageSizes;

    /**
     * @todo Remove extraction of scheme.
     *
     * @var string
     */
    protected $scheme;

    public function __construct($basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * Get the IIIF search response for fulltext research query.
     *
     * @param ItemRepresentation $item
     * @return array|null Null is returned if search is not supported for the
     * resource.
     */
    public function __invoke(ItemRepresentation $item)
    {
        $this->item = $item;

        if (!$this->prepareSearch()) {
            return null;
        }

        $this->prepareScheme();

        $response = [
            '@context' => 'http://iiif.io/api/search/0/context.json',
            '@id' => $this->scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
            '@type' => 'sc:AnnotationList',
            'within' => [
                '@type' => 'sc:Layer',
                'total' => 0,
            ],
            'startIndex' => 0,
            'resources' => [],
        ];

        $q = trim($_GET['q']);
        if (strlen($q)) {
            $resources = $this->searchFulltext($q);
            $response['within']['total'] = sizeof($resources);
            $response['resources'] = $resources;
        }

        return $response;
    }

    /**
     * Returns answers to a query.
     *
     * @todo add xml validation ( pdf filename == xml filename according to Extract Ocr plugin )
     *
     * @return array
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
    protected function searchFulltext($query)
    {
        if (!strlen($query)) {
            return [];
        }

        $queryWords = $this->formatQuery($query);
        if (empty($queryWords)) {
            return [];
        }

        $xml = $this->loadXml();
        if (empty($xml)) {
            return [];
        }

        $this->prepareImageSizes();

        $results = $this->searchFullTextPdfXml($xml, $queryWords);
        return $results;
    }

    protected function searchFullTextPdfXml(SimpleXmlElement $xml, $queryWords)
    {
        $results = [];
        try {
            $index = -1;
            foreach ($xml->page as $xmlPage) {
                ++$index;
                $attributes = $xmlPage->attributes();
                $page['number'] = (string) @$attributes->number;
                $page['width'] = (string) @$attributes->width;
                $page['height'] = (string) @$attributes->height;
                if (!strlen($page['number']) || !strlen($page['width']) || !strlen($page['height'])) {
                    $this->getView()->logger()->warn(sprintf(
                        'Incomplete data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->xmlFile->id(), $index
                    ));
                    continue;
                }

                // Should be the same than index.
                $pageIndex = $page['number'] - 1;
                if ($pageIndex !== $index) {
                    $this->getView()->logger()->warn(sprintf(
                        'Inconsistent data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->xmlFile->id(), $index
                    ));
                    continue;
                }

                $cptMatch = 0;
                $rowIndex = -1;
                foreach ($xmlPage->text as $xmlRow) {
                    ++$rowIndex;
                    $zone = [];
                    $zone['text'] = strip_tags($xmlRow->asXML());
                    foreach ($queryWords as $chars) {
                        if (!empty($this->imageSizes[$pageIndex]['width'])
                            && !empty($this->imageSizes[$pageIndex]['height'])
                            && mb_strlen($chars) >= $this->minimumQueryLength
                            && preg_match('/' . preg_quote($chars, '/') . '/Uui', $zone['text']) > 0
                        ) {
                            $attributes = $xmlRow->attributes();
                            $zone['top'] = (string) @$attributes->top;
                            $zone['left'] = (string) @$attributes->left;
                            $zone['width'] = (string) @$attributes->width;
                            $zone['height'] = (string) @$attributes->height;
                            if (!strlen($zone['top']) || !strlen($zone['left']) || !strlen($zone['width']) || !strlen($zone['height'])) {
                                $this->getView()->logger()->warn(sprintf(
                                    'Inconsistent data for xml file from pdf media #%1$s, page %2$s, row %3$s.', // @translate
                                    $this->xmlFile->id(), $pageIndex, $rowIndex
                                ));
                                continue;
                            }

                            $scaleX = $this->imageSizes[$pageIndex]['width'] / $page['width'];
                            $scaleY = $this->imageSizes[$pageIndex]['height'] / $page['height'];

                            $x = $zone['left'] + mb_stripos($zone['text'], $chars) / mb_strlen($zone['text']) * $zone['width'];
                            $y = $zone['top'];

                            $w = round($zone['width'] * ((mb_strlen($chars) + 1) / mb_strlen($zone['text']))) ;
                            $h = $zone['height'];

                            $x = round($x * $scaleX);
                            $y = round($y * $scaleY);

                            $w = round($w * $scaleX);
                            $h = round($h * $scaleY);

                            $result = [];
                            $result['@id'] = $this->scheme . '://' . $_SERVER['HTTP_HOST'] . '/omeka-s/iiif-search/searchResults/' .
                                'a' . $page['number'] .
                                'h' . $cptMatch .
                                'r' . $x . ',' . $y . ',' . $w .  ',' . $h ;
                            $result['@type'] = "oa:Annotation";
                            $result['motivation'] = "sc:painting";
                            $result['resource'] = [
                                '@type' => 'cnt:ContextAstext',
                                 'chars' => $chars,
                                ];
                            $result['on'] = $this->scheme . '://' . $_SERVER['HTTP_HOST'] . '/omeka-s/iiif/' . $this->item->id() . '/canvas/p' . $page['number'] .
                                '#xywh=' . $x . ',' . $y . ',' . $w .  ',' . $h ;

                            $results[] = $result;
                        }
                        ++$cptMatch;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->getView()->logger()->err(sprintf('Error: PDF to XML conversion failed for media file #%d!', $this->xmlFile->id()));
            return [];
        }
        return $results;
    }

    /**
     * Check if the item support search and init the xml file.
     *
     * @return bool
     */
    protected function prepareSearch()
    {
        $this->xmlFile = null;
        $this->imageSizes = [];
        foreach ($this->item->media() as $media) {
            $mediaType = $media->mediaType();
            if (!$this->xmlFile && in_array($mediaType, $this->xmlMediaTypes)) {
                $this->xmlFile = $media;
            } elseif ($media->hasOriginal() && strtok($mediaType, '/') === 'image') {
                $this->imageSizes[] = [
                    'media' => $media,
                ];
            }
        }
        return isset($this->xmlFile) && count($this->imageSizes);
    }

    protected function prepareImageSizes()
    {
        foreach ($this->imageSizes as &$image) {
            // Some media types don't save the file locally.
            if ($filename = $image['media']->filename()) {
                $filepath = $this->basePath . '/original/' . $filename;
            } else {
                $filepath = $image['media']->originalUrl();
            }
            list($image['width'], $image['height']) = getimagesize($filepath);
        }
    }

    /**
     * Normalize query because the search occurs inside a normalized text.
     * @param $query
     * @return array
     */
    protected function formatQuery($query)
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

    /**
     * @return \SimpleXMLElement|null
     */
    protected function loadXml()
    {
        $filepath = ($filename = $this->xmlFile->filename())
            ? $this->basePath . '/original/' . $filename
            : $this->xmlFile->originalUrl();

        $xmlContent = file_get_contents($filepath);

        $xmlContent = preg_replace('/\s{2,}/ui', ' ', $xmlContent);
        $xmlContent = preg_replace('/<\/?b>/ui', '', $xmlContent);
        $xmlContent = preg_replace('/<\/?i>/ui', '', $xmlContent);
        $xmlContent = str_replace('<!doctype pdf2xml system "pdf2xml.dtd">', '<!DOCTYPE pdf2xml SYSTEM "pdf2xml.dtd">', $xmlContent);

        $xmlContent = simplexml_load_string($xmlContent);
        if (!$xmlContent) {
            $this->getView()->logger()->err(sprintf('Error: Cannot get XML content from media #%d!', $this->xmlFile->id()));
            return null;
        }
        return $xmlContent;
    }

    protected function prepareScheme()
    {
        // Get the scheme. Copied from Omeka classic bootstrap.php.
        // TODO Use Zend route methods.
        if ((isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] === true))
            || (isset($_SERVER['HTTP_SCHEME']) && $_SERVER['HTTP_SCHEME'] == 'https')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
        ) {
            $this->scheme = 'https';
        } else {
            $this->scheme = 'http';
        }
        return $this->scheme;
    }

    /**
     * Returns a cleaned  string.
     *
     * Removes trailing spaces and anything else, except letters, numbers and
     * symbols.
     *
     * @param string $string The string to clean.
     * @return string The cleaned string.
     */
    protected function alnumString($string)
    {
        $string = preg_replace('/[^\p{L}\p{N}\p{S}]/u', ' ', $string);
        return trim(preg_replace('/\s+/', ' ', $string));
    }
}
