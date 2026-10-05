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
use Omeka\Api\Representation\ItemRepresentation;
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
            'annotations' => true,
            'alto' => true,
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

        $this->checkOcrTools();

        $this->initDataToPopulate($settings, 'config');
        $data = $this->prepareDataToPopulate($settings, 'config');
        if ($data === null) {
            return null;
        }

        if ($this->invalidConfigForm) {
            // Reuse the failed-submit form so its per-field error messages
            // remain attached and render inline via formRow().
            $form = $this->invalidConfigForm;
        } else {
            $form = $formManager->get(\IiifSearch\Form\ConfigForm::class);
            $form->init();
            $form->setData($data);
        }
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
        foreach (\IiifSearch\View\Helper\IiifSearch::SUPPORTED_INDEXES as $index) {
            $path = $basePath . '/' . $index['dir'] . '/' . $resourceId . '.' . $index['extension'];
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

        // In the manifest built for the visitors without rights on reserved
        // medias (module Access), the search is useless when no page is free.
        if ($event->getParam('accessFilter') === 'free' && !$this->hasFreeOcrPage($resource)) {
            return;
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

        // The targets of the results are the canvases of the manifest, so the
        // version of the manifest is set in the url when it is not the default
        // one. The url of the default manifest is unchanged.
        $isVersion2 = !is_object($manifest);
        $routeParams = ['id' => $identifier];
        $manifestVersion = $isVersion2 ? '2' : '3';
        if ($manifestVersion !== (string) $settings->get('iiifserver_manifest_default_version', '2')) {
            $routeParams['manifest'] = $manifestVersion;
        }

        $searchUrlV1 = $fixSlash($urlHelper('iiifsearch/search', $routeParams, ['force_canonical' => true]));
        $searchUrlV2 = $fixSlash($urlHelper('iiifsearch/search-2', $routeParams, ['force_canonical' => true]));

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

        // Three independent toggles: bail out early when none is on.
        $emitAlto = (bool) $settings->get('iiifsearch_canvas_seealso_alto', true);
        $emitAnnotations = (bool) $settings->get('iiifsearch_canvas_annotations', true);
        $emitHocr = (bool) $settings->get('iiifsearch_canvas_seealso_hocr', false);
        if (!$emitAlto && !$emitAnnotations && !$emitHocr) {
            return;
        }

        $media = $event->getParam('resource');
        if (!$media instanceof \Omeka\Api\Representation\AbstractResourceEntityRepresentation
            || !method_exists($media, 'item')
        ) {
            return;
        }
        $item = $media->item();
        if (!$item) {
            return;
        }

        $plugins = $services->get('ViewHelperManager');
        if (!$plugins->has('xmlAltoSplitter')) {
            return;
        }
        $splitter = $plugins->get('xmlAltoSplitter');
        if (!$splitter->hasAnyAlto($item)) {
            return;
        }
        // The ALTO resource itself (when stored as media/DO on the item) is not
        // a canvas-bearing resource; skip it so its own canvas does not get a
        // self-referential seeAlso.
        if ($media->mediaType() === 'application/alto+xml') {
            return;
        }

        // Index of the current canvas among non-ALTO file resources of the
        // item; used positionally to point at a per-page ALTO/annotation page.
        $pageIndex = $this->canvasPageIndex($item, $media);
        if ($pageIndex === null) {
            return;
        }

        // Resolve the page source; skip injection if none is available so we do
        // not advertise dangling links.
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

        $altoUrl = $emitAlto ? $fixSlash($urlHelper(
            'iiifsearch/alto-page',
            ['id' => $identifier, 'page' => $pageIndex + 1],
            ['force_canonical' => true]
        )) : null;
        $annotationsUrl = $emitAnnotations ? $fixSlash($urlHelper(
            'iiifsearch/annotation-page',
            ['id' => $identifier, 'page' => $pageIndex + 1],
            ['force_canonical' => true]
        )) : null;

        // hOCR seeAlso is emitted whenever an ALTO source is available for this
        // page: the endpoint serves a native hOCR media when one is attached at
        // the positional index, otherwise converts the per-page ALTO via the
        // bundled alto_to_hocr.xsl stylesheet. The outer hasAnyAlto() check ran
        // above already guarantees that some ALTO source exists for the item.
        $hocrUrl = null;
        if ($emitHocr) {
            $hocrUrl = $fixSlash($urlHelper(
                'iiifsearch/hocr-page',
                ['id' => $identifier, 'page' => $pageIndex + 1],
                ['force_canonical' => true]
            ));
        }

        $canvas = $event->getParam('canvas');
        $isVersion2 = !is_object($canvas);

        if ($isVersion2) {
            if ($altoUrl) {
                $canvas['seeAlso'][] = [
                    '@id' => $altoUrl,
                    'format' => 'application/xml+alto',
                    'profile' => 'http://www.loc.gov/standards/alto/v4/alto.xsd',
                    'label' => 'ALTO XML', // @translate
                ];
            }
            if ($hocrUrl) {
                $canvas['seeAlso'][] = [
                    '@id' => $hocrUrl,
                    'format' => 'text/vnd.hocr+html',
                    'label' => 'hOCR', // @translate
                ];
            }
            // IIIF Presentation 2 carries OCR in otherContent, not annotations.
            // The legacy listener never exposed v2 annotations either.
            $event->setParam('canvas', $canvas);
            return;
        }

        $seeAlsoAlto = $altoUrl ? [
            'id' => $altoUrl,
            'type' => 'Dataset',
            'format' => 'application/xml+alto',
            'profile' => 'http://www.loc.gov/standards/alto/v4/alto.xsd',
            'label' => ['none' => ['ALTO XML']],
        ] : null;
        $seeAlsoHocr = $hocrUrl ? [
            'id' => $hocrUrl,
            'type' => 'Dataset',
            'format' => 'text/vnd.hocr+html',
            'label' => ['none' => ['hOCR']],
        ] : null;
        $annotationPage = $annotationsUrl ? [
            'id' => $annotationsUrl,
            'type' => 'AnnotationPage',
        ] : null;

        if (method_exists($canvas, 'append')) {
            if ($seeAlsoAlto) {
                $canvas->append('seeAlso', $seeAlsoAlto);
            }
            if ($seeAlsoHocr) {
                $canvas->append('seeAlso', $seeAlsoHocr);
            }
            if ($annotationPage) {
                $canvas->append('annotations', $annotationPage);
            }
        } else {
            if ($seeAlsoAlto) {
                $canvas['seeAlso'][] = $seeAlsoAlto;
            }
            if ($seeAlsoHocr) {
                $canvas['seeAlso'][] = $seeAlsoHocr;
            }
            if ($annotationPage) {
                $canvas['annotations'][] = $annotationPage;
            }
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
    /**
     * Apply the OCR policy (include / exclude Omeka queries) to a single item.
     * Returns true when the item is eligible for OCR. Empty queries mean "no
     * restriction".
     *
     * Settings consumed:
     *  - iiifsearch_extract_query_include (URL-encoded query string)
     *  - iiifsearch_extract_query_exclude (URL-encoded query string)
     */
    public function itemMatchesOcrPolicy(int $itemId, $settings = null): bool
    {
        if ($itemId <= 0) {
            return false;
        }
        $services = $this->getServiceLocator();
        $settings = $settings ?: $services->get('Omeka\Settings');
        $api = $services->get('Omeka\ApiManager');

        $include = (string) $settings->get('iiifsearch_extract_query_include', '');
        if ($include !== '') {
            $q = [];
            parse_str($include, $q);
            if ($q) {
                $q['id'] = $itemId;
                $hits = $api->search('items', $q, ['initialize' => false, 'returnScalar' => 'id'])->getTotalResults();
                if (!$hits) {
                    return false;
                }
            }
        }
        $exclude = (string) $settings->get('iiifsearch_extract_query_exclude', '');
        if ($exclude !== '') {
            $q = [];
            parse_str($exclude, $q);
            if ($q) {
                $q['id'] = $itemId;
                $hits = $api->search('items', $q, ['initialize' => false, 'returnScalar' => 'id'])->getTotalResults();
                if ($hits) {
                    return false;
                }
            }
        }
        return true;
    }

    protected function canvasPageIndex(
        \Omeka\Api\Representation\ItemRepresentation $item,
        \Omeka\Api\Representation\AbstractResourceEntityRepresentation $media
    ): ?int {
        $services = $this->getServiceLocator();
        $helpers = $services->get('ViewHelperManager');
        $resources = $helpers->has('itemFileResources')
            ? $helpers->get('itemFileResources')($item)
            : null;
        if ($resources === null) {
            $resources = [];
            foreach ($item->media() as $m) {
                $resources[] = $m;
            }
        }
        $i = 0;
        foreach ($resources as $r) {
            $type = method_exists($r, 'mediaType') ? $r->mediaType() : null;
            if ($type === 'application/alto+xml') {
                continue;
            }
            $id = method_exists($r, 'id') ? $r->id() : $r->inner()->id();
            if ($id === $media->id()) {
                return $i;
            }
            $i++;
        }
        return null;
    }

    /**
     * Probe the optional command-line tools the OCR pipeline relies on and
     * surface their availability in the admin via the messenger.
     *
     *  - poppler-utils (pdftotext, pdftohtml): required to extract OCR from
     *    text-bearing PDFs.
     *  - tesseract: required to OCR images of pages that do not ship with a
     *    text layer (book scans, microfilm captures, etc.).
     *  - ocrmypdf: optional add-on that re-OCRs PDFs without a text layer
     *    before pdftohtml is invoked.
     *
     * The probe is cached 1 h in iiifsearch_audit_ocr_tools so each config form
     * open does not fork four shell processes.
     */
    protected function checkOcrTools(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $cacheKey = 'iiifsearch_audit_ocr_tools';
        $cached = $settings->get($cacheKey);
        if (!is_array($cached)
            || !isset($cached['at'])
            || (time() - (int) $cached['at']) >= 3600
        ) {
            $tools = [];
            $cli = $services->get('Omeka\Cli');
            foreach (['pdftotext', 'pdftohtml', 'tesseract', 'ocrmypdf', 'gs', 'img2pdf'] as $tool) {
                // Cli::getCommandPath() returns the resolved path when the tool
                // is on PATH, false otherwise. It goes through Omeka's strategy
                // resolver, which honours disable_functions=shell_exec by
                // switching to proc_open (so the audit keeps working on
                // hardened php.ini).
                $path = $cli->getCommandPath($tool);
                $tools[$tool] = $path ?: null;
            }
            $cached = ['at' => time(), 'tools' => $tools];
            $settings->set($cacheKey, $cached);
        }
        $tools = (array) ($cached['tools'] ?? []);

        $present = [];
        $missing = [];
        foreach ($tools as $tool => $path) {
            if ($path) {
                $present[] = $tool . ' (' . $path . ')';
            } else {
                $missing[] = $tool;
            }
        }

        if ($present) {
            $messenger->addSuccess(new PsrMessage(
                'OCR tools available: {tools}.', // @translate
                ['tools' => implode(', ', $present)]
            ));
        }
        $missingRequired = array_intersect($missing, ['pdftotext', 'pdftohtml']);
        if ($missingRequired) {
            $messenger->addError(new PsrMessage(
                'Missing required OCR tools: {tools}. Install package poppler-utils.', // @translate
                ['tools' => implode(', ', $missingRequired)]
            ));
        }
        $missingOptional = array_intersect($missing, ['tesseract', 'ocrmypdf', 'gs', 'img2pdf']);
        if ($missingOptional) {
            $messenger->addWarning(new PsrMessage(
                'Optional OCR tools not installed: {tools}. tesseract is needed to OCR images of pages without a text layer; ocrmypdf and ghostscript (gs) are needed to rebuild a PDF with an embedded text layer (and to concatenate mono-page PDFs); img2pdf is needed to build a searchable PDF from items that carry only images (no PDF).', // @translate
                ['tools' => implode(', ', $missingOptional)]
            ));
        }
    }

    protected function postInstall(): void
    {
        $this->postInstallAuto();
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        // Soft requirement: OCR extraction features need poppler-utils. Probe
        // via Omeka's Cli so disable_functions=shell_exec hosts still get a
        // meaningful answer (Cli falls back to proc_open).
        $cli = $services->get('Omeka\Cli');
        if (!$cli->getCommandPath('pdftotext')) {
            $logger->warn(new Message(
                'The command-line utility pdftotext is not available; OCR extraction will be disabled until package poppler-utils is installed.' // @translate
            ));
        }
        if (!$cli->getCommandPath('pdftohtml')) {
            $logger->warn(new Message(
                'The command-line utility pdftohtml is not available; OCR extraction will be disabled until package poppler-utils is installed.' // @translate
            ));
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        foreach (['temp', 'iiif-search', 'iiif-search-audit', 'alto', 'hocr', 'pdf2xml'] as $sub) {
            if (!$this->checkDestinationDir($basePath . '/' . $sub)) {
                $logger->warn(new Message(
                    'The directory "%s" is not writeable; OCR extraction features may not work.', // @translate
                    $basePath . '/' . $sub
                ));
            }
        }

        $this->allowFileFormats();

        // Default OCR destinations and pdf content store. The four per-format
        // destination lists are set independently so a partial admin override
        // is preserved.
        $settings = $services->get('Omeka\Settings');
        $defaults = [
            'iiifsearch_extract_dests_tsv' => ['file'],
            'iiifsearch_extract_dests_tsv_by_word' => ['file'],
            'iiifsearch_extract_dests_alto' => ['file'],
            'iiifsearch_extract_dests_pdf2xml' => [],
        ];
        foreach ($defaults as $key => $value) {
            if ($settings->get($key) === null) {
                $settings->set($key, $value);
            }
        }
        if (!$settings->get('iiifsearch_extract_content_store')) {
            $settings->set('iiifsearch_extract_content_store', ['pdf']);
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
        $services = $this->getServiceLocator();
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);

        $cleanupParams = $params['iiifsearch_cleanup'] ?? [];
        if (!empty($cleanupParams['cleanup'])
            && $cleanupParams['cleanup'] === $controller->translate('Clean up')
        ) {
            $cleanupArgs = [
                'targets' => (array) ($cleanupParams['targets'] ?? []),
                // Chained extract: CleanupMedia dispatches the follow-up
                // ExtractOcr job at the end of its own perform(), so the two
                // jobs run sequentially even when the queue worker would
                // otherwise pull them concurrently.
                'rerun_extract' => !empty($cleanupParams['rerun_extract']),
                'rerun_base_uri' => $this->getBaseUri(),
            ];
            $cleanupJob = $dispatcher->dispatch(\IiifSearch\Job\CleanupMedia::class, $cleanupArgs);
            $message = $this->buildJobLinkMessage(
                $controller,
                !empty($cleanupParams['rerun_extract'])
                    ? 'Cleaning up legacy module-created medias in background; the OCR re-extraction job will be dispatched automatically when this one ends (job %1$s#%2$s%3$s, %4$slogs%3$s).' // @translate
                    : 'Cleaning up legacy module-created medias in background (job %1$s#%2$s%3$s, %4$slogs%3$s).', // @translate
                $cleanupJob->getId()
            );
            $controller->messenger()->addSuccess($message);
            return true;
        }

        $cleanupOcrFilesParams = $params['iiifsearch_cleanup_ocr_files'] ?? [];
        if (!empty($cleanupOcrFilesParams['cleanup_ocr_files'])
            && $cleanupOcrFilesParams['cleanup_ocr_files'] === $controller->translate('Clean up OCR files')
        ) {
            $job = $dispatcher->dispatch(\IiifSearch\Job\CleanupOcrFiles::class, []);
            $message = $this->buildJobLinkMessage(
                $controller,
                'Cleaning up OCR files of excluded items in background (job %1$s#%2$s%3$s, %4$slogs%3$s).', // @translate
                $job->getId()
            );
            $controller->messenger()->addSuccess($message);
            return true;
        }

        $extractParams = $params['iiifsearch_bulk']
            ?? $params['iiifsearch_extract']
            ?? [];
        if (empty($extractParams['process'])
            || $extractParams['process'] !== $controller->translate('Process')
        ) {
            return true;
        }

        $args = [
            'mode' => $extractParams['mode'] ?? 'all',
            'base_uri' => $this->getBaseUri(),
            // 'item_ids' is kept available as an alternate selector for other
            // entry points (api.create.post, easyAdmin batch, CLI scripts).
            'item_ids' => $extractParams['item_ids'] ?? '',
            'query' => $extractParams['query'] ?? '',
        ];
        $job = $dispatcher->dispatch(\IiifSearch\Job\ExtractOcr::class, $args);

        $message = $this->buildJobLinkMessage(
            $controller,
            'Creating Extract OCR files in background (job %1$s#%2$s%3$s, %4$slogs%3$s).', // @translate
            $job->getId()
        );
        $controller->messenger()->addSuccess($message);
        return true;
    }

    /**
     * Build a translatable message with HTML links to the job page and its log
     * (Log module if present, otherwise the core job log).
     */
    protected function buildJobLinkMessage(AbstractController $controller, string $template, int $jobId): Message
    {
        $jobUrl = $controller->url()->fromRoute('admin/id', [
            'controller' => 'job', 'id' => $jobId,
        ]);
        $logUrl = class_exists('Log\Module', false)
            ? $controller->url()->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $jobId]])
            : $controller->url()->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $jobId]);
        $message = new Message(
            $template,
            sprintf('<a href="%s">', htmlspecialchars($jobUrl)),
            $jobId,
            '</a>',
            sprintf('<a href="%s"%s>', $logUrl, class_exists('Log\Module', false) ? '' : ' target="_blank"')
        );
        $message->setEscapeHtml(false);
        return $message;
    }

    /**
     * Check if the ocr of a page is free, so readable by anybody.
     *
     * The ocr follows the image of the page, or the item when there is no
     * image, like the plugin isAllowedOcr.
     */
    protected function hasFreeOcrPage(ItemRepresentation $item): bool
    {
        $plugins = $this->getServiceLocator()->get('ControllerPluginManager');
        if (!$plugins->has('accessStatus')) {
            return true;
        }
        $accessStatus = $plugins->get('accessStatus');
        $images = $this->getServiceLocator()->get('ViewHelperManager')->get('itemFileResources')->images($item);
        $resources = $images ? array_map(fn ($image) => $image->inner(), $images) : [$item];
        foreach ($resources as $resource) {
            $status = $accessStatus($resource);
            if (!$status || $status->getLevel() === \Access\Entity\AccessStatus::FREE) {
                return true;
            }
        }
        return false;
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
        $formatToSetting = [
            \IiifSearch\Job\ExtractOcr::FORMAT_TSV => 'iiifsearch_extract_dests_tsv',
            \IiifSearch\Job\ExtractOcr::FORMAT_TSV_BY_WORD => 'iiifsearch_extract_dests_tsv_by_word',
            \IiifSearch\Job\ExtractOcr::FORMAT_ALTO => 'iiifsearch_extract_dests_alto',
            \IiifSearch\Job\ExtractOcr::FORMAT_PDF2XML => 'iiifsearch_extract_dests_pdf2xml',
        ];
        $hasFileDest = false;
        $hasResourceDest = false;
        $targetTypesFiles = [];
        $targetTypesMedia = [];
        foreach ($formatToSetting as $format => $key) {
            $dests = (array) ($settings->get($key) ?: []);
            if (in_array('file', $dests, true)) {
                $hasFileDest = true;
                $targetTypesFiles[] = $format;
            }
            if (in_array('media', $dests, true) || in_array('digital_object', $dests, true)) {
                $hasResourceDest = true;
                $targetTypesMedia[] = $format;
            }
        }
        $targetContentStore = $settings->get('iiifsearch_extract_content_store') ?: [];
        $targetContentStore = array_intersect($targetContentStore, ['item', 'pdf', 'extracted']);
        if (!$hasFileDest && !$hasResourceDest && !$targetContentStore) {
            return;
        }

        // Per-item policy: include / exclude Omeka queries. An item with images
        // that does not match the OCR policy (paintings, photographs, 3D
        // objects, etc.) must not trigger an extraction.
        if (!$this->itemMatchesOcrPolicy($item->getId(), $settings)) {
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

    /**
     * Create the directory when needed and deny its direct web access.
     *
     * The ocr files (alto, hocr, pdf2xml, tsv) are derivative files that are
     * never medias, so they have no storage id and the module Access cannot
     * check their rights. They are served only through the controller of the
     * module, that checks the rights of the user page by page.
     */
    protected function checkDestinationDir(string $dirPath): ?string
    {
        return $this->getServiceLocator()->get('Common\DirectoryManager')
            ->checkDestinationDir($dirPath, true);
    }
}
