<?php declare(strict_types=1);

namespace IiifSearchTest\Iiif\Search2;

use IiifSearch\Iiif\Search2\Builder;
use PHPUnit\Framework\TestCase;

class BuilderTest extends TestCase
{
    protected function sampleMatch(int $hit, string $left): array
    {
        return [
            'image' => ['id' => 10, 'width' => 1000, 'height' => 1000],
            'page' => ['number' => '1', 'width' => '1000', 'height' => '1000'],
            'zone' => [
                'text' => 'fox',
                'left' => $left,
                'top' => '200',
                'width' => '70',
                'height' => '40',
            ],
            'chars' => 'fox',
            'hit' => $hit,
        ];
    }

    protected function build(array $pageHits): array
    {
        $pivot = [
            'baseResultUrl' => 'http://example.com/annotation/',
            'baseCanvasUrl' => 'http://example.com/canvas/p',
            'totalHit' => 2,
            'matches' => [$this->sampleMatch(1, '100'), $this->sampleMatch(2, '500')],
            'pageHits' => $pageHits,
        ];
        $page = (new Builder())->build($pivot, 'http://example.com/search/2?q=fox');
        return json_decode(json_encode($page, JSON_UNESCAPED_SLASHES), true);
    }

    public function testItemsContainOnlyHighlightingAnnotations(): void
    {
        $content = $this->build([
            ['hits' => [1], 'match' => 'fox', 'before' => 'The quick brown '],
            ['hits' => [2], 'match' => 'fox', 'after' => ' jumps'],
        ]);

        $this->assertCount(2, $content['items']);
        $this->assertSame(['highlighting', 'highlighting'], array_column($content['items'], 'motivation'));
        // The total is the number of matches, without the hits.
        $this->assertSame(2, $content['partOf']['total']);
    }

    public function testHitsAreContextualizingAnnotationsWithTextQuoteSelector(): void
    {
        $content = $this->build([
            ['hits' => [1], 'match' => 'fox', 'before' => 'The quick brown '],
            ['hits' => [2], 'match' => 'fox', 'after' => ' jumps'],
        ]);

        $this->assertCount(1, $content['annotations']);
        $this->assertSame('AnnotationPage', $content['annotations'][0]['type']);
        $hits = $content['annotations'][0]['items'];
        $this->assertCount(2, $hits);

        $this->assertSame('contextualizing', $hits[0]['motivation']);
        $this->assertSame('SpecificResource', $hits[0]['target']['type']);
        $this->assertSame($content['items'][0]['id'], $hits[0]['target']['source']);
        $this->assertSame([
            'type' => 'TextQuoteSelector',
            'prefix' => 'The quick brown ',
            'exact' => 'fox',
        ], $hits[0]['target']['selector'][0]);

        $this->assertSame($content['items'][1]['id'], $hits[1]['target']['source']);
        $this->assertSame([
            'type' => 'TextQuoteSelector',
            'exact' => 'fox',
            'suffix' => ' jumps',
        ], $hits[1]['target']['selector'][0]);
    }

    public function testHitWithMultipleAnnotationsTargetsEachOfThem(): void
    {
        $content = $this->build([
            ['hits' => [1, 2], 'match' => 'fox fox'],
        ]);

        $targets = $content['annotations'][0]['items'][0]['target'];
        $this->assertCount(2, $targets);
        $this->assertSame(array_column($content['items'], 'id'), array_column($targets, 'source'));
    }

    public function testNoAnnotationsWithoutHits(): void
    {
        $content = $this->build([]);
        $this->assertArrayNotHasKey('annotations', $content);
        $this->assertSame(2, $content['partOf']['total']);
    }
}
