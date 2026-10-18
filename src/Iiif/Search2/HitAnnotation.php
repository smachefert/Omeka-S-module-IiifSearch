<?php declare(strict_types=1);

namespace IiifSearch\Iiif\Search2;

use IiifSearch\Iiif\AbstractSimpleType;

/**
 * IIIF Content Search 2.0 — Hit annotation (motivation contextualizing).
 *
 * The target is a SpecificResource (or a list of them) whose source is the id
 * of a highlighted annotation and whose TextQuoteSelector contains the match
 * ("exact") and its context ("prefix" and "suffix").
 *
 * @link https://iiif.io/api/search/2.0/#match-context
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
        'body' => self::OPTIONAL,
        'target' => self::REQUIRED,
    ];

    public function __construct(?array $data = null)
    {
        parent::__construct($data);
    }
}
