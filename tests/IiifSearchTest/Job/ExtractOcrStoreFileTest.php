<?php declare(strict_types=1);

namespace IiifSearchTest\Job;

use IiifSearch\Job\ExtractOcr;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * The existing file is replaced only by a complete new one.
 */
class ExtractOcrStoreFileTest extends AbstractHttpControllerTestCase
{
    protected string $dir;

    protected string $target;

    public function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/iiifsearch-store-' . uniqid();
        mkdir($this->dir);
        $this->target = $this->dir . '/1.by-word.tsv';
        file_put_contents($this->target, "old\n");
    }

    public function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testExistingFileIsReplaced(): void
    {
        $tempFile = $this->tempFile("new\n");
        $this->assertTrue($this->job()->proxyStore($tempFile, $this->target));
        $this->assertSame("new\n", file_get_contents($this->target));
        $this->assertSame([$this->target], glob($this->dir . '/*'));
        $tempFile->delete();
    }

    public function testExistingFileIsKeptOnFailure(): void
    {
        $tempFile = $this->tempFile("new\n");
        $tempFile->delete();
        $this->assertFalse($this->job()->proxyStore($tempFile, $this->target));
        $this->assertSame("old\n", file_get_contents($this->target));
        $this->assertSame([$this->target], glob($this->dir . '/*'));
    }

    public function testEmptyFileIsStored(): void
    {
        $tempFile = $this->tempFile('');
        $this->assertTrue($this->job()->proxyStore($tempFile, $this->target));
        $this->assertSame('', file_get_contents($this->target));
        $this->assertSame([$this->target], glob($this->dir . '/*'));
        $tempFile->delete();
    }

    protected function tempFile(string $content): \Omeka\File\TempFile
    {
        $tempFile = $this->getApplicationServiceLocator()->get('Omeka\File\TempFileFactory')->build();
        file_put_contents($tempFile->getTempPath(), $content);
        return $tempFile;
    }

    protected function job(): ExtractOcrStoreFileProxy
    {
        return (new \ReflectionClass(ExtractOcrStoreFileProxy::class))->newInstanceWithoutConstructor();
    }
}

class ExtractOcrStoreFileProxy extends ExtractOcr
{
    public function proxyStore(\Omeka\File\TempFile $tempFile, string $filepath): bool
    {
        $this->logger = new Logger(['writers' => [['name' => Noop::class]]]);
        $this->targetFormat = self::FORMAT_TSV_BY_WORD;
        return $this->storeFileLocally($tempFile, $filepath);
    }
}
