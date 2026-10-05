<?php declare(strict_types=1);

namespace IiifSearchTest\Controller;

use IiifSearchTest\IiifSearchTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Route /iiif/search/{id}/alto.xml (whole alto of the item), url without
 * identifier and redirection of the old route /iiif-search/.
 *
 * @group altoroute
 */
class AltoRouteTest extends AbstractHttpControllerTestCase
{
    use IiifSearchTestTrait;

    protected ?int $itemId = null;

    protected ?string $altoPath = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $item = $this->getApplicationServiceLocator()->get('Omeka\ApiManager')
            ->create('items', ['o:is_public' => true])->getContent();
        $this->itemId = (int) $item->id();
    }

    public function tearDown(): void
    {
        if ($this->altoPath && file_exists($this->altoPath)) {
            unlink($this->altoPath);
        }
        $this->loginAdmin();
        $this->getApplicationServiceLocator()->get('Omeka\ApiManager')
            ->delete('items', $this->itemId);
        parent::tearDown();
    }

    public function testWholeAltoIsServed(): void
    {
        $this->writeConsolidatedAlto();
        $this->logout();

        $this->dispatch('/iiif/search/' . $this->itemId . '/alto.xml');

        $this->assertResponseStatusCode(200);
        $this->assertResponseHeaderContains('Content-Type', 'application/xml+alto; charset=utf-8');
        $this->assertSame(file_get_contents($this->altoPath), $this->getResponse()->getContent());
    }

    public function testWholeAltoIsMissing(): void
    {
        $this->logout();

        $this->dispatch('/iiif/search/' . $this->itemId . '/alto.xml');

        $this->assertResponseStatusCode(404);
    }

    public function testWholeAltoOfReservedItemIsDenied(): void
    {
        $services = $this->getApplicationServiceLocator();
        if (!$services->get('ControllerPluginManager')->has('isAllowedMediaContent')) {
            $this->markTestSkipped('Requires module Access.');
        }

        $this->writeConsolidatedAlto();
        $settings = $services->get('Omeka\Settings');
        $previousModes = $settings->get('access_modes');
        $settings->set('access_modes', ['user']);
        $this->setAccessLevel($this->itemId, 'reserved');
        $this->logout();

        try {
            $this->dispatch('/iiif/search/' . $this->itemId . '/alto.xml');
            $this->assertResponseStatusCode(403);
            $this->assertResponseHeaderContains('Omeka-S-Access-Status', 'denied');
        } finally {
            $settings->set('access_modes', $previousModes);
        }
    }

    public function testUrlWithoutIdentifier(): void
    {
        $this->dispatch('/iiif/search');

        $this->assertResponseStatusCode(400);
        $content = json_decode($this->getResponse()->getContent(), true);
        $this->assertSame('error', $content['status'] ?? null);
    }

    public function testSearchInfoWithoutImage(): void
    {
        $this->dispatch('/iiif/search/' . $this->itemId . '/search/2/info.json');

        $this->assertResponseStatusCode(404);
        $this->assertMatchedRouteName('iiifsearch/search-info');
    }

    public function testOldRouteIsRedirected(): void
    {
        $this->dispatch('/iiif-search/' . $this->itemId . '/search?q=test');

        $this->assertResponseStatusCode(301);
        $this->assertRedirectRegex('~/iiif/search/' . $this->itemId . '/search\?q=test$~');
    }

    protected function writeConsolidatedAlto(): void
    {
        $config = $this->getApplicationServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        if (!is_dir($basePath . '/alto')) {
            mkdir($basePath . '/alto', 0775, true);
        }
        $this->altoPath = sprintf('%s/alto/%d.alto.xml', $basePath, $this->itemId);
        copy($this->getFixturePath('sample.alto.xml'), $this->altoPath);
    }

    protected function setAccessLevel(int $resourceId, string $level): void
    {
        $entityManager = $this->getApplicationServiceLocator()->get('Omeka\EntityManager');
        $status = $entityManager->find(\Access\Entity\AccessStatus::class, $resourceId);
        if (!$status) {
            $status = new \Access\Entity\AccessStatus();
            $status->setId($entityManager->find(\Omeka\Entity\Resource::class, $resourceId));
            $entityManager->persist($status);
        }
        $status->setLevelSet($level);
        $status->setLevel($level);
        $entityManager->flush();
    }

    protected function loginAdmin(): void
    {
        $auth = $this->getApplicationServiceLocator()->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    protected function logout(): void
    {
        $this->getApplicationServiceLocator()->get('Omeka\AuthenticationService')->clearIdentity();
    }
}
