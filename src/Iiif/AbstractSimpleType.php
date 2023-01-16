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

use ArrayObject;
use JsonSerializable;

/**
 * Manage the IIIF objects.
 *
 * Whatever the array is filled with, it returns always a valid json IIIF object.
 * Default values can be set too.
 *
 * @todo Use JsonLdSerializable?
 *
 * @author Daniel Berthereau
 */
abstract class AbstractSimpleType extends ArrayObject implements JsonSerializable
{
    const REQUIRED = 'required';
    const RECOMMENDED = 'recommended';
    const OPTIONAL = 'optional';
    const NOT_ALLOWED = 'not_allowed';

    /**
     * Keys starting with "@" are not manageable by default in class, so manage
     * all keys in construct.
     *
     * @var array
     */
    protected $_storage = [];

    /**
     * List of ordered keys for the type.
     *
     * @var array
     */
    protected $_keys = [];

    /**
     * @var array
     */
    protected $_options = [];

    public function __construct(array $data = null)
    {
        $input = $data
            ? array_replace($this->_storage, $data)
            : $this->_storage;
        parent::__construct($input);
    }

    public function initOptions(array $options): AbstractSimpleType
    {
        $this->_options = $options;
        return $this;
    }

    public function getContent(): array
    {
        $output = $this->getArrayCopy();

        // Remove all forbidden keys.
        $allowedKeys = array_filter($this->_keys, function ($v) {
            return $v !== self::NOT_ALLOWED;
        });
        $output = array_intersect_key($output, $allowedKeys);

        // Remove useless key/values: There is no null, ot it's an error.
        $output = array_filter($output, function ($v) {
            return !is_null($v);
        });

        // TODO Remove useless context from sub-objects. And other copied data (homepage, etc.).
        // TODO Manage version (2.1 / 3.0).

        // Order keys.
        return array_replace(array_intersect_key($this->_keys, $output), $output);
    }

    public function jsonSerialize(): array
    {
        // The validity check updates the content.
        $this->isValid(true);
        // TODO Remove useless context from sub-objects. And other copied data (homepage, etc.).
        return (array) $this->getContent();
    }

    /**
     * Check validity for the object and related objects.
     *
     * @param bool $throwException
     * @throws \IiifServer\Iiif\Exception\RuntimeException
     * @return bool
     */
    public function isValid(bool $throwException = false): bool
    {
        $output = $this->getContent();

        // Check if all required data are present.
        $requiredKeys = array_filter($this->_keys, function ($v) {
            return $v === self::REQUIRED;
        });
        $intersect = array_intersect_key($requiredKeys, $output);

        $e = null;
        if (count($requiredKeys) === count($intersect)) {
            // Second check for the children.
            // Instead of a recursive method, use jsonSerialize.
            try {
                json_encode($output);
                return true;
            } catch (\Omeka\Mvc\Exception\RuntimeException $e) {
            }
        }

        if ($throwException) {
            $missingKeys = array_keys(array_diff_key($requiredKeys, $intersect));
            if ($e) {
                $message = $e->getMessage();
            } else {
                $message = sprintf(
                    'Missing required keys for object type "%1$s": "%2$s".', // @translate
                    @$this['@type'], implode('", "', $missingKeys)
                );
            }
            throw new \Omeka\Mvc\Exception\RuntimeException($message);
        }

        return false;
    }
}
