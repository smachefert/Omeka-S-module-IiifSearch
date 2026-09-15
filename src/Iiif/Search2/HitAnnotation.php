<?php declare(strict_types=1);

namespace IiifSearch\Iiif\Search2;

use IiifSearch\Iiif\AbstractSimpleType;

/**
 * IIIF Content Search 2.0 — Hit annotation (motivation contextualizing).
 *
 * Wraps before/match/after as a single Annotation whose body is a list of
 * TextualBody parts (purpose: describing | tagging | describing) and whose
 * target is the id (or list of ids) of the main highlighted Annotation(s).
 *
 * @link https://iiif.io/api/search/2.0/#search-hits
 */
class HitAnnotation extends AbstractSimpleType
{
    protected $_storage = [
        'id' => null,
        'type' => 'Annotation',
        'motivation' => 'contextualizing',
        'body' => null,
        'target' => null,
    ];

    protected $_keys = [
        'id' => self::REQUIRED,
        'type' => self::REQUIRED,
        'motivation' => self::REQUIRED,
        'body' => self::REQUIRED,
        'target' => self::REQUIRED,
    ];

    public function __construct(?array $data = null)
    {
        parent::__construct($data);
    }
}
