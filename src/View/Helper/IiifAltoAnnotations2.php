<?php declare(strict_types=1);

namespace IiifSearch\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;
use SimpleXMLElement;

/**
 * Build a IIIF Presentation 2 AnnotationList for a single ALTO page.
 *
 * v2 counterpart of IiifAltoAnnotations: same scaling logic, different JSON-LD
 * vocabulary (sc:AnnotationList / resources / oa:Annotation / cnt:ContentAsText
 * / @id / @type / on).
 */
class IiifAltoAnnotations2 extends AbstractHelper
{
    public function __invoke(ItemRepresentation $item, int $page, string $altoPath): ?array
    {
        if (!is_readable($altoPath)) {
            return null;
        }

        $xml = @simplexml_load_file($altoPath, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        if (!$xml) {
            return null;
        }

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

        $manifestVersion = $view->setting('iiifserver_manifest_default_version', '2');
        $canvasBase = $iiifUrl($item, 'iiifserver/uri', $manifestVersion, ['type' => 'canvas']) . '/p' . ($page + 1);
        $listId = $serverUrl(true);

        $altoWidth = (float) $altoPage['WIDTH'];
        $altoHeight = (float) $altoPage['HEIGHT'];

        $itemFileResources = $view->plugin('itemFileResources');
        $imageMedias = [];
        foreach ($itemFileResources($item) as $resource) {
            $mt = (string) $resource->mediaType();
            if (strtok($mt, '/') === 'image' && $resource->hasOriginal()) {
                $imageMedias[] = $resource;
            }
        }
        $resource = $imageMedias[$page] ?? null;
        if (!$resource) {
            return null;
        }
        $mediaData = $resource->mediaData() ?: [];
        $canvasWidth = (int) ($mediaData['dimensions']['original']['width']
            ?? $mediaData['width'] ?? 0);
        $canvasHeight = (int) ($mediaData['dimensions']['original']['height']
            ?? $mediaData['height'] ?? 0);
        if (!$canvasWidth || !$canvasHeight) {
            try {
                $size = @getimagesize($resource->originalUrl());
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

        $altoPage->registerXPathNamespace('a', $altoNs);

        $resources = [];
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

            $resources[] = [
                '@id' => sprintf('%s#anno-%d', $listId, ++$index),
                '@type' => 'oa:Annotation',
                'motivation' => 'sc:painting',
                'resource' => [
                    '@type' => 'cnt:ContentAsText',
                    'chars' => $text,
                    'format' => 'text/plain',
                ],
                'on' => sprintf('%s#xywh=%d,%d,%d,%d', $canvasBase, $x, $y, $w, $h),
            ];
        }

        return [
            '@context' => 'http://iiif.io/api/presentation/2/context.json',
            '@id' => $listId,
            '@type' => 'sc:AnnotationList',
            'resources' => $resources,
        ];
    }
}
