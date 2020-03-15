<?php
/**
 * Created by IntelliJ IDEA.
 * User: xavier
 * Date: 17/05/18
 * Time: 16:01
 */
namespace IiifSearch\View\Helper;

use Omeka\Api\Representation\ItemRepresentation;
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
        $results = [];
        if (!strlen($query)) {
            return $results;
        }

        $queryWords = $this->formatQuery($query);
        if (empty($queryWords)) {
            return $results;
        }

        $xml = $this->loadXml();
        if (empty($xml)) {
            return $results;
        }

        $this->prepareImageSizes();

        try {
            foreach ($xml->page as $page) {
                foreach ($page->attributes() as $a => $b) {
                    if ($a == 'height') {
                        $page_height = (string) $b;
                    }
                    if ($a == 'width') {
                        $page_width = (string) $b;
                    }
                    if ($a == 'number') {
                        $page_number = (string) $b;
                    }
                }
                $cptMatch = 1;
                foreach ($page->text as $row) {
                    $zone_text = strip_tags($row->asXML());
                    foreach ($queryWords as $q) {
                        if (mb_strlen($q) >= $this->minimumQueryLength) {
                            if ((preg_match('/' . preg_quote($q, '/') . '/Uui', $zone_text) > 0)
                                && (isset($this->imageSizes[$page_number - 1]['width']))
                                && (isset($this->imageSizes[$page_number - 1]['height']))
                            ) {
                                foreach ($row->attributes() as $key => $value) {
                                    if ($key == 'top') {
                                        $zone_top = (string) $value;
                                    }
                                    if ($key == 'left') {
                                        $zone_left = (string) $value;
                                    }
                                    if ($key == 'height') {
                                        $zone_height = (string) $value;
                                    }
                                    if ($key == 'width') {
                                        $zone_width = (string) $value;
                                    }
                                }

                                $scaleX = $this->imageSizes[$page_number - 1]['width'] / $page_width;
                                $scaleY = $this->imageSizes[$page_number - 1]['height'] / $page_height;

                                $x = $zone_left + mb_stripos($zone_text, $q) / mb_strlen($zone_text) * $zone_width;
                                $y = $zone_top ;

                                $w = round($zone_width * ((mb_strlen($q) + 1) / mb_strlen($zone_text)))  ;
                                $h = $zone_height ;

                                $x = round($x * $scaleX);
                                $y = round($y * $scaleY);

                                $w = round($w * $scaleX);
                                $h = round($h * $scaleY);

                                $result['@id'] = $this->scheme . '://' . $_SERVER['HTTP_HOST'] . '/omeka-s/iiif-search/searchResults/' .
                                    'a' . $page_number .
                                    'h' . $cptMatch .
                                    'r' . $x . ',' . $y . ',' . $w .  ',' . $h ;
                                $result['@type'] = "oa:Annotation";
                                $result['motivation'] = "sc:painting";
                                $result['resource'] = [
                                    '@type' => 'cnt:ContextAstext',
                                     'chars' => $q,
                                    ];
                                $result['on'] = $this->scheme . '://' . $_SERVER['HTTP_HOST'] . '/omeka-s/iiif/' . $this->item->id() . '/canvas/p' . $page_number .
                                    '#xywh=' . $x . ',' . $y . ',' . $w .  ',' . $h ;

                                $results[] = $result;
                            }
                            $cptMatch += 1;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            throw new \Exception('Error:PDF to XML conversion failed!');
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
