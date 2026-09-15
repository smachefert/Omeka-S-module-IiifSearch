<?php declare(strict_types=1);

namespace IiifSearch\Iiif\Search2;

use IiifSearch\Iiif\AbstractSimpleType;

/**
 * IIIF Content Search 2.0 — root AnnotationPage response.
 *
 * @link https://iiif.io/api/search/2.0/#response
 */
class AnnotationPage extends AbstractSimpleType
{
    protected $_storage = [
        '@context' => [
            'http://www.w3.org/ns/anno.jsonld',
            'http://iiif.io/api/search/2/context.json',
        ],
        'id' => null,
        'type' => 'AnnotationPage',
        'ignored' => [],
        'items' => [],
        'partOf' => null,
    ];

    protected $_keys = [
        '@context' => self::REQUIRED,
        'id' => self::REQUIRED,
        'type' => self::REQUIRED,
        'ignored' => self::OPTIONAL,
        'items' => self::REQUIRED,
        'partOf' => self::OPTIONAL,
    ];

    public function __construct(?array $data = null)
    {
        parent::__construct($data);
    }

    public function getContent(): array
    {
        if (empty($this->offsetGet('id'))) {
            $this->offsetSet('id', $this->_options['requestUri'] ?? null);
        }
        return parent::getContent();
    }
}
