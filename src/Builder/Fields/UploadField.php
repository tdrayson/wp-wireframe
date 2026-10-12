<?php

declare(strict_types=1);

namespace Wireframe\Builder\Fields;

use Wireframe\Builder\Concerns\HasButtonLabel;

/**
 * Local file picker whose file is sent with action requests, never saved (`upload`).
 */
final class UploadField extends Field
{
    use HasButtonLabel;

    protected const TYPE = 'upload';

    /**
     * Limit the file picker to these types.
     *
     * @param string $accept `<input accept>` value, e.g. `.csv,text/csv`.
     * @return static
     */
    public function accept(string $accept): static
    {
        return $this->arg('accept', $accept);
    }

    /**
     * Reject files larger than this.
     *
     * @param int $bytes Maximum decoded size in bytes (default 5 MB).
     * @return static
     */
    public function maxSize(int $bytes): static
    {
        return $this->arg('max_size', $bytes);
    }
}
