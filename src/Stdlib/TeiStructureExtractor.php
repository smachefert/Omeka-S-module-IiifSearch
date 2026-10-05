<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Extract the table of contents of a TEI file as an iiif structure.
 *
 * Each div with a head is a range labelled by the head, nested like the divs.
 * The pages are the pb inside it. A pb is matched to a canvas by its facs, that
 * points to a surface or a graphic of the facsimile, whose file name is the one
 * of an image of the item; else by its position.
 *
 * The output is the literal format with four columns of the module Iiif Server
 * ("{id}, {label}, {view}, {items}"), so it is processed like a structure set
 * in a property: the view is the canvas where the section starts and the items
 * are the canvases and the sub-sections, in the order of the document.
 *
 * @see https://gitlab.com/Daniel-KM/Omeka-S-module-IiifServer#input-format-of-the-property-for-structures-table-of-contents
 * @see https://tei-c.org/release/doc/tei-p5-doc/en/html/DS.html
 */
class TeiStructureExtractor
{
    /**
     * Increment when the output changes, so the cached structures are rebuilt.
     */
    const VERSION = 1;

    /**
     * The elements whose text is not part of a head, like notes or running
     * titles.
     */
    const SKIPPED_IN_HEAD = ['note', 'fw', 'figDesc', 'del', 'sic', 'orig', 'abbr'];

    /**
     * @var int[] Canvas indexes (one-based) by normalized file name.
     */
    protected $canvasByName = [];

    /**
     * @var int
     */
    protected $totalCanvases = 0;

    /**
     * @var array File names by xml:id of surface or graphic.
     */
    protected $facsimiles = [];

    /**
     * @var array Ranges by id, in document order.
     */
    protected $ranges = [];

    /**
     * @var array Stack of ids of the open ranges.
     */
    protected $stack = [];

    /**
     * @var string
     */
    protected $title = '';

    /**
     * @var int|null
     */
    protected $currentCanvas;

    /**
     * @var int
     */
    protected $pageOrdinal = 0;

    /**
     * @param array $canvasByName Canvas indexes (one-based) keyed by file name,
     *   with or without extension.
     * @return string|null The structure, or null when the file has no section.
     */
    public function extract(string $filepath, array $canvasByName, int $totalCanvases): ?string
    {
        $dom = new DOMDocument();
        if (!is_readable($filepath)
            || !@$dom->load($filepath, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)
        ) {
            return null;
        }

        // The file names are compared without extension too, since the TEI may
        // describe the master file (tiff) and the item may store a derivative.
        $this->canvasByName = [];
        foreach ($canvasByName as $name => $index) {
            $name = $this->normalizeName((string) $name);
            $this->canvasByName[$name] = (int) $index;
            $this->canvasByName[pathinfo($name, PATHINFO_FILENAME)] ??= (int) $index;
        }
        $this->totalCanvases = $totalCanvases;
        $this->facsimiles = [];
        $this->ranges = [];
        $this->stack = [];
        $this->currentCanvas = null;
        $this->pageOrdinal = 0;

        // Local names are used to manage TEI P5 with namespace and TEI P4
        // without namespace.
        $xpath = new DOMXPath($dom);
        $this->readFacsimiles($xpath);
        $this->title = $this->readTitle($xpath);

        $texts = $xpath->query('//*[local-name()="text"]');
        if (!$texts || !$texts->length) {
            return null;
        }
        $this->walk($texts->item(0));

        return $this->format();
    }

    protected function readTitle(DOMXPath $xpath): string
    {
        $titles = $xpath->query('//*[local-name()="titleStmt"]/*[local-name()="title"][@type="main"]');
        if (!$titles || !$titles->length) {
            $titles = $xpath->query('//*[local-name()="titleStmt"]/*[local-name()="title"]');
        }
        return $titles && $titles->length
            ? trim((string) preg_replace('~\s+~u', ' ', $titles->item(0)->textContent))
            : '';
    }

    protected function readFacsimiles(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//*[local-name()="facsimile"]//*[local-name()="surface" or local-name()="graphic"]');
        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            $id = $node->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id')
                ?: $node->getAttribute('xml:id');
            if (!$id) {
                continue;
            }
            $url = $node->localName === 'graphic'
                ? $node->getAttribute('url')
                : ($node->getAttribute('source') ?: $this->firstGraphicUrl($node));
            if ($url) {
                $this->facsimiles[$id] = $url;
            }
        }
    }

    protected function firstGraphicUrl(DOMElement $surface): ?string
    {
        foreach ($surface->getElementsByTagNameNS('*', 'graphic') as $graphic) {
            $url = $graphic->getAttribute('url');
            if ($url) {
                return $url;
            }
        }
        return null;
    }

    protected function walk(DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $name = $child->localName;
            if ($name === 'pb') {
                $this->addPage($child);
            } elseif ($name === 'teiHeader' || $name === 'facsimile') {
                continue;
            } elseif (($name === 'div' || preg_match('~^div[1-7]$~', $name))
                && ($head = $this->directHead($child))
            ) {
                $this->openRange($child, $head);
                $this->walk($child);
                array_pop($this->stack);
            } else {
                $this->walk($child);
            }
        }
    }

    protected function addPage(DOMElement $pb): void
    {
        ++$this->pageOrdinal;
        $canvas = $this->canvasOfPage($pb);
        if (!$canvas) {
            return;
        }
        $this->currentCanvas = $canvas;
        $rangeId = end($this->stack);
        if ($rangeId !== false) {
            $this->addCanvas($rangeId, $canvas);
        }
    }

    protected function canvasOfPage(DOMElement $pb): ?int
    {
        $facs = ltrim((string) $pb->getAttribute('facs'), '#');
        if ($facs !== '') {
            $file = $this->facsimiles[$facs] ?? $facs;
            $canvas = $this->canvasByName[$this->normalizeName($file)]
                ?? $this->canvasByName[$this->normalizeName(pathinfo($file, PATHINFO_FILENAME))]
                ?? null;
            if ($canvas) {
                return $canvas;
            }
        }
        // Without a known image, the order of the pages is the one of the
        // canvases.
        return $this->pageOrdinal <= $this->totalCanvases ? $this->pageOrdinal : null;
    }

    protected function directHead(DOMElement $div): ?DOMElement
    {
        foreach ($div->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'head') {
                return $child;
            }
        }
        return null;
    }

    protected function openRange(DOMElement $div, DOMElement $head): void
    {
        $id = 'tei' . (count($this->ranges) + 1);
        $this->ranges[$id] = [
            'label' => $this->headLabel($head),
            'view' => null,
            'items' => [],
        ];

        $parentId = end($this->stack);
        if ($parentId !== false) {
            $this->ranges[$parentId]['items'][] = $id;
        }

        // A section that starts in the middle of a page includes that page.
        if ($this->currentCanvas && !$this->startsWithPage($div)) {
            $this->addCanvas($id, $this->currentCanvas);
        }

        $this->stack[] = $id;
    }

    protected function addCanvas(string $rangeId, int $canvas): void
    {
        if ($this->ranges[$rangeId]['view'] === null) {
            $this->ranges[$rangeId]['view'] = $canvas;
        }
        if (!in_array($canvas, $this->ranges[$rangeId]['items'], true)) {
            $this->ranges[$rangeId]['items'][] = $canvas;
        }
    }

    protected function startsWithPage(DOMElement $div): bool
    {
        foreach ($div->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child->localName === 'pb';
            }
        }
        return false;
    }

    protected function headLabel(DOMElement $head): string
    {
        $text = '';
        $collect = function (DOMNode $node) use (&$collect, &$text): void {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    if (in_array($child->localName, self::SKIPPED_IN_HEAD, true)) {
                        continue;
                    }
                    if ($child->localName === 'lb') {
                        $text .= ' ';
                        continue;
                    }
                    $collect($child);
                } elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                    $text .= $child->nodeValue;
                }
            }
        };
        $collect($head);
        return trim((string) preg_replace('~\s+~u', ' ', $text));
    }

    /**
     * Format the ranges as literal structure with four columns.
     */
    protected function format(): ?string
    {
        // Remove the sections without page nor sub-section, that cannot be
        // displayed, then their references.
        do {
            $removed = false;
            foreach ($this->ranges as $id => $range) {
                if (!$range['items']) {
                    unset($this->ranges[$id]);
                    $removed = true;
                }
            }
            foreach ($this->ranges as $id => $range) {
                $this->ranges[$id]['items'] = array_values(array_filter(
                    $range['items'],
                    fn ($item) => is_int($item) || isset($this->ranges[$item])
                ));
            }
        } while ($removed);

        if (!$this->ranges) {
            return null;
        }

        // The module Iiif Server uses the first line as the root of the tree,
        // so the top sections are gathered in a root range.
        $children = [];
        foreach ($this->ranges as $range) {
            foreach ($range['items'] as $item) {
                if (is_string($item)) {
                    $children[] = $item;
                }
            }
        }
        $tops = array_values(array_diff(array_keys($this->ranges), $children));
        $lines = [sprintf('tei0, %s, , %s', $this->title ?: 'Contents', implode('; ', $tops))];

        foreach ($this->ranges as $id => $range) {
            // The comma separates the columns, but the parser of the module Iiif
            // Server anchors the last columns, so a label may keep it.
            $lines[] = sprintf('%s, %s, %s, %s', $id, $range['label'], $range['view'] ?? '', implode('; ', $range['items']));
        }
        return implode("\n", $lines);
    }

    protected function normalizeName(string $name): string
    {
        return mb_strtolower(basename(rawurldecode($name)));
    }
}
