<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

/**
 * Media Library picker storing attachment IDs (`file`).
 */
final class FileField extends Field
{
    protected const TYPE = 'file';

    /**
     * Allow picking several attachments.
     *
     * @param bool $multiple Whether multiple attachments can be chosen.
     * @return static
     */
    public function multiple(bool $multiple = true): static
    {
        return $this->arg('multiple', $multiple);
    }

    /**
     * Limit the Media Library to these MIME types.
     *
     * @param string|list<string> $types MIME type or group, e.g. `image` or `application/pdf`, or a list.
     * @return static
     */
    public function mimeTypes(string|array $types): static
    {
        return $this->arg('mime_types', $types);
    }

    /**
     * Set the Media Library modal title.
     *
     * @param string $title Modal title.
     * @return static
     */
    public function mediaTitle(string $title): static
    {
        return $this->arg('media_title', $title);
    }

    /**
     * Set the Media Library select-button text.
     *
     * @param string $label Button label.
     * @return static
     */
    public function mediaButton(string $label): static
    {
        return $this->arg('media_button', $label);
    }
}
