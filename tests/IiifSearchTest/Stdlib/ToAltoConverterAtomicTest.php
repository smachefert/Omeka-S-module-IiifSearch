<?php declare(strict_types=1);

namespace IiifSearchTest\Stdlib;

use IiifSearch\Stdlib\ToAltoConverter;
use IiifSearch\Stdlib\XmlMediaClassifier;
use PHPUnit\Framework\TestCase;

/**
 * The converted alto replaces the existing one only when it is complete.
 */
class ToAltoConverterAtomicTest extends TestCase
{
    protected string $dir;

    protected string $target;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/iiifsearch-convert-' . uniqid();
        mkdir($this->dir);
        $this->target = $this->dir . '/1-page.alto.xml';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    protected function fixture(string $name): string
    {
        return dirname(__DIR__, 2) . '/fixtures/' . $name;
    }

    public function testNativeAltoIsCopiedAndStamped(): void
    {
        $this->assertTrue((new ToAltoConverter())->convert($this->fixture('sample.alto.xml'), XmlMediaClassifier::TYPE_ALTO, $this->target));
        $this->assertSame([$this->target], glob($this->dir . '/*'));
        $this->assertStringContainsString(ToAltoConverter::SOFTWARE_NAME, file_get_contents($this->target));
    }

    public function testConvertedFileReplacesStaleTarget(): void
    {
        file_put_contents($this->target, 'old');
        touch($this->target, filemtime($this->fixture('page.xml')) - 3600);
        $this->assertTrue((new ToAltoConverter())->convert($this->fixture('page.xml'), XmlMediaClassifier::TYPE_PAGE, $this->target));
        $this->assertSame([$this->target], glob($this->dir . '/*'));
        $this->assertTrue(ToAltoConverter::hasCoordinates($this->target));
    }

    public function testTargetIsKeptOnFailure(): void
    {
        $source = $this->dir . '/invalid.xml';
        file_put_contents($source, '<PcGts');
        file_put_contents($this->target, 'old');
        touch($this->target, time() - 3600);
        $this->assertFalse((new ToAltoConverter())->convert($source, XmlMediaClassifier::TYPE_PAGE, $this->target));
        $this->assertSame('old', file_get_contents($this->target));
        $this->assertEqualsCanonicalizing([$source, $this->target], glob($this->dir . '/*'));
    }
}
