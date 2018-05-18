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

        $query = null;
        if ( ! isset($_GET['q']))
            throw new NotFoundException;

        $query = $_GET['q'];
        if ( $query == "" )
                throw new NotFoundException;

        $response = $this->api()->read('items', $id);
        $item = $response->getContent();
        if (empty($item)) {
            throw new NotFoundException;
        }

        //generate xml file for ocr data
        //if doesn't exist
        //
        $medias = $item->media();
        $ocrFile = [];
        $pdfFile = null;
        foreach ($medias as $media) {
            $mediaType = $media->mediaType();

            if ($mediaType == 'application/xml')
                $ocrFile[] = $media;

            if ( in_array($mediaType, $this->pdfMimeTypes) )
                $pdfFile = $media;
        }

        $this->extractOcr($pdfFile, $item);
        //if ( empty($ocrFile))


        $iiifSearch = $this->viewHelpers()->get('iiifSearch');
        $searchResponse = $iiifSearch($item, $query);

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

    protected $pdfMimeTypes = array(
        'application/pdf',
        'application/x-pdf',
        'application/acrobat',
        'text/x-pdf',
        'text/pdf',
        'applications/vnd.pdf',
    );

    /**
     * Extract the ocr from a PDF file to
     * a xml file and attach it to the item
     *
     * @param string $path
     * @return string
     */
    protected function extractOcr($pdfMedia, $item)
    {
        $original_filename = $pdfMedia->filename();
        $xml_filename = preg_replace("/\.pdf$/i", ".xml", $original_filename);
        $tmp_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename($xml_filename, ".xml");
        $tmp_file_escaped = escapeshellarg($tmp_file);

        //TODO get path original file from media
        $path = $this->basePath . DIRECTORY_SEPARATOR . '/original/' . $original_filename;

        $path = escapeshellarg($path);
        $cmd = "pdftohtml -i -c -hidden -xml $path $tmp_file_escaped";
        $res = shell_exec($cmd);

        //see Documentation Attach a file :
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

        $this->api()->create('media', $data, $filedata);
    }
}