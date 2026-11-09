<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use IiifSearch\Stdlib\XmlRepair;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Build a IIIF Presentation 3 AnnotationPage with motivation "supplementing"
 * for a single ALTO page, suitable for plugins like mirador-textoverlay.
 *
 * Each TextLine produces one annotation with a TextualBody and a target on the
 * canvas using a xywh fragment.
 */
class IiifAltoAnnotations extends AbstractHelper
{
    /**
     * @param ItemRepresentation $item    The item that owns the canvas.
     * @param int                $page    Zero-based page index.
     * @param string             $altoPath Absolute path to a per-page ALTO XML.
     */
    public function __invoke(ItemRepresentation $item, int $page, string $altoPath): ?array
    {
        if (!is_readable($altoPath)) {
            return null;
        }

        $xml = XmlRepair::loadFile($altoPath, LIBXML_NONET | LIBXML_NOBLANKS);
        if (!$xml) {
            return null;
        }

        // ALTO uses a default namespace; register it explicitly to use xpath.
        $namespaces = $xml->getNamespaces(true);
        $altoNs = $namespaces[''] ?? 'http://www.loc.gov/standards/alto/ns-v4#';
        $xml->registerXPathNamespace('a', $altoNs);

        $pages = $xml->xpath('//a:Layout/a:Page');
        if (!$pages) {
            return null;
        }
        $altoPage = $pages[0];

        $view = $this->getView();
        $iiifUrl = $view->plugin('iiifUrl');
        $serverUrl = $view->plugin('serverUrl');

        // An AnnotationPage is referenced only by a manifest v3, so the targets are
        // the canvases of this version, whatever the default version.
        $manifestVersion = '3';
        $canvasBase = $iiifUrl($item, 'iiifserver/uri', $manifestVersion, ['type' => 'canvas']) . '/p' . ($page + 1);
        $pageId = $serverUrl(true);

        // Compute scale between ALTO page coordinates and canvas pixels.
        $altoWidth = (float) $altoPage['WIDTH'];
        $altoHeight = (float) $altoPage['HEIGHT'];
        // Pick the page-th image media rather than the page-th media of any
        // type: items with attached ALTOs / PDFs interleaved with images would
        // otherwise index into a non-image entry and lose the canvas
        // dimensions. ALTO ordering itself is fixed upstream by XmlAltoSingle.
        $itemFileResources = $this->getView()->plugin('itemFileResources');
        $imageMedias = [];
        foreach ($itemFileResources($item) as $resource) {
            if ($resource->isCanvasImage()) {
                $imageMedias[] = $resource;
            }
        }
        // An item without page image (a single pdf, for example) keeps the
        // coordinates of the alto page, so the scale is 1, except when the size
        // of the pages of the pdf is available.
        $resource = $imageMedias[$page] ?? null;
        $canvasWidth = 0;
        $canvasHeight = 0;
        if ($resource && $view->getHelperPluginManager()->has('mediaDimension')) {
            try {
                $dim = $view->plugin('mediaDimension')($resource, 'original');
                $canvasWidth = (int) ($dim['width'] ?? 0);
                $canvasHeight = (int) ($dim['height'] ?? 0);
            } catch (\Throwable $e) {
                // Fall through to mediaData inspection below.
            }
        }
        if ($resource && (!$canvasWidth || !$canvasHeight)) {
            $mediaData = $resource->mediaData() ?: [];
            $canvasWidth = $canvasWidth ?: (int) ($mediaData['dimensions']['original']['width']
                ?? $mediaData['width'] ?? 0);
            $canvasHeight = $canvasHeight ?: (int) ($mediaData['dimensions']['original']['height']
                ?? $mediaData['height'] ?? 0);
        }
        // The pages of a pdf may be the canvases, with the size of the page in
        // points, like the search (see module IiifServer).
        if (!$resource && $view->getHelperPluginManager()->has('pdfPageSizes')) {
            $pdfPageSizes = $view->plugin('pdfPageSizes');
            $pageSizes = [];
            foreach ($itemFileResources($item) as $fileResource) {
                if ($fileResource->mediaType() === 'application/pdf') {
                    $pageSizes = array_merge($pageSizes, $pdfPageSizes($fileResource->inner()));
                }
            }
            $canvasWidth = (int) ($pageSizes[$page]['width'] ?? 0);
            $canvasHeight = (int) ($pageSizes[$page]['height'] ?? 0);
        }
        $scaleX = ($altoWidth > 0 && $canvasWidth > 0) ? $canvasWidth / $altoWidth : 1.0;
        $scaleY = ($altoHeight > 0 && $canvasHeight > 0) ? $canvasHeight / $altoHeight : 1.0;

        // SimpleXML's registerXPathNamespace is not inherited by sub-nodes:
        // re-register on every node before each xpath() call to avoid
        // "Undefined namespace prefix" warnings.
        $altoPage->registerXPathNamespace('a', $altoNs);

        $items = [];
        $index = 0;
        foreach ($altoPage->xpath('.//a:TextLine') as $line) {
            $hpos = (float) $line['HPOS'];
            $vpos = (float) $line['VPOS'];
            $width = (float) $line['WIDTH'];
            $height = (float) $line['HEIGHT'];
            if ($width <= 0 || $height <= 0) {
                continue;
            }
            $line->registerXPathNamespace('a', $altoNs);

            $text = '';
            foreach ($line->xpath('a:String') as $string) {
                $text .= ($text === '' ? '' : ' ') . (string) $string['CONTENT'];
            }
            if ($text === '') {
                continue;
            }

            $x = (int) round($hpos * $scaleX);
            $y = (int) round($vpos * $scaleY);
            $w = (int) round($width * $scaleX);
            $h = (int) round($height * $scaleY);

            $items[] = [
                'id' => sprintf('%s#anno-%d', $pageId, ++$index),
                'type' => 'Annotation',
                'motivation' => 'supplementing',
                'body' => [
                    'type' => 'TextualBody',
                    'value' => $text,
                    'format' => 'text/plain',
                    'language' => 'none',
                ],
                'target' => sprintf('%s#xywh=%d,%d,%d,%d', $canvasBase, $x, $y, $w, $h),
            ];
        }

        return [
            '@context' => 'http://iiif.io/api/presentation/3/context.json',
            'id' => $pageId,
            'type' => 'AnnotationPage',
            'items' => $items,
        ];
    }
}
