<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use SimpleXMLElement;

/**
 * Resolve a page→image pairing from a METS file when one is attached to the
 * item. METS structMap encodes the producer-decided mapping; using it is more
 * reliable than any filename heuristic.
 *
 * Returns null on missing METS, parse errors, unusable structMap or
 * insufficient coverage so the cascade can fall through to filename heuristics.
 */
class MetsPairingOracle
{
    /** @var string[] Used USE attribute values flagging image fileGrps. */
    protected $imageUses = [
        'IMAGES', 'IMAGE', 'MASTER', 'MASTERS', 'PRESENTATION',
        'PRINT', 'TIFF', 'JPEG', 'JP2',
    ];

    /** @var string[] Used USE attribute values flagging ocr fileGrps. */
    protected $ocrUses = [
        'ALTO', 'FULLTEXT', 'FULL_TEXT', 'OCR', 'TEXT', 'HOCR',
    ];

    /**
     * @param PageSource[] $sources
     * @param MediaRepresentation[] $images
     */
    public function pair(SimpleXMLElement $mets, array $sources, array $images): ?PairingResult
    {
        $namespaces = $mets->getDocNamespaces();
        $metsNs = null;
        foreach ($namespaces as $uri) {
            if (stripos($uri, 'loc.gov/METS') !== false) {
                $metsNs = $uri;
                break;
            }
        }
        $mets->registerXPathNamespace('mets', $metsNs ?: 'http://www.loc.gov/METS/');
        $mets->registerXPathNamespace('xlink', 'http://www.w3.org/1999/xlink');

        $fileById = $this->indexFiles($mets);
        if (!$fileById) {
            return null;
        }

        $orderedImageHrefs = $this->collectStructMapPages($mets, $fileById);
        if (!$orderedImageHrefs) {
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
        $orderedKeys = array_keys($orderedImageHrefs);
        foreach ($sources as $offset => $source) {
            $page = $source->index ?: ($offset + 1);
            $orderKey = $orderedKeys[$offset] ?? null;
            if ($orderKey === null) {
                $matches[$page] = null;
                continue;
            }
            $href = $orderedImageHrefs[$orderKey];
            $name = PageSource::normalizeName(basename($href));
            $candidates = $imagesByName[$name] ?? [];
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
        if ($coverage < PagePairer::COVERAGE_THRESHOLD) {
            return null;
        }

        $result = new PairingResult();
        $result->matches = $matches;
        $result->method = PairingResult::METHOD_METS;
        $result->pageCount = $pageCount;
        $result->imageCount = count($images);
        $result->coverage = $coverage;
        return $result;
    }

    /**
     * Build the file ID → [href, use] lookup from mets:fileSec.
     *
     * @return array<string,array{href:string,use:string}>
     */
    protected function indexFiles(SimpleXMLElement $mets): array
    {
        $byId = [];
        $fileGrps = $mets->xpath('//mets:fileSec//mets:fileGrp') ?: [];
        foreach ($fileGrps as $grp) {
            $use = strtoupper((string) ($grp['USE'] ?? ''));
            $files = $grp->xpath('.//mets:file') ?: [];
            foreach ($files as $file) {
                $id = (string) ($file['ID'] ?? '');
                $hrefNodes = $file->xpath('mets:FLocat/@xlink:href') ?: [];
                $href = $hrefNodes ? (string) $hrefNodes[0] : '';
                if ($id !== '' && $href !== '') {
                    $byId[$id] = ['href' => $href, 'use' => $use];
                }
            }
        }
        return $byId;
    }

    /**
     * Walk the structMap and return an ordered list of image hrefs, one per
     * page div. Pages without an image fileGrp link are skipped.
     *
     * @param array<string,array{href:string,use:string}> $fileById
     * @return string[]
     */
    protected function collectStructMapPages(SimpleXMLElement $mets, array $fileById): array
    {
        $pages = $mets->xpath('//mets:structMap//mets:div[mets:fptr]') ?: [];
        if (!$pages) {
            return [];
        }
        usort($pages, function ($a, $b) {
            $oa = (int) ($a['ORDER'] ?? 0);
            $ob = (int) ($b['ORDER'] ?? 0);
            return $oa <=> $ob;
        });
        $orderedImageHrefs = [];
        foreach ($pages as $i => $page) {
            $fptrs = $page->xpath('mets:fptr') ?: [];
            foreach ($fptrs as $fptr) {
                $fid = (string) ($fptr['FILEID'] ?? '');
                if (!isset($fileById[$fid])) {
                    continue;
                }
                $info = $fileById[$fid];
                if (in_array($info['use'], $this->imageUses, true)) {
                    $orderedImageHrefs[$i] = $info['href'];
                    break;
                }
            }
        }
        return $orderedImageHrefs;
    }
}
