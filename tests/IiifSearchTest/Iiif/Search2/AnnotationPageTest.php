<?php declare(strict_types=1);

namespace IiifSearchTest\Iiif\Search2;

use IiifSearch\Iiif\Search2\Annotation;
use IiifSearch\Iiif\Search2\AnnotationCollection;
use IiifSearch\Iiif\Search2\AnnotationPage;
use IiifSearch\Iiif\Search2\HitAnnotation;
use PHPUnit\Framework\TestCase;

class AnnotationPageTest extends TestCase
{
    public function testDefaultContextHasBothAnnoAndSearch2(): void
    {
        $page = new AnnotationPage();
        $page->initOptions(['requestUri' => 'http://example.com/iiif-search/1/search/2?q=fox']);
        $content = $page->getContent();
        $this->assertSame([
            'http://www.w3.org/ns/anno.jsonld',
            'http://iiif.io/api/search/2/context.json',
        ], $content['@context']);
    }

    public function testTypeIsAnnotationPage(): void
    {
        $page = new AnnotationPage();
        $page->initOptions(['requestUri' => 'http://example.com/iiif-search/1/search/2?q=fox']);
        $this->assertSame('AnnotationPage', $page->getContent()['type']);
    }

    public function testIdComesFromRequestUri(): void
    {
        $uri = 'http://example.com/iiif-search/42/search/2?q=fox';
        $page = new AnnotationPage();
        $page->initOptions(['requestUri' => $uri]);
        $this->assertSame($uri, $page->getContent()['id']);
    }

    public function testItemsAndPartOfSerialize(): void
    {
        $annotation = new Annotation([
            'id' => 'http://example.com/iiif-search/1/search/2/annotation/0',
            'body' => ['type' => 'TextualBody', 'value' => 'fox', 'format' => 'text/plain'],
            'target' => [
                'type' => 'SpecificResource',
                'source' => 'http://example.com/iiif/1/canvas/p1',
                'selector' => [
                    'type' => 'FragmentSelector',
                    'conformsTo' => 'http://www.w3.org/TR/media-frags/',
                    'value' => 'xywh=10,20,30,40',
                ],
            ],
        ]);
        $hit = new HitAnnotation([
            'id' => 'http://example.com/iiif-search/1/search/2/hit/0',
            'body' => [
                ['type' => 'TextualBody', 'purpose' => 'describing', 'value' => 'A quick '],
                ['type' => 'TextualBody', 'purpose' => 'tagging', 'value' => 'fox'],
                ['type' => 'TextualBody', 'purpose' => 'describing', 'value' => ' jumps.'],
            ],
            'target' => 'http://example.com/iiif-search/1/search/2/annotation/0',
        ]);
        $partOf = new AnnotationCollection([
            'id' => 'http://example.com/iiif-search/1/search/2',
            'label' => ['en' => ['Search results']],
            'total' => 2,
        ]);

        $page = new AnnotationPage();
        $page->initOptions(['requestUri' => 'http://example.com/iiif-search/1/search/2?q=fox']);
        $page['items'] = [$annotation, $hit];
        $page['partOf'] = $partOf;

        $json = json_encode($page, JSON_UNESCAPED_SLASHES);
        $decoded = json_decode($json, true);

        $this->assertSame('AnnotationPage', $decoded['type']);
        $this->assertCount(2, $decoded['items']);
        $this->assertSame('Annotation', $decoded['items'][0]['type']);
        $this->assertSame('highlighting', $decoded['items'][0]['motivation']);
        $this->assertSame('SpecificResource', $decoded['items'][0]['target']['type']);
        $this->assertSame('FragmentSelector', $decoded['items'][0]['target']['selector']['type']);
        $this->assertSame('xywh=10,20,30,40', $decoded['items'][0]['target']['selector']['value']);

        $this->assertSame('contextualizing', $decoded['items'][1]['motivation']);
        $this->assertCount(3, $decoded['items'][1]['body']);
        $this->assertSame('tagging', $decoded['items'][1]['body'][1]['purpose']);

        $this->assertSame('AnnotationCollection', $decoded['partOf']['type']);
        $this->assertSame(2, $decoded['partOf']['total']);
    }

    public function testIgnoredDefaultsToEmptyArray(): void
    {
        $page = new AnnotationPage();
        $page->initOptions(['requestUri' => 'http://example.com/iiif-search/1/search/2?q=fox']);
        $content = $page->getContent();
        $this->assertArrayHasKey('ignored', $content);
        $this->assertSame([], $content['ignored']);
    }
}
