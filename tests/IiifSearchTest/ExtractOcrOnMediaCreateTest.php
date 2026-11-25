<?php declare(strict_types=1);

namespace IiifSearchTest;

use CommonTest\AbstractHttpControllerTestCase;
use Laminas\EventManager\Event;
use Omeka\Api\Response;

/**
 * A pdf added alone to an item, for example via the api, is extracted too.
 */
class ExtractOcrOnMediaCreateTest extends AbstractHttpControllerTestCase
{
    protected $itemId;

    protected $previousDests;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
        $services = $this->getApplicationServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $this->previousDests = $settings->get('iiifsearch_extract_dests_tsv');
        $settings->set('iiifsearch_extract_dests_tsv', ['file']);
        $this->itemId = (int) $services->get('Omeka\ApiManager')->create('items', [])->getContent()->id();
        $this->pendingDispatches(true);
    }

    public function tearDown(): void
    {
        $this->pendingDispatches(true);
        $this->loginAsAdmin();
        $services = $this->getApplicationServiceLocator();
        $services->get('Omeka\Settings')->set('iiifsearch_extract_dests_tsv', $this->previousDests);
        $services->get('Omeka\ApiManager')->delete('items', $this->itemId);
        parent::tearDown();
    }

    public function testPdfAddedAloneIsExtracted(): void
    {
        $this->triggerMediaCreate('upload');
        $this->assertSame([\IiifSearch\Job\ExtractOcr::class], $this->pendingDispatches());
    }

    public function testOutputOfExtractionIsSkipped(): void
    {
        $this->triggerMediaCreate('iiifsearch_ocr');
        $this->assertSame([], $this->pendingDispatches());
    }

    protected function triggerMediaCreate(string $ingester): void
    {
        $services = $this->getApplicationServiceLocator();
        $connection = $services->get('Omeka\Connection');
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $connection->executeStatement(
            'INSERT INTO `resource` (`resource_type`, `is_public`, `created`, `modified`) VALUES (?, 1, ?, ?)',
            [\Omeka\Entity\Media::class, $now, $now]
        );
        $mediaId = (int) $connection->lastInsertId();
        $connection->executeStatement(
            'INSERT INTO `media` (`id`, `item_id`, `ingester`, `renderer`, `media_type`, `storage_id`, `extension`, `position`, `has_original`, `has_thumbnails`, `source`)'
            . ' VALUES (?, ?, ?, "file", "application/pdf", ?, "pdf", 1, 1, 0, "test.pdf")',
            [$mediaId, $this->itemId, $ingester, bin2hex(random_bytes(20))]
        );

        $entityManager = $services->get('Omeka\EntityManager');
        $entityManager->clear();
        $media = $entityManager->find(\Omeka\Entity\Media::class, $mediaId);
        $module = $services->get('ModuleManager')->getModule('IiifSearch');
        $module->setServiceLocator($services);
        $module->extractOcrOfMedia(new Event('api.create.post', null, ['response' => new Response($media)]));
    }

    protected function pendingDispatches(bool $reset = false): array
    {
        $deferred = $this->getApplicationServiceLocator()->get('Common\DeferredJobDispatch');
        $property = new \ReflectionProperty($deferred, 'pending');
        $property->setAccessible(true);
        $classes = array_values(array_filter(
            array_column($property->getValue($deferred), 'class'),
            fn ($class) => strpos($class, 'IiifSearch\\') === 0
        ));
        if ($reset) {
            $property->setValue($deferred, []);
        }
        return $classes;
    }
}
