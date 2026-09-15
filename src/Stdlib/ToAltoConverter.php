<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

use DOMDocument;
use XSLTProcessor;

/**
 * Convert non-alto OCR sources (hocr, pdf2xml) into a canonical ALTO file,
 * persisted on disk so the downstream pipeline only has to consume alto.
 *
 * The converted file is reused on subsequent runs as long as its mtime is
 * greater than or equal to the source mtime. The target directory is the caller
 * responsibility (typically files/alto/).
 */
class ToAltoConverter
{
    /**
     * @var string Absolute path to the directory holding the bundled XSLT.
     */
    protected $xslDir;

    public function __construct(?string $xslDir = null)
    {
        $this->xslDir = $xslDir ?: dirname(__DIR__, 2) . '/data/xsl';
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
                return @copy($sourceFilepath, $targetFilepath);
            case XmlMediaClassifier::TYPE_HOCR:
                return $this->convertHocr($sourceFilepath, $targetFilepath);
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
        $result->formatOutput = true;
        return (bool) @$result->save($targetFilepath);
    }
}
