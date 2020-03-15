<?php
namespace IiifSearch;

use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\MvcEvent;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(null, 'IiifSearch\Controller\Search');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            '*',
            'iiifserver.manifest',
            [$this, 'handleIiifServerManifest']
        );
    }

    public function handleIiifServerManifest(Event $event)
    {
        $type = $event->getParam('type');
        if ($type !== 'item') {
            return;
        }

        $resource = $event->getParam('resource');

        // Checking if resource has at least an XML file that will allow search.
        $searchServiceAvailable = false;
        $searchMediaTypes = [
            'application/xml',
            'text/xml',
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

        $urlHelper = $this->getServiceLocator()->get('ViewHelperManager')->get('url');

        $manifest = $event->getParam('manifest');

        $manifest['service'][] = [
            '@context' => 'http://iiif.io/api/search/0/context.json',
            '@id' => $urlHelper('iiifsearch', ['id' => $resource->id()], ['force_canonical' => true]),
            'profile' => 'http://iiif.io/api/search/0/search',
            'label' => 'Search within this manifest', // @translate
        ];

        $event->setParam('manifest', $manifest);
    }
}
