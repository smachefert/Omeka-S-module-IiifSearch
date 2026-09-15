<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Pair OCR pages with image medias on an item.
 *
 * Auto mode runs a cascade of heuristics, falling back to sequential position
 * pairing when no stronger signal is available. Individual layers can be forced
 * via the $forcedMethod parameter for batch overrides.
 *
 * This skeleton ships only the sequential fallback
 * (PairingResult::METHOD_SEQUENTIAL); stronger layers are added in follow-up
 * commits while preserving the public surface.
 */
class PagePairer
{
    /**
     * Coverage threshold above which a layer's result is accepted without
     * trying the next layer. Lower coverage cascades.
     */
    const COVERAGE_THRESHOLD = 0.8;

    /** @var string */
    protected $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?: (defined('OMEKA_PATH') ? OMEKA_PATH . '/files' : '');
    }

    /**
     * @param PageSource[] $sources Ordered list of OCR pages to pair.
     * @param string $forcedMethod One of PairingResult::METHOD_*; METHOD_AUTO
     *   runs the cascade.
     */
    public function pair(
        ItemRepresentation $item,
        array $sources,
        string $forcedMethod = PairingResult::METHOD_AUTO
    ): PairingResult {
        $images = $this->collectImages($item);

        $methodsToTry = $forcedMethod === PairingResult::METHOD_AUTO
            ? [
                PairingResult::METHOD_METS,
                PairingResult::METHOD_SOURCE_IMAGE_INFORMATION,
                PairingResult::METHOD_BASENAME,
                PairingResult::METHOD_BASENAME_DIR,
                PairingResult::METHOD_NUMERIC,
                PairingResult::METHOD_NUMERIC_DIR,
                PairingResult::METHOD_DIMENSION,
                PairingResult::METHOD_SEQUENTIAL,
            ]
            : [$forcedMethod];

        $result = null;
        foreach ($methodsToTry as $method) {
            $candidate = $this->runLayer($method, $item, $sources, $images);
            if ($candidate) {
                $result = $candidate;
                break;
            }
        }
        if (!$result) {
            // Forced method that returned nothing: degrade to sequential rather
            // than failing the job.
            $result = $this->layerSequential($item, $sources, $images);
        }

        $this->enrichWarnings($result, $item, $sources);
        return $result;
    }

    protected function runLayer(
        string $method,
        ItemRepresentation $item,
        array $sources,
        array $images
    ): ?PairingResult {
        switch ($method) {
            case PairingResult::METHOD_METS:
                return $this->layerMets($item, $sources, $images);
            case PairingResult::METHOD_SOURCE_IMAGE_INFORMATION:
                return $this->layerSourceImageInformation($item, $sources, $images);
            case PairingResult::METHOD_BASENAME:
                return $this->layerBasename($item, $sources, $images);
            case PairingResult::METHOD_BASENAME_DIR:
                return $this->layerBasenameDir($item, $sources, $images);
            case PairingResult::METHOD_NUMERIC:
                return $this->layerNumeric($item, $sources, $images);
            case PairingResult::METHOD_NUMERIC_DIR:
                return $this->layerNumericDir($item, $sources, $images);
            case PairingResult::METHOD_DIMENSION:
                return $this->layerDimension($item, $sources, $images);
            case PairingResult::METHOD_SEQUENTIAL:
                return $this->layerSequential($item, $sources, $images);
        }
        return null;
    }

    protected function enrichWarnings(PairingResult $result, ItemRepresentation $item, array $sources = []): void
    {
        if ($result->pageCount > $result->imageCount) {
            $result->warnings[] = sprintf(
                'Item #%d: %d ocr pages but only %d image medias; trailing pages unpaired.',
                $item->id(),
                $result->pageCount,
                $result->imageCount
            );
        }
        $this->validateMonotonic($result, $item);
        if ($sources) {
            $this->validateAspectCoherence($result, $item, $sources);
        }
    }

    /**
     * Warn when paired images are not in increasing media position order: a
     * strong signal of manual reordering or a mismatched pairing.
     */
    protected function validateMonotonic(PairingResult $result, ItemRepresentation $item): void
    {
        $positions = [];
        $orderedMedias = [];
        $i = 0;
        foreach ($item->media() as $media) {
            $orderedMedias[(int) $media->id()] = $i++;
        }
        foreach ($result->matches as $page => $media) {
            if ($media) {
                $positions[$page] = $orderedMedias[(int) $media->id()] ?? null;
            }
        }
        $previous = -1;
        $broken = false;
        foreach ($positions as $pos) {
            if ($pos === null) {
                continue;
            }
            if ($pos < $previous) {
                $broken = true;
                break;
            }
            $previous = $pos;
        }
        if ($broken) {
            $result->warnings[] = sprintf(
                'Item #%d: paired images are not in increasing media position; manual reorder or mis-pairing suspected.',
                $item->id()
            );
        }
    }

    /**
     * Warn when paired source/image aspects diverge by more than 5%.
     *
     * @param PageSource[] $sources
     */
    protected function validateAspectCoherence(PairingResult $result, ItemRepresentation $item, array $sources): void
    {
        $tolerance = 0.05;
        $sourcesByPage = [];
        foreach ($sources as $offset => $source) {
            $page = $source->index ?: ($offset + 1);
            $sourcesByPage[$page] = $source;
        }
        $diverging = 0;
        foreach ($result->matches as $page => $media) {
            if (!$media || !isset($sourcesByPage[$page])) {
                continue;
            }
            $source = $sourcesByPage[$page];
            if (!$source->intrinsicDimensions
                || !$source->intrinsicDimensions[0]
                || !$source->intrinsicDimensions[1]
            ) {
                continue;
            }
            $dims = $this->imageDimensions($media);
            if (!$dims) {
                continue;
            }
            $srcAspect = $source->intrinsicDimensions[0] / $source->intrinsicDimensions[1];
            $imgAspect = $dims[0] / $dims[1];
            if ($imgAspect > 0 && abs($srcAspect - $imgAspect) / $imgAspect > $tolerance) {
                ++$diverging;
            }
        }
        if ($diverging > 0) {
            $result->warnings[] = sprintf(
                'Item #%d: %d page(s) have aspect ratio mismatch beyond 5%% with their paired image.',
                $item->id(),
                $diverging
            );
        }
    }

    /**
     * @return MediaRepresentation[]
     */
    protected function collectImages(ItemRepresentation $item): array
    {
        $images = [];
        foreach ($item->media() as $media) {
            $mediaType = (string) $media->mediaType();
            if (strtok($mediaType, '/') === 'image' && $media->hasOriginal()) {
                $images[] = $media;
            }
        }
        return $images;
    }

    /**
     * Layer 0a — find an attached METS media and delegate to MetsPairingOracle.
     * Returns null when no METS is present, the file cannot be parsed, or the
     * structMap does not encode page structure.
     *
     * @param PageSource[] $sources
     * @param MediaRepresentation[] $images
     */
    protected function layerMets(
        ItemRepresentation $item,
        array $sources,
        array $images
    ): ?PairingResult {
        if (!$sources || !$images || $this->basePath === '') {
            return null;
        }
        $classifier = new XmlMediaClassifier();
        $metsFilepath = null;
        foreach ($item->media() as $media) {
            if (!$classifier->isXmlLikeMedia($media)) {
                continue;
            }
            if ($classifier->classifyMedia($media, $this->basePath) === XmlMediaClassifier::TYPE_METS) {
                $filename = $media->filename();
                if ($filename) {
                    $metsFilepath = $this->basePath . '/original/' . $filename;
                    break;
                }
            }
        }
        if (!$metsFilepath || !is_readable($metsFilepath)) {
            return null;
        }

        libxml_use_internal_errors(true);
        $mets = @simplexml_load_file(
            $metsFilepath,
            'SimpleXMLElement',
            LIBXML_BIGLINES | LIBXML_COMPACT | LIBXML_NOBLANKS | LIBXML_PARSEHUGE | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if (!$mets) {
            return null;
        }

        $oracle = new MetsPairingOracle();
        return $oracle->pair($mets, $sources, $images);
    }

    /**
     * Layer 0b — pair from the OCR's own sourceImageInformation hint when
     * present (ALTO Description/sourceImageInformation/fileName, hOCR ocr_page
     * title="image foo.tif; ..."). Almost authoritative when a producer
     * populated the field; absent on most legacy datasets.
     *
     * @param PageSource[] $sources
     * @param MediaRepresentation[] $images
     */
    protected function layerSourceImageInformation(
        ItemRepresentation $item,
        array $sources,
        array $images
    ): ?PairingResult {
        if (!$sources || !$images) {
            return null;
        }

        $hinted = false;
        foreach ($sources as $source) {
            if ($source->sourceImageFileName) {
                $hinted = true;
                break;
            }
        }
        if (!$hinted) {
            return null;
        }

        $imagesByName = [];
        foreach ($images as $img) {
            $name = PageSource::normalizeName((string) $img->source());
            if ($name === '') {
                continue;
            }
            $imagesByName[$name][] = $img;
        }
        if (!$imagesByName) {
            return null;
        }

        $matches = [];
        $matched = 0;
        $hasCollision = false;
        foreach ($sources as $offset => $source) {
            $page = $source->index ?: ($offset + 1);
            $hint = $source->sourceImageFileName;
            if (!$hint) {
                $matches[$page] = null;
                continue;
            }
            $hintName = PageSource::normalizeName($hint);
            $candidates = $imagesByName[$hintName] ?? [];
            $countCandidates = count($candidates);
            if ($countCandidates === 1) {
                $matches[$page] = $candidates[0];
                ++$matched;
            } elseif ($countCandidates > 1) {
                $hasCollision = true;
                $matches[$page] = null;
            } else {
                $matches[$page] = null;
            }
        }

        if ($hasCollision) {
            return null;
        }

        $pageCount = count($sources);
        $coverage = $pageCount > 0 ? $matched / $pageCount : 0.0;
        if ($coverage < self::COVERAGE_THRESHOLD) {
            return null;
        }

        $result = new PairingResult();
        $result->matches = $matches;
        $result->method = PairingResult::METHOD_SOURCE_IMAGE_INFORMATION;
        $result->pageCount = $pageCount;
        $result->imageCount = count($images);
        $result->coverage = $coverage;
        return $result;
    }

    /**
     * Layer 1 — exact basename match after normalization. Returns null when
     * coverage falls below the threshold or when a normalized name resolves to
     * multiple images (collision) so the cascade can try the next layer.
     *
     * @param PageSource[] $sources
     * @param MediaRepresentation[] $images
     */
    protected function layerBasename(
        ItemRepresentation $item,
        array $sources,
        array $images
    ): ?PairingResult {
        if (!$sources || !$images) {
            return null;
        }

        $imagesByName = [];
        foreach ($images as $img) {
            $name = PageSource::normalizeName((string) $img->source());
            if ($name === '') {
                continue;
            }
            $imagesByName[$name][] = $img;
        }
        if (!$imagesByName) {
            return null;
        }

        $matches = [];
        $matched = 0;
        $hasCollision = false;
        foreach ($sources as $offset => $source) {
            $page = $source->index ?: ($offset + 1);
            $candidates = $imagesByName[$source->sourceName] ?? [];
            $countCandidates = count($candidates);
            if ($countCandidates === 1) {
                $matches[$page] = $candidates[0];
                ++$matched;
            } elseif ($countCandidates > 1) {
                $hasCollision = true;
                $matches[$page] = null;
            } else {
                $matches[$page] = null;
            }
        }

        if ($hasCollision) {
            return null;
        }

        $pageCount = count($sources);
        $coverage = $pageCount > 0 ? $matched / $pageCount : 0.0;
        if ($coverage < self::COVERAGE_THRESHOLD) {
            return null;
        }

        $result = new PairingResult();
        $result->matches = $matches;
        $result->method = PairingResult::METHOD_BASENAME;
        $result->pageCount = $pageCount;
        $result->imageCount = count($images);
        $result->coverage = $coverage;
        return $result;
    }

    /**
     * Layer 1.5 — basename match disambiguated by the directory residue of the
     * original source path. Resolves collisions left over by Layer 1 when
     * several images share the same normalized basename across different
     * batches (e.g., "scans/2020/001.tif" vs "scans/2024/001.tif" paired
     * against "ocr/2020/001.xml").
     *
     * @param PageSource[] $sources
     * @param MediaRepresentation[] $images
     */
    protected function layerBasenameDir(
        ItemRepresentation $item,
        array $sources,
        array $images
    ): ?PairingResult {
        if (!$sources || !$images) {
            return null;
        }

        $imagesByKey = [];
        foreach ($images as $img) {
            $name = PageSource::normalizeName((string) $img->source());
            if ($name === '') {
                continue;
            }
            $dir = PageSource::extractDirResidue((string) $img->source());
            $key = $name . '|' . ($dir ?? '');
            $imagesByKey[$key][] = $img;
        }
        if (!$imagesByKey) {
            return null;
        }

        $matches = [];
        $matched = 0;
        $hasCollision = false;
        foreach ($sources as $offset => $source) {
            $page = $source->index ?: ($offset + 1);
            $key = $source->sourceName . '|' . ($source->sourceDir ?? '');
            $candidates = $imagesByKey[$key] ?? [];
            $countCandidates = count($candidates);
            if ($countCandidates === 1) {
                $matches[$page] = $candidates[0];
                ++$matched;
            } elseif ($countCandidates > 1) {
                $hasCollision = true;
                $matches[$page] = null;
            } else {
                $matches[$page] = null;
            }
        }

        if ($hasCollision) {
            return null;
        }

        $pageCount = count($sources);
        $coverage = $pageCount > 0 ? $matched / $pageCount : 0.0;
        if ($coverage < self::COVERAGE_THRESHOLD) {
            return null;
        }

        $result = new PairingResult();
        $result->matches = $matches;
        $result->method = PairingResult::METHOD_BASENAME_DIR;
        $result->pageCount = $pageCount;
        $result->imageCount = count($images);
        $result->coverage = $coverage;
        return $result;
    }

    /**
     * Layer 2 — pair by the rightmost numeric token in the normalized name,
     * optionally followed by a single r/v folio side.
     *
     * @param PageSource[] $sources
     * @param MediaRepresentation[] $images
     */
    protected function layerNumeric(
        ItemRepresentation $item,
        array $sources,
        array $images
    ): ?PairingResult {
        return $this->layerNumericInternal($item, $sources, $images, false, PairingResult::METHOD_NUMERIC);
    }

    /**
     * Layer 2.5 — numeric token disambiguated by the directory residue.
     *
     * @param PageSource[] $sources
     * @param MediaRepresentation[] $images
     */
    protected function layerNumericDir(
        ItemRepresentation $item,
        array $sources,
        array $images
    ): ?PairingResult {
        return $this->layerNumericInternal($item, $sources, $images, true, PairingResult::METHOD_NUMERIC_DIR);
    }

    /**
     * @param PageSource[] $sources
     * @param MediaRepresentation[] $images
     */
    protected function layerNumericInternal(
        ItemRepresentation $item,
        array $sources,
        array $images,
        bool $withDir,
        string $method
    ): ?PairingResult {
        if (!$sources || !$images) {
            return null;
        }

        $imagesByKey = [];
        foreach ($images as $img) {
            $name = PageSource::normalizeName((string) $img->source());
            $token = $this->extractNumericKey($name);
            if ($token === null) {
                continue;
            }
            $dir = $withDir
                ? PageSource::extractDirResidue((string) $img->source())
                : null;
            $key = $token . '|' . ($dir ?? '');
            $imagesByKey[$key][] = $img;
        }
        if (!$imagesByKey) {
            return null;
        }

        $matches = [];
        $matched = 0;
        $hasCollision = false;
        foreach ($sources as $offset => $source) {
            $page = $source->index ?: ($offset + 1);
            $token = $this->extractNumericKey($source->sourceName);
            if ($token === null) {
                $matches[$page] = null;
                continue;
            }
            $key = $token . '|' . ($withDir ? ($source->sourceDir ?? '') : '');
            $candidates = $imagesByKey[$key] ?? [];
            $count = count($candidates);
            if ($count === 1) {
                $matches[$page] = $candidates[0];
                ++$matched;
            } elseif ($count > 1) {
                $hasCollision = true;
                $matches[$page] = null;
            } else {
                $matches[$page] = null;
            }
        }

        if ($hasCollision) {
            return null;
        }

        $pageCount = count($sources);
        $coverage = $pageCount > 0 ? $matched / $pageCount : 0.0;
        if ($coverage < self::COVERAGE_THRESHOLD) {
            return null;
        }

        $result = new PairingResult();
        $result->matches = $matches;
        $result->method = $method;
        $result->pageCount = $pageCount;
        $result->imageCount = count($images);
        $result->coverage = $coverage;
        return $result;
    }

    /**
     * Extract the rightmost digit run (optionally followed by r or v for folio
     * side) from a normalized name. Returns null when no trailing digit run is
     * found.
     */
    protected function extractNumericKey(string $normalizedName): ?string
    {
        if ($normalizedName === '') {
            return null;
        }
        if (!preg_match('/(\d+)([rv]?)$/i', $normalizedName, $m)) {
            return null;
        }
        return ((int) $m[1]) . strtolower($m[2]);
    }

    /**
     * Layer 3 — pair by aspect ratio. Active only when sources carry intrinsic
     * dimensions and the item's images do not share a single dominant aspect
     * (otherwise the layer brings no signal).
     *
     * @param PageSource[] $sources
     * @param MediaRepresentation[] $images
     */
    protected function layerDimension(
        ItemRepresentation $item,
        array $sources,
        array $images
    ): ?PairingResult {
        if (!$sources || !$images) {
            return null;
        }

        $imageAspects = [];
        foreach ($images as $img) {
            $dims = $this->imageDimensions($img);
            if (!$dims || !$dims[0] || !$dims[1]) {
                continue;
            }
            $imageAspects[] = ['media' => $img, 'aspect' => $dims[0] / $dims[1]];
        }
        if (!$imageAspects) {
            return null;
        }

        // Bail out when all images share roughly the same aspect: this layer
        // cannot discriminate uniform-format documents.
        if ($this->aspectIsUniform($imageAspects, 0.02)) {
            return null;
        }

        $hasIntrinsic = false;
        foreach ($sources as $source) {
            if ($source->intrinsicDimensions
                && $source->intrinsicDimensions[0]
                && $source->intrinsicDimensions[1]
            ) {
                $hasIntrinsic = true;
                break;
            }
        }
        if (!$hasIntrinsic) {
            return null;
        }

        $tolerance = 0.05;
        $matches = [];
        $matched = 0;
        $hasCollision = false;
        foreach ($sources as $offset => $source) {
            $page = $source->index ?: ($offset + 1);
            if (!$source->intrinsicDimensions
                || !$source->intrinsicDimensions[0]
                || !$source->intrinsicDimensions[1]
            ) {
                $matches[$page] = null;
                continue;
            }
            $srcAspect = $source->intrinsicDimensions[0] / $source->intrinsicDimensions[1];
            $candidates = [];
            foreach ($imageAspects as $entry) {
                if (abs($entry['aspect'] - $srcAspect) / $srcAspect <= $tolerance) {
                    $candidates[] = $entry['media'];
                }
            }
            $count = count($candidates);
            if ($count === 1) {
                $matches[$page] = $candidates[0];
                ++$matched;
            } elseif ($count > 1) {
                $hasCollision = true;
                $matches[$page] = null;
            } else {
                $matches[$page] = null;
            }
        }

        if ($hasCollision) {
            return null;
        }

        $pageCount = count($sources);
        $coverage = $pageCount > 0 ? $matched / $pageCount : 0.0;
        if ($coverage < self::COVERAGE_THRESHOLD) {
            return null;
        }

        $result = new PairingResult();
        $result->matches = $matches;
        $result->method = PairingResult::METHOD_DIMENSION;
        $result->pageCount = $pageCount;
        $result->imageCount = count($images);
        $result->coverage = $coverage;
        return $result;
    }

    /**
     * @return array{int,int}|null
     */
    protected function imageDimensions(MediaRepresentation $media): ?array
    {
        $data = $media->mediaData();
        if (isset($data['width'], $data['height']) && $data['width'] && $data['height']) {
            return [(int) $data['width'], (int) $data['height']];
        }
        if (isset($data['dimensions']['original']['width'], $data['dimensions']['original']['height'])) {
            $w = (int) $data['dimensions']['original']['width'];
            $h = (int) $data['dimensions']['original']['height'];
            if ($w && $h) {
                return [$w, $h];
            }
        }
        return null;
    }

    /**
     * @param array<int,array{media:MediaRepresentation,aspect:float}> $imageAspects
     */
    protected function aspectIsUniform(array $imageAspects, float $tolerance): bool
    {
        if (count($imageAspects) < 2) {
            return true;
        }
        $reference = $imageAspects[0]['aspect'];
        foreach ($imageAspects as $entry) {
            if (abs($entry['aspect'] - $reference) / $reference > $tolerance) {
                return false;
            }
        }
        return true;
    }

    /**
     * Layer 4 — pair page i with image i (positional order). Always covers 100%
     * when there are at least as many images as pages.
     *
     * @param PageSource[] $sources
     * @param MediaRepresentation[] $images
     */
    protected function layerSequential(
        ItemRepresentation $item,
        array $sources,
        array $images
    ): PairingResult {
        $pageCount = count($sources);
        $imageCount = count($images);

        $matches = [];
        $matched = 0;
        foreach ($sources as $offset => $source) {
            $page = $source->index ?: ($offset + 1);
            $img = $images[$offset] ?? null;
            $matches[$page] = $img;
            if ($img) {
                ++$matched;
            }
        }

        $result = new PairingResult();
        $result->matches = $matches;
        $result->method = PairingResult::METHOD_SEQUENTIAL;
        $result->pageCount = $pageCount;
        $result->imageCount = $imageCount;
        $result->coverage = $pageCount > 0 ? $matched / $pageCount : 0.0;
        return $result;
    }
}
