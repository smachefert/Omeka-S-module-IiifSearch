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
            ]);
    }
}
