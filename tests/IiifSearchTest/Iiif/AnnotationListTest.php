<?php declare(strict_types=1);

namespace IiifSearchTest\Iiif;

use IiifSearch\Iiif\Search1\AnnotationList;
use IiifSearch\Iiif\Search1\AnnotationSearchResult;
use PHPUnit\Framework\TestCase;

class AnnotationListTest extends TestCase
{
    protected function createList(array $resources = []): AnnotationList
    {
        $list = new AnnotationList([
            'resources' => $resources,
        ]);
        $list->initOptions([
            'requestUri' => 'http://example.com/search?q=fox',
        ]);
        return $list;
    }

    public function testDefaultContext(): void
    {
        $list = $this->createList();
        $content = $list->getContent();
        $this->assertSame(
            'http://iiif.io/api/search/1/context.json',
            $content['@context']
        );
    }

    public function testDefaultType(): void
    {
        $list = $this->createList();
        $content = $list->getContent();
        $this->assertSame('sc:AnnotationList', $content['@type']);
    }

    public function testWithinTotal(): void
    {
        // Create a list with 3 fake resources.
        $resources = [
            new AnnotationSearchResult(),
            new AnnotationSearchResult(),
            new AnnotationSearchResult(),
        ];
        $list = $this->createList($resources);
        $content = $list->getContent();
        $this->assertSame(3, $content['within']['total']);
    }

    public function testEmptyResources(): void
    {
        $list = $this->createList([]);
        $content = $list->getContent();
        $this->assertSame('sc:AnnotationList', $content['@type']);
        $this->assertSame(0, $content['within']['total']);
        $this->assertSame([], $content['resources']);
    }
}
