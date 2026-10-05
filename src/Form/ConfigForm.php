<?php declare(strict_types=1);

// /admin/module/configure?id=IiifSearch

namespace IiifSearch\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    protected $elementGroups = [
        'search' => 'Search', // @translate
        'text' => 'Text', // @translate
        'extract' => 'Extraction', // @translate
        'bulk' => 'Bulk processing', // @translate
    ];

    public function init(): void
    {
        $this
            ->setOption('element_groups', $this->elementGroups)

            // Search.

            ->add([
                'name' => 'iiifsearch_versions',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'element_group' => 'search',
                    'label' => 'IIIF Content Search API versions', // @translate
                    'info' => 'Versions of the Search API exposed in IIIF manifests. Endpoints remain available regardless: /search and /search/1 for v1, /search/2 for v2.', // @translate
                    'value_options' => [
                        '1' => 'Search API 1.0', // @translate
                        '2' => 'Search API 2.0', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_versions',
                ],
            ])

            ->add([
                'name' => 'iiifsearch_minimum_query_length',
                'type' => Element\Number::class,
                'options' => [
                    'element_group' => 'search',
                    'label' => 'Minimum query length', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_minimum_query_length',
                    'min' => 1,
                ],
            ])

            // Motivation describing
            // @see https://iiif.io/api/search/1.0/#query-parameters.
            // Currently, motivations are not managed in common viewers.
            ->add([
                'name' => 'iiifsearch_disable_search_media_values',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'search',
                    'label' => 'Disable search in media values', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_disable_search_media_values',
                ],
            ])

            // Text.

            ->add([
                'name' => 'iiifsearch_alto_canvas_inject',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'text',
                    'label' => 'Expose per-page ALTO and annotations on each canvas', // @translate
                    'info' => 'Add two pointers on every canvas of the IIIF manifest: seeAlso with the raw alto of the page and annotations for direct text overlay by line.', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_alto_canvas_inject',
                    'value' => false,
                ],
            ])

            // Extraction.

            ->add([
                'name' => 'iiifsearch_extract_types_files',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Create file for formats', // @translate
                    'info' => 'For more info on formats, see readme.', // @translate
                    'documentation' => 'https://gitlab.com/Daniel-KM/Omeka-S-module-IiifSearch', // @translate
                    'value_options' => [
                        'text/tab-separated-values' => 'tsv with original order of words (quick and exact search for iiif search)', // @translate
                        'text/tab-separated-values;by-word' => 'tsv grouped by word (very quick search for iiif search, small index)', // @translate
                        'application/alto+xml' => 'xml alto (slow search, ocr transcription for iiif server)', // @translate
                        'application/vnd.pdf2xml+xml' => 'pdf2xml', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_types_files',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_types_media',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Create media for formats', // @translate
                    'value_options' => [
                        'text/tab-separated-values' => 'tsv with original order of words', // @translate
                        'text/tab-separated-values;by-word' => 'tsv grouped by word', // @translate
                        'application/alto+xml' => 'xml alto', // @translate
                        'application/vnd.pdf2xml+xml' => 'pdf2xml', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_types_media',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_types_digital_object',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Create digital object for formats', // @translate
                    'value_options' => [
                        'text/tab-separated-values' => 'tsv with original order of words', // @translate
                        'text/tab-separated-values;by-word' => 'tsv grouped by word', // @translate
                        'application/alto+xml' => 'xml alto', // @translate
                        'application/vnd.pdf2xml+xml' => 'pdf2xml', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_types_digital_object',
                ],
            ])

            ->add([
                'name' => 'iiifsearch_extract_create_empty_file',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Create xml file even if there is no text content', // @translate
                    'info' => 'This option may be useful to keep the same order and number of pages and extracted texts.', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_create_empty_file',
                ],
            ])

            ->add([
                'name' => 'iiifsearch_xml_fix_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'extract',
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
            ])
            ->add([
                'name' => 'iiifsearch_extract_reocr_no_text_layer',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Re-OCR pdf without text layer (requires ocrmypdf)', // @translate
                    'info' => 'When a pdf has no extractable text, run ocrmypdf to add a text layer before extraction. Silently skipped when ocrmypdf is not installed.', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_reocr_no_text_layer',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_ocr_images',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'OCR images without pdf nor alto via tesseract', // @translate
                    'info' => 'For items with only images (no pdf, no alto), run tesseract on each image to produce one alto file per page in files/alto/. Silently skipped when tesseract is not installed.', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_ocr_images',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_ocr_language',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'OCR language for ocrmypdf', // @translate
                    'info' => 'Tesseract language codes, e.g. "fra+eng". Used only when re-OCR is enabled.', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_ocr_language',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_alto_pairing_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Page to image pairing mode', // @translate
                    'info' => 'Strategy used to match each OCR page to its image. Auto runs a cascade of heuristics (METS, sourceImageInformation, basename, numeric, dimension, then sequential).', // @translate
                    'value_options' => [
                        'auto' => 'Auto (recommended)', // @translate
                        'mets' => 'Force METS structMap', // @translate
                        'source_image_information' => 'Force alto/hocr image hint', // @translate
                        'basename' => 'Force basename', // @translate
                        'basename_dir' => 'Force basename + directory', // @translate
                        'numeric' => 'Force numeric token', // @translate
                        'numeric_dir' => 'Force numeric token + directory', // @translate
                        'dimension' => 'Force aspect ratio', // @translate
                        'sequential' => 'Force sequential position', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_alto_pairing_mode',
                ],
            ])

            ->add([
                'name' => 'fieldset_extract_search',
                'type' => \Laminas\Form\Fieldset::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Storage for Omeka search', // @translate
                ],
            ])
            ->add([
                'name' => 'iiifserver_access_note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'element_group' => 'extract',
                    'text' => 'To save content makes it searchable anywhere with Omeka search engine. Note that it will increase the noise in the results, unless you use a search engine. Furthermore, this option is not recommended when the text is too much big.', // @translate
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_content_store',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Store the raw text in a property of a resource', // @translate
                    'info' => 'Text cannot be stored in item when an item is manually edited.', // @translate
                    'empty_option' => '',
                    'value_options' => [
                        'item' => 'Item', // @translate
                        'pdf' => 'Source pdf', // @translate
                        'extracted' => 'Created extracted resource', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_content_store',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_content_property',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Property to save raw text', // @translate
                    'info' => 'The text saved here is the plain-text rendering of the OCR, whatever its origin: pdftotext on a pdf source, tesseract on images, parsing of an existing alto or hocr, hint from any other extraction process. Applied to every store target selected above: the item, the source resource and/or the created extracted resource. Recommended values are "extracttext:extracted_text" or "bibo:content".', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_content_property',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a property…', // @translate
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_content_language',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Language code of the content', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_content_language',
                ],
            ])
        ;

        // Bulk process.

        $this
            ->add([
                'name' => 'iiifsearch_bulk',
                'type' => Fieldset::class,
                'options' => [
                    'element_group' => 'bulk',
                    'label' => 'Extract OCR job', // @translate
                ],
            ]);

        $this->get('iiifsearch_bulk')
            ->add([
                'name' => 'mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Extract OCR job', // @translate
                    'value_options' => [
                        'existing' => 'Only already extracted (improve extraction)', // @translate
                        'missing' => 'Only missing extracted medias', // @translate
                        'all' => 'All medias', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'mode',
                    'value' => 'all',
                ],
            ])
            ->add([
                'name' => 'item_ids',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Item ids', // @translate
                ],
                'attributes' => [
                    'id' => 'item_ids',
                    'placeholder' => '2-6 8 38-52 80-',
                ],
            ])
            ->add([
                'name' => 'process',
                'type' => Element\Submit::class,
                'options' => [
                    'label' => 'Run in background', // @translate
                ],
                'attributes' => [
                    'id' => 'process',
                    'value' => 'Process', // @translate
                ],
            ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter
            ->add([
                'name' => 'iiifsearch_versions',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_xml_fix_mode',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract_types_files',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract_types_media',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract_content_store',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract_content_property',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract',
                'required' => false,
            ]);
    }
}
