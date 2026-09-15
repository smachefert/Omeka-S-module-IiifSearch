<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use IiifSearchTest\IiifSearchTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IiifSearch::parseHocrBbox().
 *
 * Tests the hOCR bbox title attribute parser in isolation.
 */
class ParseHocrBboxTest extends TestCase
{
    use IiifSearchTestTrait;

    protected function parseBbox(string $title): ?array
    {
        return $this->callProtected(
            $this->createHelperStub(),
            'parseHocrBbox',
            [$title]
        );
    }

    public function testStandardBbox(): void
    {
        $result = $this->parseBbox('bbox 100 200 300 250');
        $this->assertSame([
            'left' => 100,
            'top' => 200,
            'width' => 200,
            'height' => 50,
        ], $result);
    }

    public function testBboxWithConfidence(): void
    {
        $result = $this->parseBbox(
            'bbox 100 200 300 250; x_wconf 95'
        );
        $this->assertSame([
            'left' => 100,
            'top' => 200,
            'width' => 200,
            'height' => 50,
        ], $result);
    }

    public function testBboxWithMultipleProperties(): void
    {
        $result = $this->parseBbox(
            'bbox 50 100 800 140; baseline 0 -5; x_size 30'
        );
        $this->assertSame([
            'left' => 50,
            'top' => 100,
            'width' => 750,
            'height' => 40,
        ], $result);
    }

    public function testBboxAtOrigin(): void
    {
        $result = $this->parseBbox('bbox 0 0 2480 3508');
        $this->assertSame([
            'left' => 0,
            'top' => 0,
            'width' => 2480,
            'height' => 3508,
        ], $result);
    }

    public function testBboxWithImageProperty(): void
    {
        $result = $this->parseBbox(
            'bbox 0 0 2480 3508; image page1.tif; ppageno 0'
        );
        $this->assertSame([
            'left' => 0,
            'top' => 0,
            'width' => 2480,
            'height' => 3508,
        ], $result);
    }

    public function testNoBboxReturnsNull(): void
    {
        $this->assertNull($this->parseBbox('x_wconf 95'));
    }

    public function testEmptyStringReturnsNull(): void
    {
        $this->assertNull($this->parseBbox(''));
    }

    public function testZeroWidthReturnsNull(): void
    {
        $this->assertNull(
            $this->parseBbox('bbox 100 200 100 250')
        );
    }

    public function testZeroHeightReturnsNull(): void
    {
        $this->assertNull(
            $this->parseBbox('bbox 100 200 300 200')
        );
    }

    public function testNegativeDimensionsReturnsNull(): void
    {
        $this->assertNull(
            $this->parseBbox('bbox 300 200 100 250')
        );
    }

    public function testSinglePixelBox(): void
    {
        $result = $this->parseBbox('bbox 10 20 11 21');
        $this->assertSame([
            'left' => 10,
            'top' => 20,
            'width' => 1,
            'height' => 1,
        ], $result);
    }

    public function testLargeCoordinates(): void
    {
        $result = $this->parseBbox('bbox 0 0 12000 16000');
        $this->assertSame([
            'left' => 0,
            'top' => 0,
            'width' => 12000,
            'height' => 16000,
        ], $result);
    }
}
