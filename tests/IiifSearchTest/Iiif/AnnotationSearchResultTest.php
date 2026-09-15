<?php declare(strict_types=1);

namespace IiifSearchTest\Iiif;

use IiifSearch\Iiif\Search1\AnnotationSearchResult;
use PHPUnit\Framework\TestCase;

class AnnotationSearchResultTest extends TestCase
{
    public function testDefaultContextIsSearch1(): void
    {
        $annotation = new AnnotationSearchResult([
            '@id' => 'http://example.com/a/1',
            'resource' => ['@type' => 'cnt:ContentAsText', 'chars' => 'fox'],
            'on' => 'http://example.com/canvas/p1#xywh=10,20,30,40',
        ]);
        $content = $annotation->getContent();
        $this->assertSame('http://iiif.io/api/search/1/context.json', $content['@context']);
    }

    public function testDefaultTypeAndMotivation(): void
    {
        $annotation = new AnnotationSearchResult([
            '@id' => 'http://example.com/a/1',
            'resource' => ['@type' => 'cnt:ContentAsText', 'chars' => 'fox'],
            'on' => 'http://example.com/canvas/p1#xywh=10,20,30,40',
        ]);
        $content = $annotation->getContent();
        $this->assertSame('oa:Annotation', $content['@type']);
        $this->assertSame('sc:painting', $content['motivation']);
    }

    /**
     * @see https://iiif.io/api/search/1.0/#presentation-api-compatible-responses
     * The resource type MUST be cnt:ContentAsText per the IIIF spec.
     */
    public function testResourceContentAsTextIsPreserved(): void
    {
        $annotation = new AnnotationSearchResult([
            '@id' => 'http://example.com/a/1',
            'resource' => ['@type' => 'cnt:ContentAsText', 'chars' => 'fox'],
            'on' => 'http://example.com/canvas/p1#xywh=10,20,30,40',
        ]);
        $content = $annotation->getContent();
        $this->assertSame('cnt:ContentAsText', $content['resource']['@type']);
        $this->assertSame('fox', $content['resource']['chars']);
    }

    public function testRequiredKeysPresent(): void
    {
        $annotation = new AnnotationSearchResult([
            '@id' => 'http://example.com/a/1',
            'resource' => ['@type' => 'cnt:ContentAsText', 'chars' => 'fox'],
            'on' => 'http://example.com/canvas/p1#xywh=10,20,30,40',
        ]);
        $content = $annotation->getContent();
        foreach (['@context', '@id', '@type', 'motivation', 'resource', 'on'] as $key) {
            $this->assertArrayHasKey($key, $content);
        }
    }
}
