<?php declare(strict_types=1);

namespace IiifSearch;

use IiifSearch\Form\ConfigForm;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(null, 'IiifSearch\Controller\Search');
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services): void
    {
        $filepath = __DIR__ . '/data/scripts/upgrade.php';
        $this->setServiceLocator($services);
        require_once $filepath;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            '*',
            'iiifserver.manifest',
            [$this, 'handleIiifServerManifest']
        );
    }

    public function handleIiifServerManifest(Event $event): void
    {
        // Target is the view.
        // Available keys: "format", the manifest, info etc according to format, "resource", "type".

        // This is the iiif type, not omeka one.
        $type = $event->getParam('type');
        if ($type !== 'item') {
            return;
        }

        $resource = $event->getParam('resource');

        // Check first if there is a simple file with data (see module ExtractOcr).
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $simpleFilepath = $basePath . '/iiif-search/' . $resource->id() . '.tsv';
        if (!file_exists($simpleFilepath)) {
            $simpleFilepath = $basePath . '/iiif-search/' . $resource->id() . '.xml';
            if (!file_exists($simpleFilepath)) {
                $simpleFilepath = null;
            }
        }

        // Else check if resource has at least one XML file for search.
        if (!$simpleFilepath) {
            $searchServiceAvailable = false;
            $searchMediaTypes = [
                'application/alto+xml',
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

        /** @var \IiifServer\Iiif\Manifest $manifest */
        $manifest = $event->getParam('manifest');

        // Manage last or recent version of module Iiif Server.
        // TODO Why profile is /0/?
        $isVersion2 = !is_object($manifest);
        if ($isVersion2) {
            $manifest['service'][] = [
                '@context' => 'http://iiif.io/api/search/0/context.json',
                '@id' => $urlHelper('iiifsearch', ['id' => $identifier], ['force_canonical' => true]),
                'profile' => 'http://iiif.io/api/search/0/search',
                'label' => 'Search within this manifest', // @translate
            ];
        } else {
            // Use of "@" is slightly more compatible with old viewers.
            // The context is not required.
            // The SearchService0 is not an official service, but managed by
            // old versions of Universal Viewer and used by Wellcome library.
            $service0 = [
                '@context' => 'http://iiif.io/api/search/0/context.json',
                '@id' => $urlHelper('iiifsearch', ['id' => $identifier], ['force_canonical' => true]),
                '@type' => 'SearchService0',
                'profile' => 'http://iiif.io/api/search/0/search',
                'label' => 'Search within this manifest', // @translate
            ];
            $service1 = [
                '@context' => 'http://iiif.io/api/search/1/context.json',
                'id' => $urlHelper('iiifsearch/search', ['id' => $identifier], ['force_canonical' => true]),
                'type' => 'SearchService1',
                'profile' => 'http://iiif.io/api/search/1/search',
                'label' => 'Search within this manifest', // @translate
            ];
            // Check version of module IiifServer.
            if (method_exists($manifest, 'getPropertyRequirements')) {
                $manifest['service'][] = new \IiifServer\Iiif\Service($service0);
                $manifest['service'][] = new \IiifServer\Iiif\Service($service1);
            } else {
                $manifest
                    ->appendService(new \IiifServer\Iiif\Service($resource, $service0))
                    ->appendService(new \IiifServer\Iiif\Service($resource, $service1))
                ;
            }
        }

        $event->setParam('manifest', $manifest);
    }

    /**
     * getConfigForm
     *
     * @param  mixed $renderer
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();

        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $params = [];
        $params['iiifsearch_minimum_query_length'] = $settings->get('iiifsearch_minimum_query_length', 3);
        $params['iiifsearch_xml_image_match'] = $settings->get('iiifsearch_xml_image_match', 'order');
        $params['iiifsearch_xml_fix_mode'] = $settings->get('iiifsearch_xml_fix_mode', 'no');
        $form->init();
        $form->setData($params);
        return $renderer->formCollection($form);
    }

    /**
     * handleConfigForm
     *
     * @param  mixed $controller
     */
    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $params = $controller->getRequest()->getPost();
        $settings->set('iiifsearch_minimum_query_length', intval($params['iiifsearch_minimum_query_length']));
        $settings->set('iiifsearch_xml_image_match', $params['iiifsearch_xml_image_match']);
        $settings->set('iiifsearch_xml_fix_mode', $params['iiifsearch_xml_fix_mode']);
    }

    /**
     * install
     *
     * @param ServiceLocatorInterface $services
     */
    public function install(ServiceLocatorInterface $services): void
    {
        $settings = $services->get('Omeka\Settings');
        $settings->set("iiifsearch_minimum_query_length", 3);
    }

    /**
     * unistall
     *
     * @param ServiceLocatorInterface $services
     */
    public function uninstall(ServiceLocatorInterface $services): void
    {
        $settings = $services->get('Omeka\Settings');
        $settings->delete("iiifsearch_minimum_query_length");
    }
}
