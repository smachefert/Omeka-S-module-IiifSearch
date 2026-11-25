<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use IiifSearchTest\IiifSearchTestTrait;
use Laminas\View\Renderer\PhpRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests the choice between the two tsv indexes in prepareSearchIndexAndImages().
 */
class PrepareSearchIndexTest extends TestCase
{
    use IiifSearchTestTrait;

    /**
     * @var string
     */
    protected $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/iiifsearch-test-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/iiif-search', 0777, true);
        file_put_contents($this->basePath . '/iiif-search/42.full.tsv', "fox\t1\t550,100,70,40\n");
        file_put_contents($this->basePath . '/iiif-search/42.by-word.tsv', "fox\t1:550,100,70,40\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->basePath . '/iiif-search/*'));
        rmdir($this->basePath . '/iiif-search');
        rmdir($this->basePath);
    }

    protected function setProperty(object $helper, string $name, $value): void
    {
        $ref = new ReflectionProperty(get_class($helper), $name);
        $ref->setAccessible(true);
        $ref->setValue($helper, $value);
    }

    protected function getProperty(object $helper, string $name)
    {
        $ref = new ReflectionProperty(get_class($helper), $name);
        $ref->setAccessible(true);
        return $ref->getValue($helper);
    }

    protected function buildHelper(bool $isExactSearch, bool $preferFull): object
    {
        $helper = $this->createHelperStub();

        $item = $this->createMock(\Omeka\Api\Representation\ItemRepresentation::class);
        $item->method('id')->willReturn(42);
        $this->setProperty($helper, 'item', $item);
        $this->setProperty($helper, 'basePath', $this->basePath);
        $this->setProperty($helper, 'queryIsExactSearch', $isExactSearch);

        $view = $this->createMock(PhpRenderer::class);
        $view->method('plugin')->with('itemFileResources')->willReturn(function () {
            return [];
        });
        $plugins = $this->createMock(\Laminas\View\HelperPluginManager::class);
        $plugins->method('has')->willReturn(false);
        $view->method('getHelperPluginManager')->willReturn($plugins);
        $view->method('__call')->willReturnCallback(function ($method, $args) use ($preferFull) {
            return $method === 'setting' && $args[0] === 'iiifsearch_tsv_prefer_full'
                ? $preferFull
                : null;
        });
        $helper->setView($view);

        return $helper;
    }

    public function testByWordIsUsedByDefault(): void
    {
        $helper = $this->buildHelper(false, false);
        $this->assertTrue($this->callProtected($helper, 'prepareSearchIndexAndImages', []));
        $this->assertSame('text/tab-separated-values;by-word', $this->getProperty($helper, 'index'));
        $this->assertStringEndsWith('/42.by-word.tsv', $this->getProperty($helper, 'indexFilePath'));
    }

    public function testFullIsUsedWhenPreferred(): void
    {
        $helper = $this->buildHelper(false, true);
        $this->assertTrue($this->callProtected($helper, 'prepareSearchIndexAndImages', []));
        $this->assertSame('text/tab-separated-values', $this->getProperty($helper, 'index'));
        $this->assertStringEndsWith('/42.full.tsv', $this->getProperty($helper, 'indexFilePath'));
    }

    public function testFullIsUsedForExactSearch(): void
    {
        $helper = $this->buildHelper(true, false);
        $this->assertTrue($this->callProtected($helper, 'prepareSearchIndexAndImages', []));
        $this->assertSame('text/tab-separated-values', $this->getProperty($helper, 'index'));
    }

    public function testByWordIsUsedWhenFullIsMissing(): void
    {
        unlink($this->basePath . '/iiif-search/42.full.tsv');
        $helper = $this->buildHelper(false, true);
        $this->assertTrue($this->callProtected($helper, 'prepareSearchIndexAndImages', []));
        $this->assertSame('text/tab-separated-values;by-word', $this->getProperty($helper, 'index'));
    }
}
