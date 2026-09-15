<?php declare(strict_types=1);

namespace IiifSearch\Iiif\Search2;

use IiifSearch\Iiif\AbstractSimpleType;

/**
 * IIIF Content Search 2.0 — match Annotation.
 *
 * @link https://iiif.io/api/search/2.0/#annotations
 */
class Annotation extends AbstractSimpleType
{
    protected $_storage = [
        'id' => null,
        'type' => 'Annotation',
        'motivation' => 'highlighting',
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
