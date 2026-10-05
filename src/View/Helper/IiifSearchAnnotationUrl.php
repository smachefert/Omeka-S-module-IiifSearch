<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Build the URLs of the IiifSearch endpoints (ALTO page, AnnotationPage in
 * Presentation 2 / 3) for a given item and canvas index, and probe whether the
 * item carries any ALTO source the module can serve. Used by IiifServer to emit
 * seeAlso/annotations on canvases without re-implementing OCR detection.
 */
class IiifSearchAnnotationUrl extends AbstractHelper
{
    /** @var array<int,bool> */
    protected array $ocrCache = [];

    public function isActive(): bool
    {
        return true;
    }

    /**
     * Does the item carry any ALTO source IiifSearch can serve? Delegates to
     * XmlAltoSplitter which covers media files, generated per-page files under
     * files/alto/, consolidated single-file output, and DigitalObject
     * resources.
     */
    public function hasOcrSource(ItemRepresentation $item): bool
    {
        $itemId = (int) $item->id();
        if (isset($this->ocrCache[$itemId])) {
            return $this->ocrCache[$itemId];
        }
        $splitter = $this->getView()->plugin('xmlAltoSplitter');
        return $this->ocrCache[$itemId] = $splitter->hasAnyAlto($item);
    }

    /**
     * URL of the AnnotationPage (v3) or AnnotationList (v2) for a canvas.
     *
     * @param int $canvasIndex One-based canvas position (matches IIIF canvas
     *                         naming "p1", "p2", …).
     * @param int $version     IIIF Presentation version, 2 or 3.
     */
    public function __invoke(ItemRepresentation $item, int $canvasIndex, int $version = 3): ?string
    {
        $canvasIndex = max(1, $canvasIndex);
        $view = $this->getView();
        $url = $view->plugin('url');
        $serverUrl = $view->plugin('serverUrl');
        try {
            $path = $version === 2
                ? $url(
                    'iiifsearch/annotation-list',
                    ['id' => $item->id(), 'name' => 'p' . $canvasIndex]
                )
                : $url(
                    'iiifsearch/annotation-page',
                    ['id' => $item->id(), 'page' => $canvasIndex]
                );
        } catch (\Throwable $e) {
            return null;
        }
        return $serverUrl($path);
    }

    /**
     * URL of the alto-page endpoint for a canvas. Pairing is handled by
     * IiifSearch internally; the caller only provides the one-based canvas
     * index.
     */
    public function altoUrl(ItemRepresentation $item, int $canvasIndex): ?string
    {
        $canvasIndex = max(1, $canvasIndex);
        $view = $this->getView();
        $url = $view->plugin('url');
        $serverUrl = $view->plugin('serverUrl');
        try {
            $path = $url(
                'iiifsearch/alto-page',
                ['id' => $item->id(), 'page' => $canvasIndex]
            );
        } catch (\Throwable $e) {
            return null;
        }
        return $serverUrl($path);
    }

    /**
     * URL of the consolidated alto of the item, when it exists.
     */
    public function wholeAltoUrl(ItemRepresentation $item): ?string
    {
        $view = $this->getView();
        if (!$view->plugin('xmlAltoSplitter')->consolidatedPath($item)) {
            return null;
        }
        try {
            $path = $view->plugin('url')('iiifsearch/alto', ['id' => $item->id()]);
        } catch (\Throwable $e) {
            return null;
        }
        return $view->plugin('serverUrl')($path);
    }

    /**
     * Number of pages of the consolidated alto of the item, else zero.
     */
    public function countWholeAltoPages(ItemRepresentation $item): int
    {
        $splitter = $this->getView()->plugin('xmlAltoSplitter');
        $path = $splitter->consolidatedPath($item);
        return $path ? $splitter->countPages($path) : 0;
    }

    /**
     * Resource whose access rules the ocr of a page.
     *
     * The ocr follows the attached alto when there is one, else the image of
     * the page, else the item itself, for example an item made of a single pdf.
     */
    public function ocrResource(ItemRepresentation $item, int $pageIndex): AbstractResourceEntityRepresentation
    {
        $view = $this->getView();
        $resource = $view->plugin('xmlAltoSplitter')->sourceResource($item, $pageIndex);
        if ($resource) {
            return $resource;
        }
        $images = $view->plugin('itemFileResources')->images($item);
        $image = $images[$pageIndex] ?? null;
        return $image ? $image->inner() : $item;
    }

    /**
     * Pages whose attached alto is more open than the image, with module Access.
     *
     * The ocr follows the access of the alto, so a free alto on a reserved
     * image exposes the text of the page. The files are imported by the admin,
     * so they are only reported.
     *
     * @return array<int, array{alto:string, image:string}> Keyed by one-based
     *   page number.
     */
    public function accessMismatches(ItemRepresentation $item): array
    {
        $view = $this->getView();
        $helpers = $view->getHelperPluginManager();
        if (!$helpers->has('accessLevel')) {
            return [];
        }
        $accessLevel = $helpers->get('accessLevel');
        $splitter = $view->plugin('xmlAltoSplitter');
        $ranks = ['free' => 0, 'reserved' => 1, 'protected' => 2, 'forbidden' => 3];

        $mismatches = [];
        foreach ($view->plugin('itemFileResources')->images($item) as $pageIndex => $image) {
            $alto = $splitter->sourceResource($item, $pageIndex);
            if (!$alto) {
                continue;
            }
            $altoLevel = $accessLevel($alto);
            $imageLevel = $accessLevel($image->inner());
            if (($ranks[$altoLevel] ?? 0) < ($ranks[$imageLevel] ?? 0)) {
                $mismatches[$pageIndex + 1] = ['alto' => $altoLevel, 'image' => $imageLevel];
            }
        }
        return $mismatches;
    }
}
