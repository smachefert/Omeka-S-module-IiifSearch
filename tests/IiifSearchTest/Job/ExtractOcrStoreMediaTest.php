<?php declare(strict_types=1);

namespace IiifSearchTest\Job;

use IiifSearch\Job\ExtractOcr;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * The extracted file is attached as media without http request, so it works
 * when the web server denies the access to files/temp/.
 */
class ExtractOcrStoreMediaTest extends AbstractHttpControllerTestCase
{
    protected ?int $itemId = null;

    public function setUp(): void
    {
        parent::setUp();
        $auth = $this->getApplicationServiceLocator()->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
        $this->itemId = (int) $this->api()->create('items', [
            'o:is_public' => true,
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>Page</p>']],
        ])->getContent()->id();
    }

    public function tearDown(): void
    {
        $this->getApplicationServiceLocator()->get('Omeka\EntityManager')->clear();
        $this->api()->delete('items', $this->itemId);
        parent::tearDown();
    }

    public function testExtractedFileIsAttachedAsLastMedia(): void
    {
        $services = $this->getApplicationServiceLocator();
        $tempFile = $services->get('Omeka\File\TempFileFactory')->build();
        file_put_contents($tempFile->getTempPath(), '<?xml version="1.0" encoding="UTF-8"?><alto xmlns="http://www.loc.gov/standards/alto/ns-v3#"><Layout><Page ID="p1"/></Layout></alto>');
        $item = $this->api()->read('items', $this->itemId)->getContent();

        $media = $this->job()->proxyStoreFileInMedia($tempFile, $item);
        $tempFile->delete();

        $this->assertNotNull($media);
        $this->assertTrue($media->hasOriginal());
        $this->assertSame('application/alto+xml', $media->mediaType());
        $this->assertSame('item-' . $this->itemId . '.' . $this->itemId . '.alto.xml', $media->source());

        $services->get('Omeka\EntityManager')->clear();
        $medias = $this->api()->read('items', $this->itemId)->getContent()->media();
        $this->assertCount(2, $medias);
        $this->assertSame($media->id(), end($medias)->id());
    }

    public function testExtractedFileIsAttachedAsDigitalObject(): void
    {
        if (!class_exists('DigitalObject\Module', false)) {
            $this->markTestSkipped('Requires module DigitalObject.');
        }
        $services = $this->getApplicationServiceLocator();
        $tempFile = $services->get('Omeka\File\TempFileFactory')->build();
        file_put_contents($tempFile->getTempPath(), '<?xml version="1.0" encoding="UTF-8"?><alto xmlns="http://www.loc.gov/standards/alto/ns-v3#"><Layout><Page ID="p1"/></Layout></alto>');
        $item = $this->api()->read('items', $this->itemId)->getContent();

        $job = $this->job();
        $job->setSaveAs('digital_object');
        $do = $job->proxyStoreFileInMedia($tempFile, $item);

        $this->assertNotNull($do);
        $this->assertSame('digital_objects', $do->resourceName());
        $this->assertTrue($do->hasOriginal());
        $this->assertSame('application/alto+xml', $do->mediaType());
        // The temp file belongs to the job.
        $this->assertFileExists($tempFile->getTempPath());
        $tempFile->delete();
        $this->api()->delete('digital_objects', $do->id());
    }

    protected function job(): ExtractOcrStoreMediaProxy
    {
        $services = $this->getApplicationServiceLocator();
        $job = (new \ReflectionClass(ExtractOcrStoreMediaProxy::class))->newInstanceWithoutConstructor();
        $job->init(
            $services,
            new Logger(['writers' => [['name' => Noop::class]]])
        );
        return $job;
    }

    protected function api(): \Omeka\Api\Manager
    {
        return $this->getApplicationServiceLocator()->get('Omeka\ApiManager');
    }
}

class ExtractOcrStoreMediaProxy extends ExtractOcr
{
    public function init($services, $logger): void
    {
        $this->serviceLocator = $services;
        $this->api = $services->get('Omeka\ApiManager');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->logger = $logger;
        $this->saveAs = 'media';
        $this->targetExtension = 'alto.xml';
        $this->targetMediaType = 'application/alto+xml';
    }

    public function setSaveAs(string $saveAs): void
    {
        $this->saveAs = $saveAs;
    }

    public function proxyStoreFileInMedia(\Omeka\File\TempFile $tempFile, ItemRepresentation $item)
    {
        return $this->storeFileInMedia($tempFile, null, $item);
    }
}
