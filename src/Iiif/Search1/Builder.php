<?php declare(strict_types=1);

namespace IiifSearch\Iiif\Search1;

/**
 * Build a IIIF Content Search 1.0 AnnotationList from a neutral pivot.
 *
 * Pivot shape (see IiifSearch::getRawMatches):
 * [
 *     'baseResultUrl' => string, 'baseCanvasUrl' => string, 'totalHit' => int,
 *     'matches' => [
 *         ['hit'=>int, 'page'=>['number','width','height'],
 *          'image'=>['id','width','height','source'?],
 *          'zone'=>['text','top','left','width','height'],
 *          'chars'=>string, 'isMediaValue'=>bool],
 *         ...
 *     ],
 *     'pageHits' => [
 *         ['hits'=>[hitNumbers...], 'match'=>string], ...
 *     ],
 * ]
 */
class Builder
{
    public function build(array $pivot, string $requestUri): AnnotationList
    {
        $list = new AnnotationList();
        $list->initOptions(['requestUri' => $requestUri]);

        $matches = $pivot['matches'] ?? [];
        if (empty($matches)) {
            $list->isValid(true);
            return $list;
        }

        $resources = [];
        $idsByHit = [];
        foreach ($matches as $match) {
            $box = $this->computeBox($match);
            $id = $this->computeId(
                $pivot['baseResultUrl'] ?? '',
                $match,
                $box
            );
            $resources[] = $this->buildResult($match, $box, $id, $pivot['baseCanvasUrl'] ?? '');
            $idsByHit[$match['hit']] = $id;
        }

        $hits = [];
        foreach ($pivot['pageHits'] ?? [] as $pageHit) {
            $annotationIds = [];
            foreach ($pageHit['hits'] ?? [] as $hitNumber) {
                if (isset($idsByHit[$hitNumber])) {
                    $annotationIds[] = $idsByHit[$hitNumber];
                }
            }
            if (!$annotationIds) {
                continue;
            }
            $hit = new SearchHit();
            $hit['annotations'] = $annotationIds;
            $hit['match'] = (string) ($pageHit['match'] ?? '');
            $hits[] = $hit;
        }

        $list['resources'] = $resources;
        $list['hits'] = $hits;
        $list->isValid(true);
        return $list;
    }

    public function computeBox(array $match): array
    {
        $image = $match['image'] ?? [];
        $page = $match['page'] ?? [];
        $zone = $match['zone'] ?? [];
        if (empty($zone)) {
            return [];
        }

        // The scale is the image size divided by the page size. Manage the case
        // where some image data are missing.
        $imageWidth = ($image['width'] ?? 0) ?: ($page['width'] ?? 0);
        $imageHeight = ($image['height'] ?? 0) ?: ($page['height'] ?? 0);
        $pageWidth = ($page['width'] ?? 0) ?: ($image['width'] ?? 0);
        $pageHeight = ($page['height'] ?? 0) ?: ($image['height'] ?? 0);
        $scaleX = $imageWidth && $pageWidth ? $imageWidth / $pageWidth : 1;
        $scaleY = $imageHeight && $pageHeight ? $imageHeight / $pageHeight : 1;

        $chars = (string) ($match['chars'] ?? '');
        $zoneText = (string) ($zone['text'] ?? '');
        if (strlen($chars) && mb_strlen($zoneText)) {
            $x = $zone['left'] + mb_stripos($zoneText, $chars) / mb_strlen($zoneText) * $zone['width'];
            $y = $zone['top'];
            $w = round($zone['width'] * ((mb_strlen($chars) + 1) / mb_strlen($zoneText)));
            $h = $zone['height'];
        } else {
            $x = $zone['left'];
            $y = $zone['top'];
            $w = $zone['width'];
            $h = $zone['height'];
        }

        return [
            'x' => (int) round($x * $scaleX),
            'y' => (int) round($y * $scaleY),
            'w' => (int) round($w * $scaleX),
            'h' => (int) round($h * $scaleY),
        ];
    }

    public function computeId(string $baseResultUrl, array $match, array $box): ?string
    {
        if (empty($box)) {
            return null;
        }
        return $baseResultUrl
            . 'a' . ($match['page']['number'] ?? '')
            . 'h' . ($match['hit'] ?? '')
            . 'r' . $box['x'] . ',' . $box['y'] . ',' . $box['w'] . ',' . $box['h'];
    }

    protected function buildResult(array $match, array $box, ?string $id, string $baseCanvasUrl): AnnotationSearchResult
    {
        $result = new AnnotationSearchResult([
            '@id' => $id,
            'resource' => [
                '@type' => 'cnt:ContentAsText',
                'chars' => (string) ($match['chars'] ?? ''),
            ],
            'on' => $this->computeOn($baseCanvasUrl, $match, $box),
        ]);
        return $result;
    }

    protected function computeOn(string $baseCanvasUrl, array $match, array $box): ?string
    {
        $pageNumber = $match['page']['number'] ?? null;
        if ($pageNumber === null) {
            return null;
        }
        if (empty($box)) {
            return $baseCanvasUrl . $pageNumber;
        }
        return $baseCanvasUrl . $pageNumber
            . '#xywh=' . $box['x'] . ',' . $box['y'] . ',' . $box['w'] . ',' . $box['h'];
    }
}
