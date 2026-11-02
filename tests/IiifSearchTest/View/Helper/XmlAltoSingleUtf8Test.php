<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use IiifSearch\View\Helper\FixUtf8;
use IiifSearch\View\Helper\XmlAltoSingle;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Mock;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class XmlAltoSingleUtf8Test extends TestCase
{
    protected $writer;

    protected function load(string $content): ?SimpleXMLElement
    {
        $filepath = tempnam(sys_get_temp_dir(), 'iiifsearch');
        file_put_contents($filepath, $content);
        $this->writer = new Mock();
        $logger = new Logger();
        $logger->addWriter($this->writer);
        $helper = new XmlAltoSingleProxy($logger, new FixUtf8(), sys_get_temp_dir());
        try {
            return $helper->proxyLoad($filepath);
        } finally {
            @unlink($filepath);
        }
    }

    protected function loggedMessages(): array
    {
        return array_map(fn ($event) => (string) $event['message'], $this->writer->events);
    }

    public function testLoadValidXml(): void
    {
        $xml = $this->load('<?xml version="1.0" encoding="UTF-8"?><alto><String CONTENT="été"/></alto>');
        $this->assertSame('été', (string) $xml->String['CONTENT']);
        $this->assertSame([], $this->loggedMessages());
    }

    /**
     * A windows-1252 file name in an alto declared as utf-8.
     */
    public function testLoadXmlWithInvalidUtf8(): void
    {
        $xml = $this->load('<?xml version="1.0" encoding="UTF-8"?>'
            . "<alto><Description><fileName>D\xE9dicace.jpg</fileName></Description>"
            . '<Layout><Page><String CONTENT="été"/></Page></Layout></alto>');
        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
        $this->assertSame('Dédicace.jpg', (string) $xml->Description->fileName);
        $this->assertSame('été', (string) $xml->Layout->Page->String['CONTENT']);
        $this->assertCount(1, $this->loggedMessages());
        $this->assertStringContainsString('Invalid utf-8 characters', $this->loggedMessages()[0]);
    }

    public function testLoadMalformedXml(): void
    {
        $xml = $this->load('<?xml version="1.0" encoding="UTF-8"?><alto><Page><String CONTENT="été"/></alto>');
        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
        $this->assertSame('été', (string) $xml->Page->String['CONTENT']);
        $this->assertCount(1, $this->loggedMessages());
        $this->assertStringContainsString('Malformed xml repaired', $this->loggedMessages()[0]);
    }
}

class XmlAltoSingleProxy extends XmlAltoSingle
{
    public function proxyLoad(string $filepath): ?SimpleXMLElement
    {
        return $this->loadXmlFromFilepath($filepath, 1);
    }
}
