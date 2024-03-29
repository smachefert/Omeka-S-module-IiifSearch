<?php declare(strict_types=1);

namespace IiifSearch\File;

use Omeka\File\TempFile;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\Message;

/**
 * File validator service
 */
class Validator extends \Omeka\File\Validator
{
    public function validate(TempFile $tempFile, ErrorStore $errorStore = null)
    {
        $isValid = true;
        if ($this->disable) {
            return $isValid;
        }
        if (null !== $this->mediaTypes) {
            $mediaType = $tempFile->getMediaType();
            if ($mediaType
                && !in_array($mediaType, $this->mediaTypes)
                && !(substr($mediaType, -4) === '+xml' && in_array('text/xml', $this->mediaTypes))
            ) {
                $isValid = false;
                if ($errorStore) {
                    $message = new Message(
                        'Error validating "%1$s". Cannot store files with the media type "%2$s".', // @translate
                        $tempFile->getSourceName(), $mediaType
                    );
                    $errorStore->addError('file', $message);
                }
            }
        }
        if (null !== $this->extensions) {
            $extension = $tempFile->getExtension();
            if ($extension && !in_array($extension, $this->extensions)) {
                $isValid = false;
                if ($errorStore) {
                    $message = new Message(
                        'Error validating "%1$s". Cannot store files with the resolved extension "%2$s".', // @translate
                        $tempFile->getSourceName(), $extension
                    );
                    $errorStore->addError('file', $message);
                }
            }
        }
        return $isValid;
    }
}
