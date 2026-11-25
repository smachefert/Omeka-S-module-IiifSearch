<?php declare(strict_types=1);

namespace IiifSearchTest\Stdlib;

use IiifSearch\Stdlib\ContentVisibility;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Visibility of the extracted text according to the module Access.
 *
 * @group contentvisibility
 */
class ContentVisibilityTest extends AbstractHttpControllerTestCase
{
    protected array $resourceIds = [];

    protected array $previousSettings = [];

    public function setUp(): void
    {
        parent::setUp();
        $services = $this->getApplicationServiceLocator();
        if (!$services->has(\Access\Stdlib\AccessCascade::class)) {
            $this->markTestSkipped('Requires module Access.');
        }
        $this->loginAdmin();
        $settings = $services->get('Omeka\Settings');
        foreach ([
            'iiifsearch_extract_content_visibility' => 'access',
            'iiifsearch_extract_content_property' => 'bibo:content',
            'access_property' => false,
        ] as $name => $value) {
            $this->previousSettings[$name] = $settings->get($name);
            $settings->set($name, $value);
        }
    }

    public function tearDown(): void
    {
        $services = $this->getApplicationServiceLocator();
        $this->loginAdmin();
        foreach (array_reverse($this->resourceIds) as $id) {
            try {
                $services->get('Omeka\ApiManager')->delete('items', $id);
            } catch (\Throwable $e) {
            }
        }
        $settings = $services->get('Omeka\Settings');
        foreach ($this->previousSettings as $name => $value) {
            $settings->set($name, $value);
        }
        parent::tearDown();
    }

    public function testTextOfReservedItemBecomesPrivate(): void
    {
        $itemId = $this->createItem('reserved');
        $this->assertFalse($this->isTextPublic($itemId));
    }

    public function testTextOfFreedItemBecomesPublic(): void
    {
        $itemId = $this->createItem('reserved');
        $this->getApplicationServiceLocator()->get('Omeka\ApiManager')->update(
            'items',
            $itemId,
            ['access' => ['o-access:level' => 'free']],
            [],
            ['isPartial' => true]
        );
        $this->assertTrue($this->isTextPublic($itemId));
    }

    public function testTextOfItemWithReservedImageIsPrivate(): void
    {
        $itemId = $this->createItem('free', true);
        $mediaId = $this->firstMediaId($itemId);
        $this->assertTrue($this->isTextPublic($itemId));

        $this->setStatus($mediaId, 'reserved');
        $this->update([$mediaId]);
        $this->assertFalse($this->isTextPublic($itemId));
    }

    public function testTextOfItemUnderEmbargoIsPrivate(): void
    {
        $itemId = $this->createItem('free');
        $this->setStatus($itemId, 'free', '-1 day', '+1 day');
        $this->update([$itemId]);
        $this->assertFalse($this->isTextPublic($itemId));

        $this->setStatus($itemId, 'free', '-2 days', '-1 day');
        $this->update(null);
        $this->assertTrue($this->isTextPublic($itemId));
    }

    public function testTextIsKeptWhenOptionIsNotAccess(): void
    {
        $this->getApplicationServiceLocator()->get('Omeka\Settings')
            ->set('iiifsearch_extract_content_visibility', 'public');
        $itemId = $this->createItem('reserved');
        $this->assertTrue($this->isTextPublic($itemId));
    }

    protected function createItem(string $level, bool $withImage = false): int
    {
        $data = [
            'o:is_public' => true,
            'bibo:content' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId(),
                '@value' => 'Extracted text',
                'is_public' => true,
            ]],
            'access' => ['o-access:level' => $level],
        ];
        if ($withImage) {
            $data['o:media'] = [[
                'o:ingester' => 'html',
                'html' => '<p>Page</p>',
                'o:is_public' => true,
            ]];
        }
        $item = $this->getApplicationServiceLocator()->get('Omeka\ApiManager')
            ->create('items', $data)->getContent();
        $this->resourceIds[] = $item->id();
        if ($withImage) {
            // Fake an image file, enough for the check.
            $this->connection()->executeStatement(
                'UPDATE `media` SET `media_type` = "image/jpeg", `has_original` = 1 WHERE `item_id` = ?',
                [$item->id()]
            );
        }
        return (int) $item->id();
    }

    protected function setStatus(int $resourceId, string $level, ?string $embargoStart = null, ?string $embargoEnd = null): void
    {
        $this->connection()->executeStatement(
            'UPDATE `access_status` SET `level_set` = ?, `level` = ?, `embargo_start` = ?, `embargo_end` = ? WHERE `id` = ?',
            [
                $level,
                $level,
                $embargoStart ? date('Y-m-d H:i:s', strtotime($embargoStart)) : null,
                $embargoEnd ? date('Y-m-d H:i:s', strtotime($embargoEnd)) : null,
                $resourceId,
            ]
        );
    }

    protected function update(?array $resourceIds): void
    {
        $this->getApplicationServiceLocator()->get(ContentVisibility::class)
            ->update($this->propertyId(), $resourceIds);
    }

    protected function isTextPublic(int $resourceId): bool
    {
        return (bool) $this->connection()->executeQuery(
            'SELECT `is_public` FROM `value` WHERE `resource_id` = ? AND `property_id` = ?',
            [$resourceId, $this->propertyId()]
        )->fetchOne();
    }

    protected function firstMediaId(int $itemId): int
    {
        return (int) $this->connection()->executeQuery(
            'SELECT `id` FROM `media` WHERE `item_id` = ? ORDER BY `position` LIMIT 1',
            [$itemId]
        )->fetchOne();
    }

    protected function propertyId(): int
    {
        return (int) $this->getApplicationServiceLocator()->get('Common\EasyMeta')->propertyId('bibo:content');
    }

    protected function connection(): \Doctrine\DBAL\Connection
    {
        return $this->getApplicationServiceLocator()->get('Omeka\Connection');
    }

    protected function loginAdmin(): void
    {
        $auth = $this->getApplicationServiceLocator()->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }
}
