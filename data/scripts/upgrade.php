<?php declare(strict_types=1);

namespace IiifSearch;

use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\I18n\Translator $translator
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$helpers = $services->get('ViewHelperManager');
$url = $helpers->get('url');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.96')) {
    $message = new Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.96'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}


if ($this->isModuleActive('IiifServer') && !$this->isModuleVersionAtLeast('IiifServer', '3.6.34')) {
    $message = new Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'IiifServer', '3.6.34'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (version_compare($oldVersion, '1.1.0', '<')) {
    $settings->delete('iiifserver_manifest_service_iiifsearch');
}

if (version_compare($oldVersion, '3.3.3', '<')) {
    $message = new Message(
        'XML Alto is supported natively: just upload the files as media to search inside it.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'The xml media-type should be a precise one: either "application/alto+xml" or "application/vnd.pdf2xml+xml", not "text/xml" or "application/xml".' // @translate
    );
    $messenger->addWarning($message);
    $message = new Message(
        'New files are automatically managed, but you may need modules Bulk Edit or Easy Admin to fix old ones, if any.' // @translate
    );
    $messenger->addWarning($message);
    $message = new Message(
        'Badly formatted xml files may be fixed dynamically, but it will affect performance. See %1$sreadme%2$s.', // @translate
        '<a href="https://github.com/symac/Omeka-S-module-IiifSearch">',
        '</a>'
    );
    $message->setEscapeHtml(false);
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.4.4', '<')) {
    $settings->set('iiifsearch_disable_search_media_values', false);
    $settings->set('iiifsearch_xml_fix_mode', 'no');
    $message = new Message(
        'A new option allows to include media metadata in search, not only full text.' // @translate
    );
    $messenger->addSuccess($message);
    $message = new Message(
        'A new option allows to fix bad xml and invalid utf-8 characters.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.6', '<')) {
    $message = new Message(
        'The module supports the tsv format for quicker search results. See module Extract OCR.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.10', '<')) {
    $message = new Message(
        'The module allows to do an exact search when the input is wrapped with double quotes. If wanted and if you use a tsv index, a reindexation with ExtractOcr is needed.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.14', '<')) {
    // Enable both Search 1.0 and 2.0 services in manifests by default.
    // The setting is left untouched if the admin has already configured it.
    $current = $settings->get('iiifsearch_versions', null);
    if (!is_array($current) || !$current) {
        $settings->set('iiifsearch_versions', ['1', '2']);
    }
    $message = new Message(
        'The module now supports IIIF Content Search 2.0 at /iiif-search/{id}/search/1 or /iiif-search/{id}/search/2. The existing endpoint /iiif-search/{id}/search is kept as defaut.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.15', '<')) {
    // Migrate extract ocr settings.
    $map = [
        'extractocr_types_files' => 'iiifsearch_extract_types_files',
        'extractocr_types_media' => 'iiifsearch_extract_types_media',
        'extractocr_content_store' => 'iiifsearch_extract_content_store',
        'extractocr_content_property' => 'iiifsearch_extract_content_property',
        'extractocr_content_language' => 'iiifsearch_extract_content_language',
        'extractocr_create_empty_file' => 'iiifsearch_extract_create_empty_file',
    ];
    foreach ($map as $old => $new) {
        $value = $settings->get($old);
        // Check to avoid double migration.
        if ($settings->get($new) === null) {
            $settings->set($new, $value);
        }
    }

    $message = new Message(
        'The module now manages extraction of texts without the need of the module Extract OCR. Furthermore, most of the use cases are now managed automatically: digital objects, item without alto, with an alto multi-pages or with alto by page, item without pdf or with a pdf or with multiple pdf by page, various naming convention.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.16', '<')) {
    // The legacy pairing settings are superseded by
    // iiifsearch_alto_pairing_mode (cascade: auto, mets,
    // source_image_information, basename, basename_dir, numeric, numeric_dir,
    // dimension, sequential). Carry the previous 'basename' choice forward when
    // the new setting is still at its default; drop the legacy keys.
    $current = (string) $settings->get('iiifsearch_alto_pairing_mode', 'auto');
    if ($current === 'auto'
        && (string) $settings->get('iiifsearch_xml_image_match', '') === 'basename'
    ) {
        $settings->set('iiifsearch_alto_pairing_mode', 'basename');
    }
    $settings->delete('iiifsearch_xml_image_match');
    $settings->delete('iiifsearch_alto_page_match');

    $message = new Message(
        'Settings "iiifsearch_xml_image_match" and "iiifsearch_alto_page_match" have been removed. Pairing is now controlled by "iiifsearch_alto_pairing_mode" (auto cascade by default). Multipage ALTO page-to-canvas resolution is auto-detected (PHYSICAL_IMG_NR, Page ID matching media basename, then positional).' // @translate
    );
    $messenger->addSuccess($message);

    if ($services->get('Omeka\ModuleManager')->getModule('DigitalObject')) {
        $message = new Message(
            'DigitalObjects attached to items via the configured properties (see the DigitalObject module config) are now picked up by IIIF Search, the pairer and the annotation/seeAlso endpoints alongside native medias.' // @translate
        );
        $messenger->addSuccess($message);
    }

    // Collapse iiifsearch_extract_content_store legacy values. The previous
    // media_pdf/media_extracted/digital_object_extracted granularity was
    // redundant: the target kind is already known from
    // iiifsearch_extract_types_{media,digital_object}.
    $store = (array) ($settings->get('iiifsearch_extract_content_store') ?: []);
    $map = [
        'media_pdf' => 'pdf',
        'media_extracted' => 'extracted',
        'digital_object_extracted' => 'extracted',
    ];
    $migrated = [];
    foreach ($store as $value) {
        $migrated[] = $map[$value] ?? $value;
    }
    $migrated = array_values(array_unique($migrated));
    if ($migrated !== $store) {
        $settings->set('iiifsearch_extract_content_store', $migrated);
    }

    // Warn when legacy attached pdf2xml / tsv medias or digital objects are
    // still present: early module versions stored those files as resources, but
    // it is no longer recommended (the pipeline materialises them in
    // files/alto/ on the fly).
    $legacyTypes = [
        'application/vnd.pdf2xml+xml',
        'text/tab-separated-values',
        'text/tab-separated-values;by-word',
    ];
    $placeholders = implode(',', array_fill(0, count($legacyTypes), '?'));

    $mediaCount = (int) $connection->fetchOne(
        'SELECT COUNT(id) FROM media WHERE media_type IN (' . $placeholders . ')',
        $legacyTypes
    );
    if ($mediaCount) {
        $message = new Message(
            '%1$d media attached to items use the legacy pdf2xml or tsv format. Attaching those OCR derivatives is no longer recommended: the module stores them as files under files/alto/ and files/iiif-search/. Do not remove them before these files exist, since they may be the only source of the search: first run the job Extract OCR in mode "missing" with the destination "file", that requires the command-line utility pdftotext (package poppler-utils); then use the bulk processing tab "Cleanup module-created medias". The cleanup keeps the medias of the items without file. ALTO medias remain supported.', // @translate
            $mediaCount
        );
        $messenger->addWarning($message);
    }

    // Surface a misconfiguration: tsv / pdf2xml in the "attach as media" or
    // "attach as digital object" targets. The default config only creates them
    // as flat files under files/iiif-search and files/alto.
    $miscoMedia = array_values(array_intersect(
        (array) ($settings->get('iiifsearch_extract_types_media') ?: []),
        ['application/vnd.pdf2xml+xml', 'text/tab-separated-values', 'text/tab-separated-values;by-word']
    ));
    if ($miscoMedia) {
        $message = new Message(
            'Setting "iiifsearch_extract_types_media" includes pdf2xml or tsv (%1$s). These formats should be stored as flat files, not as medias. Move them to "iiifsearch_extract_types_files" in the Extraction tab. ALTO may still be attached as media if you wish.', // @translate
            implode(', ', $miscoMedia)
        );
        $messenger->addWarning($message);
    }
    $miscoDo = array_values(array_intersect(
        (array) ($settings->get('iiifsearch_extract_types_digital_object') ?: []),
        ['application/vnd.pdf2xml+xml', 'text/tab-separated-values', 'text/tab-separated-values;by-word']
    ));
    if ($miscoDo) {
        $message = new Message(
            'Setting "iiifsearch_extract_types_digital_object" includes pdf2xml or tsv (%1$s). These formats should be stored as flat files. Move them to "iiifsearch_extract_types_files". ALTO may still be attached as a digital object.', // @translate
            implode(', ', $miscoDo)
        );
        $messenger->addWarning($message);
    }

    if ($services->get('Omeka\ModuleManager')->getModule('DigitalObject')) {
        try {
            $doCount = (int) $connection->fetchOne(
                'SELECT COUNT(id) FROM digital_object WHERE media_type IN (' . $placeholders . ')',
                $legacyTypes
            );
        } catch (\Throwable $e) {
            $doCount = 0;
        }
        if ($doCount) {
            $message = new Message(
                '%1$d digital objects use the legacy pdf2xml or tsv format. Attaching those OCR derivatives is no longer recommended: the module stores them as files under files/alto/ and files/iiif-search/. Do not remove them before these files exist: first run the job Extract OCR in mode "missing" with the destination "file", that requires pdftotext; then use the cleanup.', // @translate
                $doCount
            );
            $messenger->addWarning($message);
        }
    }

    // Migrate the three destination-keyed settings into per-format destination
    // lists. The new layout exposes one multi-checkbox per format (tsv,
    // tsv_by_word, alto, pdf2xml) listing destinations
    // (file/media/digital_object) — clearer than three lists indexed by
    // destination, and lets the form mark pdf2xml as deprecated.
    $legacyKeys = [
        'iiifsearch_extract_types_files' => 'file',
        'iiifsearch_extract_types_media' => 'media',
        'iiifsearch_extract_types_digital_object' => 'digital_object',
    ];
    $formatKey = [
        'text/tab-separated-values' => 'iiifsearch_extract_dests_tsv',
        'text/tab-separated-values;by-word' => 'iiifsearch_extract_dests_tsv_by_word',
        'application/alto+xml' => 'iiifsearch_extract_dests_alto',
        'application/vnd.pdf2xml+xml' => 'iiifsearch_extract_dests_pdf2xml',
    ];
    $migrated = [];
    foreach ($legacyKeys as $legacyKey => $destination) {
        $list = (array) ($settings->get($legacyKey) ?: []);
        foreach ($list as $mime) {
            if (isset($formatKey[$mime])) {
                $migrated[$formatKey[$mime]][] = $destination;
            }
        }
        $settings->delete($legacyKey);
    }
    foreach ($formatKey as $newKey) {
        $value = $newKey;
        $dests = isset($migrated[$newKey])
            ? array_values(array_unique($migrated[$newKey]))
            : null;
        if ($dests !== null) {
            $settings->set($newKey, $dests);
        }
        unset($value);
    }

    $message = new Message(
        'Extraction settings have been reorganised per format. Each format (TSV, by-word TSV, ALTO, pdf2xml) now exposes its own list of destinations (file / media / digital object). The recommended defaults are: file for TSV, by-word TSV and ALTO; nothing for pdf2xml (deprecated). ALTO may additionally be attached as media or digital object if you need it exposed as a regular Omeka resource. TSV and pdf2xml should never be.' // @translate
    );
    $messenger->addSuccess($message);

    // Migrate iiifsearch_xml_fix_mode from a single-choice string ('no', 'dom',
    // 'regex', 'all') to a multi-checkbox array (['dom'?, 'regex'?]). Both
    // forms are accepted at read time but only the array shape is surfaced in
    // the form.
    $currentFixMode = $settings->get('iiifsearch_xml_fix_mode');
    if (is_string($currentFixMode)) {
        $map = [
            'no' => [],
            'dom' => ['dom'],
            'regex' => ['regex'],
            'all' => ['dom', 'regex'],
        ];
        $settings->set(
            'iiifsearch_xml_fix_mode',
            $map[$currentFixMode] ?? []
        );
    }

    // ExtractOcr is now subsumed by IiifSearch: uninstall it so the two
    // extractors do not run side by side on api.create/update.
    $this->uninstallObsoleteExtractOcr();

    // Replace the single iiifsearch_alto_canvas_inject flag by three granular
    // toggles. The two legacy-equivalent toggles default to true on upgrade
    // regardless of the previously stored value: the previous default was
    // inconsistent (false in module.config.php vs true at runtime) and the
    // desired behaviour for any IiifSearch installation is to expose seeAlso
    // ALTO + annotations on canvases when search is active. The third toggle
    // (hOCR) stays opt-in.
    $settings->set('iiifsearch_canvas_seealso_alto', true);
    $settings->set('iiifsearch_canvas_annotations', true);
    $settings->set('iiifsearch_canvas_seealso_hocr', false);
    $settings->delete('iiifsearch_alto_canvas_inject');

    $messenger->addSuccess(new Message(
        'Canvas seeAlso/annotations are now controlled by three independent toggles in the Text tab (ALTO, annotations, hOCR). ALTO and annotations are enabled by default; hOCR is opt-in and requires a native hOCR media on the item.' // @translate
    ));

    // Repair media types corrupted by a finfo false positive: some text files
    // (ocr .txt, alto/hocr, etc.) whose first bytes collide with a binary magic
    // signature were stored with an audio/video/image media type (typically
    // "audio/x-mp4a-latm" for text starting with "V" + a multibyte char). Module
    // Common now fixes detection at ingestion; here, retype existing files whose
    // extension is unambiguously text but whose media type is a binary family.
    $extensionsToTypes = [
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'tsv' => 'text/tab-separated-values',
        'vtt' => 'text/vtt',
        'xml' => 'application/xml',
        'alto' => 'application/alto+xml',
        'hocr' => 'text/vnd.hocr+html',
        'html' => 'text/html',
        'json' => 'application/json',
    ];
    $fixed = 0;
    foreach ($extensionsToTypes as $extension => $mediaType) {
        $fixed += (int) $connection->executeStatement(
            'UPDATE `media` SET `media_type` = :type WHERE `extension` = :ext AND (`media_type` LIKE "audio/%" OR `media_type` LIKE "video/%" OR `media_type` LIKE "image/%")',
            ['type' => $mediaType, 'ext' => $extension]
        );
    }

    if ($fixed) {
        $messenger->addWarning(new Message(
            'Repaired the media type of %d text files that were wrongly detected as a binary type (audio/video/image). Reindex the search and regenerate manifests if needed.', // @translate
            $fixed
        ));
    }

    // The ocr files are derivative files that are never medias, so they have no
    // storage id and the module Access cannot check their rights. They are
    // served only through the endpoints of the module, that check them page by
    // page. So deny any direct web access to their directories.
    $localConfig = $services->get('Config');
    $basePath = $localConfig['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
    $directoryManager = $services->get('Common\DirectoryManager');
    $failed = [];
    foreach (['temp', 'iiif-search', 'iiif-search-audit', 'alto', 'hocr', 'pdf2xml'] as $dir) {
        if (is_dir($basePath . '/' . $dir)
            && !$directoryManager->protectDirectory($basePath . '/' . $dir)
        ) {
            $failed[] = $dir;
        }
    }
    if ($failed) {
        $messenger->addError(new Message(
            'Unable to write the file .htaccess in the directories %s: the ocr files may be readable by anybody. Deny the access to them in the config of the server.', // @translate
            implode(', ', $failed)
        ));
    } else {
        $messenger->addSuccess(new Message(
            'The direct web access to the directories of the ocr files is now denied: these files are served only through the module, that checks rights.' // @translate
        ));
    }

    $messenger->addWarning(new Message(
        'The endpoints moved from /iiif-search/{id} to /iiif/search/{id}. The old urls are redirected, but the manifests cached by the module IIIF Server (directory files/iiif) should be cleared to use the new ones.' // @translate
    ));

    $messenger->addSuccess(new Message(
        'The table of contents of a tei file attached to an item is now extracted and used as structures of the manifest by the module IIIF Server. It can be stored in a property too (option "Store the table of contents of the TEI file in a property").' // @translate
    ));
}

if (version_compare($oldVersion, '3.4.17', '<')) {
    $settings->delete('iiifsearch_xml_fix_mode');

    $messenger->addSuccess(new Message(
        'The invalid utf-8 characters and the malformed xml files are now repaired automatically, only when needed, so the option to fix them was removed. Each repaired file is logged, so it can be fixed.' // @translate
    ));
}

if (version_compare($oldVersion, '3.4.18', '<')) {
    if (!$this->isModuleActive('JobQueue')) {
        $message = new \Common\Stdlib\PsrMessage(
            'The optional module {link}Job Queue{link_end} can be installed to avoid running the same background jobs several times in parallel and to limit the number of heavy jobs running at the same time.', // @translate
            ['link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-JobQueue" target="_blank" rel="noopener">', 'link_end' => '</a>']
        );
        $message->setEscapeHtml(false);
        $messenger->addNotice($message);
    }
}
