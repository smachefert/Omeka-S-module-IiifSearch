<?php declare(strict_types=1);

namespace IiifSearch\Media\Ingester;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFile;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;

/**
 * Internal ingester to attach the file extracted by the job Extract OCR.
 *
 * The temp file is passed as an object via the internal api, so it cannot be
 * used to read a file of the server, and no http request is done, so the file
 * is attached even when the web server denies the access to files/temp/.
 */
class OcrFile implements IngesterInterface
{
    public function getLabel()
    {
        return 'Extracted ocr'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    public function ingest(Media $media, Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        $tempFile = $data['ingest_tempfile'] ?? null;
        if (!$tempFile instanceof TempFile) {
            $errorStore->addError('ingest_tempfile', 'The extracted ocr file is not a temp file.'); // @translate
            return;
        }

        // The extension of the stored file and its validation are based on the
        // source name.
        if (!$tempFile->getSourceName() && !empty($data['o:source'])) {
            $tempFile->setSourceName((string) $data['o:source']);
        }

        // Keep a standard ingester: this one is only an internal step.
        $media->setIngester('upload');
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($tempFile->getSourceName());
        }

        // The temp file is removed by the job.
        $tempFile->mediaIngestFile($media, $request, $errorStore, true, true, false);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        return $view->translate('Used only for the internal process of ocr extraction.'); // @translate
    }
}
