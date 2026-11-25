<?php declare(strict_types=1);

namespace IiifSearch\Stdlib;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Lightweight wrapper exposing a uniform file-backed resource interface for
 * both MediaRepresentation and
 * DigitalObject\Api\Representation\DigitalObjectRepresentation.
 *
 * IiifSearch needs to work over any file-backed resource attached to or
 * referenced by an item (filename, mediaType, source on disk). The wrapper
 * keeps the originating item as call-site context, since a DigitalObject can be
 * referenced by multiple items.
 */
final class FileResource
{
    public const KIND_MEDIA = 'media';
    public const KIND_DIGITAL_OBJECT = 'digital_object';

    private AbstractResourceEntityRepresentation $inner;

    private ItemRepresentation $item;

    private string $kind;

    public function __construct(
        AbstractResourceEntityRepresentation $inner,
        ItemRepresentation $item
    ) {
        $this->inner = $inner;
        $this->item = $item;
        $this->kind = $inner instanceof MediaRepresentation
            ? self::KIND_MEDIA
            : self::KIND_DIGITAL_OBJECT;
    }

    public function id(): int
    {
        return (int) $this->inner->id();
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isMedia(): bool
    {
        return $this->kind === self::KIND_MEDIA;
    }

    public function isDigitalObject(): bool
    {
        return $this->kind === self::KIND_DIGITAL_OBJECT;
    }

    public function inner(): AbstractResourceEntityRepresentation
    {
        return $this->inner;
    }

    /**
     * Underlying MediaRepresentation when kind=media, null otherwise.
     * Convenience for code paths that still need the concrete media (e.g.
     * writing back).
     */
    public function media(): ?MediaRepresentation
    {
        return $this->inner instanceof MediaRepresentation ? $this->inner : null;
    }

    public function item(): ItemRepresentation
    {
        return $this->item;
    }

    public function filename(): ?string
    {
        return method_exists($this->inner, 'filename') ? $this->inner->filename() : null;
    }

    public function mediaType(): ?string
    {
        return method_exists($this->inner, 'mediaType') ? $this->inner->mediaType() : null;
    }

    public function source(): ?string
    {
        // The source of a media ingested from an url is an uri object until
        // the entity is reloaded.
        $source = method_exists($this->inner, 'source') ? $this->inner->source() : null;
        return $source === null ? null : (string) $source;
    }

    public function storageId(): ?string
    {
        return method_exists($this->inner, 'storageId') ? $this->inner->storageId() : null;
    }

    public function extension(): ?string
    {
        return method_exists($this->inner, 'extension') ? $this->inner->extension() : null;
    }

    public function size(): ?int
    {
        return method_exists($this->inner, 'size') ? $this->inner->size() : null;
    }

    public function hasOriginal(): bool
    {
        return method_exists($this->inner, 'hasOriginal') ? (bool) $this->inner->hasOriginal() : false;
    }

    public function renderer(): ?string
    {
        return method_exists($this->inner, 'renderer') ? $this->inner->renderer() : null;
    }

    /**
     * Check if the resource is an image displayed as a canvas of the manifest.
     *
     * A remote iiif image has no original file nor media type, but it is a
     * canvas of the manifest built by the module Iiif Server.
     */
    public function isCanvasImage(): bool
    {
        return (strtok((string) $this->mediaType(), '/') === 'image' && $this->hasOriginal())
            || $this->renderer() === 'iiif';
    }

    public function hasThumbnails(): bool
    {
        return method_exists($this->inner, 'hasThumbnails') ? (bool) $this->inner->hasThumbnails() : false;
    }

    public function originalUrl(): ?string
    {
        return method_exists($this->inner, 'originalUrl') ? $this->inner->originalUrl() : null;
    }

    public function isPublic(): bool
    {
        return method_exists($this->inner, 'isPublic') ? (bool) $this->inner->isPublic() : true;
    }

    /**
     * Underlying media_data column (parsed JSON) when the inner resource
     * exposes it, otherwise null.
     *
     * @return mixed
     */
    public function mediaData()
    {
        return method_exists($this->inner, 'mediaData') ? $this->inner->mediaData() : null;
    }

    public function originalFilepath(string $basePath): ?string
    {
        $filename = $this->filename();
        return $filename ? $basePath . '/original/' . $filename : null;
    }
}
