<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use IiifSearch\View\Helper\XmlAltoSplitter;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * The alto of each page is paired with the remote iiif images, that have no
 * original file: they are canvases of the manifest, named by the identifier in
 * the url of their info.json.
 *
 * @group altosplitter
 */
class XmlAltoSplitterIiifImagesTest extends AbstractHttpControllerTestCase
{
    protected ?int $itemId = null;

    protected array $filepaths = [];

    protected array $altoIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $services = $this->getApplicationServiceLocator();
        $basePath = $this->basePath();

        // Three remote iiif images, then three altos in the reverse order, so a
        // pairing by position would be wrong.
        $media = [];
        foreach (range(1, 6) as $index) {
            $media[] = ['o:ingester' => 'html', 'html' => (string) $index];
        }
        $item = $services->get('Omeka\ApiManager')
            ->create('items', ['o:is_public' => true, 'o:media' => $media])->getContent();
        $this->itemId = (int) $item->id();

        $connection = $services->get('Omeka\Connection');
        foreach ($item->media() as $index => $medium) {
            if ($index < 3) {
                $page = $index + 1;
                $url = sprintf('https://example.org/iiif/2/BOOK%%2FBOOK_0001%%2Fmaster%%2FBOOK_0001_%04d.JP2/info.json', $page);
                $connection->executeStatement(
                    'UPDATE `media` SET `ingester` = "iiif", `renderer` = "iiif", `media_type` = NULL, `has_original` = 0, `source` = ?, `data` = ? WHERE `id` = ?',
                    [$url, json_encode(['@id' => substr($url, 0, -10), 'width' => 100, 'height' => 100]), $medium->id()]
                );
                continue;
            }
            $page = 6 - $index;
            $storageId = sprintf('test-iiif-pairing-%d-%04d', $this->itemId, $page);
            $filepath = "$basePath/original/$storageId.xml";
            file_put_contents($filepath, $this->alto($page));
            $this->filepaths[] = $filepath;
            $this->altoIds[$page] = (int) $medium->id();
            $connection->executeStatement(
                'UPDATE `media` SET `media_type` = "application/alto+xml", `storage_id` = ?, `extension` = "xml", `source` = ?, `has_original` = 1 WHERE `id` = ?',
                [$storageId, sprintf('BOOK/BOOK_0001/ALTO/BOOK_0001_%04d.XML', $page), $medium->id()]
            );
        }
        $services->get('Omeka\EntityManager')->clear();
    }

    public function tearDown(): void
    {
        foreach ($this->filepaths as $filepath) {
            @unlink($filepath);
        }
        $basePath = $this->basePath();
        foreach (glob("$basePath/iiif-search/{$this->itemId}/*") ?: [] as $filepath) {
            @unlink($filepath);
        }
        @rmdir("$basePath/iiif-search/{$this->itemId}");
        $this->loginAdmin();
        $this->getApplicationServiceLocator()->get('Omeka\ApiManager')->delete('items', $this->itemId);
        parent::tearDown();
    }

    public function testRemoteIiifImagesAreCanvases(): void
    {
        $item = $this->item();
        $images = $this->getApplicationServiceLocator()->get('ViewHelperManager')
            ->get('itemFileResources')->images($item);
        $this->assertCount(3, $images);
    }

    public function testEachRemoteImageGetsItsAltoByName(): void
    {
        $item = $this->item();
        $splitter = $this->getApplicationServiceLocator()->get('ViewHelperManager')->get('xmlAltoSplitter');
        foreach ([0, 1, 2] as $pageIndex) {
            $path = $splitter($item, $pageIndex);
            $this->assertNotNull($path, "Page index $pageIndex has no alto.");
            $this->assertStringContainsString('CONTENT="page' . ($pageIndex + 1) . '"', (string) file_get_contents($path));
        }
    }

    public function testPairingCachedByPreviousVersionIsComputedAgain(): void
    {
        // A previous version cached an empty pairing, with a signature without
        // version.
        $item = $this->item();
        $services = $this->getApplicationServiceLocator();
        $mode = (string) ($services->get('Omeka\Settings')->get('iiifsearch_alto_pairing_mode') ?: 'auto');
        $signature = [$mode];
        $basePath = $this->basePath();
        foreach ($item->media() as $medium) {
            if ($medium->mediaType() === 'application/alto+xml') {
                $signature[] = $medium->id() . ':' . $medium->filename() . ':' . (int) filemtime("$basePath/original/" . $medium->filename());
            }
        }
        $cacheDir = "$basePath/iiif-search/{$this->itemId}";
        @mkdir($cacheDir, 0775, true);
        file_put_contents("$cacheDir/pairing.json", json_encode(['signature' => md5(implode('|', $signature)), 'altos' => []]));

        $splitter = $services->get('ViewHelperManager')->get('xmlAltoSplitter');
        $this->assertNotNull($splitter($item, 0));
        $cache = json_decode((string) file_get_contents("$cacheDir/pairing.json"), true);
        $this->assertNotEmpty(array_filter($cache['altos']));
        $this->assertSame(2, XmlAltoSplitter::PAIRING_VERSION);
    }

    protected function item()
    {
        return $this->getApplicationServiceLocator()->get('Omeka\ApiManager')->read('items', $this->itemId)->getContent();
    }

    protected function basePath(): string
    {
        return $this->getApplicationServiceLocator()->get('Config')['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
    }

    protected function alto(int $page): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <alto xmlns="http://www.loc.gov/standards/alto/ns-v3#">
              <Layout><Page ID="p$page" WIDTH="100" HEIGHT="100"><PrintSpace><TextBlock><TextLine>
                <String CONTENT="page$page" HPOS="1" VPOS="1" WIDTH="10" HEIGHT="10"/>
              </TextLine></TextBlock></PrintSpace></Page></Layout>
            </alto>
            XML;
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
