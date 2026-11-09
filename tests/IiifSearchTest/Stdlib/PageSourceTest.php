<?php declare(strict_types=1);

namespace IiifSearchTest\Stdlib;

use IiifSearch\Stdlib\PageSource;
use PHPUnit\Framework\TestCase;

class PageSourceTest extends TestCase
{
    /**
     * @dataProvider sourceProvider
     */
    public function testNormalizeName(string $source, string $name, ?string $dir): void
    {
        $this->assertSame($name, PageSource::normalizeName($source));
        $this->assertSame($dir, PageSource::extractDirResidue($source));
    }

    public function sourceProvider(): array
    {
        return [
            // The identifier of a remote iiif image, with encoded slashes.
            ['https://example.org/iiif/2/BOOK%2FBOOK_0001%2Fmaster%2FBOOK_0001_0001.JP2/info.json', 'book-0001-0001', 'book/book_0001'],
            // The alto of the same page.
            ['BOOK/BOOK_0001/ALTO/BOOK_0001_0001.XML', 'book-0001-0001', 'book/book_0001'],
            // A file ingested from an url, with a query.
            ['https://example.org/files/page_002.jpg?size=full', 'page-002', null],
            ['0003.jpg', '0003', null],
            ['scan/0004.alto.xml', '0004', null],
        ];
    }
}
