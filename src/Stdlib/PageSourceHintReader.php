<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

/**
 * Read the image-filename hint embedded in an OCR source so the pairer's Layer
 * 0b (sourceImageInformation) can use it.
 *
 * Supports:
 * - ALTO: Description/sourceImageInformation/fileName.
 * - hOCR: <*[class~="ocr_page"] title="image foo.tif; bbox …">
 * - TEI:  <facsimile><surface @source="…">
 *
 * Returns null when no hint is present; the file is read only once, no caching
 * here since PageSource construction is itself a one-shot per item.
 */
class PageSourceHintReader
{
    public function readImageHint(string $filepath, string $classification): ?string
    {
        $hints = $this->readAllImageHints($filepath, $classification);
        foreach ($hints as $hint) {
            if ($hint !== '') {
                return $hint;
            }
        }
        return null;
    }

    /**
     * Return one hint per intrinsic page in the source file (single-element
     * array for per-page sources, N-element array for multipage TEI/ALTO/hOCR).
     * Pages without a hint yield an empty string at their position so the
     * indexing stays aligned with the document's natural page order.
     *
     * @return string[]
     */
    public function readAllImageHints(string $filepath, string $classification): array
    {
        if (!is_readable($filepath)) {
            return [];
        }
        switch ($classification) {
            case XmlMediaClassifier::TYPE_ALTO:
                return $this->readAltoHints($filepath);
            case XmlMediaClassifier::TYPE_HOCR:
                return $this->readHocrHints($filepath);
            case XmlMediaClassifier::TYPE_TEI:
                return $this->readTeiHints($filepath);
        }
        return [];
    }

    /**
     * Per-Page sourceImageInformation/fileName when present; falls back to the
     * single Description-level entry shared by all pages.
     *
     * @return string[]
     */
    protected function readAltoHints(string $filepath): array
    {
        // ALTO can be large; read enough head to cover several Page elements
        // but bail if the file is enormous.
        $content = (string) @file_get_contents($filepath, false, null, 0, 1048576);
        if ($content === '' || stripos($content, 'sourceimageinformation') === false) {
            return [];
        }
        $hints = [];
        if (preg_match_all('~<(?:[\w-]+:)?Page\b[^>]*>.*?(<(?:[\w-]+:)?sourceImageInformation>.*?</(?:[\w-]+:)?sourceImageInformation>).*?</(?:[\w-]+:)?Page>~is', $content, $matches)) {
            foreach ($matches[1] as $block) {
                if (preg_match('~<(?:[\w-]+:)?fileName>\s*([^<]+?)\s*</(?:[\w-]+:)?fileName>~i', $block, $m)) {
                    $hints[] = trim($m[1]);
                } else {
                    $hints[] = '';
                }
            }
        }
        if ($hints) {
            return $hints;
        }
        if (preg_match('~<(?:[\w-]+:)?fileName>\s*([^<]+?)\s*</(?:[\w-]+:)?fileName>~i', $content, $m)) {
            return [trim($m[1])];
        }
        return [];
    }

    /**
     * One hint per ocr_page div found in the hOCR.
     *
     * @return string[]
     */
    protected function readHocrHints(string $filepath): array
    {
        $content = (string) @file_get_contents($filepath, false, null, 0, 1048576);
        if ($content === '' || stripos($content, 'ocr_page') === false) {
            return [];
        }
        $hints = [];
        if (preg_match_all('~class=["\'][^"\']*ocr_page[^"\']*["\'][^>]*title=["\']([^"\']*)~i', $content, $matches)) {
            foreach ($matches[1] as $title) {
                if (preg_match('~\bimage\s+([^;]+)~i', $title, $m)) {
                    $hints[] = trim($m[1], " \t\"'");
                } else {
                    $hints[] = '';
                }
            }
        }
        return $hints;
    }

    /**
     * One hint per <surface @source> for multipage TEI; falls back to
     * <graphic @url> when surface is not used.
     *
     * @return string[]
     */
    protected function readTeiHints(string $filepath): array
    {
        $content = (string) @file_get_contents($filepath, false, null, 0, 1048576);
        if ($content === '') {
            return [];
        }
        $hints = [];
        if (preg_match_all('~<(?:[\w-]+:)?surface\b[^>]*\s(?:[\w-]+:)?source=["\']([^"\']+)~i', $content, $matches)) {
            foreach ($matches[1] as $source) {
                $hints[] = trim($source);
            }
        }
        if (!$hints && preg_match_all('~<(?:[\w-]+:)?graphic\b[^>]*\s(?:[\w-]+:)?url=["\']([^"\']+)~i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $hints[] = trim($url);
            }
        }
        return $hints;
    }

    protected function readHead(string $filepath, int $bytes): string
    {
        $fp = @fopen($filepath, 'rb');
        if (!$fp) {
            return '';
        }
        $head = (string) fread($fp, $bytes);
        fclose($fp);
        return $head;
    }
}
