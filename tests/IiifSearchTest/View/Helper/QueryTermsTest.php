<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use IiifSearchTest\IiifSearchTestTrait;
use Laminas\View\Renderer\PhpRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests the preparation of the query in prepareAndFormatQueryByWord().
 */
class QueryTermsTest extends TestCase
{
    use IiifSearchTestTrait;

    protected function setProperty(object $helper, string $name, $value): void
    {
        $ref = new ReflectionProperty(get_class($helper), $name);
        $ref->setAccessible(true);
        $ref->setValue($helper, $value);
    }

    protected function buildHelper(string $query, array $settings = []): object
    {
        $helper = $this->createHelperStub();
        $this->setProperty($helper, 'query', $query);
        $this->setProperty($helper, 'queryIsExactSearch', false);
        $this->setProperty($helper, 'index', 'text/tab-separated-values');

        $view = $this->createMock(PhpRenderer::class);
        $view->method('__call')->willReturnCallback(function ($method, $args) use ($settings) {
            return $method === 'setting' ? $settings[$args[0]] ?? null : null;
        });
        $helper->setView($view);

        return $helper;
    }

    /**
     * Get the query words and the terms of a query.
     */
    protected function prepare(string $query, array $settings = []): array
    {
        $helper = $this->buildHelper($query, $settings);
        $queryWords = $this->callProtected($helper, 'prepareAndFormatQueryByWord');
        $this->setProperty($helper, 'queryWords', $queryWords);
        return [$queryWords, $this->callProtected($helper, 'getQueryTerms')];
    }

    public function testPlainQuery(): void
    {
        [$queryWords, $terms] = $this->prepare('Huis');
        $this->assertSame(['Huis'], $queryWords);
        $this->assertSame([['text' => 'Huis', 'key' => 'huis', 'mode' => 'plain']], $terms);
    }

    public function testWildcardsAreRemovedByDefault(): void
    {
        [$queryWords, $terms] = $this->prepare('huis*');
        $this->assertSame(['huis'], $queryWords);
        $this->assertSame('plain', $terms[0]['mode']);
    }

    public function testWildcards(): void
    {
        [$queryWords, $terms] = $this->prepare('hu?s*', ['iiifsearch_wildcards' => true]);
        $this->assertCount(1, $terms);
        $this->assertSame('wildcard', $terms[0]['mode']);
        $this->assertSame('hu?s*', $terms[0]['text']);
        $this->assertSame(1, preg_match($terms[0]['regex'], 'huisje'));
        $this->assertSame(0, preg_match($terms[0]['regex'], 'thuis'));
    }

    public function testWildcardsWithSpellingRules(): void
    {
        [, $terms] = $this->prepare('huy*', [
            'iiifsearch_wildcards' => true,
            'iiifsearch_spelling_rules' => 'y = i',
        ]);
        $this->assertSame(1, preg_match($terms[0]['regex'], 'huis'));
    }

    public function testWildcardsNeedTheMinimumLength(): void
    {
        [$queryWords] = $this->prepare('h*', ['iiifsearch_wildcards' => true]);
        $this->assertSame([], $queryWords);
        [$queryWords] = $this->prepare('hu*s', ['iiifsearch_wildcards' => true]);
        $this->assertCount(1, $queryWords);
    }

    public function testFuzzy(): void
    {
        [$queryWords, $terms] = $this->prepare('Huis~ voorstraat~ huys~2', ['iiifsearch_fuzzy' => true]);
        $this->assertSame(['Huis~', 'voorstraat~', 'huys~2'], array_slice($queryWords, 0, 3));
        $this->assertSame(['text' => 'Huis', 'key' => 'huis', 'mode' => 'fuzzy', 'distance' => 1], $terms[0]);
        $this->assertSame(2, $terms[1]['distance']);
        $this->assertSame(2, $terms[2]['distance']);
    }

    public function testFuzzyNeedsTheMinimumLength(): void
    {
        [$queryWords] = $this->prepare('hu~', ['iiifsearch_fuzzy' => true]);
        $this->assertSame([], $queryWords);
    }
}

