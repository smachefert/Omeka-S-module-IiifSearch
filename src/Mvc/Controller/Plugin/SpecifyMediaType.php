<?php declare(strict_types=1);

namespace IiifSearch\Mvc\Controller\Plugin;

use finfo;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use XMLReader;

/**
 * Get a more precise media type for files, mainly xml and json ones.
 *
 * @todo Make more precise media type for text/plain and application/json.
 *
 * @see \Omeka\File\TempFile
 * @see \BulkImport\Form\Reader\XmlReaderParamsForm
 * @see \EasyAdmin /data/media-types/media-type-identifiers
 * @see \ExtractText /data/media-types/media-type-identifiers
 * @see \IiifSearch /data/media-types/media-type-identifiers
 * @see \XmlViewer /data/media-types/media-type-identifiers
 */
class SpecifyMediaType extends AbstractPlugin
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * List of normalized media types extracted from files metadata.
     *
     * @var array
     */
    protected $mediaTypesIdentifiers;

    /**
     * @var string
     */
    protected $filepath;

    public function __construct(Logger $logger, array $mediaTypesIdentifiers)
    {
        $this->logger = $logger;
        $this->mediaTypesIdentifiers = $mediaTypesIdentifiers;
    }

    /**
     * @var ?string|bool $mediaType may be a bool due to a bug in core when the file is missing and FileTemp returns false as mediaType.
     */
    public function __invoke(string $filepath, $mediaType = null): ?string
    {
        $this->filepath = $filepath;

        // The media type may be already properly detected.
        if (!$mediaType) {
            $mediaType = $this->simpleMediaType();
        }
        if ($mediaType === 'text/xml' || $mediaType === 'application/xml') {
            $mediaType = $this->getMediaTypeXml() ?: $mediaType;
        }
        if ($mediaType === 'application/zip') {
            $mediaType = $this->getMediaTypeZip() ?: $mediaType;
        }
        return $mediaType;
    }

    /**
     * Get the Internet media type of the file.
     *
     * @uses finfo
     */
    protected function simpleMediaType(): ?string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($this->filepath) ?: null;
        if (array_key_exists($mediaType, \Omeka\File\TempFile::MEDIA_TYPE_ALIASES)) {
            $mediaType = \Omeka\File\TempFile::MEDIA_TYPE_ALIASES[$mediaType];
        }
        return $mediaType;
    }

    /**
     * Extract a more precise xml media type when possible.
     */
    protected function getMediaTypeXml(): ?string
    {
        libxml_clear_errors();

        $reader = new XMLReader();
        if (!$reader->open($this->filepath)) {
            $message = new \Omeka\Stdlib\Message(
                'The file "%1$s" is not parsable by xml reader.', // @translate
                $this->filepath
            );
            $this->logger->err($message);
            return null;
        }

        $type = null;

        // Don't output error in case of a badly formatted file since there is no logger.
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::DOC_TYPE) {
                $type = $reader->name;
                break;
            }

            if ($reader->nodeType === XMLReader::PI
                && !in_array($reader->name, ['xml-stylesheet', 'oxygen'])
            ) {
                $matches = [];
                if (preg_match('~href="(.+?)"~mi', $reader->value, $matches)) {
                    $type = $matches[1];
                    break;
                }
            }

            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->namespaceURI === 'urn:oasis:names:tc:opendocument:xmlns:office:1.0') {
                    $type = $reader->getAttributeNs('mimetype', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0');
                } else {
                    $type = $reader->namespaceURI ?: $reader->getAttribute('xmlns');
                }
                if (!$type) {
                    $type = $reader->name;
                }
                break;
            }
        }

        $reader->close();

        $error = libxml_get_last_error();
        if ($error) {
            // TODO Use PsrMessage.
            $message = new \Omeka\Stdlib\Message(
                'Xml parsing error level %1$s, code %2$s, for file "%3$s", line %4$s, column %5$s: %6$s', // @translate
                $error->level, $error->code, $error->file, $error->line, $error->column, $error->message
            );
            $this->logger->err($message);
        }

        return $this->mediaTypesIdentifiers[$type] ?? null;
    }

    /**
     * Extract a more precise zipped media type when possible.
     *
     * In many cases, the media type is saved in a uncompressed file "mimetype"
     * at the beginning of the zip file. If present, get it.
     */
    protected function getMediaTypeZip(): ?string
    {
        $handle = fopen($this->filepath, 'rb');
        $contents = fread($handle, 256);
        fclose($handle);
        return substr($contents, 30, 8) === 'mimetype'
            ? substr($contents, 38, strpos($contents, 'PK', 38) - 38)
            : null;
    }
}
