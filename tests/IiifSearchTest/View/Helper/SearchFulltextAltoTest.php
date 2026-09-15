<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use IiifSearchTest\IiifSearchTestTrait;
use Laminas\View\Renderer\PhpRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SimpleXMLElement;

/**
 * Tests the searchFullTextAlto() method via reflection.
 *
 * Uses sample.alto.xml with 2 pages:
 * - Page 1: "The quick brown fox"
 * - Page 2: "Second page fox"
 */
class SearchFulltextAltoTest extends TestCase
{
    use IiifSearchTestTrait;

    /**
     * Set a protected property on the helper via reflection.
     */
    protected function setProperty(object $helper, string $name, $value): void
    {
        $ref = new ReflectionProperty(get_class($helper), $name);
        $ref->setAccessible(true);
        $ref->setValue($helper, $value);
    }

    /**
     * Create a helper stub with all dependencies injected for Alto search.
     */
    protected function buildAltoHelper(array $queryWords): object
    {
        $helper = $this->createHelperStub();

        // Mock item with id().
        $item = $this->createMock(\Omeka\Api\Representation\ItemRepresentation::class);
        $item->method('id')->willReturn(42);
        $this->setProperty($helper, 'item', $item);

        // Image sizes: 2 pages matching the Alto fixture dimensions.
        $this->setProperty($helper, 'imageSizes', [
            0 => ['id' => 101, 'width' => 2480, 'height' => 3508],
            1 => ['id' => 102, 'width' => 2480, 'height' => 3508],
        ]);

        $this->setProperty($helper, 'queryWords', $queryWords);
        $this->setProperty($helper, 'baseResultUrl', 'http://example.com/iiif/42/annotation/search-result/');
        $this->setProperty($helper, 'baseCanvasUrl', 'http://example.com/iiif/42/canvas/p');

        // Mock view with iiifUrl plugin.
        $view = $this->createMock(PhpRenderer::class);
        $iiifUrlPlugin = function () {
            return 'http://example.com/iiif/42';
        };
        $view->method('plugin')->with('iiifUrl')->willReturn($iiifUrlPlugin);
        $view->method('__call')->willReturnCallback(function ($method, $args) {
            return 'http://example.com/iiif/42';
        });
        $helper->setView($view);

        // Mock logger.
        $logger = $this->createMock(\Laminas\Log\Logger::class);
        $this->setProperty($helper, 'logger', $logger);

        return $helper;
    }

    protected function loadAltoXml(): SimpleXMLElement
    {
        $content = $this->getFixture('sample.alto.xml');
        return new SimpleXMLElement($content);
    }

    public function testAltoSearchFindsWordOnBothPages(): void
    {
        $helper = $this->buildAltoHelper(['fox']);
        $xml = $this->loadAltoXml();
        $result = $this->callProtected($helper, 'searchFullTextAlto', [$xml]);
        $this->assertNotNull($result);
        $this->assertSame(2, $result['hit']);
        $this->assertCount(2, $result['resources']);
    }

    public function testAltoSearchHitCounterIncrementsCorrectly(): void
    {
        $helper = $this->buildAltoHelper(['fox']);
        $xml = $this->loadAltoXml();
        $result = $this->callProtected($helper, 'searchFullTextAlto', [$xml]);
        // The overall hit counter should be 2 (one per page).
        $this->assertSame(2, $result['hit']);
        // There should be 2 SearchHit objects (one per page with matches).
        $this->assertCount(2, $result['hits']);
    }

    public function testAltoSearchZoneCoordinates(): void
    {
        $helper = $this->buildAltoHelper(['fox']);
        $xml = $this->loadAltoXml();
        $result = $this->callProtected($helper, 'searchFullTextAlto', [$xml]);
        // First result: page 1, "fox" at HPOS=550, VPOS=100, WIDTH=70,
        // HEIGHT=40.
        $first = $result['resources'][0];
        $this->assertSame('550', $first['zone']['left']);
        $this->assertSame('100', $first['zone']['top']);
        $this->assertSame('70', $first['zone']['width']);
        $this->assertSame('40', $first['zone']['height']);
    }

    public function testAltoSearchCaseInsensitive(): void
    {
        $helper = $this->buildAltoHelper(['FOX']);
        $xml = $this->loadAltoXml();
        $result = $this->callProtected($helper, 'searchFullTextAlto', [$xml]);
        $this->assertNotNull($result);
        $this->assertSame(2, $result['hit']);
    }

    public function testAltoSearchNoMatch(): void
    {
        $helper = $this->buildAltoHelper(['xyz']);
        $xml = $this->loadAltoXml();
        $result = $this->callProtected($helper, 'searchFullTextAlto', [$xml]);
        $this->assertNotNull($result);
        $this->assertSame(0, $result['hit']);
        $this->assertEmpty($result['resources']);
    }

    public function testAltoSearchPageNumbering(): void
    {
        $helper = $this->buildAltoHelper(['fox']);
        $xml = $this->loadAltoXml();
        $result = $this->callProtected($helper, 'searchFullTextAlto', [$xml]);
        // First hit is on page 1.
        $this->assertSame('1', $result['resources'][0]['page']['number']);
        // Second hit is on page 2.
        $this->assertSame('2', $result['resources'][1]['page']['number']);
    }

    public function testAltoSearchMediaIds(): void
    {
        $helper = $this->buildAltoHelper(['fox']);
        $xml = $this->loadAltoXml();
        $result = $this->callProtected($helper, 'searchFullTextAlto', [$xml]);
        $this->assertSame([101, 102], $result['media_ids']);
    }
}
