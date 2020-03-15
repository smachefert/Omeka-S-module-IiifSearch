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

    public function __construct($basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * Get the IIIF search response for fulltext research query.
     *
     * @param ItemRepresentation $item
     * @return array
     */
    public function __invoke(ItemRepresentation $item)
    {
        $response = [
            '@context' => 'http://iiif.io/api/search/0/context.json',
            '@id' => "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]",
            '@type' => 'sc:AnnotationList',
            'within' => [
                '@type' => 'sc:Layer',
                'total' => 0,
            ],
            'startIndex' => 0,
            'resources' => [],
        ];

        if (!$_GET['q'] == "") {
            $resources = $this->searchFulltext($item, $_GET['q']);

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
    public function searchFulltext($item, $query)
    {
        $results = [];
        $xml_file = [];
        $images = [];

        $widths = [];
        $heights = [];

        $queryWords = $this->formatQuery($query);
        if (empty($queryWords)) {
            return $results;
        }

        foreach ($item->media() as $media) {
            $mediaType = $media->mediaType();
            if (($mediaType == 'application/xml') || ($mediaType == 'text/xml')) {
                $xml_file = $media;
            } elseif ($media->hasThumbnails()) {
                $images[] = $media;
            }
        }

        if (empty($xml_file)) {
            return $results;
        }

        $xml = $this->loadXml(file_get_contents($xml_file->originalUrl()));

        foreach ($images as $media) {
            $image = $media->originalUrl();

            list($width, $height) = getimagesize($image);
            $widths[] = $width;
            $heights[] = $height;
        }

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
                    $boxes = [];
                    $zone_text = strip_tags($row->asXML());
                    foreach ($queryWords as $q) {
                        if (mb_strlen($q) >= 3) {
                            if ((preg_match('/' . preg_quote($q, '/') . '/Uui', $zone_text) > 0)
                                && (isset($widths[$page_number - 1]))
                                && (isset($heights[$page_number - 1]))
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

                                $scaleX = $widths[$page_number - 1] / $page_width;
                                $scaleY = $heights[$page_number - 1] / $page_height;

                                $x = $zone_left + mb_stripos($zone_text, $q) / mb_strlen($zone_text) * $zone_width;
                                $y = $zone_top ;

                                $w = round($zone_width * ((mb_strlen($q) + 1) / mb_strlen($zone_text)))  ;
                                $h = $zone_height ;

                                $x = round($x * $scaleX);
                                $y = round($y * $scaleY);

                                $w = round($w * $scaleX);
                                $h = round($h * $scaleY);

                                $result['@id'] = "http://$_SERVER[HTTP_HOST]" . '/omeka-s/iiif-search/searchResults/' .
                                    'a' . $page_number .
                                    'h' . $cptMatch .
                                    'r' . $x . ',' . $y . ',' . $w .  ',' . $h ;
                                $result['@type'] = "oa:Annotation";
                                $result['motivation'] = "sc:painting";
                                $result['resource'] = [
                                    '@type' => 'cnt:ContextAstext',
                                     'chars' => $q,
                                    ];
                                $result['on'] = "http://$_SERVER[HTTP_HOST]" . '/omeka-s/iiif/' . $item->id() . '/canvas/p' . $page_number .
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
     * Normalize query because the search occurs inside a normalized text.
     * @param $query
     * @return mixed
     */
    protected function formatQuery($query)
    {
        $minimumQueryLength = 3;

        $cleanQuery = $this->alnumString($query);

        if (mb_strlen($cleanQuery) < $minimumQueryLength) {
            return [];
        }

        $queryWords = explode(' ', $cleanQuery);
        if (count($queryWords) > 1) {
            $queryWords[] = $cleanQuery;
        }

        return $queryWords;
    }

    /**
     * @param $xmlContent from attached xml file ( PDFTexT module )
     * @return \SimpleXMLElement
     */
    protected function loadXml($xmlContent)
    {
        if (empty($xmlContent)) {
            throw new Exception('Error:Cannot get XML file!');
        }
        $xmlContent = preg_replace('/\s{2,}/ui', ' ', $xmlContent);
        $xmlContent = preg_replace('/<\/?b>/ui', '', $xmlContent);
        $xmlContent = preg_replace('/<\/?i>/ui', '', $xmlContent);
        $xmlContent = str_replace('<!doctype pdf2xml system "pdf2xml.dtd">', '<!DOCTYPE pdf2xml SYSTEM "pdf2xml.dtd">', $xmlContent);

        $xml = simplexml_load_string($xmlContent);
        if (!$xml) {
            throw new Exception('Error:Invalid XML!');
        }
        return $xml;
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
