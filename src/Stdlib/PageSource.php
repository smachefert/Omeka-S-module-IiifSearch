<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

use Omeka\Api\Representation\MediaRepresentation;

/**
 * A single page of OCR data exposed to the pairer.
 *
 * Wraps either an attached media (alto/hocr/pdf2xml/pdf) or a plain filepath
 * (tesseract-generated alto, hocr-converted alto, etc.). Carries the logical
 * name used by the pairing heuristics; the filesystem path is exposed
 * separately so callers can read the actual file.
 */
class PageSource
{
    /** Always 1-based. */
    public int $index;

    public ?MediaRepresentation $media;

    public ?string $filepath;

    /**
     * Logical name used by basename/numeric heuristics, normalized: lowercase
     * NFC, separators collapsed, OCR/image extension dropped.
     */
    public string $sourceName;

    /**
     * Directory residue of the original source (without bucket-like segments
     * such as "alto", "ocr", "images"). Used for the dir-aware matching layers.
     */
    public ?string $sourceDir;

    /**
     * Raw, unnormalized name as Omeka stored it (media->source() or basename of
     * the filepath). Kept for diagnostics and source-of-truth comparisons.
     */
    public string $rawSourceName;

    /** XmlMediaClassifier::TYPE_* */
    public string $classification;

    /**
     * Intrinsic page index inside the underlying source when it carries
     * multiple pages (ALTO multipage). 1 for per-page sources.
     */
    public int $intrinsicPage = 1;

    /** [w, h] in pixels when known. */
    public ?array $intrinsicDimensions = null;

    /** Image filename hinted by the source itself (ALTO sourceImage, hOCR title). */
    public ?string $sourceImageFileName = null;

    public function __construct(
        int $index,
        ?MediaRepresentation $media,
        ?string $filepath,
        string $rawSourceName,
        string $classification = 'alto'
    ) {
        $this->index = $index;
        $this->media = $media;
        $this->filepath = $filepath;
        $this->rawSourceName = $rawSourceName;
        $this->classification = $classification;
        $this->sourceName = self::normalizeName($rawSourceName);
        $this->sourceDir = self::extractDirResidue($rawSourceName);
    }

    public static function normalizeName(string $name): string
    {
        $base = basename($name);
        $base = function_exists('mb_strtolower')
            ? mb_strtolower($base, 'UTF-8')
            : strtolower($base);
        // Drop chained ocr/xml/html/image extensions.
        $patterns = [
            '~\.alto\.xml$~i',
            '~\.pdf2xml\.xml$~i',
            '~\.(?:hocr|html?|xml)$~i',
            '~\.(?:tiff?|jpe?g|jp2|png|webp|gif)$~i',
            '~\.pdf$~i',
        ];
        foreach ($patterns as $pat) {
            $next = preg_replace($pat, '', $base) ?? $base;
            if ($next !== $base) {
                $base = $next;
                // Re-apply to handle .alto.xml -> .alto then nothing else.
            }
        }
        $base = preg_replace('~[\s_-]+~', '-', $base) ?? $base;
        return trim($base, '-');
    }

    /**
     * Strip bucket segments (images, ocr, alto, ...) and return the remaining
     * directory components joined with "/". Empty when nothing remains.
     */
    public static function extractDirResidue(string $name): ?string
    {
        $dir = ltrim((string) dirname($name), '.');
        if ($dir === '' || $dir === '/' || $dir === '.') {
            return null;
        }
        $dir = function_exists('mb_strtolower')
            ? mb_strtolower($dir, 'UTF-8')
            : strtolower($dir);
        $segments = preg_split('~[\\\\/]+~', $dir) ?: [];
        $buckets = [
            'image', 'images', 'img', 'imgs',
            'scan', 'scans', 'numerisation', 'numerisations',
            'original', 'originals', 'master', 'masters',
            'tif', 'tiff', 'jpg', 'jpeg', 'png',
            'ocr', 'alto', 'hocr', 'xml', 'pdf', 'pdfs',
            'derivative', 'derivatives',
        ];
        $kept = [];
        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '' || in_array($seg, $buckets, true)) {
                continue;
            }
            $kept[] = $seg;
        }
        return $kept ? implode('/', $kept) : null;
    }
}
