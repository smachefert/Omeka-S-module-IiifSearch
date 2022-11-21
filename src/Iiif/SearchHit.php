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
 * @todo Normalize for iiif 2.1/3.0.
 *
 * @link https://iiif.io/api/presentation/2.0/#embedded-content
 *
 * This class is currently not made dependant of IiifServer\Iiif\AbstractType
 * in order to use search independantly.
 * It allows to manage future various types of annotation and triggers, in
 * particular for identifiers. It is kept light.
 */
class SearchHit extends AbstractSimpleType
{
    protected $_storage = [
        // A hit is the list of all matches of a page.
        '@type' => 'search:Hit',
        // The list of search result ids.
        'annotations' => [],
        // The whole words or strings that match.
        'match' => '',
        // The text before the first hit.
        'before' => null,
        // The text after the last hit.
        'after' => null,
    ];

    protected $_keys = [
        '@type' => self::REQUIRED,
        'annotations' => self::REQUIRED,
        'match' => self::REQUIRED,
        'before' => self::RECOMMENDED,
        'after' => self::RECOMMENDED,
    ];

    public function __construct(array $data = null)
    {
        // Parent is required to init data.
        parent::__construct($data);
    }
}
