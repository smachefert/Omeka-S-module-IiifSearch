<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;
use SimpleXMLElement;

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

        $xml = @simplexml_load_file($altoPath, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
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

        $canvasBase = $iiifUrl($item, 'iiifserver/uri', null, ['type' => 'canvas']) . '/p' . ($page + 1);
        $pageId = $serverUrl(true);

        // Compute scale between ALTO page coordinates and canvas pixels.
        $altoWidth = (float) $altoPage['WIDTH'];
        $altoHeight = (float) $altoPage['HEIGHT'];
        $media = $item->media()[$page] ?? null;
        if (!$media) {
            return null;
        }
        $canvasWidth = (int) ($media->mediaData()['width'] ?? 0);
        $canvasHeight = (int) ($media->mediaData()['height'] ?? 0);
        // Fall back to thumbnail dimensions if width/height not stored.
        if (!$canvasWidth || !$canvasHeight) {
            try {
                $size = @getimagesize($media->originalUrl());
                if ($size) {
                    $canvasWidth = (int) $size[0];
                    $canvasHeight = (int) $size[1];
                }
            } catch (\Throwable $e) {
                // Ignore.
            }
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
