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
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

if (!$this->isModuleVersionAtLeast('Common', '3.4.85')) {
    $message = new Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.85'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if ($this->isModuleActive('ExtractOcr') && !$this->isModuleVersionAtLeast('ExtractOcr', '3.4.11')) {
    $message = new Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'ExtractOcr', '3.4.11'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if ($this->isModuleActive('IiifServer') && !$this->isModuleVersionAtLeast('IiifServer', '3.6.33')) {
    $message = new Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'IiifServer', '3.6.33'
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
