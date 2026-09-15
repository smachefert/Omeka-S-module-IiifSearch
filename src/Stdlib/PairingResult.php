<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

use Omeka\Api\Representation\MediaRepresentation;

/**
 * Outcome of a PagePairer run.
 *
 * matches: page index (1-based) => image media, or null when no image could be
 * paired with that page.
 */
class PairingResult
{
    const METHOD_AUTO = 'auto';
    const METHOD_METS = 'mets';
    const METHOD_SOURCE_IMAGE_INFORMATION = 'source_image_information';
    const METHOD_BASENAME = 'basename';
    const METHOD_BASENAME_DIR = 'basename_dir';
    const METHOD_NUMERIC = 'numeric';
    const METHOD_NUMERIC_DIR = 'numeric_dir';
    const METHOD_DIMENSION = 'dimension';
    const METHOD_SEQUENTIAL = 'sequential';

    /** @var array<int,?MediaRepresentation> */
    public array $matches = [];

    public string $method = self::METHOD_SEQUENTIAL;

    public float $coverage = 0.0;

    public int $pageCount = 0;

    public int $imageCount = 0;

    /** @var string[] */
    public array $warnings = [];

    public function imageForPage(int $page): ?MediaRepresentation
    {
        return $this->matches[$page] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->coverage >= 1.0;
    }
}
