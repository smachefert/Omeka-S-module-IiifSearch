<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use DOMDocument;
use DOMXPath;
use IiifSearchTest\IiifSearchTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests the hOCR DOM parsing logic used by searchFullTextHocr().
 *
 * Verifies that a sample hOCR file is correctly parsed: pages,
 * words, coordinates, and text extraction.
 */
class HocrDomParsingTest extends TestCase
{
    use IiifSearchTestTrait;

    /**
     * @var DOMXPath
     */
    protected $xpath;

    protected function setUp(): void
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $html = $this->getFixture('sample.hocr.html');
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        $this->xpath = new DOMXPath($dom);
    }

    protected function parseBbox(string $title): ?array
    {
        return $this->callProtected(
            $this->createHelperStub(),
            'parseHocrBbox',
            [$title]
        );
    }

    public function testFixtureHasTwoPages(): void
    {
        $pages = $this->xpath->query(
            "//*[contains(@class, 'ocr_page')]"
        );
        $this->assertSame(2, $pages->length);
    }

    public function testFirstPageDimensions(): void
    {
        $pages = $this->xpath->query(
            "//*[contains(@class, 'ocr_page')]"
        );
        $bbox = $this->parseBbox(
            $pages->item(0)->getAttribute('title')
        );
        $this->assertSame(2480, $bbox['width']);
        $this->assertSame(3508, $bbox['height']);
    }

    public function testFirstPageHasNineWords(): void
    {
        $pages = $this->xpath->query(
            "//*[contains(@class, 'ocr_page')]"
        );
        $words = $this->xpath->query(
            ".//*[contains(@class, 'ocrx_word')]",
            $pages->item(0)
        );
        $this->assertSame(9, $words->length);
    }

    public function testSecondPageHasFourWords(): void
    {
        $pages = $this->xpath->query(
            "//*[contains(@class, 'ocr_page')]"
        );
        $words = $this->xpath->query(
            ".//*[contains(@class, 'ocrx_word')]",
            $pages->item(1)
        );
        $this->assertSame(4, $words->length);
    }

    public function testWordTextExtraction(): void
    {
        $pages = $this->xpath->query(
            "//*[contains(@class, 'ocr_page')]"
        );
        $words = $this->xpath->query(
            ".//*[contains(@class, 'ocrx_word')]",
            $pages->item(0)
        );

        $texts = [];
        foreach ($words as $word) {
            $texts[] = $word->textContent;
        }

        $this->assertSame(
            ['The', 'quick', 'brown', 'fox',
             'jumps', 'over', 'the', 'lazy', 'dog'],
            $texts
        );
    }

    public function testWordBboxCoordinates(): void
    {
        $pages = $this->xpath->query(
            "//*[contains(@class, 'ocr_page')]"
        );
        $words = $this->xpath->query(
            ".//*[contains(@class, 'ocrx_word')]",
            $pages->item(0)
        );

        // First word: "The" with bbox 100 100 200 140.
        $bbox = $this->parseBbox(
            $words->item(0)->getAttribute('title')
        );
        $this->assertSame([
            'left' => 100,
            'top' => 100,
            'width' => 100,
            'height' => 40,
        ], $bbox);
    }

    public function testSearchMatchesFoxOnBothPages(): void
    {
        $pages = $this->xpath->query(
            "//*[contains(@class, 'ocr_page')]"
        );

        $foxHits = [];
        foreach ($pages as $pageIndex => $page) {
            $words = $this->xpath->query(
                ".//*[contains(@class, 'ocrx_word')]",
                $page
            );
            foreach ($words as $word) {
                $text = $word->textContent;
                if (preg_match('/fox/ui', $text)) {
                    $foxHits[] = [
                        'page' => $pageIndex + 1,
                        'text' => $text,
                        'bbox' => $this->parseBbox(
                            $word->getAttribute('title')
                        ),
                    ];
                }
            }
        }

        $this->assertCount(2, $foxHits);

        // Page 1: "fox" at bbox 550 100 620 140.
        $this->assertSame(1, $foxHits[0]['page']);
        $this->assertSame('fox', $foxHits[0]['text']);
        $this->assertSame(550, $foxHits[0]['bbox']['left']);
        $this->assertSame(70, $foxHits[0]['bbox']['width']);

        // Page 2: "fox" at bbox 540 100 600 140.
        $this->assertSame(2, $foxHits[1]['page']);
        $this->assertSame(540, $foxHits[1]['bbox']['left']);
        $this->assertSame(60, $foxHits[1]['bbox']['width']);
    }

    public function testCaseInsensitiveSearch(): void
    {
        $pages = $this->xpath->query(
            "//*[contains(@class, 'ocr_page')]"
        );
        $words = $this->xpath->query(
            ".//*[contains(@class, 'ocrx_word')]",
            $pages->item(0)
        );

        $matches = [];
        foreach ($words as $word) {
            if (preg_match('/the/Uui', $word->textContent)) {
                $matches[] = $word->textContent;
            }
        }

        // "The" (capitalized) and "the" (lowercase).
        $this->assertSame(['The', 'the'], $matches);
    }
}
