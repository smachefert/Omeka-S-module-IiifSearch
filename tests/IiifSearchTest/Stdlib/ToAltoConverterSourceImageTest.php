<?php declare(strict_types=1);

namespace IiifSearchTest\Stdlib;

use IiifSearch\Stdlib\ToAltoConverter;
use PHPUnit\Framework\TestCase;

class ToAltoConverterSourceImageTest extends TestCase
{
    protected function sourceImage(string $content): ?string
    {
        $filepath = tempnam(sys_get_temp_dir(), 'iiifsearch');
        file_put_contents($filepath, $content);
        try {
            return ToAltoConverter::sourceImageFilename($filepath);
        } finally {
            @unlink($filepath);
        }
    }

    public function testSourceImageOfTesseractAlto(): void
    {
        $this->assertSame('22bf908aa02c0d2feeb2609f2096f064ffc2e731.jpg', $this->sourceImage(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <alto xmlns="http://www.loc.gov/standards/alto/ns-v3#">
              <Description>
                <MeasurementUnit>pixel</MeasurementUnit>
                <sourceImageInformation>
                  <fileName>/var/www/html/files/original/22bf908aa02c0d2feeb2609f2096f064ffc2e731.jpg</fileName>
                </sourceImageInformation>
              </Description>
              <Layout/>
            </alto>
            XML));
    }

    public function testNoSourceImage(): void
    {
        $this->assertNull($this->sourceImage('<alto><Description/><Layout><Page/></Layout></alto>'));
        $this->assertNull($this->sourceImage('not xml'));
    }
}
