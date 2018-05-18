<?php
/**
 * Created by IntelliJ IDEA.
 * User: xavier
 * Date: 17/05/18
 * Time: 16:01
 */

namespace IiifSearch\View\Helper;

use IiifSearch\Mvc\Controller\Plugin\TileInfo;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\TempFileFactory;
use Zend\View\Helper\AbstractHelper;

class IiifSearch extends AbstractHelper
{
    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * Full path to the files.
     *
     * @var string
     */
    protected $basePath;


    protected $pdfMimeTypes = array(
        'application/pdf',
        'application/x-pdf',
        'application/acrobat',
        'text/x-pdf',
        'text/pdf',
        'applications/vnd.pdf',
    );

    public function __construct(TempFileFactory $tempFileFactory, $basePath)
    {
        $this->tempFileFactory = $tempFileFactory;
        $this->basePath = $basePath;
    }

    /**
     * Get the IIIF info for the specified record.
     *
     * @todo Replace all data by standard classes.
     *
     * @param MediaRepresentation|null $media
     * @return Object|null
     */
    public function __invoke(/*MediaRepresentation*/
        $ocr)
    {
        $response = [
            '@context' => 'http://iiif.io/api/search/0/context.json',
            '@id' => "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]",
            '@type' => 'sc:Manifest',
            'within' => [
                '@type' => 'sc:layer',
                'total' => 0,
            ],
            'startIndex' => 0,
            'resources' => [],
        ];

        if (!$_GET['q'] == "") {
            $resources = $this->searchFulltext($ocr, $_GET['q']);

            $response['within']['total'] = sizeof($resources);
            $response['resources'] = $resources;
        }

        $response = (object)$response;
        return $response;
    }


    /**
     * Returns answers to a query.
     *
     * @return array
     *  Return resources that match query for IIIF Search API
     * [
     *      [
     *          '@id' => '',
     *          '@type' => '',
     *          'motivation' => '',
     *          [
     *              '@type' => '',
     *              'chars' => '',
     *          ]
     *          'on' => canvas url with coordonate for IIIF Server module,
     *      ]
     *      ...
     */
    public function searchFulltext($xmlFilePath, $query)
    {
        $results = [];

        $queryWords = $this->formatQuery($query);
        if (empty($queryWords))
            return $results;

        $xml = $this->loadXml($xmlFilePath);
        if (empty($xmlFilePath))
            throw new Exception('Error:Cannot get XML file!');


        //TODO replace all stuff to generate request
        //TODO rajouter l'item en parametre on va en avoir besoin pour la requete
        $list = array();
        set_loop_records('files', $this->_item->getFiles());
        foreach (loop('files') as $file) {
            if ($file->mime_type == 'application/xml') {
                $xml_file = $file;
            }
            // Only these image extensions can be read by current browsers.
            elseif ($file->hasThumbnail() && preg_match('/\.(jpg|jpeg|png|gif)$/i', $file->filename)) {
                $list[] = $file;
            }
        }
        $widths = array();
        $heights = array();
        foreach ($list as $file) {
            $imageSize = $this->getImageSize($file, $imageType);
            $widths[] = $imageSize['width'];
            $heights[] = $imageSize['height'];
        }
        $xmlFilePath = preg_replace('/\s{2,}/ui', ' ', $xmlFilePath);
        $xmlFilePath = preg_replace('/<\/?b>/ui', '', $xmlFilePath);
        $xmlFilePath = preg_replace('/<\/?i>/ui', '', $xmlFilePath);
        $xmlFilePath = str_replace('<!doctype pdf2xml system "pdf2xml.dtd">', '<!DOCTYPE pdf2xml SYSTEM "pdf2xml.dtd">', $xmlFilePath);
        $xml = simplexml_load_string($xmlFilePath);
        if (!$xml) {
            throw new Exception('Error:Invalid XML!');
        }
        $result = array();
        try {
            // We need to store the name of the function to be used
            // for string length. mb_strlen() is better (especially
            // for diacrictics) but not available on all systems so
            // sometimes we need to use the default strlen()
            $strlen_function = "strlen";
            if (function_exists('mb_strlen')) {
                $strlen_function = "mb_strlen";
            }
            foreach ($xml->page as $page) {
                foreach ($page->attributes() as $a => $b) {
                    if ($a == 'height') $page_height = (string)$b;
                    if ($a == 'width') $page_width = (string)$b;
                    if ($a == 'number') $page_number = (string)$b;
                }
                $t = 1;
                foreach ($page->text as $row) {
                    $boxes = array();
                    $zone_text = strip_tags($row->asXML());
                    foreach ($queryWords as $q) {
                        if ($strlen_function($q) >= 3) {
                            if (preg_match("/$q/Uui", $zone_text) > 0) {
                                foreach ($row->attributes() as $a => $b) {
                                    if ($a == 'top') $zone_top = (string)$b;
                                    if ($a == 'left') $zone_left = (string)$b;
                                    if ($a == 'height') $zone_height = (string)$b;
                                    if ($a == 'width') $zone_width = (string)$b;
                                }
                                $zone_right = ($page_width - $zone_left - $zone_width);
                                $zone_bottom = ($page_height - $zone_top - $zone_height);
                                $zone_width_char = strlen($zone_text);
                                $word_start_char = stripos($zone_text, $q);
                                $word_width_char = strlen($q);
                                $word_left = $zone_left + (($word_start_char * $zone_width) / $zone_width_char);
                                $word_right = $word_left + ((($word_width_char + 2) * $zone_width) / $zone_width_char);
                                $word_left = round($word_left * $widths[$page_number - 1] / $page_width);
                                $word_right = round($word_right * $widths[$page_number - 1] / $page_width);
                                $word_top = round($zone_top * $heights[$page_number - 1] / $page_height);
                                $word_bottom = round($word_top + ($zone_height * $heights[$page_number - 1] / $page_height));
                                $boxes[] = array(
                                    'r' => $word_right,
                                    'l' => $word_left,
                                    'b' => $word_bottom,
                                    't' => $word_top,
                                    'page' => $page_number,
                                );
                                $zone_text = str_ireplace($q, '{{{' . $q . '}}}', $zone_text);
                                $result['text'] = $zone_text;
                                $result['par'] = array();
                                $result['par'][] = array(
                                    't' => $zone_top,
                                    'r' => $zone_right,
                                    'b' => $zone_bottom,
                                    'l' => $zone_left,
                                    'page' => $page_number,
                                    'boxes' => $boxes,
                                );
                                $results[] = $result;
                            }
                            $t += 1;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            throw new Exception('Error:PDF to XML conversion failed!');
        }
        return $results;
    }

    /**
     * Normalize query because the search occurs inside a normalized text.
     * @param $query
     * @return mixed
     */
    protected function formatQuery($query) {
        $minimumQueryLength = 3;

        $cleanQuery = $this->alnumString($query);

        if (strlen($cleanQuery) < $minimumQueryLength) {
            return [];
        }

        $queryWords = explode(' ', $cleanQuery);
        if ( count($queryWords) > 1)
            $queryWords[] = $cleanQuery;

        return $queryWords;
    }

    /**
     * @param $xmlPath
     * @return \SimpleXMLElement
     */
    protected function loadXml( $xmlPath ) {
        if (empty($xmlPath)) {
            throw new Exception('Error:Cannot get XML file!');
        }
        $xmlPath = preg_replace('/\s{2,}/ui', ' ', $xmlPath);
        $xmlPath = preg_replace('/<\/?b>/ui', '', $xmlPath);
        $xmlPath = preg_replace('/<\/?i>/ui', '', $xmlPath);
        $xmlPath = str_replace('<!doctype pdf2xml system "pdf2xml.dtd">', '<!DOCTYPE pdf2xml SYSTEM "pdf2xml.dtd">', $xmlPath);
        return simplexml_load_string($xmlPath);
    }
    /**
     * Returns a cleaned  string.
     *
     * Removes trailing spaces and anything else, except letters, numbers and
     * symbols.
     *
     * @param string $string The string to clean.
     *
     * @return string
     *   The cleaned string.
     */
    protected function alnumString($string)
    {
        $string = preg_replace('/[^\p{L}\p{N}\p{S}]/u', ' ', $string);
        return trim(preg_replace('/\s+/', ' ', $string));
    }


}