<?php


namespace IiifSearch\Controller;


use Omeka\File\Store\StoreInterface;
use Omeka\Mvc\Exception\NotFoundException;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class PdfController extends AbstractActionController
{
    /**
     * @var StoreInterface
     */
    protected $store;

    /**
     * Full path to the files.
     *
     * @var string
     */
    protected $basePath;

    /**
     * Uri to access the file.
     *
     * @var string
     */
    protected $baseUri;


    protected $pdfMimeTypes = array(
        'application/pdf',
        'application/x-pdf',
        'application/acrobat',
        'text/x-pdf',
        'text/pdf',
        'applications/vnd.pdf',
    );


    public function __construct($store, $basePath, $baseUri)
    {
        $this->store = $store;
        $this->basePath = $basePath;
        $this->baseUri = $baseUri;
    }

    /**
     * Redirect to the 'info' action, required by the feature "baseUriRedirect".
     *
     * @see self::infoAction()
     */
    public function indexAction()
    {
        $id = $this->params('id');
        $this->redirect()->toRoute('iiifsearch_pdf_researchInfo', ['id' => $id]);
    }

    /**
     * Send "info.json" for the current file.
     *
     * @internal The info is managed by the MediaControler because it indicates
     * capabilities of the IXIF server for the request of a file.
     */
    public function ocrResearchAction()
    {
        $id = $this->params('id');
        if (empty($id)) {
            throw new NotFoundException;
        }

        if ( ! isset($_GET['q']))
            throw new NotFoundException;

        $response = $this->api()->read('items', $id);
        $item = $response->getContent();
        if (empty($item))
            throw new NotFoundException;

        $iiifSearch = $this->viewHelpers()->get('iiifSearch');
        $searchResponse = $iiifSearch($item);

        $searchResponse = (object)$searchResponse;
        return $this->jsonLd($searchResponse );
    }

    /**
     * Returns an error 400 to requests that are invalid.
     */
    public function badAction()
    {
        $response = $this->getResponse();

        $response->setStatusCode(400);

        $view = new ViewModel;
        $view->setVariable('message', $this->translate('The IIIF server cannot fulfill the request: the arguments are incorrect.'));
        $view->setTemplate('public/image/error');

        return $view;
    }

    /**
     * @param $item
     * @return xml and pdf media
     *      [
     *          "xmlMedia" => MediaRepresentation,
     *          "pdfMedia" => MediaRepresentation,
     *      ]
     *      if don't exist return empty array
     */
    protected function checkItem($item){

        $result = [];

        $medias = $item->media();
        foreach ($medias as $media) {
            $mediaType = $media->mediaType();

            if ( ($mediaType == 'application/xml') ||  ($mediaType == 'text/xml')  ) {
                $result['xmlMedia'] = $media;
            }

            if ( in_array($mediaType, $this->pdfMimeTypes) )
                $result['pdfMedia'] = $media;
        }

        return $result;
    }


    protected function addMediaOcrFromPdf($item, $pdfMedia) {
        $xml_filename = $this->extractOcr($pdfMedia);
        $data = [
            "o:ingester" => "url", //choose default ingester
            "o:item" => [
                "o:id" => $item->id() //Id of the item to which attach the medium
            ],
            "ingest_url" => sprintf('%s/%s', $this->baseUri, $xml_filename),
            "o:source" => basename($pdfMedia->source(), ".pdf").".xml"
        ];

        $this->api()->create('media', $data);

        //TODO return media representation
        return $data = [
            "o:ingester" => "url", //choose default ingester
            "o:item" => [
                "o:id" => $item->id() //Id of the item to which attach the medium
            ],
            "ingest_url" => sprintf('%s/%s', $this->baseUri, $xml_filename),
            "o:source" => basename($pdfMedia->source(), ".pdf").".xml",
            "path" => sprintf('%s/%s', $this->basePath, $xml_filename)
        ];
    }

    /**
     * Extract the ocr from a PDF file to
     * a xml file and attach it to the item
     *
     * @param MediaRepresentation $pdf
     * @return generated xml file's path
     */
    protected function extractOcr($pdf)
    {
        $original_filename = $pdf->filename();

        $xml_filename = preg_replace("/\.pdf$/i", ".xml", $original_filename);
        $tmp_file = sprintf('%s/%s', $this->basePath, $xml_filename);
        $tmp_file_escaped = escapeshellarg($tmp_file);

        //TODO get path original file from media
        $path = $this->basePath . DIRECTORY_SEPARATOR . '/original/' . $original_filename;

        $path = escapeshellarg($path);
        $cmd = "pdftohtml -i -c -hidden -xml $path $tmp_file_escaped";
        $res = shell_exec($cmd);
        return $xml_filename;

    }
}
