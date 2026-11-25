<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use IiifSearch\Stdlib\XmlRepair;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * The annotations of a page are built even when its alto declared as utf-8 has
 * invalid characters, like a windows-1252 file name.
 */
class IiifAltoAnnotationsUtf8Test extends AbstractHttpControllerTestCase
{
    protected ?int $itemId = null;

    protected string $altoPath;

    public function setUp(): void
    {
        parent::setUp();
        $this->altoPath = tempnam(sys_get_temp_dir(), 'iiifsearch') . '.xml';
        file_put_contents($this->altoPath, '<?xml version="1.0" encoding="UTF-8"?>'
            . '<alto xmlns="http://www.loc.gov/standards/alto/ns-v3#">'
            . "<Description><sourceImageInformation><fileName>D\xE9dicace.jpg</fileName></sourceImageInformation></Description>"
            . '<Layout><Page ID="p1" WIDTH="100" HEIGHT="100"><PrintSpace><TextBlock>'
            . '<TextLine HPOS="1" VPOS="2" WIDTH="30" HEIGHT="10"><String CONTENT="Dédicace"/><String CONTENT="à"/></TextLine>'
            . '</TextBlock></PrintSpace></Page></Layout></alto>');
    }

    public function tearDown(): void
    {
        @unlink($this->altoPath);
        if ($this->itemId) {
            $this->loginAdmin();
            $this->getApplicationServiceLocator()->get('Omeka\ApiManager')->delete('items', $this->itemId);
        }
        parent::tearDown();
    }

    public function testLoadFileFixesInvalidUtf8(): void
    {
        $xml = XmlRepair::loadFile($this->altoPath);
        $this->assertNotNull($xml);
        $xml->registerXPathNamespace('a', 'http://www.loc.gov/standards/alto/ns-v3#');
        $this->assertSame('Dédicace.jpg', (string) $xml->xpath('//a:fileName')[0]);
    }

    public function testLoadFileRepairsMalformedXml(): void
    {
        file_put_contents($this->altoPath, '<?xml version="1.0" encoding="UTF-8"?><alto><Layout><Page ID="p1"></Layout></alto>');
        $this->assertNotNull(XmlRepair::loadFile($this->altoPath));
    }

    /**
     * @dataProvider helperProvider
     */
    public function testAnnotationsOfInvalidUtf8Alto(string $helperName, string $itemsKey): void
    {
        $services = $this->getApplicationServiceLocator();
        $helpers = $services->get('ViewHelperManager');
        if (!$helpers->has('iiifUrl')) {
            $this->markTestSkipped('Requires module IIIF Server.');
        }
        $this->loginAdmin();
        $item = $services->get('Omeka\ApiManager')->create('items', ['o:is_public' => true])->getContent();
        $this->itemId = (int) $item->id();

        // The id of the annotation page is the url of the request.
        $_SERVER['REQUEST_URI'] ??= '/iiif/search/' . $this->itemId . '/annotations/1.json';
        $page = $helpers->get($helperName)($item, 0, $this->altoPath);
        $this->assertIsArray($page);
        $this->assertCount(1, $page[$itemsKey]);
        $this->assertStringContainsString('Dédicace à', json_encode($page[$itemsKey][0], JSON_UNESCAPED_UNICODE));
    }

    public function helperProvider(): array
    {
        return [
            'v3' => ['iiifAltoAnnotations', 'items'],
            'v2' => ['iiifAltoAnnotations2', 'resources'],
        ];
    }

    protected function loginAdmin(): void
    {
        $auth = $this->getApplicationServiceLocator()->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }
}
