<?php declare(strict_types=1);

// /admin/module/configure?id=IiifSearch

namespace IiifSearch\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'iiifsearch_minimum_query_length',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Minimum query length', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_minimum_query_length',
                    'min' => 1,
                    'value' => 3
                ],
            ])

            // Motivation describing
            // @see https://iiif.io/api/search/1.0/#query-parameters.
            // Currently, motivations are not managed in common viewers.
            ->add([
                'name' => 'iiifsearch_disable_search_media_values',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Disable search in media values', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_disable_search_media_values',
                ],
            ]);
    }
}
