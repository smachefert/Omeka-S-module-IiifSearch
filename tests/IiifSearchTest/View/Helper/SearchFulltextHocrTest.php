<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use IiifSearchTest\IiifSearchTestTrait;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests the hits of searchFullTextHocr() via reflection.
 *
 * Uses sample.hocr.html with 2 pages:
 * - Page 1: "The quick brown fox jumps over the lazy dog"
 * - Page 2: "Second page content fox"
 */
class SearchFulltextHocrTest extends TestCase
{
    use IiifSearchTestTrait;

    protected function setProperty(object $helper, string $name, $value): void
    {
        $ref = new ReflectionProperty(get_class($helper), $name);
        $ref->setAccessible(true);
        $ref->setValue($helper, $value);
    }

    protected function buildHocrHelper(array $queryWords): object
    {
        $helper = $this->createHelperStub();

        $item = $this->createMock(\Omeka\Api\Representation\ItemRepresentation::class);
        $item->method('id')->willReturn(42);
        $this->setProperty($helper, 'item', $item);

        $this->setProperty($helper, 'imageSizes', [
            0 => ['id' => 101, 'width' => 2480, 'height' => 3508],
            1 => ['id' => 102, 'width' => 2480, 'height' => 3508],
        ]);
        $this->setProperty($helper, 'queryWords', $queryWords);
        $this->setProperty($helper, 'indexFilePath', $this->getFixturePath('sample.hocr.html'));
        $this->setProperty($helper, 'baseResultUrl', 'http://example.com/iiif/42/annotation/search-result/');
        $this->setProperty($helper, 'baseCanvasUrl', 'http://example.com/iiif/42/canvas/p');

        $logger = $this->createMock(\Laminas\Log\Logger::class);
        $this->setProperty($helper, 'logger', $logger);

        return $helper;
    }

    public function testHocrSearchHitsHaveContext(): void
    {
        $helper = $this->buildHocrHelper(['fox']);
        $result = $this->callProtected($helper, 'searchFullTextHocr', []);
        $this->assertSame(2, $result['hit']);
        $this->assertSame([
            'hits' => [1],
            'match' => 'fox',
            'before' => 'The quick brown ',
            'after' => ' jumps over the lazy dog',
        ], $result['hits'][0]);
        $this->assertSame([
            'hits' => [2],
            'match' => 'fox',
            'before' => 'Second page content ',
            'after' => null,
        ], $result['hits'][1]);
    }
}
