<?php declare(strict_types=1);

namespace IiifSearchTest\Job;

use IiifSearch\Job\ExtractOcr;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Noop;
use Omeka\Api\Representation\ItemRepresentation;
use PHPUnit\Framework\TestCase;

class ExtractOcrAltoTsvTest extends TestCase
{
    protected $tsvFilepath;

    protected function setUp(): void
    {
        $this->tsvFilepath = tempnam(sys_get_temp_dir(), 'iiifsearch') . '.tsv';
    }

    protected function tearDown(): void
    {
        @unlink($this->tsvFilepath);
    }

    protected function extract(string $altoContent, string $format): bool
    {
        $altoFilepath = tempnam(sys_get_temp_dir(), 'iiifsearch');
        file_put_contents($altoFilepath, $altoContent);
        $job = (new \ReflectionClass(ExtractOcrAltoTsvProxy::class))->newInstanceWithoutConstructor();
        $item = $this->getMockBuilder(ItemRepresentation::class)
            ->disableOriginalConstructor()
            ->getMock();
        $item->method('id')->willReturn(1);
        try {
            return $job->proxyExtract($altoFilepath, $this->tsvFilepath, $item, $format);
        } finally {
            @unlink($altoFilepath);
        }
    }

    protected function alto(string $namespace): string
    {
        $xmlns = $namespace === '' ? '' : ' xmlns="' . $namespace . '"';
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <alto$xmlns>
              <Layout>
                <Page ID="page_0" WIDTH="1000" HEIGHT="2000">
                  <PrintSpace>
                    <TextBlock>
                      <TextLine>
                        <String CONTENT="Chapitre" HPOS="10" VPOS="20" WIDTH="30" HEIGHT="40"/>
                        <String CONTENT="premier" HPOS="50" VPOS="20" WIDTH="30" HEIGHT="40"/>
                      </TextLine>
                    </TextBlock>
                  </PrintSpace>
                </Page>
              </Layout>
            </alto>
            XML;
    }

    public function namespaceProvider(): array
    {
        return [
            'alto v3 (tesseract)' => ['http://www.loc.gov/standards/alto/ns-v3#'],
            'alto v4' => ['http://www.loc.gov/standards/alto/ns-v4#'],
            'no namespace' => [''],
        ];
    }

    /**
     * @dataProvider namespaceProvider
     */
    public function testFullTsvFromAlto(string $namespace): void
    {
        $this->assertTrue($this->extract($this->alto($namespace), ExtractOcr::FORMAT_TSV));
        $this->assertSame(
            "Chapitre\t1\t10,20,30,40\npremier\t1\t50,20,30,40\n",
            file_get_contents($this->tsvFilepath)
        );
    }

    /**
     * @dataProvider namespaceProvider
     */
    public function testByWordTsvFromAlto(string $namespace): void
    {
        $this->assertTrue($this->extract($this->alto($namespace), ExtractOcr::FORMAT_TSV_BY_WORD));
        $this->assertSame(
            "chapitre\t1:10,20,30,40\npremier\t1:50,20,30,40\n",
            file_get_contents($this->tsvFilepath)
        );
    }

    public function testAltoWithCoordinates(): void
    {
        $this->assertTrue($this->hasCoordinates($this->alto('http://www.loc.gov/standards/alto/ns-v3#')));
        $this->assertTrue($this->hasCoordinates($this->alto('')));
    }

    public function testAltoWithoutCoordinates(): void
    {
        // Like a tei without facsimile zones converted into alto.
        $alto = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <alto xmlns="http://www.loc.gov/standards/alto/ns-v4#">
              <Layout>
                <Page ID="f1" WIDTH="0" HEIGHT="0">
                  <PrintSpace HPOS="0" VPOS="0" WIDTH="0" HEIGHT="0">
                    <TextBlock ID="f1_block" HPOS="0" VPOS="0" WIDTH="0" HEIGHT="0">
                      <TextLine>
                        <String CONTENT="Chapitre" HPOS="0" VPOS="0" WIDTH="0" HEIGHT="0"/>
                      </TextLine>
                    </TextBlock>
                  </PrintSpace>
                </Page>
              </Layout>
            </alto>
            XML;
        $this->assertFalse($this->hasCoordinates($alto));
        $this->assertFalse($this->hasCoordinates('not xml'));
    }

    protected function hasCoordinates(string $altoContent): bool
    {
        $altoFilepath = tempnam(sys_get_temp_dir(), 'iiifsearch');
        file_put_contents($altoFilepath, $altoContent);
        try {
            return \IiifSearch\Stdlib\ToAltoConverter::hasCoordinates($altoFilepath);
        } finally {
            @unlink($altoFilepath);
        }
    }
}

class ExtractOcrAltoTsvProxy extends ExtractOcr
{
    public function proxyExtract(string $altoFilepath, string $tsvFilepath, ItemRepresentation $item, string $format): bool
    {
        $this->logger = new Logger(['writers' => [['name' => Noop::class]]]);
        $this->createEmptyFile = false;
        $this->targetExtension = 'tsv';
        return $this->extractTextToTsvFromAlto($altoFilepath, $tsvFilepath, $item, $format);
    }

    protected function listMediaImagesData(ItemRepresentation $item): array
    {
        return [];
    }
}
