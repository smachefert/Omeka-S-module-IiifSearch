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

        // The hits with their context are additional annotations, separated
        // from the matches.
        // @link https://iiif.io/api/search/2.0/#match-context
        $hitItems = [];
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
            $hitItems[] = $this->buildHit(
                $base . '/hit/' . $hitIndex,
                $pageHit,
                $targets
            );
            ++$hitIndex;
        }

        $page['items'] = $items;
        if ($hitItems) {
            $page['annotations'] = [
                [
                    'type' => 'AnnotationPage',
                    'items' => $hitItems,
                ],
            ];
        }
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

    /**
     * Build the annotation of a hit, with the match and its context in a text
     * quote selector of each annotation of the match.
     */
    protected function buildHit(string $id, array $pageHit, array $targets): HitAnnotation
    {
        $selector = ['type' => 'TextQuoteSelector'];
        if (isset($pageHit['before']) && $pageHit['before'] !== '') {
            $selector['prefix'] = (string) $pageHit['before'];
        }
        $selector['exact'] = (string) ($pageHit['match'] ?? '');
        if (isset($pageHit['after']) && $pageHit['after'] !== '') {
            $selector['suffix'] = (string) $pageHit['after'];
        }

        $specificResources = [];
        foreach ($targets as $target) {
            $specificResources[] = [
                'type' => 'SpecificResource',
                'source' => $target,
                'selector' => [$selector],
            ];
        }

        return new HitAnnotation([
            'id' => $id,
            'target' => count($specificResources) === 1 ? $specificResources[0] : $specificResources,
        ]);
    }

    protected function stripQuery(string $url): string
    {
        $base = strtok($url, '?');
        return is_string($base) ? $base : $url;
    }
}
