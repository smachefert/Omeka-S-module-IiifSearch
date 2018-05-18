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
    public function __invoke(ItemRepresentation $item, $query)
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


        $medias = $item->media();
        $ocrFile = [];
        $pdfFile = null;
        foreach ($medias as $media) {
            $mediaType = $media->mediaType();
            if ($mediaType == 'application/xml') {
                $ocrFile[] = $media;
            }
            if ( in_array($mediaType, $this->pdfMimeTypes) ) {
                $pdfFile = $media;
            }
        }
        if ( empty($ocrFile)) {
            // TODO Create ocr file
            //$toc = $this->pdfToc($pdfFile->source());
            //$xmlFile = $this->extractOcr($pdfFile, $item);



        }


        $response = (object) $response;
        return $response;
    }

    /**
     * Extract the table of contents from a PDF file.
     *
     * @param string $path
     * @return string
     */
    public function pdfToc($path)
    {
        $path = escapeshellarg($path);
        $dump_data =  shell_exec("pdftk $path dump_data");
        if (is_string($dump_data)) {

            //prepare ToC
            $dump_data = preg_replace("/^.*(Bookmark.*)$/isU", "$1", $dump_data);
            $dump_data_array = preg_split("/\n/", $dump_data);
            $dump_data_array = preg_split("/\n/", $dump_data);

            $toc = "";
            for ($i = 0; $i <= sizeof($dump_data_array); $i+=3)
            {
                $bm_title = str_replace("BookmarkTitle: ", "", $dump_data_array[$i]);
                $bm_level = str_replace("BookmarkLevel: ", "", $dump_data_array[$i+1]);
                $bm_page = str_replace("BookmarkPageNumber: ", "", $dump_data_array[$i+2]);
                if ($toc != "")
                {
                    $toc .= "\n";
                }
                if ( ($bm_level != "") and ($bm_title != "") and ($bm_page != "") )
                {
                    $toc .= $bm_level."|".$bm_title."|".$bm_page;
                }
            }
            return $toc;
        }
    }



/*    public function getPageNumbers()
    {
        if (empty($this->_item)) {
            return;
        }
        $leaves = $this->getLeaves($this->_item);
        $numbers = array();
        foreach ($leaves as $leaf) {
            if (empty($leaf)) {
                $number = '';
            }
            else {
                $file = &$leaf;
                $txt = $file->original_filename;
                $re1 = '.*?'; # Non-greedy match on filler
                $re2 = '(page)';  # Word 1
                $re3 = '(\\d+)';  # Integer Number 1
                $c = preg_match_all('/' . $re1 . $re2 . $re3 . '/is', $txt, $matches);
                if ($c) {
                    $word1 = $matches[1][0];
                    $int1 = $matches[2][0];
                    $int1 = preg_replace( "/^[0]{0,6}/", '', $int1 );
                    $number = $int1;
                }
                else {
                    $number = null;
                }
            }
            $numbers[] = $number;
        }
        return $numbers;
    }*/

    /**
     * Returns answers to a query.
     *
     * @return array
     *   Result can be returned by leaf index or by file id. The custom
     *   highlightFiles() function should use the same.
     *   Associative array of leaf indexes or file ids as keys and an array
     *   values for each result in the page (words and start position):
     * array(
     *   leaf index = array(
     *     array(
     *       'answer' => answer, findable in the original text,
     *       'position' => position of the answer in the original text,
     *     ),
     *   ),
     * );
     */
    public function searchFulltext($ocrFile, $query)
    {
        if (empty($this->_item)) {
            return;
        }
        $minimumQueryLength = 3;
        $maxResult = 10;
        // Simplify checks, because arrays are 0-based.
        $maxResult--;
        $results = array();
        // Normalize query because the search occurs inside a normalized text.
        $cleanQuery = $this->_alnumString($query);
        if (strlen($cleanQuery) < $minimumQueryLength) {
            return $results;
        }
        $queryWords = explode(' ', $cleanQuery);
        $countQueryWords = count($queryWords);
        if ($countQueryWords > 1) $queryWords[] = $cleanQuery;
        $iResult = 0;
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
        if ($xml_file) {
            $results = array();
            // To use the local path is discouraged, because it bypasses the
            // storage, so it's not compatible with Amazon S3, etc.
            $string = file_get_contents($xml_file->getWebPath('original'));
            if (empty($string)) {
                throw new Exception('Error:Cannot get XML file!');
            }
            $string = preg_replace('/\s{2,}/ui', ' ', $string);
            $string = preg_replace('/<\/?b>/ui', '', $string);
            $string = preg_replace('/<\/?i>/ui', '', $string);
            $string = str_replace('<!doctype pdf2xml system "pdf2xml.dtd">', '<!DOCTYPE pdf2xml SYSTEM "pdf2xml.dtd">', $string);
            $xml =  simplexml_load_string($string);
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
                foreach( $xml->page as $page) {
                    foreach($page->attributes() as $a => $b) {
                        if ($a == 'height') $page_height = (string)$b ;
                        if ($a == 'width')  $page_width = (string)$b ;
                        if ($a == 'number') $page_number = (string)$b ;
                    }
                    $t = 1;
                    foreach( $page->text as $row) {
                        $boxes = array();
                        $zone_text = strip_tags($row->asXML());
                        foreach($queryWords as $q) {
                            if($strlen_function($q) >= 3) {
                                if(preg_match("/$q/Uui", $zone_text) > 0) {
                                    foreach($row->attributes() as $a => $b) {
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
                                    $word_left = $zone_left + ( ($word_start_char * $zone_width) / $zone_width_char);
                                    $word_right = $word_left + ( ( ( $word_width_char + 2) * $zone_width) / $zone_width_char );
                                    $word_left = round($word_left * $widths[$page_number - 1] / $page_width);
                                    $word_right = round( $word_right * $widths[$page_number - 1] / $page_width);
                                    $word_top = round($zone_top * $heights[$page_number - 1] / $page_height);
                                    $word_bottom = round($word_top + ( $zone_height * $heights[$page_number - 1] / $page_height ));
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
        }
        return $results;
    }
}