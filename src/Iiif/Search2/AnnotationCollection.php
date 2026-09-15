<?php declare(strict_types=1);

namespace IiifSearch\Iiif\Search2;

use IiifSearch\Iiif\AbstractSimpleType;

/**
 * IIIF Content Search 2.0 — partOf AnnotationCollection.
 *
 * @link https://iiif.io/api/search/2.0/#annotation-collection
 */
class AnnotationCollection extends AbstractSimpleType
{
    protected $_storage = [
        'id' => null,
        'type' => 'AnnotationCollection',
        'label' => null,
        'total' => 0,
    ];

    protected $_keys = [
        'id' => self::REQUIRED,
        'type' => self::REQUIRED,
        'label' => self::OPTIONAL,
        'total' => self::OPTIONAL,
    ];

    public function __construct(?array $data = null)
    {
        parent::__construct($data);
    }
}
