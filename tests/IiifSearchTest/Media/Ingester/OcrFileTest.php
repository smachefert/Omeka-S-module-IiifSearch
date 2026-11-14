<?php declare(strict_types=1);

namespace IiifSearchTest\Media\Ingester;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * The extracted ocr is attached without http request, so the protection of the
 * directory files/temp/ does not prevent it.
 */
class OcrFileTest extends AbstractHttpControllerTestCase
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
        $this->itemId = (int) $this->api()->create('items', ['o:is_public' => true])->getContent()->id();
    }

    public function tearDown(): void
    {
        // The media were created separately, so reload the item with them.
        $this->getApplicationServiceLocator()->get('Omeka\EntityManager')->clear();
        $this->api()->delete('items', $this->itemId);
        parent::tearDown();
    }

    public function testTempFileIsAttached(): void
    {
        $tempFile = $this->getApplicationServiceLocator()->get('Omeka\File\TempFileFactory')->build();
        file_put_contents($tempFile->getTempPath(), '<?xml version="1.0" encoding="UTF-8"?><alto xmlns="http://www.loc.gov/standards/alto/ns-v3#"><Layout><Page ID="p1"/></Layout></alto>');

        $media = $this->api()->create('media', [
            'o:item' => ['o:id' => $this->itemId],
            'o:ingester' => 'iiifsearch_ocr',
            'ingest_tempfile' => $tempFile,
            'o:source' => 'document.' . $this->itemId . '.alto.xml',
        ])->getContent();
        $tempFile->delete();

        $this->assertTrue($media->hasOriginal());
        $this->assertSame('upload', $media->ingester());
        $this->assertSame('xml', $media->extension());
        $this->assertSame('document.' . $this->itemId . '.alto.xml', $media->source());
        $basePath = $this->getApplicationServiceLocator()->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $this->assertFileExists($basePath . '/original/' . $media->filename());
    }

    public function testFilepathIsRefused(): void
    {
        $this->expectException(\Omeka\Api\Exception\ValidationException::class);
        $this->api()->create('media', [
            'o:item' => ['o:id' => $this->itemId],
            'o:ingester' => 'iiifsearch_ocr',
            'ingest_tempfile' => '/etc/passwd',
        ]);
    }

    protected function api(): \Omeka\Api\Manager
    {
        return $this->getApplicationServiceLocator()->get('Omeka\ApiManager');
    }
}
