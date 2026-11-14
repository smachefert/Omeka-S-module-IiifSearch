<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

/**
 * Probe an alto file (or string) for the module's provenance marker injected
 * by {@see ToAltoConverter::stampProvenance()}. Used by the extract job to
 * decide whether an existing alto media is safe to delete (module-produced) or
 * must be preserved (native upload).
 */
class AltoProvenance
{
    /**
     * @param string $filepath Absolute path to an alto XML document.
     */
    public static function isModuleAlto(string $filepath): bool
    {
        if (!is_readable($filepath)) {
            return false;
        }
        $reader = new \XMLReader();
        if (!@$reader->open($filepath, null, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return false;
        }
        $found = false;
        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName === 'Layout') {
                    // Provenance lives in Description, before Layout: bail out
                    // as soon as we cross that boundary.
                    break;
                }
                if ($reader->localName === 'softwareName') {
                    $value = trim((string) $reader->readString());
                    if ($value === ToAltoConverter::SOFTWARE_NAME) {
                        $found = true;
                        break;
                    }
                }
            }
        } finally {
            $reader->close();
        }
        return $found;
    }
}
