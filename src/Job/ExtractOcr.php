<?php declare(strict_types=1);

namespace IiifSearch\Job;

use DateTime;
use DOMDocument;
use Exception;
use IiifSearch\Stdlib\PagePairer;
use IiifSearch\Stdlib\PageSource;
use IiifSearch\Stdlib\PageSourceHintReader;
use IiifSearch\Stdlib\PairingResult;
use IiifSearch\Stdlib\XmlMediaClassifier;
use IiifSearch\Stdlib\XmlRepair;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\TempFile;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;
use SimpleXMLElement;
use XSLTProcessor;

class ExtractOcr extends AbstractJob
{
    const FORMAT_ALTO = 'application/alto+xml';
    const FORMAT_PDF2XML = 'application/vnd.pdf2xml+xml';
    const FORMAT_TSV = 'text/tab-separated-values';
    const FORMAT_TSV_BY_WORD = 'text/tab-separated-values;by-word';

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Omeka\Stdlib\Cli
     */
    protected $cli;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \IiifSearch\View\Helper\FixUtf8|null
     */
    protected $fixUtf8;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\File\TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $baseUri;

    /**
     * @var bool
     */
    protected $createEmptyFile;

    /**
     * @var bool
     */
    protected $reocrEnabled = false;

    /**
     * @var bool
     */
    protected $ocrImagesEnabled = false;

    /**
     * @var string
     */
    protected $ocrLanguage = '';

    /**
     * @var bool
     */
    protected $contentOnly = false;

    /**
     * @var bool
     */
    protected $createMedia;

    /**
     * @var string
     */
    protected $language;

    /**
     * @var int|null
     */
    protected $propertyId;

    /**
     * @var string|null
     */
    protected $propertyTerm;

    /**
     * @var string
     */
    protected $targetDirPath;

    /**
     * @var string
     */
    protected $targetExtension;

    /**
     * @var string
     */
    protected $targetFormat;

    /**
     * @var string
     */
    protected $targetMediaType;

    /**
     * @var array
     */
    protected $dataPdf;

    /**
     * @var array
     */
    protected $store = [
        'item' => false,
        'media_pdf' => false,
        'media_extracted' => false,
    ];

    /**
     * @var array
     */
    protected $stats = [];

    /**
     * @var \Transliterator|false|null
     */
    protected $transliterator;

    /**
     * @var XmlMediaClassifier|null
     */
    protected $xmlMediaClassifier;

    /**
     * @var string
     */
    protected $pairingMode = 'auto';

    /**
     * @brief Attach attracted ocr data from pdf with item
     */
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $helpers = $services->get('ViewHelperManager');
        $this->api = $services->get('Omeka\ApiManager');
        $this->fixUtf8 = $helpers->has('FixUtf8') ? $helpers->get('FixUtf8') : null;
        $this->logger = $services->get('Omeka\Logger');
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('extract-ocr/extract-ocr/job_' . $this->job->getId());
        $this->logger->addProcessor($referenceIdProcessor);
        $this->tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $this->cli = $services->get('Omeka\Cli');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->baseUri = $this->getArg('base_uri');
        $this->basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        if ((int) shell_exec('hash pdftotext 2>&- || echo 1')) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The command-line utility pdftotext is not available. Install the package poppler-utils.' //@translate
            );
            return;
        }

        if ((int) shell_exec('hash pdftohtml 2>&- || echo 1')) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The command-line utility pdftohtml is not available. Install the package poppler-utils.' //@translate
            );
            return;
        }

        if (!$this->checkDestinationDir($this->basePath . '/temp')) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The temporary directory "files/temp" is not writeable. Fix rights or create it manually.' // @translate
            );
            return;
        }

        if (!$this->baseUri) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The base uri is unknown.' // @translate
            );
            return;
        }

        // Clean and reorder media types to extract tsv, then pdf2xml then alto
        // to simplify storage of text as value.
        // Indeed, the text is not extracted directly for format alto.
        $formats = [
            self::FORMAT_TSV => 'tsv',
            self::FORMAT_TSV_BY_WORD => 'tsv by word',
            self::FORMAT_PDF2XML => 'pdf2xml',
            self::FORMAT_ALTO => 'alto',
        ];
        $dirPaths = [
            self::FORMAT_ALTO => 'alto',
            self::FORMAT_PDF2XML => 'pdf2xml',
            self::FORMAT_TSV => 'iiif-search',
            self::FORMAT_TSV_BY_WORD => 'iiif-search',
        ];
        $extensions = [
            self::FORMAT_ALTO => 'alto.xml',
            self::FORMAT_PDF2XML => 'pdf2xml.xml',
            self::FORMAT_TSV => 'full.tsv',
            self::FORMAT_TSV_BY_WORD => 'by-word.tsv',
        ];
        $mediaTypes = [
            self::FORMAT_ALTO => 'application/alto+xml',
            self::FORMAT_PDF2XML => 'application/vnd.pdf2xml+xml',
            self::FORMAT_TSV => 'text/tab-separated-values',
            self::FORMAT_TSV_BY_WORD => 'text/tab-separated-values',
        ];

        $settings = $services->get('Omeka\Settings');

        // Prepare and reorder types.
        $targetTypesFiles = $settings->get('iiifsearch_extract_types_files') ?: [];
        $targetTypesFiles = array_values(array_intersect(array_keys($formats), $targetTypesFiles));
        $targetTypesMedia = $settings->get('iiifsearch_extract_types_media') ?: [];
        $targetTypesMedia = array_values(array_intersect(array_keys($formats), $targetTypesMedia));
        $targetContentStore = $settings->get('iiifsearch_extract_content_store') ?: [];
        $targetContentStore = array_intersect($targetContentStore, ['item', 'media_pdf', 'media_extracted']);
        if (!count($targetTypesFiles) && !count($targetTypesMedia) && !count($targetContentStore)) {
            $this->logger->warn(
                'No extract format to process.' // @translate
            );
            return;
        }

        if ((in_array(self::FORMAT_ALTO, $targetTypesFiles) || in_array(self::FORMAT_ALTO, $targetTypesMedia))
            && !class_exists('XSLTProcessor', false)
        ) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'The php extension "xml" or "xsl" is required to extract text as xml alto.' // @translate
            );
            return;
        }

        foreach ($targetTypesFiles as $format) {
            if (!$this->checkDestinationDir($this->basePath . '/' . $dirPaths[$format])) {
               $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            }
        }
        if ($this->job->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            $this->logger->err('The directory is not writeable.'); // @translate
            return;
        }

        $mode = $this->getArg('mode') ?: 'all';
        $itemId = (int) $this->getArg('item_id');
        $itemIds = (string) $this->getArg('item_ids');
        if ($itemId) {
            $itemIds = trim($itemId . ' ' . $itemIds);
        }

        // TODO Manage the case where there are multiple pdf by item (rare).

        $contentStore = &$targetContentStore;
        if ($contentStore) {
            $prop = $settings->get('iiifsearch_extract_content_property');
            if ($prop) {
                /** @var \Common\Stdlib\EasyMeta $easyMeta */
                $easyMeta = $services->get('Common\EasyMeta');
                $this->propertyId = $easyMeta->propertyId($prop);
                if ($this->propertyId) {
                    $this->propertyTerm = $prop;
                    $this->language = $settings->get('iiifsearch_extract_content_language');
                    $this->store['item'] = in_array('item', $contentStore) && !$this->getArg('manual');
                    $this->store['media_pdf'] = in_array('media_pdf', $contentStore);
                    $this->store['media_extracted'] = in_array('media_extracted', $contentStore);
                }
            }
            if (!$this->propertyId) {
                $this->logger->warn(
                    'The option to store text is set, but no property is defined.' // @translate
                );
            }
        }

        $this->createEmptyFile = (bool) $settings->get('iiifsearch_extract_create_empty_file');

        $pairingMode = (string) ($this->getArg('pairing_mode')
            ?: $settings->get('iiifsearch_alto_pairing_mode', 'auto'));
        $allowedModes = ['auto', 'mets', 'source_image_information', 'basename', 'basename_dir', 'numeric', 'numeric_dir', 'dimension', 'sequential'];
        $this->pairingMode = in_array($pairingMode, $allowedModes, true) ? $pairingMode : 'auto';

        $this->reocrEnabled = (bool) $settings->get('iiifsearch_extract_reocr_no_text_layer', true);
        $this->ocrLanguage = trim((string) $settings->get('iiifsearch_extract_ocr_language', ''));
        if ($this->reocrEnabled && (int) shell_exec('hash ocrmypdf 2>&- || echo 1')) {
            $this->reocrEnabled = false;
            $this->logger->info(
                'Option "reocr pdf without text layer" is enabled but ocrmypdf is not available; pdf without text will be skipped.' // @translate
            );
        }

        $this->ocrImagesEnabled = (bool) $settings->get('iiifsearch_extract_ocr_images', true);
        if ($this->ocrImagesEnabled && (int) shell_exec('hash tesseract 2>&- || echo 1')) {
            $this->ocrImagesEnabled = false;
            $this->logger->info(
                'Option "ocr images" is enabled but tesseract is not available; image-only items will be skipped.' // @translate
            );
        }

        // It's not possible to search multiple item ids, so use the connection.
        // SInce the job can be sent only by an admin, there is no rights issue.

        /*
        // TODO The media type can be non-standard for pdf (text/pdf…) on very old servers.
        $query = [
            'media_type' => 'application/pdf',
            'extension' => 'pdf',
        ];
        if ($itemId) {
            $query['item_id'] = $itemId;
        }
        $response = $this->api->search('media', $query, ['returnScalar' => 'id']);
        $pdfMediaIds = $response->getContent();
        $totalToProcess = count($pdfMediaIds);
        */

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        /*
        $entityManager = $services->get('Omeka\EntityManager');
        $mediaRepository = $entityManager->getRepository(\Omeka\Entity\Media::class);
        $criteria = Criteria::create();
        $expr = $criteria->expr();
        $criteria
            ->andWhere($expr->in('media_type', ['application/pdf', 'text/pdf']))
            ->andWhere($expr->eq('extension', 'pdf'))
            ->orderBy(['id' => 'ASC']);
        if ($itemIds) {
            $range = $this->exprRange('item', $itemIds);
            if ($range) {
                $criteria->andWhere($expr->orX(...$range));
            }
        }
        $collection = $mediaRepository->matching($criteria);
        $totalToProcess = $collection->count();
        */

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        // Select item ids having at least one pdf media, one alto media, or one
        // image media (for tesseract-based ALTO extraction).
        $sql = <<<'SQL'
            SELECT DISTINCT `item_id`
            FROM `media`
            WHERE (`media_type` IN (:pdf_media_type) AND `extension` = :pdf_extension)
                OR `media_type` = :alto_media_type
                OR `media_type` LIKE 'image/%'
            SQL;
        $bind = [
            'pdf_media_type' => ['application/pdf', 'text/pdf'],
            'pdf_extension' => 'pdf',
            'alto_media_type' => self::FORMAT_ALTO,
        ];
        $types = [
            'pdf_media_type' => \Doctrine\DBAL\Connection::PARAM_STR_ARRAY,
            'pdf_extension' => \Doctrine\DBAL\ParameterType::STRING,
            'alto_media_type' => \Doctrine\DBAL\ParameterType::STRING,
        ];
        if ($itemIds) {
            $range = $this->exprRange('item_id', $itemIds);
            if ($range) {
                $sql .= ' AND ((' . implode(') OR (', $range) . '))';
            }
        }
        $sql .= ' ORDER BY `item_id` ASC';
        $targetItemIds = $connection->executeQuery($sql, $bind, $types)->fetchFirstColumn();
        $targetItemIds = array_map('intval', $targetItemIds);
        $totalToProcess = count($targetItemIds);

        if (empty($totalToProcess)) {
            $message = new Message('No item with a pdf or alto to process.'); // @translate
            $this->logger->notice($message);
            return;
        }

        if (count($targetTypesMedia)) {
            $this->logger->info(new Message(
                'Formats of xml files to create: %s.', // @translate,
                implode(', ', array_intersect_key($formats, array_flip($targetTypesMedia)))
           ));
        }

        if ($mode === 'existing') {
            $this->logger->info(new Message(
                'Creating Extract OCR files for %d PDF only if they already exist.', // @translate
                $totalToProcess
            ));
        } elseif ($mode === 'missing') {
            $this->logger->info(new Message(
                'Creating Extract OCR files for %d PDF, only if they do not exist yet.', // @translate
                $totalToProcess
            ));
        } elseif ($mode === 'all') {
            $this->logger->info(new Message(
                'Creating Extract OCR files for %d PDF, xml files will be overridden or created.', // @translate
                $totalToProcess
            ));
        } else {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(new Message(
                'Mode of extraction "%s" is not managed.', // @translate
                $mode
            ));
            return;
        }

        // TODO Currently, the process create the files via a loop by media type. Restructure it to do it one time by item.

        // Create a single table to process a single loop.
        // Most of the time, there are one or two formats.
        $create = [];
        foreach ($targetTypesFiles as $targetType) {
            $create[] = ['format' => $targetType, 'create_media' => false];
        }
        foreach ($targetTypesMedia as $targetType) {
            $create[] = ['format' => $targetType, 'create_media' => true];
        }
        // Allow to store extracted text without creating files or media.
        if (empty($create) && $contentStore) {
            $create[] = ['format' => self::FORMAT_PDF2XML, 'create_media' => false, 'content_only' => true];
        }
        foreach ($create as $key => $targetData) {
            $this->createMedia = $targetData['create_media'];
            $this->contentOnly = !empty($targetData['content_only']);
            $this->targetFormat = $targetData['format'];
            $this->targetDirPath = $dirPaths[$this->targetFormat];
            $this->targetExtension = $extensions[$this->targetFormat];
            $this->targetMediaType = $mediaTypes[$this->targetFormat];
            if (count($targetTypesMedia) > 1) {
                $this->logger->notice(new Message(
                    'Processing format %1$d/%2$d: %3$s (%4$s).', // @translate
                    $key + 1, count($targetTypesMedia), $formats[$this->targetFormat], $this->targetMediaType
                ));
            }
            if ($key > 0) {
                $this->store = [
                    'item' => false,
                    'media_pdf' => false,
                    'media_extracted' => false,
                ];
                $this->propertyId = null;
                $this->propertyTerm = null;
            }
            $this->process($targetItemIds, $mode, $totalToProcess);
            if ($this->shouldStop()) {
                // The message is already displayed.
                return;
            }
        }
        if (count($targetTypesMedia) > 1) {
            $this->logger->notice(
                'End of processing formats.', // @translate
            );
        }
    }

    protected function process($targetItemIds, $mode, $totalToProcess)
    {
        $countPdf = 0;
        $countSkipped = 0;
        $countFailed = 0;
        $countProcessed = 0;
        $this->stats = [
            'no_pdf' => [],
            'no_text_layer' => [],
            'issue' => [],
        ];

        $suffixFilenames = [
            self::FORMAT_ALTO=> '.alto',
            self::FORMAT_PDF2XML => '.pdf2xml',
            self::FORMAT_TSV => '.full',
            self::FORMAT_TSV_BY_WORD => '.by-word',
        ];
        $shortExtensions = [
            self::FORMAT_ALTO => 'xml',
            self::FORMAT_PDF2XML => 'xml',
            self::FORMAT_TSV => 'tsv',
            self::FORMAT_TSV_BY_WORD => 'tsv',
        ];

        foreach ($targetItemIds as $itemId) {
            if ($this->shouldStop()) {
                if ($mode === 'all') {
                    $this->logger->warn(new Message(
                        'The job "Extract OCR" was stopped: %1$d/%2$d resources processed, %3$d failed (%4$d without file, %5$d without text layer, %6$d with issue).', // @translate
                        $countProcessed, $totalToProcess, $countFailed, count($this->stats['no_pdf']), count($this->stats['no_text_layer']), count($this->stats['issue'])
                    ));
                } else {
                    $this->logger->warn(new Message(
                        'The job "Extract OCR" was stopped: %1$d/%2$d resources processed, %3$d skipped, %4$d failed (%5$d without file, %6$d without text layer, %7$d with issue).', // @translate
                        $countProcessed, $totalToProcess, $countSkipped, $countFailed, count($this->stats['no_pdf']), count($this->stats['no_text_layer']), count($this->stats['issue'])
                    ));
                }
                return;
            }

            // Step 1: Check the presence of the file/media according to mode.
            // Remove existing file/media if needed. Only the file/media with
            // the same format is removed.

            try {
                $item = $this->api->read('items', ['id' => $itemId])->getContent();
            } catch (\Omeka\Api\Exception\NotFoundException $e) {
                continue;
            }
            $pdfMedias = $this->findPdfMedias($item);
            $pdfMedia = $pdfMedias[0] ?? null;

            // TODO Improve search of an existing file, that can be imported separatly, or that can be another xml format with the same name.
            // Search if this item has already an xml file, managing double
            // extension. For security and to avoid to remove native xml, in
            // particular alto, append the item id for the base of the
            // derivative file.
            $sourceBase = $pdfMedia
                ? basename($pdfMedia->source(), '.pdf')
                : 'item-' . $item->id();
            $targetFilenameNoExtension = $sourceBase . '.' . $item->id();
            $shortExtension = $shortExtensions[$this->targetFormat];
            $targetFilename = $targetFilenameNoExtension . $suffixFilenames[$this->targetFormat] . '.' . $shortExtension;
            $searchExistingOcrMedia = $this->getMediaFromFilename($item->id(), $targetFilename, $shortExtension, $this->targetMediaType);

            $localSearchFilepath = $this->basePath . '/' . $this->targetDirPath . '/' . $item->id() . '.' . $this->targetExtension;
            $searchExistingOcrFile = file_exists($localSearchFilepath);

            ++$countPdf;
            if ($pdfMedia) {
                $this->logger->info(new Message(
                    'Index #%1$d/%2$d: Extracting OCR for item #%3$d, media #%4$d "%5$s".', // @translate
                    $countPdf, $totalToProcess, $item->id(), $pdfMedia->id(), $pdfMedia->source())
                );
            } else {
                $this->logger->info(new Message(
                    'Index #%1$d/%2$d: Extracting OCR for item #%3$d (no pdf, using attached alto).', // @translate
                    $countPdf, $totalToProcess, $item->id())
                );
            }

            if ($this->contentOnly) {
                // No file or media to check, skip to text extraction.
            } elseif ($mode === 'all' || $mode === 'existing') {
                if ($searchExistingOcrFile) {
                    @unlink($localSearchFilepath);
                    $this->logger->info(new Message(
                        'The existing %1$s was removed for item #%2$d.', // @translate
                        $this->targetExtension, $item->id()
                    ));
                }
                if ($searchExistingOcrMedia) {
                    try {
                        $this->api->delete('media', $searchExistingOcrMedia->id());
                    } catch (Exception $e) {
                        // There may be a doctrine issue with module Access, but media is removed.
                    }
                    $this->logger->info(new Message(
                        'The existing %1$s was removed for item #%2$d.', // @translate
                        $this->targetExtension, $item->id()
                    ));
                }
                if ($mode === 'existing'
                    && (
                        (!$this->createMedia && !$searchExistingOcrFile)
                        || ($this->createMedia && !$searchExistingOcrMedia)
                    )
                ) {
                    ++$countSkipped;
                    continue;
                }
            }
            // Here, mode is "missing".
            elseif (!$this->createMedia && $searchExistingOcrFile) {
                $this->logger->info(new Message(
                    'A file %1$s already exists, so item #%2$d is skipped.',  // @translate
                    $this->targetExtension, $item->id()
                ));
                ++$countSkipped;
                continue;
            } elseif ($this->createMedia && $searchExistingOcrMedia) {
                $this->logger->info(new Message(
                    'A file %1$s (media #%2$d) already exists, so item #%3$d is skipped.',  // @translate
                    $this->targetExtension, $searchExistingOcrMedia->id(), $item->id()
                ));
                ++$countSkipped;
                continue;
            }

            // Step 2: Create new file/media, and store text content if needed.

            // Reuse existing ALTO medias when present: skip ALTO target and
            // route TSV targets to ALTO-based extraction instead of pdftohtml.
            // Supports both a single multi-page alto and a collection of
            // per-page alto medias.
            $existingAltoMedias = [];
            if (in_array($this->targetFormat, [self::FORMAT_ALTO, self::FORMAT_TSV, self::FORMAT_TSV_BY_WORD], true)) {
                $existingAltoMedias = $this->findExistingAltoMedias($item);
            }

            // When neither pdf nor alto is present, optionally generate
            // per-page alto from images via tesseract. Persisted under
            // files/alto/ for export reuse.
            $generatedAltoFilepaths = [];
            if (!$existingAltoMedias
                && !$pdfMedias
                && $this->ocrImagesEnabled
                && in_array($this->targetFormat, [self::FORMAT_ALTO, self::FORMAT_TSV, self::FORMAT_TSV_BY_WORD], true)
            ) {
                $generatedAltoFilepaths = $this->generateAltoFromImagesViaTesseract($item);
            }

            $altoSources = $existingAltoMedias ?: $generatedAltoFilepaths;

            if ($altoSources && $this->targetFormat === self::FORMAT_ALTO) {
                $count = count($altoSources);
                $this->logger->info(new Message(
                    'Item #%1$d: %2$d alto source(s) available, skipping alto generation.', // @translate
                    $item->id(), $count
                ));
                ++$countSkipped;
                unset($pdfMedia, $item);
                $this->entityManager->clear();
                continue;
            }

            $hasOcrFile = null;
            $ocrMedia = null;
            if ($altoSources
                && in_array($this->targetFormat, [self::FORMAT_TSV, self::FORMAT_TSV_BY_WORD], true)
            ) {
                $tempFile = $this->extractTsvFromAltoToTempFile($altoSources, $item);
            } elseif ($pdfMedias) {
                if (count($pdfMedias) > 1) {
                    $this->logger->info(new Message(
                        'Item #%1$d: %2$d pdf medias will be concatenated for extraction.', // @translate
                        $item->id(), count($pdfMedias)
                    ));
                }
                $tempFile = $this->extractOcrFromPdfMediasToTempFile($pdfMedias);
            } else {
                $tempFile = null;
                $this->stats['no_pdf'][] = $item->id();
                $this->logger->notice(new Message(
                    'Item #%1$d: no pdf and no usable alto for format %2$s, skipped.', // @translate
                    $item->id(), $this->targetExtension
                ));
            }
            if ($tempFile) {
                $textContent = $this->extractTextContent($pdfMedia, $tempFile);
                if ($this->contentOnly) {
                    // No file or media to create, text stored below.
                } elseif ($this->createMedia) {
                    // Do not create is only for media.
                    $doNotCreate = !in_array($this->targetFormat, [self::FORMAT_TSV, self::FORMAT_TSV_BY_WORD])
                        && !$this->createEmptyFile
                        && !strlen($textContent);
                    if ($doNotCreate) {
                        $this->stats['no_text_layer'][] = $pdfMedia ? $pdfMedia->id() : $item->id();
                        $this->logger->notice(new Message(
                            'The output %1$s for item #%2$d has no text content and is not created.', // @translate
                            $this->targetExtension, $item->id()
                        ));
                    } else {
                        $ocrMedia = $this->storeFileInMedia($tempFile, $textContent, $item, $pdfMedia);
                        if ($ocrMedia) {
                            $this->logger->info(new Message(
                                'Media #%1$d (item #%2$d) created for %3$s file.', // @translate
                                $ocrMedia->id(), $item->id(), $this->targetExtension
                            ));
                        }
                    }
                } else {
                    $hasOcrFile = $this->storeFileLocally($tempFile, $localSearchFilepath);
                    if ($hasOcrFile) {
                        $this->logger->info(new Message(
                            'IIIF Search file for item #%1$d created for format %2$s.', // @translate
                            $item->id(), $this->targetExtension
                        ));
                    } else {
                        $this->logger->err(new Message(
                            'Unable to store the IIIF Search file for item #%1$d for format %2$s.', // @translate
                            $item->id(), $this->targetExtension
                        ));
                    }
                }
                $tempFile->delete();

                if ($hasOcrFile || $ocrMedia || $this->contentOnly) {
                    // Text content is already stored in media ocr.
                    if ($this->store['media_pdf'] && $pdfMedia) {
                        $this->storeContentInProperty($pdfMedia, $textContent);
                    }
                    if ($this->store['item']) {
                        $this->storeContentInProperty($item, $textContent);
                    }
                    ++$countProcessed;
                } else {
                    ++$countFailed;
                }
            } else {
                ++$countFailed;
            }

            // Avoid memory issue.
            unset($pdfMedia);
            unset($ocrMedia);
            unset($item);

            // Clear the Doctrine identity map to prevent memory growth
            // on large batches. Property scalars are cached above.
            $this->entityManager->clear();
        }

        if ($this->stats['no_pdf']) {
            $this->logger->notice(new Message(
                'These items have no pdf file (or no usable alto for the requested format): #%s', // @translate
                implode(', #', $this->stats['no_pdf'])
            ));
        }

        if ($this->stats['no_text_layer']) {
            $this->logger->notice(new Message(
                'These pdf files have no text layer: #%s', // @translate
                implode(', #', $this->stats['no_text_layer'])
            ));
        }

        if ($this->stats['issue']) {
            $this->logger->notice(new Message(
                'These pdf files have issues when extracting content: #%s', // @translate
                implode(', #', $this->stats['issue'])
            ));
        }

        if ($mode === 'all') {
            $this->logger->notice(new Message(
                'Processed %1$d/%2$d pdf files, %3$d files %4$s created, %5$d failed (%6$d without file, %7$d without text layer, %8$d with issue).', // @translate
                $countPdf, $totalToProcess, $countProcessed, $this->targetExtension, $countFailed, count($this->stats['no_pdf']), count($this->stats['no_text_layer']), count($this->stats['issue'])
            ));
        } else {
            $this->logger->notice(new Message(
                'Processed %1$d/%2$d pdf files, %3$d skipped, %4$d files %5$s, created, %6$d failed (%7$d without file, %8$d without text layer, %9$d with issue).', // @translate
                $countPdf, $totalToProcess, $countSkipped, $countProcessed, $this->targetExtension, $countFailed, count($this->stats['no_pdf']), count($this->stats['no_text_layer']), count($this->stats['issue'])
            ));
        }
    }

    /**
     * Get the first media from item id, source name, extension and media type.
     *
     * @todo Improve search of ocr pdf2xml files.
     *
     * Copy:
     * @see \IiifSearch\Module::getMediaFromFilename()
     * @see \IiifSearch\Job\ExtractOcr::getMediaFromFilename()
     *
     * @param int $itemId
     * @param string $filename
     * @param string $extension
     * @param string $mediaType
     * @return \Omeka\Api\Representation\MediaRepresentation|null
     */
    protected function getMediaFromFilename(
        int $itemId,
        string $filename,
        string $extension,
        string $mediaType
    ): ?MediaRepresentation {
        // The api search() doesn't allow to search a source, so we use read().
        try {
            return $this->api->read('media', [
                'item' => $itemId,
                'source' => $filename,
                'extension' => $extension,
                'mediaType' => $mediaType,
            ])->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            return null;
        }
    }

    /**
     * @param MediaRepresentation[] $pdfMedias
     */
    protected function extractOcrFromPdfMediasToTempFile(array $pdfMedias, bool $forceXml = false): ?TempFile
    {
        $pdfMedias = array_values(array_filter($pdfMedias));
        if (!$pdfMedias) {
            return null;
        }

        $pdfFilepaths = [];
        $ocrTempFiles = [];
        foreach ($pdfMedias as $pdfMedia) {
            $pdfFilepath = $this->basePath . '/original/' . $pdfMedia->filename();
            if (!file_exists($pdfFilepath)) {
                $this->stats['no_pdf'][] = $pdfMedia->id();
                $this->logger->err(new Message(
                    'Missing pdf file (media #%1$d).', // @translate
                    $pdfMedia->id()
                ));
                foreach ($ocrTempFiles as $tf) {
                    $tf->delete();
                }
                return null;
            }
            $ocrResult = $this->maybeOcrPdf($pdfFilepath, $pdfMedia->id());
            if ($ocrResult['tempFile']) {
                $ocrTempFiles[] = $ocrResult['tempFile'];
            }
            $pdfFilepaths[] = $ocrResult['filepath'];
        }

        $firstPdfMedia = $pdfMedias[0];
        $this->dataPdf = [
            'source_pdf_file_url' => $firstPdfMedia->originalUrl(),
            'source_pdf_file_name' => $firstPdfMedia->filename(),
            'source_pdf_file_identifier' => (string) $firstPdfMedia->value('dcterms:identifier') ?: '',
            'source_pdf_document_url' => $firstPdfMedia->item()->apiUrl(),
            'source_pdf_document_identifier' => (string) $firstPdfMedia->item()->value('dcterms:identifier') ?: '',
        ];

        // Do the conversion of the pdf to xml.
        $forceXmlForTsv = $forceXml
            && in_array($this->targetFormat, [self::FORMAT_TSV, self::FORMAT_TSV_BY_WORD]);
        $tempFile = $forceXmlForTsv
            // The temp file is a pdf2xml file, with extension ".pdf2xml.xml".
            ? $this->extractPdfToTempFile($pdfFilepaths, $firstPdfMedia->item(), 'pdf2xml.xml', self::FORMAT_PDF2XML)
            : $this->extractPdfToTempFile($pdfFilepaths, $firstPdfMedia->item(), $this->targetExtension, $this->targetFormat);

        foreach ($ocrTempFiles as $tf) {
            $tf->delete();
        }

        if (empty($tempFile)) {
            $this->stats['issue'][] = $firstPdfMedia->id();
            $this->logger->err(new Message(
                'File %1$s was not created for media #%2$s.', // @translate
                $this->targetExtension, $firstPdfMedia->id()
            ));
            return null;
        }

        $tempPath = $tempFile->getTempPath();

        // A check is done when option "create empty file" is not used.
        if (!$tempPath || !file_exists($tempPath)) {
            return null;
        }

        return $tempFile;
    }

    /**
     * If the pdf has no text layer and reocr is enabled, run ocrmypdf and
     * return a path to a temporary OCR'd pdf. Otherwise return the original.
     *
     * @return array{filepath:string,tempFile:?TempFile}
     */
    protected function maybeOcrPdf(string $pdfFilepath, ?int $pdfMediaId = null): array
    {
        if (!$this->reocrEnabled) {
            return ['filepath' => $pdfFilepath, 'tempFile' => null];
        }

        // Quick probe: extract text to stdout. If empty, the pdf has no text
        // layer and is a candidate for ocrmypdf.
        $probe = $this->cli->execute(sprintf('pdftotext -layout %1$s -', escapeshellarg($pdfFilepath)));
        if ($probe === false) {
            return ['filepath' => $pdfFilepath, 'tempFile' => null];
        }
        if (strlen(trim((string) $probe)) > 0) {
            return ['filepath' => $pdfFilepath, 'tempFile' => null];
        }

        $tempFile = $this->tempFileFactory->build();
        $ocrPath = $tempFile->getTempPath() . '.ocr.pdf';
        @unlink($tempFile->getTempPath());
        $tempFile->setTempPath($ocrPath);

        $langArg = $this->ocrLanguage !== ''
            ? ' -l ' . escapeshellarg($this->ocrLanguage)
            : '';
        $command = sprintf(
            'ocrmypdf --skip-text --quiet%1$s %2$s %3$s',
            $langArg,
            escapeshellarg($pdfFilepath),
            escapeshellarg($ocrPath)
        );
        $this->logger->info(new Message(
            'PDF media #%1$s has no text layer: running ocrmypdf.', // @translate
            $pdfMediaId === null ? '?' : (string) $pdfMediaId
        ));
        $result = $this->cli->execute($command);
        if ($result === false || !file_exists($ocrPath) || !filesize($ocrPath)) {
            $this->logger->warn(new Message(
                'ocrmypdf failed for pdf media #%1$s; falling back to original pdf.', // @translate
                $pdfMediaId === null ? '?' : (string) $pdfMediaId
            ));
            $tempFile->delete();
            return ['filepath' => $pdfFilepath, 'tempFile' => null];
        }
        return ['filepath' => $ocrPath, 'tempFile' => $tempFile];
    }

    /**
     * Extract the text content of the pdf, reusing temp file when possible.
     */
    protected function extractTextContent(?MediaRepresentation $pdfMedia, ?TempFile $tempFile = null): ?string
    {
        // For tsv, text content is only needed for property storage. Skip the
        // expensive re-extraction when no property is configured.
        $isTsv = in_array($this->targetFormat, [self::FORMAT_TSV, self::FORMAT_TSV_BY_WORD]);
        if ($isTsv && !$this->propertyId) {
            return null;
        }

        // Without a pdf, text content cannot be re-extracted; rely on tempFile.
        if (!$pdfMedia) {
            if (!$tempFile) {
                return null;
            }
            $xmlContent = (string) file_get_contents($tempFile->getTempPath());
            if ($this->targetFormat === self::FORMAT_ALTO) {
                return $this->extractTextFromAlto($xmlContent);
            }
            return trim(str_replace('  ', ' ', strip_tags(str_replace('<', ' <', $xmlContent))));
        }

        $localTempFile = null;
        if ($isTsv || !$tempFile) {
            $localTempFile = $this->extractOcrFromPdfMediasToTempFile([$pdfMedia], true);
            $tempFile = $localTempFile;
        }

        if (!$tempFile) {
            return null;
        }

        $tempPath = $tempFile->getTempPath();
        $xmlContent = (string) file_get_contents($tempPath);

        // Clean up the locally created temp file.
        if ($localTempFile) {
            $localTempFile->delete();
        }

        // The content can be reextracted through pdftotext, that may return a
        // different layout with options -layout or -raw.
        // Here, the text is extracted from the extracted pdf2xml.
        if ($this->targetFormat === self::FORMAT_ALTO) {
            $textContent = $this->extractTextFromAlto($xmlContent);
        } else {
            // Add a space between words.
            $textContent = trim(str_replace('  ', ' ', strip_tags( str_replace('<', ' <', $xmlContent))));
        }

        return $textContent;
    }

    /**
     * Extract text from alto.
     */
    protected function extractTextFromAlto(string $content): string
    {
        $simpleXml = simplexml_load_string($content, null, LIBXML_NONET);
        if ($simpleXml === false) {
            return '';
        }

        $modulePath = dirname(__DIR__, 2);
        $xsltPath = $modulePath . '/data/xsl/alto_to_text.xsl';
        $dom = $this->processXslt($simpleXml, $xsltPath);
        if (!$dom) {
            return '';
        }

        $dom->formatOutput = false;
        $dom->strictErrorChecking = false;
        $dom->validateOnParse = false;
        $dom->recover = true;
        $result = (string) $dom->saveHTML();
        return html_entity_decode($result, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
    }

    protected function storeFileLocally(TempFile $tempFile, string $filepath): bool
    {
        $tempFilepath = $tempFile->getTempPath();
        return copy($tempFilepath, $filepath);
    }

    protected function storeFileInMedia(
        TempFile $tempFile,
        ?string $textContent,
        ItemRepresentation $item,
        ?MediaRepresentation $pdfMedia = null
    ): ?MediaRepresentation {
        // It's not possible to save a local file via the "upload" ingester. So
        // the ingester "url" can be used, but it requires the file to be in the
        // omeka files directory. Else, use module FileSideload or inject sql.
        $storeFile = $this->makeTempFileDownloadable($tempFile, '/extractocr');
        if (!$storeFile) {
            return null;
        }

        $currentPosition = count($item->media());

        // This data is important to get the matching pdf and xml.
        $sourceBase = $pdfMedia
            ? basename($pdfMedia->source(), '.pdf')
            : 'item-' . $item->id();
        $source = $sourceBase . '.' . $item->id() . '.' . $this->targetExtension;

        $data = [
            'o:item' => [
                'o:id' => $item->id(),
            ],
            'o:ingester' => 'url',
            'ingest_url' => $storeFile['url'],
            'o:source' => $source,
            'o:lang' => $this->language,
            'o:media_type' => $this->targetMediaType,
            'position' => $currentPosition,
            'values_json' => '{}',
        ];

        if ($this->propertyId && strlen((string) $textContent) && $this->store['media_extracted']) {
            $data[$this->propertyTerm][] = [
                'type' => 'literal',
                'property_id' => $this->propertyId,
                '@value' => $textContent ,
                '@language' => $this->language,
            ];
            if ($pdfMedia) {
                $data['dcterms:isFormatOf'][] = [
                    'type' => 'resource:media',
                    // dcterms:isFormatOf.
                    'property_id' => 37,
                    'value_resource_id' => $pdfMedia->id(),
                ];
            }
        }

        try {
            $media = $this->api->create('media', $data)->getContent();
        } catch (\Omeka\Api\Exception\ExceptionInterface $e) {
            // Generally a bad or missing pdf file.
            $this->logger->err($e->getMessage() ?: $e);
            return null;
        } catch (Exception $e) {
            $this->logger->err($e);
            return null;
        } finally {
            @unlink($storeFile['filepath']);
        }

        if (!$media) {
            return null;
        }

        // Move the xml file as the last media to avoid thumbnails issues.
        $this->reorderMediasAndSetType($media);
        return $media;
    }

    /**
     * Extract and store OCR Data from one or more pdf files in .xml or .tsv.
     *
     * @param string|string[] $pdfFilepaths
     */
    protected function extractPdfToTempFile(
        $pdfFilepaths,
        ItemRepresentation $item,
        string $extension,
        string $format
    ): ?TempFile {
        $pdfFilepaths = is_array($pdfFilepaths) ? array_values($pdfFilepaths) : [$pdfFilepaths];
        if (!$pdfFilepaths) {
            return null;
        }

        $tempFile = $this->tempFileFactory->build();

        $tempFilepath = $tempFile->getTempPath() . '.' . $extension;
        @unlink($tempFile->getTempPath());
        $tempFile->setTempPath($tempFilepath);
        $tempPath = $tempFile->getTempPath();

        if (in_array($format, [self::FORMAT_TSV, self::FORMAT_TSV_BY_WORD])) {
            $result = $this->extractTextToTsv($pdfFilepaths, $tempFilepath, $item, $format);
            if (!$result) {
                if ($tempPath && file_exists($tempPath)) {
                    $tempFile->delete();
                }
                return null;
            }
            return $tempFile;
        }

        $simpleXml = $this->runPdftohtmlAndMerge($pdfFilepaths);
        if (!$simpleXml) {
            if ($tempPath && file_exists($tempPath)) {
                $tempFile->delete();
            }
            return null;
        }

        $simpleXml->saveXML($tempFilepath);

        if ($format === self::FORMAT_ALTO) {
            /** @see https://gitlab.freedesktop.org/poppler/poppler/-/raw/master/utils/pdf2xml.dtd pdf2xml */
            $modulePath = dirname(__DIR__, 2);
            $xsltPath = $modulePath . '/data/xsl/pdf2xml_to_alto.xsl';
            $args = $this->dataPdf;
            $args['datetime'] = (new DateTime('now'))->format('Y-m-d\TH:i:s');
            $dom = $this->processXslt($simpleXml, $xsltPath, $args);
            if (!$dom) {
                $tempFile->delete();
                return null;
            }
            $dom->formatOutput = true;
            $dom->strictErrorChecking = false;
            $dom->validateOnParse = false;
            $dom->recover = true;
            $dom->preserveWhiteSpace = false;
            $result = $dom->save($tempFilepath);
            if (!$result) {
                if ($tempPath && file_exists($tempPath)) {
                    $tempFile->delete();
                }
                return null;
            }
        }

        return $tempFile;
    }

    /**
     * Run pdftohtml on each pdf and merge the resulting pdf2xml documents into
     * a single SimpleXMLElement, renumbering pages globally.
     *
     * @param string[] $pdfFilepaths
     */
    protected function runPdftohtmlAndMerge(array $pdfFilepaths): ?SimpleXMLElement
    {
        $mergedDom = null;
        $mergedRoot = null;
        $globalPage = 0;

        foreach ($pdfFilepaths as $pdfFilepath) {
            $tempPdfXml = $this->tempFileFactory->build();
            $pdfXmlPath = $tempPdfXml->getTempPath() . '.pdf2xml.xml';
            @unlink($tempPdfXml->getTempPath());
            $tempPdfXml->setTempPath($pdfXmlPath);

            $command = sprintf('pdftohtml -i -c -hidden -nodrm -enc "UTF-8" -xml %1$s %2$s',
                escapeshellarg($pdfFilepath), escapeshellarg($pdfXmlPath));
            $result = $this->cli->execute($command);
            if ($result === false || !file_exists($pdfXmlPath) || !filesize($pdfXmlPath)) {
                $tempPdfXml->delete();
                return null;
            }

            $xmlContent = file_get_contents($pdfXmlPath);
            $xmlContent = preg_replace('/[^\PCc^\PCn^\PCs]/u', '', $xmlContent);
            if ($this->fixUtf8) {
                $xmlContent = $this->fixUtf8->__invoke($xmlContent);
            }
            $xmlContent = $this->fixXmlPdf2Xml($xmlContent);
            if (!$xmlContent) {
                $tempPdfXml->delete();
                return null;
            }
            $simpleXml = $this->fixXmlDom($xmlContent);
            $tempPdfXml->delete();
            if (!$simpleXml) {
                return null;
            }

            $sourceDom = dom_import_simplexml($simpleXml)->ownerDocument;
            if ($mergedDom === null) {
                // Use the first file as the base and renumber its pages.
                $mergedDom = $sourceDom;
                $root = $mergedDom->documentElement;
                $pageNodes = $root->getElementsByTagName('page');
                $pageList = [];
                foreach ($pageNodes as $p) {
                    $pageList[] = $p;
                }
                foreach ($pageList as $p) {
                    ++$globalPage;
                    $p->setAttribute('number', (string) $globalPage);
                }
                $mergedRoot = $root;
                continue;
            }

            // Append pages from subsequent pdfs into the merged document.
            $sourceRoot = $sourceDom->documentElement;
            $pageNodes = $sourceRoot->getElementsByTagName('page');
            $pageList = [];
            foreach ($pageNodes as $p) {
                $pageList[] = $p;
            }
            foreach ($pageList as $p) {
                ++$globalPage;
                $imported = $mergedDom->importNode($p, true);
                $imported->setAttribute('number', (string) $globalPage);
                $mergedRoot->appendChild($imported);
            }
        }

        if (!$mergedDom) {
            return null;
        }
        return simplexml_import_dom($mergedDom);
    }

    /**
     * Find every attached image media on the item, in media position order.
     *
     * @return MediaRepresentation[]
     */
    protected function findImageMedias(ItemRepresentation $item): array
    {
        $images = [];
        foreach ($item->media() as $media) {
            $mediaType = (string) $media->mediaType();
            if (strtok($mediaType, '/') === 'image' && $media->hasOriginal()) {
                $images[] = $media;
            }
        }
        return $images;
    }

    /**
     * Run tesseract on each image media of the item to produce one ALTO file
     * per page, written under files/alto/ for export reuse.
     *
     * @return string[] Ordered list of generated alto filepaths.
     */
    protected function generateAltoFromImagesViaTesseract(ItemRepresentation $item): array
    {
        $imageMedias = $this->findImageMedias($item);
        if (!$imageMedias) {
            return [];
        }

        $altoDir = $this->basePath . '/alto';
        if (!$this->checkDestinationDir($altoDir)) {
            return [];
        }

        $langArg = $this->ocrLanguage !== ''
            ? ' -l ' . escapeshellarg($this->ocrLanguage)
            : '';

        $generated = [];
        $page = 0;
        foreach ($imageMedias as $imageMedia) {
            ++$page;
            $imagePath = $this->basePath . '/original/' . $imageMedia->filename();
            if (!file_exists($imagePath)) {
                $this->logger->warn(new Message(
                    'Image media #%1$d: file missing, skipped for tesseract.', // @translate
                    $imageMedia->id()
                ));
                continue;
            }

            // Persistent target: files/alto/<item_id>-page-<NN>.alto.xml
            $altoBase = sprintf('%s/%d-page-%03d.alto', $altoDir, $item->id(), $page);
            $altoPath = $altoBase . '.xml';
            // tesseract appends ".xml" to the output base when the alto config
            // is used. Pass the base without extension.
            $command = sprintf(
                'tesseract %1$s %2$s%3$s alto',
                escapeshellarg($imagePath),
                escapeshellarg($altoBase),
                $langArg
            );
            $result = $this->cli->execute($command);
            if ($result === false || !file_exists($altoPath) || !filesize($altoPath)) {
                $this->logger->warn(new Message(
                    'tesseract failed for image media #%1$d.', // @translate
                    $imageMedia->id()
                ));
                @unlink($altoPath);
                continue;
            }
            $generated[] = $altoPath;
        }

        if ($generated) {
            $this->logger->info(new Message(
                'Item #%1$d: %2$d alto file(s) generated via tesseract under files/alto/.', // @translate
                $item->id(), count($generated)
            ));
        }
        return $generated;
    }

    /**
     * Find every attached pdf media on the item, in media position order.
     *
     * @return MediaRepresentation[]
     */
    protected function findPdfMedias(ItemRepresentation $item): array
    {
        $pdfMedias = [];
        foreach ($item->media() as $media) {
            $mediaType = (string) $media->mediaType();
            if ((string) $media->extension() === 'pdf'
                && ($mediaType === 'application/pdf' || $mediaType === 'text/pdf')
            ) {
                $pdfMedias[] = $media;
            }
        }
        return $pdfMedias;
    }

    /**
     * Find every attached ALTO XML media on the item, in media position order.
     *
     * Detection is done by sniffing the root element rather than relying on
     * media_type, because Omeka almost always stores alto uploads as plain
     * application/xml or text/xml. Falls back to the legacy mime check when the
     * classifier is unavailable.
     *
     * @return MediaRepresentation[]
     */
    protected function findExistingAltoMedias(ItemRepresentation $item): array
    {
        $classifier = $this->getXmlMediaClassifier();
        $medias = [];
        foreach ($item->media() as $media) {
            if ($media->mediaType() === self::FORMAT_ALTO) {
                $medias[] = $media;
                continue;
            }
            if ($classifier->isXmlLikeMedia($media)
                && $classifier->classifyMedia($media, $this->basePath) === XmlMediaClassifier::TYPE_ALTO
            ) {
                $medias[] = $media;
            }
        }
        return $medias;
    }

    protected function getXmlMediaClassifier(): XmlMediaClassifier
    {
        if (!$this->xmlMediaClassifier) {
            $this->xmlMediaClassifier = new XmlMediaClassifier();
        }
        return $this->xmlMediaClassifier;
    }

    /**
     * Build a TSV temp file from existing ALTO sources (medias or filepaths),
     * bypassing pdftohtml.
     *
     * @param array $altoSources MediaRepresentation[]|string[] (filepaths).
     */
    protected function extractTsvFromAltoToTempFile(
        array $altoSources,
        ItemRepresentation $item
    ): ?TempFile {
        $altoFilepaths = [];
        foreach ($altoSources as $source) {
            if (is_string($source)) {
                if (!file_exists($source)) {
                    $this->logger->err(new Message(
                        'Missing ALTO file "%s".', // @translate
                        $source
                    ));
                    return null;
                }
                $altoFilepaths[] = $source;
                continue;
            }
            $altoFilepath = $this->basePath . '/original/' . $source->filename();
            if (!file_exists($altoFilepath)) {
                $this->logger->err(new Message(
                    'Missing ALTO file (media #%1$d).', // @translate
                    $source->id()
                ));
                return null;
            }
            $altoFilepaths[] = $altoFilepath;
        }
        if (!$altoFilepaths) {
            return null;
        }

        // Build one PageSource per intrinsic page (a multipage alto with
        // per-Page sourceImageInformation produces N PageSources sharing the
        // same media/filepath but carrying individual image hints). The pairer
        // is invoked as soon as the total intrinsic page count is greater than
        // one, even for a single multipage source.
        $hintReader = new PageSourceHintReader();
        $pageSources = [];
        $globalIndex = 0;
        foreach ($altoSources as $src) {
            if (is_string($src)) {
                $filepath = $src;
                $media = null;
                $rawName = basename($src);
            } else {
                $filepath = $this->basePath . '/original/' . $src->filename();
                $media = $src;
                $rawName = (string) $src->source();
            }
            $hints = $hintReader->readAllImageHints($filepath, 'alto');
            $pageCount = max(1, count($hints));
            for ($k = 0; $k < $pageCount; ++$k) {
                ++$globalIndex;
                $ps = new PageSource($globalIndex, $media, $filepath, $rawName, 'alto');
                $ps->intrinsicPage = $k + 1;
                $ps->sourceImageFileName = $hints[$k] ?? null;
                $pageSources[] = $ps;
            }
        }

        $pairing = null;
        if (count($pageSources) > 1) {
            $pairer = new PagePairer($this->basePath);
            $pairing = $pairer->pair($item, $pageSources, $this->pairingMode);
            $this->logger->info(new Message(
                'Item #%1$d: pairing method %2$s, coverage %3$s.', // @translate
                $item->id(),
                $pairing->method,
                number_format($pairing->coverage, 2)
            ));
            foreach ($pairing->warnings as $w) {
                $this->logger->notice($w);
            }
        }

        $tempFile = $this->tempFileFactory->build();
        $tsvPath = $tempFile->getTempPath() . '.' . $this->targetExtension;
        @unlink($tempFile->getTempPath());
        $tempFile->setTempPath($tsvPath);
        if (!$this->extractTextToTsvFromAlto($altoFilepaths, $tsvPath, $item, $this->targetFormat, $pairing)) {
            $tempFile->delete();
            return null;
        }
        return $tempFile;
    }

    /**
     * Resolve the image dimensions to use for scaling a given ocr page.
     *
     * When the pairer produced a non-sequential match, prefer it; otherwise
     * fall back to positional order. Returns null when no image matches.
     *
     * @param array<int,array{id:int,width:int,height:int,source:?string}> $listMediaImages
     * @return array{width:int,height:int}|null
     */
    protected function resolvePageImage(?PairingResult $pairing, int $indexPage, array $listMediaImages): ?array
    {
        if ($pairing && $pairing->method !== PairingResult::METHOD_SEQUENTIAL) {
            $media = $pairing->imageForPage($indexPage);
            if ($media) {
                foreach ($listMediaImages as $entry) {
                    if (isset($entry['id']) && (int) $entry['id'] === (int) $media->id()) {
                        return $entry;
                    }
                }
            }
        }
        return $listMediaImages[$indexPage - 1] ?? null;
    }

    /**
     * Parse ALTO XML and emit TSV (full or by-word). Coordinates are scaled
     * from ALTO Page WIDTH/HEIGHT to the corresponding image media dimensions
     * so the produced TSV stays consistent with pdftohtml-based output.
     *
     * Accepts either a single multi-page alto file or a list of per-page alto
     * files; page numbering is global across all input files.
     *
     * @param string|string[] $altoFilepaths Single path or ordered list.
     */
    protected function extractTextToTsvFromAlto(
        $altoFilepaths,
        string $tsvFilepath,
        ItemRepresentation $item,
        string $format,
        ?PairingResult $pairing = null
    ): bool {
        $altoFilepaths = is_array($altoFilepaths) ? $altoFilepaths : [$altoFilepaths];
        if (!$altoFilepaths) {
            return false;
        }

        $listMediaImages = $this->listMediaImagesData($item);
        $isFullTsv = $format === self::FORMAT_TSV;
        $hasRow = false;
        $resultTsv = [];

        $fp = null;
        if ($isFullTsv) {
            $fp = fopen($tsvFilepath, 'w');
            if ($fp === false) {
                $this->logger->err(new Message(
                    'Unable to open file "%s" for writing.', // @translate
                    $tsvFilepath
                ));
                return false;
            }
        }

        $indexPage = 0;
        foreach ($altoFilepaths as $altoFilepath) {
            $content = file_get_contents($altoFilepath);
            if ($content === false) {
                if ($isFullTsv) {
                    fclose($fp);
                }
                return false;
            }
            // Remove control characters from bad ocr.
            $content = preg_replace('/[^\PCc^\PCn^\PCs]/u', '', $content);
            $xml = simplexml_load_string(
                $content,
                null,
                LIBXML_BIGLINES | LIBXML_COMPACT | LIBXML_NOBLANKS | LIBXML_PARSEHUGE | LIBXML_NONET
            );
            if ($xml === false) {
                continue;
            }

            // Register default namespace as 'alto' for xpath.
            $namespaces = $xml->getDocNamespaces();
            if (!empty($namespaces[''])) {
                $xml->registerXPathNamespace('alto', $namespaces['']);
                $pages = $xml->xpath('//alto:Page');
                $stringXpath = './/alto:String';
            } else {
                $pages = $xml->xpath('//Page');
                $stringXpath = './/String';
            }

            foreach ($pages ?: [] as $page) {
                ++$indexPage;
                $attr = $page->attributes();
                $pageWidth = (float) $attr->WIDTH;
                $pageHeight = (float) $attr->HEIGHT;
                if (!$pageWidth || !$pageHeight) {
                    continue;
                }
                $mediaImage = $this->resolvePageImage($pairing, $indexPage, $listMediaImages);
                $mediaImageWidth = $mediaImage ? $mediaImage['width'] : $pageWidth;
                $mediaImageHeight = $mediaImage ? $mediaImage['height'] : $pageHeight;
                $scaleX = $mediaImageWidth / $pageWidth;
                $scaleY = $mediaImageHeight / $pageHeight;

                foreach ($page->xpath($stringXpath) ?: [] as $string) {
                    $a = $string->attributes();
                    $word = $this->normalize((string) $a->CONTENT);
                    if (!strlen($word)) {
                        continue;
                    }
                    $x = round(((float) $a->HPOS) * $scaleX);
                    $y = round(((float) $a->VPOS) * $scaleY);
                    $w = round(((float) $a->WIDTH) * $scaleX);
                    $h = round(((float) $a->HEIGHT) * $scaleY);
                    $xywh = $x . ',' . $y . ',' . $w . ',' . $h;
                    if ($isFullTsv) {
                        fputcsv($fp, [$word, $indexPage, $xywh], "\t", "\0", "\0");
                        $hasRow = true;
                    } else {
                        $word = mb_strtolower($word, 'UTF-8');
                        $resultTsv[$word][] = $indexPage . ':' . $xywh;
                    }
                }
            }
        }

        if ($isFullTsv) {
            $closed = fclose($fp);
            if (!$hasRow && !$this->createEmptyFile) {
                @unlink($tsvFilepath);
                return true;
            }
            return $closed;
        }

        if (!$resultTsv && !$this->createEmptyFile) {
            return true;
        }
        $fp = fopen($tsvFilepath, 'w');
        if ($fp === false) {
            $this->logger->err(new Message(
                'Unable to open file "%s" for writing.', // @translate
                $tsvFilepath
            ));
            return false;
        }
        foreach ($resultTsv as $word => $positions) {
            fputcsv($fp, [$word, implode(';', $positions)], "\t", "\0", "\0");
        }
        return fclose($fp);
    }

    /**
     * Build a tsv from one or several pdf files, with a global page index.
     *
     * @param string|string[] $pdfFilepaths
     */
    protected function extractTextToTsv($pdfFilepaths, $tsvFilepath, ItemRepresentation $item, $format) : bool
    {
        $pdfFilepaths = is_array($pdfFilepaths) ? array_values($pdfFilepaths) : [$pdfFilepaths];
        if (!$pdfFilepaths) {
            return false;
        }

        $listMediaImages = $this->listMediaImagesData($item);

        $hasRow = false;
        $resultTsv = [];
        $indexXmlPage = 0;
        $isFullTsv = $format === self::FORMAT_TSV;

        $fp = null;
        if ($isFullTsv) {
            $fp = fopen($tsvFilepath, 'w');
            if ($fp === false) {
                $this->logger->err(new Message(
                    'Unable to open file "%s" for writing.', // @translate
                    $tsvFilepath
                ));
                return false;
            }
        }

        foreach ($pdfFilepaths as $pdfFilepath) {
            // Create temp file that will be removed at the end of the loop.
            $tempFile = $this->tempFileFactory->build();
            $xmlFilepath = $tempFile->getTempPath() . 'pdf2xml.xml';
            @unlink($tempFile->getTempPath());
            $tempFile->setTempPath($xmlFilepath);
            $tempPath = $tempFile->getTempPath();

            $command = sprintf('pdftotext -bbox -layout %1$s %2$s',
                escapeshellarg($pdfFilepath), escapeshellarg($xmlFilepath));
            $result = $this->cli->execute($command);
            if ($result === false) {
                if ($tempPath && file_exists($tempPath)) {
                    $tempFile->delete();
                }
                if ($isFullTsv) {
                    fclose($fp);
                }
                return false;
            }

            $content = file_get_contents($xmlFilepath);
            $content = preg_replace('/[^\PCc^\PCn^\PCs]/u', '', $content);
            $xml = simplexml_load_string($content, null,
                LIBXML_BIGLINES | LIBXML_COMPACT | LIBXML_NOBLANKS | LIBXML_PARSEHUGE | LIBXML_NONET
            );
            $tempFile->delete();
            if ($xml === false) {
                if ($isFullTsv) {
                    fclose($fp);
                }
                return false;
            }

            foreach ($xml->body->doc->page ?? [] as $xmlPage) {
                ++$indexXmlPage;

                $pageAttribute = $xmlPage->attributes();
                $pageWidth = (float) $pageAttribute->width;
                $pageHeigth = (float) $pageAttribute->height;
                if (!$pageWidth || !$pageHeigth) {
                    continue;
                }

                $mediaImage = $listMediaImages[$indexXmlPage - 1] ?? null;
                $mediaImageWidth = $mediaImage ? $mediaImage['width'] : $pageWidth;
                $mediaImageHeight = $mediaImage ? $mediaImage['height'] : $pageHeigth;
                $scaleX = $mediaImageWidth / $pageWidth;
                $scaleY = $mediaImageHeight / $pageHeigth;

                foreach ($xmlPage->word ?? [] as $xmlword) {
                    $word = (string) $xmlword;
                    $word = $this->normalize($word);
                    if (!strlen($word)) {
                        continue;
                    }

                    $attributes = $xmlword->attributes();
                    $xMax = $attributes->xMax * $scaleX;
                    $yMax = $attributes->yMax * $scaleY;
                    $xMin = $attributes->xMin * $scaleX;
                    $yMin = $attributes->yMin * $scaleY;

                    $width = round($xMax - $xMin);
                    $height = round($yMax - $yMin);
                    $xywh = round((float) $xMin) . ',' . round((float) $yMin) . ',' . $width . ',' . $height;

                    if ($isFullTsv) {
                        fputcsv($fp, [$word, $indexXmlPage, $xywh], "\t", "\0", "\0");
                        $hasRow = true;
                    } else {
                        $word = mb_strtolower($word, 'UTF-8');
                        $resultTsv[$word][] = $indexXmlPage . ':' . $xywh;
                    }
                }
            }
        }

        if ($isFullTsv) {
            $result = fclose($fp);
            if (!$hasRow && !$this->createEmptyFile) {
                @unlink($tsvFilepath);
                return true;
            }
            return $result;
        }

        if (!$resultTsv && !$this->createEmptyFile) {
            return true;
        }

        $fp = fopen($tsvFilepath, 'w');
        if ($fp === false) {
            $this->logger->err(new Message(
                'Unable to open file "%s" for writing.', // @translate
                $tsvFilepath
            ));
            return false;
        }
        foreach ($resultTsv as $word => $positions) {
            fputcsv($fp, [$word, implode(';', $positions)], "\t", "\0", "\0");
        }
        return fclose($fp);
    }

    /**
     * Check if xml is valid.
     *
     * Copy in:
     * @see \IiifSearch\Job\ExtractOcr::fixXmlDom()
     * @see \IiifSearch\View\Helper\IiifSearch::fixXmlDom()
     * @see \IiifSearch\View\Helper\XmlAltoSingle::fixXmlDom()
     * @see \IiifServer\Iiif\TraitXml::fixXmlDom()
     */
    protected function fixXmlDom(string $xmlContent): ?SimpleXMLElement
    {
        return XmlRepair::fixXmlDom($xmlContent);
    }

    protected function fixXmlPdf2Xml(?string $xmlContent): string
    {
        return XmlRepair::fixXmlPdf2Xml($xmlContent);
    }

    protected function processXslt(SimpleXMLElement $simpleXml, string $xsltPath, array $params = []): ?DOMDocument
    {
        try {
            $domXml = dom_import_simplexml($simpleXml);
            $domXsl = new DOMDocument('1.1', 'UTF-8');
            $domXsl->load($xsltPath, LIBXML_NONET);
            $proc = new XSLTProcessor();
            $proc->setSecurityPrefs(XSL_SECPREF_CREATE_DIRECTORY | XSL_SECPREF_WRITE_FILE | XSL_SECPREF_READ_NETWORK | XSL_SECPREF_WRITE_NETWORK);
            $proc->importStyleSheet($domXsl);
            $proc->setParameter('', $params);
            return $proc->transformToDoc($domXml) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Append the content text to a resource.
     *
     * A check is done to avoid to duplicate content.
     */
    protected function storeContentInProperty(AbstractResourceEntityRepresentation $resource, ?string $textContent): void
    {
        if ($textContent === null || $textContent === '') {
            return;
        }

        $contentValue = [
            'type' => 'literal',
            'property_id' => $this->propertyId,
            '@value' => $textContent ,
            '@language' => $this->language,
        ];

        $existingValues = $resource->value($this->propertyTerm, ['all' => true]);
        foreach ($existingValues as $v) {
            if ($v->value() === $contentValue['@value']) {
                return;
            }
        }

        // TODO Check it is working only with job, not the manual edition.
        // With append, there is no need to pass all property values.
        $resourceJson = [$this->propertyTerm => [$contentValue]];

        $this->api->update(
            $resource->resourceName(),
            $resource->id(),
            $resourceJson,
            [],
            ['isPartial' => true, 'collectionAction' => 'append']
        );
    }

    /**
     * Move a media at the last position of the item.
     *
     * @see \CSVImport\Job\Import::reorderMedias()
     *
     * @todo Move this process in the core.
     */
    protected function reorderMediasAndSetType(MediaRepresentation $media): void
    {
        // Note: the position is not available in representation.

        $entityManager = $this->entityManager;
        $mediaRepository = $entityManager->getRepository(\Omeka\Entity\Media::class);
        $medias = $mediaRepository->findBy(['item' => $media->item()->id()]);
        if (count($medias) <= 1) {
            return;
        }

        $lastMedia = null;
        $lastMediaId = (int) $media->id();
        $key = 0;
        foreach ($medias as $itemMedia) {
            $itemMediaId = (int) $itemMedia->getId();
            if ($itemMediaId !== $lastMediaId) {
                $itemMedia->setPosition(++$key);
            } else {
                $lastMedia = $itemMedia;
            }
        }

        if (!$lastMedia) {
            return;
        }

        $lastMedia->setPosition(++$key);

        $lastMedia->setMediaType($this->targetMediaType);

        // Flush one time to use a transaction and to avoid a duplicate issue
        // with the index item_id/position.
        $entityManager->flush();
    }

    /**
     * Save a temp file into the files/temp directory.
     *
     * @see \DerivativeMedia\Module::makeTempFileDownloadable()
     * @see \Ebook\Mvc\Controller\Plugin\Ebook::saveFile()
     * @see \IiifSearch\Job\ExtractOcr::makeTempFileDownloadable()
     */
    protected function makeTempFileDownloadable(TempFile $tempFile, string $base = ''): ?array
    {
        $baseDestination = '/temp';
        $destinationDir = $this->basePath . $baseDestination . $base;
        if (!$this->checkDestinationDir($destinationDir)) {
            return null;
        }

        $source = $tempFile->getTempPath();

        // Find a unique meaningful filename instead of a hash.
        $name = date('Ymd_His');
        $i = 0;
        do {
            $filename = $name . ($i ? '-' . $i : '') . '.' . $this->targetExtension;
            $destination = $destinationDir . '/' . $filename;
            if (!file_exists($destination)) {
                $result = @copy($source, $destination);
                if (!$result) {
                    $this->logger->err(new Message(
                        'File cannot be saved in temporary directory "%1$s" (temp file: "%2$s")', // @translate
                        $destination, $source
                    ));
                    return null;
                }
                $storageId = $base . $name . ($i ? '-' . $i : '');
                break;
            }
        } while (++$i);

        return [
            'filepath' => $destination,
            'filename' => $filename,
            'url' => $this->baseUri . $baseDestination . $base . '/' . $filename,
            'url_file' => $baseDestination . $base . '/' . $filename,
            'storageId' => $storageId,
        ];
    }

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path.
     * @return string|null
     */
    protected function checkDestinationDir(string $dirPath): ?string
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writeable($dirPath)) {
                $this->logger->err(new Message(
                    'The directory "%s" is not writeable.', // @translate
                    $dirPath
                ));
                return null;
            }
            return $dirPath;
        }

        $result = @mkdir($dirPath, 0775, true);
        if (!$result) {
            $this->logger->err(new Message(
                'The directory "%1$s" is not writeable: %2$s.', // @translate
                $dirPath, error_get_last()['message'] ?? 'unknown error'
            ));
            return null;
        }
        return $dirPath;
    }

    /**
     * Create a list of doctrine expressions for a range.
     *
     * @param string $column
     * @param array|string $ids
     */
    protected function exprRange(string $column, $ids): array
    {
        $ranges = $this->rangeToArray($ids);
        if (empty($ranges)) {
            return [];
        }

        $conditions = [];

        foreach ($ranges as $range) {
            if (strpos($range, '-') === false) {
                $conditions[] = $column . ' = ' . (int) $range;
            } else {
                [$from, $to] = explode('-', $range);
                $from = strlen($from) ? (int) $from : null;
                $to = strlen($to) ? (int) $to : null;
                if ($from && $to) {
                    $conditions[] = "`$column` >= $from AND `$column` <= $to";
                } elseif ($from) {
                    $conditions[] = "`$column` >= $from";
                } elseif ($to) {
                    $conditions[] = "`$column` <= $to";
                }
            }
        }

        return $conditions;
    }

    /**
     * Clean a list of ranges of ids.
     *
     * @param string|array $ids
     */
    protected function rangeToArray($ids): array
    {
        $clean = function ($str): string {
            $str = preg_replace('/[^0-9-]/', ' ', (string) $str);
            $str = preg_replace('/\s+/', ' ', $str);
            return trim($str);
        };

        $ids = is_array($ids)
            ? array_map($clean, $ids)
            : explode(' ', $clean($ids));

        // Skip empty ranges, fake ranges  and ranges with multiple "-".
        return array_values(array_filter($ids, function ($v) {
            return !empty($v) && $v !== '-' && substr_count($v, '-') <= 1;
        }));
    }

    /**
     * Normalize a string as utf8.
     *
     * @todo Check if it is working for non-latin languages.
     * Should be the same normalization in IiifSearch and ExtractOcr.
     *
     * @param string $input
     * @return string
     */
    protected function normalize($input): string
    {
        if ($this->transliterator === null) {
            $this->transliterator = extension_loaded('intl')
                ? (\Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;') ?: false)
                : false;
        }
        if ($this->transliterator) {
            return (string) $this->transliterator->transliterate((string) $input);
        }
        if (extension_loaded('iconv')) {
            return (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $input);
        }
        return (string) $input;
    }

    protected function listMediaImagesData(ItemRepresentation $item): array
    {
        $imageSizes = [];

        foreach ($item->media() as $media) {
            $mediaId = $media->id();
            $mediaType = $media->mediaType();
            if (strtok((string) $mediaType, '/') === 'image') {
                // TODO The images sizes may be stored by xml files too, so skip size retrieving once the matching between images and text is done by page.
                $mediaData = $media->mediaData();
                // Iiif info stored by Omeka.
                if (isset($mediaData['width'])) {
                    $imageSizes[] = [
                        'id' => $mediaId,
                        'width' => $mediaData['width'],
                        'height' => $mediaData['height'],
                        'source' => $media->source(),
                    ];
                }
                // Info stored by Iiif Server.
                elseif (isset($mediaData['dimensions']['original']['width'])) {
                    $imageSizes[] = [
                        'id' => $mediaId,
                        'width' => $mediaData['dimensions']['original']['width'],
                        'height' => $mediaData['dimensions']['original']['height'],
                        'source' => $media->source(),
                    ];
                } elseif ($media->hasOriginal() && strtok($mediaType, '/') === 'image') {
                    $size = ['id' => $mediaId];
                    $size += $this->imageSizeLocal($media);
                    $size['source'] = $media->source();
                    $imageSizes[] = $size;
                }
            }
        }

        return $imageSizes;
    }

    protected function imageSizeLocal(MediaRepresentation $media): array
    {
        // Some media types don't save the file locally.
        $filepath = ($filename = $media->filename())
            ? $this->basePath . '/original/' . $filename
            : $media->originalUrl();
        $size = @getimagesize($filepath);
        // EXIF orientations 5-8 indicate a 90° or 270°
        // rotation, so width and height must be swapped.
        if ($size) {
            $exif = @exif_read_data($filepath);
            if ($exif && !empty($exif['Orientation']) && $exif['Orientation'] >= 5) {
                [$size[0], $size[1]] = [$size[1], $size[0]];
            }
        }
        return $size
            ? ['width' => $size[0], 'height' => $size[1]]
            : ['width' => 0, 'height' => 0];
    }
}
