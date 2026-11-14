<?php declare(strict_types=1);

namespace IiifSearchTest\Stdlib;

use IiifSearch\Stdlib\TeiStructureExtractor;
use PHPUnit\Framework\TestCase;

/**
 * The file "tei-tcp-n00526.xml" is the official test file "testsimple.xml" of
 * the TEI Consortium (TEI Simple Print), an EEBO-TCP Phase I text (TCP N00526)
 * released under the Creative Commons 0 1.0 Universal.
 *
 * @link https://github.com/TEIC/TEI-Simple/blob/master/tests/testsimple.xml
 */
class TeiStructureExtractorTest extends TestCase
{
    protected function fixture(string $name): string
    {
        return dirname(__DIR__, 2) . '/fixtures/' . $name;
    }

    protected function extractLines(string $fixture, array $canvasByName, int $total): array
    {
        $toc = (new TeiStructureExtractor())->extract($this->fixture($fixture), $canvasByName, $total);
        $this->assertNotNull($toc);
        return explode("\n", $toc);
    }

    public function testFacsimileMatchedByFileName(): void
    {
        $canvases = [
            'p001.jpg' => 1,
            'p002.jpg' => 2,
            'p003.jpg' => 3,
            'p004.jpg' => 4,
            'p005.jpg' => 5,
        ];
        $this->assertSame([
            'tei0, Sample with facsimile, , tei1; tei4',
            'tei1, Chapter I, of the beginning, 2, 2; tei2; tei3',
            'tei2, First section, 2, 2',
            'tei3, Second section, 3, 3',
            'tei4, Chapter II, 3, 3; 4; 5; tei5',
            'tei5, Last section, 5, 5',
        ], $this->extractLines('tei-facsimile.xml', $canvases, 5));
    }

    public function testFacsimileIndependentOfCanvasOrder(): void
    {
        $canvases = [
            'p001.jpg' => 5,
            'p002.jpg' => 4,
            'p003.jpg' => 3,
            'p004.jpg' => 2,
            'p005.jpg' => 1,
        ];
        $lines = $this->extractLines('tei-facsimile.xml', $canvases, 5);
        $this->assertSame('tei1, Chapter I, of the beginning, 4, 4; tei2; tei3', $lines[1]);
        $this->assertSame('tei4, Chapter II, 3, 3; 2; 1; tei5', $lines[4]);
    }

    public function testFacsimileMasterFileMatchedWithoutExtension(): void
    {
        // The surface s3 refers to "p003.tif", the item stores "p003.jpg".
        $lines = $this->extractLines('tei-facsimile.xml', ['p003.jpg' => 9], 9);
        $this->assertSame('tei3, Second section, 9, 9', $lines[3]);
    }

    public function testDivWithoutHeadIsNotASection(): void
    {
        $lines = $this->extractLines('tei-facsimile.xml', ['p001.jpg' => 1], 5);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('Title page', $line);
        }
    }

    public function testHeadLabelSkipsNotesAndKeepsLineBreaksAsSpaces(): void
    {
        $lines = $this->extractLines('tei-facsimile.xml', [], 5);
        $this->assertStringStartsWith('tei1, Chapter I, of the beginning, ', $lines[1]);
        $this->assertStringStartsWith('tei2, First section, ', $lines[2]);
    }

    public function testRootGathersTopSectionsWithTheTitle(): void
    {
        $lines = $this->extractLines('tei-tcp-n00526.xml', [], 168);
        $this->assertStringStartsWith('tei0, Unum necessarium awakenings for the unregenerate.', $lines[0]);
        $this->assertStringEndsWith(', , tei1; tei2; tei5; tei7; tei10; tei21', $lines[0]);
    }

    public function testOfficialTeiMatchedByPageOrder(): void
    {
        // The facs of the pages are not references to a facsimile, so the pages
        // are matched by position.
        $lines = $this->extractLines('tei-tcp-n00526.xml', [], 168);
        $this->assertCount(22, $lines);
        $this->assertSame('tei1, Introduction., 3, 3; 4; 5; 6', $lines[1]);
        $this->assertStringStartsWith('tei2, AWAKENINGS FOR THE UNREGENERATE., 7, 7; 8; 9; 10; 11; 12; tei3; tei4', $lines[2]);
        foreach (array_slice($lines, 1) as $line) {
            $this->assertMatchesRegularExpression('~^tei\d+, .+, \d*, (?:\d+|tei\d+)(?:; (?:\d+|tei\d+))*$~', $line);
        }
    }

    public function testPagesBeyondCanvasesAreIgnored(): void
    {
        $lines = $this->extractLines('tei-tcp-n00526.xml', [], 10);
        foreach (array_slice($lines, 1) as $line) {
            $items = explode('; ', substr($line, strrpos($line, ', ') + 2));
            foreach ($items as $item) {
                if (ctype_digit($item)) {
                    $this->assertLessThanOrEqual(10, (int) $item);
                }
            }
        }
    }

    public function testFacsimileFullyMatchedIsCertain(): void
    {
        $extractor = new TeiStructureExtractor();
        $extractor->extract($this->fixture('tei-facsimile.xml'), [
            'p001.jpg' => 1,
            'p002.jpg' => 2,
            'p003.jpg' => 3,
            'p004.jpg' => 4,
            'p005.jpg' => 5,
        ], 5);
        $this->assertTrue($extractor->stats()['by_name']);
        $this->assertFalse($extractor->isAlignmentUncertain());
    }

    public function testUnmatchedPageIsPlacedAfterThePreviousMatchedPage(): void
    {
        // The image of the page 3 is missing in the item, that has the page 4 at
        // canvas 9: the page 3 follows the page 2, not its rank in the TEI.
        $extractor = new TeiStructureExtractor();
        $toc = $extractor->extract($this->fixture('tei-facsimile.xml'), [
            'p001.jpg' => 6,
            'p002.jpg' => 7,
            'p004.jpg' => 9,
            'p005.jpg' => 10,
        ], 10);
        $lines = explode("\n", (string) $toc);
        $this->assertSame('tei3, Second section, 8, 8', $lines[3]);
        $this->assertSame('tei4, Chapter II, 8, 8; 9; 10; tei5', $lines[4]);
        $this->assertSame(1, $extractor->stats()['unmatched']);
        $this->assertTrue($extractor->isAlignmentUncertain());
    }

    public function testOrderWithAnotherNumberOfPagesIsUncertain(): void
    {
        $extractor = new TeiStructureExtractor();
        $extractor->extract($this->fixture('tei-tcp-n00526.xml'), [], 168);
        $this->assertFalse($extractor->stats()['by_name']);
        $this->assertFalse($extractor->isAlignmentUncertain());

        $extractor->extract($this->fixture('tei-tcp-n00526.xml'), [], 160);
        $this->assertTrue($extractor->isAlignmentUncertain());
    }

    public function testMissingFileReturnsNull(): void
    {
        $this->assertNull((new TeiStructureExtractor())->extract('/nonexistent/tei.xml', [], 1));
    }
}
