<?php declare(strict_types=1);

namespace IiifSearch\File;

use IiifSearch\Mvc\Controller\Plugin\SpecifyMediaType;

class TempFile extends \Omeka\File\TempFile
{
    /**
     * @var \EasyAdmin\Mvc\Controller\Plugin\SpecifyMediaType
     */
    protected $specifyMediaType;

    public function setSpecifyMediaType(SpecifyMediaType $specifyMediaType): \Omeka\File\TempFile
    {
        $this->specifyMediaType = $specifyMediaType;
        return $this;
    }

    public function getMediaType()
    {
        if (isset($this->mediaType)) {
            return $this->mediaType;
        }
        parent::getMediaType();
        $this->mediaType = $this->specifyMediaType->__invoke($this->getTempPath(), $this->mediaType);
        return $this->mediaType;
    }
}
