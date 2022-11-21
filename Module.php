<?php declare(strict_types=1);

namespace IiifSearch;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
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

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator): void
    {
        $filepath = __DIR__ . '/data/scripts/upgrade.php';
        $this->setServiceLocator($serviceLocator);
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

        $plugins = $this->getServiceLocator()->get('ViewHelperManager');
        $urlHelper = $plugins->get('url');
        $identifier = $plugins->has('iiifCleanIdentifiers')
            ? $plugins->get('iiifCleanIdentifiers')->__invoke($resource->id())
            : $resource->id();

        /** @var \IiifServer\Iiif\Manifest $manifest */
        $manifest = $event->getParam('manifest');

        // Manage last or recent version of module Iiif Server.
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
}
