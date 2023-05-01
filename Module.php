<?php declare(strict_types=1);

namespace IiifSearch;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;

use Laminas\Mvc\Controller\AbstractController;
use IiifSearch\Form\ConfigForm;
use Laminas\View\Renderer\PhpRenderer;

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

        // Checking if resource has at least an XML file that will allow search.
        $searchServiceAvailable = false;
        $searchMediaTypes = [
            'application/alto+xml',
            'application/vnd.pdf2xml+xml',
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
            $manifest
                // Use of "@" is slightly more compatible with old viewers.
                ->appendService(new \IiifServer\Iiif\Service($resource, [
                    '@context' => 'http://iiif.io/api/search/0/context.json',
                    '@id' => $urlHelper('iiifsearch', ['id' => $identifier], ['force_canonical' => true]),
                    '@type' => 'SearchService1',
                    'profile' => 'http://iiif.io/api/search/0/search',
                    'label' => 'Search within this manifest', // @translate
                ]))
                /*
                ->appendService(new \IiifServer\Iiif\Service($resource, [
                    '@context' => 'http://iiif.io/api/search/1/context.json',
                    'id' => $urlHelper('iiifsearch/search', ['id' => $identifier], ['force_canonical' => true]),
                    'type' => 'SearchService1',
                    'profile' => 'http://iiif.io/api/search/1/search',
                    'label' => 'Search within this manifest', // @translate
                ]))
                */
            ;
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
