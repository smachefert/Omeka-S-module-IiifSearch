<?php declare(strict_types=1);

namespace IiifSearchTest\Iiif\Search1;

use IiifSearch\Iiif\Search1\Builder;
use PHPUnit\Framework\TestCase;

class BuilderTest extends TestCase
{
    protected function sampleMatch(): array
    {
        return [
            'image' => ['id' => 10, 'width' => 1000, 'height' => 1000],
            'page' => ['number' => '1', 'width' => '1000', 'height' => '1000'],
            'zone' => [
                'text' => 'fox',
                'left' => '100',
                'top' => '200',
                'width' => '70',
                'height' => '40',
            ],
            'chars' => 'fox',
            'hit' => 1,
        ];
    }

    public function testComputeBoxIdentityScale(): void
    {
        $match = $this->sampleMatch();
        $match['image'] = ['id' => 10, 'width' => 2480, 'height' => 3508];
        $match['page'] = ['number' => '1', 'width' => '2480', 'height' => '3508'];
        $match['zone'] = [
            'text' => 'fox',
            'left' => '550',
            'top' => '100',
            'width' => '70',
            'height' => '40',
        ];
        $match['chars'] = '';

        $box = (new Builder())->computeBox($match);
        $this->assertSame(['x' => 550, 'y' => 100, 'w' => 70, 'h' => 40], $box);
    }

    public function testComputeBoxScalingHalves(): void
    {
        $match = $this->sampleMatch();
        // Image is half the page → coordinates should be halved.
        $match['image'] = ['id' => 10, 'width' => 500, 'height' => 500];
        $match['page'] = ['number' => '1', 'width' => '1000', 'height' => '1000'];
        $match['zone'] = [
            'text' => 'fox',
            'left' => '200',
            'top' => '400',
            'width' => '100',
            'height' => '50',
        ];
        $match['chars'] = '';

        $box = (new Builder())->computeBox($match);
        $this->assertSame(['x' => 100, 'y' => 200, 'w' => 50, 'h' => 25], $box);
    }

    public function testComputeIdFormat(): void
    {
        $match = $this->sampleMatch();
        $box = ['x' => 100, 'y' => 200, 'w' => 70, 'h' => 40];
        $id = (new Builder())->computeId('http://example.com/annotation/', $match, $box);
        $this->assertSame(
            'http://example.com/annotation/a1h1r100,200,70,40',
            $id
        );
    }

    public function testComputeIdReturnsNullWhenBoxIsEmpty(): void
    {
        $match = $this->sampleMatch();
        $id = (new Builder())->computeId('http://example.com/annotation/', $match, []);
        $this->assertNull($id);
    }

    public function testBuildProducesAnnotationListWithExpectedOn(): void
    {
        $pivot = [
            'baseResultUrl' => 'http://example.com/annotation/',
            'baseCanvasUrl' => 'http://example.com/canvas/p',
            'totalHit' => 1,
            'matches' => [$this->sampleMatch()],
            'pageHits' => [['hits' => [1], 'match' => 'fox']],
        ];

        $list = (new Builder())->build($pivot, 'http://example.com/search?q=fox');
        $content = $list->getContent();

        $this->assertCount(1, $content['resources']);
        $this->assertCount(1, $content['hits']);

        $resource = $content['resources'][0];
        // resources[] hold AnnotationSearchResult objects ; serialize them.
        $resourceContent = $resource->getContent();
        $this->assertMatchesRegularExpression(
            '#^http://example\.com/canvas/p1\#xywh=\d+,\d+,\d+,\d+$#',
            $resourceContent['on']
        );
        $this->assertMatchesRegularExpression(
            '#^http://example\.com/annotation/a1h1r\d+,\d+,\d+,\d+$#',
            $resourceContent['@id']
        );
    }
}
