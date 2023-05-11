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
            ])

            // The option is the same in module IIIF Server.
            // TODO Make option to match image and xml an option to set in a property of the item.
            ->add([
                'name' => 'iiifsearch_xml_image_match',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Match images and xmls when they are multiple', // @translate
                    'value_options' => [
                        'order' => 'Media order (page_001.jpg, alto_001.xml, page_002.jpg, alto_002.xml, …)', // @translate
                        'basename' => 'Media source base filename (page_001.jpg, page_002.jpg, page_002.xml, page_001.xml…)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_xml_image_match',
                    'value' => 'order',
                ],
            ])

            // The option is the same in module IIIF Server.
            ->add([
                'name' => 'iiifsearch_xml_fix_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Fix bad xml and invalid utf-8 characters', // @translate
                    'value_options' => [
                        'no' => 'No', // @translate
                        'dom' => 'Via DOM (quick)', // @translate
                        'regex' => 'Via regex (slow)', // @translate
                        'all' => 'All', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_xml_fix_mode',
                    'value' => 'no',
                ],
            ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter
            ->add([
                'name' => 'iiifsearch_xml_fix_mode',
                'required' => false,
            ]);
    }
}
