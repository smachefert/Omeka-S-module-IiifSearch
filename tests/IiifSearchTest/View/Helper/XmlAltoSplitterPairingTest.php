<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * The alto of each page is paired with the images only, like the canvases of
 * the manifest, even when a pdf and a text are attached to each page.
 *
 * @group altosplitter
 */
class XmlAltoSplitterPairingTest extends AbstractHttpControllerTestCase
{
    protected ?int $itemId = null;

    protected array $filepaths = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $services = $this->getApplicationServiceLocator();
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';

        // Three pages, each with an image, a pdf and an alto.
        $media = [];
        foreach ([1, 2, 3] as $page) {
            foreach (['jpg', 'pdf', 'xml'] as $extension) {
                $media[] = ['o:ingester' => 'html', 'html' => "$page.$extension"];
            }
        }
        $item = $services->get('Omeka\ApiManager')
            ->create('items', ['o:is_public' => true, 'o:media' => $media])->getContent();
        $this->itemId = (int) $item->id();

        $connection = $services->get('Omeka\Connection');
        $mediaTypes = ['jpg' => 'image/jpeg', 'pdf' => 'application/pdf', 'xml' => 'application/alto+xml'];
        foreach ($item->media() as $index => $medium) {
            $page = intdiv($index, 3) + 1;
            $extension = array_keys($mediaTypes)[$index % 3];
            $storageId = sprintf('test-pairing-%d-%s-%04d', $this->itemId, $extension, $page);
            $filename = "$storageId.$extension";
            $filepath = "$basePath/original/$filename";
            file_put_contents($filepath, $extension === 'xml' ? $this->alto($page) : 'x');
            $this->filepaths[] = $filepath;
            $connection->executeStatement(
                'UPDATE `media` SET `media_type` = ?, `storage_id` = ?, `extension` = ?, `source` = ?, `has_original` = 1 WHERE `id` = ?',
                [$mediaTypes[$extension], $storageId, $extension, sprintf('%04d.%s', $page, $extension), $medium->id()]
            );
        }
        $services->get('Omeka\EntityManager')->clear();
    }

    public function tearDown(): void
    {
        foreach ($this->filepaths as $filepath) {
            @unlink($filepath);
        }
        $services = $this->getApplicationServiceLocator();
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        @unlink("$basePath/iiif-search/{$this->itemId}/pairing.json");
        @rmdir("$basePath/iiif-search/{$this->itemId}");
        $this->loginAdmin();
        $services->get('Omeka\ApiManager')->delete('items', $this->itemId);
        parent::tearDown();
    }

    public function testEachPageGetsItsAlto(): void
    {
        $services = $this->getApplicationServiceLocator();
        $item = $services->get('Omeka\ApiManager')->read('items', $this->itemId)->getContent();
        $splitter = $services->get('ViewHelperManager')->get('xmlAltoSplitter');
        foreach ([0, 1, 2] as $pageIndex) {
            $path = $splitter($item, $pageIndex);
            $this->assertNotNull($path, "Page index $pageIndex has no alto.");
            $this->assertStringEndsWith(sprintf('-xml-%04d.xml', $pageIndex + 1), $path);
        }
        $this->assertNull($splitter($item, 3));
    }

    protected function alto(int $page): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <alto xmlns="http://www.loc.gov/standards/alto/ns-v3#">
              <Description><sourceImageInformation><fileName>{$this->pageName($page)}.jpg</fileName></sourceImageInformation></Description>
              <Layout><Page ID="p$page" WIDTH="100" HEIGHT="100"><PrintSpace><TextBlock><TextLine>
                <String CONTENT="page$page" HPOS="1" VPOS="1" WIDTH="10" HEIGHT="10"/>
              </TextLine></TextBlock></PrintSpace></Page></Layout>
            </alto>
            XML;
    }

    protected function pageName(int $page): string
    {
        return sprintf('%04d', $page);
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
