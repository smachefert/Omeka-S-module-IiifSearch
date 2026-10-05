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
     * @var string Empty (off), 'update' (replace source file) or 'new'
     *             (create a new media/digital object).
     */
    protected $createPdfTextLayerMode = '';

    /**
     * @var bool Whether multi-PDF items should be concatenated before re-OCR.
     */
    protected $concatMonopagePdf = false;

    /**
     * @var bool Whether img2pdf is on PATH (image-only items branch).
     */
    protected $img2pdfAvailable = false;

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
     * @var \IiifSearch\View\Helper\ItemFileResources|null
     */
    protected $itemFileResources;

    /**
     * Target kind for the new resource created for the current format: 'media'
     * (default) or 'digital_object'.
     *
     * @var string
     */
    protected $saveAs = 'media';

    /**
     * @var array
     */
    protected $dataPdf;

    /**
     * @var array
     */
    protected $store = [
        'item' => false,
        'pdf' => false,
        'extracted' => false,
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
        $this->itemFileResources = $helpers->has('itemFileResources')
            ? $helpers->get('itemFileResources')
            : null;
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

        // Prepare and reorder types from the per-format destination settings.
        // Each format names its destinations (file, media, digital_object);
        // here we pivot those settings back into the three lists the rest of
        // the job already consumes.
        $formatToSetting = [
            self::FORMAT_TSV => 'iiifsearch_extract_dests_tsv',
            self::FORMAT_TSV_BY_WORD => 'iiifsearch_extract_dests_tsv_by_word',
            self::FORMAT_ALTO => 'iiifsearch_extract_dests_alto',
            self::FORMAT_PDF2XML => 'iiifsearch_extract_dests_pdf2xml',
        ];
        $targetTypesFiles = [];
        $targetTypesMedia = [];
        $targetTypesDigitalObject = [];
        foreach ($formatToSetting as $format => $key) {
            $dests = (array) ($settings->get($key) ?: []);
            $dests = array_values(array_intersect($dests, ['file', 'media', 'digital_object']));
            if (in_array('file', $dests, true)) {
                $targetTypesFiles[] = $format;
            }
            if (in_array('media', $dests, true)) {
                $targetTypesMedia[] = $format;
            }
            if (in_array('digital_object', $dests, true)) {
                $targetTypesDigitalObject[] = $format;
            }
        }
        $targetTypesFiles = array_values(array_intersect(array_keys($formats), $targetTypesFiles));
        $targetTypesMedia = array_values(array_intersect(array_keys($formats), $targetTypesMedia));
        $targetTypesDigitalObject = array_values(array_intersect(array_keys($formats), $targetTypesDigitalObject));
        $doModuleActive = (bool) $services->get('Omeka\ModuleManager')->getModule('DigitalObject');
        if ($targetTypesDigitalObject && !$doModuleActive) {
            $this->logger->warn(
                'A "digital_object" destination is configured but the DigitalObject module is not active; those formats will be created as media instead.' // @translate
            );
            $targetTypesMedia = array_values(array_unique(array_merge($targetTypesMedia, $targetTypesDigitalObject)));
            $targetTypesDigitalObject = [];
        }
        $targetContentStore = $settings->get('iiifsearch_extract_content_store') ?: [];
        $targetContentStore = array_intersect($targetContentStore, ['item', 'pdf', 'extracted']);
        if (!count($targetTypesFiles) && !count($targetTypesMedia) && !count($targetTypesDigitalObject) && !count($targetContentStore)) {
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
        // Optional Omeka query string (from form Omeka\Form\Element\Query): it
        // narrows the item set to the API result. Combined with item_ids when
        // both are present.
        $query = (string) $this->getArg('query');
        $queryItemIds = null;
        if ($query !== '') {
            $parsed = [];
            parse_str($query, $parsed);
            if ($parsed) {
                $queryItemIds = $this->api
                    ->search('items', $parsed, ['initialize' => false, 'returnScalar' => 'id'])
                    ->getContent();
                $queryItemIds = array_map('intval', $queryItemIds);
            }
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
                    $this->store['pdf'] = in_array('pdf', $contentStore);
                    $this->store['extracted'] = in_array('extracted', $contentStore);
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

        // Optional PDF text-layer rebuild. Phase 1 covered items that already
        // carry a PDF. Phase 2 extends the feature to image-only items: img2pdf
        // bundles the images into a single multi-page PDF which is then run
        // through ocrmypdf. The presence of img2pdf is detected once and the
        // image-only branch is silently disabled when missing.
        $createPdfMode = (string) $settings->get('iiifsearch_extract_create_pdf_text_layer', '');
        $this->createPdfTextLayerMode = in_array($createPdfMode, ['update', 'new'], true)
            ? $createPdfMode
            : '';
        $this->concatMonopagePdf = (bool) $settings->get('iiifsearch_extract_concat_monopage_pdf', false);
        $this->img2pdfAvailable = false;
        if ($this->createPdfTextLayerMode !== '') {
            if ((int) shell_exec('hash ocrmypdf 2>&- || echo 1')) {
                $this->createPdfTextLayerMode = '';
                $this->logger->info(
                    'Option "create pdf with text layer" is enabled but ocrmypdf is not available; the PDF text-layer rebuild is skipped.' // @translate
                );
            } else {
                if ($this->concatMonopagePdf && (int) shell_exec('hash gs 2>&- || echo 1')) {
                    $this->concatMonopagePdf = false;
                    $this->logger->info(
                        'Option "concat mono-page PDFs" is enabled but ghostscript (gs) is not available; multi-PDF items will be skipped by the text-layer rebuild.' // @translate
                    );
                }
                $this->img2pdfAvailable = !(int) shell_exec('hash img2pdf 2>&- || echo 1');
                if (!$this->img2pdfAvailable) {
                    $this->logger->info(
                        'Image-only items will be skipped by the PDF text-layer rebuild: img2pdf is not available.' // @translate
                    );
                }
            }
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
        // image media (for tesseract-based ALTO extraction). When the
        // DigitalObject module is active, union with items linked to a DO
        // matching the same criteria so DO-only OCR sources are not missed.
        $doModuleActive = (bool) $services->get('Omeka\ModuleManager')->getModule('DigitalObject');
        $sql = <<<'SQL'
            SELECT DISTINCT `item_id`
            FROM `media`
            WHERE (`media_type` IN (:pdf_media_type) AND `extension` = :pdf_extension)
                OR `media_type` = :alto_media_type
                OR `media_type` LIKE 'image/%'
            SQL;
        if ($doModuleActive) {
            $sql .= <<<'SQL'

                UNION
                SELECT DISTINCT `v`.`resource_id` AS `item_id`
                FROM `value` `v`
                INNER JOIN `digital_object` `d` ON `v`.`value_resource_id` = `d`.`id`
                WHERE (`d`.`media_type` IN (:pdf_media_type) AND `d`.`extension` = :pdf_extension)
                    OR `d`.`media_type` = :alto_media_type
                    OR `d`.`media_type` LIKE 'image/%'
                SQL;
        }
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
                // Apply the range filter to both branches of the union by
                // wrapping the whole statement in a subquery.
                $sql = 'SELECT `item_id` FROM (' . $sql . ') AS `t`'
                    . ' WHERE ((' . implode(') OR (', $range) . '))';
            }
        }
        $sql .= ' ORDER BY `item_id` ASC';
        $targetItemIds = $connection->executeQuery($sql, $bind, $types)->fetchFirstColumn();
        $targetItemIds = array_map('intval', $targetItemIds);
        if ($queryItemIds !== null) {
            $targetItemIds = array_values(array_intersect($targetItemIds, $queryItemIds));
        }

        // Apply per-item OCR policy (include / exclude). Resolved in bulk
        // through the search API instead of one call per item id so the filter
        // scales to large libraries.
        $api = $services->get('Omeka\ApiManager');
        $include = (string) $settings->get('iiifsearch_extract_query_include', '');
        if ($include !== '' && $targetItemIds) {
            $q = [];
            parse_str($include, $q);
            if ($q) {
                $allowed = $api->search('items', $q, ['initialize' => false, 'returnScalar' => 'id'])->getContent();
                $allowed = array_map('intval', is_array($allowed) ? array_values($allowed) : []);
                $before = count($targetItemIds);
                $targetItemIds = array_values(array_intersect($targetItemIds, $allowed));
                $this->logger->info(new Message(
                    'OCR include filter: %1$d/%2$d items kept.', // @translate
                    count($targetItemIds), $before
                ));
            }
        }
        $exclude = (string) $settings->get('iiifsearch_extract_query_exclude', '');
        if ($exclude !== '' && $targetItemIds) {
            $q = [];
            parse_str($exclude, $q);
            if ($q) {
                $excluded = $api->search('items', $q, ['initialize' => false, 'returnScalar' => 'id'])->getContent();
                $excluded = array_map('intval', is_array($excluded) ? array_values($excluded) : []);
                $before = count($targetItemIds);
                $targetItemIds = array_values(array_diff($targetItemIds, $excluded));
                $this->logger->info(new Message(
                    'OCR exclude filter: %1$d items removed (%2$d remaining).', // @translate
                    $before - count($targetItemIds), count($targetItemIds)
                ));
            }
        }

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
            $create[] = ['format' => $targetType, 'create_media' => false, 'target_kind' => 'media'];
        }
        foreach ($targetTypesMedia as $targetType) {
            $create[] = ['format' => $targetType, 'create_media' => true, 'target_kind' => 'media'];
        }
        foreach ($targetTypesDigitalObject as $targetType) {
            $create[] = ['format' => $targetType, 'create_media' => true, 'target_kind' => 'digital_object'];
        }
        // Allow to store extracted text without creating files or media.
        if (empty($create) && $contentStore) {
            $create[] = ['format' => self::FORMAT_PDF2XML, 'create_media' => false, 'content_only' => true, 'target_kind' => 'media'];
        }
        foreach ($create as $key => $targetData) {
            $this->createMedia = $targetData['create_media'];
            $this->contentOnly = !empty($targetData['content_only']);
            $this->targetFormat = $targetData['format'];
            $this->saveAs = $targetData['target_kind'] ?? 'media';
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
                    'pdf' => false,
                    'extracted' => false,
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
            if (!$this->entityManager->isOpen()) {
                $this->logger->err(new Message(
                    'EntityManager closed before item #%1$d, aborting.', // @translate
                    $itemId
                ));
                return;
            }
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

            // Optional: rebuild the source PDF with an embedded text layer
            // (ocrmypdf). When the original PDF is replaced in place the
            // representation we hold becomes stale, so refresh it. Image- only
            // items go through a separate branch that bundles the images via
            // img2pdf before invoking ocrmypdf.
            if ($this->createPdfTextLayerMode !== '') {
                if ($pdfMedias) {
                    $rebuilt = $this->maybeBuildPdfWithTextLayer($item, $pdfMedias);
                } elseif ($this->img2pdfAvailable) {
                    $rebuilt = $this->maybeBuildPdfFromImages($item);
                } else {
                    $rebuilt = 'skipped';
                }
                if ($rebuilt === 'created') {
                    try {
                        $item = $this->api->read('items', ['id' => $itemId])->getContent();
                        $pdfMedias = $this->findPdfMedias($item);
                        $pdfMedia = $pdfMedias[0] ?? null;
                    } catch (\Throwable $e) {
                        // Continue with stale references; downstream code is
                        // file-path based and will still work.
                    }
                } elseif ($rebuilt === 'updated') {
                    try {
                        $item = $this->api->read('items', ['id' => $itemId])->getContent();
                        $pdfMedias = $this->findPdfMedias($item);
                        $pdfMedia = $pdfMedias[0] ?? null;
                    } catch (\Throwable $e) {
                        // Continue with stale references; downstream code is
                        // file-path based and will still work.
                    }
                }
            }

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
                if ($searchExistingOcrMedia
                    && $this->canDeleteOcrMedia($searchExistingOcrMedia)
                ) {
                    $this->deleteOcrMediaSafely($searchExistingOcrMedia);
                    $this->logger->info(new Message(
                        'The existing %1$s was removed for item #%2$d (media #%3$d).', // @translate
                        $this->targetExtension, $item->id(), $searchExistingOcrMedia->id()
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
                $existingAltoMedias = $this->findOrMaterializeAltoSources($item);
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
                // Even though no new alto is produced, the configured content
                // stores must still receive the plain text rendered from the
                // available alto sources; only the 'extracted' target is
                // inapplicable since no new resource is created here.
                if ($this->propertyId
                    && ($this->store['item'] || $this->store['pdf'])
                ) {
                    $textContent = $this->extractTextFromAltoSources($altoSources);
                    if ($textContent !== null && $textContent !== '') {
                        if ($this->store['pdf'] && $pdfMedia) {
                            $this->storeContentInProperty($pdfMedia, $textContent);
                        }
                        if ($this->store['item']) {
                            $this->storeContentInProperty($item, $textContent);
                        }
                    }
                }
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
                    if ($this->store['pdf'] && $pdfMedia) {
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

            // Clear the Doctrine identity map to prevent memory growth on large
            // batches. Property scalars are cached above.
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
     * Concatenate the plain text of all alto sources of an item. Sources are
     * either MediaRepresentation/AbstractResourceEntityRepresentation
     * (filename relative to the original directory) or absolute file paths for
     * generated alto files. Returns null when nothing readable.
     *
     * @param array<mixed> $altoSources
     */
    protected function extractTextFromAltoSources(array $altoSources): ?string
    {
        $parts = [];
        foreach ($altoSources as $source) {
            if (is_string($source)) {
                $path = $source;
            } else {
                $filename = method_exists($source, 'filename')
                    ? $source->filename()
                    : null;
                if (!$filename) {
                    continue;
                }
                $path = $this->basePath . '/original/' . $filename;
            }
            if (!is_readable($path)) {
                continue;
            }
            $content = (string) @file_get_contents($path);
            if ($content === '') {
                continue;
            }
            $text = trim($this->extractTextFromAlto($content));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return $parts ? implode("\n", $parts) : null;
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
        $targetDir = dirname($filepath);

        // Auto-création du dossier cible si absent (cause #1 d'échec historique
        // : dossier files/iiif-search/ non créé à l'installation).
        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                $err = error_get_last();
                $this->logger->err(new Message(
                    'cannot mkdir "%1$s" (%2$s). dirname stat: %3$s. disk free: %4$s.', // @translate
                    $targetDir,
                    $err['message'] ?? 'unknown',
                    $this->describePathPerms($targetDir),
                    $this->describeDiskFree($targetDir)
                ));
                return false;
            }
            @chmod($targetDir, 0775);
            $this->logger->info(new Message(
                'created missing target dir "%1$s".', // @translate
                $targetDir
            ));
        }

        if (!is_writable($targetDir)) {
            $this->logger->err(new Message(
                'target dir "%1$s" not writable. %2$s disk free: %3$s.', // @translate
                $targetDir,
                $this->describePathPerms($targetDir),
                $this->describeDiskFree($targetDir)
            ));
            return false;
        }

        if (!is_readable($tempFilepath) || !file_exists($tempFilepath)) {
            $this->logger->err(new Message(
                'temp file "%1$s" missing or unreadable.', // @translate
                $tempFilepath
            ));
            return false;
        }

        // copy() PHP émet un Warning sans exception ; capter via @ + diag.
        $ok = @copy($tempFilepath, $filepath);
        if (!$ok) {
            $err = error_get_last();
            $size = @filesize($tempFilepath);
            $this->logger->err(new Message(
                'copy("%1$s"→"%2$s") FAILED. err=%3$s. src size=%4$s. dst dir: %5$s. disk free: %6$s.', // @translate
                $tempFilepath,
                $filepath,
                $err['message'] ?? 'unknown',
                $size === false ? 'unknown' : (string) $size,
                $this->describePathPerms($targetDir),
                $this->describeDiskFree($targetDir)
            ));
            return false;
        }

        @chmod($filepath, 0664);
        if ($this->targetFormat === self::FORMAT_ALTO) {
            (new \IiifSearch\Stdlib\ToAltoConverter())->stampAltoFile($filepath);
        }
        return true;
    }

    /**
     * Retourne owner:group mode pour un chemin existant.
     */
    protected function describePathPerms(string $path): string
    {
        if (!file_exists($path)) {
            return sprintf('%s does not exist', $path);
        }
        $stat = @stat($path);
        if ($stat === false) {
            return sprintf('%s: stat() failed', $path);
        }
        $owner = function_exists('posix_getpwuid')
            ? (posix_getpwuid($stat['uid'])['name'] ?? (string) $stat['uid'])
            : (string) $stat['uid'];
        $group = function_exists('posix_getgrgid')
            ? (posix_getgrgid($stat['gid'])['name'] ?? (string) $stat['gid'])
            : (string) $stat['gid'];
        return sprintf('%s = %s:%s %04o', $path, $owner, $group, $stat['mode'] & 0777);
    }

    /**
     * Retourne l'espace libre human-readable pour un chemin.
     */
    protected function describeDiskFree(string $path): string
    {
        $checkPath = $path;
        while (!file_exists($checkPath) && $checkPath !== '/' && $checkPath !== '') {
            $checkPath = dirname($checkPath);
        }
        $free = @disk_free_space($checkPath);
        if ($free === false) {
            return 'unknown';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($free >= 1024 && $i < count($units) - 1) {
            $free /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $free, $units[$i]);
    }

    /**
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation|null
     */
    protected function storeFileInMedia(
        TempFile $tempFile,
        ?string $textContent,
        ItemRepresentation $item,
        ?MediaRepresentation $pdfMedia = null
    ) {
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

        $storeText = $this->propertyId && strlen((string) $textContent)
            && $this->store['extracted'];
        if ($storeText) {
            $data[$this->propertyTerm][] = [
                'type' => 'literal',
                'property_id' => $this->propertyId,
                '@value' => $textContent,
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
            if ($this->saveAs === 'digital_object') {
                $media = $this->createAsDigitalObject($data, $storeFile, $item);
            } else {
                $media = $this->api->create('media', $data)->getContent();
            }
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

        if ($this->saveAs !== 'digital_object') {
            // Move the xml file as the last media to avoid thumbnails issues.
            $this->reorderMediasAndSetType($media);
        }
        return $media;
    }

    /**
     * Ingest the extracted file as a DigitalObject and link it to the item via
     * the first property listed in DigitalObject's
     * digitalobject_inline_properties config so digitalObjectInline picks it up
     * downstream.
     *
     * @return \DigitalObject\Api\Representation\DigitalObjectRepresentation|null
     */
    protected function createAsDigitalObject(array $data, array $storeFile, ItemRepresentation $item)
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $linkProperties = $config['digitalobject']['config']['digitalobject_inline_properties']
            ?? ['dcterms:hasFormat'];
        $linkTerm = (string) reset($linkProperties);
        if ($linkTerm === '') {
            $this->logger->err(
                'No digitalobject_inline_properties configured; cannot link the new digital object to the item.' // @translate
            );
            return null;
        }
        $easyMeta = $services->get('Common\EasyMeta');
        $linkPropertyId = $easyMeta->propertyId($linkTerm);
        if (!$linkPropertyId) {
            $this->logger->err(new Message(
                'Property "%s" (digitalobject_inline_properties) is unknown; cannot link the new digital object.', // @translate
                $linkTerm
            ));
            return null;
        }
        $doData = [
            'o:ingester' => 'sideload',
            'ingest_filename' => $storeFile['filepath'],
            'o:source' => $data['o:source'] ?? null,
            'o:lang' => $data['o:lang'] ?? null,
            'o:media_type' => $data['o:media_type'] ?? null,
        ];
        if (!empty($data[$this->propertyTerm])) {
            $doData[$this->propertyTerm] = $data[$this->propertyTerm];
        }
        $do = $this->api->create('digital_objects', $doData)->getContent();
        if (!$do) {
            return null;
        }
        $existing = $item->value($linkTerm, ['all' => true, 'default' => []]);
        $values = [];
        foreach ($existing as $value) {
            $values[] = $value->jsonSerialize();
        }
        $values[] = [
            'type' => 'resource:digitalobject',
            'property_id' => $linkPropertyId,
            'value_resource_id' => $do->id(),
        ];
        $this->api->update(
            'items',
            $item->id(),
            [$linkTerm => $values],
            [],
            ['isPartial' => true, 'collectionAction' => 'replace']
        );
        return $do;
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
            (new \IiifSearch\Stdlib\ToAltoConverter())->stampAltoFile($tempFilepath);
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
     * @return \IiifSearch\Stdlib\FileResource[]
     */
    protected function collectItemFileResources(ItemRepresentation $item): array
    {
        if ($this->itemFileResources) {
            return ($this->itemFileResources)($item);
        }
        $out = [];
        foreach ($item->media() as $media) {
            $out[] = new \IiifSearch\Stdlib\FileResource($media, $item);
        }
        return $out;
    }

    /**
     * Find every attached image resource on the item (media or DigitalObject),
     * in collection order.
     *
     * @return array<MediaRepresentation|\Omeka\Api\Representation\AbstractResourceEntityRepresentation>
     */
    protected function findImageMedias(ItemRepresentation $item): array
    {
        $images = [];
        foreach ($this->collectItemFileResources($item) as $resource) {
            $mediaType = (string) $resource->mediaType();
            if (strtok($mediaType, '/') === 'image' && $resource->hasOriginal()) {
                $images[] = $resource->inner();
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
            // Tag the file as module-produced so a future delete pass can
            // distinguish it from a native alto upload.
            (new \IiifSearch\Stdlib\ToAltoConverter())->stampAltoFile($altoPath);
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
        foreach ($this->collectItemFileResources($item) as $resource) {
            $mediaType = (string) $resource->mediaType();
            if ((string) $resource->extension() === 'pdf'
                && ($mediaType === 'application/pdf' || $mediaType === 'text/pdf')
            ) {
                $pdfMedias[] = $resource->inner();
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
        foreach ($this->collectItemFileResources($item) as $resource) {
            if ($resource->mediaType() === self::FORMAT_ALTO) {
                $medias[] = $resource->inner();
                continue;
            }
            if ($classifier->isXmlLikeMedia($resource)
                && $classifier->classifyMedia($resource, $this->basePath) === XmlMediaClassifier::TYPE_ALTO
            ) {
                $medias[] = $resource->inner();
            }
        }
        return $medias;
    }

    /**
     * Like findExistingAltoMedias but also materializes hOCR, pdf2xml and TEI
     * sources to ALTO files under files/alto/, then exposes them as filepath
     * sources. The returned array is a heterogeneous list of
     * MediaRepresentation (native ALTO) and string filepaths (materialized ALTO
     * derived from another dialect).
     *
     * @return array<MediaRepresentation|string>
     */
    protected function findOrMaterializeAltoSources(ItemRepresentation $item): array
    {
        $classifier = $this->getXmlMediaClassifier();
        $sources = [];
        $converter = null;
        $altoDir = $this->basePath . '/alto';
        $altoDirReady = null;
        foreach ($this->collectItemFileResources($item) as $resource) {
            if ($resource->mediaType() === self::FORMAT_ALTO) {
                $sources[] = $resource->inner();
                continue;
            }
            if (!$classifier->isXmlLikeMedia($resource)) {
                continue;
            }
            $cls = $classifier->classifyMedia($resource, $this->basePath);
            if ($cls === XmlMediaClassifier::TYPE_ALTO) {
                $sources[] = $resource->inner();
                continue;
            }
            if (!in_array($cls, [XmlMediaClassifier::TYPE_HOCR, XmlMediaClassifier::TYPE_PDF2XML, XmlMediaClassifier::TYPE_TEI], true)) {
                continue;
            }
            $filename = $resource->filename();
            if (!$filename) {
                continue;
            }
            if ($altoDirReady === null) {
                $altoDirReady = (bool) $this->checkDestinationDir($altoDir);
            }
            if (!$altoDirReady) {
                continue;
            }
            $sourceFilepath = $this->basePath . '/original/' . $filename;
            $sourceBase = pathinfo((string) $resource->source(), PATHINFO_FILENAME) ?: 'media-' . $resource->id();
            $targetPath = sprintf('%s/%d-%s.alto.xml', $altoDir, $item->id(), $sourceBase);
            if ($converter === null) {
                $converter = new \IiifSearch\Stdlib\ToAltoConverter();
            }
            if ($converter->convert($sourceFilepath, $cls, $targetPath)) {
                $sources[] = $targetPath;
                $this->logger->info(new Message(
                    'Item #%1$d: media #%2$d (%3$s) materialized as %4$s.', // @translate
                    $item->id(), $resource->id(), $cls, basename($targetPath)
                ));
            } else {
                $this->logger->warn(new Message(
                    'Item #%1$d: failed to materialize media #%2$d (%3$s) as alto.', // @translate
                    $item->id(), $resource->id(), $cls
                ));
            }
        }
        return $sources;
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
            $pairer = new PagePairer($this->basePath, $this->itemFileResources);
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
                // The xpath namespaces are not inherited by the sub-nodes.
                if (!empty($namespaces[''])) {
                    $page->registerXPathNamespace('alto', $namespaces['']);
                }
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
                // Pas de contenu et pas de fichier vide demandé : signaler
                // explicitement l'absence de fichier au caller pour éviter que
                // storeFileLocally() tente de lire un fichier supprimé.
                $this->logger->notice(new Message(
                    'Item #%1$d: no OCR rows extracted for %2$s, file not created.', // @translate
                    $item->id(), $this->targetExtension
                ));
                return false;
            }
            return $closed;
        }

        if (!$resultTsv && !$this->createEmptyFile) {
            // Idem pour by-word.tsv : aucun mot extrait => pas de fichier
            // produit.
            $this->logger->notice(new Message(
                'Item #%1$d: no OCR words extracted for %2$s, file not created.', // @translate
                $item->id(), $this->targetExtension
            ));
            return false;
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

        // Run PagePairer on the pdf medias so per-page scaling uses the paired
        // image when several pdfs are present on the item. Each pdf contributes
        // one PageSource per intrinsic page, mirroring the alto expansion
        // logic. Without intrinsic page count from pdfinfo we start with one
        // source per file; the global page counter below matches what the
        // pairer sees only for single-page pdfs, so the pairer's contribution
        // stays limited to file-level routing.
        $pdfPairing = null;
        if (count($pdfFilepaths) > 1) {
            $pageSources = [];
            $i = 0;
            foreach ($pdfFilepaths as $pdfFilepath) {
                ++$i;
                $pageSources[] = new PageSource(
                    $i,
                    null,
                    $pdfFilepath,
                    basename($pdfFilepath),
                    'pdf'
                );
            }
            $pairer = new PagePairer($this->basePath, $this->itemFileResources);
            $pdfPairing = $pairer->pair($item, $pageSources, $this->pairingMode);
            $this->logger->info(new Message(
                'Item #%1$d: pdf pairing method %2$s, coverage %3$s.', // @translate
                $item->id(),
                $pdfPairing->method,
                number_format($pdfPairing->coverage, 2)
            ));
        }

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

        // Precompute, per pdf, the anchor image index (0-based into
        // $listMediaImages) the pairer would assign to its first page.
        $pdfAnchors = [];
        if ($pdfPairing && $pdfPairing->method !== PairingResult::METHOD_SEQUENTIAL) {
            $imagePositionsById = [];
            foreach ($listMediaImages as $idx => $entry) {
                if (!empty($entry['id'])) {
                    $imagePositionsById[(int) $entry['id']] = $idx;
                }
            }
            $i = 0;
            foreach ($pdfFilepaths as $pdfFilepath) {
                ++$i;
                $img = $pdfPairing->imageForPage($i);
                if ($img && isset($imagePositionsById[(int) $img->id()])) {
                    $pdfAnchors[$i - 1] = $imagePositionsById[(int) $img->id()];
                }
            }
        }

        $pdfIndex = -1;
        foreach ($pdfFilepaths as $pdfFilepath) {
            ++$pdfIndex;
            $pageWithinPdf = 0;
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
                ++$pageWithinPdf;

                $pageAttribute = $xmlPage->attributes();
                $pageWidth = (float) $pageAttribute->width;
                $pageHeigth = (float) $pageAttribute->height;
                if (!$pageWidth || !$pageHeigth) {
                    continue;
                }

                // Pairer-driven anchor: pdf's first page goes to the paired
                // image; subsequent pages within the same pdf go to the
                // following image medias.
                $mediaImage = null;
                if (isset($pdfAnchors[$pdfIndex])) {
                    $imgIdx = $pdfAnchors[$pdfIndex] + $pageWithinPdf - 1;
                    $mediaImage = $listMediaImages[$imgIdx] ?? null;
                }
                if (!$mediaImage) {
                    $mediaImage = $listMediaImages[$indexXmlPage - 1] ?? null;
                }
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
    /**
     * Guiding rule: only delete files the module itself created. A user upload
     * is always preserved, whatever its mime type.
     *
     *  - alto: deletable iff the file carries the module's provenance marker
     *    (see ToAltoConverter::stampProvenance()). No filename fallback: a
     *    user-uploaded alto could share any naming pattern.
     *  - pdf2xml: always deletable. The format is never a legitimate user
     *    upload; it has only ever been attached by past versions of the module,
     *    with several naming conventions (legacy "{base}.xml" advertised as
     *    text/xml or application/xml because the precise mime was not defined
     *    yet, as well as the newer "{base}.{itemId}.pdf2xml.xml" with the
     *    canonical mime). Generic XML medias are content-sniffed via
     *    XmlMediaClassifier to confirm a pdf2xml root.
     *  - tsv / tsv;by-word: always deletable for the same reason — the
     *    module is the sole producer of these mime types.
     *  - anything else (image, pdf, audio/video, native tei/mets/hocr, etc.):
     *    never.
     */
    protected function canDeleteOcrMedia(MediaRepresentation $media): bool
    {
        $mediaType = (string) $media->mediaType();

        if ($mediaType === 'application/alto+xml') {
            $filename = (string) $media->filename();
            if ($filename === '') {
                return false;
            }
            return \IiifSearch\Stdlib\AltoProvenance::isModuleAlto(
                $this->basePath . '/original/' . $filename
            );
        }

        if (in_array($mediaType, [
            'application/vnd.pdf2xml+xml',
            'text/tab-separated-values',
            'text/tab-separated-values;by-word',
        ], true)) {
            return true;
        }

        // Legacy pdf2xml medias declared as generic text/xml or
        // application/xml: confirm via content sniffing on the root element.
        if (in_array($mediaType, ['text/xml', 'application/xml', 'application/octet-stream', ''], true)) {
            $filename = (string) $media->filename();
            if ($filename === '') {
                return false;
            }
            $cls = $this->getXmlMediaClassifier()->classifyMedia($media, $this->basePath);
            return $cls === XmlMediaClassifier::TYPE_PDF2XML;
        }

        return false;
    }

    /**
     * Delete an OCR-derived media without poisoning Doctrine when the physical
     * file is missing or unwritable. Omeka's MediaAdapter::deleteEntity()
     * delegates to Store\Local::delete() inside the same EntityManager
     * transaction; unlink() failures bubble up as RuntimeException that closes
     * the EntityManager mid-job — even when the caller catches it. To avoid
     * that, drop the entity through Doctrine directly and unlink the file
     * outside any Doctrine transaction.
     */
    protected function deleteOcrMediaSafely(MediaRepresentation $media): void
    {
        $mediaId = (int) $media->id();
        $mediaType = (string) $media->mediaType();
        $filename = (string) $media->filename();
        $originalPath = $filename !== ''
            ? $this->basePath . '/original/' . $filename
            : '';

        // Hard safety net: only OCR-derived formats are eligible for file
        // unlink. Image / audio / video / pdf medias must never have their
        // original file removed by this job. canDeleteOcrMedia() already gates
        // the call, but we re-check defensively here.
        $unlinkAllowed = in_array($mediaType, [
            'application/alto+xml',
            'application/vnd.pdf2xml+xml',
            'text/tab-separated-values',
            'text/tab-separated-values;by-word',
        ], true);

        // ORM-level delete (api->delete or EntityManager::remove() + flush)
        // cannot be used here: Omeka core registers a Doctrine event listener
        // on Media that calls Store\Local::delete() inside the flush
        // transaction. When unlink() fails, the RuntimeException propagates
        // through flush() and Doctrine closes the EntityManager for the rest of
        // the job. Raw DBAL bypasses the ORM event system entirely, so the row
        // is dropped without ever touching the filesystem from inside a
        // Doctrine transaction.
        $conn = $this->entityManager->getConnection();
        try {
            $affected = $conn->delete('media', ['id' => $mediaId]);
        } catch (\Throwable $e) {
            $this->logger->warn(new Message(
                'Unable to delete media row #%1$d via DBAL: %2$s', // @translate
                $mediaId, $e->getMessage()
            ));
            return;
        }
        // Drop any managed copy from the identity map so further lookups on
        // this id don't resurrect a stale instance.
        $managed = $this->entityManager->getUnitOfWork()
            ->tryGetById($mediaId, \Omeka\Entity\Media::class);
        if ($managed) {
            $this->entityManager->detach($managed);
        }

        if ($affected < 1 || !$unlinkAllowed) {
            return;
        }

        // Best-effort unlink of the original file. Any failure is logged but
        // never propagated: the canonical state is the database, and the row is
        // already gone.
        if ($originalPath !== '' && file_exists($originalPath)) {
            if (!@unlink($originalPath)) {
                $err = error_get_last();
                $this->logger->warn(new Message(
                    'Media row #%1$d deleted but file "%2$s" could not be removed: %3$s', // @translate
                    $mediaId, $originalPath, $err['message'] ?? 'unknown'
                ));
            }
        }
    }

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
     * Rebuild the source PDF with an embedded text layer via ocrmypdf, then
     * either replace the existing media/digital object file in place or attach
     * the result as a new resource.
     *
     * Returns:
     *  - 'updated' when an existing source has been overwritten;
     *  - 'created' when a new resource has been created;
     *  - 'skipped' when nothing was done.
     *
     * Out of scope (phase 1): items with no PDF (image-only). Already-
     * searchable PDFs are skipped too — no rewrite of an existing text layer.
     */
    protected function maybeBuildPdfWithTextLayer(ItemRepresentation $item, array $pdfMedias): string
    {
        if (!$pdfMedias) {
            return 'skipped';
        }
        $itemId = (int) $item->id();
        $multiPdf = count($pdfMedias) > 1;

        // Source file paths (read from /original/), and a label for logs.
        $sourcePaths = [];
        foreach ($pdfMedias as $m) {
            $filename = $m->filename();
            if (!$filename) {
                continue;
            }
            $path = $this->basePath . '/original/' . $filename;
            if (!is_readable($path)) {
                continue;
            }
            $sourcePaths[] = $path;
        }
        if (!$sourcePaths) {
            return 'skipped';
        }

        // Concat mono-page PDFs into a temp file (mode forced to "new").
        $effectiveMode = $this->createPdfTextLayerMode;
        $inputPath = $sourcePaths[0];
        $concatTemp = null;
        if ($multiPdf) {
            if (!$this->concatMonopagePdf) {
                $this->logger->info(new Message(
                    'Item #%1$d: %2$d PDFs attached; "concat mono-page PDFs" is off, skipping text-layer rebuild.', // @translate
                    $itemId, count($pdfMedias)
                ));
                return 'skipped';
            }
            $concatTemp = sys_get_temp_dir() . '/iiifsearch-concat-' . $itemId . '-' . uniqid('', true) . '.pdf';
            $cmd = 'gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile='
                . escapeshellarg($concatTemp) . ' '
                . implode(' ', array_map('escapeshellarg', $sourcePaths));
            @shell_exec($cmd);
            if (!file_exists($concatTemp) || !filesize($concatTemp)) {
                $this->logger->warn(new Message(
                    'Item #%1$d: ghostscript concatenation failed; text-layer rebuild skipped.', // @translate
                    $itemId
                ));
                @unlink($concatTemp);
                return 'skipped';
            }
            $inputPath = $concatTemp;
            $effectiveMode = 'new';
        }

        // Skip when the PDF already carries a text layer. pdftotext on the
        // input file: empty output (or whitespace only) ⇒ no text layer.
        $probeCmd = 'pdftotext ' . escapeshellarg($inputPath) . ' - 2>/dev/null';
        $probe = (string) @shell_exec($probeCmd);
        if (trim($probe) !== '') {
            $this->logger->info(new Message(
                'Item #%1$d: PDF already carries a text layer, text-layer rebuild skipped.', // @translate
                $itemId
            ));
            if ($concatTemp) {
                @unlink($concatTemp);
            }
            return 'skipped';
        }

        // ocrmypdf: produce a fresh searchable PDF in a temp path.
        $outputTemp = sys_get_temp_dir() . '/iiifsearch-ocr-' . $itemId . '-' . uniqid('', true) . '.pdf';
        $langArg = $this->ocrLanguage !== ''
            ? ' -l ' . escapeshellarg($this->ocrLanguage)
            : '';
        $cmd = 'ocrmypdf --skip-text' . $langArg . ' '
            . escapeshellarg($inputPath) . ' '
            . escapeshellarg($outputTemp) . ' 2>&1';
        $output = (string) @shell_exec($cmd);
        if ($concatTemp) {
            @unlink($concatTemp);
        }
        if (!file_exists($outputTemp) || !filesize($outputTemp)) {
            $this->logger->err(new Message(
                'Item #%1$d: ocrmypdf failed: %2$s', // @translate
                $itemId, trim($output)
            ));
            @unlink($outputTemp);
            return 'skipped';
        }

        if ($effectiveMode === 'update') {
            $ok = $this->replacePdfFileInPlace($pdfMedias[0], $outputTemp);
            @unlink($outputTemp);
            if ($ok) {
                $this->logger->info(new Message(
                    'Item #%1$d: PDF media #%2$d updated in place with a text layer.', // @translate
                    $itemId, $pdfMedias[0]->id()
                ));
                return 'updated';
            }
            return 'skipped';
        }

        // mode 'new': attach as a fresh media (or DO mirroring the source).
        $tempFile = $this->tempFileFactory->build();
        $tempPath = $tempFile->getTempPath() . '.pdf';
        @unlink($tempFile->getTempPath());
        $tempFile->setTempPath($tempPath);
        if (!@rename($outputTemp, $tempPath)) {
            @copy($outputTemp, $tempPath);
            @unlink($outputTemp);
        }

        $previousSaveAs = $this->saveAs;
        $previousFormat = $this->targetFormat;
        $previousExtension = $this->targetExtension;
        $previousMediaType = $this->targetMediaType;

        // Mirror the source kind for the new resource: a digital object if the
        // source PDF is a DO, a media otherwise.
        $sourceIsDo = method_exists($pdfMedias[0], 'resourceName')
            && $pdfMedias[0]->resourceName() === 'digital_objects';
        $this->saveAs = $sourceIsDo ? 'digital_object' : 'media';
        $this->targetFormat = 'application/pdf';
        $this->targetExtension = 'pdf';
        $this->targetMediaType = 'application/pdf';

        $created = null;
        try {
            $created = $this->storeFileInMedia($tempFile, null, $item, $pdfMedias[0]);
        } catch (\Throwable $e) {
            $this->logger->err(new Message(
                'Item #%1$d: failed to attach the rebuilt PDF: %2$s', // @translate
                $itemId, $e->getMessage()
            ));
        }

        $this->saveAs = $previousSaveAs;
        $this->targetFormat = $previousFormat;
        $this->targetExtension = $previousExtension;
        $this->targetMediaType = $previousMediaType;
        @unlink($tempPath);

        if ($created) {
            $this->logger->info(new Message(
                'Item #%1$d: rebuilt PDF attached as %2$s #%3$d.', // @translate
                $itemId, $sourceIsDo ? 'digital_object' : 'media', $created->id()
            ));
            return 'created';
        }
        return 'skipped';
    }

    /**
     * Replace the original file of a media/DO in place with a freshly OCRed
     * copy, and refresh sha256 + size via raw DBAL. Doctrine's ORM-level
     * setData() + flush() is avoided here because the storage event listener
     * can close the EntityManager on unlink failure mid-flush; file overwrites
     * have no such hazard since we never delete the file.
     */
    protected function replacePdfFileInPlace($source, string $newPdfPath): bool
    {
        $filename = $source->filename();
        if (!$filename) {
            return false;
        }
        $sourceId = (int) $source->id();
        $originalPath = $this->basePath . '/original/' . $filename;
        if (!@copy($newPdfPath, $originalPath)) {
            $this->logger->err(new Message(
                'Could not overwrite "%1$s" with the rebuilt PDF.', // @translate
                $originalPath
            ));
            return false;
        }
        $sha256 = @hash_file('sha256', $originalPath) ?: null;
        $size = @filesize($originalPath);
        $size = $size === false ? null : (int) $size;

        $table = method_exists($source, 'resourceName')
            && $source->resourceName() === 'digital_objects'
            ? 'digital_object'
            : 'media';
        try {
            $this->entityManager->getConnection()->update(
                $table,
                array_filter([
                    'sha256' => $sha256,
                    'size' => $size,
                ], static fn ($v) => $v !== null),
                ['id' => $sourceId]
            );
        } catch (\Throwable $e) {
            $this->logger->err(new Message(
                'Could not update %1$s #%2$d after rebuilding the PDF: %3$s', // @translate
                $table, $sourceId, $e->getMessage()
            ));
            return false;
        }
        return true;
    }

    /**
     * Image-only items: bundle the images into a single multi-page PDF via
     * img2pdf, then add a text layer via ocrmypdf, then attach the result as a
     * new media (or DigitalObject mirroring the source kind). The mode is
     * always 'new' here because there is no existing PDF row to overwrite in
     * place.
     *
     * Remote images (no local original file) are skipped: img2pdf needs a local
     * path. Downloading the originals on the fly is out of scope.
     */
    protected function maybeBuildPdfFromImages(ItemRepresentation $item): string
    {
        $itemId = (int) $item->id();

        // Collect local image paths in resource order, restricted to
        // file-backed images. Mixed local / remote items are processed
        // partially with a single warning.
        $imageResources = [];
        $imagePaths = [];
        $skippedRemote = 0;
        foreach ($this->collectItemFileResources($item) as $resource) {
            if (strtok((string) $resource->mediaType(), '/') !== 'image') {
                continue;
            }
            if (method_exists($resource, 'hasOriginal') && !$resource->hasOriginal()) {
                ++$skippedRemote;
                continue;
            }
            $filename = $resource->filename();
            if (!$filename) {
                continue;
            }
            $path = $this->basePath . '/original/' . $filename;
            if (!is_readable($path)) {
                continue;
            }
            $imageResources[] = $resource;
            $imagePaths[] = $path;
        }
        if (!$imagePaths) {
            if ($skippedRemote) {
                $this->logger->info(new Message(
                    'Item #%1$d: only remote images, skipping image-to-PDF rebuild.', // @translate
                    $itemId
                ));
            }
            return 'skipped';
        }
        if ($skippedRemote) {
            $this->logger->warn(new Message(
                'Item #%1$d: %2$d remote images skipped from the image-to-PDF bundle.', // @translate
                $itemId, $skippedRemote
            ));
        }

        $bundleTemp = sys_get_temp_dir() . '/iiifsearch-bundle-' . $itemId . '-' . uniqid('', true) . '.pdf';
        $cmd = 'img2pdf '
            . implode(' ', array_map('escapeshellarg', $imagePaths))
            . ' --output ' . escapeshellarg($bundleTemp) . ' 2>&1';
        $bundleOut = (string) @shell_exec($cmd);
        if (!file_exists($bundleTemp) || !filesize($bundleTemp)) {
            $this->logger->err(new Message(
                'Item #%1$d: img2pdf failed: %2$s', // @translate
                $itemId, trim($bundleOut)
            ));
            @unlink($bundleTemp);
            return 'skipped';
        }

        $outputTemp = sys_get_temp_dir() . '/iiifsearch-ocr-' . $itemId . '-' . uniqid('', true) . '.pdf';
        $langArg = $this->ocrLanguage !== ''
            ? ' -l ' . escapeshellarg($this->ocrLanguage)
            : '';
        $cmd = 'ocrmypdf --skip-text' . $langArg . ' '
            . escapeshellarg($bundleTemp) . ' '
            . escapeshellarg($outputTemp) . ' 2>&1';
        $output = (string) @shell_exec($cmd);
        @unlink($bundleTemp);
        if (!file_exists($outputTemp) || !filesize($outputTemp)) {
            $this->logger->err(new Message(
                'Item #%1$d: ocrmypdf failed on the image bundle: %2$s', // @translate
                $itemId, trim($output)
            ));
            @unlink($outputTemp);
            return 'skipped';
        }

        // Attach as new media (or DO mirroring the first image source).
        $tempFile = $this->tempFileFactory->build();
        $tempPath = $tempFile->getTempPath() . '.pdf';
        @unlink($tempFile->getTempPath());
        $tempFile->setTempPath($tempPath);
        if (!@rename($outputTemp, $tempPath)) {
            @copy($outputTemp, $tempPath);
            @unlink($outputTemp);
        }

        $first = $imageResources[0];
        $sourceIsDo = method_exists($first, 'resourceName')
            && $first->resourceName() === 'digital_objects';

        $previousSaveAs = $this->saveAs;
        $previousFormat = $this->targetFormat;
        $previousExtension = $this->targetExtension;
        $previousMediaType = $this->targetMediaType;

        $this->saveAs = $sourceIsDo ? 'digital_object' : 'media';
        $this->targetFormat = 'application/pdf';
        $this->targetExtension = 'pdf';
        $this->targetMediaType = 'application/pdf';

        $created = null;
        try {
            $created = $this->storeFileInMedia($tempFile, null, $item, null);
        } catch (\Throwable $e) {
            $this->logger->err(new Message(
                'Item #%1$d: failed to attach the rebuilt PDF: %2$s', // @translate
                $itemId, $e->getMessage()
            ));
        }

        $this->saveAs = $previousSaveAs;
        $this->targetFormat = $previousFormat;
        $this->targetExtension = $previousExtension;
        $this->targetMediaType = $previousMediaType;
        @unlink($tempPath);

        if ($created) {
            $this->logger->info(new Message(
                'Item #%1$d: built a searchable PDF from %2$d images and attached it as %3$s #%4$d.', // @translate
                $itemId, count($imagePaths),
                $sourceIsDo ? 'digital_object' : 'media',
                $created->id()
            ));
            return 'created';
        }
        return 'skipped';
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

        foreach ($this->collectItemFileResources($item) as $resource) {
            $mediaType = (string) $resource->mediaType();
            if (strtok($mediaType, '/') !== 'image') {
                continue;
            }
            $dim = $this->resolveImageDimensions($resource);
            if (!$dim || (!$dim['width'] && !$dim['height'])) {
                continue;
            }
            $imageSizes[] = [
                'id' => $resource->id(),
                'width' => (int) $dim['width'],
                'height' => (int) $dim['height'],
                'source' => $resource->source(),
            ];
        }

        return $imageSizes;
    }

    /**
     * Resolve image dimensions through IiifServer's mediaDimension controller
     * plugin when available: it returns cached dimensions from media data and
     * persists a fresh measurement on the first read, so subsequent jobs and
     * manifests no longer fall back to expensive @getimagesize() probes. The
     * local imageSizeLocal() probe is kept as a last-resort fallback for
     * standalone deployments without IiifServer.
     *
     * @return array{width:int|null,height:int|null}|null
     */
    protected function resolveImageDimensions($media): ?array
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        if ($plugins->has('mediaDimension')) {
            try {
                $dim = $plugins->get('mediaDimension')($media, 'original');
                if (is_array($dim)) {
                    return [
                        'width' => isset($dim['width']) ? (int) $dim['width'] : null,
                        'height' => isset($dim['height']) ? (int) $dim['height'] : null,
                    ];
                }
            } catch (\Throwable $e) {
                // Fall through.
            }
        }
        if (method_exists($media, 'hasOriginal') && !$media->hasOriginal()) {
            return null;
        }
        return $this->imageSizeLocal($media);
    }

    /**
     * @param MediaRepresentation|\IiifSearch\Stdlib\FileResource $media
     */
    protected function imageSizeLocal($media): array
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
