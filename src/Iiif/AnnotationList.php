<?php declare(strict_types=1);

/*
 * Copyright 2020-2022 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace IiifSearch\Iiif;

/**
 * @link https://iiif.io/api/search/1.0/#presentation-api-compatible-responses
 * @link https://iiif.io/api/presentation/2.1/#annotation-list
 *
 * This class is currently not made dependant of IiifServer\Iiif\AbstractType
 * in order to use search independantly.
 * It allows to manage future various types of annotation and triggers, in
 * particular for identifiers. It is kept light.
 */
class AnnotationList extends AbstractSimpleType
{
    protected $_storage = [
        '@context' => 'http://iiif.io/api/search/0/context.json',
        '@id' => null,
        '@type' => 'sc:AnnotationList',
        'within' => null,
        // TODO No pagination currently for search.
        'startIndex' => 0,
        'resources' => [],
        'hits' => [],
    ];

    protected $_keys = [
        '@context' => self::REQUIRED,
        '@id' => self::REQUIRED,
        '@type' => self::REQUIRED,
        // TODO To be checked.
        'within' => self::OPTIONAL,
        'startIndex' => self::OPTIONAL,
        'resources' => self::REQUIRED,
        'hits' => self::RECOMMENDED,
    ];

    public function __construct(array $data = null)
    {
        // Parent is required to init data.
        parent::__construct($data);
    }

    public function getContent(): array
    {
        if (empty($this->offsetGet('@id'))) {
            $this->offsetSet('@id', $this->id());
        }
        if (empty($this->offsetGet('within'))) {
            $this->offsetSet('within', $this->within());
        }
        return parent::getContent();
    }

    public function id(): ?string
    {
        return $this->_options['requestUri'];
    }

    public function within(): array
    {
        // TODO Implement class Within.
        return [
            '@type' => 'sc:Layer',
            'total' => count($this['resources']),
        ];
    }
}
