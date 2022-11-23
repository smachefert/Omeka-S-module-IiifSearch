<?php declare(strict_types=1);

namespace IiifSearch;

use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $serviceLocator
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$services = $serviceLocator;
$plugins = $services->get('ControllerPluginManager');
// $api = $plugins->get('api');
// $config = require dirname(dirname(__DIR__)) . '/config/module.config.php';
$settings = $services->get('Omeka\Settings');
// $connection = $services->get('Omeka\Connection');
// $entityManager = $services->get('Omeka\EntityManager');
$messenger = $plugins->get('messenger');

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
