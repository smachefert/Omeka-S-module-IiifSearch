<?php declare(strict_types=1);

namespace IiifSearchTest\Stdlib;

use DOMDocument;
use DOMXPath;
use IiifSearch\Stdlib\PageSourceHintReader;
use IiifSearch\Stdlib\ToAltoConverter;
use IiifSearch\Stdlib\XmlMediaClassifier;
use PHPUnit\Framework\TestCase;

class ToAltoConverterPageTest extends TestCase
{
    /**
     * @var string
     */
    protected $target;

    protected function setUp(): void
    {
        $this->target = tempnam(sys_get_temp_dir(), 'iiifsearch') . '.alto.xml';
    }

    protected function tearDown(): void
    {
        @unlink($this->target);
    }

    protected function fixture(string $name): string
    {
        return dirname(__DIR__, 2) . '/fixtures/' . $name;
    }

    protected function convert(): DOMXPath
    {
        $converter = new ToAltoConverter();
        $this->assertTrue($converter->convert($this->fixture('page.xml'), XmlMediaClassifier::TYPE_PAGE, $this->target));
        $dom = new DOMDocument();
        $this->assertTrue($dom->load($this->target));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('alto', 'http://www.loc.gov/standards/alto/ns-v4#');
        return $xpath;
    }

    public function testClassification(): void
    {
        $classifier = new XmlMediaClassifier();
        $this->assertSame(XmlMediaClassifier::TYPE_PAGE, $classifier->classifyFile($this->fixture('page.xml')));
        $this->assertSame(XmlMediaClassifier::TYPE_PAGE, $classifier->classifyString(
            '<?xml version="1.0" encoding="UTF-8"?><PcGts xmlns="http://schema.primaresearch.org/PAGE/gts/pagecontent/2013-07-15"><Page/></PcGts>'
        ));
        $this->assertSame(XmlMediaClassifier::TYPE_PAGE, $classifier->classifyString(
            '<pc:PcGts xmlns:pc="http://schema.primaresearch.org/PAGE/gts/pagecontent/2010-03-19"><pc:Page/></pc:PcGts>'
        ));
    }

    public function testStringsFollowReadingOrder(): void
    {
        $xpath = $this->convert();
        $contents = [];
        foreach ($xpath->query('//alto:String') as $string) {
            $contents[] = $string->getAttribute('CONTENT');
        }
        // Region r2 is first in the reading order, the table outside of it is
        // last, the word without text is skipped and the text equiv with the
        // lowest index is used.
        $this->assertSame(['Lorem', 'ipsum', 'dolor', 'Hello', 'world', 'Cell'], $contents);
        $blocks = [];
        foreach ($xpath->query('//alto:TextBlock') as $block) {
            $blocks[] = $block->getAttribute('ID');
        }
        $this->assertSame(['r2', 'r1', 't1c1'], $blocks);
    }

    public function testCoordinates(): void
    {
        $xpath = $this->convert();
        $page = $xpath->query('//alto:Page')->item(0);
        $this->assertSame('1000', $page->getAttribute('WIDTH'));
        $this->assertSame('800', $page->getAttribute('HEIGHT'));

        // Bbox of the polygon.
        $word = $xpath->query('//alto:String[@ID="r1l1w1"]')->item(0);
        $this->assertSame(
            ['108', '102', '144', '56', '0.95'],
            [
                $word->getAttribute('HPOS'),
                $word->getAttribute('VPOS'),
                $word->getAttribute('WIDTH'),
                $word->getAttribute('HEIGHT'),
                $word->getAttribute('WC'),
            ]
        );

        // Negative coordinates are set to 0.
        $line = $xpath->query('//alto:TextLine[@ID="r2l1"]')->item(0);
        $this->assertSame(
            ['0', '195', '295', '45'],
            [
                $line->getAttribute('HPOS'),
                $line->getAttribute('VPOS'),
                $line->getAttribute('WIDTH'),
                $line->getAttribute('HEIGHT'),
            ]
        );

        // A line without words is split proportionally (295 / 17 characters).
        $strings = $xpath->query('//alto:TextLine[@ID="r2l1"]/alto:String');
        $this->assertSame(3, $strings->length);
        $this->assertSame('0', $strings->item(0)->getAttribute('HPOS'));
        $this->assertSame('87', $strings->item(0)->getAttribute('WIDTH'));
        $this->assertSame('104', $strings->item(1)->getAttribute('HPOS'));
        $this->assertSame('208', $strings->item(2)->getAttribute('HPOS'));

        // A line without text is skipped.
        $this->assertSame(0, $xpath->query('//alto:TextLine[@ID="r2l2"]')->length);

        $this->assertTrue(ToAltoConverter::hasCoordinates($this->target));
    }

    public function testSourceImageAndProvenance(): void
    {
        $xpath = $this->convert();
        $this->assertSame('p001.jpg', ToAltoConverter::sourceImageFilename($this->target));
        $this->assertSame(
            ToAltoConverter::SOFTWARE_NAME,
            $xpath->query('//alto:OCRProcessing//alto:softwareName')->item(0)->nodeValue
        );
        $this->assertSame(['p001.jpg'], (new PageSourceHintReader())->readAllImageHints($this->fixture('page.xml'), XmlMediaClassifier::TYPE_PAGE));
        $this->assertSame(['p001.jpg'], (new PageSourceHintReader())->readAllImageHints($this->target, XmlMediaClassifier::TYPE_ALTO));
    }
}
