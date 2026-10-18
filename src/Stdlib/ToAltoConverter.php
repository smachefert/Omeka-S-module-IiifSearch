<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

use DOMDocument;
use XSLTProcessor;

/**
 * Convert non-alto OCR sources (hocr, page, pdf2xml, tei) into a canonical ALTO
 * file, persisted on disk so the downstream pipeline only has to consume alto.
 *
 * The converted file is reused on subsequent runs as long as its mtime is
 * greater than or equal to the source mtime. The target directory is the caller
 * responsibility (typically files/alto/).
 */
class ToAltoConverter
{
    /**
     * Provenance markers written into every alto file produced by the module.
     * Used by {@see IiifSearch\Stdlib\AltoProvenance::isModuleAlto()} to safely
     * distinguish module-generated alto medias (deletable) from native ones
     * (must be preserved).
     */
    public const SOFTWARE_NAME = 'IiifSearch (Omeka S)';
    public const SOFTWARE_CREATOR = 'Daniel Berthereau';

    /**
     * @var string Absolute path to the directory holding the bundled XSLT.
     */
    protected $xslDir;

    public function __construct(?string $xslDir = null)
    {
        $this->xslDir = $xslDir ?: dirname(__DIR__, 2) . '/data/xsl';
    }

    /**
     * Check if an alto file has at least one word with a position on a page.
     */
    public static function hasCoordinates(string $filepath): bool
    {
        $reader = new \XMLReader();
        if (!@$reader->open($filepath, null, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            return false;
        }
        $hasPageSize = false;
        $result = false;
        while (@$reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'Page') {
                $hasPageSize = (float) $reader->getAttribute('WIDTH') > 0
                    && (float) $reader->getAttribute('HEIGHT') > 0;
            } elseif ($reader->localName === 'String'
                && $hasPageSize
                && (float) $reader->getAttribute('WIDTH') > 0
                && (float) $reader->getAttribute('HEIGHT') > 0
            ) {
                $result = true;
                break;
            }
        }
        $reader->close();
        return $result;
    }

    /**
     * Get the file name of the source image of an alto file, like tesseract.
     */
    public static function sourceImageFilename(string $altoFilepath): ?string
    {
        $reader = new \XMLReader();
        if (!@$reader->open($altoFilepath, null, LIBXML_NONET)) {
            return null;
        }
        $result = null;
        while (@$reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'fileName') {
                $result = basename(trim($reader->readString())) ?: null;
                break;
            }
            // The description is before the layout.
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'Layout') {
                break;
            }
        }
        $reader->close();
        return $result;
    }

    /**
     * Stamp an existing alto file in place with the module's provenance block.
     * Used for alto produced outside the XSLT pipeline (tesseract, direct
     * copy).
     */
    public function stampAltoFile(string $altoFilepath, ?string $version = null): bool
    {
        if (!is_readable($altoFilepath)) {
            return false;
        }
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        if (!@$dom->load($altoFilepath, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return false;
        }
        $this->stampProvenance($dom, $version);
        return (bool) @$dom->save($altoFilepath);
    }

    /**
     * Inject (or refresh) a Description/OCRProcessing block identifying the
     * module as the producer of this alto document. Safe to call on alto
     * already carrying the marker: the existing block is replaced.
     */
    protected function stampProvenance(DOMDocument $dom, ?string $version = null): void
    {
        $root = $dom->documentElement;
        if (!$root) {
            return;
        }
        $ns = $root->namespaceURI ?: 'http://www.loc.gov/standards/alto/ns-v4#';

        $description = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'Description') {
                $description = $child;
                break;
            }
        }
        if (!$description) {
            $description = $dom->createElementNS($ns, 'Description');
            $root->insertBefore($description, $root->firstChild);
        }

        // Drop pre-existing OCRProcessing blocks emitted by this module so the
        // file ends up with a single canonical marker; foreign blocks (other
        // producers in a multi-step pipeline) are kept untouched.
        $existing = [];
        foreach ($description->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === 'OCRProcessing') {
                $existing[] = $node;
            }
        }
        foreach ($existing as $node) {
            $names = $node->getElementsByTagNameNS($ns, 'softwareName');
            for ($i = 0; $i < $names->length; ++$i) {
                if (trim((string) $names->item($i)->nodeValue) === self::SOFTWARE_NAME) {
                    $description->removeChild($node);
                    break;
                }
            }
        }

        $proc = $dom->createElementNS($ns, 'OCRProcessing');
        $proc->setAttribute('ID', 'OCR_iiifsearch');
        $step = $dom->createElementNS($ns, 'ocrProcessingStep');
        $software = $dom->createElementNS($ns, 'processingSoftware');
        $software->appendChild($dom->createElementNS($ns, 'softwareCreator', self::SOFTWARE_CREATOR));
        $software->appendChild($dom->createElementNS($ns, 'softwareName', self::SOFTWARE_NAME));
        if ($version !== null && $version !== '') {
            $software->appendChild($dom->createElementNS($ns, 'softwareVersion', $version));
        }
        $step->appendChild($software);
        $proc->appendChild($step);
        $description->appendChild($proc);
    }

    /**
     * @param string $classification One of XmlMediaClassifier::TYPE_*.
     * @return bool True on success or when the target is already up to date.
     */
    public function convert(string $sourceFilepath, string $classification, string $targetFilepath): bool
    {
        if (!is_readable($sourceFilepath)) {
            return false;
        }
        if ($this->targetIsFresh($sourceFilepath, $targetFilepath)) {
            return true;
        }

        switch ($classification) {
            case XmlMediaClassifier::TYPE_ALTO:
                // Copy native alto, then stamp the target so downstream cleanup
                // recognises the rewritten copy as module-produced. The
                // original source on disk is untouched.
                if (!@copy($sourceFilepath, $targetFilepath)) {
                    return false;
                }
                $this->stampAltoFile($targetFilepath);
                return true;
            case XmlMediaClassifier::TYPE_HOCR:
                return $this->convertHocr($sourceFilepath, $targetFilepath);
            case XmlMediaClassifier::TYPE_PAGE:
                return $this->convertPage($sourceFilepath, $targetFilepath);
            case XmlMediaClassifier::TYPE_PDF2XML:
                return $this->convertPdf2xml($sourceFilepath, $targetFilepath);
            case XmlMediaClassifier::TYPE_TEI:
                return $this->convertTei($sourceFilepath, $targetFilepath);
        }
        return false;
    }

    protected function convertTei(string $sourceFilepath, string $targetFilepath): bool
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->load($sourceFilepath, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if (!$loaded) {
            return false;
        }
        return $this->runXslt($dom, $this->xslDir . '/tei_to_alto.xsl', $targetFilepath);
    }

    protected function targetIsFresh(string $sourceFilepath, string $targetFilepath): bool
    {
        if (!file_exists($targetFilepath) || !filesize($targetFilepath)) {
            return false;
        }
        $srcMtime = @filemtime($sourceFilepath) ?: 0;
        $dstMtime = @filemtime($targetFilepath) ?: 0;
        return $dstMtime >= $srcMtime;
    }

    protected function convertHocr(string $sourceFilepath, string $targetFilepath): bool
    {
        $content = @file_get_contents($sourceFilepath);
        if ($content === false || $content === '') {
            return false;
        }
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->loadHTML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if (!$loaded) {
            return false;
        }
        return $this->runXslt($dom, $this->xslDir . '/hocr_to_alto.xsl', $targetFilepath);
    }

    protected function convertPage(string $sourceFilepath, string $targetFilepath): bool
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->load($sourceFilepath, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if (!$loaded) {
            return false;
        }
        return $this->runXslt($dom, $this->xslDir . '/page_to_alto.xsl', $targetFilepath);
    }

    protected function convertPdf2xml(string $sourceFilepath, string $targetFilepath): bool
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->load($sourceFilepath, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if (!$loaded) {
            return false;
        }
        return $this->runXslt($dom, $this->xslDir . '/pdf2xml_to_alto.xsl', $targetFilepath);
    }

    protected function runXslt(DOMDocument $source, string $xslPath, string $targetFilepath): bool
    {
        if (!is_readable($xslPath)) {
            return false;
        }
        $xsl = new DOMDocument();
        if (!@$xsl->load($xslPath, LIBXML_NONET)) {
            return false;
        }
        $proc = new XSLTProcessor();
        $proc->setSecurityPrefs(XSL_SECPREF_CREATE_DIRECTORY | XSL_SECPREF_WRITE_FILE | XSL_SECPREF_READ_NETWORK | XSL_SECPREF_WRITE_NETWORK);
        if (!@$proc->importStyleSheet($xsl)) {
            return false;
        }
        $result = @$proc->transformToDoc($source);
        if (!$result) {
            return false;
        }
        $this->stampProvenance($result);
        $result->formatOutput = true;
        return (bool) @$result->save($targetFilepath);
    }
}
