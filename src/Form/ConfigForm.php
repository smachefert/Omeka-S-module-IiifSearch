<?php declare(strict_types=1);

// /admin/module/configure?id=IiifSearch

namespace IiifSearch\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    protected $elementGroups = [
        'search' => 'Search', // @translate
        'text' => 'Text', // @translate
        'extract' => 'Extraction', // @translate
        'pdf' => 'PDF rebuild', // @translate
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
                'name' => 'iiifsearch_canvas_seealso_alto',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'text',
                    'label' => 'Expose ALTO via seeAlso on each canvas', // @translate
                    'info' => 'Append a seeAlso entry to every canvas of the IIIF manifest with the URL of the per-page ALTO XML (/iiif-search/{id}/alto/N.xml). Consumed by Mirador (mirador-textoverlay, ocr-helper) and most IIIF readers.', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_canvas_seealso_alto',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_canvas_annotations',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'text',
                    'label' => 'Expose annotations (supplementing) on each canvas', // @translate
                    'info' => 'Append an annotations entry to every canvas with the URL of the per-page AnnotationPage built from the ALTO (/iiif-search/{id}/annotations/N.json). Used by viewers that consume the IIIF AnnotationPage format for text overlay.', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_canvas_annotations',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_canvas_seealso_hocr',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'text',
                    'label' => 'Expose hOCR via seeAlso on each canvas (if available)', // @translate
                    'info' => 'Append a seeAlso entry with the URL of the per-page hOCR (/iiif-search/{id}/hocr/N.html). Phase 1: serves only native hOCR medias attached to the item; no conversion from ALTO. Leave off when no hOCR source is present.', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_canvas_seealso_hocr',
                ],
            ])

            // Extraction.

            ->add([
                'name' => 'fieldset_extract_resource',
                'type' => \Laminas\Form\Fieldset::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Items to process or not to process', // @translate
                ],
            ])

            ->add([
                'name' => 'iiifsearch_extract_query_include',
                'type' => OmekaElement\Query::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Extract text only from items matching query', // @translate
                    'info' => 'Leave empty to ocerize every item with images or a PDF. To restrict to digitised books or periodicals, limit the query to specific templates, item sets or properties.', // @translate
                    'query_resource_type' => 'items',
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_query_include',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_query_exclude',
                'type' => OmekaElement\Query::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Skip items matching query', // @translate
                    'info' => 'Leave empty to keep the include filter only. Useful to skip paintings, photographs or 3D objects that have images but are not text-bearing. Evaluated after the include filter.', // @translate
                    'query_resource_type' => 'items',
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_query_exclude',
                ],
            ])

            ->add([
                'name' => 'fieldset_extract_storage',
                'type' => \Laminas\Form\Fieldset::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Storage of indices and derivative data', // @translate
                ],
            ])

            ->add([
                'name' => 'iiifsearch_extract_dests_tsv',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'TSV index (full)', // @translate
                    'info' => 'Quick and exact search for IIIF search. Recommended: file only.', // @translate
                    'value_options' => $this->destinationOptions(),
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_dests_tsv',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_dests_tsv_by_word',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'TSV index (grouped by word)', // @translate
                    'info' => 'Very quick search for IIIF search, small index. Recommended: file only.', // @translate
                    'value_options' => $this->destinationOptions(),
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_dests_tsv_by_word',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_dests_alto',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'ALTO XML', // @translate
                    'info' => 'OCR transcription for IIIF server. File is the default; attaching as media or digital object is also supported when you want to expose ALTO as a regular Omeka resource.', // @translate
                    'value_options' => $this->destinationOptions(),
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_dests_alto',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_dests_pdf2xml',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'pdf2xml (deprecated)', // @translate
                    'info' => 'pdf2xml is an intermediate format kept for backward compatibility and not recommended anymore. Leave all destinations unchecked.', // @translate
                    'value_options' => $this->destinationOptions(),
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_dests_pdf2xml',
                ],
            ])

            ->add([
                'name' => 'fieldset_extract_process',
                'type' => \Laminas\Form\Fieldset::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Process for extraction', // @translate
                ],
            ])

            ->add([
                'name' => 'iiifsearch_xml_fix_mode',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'extract',
                    'label' => 'Fix bad xml and invalid utf-8 characters', // @translate
                    'info' => 'Independent strategies for repairing malformed OCR XML. Leave both unchecked to disable. DOM is quick (single pass via DOMDocument); regex is slow (per-character UTF-8 fix-up). Both can be enabled together for maximum coverage on degraded files.', // @translate
                    'value_options' => [
                        'dom' => 'Via DOM (quick)', // @translate
                        'regex' => 'Via regex (slow)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_xml_fix_mode',
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
                'name' => 'iiifsearch_extract_create_pdf_text_layer',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'pdf',
                    'label' => 'Create a PDF with text layer (requires ocrmypdf + ghostscript)', // @translate
                    'info' => 'Rebuild the source PDF with an embedded text layer so the file itself becomes searchable in any PDF reader. "Update in place" replaces the existing media/digital object file (sha256 and size are refreshed via DBAL to keep the database consistent). "New resource" creates a fresh media or digital object next to the source, of the same kind. PDFs that already carry a text layer are skipped. Items without a PDF rely on img2pdf to bundle their images first, and the result is always attached as a new media / digital object.', // @translate
                    'value_options' => [
                        '' => 'Off', // @translate
                        'update' => 'Update file in place on existing media / digital object', // @translate
                        'new' => 'Create a new media / digital object (mirrors the source kind)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_create_pdf_text_layer',
                ],
            ])
            ->add([
                'name' => 'iiifsearch_extract_concat_monopage_pdf',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'pdf',
                    'label' => 'Concatenate mono-page PDFs before re-OCR', // @translate
                    'info' => 'When an item carries several single-page PDFs (typically a scanned book split per page), concatenate them via ghostscript into a single multi-page PDF before running ocrmypdf. The result is always created as a new media / digital object — never used to update one of the source rows in place — because there is no unambiguous "primary" row to overwrite.', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_extract_concat_monopage_pdf',
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
                    'label' => 'Page to image pairing mode when ocr is provided', // @translate
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
                'name' => 'query',
                'type' => OmekaElement\Query::class,
                'options' => [
                    'label' => 'Item query', // @translate
                    'info' => 'Restrict the job to items matching this Omeka query. Leave empty to process all items with ocr-eligible content.', // @translate
                    'query_resource_type' => 'items',
                ],
                'attributes' => [
                    'id' => 'query',
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

        // Cleanup of legacy module-created medias. Only medias that match the
        // strict provenance rules of ExtractOcr::canDeleteOcrMedia() will ever
        // be touched. User uploads remain untouched.

        $this
            ->add([
                'name' => 'iiifsearch_cleanup',
                'type' => Fieldset::class,
                'options' => [
                    'element_group' => 'bulk',
                    'label' => 'Cleanup module-created medias', // @translate
                ],
            ]);

        $this->get('iiifsearch_cleanup')
            ->add([
                'name' => 'targets',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Formats to delete', // @translate
                    'info' => 'Only medias produced by the module are eligible: tsv indexes whose source ends with ".{itemId}.[by-word.]tsv", pdf2xml whose source ends with ".{itemId}.pdf2xml.xml" (legacy, deprecated), and ALTO whose XML carries the module provenance marker. Medias uploaded by users are never touched, whatever their format. A media is kept when its item has no ocr file yet under files/iiif-search/ or files/alto/, since it may be the only source of the search.', // @translate
                    'value_options' => [
                        'tsv' => 'TSV search indexes (.{itemId}.tsv, .{itemId}.by-word.tsv)', // @translate
                        'pdf2xml' => 'pdf2xml (deprecated, not attached anymore by recent versions)', // @translate
                        'alto' => 'ALTO created by the module (provenance marker)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'iiifsearch_cleanup_targets',
                    'value' => ['tsv', 'pdf2xml'],
                ],
            ])
            ->add([
                'name' => 'rerun_extract',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Re-run OCR / index extraction after cleanup', // @translate
                    'info' => 'Dispatch the Extract OCR job ("missing" mode) right after the cleanup so the deleted indexes and OCR derivatives are produced again as flat files under files/iiif-search/ and files/alto/. It requires the command-line utility pdftotext: without it, nothing is deleted.', // @translate
                ],
                'attributes' => [
                    'id' => 'iiifsearch_cleanup_rerun_extract',
                    'value' => '1',
                ],
            ])
            ->add([
                'name' => 'cleanup',
                'type' => Element\Submit::class,
                'options' => [
                    'label' => 'Run cleanup in background', // @translate
                ],
                'attributes' => [
                    'id' => 'cleanup',
                    'value' => 'Clean up', // @translate
                ],
            ]);

        // Cleanup of flat OCR files (files/iiif-search, files/alto,
        // files/pdf2xml) for items that no longer match the OCR policy. Useful
        // when the include/exclude queries are tightened after a wider initial
        // run (paintings or photographs OCRed by mistake).

        $this
            ->add([
                'name' => 'iiifsearch_cleanup_ocr_files',
                'type' => Fieldset::class,
                'options' => [
                    'element_group' => 'bulk',
                    'label' => 'Cleanup OCR files of excluded items', // @translate
                ],
            ]);

        $this->get('iiifsearch_cleanup_ocr_files')
            ->add([
                'name' => 'cleanup_ocr_files',
                'type' => Element\Submit::class,
                'options' => [
                    'label' => 'Remove OCR files for items not matching the OCR policy', // @translate
                    'info' => 'Scans files/iiif-search/, files/alto/ and files/pdf2xml/, parses the item id at the start of each filename, and unlinks files whose item is excluded by the include / exclude queries in the Extraction tab. Files under files/original/ are never touched.', // @translate
                ],
                'attributes' => [
                    'id' => 'cleanup_ocr_files',
                    'value' => 'Clean up OCR files', // @translate
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
                'name' => 'iiifsearch_extract_dests_tsv',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract_dests_tsv_by_word',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract_dests_alto',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract_dests_pdf2xml',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract_query_include',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract_query_exclude',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract_create_pdf_text_layer',
                'required' => false,
            ])
            ->add([
                'name' => 'iiifsearch_extract_concat_monopage_pdf',
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

    /**
     * Destination options used by every per-format multicheckbox in the
     * extraction tab.
     */
    protected function destinationOptions(): array
    {
        return [
            'file' => 'flat file (recommended)', // @translate
            'media' => 'attach as media', // @translate
            'digital_object' => 'attach as digital object (requires DigitalObject module)', // @translate
        ];
    }
}
