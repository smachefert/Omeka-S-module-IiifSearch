IIIF Search (module for Omeka S)
================================

IIIF Search is a module for Omeka S that add IIIF Search Api for fulltext searching.
The source text can be xml files (alto, page, hocr, pdf2xml, tei), pdf (with or without text layer) or images.
When there is no text, the images or the pdf can be ocerized if the tools are available (ocrmypdf or tesseract) on the server and the texts are stored as standard files.


Installation
------------

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

With big bases or on shared hosts, it is recommended to install the optional
module [Job Queue] to avoid to overload the server when indexing data.

The module can live alone, but module [IIIF Server](https://gitlab.com/Daniel-KM/Omeka-S-module-IiifServer) is required to be useful on your own install.
The IIIF search service is automatically appended to IIIF manifests when an ocr text is available.

* From the zip

Download the last release [IiifSearch.zip] from the list of releases, and uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of the module to `IiifSearch`.

Then install it like any other Omeka module and follow the config instructions.

* For test

The module includes a comprehensive test suite with unit and functional tests.
Run them from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/IiifSearch/phpunit.xml --testdox
```

* Old options for badly formatted xml files

The old option to fix bad Microsoft xml files `iiifserver_enable_utf8_fix` that might be added in the file `config/local.config.php` of Omeka is no more needed and can be removed: the invalid utf-8 characters and the malformed xml are now repaired automatically, only when needed.

```php
    'iiifserver' => [
        'config' => [
            'iiifserver_enable_utf8_fix' => true,
        ],
    ],
```


Usage
-----

If your ocr files are Alto xml files, they are managed natively: just upload them with the item alongside images (tested on alto v3).
If your transcriptions are PAGE xml files (the output of htr and layout tools like Transkribus, laypa, Loghi, eScriptorium or OCR-D), upload them in the same way: they are converted to alto automatically.
The formats hOCR and pdf2xml are managed too, but they are not recommended because they are not standards.
If the ocr is not available, it will be extracted automatically from pdf and images.

You can use API with https://yourdomain/omeka-s/iiif/search/:itemID/search?q=textquery or the variant with version 1 https://yourdomain/omeka-s/iiif/search/:itemID/search/1?q=textquery or 2 https://yourdomain/omeka-s/iiif/search/:itemID/search/2?q=textquery.
This endpoint can be used without the module [Iiif Server].

Iiif Search module will return [Iiif Search response].


Automatic page-to-image pairing
-------------------------------

When an item carries several OCR sources (per-page alto, PAGE, hOCR, pdf2xml) or several PDF files, each OCR page must be associated with the right image so that search highlights land on the right pixels.
The module decides this automatically by running a cascade of heuristics from the most authoritative to the least:

1. METS structMap: when a METS file is attached, its `<fileSec>` and `<structMap>` describe the producer-decided pairing and are used as-is.
2. `<sourceImageInformation>` / PAGE `imageFilename` / hOCR `title="image …"`: when the OCR carries its own image filename hint, that hint is matched against the attached image medias.
3. Exact basename: after normalisation (lowercase, collapsed separators, OCR/image extension dropped), the OCR file name is matched against the image's source basename.
4. Basename + sub-folder: same as above, with the sub-folder of `media->source()` used as a disambiguator so that batches reusing the same filenames across different folders do not collide.
5. Numeric token: rightmost numeric run (optionally followed by `r` or `v` for folio side) extracted from each name. `MS-Lat-1234_f042r.tif` ↔ `042r.alto.xml` pairs on `42r`.
6. Numeric token + sub-folder: same with sub-folder disambiguation.
7. Aspect ratio: when alto page dimensions are available and the item's images have distinct shapes, pair by aspect ratio within a 5 % tolerance.
8. Sequential position: fallback: the n-th OCR page pairs with the n-th image, like before.

The cascade short-circuits as soon as a layer covers ≥ 80 % of pages without collisions. Per-page warnings are emitted when paired images are out of order or when aspect ratios diverge by more than 5 % from their OCR page.

XML uploads are detected by content, not by mime: an alto, mets, PAGE or hOCR file uploaded as plain `application/xml` is still recognised. PAGE, hOCR, pdf2xml and TEI sources are converted on the fly to a canonical ALTO file persisted under `files/alto/` for export reuse.
The original file remains the source: the alto is rebuilt when it is modified.
For PAGE, the bounding box of each polygon (`Coords/@points`) is used, the regions follow the reading order, and the text of a line without words is split proportionally, like pdf2xml.

Per-image OCR (no PDF, no ALTO) is generated by tesseract when available, producing one ALTO file per image under `files/alto/`.

Configuration setting Page to image pairing mode runs the full cascade by default (`auto`). Each layer can also be forced individually for batch jobs through the same setting, or through the `pairing_mode` argument of the OCR extraction job for one-off overrides.

A dry-run audit job is provided to preview the cascade decision on a fonds before launching a writing run; output is a CSV under `files/iiif-search-audit/`.


Configuration options
---------------------

The default settings are fine for most use cases.

The config form is divided in four tabs. The tab "Extract" is the most important because it defines what to prepare or not. It should be set earlier, because changes may require to reprocess all medias.

### Search

- IIIF Content Search API versions
  Versions of the Search API exposed as services in IIIF manifests.
  The HTTP endpoints stay available regardless of this setting: `/search` and `/search/1` for v1, `/search/2` for v2.

- Minimum query length
  Queries shorter than this are rejected.

- Disable search in media values
  By default, fulltext hits are also collected from RDF property values attached to media or digital objects of the item, returned as item-level matches.
  Disable to limit hits to OCR content only.

- Prefer the full tsv index
  When an item has the two tsv indexes, the one grouped by word is used for the queries, except for exact searches, because it is a bit quicker.
  Enable to use the full index for all queries, so the hits get their context.

- Spelling rules to match variants of words
  One rule by line: a list of strings separated by spaces, then `=`, then the string used to compare words. The rules apply to the query and to the text, so they are useful for old texts with many spellings of the same word. For example for early modern Dutch:
  ```
  ij y ie = i
  ae = aa
  ck = k
  gh = g
  cq = k
  dt = t
  ```
  With these rules, a query for "huis" finds "huys" and "huijs" too. The rules are applied with `strtr()`, so the longest string is replaced first. They may find some unrelated words, so keep them short.

- Allow wildcards in the query
  `*` matches any number of letters and `?` one letter, for example `huis*` or `hu?s`. A word with a wildcard matches whole words, and it should have the minimum query length without the wildcards.

- Allow fuzzy search in the query
  `~` after a word finds the words with one different letter (an insertion, a deletion or a substitution), or two for a word of eight letters or more, for example `huis~` finds "hvis" and "huus". The number can be set with `~1` or `~2`. The distance is computed after the normalization of the words, but by byte, so it is stricter for non-latin letters.
  Fuzzy search finds many unrelated words for short words, so it is useful mainly for long words or for texts with many errors of recognition.

The words are compared without case, diacritics and punctuation around them. In the tsv indexes, a query word matches a whole word. In the xml formats (alto, hOCR, pdf2xml), it matches a part of a word too, but a word with a wildcard or a fuzzy word matches whole words.
The IIIF Content Search API leaves the matching to the server, so the syntax for wildcards and fuzzy search is specific to this module. The matching text of the page is returned in `match` (Search API 1) and in `exact` (Search API 2), so the viewers display the variant that is found.
On a book of 75,000 words (tsv index), the rules, the wildcards and the fuzzy search add about 20 to 40 ms by query.

Each match is a hit with up to five words of context before and after it in its page: `before` and `after` in Search API 1, and a `contextualizing` annotation with a `TextQuoteSelector` (`prefix`, `exact`, `suffix`) in the `annotations` of the response in Search API 2.
The context is available for alto, hOCR, pdf2xml and the full tsv index, but not for the tsv grouped-by-word index, that does not keep the order of the words. When the two tsv indexes are available, enable the option above to get the context.

### Text

- Expose per-page ALTO and annotations on each canvas
  Adds two pointers on every non-ALTO canvas of the IIIF manifest:
  - `seeAlso` (`/iiif/search/{id}/alto/{n}.xml`): raw ALTO of the page,
  - `annotations` (`/iiif/search/{id}/annotations/{n}.json`): IIIF AnnotationPage with one annotation per OCR line, suitable for viewer text overlay (Mirador textoverlay, UV transcription view, etc.).

  The page is resolved from any available source, in this order:
  1. Multipage ALTO file (one XML with N `<Page>` elements) attached as media or digital object (the page is extracted and cached under`files/iiif-search/{item_id}/alto-{n}.xml`).
  2. Per-page ALTO files attached as media or digital object, picked by positional order among the item's file resources.
  3. ALTO files generated by the job Extract OCR under `files/alto/`, matched first by the `{item_id}-page-{NNN}.alto.xml` convention then by sorted glob.

  Independent from the Search service: the Search service answers a query against the whole item; the canvas pointers expose the raw OCR for the current page without any query.
  Disable when viewers do not consume page-level OCR/annotations. Default: off (opt-in).

- Table of contents of a TEI file
  When a public TEI file is attached to an item, its table of contents is used
  as structure of the manifest by the module Iiif Server: each `div` with a
  `head` is a range labelled by the head, nested like the divs, and the `pb`
  inside it are its canvases. A `pb` is matched to an image by its `facs`, that
  refers to a `surface` or a `graphic` of the `facsimile` (the file names are
  compared with and without extension, so a TEI describing tiff masters matches
  jpeg images), else by its position. The structure is cached under
  `files/iiif-search/{item_id}.toc.json` and rebuilt when the TEI or the images
  change. The priority with a structure set in a property is defined in the
  module Iiif Server.

  Optionally, the job Extract OCR stores this table of contents in a property
  (default `dcterms:tableOfContents`), so it can be seen and fixed in the
  record. A structure set manually in the property is kept and a stored one is
  replaced when the TEI changes. To be used in the manifest, the property should
  be the property for structures of the module Iiif Server.

### Extract

- Bad xml and invalid utf-8 characters
  The invalid utf-8 characters (generally windows-1252 bytes in a file declared
  as utf-8) and the malformed xml are repaired automatically, only when needed.
  Each repaired file is logged: for performance, it is better to fix it.

- Per-format destinations
  There are four independent multi-checkboxes pick where each format should be written:
  - Tsv full index (default file);
  - Tsv grouped-by-word index (default file);
  - Alto xml (default file);
  - Pdf2xml (default empty, deprecated, kept for old installations).

  Each list can hold any subset of file, media or digital object.
  Files land under `files/iiif-search/` (tsv), `files/alto/` (alto), `files/pdf2xml/` (pdf2xml).
  Resource targets attach the file as a regular Omeka media, or as a DigitalObject linked back to the item via the first property listed in Digital Object inline property (default `dcterms:hasFormat`).
  Recommendation: file only for tsv and pdf2xml; ALTO can additionally be attached as media / digital object when you want it exposed as a regular Omeka resource.

- Restrict OCR to items matching query
  Default empty (all items eligible). Use it to limit OCR to digitised books / periodicals / manuscripts by resource template, item set, property value, etc.

- Skip OCR for items matching query
  Default empty. Evaluated after the include filter above. Useful to keep paintings, photographs or 3D objects out of the OCR pipeline even when they share images with other items.

  Both queries are consulted by the `api.create.post` listener (per item save) and by the bulk Extract OCR job (in two API calls resolved against the candidate set, no per-item loop).

- Store the raw text in a property of a resource
  Combinable targets for the extracted plain text, stored as a value of the property selected below:
  - item: text is set on the source item itself,
  - pdf: text is set on the source PDF, whether attached as media or digital object,
  - extracted: text is set on the newly created extracted resource, whichever kind it is (media or digital object).

- Property to save raw text
  RDF property used by every store target above (item, source resource, created extracted resource).
  The text saved here is the plain-text rendering of the OCR regardless of its origin: pdftotext on a pdf source, tesseract on images, parsing of an existing alto or hocr, or any other extraction process plugged in the pipeline.
  `extracttext:extracted_text` (module ExtractText) or `bibo:content` are good defaults.
  Note: storing the full OCR text inside a property increases search noise unless a fulltext search engine is configured.

- Language code of the content
  Optional ISO language tag applied to the stored text value.

- Visibility of the property with raw text
  The stored text may be public, private, or follow the visibility and the access of the files with the module [Access](https://gitlab.com/Daniel-KM/Omeka-S-module-Access): the text of an item is public only when all its images (or the item without image) are public, freely available and not under embargo; the text of a media follows the media. A public value is visible even when the files are reserved, so do not set it public with reserved files. The visibility is set when the text is extracted. With the option following the access, it is updated automatically when the module Access updates the access of the files (save of a resource, batch edit, rebuild of the access index, end of embargo).

- Create xml file even if there is no text content
  Useful to keep a stable page count and ordering when some pages have no recoverable text.

- Re-OCR pdf without text layer
  When a PDF has no extractable text layer, run `ocrmypdf` to add one before extraction. Silently skipped when `ocrmypdf` is not installed.

- OCR images without pdf nor alto
  For items composed only of images (no PDF, no ALTO), `tesseract` is run on each image to produce one ALTO file per page under `files/alto/`.
  Silently skipped when `tesseract` is not installed.

- OCR language
  Tesseract language code (`eng`, `fra`, `deu+fra`, …) passed to `-l`.

- Page to image pairing mode
  `auto` runs the full cascade described above.
  Each layer can also be forced individually (`mets`, `source_image_information`, `basename`, `basename_dir`, `numeric`, `numeric_dir`, `dimension`, `sequential`) for batch overrides.

### Bulk processing

The config form should be saved before bulk processing.

- Extract OCR
  It runs the job on a selection of items (`all`, `missing`, `existing`) with optional `item_ids` filter (`2-6 8 38-52 80-`) or Omeka query.
  The include / exclude policy from the tab Extract is also applied to the candidate set.

- Cleanup module-created medias
  It runs a job that drops the rows that wrap module-produced OCR derivatives still attached as Omeka medias (early-version legacy).
  Eligible only when the media carries the module provenance marker (ALTO) or matches the naming convention `{base}.{itemId}.{ext}` (Tsv, pdf2xml).
  User uploads are never touched, whatever the mime type.
  The pre-checked checkbox "Re-run OCR / index extraction after cleanup" allows to run job to extract OCR in `missing` mode at the end of the cleanup so the two jobs run strictly sequentially.

- Cleanup OCR files of excluded items
  It runs a job that scans `files/iiif-search/`, `files/alto/`, and `files/pdf2xml/`, parses the item id at the start of each filename, and unlinks files whose item is no longer covered by the include / exclude policy.
  `files/original/` is never touched and user uploads remain intact.


Viewers
-------

- [Diva](https://gitlab.com/Daniel-KM/Omeka-S-module-Diva) : Module for Omeka S compliant with IIIF that displays a light IIIF compliant viewer.
- [Mirador](https://gitlab.com/Daniel-KM/Omeka-S-module-Mirador) : Module for Omeka S compliant with IIIF that displays a fully IIIF compliant viewer with multiple windows.
- [Universal Viewer](https://gitlab.com/Daniel-KM/Omeka-S-module-UniversalViewer) : Module for Omeka S compliant with IIIF that displays an unified online player for any file. It can display books, images, maps, audio, movies, pdf, 3D views, and anything else as long as the appropriate extensions are installed.


TODO
----

- [x] Implement API Search v1.
- [x] Implement API Search v2.
- [x] Add a distinct route for v0, v1 and v2 (v0 and v1 done, v2 missing).
- [ ] Auto complete.
- [x] Store data (word positions) as media data or item data or in a specific table or in Solr to speed up queries, in particular when alto are many. Use pdftotext -tsv for a simpler process.
- [x] Fix utf8 issues with dom.
- [ ] Search words with "+" (AND).


Troubleshooting
---------------

See online [IIIF Search issues](https://gitlab.com/Daniel-KM/Omeka-S-module-IiifSearch/-/work_items).


License
-------

This module is published under [GNU/GPL](https://www.gnu.org/licenses/gpl-3.0.html).

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
details.

You should have received a copy of the GNU General Public License along with
this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.


Contact
-------

* Sylvain Machefert, Université Bordeaux 3 (see [symac](https://github.com/symac))
* Daniel Berthereau, (see [Daniel-KM](https://gitlab.com/Daniel-KM))


[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[IiifSearch.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifSearch/-/releases
[Iiif Server]: https://gitlab.com/Daniel-KM/Omeka-S-module-IiifServer
[Iiif Search response]: https://iiif.io/api/search/
[Job Queue]: https://gitlab.com/Daniel-KM/Omeka-S-module-JobQueue
