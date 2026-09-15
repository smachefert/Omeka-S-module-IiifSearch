<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use IiifSearchTest\IiifSearchTestTrait;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests the searchMediaValues() method via reflection with mocked API.
 *
 * searchMediaValues() searches media metadata (not fulltext indexes) and
 * returns annotation results for media whose values match the query.
 */
class SearchMediaValuesTest extends TestCase
{
    use IiifSearchTestTrait;

    protected function setProperty(object $helper, string $name, $value): void
    {
        $ref = new ReflectionProperty(get_class($helper), $name);
        $ref->setAccessible(true);
        $ref->setValue($helper, $value);
    }

    /**
     * Build a helper stub with mocked API returning the given media IDs.
     */
    protected function buildHelper(
        string $query,
        array $returnedMediaIds,
        array $imageSizes
    ): object {
        $helper = $this->createHelperStub();

        $item = $this->createMock(\Omeka\Api\Representation\ItemRepresentation::class);
        $item->method('id')->willReturn(42);
        $this->setProperty($helper, 'item', $item);
        $this->setProperty($helper, 'query', $query);
        $this->setProperty($helper, 'imageSizes', $imageSizes);

        // Mock API.
        $response = $this->createMock(Response::class);
        $response->method('getContent')->willReturn($returnedMediaIds);
        $api = $this->createMock(ApiManager::class);
        $api->method('search')->willReturn($response);
        $this->setProperty($helper, 'api', $api);

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

        return $helper;
    }

    public function testSearchMediaValuesReturnsResults(): void
    {
        $imageSizes = [
            0 => ['id' => 101, 'width' => 800, 'height' => 600],
            1 => ['id' => 102, 'width' => 800, 'height' => 600],
        ];
        $helper = $this->buildHelper('test query', [101, 102], $imageSizes);
        $result = $this->callProtected($helper, 'searchMediaValues', [0, []]);
        $this->assertNotNull($result);
        $this->assertSame(2, $result['hit']);
        $this->assertCount(2, $result['resources']);
    }

    public function testSearchMediaValuesHitOffset(): void
    {
        $imageSizes = [
            0 => ['id' => 101, 'width' => 800, 'height' => 600],
        ];
        // Start hit at 5 (previous fulltext results).
        $helper = $this->buildHelper('test', [101], $imageSizes);
        $result = $this->callProtected($helper, 'searchMediaValues', [5, []]);
        $this->assertNotNull($result);
        // hit counter in result is local (1 new hit).
        $this->assertSame(1, $result['hit']);
    }

    public function testSearchMediaValuesExcludesAlreadyFound(): void
    {
        $imageSizes = [
            0 => ['id' => 101, 'width' => 800, 'height' => 600],
            1 => ['id' => 102, 'width' => 800, 'height' => 600],
        ];
        // Media 101 already found in fulltext, should be excluded.
        $helper = $this->buildHelper('test', [101, 102], $imageSizes);
        $result = $this->callProtected($helper, 'searchMediaValues', [0, [101]]);
        $this->assertNotNull($result);
        $this->assertSame(1, $result['hit']);
    }

    public function testSearchMediaValuesCompactKeys(): void
    {
        $imageSizes = [
            0 => ['id' => 101, 'width' => 800, 'height' => 600],
        ];
        $helper = $this->buildHelper('test', [101], $imageSizes);
        $result = $this->callProtected($helper, 'searchMediaValues', [0, []]);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('resources', $result);
        $this->assertArrayHasKey('hit', $result);
        // Each resource carries the neutral pivot keys consumed by builders.
        $first = $result['resources'][0];
        $this->assertArrayHasKey('image', $first);
        $this->assertArrayHasKey('page', $first);
        $this->assertArrayHasKey('zone', $first);
        $this->assertArrayHasKey('chars', $first);
        $this->assertArrayHasKey('hit', $first);
    }

    public function testSearchMediaValuesEmptyQuery(): void
    {
        $imageSizes = [
            0 => ['id' => 101, 'width' => 800, 'height' => 600],
        ];
        $helper = $this->buildHelper('', [101], $imageSizes);
        $result = $this->callProtected($helper, 'searchMediaValues', [0, []]);
        $this->assertNull($result);
    }
}
