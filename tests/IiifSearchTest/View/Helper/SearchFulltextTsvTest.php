<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use IiifSearchTest\IiifSearchTestTrait;
use Laminas\View\Renderer\PhpRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests the searchFulltextTsv() method via reflection for by-word TSV.
 *
 * Uses sample-byword.tsv with entries:
 * fox  on pages 1 and 2
 * quick on page 1
 * brown on page 1
 * house with punctuation on page 3
 */
class SearchFulltextTsvTest extends TestCase
{
    use IiifSearchTestTrait;

    protected function setProperty(object $helper, string $name, $value): void
    {
        $ref = new ReflectionProperty(get_class($helper), $name);
        $ref->setAccessible(true);
        $ref->setValue($helper, $value);
    }

    /**
     * Create a helper stub with all dependencies injected for TSV search.
     */
    protected function buildTsvHelper(array $queryWords, string $query = ''): object
    {
        $helper = $this->createHelperStub();

        // Mock item with id().
        $item = $this->createMock(\Omeka\Api\Representation\ItemRepresentation::class);
        $item->method('id')->willReturn(42);
        $this->setProperty($helper, 'item', $item);

        // Image sizes: 3 pages.
        $this->setProperty($helper, 'imageSizes', [
            0 => ['id' => 101, 'width' => 2480, 'height' => 3508],
            1 => ['id' => 102, 'width' => 2480, 'height' => 3508],
            2 => ['id' => 103, 'width' => 2480, 'height' => 3508],
        ]);

        $this->setProperty($helper, 'queryWords', $queryWords);
        $this->setProperty($helper, 'query', $query ?: implode(' ', $queryWords));
        $this->setProperty($helper, 'queryIsExactSearch', false);

        // Mock mediaTsv (nullable).
        $mediaTsv = $this->createMock(\Omeka\Api\Representation\MediaRepresentation::class);
        $mediaTsv->method('id')->willReturn(201);
        $this->setProperty($helper, 'mediaTsv', $mediaTsv);

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

    public function testTsvByWordSearchFindsWord(): void
    {
        $helper = $this->buildTsvHelper(['fox']);
        $filepath = $this->getFixturePath('sample-byword.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, true]);
        $this->assertNotNull($result);
        $this->assertSame(2, $result['hit']);
        $this->assertCount(2, $result['resources']);
    }

    public function testTsvByWordHitCounter(): void
    {
        $helper = $this->buildTsvHelper(['fox']);
        $filepath = $this->getFixturePath('sample-byword.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, true]);
        // hit counter = total number of hits.
        $this->assertSame(2, $result['hit']);
    }

    public function testTsvByWordPageNumbering(): void
    {
        $helper = $this->buildTsvHelper(['fox']);
        $filepath = $this->getFixturePath('sample-byword.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, true]);
        // First hit on page 1, second on page 2.
        $this->assertSame('1', $result['resources'][0]['page']['number']);
        $this->assertSame('2', $result['resources'][1]['page']['number']);
    }

    public function testTsvByWordZoneCoordinates(): void
    {
        $helper = $this->buildTsvHelper(['fox']);
        $filepath = $this->getFixturePath('sample-byword.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, true]);
        $first = $result['resources'][0];
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $first['zone']['left']);
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $first['zone']['top']);
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $first['zone']['width']);
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $first['zone']['height']);
    }

    public function testTsvByWordNoMatch(): void
    {
        $helper = $this->buildTsvHelper(['xyz']);
        $filepath = $this->getFixturePath('sample-byword.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, true]);
        $this->assertNull($result);
    }

    public function testTsvByWordHitsHaveNoContext(): void
    {
        $helper = $this->buildTsvHelper(['fox']);
        $filepath = $this->getFixturePath('sample-byword.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, true]);
        // The words are not ordered, so there is one hit by match without
        // context.
        $this->assertSame([
            ['hits' => [1], 'match' => 'fox'],
            ['hits' => [2], 'match' => 'fox'],
        ], $result['hits']);
    }

    public function testTsvFullHitsHaveContext(): void
    {
        $helper = $this->buildTsvHelper(['fox']);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]);
        $this->assertSame(2, $result['hit']);
        $this->assertSame([
            [
                'hits' => [1],
                'match' => 'fox',
                'before' => 'The quick brown ',
                'after' => null,
            ],
            [
                'hits' => [2],
                'match' => 'fox',
                'before' => 'Second page ',
                'after' => null,
            ],
        ], $result['hits']);
    }

    public function testTsvFullContextAfterHit(): void
    {
        $helper = $this->buildTsvHelper(['quick']);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]);
        // The words after the hit stop at the end of the page.
        $this->assertSame('The ', $result['hits'][0]['before']);
        $this->assertSame(' brown fox', $result['hits'][0]['after']);
    }

    public function testTsvFullExactSearchHasContext(): void
    {
        $helper = $this->buildTsvHelper(['quick', 'brown', 'full' => 'quick brown'], 'quick brown');
        $this->setProperty($helper, 'queryIsExactSearch', true);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]);
        $this->assertSame(1, $result['hit']);
        $this->assertSame([
            'hits' => [1],
            'match' => 'quick brown',
            'before' => 'The ',
            'after' => ' fox',
        ], $result['hits'][0]);
    }

    public function testTsvByWordMatchesWordsWithPunctuation(): void
    {
        $helper = $this->buildTsvHelper(['house']);
        $filepath = $this->getFixturePath('sample-byword.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, true]);
        $this->assertSame(3, $result['hit']);
        $this->assertSame(['house', 'house', 'house'], array_column($result['hits'], 'match'));
        $this->assertSame('house', $result['resources'][0]['zone']['text']);
    }

    public function testTsvFullMatchesWordsWithPunctuation(): void
    {
        $helper = $this->buildTsvHelper(['house']);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]);
        $this->assertSame(3, $result['hit']);
        // The punctuation is part of the context.
        $this->assertSame([
            'hits' => [1],
            'match' => 'house',
            'before' => 'A ',
            'after' => ', (house) and house.',
        ], $result['hits'][0]);
        $this->assertSame('A house, (', $result['hits'][1]['before']);
        $this->assertSame(') and house.', $result['hits'][1]['after']);
        $this->assertSame('.', $result['hits'][2]['after']);
    }

    public function testTsvFullExactSearchMatchesWordsWithPunctuation(): void
    {
        $helper = $this->buildTsvHelper(['house', 'and', 'full' => 'house and'], 'house and');
        $this->setProperty($helper, 'queryIsExactSearch', true);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]);
        $this->assertSame(1, $result['hit']);
        // The punctuation around the expression is part of the context.
        $this->assertSame('house) and', $result['hits'][0]['match']);
        $this->assertSame('A house, (', $result['hits'][0]['before']);
        $this->assertSame(' house.', $result['hits'][0]['after']);
    }
}
