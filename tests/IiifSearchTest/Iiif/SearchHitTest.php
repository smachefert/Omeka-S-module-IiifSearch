<?php declare(strict_types=1);

namespace IiifSearchTest\Iiif;

use IiifSearch\Iiif\Search1\SearchHit;
use PHPUnit\Framework\TestCase;

class SearchHitTest extends TestCase
{
    public function testDefaultType(): void
    {
        $hit = new SearchHit();
        $content = $hit->getContent();
        $this->assertSame('search:Hit', $content['@type']);
    }

    public function testAnnotationsAndMatch(): void
    {
        $hit = new SearchHit();
        $hit['annotations'] = [
            'http://example.com/annotation/a1h1r100,200,70,40',
            'http://example.com/annotation/a2h2r540,100,60,40',
        ];
        $hit['match'] = 'fox';

        $content = $hit->getContent();
        $this->assertCount(2, $content['annotations']);
        $this->assertSame('fox', $content['match']);
    }
}
