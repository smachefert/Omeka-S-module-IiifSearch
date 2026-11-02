<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use Common\Stdlib\DirectoryManager;
use Common\Stdlib\FileLock;
use IiifSearch\View\Helper\XmlAltoSplitter;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;
use Omeka\Api\Representation\ItemRepresentation;
use PHPUnit\Framework\TestCase;

/**
 * A page is cached atomically, and served even when another request builds it.
 *
 * @group altosplitter
 */
class XmlAltoSplitterLockTest extends TestCase
{
    protected string $basePath;

    protected string $sourcePath;

    protected string $cachePath;

    protected XmlAltoSplitterLockProxy $splitter;

    protected ItemRepresentation $item;

    public function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/iiifsearch-lock-' . uniqid();
        mkdir($this->basePath . '/original', 0775, true);
        $this->sourcePath = $this->basePath . '/original/multi.xml';
        file_put_contents($this->sourcePath, $this->alto());
        $this->cachePath = $this->basePath . '/iiif-search/7/alto-1.xml';

        $this->splitter = $this->newSplitter();
        $this->item = $this->createMock(ItemRepresentation::class);
        $this->item->method('id')->willReturn(7);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->basePath);
    }

    public function testPageIsCachedWithoutTempFile(): void
    {
        $path = $this->splitter->proxyResolve($this->sourcePath, $this->item, 1);
        $this->assertSame($this->cachePath, $path);
        $this->assertStringContainsString('page2', file_get_contents($path));
        $this->assertStringNotContainsString('page1', file_get_contents($path));
        $this->assertSame([], glob(dirname($this->cachePath) . '/*.tmp*') ?: []);
    }

    public function testPageIsServedWhenLockIsHeld(): void
    {
        mkdir(dirname($this->cachePath), 0775, true);
        $otherLock = new FileLock();
        $this->assertTrue($otherLock->acquire($this->cachePath));

        $path = $this->splitter->proxyResolve($this->sourcePath, $this->item, 1);
        $this->assertNotNull($path);
        $this->assertNotSame($this->cachePath, $path);
        $this->assertStringContainsString('page2', file_get_contents($path));
        $this->assertFileDoesNotExist($this->cachePath);
        $this->assertSame([], glob(dirname($this->cachePath) . '/*.tmp*') ?: []);

        $otherLock->release($this->cachePath);
        // A new request caches the page.
        $this->assertSame($this->cachePath, $this->newSplitter()->proxyResolve($this->sourcePath, $this->item, 1));
    }

    public function testStaleCacheIsNotServedWhenLockIsHeld(): void
    {
        mkdir(dirname($this->cachePath), 0775, true);
        file_put_contents($this->cachePath, 'stale');
        touch($this->cachePath, time() - 3600);
        $otherLock = new FileLock();
        $this->assertTrue($otherLock->acquire($this->cachePath));

        $path = $this->splitter->proxyResolve($this->sourcePath, $this->item, 1);
        $this->assertNotSame($this->cachePath, $path);
        $this->assertStringContainsString('page2', file_get_contents($path));
        $this->assertSame('stale', file_get_contents($this->cachePath));
        $otherLock->release($this->cachePath);
    }

    protected function newSplitter(): XmlAltoSplitterLockProxy
    {
        $logger = new Logger(['writers' => [['name' => Noop::class]]]);
        return new XmlAltoSplitterLockProxy($logger, new DirectoryManager($logger), $this->basePath);
    }

    protected function alto(): string
    {
        $pages = '';
        foreach ([1, 2, 3] as $page) {
            $pages .= <<<XML
                <Page ID="p$page" PHYSICAL_IMG_NR="$page" WIDTH="100" HEIGHT="100"><PrintSpace><TextBlock><TextLine>
                  <String CONTENT="page$page" HPOS="1" VPOS="1" WIDTH="10" HEIGHT="10"/>
                </TextLine></TextBlock></PrintSpace></Page>
                XML;
        }
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <alto xmlns="http://www.loc.gov/standards/alto/ns-v4#"><Layout>$pages</Layout></alto>
            XML;
    }

    protected function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = "$dir/$name";
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

class XmlAltoSplitterLockProxy extends XmlAltoSplitter
{
    public function proxyResolve(string $sourcePath, ItemRepresentation $item, int $pageIndex): ?string
    {
        return $this->resolveFromMultipageFile($sourcePath, $item, $pageIndex);
    }
}
