<?php declare(strict_types=1);

/*
 * Copyright 2020-2023 Daniel Berthereau
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
 * @todo Normalize for iiif 2.1/3.0.
 *
 * @link https://iiif.io/api/presentation/2.0/#embedded-content
 *
 * This class is currently not made dependant of IiifServer\Iiif\AbstractType
 * in order to use search independantly.
 * It allows to manage future various types of annotation and triggers, in
 * particular for identifiers. It is kept light.
 */
class AnnotationSearchResult extends AbstractSimpleType
{
    protected $_storage = [
        '@context' => 'http://iiif.io/api/search/0/context.json',
        '@id' => null,
        '@type' => 'oa:Annotation',
        'motivation' => 'sc:painting',
        'resource' => null,
        'on' => null,
    ];

    protected $_keys = [
        '@context' => self::REQUIRED,
        '@id' => self::REQUIRED,
        '@type' => self::REQUIRED,
        // TODO To be checked.
        'motivation' => self::REQUIRED,
        'resource' => self::REQUIRED,
        'on' => self::REQUIRED,
    ];

    /**
     * @var array
     */
    protected $_result = [];

    /**
     * @var array
     */
    protected $_box = [];

    public function __construct(array $data = null)
    {
        // Parent is required to init data.
        parent::__construct($data);
    }

    /**
     * @param array $options
     * @return self
     */
    public function setResult(array $result): AbstractSimpleType
    {
        $this->_result = $result;
        $this->prepareBox();
        return $this;
    }

    public function getContent(): array
    {
        if (empty($this->offsetGet('@id'))) {
            $this->offsetSet('@id', $this->id());
        }
        if (empty($this->offsetGet('resource'))) {
            $this->offsetSet('resource', $this->resource());
        }
        if (empty($this->offsetGet('on'))) {
            $this->offsetSet('on', $this->on());
        }
        return parent::getContent();
    }

    /**
     * A search result is an annotation on the item.
     *
     * @return string|null
     */
    public function id(): ?string
    {
        if (empty($this->_box)) {
            return null;
        }

        return $this->_options['baseResultUrl']
            . 'a' . $this->_result['page']['number']
            . 'h' . $this->_result['hit']
            . 'r' . $this->_box['x'] . ',' . $this->_box['y'] . ',' . $this->_box['w'] . ',' . $this->_box['h'];
    }

    /**
     * Warning: resource() is used for the annotation resource, not the Omeka
     * resource.
     */
    public function resource(): array
    {
        return [
            '@type' => 'cnt:ContextAstext',
            'chars' => $this->_result['chars'],
        ];
    }

    public function on(): string
    {
        // TODO Use the routing system of IiifServer.
        return $this->_options['baseCanvasUrl']
            . $this->_result['page']['number']
            . '#xywh=' . $this->_box['x'] . ',' . $this->_box['y'] . ',' . $this->_box['w'] . ',' . $this->_box['h'];
    }

    protected function prepareBox(): AbstractSimpleType
    {
        if (empty($this->_result['resource'])) {
            return $this;
        }

        $scaleX = $this->_result['image']['width'] / $this->_result['page']['width'];
        $scaleY = $this->_result['image']['height'] / $this->_result['page']['height'];

        if (strlen($this->_result['chars'])) {
            $x = $this->_result['zone']['left'] + mb_stripos($this->_result['zone']['text'], $this->_result['chars'])
            / mb_strlen($this->_result['zone']['text']) * $this->_result['zone']['width'];
            $y = $this->_result['zone']['top'];
            $w = round($this->_result['zone']['width'] * ((mb_strlen($this->_result['chars']) + 1) / mb_strlen($this->_result['zone']['text']))) ;
            $h = $this->_result['zone']['height'];
        } else {
            $x = $this->_result['zone']['left'];
            $y = $this->_result['zone']['top'];
            $w = $this->_result['zone']['width'];
            $h = $this->_result['zone']['height'];
        }

        $this->_box['x'] = round($x * $scaleX);
        $this->_box['y'] = round($y * $scaleY);

        $this->_box['w'] = round($w * $scaleX);
        $this->_box['h'] = round($h * $scaleY);
        return $this;
    }
}
