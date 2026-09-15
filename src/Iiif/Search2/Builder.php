<?php declare(strict_types=1);

namespace IiifSearch\Iiif\Search2;

use IiifSearch\Iiif\Search1\Builder as Search1Builder;

/**
 * Build a IIIF Content Search 2.0 AnnotationPage from the neutral pivot.
 *
 * Pivot shape: see IiifSearch::getRawMatches.
 */
class Builder
{
    /**
     * @var Search1Builder
     */
    protected $boxBuilder;

    public function __construct(?Search1Builder $boxBuilder = null)
    {
        // Reuse computeBox from the v1 builder to avoid duplicating the
        // image/page/zone scaling math.
        $this->boxBuilder = $boxBuilder ?? new Search1Builder();
    }

    public function build(array $pivot, string $requestUri): AnnotationPage
    {
        $page = new AnnotationPage();
        $page->initOptions(['requestUri' => $requestUri]);

        $matches = $pivot['matches'] ?? [];
        $items = [];
        $idsByHit = [];

        $base = $this->stripQuery($requestUri);
        $baseCanvasUrl = (string) ($pivot['baseCanvasUrl'] ?? '');

        $index = 0;
        foreach ($matches as $match) {
            $box = $this->boxBuilder->computeBox($match);
            $chars = (string) ($match['chars'] ?? '');
            if ($chars === '') {
                continue;
            }
            $annotationId = $base . '/annotation/' . $index;
            $items[] = $this->buildAnnotation($annotationId, $chars, $match, $box, $baseCanvasUrl);
            if (isset($match['hit'])) {
                $idsByHit[$match['hit']] = $annotationId;
            }
            ++$index;
        }

        $hitIndex = 0;
        foreach ($pivot['pageHits'] ?? [] as $pageHit) {
            $targets = [];
            foreach ($pageHit['hits'] ?? [] as $hitNumber) {
                if (isset($idsByHit[$hitNumber])) {
                    $targets[] = $idsByHit[$hitNumber];
                }
            }
            if (!$targets) {
                continue;
            }
            $items[] = $this->buildHit(
                $base . '/hit/' . $hitIndex,
                (string) ($pageHit['match'] ?? ''),
                $targets
            );
            ++$hitIndex;
        }

        $page['items'] = $items;
        $page['partOf'] = new AnnotationCollection([
            'id' => $base,
            'label' => ['en' => ['Search results']],
            'total' => count($items),
        ]);
        $page->isValid(true);
        return $page;
    }

    protected function buildAnnotation(string $id, string $chars, array $match, array $box, string $baseCanvasUrl): Annotation
    {
        $pageNumber = $match['page']['number'] ?? null;
        $canvasUrl = $pageNumber === null
            ? null
            : $baseCanvasUrl . $pageNumber;

        $target = $canvasUrl;
        if ($canvasUrl !== null && !empty($box)) {
            $target = [
                'type' => 'SpecificResource',
                'source' => $canvasUrl,
                'selector' => [
                    'type' => 'FragmentSelector',
                    'conformsTo' => 'http://www.w3.org/TR/media-frags/',
                    'value' => sprintf(
                        'xywh=%d,%d,%d,%d',
                        $box['x'],
                        $box['y'],
                        $box['w'],
                        $box['h']
                    ),
                ],
            ];
        }

        return new Annotation([
            'id' => $id,
            'body' => [
                'type' => 'TextualBody',
                'value' => $chars,
                'format' => 'text/plain',
            ],
            'target' => $target,
        ]);
    }

    protected function buildHit(string $id, string $match, array $targets): HitAnnotation
    {
        $body = [
            'type' => 'TextualBody',
            'purpose' => 'tagging',
            'value' => $match,
            'format' => 'text/plain',
        ];
        return new HitAnnotation([
            'id' => $id,
            'body' => $body,
            'target' => count($targets) === 1 ? $targets[0] : $targets,
        ]);
    }

    protected function stripQuery(string $url): string
    {
        $base = strtok($url, '?');
        return is_string($base) ? $base : $url;
    }
}
