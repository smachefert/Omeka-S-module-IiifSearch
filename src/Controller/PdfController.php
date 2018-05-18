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


    protected $pdfMimeTypes = array(
        'application/pdf',
        'application/x-pdf',
        'application/acrobat',
        'text/x-pdf',
        'text/pdf',
        'applications/vnd.pdf',
    );


    public function __construct($store, $basePath)
    {
        $this->store = $store;
        $this->basePath = $basePath;
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


        $result = $this->checkItem($item);
        if (empty($result['pdfMedia']) )
            throw new InvalidArgumentException("L'item doit contenir un pdf");

        if (empty($result['xmlMedia'])) {
            $result['xmlMedia'] = $this->addMediaOcrFromPdf($item, $result['pdfMedia']);
        }

        $iiifSearch = $this->viewHelpers()->get('iiifSearch');
        $searchResponse = $iiifSearch($result['xmlMedia']);

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

            if ($mediaType == 'application/xml')
                $result['xmlMedia'] = $media;

            if ( in_array($mediaType, $this->pdfMimeTypes) )
                $result['pdfMedia'] = $media;
        }

        return $result;
    }


    protected function addMediaOcrFromPdf($item, $pdfMedia) {
        $xmlFilePath = $this->extractOcr($pdfMedia);

        //TODO attach file to item
      /*  //see Documentation Attach a file :
        //https://omeka.org/s/docs/developer/key_concepts/api/
        $fileIndex = 0;
        $data = [
            "o:ingester" => "fdg", //choose default ingester
            "file_index" => $fileIndex, //generate random index
            "o:item" => [
                "o:id" => $item->id() //Id of the item to which attach the medium
            ],
        ];

        $filedata = [
            'file'=>[
                $fileIndex => $tmp_file_escaped,
            ],
        ];

        $this->api()->create('media', $data, $filedata);*/

        //TODO return media representation
        return $xmlFilePath;
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
        $tmp_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename($xml_filename, ".xml");
        $tmp_file_escaped = escapeshellarg($tmp_file);

        //TODO get path original file from media
        $path = $this->basePath . DIRECTORY_SEPARATOR . '/original/' . $original_filename;

        $path = escapeshellarg($path);
        $cmd = "pdftohtml -i -c -hidden -xml $path $tmp_file_escaped";
        $res = shell_exec($cmd);

        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $xml_filename;
    }
}