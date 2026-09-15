<?php declare(strict_types=1);

namespace IiifSearch;

if (!class_exists('Common\TraitModule', false)) {
    require_once file_exists(dirname(__DIR__) . '/Common/src/TraitModule.php')
        ? dirname(__DIR__) . '/Common/src/TraitModule.php'
        : dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl
            ->allow(null, 'IiifSearch\Controller\Search');

        // Re-encode decoded slashes in identifiers for search routes.
        $event->getApplication()->getEventManager()
            ->attach(MvcEvent::EVENT_ROUTE, [$this, 'reencodeIdentifierSlashes'], 1000);

        $this->migratePairingSettings();
    }

    /**
     * Map the legacy xml_image_match value onto iiifsearch_alto_pairing_mode on
     * the first boot after upgrade. Only runs when the new setting is at its
     * default ("auto") so manual choices are preserved. Idempotent via a
     * one-shot flag.
     */
    protected function migratePairingSettings(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if ($settings->get('iiifsearch_pairing_migrated')) {
            return;
        }
        $current = (string) $settings->get('iiifsearch_alto_pairing_mode', 'auto');
        if ($current === 'auto') {
            $legacy = (string) $settings->get('iiifsearch_xml_image_match', '');
            if ($legacy === 'basename') {
                $settings->set('iiifsearch_alto_pairing_mode', 'basename');
            }
        }
        $settings->set('iiifsearch_pairing_migrated', true);
    }


    /**
     * Re-encode decoded slashes in iiif url identifiers.
     *
     * When apache or a reverse proxy does not preserve encoded slashes (%2F),
     * they get decoded to "/" in the path, breaking segment routes that expect
     * the identifier as a single path segment. This listener detects where the
     * identifier ends by looking for known iiif keywords (manifest, canvas,
     * info.json, etc.) from the right of the path, then re-encodes all "/"
     * within the identifier portion.
     *
     * This is a no-op when identifiers contain no decoded slashes (e.g. simple
     * numeric ids or already-encoded identifiers).
     *
     * @see https://iiif.io/api/presentation/3.0/
     * @see https://iiif.io/api/presentation/2.1/
     * @see https://iiif.io/api/image/3.0/
     *
     * Adapted and copied id:
     * @see \IiifServer\Module::reencodeIdentifierSlashes()
     * @see \IiifSearch\Module::reencodeIdentifierSlashes()
     */
    public function reencodeIdentifierSlashes(MvcEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request instanceof \Laminas\Http\PhpEnvironment\Request) {
            return;
        }

        $path = $request->getUri()->getPath();

        // Quick check: only process iiif search paths.
        if (strpos($path, '/iiif-search/') === false) {
            return;
        }

        // Parse: /iiif-search/{identifier}[/search|/list/{name}]
        if (!preg_match('#^(/iiif-search)(/[^?]*)$#', $path, $matches)) {
            return;
        }

        // Remove leading slash.
        $remainder = substr($matches[2], 1);
        $segments = explode('/', $remainder);
        $count = count($segments);

        // A single segment means no slashes in the identifier.
        if ($count <= 1) {
            return;
        }

        // Known iiif Search keywords that appear after the identifier.
        static $searchKeywords = [
            'search' => true,
            'autocomplete' => true,
            'list' => true,
        ];

        // Scan from the right to find the first iiif keyword.
        // Start at index 1 (the identifier needs at least one segment).
        $suffixCount = 0;
        for ($i = $count - 1; $i >= 1; $i--) {
            if (isset($searchKeywords[$segments[$i]])) {
                $suffixCount = $count - $i;
                break;
            }
        }

        $identifierCount = $count - $suffixCount;
        if ($identifierCount <= 1) {
            return;
        }

        // Re-encode slashes within the identifier portion.
        $identifierParts = array_slice($segments, 0, $identifierCount);
        $encodedIdentifier = implode('%2F', $identifierParts);

        $suffixParts = array_slice($segments, $identifierCount);
        if ($suffixParts) {
            $encodedIdentifier .= '/' . implode('/', $suffixParts);
        }

        $iiifBase = $matches[1];
        $newPath = $iiifBase . '/' . $encodedIdentifier;
        if ($newPath !== $path) {
            $request->getUri()->setPath($newPath);
        }
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        $errors = [];

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.85')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.85'
            );
            $errors[] = (string) $message;
        }

        if ($this->isModuleActive('IiifServer') && !$this->isModuleVersionAtLeast('IiifServer', '3.6.33')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'IiifServer', '3.6.33'
            );
            $errors[] = (string) $message;
        }

        if ($errors) {
            throw new \Omeka\Module\Exception\ModuleCannotInstallException(
                implode("\n", $errors)
            );
        }

        $moduleManager = $services->get('Omeka\ModuleManager');
        $extractOcr = $moduleManager->getModule('ExtractOcr');
        if ($extractOcr) {
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            try {
                $moduleManager->deactivate($self);
                $moduleManager->uninstall($self);
                $messenger->addSuccess(new PsrMessage(
                    'Module Extract OCR uninstalled: features are now in IIIF Search.' // @translate
                ));
            } catch (\Throwable $e) {
            }
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            '*',
            'iiifserver.manifest',
            [$this, 'handleIiifServerManifest']
        );

        // OCR extraction (formerly module ExtractOcr).
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$this, 'extractOcr']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.post',
            [$this, 'extractOcr']
        );

        // EasyAdmin batch job entries.
        $sharedEventManager->attach(
            \EasyAdmin\Form\CheckAndFixForm::class,
            'form.add_elements',
            [$this, 'handleEasyAdminJobsForm']
        );
        $sharedEventManager->attach(
            \EasyAdmin\Controller\Admin\CheckAndFixController::class,
            'easyadmin.job',
            [$this, 'handleEasyAdminJobs']
        );
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $formManager = $services->get('FormElementManager');

        $this->initDataToPopulate($settings, 'config');
        $data = $this->prepareDataToPopulate($settings, 'config');
        if ($data === null) {
            return null;
        }

        $form = $formManager->get(\IiifSearch\Form\ConfigForm::class);
        $form->init();
        $form->setData($data);
        $form->prepare();

        $view = $renderer;
        $translate = $view->plugin('translate');
        $escape = $view->plugin('escapeHtml');

        // Dispatch elements to tabs using element_groups.
        $elementGroups = $form->getOption('element_groups') ?: [];
        $tabs = array_fill_keys(array_keys($elementGroups), '');
        $ungrouped = '';

        foreach ($form as $element) {
            if ($element instanceof \Laminas\Form\FieldsetInterface) {
                $group = $element->getOption('element_group');
                if ($group && isset($tabs[$group])) {
                    $tabs[$group] .= $view->formCollection($element);
                }
                continue;
            }
            $group = $element->getOption('element_group');
            if ($group && isset($tabs[$group])) {
                $tabs[$group] .= $view->formRow($element);
            } else {
                $ungrouped .= $view->formRow($element);
            }
        }

        $firstGroup = array_key_first($elementGroups);
        if ($firstGroup && $ungrouped !== '') {
            $tabs[$firstGroup] = $ungrouped . $tabs[$firstGroup];
        }

        // Module navigation bar shared with IiifServer and ImageServer.
        $iiifModules = ['IiifServer', 'IiifSearch', 'ImageServer'];
        $moduleNav = $view->moduleConfigNav($iiifModules, 'IiifSearch');

        // Build tabs.
        $tabNav = '';
        $tabContent = '';
        $isFirst = true;
        foreach ($elementGroups as $groupName => $groupLabel) {
            if (empty($tabs[$groupName])) {
                continue;
            }
            $activeClass = $isFirst ? ' class="active"' : '';
            $sectionClass = $isFirst ? 'section active' : 'section';
            $tabNav .= '<li' . $activeClass . '><a href="#iiifsearch-' . $groupName . '">'
                . $escape($translate($groupLabel)) . '</a></li>';
            $tabContent .= '<div id="iiifsearch-' . $groupName . '" class="' . $sectionClass . '">'
                . $tabs[$groupName] . '</div>';
            $isFirst = false;
        }

        return $moduleNav
            . '<ul class="section-nav" style="list-style:none;padding:0;">'
            . $tabNav
            . '</ul>'
            . $tabContent;
    }

    public function handleIiifServerManifest(Event $event): void
    {
        // Target is the view. Available keys: "format", the manifest, info etc
        // according to format, "resource", "type".

        // This is the iiif type, not omeka one.
        $type = $event->getParam('type');

        if ($type === 'media' && $event->getParam('format') === 'canvas') {
            $this->handleIiifServerCanvas($event);
            return;
        }

        if ($type !== 'item') {
            return;
        }

        $resource = $event->getParam('resource');

        // Check first if there is a simple file with data (see module ExtractOcr).
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $resourceId = $resource->id();
        $simpleFilepath = null;
        $localPaths = [
            $basePath . '/iiif-search/' . $resourceId . '.tsv',
            $basePath . '/alto/' . $resourceId . '.alto.xml',
            $basePath . '/hocr/' . $resourceId . '.hocr.html',
            $basePath . '/pdf2xml/' . $resourceId . '.xml',
            // Old path before ExtractOcr 3.4.7.
            $basePath . '/iiif-search/' . $resourceId . '.xml',
        ];
        foreach ($localPaths as $path) {
            if (file_exists($path)) {
                $simpleFilepath = $path;
                break;
            }
        }

        // Else check if resource has at least one XML file for search.
        if (!$simpleFilepath) {
            $searchServiceAvailable = false;
            $searchMediaTypes = [
                'application/alto+xml',
                'text/vnd.hocr+html',
                'application/vnd.pdf2xml+xml',
                'text/tab-separated-values',
            ];
            foreach ($resource->media() as $media) {
                $mediaType = $media->mediaType();
                if (in_array($mediaType, $searchMediaTypes)) {
                    $searchServiceAvailable = true;
                    break;
                }
            }
            if (!$searchServiceAvailable) {
                return;
            }
        }

        $plugins = $this->getServiceLocator()->get('ViewHelperManager');
        $urlHelper = $plugins->get('url');
        $identifier = $plugins->has('iiifCleanIdentifiers')
            ? $plugins->get('iiifCleanIdentifiers')->__invoke($resource->id())
            : $resource->id();

        // When the server does not support encoded slashes, restore literal
        // slashes in generated URLs so they remain functional.
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $encodeSlash = (bool) $settings->get('iiifserver_identifier_encode_slash', false);
        $fixSlash = function (string $url) use ($encodeSlash): string {
            return $encodeSlash ? $url : strtr($url, ['%252F' => '/', '%2F' => '/']);
        };

        /** @var \IiifServer\Iiif\Manifest $manifest */
        $manifest = $event->getParam('manifest');

        $versions = $settings->get('iiifsearch_versions', ['1', '2']);
        $versions = array_values(array_intersect(['1', '2'], (array) $versions));
        if (empty($versions)) {
            return;
        }

        $searchUrlV1 = $fixSlash($urlHelper('iiifsearch/search', ['id' => $identifier], ['force_canonical' => true]));
        $searchUrlV2 = $fixSlash($urlHelper('iiifsearch/search-2', ['id' => $identifier], ['force_canonical' => true]));

        $isVersion2 = !is_object($manifest);
        if ($isVersion2) {
            // Manifest IIIF Presentation 2 only carries Search v1 services.
            if (in_array('1', $versions, true)) {
                $manifest['service'][] = [
                    '@context' => 'http://iiif.io/api/search/1/context.json',
                    '@id' => $searchUrlV1,
                    'profile' => 'http://iiif.io/api/search/1/search',
                    'label' => 'Search within this manifest', // @translate
                ];
            }
        } else {
            $services = [];
            if (in_array('1', $versions, true)) {
                $services[] = [
                    '@context' => 'http://iiif.io/api/search/1/context.json',
                    'id' => $searchUrlV1,
                    'type' => 'SearchService1',
                    'profile' => 'http://iiif.io/api/search/1/search',
                    'label' => 'Search within this manifest', // @translate
                ];
            }
            if (in_array('2', $versions, true)) {
                $services[] = [
                    '@context' => 'http://iiif.io/api/search/2/context.json',
                    'id' => $searchUrlV2,
                    'type' => 'SearchService2',
                    'profile' => 'http://iiif.io/api/search/2/search',
                    'label' => 'Search within this manifest', // @translate
                ];
            }
            // Check version of module IiifServer.
            if (method_exists($manifest, 'getPropertyRequirements')) {
                foreach ($services as $service) {
                    $manifest['service'][] = new \IiifServer\Iiif\Service($service);
                }
            } else {
                foreach ($services as $service) {
                    $manifest->appendService(new \IiifServer\Iiif\Service($resource, $service));
                }
            }
        }

        $event->setParam('manifest', $manifest);
    }

    /**
     * Inject seeAlso (ALTO) and supplementing annotations on each canvas of an
     * item that owns a multipage ALTO XML media. Per-page resources are served
     * by IiifSearch endpoints (/iiif-search/:id/alto/:n.xml and
     * /iiif-search/:id/annotations/:n.json).
     */
    protected function handleIiifServerCanvas(Event $event): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('iiifsearch_alto_canvas_inject', true)) {
            return;
        }

        $media = $event->getParam('resource');
        if (!$media instanceof \Omeka\Api\Representation\MediaRepresentation) {
            return;
        }
        $item = $media->item();

        $plugins = $services->get('ViewHelperManager');
        if (!$plugins->has('xmlAltoSplitter')) {
            return;
        }
        $splitter = $plugins->get('xmlAltoSplitter');
        $altoMedia = $splitter->findMultipageAltoMedia($item);
        if (!$altoMedia) {
            return;
        }
        // The ALTO media itself is not a canvas-bearing media.
        if ($altoMedia->id() === $media->id()) {
            return;
        }

        $pageIndex = $this->canvasPageIndex($item, $media, $altoMedia);
        if ($pageIndex === null) {
            return;
        }

        // Generate (and cache) the per-page ALTO; skip injection if missing.
        $altoPath = $splitter($item, $pageIndex);
        if (!$altoPath) {
            return;
        }

        $urlHelper = $plugins->get('url');
        $identifier = $plugins->has('iiifCleanIdentifiers')
            ? $plugins->get('iiifCleanIdentifiers')->__invoke($item->id())
            : $item->id();
        $encodeSlash = (bool) $settings->get('iiifserver_identifier_encode_slash', false);
        $fixSlash = function (string $url) use ($encodeSlash): string {
            return $encodeSlash ? $url : strtr($url, ['%252F' => '/', '%2F' => '/']);
        };

        $altoUrl = $fixSlash($urlHelper(
            'iiifsearch/alto-page',
            ['id' => $identifier, 'page' => $pageIndex],
            ['force_canonical' => true]
        ));
        $annotationsUrl = $fixSlash($urlHelper(
            'iiifsearch/annotation-page',
            ['id' => $identifier, 'page' => $pageIndex],
            ['force_canonical' => true]
        ));

        $canvas = $event->getParam('canvas');
        $isVersion2 = !is_object($canvas);

        if ($isVersion2) {
            $canvas['seeAlso'][] = [
                '@id' => $altoUrl,
                'format' => 'application/xml+alto',
                'profile' => 'http://www.loc.gov/standards/alto/v4/alto.xsd',
                'label' => 'ALTO XML', // @translate
            ];
            $event->setParam('canvas', $canvas);
            return;
        }

        $seeAlso = [
            'id' => $altoUrl,
            'type' => 'Dataset',
            'format' => 'application/xml+alto',
            'profile' => 'http://www.loc.gov/standards/alto/v4/alto.xsd',
            'label' => ['none' => ['ALTO XML']],
        ];
        $annotationPage = [
            'id' => $annotationsUrl,
            'type' => 'AnnotationPage',
        ];

        if (method_exists($canvas, 'append')) {
            $canvas->append('seeAlso', $seeAlso);
            $canvas->append('annotations', $annotationPage);
        } else {
            $canvas['seeAlso'][] = $seeAlso;
            $canvas['annotations'][] = $annotationPage;
        }

        $event->setParam('canvas', $canvas);
    }

    /**
     * Map a canvas-bearing media to its zero-based ALTO page index.
     *
     * Default strategy: position among non-ALTO media of the item, matching the
     * splitter "order" mapping. Other strategies are honored when the splitter
     * is configured for them.
     */
    protected function canvasPageIndex(
        \Omeka\Api\Representation\ItemRepresentation $item,
        \Omeka\Api\Representation\MediaRepresentation $media,
        \Omeka\Api\Representation\MediaRepresentation $altoMedia
    ): ?int {
        $i = 0;
        foreach ($item->media() as $m) {
            if ($m->id() === $altoMedia->id()) {
                continue;
            }
            if ($m->id() === $media->id()) {
                return $i;
            }
            $i++;
        }
        return null;
    }

    protected function postInstall(): void
    {
        $this->postInstallAuto();
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        // Soft requirement: OCR extraction features need poppler-utils.
        if ((int) shell_exec('hash pdftotext 2>&- || echo 1')) {
            $logger->warn(new Message(
                'The command-line utility pdftotext is not available; OCR extraction will be disabled until package poppler-utils is installed.' // @translate
            ));
        }
        if ((int) shell_exec('hash pdftohtml 2>&- || echo 1')) {
            $logger->warn(new Message(
                'The command-line utility pdftohtml is not available; OCR extraction will be disabled until package poppler-utils is installed.' // @translate
            ));
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        foreach (['temp', 'iiif-search', 'alto', 'pdf2xml'] as $sub) {
            if (!$this->checkDestinationDir($basePath . '/' . $sub)) {
                $logger->warn(new Message(
                    'The directory "%s" is not writeable; OCR extraction features may not work.', // @translate
                    $basePath . '/' . $sub
                ));
            }
        }

        $this->allowFileFormats();

        // Default OCR file types and pdf content store.
        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('iiifsearch_extract_types_files')) {
            $settings->set('iiifsearch_extract_types_files', [
                'text/tab-separated-values;by-word',
                'application/alto+xml',
            ]);
        }
        if (!$settings->get('iiifsearch_extract_content_store')) {
            $settings->set('iiifsearch_extract_content_store', ['media_pdf']);
        }
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $this->allowFileFormats();

        $result = $this->handleConfigFormAuto($controller);
        if (!$result) {
            return false;
        }

        $params = $controller->getRequest()->getPost();
        $extractParams = $params['iiifsearch_extract'] ?? [];
        if (empty($extractParams['process'])
            || $extractParams['process'] !== $controller->translate('Process')
        ) {
            return true;
        }

        $services = $this->getServiceLocator();
        $args = [
            'mode' => $extractParams['mode'] ?? 'all',
            'base_uri' => $this->getBaseUri(),
            'item_ids' => $extractParams['item_ids'] ?? '',
        ];
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\IiifSearch\Job\ExtractOcr::class, $args);

        $message = new Message(
            'Creating Extract OCR files in background (job %1$s#%2$s%3$s, %4$slogs%3$s).', // @translate
            sprintf(
                '<a href="%s">',
                htmlspecialchars($controller->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
            ),
            $job->getId(),
            '</a>',
            class_exists('Log\Module', false)
                ? sprintf('<a href="%1$s">', $controller->url()->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                : sprintf('<a href="%1$s" target="_blank">', $controller->url()->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))
        );
        $message->setEscapeHtml(false);
        $controller->messenger()->addSuccess($message);
        return true;
    }

    /**
     * Launch extract ocr job for an item after save.
     *
     * Deduplicates dispatches in batches via Common\DeferredJobDispatch.
     */
    public function extractOcr(Event $event): void
    {
        $services = $this->getServiceLocator();
        $response = $event->getParams()['response'];
        /** @var \Omeka\Entity\Item $item */
        $item = $response->getContent();

        $extensions = [
            \IiifSearch\Job\ExtractOcr::FORMAT_ALTO => 'alto.xml',
            \IiifSearch\Job\ExtractOcr::FORMAT_PDF2XML => 'xml',
            \IiifSearch\Job\ExtractOcr::FORMAT_TSV => 'tsv',
            \IiifSearch\Job\ExtractOcr::FORMAT_TSV_BY_WORD => 'tsv',
        ];
        $settings = $services->get('Omeka\Settings');
        $targetTypesFiles = $settings->get('iiifsearch_extract_types_files') ?: [];
        $targetTypesFiles = array_intersect($targetTypesFiles, array_flip($extensions));
        $targetTypesMedia = $settings->get('iiifsearch_extract_types_media') ?: [];
        $targetTypesMedia = array_intersect($targetTypesMedia, array_flip($extensions));
        $targetContentStore = $settings->get('iiifsearch_extract_content_store') ?: [];
        $targetContentStore = array_intersect($targetContentStore, ['item', 'media_pdf', 'media_extracted']);
        if (!$targetTypesFiles && !$targetTypesMedia && !$targetContentStore) {
            return;
        }

        $hasPdf = false;
        /** @var \Omeka\Entity\Media $media */
        foreach ($item->getMedia() as $media) {
            $mediaType = $media->getMediaType();
            $extension = strtolower((string) $media->getExtension());
            if ($mediaType === 'application/pdf' && $extension === 'pdf') {
                $hasPdf = true;
                break;
            }
        }
        if (!$hasPdf) {
            return;
        }

        $source = (string) $media->getSource();
        $filename = (string) parse_url($source, PHP_URL_PATH);
        $targetFilenameNoExtension = strlen($filename)
            ? basename($filename, '.pdf')
            : $media->id() . '-' . $media->getStorageId();
        if (!$targetFilenameNoExtension) {
            return;
        }

        $suffixFilenames = [
            'alto.xml' => '.alto',
            'xml' => '',
            'tsv' => '',
        ];
        $shortExtensions = [
            'alto.xml' => 'xml',
            'xml' => 'xml',
            'tsv' => 'tsv',
        ];
        $dirPaths = [
            'alto.xml' => 'alto',
            'pdf2xml' => 'pdf2xml',
            'tsv' => 'iiif-search',
            'xml' => 'pdf2xml',
        ];

        $existingFiles = array_fill_keys($targetTypesFiles, false);
        if ($targetTypesFiles) {
            $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
            foreach ($targetTypesFiles as $targetTypeFile) {
                $targetExtension = $extensions[$targetTypeFile];
                $targetDirPath = $dirPaths[$targetExtension];
                $localSearchFilepath = $basePath . '/' . $targetDirPath . '/' . $item->getId() . '.' . $targetExtension;
                if (file_exists($localSearchFilepath)) {
                    $existingFiles[$targetTypeFile] = true;
                }
            }
        }

        $existingMedias = array_fill_keys($targetTypesMedia, false);
        if ($targetTypesMedia) {
            foreach ($targetTypesMedia as $targetMediaType) {
                $targetExtension = $extensions[$targetMediaType];
                $targetFilename = $targetFilenameNoExtension . '.' . $targetExtension;
                if ($this->getMediaFromFilename($item->getId(), $targetFilename . $suffixFilenames[$targetExtension], $shortExtensions[$targetExtension], $targetMediaType)) {
                    $existingMedias[$targetMediaType] = true;
                }
            }
        }

        if (count(array_filter($existingFiles)) === count($existingFiles)
            && count(array_filter($existingMedias)) === count($existingMedias)
            && !count($targetContentStore)
        ) {
            return;
        }

        $baseUri = $this->getBaseUri();
        if ($services->has('Common\DeferredJobDispatch')) {
            $services->get('Common\DeferredJobDispatch')->defer(
                \IiifSearch\Job\ExtractOcr::class,
                'iiifsearch_extract_ocr',
                ['item_ids' => $item->getId()],
                function (string $key, array $allParams) use ($baseUri) {
                    $ids = [];
                    foreach ($allParams as $p) {
                        $ids[] = $p['item_ids'];
                    }
                    return [
                        'mode' => 'all',
                        'base_uri' => $baseUri,
                        'item_ids' => implode(' ', array_unique($ids)),
                        'manual' => true,
                    ];
                }
            );
        } else {
            $services->get(\Omeka\Job\Dispatcher::class)->dispatch(
                \IiifSearch\Job\ExtractOcr::class,
                [
                    'mode' => 'all',
                    'base_uri' => $baseUri,
                    'item_ids' => (string) $item->getId(),
                    'manual' => true,
                ]
            );
        }
    }

    public function handleEasyAdminJobsForm(Event $event): void
    {
        /** @var \EasyAdmin\Form\CheckAndFixForm $form */
        $form = $event->getTarget();
        $fieldset = $form->get('module_tasks');
        $process = $fieldset->get('process');
        $valueOptions = $process->getValueOptions();
        $valueOptions['iiifsearch_extract'] = 'IIIF Search: Extract ocr from files'; // @translate
        $process->setValueOptions($valueOptions);

        $fieldset
            ->add([
                'type' => \Laminas\Form\Fieldset::class,
                'name' => 'iiifsearch_extract',
                'options' => [
                    'label' => 'Options to extract OCR', // @translate
                ],
                'attributes' => [
                    'class' => 'iiifsearch_extract',
                ],
            ])
            ->get('iiifsearch_extract')
            ->add([
                'name' => 'mode',
                'type' => \Common\Form\Element\OptionalRadio::class,
                'options' => [
                    'label' => 'Extract OCR job', // @translate
                    'value_options' => [
                        'existing' => 'Only already extracted (improve extraction)', // @translate
                        'missing' => 'Only missing extracted medias', // @translate
                        'all' => 'All medias', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'mode',
                    'value' => 'all',
                ],
            ])
            ->add([
                'name' => 'item_ids',
                'type' => \Laminas\Form\Element\Text::class,
                'options' => [
                    'label' => 'Item ids', // @translate
                ],
                'attributes' => [
                    'id' => 'item_ids',
                    'placeholder' => '2-6 8 38-52 80-',
                ],
            ]);
    }

    public function handleEasyAdminJobs(Event $event): void
    {
        $process = $event->getParam('process');
        if ($process === 'iiifsearch_extract') {
            $params = $event->getParam('params');
            $event->setParam('job', \IiifSearch\Job\ExtractOcr::class);
            $args = $params['module_tasks']['iiifsearch_extract'] ?? [];
            $args['base_uri'] = $this->getBaseUri();
            $event->setParam('args', $args);
        }
    }

    /**
     * Allow TSV and XML extensions and media types in omeka settings.
     */
    protected function allowFileFormats(): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        $extensionWhitelist = $settings->get('extension_whitelist', []);
        $extensions = ['tsv', 'xml'];
        $extensionWhitelist = array_unique(array_merge($extensionWhitelist, $extensions));
        $settings->set('extension_whitelist', $extensionWhitelist);

        $mediaTypeWhitelist = $settings->get('media_type_whitelist', []);
        $xmlMediaTypes = [
            'application/xml',
            'text/xml',
            'application/alto+xml',
            'application/vnd.pdf2xml+xml',
            'application/x-empty',
            'text/tab-separated-values',
        ];
        $mediaTypeWhitelist = array_unique(array_merge($mediaTypeWhitelist, $xmlMediaTypes));
        $settings->set('media_type_whitelist', $mediaTypeWhitelist);
    }

    /**
     * Return the first media matching item id, source filename, extension and
     * media type. Returns null when none matches.
     */
    protected function getMediaFromFilename($itemId, $filename, $extension, $mediaType)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        try {
            return $api->read('media', [
                'item' => $itemId,
                'source' => $filename,
                'extension' => $extension,
                'mediaType' => $mediaType,
            ])->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
        }
        return null;
    }

    protected function getBaseUri(): string
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $baseUri = $config['file_store']['local']['base_uri'] ?? null;
        if (!$baseUri) {
            $helpers = $services->get('ViewHelperManager');
            $serverUrlHelper = $helpers->get('serverUrl');
            $basePathHelper = $helpers->get('basePath');
            $baseUri = $serverUrlHelper($basePathHelper('files'));
            if ($baseUri === 'http:///files' || $baseUri === 'https:///files') {
                $t = $services->get('MvcTranslator');
                throw new \Omeka\Mvc\Exception\RuntimeException(
                    $t->translate('The base uri is not set (key [file_store][local][base_uri]) in the config file of Omeka "config/local.config.php". It must be set for now in order to process background jobs.') // @translate
                );
            }
        }
        return $baseUri;
    }

    protected function checkDestinationDir(string $dirPath): ?string
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writeable($dirPath)) {
                return null;
            }
            return $dirPath;
        }
        return @mkdir($dirPath, 0775, true) ? $dirPath : null;
    }
}
