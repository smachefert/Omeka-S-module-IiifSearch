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

    public function testTsvSpellingRulesMatchVariants(): void
    {
        $helper = $this->buildTsvHelper(['foks']);
        $this->setProperty($helper, 'spellingRules', ['x' => 'ks']);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]);
        $this->assertSame(2, $result['hit']);
        // The match is the word of the text.
        $this->assertSame(['fox', 'fox'], array_column($result['hits'], 'match'));

        $result = $this->callProtected($helper, 'searchFulltextTsv', [$this->getFixturePath('sample-byword.tsv'), true]);
        $this->assertSame(2, $result['hit']);
    }

    public function testTsvWithoutSpellingRulesDoesNotMatchVariants(): void
    {
        $helper = $this->buildTsvHelper(['foks']);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]);
        $this->assertNull($result);
    }

    public function testParseSpellingRules(): void
    {
        $helper = $this->buildTsvHelper(['fox']);
        $rules = $this->callProtected($helper, 'parseSpellingRules', ["IJ y  ie = i\nae = aa\n\nwrong\ni = i\n"]);
        $this->assertSame(['ij' => 'i', 'y' => 'i', 'ie' => 'i', 'ae' => 'aa'], $rules);
        $this->setProperty($helper, 'spellingRules', $rules);
        $this->assertSame('huis', $this->callProtected($helper, 'wordKey', ['Huys,']));
        $this->assertSame('huis', $this->callProtected($helper, 'wordKey', ['huijs']));
        $this->assertSame('straat', $this->callProtected($helper, 'wordKey', ['straet']));
    }

    public function testTsvWildcards(): void
    {
        $helper = $this->buildTsvHelper([preg_quote('f?x', '/'), preg_quote('b*n', '/')]);
        $this->setProperty($helper, 'useWildcards', true);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]);
        $this->assertSame(3, $result['hit']);
        $this->assertSame(['brown', 'fox', 'fox'], array_column($result['hits'], 'match'));

        $result = $this->callProtected($helper, 'searchFulltextTsv', [$this->getFixturePath('sample-byword.tsv'), true]);
        $this->assertSame(3, $result['hit']);
    }

    public function testTsvWildcardsMatchWholeWords(): void
    {
        $helper = $this->buildTsvHelper([preg_quote('hous*', '/')]);
        $this->setProperty($helper, 'useWildcards', true);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]);
        $this->assertSame(['house', 'house', 'house'], array_column($result['hits'], 'match'));

        // A wildcard does not match a part of a word.
        $helper = $this->buildTsvHelper([preg_quote('ou*', '/')]);
        $this->setProperty($helper, 'useWildcards', true);
        $this->assertNull($this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]));
    }

    public function testTsvWithoutWildcards(): void
    {
        $helper = $this->buildTsvHelper([preg_quote('f?x', '/')]);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $this->assertNull($this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]));
    }

    public function testTsvFuzzy(): void
    {
        $helper = $this->buildTsvHelper(['fix~', 'quack~']);
        $this->setProperty($helper, 'useFuzzy', true);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]);
        $this->assertSame(['quick', 'fox', 'fox'], array_column($result['hits'], 'match'));

        $result = $this->callProtected($helper, 'searchFulltextTsv', [$this->getFixturePath('sample-byword.tsv'), true]);
        $this->assertSame(3, $result['hit']);
    }

    public function testTsvFuzzyDistance(): void
    {
        $filepath = $this->getFixturePath('sample-full.tsv');

        $helper = $this->buildTsvHelper(['qack~']);
        $this->setProperty($helper, 'useFuzzy', true);
        $this->assertNull($this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]));

        $helper = $this->buildTsvHelper(['qack~2']);
        $this->setProperty($helper, 'useFuzzy', true);
        $result = $this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]);
        $this->assertSame(['quick'], array_column($result['hits'], 'match'));
    }

    public function testTsvWithoutFuzzy(): void
    {
        // The "~" is removed like the punctuation.
        $helper = $this->buildTsvHelper(['fix~']);
        $filepath = $this->getFixturePath('sample-full.tsv');
        $this->assertNull($this->callProtected($helper, 'searchFulltextTsv', [$filepath, false]));
    }
}

