<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

use DOMDocument;
use Exception;
use SimpleXMLElement;

/**
 * Centralized XML repair routines shared by the OCR pipeline.
 *
 * Two flavours:
 * - fixXmlDom: parse loosely via DOMDocument with libxml errors silenced.
 * - fixXmlPdf2Xml: regex-level pre-clean of pdftohtml output before parsing.
 *
 * Both used to be copy-pasted into IiifSearch\Job\ExtractOcr,
 * IiifSearch\View\Helper\IiifSearch and IiifSearch\View\Helper\XmlAltoSingle.
 * IiifServer\Iiif\TraitXml carries a near-identical copy that should eventually
 * delegate here too.
 */
class XmlRepair
{
    public static function fixXmlDom(string $xmlContent): ?SimpleXMLElement
    {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.1', 'UTF-8');
        $dom->strictErrorChecking = false;
        $dom->validateOnParse = false;
        $dom->recover = true;
        try {
            $result = $dom->loadXML($xmlContent, LIBXML_NONET);
            $result = $result ? simplexml_import_dom($dom) : null;
        } catch (Exception $e) {
            $result = null;
        }

        libxml_clear_errors();
        libxml_use_internal_errors(false);

        return $result;
    }

    public static function fixXmlPdf2Xml(?string $xmlContent): string
    {
        if (!$xmlContent) {
            return (string) $xmlContent;
        }
        // When the content is not a valid unicode text, a null is output.
        // Replace all series of spaces by a single space.
        $xmlContent = preg_replace('~\s{2,}~S', ' ', $xmlContent) ?? $xmlContent;
        // Remove bold and italic.
        $xmlContent = preg_replace('~</?[bi]>~S', '', $xmlContent) ?? $xmlContent;
        // Remove fontspecs, useless for search and sometimes incorrect with old
        // versions of pdftohtml. Example with pdftohtml 0.71 (debian 10):
        // <fontspec id="^C
        // <fontspec id=" " size="^P" family="PBPMTB+ArialUnicodeMS"
        // color="#000000"/> Keep incomplete font specs as a placeholder so the
        // order of font ids is preserved downstream.
        $xmlContent = preg_replace('~<fontspec id="[^>]*$~S', '<fontspec/>*\n', $xmlContent) ?? $xmlContent;
        $xmlContent = str_replace(
            '<!doctype pdf2xml system "pdf2xml.dtd">',
            '<!DOCTYPE pdf2xml SYSTEM "pdf2xml.dtd">',
            $xmlContent
        );
        return $xmlContent;
    }
}
