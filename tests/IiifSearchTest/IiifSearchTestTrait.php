<?php declare(strict_types=1);

namespace IiifSearchTest;

use IiifSearch\View\Helper\IiifSearch;
use ReflectionMethod;

/**
 * Shared test helpers for IiifSearch module tests.
 */
trait IiifSearchTestTrait
{
    /**
     * Get a fixture file path.
     */
    protected function getFixturePath(string $name): string
    {
        $path = dirname(__DIR__) . '/fixtures/' . $name;
        if (!file_exists($path)) {
            throw new \RuntimeException("Fixture not found: $path");
        }
        return $path;
    }

    /**
     * Get a fixture file content.
     */
    protected function getFixture(string $name): string
    {
        return file_get_contents($this->getFixturePath($name));
    }

    /**
     * Create an IiifSearch instance without constructor dependencies.
     *
     * Useful for testing protected methods via reflection.
     */
    protected function createHelperStub(): IiifSearch
    {
        return (new \ReflectionClass(IiifSearch::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * Call a protected method on an IiifSearch helper.
     *
     * @param IiifSearch $helper
     * @param string $method
     * @param array $args
     * @return mixed
     */
    protected function callProtected(
        IiifSearch $helper,
        string $method,
        array $args = []
    ) {
        $ref = new ReflectionMethod(IiifSearch::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($helper, ...$args);
    }
}
