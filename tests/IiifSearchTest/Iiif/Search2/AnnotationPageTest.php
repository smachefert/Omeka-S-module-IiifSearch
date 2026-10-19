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
            'target' => [
                'type' => 'SpecificResource',
                'source' => 'http://example.com/iiif-search/1/search/2/annotation/0',
                'selector' => [[
                    'type' => 'TextQuoteSelector',
                    'prefix' => 'A quick ',
                    'exact' => 'fox',
                    'suffix' => ' jumps.',
                ]],
            ],
        ]);
        $partOf = new AnnotationCollection([
            'id' => 'http://example.com/iiif-search/1/search/2',
            'label' => ['en' => ['Search results']],
            'total' => 1,
        ]);

        $page = new AnnotationPage();
        $page->initOptions(['requestUri' => 'http://example.com/iiif-search/1/search/2?q=fox']);
        $page['items'] = [$annotation];
        $page['annotations'] = [['type' => 'AnnotationPage', 'items' => [$hit]]];
        $page['partOf'] = $partOf;

        $json = json_encode($page, JSON_UNESCAPED_SLASHES);
        $decoded = json_decode($json, true);

        $this->assertSame('AnnotationPage', $decoded['type']);
        $this->assertCount(1, $decoded['items']);
        $this->assertSame('Annotation', $decoded['items'][0]['type']);
        $this->assertSame('highlighting', $decoded['items'][0]['motivation']);
        $this->assertSame('SpecificResource', $decoded['items'][0]['target']['type']);
        $this->assertSame('FragmentSelector', $decoded['items'][0]['target']['selector']['type']);
        $this->assertSame('xywh=10,20,30,40', $decoded['items'][0]['target']['selector']['value']);

        $hitContent = $decoded['annotations'][0]['items'][0];
        $this->assertSame('contextualizing', $hitContent['motivation']);
        $this->assertArrayNotHasKey('body', $hitContent);
        $this->assertSame('TextQuoteSelector', $hitContent['target']['selector'][0]['type']);
        $this->assertSame('fox', $hitContent['target']['selector'][0]['exact']);

        $this->assertSame('AnnotationCollection', $decoded['partOf']['type']);
        $this->assertSame(1, $decoded['partOf']['total']);
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
