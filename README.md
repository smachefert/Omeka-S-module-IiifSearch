IIIF Search (module for Omeka S)
================================


Summary
-----------

IIIF Search is a module for Omeka S that add IIIF Search Api for fulltext searching.


Optional modules
----------------

The module can live alone, but module [IIIF-Server](https://github.com/bubdxm/Omeka-S-module-IiifServer) is required to be useful on your own install.

If your ocr comes from pdf, you need to extract them first with module [Extract OCR](https://github.com/bubdxm/Omeka-S-module-ExtractOcr).

If your ocr files are Alto xml files, they are managed natively: just upload them with the item alongside images (tested on alto v3).


Installation
------------

- Download the last release or install the module via git:

```sh
cd omeka-s/modules
git clone git@github.com:symac/Omeka-S-module-IiifSearch.git "IiifSearch"
```

- Enable it from Omeka admin → Modules → IiifSearch -> install

The IIIF search service is automatically appended to IIIF manifests when an ocr text is available.

***WARNING***

If your files are badly UTF-8 encoded, in particular alto xml files, you may need to enable a feature to fix them dynamically: add this code in the file `config/local.config.php` of Omeka:

```php
    'iiifserver' => [
        'config' => [
            'iiifserver_enable_utf8_fix' => true,
        ],
    ],
```

Of course, for performance, it's better to fix files before upload.


Using the Iiif Search module
---------------------------

You can use API with :

http://yourdomain/omeka-s/iiif-search/:itemID?q=textquery

Iiif Search module will return Iiif Search response.


Viewers
-------

- [Diva](https://gitlab.com/Daniel-KM/Omeka-S-module-Diva) : Module for Omeka S compliant with IIIF that displays a light IIIF compliant viewer.
- [Mirador](https://gitlab.com/Daniel-KM/Omeka-S-module-Mirador) : Module for Omeka S compliant with IIIF that displays a fully IIIF compliant viewer with multiple windows.
- [Universal Viewer](https://gitlab.com/Daniel-KM/Omeka-S-module-UniversalViewer) : Module for Omeka S compliant with IIIF that displays an unified online player for any file. It can display books, images, maps, audio, movies, pdf, 3D views, and anything else as long as the appropriate extensions are installed.


TODO
----

- [ ] Implement API Search v2.
- [ ] Add a distinct route for v0, v1 and v2.
- [ ] Auto complete.
- [ ] Store data (word positions) as media data or item data or in a specific table or in Solr to speed up queries, in particular when alto are many.
- [ ] Fix utf8 issues with dom.


Troubleshooting
---------------

See online [IIIF Search issues](https://github.com/bubdxm/Omeka-S-module-IiifSearch/issues).


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

* Syvain Machefert, Université Bordeaux 3 (see [symac](https://github.com/symac))
* Daniel Berthereau, (see [Daniel-KM](https://gitlab.com/Daniel-KM))
